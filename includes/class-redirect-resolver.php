<?php
/**
 * URL shortener redirect resolver.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Redirect_Resolver {

	private const TIMEOUT = 5;

	private const SHORT_DOMAINS = [
		't.co'         => true,
		'bit.ly'       => true,
		'tinyurl.com'  => true,
		'ow.ly'        => true,
		'buff.ly'      => true,
		'dlvr.it'      => true,
		'ift.tt'       => true,
		'rb.gy'        => true,
		'shorturl.at'  => true,
		'cutt.ly'      => true,
	];

	/**
	 * Resolve a URL that goes through a known shortener. Returns the original URL
	 * unchanged if not a known shortener or if resolution fails.
	 */
	public static function resolve( string $url ): string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $host ) {
			return $url;
		}
		$host = strtolower( $host );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		if ( ! isset( self::SHORT_DOMAINS[ $host ] ) ) {
			return $url;
		}

		$response = wp_remote_head(
			$url,
			[
				'timeout'     => self::TIMEOUT,
				'redirection' => 5,
				'headers'     => [ 'User-Agent' => 'Mozilla/5.0' ],
			]
		);
		if ( is_wp_error( $response ) ) {
			return $url;
		}
		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( is_array( $location ) ) {
			$location = end( $location );
		}
		return is_string( $location ) && '' !== $location ? $location : $url;
	}
}
