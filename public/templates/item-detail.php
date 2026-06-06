<?php
/**
 * Full item content (no theme chrome). Shared by the single-item page,
 * the REST endpoint and the in-page modal.
 *
 * Inside the modal/detail, media cells are lightbox triggers (no data
 * attribute to disable). A single video is rendered "featured" (inline
 * with controls and autoplay), mirroring alerta-boe's featuredVideo flag.
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

$article    = is_array( $item['article'] ?? null ) ? $item['article'] : null;
$permalink  = NC_Plugin::item_permalink( (int) $item['id'] );
$media_list = NC_Template_Helpers::build_media_list( $item );
$too_big    = NC_Template_Helpers::extract_too_big_videos( $item );
$yt_cta     = NC_Template_Helpers::youtube_cta_url( $item );
$media_json = wp_json_encode( $media_list );
$count      = count( $media_list );
$date_label = NC_Template_Helpers::format_date_es( (string) $item['published_at'] );

// Featured-video shortcut: a single video item with a playable source.
$single_featured_video = ( 1 === $count && 'video' === $media_list[0]['kind'] );
?>
<article class="nc-item nc-item--detail" data-source="<?php echo esc_attr( (string) $item['source'] ); ?>">

	<header class="nc-item-header">
		<span class="nc-item-source"><?php echo esc_html( (string) $item['source_name'] ); ?></span>
		<div class="nc-item-header-right">
			<time class="nc-item-time" datetime="<?php echo esc_attr( (string) $item['published_at'] ); ?>"><?php echo esc_html( $date_label ); ?></time>
			<a class="nc-item-permalink" href="<?php echo esc_url( $permalink ); ?>" aria-label="<?php esc_attr_e( 'Permalink', 'wp-news-collector' ); ?>">
				<?php echo NC_Template_Helpers::permalink_svg(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</a>
		</div>
	</header>

	<?php if ( $single_featured_video ) : ?>
		<div class="nc-item-media nc-item-featured-video">
			<video class="nc-item-featured-video__el"
				src="<?php echo esc_url( (string) $media_list[0]['src'] ); ?>"
				<?php if ( ! empty( $media_list[0]['poster'] ) ) : ?>poster="<?php echo esc_url( (string) $media_list[0]['poster'] ); ?>"<?php endif; ?>
				controls autoplay playsinline preload="auto"></video>
		</div>
	<?php elseif ( 1 === $count && 'image' === $media_list[0]['kind'] ) :
		// Single image — no crop, lightbox-enabled.
		$m = $media_list[0];
		?>
		<div class="nc-item-media nc-item-img-wrap"
			data-nc-media="<?php echo esc_attr( (string) $media_json ); ?>">
			<button type="button" class="nc-item-img-btn"
				data-nc-media-index="0"
				aria-label="<?php esc_attr_e( 'Expand image', 'wp-news-collector' ); ?>">
				<img class="nc-item-img" src="<?php echo esc_url( (string) $m['src'] ); ?>" alt="" loading="lazy" />
				<?php if ( ! empty( $m['youtubeId'] ) ) : ?>
					<span class="nc-media-cell__play nc-media-cell__play--yt" aria-hidden="true"><?php echo NC_Template_Helpers::youtube_play_svg(); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<?php endif; ?>
			</button>
		</div>
	<?php elseif ( $count > 0 ) : ?>
		<div class="nc-item-media nc-item-media-grid nc-item-media-grid--<?php echo (int) min( 4, $count ); ?>"
			data-nc-media="<?php echo esc_attr( (string) $media_json ); ?>">
			<?php foreach ( $media_list as $i => $media ) :
				$is_video    = 'video' === $media['kind'];
				$is_yt_thumb = ! empty( $media['youtubeId'] );
				$thumb       = $is_video ? ( $media['poster'] ?? '' ) : $media['src'];
				?>
				<button type="button" class="nc-media-cell <?php echo $is_video ? 'nc-media-cell--video' : 'nc-media-cell--image'; ?>"
					data-nc-media-index="<?php echo (int) $i; ?>"
					aria-label="<?php echo $is_video ? esc_attr__( 'Watch video', 'wp-news-collector' ) : esc_attr__( 'Expand image', 'wp-news-collector' ); ?>">
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
				</button>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php foreach ( $too_big as $tb ) : ?>
		<a class="nc-item-tg-video" href="<?php echo esc_url( (string) $item['guid'] ); ?>" target="_blank" rel="noreferrer noopener">
			<div class="nc-tg-video-thumb">
				<img src="<?php echo esc_url( (string) $tb['poster'] ); ?>" alt="" loading="lazy" />
				<span class="nc-media-cell__play"><?php echo NC_Template_Helpers::play_svg(); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			</div>
		</a>
	<?php endforeach; ?>

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
