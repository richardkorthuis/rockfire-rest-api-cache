<?php
/**
 * The plugin bootstrap file.
 *
 * Based on WP REST Cache by Acato (https://wordpress.org/plugins/wp-rest-cache/), GPLv3.
 *
 * @since   1.0.0
 * @package Rockfire_Rest_Api_Cache
 *
 * @wordpress-plugin
 * Plugin Name:       Rockfire – REST API Cache
 * Description:       Fast, security-hardened caching for the WordPress REST API.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * WC requires at least: 11.2
 * WC tested up to:  11.3
 * Author:            Rockfire
 * Author URI:        https://profiles.wordpress.org/rockfire/
 * License:           GPLv3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       rockfire-rest-api-cache
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

// Guard against the file being loaded twice (e.g. by the must-use loader and by WordPress).
if ( defined( 'RFRAC_VERSION' ) ) {
	return;
}

define( 'RFRAC_VERSION', '1.0.0' );
define( 'RFRAC_FILE', __FILE__ );
define( 'RFRAC_DIR', __DIR__ . '/' );

require_once RFRAC_DIR . 'includes/class-autoloader.php';
\Rockfire\Rest_Api_Cache\Autoloader::register();

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/install/class-activator.php
 */
register_activation_hook( __FILE__, [ '\Rockfire\Rest_Api_Cache\Install\Activator', 'activate' ] );

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/install/class-deactivator.php
 */
register_deactivation_hook( __FILE__, [ '\Rockfire\Rest_Api_Cache\Install\Deactivator', 'deactivate' ] );

/**
 * Begins execution of the plugin.
 *
 * The booted container is available as \Rockfire\Rest_Api_Cache\Plugin::instance().
 */
\Rockfire\Rest_Api_Cache\Plugin::boot();
