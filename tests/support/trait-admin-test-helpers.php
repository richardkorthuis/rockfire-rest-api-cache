<?php
/**
 * Helpers for admin tests: users, request input and redirects.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin test helpers. Use in a WP_UnitTestCase; call {@see self::reset_admin_request()} in tear_down().
 */
trait Rfrac_Admin_Test_Helpers {

	/**
	 * Log in as a new administrator (a super admin on multisite).
	 *
	 * @return int User ID.
	 */
	protected function as_admin(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Log in as a new subscriber.
	 *
	 * @return int User ID.
	 */
	protected function as_subscriber(): int {
		return $this->as_role( 'subscriber' );
	}

	/**
	 * Log in as a new user with a role.
	 *
	 * @param string $role Role.
	 *
	 * @return int User ID.
	 */
	protected function as_role( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Make every redirect throw {@see Redirect_Intercepted} (with the location and status) before a header is sent.
	 *
	 * @return void
	 */
	protected function intercept_redirect(): void {
		add_filter(
			'wp_redirect',
			static function ( $location, $status ) {
				throw new Redirect_Intercepted( (string) $location, (int) $status );
			},
			PHP_INT_MAX,
			2
		);
	}

	/**
	 * Set the request input; values are slashed like WordPress does. `$_REQUEST` is the merge of both.
	 *
	 * @param array<string,mixed> $get  GET parameters.
	 * @param array<string,mixed> $post POST parameters.
	 *
	 * @return void
	 */
	protected function set_request( array $get, array $post = array() ): void {
		$_GET     = wp_slash( $get );
		$_POST    = wp_slash( $post );
		$_REQUEST = array_merge( $_GET, $_POST );
		$_SERVER['REQUEST_METHOD'] = array() === $post ? 'GET' : 'POST';
	}

	/**
	 * Clear the request input and the referer.
	 *
	 * @return void
	 */
	protected function reset_admin_request(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['HTTP_REFERER'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}
}
