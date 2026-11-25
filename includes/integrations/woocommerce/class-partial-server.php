<?php
/**
 * WooCommerce answer to the partial REST server filter.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the engine when WooCommerce left some of its REST namespaces unregistered for the request.
 *
 * On a request to one of its own namespaces WooCommerce registers only that namespace, so the live REST
 * server lacks the routes of the others, the Store API catalog among them. Answering the engine's
 * {@see \Rockfire\Rest_Api_Cache\Hooks::PARTIAL_SERVER} filter with `true` keeps the engine from rebuilding the
 * route snapshot from such a server; the next request outside those namespaces rebuilds it with every route.
 *
 * On the front end the decision is WooCommerce's own: it is asked through `wc_rest_should_load_namespace()` (which
 * honours its `wc_rest_should_load_namespace` filter) for every known namespace, never reimplemented here.
 *
 * In wp-admin the answer is `true` as well while the live REST server carries no Store API namespace
 * ({@see self::STORE_API_NAMESPACE}): a foreign admin REST server (block-editor preload, another plugin) or a
 * discovery server whose Store API init was skipped or failed must never recompile the snapshot without the catalog
 * routes, so only the plugin's own discovery path (`rfrac/discovery_server`) compiles in admin.
 * It reads only the public `WP_REST_Server::get_namespaces()`.
 *
 * @since 1.0.0
 */
final class Partial_Server {

	/**
	 * The namespace of the public Store API, the one a REST server must carry for the catalog routes to be compiled in.
	 *
	 * @var string
	 */
	public const STORE_API_NAMESPACE = 'wc/store/v1';

	/**
	 * The namespaces WooCommerce loads lazily; the list `wc_rest_should_load_namespace()` calls its known namespaces.
	 *
	 * @var list<string>
	 */
	public const KNOWN_NAMESPACES = [
		'wc/v1',
		'wc/v2',
		'wc/v3',
		'wc/v4',
		'wc-telemetry',
		'wc-admin',
		'wc-analytics',
		'wc/store',
		'wc/private',
	];

	/**
	 * Filter callback: whether this request's REST server is partial.
	 *
	 * True when an earlier callback already answered strictly `true`, when the request is in wp-admin and the REST
	 * server lacks the Store API namespace, or when WooCommerce skipped at least one of its known
	 * namespaces for the route.
	 *
	 * @param mixed $partial Whether the server is partial so far.
	 * @param mixed $server  The REST server of the request; in wp-admin it must carry the Store API namespace.
	 * @param mixed $route   Request route with a leading slash, `''` when unknown (WooCommerce then reads the
	 *                       route of the current request itself).
	 *
	 * @return bool
	 */
	public static function filter( mixed $partial, mixed $server, mixed $route ): bool {
		if ( true === $partial ) {
			return true;
		}

		// Read when the filter runs, never cached at registration: the admin context is known only by then.
		if ( is_admin() && $server instanceof \WP_REST_Server && ! in_array( self::STORE_API_NAMESPACE, $server->get_namespaces(), true ) ) {
			return true;
		}

		$route = is_scalar( $route ) ? (string) $route : '';
		foreach ( self::KNOWN_NAMESPACES as $namespace ) {
			if ( ! self::should_load( $namespace, $route ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether WooCommerce loads a namespace for a route.
	 *
	 * A method of its own on purpose: with the call to the WooCommerce stub inside the loop of {@see self::filter()},
	 * PHPStan runs over its 1G memory limit while it analyses this file.
	 *
	 * @param string $ns    A known namespace.
	 * @param string $route Request route, `''` when unknown.
	 *
	 * @return bool
	 */
	private static function should_load( string $ns, string $route ): bool {
		return wc_rest_should_load_namespace( $ns, $route );
	}
}
