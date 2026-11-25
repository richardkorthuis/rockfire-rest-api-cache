<?php
/**
 * Cron scheduling of the maintenance run.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Maintenance;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules and unschedules the maintenance events of the current site.
 *
 * @since 1.0.0
 */
class Scheduler {

	/**
	 * Hourly maintenance hook.
	 *
	 * @var string
	 */
	public const HOOK = 'rfrac_maintenance';

	/**
	 * Single follow-up event after an incomplete run.
	 *
	 * @var string
	 */
	public const CONTINUE_HOOK = 'rfrac_maintenance_continue';

	/**
	 * Schedule the hourly event (first run in an hour) unless it is scheduled.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Make sure the hourly event exists.
	 *
	 * @return void
	 */
	public static function ensure_scheduled(): void {
		self::schedule();
	}

	/**
	 * Schedule the hourly event on a newly created network site when the plugin is network-active.
	 *
	 * Hooked to `wp_initialize_site` at priority 1000.
	 *
	 * @param \WP_Site $site The new site.
	 *
	 * @return void
	 */
	public static function on_initialize_site( \WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( plugin_basename( RFRAC_FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		try {
			self::schedule();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Clear both events.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
	}
}
