<?php
/**
 * Full-page landing for /{source_slug}/{source}. Uses the theme's header/footer
 * and server-renders the channel feed via the [news_feed] shortcode.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

// Hard-sanitize: the handle is interpolated into a shortcode, so drop anything
// but a plain handle before do_shortcode().
$nc_handle = (string) preg_replace( '/[^a-z0-9_-]/i', '', (string) get_query_var( NC_Source_Page::QUERY_VAR ) );
$nc_name   = NC_Source_Page::display_name( $nc_handle );

get_header();
?>
<main class="nc-detail-page nc-source-page">
	<div class="nc-detail-page__inner">
		<?php if ( null === $nc_name ) : ?>
			<p><?php esc_html_e( 'Source not found.', 'wp-news-collector' ); ?></p>
		<?php else : ?>
			<h1 class="nc-source-page__title"><?php echo esc_html( $nc_name ); ?></h1>
			<?php echo do_shortcode( '[news_feed source="' . esc_attr( $nc_handle ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
