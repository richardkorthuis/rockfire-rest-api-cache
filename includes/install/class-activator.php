<?php
/**
 * Fired during plugin activation
 *
 * @since 1.0.0
 *
 * @package    Rockfire_Rest_Api_Cache
 * @subpackage Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Fired during plugin activation.
 *
 * The schema, the compiled config and the must-use file are set up by the plugin container,
 * which listens to the lifecycle actions fired here (see `Plugin::register_lifecycle_hooks()`).
 *
 * @package    Rockfire_Rest_Api_Cache
 * @subpackage Rockfire_Rest_Api_Cache
 */
class Activator {

	/**
	 * Activate the plugin (network-wide on multisite when requested).
	 *
	 * @internal Lifecycle hook: do_action( 'rfrac/activated', bool $network_wide ). Fires once per activation, after all sites.
	 *
	 * @param bool $network_wide Whether the plugin is activated network-wide.
	 *
	 * @return void
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			Sites::each( [ self::class, 'activate_site' ] );
		} else {
			self::activate_site();
		}

		do_action( 'rfrac/activated', $network_wide );
	}

	/**
	 * Activate the plugin for the current site.
	 *
	 * @internal Lifecycle hook: do_action( 'rfrac/activate_site', int $blog_id ). Fires once per activated site.
	 *
	 * @return void
	 */
	public static function activate_site(): void {
		do_action( 'rfrac/activate_site', get_current_blog_id() );
	}
}
