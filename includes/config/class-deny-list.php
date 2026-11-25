<?php
/**
 * Hard deny-list of REST routes that are never cached.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Routes that carry per-user or mutating state and can never be cached, whatever the settings say.
 *
 * Anchored, case-insensitive and segment-aware: `/wp/v2/users/meta` is allowed while
 * `/wp/v2/users/me` and `/wp/v2/users/me/` are denied.
 *
 * @since 1.0.0
 */
final class Deny_List {

	/**
	 * Denied route patterns.
	 *
	 * @var list<string>
	 */
	public const PATTERNS = [
		'@^/wc/store(/v\d+)?/(cart|checkout|order|batch|shopper-lists)(/|$)@i',
		'@^/wc/agentic(/|$)@i',
		'@^/wc/private(/|$)@i',
		'@^/wp/v2/users/me(/|$)@i',
		'@^/batch/v1(/|$)@i',
	];

	/**
	 * Concrete denied routes used to test whether a wildcard key could match a denied route.
	 *
	 * @var list<string>
	 */
	private const SAMPLES = [
		'/wc/store/cart',
		'/wc/store/checkout',
		'/wc/store/order',
		'/wc/store/batch',
		'/wc/store/v1/cart',
		'/wc/store/v1/cart/items',
		'/wc/store/v1/checkout',
		'/wc/store/v1/order',
		'/wc/store/v1/batch',
		'/wc/store/shopper-lists',
		'/wc/store/v1/shopper-lists',
		'/wc/store/v1/shopper-lists/x',
		'/wc/store/v1/shopper-lists/x/items',
		'/wc/store/v1/shopper-lists/x/items/y',
		'/wc/store/v1/cart/extensions',
		'/wc/store/v1/checkout/1',
		'/wc/agentic',
		'/wc/agentic/v1',
		'/wc/agentic/v1/checkout_sessions',
		'/wc/agentic/v1/checkout_sessions/x',
		'/wc/agentic/v1/checkout_sessions/x/complete',
		'/wc/private',
		'/wc/private/patterns',
		'/wp/v2/users/me',
		'/wp/v2/users/me/x',
		'/batch/v1',
		'/batch/v1/x',
	];

	/**
	 * Whether a concrete REST route is denied.
	 *
	 * @param string $route Route, e.g. `/wp/v2/users/me`.
	 *
	 * @return bool
	 */
	public static function is_denied( string $route ): bool {
		foreach ( self::PATTERNS as $pattern ) {
			if ( 1 === preg_match( $pattern, $route ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a wildcard route key is denied: the key itself or any denied route it could match.
	 *
	 * @param string $key Wildcard key, e.g. `/wc/store/v1/*`.
	 *
	 * @return bool
	 */
	public static function is_wildcard_denied( string $key ): bool {
		if ( self::is_denied( $key ) ) {
			return true;
		}
		$regex = self::wildcard_regex( $key );
		foreach ( self::SAMPLES as $sample ) {
			if ( 1 === preg_match( $regex, $sample ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build the request-time regex for a wildcard key.
	 *
	 * Each segment is escaped with `preg_quote()` and `*` becomes `[^/]+` (one or more characters
	 * within a single segment). User text is never compiled as regex in any other way.
	 *
	 * @param string $key Wildcard key.
	 *
	 * @return string Complete pattern with delimiters.
	 */
	public static function wildcard_regex( string $key ): string {
		$segments = [];
		foreach ( explode( '/', $key ) as $segment ) {
			$segments[] = str_replace( '\*', '[^/]+', preg_quote( $segment, '@' ) );
		}
		return '@^' . implode( '/', $segments ) . '$@i';
	}
}
