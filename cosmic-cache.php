<?php
/**
 * Plugin Name:       Cosmic Cache
 * Description:       Performance-focused WordPress caching: a pre-boot page cache, opt-in object cache, asset optimization and CDN rewrite — pure PHP, zero runtime dependencies.
 * Version:           1.1.3
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Cosmic Cache
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cosmic-cache
 * Domain Path:       /languages
 *
 * @package cosmic-cache
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'COSMIC_CACHE_VERSION' ) ) {
	define( 'COSMIC_CACHE_VERSION', '1.1.3' );
}

require_once __DIR__ . '/src/Autoloader.php';

\CosmicCache\Autoloader::register( __DIR__ . '/src' );

if ( ! class_exists( '\\CosmicCache\\Plugin' ) ) {
	require_once __DIR__ . '/src/Plugin.php';
}

/**
 * Boot the plugin.
 *
 * @return \CosmicCache\Plugin
 */
function cosmic_cache_plugin() {
	return \CosmicCache\Plugin::instance();
}

// Activation / deactivation.
register_activation_hook( __FILE__, array( cosmic_cache_plugin(), 'activate' ) );
register_deactivation_hook( __FILE__, array( cosmic_cache_plugin(), 'deactivate' ) );

// Wire up hooks.
add_action( 'plugins_loaded', array( cosmic_cache_plugin(), 'boot' ), 1 );
