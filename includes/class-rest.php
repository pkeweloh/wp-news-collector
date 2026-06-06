<?php
/**
 * REST endpoints for the public feed.
 *
 *  GET /wp-json/nc/v1/item/{id}  — single item HTML (for modal hydration)
 *  GET /wp-json/nc/v1/feed       — paginated item HTML (for infinite scroll)
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Rest {

	public function __construct( private NC_Item_Repository $items ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			'nc/v1',
			'/item/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'id' => [
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					],
				],
			]
		);

		register_rest_route(
			'nc/v1',
			'/feed',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_feed' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'page'      => [
						'default'           => 1,
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
					'page_size' => [
						'default'           => 20,
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
					'source'    => [
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$item = $this->items->get_by_id( $id );
		if ( null === $item || 1 !== (int) $item['enabled'] ) {
			return new WP_Error( 'nc_not_found', __( 'Item not found', 'wp-news-collector' ), [ 'status' => 404 ] );
		}

		ob_start();
		include NC_Template_Loader::locate( 'item-detail.php' );
		$html = (string) ob_get_clean();

		$title = (string) ( $item['article']['title'] ?? '' );
		if ( '' === $title ) {
			$title = sprintf( '%s — #%d', (string) $item['source_name'], $id );
		}

		return new WP_REST_Response(
			[
				'id'        => $id,
				'permalink' => NC_Plugin::item_permalink( $id ),
				'title'     => $title,
				'html'      => $html,
			]
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_feed( WP_REST_Request $request ) {
		$page      = (int) $request['page'];
		$page_size = max( 1, min( 100, (int) $request['page_size'] ) );
		$source    = (string) $request['source'];

		$result   = $this->items->get_page( $page, $page_size, $source );
		$items    = $result['items'];
		$has_next = (bool) $result['has_next'];

		$show_images = true;
		$show_videos = true;
		$items_html  = '';
		foreach ( $items as $item ) {
			ob_start();
			include NC_Template_Loader::locate( 'item.php' );
			$items_html .= (string) ob_get_clean();
		}

		return new WP_REST_Response(
			[
				'page'       => $page,
				'has_next'   => $has_next,
				'items_html' => $items_html,
			]
		);
	}
}
