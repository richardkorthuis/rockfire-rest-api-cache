<?php
/**
 * Read model of one REST route merged with its effective rule.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Services;

use Rockfire\Rest_Api_Cache\Config\Route_Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable view of a route as listed by {@see Route_Service}.
 *
 * @since 1.0.0
 */
final class Route_View {

	/**
	 * Constructor.
	 *
	 * @param string          $id            Stable short id of the route (see {@see Route_Service::id_for()}).
	 * @param string          $route         Route pattern exactly as registered (the rule key).
	 * @param string          $namespace     REST namespace, empty when unknown.
	 * @param string[]        $args          Names of the GET arguments (those of the other methods are in `$method_args`).
	 * @phpstan-param list<string> $args
	 * @param bool            $is_registered False for an orphan rule (a rule without a readable registered route).
	 * @param bool            $is_denied     Whether the route can never be cached.
	 * @param bool            $is_default    Whether the route is cached by default.
	 * @param Route_Rule|null $rule          The override rule (stored, or from `RFRAC_ROUTES` when locked), null when none.
	 * @param string          $source        Layer of the effective rule: default, settings, constant or filter.
	 * @param bool            $locked        Whether the rule is defined by the `RFRAC_ROUTES` constant.
	 * @param bool            $enabled       Effective: whether responses are cached; never true for a denied route.
	 * @param int             $ttl           Effective TTL in seconds (the rule TTL, else the site default TTL).
	 * @param string|null     $invalid       Why the stored rule is invalid (it then counts as `excluded`), or null.
	 * @param string[]        $methods       The methods of {@see Route_Rule::METHODS} the route registers a handler for, in that order.
	 * @phpstan-param list<string> $methods
	 * @param array           $method_args   Method => names of the arguments of that method's handlers.
	 * @phpstan-param array<string,list<string>> $method_args
	 * @param string[]        $custom_access The methods of `$methods` whose permission check is custom
	 *                                       ({@see \Rockfire\Rest_Api_Cache\Config\Route_Discovery::ACCESS_CUSTOM}).
	 * @phpstan-param list<string> $custom_access
	 * @param string[]        $cached_custom The methods of `$custom_access` whose responses are cached; empty unless `$enabled`.
	 * @phpstan-param list<string> $cached_custom
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $route,
		public readonly string $namespace, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound -- Public field name mandated by the route view schema.
		public readonly array $args,
		public readonly bool $is_registered,
		public readonly bool $is_denied,
		public readonly bool $is_default,
		public readonly ?Route_Rule $rule,
		public readonly string $source,
		public readonly bool $locked,
		public readonly bool $enabled,
		public readonly int $ttl,
		public readonly ?string $invalid = null,
		public readonly array $methods = Route_Rule::DEFAULT_METHODS,
		public readonly array $method_args = [],
		public readonly array $custom_access = [],
		public readonly array $cached_custom = []
	) {
	}

	/**
	 * Whether the route belongs to core's `wp/v2` namespace, which is only ever cached for GET.
	 *
	 * @return bool
	 */
	public function is_core(): bool {
		return 'wp/v2' === $this->namespace || str_starts_with( $this->route, '/wp/v2' );
	}
}
