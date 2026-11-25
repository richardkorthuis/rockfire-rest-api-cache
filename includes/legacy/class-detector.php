<?php
/**
 * Detector of the original plugin and of what it left behind.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Tells whether the original WP REST Cache plugin is active or has left data behind, so that the
 * legacy layer only does its work on a site that was switched from it.
 *
 * @since 1.0.0
 */
final class Detector {

	/**
	 * Option the original plugin keeps its installed database version in.
	 *
	 * @var string
	 */
	private const ORIGINAL_DB_VERSION_OPTION = 'wp_rest_cache_database_version';

	/**
	 * Whether the original plugin is active right now.
	 *
	 * @return bool
	 */
	public static function original_active(): bool {
		return Coexistence_Guard::is_blocked();
	}

	/**
	 * Whether the original plugin left its database version option behind.
	 *
	 * @return bool
	 */
	public static function has_leftovers(): bool {
		return false !== get_option( self::ORIGINAL_DB_VERSION_OPTION );
	}

	/**
	 * Whether anything of the original plugin is present: the plugin itself, its leftovers or a constant of it.
	 *
	 * @return bool
	 */
	public static function present(): bool {
		return self::original_active() || self::has_leftovers() || [] !== Original_Settings::defined_constants();
	}
}
