<?php
/**
 * CRUD for the nc_sources table.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Source_Repository {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'nc_sources';
	}

	/**
	 * Active sources (enabled=1): used by the processor.
	 *
	 * @return array<int, array{url:string,name:string}>
	 */
	public function get_active(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted
		$rows = $wpdb->get_results( "SELECT url, name FROM {$this->table} WHERE enabled = 1 ORDER BY id ASC", ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * All sources for the admin list (all columns).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all_admin(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, url, name, enabled, created_at FROM {$this->table} ORDER BY id ASC", ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}

	public function get_by_id( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, url, name, enabled, created_at FROM {$this->table} WHERE id = %d", $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	public function add( string $url, string $name = '' ): bool {
		global $wpdb;
		$url  = trim( $url );
		$name = trim( $name );
		if ( '' === $url ) {
			return false;
		}
		$now      = gmdate( 'Y-m-d H:i:s' );
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$this->table} (url, name, enabled, created_at) VALUES (%s, %s, 1, %s)",
				$url,
				$name,
				$now
			)
		);
		// If a row already existed with empty name, refresh the name.
		if ( $inserted === 0 && '' !== $name ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table} SET name = %s WHERE url = %s AND name = ''",
					$name,
					$url
				)
			);
		}
		return false !== $inserted;
	}

	public function update( int $id, ?string $name = null, ?bool $enabled = null ): bool {
		global $wpdb;
		$data   = [];
		$format = [];
		if ( null !== $name ) {
			$data['name'] = $name;
			$format[]     = '%s';
		}
		if ( null !== $enabled ) {
			$data['enabled'] = $enabled ? 1 : 0;
			$format[]        = '%d';
		}
		if ( empty( $data ) ) {
			return false;
		}
		$rows = $wpdb->update( $this->table, $data, [ 'id' => $id ], $format, [ '%d' ] );
		return false !== $rows;
	}

	public function delete( int $id ): bool {
		global $wpdb;
		$rows = $wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );
		return (bool) $rows;
	}
}
