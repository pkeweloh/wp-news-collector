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

	/** Window for the attempt-log summary. */
	private const ATTEMPT_WINDOW_DAYS = 30;

	/** Shorter window for the markup alarm: it is about right now, not about history. */
	private const ALARM_WINDOW_DAYS = 7;

	public function __construct(
		private NC_Catbox_Upload_Repository $uploads,
		private NC_Catbox_Syncer $syncer,
		private NC_Item_Repository $items
	) {}

	public function render(): void {
		$settings = NC_Plugin::get_settings();
		// The cap decides which failures a sweep can still reach, so the chips have
		// to read it or "pending" would include pieces nothing will ever touch.
		$max_attempts = (int) ( $settings['catbox_retry_max_attempts'] ?? 0 );

		$total_uploads = $this->uploads->count_total();
		$failed        = $this->uploads->count_failed( $max_attempts );
		$exhausted     = $this->uploads->count_exhausted( $max_attempts );
		$parked        = $this->uploads->count_parked();
		$gone          = $this->uploads->count_source_gone();
		$cleanup_stats = get_option( 'nc_catbox_cleanup_stats', null );
		$msg           = isset( $_GET['nc_msg'] ) ? sanitize_key( (string) $_GET['nc_msg'] ) : '';
		$msg_count     = isset( $_GET['nc_n'] ) ? (int) $_GET['nc_n'] : 0;

		$uploads_page_num = isset( $_GET['upaged'] ) ? max( 1, (int) $_GET['upaged'] ) : 1;
		$uploads_filter   = isset( $_GET['uf'] ) ? sanitize_key( (string) $_GET['uf'] ) : 'all';
		$uploads_data     = $this->uploads->get_page( $uploads_page_num, 30, $uploads_filter, $max_attempts );
		$album_month_map  = $this->uploads->get_album_month_map();

		$attempt_days   = self::ATTEMPT_WINDOW_DAYS;
		$attempt_counts = $this->uploads->attempt_outcome_counts( $attempt_days );
		$alarm_days     = self::ALARM_WINDOW_DAYS;
		$markup_alarm   = $this->uploads->count_markup_alarm( $alarm_days );

		// The view needs this to hide retry buttons that could not work.
		$failed_rows = [];
		foreach ( (array) $uploads_data['items'] as $row ) {
			if ( '' === (string) ( $row['catbox_url'] ?? '' ) ) {
				$failed_rows[] = $row;
			}
		}
		$linked_map = $this->syncer->linked_map( $failed_rows );
		$item_refs  = $this->items->get_refs_by_guids( wp_list_pluck( (array) $uploads_data['items'], 'item_guid' ) );

		include NC_PLUGIN_DIR . 'admin/views/catbox-uploads.php';
	}
}
