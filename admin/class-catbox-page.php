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
		private NC_Catbox_Syncer $syncer,
		private NC_Source_Cover_Repository $covers,
		private NC_Item_Repository $items
	) {}

	public function render(): void {
		$albums        = $this->uploads->get_albums_with_stats();
		$total_uploads = $this->uploads->count_total();
		$unassigned    = $this->uploads->count_unassigned();
		// Same cap as the uploads page, so both read "failed" as "still actionable".
		$failed        = $this->uploads->count_failed( (int) ( NC_Plugin::get_settings()['catbox_retry_max_attempts'] ?? 0 ) );
		$covers        = $this->covers->get_all();
		foreach ( $covers as $i => $cover ) {
			$covers[ $i ]['sample_ids'] = $this->items->find_ids_with_image(
				(string) $cover['source'],
				(string) $cover['catbox_url'],
				3
			);
		}
		$sync_stats    = get_option( 'nc_catbox_sync_stats', null );
		$retry_stats   = get_option( 'nc_catbox_retry_stats', null );
		$msg           = isset( $_GET['nc_msg'] ) ? sanitize_key( (string) $_GET['nc_msg'] ) : '';

		include NC_PLUGIN_DIR . 'admin/views/catbox.php';
	}
}
