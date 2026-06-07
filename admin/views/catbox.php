<?php
/**
 * Admin view: Catbox Albums & Sync.
 *
 * @package wp-news-collector
 * @var array<int, array<string, mixed>> $albums
 * @var int                              $total_uploads
 * @var int                              $unassigned
 * @var array<string, mixed>|null        $sync_stats
 * @var string                           $msg
 * @var array<string, mixed>             $uploads_data
 * @var string                           $uploads_filter
 */

defined( 'ABSPATH' ) || exit;

$msg_map = [
	'sync_queued'  => [ 'success', __( 'Sync queued in Action Scheduler.', 'wp-news-collector' ) ],
	'sync_ran'     => [ 'success', __( 'Sync completed.', 'wp-news-collector' ) ],
	'no_userhash'  => [ 'error',   __( 'Configure the Catbox userhash in Settings before managing albums.', 'wp-news-collector' ) ],
];
?>
<div class="wrap">
<h1><?php esc_html_e( 'Catbox: Albums & Sync', 'wp-news-collector' ); ?></h1>

<?php if ( isset( $msg_map[ $msg ] ) ) : ?>
	<div class="notice notice-<?php echo esc_attr( $msg_map[ $msg ][0] ); ?> is-dismissible">
		<p><?php echo esc_html( $msg_map[ $msg ][1] ); ?></p>
	</div>
<?php endif; ?>

<!-- -----------------------------------------------------------------------
     Stats bar
--------------------------------------------------------------------- -->
<div style="display:flex;gap:1.5rem;margin:1rem 0 1.5rem;flex-wrap:wrap;">
	<div class="postbox" style="margin:0;padding:.75rem 1rem;min-width:140px;">
		<p class="description" style="margin:0 0 2px"><?php esc_html_e( 'Total uploads', 'wp-news-collector' ); ?></p>
		<strong style="font-size:1.4em"><?php echo (int) $total_uploads; ?></strong>
	</div>
	<div class="postbox" style="margin:0;padding:.75rem 1rem;min-width:140px;">
		<p class="description" style="margin:0 0 2px"><?php esc_html_e( 'No album', 'wp-news-collector' ); ?></p>
		<strong style="font-size:1.4em;color:<?php echo $unassigned > 0 ? '#b32d2e' : 'inherit'; ?>"><?php echo (int) $unassigned; ?></strong>
	</div>
	<div class="postbox" style="margin:0;padding:.75rem 1rem;min-width:140px;">
		<p class="description" style="margin:0 0 2px"><?php esc_html_e( 'Albums', 'wp-news-collector' ); ?></p>
		<strong style="font-size:1.4em"><?php echo count( $albums ); ?></strong>
	</div>
</div>

<!-- -----------------------------------------------------------------------
     Sync info + manual trigger
--------------------------------------------------------------------- -->
<div class="postbox" style="padding:1rem 1.25rem;max-width:700px;margin-bottom:1.5rem;">
	<h2 style="margin-top:0"><?php esc_html_e( 'Daily sync', 'wp-news-collector' ); ?></h2>
	<?php if ( is_array( $sync_stats ) ) : ?>
		<p>
			<?php
			printf(
				// translators: 1: date/time, 2: tracked count, 3: assigned count
				esc_html__( 'Last sync: %1$s: %2$d tracked, %3$d assigned to album.', 'wp-news-collector' ),
				esc_html( (string) ( $sync_stats['ran_at'] ?? '—' ) ),
				(int) ( $sync_stats['tracked'] ?? 0 ),
				(int) ( $sync_stats['assigned'] ?? 0 )
			);
			?>
		</p>
		<?php if ( ! empty( $sync_stats['errors'] ) ) : ?>
			<details>
				<summary style="cursor:pointer;color:#b32d2e"><?php printf( esc_html__( '%d errors', 'wp-news-collector' ), count( $sync_stats['errors'] ) ); ?></summary>
				<ul style="margin:.5rem 0 0 1rem">
					<?php foreach ( (array) $sync_stats['errors'] as $err ) : ?>
						<li><?php echo esc_html( (string) $err ); ?></li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endif; ?>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'No sync has been run yet.', 'wp-news-collector' ); ?></p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:.75rem">
		<?php wp_nonce_field( 'nc_catbox_sync' ); ?>
		<input type="hidden" name="action" value="nc_catbox_sync" />
		<?php submit_button( __( 'Run sync now', 'wp-news-collector' ), 'secondary', 'submit', false ); ?>
	</form>
</div>

<!-- -----------------------------------------------------------------------
     Albums table
--------------------------------------------------------------------- -->
<h2><?php esc_html_e( 'Monthly albums', 'wp-news-collector' ); ?></h2>
<?php if ( empty( $albums ) ) : ?>
	<p class="description"><?php esc_html_e( 'No albums yet. They are created automatically with the first sync.', 'wp-news-collector' ); ?></p>
<?php else : ?>
<table class="wp-list-table widefat fixed striped" style="max-width:800px;">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Month', 'wp-news-collector' ); ?></th>
			<th><?php esc_html_e( 'Name', 'wp-news-collector' ); ?></th>
				<th><?php esc_html_e( 'Album ID', 'wp-news-collector' ); ?></th>
			<th style="width:90px;text-align:right"><?php esc_html_e( 'Files', 'wp-news-collector' ); ?></th>
			<th style="width:160px"><?php esc_html_e( 'Created', 'wp-news-collector' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $albums as $album ) : ?>
		<tr>
			<td><strong><?php echo esc_html( (string) $album['month'] ); ?></strong></td>
			<td><?php
				// Stored name; fall back to the computed name for legacy rows saved before the column existed.
				$album_name = '' !== (string) ( $album['name'] ?? '' )
					? (string) $album['name']
					: NC_Plugin::catbox_album_name( (string) $album['month'] );
				echo esc_html( $album_name );
			?></td>
			<td>
				<a href="<?php echo esc_url( 'https://catbox.moe/c/' . $album['album_id'] ); ?>" target="_blank" rel="noreferrer noopener">
					<?php echo esc_html( (string) $album['album_id'] ); ?>
				</a>
			</td>
			<td style="text-align:right"><?php echo (int) $album['file_count']; ?></td>
			<td><?php echo esc_html( (string) $album['created_at'] ); ?></td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>

<!-- -----------------------------------------------------------------------
     Uploads list
--------------------------------------------------------------------- -->
<h2 style="margin-top:2rem"><?php esc_html_e( 'Tracked uploads', 'wp-news-collector' ); ?></h2>

<ul class="subsubsub" style="margin-bottom:.5rem">
	<?php
	$base_url = add_query_arg( [ 'page' => 'nc_catbox' ], admin_url( 'admin.php' ) );
	$filters  = [
		'all'        => __( 'All', 'wp-news-collector' ),
		'unassigned' => __( 'No album', 'wp-news-collector' ),
	];
	$f_links = [];
	foreach ( $filters as $fkey => $flabel ) {
		$class    = $fkey === $uploads_filter ? 'current' : '';
		$f_links[] = sprintf(
			'<li><a href="%s" class="%s">%s</a></li>',
			esc_url( add_query_arg( 'uf', $fkey, $base_url ) ),
			esc_attr( $class ),
			esc_html( $flabel )
		);
	}
	echo implode( ' | ', $f_links ); // phpcs:ignore WordPress.Security.EscapeOutput
	?>
</ul>

<?php if ( empty( $uploads_data['items'] ) ) : ?>
	<p class="description"><?php esc_html_e( 'No uploads to show.', 'wp-news-collector' ); ?></p>
<?php else : ?>
<table class="wp-list-table widefat fixed striped">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Catbox URL', 'wp-news-collector' ); ?></th>
			<th style="width:100px"><?php esc_html_e( 'Type', 'wp-news-collector' ); ?></th>
			<th style="width:140px"><?php esc_html_e( 'Source', 'wp-news-collector' ); ?></th>
			<th style="width:90px"><?php esc_html_e( 'Album', 'wp-news-collector' ); ?></th>
			<th style="width:140px"><?php esc_html_e( 'Uploaded', 'wp-news-collector' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $uploads_data['items'] as $upload ) : ?>
		<tr>
			<td>
				<a href="<?php echo esc_url( (string) $upload['catbox_url'] ); ?>" target="_blank" rel="noreferrer noopener">
					<?php echo esc_html( basename( (string) $upload['catbox_url'] ) ); ?>
				</a>
			</td>
			<td><?php echo esc_html( (string) $upload['upload_type'] ); ?></td>
			<td><?php echo esc_html( (string) ( $upload['source_name'] ?: $upload['source'] ) ); ?></td>
			<td>
				<?php if ( ! empty( $upload['album_id'] ) ) : ?>
					<a href="<?php echo esc_url( 'https://catbox.moe/c/' . $upload['album_id'] ); ?>" target="_blank" rel="noreferrer noopener">
						<?php echo esc_html( (string) $upload['album_id'] ); ?>
					</a>
				<?php else : ?>
					<span style="color:#888">—</span>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( (string) $upload['uploaded_at'] ); ?></td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php if ( (int) $uploads_data['total_pages'] > 1 ) : ?>
<div class="tablenav bottom" style="margin-top:.5rem">
	<div class="tablenav-pages">
		<?php
		printf(
			'<span class="displaying-num">%s</span>',
			sprintf(
				// translators: %d: total upload count
				esc_html__( '%d items', 'wp-news-collector' ),
				(int) $uploads_data['total_items']
			)
		);
		$prev_url = $uploads_data['has_prev']
			? esc_url( add_query_arg( [ 'uf' => $uploads_filter, 'upaged' => $uploads_data['page'] - 1 ], $base_url ) )
			: '';
		$next_url = $uploads_data['has_next']
			? esc_url( add_query_arg( [ 'uf' => $uploads_filter, 'upaged' => $uploads_data['page'] + 1 ], $base_url ) )
			: '';
		if ( $prev_url ) {
			echo '<a class="button" href="' . $prev_url . '">&laquo; ' . esc_html__( 'Previous', 'wp-news-collector' ) . '</a> ';
		}
		echo '<span>' . (int) $uploads_data['page'] . ' / ' . (int) $uploads_data['total_pages'] . '</span>';
		if ( $next_url ) {
			echo ' <a class="button" href="' . $next_url . '">' . esc_html__( 'Next', 'wp-news-collector' ) . ' &raquo;</a>';
		}
		?>
	</div>
</div>
<?php endif; ?>
<?php endif; ?>

</div>
