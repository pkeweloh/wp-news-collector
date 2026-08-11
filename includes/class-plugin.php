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
	private NC_Source_Cover_Repository $covers;
	private NC_News_Processor $processor;
	private NC_Catbox_Syncer $syncer;
	private NC_Rewrite $rewrite;
	private NC_Rest $rest;
	private NC_Detail_Page $detail;
	private NC_Source_Page $source_page;
	private ?NC_Admin $admin = null;
	private ?NC_Shortcode $shortcode = null;

	public function __construct() {
		$this->sources   = new NC_Source_Repository();
		$this->items     = new NC_Item_Repository();
		$this->uploads   = new NC_Catbox_Upload_Repository();
		$this->covers    = new NC_Source_Cover_Repository();
		$settings        = self::get_settings();
		$catbox          = new NC_Catbox_Uploader( $settings['catbox_userhash'] );
		$this->processor = new NC_News_Processor( $this->sources, $this->items, $catbox, $settings, $this->uploads, $this->covers );
		$this->syncer    = new NC_Catbox_Syncer( $this->items, $this->uploads, $catbox );
		$this->rewrite   = new NC_Rewrite();
		$this->rest      = new NC_Rest( $this->items );
		$this->detail    = new NC_Detail_Page( $this->items );
		$this->source_page = new NC_Source_Page();

		if ( is_admin() ) {
			require_once NC_PLUGIN_DIR . 'admin/class-admin.php';
			$this->admin = new NC_Admin( $this->sources, $this->items, $this->processor, $this->uploads, $this->syncer, $this->covers );
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
			add_action( 'admin_post_nc_catbox_cleanup', [ $this->admin, 'handle_catbox_cleanup' ] );
			add_action( 'admin_post_nc_catbox_requeue', [ $this->admin, 'handle_catbox_requeue' ] );
			add_action( 'admin_post_nc_retry_upload', [ $this->admin, 'handle_retry_catbox_upload' ] );
			add_action( 'admin_post_nc_retry_item_uploads', [ $this->admin, 'handle_retry_item_uploads' ] );
			add_action( 'admin_post_nc_detect_covers', [ $this->admin, 'handle_detect_covers' ] );
			add_action( 'admin_post_nc_set_cover_status', [ $this->admin, 'handle_set_cover_status' ] );
			add_action( 'admin_post_nc_item_media_save', [ $this->admin, 'handle_item_media_save' ] );
		}

		add_action( 'init', [ $this->shortcode, 'register' ] );
		add_action( 'widgets_init', [ $this, 'register_widget' ] );
		add_action( 'nc_fetch_all_sources', [ $this->processor, 'run_cycle' ] );
		add_action( 'nc_backfill_catbox', [ $this->processor, 'backfill_catbox' ] );
		add_action( 'nc_backfill_catbox_ids', [ $this->processor, 'backfill_catbox' ] );
		add_action( 'nc_catbox_sync', [ $this->syncer, 'run_sync' ] );
		add_action( 'nc_catbox_retry', [ $this, 'run_catbox_retry' ] );
		add_action( 'nc_detect_covers', [ $this->processor, 'detect_cover_candidates' ] );
		add_action( 'nc_clean_covers', [ $this->processor, 'clean_confirmed_covers' ] );
		add_action( 'init', [ $this, 'maybe_schedule_recurring' ] );
		add_action( 'init', [ $this, 'maybe_flush_rewrite' ], 20 );

		$this->rewrite->register();
		$this->rest->register();
		$this->detail->register();
		$this->source_page->register();
	}

	/**
	 * Public permalink for a single item. Pretty permalinks → /{item_slug}/{id}
	 * (plus an optional decorative /{slug}); plain → ?nc_item={id}. The slug is
	 * ignored on read, so it is purely additive.
	 */
	public static function item_permalink( int $id, string $slug = '' ): string {
		if ( get_option( 'permalink_structure' ) ) {
			$url = home_url( '/' . NC_Rewrite::slug() . '/' . $id );
			if ( '' !== $slug ) {
				$url .= '/' . $slug;
			}
			return $url;
		}
		return add_query_arg( NC_Rewrite::QUERY_VAR, $id, home_url( '/' ) );
	}

	/** Public landing-page URL for a single source handle: /{source_slug}/{handle}. */
	public static function source_permalink( string $handle ): string {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/' . NC_Source_Page::base() . '/' . $handle );
		}
		return add_query_arg( NC_Source_Page::QUERY_VAR, $handle, home_url( '/' ) );
	}

	/**
	 * Catbox monthly album name. Single source of truth shared by album
	 * creation and the admin display, so the shown name always matches the
	 * name used on Catbox: "<site title> - YYYY-MM".
	 */
	public static function catbox_album_name( string $month ): string {
		return get_bloginfo( 'name' ) . ' - ' . $month;
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

	/** Runs at init priority 20, after the rewrite rules re-register with any new slugs. */
	public function maybe_flush_rewrite(): void {
		if ( get_option( 'nc_needs_rewrite_flush' ) ) {
			delete_option( 'nc_needs_rewrite_flush' );
			flush_rewrite_rules( false );
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
		if ( ! empty( $settings['catbox_userhash'] ) && ! empty( $settings['catbox_retry_enabled'] )
			&& ! as_next_scheduled_action( 'nc_catbox_retry' ) ) {
			$retry_interval = max( 300, (int) $settings['catbox_retry_interval'] );
			as_schedule_recurring_action( time() + 120, $retry_interval, 'nc_catbox_retry', [], 'nc' );
		}
	}

	public function run_catbox_retry(): void {
		$settings = self::get_settings();
		if ( empty( $settings['catbox_enabled'] ) || empty( $settings['catbox_userhash'] )
			|| empty( $settings['catbox_retry_enabled'] ) ) {
			return;
		}
		$this->syncer->retry_failed(
			max( 1, (int) $settings['catbox_retry_batch_size'] ),
			(int) $settings['catbox_retry_max_attempts'],
			(int) $settings['catbox_retry_breaker_threshold']
		);
	}

	public static function default_settings(): array {
		return [
			'catbox_enabled'                 => false,
			'catbox_userhash'                => '',
			'fetch_interval_minutes'         => 30,
			'max_items_per_source'           => 50,
			'item_slug'                      => 'item',
			'source_slug'                    => 'source',
			'catbox_retry_enabled'           => true,
			'catbox_retry_interval'          => 3600,
			'catbox_retry_batch_size'        => 10,
			'catbox_retry_max_attempts'      => 8,
			'catbox_retry_breaker_threshold' => 3,
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