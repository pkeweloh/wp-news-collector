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

	/** Default minimum distinct posts for an image to be proposed as a cover candidate. */
	public const COVER_THRESHOLD = 3;

	/** @var array<string, string[]>|null Lazily loaded source => confirmed cover URLs. */
	private ?array $cover_cache = null;

	public function __construct(
		private NC_Source_Repository $sources,
		private NC_Item_Repository $items,
		private NC_Catbox_Uploader $catbox,
		private array $settings,
		private ?NC_Catbox_Upload_Repository $uploads = null,
		private ?NC_Source_Cover_Repository $covers = null,
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
		$this->cover_cache = null; // Refresh known covers for this cycle.
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
				$album_name = NC_Plugin::catbox_album_name( $month );
				$album_id   = $this->catbox->create_album( $album_name );
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

		// 1) Prefer og:image as the article cover; the URL Telegram embeds expires.
		$article = $item['article'];
		if ( is_array( $article ) && '' !== ( $article['url'] ?? '' ) ) {
			$og = NC_OG_Scraper::fetch( (string) $article['url'] );
			if ( '' !== $og['site_name'] ) {
				$article['site_name'] = $og['site_name'];
			}
			$cover = '' !== $og['image'] ? $og['image'] : $this->youtube_cover( $item );
			if ( '' !== $cover ) {
				$article['image_url'] = $cover;
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

			// 3b) Drop known channel-cover images for this source.
			$item['images'] = $this->strip_cover_images( $source, (array) $item['images'] );
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

	/**
	 * Remove any image that is a known recurring channel cover for the source.
	 *
	 * @param string[] $images
	 * @return string[]
	 */
	private function strip_cover_images( string $source, array $images ): array {
		if ( ! $this->covers || empty( $images ) ) {
			return array_values( $images );
		}
		if ( null === $this->cover_cache ) {
			$this->cover_cache = $this->covers->get_confirmed_urls_by_source();
		}
		$cover_urls = $this->cover_cache[ $source ] ?? [];
		if ( empty( $cover_urls ) ) {
			return array_values( $images );
		}
		$kept = [];
		foreach ( $images as $url ) {
			if ( in_array( (string) $url, $cover_urls, true ) ) {
				continue;
			}
			$kept[] = $url;
		}
		return $kept;
	}

	/**
	 * Propose recurring-image candidates for review. A candidate is an image
	 * whose (deduped) Catbox URL appears in at least $threshold distinct posts
	 * of the same source. This only records candidates (nc_source_covers); it
	 * never modifies items and never changes an existing human decision. The
	 * frequency heuristic cannot tell a channel logo from a legitimately
	 * recurring image (e.g. a daily livestream cover), so a human confirms.
	 *
	 * @return array{sources_scanned:int, candidates:int, errors:string[]}
	 */
	public function detect_cover_candidates( int $threshold = 0 ): array {
		$stats = [ 'sources_scanned' => 0, 'candidates' => 0, 'errors' => [] ];
		if ( ! $this->covers ) {
			$stats['errors'][] = 'Cover repository unavailable.';
			return $stats;
		}
		if ( $threshold <= 0 ) {
			$threshold = self::COVER_THRESHOLD;
		}

		$refs = $this->items->get_all_image_refs();

		// Count distinct posts per (source, catbox image URL).
		$counts = []; // source => [ url => count ]
		foreach ( $refs as $ref ) {
			$source = $ref['source'];
			$seen   = [];
			foreach ( $ref['images'] as $url ) {
				if ( ! self::is_catbox_url( $url ) || isset( $seen[ $url ] ) ) {
					continue;
				}
				$seen[ $url ]              = true;
				$counts[ $source ][ $url ] = ( $counts[ $source ][ $url ] ?? 0 ) + 1;
			}
		}

		foreach ( $counts as $source => $urls ) {
			$stats['sources_scanned']++;
			foreach ( $urls as $url => $count ) {
				if ( $count < $threshold ) {
					continue;
				}
				$original = $this->uploads ? $this->uploads->get_original_for_catbox( $url ) : '';
				$this->covers->upsert_candidate( $source, $url, $original, $count );
				$stats['candidates']++;
			}
		}

		return $stats;
	}

	/**
	 * Strip every confirmed cover from existing items in place. Called after a
	 * cover is confirmed; idempotent.
	 *
	 * @return array{items_cleaned:int, urls_removed:int, errors:string[]}
	 */
	public function clean_confirmed_covers(): array {
		$stats = [ 'items_cleaned' => 0, 'urls_removed' => 0, 'errors' => [] ];
		if ( ! $this->covers ) {
			$stats['errors'][] = 'Cover repository unavailable.';
			return $stats;
		}
		$confirmed = $this->covers->get_confirmed_urls_by_source();
		if ( empty( $confirmed ) ) {
			return $stats;
		}
		// source => [ url => true ] for O(1) lookups.
		$lookup = [];
		foreach ( $confirmed as $source => $urls ) {
			foreach ( $urls as $url ) {
				$lookup[ $source ][ $url ] = true;
			}
		}

		foreach ( $this->items->get_all_image_refs() as $ref ) {
			$source = $ref['source'];
			if ( empty( $lookup[ $source ] ) ) {
				continue;
			}
			$kept    = [];
			$removed = 0;
			foreach ( $ref['images'] as $url ) {
				if ( isset( $lookup[ $source ][ $url ] ) ) {
					$removed++;
					continue;
				}
				$kept[] = $url;
			}
			if ( $removed <= 0 ) {
				continue;
			}
			$full = $this->items->get_by_id( $ref['id'] );
			if ( ! is_array( $full ) ) {
				continue;
			}
			$videos  = (array) ( $full['videos'] ?? [] );
			$article = is_array( $full['article'] ?? null ) ? $full['article'] : null;
			$this->items->update_media( $ref['id'], array_values( $kept ), $videos, $article );
			$stats['items_cleaned']++;
			$stats['urls_removed'] += $removed;
		}

		$this->cover_cache = null; // Invalidate so later ingests reload confirmed set.
		return $stats;
	}

	private static function is_catbox_url( string $url ): bool {
		return 0 === strpos( $url, 'https://files.catbox.moe/' );
	}

	/**
	 * @param array{fetched:int, inserted:int, skipped:int, errors:string[]} $stats
	 */
	/** @param array<string, mixed> $item */
	private function youtube_cover( array $item ): string {
		$ids = (array) ( $item['youtube_ids'] ?? [] );
		return empty( $ids ) ? '' : NC_OG_Scraper::youtube_thumbnail( (string) $ids[0] );
	}

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
			if ( $this->uploads ) {
				// Track the failure so it can be surfaced and retried from admin.
				$this->uploads->resolve_result( $source, $source_name, $guid, $upload_type, $url, null, $e->getMessage() );
			}
			return '';
		}
	}
}
