<?php
/**
 * Single item view: read + editable media form.
 *
 * @package wp-news-collector
 * @var array<string, mixed> $item
 */

defined( 'ABSPATH' ) || exit;

$allowed = [
	'b'      => [],
	'strong' => [],
	'i'      => [],
	'em'     => [],
	'br'     => [],
	'p'      => [],
	'a'      => [ 'href' => true, 'target' => true, 'rel' => true ],
];

$id   = (int) $item['id'];
$back = add_query_arg( [ 'page' => 'nc_items' ], admin_url( 'admin.php' ) );
$msg  = isset( $_GET['nc_msg'] ) ? sanitize_key( (string) $_GET['nc_msg'] ) : '';

$videos  = (array) ( $item['videos'] ?? [] );
$images  = (array) ( $item['images'] ?? [] );
$audios  = (array) ( $item['audios'] ?? [] );
$article = is_array( $item['article'] ?? null ) ? $item['article'] : null;

$valid_statuses       = [ 'pending', 'ok', 'upload_failed', 'too_big' ];
$valid_audio_statuses = [ 'pending', 'ok', 'upload_failed' ];

$settings       = (array) get_option( 'nc_settings', [] );
$catbox_enabled = ! empty( $settings['catbox_enabled'] );

// A media piece still on a non-Catbox http URL means its upload is pending/failed.
$is_pending_url = static function ( string $url ): bool {
	return '' !== $url && 0 === strpos( $url, 'http' ) && 0 !== strpos( $url, 'https://files.catbox.moe/' );
};
$has_pending = false;
foreach ( $images as $img_url ) {
	if ( $is_pending_url( (string) $img_url ) ) {
		$has_pending = true;
		break;
	}
}
foreach ( $videos as $video ) {
	$video = (array) $video;
	if ( $is_pending_url( (string) ( $video['poster_url'] ?? '' ) ) ) {
		$has_pending = true;
	}
	if ( ( $video['status'] ?? '' ) !== 'too_big'
		&& '' !== (string) ( $video['original_url'] ?? '' )
		&& 0 !== strpos( (string) ( $video['catbox_url'] ?? '' ), 'https://files.catbox.moe/' ) ) {
		$has_pending = true;
	}
}
foreach ( $audios as $audio ) {
	$audio = (array) $audio;
	if ( '' !== (string) ( $audio['original_url'] ?? '' )
		&& 0 !== strpos( (string) ( $audio['catbox_url'] ?? '' ), 'https://files.catbox.moe/' ) ) {
		$has_pending = true;
	}
}
if ( is_array( $article ) && $is_pending_url( (string) ( $article['image_url'] ?? '' ) ) ) {
	$has_pending = true;
}

$item_msg_map = [
	'media_saved'     => [ 'success', __( 'Media saved successfully.', 'wp-news-collector' ) ],
	'retry_ok'        => [ 'success', __( 'Retry completed: pending uploads succeeded.', 'wp-news-collector' ) ],
	'retry_partial'   => [ 'warning', __( 'Retry finished with some uploads still failing.', 'wp-news-collector' ) ],
	'retry_none'      => [ 'info',    __( 'Nothing to retry: no pending uploads for this item.', 'wp-news-collector' ) ],
	'retry_failed'    => [ 'error',   __( 'Retry failed.', 'wp-news-collector' ) ],
	'catbox_disabled' => [ 'error',   __( 'Catbox is disabled. Enable it in Settings first.', 'wp-news-collector' ) ],
];
?>
<div class="wrap nc-wrap">
<h1><?php printf( esc_html__( 'Item #%d', 'wp-news-collector' ), $id ); ?></h1>
<?php
$delete_url = wp_nonce_url(
	add_query_arg(
		[ 'action' => 'nc_item_action', 'nc_action' => 'delete', 'id' => $id ],
		admin_url( 'admin-post.php' )
	),
	'nc_item_delete_' . $id
);
?>
<p style="display:flex;gap:.5rem;align-items:center">
	<a href="<?php echo esc_url( $back ); ?>" class="button">&laquo; <?php esc_html_e( 'Back to items', 'wp-news-collector' ); ?></a>
	<a href="<?php echo esc_url( $delete_url ); ?>" class="button"
		onclick="return confirm('<?php echo esc_js( __( 'Delete this item?', 'wp-news-collector' ) ); ?>')"
		style="color:#b32d2e;border-color:#b32d2e">✕ <?php esc_html_e( 'Delete', 'wp-news-collector' ); ?></a>
</p>

<?php if ( isset( $item_msg_map[ $msg ] ) ) : ?>
	<div class="notice notice-<?php echo esc_attr( $item_msg_map[ $msg ][0] ); ?> is-dismissible"><p><?php echo esc_html( $item_msg_map[ $msg ][1] ); ?></p></div>
<?php endif; ?>

<?php if ( $catbox_enabled && $has_pending ) : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 1rem">
		<?php wp_nonce_field( 'nc_retry_item_uploads_' . $id ); ?>
		<input type="hidden" name="action" value="nc_retry_item_uploads" />
		<input type="hidden" name="item_id" value="<?php echo $id; ?>" />
		<button type="submit" class="button button-secondary"><?php esc_html_e( 'Retry Catbox uploads', 'wp-news-collector' ); ?></button>
		<span class="description" style="margin-left:.5rem"><?php esc_html_e( 'Some media is still on its original URL.', 'wp-news-collector' ); ?></span>
	</form>
<?php endif; ?>

<!-- -----------------------------------------------------------------------
     Metadata (readonly)
--------------------------------------------------------------------- -->
<table class="widefat" style="max-width:900px;margin-bottom:1.5rem">
	<tbody>
		<?php $it_slug = NC_Template_Helpers::item_slug( $item ); ?>
		<tr><th><?php esc_html_e( 'ID', 'wp-news-collector' ); ?></th><td>#<?php echo (int) $id; ?>: <a href="<?php echo esc_url( NC_Plugin::item_permalink( $id, $it_slug ) ); ?>" target="_blank" rel="noreferrer noopener"><?php esc_html_e( 'View', 'wp-news-collector' ); ?> ↗</a></td></tr>
		<tr><th><?php esc_html_e( 'Slug', 'wp-news-collector' ); ?></th><td><?php echo '' !== $it_slug ? '<code>' . esc_html( $it_slug ) . '</code>' : '—'; ?></td></tr>
		<tr><th><?php esc_html_e( 'GUID', 'wp-news-collector' ); ?></th><td><code><?php echo esc_html( (string) $item['guid'] ); ?></code>: <a href="<?php echo esc_url( (string) $item['guid'] ); ?>" target="_blank" rel="noreferrer noopener">Telegram ↗</a></td></tr>
		<tr><th><?php esc_html_e( 'Source', 'wp-news-collector' ); ?></th><td><?php echo esc_html( (string) $item['source_name'] ); ?> <em>(<?php echo esc_html( (string) $item['source'] ); ?>)</em></td></tr>
		<tr><th><?php esc_html_e( 'Published', 'wp-news-collector' ); ?></th><td><?php echo esc_html( (string) $item['published_at'] ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Visible', 'wp-news-collector' ); ?></th><td><?php echo (int) $item['enabled'] === 1 ? esc_html__( 'Yes', 'wp-news-collector' ) : esc_html__( 'No', 'wp-news-collector' ); ?></td></tr>
	</tbody>
</table>

<!-- -----------------------------------------------------------------------
     Editable media form
--------------------------------------------------------------------- -->
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'nc_item_media_save_' . $id ); ?>
	<input type="hidden" name="action" value="nc_item_media_save" />
	<input type="hidden" name="id" value="<?php echo $id; ?>" />

	<!-- Text: locked (read-only) until the user clicks Edit. -->
	<div style="display:flex;align-items:center;gap:12px;margin-bottom:.5rem">
		<h2 style="margin:0"><?php esc_html_e( 'Text', 'wp-news-collector' ); ?></h2>
		<button type="button" id="nc-text-lock" class="button button-small">&#9998; <?php esc_html_e( 'Edit', 'wp-news-collector' ); ?></button>
	</div>
	<div id="nc-text-readonly" style="padding:12px;background:#fff;border:1px solid #ccd0d4;max-width:700px;margin-bottom:1.5rem">
		<?php echo wp_kses( (string) ( $item['text'] ?? '' ), $allowed ); ?>
	</div>
	<textarea id="nc-text-input" name="text" rows="6"
		style="display:none;width:100%;max-width:700px;margin-bottom:1.5rem;font-family:monospace;font-size:.9em"><?php echo esc_textarea( (string) ( $item['text'] ?? '' ) ); ?></textarea>

	<!-- Images -->
	<h2><?php esc_html_e( 'Images', 'wp-news-collector' ); ?></h2>
	<div id="nc-images-wrap">
		<?php foreach ( $images as $idx => $img_url ) : $img_url = (string) $img_url; ?>
		<div class="nc-media-row" style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
			<?php if ( '' !== $img_url ) : ?>
				<img src="<?php echo esc_url( $img_url ); ?>" style="width:48px;height:48px;object-fit:cover;border:1px solid #ccd0d4;flex-shrink:0" loading="lazy" />
			<?php else : ?>
				<span style="width:48px;height:48px;display:inline-block;background:#f0f0f1;flex-shrink:0"></span>
			<?php endif; ?>
			<input type="text" name="images[]" value="<?php echo esc_attr( $img_url ); ?>"
				placeholder="https://files.catbox.moe/…"
				style="flex:1;font-family:monospace;font-size:.85em" />
			<button type="button" class="button nc-remove-row" title="<?php esc_attr_e( 'Remove', 'wp-news-collector' ); ?>">✕</button>
		</div>
		<?php endforeach; ?>
	</div>
	<p>
		<button type="button" id="nc-add-image" class="button">
			+ <?php esc_html_e( 'Add image', 'wp-news-collector' ); ?>
		</button>
	</p>

	<!-- Videos -->
	<h2 style="margin-top:1.5rem"><?php esc_html_e( 'Videos', 'wp-news-collector' ); ?></h2>
	<div id="nc-videos-wrap">
		<?php foreach ( $videos as $idx => $video ) : $video = (array) $video; ?>
		<div class="nc-video-block" style="margin-bottom:1rem;padding:12px;background:#fff;border:1px solid #ccd0d4;max-width:700px">
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
				<strong><?php printf( esc_html__( 'Video %d', 'wp-news-collector' ), (int) $idx + 1 ); ?></strong>
				<button type="button" class="button nc-remove-row" title="<?php esc_attr_e( 'Remove video', 'wp-news-collector' ); ?>">✕ <?php esc_html_e( 'Remove', 'wp-news-collector' ); ?></button>
			</div>

			<?php if ( ! empty( $video['original_url'] ) ) : ?>
			<p style="margin:0 0 8px;font-size:.85em;color:#666">
				<?php esc_html_e( 'Original URL:', 'wp-news-collector' ); ?>
				<a href="<?php echo esc_url( (string) $video['original_url'] ); ?>" target="_blank" rel="noreferrer noopener" style="font-family:monospace">
					<?php echo esc_html( (string) $video['original_url'] ); ?>
				</a>
			</p>
			<input type="hidden" name="videos[<?php echo (int) $idx; ?>][original_url]" value="<?php echo esc_attr( (string) $video['original_url'] ); ?>" />
			<?php endif; ?>

			<table class="form-table" style="margin:0">
				<tr>
					<th style="width:120px;padding:4px 0"><?php esc_html_e( 'Catbox URL', 'wp-news-collector' ); ?></th>
					<td style="padding:4px 0">
						<input type="text" name="videos[<?php echo (int) $idx; ?>][catbox_url]"
							value="<?php echo esc_attr( (string) ( $video['catbox_url'] ?? '' ) ); ?>"
							placeholder="https://files.catbox.moe/…"
							style="width:100%;font-family:monospace;font-size:.85em" />
					</td>
				</tr>
				<tr>
					<th style="padding:4px 0"><?php esc_html_e( 'Poster URL', 'wp-news-collector' ); ?></th>
					<td style="padding:4px 0;display:flex;align-items:center;gap:8px">
						<?php $poster = (string) ( $video['poster_url'] ?? '' ); ?>
						<?php if ( '' !== $poster ) : ?>
							<img src="<?php echo esc_url( $poster ); ?>" style="width:48px;height:48px;object-fit:cover;border:1px solid #ccd0d4;flex-shrink:0" loading="lazy" />
						<?php endif; ?>
						<input type="text" name="videos[<?php echo (int) $idx; ?>][poster_url]"
							value="<?php echo esc_attr( $poster ); ?>"
							placeholder="https://files.catbox.moe/…"
							style="flex:1;font-family:monospace;font-size:.85em" />
					</td>
				</tr>
				<tr>
					<th style="padding:4px 0"><?php esc_html_e( 'Status', 'wp-news-collector' ); ?></th>
					<td style="padding:4px 0">
						<select name="videos[<?php echo (int) $idx; ?>][status]">
							<?php foreach ( $valid_statuses as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( (string) ( $video['status'] ?? 'pending' ), $s ); ?>>
									<?php echo esc_html( $s ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
		</div>
		<?php endforeach; ?>
	</div>
	<p>
		<button type="button" id="nc-add-video" class="button">
			+ <?php esc_html_e( 'Add video', 'wp-news-collector' ); ?>
		</button>
	</p>

	<!-- Audios -->
	<h2 style="margin-top:1.5rem"><?php esc_html_e( 'Audios', 'wp-news-collector' ); ?></h2>
	<div id="nc-audios-wrap">
		<?php foreach ( $audios as $idx => $audio ) : $audio = (array) $audio; ?>
		<div class="nc-audio-block" style="margin-bottom:1rem;padding:12px;background:#fff;border:1px solid #ccd0d4;max-width:700px">
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
				<strong><?php printf( esc_html__( 'Audio %d', 'wp-news-collector' ), (int) $idx + 1 ); ?></strong>
				<button type="button" class="button nc-remove-row" title="<?php esc_attr_e( 'Remove audio', 'wp-news-collector' ); ?>">✕ <?php esc_html_e( 'Remove', 'wp-news-collector' ); ?></button>
			</div>

			<?php if ( ! empty( $audio['original_url'] ) ) : ?>
			<p style="margin:0 0 8px;font-size:.85em;color:#666">
				<?php esc_html_e( 'Original URL:', 'wp-news-collector' ); ?>
				<a href="<?php echo esc_url( (string) $audio['original_url'] ); ?>" target="_blank" rel="noreferrer noopener" style="font-family:monospace">
					<?php echo esc_html( (string) $audio['original_url'] ); ?>
				</a>
			</p>
			<input type="hidden" name="audios[<?php echo (int) $idx; ?>][original_url]" value="<?php echo esc_attr( (string) $audio['original_url'] ); ?>" />
			<?php endif; ?>

			<table class="form-table" style="margin:0">
				<tr>
					<th style="width:120px;padding:4px 0"><?php esc_html_e( 'Catbox URL', 'wp-news-collector' ); ?></th>
					<td style="padding:4px 0">
						<input type="text" name="audios[<?php echo (int) $idx; ?>][catbox_url]"
							value="<?php echo esc_attr( (string) ( $audio['catbox_url'] ?? '' ) ); ?>"
							placeholder="https://files.catbox.moe/…"
							style="width:100%;font-family:monospace;font-size:.85em" />
					</td>
				</tr>
				<tr>
					<th style="padding:4px 0"><?php esc_html_e( 'Status', 'wp-news-collector' ); ?></th>
					<td style="padding:4px 0">
						<select name="audios[<?php echo (int) $idx; ?>][status]">
							<?php foreach ( $valid_audio_statuses as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( (string) ( $audio['status'] ?? 'pending' ), $s ); ?>>
									<?php echo esc_html( $s ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
		</div>
		<?php endforeach; ?>
	</div>
	<p>
		<button type="button" id="nc-add-audio" class="button">
			+ <?php esc_html_e( 'Add audio', 'wp-news-collector' ); ?>
		</button>
	</p>

	<!-- Article -->
	<?php if ( $article ) : ?>
	<h2 style="margin-top:1.5rem"><?php esc_html_e( 'Article', 'wp-news-collector' ); ?></h2>
	<div style="padding:12px;background:#fff;border:1px solid #ccd0d4;max-width:700px">
		<table class="form-table" style="margin:0">
			<tr>
				<th style="width:120px;padding:4px 0"><?php esc_html_e( 'Cover image', 'wp-news-collector' ); ?></th>
				<td style="padding:4px 0;display:flex;align-items:center;gap:8px">
					<?php $art_img = (string) ( $article['image_url'] ?? '' ); ?>
					<?php if ( '' !== $art_img ) : ?>
						<img src="<?php echo esc_url( $art_img ); ?>" style="width:48px;height:48px;object-fit:cover;border:1px solid #ccd0d4;flex-shrink:0" loading="lazy" />
					<?php endif; ?>
					<input type="text" name="article[image_url]" value="<?php echo esc_attr( $art_img ); ?>"
						placeholder="https://files.catbox.moe/…"
						style="flex:1;font-family:monospace;font-size:.85em" />
				</td>
			</tr>
			<tr>
				<th style="padding:4px 0"><?php esc_html_e( 'Title', 'wp-news-collector' ); ?></th>
				<td style="padding:4px 0"><input type="text" name="article[title]" value="<?php echo esc_attr( (string) ( $article['title'] ?? '' ) ); ?>" style="width:100%" /></td>
			</tr>
			<tr>
				<th style="padding:4px 0"><?php esc_html_e( 'Site name', 'wp-news-collector' ); ?></th>
				<td style="padding:4px 0"><input type="text" name="article[site_name]" value="<?php echo esc_attr( (string) ( $article['site_name'] ?? '' ) ); ?>" style="width:100%" /></td>
			</tr>
			<tr>
				<th style="padding:4px 0"><?php esc_html_e( 'URL', 'wp-news-collector' ); ?></th>
				<td style="padding:4px 0"><input type="url" name="article[url]" value="<?php echo esc_attr( (string) ( $article['url'] ?? '' ) ); ?>" style="width:100%;font-family:monospace;font-size:.85em" /></td>
			</tr>
			<tr>
				<th style="padding:4px 0"><?php esc_html_e( 'Text', 'wp-news-collector' ); ?></th>
				<td style="padding:4px 0"><textarea name="article[text]" rows="3" style="width:100%"><?php echo esc_textarea( (string) ( $article['text'] ?? '' ) ); ?></textarea></td>
			</tr>
		</table>
	</div>
	<?php endif; ?>

	<p style="margin-top:1.5rem">
		<?php submit_button( __( 'Save media', 'wp-news-collector' ), 'primary', 'submit', false ); ?>
	</p>
</form>

<?php if ( ! empty( $item['youtube_ids'] ) ) : ?>
<h2>YouTube</h2>
<ul>
	<?php foreach ( (array) $item['youtube_ids'] as $yt ) : ?>
		<li><a href="https://www.youtube.com/watch?v=<?php echo esc_attr( (string) $yt ); ?>" target="_blank" rel="noreferrer noopener"><?php echo esc_html( (string) $yt ); ?></a></li>
	<?php endforeach; ?>
</ul>
<?php endif; ?>

<details style="margin-top:24px">
	<summary><?php esc_html_e( 'Raw description', 'wp-news-collector' ); ?></summary>
	<pre style="max-width:900px;white-space:pre-wrap;background:#fff;padding:12px;border:1px solid #ccd0d4"><?php echo esc_html( (string) $item['raw_description'] ); ?></pre>
</details>

</div><!-- .wrap -->

<script>
(function () {
	var imgWrap   = document.getElementById('nc-images-wrap');
	var vidWrap   = document.getElementById('nc-videos-wrap');
	var audWrap   = document.getElementById('nc-audios-wrap');
	var vidCount  = <?php echo count( $videos ); ?>;
	var audCount  = <?php echo count( $audios ); ?>;
	var statuses  = <?php echo wp_json_encode( $valid_statuses ); ?>;
	var audioStatuses = <?php echo wp_json_encode( $valid_audio_statuses ); ?>;

	// Text lock: read-only until Edit; re-locking discards the change.
	var textBtn      = document.getElementById('nc-text-lock');
	var textReadonly = document.getElementById('nc-text-readonly');
	var textInput    = document.getElementById('nc-text-input');
	var textLabels   = <?php echo wp_json_encode( [ 'edit' => "\u{270E} " . __( 'Edit', 'wp-news-collector' ), 'lock' => "\u{2715} " . __( 'Lock', 'wp-news-collector' ) ] ); ?>;
	if (textBtn) {
		var textOriginal = textInput.value;
		var textUnlocked = false;
		textBtn.addEventListener('click', function () {
			textUnlocked = !textUnlocked;
			if (textUnlocked) {
				textReadonly.style.display = 'none';
				textInput.style.display = '';
				textInput.focus();
				textBtn.textContent = textLabels.lock;
			} else {
				textInput.value = textOriginal;
				textInput.style.display = 'none';
				textReadonly.style.display = '';
				textBtn.textContent = textLabels.edit;
			}
		});
	}

	// Remove any row/block
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.nc-remove-row');
		if (!btn) return;
		var row = btn.closest('.nc-media-row, .nc-video-block, .nc-audio-block');
		if (row) row.parentNode.removeChild(row);
	});

	// Add image row
	document.getElementById('nc-add-image').addEventListener('click', function () {
		var row = document.createElement('div');
		row.className = 'nc-media-row';
		row.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:6px';
		row.innerHTML =
			'<span style="width:48px;height:48px;display:inline-block;background:#f0f0f1;flex-shrink:0"></span>' +
			'<input type="text" name="images[]" placeholder="https://files.catbox.moe/…"' +
			'  style="flex:1;font-family:monospace;font-size:.85em" />' +
			'<button type="button" class="button nc-remove-row" title="Quitar">✕</button>';
		imgWrap.appendChild(row);
		row.querySelector('input').focus();
	});

	// Add video block
	document.getElementById('nc-add-video').addEventListener('click', function () {
		var idx = vidCount++;
		var opts = statuses.map(function (s) {
			return '<option value="' + s + '"' + (s === 'ok' ? ' selected' : '') + '>' + s + '</option>';
		}).join('');
		var block = document.createElement('div');
		block.className = 'nc-video-block';
		block.style.cssText = 'margin-bottom:1rem;padding:12px;background:#fff;border:1px solid #ccd0d4;max-width:700px';
		block.innerHTML =
			'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">' +
			'  <strong>Vídeo nuevo</strong>' +
			'  <button type="button" class="button nc-remove-row" title="Quitar vídeo">✕ Quitar</button>' +
			'</div>' +
			'<table class="form-table" style="margin:0">' +
			'  <tr><th style="width:120px;padding:4px 0">Catbox URL</th>' +
			'      <td style="padding:4px 0"><input type="text" name="videos[' + idx + '][catbox_url]"' +
			'        placeholder="https://files.catbox.moe/…" style="width:100%;font-family:monospace;font-size:.85em" /></td></tr>' +
			'  <tr><th style="padding:4px 0">Poster URL</th>' +
			'      <td style="padding:4px 0"><input type="text" name="videos[' + idx + '][poster_url]"' +
			'        placeholder="https://files.catbox.moe/…" style="width:100%;font-family:monospace;font-size:.85em" /></td></tr>' +
			'  <tr><th style="padding:4px 0">Estado</th>' +
			'      <td style="padding:4px 0"><select name="videos[' + idx + '][status]">' + opts + '</select></td></tr>' +
			'</table>';
		vidWrap.appendChild(block);
		block.querySelector('input').focus();
	});

	// Add audio block
	document.getElementById('nc-add-audio').addEventListener('click', function () {
		var idx = audCount++;
		var opts = audioStatuses.map(function (s) {
			return '<option value="' + s + '"' + (s === 'ok' ? ' selected' : '') + '>' + s + '</option>';
		}).join('');
		var block = document.createElement('div');
		block.className = 'nc-audio-block';
		block.style.cssText = 'margin-bottom:1rem;padding:12px;background:#fff;border:1px solid #ccd0d4;max-width:700px';
		block.innerHTML =
			'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">' +
			'  <strong>Audio nuevo</strong>' +
			'  <button type="button" class="button nc-remove-row" title="Quitar audio">✕ Quitar</button>' +
			'</div>' +
			'<table class="form-table" style="margin:0">' +
			'  <tr><th style="width:120px;padding:4px 0">Catbox URL</th>' +
			'      <td style="padding:4px 0"><input type="text" name="audios[' + idx + '][catbox_url]"' +
			'        placeholder="https://files.catbox.moe/…" style="width:100%;font-family:monospace;font-size:.85em" /></td></tr>' +
			'  <tr><th style="padding:4px 0">Estado</th>' +
			'      <td style="padding:4px 0"><select name="audios[' + idx + '][status]">' + opts + '</select></td></tr>' +
			'</table>';
		audWrap.appendChild(block);
		block.querySelector('input').focus();
	});
})();
</script>
