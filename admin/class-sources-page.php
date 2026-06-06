<?php
/**
 * Sources admin page — list, add, edit, delete.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class NC_Sources_Table extends WP_List_Table {

	/** @var array<int, array<string, mixed>> */
	private array $rows;

	/** @param array<int, array<string, mixed>> $rows */
	public function __construct( array $rows ) {
		parent::__construct(
			[
				'singular' => 'source',
				'plural'   => 'sources',
				'ajax'     => false,
			]
		);
		$this->rows = $rows;
	}

	public function get_columns(): array {
		return [
			'name'       => __( 'Name', 'wp-news-collector' ),
			'url'        => __( 'URL', 'wp-news-collector' ),
			'enabled'    => __( 'Enabled', 'wp-news-collector' ),
			'created_at' => __( 'Created', 'wp-news-collector' ),
			'actions'    => __( 'Actions', 'wp-news-collector' ),
		];
	}

	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], [] ];
		$this->items           = $this->rows;
	}

	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}

	public function column_name( $item ): string {
		$edit_url = add_query_arg(
			[ 'page' => 'nc_sources', 'edit' => (int) $item['id'] ],
			admin_url( 'admin.php' )
		);
		$name = '' !== $item['name'] ? $item['name'] : '—';
		return sprintf( '<a href="%s"><strong>%s</strong></a>', esc_url( $edit_url ), esc_html( $name ) );
	}

	public function column_url( $item ): string {
		return sprintf( '<code>%s</code>', esc_html( (string) $item['url'] ) );
	}

	public function column_enabled( $item ): string {
		$enabled = (int) $item['enabled'] === 1;
		return $enabled
			? '<span style="color:#16a34a">●</span> ' . esc_html__( 'Yes', 'wp-news-collector' )
			: '<span style="color:#9ca3af">○</span> ' . esc_html__( 'No', 'wp-news-collector' );
	}

	public function column_actions( $item ): string {
		$id        = (int) $item['id'];
		$base      = admin_url( 'admin-post.php' );
		$toggle    = wp_nonce_url(
			add_query_arg(
				[ 'action' => 'nc_source_toggle', 'id' => $id ],
				$base
			),
			'nc_source_toggle_' . $id
		);
		$delete    = wp_nonce_url(
			add_query_arg(
				[ 'action' => 'nc_source_delete', 'id' => $id ],
				$base
			),
			'nc_source_delete_' . $id
		);
		$edit      = add_query_arg(
			[ 'page' => 'nc_sources', 'edit' => $id ],
			admin_url( 'admin.php' )
		);
		$toggle_lbl = (int) $item['enabled'] === 1 ? __( 'Disable', 'wp-news-collector' ) : __( 'Enable', 'wp-news-collector' );
		$del_confirm = esc_attr__( 'Delete this source?', 'wp-news-collector' );
		return sprintf(
			'<a href="%s">%s</a> | <a href="%s">%s</a> | <a href="%s" onclick="return confirm(\'%s\')" style="color:#b32d2e">%s</a>',
			esc_url( $edit ),
			esc_html__( 'Edit', 'wp-news-collector' ),
			esc_url( $toggle ),
			esc_html( $toggle_lbl ),
			esc_url( $delete ),
			$del_confirm,
			esc_html__( 'Delete', 'wp-news-collector' )
		);
	}
}

class NC_Sources_Page {

	public function __construct( private NC_Source_Repository $sources ) {}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		$msg     = isset( $_GET['nc_msg'] ) ? sanitize_key( (string) $_GET['nc_msg'] ) : '';

		if ( $edit_id > 0 ) {
			$source = $this->sources->get_by_id( $edit_id );
			if ( $source ) {
				include NC_PLUGIN_DIR . 'admin/views/source-edit.php';
				return;
			}
		}

		$rows  = $this->sources->get_all_admin();
		$table = new NC_Sources_Table( $rows );
		$table->prepare_items();

		include NC_PLUGIN_DIR . 'admin/views/sources-list.php';
	}
}
