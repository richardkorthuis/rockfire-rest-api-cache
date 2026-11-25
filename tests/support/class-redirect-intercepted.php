<?php
/**
 * Exception thrown instead of a redirect in admin tests.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by the `wp_redirect` filter of {@see Rfrac_Admin_Test_Helpers::intercept_redirect()}, before any header is sent.
 */
final class Redirect_Intercepted extends RuntimeException {

	/**
	 * Redirect location.
	 *
	 * @var string
	 */
	public string $location;

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	public int $status;

	/**
	 * Constructor.
	 *
	 * @param string $location Redirect location.
	 * @param int    $status   HTTP status.
	 */
	public function __construct( string $location, int $status ) {
		parent::__construct( sprintf( 'Redirect (%d) to %s', $status, $location ) );
		$this->location = $location;
		$this->status   = $status;
	}
}
