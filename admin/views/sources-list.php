<?php
/**
 * Sources list view.
 *
 * @package wp-news-collector
 * @var NC_Sources_Table $table
 * @var string $msg
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap nc-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'News Collector: Sources', 'wp-news-collector' ); ?></h1>
	<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'nc_sources', 'edit' => 'new' ], admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add new', 'wp-news-collector' ); ?></a>

	<?php if ( 'added' === $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Source added.', 'wp-news-collector' ); ?></p></div>
	<?php elseif ( 'updated' === $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Source updated.', 'wp-news-collector' ); ?></p></div>
	<?php elseif ( 'deleted' === $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Source deleted.', 'wp-news-collector' ); ?></p></div>
	<?php elseif ( 'toggled' === $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Source toggled.', 'wp-news-collector' ); ?></p></div>
	<?php elseif ( 'error' === $msg ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'URL is required.', 'wp-news-collector' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nc-inline-form" style="margin:18px 0;padding:14px;background:#fff;border:1px solid #ccd0d4">
		<input type="hidden" name="action" value="nc_source_save" />
		<?php wp_nonce_field( 'nc_source_save' ); ?>
		<label><?php esc_html_e( 'URL', 'wp-news-collector' ); ?>
			<input type="url" name="url" required style="width:420px" placeholder="https://rsshub.example/telegram/channel/foo" />
		</label>
		&nbsp;
		<label><?php esc_html_e( 'Name', 'wp-news-collector' ); ?>
			<input type="text" name="name" placeholder="<?php esc_attr_e( 'Display name (optional)', 'wp-news-collector' ); ?>" />
		</label>
		<?php submit_button( __( 'Add source', 'wp-news-collector' ), 'primary', 'submit', false ); ?>
	</form>

	<?php $table->display(); ?>
</div>
