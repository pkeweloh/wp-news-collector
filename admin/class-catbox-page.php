<?php
/**
 * Admin page: Catbox Albums & Sync.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Catbox_Page {

	public function __construct(
		private NC_Catbox_Upload_Repository $uploads,
		private NC_Catbox_Syncer $syncer
	) {}

	public function render(): void {
		$albums        = $this->uploads->get_albums_with_stats();
		$total_uploads = $this->uploads->count_total();
		$unassigned    = $this->uploads->count_unassigned();
		$sync_stats    = get_option( 'nc_catbox_sync_stats', null );
		$msg           = isset( $_GET['nc_msg'] ) ? sanitize_key( (string) $_GET['nc_msg'] ) : '';

		$uploads_page_num = isset( $_GET['upaged'] ) ? max( 1, (int) $_GET['upaged'] ) : 1;
		$uploads_filter   = isset( $_GET['uf'] ) ? sanitize_key( (string) $_GET['uf'] ) : 'all';
		$uploads_data     = $this->uploads->get_page( $uploads_page_num, 30, $uploads_filter );

		include NC_PLUGIN_DIR . 'admin/views/catbox.php';
	}
}
