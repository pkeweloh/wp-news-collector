<?php
/**
 * Plugin activation / uninstall handlers.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Activator {

	/**
	 * Create custom DB tables. Runs on `register_activation_hook`.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate  = $wpdb->get_charset_collate();
		$sources          = $wpdb->prefix . 'nc_sources';
		$items            = $wpdb->prefix . 'nc_items';
		$catbox_uploads   = $wpdb->prefix . 'nc_catbox_uploads';
		$catbox_attempts  = $wpdb->prefix . 'nc_catbox_upload_attempts';
		$catbox_albums    = $wpdb->prefix . 'nc_catbox_albums';
		$source_covers    = $wpdb->prefix . 'nc_source_covers';

		$sql_sources = "CREATE TABLE {$sources} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url        VARCHAR(500)    NOT NULL,
			name       VARCHAR(255)    NOT NULL DEFAULT '',
			enabled    TINYINT(1)      NOT NULL DEFAULT 1,
			created_at DATETIME        NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_url (url(191))
		) {$charset_collate};";

		$sql_items = "CREATE TABLE {$items} (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			guid            VARCHAR(500)    NOT NULL,
			telegram_id     BIGINT          NOT NULL DEFAULT 0,
			source          VARCHAR(255)    NOT NULL DEFAULT '',
			source_name     VARCHAR(255)    NOT NULL DEFAULT '',
			raw_description LONGTEXT        NOT NULL,
			text            LONGTEXT,
			images          LONGTEXT,
			videos          LONGTEXT,
			audios          LONGTEXT,
			youtube_ids     TEXT,
			article         LONGTEXT,
			enabled         TINYINT(1)      NOT NULL DEFAULT 1,
			content_hash    VARCHAR(100)    NOT NULL DEFAULT '',
			published_at    DATETIME        NULL DEFAULT NULL,
			fetched_at      DATETIME        NOT NULL,
			updated_at      DATETIME        NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_guid (guid(191)),
			KEY idx_published (published_at, telegram_id),
			KEY idx_source_tgid (source(150), telegram_id)
		) {$charset_collate};";

		// catbox_url nullable: failed rows are NULL, which coexist under uk_catbox_url.
		$sql_catbox_uploads = "CREATE TABLE {$catbox_uploads} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source       VARCHAR(255)    NOT NULL DEFAULT '',
			source_name  VARCHAR(255)    NOT NULL DEFAULT '',
			item_guid    VARCHAR(500)    NOT NULL DEFAULT '',
			upload_type  VARCHAR(32)     NOT NULL DEFAULT '',
			original_url TEXT,
			catbox_url    VARCHAR(500)   DEFAULT NULL,
			error         TEXT           DEFAULT NULL,
			album_id      VARCHAR(32)    DEFAULT NULL,
			retry_count   INT            NOT NULL DEFAULT 0,
			next_retry_at DATETIME       DEFAULT NULL,
			source_gone   TINYINT(1)     NOT NULL DEFAULT 0,
			uploaded_at   DATETIME       NOT NULL,
			created_at    DATETIME       NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_catbox_url (catbox_url(191))
		) {$charset_collate};";

		// One row per attempt: nc_catbox_uploads is overwritten on every retry, so
		// the cause is only knowable here. trigger_type: `trigger` is reserved.
		$sql_catbox_attempts = "CREATE TABLE {$catbox_attempts} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attempted_at DATETIME        NOT NULL,
			item_guid    VARCHAR(500)    NOT NULL DEFAULT '',
			upload_type  VARCHAR(32)     NOT NULL DEFAULT '',
			original_url TEXT,
			trigger_type VARCHAR(20)     NOT NULL DEFAULT '',
			outcome      VARCHAR(20)     NOT NULL DEFAULT '',
			error        TEXT,
			PRIMARY KEY  (id),
			KEY idx_attempted (attempted_at),
			KEY idx_attempt_guid (item_guid(191))
		) {$charset_collate};";

		$sql_catbox_albums = "CREATE TABLE {$catbox_albums} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			month      VARCHAR(7)      NOT NULL DEFAULT '',
			album_id   VARCHAR(32)     NOT NULL DEFAULT '',
			created_at DATETIME        NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_month (month)
		) {$charset_collate};";

		// Recurring channel-cover images RSSHub injects into many posts.
		// Frequency only proposes candidates; a human confirms which are real
		// covers (status). Only confirmed covers are stripped from items.
		$sql_source_covers = "CREATE TABLE {$source_covers} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source       VARCHAR(255)    NOT NULL DEFAULT '',
			catbox_url   VARCHAR(500)    NOT NULL DEFAULT '',
			original_url TEXT,
			post_count   INT UNSIGNED    NOT NULL DEFAULT 0,
			status       VARCHAR(20)     NOT NULL DEFAULT 'candidate',
			is_icon      TINYINT(1)      NOT NULL DEFAULT 0,
			created_at   DATETIME        NOT NULL,
			updated_at   DATETIME        NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_source_url (source(150), catbox_url(150))
		) {$charset_collate};";

		dbDelta( $sql_sources );
		dbDelta( $sql_items );
		dbDelta( $sql_catbox_uploads );
		dbDelta( $sql_catbox_attempts );
		dbDelta( $sql_catbox_albums );
		dbDelta( $sql_source_covers );

		self::upgrade_catbox_uploads( $catbox_uploads );

		// Seed default settings only on first activation; preserve existing values.
		if ( false === get_option( 'nc_settings' ) ) {
			add_option( 'nc_settings', NC_Plugin::default_settings() );
		}

		// Register the rewrite rule explicitly so flush_rewrite_rules() picks it up,
		// since the `init` hook has not yet fired during activation.
		$slug = NC_Rewrite::slug();
		add_rewrite_rule( '^' . $slug . '/([0-9]+)(?:/[^/]+)?/?$', 'index.php?' . NC_Rewrite::QUERY_VAR . '=$matches[1]', 'top' );
		if ( NC_Source_Page::base() !== $slug ) {
			add_rewrite_rule( '^' . NC_Source_Page::base() . '/([^/]+)/?$', 'index.php?' . NC_Source_Page::QUERY_VAR . '=$matches[1]', 'top' );
		}
		flush_rewrite_rules( false );
	}

	// dbDelta does not reliably change column nullability, so migrate in place.
	// Idempotent and non-destructive: safe on every (re)activation.
	private static function upgrade_catbox_uploads( string $table ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		if ( ! in_array( 'error', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN error TEXT DEFAULT NULL AFTER catbox_url" );
		}
		if ( ! in_array( 'retry_count', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN retry_count INT NOT NULL DEFAULT 0 AFTER album_id" );
		}
		if ( ! in_array( 'next_retry_at', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN next_retry_at DATETIME DEFAULT NULL AFTER retry_count" );
		}
		if ( ! in_array( 'source_gone', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN source_gone TINYINT(1) NOT NULL DEFAULT 0 AFTER next_retry_at" );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} MODIFY catbox_url VARCHAR(500) DEFAULT NULL" );
	}

	/**
	 * Drop tables + delete settings. Runs on `register_uninstall_hook`.
	 */
	public static function uninstall(): void {
		global $wpdb;

		$sources         = $wpdb->prefix . 'nc_sources';
		$items           = $wpdb->prefix . 'nc_items';
		$catbox_uploads  = $wpdb->prefix . 'nc_catbox_uploads';
		$catbox_attempts = $wpdb->prefix . 'nc_catbox_upload_attempts';
		$catbox_albums   = $wpdb->prefix . 'nc_catbox_albums';
		$source_covers   = $wpdb->prefix . 'nc_source_covers';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$source_covers}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$catbox_attempts}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$catbox_uploads}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$catbox_albums}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$items}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$sources}" );

		delete_option( 'nc_settings' );
		delete_option( 'nc_needs_rewrite_flush' );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'nc_fetch_all_sources' );
		}

		flush_rewrite_rules( false );
	}
}