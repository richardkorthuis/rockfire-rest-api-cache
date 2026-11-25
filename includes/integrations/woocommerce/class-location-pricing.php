<?php
/**
 * WooCommerce location pricing: price-bearing Store API routes are never cached while prices may vary by visitor location.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Integrations\WooCommerce;

use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the generic visitor-variance seam for the price-bearing routes and vetoes storing their responses.
 *
 * Anonymous Store API requests never load a customer, so WooCommerce computes catalog prices from the shop's base
 * location, unless a callback on `woocommerce_get_tax_location` changes the tax location (a geolocation or
 * multi-currency plugin does). Two places guard against that, and neither keys anything by location:
 *
 * - at compile time, {@see self::varies()} marks the price-bearing routes as varying per visitor when such a
 *   callback is hooked, so they are neither served from nor stored in the cache;
 * - at store time, {@see self::allow_store()} refuses to store a price-bearing response while a customer is
 *   loaded or such a callback is hooked, so a snapshot compiled without detection never produces an entry.
 *
 * {@see Hooks::WC_LOCATION_PRICING} forces both on or off. The route list is {@see Store_Routes::PRICE_BEARING}.
 *
 * @since 1.0.0
 */
final class Location_Pricing {

	use Hook_Subscriber_Trait;

	/**
	 * The `status()` value when the filter forces the bypass on although no location filter is hooked.
	 *
	 * @var string
	 */
	public const STATUS_FORCED_ON = 'forced_on';

	/**
	 * The `status()` value when the filter forces the bypass off although a location filter is hooked.
	 *
	 * @var string
	 */
	public const STATUS_FORCED_OFF = 'forced_off';

	/**
	 * The `status()` value when a location filter is hooked.
	 *
	 * @var string
	 */
	public const STATUS_DETECTED = 'detected';

	/**
	 * The `status()` value when no location filter is hooked.
	 *
	 * @var string
	 */
	public const STATUS_NOT_DETECTED = 'not_detected';

	/**
	 * The hooks: the visitor-variance seam of the compiler (10, 2) and the store veto (10, 4).
	 *
	 * @return array<int,array<int,mixed>>
	 *
	 * @phpstan-return list<array{0:string,1:'varies'|'allow_store',2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		return array(
			array( Hooks::ROUTE_VARIES_PER_VISITOR, 'varies', 10, 2 ),
			array( Hooks::STORE_ALLOWED, 'allow_store', 10, 4 ),
		);
	}

	/**
	 * Whether a callback is hooked on the tax-location filter of WooCommerce.
	 *
	 * @return bool
	 */
	public static function detected(): bool {
		return false !== has_filter( 'woocommerce_get_tax_location' );
	}

	/**
	 * Whether the responses of a route vary per visitor: a price-bearing route does while location pricing is detected or forced on.
	 *
	 * The parameters are `mixed` and default to a neutral value: a hook callback receives whatever other
	 * callbacks passed on, and is registered through {@see Hook_Subscriber_Trait::register()}, which needs
	 * callbacks that can be called without arguments.
	 *
	 * @param mixed $varies    Whether another callback already marked the route as varying.
	 * @param mixed $route_key Key of the route rule.
	 *
	 * @return bool
	 */
	public function varies( mixed $varies = false, mixed $route_key = '' ): bool {
		return (bool) $varies || ( is_string( $route_key ) && Store_Routes::is_price_bearing( $route_key ) && self::filtered( self::detected() ) );
	}

	/**
	 * Whether a storable response may be stored: a price-bearing response may not while a customer is loaded or a location filter is hooked.
	 *
	 * Only vetoes, and only price-bearing routes, either by their rule key or by the route that was requested
	 * (a wildcard rule can cover them). A customer is loaded for front-end requests only, never for an anonymous
	 * Store API request, so one that is loaded means the prices may be the visitor's own.
	 *
	 * @param mixed $allowed  Whether another callback allows storing.
	 * @param mixed $route    Request route.
	 * @param mixed $rule_key Key of the matched route rule.
	 * @param mixed $size     Payload size in bytes.
	 *
	 * @return mixed `$allowed` for a route that is not price-bearing, while nothing varies the prices or when the bypass is forced off, else false.
	 */
	public function allow_store( mixed $allowed = true, mixed $route = '', mixed $rule_key = '', mixed $size = 0 ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( false === $allowed ) {
			return $allowed;
		}
		if ( ! ( is_string( $rule_key ) && Store_Routes::is_price_bearing( $rule_key ) ) && ! ( is_string( $route ) && self::is_price_route( $route ) ) ) {
			return $allowed;
		}
		if ( ! ( self::customer_loaded() || self::detected() ) ) {
			return $allowed;
		}

		/**
		 * Filters whether the WooCommerce catalog prices vary by visitor location; only false lets the response be stored.
		 *
		 * @see Hooks::WC_LOCATION_PRICING
		 */
		return false === apply_filters( Hooks::WC_LOCATION_PRICING, true ) ? $allowed : false; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
	}

	/**
	 * How location pricing is currently treated.
	 *
	 * @return string `forced_on` (the filter forces the bypass on), `forced_off` (it forces it off while a location
	 *                filter is hooked), `detected` (a location filter is hooked) or `not_detected`.
	 */
	public static function status(): string {
		$detected = self::detected();
		$result   = self::filtered( $detected );

		if ( $result !== $detected ) {
			return $result ? self::STATUS_FORCED_ON : self::STATUS_FORCED_OFF;
		}

		return $detected ? self::STATUS_DETECTED : self::STATUS_NOT_DETECTED;
	}

	/**
	 * The detected value passed through {@see Hooks::WC_LOCATION_PRICING}.
	 *
	 * @param bool $detected Whether a location filter is hooked.
	 *
	 * @return bool
	 */
	private static function filtered( bool $detected ): bool {
		/**
		 * Filters whether the WooCommerce catalog prices vary by visitor location.
		 *
		 * @see Hooks::WC_LOCATION_PRICING
		 */
		return (bool) apply_filters( Hooks::WC_LOCATION_PRICING, $detected ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
	}

	/**
	 * Whether WooCommerce has loaded a customer.
	 *
	 * The property stays null for an anonymous Store API request; it is read from the object's properties because
	 * the WooCommerce stubs type it as always set.
	 *
	 * @return bool
	 */
	private static function customer_loaded(): bool {
		$properties = get_object_vars( \WC() );

		return isset( $properties['customer'] ) && $properties['customer'] instanceof \WC_Customer;
	}

	/**
	 * Whether a requested route is served by a price-bearing Store API route.
	 *
	 * The slug route is a catch-all (it also matches `products/categories` and the like), so a route handled by
	 * another catalog route is not price-bearing.
	 *
	 * @param string $route Request route, e.g. `/wc/store/v1/products/12`.
	 *
	 * @return bool
	 */
	private static function is_price_route( string $route ): bool {
		foreach ( Store_Routes::CATALOG as $pattern ) {
			if ( ! Store_Routes::is_price_bearing( $pattern ) && 1 === preg_match( '@^' . $pattern . '$@i', $route ) ) {
				return false;
			}
		}
		foreach ( Store_Routes::PRICE_BEARING as $pattern ) {
			if ( 1 === preg_match( '@^' . $pattern . '$@i', $route ) ) {
				return true;
			}
		}

		return false;
	}
}
