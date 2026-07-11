<?php
/**
 * CRUD for the nc_items table.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Item_Repository {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'nc_items';
	}

	public function exists( string $guid ): bool {
		global $wpdb;
		$row = $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$this->table} WHERE guid = %s LIMIT 1", $guid )
		);
		return null !== $row;
	}

	/**
	 * Insert a new parsed item. Arrays are JSON-encoded.
	 * Uses INSERT IGNORE to silently skip duplicates by guid.
	 *
	 * @param array<string, mixed> $item Item array as produced by NC_Feed_Parser.
	 */
	public function insert( array $item ): bool {
		global $wpdb;

		$article = $item['article'] ?? null;

		$data = [
			'guid'            => (string) ( $item['guid'] ?? '' ),
			'telegram_id'     => (int) ( $item['telegram_id'] ?? 0 ),
			'source'          => (string) ( $item['source'] ?? '' ),
			'source_name'     => (string) ( $item['source_name'] ?? '' ),
			'raw_description' => (string) ( $item['raw_description'] ?? '' ),
			'text'            => (string) ( $item['text'] ?? '' ),
			'images'          => wp_json_encode( $item['images'] ?? [] ),
			'videos'          => wp_json_encode( $item['videos'] ?? [] ),
			'youtube_ids'     => wp_json_encode( $item['youtube_ids'] ?? [] ),
			'article'         => $article ? wp_json_encode( $article ) : null,
			'enabled'         => 1,
			'published_at'    => (string) ( $item['published_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
			'fetched_at'      => (string) ( $item['fetched_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		];

		// $wpdb->insert doesn't support INSERT IGNORE; use a prepared query.
		$sql = "INSERT IGNORE INTO {$this->table}
			(guid, telegram_id, source, source_name, raw_description, text, images, videos, youtube_ids, article, enabled, published_at, fetched_at)
			VALUES (%s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s)";

		$result = $wpdb->query(
			$wpdb->prepare(
				$sql,
				$data['guid'],
				$data['telegram_id'],
				$data['source'],
				$data['source_name'],
				$data['raw_description'],
				$data['text'],
				$data['images'],
				$data['videos'],
				$data['youtube_ids'],
				null === $data['article'] ? '' : $data['article'],
				$data['enabled'],
				$data['published_at'],
				$data['fetched_at']
			)
		);

		// Reset article to NULL if it was inserted as empty string and article was actually null.
		if ( null === $article && $result ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table} SET article = NULL WHERE guid = %s AND article = ''",
					$data['guid']
				)
			);
		}

		return (bool) $result;
	}

	public function get_by_id( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->decode_row( $row ) : null;
	}

	public function get_by_guid( string $guid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE guid = %s LIMIT 1", $guid ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->decode_row( $row ) : null;
	}

	/** Swap one original media URL for its Catbox URL. Returns published_at or null. */
	public function replace_media_url( string $item_guid, string $upload_type, string $original_url, string $new_url ): ?string {
		$item = $this->get_by_guid( $item_guid );
		if ( null === $item ) {
			return null;
		}
		$images  = array_map( 'strval', (array) ( $item['images'] ?? [] ) );
		$videos  = (array) ( $item['videos'] ?? [] );
		$article = is_array( $item['article'] ?? null ) ? $item['article'] : null;

		if ( 'image' === $upload_type ) {
			$images = array_map(
				static fn ( string $u ): string => $u === $original_url ? $new_url : $u,
				$images
			);
		} elseif ( 'poster' === $upload_type || 'video' === $upload_type ) {
			foreach ( $videos as &$v ) {
				$v = (array) $v;
				if ( 'poster' === $upload_type && ( $v['poster_url'] ?? '' ) === $original_url ) {
					$v['poster_url'] = $new_url;
				} elseif ( 'video' === $upload_type && ( $v['original_url'] ?? '' ) === $original_url ) {
					$v['catbox_url'] = $new_url;
					$v['status']     = 'ok';
				}
			}
			unset( $v );
		} elseif ( 'article_image' === $upload_type && is_array( $article ) && ( $article['image_url'] ?? '' ) === $original_url ) {
			$article['image_url'] = $new_url;
		}

		$this->update_media( (int) $item['id'], array_values( $images ), array_values( $videos ), $article );
		return isset( $item['published_at'] ) ? (string) $item['published_at'] : null;
	}

	/**
	 * Return all item IDs in ascending order. Useful for batch maintenance jobs.
	 *
	 * @return int[]
	 */
	public function get_all_ids(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( "SELECT id FROM {$this->table} ORDER BY id ASC" );
		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	/**
	 * Lightweight (id, source, images) for every item, for batch media
	 * maintenance (e.g. recurring channel-cover detection). Images are decoded.
	 *
	 * @return array<int, array{id:int, source:string, images:array<int,string>}>
	 */
	public function get_all_image_refs(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, source, images FROM {$this->table}", ARRAY_A );
		$out  = [];
		foreach ( (array) $rows as $row ) {
			$images = [];
			$raw    = $row['images'] ?? '';
			if ( is_string( $raw ) && '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$images = array_map( 'strval', $decoded );
				}
			}
			$out[] = [
				'id'     => (int) $row['id'],
				'source' => (string) $row['source'],
				'images' => $images,
			];
		}
		return $out;
	}

	/**
	 * A few item IDs of a source whose images contain the given Catbox URL,
	 * for reviewing a cover candidate. Matches on the bare filename to dodge
	 * JSON slash-escaping in the stored column.
	 *
	 * @return int[]
	 */
	public function find_ids_with_image( string $source, string $catbox_url, int $limit = 3 ): array {
		global $wpdb;
		$needle = '%' . $wpdb->esc_like( basename( $catbox_url ) ) . '%';
		$ids    = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE source = %s AND images LIKE %s ORDER BY published_at DESC LIMIT %d",
				$source,
				$needle,
				max( 1, $limit )
			)
		);
		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	/**
	 * Update the media-related fields on an existing row.
	 *
	 * @param string[]                       $images
	 * @param array<int, array<string, mixed>> $videos
	 * @param array<string, mixed>|null      $article
	 */
	public function update_media( int $id, array $images, array $videos, ?array $article ): bool {
		global $wpdb;
		$data = [
			'images'  => wp_json_encode( $images ),
			'videos'  => wp_json_encode( $videos ),
			'article' => null === $article ? null : wp_json_encode( $article ),
		];
		$rows = $wpdb->update(
			$this->table,
			$data,
			[ 'id' => $id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
		return false !== $rows;
	}

	public function update_text( int $id, string $text ): bool {
		global $wpdb;
		$rows = $wpdb->update(
			$this->table,
			[ 'text' => $text ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);
		return false !== $rows;
	}

	public function set_enabled( int $id, bool $enabled ): bool {
		global $wpdb;
		$rows = $wpdb->update(
			$this->table,
			[ 'enabled' => $enabled ? 1 : 0 ],
			[ 'id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);
		return (bool) $rows;
	}

	public function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * @param int[] $ids
	 * @return int Number of rows deleted.
	 */
	public function bulk_delete( array $ids ): int {
		if ( empty( $ids ) ) {
			return 0;
		}
		global $wpdb;
		$ids         = array_map( 'intval', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "DELETE FROM {$this->table} WHERE id IN ({$placeholders})";
		return (int) $wpdb->query( $wpdb->prepare( $sql, ...$ids ) );
	}

	/**
	 * @param int[] $ids
	 */
	public function bulk_set_enabled( array $ids, bool $enabled ): int {
		if ( empty( $ids ) ) {
			return 0;
		}
		global $wpdb;
		$ids          = array_map( 'intval', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$value        = $enabled ? 1 : 0;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "UPDATE {$this->table} SET enabled = %d WHERE id IN ({$placeholders})";
		return (int) $wpdb->query( $wpdb->prepare( $sql, $value, ...$ids ) );
	}

	/**
	 * Public-facing pagination: only enabled items.
	 * $source accepts a single handle or a comma-separated list (e.g. "foo,bar").
	 *
	 * @return array<string, mixed>
	 */
	public function get_page( int $page, int $page_size, string $source = '' ): array {
		$conditions = [ 'enabled = 1' ];
		$params     = [];

		if ( '' !== $source ) {
			$handles = array_values( array_filter( array_map( 'trim', explode( ',', $source ) ) ) );
			if ( count( $handles ) === 1 ) {
				$conditions[] = 'source = %s';
				$params[]     = $handles[0];
			} elseif ( count( $handles ) > 1 ) {
				$placeholders = implode( ', ', array_fill( 0, count( $handles ), '%s' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$conditions[] = "source IN ({$placeholders})";
				array_push( $params, ...$handles );
			}
		}

		return $this->fetch_page( $page, $page_size, $conditions, $params );
	}

	// An image/cover still on a non-Catbox URL means its upload failed. Literals
	// only, no input, so it is safe to interpolate.
	private const PENDING_IMAGE_SQL = "(
		EXISTS (
			SELECT 1 FROM JSON_TABLE(
				CASE WHEN JSON_VALID(images) THEN images ELSE '[]' END,
				'$[*]' COLUMNS ( val VARCHAR(500) PATH '$' )
			) jt
			WHERE jt.val LIKE 'http%' AND jt.val NOT LIKE 'https://files.catbox.moe/%'
		)
		OR (
			JSON_VALID(article)
			AND JSON_UNQUOTE(JSON_EXTRACT(article, '$.image_url')) LIKE 'http%'
			AND JSON_UNQUOTE(JSON_EXTRACT(article, '$.image_url')) NOT LIKE 'https://files.catbox.moe/%'
		)
		OR EXISTS (
			SELECT 1 FROM JSON_TABLE(
				CASE WHEN JSON_VALID(videos) THEN videos ELSE '[]' END,
				'$[*]' COLUMNS ( poster VARCHAR(500) PATH '$.poster_url' )
			) jv
			WHERE jv.poster LIKE 'http%' AND jv.poster NOT LIKE 'https://files.catbox.moe/%'
		)
	)";

	/**
	 * Admin pagination: all items + optional video filter.
	 *
	 * Filter values:
	 *  - 'all'           → no extra WHERE
	 *  - 'too_big'       → videos LIKE '%"too_big"%'
	 *  - 'upload_failed' → failed video OR (when Catbox is on) an image/cover
	 *                      left on its original non-Catbox URL
	 *  - 'hidden'        → enabled = 0
	 *
	 * @return array<string, mixed>
	 */
	public function get_page_admin( int $page, int $page_size, string $video_filter = 'all', bool $catbox_enabled = false ): array {
		[ $conditions, $params ] = $this->admin_filter_clause( $video_filter, $catbox_enabled );
		return $this->fetch_page( $page, $page_size, $conditions, $params );
	}

	/**
	 * Build the WHERE conditions + params for an admin filter key.
	 *
	 * @return array{0: string[], 1: array<int, mixed>}
	 */
	private function admin_filter_clause( string $video_filter, bool $catbox_enabled = false ): array {
		$conditions = [];
		$params     = [];
		switch ( $video_filter ) {
			case 'too_big':
				$conditions[] = 'videos LIKE %s';
				$params[]     = '%"too_big"%';
				break;
			case 'upload_failed':
				if ( $catbox_enabled ) {
					$conditions[] = '( videos LIKE %s OR ' . self::PENDING_IMAGE_SQL . ' )';
				} else {
					$conditions[] = 'videos LIKE %s';
				}
				$params[] = '%"upload_failed"%';
				break;
			case 'hidden':
				$conditions[] = 'enabled = 0';
				break;
			case 'all':
			default:
				break;
		}
		return [ $conditions, $params ];
	}

	/**
	 * Count items matching an admin filter key, for the filter links.
	 */
	public function count_admin( string $video_filter = 'all', bool $catbox_enabled = false ): int {
		global $wpdb;
		[ $conditions, $params ] = $this->admin_filter_clause( $video_filter, $catbox_enabled );
		$where = empty( $conditions ) ? '' : ( 'WHERE ' . implode( ' AND ', $conditions ) );
		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} {$where}" );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} {$where}", ...$params ) );
	}

	/**
	 * @param string[] $conditions
	 * @param array<int, mixed> $params
	 * @return array<string, mixed>
	 */
	private function fetch_page( int $page, int $page_size, array $conditions, array $params ): array {
		global $wpdb;
		$page      = max( 1, $page );
		$page_size = max( 1, $page_size );
		$offset    = ( $page - 1 ) * $page_size;

		$where = empty( $conditions ) ? '' : ( 'WHERE ' . implode( ' AND ', $conditions ) );

		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} {$where}" );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} {$where}", ...$params ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT * FROM {$this->table} {$where} ORDER BY published_at DESC, telegram_id DESC LIMIT %d OFFSET %d";
		$all_params = array_merge( $params, [ $page_size, $offset ] );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$all_params ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : [];

		$total_pages = $total > 0 ? (int) ceil( $total / $page_size ) : 1;

		return [
			'page'        => $page,
			'page_size'   => $page_size,
			'total_items' => $total,
			'total_pages' => $total_pages,
			'has_next'    => $page < $total_pages,
			'has_prev'    => $page > 1,
			'items'       => array_map( [ $this, 'decode_row' ], $rows ),
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function decode_row( array $row ): array {
		foreach ( [ 'images', 'videos', 'youtube_ids' ] as $key ) {
			$raw = $row[ $key ] ?? '';
			if ( is_string( $raw ) && '' !== $raw ) {
				$decoded   = json_decode( $raw, true );
				$row[ $key ] = is_array( $decoded ) ? $decoded : [];
			} else {
				$row[ $key ] = [];
			}
		}
		$raw_article = $row['article'] ?? null;
		if ( is_string( $raw_article ) && '' !== $raw_article ) {
			$decoded         = json_decode( $raw_article, true );
			$row['article'] = is_array( $decoded ) ? $decoded : null;
		} else {
			$row['article'] = null;
		}
		$row['enabled']     = (int) ( $row['enabled'] ?? 1 );
		$row['telegram_id'] = (int) ( $row['telegram_id'] ?? 0 );
		$row['id']          = (int) ( $row['id'] ?? 0 );
		return $row;
	}
}
