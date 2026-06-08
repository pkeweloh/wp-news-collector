<?php
/**
 * Catbox (catbox.moe) uploader using the urlupload endpoint.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Catbox_Exception extends RuntimeException {}

class NC_Catbox_Uploader {

	private const ENDPOINT          = 'https://catbox.moe/user/api.php';
	private const UPLOAD_TIMEOUT    = 60;
	private const DOWNLOAD_TIMEOUT  = 120;
	private const MIN_BYTES         = 512; // small responses are typically error HTML
	private const VALID_CT_PREFIXES = [ 'image/', 'video/', 'audio/' ];

	public function __construct( private string $userhash = '' ) {}

	/**
	 * Upload a remote URL to Catbox. Downloads the asset locally first, then
	 * uploads it as multipart `fileupload`. This works for token-protected
	 * URLs (e.g. Telegram CDN) that Catbox's `urlupload` mode cannot access.
	 *
	 * @throws NC_Catbox_Exception on transport or API error.
	 */
	public function upload_from_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			throw new NC_Catbox_Exception( 'Empty URL' );
		}

		[ $bytes, $suffix ] = $this->download( $url );

		// wp_tempnam() lives in wp-admin/includes/file.php, which is not loaded
		// during WP Cron / Action Scheduler runs. Pull it in on demand.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp = wp_tempnam( 'nc_catbox_' . $suffix );
		if ( ! $tmp ) {
			throw new NC_Catbox_Exception( 'Cannot create temp file' );
		}
		if ( '' !== $suffix && '.bin' !== $suffix ) {
			$with_suffix = $tmp . $suffix;
			if ( @rename( $tmp, $with_suffix ) ) {
				$tmp = $with_suffix;
			}
		}
		if ( false === file_put_contents( $tmp, $bytes ) ) {
			@unlink( $tmp );
			throw new NC_Catbox_Exception( 'Cannot write temp file' );
		}
		try {
			return $this->upload_file( $tmp );
		} finally {
			@unlink( $tmp );
		}
	}

	/**
	 * @return array{0:string, 1:string} Raw bytes and a filename suffix including the dot (e.g. ".jpg").
	 */
	private function download( string $url ): array {
		$response = wp_remote_get(
			$url,
			[
				'timeout'     => self::DOWNLOAD_TIMEOUT,
				'redirection' => 5,
				'headers'     => [ 'User-Agent' => 'Mozilla/5.0' ],
			]
		);
		if ( is_wp_error( $response ) ) {
			throw new NC_Catbox_Exception( 'Download failed: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			throw new NC_Catbox_Exception( 'Download HTTP ' . $code );
		}
		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$ok           = false;
		foreach ( self::VALID_CT_PREFIXES as $prefix ) {
			if ( 0 === strpos( $content_type, $prefix ) ) {
				$ok = true;
				break;
			}
		}
		if ( ! $ok ) {
			throw new NC_Catbox_Exception( 'Unexpected content-type: ' . $content_type );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) < self::MIN_BYTES ) {
			throw new NC_Catbox_Exception( 'Response too small (' . strlen( $body ) . ' bytes)' );
		}

		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext      = pathinfo( $path, PATHINFO_EXTENSION );
		$suffix   = '' !== $ext ? '.' . strtolower( preg_replace( '~[^a-z0-9]~i', '', $ext ) ) : '.bin';
		return [ $body, $suffix ];
	}

	private function upload_file( string $path ): string {
		$filename  = basename( $path );
		$mime      = $this->guess_mime( $path );
		$boundary  = 'CatboxBoundary' . wp_generate_password( 12, false );
		$content   = file_get_contents( $path );
		if ( false === $content ) {
			throw new NC_Catbox_Exception( 'Cannot read temp file' );
		}

		$parts = '';
		if ( '' !== $this->userhash ) {
			$parts .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"userhash\"\r\n\r\n{$this->userhash}\r\n";
		}
		$parts .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"reqtype\"\r\n\r\nfileupload\r\n";
		$parts .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"fileToUpload\"; filename=\"{$filename}\"\r\nContent-Type: {$mime}\r\n\r\n";
		$body   = $parts . $content . "\r\n--{$boundary}--\r\n";

		$response = wp_remote_post(
			self::ENDPOINT,
			[
				'timeout' => self::UPLOAD_TIMEOUT,
				'headers' => [ 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ],
				'body'    => $body,
			]
		);
		if ( is_wp_error( $response ) ) {
			throw new NC_Catbox_Exception( 'Catbox transport error: ' . $response->get_error_message() );
		}
		$out = trim( (string) wp_remote_retrieve_body( $response ) );

		// Mirror the Python client: trust the body if it looks like a Catbox URL,
		// regardless of HTTP status: Catbox sometimes returns 5xx alongside a
		// valid URL when the upload actually succeeded.
		if ( 0 === strpos( $out, 'https://files.catbox.moe/' ) ) {
			return $out;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		throw new NC_Catbox_Exception( 'Catbox unexpected response (HTTP ' . $code . '): ' . $out );
	}

	/**
	 * Create a new Catbox album. Returns the album short code (e.g. '3q3qnn').
	 * Requires userhash.
	 *
	 * @throws NC_Catbox_Exception
	 */
	public function create_album( string $title ): string {
		if ( '' === $this->userhash ) {
			throw new NC_Catbox_Exception( 'User hash required to manage albums' );
		}
		$boundary = 'CatboxBoundary' . wp_generate_password( 12, false );
		$parts  = "--{$boundary}\r\nContent-Disposition: form-data; name=\"reqtype\"\r\n\r\ncreatealbum\r\n";
		$parts .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"userhash\"\r\n\r\n{$this->userhash}\r\n";
		$parts .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\n{$title}\r\n";
		$parts .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"desc\"\r\n\r\n\r\n";
		$parts .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"files\"\r\n\r\n\r\n";
		$body   = $parts . "--{$boundary}--\r\n";

		$response = wp_remote_post(
			self::ENDPOINT,
			[
				'timeout' => self::UPLOAD_TIMEOUT,
				'headers' => [ 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ],
				'body'    => $body,
			]
		);
		if ( is_wp_error( $response ) ) {
			throw new NC_Catbox_Exception( 'Album creation failed: ' . $response->get_error_message() );
		}
		$result = trim( (string) wp_remote_retrieve_body( $response ) );
		// Response is the album URL, e.g. https://catbox.moe/c/abc123
		$short = basename( rtrim( $result, '/' ) );
		if ( '' === $short ) {
			throw new NC_Catbox_Exception( 'Unexpected createalbum response: ' . $result );
		}
		return $short;
	}

	/**
	 * Add filenames to an existing Catbox album.
	 * $filenames are the bare filenames (e.g. 'abc123.jpg'), not full URLs.
	 * Requires userhash.
	 *
	 * @param string[] $filenames
	 * @throws NC_Catbox_Exception
	 */
	public function add_to_album( string $short, array $filenames ): void {
		if ( '' === $this->userhash ) {
			throw new NC_Catbox_Exception( 'User hash required to manage albums' );
		}
		if ( empty( $filenames ) ) {
			return;
		}
		$boundary  = 'CatboxBoundary' . wp_generate_password( 12, false );
		$files_str = implode( ' ', $filenames );
		$parts  = "--{$boundary}\r\nContent-Disposition: form-data; name=\"reqtype\"\r\n\r\naddtoalbum\r\n";
		$parts .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"userhash\"\r\n\r\n{$this->userhash}\r\n";
		$parts .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"short\"\r\n\r\n{$short}\r\n";
		$parts .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"files\"\r\n\r\n{$files_str}\r\n";
		$body   = $parts . "--{$boundary}--\r\n";

		$response = wp_remote_post(
			self::ENDPOINT,
			[
				'timeout' => self::UPLOAD_TIMEOUT,
				'headers' => [ 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ],
				'body'    => $body,
			]
		);
		if ( is_wp_error( $response ) ) {
			throw new NC_Catbox_Exception( 'Album add failed: ' . $response->get_error_message() );
		}
	}

	private function guess_mime( string $path ): string {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$map = [
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'mp4'  => 'video/mp4',
			'webm' => 'video/webm',
			'mov'  => 'video/quicktime',
			'mp3'  => 'audio/mpeg',
			'ogg'  => 'audio/ogg',
		];
		if ( isset( $map[ $ext ] ) ) {
			return $map[ $ext ];
		}
		if ( function_exists( 'mime_content_type' ) ) {
			$detected = @mime_content_type( $path );
			if ( is_string( $detected ) && '' !== $detected ) {
				return $detected;
			}
		}
		return 'application/octet-stream';
	}
}
