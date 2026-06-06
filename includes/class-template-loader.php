<?php
/**
 * Template loader — theme override support.
 *
 * Themes can override any public template by placing a file at:
 *   wp-content/themes/{theme}/wp-news-collector/{template}
 *
 * For example, to override the item card:
 *   wp-content/themes/mytheme/wp-news-collector/item.php
 *
 * Available templates:
 *   feed.php          — the feed container (loop + sentinel + disclaimer)
 *   item.php          — single item card rendered inside the feed
 *   item-detail.php   — full item content (modal + REST endpoint + standalone page)
 *   single-item.php   — full-page wrapper for /noticia/{id} (header/footer + item-detail)
 *
 * Child themes are checked before parent themes; plugin defaults are the final fallback.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Template_Loader {

	/**
	 * Return the resolved path for a public template.
	 *
	 * @param string $template  Filename relative to the templates directory (e.g. 'item.php').
	 * @return string           Absolute path to the template file to include.
	 */
	public static function locate( string $template ): string {
		$template = ltrim( $template, '/' );

		// 1. Child theme.
		$path = get_stylesheet_directory() . '/wp-news-collector/' . $template;
		if ( file_exists( $path ) ) {
			return $path;
		}

		// 2. Parent theme (relevant when a child theme is active).
		$path = get_template_directory() . '/wp-news-collector/' . $template;
		if ( file_exists( $path ) ) {
			return $path;
		}

		// 3. Plugin default.
		return NC_PLUGIN_DIR . 'public/templates/' . $template;
	}
}
