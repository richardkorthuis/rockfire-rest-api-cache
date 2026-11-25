<?php
/**
 * The request URI as the legacy hooks know it.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Request\Request_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the `/wp-json/wp/v2/posts?per_page=5` form of a request that the legacy filters receive as the URI.
 *
 * The context holds the route and the sorted query parameters, so the result is identical for `/wp-json/...` and
 * `?rest_route=...` requests. The `_wpnonce` parameter is never part of it: a legacy callback must not see a nonce,
 * and a hit never verifies one. The class is pure: it reads nothing but its arguments.
 *
 * @since 1.0.0
 */
final class Request_Uri {

	/**
	 * The query parameters of a request as the legacy hooks know them: all of them but the nonce.
	 *
	 * @since 1.0.0
	 *
	 * @param Request_Context $context The request.
	 *
	 * @return array<int|string,string|array<int|string,mixed>>
	 */
	public static function params( Request_Context $context ): array {
		$params = $context->params;
		unset( $params['_wpnonce'] );
		return $params;
	}

	/**
	 * The URI of a request: a slash, the REST prefix, the route and the query string when there are parameters.
	 *
	 * The query string is RFC 3986 encoded, with `&` as the separator, and has no `_wpnonce`.
	 *
	 * @since 1.0.0
	 *
	 * @param Request_Context $context     The request.
	 * @param string          $rest_prefix The REST URL prefix, for example `wp-json`.
	 *
	 * @return string
	 */
	public static function of( Request_Context $context, string $rest_prefix ): string {
		$uri    = '/' . $rest_prefix . (string) $context->route;
		$params = self::params( $context );
		if ( array() === $params ) {
			return $uri;
		}

		$query = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		return '' === $query ? $uri : $uri . '?' . $query;
	}
}
