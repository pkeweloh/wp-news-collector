<?php
/**
 * Public feed template.
 *
 * @package wp-news-collector
 * @var array<int, array<string, mixed>> $items
 * @var bool $show_images
 * @var bool $show_videos
 * @var bool $has_next
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="nc-feed" aria-label="<?php esc_attr_e( 'News feed', 'wp-news-collector' ); ?>">
	<?php if ( empty( $items ) ) : ?>
		<article class="nc-feed-empty">
			<h2><?php esc_html_e( 'No news', 'wp-news-collector' ); ?></h2>
			<p><?php esc_html_e( 'No news yet. Check back soon.', 'wp-news-collector' ); ?></p>
		</article>
	<?php else : ?>
		<?php foreach ( $items as $item ) :
			include NC_Template_Loader::locate( 'item.php' );
		endforeach; ?>
		<?php if ( $has_next ) : ?>
		<div class="nc-feed-sentinel" aria-hidden="true"></div>
		<p class="nc-feed-loading" style="display:none"><?php esc_html_e( 'Loading…', 'wp-news-collector' ); ?></p>
		<button type="button" class="nc-feed-load-more"><?php esc_html_e( 'Load more', 'wp-news-collector' ); ?></button>
		<?php endif; ?>
	<?php endif; ?>
</section>

<div class="nc-feed-disclaimer">
	<p><?php esc_html_e( 'This section aggregates posts from third-party Telegram channels. Opinions expressed are the sole responsibility of their authors.', 'wp-news-collector' ); ?></p>
</div>
