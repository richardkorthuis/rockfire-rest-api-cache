<?php
/**
 * Removal of scheduled plugin events.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Install;

use Rockfire\Rest_Api_Cache\Names;

defined( 'ABSPATH' ) || exit;

/**
 * Cleanup helpers for the current site.
 *
 * @since 1.0.0
 */
final class Cleanup {

	/**
	 * Unschedule every cron event whose hook starts with the plugin prefix.
	 *
	 * @return int Number of distinct hooks that were unscheduled.
	 */
	public static function unschedule_events(): int {
		$cron = _get_cron_array();
		if ( empty( $cron ) ) {
			return 0;
		}

		$hooks = [];
		foreach ( $cron as $events ) {
			foreach ( array_keys( $events ) as $hook ) {
				$hook = (string) $hook;
				if ( 0 === strpos( $hook, Names::PREFIX ) ) {
					$hooks[ $hook ] = true;
				}
			}
		}

		foreach ( array_keys( $hooks ) as $hook ) {
			wp_unschedule_hook( (string) $hook );
		}

		return count( $hooks );
	}
}
