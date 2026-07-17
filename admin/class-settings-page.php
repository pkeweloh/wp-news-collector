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
			'catbox_retry_enabled'           => ! empty( $input['catbox_retry_enabled'] ),
			'catbox_retry_interval'          => isset( $input['catbox_retry_interval'] )
				? max( 300, (int) $input['catbox_retry_interval'] )
				: $defaults['catbox_retry_interval'],
			'catbox_retry_batch_size'        => isset( $input['catbox_retry_batch_size'] )
				? max( 1, (int) $input['catbox_retry_batch_size'] )
				: $defaults['catbox_retry_batch_size'],
			'catbox_retry_max_attempts'      => isset( $input['catbox_retry_max_attempts'] )
				? max( 0, (int) $input['catbox_retry_max_attempts'] )
				: $defaults['catbox_retry_max_attempts'],
			'catbox_retry_breaker_threshold' => isset( $input['catbox_retry_breaker_threshold'] )
				? max( 1, (int) $input['catbox_retry_breaker_threshold'] )
				: $defaults['catbox_retry_breaker_threshold'],
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

			as_unschedule_all_actions( 'nc_catbox_retry' );
			if ( $out['catbox_retry_enabled'] && '' !== $out['catbox_userhash'] ) {
				as_schedule_recurring_action(
					time() + 120,
					$out['catbox_retry_interval'],
					'nc_catbox_retry',
					[],
					'nc'
				);
			}
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
