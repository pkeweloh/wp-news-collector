<?php
/**
 * Plugin Name: News Collector
 * Plugin URI:  https://github.com/pkeweloh/wp-news-collector
 * Description: Aggregates RSS/RSSHub feeds (Telegram channels), scrapes OG metadata, uploads media to Catbox, and displays items via shortcode.
 * Version:     1.0.17
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author:      Philipp Keweloh
 * License:     GPL-2.0-or-later
 * Text Domain: wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

define( 'NC_VERSION', '1.0.17' );
define( 'NC_PLUGIN_FILE', __FILE__ );
define( 'NC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Action Scheduler: bundle in vendor/ so it works without WooCommerce
if ( ! class_exists( 'ActionScheduler_Versions' ) ) {
    require_once NC_PLUGIN_DIR . 'vendor/action-scheduler/action-scheduler.php';
}

require_once NC_PLUGIN_DIR . 'includes/class-activator.php';
require_once NC_PLUGIN_DIR . 'includes/class-source-repository.php';
require_once NC_PLUGIN_DIR . 'includes/class-item-repository.php';
require_once NC_PLUGIN_DIR . 'includes/class-feed-parser.php';
require_once NC_PLUGIN_DIR . 'includes/class-og-scraper.php';
require_once NC_PLUGIN_DIR . 'includes/class-telegram-media.php';
require_once NC_PLUGIN_DIR . 'includes/class-catbox-uploader.php';
require_once NC_PLUGIN_DIR . 'includes/class-catbox-upload-repository.php';
require_once NC_PLUGIN_DIR . 'includes/class-source-cover-repository.php';
require_once NC_PLUGIN_DIR . 'includes/class-catbox-syncer.php';
require_once NC_PLUGIN_DIR . 'includes/class-redirect-resolver.php';
require_once NC_PLUGIN_DIR . 'includes/class-news-processor.php';
require_once NC_PLUGIN_DIR . 'includes/class-template-helpers.php';
require_once NC_PLUGIN_DIR . 'includes/class-template-loader.php';
require_once NC_PLUGIN_DIR . 'includes/class-widget.php';
require_once NC_PLUGIN_DIR . 'includes/class-rewrite.php';
require_once NC_PLUGIN_DIR . 'includes/class-rest.php';
require_once NC_PLUGIN_DIR . 'includes/class-detail-page.php';
require_once NC_PLUGIN_DIR . 'includes/class-source-page.php';
require_once NC_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, [ 'NC_Activator', 'install' ] );
register_uninstall_hook( __FILE__, [ 'NC_Activator', 'uninstall' ] );

$nc_plugin = new NC_Plugin();
add_action( 'plugins_loaded', [ $nc_plugin, 'load_textdomain' ] );
$nc_plugin->init();
