<?php
/**
 * Admin page: Catbox tracked uploads + orphan cleanup.
 *
 * Split off from the main Catbox page so filtering/paginating the uploads
 * table (and running the orphan cleanup) does not reload the covers/sync UI.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Catbox_Uploads_Page {

	public function __construct(
		private NC_Catbox_Upload_Repository $uploads
	) {}

	public function render(): void {
		$total_uploads = $this->uploads->count_total();
		$failed        = $this->uploads->count_failed();
		$cleanup_stats = get_option( 'nc_catbox_cleanup_stats', null );
		$msg           = isset( $_GET['nc_msg'] ) ? sanitize_key( (string) $_GET['nc_msg'] ) : '';

		$uploads_page_num = isset( $_GET['upaged'] ) ? max( 1, (int) $_GET['upaged'] ) : 1;
		$uploads_filter   = isset( $_GET['uf'] ) ? sanitize_key( (string) $_GET['uf'] ) : 'all';
		$uploads_data     = $this->uploads->get_page( $uploads_page_num, 30, $uploads_filter );
		$album_month_map  = $this->uploads->get_album_month_map();

		include NC_PLUGIN_DIR . 'admin/views/catbox-uploads.php';
	}
}
