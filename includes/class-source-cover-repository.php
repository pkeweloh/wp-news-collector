<?php
/**
 * Repository for channel-cover images (nc_source_covers).
 *
 * A "cover" is an image (identified by its deduped Catbox URL) that recurs
 * across many posts of the same source. Frequency only PROPOSES candidates;
 * a human confirms which are real covers, because the heuristic cannot tell a
 * channel logo (ARDI/El Diestro) from a legitimately recurring image such as a
 * daily livestream cover (CANAL 5TV). Binary status:
 *   - candidate: not (or no longer) a confirmed cover; kept on items
 *   - confirmed: a real cover; stripped from items, eligible as channel icon
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Source_Cover_Repository {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'nc_source_covers';
	}

	/**
	 * Record a detected candidate. Idempotent on (source, catbox_url): refreshes
	 * the post count but NEVER changes an existing human decision (status).
	 */
	public function upsert_candidate( string $source, string $catbox_url, string $original_url, int $post_count ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->table}
				 (source, catbox_url, original_url, post_count, status, is_icon, created_at, updated_at)
				 VALUES (%s, %s, %s, %d, 'candidate', 0, %s, %s)
				 ON DUPLICATE KEY UPDATE
				 post_count = VALUES(post_count),
				 original_url = VALUES(original_url),
				 updated_at = VALUES(updated_at)",
				$source,
				$catbox_url,
				$original_url,
				$post_count,
				$now,
				$now
			)
		);
	}

	/**
	 * Set the review status of a cover.
	 */
	public function set_status( string $source, string $catbox_url, string $status ): void {
		global $wpdb;
		$data    = [ 'status' => $status, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ];
		$formats = [ '%s', '%s' ];
		// Only a confirmed cover can be the channel icon; clear it otherwise.
		if ( 'confirmed' !== $status ) {
			$data['is_icon'] = 0;
			$formats[]       = '%d';
		}
		$wpdb->update(
			$this->table,
			$data,
			[ 'source' => $source, 'catbox_url' => $catbox_url ],
			$formats,
			[ '%s', '%s' ]
		);
	}

	/**
	 * Flag the highest-frequency confirmed cover of a source as its display
	 * icon, clearing the flag on the others.
	 */
	public function set_primary_icon( string $source ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$this->table} SET is_icon = 0 WHERE source = %s", $source )
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET is_icon = 1
				 WHERE source = %s AND status = 'confirmed'
				 ORDER BY post_count DESC, id ASC LIMIT 1",
				$source
			)
		);
	}

	/**
	 * Map of source => confirmed cover Catbox URLs. Used to strip covers from
	 * items, both on ingest and when applying a confirmation.
	 *
	 * @return array<string, string[]>
	 */
	public function get_confirmed_urls_by_source(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT source, catbox_url FROM {$this->table} WHERE status = 'confirmed'",
			ARRAY_A
		);
		$map = [];
		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['source'] ][] = (string) $row['catbox_url'];
		}
		return $map;
	}

	/**
	 * URLs already reviewed (confirmed or ignored) for a source, so detection
	 * can skip re-counting decisions that are already made for the listing.
	 *
	 * @return array<string, string> catbox_url => status
	 */
	public function get_statuses_for_source( string $source ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT catbox_url, status FROM {$this->table} WHERE source = %s", $source ),
			ARRAY_A
		);
		$map = [];
		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['catbox_url'] ] = (string) $row['status'];
		}
		return $map;
	}

	/**
	 * All covers, grouped for the admin listing (candidates first).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT source, catbox_url, original_url, post_count, status, is_icon, updated_at
			 FROM {$this->table}
			 ORDER BY FIELD(status, 'candidate', 'confirmed', 'ignored'), source ASC, post_count DESC",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Map of source => icon Catbox URL (the confirmed cover flagged as is_icon).
	 *
	 * @return array<string, string>
	 */
	public function get_icons(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT source, catbox_url FROM {$this->table} WHERE is_icon = 1",
			ARRAY_A
		);
		$map = [];
		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['source'] ] = (string) $row['catbox_url'];
		}
		return $map;
	}

	/**
	 * Number of unreviewed candidates, for the admin badge.
	 */
	public function count_candidates(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE status = 'candidate'" );
	}

	public function count_total(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
	}
}
