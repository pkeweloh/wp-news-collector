<?php
/**
 * News processing pipeline: orchestrates fetch / parse / enrich / save.
 *
 * Mirrors alerta-boe/app/news_processor.py.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_News_Processor {

	private const FETCH_TIMEOUT = 60;

	public function __construct(
		private NC_Source_Repository $sources,
		private NC_Item_Repository $items,
		private NC_Catbox_Uploader $catbox,
		private array $settings,
		private ?NC_Catbox_Upload_Repository $uploads = null,
	) {}

	/**
	 * Refresh settings (useful when invoked from a long-lived process).
	 *
	 * @param array<string, mixed> $settings
	 */
	public function set_settings( array $settings ): void {
		$this->settings = $settings;
	}

	/**
	 * @return array{fetched:int, inserted:int, skipped:int, errors:string[]}
	 */
	public function run_cycle(): array {
		$stats    = [ 'fetched' => 0, 'inserted' => 0, 'skipped' => 0, 'errors' => [] ];
		$album_id = '';
		if ( ! empty( $this->settings['catbox_enabled'] ) && $this->uploads ) {
			$album_id = $this->get_or_create_current_album( $stats );
		}
		foreach ( $this->sources->get_active() as $src ) {
			$this->run_for_source( (string) $src['url'], (string) $src['name'], $stats, $album_id );
		}
		return $stats;
	}

	/**
	 * Get or create the album for the current month. Returns the album short code or ''.
	 *
	 * @param array{fetched:int, inserted:int, skipped:int, errors:string[]} $stats
	 */
	private function get_or_create_current_album( array &$stats ): string {
		if ( ! $this->uploads ) {
			return '';
		}
		$month    = gmdate( 'Y-m' );
		$album_id = $this->uploads->get_album_for_month( $month );
		if ( '' === $album_id ) {
			try {
				$album_id = $this->catbox->create_album( 'News Collector - ' . $month );
				$this->uploads->save_album_for_month( $month, $album_id );
			} catch ( NC_Catbox_Exception $e ) {
				$stats['errors'][] = 'Album creation failed: ' . $e->getMessage();
				return '';
			}
		}
		return $album_id;
	}

	/**
	 * @param array{fetched:int, inserted:int, skipped:int, errors:string[]} $stats
	 */
	private function run_for_source( string $rss_url, string $name, array &$stats, string $album_id = '' ): void {
		$response = wp_remote_get( $rss_url, [ 'timeout' => self::FETCH_TIMEOUT ] );
		if ( is_wp_error( $response ) ) {
			$stats['errors'][] = sprintf( 'RSS fetch failed (%s): %s', $rss_url, $response->get_error_message() );
			return;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$stats['errors'][] = sprintf( 'RSS HTTP %d (%s)', $code, $rss_url );
			return;
		}
		$body = (string) wp_remote_retrieve_body( $response );

		$source       = NC_Feed_Parser::source_from_url( $rss_url );
		$display_name = '' !== $name ? $name : $source;
		$parsed       = NC_Feed_Parser::parse_feed( $body, $source );

		$stats['fetched'] += count( $parsed );

		$max_inserts = (int) ( $this->settings['max_items_per_source'] ?? 50 );
		$inserts_for_source = 0;

		foreach ( $parsed as $item ) {
			$item['source_name'] = $display_name;

			if ( $this->items->exists( (string) $item['guid'] ) ) {
				$stats['skipped']++;
				continue;
			}

			$item = $this->enrich_item( $item, $stats );
			$this->items->insert( $item );
			$stats['inserted']++;

			// Assign all catbox URLs for this item to the monthly album.
			if ( '' !== $album_id && $this->uploads ) {
				$this->assign_item_to_album( $item, $album_id, $stats );
			}
			$inserts_for_source++;

			if ( $max_inserts > 0 && $inserts_for_source >= $max_inserts ) {
				break;
			}
		}
	}

	/**
	 * Apply OG scrape, Catbox upload, redirect resolution.
	 *
	 * @param array<string, mixed> $item
	 * @param array{fetched:int, inserted:int, skipped:int, errors:string[]} $stats
	 * @return array<string, mixed>
	 */
	private function enrich_item( array $item, array &$stats ): array {
		$source      = (string) ( $item['source'] ?? '' );
		$source_name = (string) ( $item['source_name'] ?? '' );
		$guid        = (string) ( $item['guid'] ?? '' );

		// 1) OG fetch for article URL (always: gives site_name; fallback image too).
		$article = $item['article'];
		if ( is_array( $article ) && '' !== ( $article['url'] ?? '' ) ) {
			$og = NC_OG_Scraper::fetch( (string) $article['url'] );
			if ( '' !== $og['site_name'] ) {
				$article['site_name'] = $og['site_name'];
			}
			if ( empty( $item['images'] ) && '' !== $og['image'] ) {
				$item['images'] = [ $og['image'] ];
			}
			$item['article'] = $article;
		}

		// 2) If still no images, scan other hrefs in text for OG image.
		if ( empty( $item['images'] ) ) {
			foreach ( $this->og_candidate_urls( $item ) as $candidate ) {
				if ( is_array( $item['article'] ) && $candidate === ( $item['article']['url'] ?? '' ) ) {
					continue;
				}
				$og = NC_OG_Scraper::fetch( $candidate );
				if ( '' !== $og['image'] ) {
					$item['images'] = [ $og['image'] ];
					break;
				}
			}
		}

		// 3) Catbox uploads
		if ( ! empty( $this->settings['catbox_enabled'] ) ) {
			$uploaded_images = [];
			foreach ( (array) $item['images'] as $img_url ) {
				$catbox_url        = $this->try_upload( (string) $img_url, $stats, $source, $source_name, $guid, 'image' );
				$uploaded_images[] = '' !== $catbox_url ? $catbox_url : $img_url;
			}
			$item['images'] = $uploaded_images;

			$updated_videos = [];
			foreach ( (array) $item['videos'] as $video ) {
				$video = (array) $video;
				if ( '' !== ( $video['poster_url'] ?? '' ) ) {
					$catbox_url = $this->try_upload( (string) $video['poster_url'], $stats, $source, $source_name, $guid, 'poster' );
					if ( '' !== $catbox_url ) {
						$video['poster_url'] = $catbox_url;
					}
				}
				if ( ( $video['status'] ?? '' ) !== 'too_big' && '' !== ( $video['original_url'] ?? '' ) ) {
					$catbox_url = $this->try_upload( (string) $video['original_url'], $stats, $source, $source_name, $guid, 'video' );
					if ( '' !== $catbox_url ) {
						$video['catbox_url'] = $catbox_url;
						$video['status']     = 'ok';
					} else {
						$video['status'] = 'upload_failed';
					}
				}
				$updated_videos[] = $video;
			}
			$item['videos'] = $updated_videos;

			if ( is_array( $item['article'] ) && '' !== ( $item['article']['image_url'] ?? '' ) ) {
				$catbox_url = $this->try_upload( (string) $item['article']['image_url'], $stats, $source, $source_name, $guid, 'article_image' );
				if ( '' !== $catbox_url ) {
					$item['article']['image_url'] = $catbox_url;
				}
			}
		}

		// 4) Resolve shortener for article URL
		if ( is_array( $item['article'] ) && '' !== ( $item['article']['url'] ?? '' ) ) {
			$item['article']['url'] = NC_Redirect_Resolver::resolve( (string) $item['article']['url'] );
		}

		return $item;
	}

	/**
	 * Collect catbox URLs from an enriched item and add them to the monthly album.
	 *
	 * @param array<string, mixed> $item
	 * @param array{fetched:int, inserted:int, skipped:int, errors:string[]} $stats
	 */
	private function assign_item_to_album( array $item, string $album_id, array &$stats ): void {
		$catbox_urls = [];
		foreach ( (array) ( $item['images'] ?? [] ) as $url ) {
			if ( self::is_catbox_url( (string) $url ) ) {
				$catbox_urls[] = (string) $url;
			}
		}
		foreach ( (array) ( $item['videos'] ?? [] ) as $v ) {
			$v = (array) $v;
			foreach ( [ 'poster_url', 'catbox_url' ] as $key ) {
				if ( self::is_catbox_url( (string) ( $v[ $key ] ?? '' ) ) ) {
					$catbox_urls[] = (string) $v[ $key ];
				}
			}
		}
		$article = is_array( $item['article'] ?? null ) ? $item['article'] : null;
		if ( is_array( $article ) && self::is_catbox_url( (string) ( $article['image_url'] ?? '' ) ) ) {
			$catbox_urls[] = (string) $article['image_url'];
		}

		if ( empty( $catbox_urls ) ) {
			return;
		}
		$filenames = array_values( array_unique( array_map( 'basename', $catbox_urls ) ) );
		try {
			$this->catbox->add_to_album( $album_id, $filenames );
			foreach ( $catbox_urls as $url ) {
				$this->uploads->set_album( $url, $album_id );
			}
		} catch ( NC_Catbox_Exception $e ) {
			$stats['errors'][] = sprintf( 'Album add failed (%s): %s', $album_id, $e->getMessage() );
		}
	}

	/**
	 * @param array<string, mixed> $item
	 * @return string[]
	 */
	private function og_candidate_urls( array $item ): array {
		$urls = [];
		if ( is_array( $item['article'] ) && '' !== ( $item['article']['url'] ?? '' ) ) {
			$urls[] = (string) $item['article']['url'];
		}
		$text = (string) ( $item['text'] ?? '' );
		if ( preg_match_all( '~href="(https?://[^"]+)"~', $text, $m ) ) {
			foreach ( $m[1] as $href ) {
				if ( ! in_array( $href, $urls, true ) ) {
					$urls[] = $href;
				}
			}
		}
		return $urls;
	}

	/**
	 * Re-upload to Catbox any media still pointing at original (non-Catbox) URLs.
	 * Idempotent. Pass $ids to limit to specific items; empty array = all items.
	 *
	 * @param int[] $ids  Specific item IDs to process. Empty = all items.
	 * @return array{processed:int, updated:int, uploaded:int, skipped:int, errors:string[]}
	 */
	public function backfill_catbox( array $ids = [] ): array {
		$stats = [ 'processed' => 0, 'updated' => 0, 'uploaded' => 0, 'skipped' => 0, 'errors' => [], 'fetched' => 0, 'inserted' => 0 ];
		if ( empty( $this->settings['catbox_enabled'] ) ) {
			$stats['errors'][] = 'Catbox is disabled in settings.';
			return $stats;
		}

		$all_ids = empty( $ids ) ? $this->items->get_all_ids() : array_map( 'intval', $ids );

		foreach ( $all_ids as $id ) {
			$item = $this->items->get_by_id( $id );
			if ( ! is_array( $item ) ) {
				continue;
			}
			$stats['processed']++;
			$source      = (string) ( $item['source'] ?? '' );
			$source_name = (string) ( $item['source_name'] ?? '' );
			$guid        = (string) ( $item['guid'] ?? '' );

			$images_in  = (array) ( $item['images'] ?? [] );
			$videos_in  = (array) ( $item['videos'] ?? [] );
			$article_in = is_array( $item['article'] ?? null ) ? $item['article'] : null;

			$dirty           = false;
			$uploaded_images = [];
			foreach ( $images_in as $img_url ) {
				$img_url = (string) $img_url;
				if ( '' === $img_url || self::is_catbox_url( $img_url ) ) {
					$uploaded_images[] = $img_url;
					$stats['skipped']++;
					continue;
				}
				$new = $this->try_upload( $img_url, $stats, $source, $source_name, $guid, 'image' );
				if ( '' !== $new ) {
					$uploaded_images[] = $new;
					$stats['uploaded']++;
					$dirty = true;
				} else {
					$uploaded_images[] = $img_url;
				}
			}

			$updated_videos = [];
			foreach ( $videos_in as $video ) {
				$video = (array) $video;
				$poster = (string) ( $video['poster_url'] ?? '' );
				if ( '' !== $poster && ! self::is_catbox_url( $poster ) ) {
					$new = $this->try_upload( $poster, $stats, $source, $source_name, $guid, 'poster' );
					if ( '' !== $new ) {
						$video['poster_url'] = $new;
						$stats['uploaded']++;
						$dirty = true;
					}
				} elseif ( '' !== $poster ) {
					$stats['skipped']++;
				}

				$original = (string) ( $video['original_url'] ?? '' );
				$status   = (string) ( $video['status'] ?? '' );
				if ( '' !== $original && 'too_big' !== $status ) {
					$catbox_existing = (string) ( $video['catbox_url'] ?? '' );
					if ( '' === $catbox_existing ) {
						$new = $this->try_upload( $original, $stats, $source, $source_name, $guid, 'video' );
						if ( '' !== $new ) {
							$video['catbox_url'] = $new;
							$video['status']     = 'ok';
							$stats['uploaded']++;
							$dirty = true;
						} else {
							$video['status'] = 'upload_failed';
							$dirty           = true;
						}
					} else {
						$stats['skipped']++;
					}
				}
				$updated_videos[] = $video;
			}

			if ( is_array( $article_in ) ) {
				$art_img = (string) ( $article_in['image_url'] ?? '' );
				if ( '' !== $art_img && ! self::is_catbox_url( $art_img ) ) {
					$new = $this->try_upload( $art_img, $stats, $source, $source_name, $guid, 'article_image' );
					if ( '' !== $new ) {
						$article_in['image_url'] = $new;
						$stats['uploaded']++;
						$dirty                   = true;
					}
				} elseif ( '' !== $art_img ) {
					$stats['skipped']++;
				}
			}

			if ( $dirty ) {
				$this->items->update_media( $id, $uploaded_images, $updated_videos, $article_in );
				$stats['updated']++;
			}
		}

		return $stats;
	}

	private static function is_catbox_url( string $url ): bool {
		return 0 === strpos( $url, 'https://files.catbox.moe/' );
	}

	/**
	 * @param array{fetched:int, inserted:int, skipped:int, errors:string[]} $stats
	 */
	private function try_upload(
		string $url,
		array &$stats,
		string $source = '',
		string $source_name = '',
		string $guid = '',
		string $upload_type = 'image'
	): string {
		try {
			$catbox_url = $this->catbox->upload_from_url( $url );
			if ( $this->uploads ) {
				$this->uploads->log_upload( $source, $source_name, $guid, $upload_type, $url, $catbox_url );
			}
			return $catbox_url;
		} catch ( NC_Catbox_Exception $e ) {
			$stats['errors'][] = sprintf( 'Catbox upload failed (%s): %s', $url, $e->getMessage() );
			return '';
		}
	}
}
