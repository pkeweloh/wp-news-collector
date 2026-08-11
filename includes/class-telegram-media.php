<?php
/**
 * Re-mints the media URLs of a Telegram message from its public embed page.
 * Port of alerta-boe/app/telegram_media.py.
 *
 * Stored cdn*.telesco.pe links are signed and live hours, so on a retry the URL is
 * dead while the media is not. t.me/{channel}/{id}?embed=1 re-signs it on every
 * request, which is what makes a piece lost days ago recoverable with no account.
 *
 * The price is reading Telegram's HTML, and MARKUP_ALARM is that price made
 * visible: a page that shows the message but yields no media is a broken parser.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Telegram_Media {

	public const MARKUP_ALARM = 'Telegram embed unreadable: the t.me markup may have changed';

	private const TIMEOUT    = 30;
	private const USER_AGENT = 'Mozilla/5.0';

	// The channel avatar lives outside these wrappers, so reading only from here
	// keeps it out without a filter.
	private const PHOTO_CLASS  = 'tgme_widget_message_photo_wrap';
	private const PLAYER_CLASS = 'tgme_widget_message_video_player';
	private const THUMB_CLASS  = 'tgme_widget_message_video_thumb';
	private const MSG_CLASS    = 'tgme_widget_message';

	/**
	 * The message's permanent address, or '' when the item is not a Telegram post.
	 * Prefers source and telegram_id: RSSHub guids have proven unstable.
	 *
	 * @param array<string, mixed> $item
	 */
	public static function embed_url( array $item ): string {
		$guid = trim( (string) ( $item['guid'] ?? '' ) );
		if ( 0 !== strpos( $guid, 'https://t.me/' ) ) {
			return '';
		}
		$source      = trim( (string) ( $item['source'] ?? '' ) );
		$telegram_id = (int) ( $item['telegram_id'] ?? 0 );
		if ( '' !== $source && $telegram_id > 0 ) {
			return 'https://t.me/' . $source . '/' . $telegram_id . '?embed=1';
		}
		return $guid . '?embed=1';
	}

	/**
	 * Never throws: an unreachable page comes back unreadable so the caller can
	 * fall back to the stored URL.
	 *
	 * @param array<string, mixed> $item
	 * @return array{photos:string[], videos:list<array{0:string, 1:string}>, readable:bool, has_message:bool}
	 */
	public static function fetch( array $item ): array {
		$url = self::embed_url( $item );
		if ( '' === $url ) {
			return self::empty_media( false );
		}
		$response = wp_remote_get(
			$url,
			[
				'timeout' => self::TIMEOUT,
				'headers' => [ 'User-Agent' => self::USER_AGENT ],
			]
		);
		if ( is_wp_error( $response ) ) {
			return self::empty_media( false );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return self::empty_media( false );
		}
		return self::parse( (string) wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Photos and videos in page order, which is the order the item stores them in.
	 *
	 * @return array{photos:string[], videos:list<array{0:string, 1:string}>, readable:bool, has_message:bool}
	 */
	public static function parse( string $html ): array {
		$dom   = self::load_html( $html );
		$xpath = new DOMXPath( $dom );
		$media = self::empty_media( true );

		$media['has_message'] = $xpath->query( '//*[' . self::has_class( self::MSG_CLASS ) . ']' )->length > 0;

		foreach ( $xpath->query( '//a[' . self::has_class( self::PHOTO_CLASS ) . ']' ) as $wrap ) {
			$url = self::background_url( $wrap );
			if ( '' !== $url ) {
				$media['photos'][] = $url;
			}
		}

		foreach ( $xpath->query( '//*[' . self::has_class( self::PLAYER_CLASS ) . ']' ) as $player ) {
			$video = $xpath->query( './/video', $player )->item( 0 );
			$src   = $video instanceof DOMElement ? trim( $video->getAttribute( 'src' ) ) : '';
			$thumb = $xpath->query( './/*[' . self::has_class( self::THUMB_CLASS ) . ']', $player )->item( 0 );
			$media['videos'][] = [ $src, self::background_url( $thumb ) ];
		}

		return $media;
	}

	/**
	 * The page loaded and the message is there, but nothing came out of it.
	 * We only ask about messages we know had media, so nothing means broken.
	 *
	 * @param array<string, mixed> $media
	 */
	public static function markup_suspect( array $media ): bool {
		return ! empty( $media['readable'] )
			&& ! empty( $media['has_message'] )
			&& empty( $media['photos'] )
			&& empty( $media['videos'] );
	}

	/**
	 * The current URL for one stored piece, matched by position.
	 *
	 * Position is the only stable key: the stored URL is the expired one and the
	 * fresh page cannot be matched against it. Positions hold even after a partial
	 * recovery, because a repaired piece keeps its slot with a Catbox URL in it.
	 *
	 * @param array<string, mixed> $item
	 * @param array<string, mixed> $media
	 */
	public static function fresh_url_for( array $item, string $upload_type, string $original_url, array $media ): string {
		$photos = (array) ( $media['photos'] ?? [] );
		$videos = (array) ( $media['videos'] ?? [] );

		if ( 'image' === $upload_type ) {
			$index = self::index_of( array_map( 'strval', (array) ( $item['images'] ?? [] ) ), $original_url );
			return isset( $photos[ $index ] ) ? (string) $photos[ $index ] : '';
		}
		if ( 'video' === $upload_type || 'poster' === $upload_type ) {
			$key    = 'video' === $upload_type ? 'original_url' : 'poster_url';
			$stored = [];
			foreach ( (array) ( $item['videos'] ?? [] ) as $v ) {
				$v        = (array) $v;
				$stored[] = (string) ( $v[ $key ] ?? '' );
			}
			$index = self::index_of( $stored, $original_url );
			if ( isset( $videos[ $index ] ) ) {
				$pair = (array) $videos[ $index ];
				return (string) ( 'video' === $upload_type ? ( $pair[0] ?? '' ) : ( $pair[1] ?? '' ) );
			}
		}
		return '';
	}

	/**
	 * @param string[] $values
	 * @return int -1 when absent.
	 */
	private static function index_of( array $values, string $target ): int {
		if ( '' === $target ) {
			return -1;
		}
		$index = array_search( $target, array_values( $values ), true );
		return false === $index ? -1 : (int) $index;
	}

	/** The URL inside a `background-image:url('...')` inline style. */
	private static function background_url( ?DOMNode $element ): string {
		if ( ! $element instanceof DOMElement ) {
			return '';
		}
		$style = $element->getAttribute( 'style' );
		if ( 1 === preg_match( "~background-image\s*:\s*url\(\s*'([^']+)'~i", $style, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/** XPath predicate matching one whole class token. */
	private static function has_class( string $class ): string {
		return "contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')";
	}

	/** @return array{photos:string[], videos:list<array{0:string, 1:string}>, readable:bool, has_message:bool} */
	private static function empty_media( bool $readable ): array {
		return [ 'photos' => [], 'videos' => [], 'readable' => $readable, 'has_message' => false ];
	}

	private static function load_html( string $html ): DOMDocument {
		$dom  = new DOMDocument( '1.0', 'UTF-8' );
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $dom;
	}
}
