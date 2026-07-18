<?php
/**
 * Rewrite rule for canonical item permalinks (/{slug}/{id}).
 * The slug is configurable via Settings (default: "item").
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Rewrite {

	public const QUERY_VAR = 'nc_item';

	public static function slug(): string {
		$slug = trim( (string) ( NC_Plugin::get_settings()['item_slug'] ?? '' ) );
		return '' !== $slug ? sanitize_title( $slug ) : 'item';
	}

	public function register(): void {
		add_action( 'init', [ $this, 'add_rules' ] );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
	}

	public function add_rules(): void {
		// Optional trailing /{title-slug} is decorative and ignored: only the id matches.
		$regex = '^' . self::slug() . '/([0-9]+)(?:/[^/]+)?/?$';
		add_rewrite_rule( $regex, 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}
}
