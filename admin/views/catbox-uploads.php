<?php
/**
 * Admin view: Catbox tracked uploads + orphan cleanup.
 *
 * @package wp-news-collector
 * @var int                        $total_uploads
 * @var int                        $failed
 * @var int                        $exhausted
 * @var int                        $parked
 * @var int                        $gone
 * @var int                        $max_attempts
 * @var int                        $markup_alarm
 * @var int                        $alarm_days
 * @var array<string, mixed>|null  $cleanup_stats
 * @var string                     $msg
 * @var int                        $msg_count
 * @var array<string, mixed>       $uploads_data
 * @var string                     $uploads_filter
 * @var array<string, string>      $album_month_map
 * @var array<int, bool>           $linked_map
 * @var array<string, array{id:int, text:string}> $item_refs
 * @var array<string, int>         $attempt_counts
 * @var int                        $attempt_days
 */

defined( 'ABSPATH' ) || exit;

$msg_map = [
	'retry_ok'        => [ 'success', __( 'Retry completed: upload succeeded.', 'wp-news-collector' ) ],
	'retry_failed'    => [ 'error',   __( 'Retry failed: the upload could not be completed.', 'wp-news-collector' ) ],
	'catbox_disabled' => [ 'error',   __( 'Catbox is disabled. Enable it in Settings first.', 'wp-news-collector' ) ],
	'cleanup_scanned' => [ 'success', __( 'Orphan scan completed.', 'wp-news-collector' ) ],
	'cleanup_deleted' => [ 'success', __( 'Orphaned upload rows deleted.', 'wp-news-collector' ) ],
	'requeued'        => [
		'success',
		sprintf(
			// translators: %d: number of pieces put back in the retry queue
			_n( '%d retired piece put back in the queue.', '%d retired pieces put back in the queue.', $msg_count, 'wp-news-collector' ),
			(int) $msg_count
		),
	],
];

$base_url = add_query_arg( [ 'page' => 'nc_catbox_uploads' ], admin_url( 'admin.php' ) );
?>
<div class="wrap">
<h1><?php esc_html_e( 'Catbox: Uploads', 'wp-news-collector' ); ?></h1>

<?php if ( isset( $msg_map[ $msg ] ) ) : ?>
	<div class="notice notice-<?php echo esc_attr( $msg_map[ $msg ][0] ); ?> is-dismissible">
		<p><?php echo esc_html( $msg_map[ $msg ][1] ); ?></p>
	</div>
<?php endif; ?>

<!-- -----------------------------------------------------------------------
     Upload attempts
--------------------------------------------------------------------- -->
<div class="postbox" style="padding:1rem 1.25rem;max-width:700px;margin:1rem 0 1.5rem;">
	<h2 style="margin-top:0">
		<?php
		printf(
			// translators: %d: number of days in the attempt window
			esc_html__( 'Upload attempts (last %d days)', 'wp-news-collector' ),
			(int) $attempt_days
		);
		?>
	</h2>
	<p class="description" style="margin:0 0 .5rem">
		<?php esc_html_e( 'The uploads table only keeps the current state, and repairing a piece erases its error. This log records one row per attempt, which is what makes the real failure rate measurable.', 'wp-news-collector' ); ?>
	</p>
	<?php
	$outcome_labels = [
		'ok'              => __( 'Uploaded', 'wp-news-collector' ),
		'download_failed' => __( 'Download failed', 'wp-news-collector' ),
		'download_gone'   => __( 'Source gone', 'wp-news-collector' ),
		'upload_failed'   => __( 'Catbox rejected', 'wp-news-collector' ),
	];
	$attempt_total = array_sum( array_map( 'intval', (array) $attempt_counts ) );
	?>
	<?php if ( 0 === $attempt_total ) : ?>
		<p style="margin:0"><span class="description"><?php esc_html_e( 'No attempts recorded yet.', 'wp-news-collector' ); ?></span></p>
	<?php else : ?>
		<p style="margin:0">
			<?php
			$parts = [];
			foreach ( $outcome_labels as $okey => $olabel ) {
				$ocount = (int) ( $attempt_counts[ $okey ] ?? 0 );
				if ( 0 === $ocount ) {
					continue;
				}
				$parts[] = sprintf( '%s: %d', $olabel, $ocount );
			}
			echo esc_html( implode( ' · ', $parts ) );
			?>
			<span class="description">
				&middot;
				<?php
				printf(
					// translators: %d: total attempts in the window
					esc_html( _n( '%d attempt total', '%d attempts total', (int) $attempt_total, 'wp-news-collector' ) ),
					(int) $attempt_total
				);
				?>
			</span>
		</p>
	<?php endif; ?>
	<?php if ( (int) $markup_alarm > 0 ) : ?>
		<div class="notice notice-error inline" style="margin:.75rem 0 0">
			<p>
				<?php
				printf(
					// translators: 1: number of attempts, 2: number of days in the window
					esc_html(
						_n(
							'%1$d attempt in the last %2$d days could not read the Telegram embed page. The media is still there and we stopped finding it: the t.me markup probably changed and the reader needs updating.',
							'%1$d attempts in the last %2$d days could not read the Telegram embed page. The media is still there and we stopped finding it: the t.me markup probably changed and the reader needs updating.',
							(int) $markup_alarm,
							'wp-news-collector'
						)
					),
					(int) $markup_alarm,
					(int) $alarm_days
				);
				?>
			</p>
		</div>
	<?php endif; ?>
	<?php if ( (int) $exhausted > 0 ) : ?>
		<p style="margin:.5rem 0 0">
			<?php
			printf(
				// translators: 1: number of pieces, 2: attempt cap
				esc_html(
					_n(
						'%1$d piece used up its %2$d attempts: no sweep will pick it again, so it needs a manual retry or a decision.',
						'%1$d pieces used up their %2$d attempts: no sweep will pick them again, so they need a manual retry or a decision.',
						(int) $exhausted,
						'wp-news-collector'
					)
				),
				(int) $exhausted,
				(int) $max_attempts
			);
			?>
		</p>
	<?php endif; ?>
	<?php if ( (int) $gone > 0 ) : ?>
		<p style="margin:.5rem 0 0">
			<?php
			printf(
				// translators: %d: number of retired rows
				esc_html(
					_n(
						'%d piece retired: the fresh Telegram page did not give it either.',
						'%d pieces retired: the fresh Telegram page did not give them either.',
						(int) $gone,
						'wp-news-collector'
					)
				),
				(int) $gone
			);
			?>
		</p>
		<p class="description" style="margin:.25rem 0 .5rem">
			<?php esc_html_e( 'Pieces retired before the plugin learned to re-mint expired Telegram links were given up on too early: their message is permanent and its embed page re-signs the media on every request. Requeueing them re-runs the whole recovery, which costs one fetch per message.', 'wp-news-collector' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'nc_catbox_requeue' ); ?>
			<input type="hidden" name="action" value="nc_catbox_requeue" />
			<?php submit_button( __( 'Requeue retired pieces', 'wp-news-collector' ), 'secondary', 'submit', false ); ?>
		</form>
	<?php endif; ?>
</div>

<!-- -----------------------------------------------------------------------
     Orphaned upload rows
--------------------------------------------------------------------- -->
<div class="postbox" style="padding:1rem 1.25rem;max-width:700px;margin:1rem 0 1.5rem;">
	<h2 style="margin-top:0"><?php esc_html_e( 'Orphaned upload rows', 'wp-news-collector' ); ?></h2>
	<p class="description" style="margin:0 0 .5rem">
		<?php esc_html_e( 'Upload log rows whose media is no longer referenced by any item. Deleting them only removes the tracking row; the Catbox file is never touched.', 'wp-news-collector' ); ?>
	</p>
	<?php if ( is_array( $cleanup_stats ) ) : ?>
		<p style="margin:0 0 .5rem">
			<?php
			printf(
				// translators: 1: date/time, 2: orphan count, 3: total rows, 4: deleted count
				esc_html__( 'Last run: %1$s: %2$d orphan(s) of %3$d rows, %4$d deleted.', 'wp-news-collector' ),
				esc_html( NC_Template_Helpers::format_date_es( (string) ( $cleanup_stats['ran_at'] ?? '' ) ) ),
				(int) ( $cleanup_stats['orphans'] ?? 0 ),
				(int) ( $cleanup_stats['total'] ?? 0 ),
				(int) ( $cleanup_stats['deleted'] ?? 0 )
			);
			?>
			<?php if ( ! empty( $cleanup_stats['dry_run'] ) && (int) ( $cleanup_stats['orphans'] ?? 0 ) > 0 ) : ?>
				<span class="description">&middot; <?php esc_html_e( 'dry-run: nothing deleted yet.', 'wp-news-collector' ); ?></span>
			<?php endif; ?>
		</p>
	<?php endif; ?>
	<div style="display:flex;gap:.5rem;align-items:flex-start">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'nc_catbox_cleanup' ); ?>
			<input type="hidden" name="action" value="nc_catbox_cleanup" />
			<input type="hidden" name="mode" value="scan" />
			<?php submit_button( __( 'Scan for orphans', 'wp-news-collector' ), 'secondary', 'submit', false ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			onsubmit="return confirm('<?php echo esc_js( __( 'Delete all orphaned upload log rows? The Catbox files are not affected.', 'wp-news-collector' ) ); ?>');">
			<?php wp_nonce_field( 'nc_catbox_cleanup' ); ?>
			<input type="hidden" name="action" value="nc_catbox_cleanup" />
			<input type="hidden" name="mode" value="delete" />
			<?php submit_button( __( 'Delete orphans', 'wp-news-collector' ), 'delete', 'submit', false ); ?>
		</form>
	</div>
</div>

<!-- -----------------------------------------------------------------------
     Uploads list
--------------------------------------------------------------------- -->
<h2 style="margin-top:1rem"><?php esc_html_e( 'Tracked uploads', 'wp-news-collector' ); ?></h2>

<ul class="subsubsub" style="margin-bottom:.5rem">
	<?php
	// Disjoint buckets: a row counted twice would make a button offer to recover
	// more than it can.
	$filters = [
		'all'        => __( 'All', 'wp-news-collector' ),
		'unassigned' => __( 'No album', 'wp-news-collector' ),
		'failed'     => sprintf( '%s (%d)', __( 'Failed', 'wp-news-collector' ), (int) $failed ),
	];
	if ( (int) $exhausted > 0 ) {
		$filters['exhausted'] = sprintf( '%s (%d)', __( 'Out of attempts', 'wp-news-collector' ), (int) $exhausted );
	}
	if ( (int) $parked > 0 ) {
		$filters['orphaned'] = sprintf( '%s (%d)', __( 'Orphaned', 'wp-news-collector' ), (int) $parked );
	}
	$filters['gone'] = sprintf( '%s (%d)', __( 'Retired', 'wp-news-collector' ), (int) $gone );
	$f_links = [];
	foreach ( $filters as $fkey => $flabel ) {
		$class     = $fkey === $uploads_filter ? 'current' : '';
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
			<th style="width:80px"><?php esc_html_e( 'Item', 'wp-news-collector' ); ?></th>
			<th style="width:90px"><?php esc_html_e( 'Album', 'wp-news-collector' ); ?></th>
			<th><?php esc_html_e( 'Error', 'wp-news-collector' ); ?></th>
			<th style="width:140px"><?php esc_html_e( 'Uploaded', 'wp-news-collector' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $uploads_data['items'] as $upload ) : ?>
			<?php
			$up_catbox = (string) ( $upload['catbox_url'] ?? '' );
			$up_error  = (string) ( $upload['error'] ?? '' );
			$is_failed = '' === $up_catbox;
			$up_id     = (int) $upload['id'];
			$is_gone   = ! empty( $upload['source_gone'] );
			$is_linked = ! empty( $linked_map[ $up_id ] );
			// Retry only where it could succeed: still in the item, source not expired.
			$can_retry = $is_failed && ! $is_gone && $is_linked;
			$up_ref    = $item_refs[ (string) ( $upload['item_guid'] ?? '' ) ] ?? null;
			?>
		<tr<?php echo $is_failed ? ' style="background:#fcf0f1"' : ''; ?>>
			<td>
				<?php if ( $is_failed ) : ?>
					<span style="color:#b32d2e;font-weight:600"><?php esc_html_e( 'Failed', 'wp-news-collector' ); ?></span>
					<?php $orig = (string) ( $upload['original_url'] ?? '' ); ?>
					<?php if ( '' !== $orig ) : ?>
						<br><span class="description" style="word-break:break-all"><?php echo esc_html( $orig ); ?></span>
					<?php endif; ?>
				<?php else : ?>
					<a href="<?php echo esc_url( $up_catbox ); ?>" target="_blank" rel="noreferrer noopener">
						<?php echo esc_html( basename( $up_catbox ) ); ?>
					</a>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( (string) $upload['upload_type'] ); ?></td>
			<td><?php echo esc_html( (string) ( $upload['source_name'] ?: $upload['source'] ) ); ?></td>
			<td>
				<?php if ( is_array( $up_ref ) ) : ?>
					<?php $up_excerpt = trim( wp_strip_all_tags( (string) $up_ref['text'] ) ); ?>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'nc_items', 'view' => $up_ref['id'] ], admin_url( 'admin.php' ) ) ); ?>"
						title="<?php echo esc_attr( mb_substr( $up_excerpt, 0, 200 ) ); ?>">#<?php echo (int) $up_ref['id']; ?></a>
				<?php else : ?>
					<span style="color:#888">—</span>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( ! empty( $upload['album_id'] ) ) : ?>
					<?php
					$up_album_id = (string) $upload['album_id'];
					$up_month    = $album_month_map[ $up_album_id ] ?? '';
					$up_label    = '' !== $up_month
						? NC_Plugin::catbox_album_name( $up_month )
						: $up_album_id;
					?>
					<a href="<?php echo esc_url( 'https://catbox.moe/c/' . $up_album_id ); ?>" target="_blank" rel="noreferrer noopener">
						<?php echo esc_html( $up_label ); ?>
					</a>
				<?php else : ?>
					<span style="color:#888">—</span>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( $is_failed ) : ?>
					<?php if ( '' !== $up_error ) : ?>
						<details class="nc-upload-error">
							<summary style="cursor:pointer;color:#b32d2e"><?php esc_html_e( 'Show error', 'wp-news-collector' ); ?></summary>
							<p class="description" style="margin:.4rem 0;word-break:break-word"><?php echo esc_html( $up_error ); ?></p>
						</details>
					<?php endif; ?>
					<?php if ( $can_retry ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:.25rem 0 0">
							<?php wp_nonce_field( 'nc_retry_upload' ); ?>
							<input type="hidden" name="action" value="nc_retry_upload" />
							<input type="hidden" name="upload_id" value="<?php echo (int) $up_id; ?>" />
							<button type="submit" class="button button-small"><?php esc_html_e( 'Retry', 'wp-news-collector' ); ?></button>
						</form>
					<?php elseif ( $is_gone ) : ?>
						<p class="description" style="margin:.25rem 0 0">
							<?php esc_html_e( 'Retired: the fresh Telegram page did not give this piece either.', 'wp-news-collector' ); ?>
						</p>
					<?php else : ?>
						<p class="description" style="margin:.25rem 0 0">
							<?php esc_html_e( 'No longer part of the item: this row is history, not pending work.', 'wp-news-collector' ); ?>
						</p>
					<?php endif; ?>
						<?php
						// Mirrors alerta-boe's backoffLabel: "N reintentos · próximo <date>",
						// or "reintento pendiente" once next_retry_at is due.
						$up_retries = (int) ( $upload['retry_count'] ?? 0 );
						// A parked orphan carries a year-2999 sentinel; neither it nor a
						// retired row has a next run worth announcing.
						$up_next    = $can_retry ? (string) ( $upload['next_retry_at'] ?? '' ) : '';
						if ( $up_retries > 0 || '' !== $up_next ) :
							$backoff = [];
							if ( $up_retries > 0 ) {
								$backoff[] = sprintf( _n( '%d retry', '%d retries', $up_retries, 'wp-news-collector' ), $up_retries );
							}
							if ( '' !== $up_next ) {
								$due_ts    = strtotime( $up_next . ' UTC' );
								$backoff[] = ( false === $due_ts || $due_ts <= time() )
									? __( 'retry pending', 'wp-news-collector' )
									: sprintf(
										/* translators: %s: next retry date/time */
										__( 'next %s', 'wp-news-collector' ),
										NC_Template_Helpers::format_date_es( $up_next )
									);
							}
							?>
							<p class="description" style="margin:.25rem 0 0"><?php echo esc_html( implode( ' · ', $backoff ) ); ?></p>
						<?php endif; ?>
				<?php else : ?>
					<span style="color:#888">—</span>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( (string) $upload['uploaded_at'] ); ?></td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<script>
// Exclusive accordion: opening one row's error closes the others.
document.querySelectorAll('.nc-upload-error').forEach(function (d) {
	d.addEventListener('toggle', function () {
		if (d.open) {
			document.querySelectorAll('.nc-upload-error[open]').forEach(function (o) {
				if (o !== d) { o.open = false; }
			});
		}
	});
});
</script>

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
