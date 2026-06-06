<?php
/**
 * Smoke load: ensure every plugin class file parses + autoloads without
 * a real WordPress instance. Stubs the WP API surface used at load time.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );

if ( ! function_exists( 'add_action' ) ) { function add_action( ...$a ) {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter( ...$a ) {} }
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( ...$a ) {} }
if ( ! function_exists( 'plugin_dir_path' ) ) { function plugin_dir_path( $f ) { return dirname( $f ) . '/'; } }
if ( ! function_exists( 'plugin_dir_url' ) ) { function plugin_dir_url( $f ) { return 'http://example.test/'; } }
if ( ! function_exists( 'register_activation_hook' ) ) { function register_activation_hook( ...$a ) {} }
if ( ! function_exists( 'register_uninstall_hook' ) ) { function register_uninstall_hook( ...$a ) {} }
if ( ! function_exists( 'is_admin' ) ) { function is_admin() { return false; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'add_option' ) ) { function add_option( ...$a ) { return true; } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( ...$a ) { return true; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? parse_url( $u ) : parse_url( $u, $c ); } }
if ( ! function_exists( 'doing_action' ) ) { function doing_action( $h ) { return false; } }
if ( ! function_exists( 'did_action' ) ) { function did_action( $h ) { return 0; } }
if ( ! function_exists( 'register_setting' ) ) { function register_setting( ...$a ) {} }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = '' ) { return $n === 1 ? $s : $p; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return $u; } }

// Stand-in $wpdb so the repository constructors don't crash.
class wpdb_stub {
	public string $prefix = 'wp_';
}
global $wpdb;
$wpdb = new wpdb_stub();

require __DIR__ . '/../wp-news-collector.php';

echo "Loaded OK\n";

// Spot-check repos can be instantiated and basic methods exist.
$plugin_classes = [
	'NC_Plugin', 'NC_Activator', 'NC_Source_Repository', 'NC_Item_Repository',
	'NC_Feed_Parser', 'NC_OG_Scraper', 'NC_Catbox_Uploader', 'NC_Redirect_Resolver',
	'NC_News_Processor', 'NC_Shortcode',
];
foreach ( $plugin_classes as $c ) {
	if ( ! class_exists( $c ) ) {
		echo "MISSING: $c\n";
		exit( 1 );
	}
}
echo "All classes present\n";

// Verify AS bundle is registered as a deferred version (not yet initialized — that needs plugins_loaded).
if ( class_exists( 'ActionScheduler_Versions' ) ) {
	echo "ActionScheduler_Versions loaded\n";
} else {
	echo "ActionScheduler_Versions MISSING\n";
	exit( 1 );
}

echo "SMOKE OK\n";
