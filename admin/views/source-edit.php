<?php
/**
 * Source edit view.
 *
 * @package wp-news-collector
 * @var array<string, mixed> $source
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap nc-wrap">
	<h1><?php esc_html_e( 'Edit source', 'wp-news-collector' ); ?></h1>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="nc_source_save" />
		<input type="hidden" name="id" value="<?php echo (int) $source['id']; ?>" />
		<?php wp_nonce_field( 'nc_source_save' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="nc_url"><?php esc_html_e( 'URL', 'wp-news-collector' ); ?></label></th>
				<td><input type="url" id="nc_url" class="regular-text" value="<?php echo esc_attr( (string) $source['url'] ); ?>" disabled />
					<p class="description"><?php esc_html_e( 'URL cannot be edited. Delete and re-add to change it.', 'wp-news-collector' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nc_name"><?php esc_html_e( 'Name', 'wp-news-collector' ); ?></label></th>
				<td><input type="text" id="nc_name" name="name" class="regular-text" value="<?php echo esc_attr( (string) $source['name'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Enabled', 'wp-news-collector' ); ?></th>
				<td><label><input type="checkbox" name="enabled" value="1" <?php checked( (int) $source['enabled'] === 1 ); ?> /> <?php esc_html_e( 'Active', 'wp-news-collector' ); ?></label></td>
			</tr>
		</table>
		<?php submit_button( __( 'Save', 'wp-news-collector' ) ); ?>
		<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'nc_sources' ], admin_url( 'admin.php' ) ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'wp-news-collector' ); ?></a>
	</form>
</div>
