<?php
/**
 * Shim of the endpoint API class of the original plugin.
 *
 * Reproduces the public interface (class name and method signatures) of WP REST Cache by Acato
 * (https://wordpress.org/plugins/wp-rest-cache/), GPLv3, so existing calls keep working. The implementation is new.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace WP_Rest_Cache_Plugin\Includes\API;

use Rockfire\Rest_Api_Cache\Legacy\Shim_Notice;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps site code and leftover must-use files that call the original's endpoint API from failing fatally.
 *
 * The class is loaded by the shim loader only while the original plugin is absent. Every method has the signature
 * the original had (without native types, except where the original type-hinted a class or array), raises one
 * developer notice and returns what leaves the caller's data untouched: no response is served, stored or
 * tagged, no header is sent, and the request is never ended. Each call writes only the legacy findings record,
 * as a finding shown on the Import page and in Site Health; nothing else is read from or written to the options.
 * Rockfire serves, stores and invalidates responses itself.
 *
 * @since 1.0.0
 */
class Endpoint_Api {

	/**
	 * Serve a cached response (the original did this on the first REST request of a page load).
	 *
	 * Does nothing: no output, no headers and no exit.
	 *
	 * @return void
	 */
	public function get_api_cache() {
		Shim_Notice::unsupported( __METHOD__, 'The shim does not serve cached responses; the plugin serves them itself.' );
	}

	/**
	 * Whether caching of the current request should be skipped.
	 *
	 * @return bool Always true: this class never caches.
	 */
	public function skip_caching() {
		Shim_Notice::unsupported( __METHOD__, 'The shim does not cache responses; the plugin decides that per route.' );

		return true;
	}

	/**
	 * Cache the response data.
	 *
	 * @param mixed $result Response data to send to the client.
	 *
	 * @return mixed The response data, unchanged.
	 */
	public function save_cache( $result ) {
		Shim_Notice::unsupported( __METHOD__, 'The shim does not store responses; the plugin stores them itself.' );

		return $result;
	}

	/**
	 * Save the response headers so they can be added to the cache.
	 *
	 * @param mixed             $served Whether the request has already been served.
	 * @param \WP_HTTP_Response $result Result to send to the client.
	 *
	 * @return mixed The served flag, unchanged.
	 */
	public function save_cache_headers( $served, \WP_HTTP_Response $result ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature of the original.
		Shim_Notice::unsupported( __METHOD__, 'The shim does not store response headers; the plugin stores them itself.' );

		return $served;
	}

	/**
	 * Re-save the options the original kept for its early serving.
	 *
	 * Does nothing but record the call: the only option written is the legacy findings record, as a finding
	 * shown on the Import page and in Site Health.
	 *
	 * @return void
	 */
	public function save_options() {
		Shim_Notice::unsupported( __METHOD__, 'The shim keeps no options; the plugin compiles its own configuration.' );
	}

	/**
	 * Add the default WordPress endpoints to the allowed endpoints for caching.
	 *
	 * @param array<mixed> $allowed_endpoints The endpoints that are allowed to be cached.
	 *
	 * @return array<mixed> The endpoints, unchanged.
	 */
	public function add_wordpress_endpoints( array $allowed_endpoints ) {
		Shim_Notice::unsupported( __METHOD__, 'The shim does not change the cached routes; the plugin manages them in its route settings.' );

		return $allowed_endpoints;
	}

	/**
	 * Determine the object type for caches of WordPress endpoints.
	 *
	 * @param mixed $object_type The automatically determined object type.
	 * @param mixed $cache_key   The cache key.
	 * @param mixed $data        The cached data.
	 * @param mixed $uri         The requested URI.
	 *
	 * @return mixed The object type, unchanged.
	 */
	public function determine_object_type( $object_type, $cache_key, $data, $uri ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature of the original.
		Shim_Notice::unsupported( __METHOD__, 'The shim does not determine object types; the plugin tags responses itself.' );

		return $object_type;
	}
}
