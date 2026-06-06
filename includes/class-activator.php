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
		$catbox_albums    = $wpdb->prefix . 'nc_catbox_albums';

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
			youtube_ids     TEXT,
			article         LONGTEXT,
			enabled         TINYINT(1)      NOT NULL DEFAULT 1,
			published_at    DATETIME        NULL DEFAULT NULL,
			fetched_at      DATETIME        NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_guid (guid(191)),
			KEY idx_published (published_at, telegram_id)
		) {$charset_collate};";

		$sql_catbox_uploads = "CREATE TABLE {$catbox_uploads} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source       VARCHAR(255)    NOT NULL DEFAULT '',
			source_name  VARCHAR(255)    NOT NULL DEFAULT '',
			item_guid    VARCHAR(500)    NOT NULL DEFAULT '',
			upload_type  VARCHAR(32)     NOT NULL DEFAULT '',
			original_url TEXT,
			catbox_url   VARCHAR(500)    NOT NULL DEFAULT '',
			album_id     VARCHAR(32)     DEFAULT NULL,
			uploaded_at  DATETIME        NOT NULL,
			created_at   DATETIME        NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_catbox_url (catbox_url(191))
		) {$charset_collate};";

		$sql_catbox_albums = "CREATE TABLE {$catbox_albums} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			month      VARCHAR(7)      NOT NULL DEFAULT '',
			album_id   VARCHAR(32)     NOT NULL DEFAULT '',
			created_at DATETIME        NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_month (month)
		) {$charset_collate};";

		dbDelta( $sql_sources );
		dbDelta( $sql_items );
		dbDelta( $sql_catbox_uploads );
		dbDelta( $sql_catbox_albums );

		// Seed default settings only on first activation; preserve existing values.
		if ( false === get_option( 'nc_settings' ) ) {
			add_option( 'nc_settings', NC_Plugin::default_settings() );
		}

		// Register the rewrite rule explicitly so flush_rewrite_rules() picks it up,
		// since the `init` hook has not yet fired during activation.
		$slug = NC_Rewrite::slug();
		add_rewrite_rule( '^' . $slug . '/([0-9]+)/?$', 'index.php?' . NC_Rewrite::QUERY_VAR . '=$matches[1]', 'top' );
		flush_rewrite_rules( false );
	}

	/**
	 * Drop tables + delete settings. Runs on `register_uninstall_hook`.
	 */
	public static function uninstall(): void {
		global $wpdb;

		$sources        = $wpdb->prefix . 'nc_sources';
		$items          = $wpdb->prefix . 'nc_items';
		$catbox_uploads = $wpdb->prefix . 'nc_catbox_uploads';
		$catbox_albums  = $wpdb->prefix . 'nc_catbox_albums';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$catbox_uploads}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$catbox_albums}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$items}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$sources}" );

		delete_option( 'nc_settings' );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'nc_fetch_all_sources' );
		}

		flush_rewrite_rules( false );
	}
}