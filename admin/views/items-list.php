<?php
/**
 * Items list view.
 *
 * @package wp-news-collector
 * @var NC_Items_Table $table
 * @var array<string, mixed> $page
 * @var string $vf
 * @var int $paged
 * @var string $msg
 */

defined( 'ABSPATH' ) || exit;

$filters = [
	'all'           => __( 'All', 'wp-news-collector' ),
	'too_big'       => __( 'Too big', 'wp-news-collector' ),
	'upload_failed' => __( 'Upload failed', 'wp-news-collector' ),
	'hidden'        => __( 'Hidden', 'wp-news-collector' ),
];
?>
<div class="wrap nc-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'News Collector — Items', 'wp-news-collector' ); ?></h1>

	<?php if ( '' !== $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php
			echo esc_html( sprintf( __( 'Action completed: %s', 'wp-news-collector' ), $msg ) );
		?></p></div>
	<?php endif; ?>

	<form method="get" style="margin:12px 0">
		<input type="hidden" name="page" value="nc_items" />
		<label><?php esc_html_e( 'Filter', 'wp-news-collector' ); ?>
			<select name="vf">
				<?php foreach ( $filters as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $vf, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php submit_button( __( 'Apply', 'wp-news-collector' ), 'secondary', 'submit', false ); ?>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="nc_items_bulk" />
		<input type="hidden" name="vf" value="<?php echo esc_attr( $vf ); ?>" />
		<input type="hidden" name="paged" value="<?php echo (int) $paged; ?>" />
		<?php // Nonce is added by WP_List_Table::display_tablenav() as 'bulk-items' — do not add a second one here. ?>

		<div class="tablenav top">
			<select name="bulk_action">
				<option value=""><?php esc_html_e( 'Bulk actions', 'wp-news-collector' ); ?></option>
				<option value="retry_catbox"><?php esc_html_e( 'Retry Catbox', 'wp-news-collector' ); ?></option>
				<option value="hide"><?php esc_html_e( 'Hide', 'wp-news-collector' ); ?></option>
				<option value="show"><?php esc_html_e( 'Show', 'wp-news-collector' ); ?></option>
				<option value="delete"><?php esc_html_e( 'Delete', 'wp-news-collector' ); ?></option>
			</select>
			<?php submit_button( __( 'Apply', 'wp-news-collector' ), 'action', 'submit', false ); ?>
			<span class="displaying-num" style="margin-left:12px"><?php
				echo esc_html( sprintf( _n( '%d item', '%d items', (int) $page['total_items'], 'wp-news-collector' ), (int) $page['total_items'] ) );
			?></span>
		</div>

		<?php $table->display(); ?>
	</form>

	<?php
	if ( (int) $page['total_pages'] > 1 ) :
		$base = add_query_arg( [ 'page' => 'nc_items', 'vf' => $vf ], admin_url( 'admin.php' ) );
		?>
		<div class="tablenav bottom">
			<?php if ( $page['has_prev'] ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', (int) $page['page'] - 1, $base ) ); ?>">&laquo; <?php esc_html_e( 'Previous', 'wp-news-collector' ); ?></a>
			<?php endif; ?>
			<span><?php echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'wp-news-collector' ), (int) $page['page'], (int) $page['total_pages'] ) ); ?></span>
			<?php if ( $page['has_next'] ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', (int) $page['page'] + 1, $base ) ); ?>"><?php esc_html_e( 'Next', 'wp-news-collector' ); ?> &raquo;</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
