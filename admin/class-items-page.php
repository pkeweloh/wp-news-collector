<?php
/**
 * Items admin page: list, filter, view, delete.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class NC_Items_Table extends WP_List_Table {

	/** @var array<int, array<string, mixed>> */
	private array $rows;
	private string $video_filter;
	private int $current_page;

	/** @param array<int, array<string, mixed>> $rows */
	public function __construct( array $rows, string $video_filter, int $current_page ) {
		parent::__construct(
			[
				'singular' => 'item',
				'plural'   => 'items',
				'ajax'     => false,
			]
		);
		$this->rows         = $rows;
		$this->video_filter = $video_filter;
		$this->current_page = $current_page;
	}

	public function get_columns(): array {
		return [
			'cb'           => '<input type="checkbox" />',
			'id'           => __( 'ID', 'wp-news-collector' ),
			'source_name'  => __( 'Source', 'wp-news-collector' ),
			'text'         => __( 'Text', 'wp-news-collector' ),
			'images'       => __( 'Images', 'wp-news-collector' ),
			'videos'       => __( 'Videos', 'wp-news-collector' ),
			'published_at' => __( 'Published', 'wp-news-collector' ),
			'enabled'      => __( 'Status', 'wp-news-collector' ),
			'actions'      => __( 'Actions', 'wp-news-collector' ),
		];
	}

	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], [] ];
		$this->items           = $this->rows;
	}

	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="item_ids[]" value="%d" />', (int) $item['id'] );
	}

	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}

	public function column_id( $item ): string {
		$view = add_query_arg(
			[ 'page' => 'nc_items', 'view' => (int) $item['id'] ],
			admin_url( 'admin.php' )
		);
		return sprintf( '<a href="%s">#%d</a>', esc_url( $view ), (int) $item['id'] );
	}

	public function column_text( $item ): string {
		$text = wp_strip_all_tags( (string) ( $item['text'] ?? '' ) );
		$text = mb_strimwidth( $text, 0, 120, '…' );
		return esc_html( $text );
	}

	public function column_images( $item ): string {
		return (string) count( (array) ( $item['images'] ?? [] ) );
	}

	public function column_videos( $item ): string {
		return (string) count( (array) ( $item['videos'] ?? [] ) );
	}

	public function column_enabled( $item ): string {
		return (int) $item['enabled'] === 1
			? '<span style="color:#16a34a">●</span> ' . esc_html__( 'Visible', 'wp-news-collector' )
			: '<span style="color:#9ca3af">○</span> ' . esc_html__( 'Hidden', 'wp-news-collector' );
	}

	public function column_actions( $item ): string {
		$id   = (int) $item['id'];
		$base = admin_url( 'admin-post.php' );
		$args = [ 'action' => 'nc_item_action', 'id' => $id, 'vf' => $this->video_filter, 'paged' => $this->current_page ];

		$toggle_action = (int) $item['enabled'] === 1 ? 'hide' : 'show';
		$toggle = wp_nonce_url(
			add_query_arg( array_merge( $args, [ 'nc_action' => $toggle_action ] ), $base ),
			'nc_item_' . $toggle_action . '_' . $id
		);
		$delete = wp_nonce_url(
			add_query_arg( array_merge( $args, [ 'nc_action' => 'delete' ] ), $base ),
			'nc_item_delete_' . $id
		);
		$view = add_query_arg(
			[ 'page' => 'nc_items', 'view' => $id ],
			admin_url( 'admin.php' )
		);
		$toggle_lbl = 'hide' === $toggle_action ? __( 'Hide', 'wp-news-collector' ) : __( 'Show', 'wp-news-collector' );
		$confirm    = esc_attr__( 'Delete this item?', 'wp-news-collector' );
		return sprintf(
			'<a href="%s">%s</a> | <a href="%s">%s</a> | <a href="%s" onclick="return confirm(\'%s\')" style="color:#b32d2e">%s</a>',
			esc_url( $view ),
			esc_html__( 'View', 'wp-news-collector' ),
			esc_url( $toggle ),
			esc_html( $toggle_lbl ),
			esc_url( $delete ),
			$confirm,
			esc_html__( 'Delete', 'wp-news-collector' )
		);
	}
}

class NC_Items_Page {

	public function __construct( private NC_Item_Repository $items ) {}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$view_id = isset( $_GET['view'] ) ? (int) $_GET['view'] : 0;
		if ( $view_id > 0 ) {
			$item = $this->items->get_by_id( $view_id );
			if ( $item ) {
				include NC_PLUGIN_DIR . 'admin/views/item-view.php';
				return;
			}
		}

		$vf    = isset( $_GET['vf'] ) ? sanitize_key( (string) $_GET['vf'] ) : 'all';
		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$page_size = 25;
		$page  = $this->items->get_page_admin( $paged, $page_size, $vf );

		$table = new NC_Items_Table( $page['items'], $vf, $paged );
		$table->prepare_items();

		$msg = isset( $_GET['nc_msg'] ) ? sanitize_key( (string) $_GET['nc_msg'] ) : '';
		include NC_PLUGIN_DIR . 'admin/views/items-list.php';
	}
}
