<?php
/**
 * The redirect of the admin actions.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The only redirect of the plugin: a safe (same-site) 303 redirect that always ends the request.
 *
 * The static test tests/admin/test-admin-security-static.php enforces that no other file redirects.
 *
 * @since 1.0.0
 */
final class Redirect {

	/**
	 * Redirect to a same-site URL with a 303 and exit; a foreign or empty URL redirects to the admin dashboard.
	 *
	 * @param string $url Target URL.
	 *
	 * @return never
	 */
	public static function safe_and_exit( string $url ): never {
		$url = '' !== trim( $url ) ? $url : admin_url();
		wp_safe_redirect( $url, 303 );
		exit;
	}
}
