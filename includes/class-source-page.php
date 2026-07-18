<?php
/**
 * Server-side handler for /{source_slug}/{source} landing pages: server-renders
 * one channel's feed at a shareable, crawlable URL.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Source_Page {

	public const QUERY_VAR = 'nc_source';

	/** URL prefix for source pages, configurable via Settings (default: "source"). */
	public static function base(): string {
		$slug = trim( (string) ( NC_Plugin::get_settings()['source_slug'] ?? '' ) );
		return '' !== $slug ? sanitize_title( $slug ) : 'source';
	}

	public function register(): void {
		add_action( 'init', [ $this, 'add_rules' ] );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_filter( 'template_include', [ $this, 'maybe_override_template' ] );
		add_filter( 'document_title_parts', [ $this, 'maybe_set_title' ] );
		add_filter( 'pre_get_document_title', [ $this, 'maybe_force_title' ] );
		add_action( 'template_redirect', [ $this, 'handle_status' ] );
		add_action( 'wp_head', [ $this, 'maybe_canonical' ] );
	}

	public function add_rules(): void {
		$base = self::base();
		// Same base as the item permalink would make routing ambiguous; skip it.
		if ( $base === NC_Rewrite::slug() ) {
			return;
		}
		add_rewrite_rule( '^' . $base . '/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public function maybe_override_template( string $template ): string {
		$handle = $this->current_handle();
		if ( '' === $handle || null === self::display_name( $handle ) ) {
			return $template;
		}
		return NC_Template_Loader::locate( 'source-feed.php' );
	}

	public function handle_status(): void {
		$handle = $this->current_handle();
		if ( '' === $handle ) {
			return;
		}
		global $wp_query;
		if ( null === self::display_name( $handle ) ) {
			// Unknown source: serve a real 404 instead of an empty feed.
			if ( $wp_query instanceof WP_Query ) {
				$wp_query->set_404();
			}
			status_header( 404 );
			return;
		}
		status_header( 200 );
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->is_404 = false;
			$wp_query->is_home = false;
		}
		// The listing has no queried object, so core would try to redirect it away.
		remove_action( 'template_redirect', 'redirect_canonical' );
	}

	/**
	 * @param array<string, string> $parts
	 * @return array<string, string>
	 */
	public function maybe_set_title( array $parts ): array {
		$name = $this->current_source_name();
		if ( null !== $name ) {
			$parts['title'] = $name;
		}
		return $parts;
	}

	public function maybe_force_title( string $title ): string {
		if ( '' !== $title ) {
			return $title;
		}
		$name = $this->current_source_name();
		return null === $name ? $title : $name . ': ' . get_bloginfo( 'name' );
	}

	public function maybe_canonical(): void {
		$handle = $this->current_handle();
		if ( '' === $handle || null === self::display_name( $handle ) ) {
			return;
		}
		echo '<link rel="canonical" href="' . esc_url( NC_Plugin::source_permalink( $handle ) ) . '" />' . "\n";
	}

	private function current_handle(): string {
		return sanitize_text_field( (string) get_query_var( self::QUERY_VAR ) );
	}

	private function current_source_name(): ?string {
		$handle = $this->current_handle();
		return '' === $handle ? null : self::display_name( $handle );
	}

	/**
	 * Display name for a source handle, or null if it has no items. Cached per
	 * request so the DISTINCT query runs at most once.
	 */
	public static function display_name( string $handle ): ?string {
		static $map = null;
		if ( null === $map ) {
			$map = [];
			foreach ( ( new NC_Item_Repository() )->get_distinct_sources() as $row ) {
				$map[ (string) $row['source'] ] = (string) $row['source_name'];
			}
		}
		return array_key_exists( $handle, $map ) ? $map[ $handle ] : null;
	}
}
