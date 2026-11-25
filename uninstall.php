<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

// If uninstall not called from WordPress, then exit.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-autoloader.php';

\Rockfire\Rest_Api_Cache\Autoloader::register();

\Rockfire\Rest_Api_Cache\Install\Uninstaller::run();
