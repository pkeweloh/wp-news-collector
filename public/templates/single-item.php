<?php
/**
 * Full-page template for /noticia/{id}. Uses the theme's header/footer.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

$item_id = (int) get_query_var( NC_Rewrite::QUERY_VAR );
$items   = new NC_Item_Repository();
$item    = $items->get_by_id( $item_id );

// Make sure the public assets load even outside the shortcode flow.
wp_enqueue_style( 'nc-public', NC_PLUGIN_URL . 'assets/css/public.css', [], NC_VERSION );
wp_enqueue_script( 'nc-public', NC_PLUGIN_URL . 'assets/js/public.js', [], NC_VERSION, true );
wp_localize_script(
	'nc-public',
	'NC_DATA',
	[
		'restUrl'  => esc_url_raw( rest_url( 'nc/v1/item/' ) ),
		'slug'     => NC_Rewrite::slug(),
		'queryVar' => NC_Rewrite::QUERY_VAR,
		'pretty'   => (bool) get_option( 'permalink_structure' ),
		'home'     => esc_url_raw( home_url( '/' ) ),
	]
);

get_header();
?>
<main class="nc-detail-page">
	<div class="nc-detail-page__inner">
		<?php if ( null === $item ) : ?>
			<p><?php esc_html_e( 'Item not found.', 'wp-news-collector' ); ?></p>
		<?php else : ?>
			<?php include NC_Template_Loader::locate( 'item-detail.php' ); ?>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
