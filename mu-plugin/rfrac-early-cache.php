<?php
/**
 * Early-cache loader of Rockfire – REST API Cache.
 *
 * Template: `Mu_Installer` copies this file to `wp-content/mu-plugins/`, replacing the version
 * and basename placeholders. It must not use any constant of the main plugin file: it runs
 * before regular plugins are loaded.
 *
 * @since   1.0.0
 * @package Rockfire_Rest_Api_Cache
 *
 * @wordpress-plugin
 * Plugin Name:       Rockfire – REST API Cache (early cache)
 * Description:       Serves cached REST API responses before other plugins load, so cache hits are fast. Installed by Rockfire – REST API Cache; deactivating that plugin removes this file.
 * Version:           {{RFRAC_PLUGIN_VERSION}}
 * RFRAC Template:    1
 * Author:            Rockfire
 * Author URI:        https://profiles.wordpress.org/rockfire/
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'RFRAC_EARLY_LOADED' ) ) {
	return;
}
define( 'RFRAC_EARLY_LOADED', 1 );

$rfrac_basename = '{{RFRAC_PLUGIN_BASENAME}}';

$rfrac_active = in_array( $rfrac_basename, (array) get_option( 'active_plugins', array() ), true )
	|| isset( ( (array) get_site_option( 'active_sitewide_plugins', array() ) )[ $rfrac_basename ] );
if ( ! $rfrac_active ) {
	return;
}

$rfrac_dir = WP_PLUGIN_DIR . '/' . dirname( $rfrac_basename );
if ( ! is_readable( $rfrac_dir . '/includes/class-autoloader.php' ) ) {
	return;
}

require_once $rfrac_dir . '/includes/class-autoloader.php';
\Rockfire\Rest_Api_Cache\Autoloader::register();

try {
	\Rockfire\Rest_Api_Cache\Serving\Early_Server::boot( 1 );
} catch ( \Throwable $rfrac_error ) {
	unset( $rfrac_error ); // Never break the site from the early phase.
}
