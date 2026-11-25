<?php
/**
 * The capability that guards the admin of the plugin.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Admin;

use Rockfire\Rest_Api_Cache\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the capability required for every admin page, admin action and the admin-bar flush.
 *
 * The filter is applied on every call, so a test or a late filter always takes effect.
 *
 * @since 1.0.0
 */
class Capability {

	/**
	 * Default capability.
	 *
	 * @var string
	 */
	public const DEFAULT = 'manage_options';

	/**
	 * The required capability.
	 *
	 * @return string
	 */
	public function get(): string {
		/**
		 * Filters the capability required to use the admin pages, the admin actions, saving the settings
		 * and the admin-bar flush. Installing the must-use file additionally needs `activate_plugins`
		 * (`manage_network_plugins` on multisite).
		 *
		 * @since 1.0.0
		 *
		 * @see Hooks::SETTINGS_CAPABILITY
		 *
		 * @param mixed $capability Capability name. Default `manage_options`; anything but a non-empty string falls back to it.
		 */
		$capability = apply_filters( Hooks::SETTINGS_CAPABILITY, self::DEFAULT ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		return is_string( $capability ) && '' !== $capability ? $capability : self::DEFAULT;
	}

	/**
	 * Whether the current user has the required capability.
	 *
	 * @return bool
	 */
	public function current_user_can(): bool {
		return current_user_can( $this->get() );
	}

	/**
	 * End the request with a 403 unless the current user has the required capability.
	 *
	 * @return void
	 */
	public function require(): void {
		if ( ! $this->current_user_can() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'rockfire-rest-api-cache' ), 403 );
		}
	}
}
