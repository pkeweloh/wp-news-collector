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
					$album_id = $this->catbox->create_album( 'News Collector - ' . $month );
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
