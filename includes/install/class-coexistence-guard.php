<?php
/**
 * Coexistence guard: pauses caching while the original WP REST Cache plugin is active.
 *
 * @since 1.0.0
 *
 * @package    Rockfire_Rest_Api_Cache
 * @subpackage Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Detects the original WP REST Cache plugin and reports whether Rockfire must stay paused.
 *
 * Detection is safe in the must-use phase: it uses only `class_exists()` and already-loaded
 * options, never `is_plugin_active()` or admin includes, and never issues an extra DB query.
 * The other plugin is never deactivated automatically: the Import page offers to do it. Its settings
 * are imported only on request. While the guard does not block, its constants, hooks and classes are
 * honoured by the code under `includes/legacy/`.
 *
 * @package    Rockfire_Rest_Api_Cache
 * @subpackage Rockfire_Rest_Api_Cache
 */
final class Coexistence_Guard {

	/**
	 * Main file name of the original plugin.
	 *
	 * @var string
	 */
	const ORIGINAL_FILE = 'wp-rest-cache.php';

	/**
	 * Main class of the original plugin.
	 *
	 * @var string
	 */
	const ORIGINAL_CLASS = 'WP_Rest_Cache_Plugin\Includes\Plugin';

	/**
	 * Memoised result for the current request.
	 *
	 * @var bool|null
	 */
	private static $blocked = null;

	/**
	 * Whether caching must be paused because the original plugin is active.
	 *
	 * The result is memoised for the request; use {@see self::reset()} in tests.
	 *
	 * @return bool
	 */
	public static function is_blocked(): bool {
		if ( null === self::$blocked ) {
			self::$blocked = self::detect();
		}

		return self::$blocked;
	}

	/**
	 * Forget the memoised result (intended for tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$blocked = null;
	}

	/**
	 * Run the detection.
	 *
	 * @return bool
	 */
	private static function detect(): bool {
		if ( class_exists( self::ORIGINAL_CLASS, false ) ) {
			return true;
		}

		foreach ( (array) get_option( 'active_plugins', [] ) as $plugin ) {
			if ( is_string( $plugin ) && basename( $plugin ) === self::ORIGINAL_FILE ) {
				return true;
			}
		}

		if ( is_multisite() ) {
			foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) as $plugin ) {
				if ( basename( (string) $plugin ) === self::ORIGINAL_FILE ) {
					return true;
				}
			}
		}

		return false;
	}
}
