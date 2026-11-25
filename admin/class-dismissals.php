<?php
/**
 * Per-user dismissals of the persistent admin notices.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Which persistent notices the current user has dismissed, and for which state of the notice.
 *
 * Stored per site in the user meta {@see self::meta_key()} as a map of notice ID to an integer token, so a
 * dismissal on one site never hides the notice on another. A dismissal holds only for the exact token it was
 * made with: a notice whose state changes passes a new token and shows again. Anything in the meta that is
 * not such a map reads as not dismissed and is overwritten by the next dismissal. Nothing is stored without a
 * user, and nothing is hooked here.
 *
 * @since 1.0.0
 */
final class Dismissals {

	/**
	 * Base of the per-site user meta key of the dismissals.
	 *
	 * @var string
	 */
	public const META = 'rfrac_dismissed_notices';

	/**
	 * Notice ID: another REST cache plugin is active on the site.
	 *
	 * @var string
	 */
	public const COEXISTENCE = 'coexistence';

	/**
	 * Notice ID: another REST cache plugin is network-active.
	 *
	 * @var string
	 */
	public const COEXISTENCE_NETWORK = 'coexistence_network';

	/**
	 * Notice ID: the summary of a legacy import.
	 *
	 * @var string
	 */
	public const IMPORT_SUMMARY = 'import_summary';

	/**
	 * User meta key of the dismissals on the current site.
	 *
	 * Prefixed with the blog prefix at call time, so it follows `switch_to_blog()`.
	 *
	 * @return string
	 */
	public static function meta_key(): string {
		global $wpdb;

		// Always a wpdb once WordPress has loaded; the check only narrows the global for static analysis.
		$prefix = $wpdb instanceof \wpdb ? $wpdb->get_blog_prefix() : '';

		return $prefix . self::META;
	}

	/**
	 * Dismiss a notice for the current user on the current site.
	 *
	 * Does nothing without a user.
	 *
	 * @param string $id    Notice ID.
	 * @param int    $token State of the notice the dismissal holds for.
	 *
	 * @return void
	 */
	public static function dismiss( string $id, int $token = 1 ): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		$dismissed        = self::read( $user_id );
		$dismissed[ $id ] = $token;
		update_user_meta( $user_id, self::meta_key(), $dismissed );
	}

	/**
	 * Whether the current user has dismissed a notice, for exactly this token, on the current site.
	 *
	 * @param string $id    Notice ID.
	 * @param int    $token State of the notice.
	 *
	 * @return bool
	 */
	public static function is_dismissed( string $id, int $token = 1 ): bool {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		$dismissed = self::read( $user_id );

		return isset( $dismissed[ $id ] ) && $dismissed[ $id ] === $token;
	}

	/**
	 * The stored dismissals of a user on the current site.
	 *
	 * @param int $user_id User.
	 *
	 * @return array<string,int> Notice ID => token; empty when the meta is absent or not a map of integers.
	 */
	private static function read( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::meta_key(), true );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$dismissed = array();
		foreach ( $stored as $id => $token ) {
			if ( is_int( $token ) ) {
				$dismissed[ (string) $id ] = $token;
			}
		}

		return $dismissed;
	}
}
