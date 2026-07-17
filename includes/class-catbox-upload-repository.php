<?php
/**
 * Repository for Catbox upload tracking (nc_catbox_uploads + nc_catbox_albums).
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Catbox_Upload_Repository {

	private string $uploads_table;
	private string $albums_table;
	private string $items_table;

	public function __construct() {
		global $wpdb;
		$this->uploads_table = $wpdb->prefix . 'nc_catbox_uploads';
		$this->albums_table  = $wpdb->prefix . 'nc_catbox_albums';
		$this->items_table   = $wpdb->prefix . 'nc_items';
	}

	// Upload logging

	/**
	 * Log a Catbox upload immediately after each successful upload.
	 * Uses INSERT IGNORE: idempotent on catbox_url.
	 */
	public function log_upload(
		string $source,
		string $source_name,
		string $item_guid,
		string $upload_type,
		string $original_url,
		string $catbox_url,
		?string $album_id = null
	): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$this->uploads_table}
				 (source, source_name, item_guid, upload_type, original_url, catbox_url, album_id, uploaded_at, created_at)
				 VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)",
				$source,
				$source_name,
				$item_guid,
				$upload_type,
				$original_url,
				$catbox_url,
				$album_id,
				$now,
				$now
			)
		);
	}

	/** @return array<string, mixed>|null */
	public function get_by_id( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->uploads_table} WHERE id = %d", $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	// A failed row keeps a NULL catbox_url so it does not collide under uk_catbox_url.
	public function set_result( int $id, ?string $catbox_url, ?string $error ): void {
		global $wpdb;
		$wpdb->update(
			$this->uploads_table,
			[ 'catbox_url' => $catbox_url, 'error' => $error ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	// Update the row for (item_guid, original_url) or insert one.
	public function resolve_result(
		string $source,
		string $source_name,
		string $item_guid,
		string $upload_type,
		string $original_url,
		?string $catbox_url,
		?string $error
	): void {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->uploads_table} WHERE item_guid = %s AND original_url = %s LIMIT 1",
				$item_guid,
				$original_url
			)
		);
		if ( $id ) {
			$this->set_result( (int) $id, $catbox_url, $error );
			return;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$this->uploads_table,
			[
				'source'       => $source,
				'source_name'  => $source_name,
				'item_guid'    => $item_guid,
				'upload_type'  => $upload_type,
				'original_url' => $original_url,
				'catbox_url'   => $catbox_url,
				'error'        => $error,
				'uploaded_at'  => $now,
				'created_at'   => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	public function count_failed(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->uploads_table} WHERE error IS NOT NULL" );
	}

	// $max_attempts <= 0 means no attempt cap.
	private const RETRYABLE_WHERE = "error IS NOT NULL
		AND ( catbox_url IS NULL OR catbox_url = '' )
		AND ( next_retry_at IS NULL OR next_retry_at <= %s )
		AND ( %d <= 0 OR retry_count < %d )";

	/**
	 * Failed uploads due for another attempt, most-urgent first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_retryable_uploads( string $now, int $max_attempts, int $limit = 100 ): array {
		global $wpdb;
		$limit = max( 1, $limit );
		$sql   = "SELECT * FROM {$this->uploads_table}
			WHERE " . self::RETRYABLE_WHERE . "
			ORDER BY next_retry_at IS NULL DESC, next_retry_at ASC, id ASC
			LIMIT %d";
		$rows  = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( $sql, $now, $max_attempts, $max_attempts, $limit ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	public function count_retryable( string $now, int $max_attempts ): int {
		global $wpdb;
		$sql = "SELECT COUNT(*) FROM {$this->uploads_table} WHERE " . self::RETRYABLE_WHERE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $now, $max_attempts, $max_attempts ) );
	}

	public function schedule_upload_retry( int $id, string $next_retry_at ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->uploads_table} SET retry_count = retry_count + 1, next_retry_at = %s WHERE id = %d",
				$next_retry_at,
				$id
			)
		);
	}

	/**
	 * Look up the original (source) URL we logged for a given Catbox URL.
	 * Returns '' if none is tracked.
	 */
	public function get_original_for_catbox( string $catbox_url ): string {
		global $wpdb;
		$val = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT original_url FROM {$this->uploads_table} WHERE catbox_url = %s LIMIT 1",
				$catbox_url
			)
		);
		return is_string( $val ) ? $val : '';
	}

	/**
	 * Insert a row for a catbox URL found in nc_items but not yet tracked.
	 * Returns true if a new row was actually inserted.
	 */
	public function insert_if_missing(
		string $source,
		string $source_name,
		string $item_guid,
		string $upload_type,
		string $catbox_url,
		string $uploaded_at
	): bool {
		global $wpdb;
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$this->uploads_table}
				 (source, source_name, item_guid, upload_type, catbox_url, uploaded_at, created_at)
				 VALUES (%s, %s, %s, %s, %s, %s, %s)",
				$source,
				$source_name,
				$item_guid,
				$upload_type,
				$catbox_url,
				$uploaded_at,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		return 1 === (int) $result;
	}

	/**
	 * Return uploads without an album_id, joined with nc_items for published_at.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_unassigned_by_month(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT u.id, u.catbox_url, u.source, u.source_name, u.item_guid,
			        i.published_at
			 FROM {$this->uploads_table} u
			 LEFT JOIN {$this->items_table} i ON i.guid = u.item_guid
			 WHERE u.album_id IS NULL AND u.catbox_url != ''
			 ORDER BY i.published_at ASC",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Set the album_id for a given catbox URL.
	 */
	public function set_album( string $catbox_url, string $album_id ): void {
		global $wpdb;
		$wpdb->update(
			$this->uploads_table,
			[ 'album_id' => $album_id ],
			[ 'catbox_url' => $catbox_url ],
			[ '%s' ],
			[ '%s' ]
		);
	}

	/**
	 * Paginated uploads list for admin UI.
	 *
	 * @return array<string, mixed>
	 */
	public function get_page( int $page, int $page_size, string $filter = 'all' ): array {
		global $wpdb;
		$page      = max( 1, $page );
		$page_size = max( 1, min( 200, $page_size ) );
		$offset    = ( $page - 1 ) * $page_size;
		$where     = 'WHERE 1=1';
		if ( 'unassigned' === $filter ) {
			$where .= " AND album_id IS NULL AND catbox_url IS NOT NULL AND catbox_url != ''";
		} elseif ( 'failed' === $filter ) {
			$where .= ' AND error IS NOT NULL';
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->uploads_table} {$where}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->uploads_table} {$where} ORDER BY uploaded_at DESC LIMIT %d OFFSET %d",
				$page_size,
				$offset
			),
			ARRAY_A
		);
		$total_pages = $total > 0 ? (int) ceil( $total / $page_size ) : 1;
		return [
			'page'        => $page,
			'total_items' => $total,
			'total_pages' => $total_pages,
			'has_next'    => $page < $total_pages,
			'has_prev'    => $page > 1,
			'items'       => is_array( $rows ) ? $rows : [],
		];
	}

	// Albums

	/**
	 * Get the album short code for a given month (YYYY-MM). Returns '' if none.
	 */
	public function get_album_for_month( string $month ): string {
		global $wpdb;
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT album_id FROM {$this->albums_table} WHERE month = %s LIMIT 1",
				$month
			)
		);
		return is_string( $row ) ? $row : '';
	}

	/**
	 * Persist a new album for a month. Returns the album_id.
	 * The display name is derived from the month, so it is not stored.
	 */
	public function save_album_for_month( string $month, string $album_id ): string {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$this->albums_table} (month, album_id, created_at) VALUES (%s, %s, %s)",
				$month,
				$album_id,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		return $album_id;
	}

	/**
	 * All albums with file count, ordered by month desc.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_albums_with_stats(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT a.month, a.album_id, a.created_at,
			        COUNT(u.id) AS file_count
			 FROM {$this->albums_table} a
			 LEFT JOIN {$this->uploads_table} u ON u.album_id = a.album_id
			 GROUP BY a.id, a.month, a.album_id, a.created_at
			 ORDER BY a.month DESC",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Map of album_id => month, so callers can derive an album's display
	 * name from its month (see NC_Plugin::catbox_album_name).
	 *
	 * @return array<string, string>
	 */
	public function get_album_month_map(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT album_id, month FROM {$this->albums_table}",
			ARRAY_A
		);
		$map = [];
		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['album_id'] ] = (string) $row['month'];
		}
		return $map;
	}

	/**
	 * Count of uploads without an album assignment.
	 */
	public function count_unassigned(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->uploads_table} WHERE album_id IS NULL AND catbox_url != ''" );
	}

	/**
	 * Total upload count.
	 */
	public function count_total(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->uploads_table}" );
	}
}
