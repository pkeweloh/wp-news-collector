<?php
/**
 * Settings page view.
 *
 * @package wp-news-collector
 * @var array<string, mixed> $settings
 * @var string $msg
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap nc-wrap">
	<h1><?php esc_html_e( 'News Collector: Settings', 'wp-news-collector' ); ?></h1>

	<?php settings_errors( 'nc_settings' ); ?>

	<?php if ( 'queued' === $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Fetch job queued.', 'wp-news-collector' ); ?></p></div>
	<?php elseif ( 'ran' === $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Fetch cycle completed (Action Scheduler not available).', 'wp-news-collector' ); ?></p></div>
	<?php elseif ( 'backfill_queued' === $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Catbox backfill queued: it will run on the next Action Scheduler tick.', 'wp-news-collector' ); ?></p></div>
	<?php elseif ( 'backfill_ran' === $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Catbox backfill completed.', 'wp-news-collector' ); ?></p></div>
	<?php elseif ( 'catbox_off' === $msg ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Enable Catbox uploads first and save settings, then run the backfill.', 'wp-news-collector' ); ?></p></div>
	<?php endif; ?>

	<form action="options.php" method="post">
		<?php settings_fields( 'nc_settings_group' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="nc_catbox_enabled"><?php esc_html_e( 'Enable Catbox uploads', 'wp-news-collector' ); ?></label></th>
				<td>
					<input type="checkbox" id="nc_catbox_enabled" name="nc_settings[catbox_enabled]" value="1" <?php checked( ! empty( $settings['catbox_enabled'] ) ); ?> />
					<p class="description"><?php esc_html_e( 'Upload all images and videos to catbox.moe for persistent hosting.', 'wp-news-collector' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nc_catbox_userhash"><?php esc_html_e( 'Catbox userhash', 'wp-news-collector' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="nc_catbox_userhash" name="nc_settings[catbox_userhash]" value="<?php echo esc_attr( (string) $settings['catbox_userhash'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional. Tie uploads to a Catbox account.', 'wp-news-collector' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Auto-retry failed uploads', 'wp-news-collector' ); ?></th>
				<td>
					<label>
						<input type="checkbox" id="nc_catbox_retry_enabled" name="nc_settings[catbox_retry_enabled]" value="1" <?php checked( ! empty( $settings['catbox_retry_enabled'] ) ); ?> />
						<?php esc_html_e( 'Periodically re-upload failed Catbox uploads (needs a userhash).', 'wp-news-collector' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Uses per-item exponential backoff and a circuit breaker that pauses until the next sweep when Catbox is down.', 'wp-news-collector' ); ?></p>
					<div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin:.7rem 0 0;">
						<label style="display:flex;flex-direction:column;gap:.25rem;">
							<span><?php esc_html_e( 'Interval (seconds)', 'wp-news-collector' ); ?></span>
							<input type="number" min="300" style="width:9rem" name="nc_settings[catbox_retry_interval]" value="<?php echo esc_attr( (string) ( $settings['catbox_retry_interval'] ?? 3600 ) ); ?>" />
						</label>
						<label style="display:flex;flex-direction:column;gap:.25rem;">
							<span><?php esc_html_e( 'Batch size', 'wp-news-collector' ); ?></span>
							<input type="number" min="1" style="width:9rem" name="nc_settings[catbox_retry_batch_size]" value="<?php echo esc_attr( (string) ( $settings['catbox_retry_batch_size'] ?? 10 ) ); ?>" />
						</label>
						<label style="display:flex;flex-direction:column;gap:.25rem;">
							<span><?php esc_html_e( 'Max attempts (0 = unlimited)', 'wp-news-collector' ); ?></span>
							<input type="number" min="0" style="width:9rem" name="nc_settings[catbox_retry_max_attempts]" value="<?php echo esc_attr( (string) ( $settings['catbox_retry_max_attempts'] ?? 8 ) ); ?>" />
						</label>
						<label style="display:flex;flex-direction:column;gap:.25rem;">
							<span><?php esc_html_e( 'Breaker threshold', 'wp-news-collector' ); ?></span>
							<input type="number" min="1" style="width:9rem" name="nc_settings[catbox_retry_breaker_threshold]" value="<?php echo esc_attr( (string) ( $settings['catbox_retry_breaker_threshold'] ?? 3 ) ); ?>" />
						</label>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nc_fetch_interval"><?php esc_html_e( 'Fetch interval (minutes)', 'wp-news-collector' ); ?></label></th>
				<td><input type="number" min="1" id="nc_fetch_interval" name="nc_settings[fetch_interval_minutes]" value="<?php echo esc_attr( (string) $settings['fetch_interval_minutes'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="nc_max_items"><?php esc_html_e( 'Max items per source per cycle', 'wp-news-collector' ); ?></label></th>
				<td><input type="number" min="1" id="nc_max_items" name="nc_settings[max_items_per_source]" value="<?php echo esc_attr( (string) $settings['max_items_per_source'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="nc_item_slug"><?php esc_html_e( 'Item permalink slug', 'wp-news-collector' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="nc_item_slug" name="nc_settings[item_slug]"
						value="<?php echo esc_attr( (string) ( $settings['item_slug'] ?? 'item' ) ); ?>" />
					<p class="description">
						<?php
						printf(
							/* translators: %s: current permalink example */
							esc_html__( 'URL prefix for single item pages. Current: %s', 'wp-news-collector' ),
							'<code>' . esc_html( home_url( '/' . ( $settings['item_slug'] ?? 'item' ) . '/123' ) ) . '</code>'
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nc_source_slug"><?php esc_html_e( 'Source permalink slug', 'wp-news-collector' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="nc_source_slug" name="nc_settings[source_slug]"
						value="<?php echo esc_attr( (string) ( $settings['source_slug'] ?? 'source' ) ); ?>" />
					<p class="description">
						<?php
						printf(
							/* translators: %s: current source page URL example */
							esc_html__( 'URL prefix for per-source channel pages. Must differ from the item slug. Current: %s', 'wp-news-collector' ),
							'<code>' . esc_html( home_url( '/' . ( $settings['source_slug'] ?? 'source' ) . '/channel' ) ) . '</code>'
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>

	<hr/>
	<h2><?php esc_html_e( 'Run now', 'wp-news-collector' ); ?></h2>
	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block;margin-right:8px">
		<input type="hidden" name="action" value="nc_run_now" />
		<?php wp_nonce_field( 'nc_run_now' ); ?>
		<?php submit_button( __( 'Fetch all sources now', 'wp-news-collector' ), 'secondary', 'submit', false ); ?>
	</form>

	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block">
		<input type="hidden" name="action" value="nc_backfill_catbox" />
		<?php wp_nonce_field( 'nc_backfill_catbox' ); ?>
		<?php submit_button(
			__( 'Re-upload existing items to Catbox', 'wp-news-collector' ),
			'secondary',
			'submit',
			false,
			[ 'onclick' => 'return confirm(\'' . esc_js( __( 'This will re-upload all non-Catbox media on existing items. Continue?', 'wp-news-collector' ) ) . '\')' ]
		); ?>
	</form>
	<p class="description" style="margin-top:6px"><?php esc_html_e( 'Idempotent: items whose media already lives on files.catbox.moe are skipped.', 'wp-news-collector' ); ?></p>
</div>
