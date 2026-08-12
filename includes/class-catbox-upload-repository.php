<?php
/**
 * Repository for Catbox upload tracking (nc_catbox_uploads + nc_catbox_albums).
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Catbox_Upload_Repository {

	private string $uploads_table;
	private string $attempts_table;
	private string $albums_table;
	private string $items_table;

	public function __construct() {
		global $wpdb;
		$this->uploads_table  = $wpdb->prefix . 'nc_catbox_uploads';
		$this->attempts_table = $wpdb->prefix . 'nc_catbox_upload_attempts';
		$this->albums_table   = $wpdb->prefix . 'nc_catbox_albums';
		$this->items_table    = $wpdb->prefix . 'nc_items';
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

	// Far-future so RETRYABLE_WHERE (next_retry_at <= now) excludes it from query + count.
	private const ORPHAN_PARK_UNTIL = '2999-01-01 00:00:00';

	/**
	 * A failure somebody can still act on. Retired sources and parked orphans are
	 * out: the four backlog buckets (pending, exhausted, retired, orphaned) must be
	 * disjoint, or a button ends up offering to recover more than it can.
	 */
	private const LIVE_FAILED_WHERE = "error IS NOT NULL
		AND ( catbox_url IS NULL OR catbox_url = '' )
		AND source_gone = 0
		AND ( next_retry_at IS NULL OR next_retry_at < '" . self::ORPHAN_PARK_UNTIL . "' )";

	/** Under the attempt cap, so a sweep will still pick it up (0 = no cap). */
	private const UNDER_CAP = '( %d <= 0 OR retry_count < %d )';

	/** Failures still in the queue: not retired, not orphaned, not out of attempts. */
	public function count_failed( int $max_attempts = 0 ): int {
		global $wpdb;
		$sql = 'SELECT COUNT(*) FROM ' . $this->uploads_table . ' WHERE ' . self::LIVE_FAILED_WHERE . ' AND ' . self::UNDER_CAP;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $max_attempts, $max_attempts ) );
	}

	/**
	 * Failures that used up their attempts. Counted apart because no sweep will
	 * ever pick them again: left under "pending" they would pin that number above
	 * zero for good, and a number that never goes out teaches people to ignore it.
	 */
	public function count_exhausted( int $max_attempts ): int {
		if ( $max_attempts <= 0 ) {
			return 0;
		}
		global $wpdb;
		$sql = 'SELECT COUNT(*) FROM ' . $this->uploads_table . ' WHERE ' . self::LIVE_FAILED_WHERE . ' AND NOT ' . self::UNDER_CAP;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $max_attempts, $max_attempts ) );
	}

	/** Failed rows parked as orphans: history, not pending work. */
	public function count_parked(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$this->uploads_table} WHERE next_retry_at = %s",
				self::ORPHAN_PARK_UNTIL
			)
		);
	}

	/**
	 * Attempts in a recent window whose error is the markup alarm: the bet on
	 * reading t.me's HTML losing, which is loud because the media is still there
	 * and we merely stopped finding it.
	 */
	public function count_markup_alarm( int $days = 7 ): int {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$this->attempts_table} WHERE attempted_at >= %s AND error LIKE %s",
				$since,
				$wpdb->esc_like( substr( NC_Telegram_Media::MARKUP_ALARM, 0, 40 ) ) . '%'
			)
		);
	}

	// $max_attempts <= 0 means no attempt cap. source_gone is filtered here and not
	// in PHP, or expired sources would still crowd the batch out.
	private const RETRYABLE_WHERE = "error IS NOT NULL
		AND ( catbox_url IS NULL OR catbox_url = '' )
		AND source_gone = 0
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

	/** Source answered 404/410: retire it, kept distinct from an orphan for the UI. */
	public function mark_source_gone( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->uploads_table,
			[ 'source_gone' => 1 ],
			[ 'id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);
	}

	/**
	 * Retired rows that could still be recovered, for the caller's linked filter.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_source_gone_rows(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT id, item_guid, upload_type, original_url FROM {$this->uploads_table}
			 WHERE source_gone = 1 AND ( catbox_url IS NULL OR catbox_url = '' )",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Clear the retirement and the backoff so the sweep picks these up again.
	 *
	 * @param int[] $ids
	 * @return int Rows updated.
	 */
	public function requeue_uploads( array $ids ): int {
		if ( empty( $ids ) ) {
			return 0;
		}
		global $wpdb;
		$ids     = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$updated = 0;
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$updated     += (int) $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"UPDATE {$this->uploads_table}
					 SET source_gone = 0, retry_count = 0, next_retry_at = NULL
					 WHERE id IN ({$placeholders})",
					...$chunk
				)
			);
		}
		return $updated;
	}

	public function count_source_gone(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->uploads_table} WHERE source_gone = 1" );
	}

	/**
	 * Close log rows for pieces that ended up on Catbox by another route.
	 *
	 * An admin edit, a re-ingest that rewrites media, anything going through
	 * update_media() writes the item without touching this table, so the row keeps
	 * saying "broken" while the piece is fine. The item no longer needs uploading,
	 * so is_piece_linked() reads it as an orphan and the sweep parks a piece that
	 * is perfectly stored. Copying the URL the item already holds says what
	 * happened (resolved) instead of what parking it says (never needed).
	 *
	 * Videos and audios only: those keep original_url beside catbox_url, so the row
	 * can be matched back. An image or a poster loses its original on repair and is
	 * then indistinguishable from media somebody removed, which is cleanup_orphans'
	 * job.
	 *
	 * @return int Rows closed.
	 */
	public function reconcile_resolved_uploads(): int {
		$pairs = [];
		foreach ( [ 'video' => 'videos', 'audio' => 'audios' ] as $upload_type => $column ) {
			if ( 'audios' === $column && ! $this->items_column_exists( 'audios' ) ) {
				continue;
			}
			foreach ( $this->resolved_pairs( $upload_type, $column ) as $id => $url ) {
				$pairs[ $id ] = $url;
			}
		}
		if ( empty( $pairs ) ) {
			return 0;
		}

		// uk_catbox_url is on catbox_url, so a URL another row already claims cannot
		// be written here. That other row is the same upload logged by the path that
		// repaired it, which makes this one a duplicate with nothing left to say.
		$owners  = $this->catbox_url_owners( array_values( $pairs ) );
		$closed  = 0;
		$dupes   = [];
		global $wpdb;
		foreach ( $pairs as $id => $url ) {
			$owner = $owners[ $url ] ?? 0;
			if ( $owner > 0 && $owner !== $id ) {
				$dupes[] = $id;
				continue;
			}
			$wpdb->update(
				$this->uploads_table,
				[ 'catbox_url' => $url, 'error' => null, 'next_retry_at' => null ],
				[ 'id' => $id ],
				[ '%s', '%s', '%s' ],
				[ '%d' ]
			);
			$closed++;
		}
		return $closed + $this->delete_by_ids( $dupes );
	}

	/**
	 * Compare two URL expressions across a JSON_TABLE boundary. Its columns carry
	 * the server's default collation, which need not be the tables', and MariaDB
	 * refuses to compare two implicit collations. Binary is also the right rule
	 * here: a Catbox filename differing only in case is a different file.
	 */
	private static function url_eq( string $left, string $right ): string {
		return 'CONVERT(' . $left . ' USING utf8mb4) COLLATE utf8mb4_bin'
			. ' = CONVERT(' . $right . ' USING utf8mb4) COLLATE utf8mb4_bin';
	}

	/**
	 * Broken rows whose item already holds a Catbox URL for the same original.
	 *
	 * @return array<int, string> upload id => catbox URL
	 */
	private function resolved_pairs( string $upload_type, string $column ): array {
		global $wpdb;
		// Literals only ($upload_type and $column come from a fixed map), and the
		// LIKE pattern would need escaping under prepare(), so the SQL is built here.
		$sql = "SELECT u.id AS id, m.catbox_url AS catbox_url
			FROM {$this->uploads_table} u
			JOIN {$this->items_table} i ON i.guid = u.item_guid
			JOIN JSON_TABLE(
				CASE WHEN JSON_VALID(i.{$column}) THEN i.{$column} ELSE '[]' END,
				'$[*]' COLUMNS (
					original_url VARCHAR(500) PATH '$.original_url',
					catbox_url   VARCHAR(500) PATH '$.catbox_url'
				)
			) m ON " . self::url_eq( 'm.original_url', 'u.original_url' ) . "
			WHERE u.upload_type = '" . $upload_type . "'
			  AND ( u.catbox_url IS NULL OR u.catbox_url = '' )
			  AND u.error IS NOT NULL
			  AND m.catbox_url LIKE 'https://files.catbox.moe/%'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$out  = [];
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = (string) $row['catbox_url'];
		}
		return $out;
	}

	/**
	 * Which of these Catbox URLs are already claimed, and by which row.
	 *
	 * @param string[] $urls
	 * @return array<string, int> catbox URL => upload id
	 */
	private function catbox_url_owners( array $urls ): array {
		$urls = array_values( array_unique( $urls ) );
		if ( empty( $urls ) ) {
			return [];
		}
		global $wpdb;
		$owners = [];
		foreach ( array_chunk( $urls, 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows         = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id, catbox_url FROM {$this->uploads_table} WHERE catbox_url IN ({$placeholders})",
					...$chunk
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$owners[ (string) $row['catbox_url'] ] = (int) $row['id'];
			}
		}
		return $owners;
	}

	/** The audios column landed later, so an install that never reactivated lacks it. */
	private function items_column_exists( string $col ): bool {
		global $wpdb;
		$found = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SHOW COLUMNS FROM {$this->items_table} LIKE %s", $col )
		);
		return null !== $found;
	}

	/** Park an orphan so it stops hogging the NULL-first head of the retry queue. */
	public function park_orphan( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->uploads_table,
			[ 'next_retry_at' => self::ORPHAN_PARK_UNTIL ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	// Attempt log

	/** Days of attempt history kept; pruned from the daily sync. */
	public const ATTEMPT_RETENTION_DAYS = 365;

	/**
	 * Append one attempt to the audit log.
	 *
	 * @param string $trigger ingest|auto_retry|manual
	 * @param string $outcome ok|download_failed|download_gone|upload_failed
	 */
	public function log_attempt(
		string $item_guid,
		string $upload_type,
		string $original_url,
		string $trigger,
		string $outcome,
		?string $error = null
	): void {
		global $wpdb;
		$wpdb->insert(
			$this->attempts_table,
			[
				'attempted_at' => gmdate( 'Y-m-d H:i:s' ),
				'item_guid'    => $item_guid,
				'upload_type'  => $upload_type,
				'original_url' => $original_url,
				'trigger_type' => $trigger,
				'outcome'      => $outcome,
				'error'        => $error,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/** Drop attempts older than the retention window (0 = keep everything). */
	public function prune_attempts( int $retention_days ): int {
		if ( $retention_days < 1 ) {
			return 0;
		}
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $retention_days * DAY_IN_SECONDS );
		return (int) $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "DELETE FROM {$this->attempts_table} WHERE attempted_at < %s", $cutoff )
		);
	}

	/**
	 * Attempt counts by outcome over a recent window: the uploads table is state
	 * and loses the cause, so the failure rate is only observable here.
	 *
	 * @return array<string, int> outcome => count
	 */
	public function attempt_outcome_counts( int $days = 30 ): array {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT outcome, COUNT(*) AS total FROM {$this->attempts_table}
				 WHERE attempted_at >= %s GROUP BY outcome",
				$since
			),
			ARRAY_A
		);
		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['outcome'] ] = (int) $row['total'];
		}
		return $out;
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
	public function get_page( int $page, int $page_size, string $filter = 'all', int $max_attempts = 0 ): array {
		global $wpdb;
		$page      = max( 1, $page );
		$page_size = max( 1, min( 200, $page_size ) );
		$offset    = ( $page - 1 ) * $page_size;
		$where     = 'WHERE 1=1';
		// The buckets mirror the chip counts, so a chip and its list always agree.
		if ( 'unassigned' === $filter ) {
			$where .= " AND album_id IS NULL AND catbox_url IS NOT NULL AND catbox_url != ''";
		} elseif ( 'failed' === $filter ) {
			$where .= ' AND ' . self::LIVE_FAILED_WHERE . ' AND ' . $wpdb->prepare( self::UNDER_CAP, $max_attempts, $max_attempts );
		} elseif ( 'exhausted' === $filter ) {
			$where .= ' AND ' . self::LIVE_FAILED_WHERE . ' AND NOT ' . $wpdb->prepare( self::UNDER_CAP, $max_attempts, $max_attempts );
		} elseif ( 'orphaned' === $filter ) {
			$where .= $wpdb->prepare( ' AND next_retry_at = %s', self::ORPHAN_PARK_UNTIL );
		} elseif ( 'gone' === $filter ) {
			$where .= ' AND source_gone = 1';
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

	// Orphan cleanup

	/**
	 * Every upload row's identity, for the in-memory orphan scan.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all_for_orphan_scan(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, item_guid, upload_type, original_url, catbox_url, error FROM {$this->uploads_table}",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Delete upload rows by id, chunked. Returns the number of rows removed.
	 *
	 * @param int[] $ids
	 */
	public function delete_by_ids( array $ids ): int {
		if ( empty( $ids ) ) {
			return 0;
		}
		global $wpdb;
		$ids     = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$deleted = 0;
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->uploads_table} WHERE id IN ({$placeholders})", ...$chunk ) );
		}
		return $deleted;
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
