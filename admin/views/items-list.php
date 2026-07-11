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
 * @var array<string, int> $filter_counts
 * @var int $bulk_uploaded
 * @var int $bulk_failed
 * @var array<string, mixed> $last_run
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
	<h1><?php esc_html_e( 'News Collector: Items', 'wp-news-collector' ); ?></h1>

	<?php if ( ! empty( $last_run ) ) : ?>
		<?php $lr_errors = (array) ( $last_run['errors'] ?? [] ); ?>
		<div class="notice notice-<?php echo empty( $lr_errors ) ? 'info' : 'warning'; ?> inline" style="margin:1rem 0">
			<p style="margin:.5em 0"><?php
				echo esc_html( sprintf(
					/* translators: 1: date, 2: fetched, 3: new, 4: skipped */
					__( 'Last fetch %1$s: %2$d items fetched, %3$d new, %4$d skipped.', 'wp-news-collector' ),
					get_date_from_gmt( (string) ( $last_run['at'] ?? '' ), 'Y-m-d H:i' ),
					(int) ( $last_run['fetched'] ?? 0 ),
					(int) ( $last_run['inserted'] ?? 0 ),
					(int) ( $last_run['skipped'] ?? 0 )
				) );
			?></p>
			<?php if ( ! empty( $lr_errors ) ) : ?>
				<details style="margin:0 0 .5em">
					<summary style="cursor:pointer;color:#b32d2e"><?php printf( esc_html__( '%d errors', 'wp-news-collector' ), count( $lr_errors ) ); ?></summary>
					<ul style="margin:.5em 0 0 1.5em;list-style:disc">
						<?php foreach ( $lr_errors as $err ) : ?>
							<li><code><?php echo esc_html( (string) $err ); ?></code></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( 'bulk_retry_catbox' === $msg ) : ?>
		<div class="notice notice-<?php echo $bulk_failed > 0 ? 'warning' : 'success'; ?> is-dismissible"><p><?php
			echo esc_html( sprintf(
				/* translators: 1: uploaded count, 2: failed count */
				__( 'Retry Catbox: %1$d uploaded, %2$d failed.', 'wp-news-collector' ),
				$bulk_uploaded,
				$bulk_failed
			) );
		?></p></div>
	<?php elseif ( '' !== $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php
			echo esc_html( sprintf( __( 'Action completed: %s', 'wp-news-collector' ), $msg ) );
		?></p></div>
	<?php endif; ?>

	<ul class="subsubsub">
		<?php
		$base_url = add_query_arg( [ 'page' => 'nc_items' ], admin_url( 'admin.php' ) );
		$f_links  = [];
		foreach ( $filters as $key => $label ) {
			$class     = $key === $vf ? 'current' : '';
			$count     = (int) ( $filter_counts[ $key ] ?? 0 );
			$f_links[] = sprintf(
				'<li><a href="%s" class="%s">%s <span class="count">(%d)</span></a></li>',
				esc_url( add_query_arg( 'vf', $key, $base_url ) ),
				esc_attr( $class ),
				esc_html( $label ),
				$count
			);
		}
		echo implode( ' | ', $f_links ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
	</ul>
	<br class="clear" />

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="nc_items_bulk" />
		<input type="hidden" name="vf" value="<?php echo esc_attr( $vf ); ?>" />
		<input type="hidden" name="paged" value="<?php echo (int) $paged; ?>" />
		<?php // Nonce is added by WP_List_Table::display_tablenav() as 'bulk-items': do not add a second one here. ?>

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
