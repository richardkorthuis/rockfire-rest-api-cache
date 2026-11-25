<?php
/**
 * Facts about one registered REST route.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable description of a discovered REST route.
 *
 * @since 1.0.0
 */
final class Route_Info {

	/**
	 * Constructor.
	 *
	 * @param string   $route       Route as registered (a regex), e.g. `/wp/v2/posts/(?P<id>[\d]+)`.
	 * @param string   $namespace   Route namespace, e.g. `wp/v2` (empty when unknown).
	 * @param bool     $readable    Whether the route has a GET handler.
	 * @param string[] $get_args    Sorted union of the argument names of the GET handlers.
	 * @phpstan-param list<string> $get_args
	 * @param bool     $denied      Whether the hard deny-list blocks the route.
	 * @param bool     $is_default  Whether the route is cached by default.
	 * @param string[] $methods     The methods of {@see Route_Rule::METHODS} the route has a handler for, in that order.
	 *                              Empty when the route was not read from a server; {@see self::registers()} then
	 *                              answers GET from `$readable`.
	 * @phpstan-param list<string> $methods
	 * @param array    $method_args Method => sorted union of the argument names of that method's handlers.
	 * @phpstan-param array<string,list<string>> $method_args
	 * @param string[] $custom_access The methods of `$methods` with a handler whose permission check is neither public nor
	 *                                core's own ({@see Route_Discovery::ACCESS_CUSTOM}), in the same order.
	 * @phpstan-param list<string> $custom_access
	 */
	public function __construct(
		public readonly string $route,
		public readonly string $namespace, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound -- Public field name mandated by the route info schema.
		public readonly bool $readable,
		public readonly array $get_args,
		public readonly bool $denied,
		public readonly bool $is_default,
		public readonly array $methods = [],
		public readonly array $method_args = [],
		public readonly array $custom_access = []
	) {
	}

	/**
	 * Whether the route has a handler for a method. GET also follows `$readable`, so a route built without
	 * `$methods` still answers it.
	 *
	 * @param string $method Request method, in any case.
	 *
	 * @return bool
	 */
	public function registers( string $method ): bool {
		$method = strtoupper( $method );
		if ( in_array( $method, $this->methods, true ) ) {
			return true;
		}
		return 'GET' === $method && $this->readable;
	}
}
