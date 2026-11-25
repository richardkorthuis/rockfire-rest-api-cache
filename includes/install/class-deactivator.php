<?php
/**
 * Fired during plugin deactivation
 *
 * @since 1.0.0
 *
 * @package    Rockfire_Rest_Api_Cache
 * @subpackage Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Fired during plugin deactivation.
 *
 * Flushing the entries and removing the must-use file are done by the plugin container, which
 * listens to the lifecycle actions fired here (see `Plugin::register_lifecycle_hooks()`).
 *
 * @package    Rockfire_Rest_Api_Cache
 * @subpackage Rockfire_Rest_Api_Cache
 */
class Deactivator {

	/**
	 * Deactivate the plugin (network-wide on multisite when requested).
	 *
	 * @internal Lifecycle hook: do_action( 'rfrac/deactivated', bool $network_wide ). Fires once per deactivation, after all sites.
	 *
	 * @param bool $network_wide Whether the plugin is deactivated network-wide.
	 *
	 * @return void
	 */
	public static function deactivate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			Sites::each( [ self::class, 'deactivate_site' ] );
		} else {
			self::deactivate_site();
		}

		do_action( 'rfrac/deactivated', $network_wide );
	}

	/**
	 * Deactivate the plugin for the current site: unschedule every plugin cron event.
	 *
	 * @internal Lifecycle hook: do_action( 'rfrac/deactivate_site', int $blog_id ). Fires once per deactivated site.
	 *
	 * @return void
	 */
	public static function deactivate_site(): void {
		Cleanup::unschedule_events();

		do_action( 'rfrac/deactivate_site', get_current_blog_id() );
	}
}
