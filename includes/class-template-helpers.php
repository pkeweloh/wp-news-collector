<?php
/**
 * Shared rendering helpers: port of alerta-boe NewsCard logic.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Template_Helpers {

	/**
	 * Build the unified media deck (images + videos) used by both the feed and
	 * the detail page, applying the same filters as alerta-boe NewsCard.tsx:
	 *  - skip videos whose status === 'too_big' AND have no catbox_url
	 *    (those are surfaced separately as Telegram links)
	 *  - skip standalone images when the item has an article cover image
	 *    (avoids visual duplication)
	 *  - fall back to the YouTube thumbnail when there is no other media
	 *
	 * @param array<string, mixed> $item
	 * @return array<int, array<string, string>> Each entry: kind, src, [poster], [youtubeId]
	 */
	public static function build_media_list( array $item ): array {
		$media = [];

		$videos = (array) ( $item['videos'] ?? [] );
		// Posters of un-fixed too_big videos: used to suppress duplicate pending entries
		$too_big_posters = [];
		// Every poster, to keep a loose image that repeats one out of the deck: Catbox
		// returns a single file for identical bytes, so a photo and a video thumbnail
		// of the same frame end up sharing a URL. Painted here rather than waited for,
		// so the view is right before the sweep gets to the stored copy.
		$all_posters = [];
		foreach ( $videos as $v ) {
			$v = (array) $v;
			if ( ! empty( $v['poster_url'] ) ) {
				$all_posters[ (string) $v['poster_url'] ] = true;
			}
			if ( ( $v['status'] ?? '' ) === 'too_big' && empty( $v['catbox_url'] ) && ! empty( $v['poster_url'] ) ) {
				$too_big_posters[ (string) $v['poster_url'] ] = true;
			}
		}

		foreach ( $videos as $v ) {
			$v      = (array) $v;
			$status = (string) ( $v['status'] ?? '' );
			$catbox = (string) ( $v['catbox_url'] ?? '' );
			$orig   = (string) ( $v['original_url'] ?? '' );
			$poster = (string) ( $v['poster_url'] ?? '' );

			// too_big without catbox → handled separately by extract_too_big_videos()
			if ( 'too_big' === $status && '' === $catbox ) {
				continue;
			}
			// Skip pending/failed videos whose poster duplicates an un-fixed too_big entry
			if ( '' === $catbox && '' !== $poster && isset( $too_big_posters[ $poster ] ) ) {
				continue;
			}
			$src = '' !== $catbox ? $catbox : $orig;
			if ( '' !== $src ) {
				$entry = [ 'kind' => 'video', 'src' => $src ];
				if ( '' !== $poster ) {
					$entry['poster'] = $poster;
				}
				$media[] = $entry;
			} elseif ( '' !== $poster ) {
				$media[] = [ 'kind' => 'image', 'src' => $poster ];
			}
		}

		$article    = is_array( $item['article'] ?? null ) ? $item['article'] : null;
		$has_cover  = is_array( $article ) && '' !== (string) ( $article['image_url'] ?? '' );

		if ( ! $has_cover ) {
			foreach ( (array) ( $item['images'] ?? [] ) as $img_url ) {
				$img_url = (string) $img_url;
				if ( '' !== $img_url && ! isset( $all_posters[ $img_url ] ) ) {
					$media[] = [ 'kind' => 'image', 'src' => $img_url ];
				}
			}
			// Fallback: youtube thumbnail when there is no other media
			$youtube_ids = (array) ( $item['youtube_ids'] ?? [] );
			if ( empty( $media ) && ! empty( $youtube_ids ) ) {
				$yt      = (string) $youtube_ids[0];
				$media[] = [
					'kind'      => 'image',
					'src'       => 'https://img.youtube.com/vi/' . $yt . '/hqdefault.jpg',
					'youtubeId' => $yt,
				];
			}
		}

		return $media;
	}

	/**
	 * Too_big videos that are not yet uploaded to Catbox: rendered as
	 * "open in Telegram" thumbnails below the media grid.
	 *
	 * @param array<string, mixed> $item
	 * @return array<int, array<string, string>>
	 */
	public static function extract_too_big_videos( array $item ): array {
		$videos      = (array) ( $item['videos'] ?? [] );
		$catbox_pset = [];
		foreach ( $videos as $v ) {
			$v = (array) $v;
			if ( ! empty( $v['catbox_url'] ) && ! empty( $v['poster_url'] ) ) {
				$catbox_pset[ (string) $v['poster_url'] ] = true;
			}
		}
		$out = [];
		foreach ( $videos as $v ) {
			$v      = (array) $v;
			$status = (string) ( $v['status'] ?? '' );
			$poster = (string) ( $v['poster_url'] ?? '' );
			$catbox = (string) ( $v['catbox_url'] ?? '' );
			if ( 'too_big' !== $status || '' !== $catbox || '' === $poster ) {
				continue;
			}
			if ( isset( $catbox_pset[ $poster ] ) ) {
				continue;
			}
			$out[] = [ 'poster' => $poster ];
		}
		return $out;
	}

	/**
	 * Decorative title-slug for rich permalinks. Empty for media-only posts so
	 * they keep the bare /{slug}/{id} URL. Port of alerta-boe noticia.ts newsSlug.
	 *
	 * @param array<string, mixed> $item
	 */
	public static function item_slug( array $item ): string {
		$article = is_array( $item['article'] ?? null ) ? $item['article'] : null;
		$base    = is_array( $article ) ? (string) ( $article['title'] ?? '' ) : '';
		if ( '' === $base ) {
			$base = wp_strip_all_tags( (string) ( $item['text'] ?? '' ) );
		}
		// sanitize_title keeps %-encoded octets (emoji/non-latin) that leak into
		// the URL, so reduce to plain ASCII [a-z0-9] here.
		$base = strtolower( remove_accents( $base ) );
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $base ), '-' );
		if ( '' === $slug ) {
			return '';
		}
		return rtrim( substr( $slug, 0, 60 ), '-' );
	}

	/**
	 * Playable audios: those with a Catbox or original URL, mirroring the
	 * NewsCard.tsx filter. Each entry has a ready-to-use 'src'.
	 *
	 * @param array<string, mixed> $item
	 * @return array<int, array{src:string}>
	 */
	public static function build_audio_list( array $item ): array {
		$out = [];
		foreach ( (array) ( $item['audios'] ?? [] ) as $a ) {
			$a   = (array) $a;
			$src = (string) ( $a['catbox_url'] ?? '' );
			if ( '' === $src ) {
				$src = (string) ( $a['original_url'] ?? '' );
			}
			if ( '' !== $src ) {
				$out[] = [ 'src' => $src ];
			}
		}
		return $out;
	}

	/**
	 * If the item has no media nor article but has YouTube IDs, expose the
	 * first one as a CTA URL ("Ver en YouTube").
	 *
	 * @param array<string, mixed> $item
	 */
	public static function youtube_cta_url( array $item ): string {
		$article = is_array( $item['article'] ?? null ) ? $item['article'] : null;
		if ( is_array( $article ) ) {
			return '';
		}
		$youtube_ids = (array) ( $item['youtube_ids'] ?? [] );
		if ( empty( $youtube_ids ) ) {
			return '';
		}
		// Show CTA only if no images and no usable videos exist
		$has_images = ! empty( (array) ( $item['images'] ?? [] ) );
		$has_video  = false;
		foreach ( (array) ( $item['videos'] ?? [] ) as $v ) {
			$v = (array) $v;
			if ( ! empty( $v['catbox_url'] ) || ! empty( $v['original_url'] ) || ! empty( $v['poster_url'] ) ) {
				$has_video = true;
				break;
			}
		}
		if ( $has_images || $has_video ) {
			return '';
		}
		return 'https://www.youtube.com/watch?v=' . (string) $youtube_ids[0];
	}

	/**
	 * Format a UTC datetime string for display.
	 * Timezone: wp_timezone() (Settings → General → Timezone).
	 * Format: translatable, default English "June 6, 21:44",
	 *         Spanish .mo ships "j \d\e F, H:i" → "6 de junio, 21:44".
	 */
	public static function format_date_es( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}
		try {
			$ts = ( new DateTimeImmutable( $stored, new DateTimeZone( 'UTC' ) ) )->getTimestamp();
		} catch ( Exception $e ) {
			return $stored;
		}
		/* translators: PHP date format for news item timestamps. See https://www.php.net/date */
		$format = __( 'F j, H:i', 'wp-news-collector' );
		return wp_date( $format, $ts ) ?: $stored;
	}

	/**
	 * Resolve a human-readable source name for the article card, mirroring
	 * the inline IIFE in NewsCard.tsx (site_name → hostname → "YouTube").
	 *
	 * @param array<string, mixed> $article
	 */
	public static function article_source_label( array $article ): string {
		$site = (string) ( $article['site_name'] ?? '' );
		if ( '' !== $site ) {
			return $site;
		}
		$url = (string) ( $article['url'] ?? '' );
		if ( '' === $url ) {
			return '';
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$host = preg_replace( '~^www\.~', '', strtolower( $host ) );
		if ( '' === $host ) {
			return '';
		}
		if ( false !== strpos( $host, 'youtube.com' ) || false !== strpos( $host, 'youtu.be' ) ) {
			return 'YouTube';
		}
		return $host;
	}

	/**
	 * SVG chain/link icon for the permalink affordance (same paths as alerta-boe).
	 */
	public static function permalink_svg(): string {
		return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>'
			. '<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>'
			. '</svg>';
	}

	public static function play_svg(): string {
		return '<svg width="56" height="56" viewBox="0 0 56 56" aria-hidden="true">'
			. '<circle cx="28" cy="28" r="26" fill="rgba(0,0,0,0.55)"/>'
			. '<polygon points="22,16 22,40 42,28" fill="white"/>'
			. '</svg>';
	}

	public static function youtube_play_svg(): string {
		return '<svg viewBox="0 0 68 48" width="90" height="64" aria-hidden="true">'
			. '<path d="M66.52 7.74c-.78-2.93-2.49-5.41-5.42-6.19C55.79.13 34 0 34 0S12.21.13 6.9 1.55c-2.93.78-4.63 3.26-5.42 6.19C.06 13.05 0 24 0 24s.06 10.95 1.48 16.26c.78 2.93 2.49 5.41 5.42 6.19C12.21 47.87 34 48 34 48s21.79-.13 27.1-1.55c2.93-.78 4.64-3.26 5.42-6.19C67.94 34.95 68 24 68 24s-.06-10.95-1.48-16.26z" fill="#f00"/>'
			. '<path d="M45 24L27 14v20" fill="#fff"/>'
			. '</svg>';
	}

	public static function muted_svg(): string {
		return '<svg width="20" height="20" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
			. '<path d="M3.63 3.63a1 1 0 0 0 0 1.41L7.29 8.7 7 9H4a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1h3l5 5v-6.59l4.18 4.18A6.97 6.97 0 0 1 13 18.93V21a9 9 0 0 0 5.19-2.38l1.38 1.38a1 1 0 0 0 1.41-1.41L5.05 3.63a1 1 0 0 0-1.42 0ZM19 12c0 .82-.15 1.61-.41 2.34l1.53 1.53A8.96 8.96 0 0 0 21 12c0-4.28-3-7.86-7-8.77V5.3c2.89.86 5 3.54 5 6.7Zm-7-8-1.88 1.88L12 7.76V4ZM16.5 12c0-1.77-1-3.29-2.5-4.03v1.79l2.48 2.48c.01-.08.02-.16.02-.24Z"/>'
			. '</svg>';
	}

	public static function telegram_svg(): string {
		return '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
			. '<path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/>'
			. '</svg>';
	}
}
