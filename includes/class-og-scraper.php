<?php
/**
 * Open Graph metadata scraper.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_OG_Scraper {

	private const TIMEOUT     = 8;
	private const MAX_BYTES   = 65536;
	private const USER_AGENT  = 'Mozilla/5.0 (compatible; NewsCollector/1.0)';

	/**
	 * Fetch og:image and og:site_name in a single request. Never throws.
	 *
	 * @return array{image:string, site_name:string}
	 */
	public static function fetch( string $url ): array {
		$out = [ 'image' => '', 'site_name' => '' ];
		if ( '' === trim( $url ) ) {
			return $out;
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => self::TIMEOUT,
				'redirection' => 5,
				'headers'     => [ 'User-Agent' => self::USER_AGENT ],
			]
		);
		if ( is_wp_error( $response ) ) {
			return $out;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return $out;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return $out;
		}
		$body = substr( $body, 0, self::MAX_BYTES );

		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $body, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xpath = new DOMXPath( $dom );
		$metas = $xpath->query( '//meta[@property]' );
		if ( false === $metas ) {
			return $out;
		}
		foreach ( $metas as $meta ) {
			if ( ! $meta instanceof DOMElement ) {
				continue;
			}
			$property = strtolower( (string) $meta->getAttribute( 'property' ) );
			$content  = trim( (string) $meta->getAttribute( 'content' ) );
			if ( '' === $content ) {
				continue;
			}
			if ( 'og:image' === $property && '' === $out['image'] ) {
				$out['image'] = $content;
			} elseif ( 'og:site_name' === $property && '' === $out['site_name'] ) {
				$out['site_name'] = $content;
			}
			if ( '' !== $out['image'] && '' !== $out['site_name'] ) {
				break;
			}
		}
		return $out;
	}
}
