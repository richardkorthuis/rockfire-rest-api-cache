<?php
/**
 * The admin screens a plugin notice may print on.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Predicates over the current admin screen for the notices that are not flash messages.
 *
 * Both predicates are false when there is no screen (before `current_screen`, or outside the admin).
 * Nothing is hooked here.
 *
 * @since 1.0.0
 */
final class Notice_Screen {

	/**
	 * Whether the current screen is the Plugins list of a site or of the network.
	 *
	 * @return bool
	 */
	public static function is_plugins_screen(): bool {
		$id = self::screen_id();

		return 'plugins' === $id || 'plugins-network' === $id;
	}

	/**
	 * Whether a notice of the plugin may print on the current screen: the Plugins list or a page of the plugin.
	 *
	 * Pages of the plugin are registered with `rfrac-*` slugs, so their screen ids contain `_page_rfrac-`
	 * (`toplevel_page_rfrac-caches`, `rest-api-cache_page_rfrac-tools`).
	 *
	 * @return bool
	 */
	public static function matches(): bool {
		return self::is_plugins_screen() || str_contains( self::screen_id(), '_page_rfrac-' );
	}

	/**
	 * The ID of the current screen, empty when there is none.
	 *
	 * @return string
	 */
	private static function screen_id(): string {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return '';
		}
		$screen = get_current_screen();

		return $screen instanceof \WP_Screen ? $screen->id : '';
	}
}
