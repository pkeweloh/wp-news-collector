<?php
/**
 * Daily reconciliation: track catbox uploads from nc_items and assign to monthly albums.
 * Port of alerta-boe/app/catbox_syncer.py.
 *
 * Phase 1: scan nc_items for catbox URLs not yet in nc_catbox_uploads.
 * Phase 2: get-or-create the monthly album and call add_to_album.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Catbox_Syncer {

	private const CATBOX_PREFIX = 'https://files.catbox.moe/';

	public function __construct(
		private NC_Item_Repository $items,
		private NC_Catbox_Upload_Repository $uploads,
		private NC_Catbox_Uploader $catbox
	) {}

	/**
	 * Run both sync phases. Saves result to option nc_catbox_sync_stats.
	 *
	 * @return array{tracked:int, assigned:int, errors:string[], ran_at:string}
	 */
	public function run_sync(): array {
		$stats = [ 'tracked' => 0, 'assigned' => 0, 'errors' => [] ];
		$this->fill_missing_tracking( $stats );
		$this->assign_to_albums( $stats );
		$stats['ran_at'] = gmdate( 'Y-m-d H:i:s' );
		update_option( 'nc_catbox_sync_stats', $stats );
		return $stats;
	}

	/** @return array<string, mixed> */
	public function retry_upload( int $upload_id ): array {
		$row = $this->uploads->get_by_id( $upload_id );
		if ( null === $row ) {
			return [ 'ok' => false, 'not_found' => true, 'error' => 'Upload not found' ];
		}
		if ( '' !== (string) ( $row['catbox_url'] ?? '' ) ) {
			return [ 'ok' => true, 'already_done' => true, 'catbox_url' => (string) $row['catbox_url'] ];
		}

		$guid        = (string) ( $row['item_guid'] ?? '' );
		$upload_type = (string) ( $row['upload_type'] ?? '' );
		$original    = (string) ( $row['original_url'] ?? '' );
		$item        = $this->items->get_by_guid( $guid ) ?? [];
		$src_url     = $this->upload_source( $item, $upload_type, $original );

		try {
			$new_url = $this->catbox->upload_from_url( $src_url );
		} catch ( NC_Catbox_Exception $e ) {
			$this->uploads->set_result( $upload_id, null, $e->getMessage() );
			return [ 'ok' => false, 'error' => $e->getMessage() ];
		}

		$published_at = $this->items->replace_media_url( $guid, $upload_type, $original, $new_url );
		$this->uploads->set_result( $upload_id, $new_url, null );
		$album_id = $this->assign_one_to_album( $new_url, $published_at );
		return [ 'ok' => true, 'catbox_url' => $new_url, 'album_id' => $album_id ];
	}

	/** @return array<string, mixed> */
	public function retry_item( int $item_id ): array {
		$item = $this->items->get_by_id( $item_id );
		if ( null === $item ) {
			return [ 'ok' => false, 'not_found' => true ];
		}
		$guid        = (string) ( $item['guid'] ?? '' );
		$source      = (string) ( $item['source'] ?? '' );
		$source_name = (string) ( $item['source_name'] ?? '' );

		$results = [];
		foreach ( $this->pending_pieces( $item ) as [ $upload_type, $original ] ) {
			$src_url = $this->upload_source( $item, $upload_type, $original );
			try {
				$new_url = $this->catbox->upload_from_url( $src_url );
			} catch ( NC_Catbox_Exception $e ) {
				$this->uploads->resolve_result( $source, $source_name, $guid, $upload_type, $original, null, $e->getMessage() );
				$results[] = [ 'type' => $upload_type, 'error' => $e->getMessage() ];
				continue;
			}
			$this->items->replace_media_url( $guid, $upload_type, $original, $new_url );
			$this->uploads->resolve_result( $source, $source_name, $guid, $upload_type, $original, $new_url, null );
			$this->assign_one_to_album( $new_url, (string) ( $item['published_at'] ?? '' ) );
			$results[] = [ 'type' => $upload_type, 'catbox_url' => $new_url ];
		}

		$failed = 0;
		foreach ( $results as $r ) {
			if ( isset( $r['error'] ) ) {
				$failed++;
			}
		}
		return [
			'ok'       => 0 === $failed,
			'pending'  => count( $results ),
			'uploaded' => count( $results ) - $failed,
			'failed'   => $failed,
			'results'  => $results,
		];
	}

	private function assign_one_to_album( string $catbox_url, ?string $published_at ): ?string {
		$month = substr( (string) $published_at, 0, 7 );
		if ( '' === $month || strlen( $month ) !== 7 ) {
			return null;
		}
		$album_id = $this->uploads->get_album_for_month( $month );
		if ( '' === $album_id ) {
			try {
				$album_id = $this->catbox->create_album( NC_Plugin::catbox_album_name( $month ) );
				$this->uploads->save_album_for_month( $month, $album_id );
			} catch ( NC_Catbox_Exception $e ) {
				return null;
			}
		}
		try {
			$this->catbox->add_to_album( $album_id, [ basename( $catbox_url ) ] );
			$this->uploads->set_album( $catbox_url, $album_id );
		} catch ( NC_Catbox_Exception $e ) {
			return $album_id;
		}
		return $album_id;
	}

	// For an article cover, re-derive from the live og:image (stored URL may
	// have expired), then fall back to a YouTube thumbnail.
	/** @param array<string, mixed> $item */
	private function upload_source( array $item, string $upload_type, string $original_url ): string {
		if ( 'article_image' === $upload_type ) {
			$article = is_array( $item['article'] ?? null ) ? $item['article'] : [];
			$url     = (string) ( $article['url'] ?? '' );
			if ( '' !== $url ) {
				$fresh = NC_OG_Scraper::fetch( $url )['image'];
				if ( '' !== $fresh ) {
					return $fresh;
				}
				// og failed (e.g. YouTube consent page): derive from the video id.
				$yt = NC_Feed_Parser::extract_youtube_id( $url );
				if ( '' !== $yt ) {
					return NC_OG_Scraper::youtube_thumbnail( $yt );
				}
			}
			$ids = (array) ( $item['youtube_ids'] ?? [] );
			if ( ! empty( $ids ) ) {
				return NC_OG_Scraper::youtube_thumbnail( (string) $ids[0] );
			}
		}
		return $original_url;
	}

	/**
	 * @param array<string, mixed> $item
	 * @return list<array{0:string, 1:string}>  [[upload_type, original_url], ...]
	 */
	private function pending_pieces( array $item ): array {
		$pieces = [];
		foreach ( (array) ( $item['images'] ?? [] ) as $url ) {
			if ( $this->is_original( (string) $url ) ) {
				$pieces[] = [ 'image', (string) $url ];
			}
		}
		foreach ( (array) ( $item['videos'] ?? [] ) as $v ) {
			$v = (array) $v;
			if ( $this->is_original( (string) ( $v['poster_url'] ?? '' ) ) ) {
				$pieces[] = [ 'poster', (string) $v['poster_url'] ];
			}
			// Skip too_big videos: they are intentionally not uploaded.
			if ( ( $v['status'] ?? '' ) !== 'too_big'
				&& '' !== (string) ( $v['original_url'] ?? '' )
				&& ! $this->is_catbox( (string) ( $v['catbox_url'] ?? '' ) ) ) {
				$pieces[] = [ 'video', (string) $v['original_url'] ];
			}
		}
		$article = is_array( $item['article'] ?? null ) ? $item['article'] : null;
		if ( is_array( $article ) && $this->is_original( (string) ( $article['image_url'] ?? '' ) ) ) {
			$pieces[] = [ 'article_image', (string) $article['image_url'] ];
		}
		return $pieces;
	}

	private function is_original( string $url ): bool {
		return '' !== $url && 0 === strpos( $url, 'http' ) && ! $this->is_catbox( $url );
	}

	// Phase 1

	/**
	 * @param array{tracked:int, assigned:int, errors:string[]} $stats
	 */
	private function fill_missing_tracking( array &$stats ): void {
		foreach ( $this->items->get_all_ids() as $id ) {
			$item = $this->items->get_by_id( $id );
			if ( null === $item ) {
				continue;
			}
			$uploaded_at = (string) ( $item['fetched_at'] ?? gmdate( 'Y-m-d H:i:s' ) );
			foreach ( $this->extract_catbox_urls( $item ) as [ $catbox_url, $upload_type ] ) {
				$inserted = $this->uploads->insert_if_missing(
					(string) ( $item['source'] ?? '' ),
					(string) ( $item['source_name'] ?? '' ),
					(string) ( $item['guid'] ?? '' ),
					$upload_type,
					$catbox_url,
					$uploaded_at
				);
				if ( $inserted ) {
					$stats['tracked']++;
				}
			}
		}
	}

	// Phase 2

	/**
	 * @param array{tracked:int, assigned:int, errors:string[]} $stats
	 */
	private function assign_to_albums( array &$stats ): void {
		$unassigned = $this->uploads->get_unassigned_by_month();
		if ( empty( $unassigned ) ) {
			return;
		}

		// Group by month of published_at.
		$by_month = [];
		foreach ( $unassigned as $row ) {
			$month = substr( (string) ( $row['published_at'] ?? '' ), 0, 7 );
			if ( '' === $month || strlen( $month ) !== 7 ) {
				continue;
			}
			$by_month[ $month ][] = $row;
		}

		foreach ( $by_month as $month => $rows ) {
			$album_id = $this->uploads->get_album_for_month( $month );
			if ( '' === $album_id ) {
				try {
					$album_name = NC_Plugin::catbox_album_name( $month );
					$album_id   = $this->catbox->create_album( $album_name );
					$this->uploads->save_album_for_month( $month, $album_id );
				} catch ( NC_Catbox_Exception $e ) {
					$stats['errors'][] = sprintf( 'Album creation failed (%s): %s', $month, $e->getMessage() );
					continue;
				}
			}

			// Deduplicate filenames for this batch.
			$filenames = [];
			$seen      = [];
			foreach ( $rows as $row ) {
				$filename = basename( (string) $row['catbox_url'] );
				if ( '' !== $filename && ! isset( $seen[ $filename ] ) ) {
					$filenames[]          = $filename;
					$seen[ $filename ]    = true;
				}
			}

			if ( empty( $filenames ) ) {
				continue;
			}

			try {
				$this->catbox->add_to_album( $album_id, $filenames );
				foreach ( $rows as $row ) {
					$this->uploads->set_album( (string) $row['catbox_url'], $album_id );
					$stats['assigned']++;
				}
			} catch ( NC_Catbox_Exception $e ) {
				$stats['errors'][] = sprintf( 'Album add failed (%s/%s): %s', $month, $album_id, $e->getMessage() );
			}
		}
	}

	// Helpers

	/**
	 * Extract all catbox URLs from a decoded item row with their upload type.
	 *
	 * @param array<string, mixed> $item
	 * @return list<array{0:string, 1:string}>  [[catbox_url, upload_type], ...]
	 */
	private function extract_catbox_urls( array $item ): array {
		$results = [];

		foreach ( (array) ( $item['images'] ?? [] ) as $url ) {
			if ( $this->is_catbox( (string) $url ) ) {
				$results[] = [ (string) $url, 'image' ];
			}
		}

		foreach ( (array) ( $item['videos'] ?? [] ) as $v ) {
			$v = (array) $v;
			if ( $this->is_catbox( (string) ( $v['poster_url'] ?? '' ) ) ) {
				$results[] = [ (string) $v['poster_url'], 'poster' ];
			}
			if ( $this->is_catbox( (string) ( $v['catbox_url'] ?? '' ) ) ) {
				$results[] = [ (string) $v['catbox_url'], 'video' ];
			}
		}

		$article = is_array( $item['article'] ?? null ) ? $item['article'] : null;
		if ( is_array( $article ) && $this->is_catbox( (string) ( $article['image_url'] ?? '' ) ) ) {
			$results[] = [ (string) $article['image_url'], 'article_image' ];
		}

		return $results;
	}

	private function is_catbox( string $url ): bool {
		return '' !== $url && 0 === strpos( $url, self::CATBOX_PREFIX );
	}
}
