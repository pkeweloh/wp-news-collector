<?php
/**
 * Admin bootstrap: menus, asset enqueue, form handlers.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

require_once NC_PLUGIN_DIR . 'admin/class-settings-page.php';
require_once NC_PLUGIN_DIR . 'admin/class-sources-page.php';
require_once NC_PLUGIN_DIR . 'admin/class-items-page.php';
require_once NC_PLUGIN_DIR . 'admin/class-catbox-page.php';

class NC_Admin {

	private NC_Settings_Page $settings_page;
	private NC_Sources_Page $sources_page;
	private NC_Items_Page $items_page;
	private NC_Catbox_Page $catbox_page;

	public function __construct(
		private NC_Source_Repository $sources,
		private NC_Item_Repository $items,
		private NC_News_Processor $processor,
		private NC_Catbox_Upload_Repository $uploads,
		private NC_Catbox_Syncer $syncer,
		private NC_Source_Cover_Repository $covers,
	) {
		$this->settings_page = new NC_Settings_Page();
		$this->sources_page  = new NC_Sources_Page( $this->sources );
		$this->items_page    = new NC_Items_Page( $this->items );
		$this->catbox_page   = new NC_Catbox_Page( $this->uploads, $this->syncer, $this->covers, $this->items );
	}

	public function register_menus(): void {
		add_menu_page(
			__( 'News Collector', 'wp-news-collector' ),
			__( 'News Collector', 'wp-news-collector' ),
			'manage_options',
			'nc_items',
			[ $this->items_page, 'render' ],
			'dashicons-rss',
			30
		);
		add_submenu_page(
			'nc_items',
			__( 'Items', 'wp-news-collector' ),
			__( 'Items', 'wp-news-collector' ),
			'manage_options',
			'nc_items',
			[ $this->items_page, 'render' ]
		);
		add_submenu_page(
			'nc_items',
			__( 'Sources', 'wp-news-collector' ),
			__( 'Sources', 'wp-news-collector' ),
			'manage_options',
			'nc_sources',
			[ $this->sources_page, 'render' ]
		);
		add_submenu_page(
			'nc_items',
			__( 'Settings', 'wp-news-collector' ),
			__( 'Settings', 'wp-news-collector' ),
			'manage_options',
			'nc_settings',
			[ $this->settings_page, 'render' ]
		);
		add_submenu_page(
			'nc_items',
			__( 'Catbox', 'wp-news-collector' ),
			__( 'Catbox', 'wp-news-collector' ),
			'manage_options',
			'nc_catbox',
			[ $this->catbox_page, 'render' ]
		);
	}

	public function register_settings(): void {
		$this->settings_page->register();
	}

	public function enqueue_assets( string $hook ): void {
		// Only load on our admin pages.
		if ( false === strpos( $hook, 'nc_items' )
			&& false === strpos( $hook, 'nc_sources' )
			&& false === strpos( $hook, 'nc_settings' )
			&& false === strpos( $hook, 'nc_catbox' )
			&& false === strpos( $hook, 'news-collector' ) ) {
			return;
		}
		wp_enqueue_style( 'nc-admin', NC_PLUGIN_URL . 'assets/css/admin.css', [], NC_VERSION );
	}

	// Form handlers (admin-post.php)

	public function handle_source_save(): void {
		$this->ensure_admin();
		check_admin_referer( 'nc_source_save' );
		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$url  = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['url'] ) ) : '';
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';

		if ( $id > 0 ) {
			$enabled = isset( $_POST['enabled'] );
			$this->sources->update( $id, $name, $enabled );
			$msg = 'updated';
		} elseif ( '' !== $url ) {
			$this->sources->add( $url, $name );
			$msg = 'added';
		} else {
			$msg = 'error';
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'nc_sources', 'nc_msg' => $msg ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_source_delete(): void {
		$this->ensure_admin();
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'nc_source_delete_' . $id );
		if ( $id > 0 ) {
			$this->sources->delete( $id );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'nc_sources', 'nc_msg' => 'deleted' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_source_toggle(): void {
		$this->ensure_admin();
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'nc_source_toggle_' . $id );
		if ( $id > 0 ) {
			$row = $this->sources->get_by_id( $id );
			if ( $row ) {
				$this->sources->update( $id, null, ! (bool) $row['enabled'] );
			}
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'nc_sources', 'nc_msg' => 'toggled' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_item_action(): void {
		$this->ensure_admin();
		$id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$action = isset( $_GET['nc_action'] ) ? sanitize_key( (string) $_GET['nc_action'] ) : '';
		check_admin_referer( 'nc_item_' . $action . '_' . $id );

		switch ( $action ) {
			case 'hide':
				$this->items->set_enabled( $id, false );
				break;
			case 'show':
				$this->items->set_enabled( $id, true );
				break;
			case 'delete':
				$this->items->delete( $id );
				break;
		}
		$redirect = add_query_arg(
			[
				'page'   => 'nc_items',
				'nc_msg' => $action,
				'vf'     => isset( $_GET['vf'] ) ? sanitize_key( (string) $_GET['vf'] ) : 'all',
				'paged'  => isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1,
			],
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_items_bulk(): void {
		$this->ensure_admin();
		check_admin_referer( 'bulk-items' );
		$ids    = isset( $_POST['item_ids'] ) ? array_map( 'intval', (array) $_POST['item_ids'] ) : [];
		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( (string) $_POST['bulk_action'] ) : '';
		switch ( $action ) {
			case 'delete':
				$this->items->bulk_delete( $ids );
				break;
			case 'hide':
				$this->items->bulk_set_enabled( $ids, false );
				break;
			case 'show':
				$this->items->bulk_set_enabled( $ids, true );
				break;
			case 'retry_catbox':
				if ( ! empty( $ids ) ) {
					$settings = NC_Plugin::get_settings();
					if ( ! empty( $settings['catbox_enabled'] ) ) {
						if ( function_exists( 'as_enqueue_async_action' ) ) {
							as_enqueue_async_action( 'nc_backfill_catbox_ids', [ $ids ], 'nc' );
						} else {
							$this->processor->backfill_catbox( $ids );
						}
					}
				}
				break;
		}
		$redirect = add_query_arg(
			[
				'page'   => 'nc_items',
				'nc_msg' => 'bulk_' . $action,
				'vf'     => isset( $_POST['vf'] ) ? sanitize_key( (string) $_POST['vf'] ) : 'all',
				'paged'  => isset( $_POST['paged'] ) ? (int) $_POST['paged'] : 1,
			],
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_backfill_catbox(): void {
		$this->ensure_admin();
		check_admin_referer( 'nc_backfill_catbox' );
		$settings = NC_Plugin::get_settings();
		if ( empty( $settings['catbox_enabled'] ) ) {
			wp_safe_redirect( add_query_arg( [ 'page' => 'nc_settings', 'nc_msg' => 'catbox_off' ], admin_url( 'admin.php' ) ) );
			exit;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'nc_backfill_catbox', [], 'nc' );
			$msg = 'backfill_queued';
		} else {
			$this->processor->backfill_catbox();
			$msg = 'backfill_ran';
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'nc_settings', 'nc_msg' => $msg ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_item_media_save(): void {
		$this->ensure_admin();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id <= 0 ) {
			wp_die( esc_html__( 'Invalid item ID.', 'wp-news-collector' ) );
		}
		check_admin_referer( 'nc_item_media_save_' . $id );

		// Parse images: keep non-empty URLs only.
		$images = [];
		if ( isset( $_POST['images'] ) && is_array( $_POST['images'] ) ) {
			foreach ( $_POST['images'] as $raw ) {
				$url = esc_url_raw( wp_unslash( (string) $raw ) );
				if ( '' !== $url ) {
					$images[] = $url;
				}
			}
		}

		// Parse videos.
		$valid_statuses = [ 'pending', 'ok', 'upload_failed', 'too_big' ];
		$videos         = [];
		if ( isset( $_POST['videos'] ) && is_array( $_POST['videos'] ) ) {
			foreach ( $_POST['videos'] as $raw_video ) {
				if ( ! is_array( $raw_video ) ) {
					continue;
				}
				$catbox_url   = esc_url_raw( wp_unslash( (string) ( $raw_video['catbox_url'] ?? '' ) ) );
				$poster_url   = esc_url_raw( wp_unslash( (string) ( $raw_video['poster_url'] ?? '' ) ) );
				$original_url = esc_url_raw( wp_unslash( (string) ( $raw_video['original_url'] ?? '' ) ) );
				$status       = sanitize_key( (string) ( $raw_video['status'] ?? 'pending' ) );
				if ( ! in_array( $status, $valid_statuses, true ) ) {
					$status = 'pending';
				}
				$video = [ 'status' => $status ];
				if ( '' !== $original_url ) {
					$video['original_url'] = $original_url;
				}
				if ( '' !== $catbox_url ) {
					$video['catbox_url'] = $catbox_url;
				}
				if ( '' !== $poster_url ) {
					$video['poster_url'] = $poster_url;
				}
				// Only include the row if it has at least one URL.
				if ( ! empty( $video['catbox_url'] ) || ! empty( $video['poster_url'] ) || ! empty( $video['original_url'] ) ) {
					$videos[] = $video;
				}
			}
		}

		$item = $this->items->get_by_id( $id );
		if ( $item ) {
			$article = is_array( $item['article'] ?? null ) ? $item['article'] : null;
			$this->items->update_media( $id, $images, $videos, $article );
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'nc_items', 'view' => $id, 'nc_msg' => 'media_saved' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_catbox_sync(): void {
		$this->ensure_admin();
		check_admin_referer( 'nc_catbox_sync' );
		$settings = NC_Plugin::get_settings();
		if ( empty( $settings['catbox_userhash'] ) ) {
			wp_safe_redirect( add_query_arg( [ 'page' => 'nc_catbox', 'nc_msg' => 'no_userhash' ], admin_url( 'admin.php' ) ) );
			exit;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'nc_catbox_sync', [], 'nc' );
			$msg = 'sync_queued';
		} else {
			$this->syncer->run_sync();
			$msg = 'sync_ran';
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'nc_catbox', 'nc_msg' => $msg ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_detect_covers(): void {
		$this->ensure_admin();
		check_admin_referer( 'nc_detect_covers' );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'nc_detect_covers', [], 'nc' );
			$msg = 'covers_queued';
		} else {
			$this->processor->detect_cover_candidates();
			$msg = 'covers_ran';
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'nc_catbox', 'nc_msg' => $msg ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_set_cover_status(): void {
		$this->ensure_admin();
		check_admin_referer( 'nc_cover_status' );
		$rows = isset( $_POST['covers'] ) && is_array( $_POST['covers'] ) ? wp_unslash( $_POST['covers'] ) : [];

		$touched_sources = [];
		$any_confirmed    = false;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$source = isset( $row['source'] ) ? sanitize_text_field( (string) $row['source'] ) : '';
			$url    = isset( $row['catbox_url'] ) ? esc_url_raw( (string) $row['catbox_url'] ) : '';
			if ( '' === $source || '' === $url ) {
				continue;
			}
			// Binary: ticked = 'confirmed' (a cover, removed); unticked = 'candidate'.
			$status = ! empty( $row['is_cover'] ) ? 'confirmed' : 'candidate';
			$this->covers->set_status( $source, $url, $status );
			$touched_sources[ $source ] = true;
			if ( 'confirmed' === $status ) {
				$any_confirmed = true;
			}
		}

		// Recompute the display icon for every source shown in the table, since
		// unticking the current icon may need to hand it to another confirmed cover.
		foreach ( array_keys( $touched_sources ) as $source ) {
			$this->covers->set_primary_icon( $source );
		}

		if ( $any_confirmed ) {
			// Apply: strip newly confirmed covers from existing items.
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( 'nc_clean_covers', [], 'nc' );
			} else {
				$this->processor->clean_confirmed_covers();
			}
		}

		wp_safe_redirect( add_query_arg( [ 'page' => 'nc_catbox', 'nc_msg' => 'covers_saved' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_run_now(): void {
		$this->ensure_admin();
		check_admin_referer( 'nc_run_now' );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'nc_fetch_all_sources', [], 'nc' );
			$msg = 'queued';
		} else {
			$this->processor->run_cycle();
			$msg = 'ran';
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'nc_settings', 'nc_msg' => $msg ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private function ensure_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wp-news-collector' ) );
		}
	}
}
