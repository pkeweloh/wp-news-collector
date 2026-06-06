<?php
/**
 * Main plugin bootstrap class.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Plugin {

	private NC_Source_Repository $sources;
	private NC_Item_Repository $items;
	private NC_Catbox_Upload_Repository $uploads;
	private NC_News_Processor $processor;
	private NC_Catbox_Syncer $syncer;
	private NC_Rewrite $rewrite;
	private NC_Rest $rest;
	private NC_Detail_Page $detail;
	private ?NC_Admin $admin = null;
	private ?NC_Shortcode $shortcode = null;

	public function __construct() {
		$this->sources   = new NC_Source_Repository();
		$this->items     = new NC_Item_Repository();
		$this->uploads   = new NC_Catbox_Upload_Repository();
		$settings        = self::get_settings();
		$catbox          = new NC_Catbox_Uploader( $settings['catbox_userhash'] );
		$this->processor = new NC_News_Processor( $this->sources, $this->items, $catbox, $settings, $this->uploads );
		$this->syncer    = new NC_Catbox_Syncer( $this->items, $this->uploads, $catbox );
		$this->rewrite   = new NC_Rewrite();
		$this->rest      = new NC_Rest( $this->items );
		$this->detail    = new NC_Detail_Page( $this->items );

		if ( is_admin() ) {
			require_once NC_PLUGIN_DIR . 'admin/class-admin.php';
			$this->admin = new NC_Admin( $this->sources, $this->items, $this->processor, $this->uploads, $this->syncer );
		}

		require_once NC_PLUGIN_DIR . 'public/class-shortcode.php';
		$this->shortcode = new NC_Shortcode( $this->items );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'wp-news-collector',
			false,
			dirname( plugin_basename( NC_PLUGIN_FILE ) ) . '/languages'
		);
	}

	public function init(): void {
		if ( $this->admin ) {
			add_action( 'admin_menu', [ $this->admin, 'register_menus' ] );
			add_action( 'admin_init', [ $this->admin, 'register_settings' ] );
			add_action( 'admin_enqueue_scripts', [ $this->admin, 'enqueue_assets' ] );
			add_action( 'admin_post_nc_source_save', [ $this->admin, 'handle_source_save' ] );
			add_action( 'admin_post_nc_source_delete', [ $this->admin, 'handle_source_delete' ] );
			add_action( 'admin_post_nc_source_toggle', [ $this->admin, 'handle_source_toggle' ] );
			add_action( 'admin_post_nc_item_action', [ $this->admin, 'handle_item_action' ] );
			add_action( 'admin_post_nc_items_bulk', [ $this->admin, 'handle_items_bulk' ] );
			add_action( 'admin_post_nc_run_now', [ $this->admin, 'handle_run_now' ] );
			add_action( 'admin_post_nc_backfill_catbox', [ $this->admin, 'handle_backfill_catbox' ] );
			add_action( 'admin_post_nc_catbox_sync', [ $this->admin, 'handle_catbox_sync' ] );
			add_action( 'admin_post_nc_item_media_save', [ $this->admin, 'handle_item_media_save' ] );
		}

		add_action( 'init', [ $this->shortcode, 'register' ] );
		add_action( 'widgets_init', [ $this, 'register_widget' ] );
		add_action( 'nc_fetch_all_sources', [ $this->processor, 'run_cycle' ] );
		add_action( 'nc_backfill_catbox', [ $this->processor, 'backfill_catbox' ] );
		add_action( 'nc_backfill_catbox_ids', [ $this->processor, 'backfill_catbox' ] );
		add_action( 'nc_catbox_sync', [ $this->syncer, 'run_sync' ] );
		add_action( 'init', [ $this, 'maybe_schedule_recurring' ] );

		$this->rewrite->register();
		$this->rest->register();
		$this->detail->register();
	}

	/**
	 * Public permalink for a single item. Honors the site's permalink structure:
	 * pretty permalinks → /noticia/{id}; plain → ?nc_item={id}.
	 */
	public static function item_permalink( int $id ): string {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/' . NC_Rewrite::slug() . '/' . $id );
		}
		return add_query_arg( NC_Rewrite::QUERY_VAR, $id, home_url( '/' ) );
	}

	public function register_widget(): void {
		NC_Widget_Registry::set_items( $this->items );
		register_widget( 'NC_News_Widget' );
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_widget_assets' ] );
	}

	public function maybe_enqueue_widget_assets(): void {
		if ( is_active_widget( false, false, 'nc_news_widget', true ) ) {
			wp_enqueue_style( 'nc-public', NC_PLUGIN_URL . 'assets/css/public.css', [], NC_VERSION );
		}
	}

	public function maybe_schedule_recurring(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( ! as_next_scheduled_action( 'nc_fetch_all_sources' ) ) {
			$settings = self::get_settings();
			$interval = max( 1, (int) $settings['fetch_interval_minutes'] ) * 60;
			as_schedule_recurring_action( time() + 60, $interval, 'nc_fetch_all_sources', [], 'nc' );
		}
		// Daily catbox sync: only when userhash is configured.
		$settings = self::get_settings();
		if ( ! empty( $settings['catbox_userhash'] ) && ! as_next_scheduled_action( 'nc_catbox_sync' ) ) {
			as_schedule_recurring_action( time() + 300, DAY_IN_SECONDS, 'nc_catbox_sync', [], 'nc' );
		}
	}

	public static function default_settings(): array {
		return [
			'catbox_enabled'         => false,
			'catbox_userhash'        => '',
			'fetch_interval_minutes' => 30,
			'max_items_per_source'   => 50,
			'item_slug'              => 'noticia',
		];
	}

	public static function get_settings(): array {
		$stored = get_option( 'nc_settings', [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		return array_merge( self::default_settings(), $stored );
	}
}