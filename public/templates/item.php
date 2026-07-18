<?php
/**
 * Single item template (rendered inside feed.php).
 *
 * @package wp-news-collector
 * @var array<string, mixed> $item
 * @var bool $show_images
 * @var bool $show_videos
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

$article    = is_array( $item['article'] ?? null ) ? $item['article'] : null;
$permalink  = NC_Plugin::item_permalink( (int) $item['id'] );
$media_list = $show_images || $show_videos ? NC_Template_Helpers::build_media_list( $item ) : [];
$too_big    = $show_videos ? NC_Template_Helpers::extract_too_big_videos( $item ) : [];
$audios     = $show_videos ? NC_Template_Helpers::build_audio_list( $item ) : [];
$yt_cta     = NC_Template_Helpers::youtube_cta_url( $item );
$media_json = wp_json_encode( $media_list );
$count      = count( $media_list );
$date_label = NC_Template_Helpers::format_date_es( (string) $item['published_at'] );
?>
<article class="nc-item nc-item--clickable"
	data-source="<?php echo esc_attr( (string) $item['source'] ); ?>"
	data-nc-item-id="<?php echo (int) $item['id']; ?>"
	data-nc-permalink="<?php echo esc_url( $permalink ); ?>"
	id="nc-item-<?php echo (int) $item['id']; ?>">

	<header class="nc-item-header">
		<span class="nc-item-source"><?php echo esc_html( (string) $item['source_name'] ); ?></span>
		<div class="nc-item-header-right">
			<time class="nc-item-time" datetime="<?php echo esc_attr( (string) $item['published_at'] ); ?>"><?php echo esc_html( $date_label ); ?></time>
			<a class="nc-item-permalink" href="<?php echo esc_url( $permalink ); ?>" aria-label="<?php esc_attr_e( 'Permalink', 'wp-news-collector' ); ?>">
				<?php echo NC_Template_Helpers::permalink_svg(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</a>
		</div>
	</header>

	<?php if ( $count > 0 ) : ?>
		<?php if ( 1 === $count && 'image' === $media_list[0]['kind'] ) :
			// Single image: no crop, natural dimensions.
			$m = $media_list[0];
			?>
			<div class="nc-item-media nc-item-img-wrap" data-nc-disable-lightbox="1">
				<img class="nc-item-img" src="<?php echo esc_url( (string) $m['src'] ); ?>" alt="" loading="lazy" />
				<?php if ( ! empty( $m['youtubeId'] ) ) : ?>
					<span class="nc-media-cell__play nc-media-cell__play--yt" aria-hidden="true"><?php echo NC_Template_Helpers::youtube_play_svg(); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<?php endif; ?>
			</div>
		<?php elseif ( 1 === $count && 'video' === $media_list[0]['kind'] ) :
			// Single video: inline muted autoplay via IntersectionObserver (mirrors alerta-boe VideoPlayer).
			$m = $media_list[0];
			?>
			<div class="nc-item-media nc-item-video-inline" data-nc-disable-lightbox="1">
				<video class="nc-item-video-inline__el"
					src="<?php echo esc_url( (string) $m['src'] ); ?>"
					<?php if ( ! empty( $m['poster'] ) ) : ?>poster="<?php echo esc_url( (string) $m['poster'] ); ?>"<?php endif; ?>
					muted loop playsinline preload="none"
					data-nc-inline-video="1"></video>
				<span class="nc-item-video-inline__mute" aria-label="<?php esc_attr_e( 'Muted', 'wp-news-collector' ); ?>">
					<?php echo NC_Template_Helpers::muted_svg(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</span>
			</div>
		<?php else :
			// Multi-image grid or single video thumbnail.
			// In the list, cells are not lightbox triggers: the card click opens the modal.
			?>
			<div class="nc-item-media nc-item-media-grid nc-item-media-grid--<?php echo (int) min( 4, $count ); ?>"
				data-nc-media="<?php echo esc_attr( (string) $media_json ); ?>"
				data-nc-disable-lightbox="1">
				<?php foreach ( array_slice( $media_list, 0, 4 ) as $i => $media ) :
					$is_video    = 'video' === $media['kind'];
					$is_yt_thumb = ! empty( $media['youtubeId'] );
					$thumb       = $is_video ? ( $media['poster'] ?? '' ) : $media['src'];
					?>
					<div class="nc-media-cell <?php echo $is_video ? 'nc-media-cell--video' : 'nc-media-cell--image'; ?>">
						<?php if ( '' !== $thumb ) : ?>
							<img class="nc-media-cell__thumb" src="<?php echo esc_url( (string) $thumb ); ?>" alt="" loading="lazy" />
						<?php else : ?>
							<span class="nc-media-cell__dark"></span>
						<?php endif; ?>
						<?php if ( $is_video ) : ?>
							<span class="nc-media-cell__play" aria-hidden="true"><?php echo NC_Template_Helpers::play_svg(); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<?php elseif ( $is_yt_thumb ) : ?>
							<span class="nc-media-cell__play nc-media-cell__play--yt" aria-hidden="true"><?php echo NC_Template_Helpers::youtube_play_svg(); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<?php endif; ?>
						<?php if ( $i === 3 && $count > 4 ) : ?>
							<span class="nc-media-cell__overflow">+<?php echo (int) ( $count - 4 ); ?></span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php foreach ( $too_big as $tb ) : ?>
		<a class="nc-item-tg-video" href="<?php echo esc_url( (string) $item['guid'] ); ?>" target="_blank" rel="noreferrer noopener">
			<div class="nc-tg-video-thumb">
				<img src="<?php echo esc_url( (string) $tb['poster'] ); ?>" alt="" loading="lazy" />
				<span class="nc-media-cell__play"><?php echo NC_Template_Helpers::play_svg(); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			</div>
		</a>
	<?php endforeach; ?>

	<?php if ( ! empty( $audios ) ) : ?>
		<div class="nc-item-audios">
			<?php foreach ( $audios as $audio ) : ?>
				<audio class="nc-item-audio" controls preload="none" src="<?php echo esc_url( (string) $audio['src'] ); ?>"></audio>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="nc-item-body">
		<?php if ( ! empty( $item['text'] ) ) : ?>
			<div class="nc-item-text"><?php echo wp_kses( (string) $item['text'], $allowed ); ?></div>
		<?php endif; ?>

		<?php if ( $article && ! empty( $article['url'] ) ) :
			$src_label = NC_Template_Helpers::article_source_label( $article );
			?>
			<a class="nc-item-article" href="<?php echo esc_url( (string) $article['url'] ); ?>" target="_blank" rel="noreferrer noopener">
				<div class="nc-item-article__body">
					<?php if ( '' !== $src_label ) : ?>
						<span class="nc-item-article__source"><?php echo esc_html( $src_label ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $article['title'] ) ) : ?>
						<span class="nc-item-article__title"><?php echo esc_html( (string) $article['title'] ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $article['text'] ) ) : ?>
						<span class="nc-item-article__excerpt"><?php echo esc_html( (string) $article['text'] ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( ! empty( $article['image_url'] ) ) : ?>
					<img class="nc-item-article__image" src="<?php echo esc_url( (string) $article['image_url'] ); ?>" alt="" loading="lazy" />
				<?php endif; ?>
			</a>
		<?php endif; ?>

		<?php if ( '' !== $yt_cta ) : ?>
			<a class="nc-item-yt-cta" href="<?php echo esc_url( $yt_cta ); ?>" target="_blank" rel="noreferrer noopener">
				<span class="nc-item-yt-cta__icon" aria-hidden="true">▶</span>
				<?php esc_html_e( 'Watch on YouTube', 'wp-news-collector' ); ?>
			</a>
		<?php endif; ?>
	</div>
</article>
