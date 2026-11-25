<?php
/**
 * Shim of the deprecated global endpoint API class of the original plugin.
 *
 * Reproduces the public interface (class name and method signatures) of WP REST Cache by Acato
 * (https://wordpress.org/plugins/wp-rest-cache/), GPLv3, so existing calls keep working. The implementation is new.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Legacy\Shim_Notice;
use WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api;

defined( 'ABSPATH' ) || exit;

/**
 * Proxy that old must-use files of the original call: it forwards every call to the endpoint API shim.
 *
 * The class is loaded by the shim loader only while the original plugin is absent. A call to a method the shim
 * does not have raises one developer notice and returns null; no call ever throws.
 *
 * @since 1.0.0
 */
class WP_Rest_Cache_Endpoint_Api {

	/**
	 * The endpoint API shim the calls are forwarded to.
	 *
	 * @var Endpoint_Api
	 */
	private $endpoint_api;

	/**
	 * Build the endpoint API shim.
	 */
	public function __construct() {
		$this->endpoint_api = new Endpoint_Api();
	}

	/**
	 * Forward a call to the public method of the endpoint API shim with that name.
	 *
	 * @param string                  $name      Name of the called function.
	 * @param array<int|string,mixed> $arguments Arguments supplied.
	 *
	 * @return mixed The result of the forwarded call, null when there is no such method or the arguments do not fit.
	 */
	public function __call( $name, $arguments ) {
		$callback = array( $this->endpoint_api, $name );

		if ( is_callable( $callback ) ) {
			try {
				return call_user_func_array( $callback, $arguments );
			} catch ( \Error $error ) {
				Shim_Notice::unsupported( __CLASS__ . '::' . $name, 'The arguments do not fit the method of the shim.' );

				return null;
			}
		}

		Shim_Notice::unsupported( __CLASS__ . '::' . $name, 'The shim has no such method.' );

		return null;
	}
}
