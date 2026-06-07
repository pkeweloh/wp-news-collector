<?php
/**
 * Server-side handler for /noticia/{id} canonical URLs.
 *
 * Renders a full page (with the theme's header/footer) when a visitor lands
 * directly on the item URL: shared link, refresh after pushState, or SEO crawl.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Detail_Page {

	public function __construct( private NC_Item_Repository $items ) {}

	public function register(): void {
		add_filter( 'template_include', [ $this, 'maybe_override_template' ] );
		add_filter( 'document_title_parts', [ $this, 'maybe_set_title' ] );
		add_filter( 'pre_get_document_title', [ $this, 'maybe_force_title' ] );
		add_action( 'template_redirect', [ $this, 'handle_404_for_missing' ] );
	}

	public function maybe_override_template( string $template ): string {
		$id = $this->current_item_id();
		if ( $id <= 0 ) {
			return $template;
		}
		$item = $this->items->get_by_id( $id );
		if ( null === $item || 1 !== (int) $item['enabled'] ) {
			return $template;
		}
		return NC_Template_Loader::locate( 'single-item.php' );
	}

	public function handle_404_for_missing(): void {
		$id = $this->current_item_id();
		if ( $id <= 0 ) {
			return;
		}
		$item = $this->items->get_by_id( $id );
		if ( null !== $item && 1 === (int) $item['enabled'] ) {
			// Tell WP this is a real, found page so it does not 404.
			status_header( 200 );
			global $wp_query;
			if ( $wp_query instanceof WP_Query ) {
				$wp_query->is_404      = false;
				$wp_query->is_singular = true;
			}
			return;
		}
		// Not found: leave the 404 path alone.
	}

	/**
	 * @param array<string, string> $parts
	 * @return array<string, string>
	 */
	public function maybe_set_title( array $parts ): array {
		$id = $this->current_item_id();
		if ( $id <= 0 ) {
			return $parts;
		}
		$item = $this->items->get_by_id( $id );
		if ( null === $item ) {
			return $parts;
		}
		$parts['title'] = $this->item_title( $item );
		return $parts;
	}

	public function maybe_force_title( string $title ): string {
		$id = $this->current_item_id();
		if ( $id <= 0 || '' !== $title ) {
			return $title;
		}
		$item = $this->items->get_by_id( $id );
		return null === $item ? $title : $this->item_title( $item ) . ': ' . get_bloginfo( 'name' );
	}

	private function current_item_id(): int {
		return (int) get_query_var( NC_Rewrite::QUERY_VAR );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function item_title( array $item ): string {
		$title = (string) ( ( $item['article']['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = sprintf( '%s #%d', (string) $item['source_name'], (int) $item['id'] );
		}
		return $title;
	}
}
