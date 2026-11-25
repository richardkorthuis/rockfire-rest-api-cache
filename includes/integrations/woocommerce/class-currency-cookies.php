<?php
/**
 * The currency cookies that key the price-bearing WooCommerce routes.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Integrations\WooCommerce;

use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the engine's `rfrac/route_vary_cookies` seam with the currency-cookie declaration of the site.
 *
 * A currency plugin declares its cookie names and allowed values once, through `rfrac/woocommerce/currency_cookies`
 * ({@see Hooks::WC_CURRENCY_COOKIES}); no adapter for a named plugin is needed. The declaration applies to the
 * price-bearing Store API routes ({@see Store_Routes::is_price_bearing()}) and to no other route, so the catalog
 * routes without prices stay shared by all visitors. It is read while the configuration is compiled, and read again,
 * as a veto, each time {@see \Rockfire\Rest_Api_Cache\Serving\Capture} is about to store a response of such a route:
 * the engine keys the routes by the compiled declaration, refuses a cookie value outside the allow-list, and stores
 * nothing once the declaration differs from the compiled one. The filter therefore runs on the storing request as
 * well, so its callbacks must stay cheap and free of side effects.
 *
 * @since 1.0.0
 */
final class Currency_Cookies {

	use Hook_Subscriber_Trait;

	/**
	 * The hooks: the engine's vary-cookie seam, `rfrac/route_vary_cookies` (10, 2).
	 *
	 * @return array<int,array<int,mixed>>
	 *
	 * @phpstan-return list<array{0:string,1:'route_cookies',2:int,3:int}>
	 */
	public static function hooks(): array {
		return array(
			array( Hooks::ROUTE_VARY_COOKIES, 'route_cookies', 10, 2 ),
		);
	}

	/**
	 * `rfrac/route_vary_cookies`: add the currency-cookie declaration to the cookies of a price-bearing route.
	 *
	 * Returns `$cookies` untouched for every other route and when the site declares nothing. A declaration that is
	 * not an array is returned as it is, as is a `$cookies` that is not an array, so the engine rejects it and
	 * the route is not cached. A filter that throws is recorded with {@see Failure_Recorder} and answers `null`,
	 * which the engine rejects too. Otherwise the declaration is merged into `$cookies` per cookie name, with the
	 * union of the value lists of a name both of them declare.
	 *
	 * @param mixed $cookies   Cookie name => allowed values, as declared so far.
	 * @param mixed $route_key Key of the route rule.
	 *
	 * @return mixed The cookies of the route.
	 */
	public function route_cookies( mixed $cookies, mixed $route_key = '' ): mixed {
		if ( ! is_string( $route_key ) || ! Store_Routes::is_price_bearing( $route_key ) ) {
			return $cookies;
		}

		try {
			/**
			 * Filters the cookies, with their allowed values, that carry the visitor's currency.
			 *
			 * @see Hooks::WC_CURRENCY_COOKIES
			 */
			$declared = apply_filters( Hooks::WC_CURRENCY_COOKIES, array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		} catch ( \Throwable $e ) {
			// A declaration that cannot be read is not an empty one: null fails closed, so the route is not cached.
			Failure_Recorder::record( 'hook:' . Hooks::WC_CURRENCY_COOKIES, $e, $route_key );
			return null;
		}

		if ( ! is_array( $declared ) ) {
			return $declared;
		}
		if ( ! is_array( $cookies ) ) {
			return $cookies;
		}

		foreach ( $declared as $name => $values ) {
			if ( ! array_key_exists( $name, $cookies ) ) {
				$cookies[ $name ] = $values;
				continue;
			}
			$cookies[ $name ] = self::union( $cookies[ $name ], $values );
		}

		return $cookies;
	}

	/**
	 * The union of the allowed values two declarations make for one cookie.
	 *
	 * A side that is not a list of values cannot be merged; it is kept, so the engine rejects the declaration.
	 *
	 * @param mixed $existing Values declared so far.
	 * @param mixed $values   Values of the currency-cookie declaration.
	 *
	 * @return mixed
	 */
	private static function union( mixed $existing, mixed $values ): mixed {
		if ( ! is_array( $existing ) ) {
			return $existing;
		}
		if ( ! is_array( $values ) ) {
			return $values;
		}

		$union = array_values( $existing );
		foreach ( $values as $value ) {
			if ( ! in_array( $value, $union, true ) ) {
				$union[] = $value;
			}
		}
		return $union;
	}
}
