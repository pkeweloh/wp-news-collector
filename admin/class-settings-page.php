<?php
/**
 * Settings page: Catbox / interval / max items.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Settings_Page {

	public function register(): void {
		register_setting(
			'nc_settings_group',
			'nc_settings',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => NC_Plugin::default_settings(),
			]
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$defaults = NC_Plugin::default_settings();
		$input    = is_array( $input ) ? $input : [];

		$prev_slug = (string) ( NC_Plugin::get_settings()['item_slug'] ?? 'noticia' );

		$out = [
			'catbox_enabled'         => ! empty( $input['catbox_enabled'] ),
			'catbox_userhash'        => isset( $input['catbox_userhash'] ) ? sanitize_text_field( (string) $input['catbox_userhash'] ) : '',
			'fetch_interval_minutes' => isset( $input['fetch_interval_minutes'] )
				? max( 1, (int) $input['fetch_interval_minutes'] )
				: $defaults['fetch_interval_minutes'],
			'max_items_per_source'   => isset( $input['max_items_per_source'] )
				? max( 1, (int) $input['max_items_per_source'] )
				: $defaults['max_items_per_source'],
			'item_slug'              => isset( $input['item_slug'] )
				? sanitize_title( (string) $input['item_slug'] ) ?: 'noticia'
				: $defaults['item_slug'],
		];

		// Reschedule recurring action with new interval.
		if ( function_exists( 'as_unschedule_all_actions' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			as_unschedule_all_actions( 'nc_fetch_all_sources' );
			as_schedule_recurring_action(
				time() + 60,
				$out['fetch_interval_minutes'] * 60,
				'nc_fetch_all_sources',
				[],
				'nc'
			);
		}

		// Flush rewrite rules if the item slug changed.
		if ( $out['item_slug'] !== $prev_slug ) {
			flush_rewrite_rules( false );
		}

		return $out;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = NC_Plugin::get_settings();
		$msg      = isset( $_GET['nc_msg'] ) ? sanitize_key( (string) $_GET['nc_msg'] ) : '';
		include NC_PLUGIN_DIR . 'admin/views/settings.php';
	}
}
