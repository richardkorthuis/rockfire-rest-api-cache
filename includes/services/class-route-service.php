<?php
/**
 * Route discovery merged with rules, plus validated rule persistence.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Services;

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Deny_List;
use Rockfire\Rest_Api_Cache\Config\Effective_Rule;
use Rockfire\Rest_Api_Cache\Config\Route_Discovery;
use Rockfire\Rest_Api_Cache\Config\Route_Info;
use Rockfire\Rest_Api_Cache\Config\Route_Rule;
use Rockfire\Rest_Api_Cache\Config\Settings_Schema;
use Rockfire\Rest_Api_Cache\Config\Validation_Result;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every REST route that registers GET, POST, PUT or PATCH with its effective rule and validates and persists
 * route rules (through {@see Config} only). Intended for the Routes page, the Tools page and WP-CLI.
 *
 * The list is memoised per instance; it is cleared by this service's own writes. A write made
 * outside the service (for example through {@see Config} directly) requires {@see self::flush()}.
 *
 * A route is identified by its registered pattern; URLs and forms carry {@see self::id_for()}.
 *
 * @since 1.0.0
 */
class Route_Service {

	/**
	 * Valid values of the `view` filter.
	 *
	 * @var string[]
	 */
	public const VIEWS = [ 'all', 'enabled', 'disabled', 'configured', 'locked', 'unregistered' ];

	/**
	 * Statuses accepted by {@see self::set_status()}.
	 *
	 * @var string[]
	 */
	private const BULK_STATUSES = [ Route_Rule::STATUS_ENABLED, Route_Rule::STATUS_EXCLUDED, Route_Rule::STATUS_DEFAULT ];

	/**
	 * Pattern a route preset key has to match.
	 *
	 * @var string
	 */
	private const PRESET_KEY_PATTERN = '/^[a-z0-9_-]{1,64}$/D';

	/**
	 * Settings and rules access.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Route discovery.
	 *
	 * @var Route_Discovery
	 */
	private Route_Discovery $discovery;

	/**
	 * Memoised unfiltered list.
	 *
	 * @var Route_View[]|null
	 */
	private ?array $views = null;

	/**
	 * Constructor.
	 *
	 * @param Config          $config    Settings and rules access.
	 * @param Route_Discovery $discovery Route discovery.
	 */
	public function __construct( Config $config, Route_Discovery $discovery ) {
		$this->config    = $config;
		$this->discovery = $discovery;
	}

	/**
	 * Forget the memoised list. Call it after rules were changed outside this service.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->views = null;
	}

	/**
	 * The id used in URLs and forms for a route pattern.
	 *
	 * @param string $route Route pattern exactly as registered.
	 *
	 * @return string 12 hex characters.
	 */
	public static function id_for( string $route ): string {
		return substr( md5( $route ), 0, 12 );
	}

	/**
	 * All listed routes (those registering GET, POST, PUT or PATCH, except core `wp/v2` routes without GET) merged with their effective rule, plus the orphan rules.
	 *
	 * @param array<string,mixed> $filter Keys: `search` (case-insensitive substring of the route), `namespace`,
	 *                                    `view` (all|enabled|disabled|configured|locked|unregistered),
	 *                                    `orderby` (route|namespace) and `order` (asc|desc).
	 *
	 * @return Route_View[]
	 * @phpstan-return list<Route_View>
	 */
	public function all( array $filter = [] ): array {
		$search    = isset( $filter['search'] ) && is_string( $filter['search'] ) ? trim( $filter['search'] ) : '';
		$namespace = isset( $filter['namespace'] ) && is_string( $filter['namespace'] ) ? $filter['namespace'] : '';
		$view      = isset( $filter['view'] ) && is_string( $filter['view'] ) ? $filter['view'] : 'all';
		$by_ns     = isset( $filter['orderby'] ) && 'namespace' === $filter['orderby'];
		$desc      = isset( $filter['order'] ) && is_string( $filter['order'] ) && 'desc' === strtolower( $filter['order'] );

		$out = [];
		foreach ( $this->views() as $item ) {
			if ( '' !== $search && false === stripos( $item->route, $search ) ) {
				continue;
			}
			if ( '' !== $namespace && $item->namespace !== $namespace ) {
				continue;
			}
			if ( ! $this->matches_view( $item, $view ) ) {
				continue;
			}
			$out[] = $item;
		}

		usort(
			$out,
			static function ( Route_View $a, Route_View $b ) use ( $by_ns, $desc ): int {
				$cmp = $by_ns ? $a->namespace <=> $b->namespace : 0;
				if ( 0 === $cmp ) {
					$cmp = $a->route <=> $b->route;
				}
				return $desc ? -$cmp : $cmp;
			}
		);

		return $out;
	}

	/**
	 * The namespaces of the registered routes.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function namespaces(): array {
		$out = [];
		foreach ( $this->views() as $item ) {
			if ( $item->is_registered && '' !== $item->namespace ) {
				$out[ $item->namespace ] = true;
			}
		}
		$out = array_map( 'strval', array_keys( $out ) );
		sort( $out );
		return $out;
	}

	/**
	 * The cached routes that check access with a custom permission callback for a cached method, in discovery order.
	 *
	 * Their responses are only stored for visitors who are not logged in, but a check that relies on anything the cache
	 * key does not hold (a secret header, the visitor's IP) would have its outcome served to every visitor.
	 *
	 * @return Route_View[]
	 * @phpstan-return list<Route_View>
	 */
	public function cached_custom_access(): array {
		return array_values( array_filter( $this->views(), static fn( Route_View $item ): bool => [] !== $item->cached_custom ) );
	}

	/**
	 * Find a route by its id.
	 *
	 * @param string $route_id Id from {@see self::id_for()}.
	 *
	 * @return Route_View|null
	 */
	public function find( string $route_id ): ?Route_View {
		foreach ( $this->views() as $item ) {
			if ( $item->id === $route_id ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Resolve a route pattern or a concrete request path to a route.
	 *
	 * The exact pattern wins (it may also return an orphan rule); otherwise the readable registered
	 * patterns are matched in discovery order, anchored and case-insensitive with core's pattern and
	 * delimiter. The request method is not checked; routes that register none of GET, POST, PUT and PATCH, and core
	 * `wp/v2` routes without a GET handler, are not listed.
	 * A pattern that fails to run (a broken third-party pattern, a PCRE limit) does not match, like in
	 * core's dispatcher; the failure is recorded with {@see Failure_Recorder} (context `routes`).
	 *
	 * @param string $route_or_path Route pattern or path such as `/wp/v2/posts/5`.
	 *
	 * @return Route_View|null
	 */
	public function resolve( string $route_or_path ): ?Route_View {
		$views = $this->views();
		foreach ( $views as $item ) {
			if ( $item->route === $route_or_path ) {
				return $item;
			}
		}

		$path = (string) strtok( $route_or_path, '?' );
		if ( '' === $path ) {
			return null;
		}
		if ( '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		foreach ( $views as $item ) {
			if ( ! $item->is_registered ) {
				continue;
			}
			// Same pattern and delimiter as core's dispatcher, so it resolves exactly what core routes.
			$matched = @preg_match( '@^' . $item->route . '$@i', $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A broken third-party pattern is recorded below instead of emitting a warning.
			if ( 1 === $matched ) {
				return $item;
			}
			if ( false === $matched ) {
				Failure_Recorder::record( 'routes', sprintf( 'The pattern of route %s could not be matched: %s', $item->route, preg_last_error_msg() ), $item->route, false );
			}
		}
		return null;
	}

	/**
	 * Validate and persist the rule of one route.
	 *
	 * The input replaces the stored rule (missing fields take their default). A rule equal to the
	 * route's default rule removes the override: for a route cached by default that is the built-in
	 * rule ({@see Config::default_route_rule()}, `status` `default` counting as `enabled`), for any
	 * other route {@see Route_Rule::defaults()}. Saving `{status: default}` on a default route is
	 * therefore a stored override without the built-in `search` bypass.
	 *
	 * A TTL outside [60, site `max_ttl`] is clamped into that range; `$adjusted_ttl` then receives the
	 * TTL that was saved, so the caller can say so.
	 *
	 * The `methods` of the rule have to be methods the route registers, and a core `wp/v2` route only takes GET. A rule
	 * without `methods` caches GET: an enabled rule is refused on a route that does not register GET, an excluded or
	 * default one is not. An empty `methods` list is another matter: it says that no method was chosen (a form with
	 * every method box unticked), and an enabled rule is refused for it instead of being stored as GET.
	 *
	 * @param string              $route_id     Route id.
	 * @param array<string,mixed> $input        Rule fields (see {@see Route_Rule::from_array()}).
	 * @param int|null            $adjusted_ttl Receives the saved TTL when the requested TTL was clamped, else null.
	 *
	 * @return Route_Rule|\WP_Error The saved rule, or an error: `rfrac_route_not_found`, `rfrac_route_unregistered`,
	 *                              `rfrac_route_locked`, `rfrac_invalid_match` (wildcard on a registered route),
	 *                              `rfrac_invalid_method` (field `methods`), `rfrac_invalid_*`, `rfrac_forbidden_header`,
	 *                              `rfrac_invalid_post_type`, `rfrac_invalid_taxonomy`,
	 *                              `rfrac_save_failed` (the database write failed).
	 */
	public function save( string $route_id, array $input, ?int &$adjusted_ttl = null ): Route_Rule|\WP_Error {
		$adjusted_ttl = null;

		$view = $this->find( $route_id );
		if ( null === $view ) {
			if ( $this->is_unreadable_route( $route_id ) ) {
				return new \WP_Error( 'rfrac_route_unregistered', __( 'This route is not registered.', 'rockfire-rest-api-cache' ) );
			}
			return new \WP_Error( 'rfrac_route_not_found', __( 'Unknown route.', 'rockfire-rest-api-cache' ) );
		}
		if ( ! $view->is_registered ) {
			return new \WP_Error( 'rfrac_route_unregistered', __( 'This route is not registered.', 'rockfire-rest-api-cache' ) );
		}
		if ( $view->is_denied || $view->locked ) {
			return new \WP_Error( 'rfrac_route_locked', __( 'This route can not be changed.', 'rockfire-rest-api-cache' ) );
		}

		$rule = Route_Rule::from_array( $input );
		if ( Route_Rule::MATCH_WILDCARD === $rule->match ) {
			return new \WP_Error( 'rfrac_invalid_match', __( 'A registered route can only use registered matching.', 'rockfire-rest-api-cache' ), [ 'field' => 'match' ] );
		}
		$requested_ttl = isset( $input['ttl'] ) && '' !== $input['ttl'] ? Settings_Schema::to_int( $input['ttl'] ) : null;
		$rule          = $rule->with_ttl( $this->clamp_ttl( $rule->ttl ) );

		$none_chosen = array_key_exists( 'methods', $input ) && array() === $input['methods'];
		$result      = $rule->validate()->merge( Route_Rule::validate_key( $view->route, $rule->match ) )->merge( $this->validate_methods( $view, $rule, $none_chosen ) );
		foreach ( $rule->flush_post_types as $name ) {
			if ( ! post_type_exists( $name ) ) {
				$result->add_error( 'invalid_post_type', sprintf( 'Unknown post type "%s".', $name ), 'flush_post_types' );
			}
		}
		foreach ( $rule->flush_taxonomies as $name ) {
			if ( ! taxonomy_exists( $name ) ) {
				$result->add_error( 'invalid_taxonomy', sprintf( 'Unknown taxonomy "%s".', $name ), 'flush_taxonomies' );
			}
		}
		if ( ! $result->is_valid() ) {
			return $this->to_error( $result );
		}

		$saved       = $this->config->save_route_rules( [ $view->route => $this->is_default_rule( $view->is_default, $rule ) ? null : $rule->to_array() ] );
		$this->views = null;
		if ( ! $saved->is_valid() ) {
			return $this->to_error( $saved );
		}
		if ( null !== $requested_ttl && $rule->ttl !== $requested_ttl ) {
			$adjusted_ttl = $rule->ttl;
		}
		return $rule;
	}

	/**
	 * Remove the override rule of a route (also of an orphan).
	 *
	 * @param string $route_id Route id.
	 *
	 * @return bool|\WP_Error True when a stored rule was removed, false when there was nothing to reset, or an
	 *                        error: `rfrac_route_not_found`, `rfrac_route_locked`, or the failed write's error.
	 */
	public function reset( string $route_id ): bool|\WP_Error {
		$view = $this->find( $route_id );
		if ( null === $view ) {
			return new \WP_Error( 'rfrac_route_not_found', __( 'Unknown route.', 'rockfire-rest-api-cache' ) );
		}
		if ( $view->locked ) {
			return new \WP_Error( 'rfrac_route_locked', __( 'This route can not be changed.', 'rockfire-rest-api-cache' ) );
		}
		if ( ! isset( $this->config->stored_route_rules()[ $view->route ] ) && null === $view->invalid ) {
			return false;
		}
		$result      = $this->config->delete_route_rule( $view->route );
		$this->views = null;
		return $result->is_valid() ? true : $this->to_error( $result );
	}

	/**
	 * Set the status of several routes with one write.
	 *
	 * Starts from each route's own rule (the stored override, else the built-in rule of a default route,
	 * so the `search` bypass is kept) and changes only `status`. Skipped, and not counted: unknown,
	 * denied, unregistered and constant-locked routes, routes that can not be enabled because the rule has no method
	 * they register (for example a POST-only route, whose methods have to be chosen on its edit screen), and routes
	 * already at the target status (`default` and `enabled` are the same state on a route cached by default).
	 *
	 * `$skipped` tells the routes that can not be changed (unknown, denied, unregistered, constant-locked and those
	 * without a method to cache) from the ones already at the target status, which are neither counted nor skipped.
	 * `$needs_methods` tells which of the skipped ones can be changed, but only once their methods are chosen on the
	 * edit screen of the route: it is part of `$skipped`, so the routes that can not be changed at all are
	 * `$skipped - $needs_methods`.
	 *
	 * @param string[] $route_ids     Route ids.
	 * @param string   $status        `enabled`, `excluded` or `default`.
	 * @param int|null $skipped       Receives the number of routes skipped because they can not be changed (now).
	 * @param int|null $needs_methods Receives the number of skipped routes that only need their methods chosen.
	 *
	 * @return int|\WP_Error Number of routes changed, or `rfrac_invalid_status` / the failed write's error.
	 *
	 * @param-out int $skipped
	 * @param-out int $needs_methods
	 */
	public function set_status( array $route_ids, string $status, ?int &$skipped = null, ?int &$needs_methods = null ): int|\WP_Error {
		$skipped       = 0;
		$needs_methods = 0;
		if ( ! in_array( $status, self::BULK_STATUSES, true ) ) {
			return new \WP_Error( 'rfrac_invalid_status', __( 'Unknown status.', 'rockfire-rest-api-cache' ), [ 'field' => 'status' ] );
		}

		$batch = [];
		foreach ( array_unique( array_map( 'strval', $route_ids ) ) as $route_id ) {
			$view = $this->find( $route_id );
			if ( null === $view || ! $view->is_registered || $view->is_denied || $view->locked ) {
				++$skipped;
				continue;
			}
			$current = $view->rule ?? ( $view->is_default ? Config::default_route_rule() : Route_Rule::defaults() );
			if ( null === $view->invalid && $this->resolved_status( $view->is_default, $current->status ) === $this->resolved_status( $view->is_default, $status ) ) {
				continue;
			}
			$data           = $current->to_array();
			$data['status'] = $status;
			$updated        = Route_Rule::from_array( $data );
			if ( Route_Rule::STATUS_ENABLED === $status && ! self::registers_a_method_of( $view, $updated ) ) {
				// Enabling would cache nothing: none of the rule's methods is registered (a POST-only route needs its methods chosen).
				++$skipped;
				++$needs_methods;
				continue;
			}
			$batch[ $view->route ] = $this->is_default_rule( $view->is_default, $updated ) ? null : $updated->to_array();
		}

		if ( [] === $batch ) {
			return 0;
		}
		$result      = $this->config->save_route_rules( $batch );
		$this->views = null;
		return $result->is_valid() ? count( $batch ) : $this->to_error( $result );
	}

	/**
	 * The route presets: named groups of routes that can be enabled in one step.
	 *
	 * Applies {@see Hooks::ROUTE_PRESETS}. An entry is dropped when its key is not 1 to 64 characters of
	 * `a-z`, `0-9`, `_` and `-`, when its label is not a non-empty string, or when its routes are not a
	 * non-empty list of strings. A filter result that is not an array means no presets.
	 *
	 * @return array<string,array{label:string,routes:list<string>}> Preset key => label and route patterns.
	 */
	public function presets(): array {
		$filtered = apply_filters( Hooks::ROUTE_PRESETS, [] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		if ( ! is_array( $filtered ) ) {
			return [];
		}

		$out = [];
		foreach ( $filtered as $key => $preset ) {
			$key = (string) $key;
			if ( 1 !== preg_match( self::PRESET_KEY_PATTERN, $key ) || ! is_array( $preset ) ) {
				continue;
			}
			$label  = $preset['label'] ?? null;
			$routes = $preset['routes'] ?? null;
			if ( ! is_string( $label ) || '' === $label || ! is_array( $routes ) || [] === $routes || ! array_is_list( $routes ) ) {
				continue;
			}
			$list = [];
			foreach ( $routes as $route ) {
				if ( ! is_string( $route ) ) {
					continue 2;
				}
				$list[] = $route;
			}
			$out[ $key ] = [
				'label'  => $label,
				'routes' => $list,
			];
		}
		return $out;
	}

	/**
	 * Enable the routes of a preset with one write.
	 *
	 * Goes through {@see self::set_status()}, so unregistered, denied and constant-locked routes and routes that are
	 * already enabled are skipped and not counted, and an enabled route keeps the rest of its stored rule. Of those,
	 * `$skipped` counts the routes that can not be changed; the already enabled ones are not skipped. `$needs_methods`
	 * is the part of `$skipped` that only needs its methods chosen on the edit screen of the route.
	 *
	 * @param string   $key           Preset key, see {@see self::presets()}.
	 * @param int|null $skipped       Receives the number of routes skipped because they can not be changed (see {@see self::set_status()}).
	 * @param int|null $needs_methods Receives the number of skipped routes that need their methods chosen (see {@see self::set_status()}).
	 *
	 * @return int|\WP_Error Number of routes enabled, or `rfrac_preset_not_found` / the failed write's error.
	 *
	 * @param-out int $skipped
	 * @param-out int $needs_methods
	 */
	public function enable_preset( string $key, ?int &$skipped = null, ?int &$needs_methods = null ): int|\WP_Error {
		$skipped       = 0;
		$needs_methods = 0;
		$presets       = $this->presets();
		if ( ! isset( $presets[ $key ] ) ) {
			return new \WP_Error( 'rfrac_preset_not_found', __( 'Unknown route preset.', 'rockfire-rest-api-cache' ) );
		}
		return $this->set_status( array_map( [ self::class, 'id_for' ], $presets[ $key ]['routes'] ), Route_Rule::STATUS_ENABLED, $skipped, $needs_methods );
	}

	/**
	 * Return the routes of a route preset to their default policy.
	 *
	 * The reverse of {@see self::enable_preset()}: every route of the preset that can be changed gets the status
	 * `default`, so a route that is not cached by default is no longer cached and its stored rule is removed
	 * unless it keeps other settings. Routes that can not be changed are counted in `$skipped`, like there.
	 *
	 * @param string   $key     Preset key.
	 * @param int|null $skipped Set to the number of routes that can not be changed.
	 *
	 * @return int|\WP_Error Number of routes changed, or `rfrac_preset_not_found` for an unknown key.
	 *
	 * @param-out int $skipped
	 */
	public function disable_preset( string $key, ?int &$skipped = null ): int|\WP_Error {
		$skipped = 0;
		$presets = $this->presets();
		if ( ! isset( $presets[ $key ] ) ) {
			return new \WP_Error( 'rfrac_preset_not_found', __( 'Unknown route preset.', 'rockfire-rest-api-cache' ) );
		}
		return $this->set_status( array_map( [ self::class, 'id_for' ], $presets[ $key ]['routes'] ), Route_Rule::STATUS_DEFAULT, $skipped );
	}

	/**
	 * Whether a route preset is enabled: every route of it that can be changed is cached.
	 *
	 * Routes that can not be changed (unregistered, denied or locked by a constant) or enabled (none of the methods of
	 * their rule is registered) are left out. A preset with no route that can be changed is not enabled.
	 *
	 * @param string $key Preset key.
	 *
	 * @return bool
	 */
	public function preset_enabled( string $key ): bool {
		$presets = $this->presets();
		if ( ! isset( $presets[ $key ] ) ) {
			return false;
		}

		$changeable = 0;
		foreach ( $presets[ $key ]['routes'] as $route ) {
			$view = $this->find( self::id_for( $route ) );
			if ( null === $view || ! $view->is_registered || $view->is_denied || $view->locked ) {
				continue;
			}
			if ( ! $view->enabled ) {
				if ( ! self::registers_a_method_of( $view, $view->rule ?? ( $view->is_default ? Config::default_route_rule() : Route_Rule::defaults() ) ) ) {
					continue; // Enabling it would cache nothing, so it is left out like set_status() leaves it out.
				}
				return false;
			}
			++$changeable;
		}
		return $changeable > 0;
	}

	/**
	 * Check the methods of a rule against the methods the route registers.
	 *
	 * A method the route does not register is refused when the rule names methods (anything but the default GET), and a
	 * non-GET method on a core `wp/v2` route is always refused. An enabled rule needs at least one method the route
	 * registers; the default GET of a rule without methods counts like any other, but an explicitly empty list of
	 * methods (`$none_chosen`) does not count as GET. Methods that are not supported at all are reported by
	 * {@see Route_Rule::validate()}.
	 *
	 * @param Route_View $view        Route.
	 * @param Route_Rule $rule        Rule.
	 * @param bool       $none_chosen Whether the input named an empty list of methods (which the rule reads as GET).
	 *
	 * @return Validation_Result Error code `invalid_method` on field `methods`.
	 */
	private function validate_methods( Route_View $view, Route_Rule $rule, bool $none_chosen = false ): Validation_Result {
		$result = new Validation_Result();
		$core   = $view->is_core();
		$named  = Route_Rule::DEFAULT_METHODS !== $rule->methods;

		foreach ( $rule->methods as $method ) {
			if ( ! in_array( $method, Route_Rule::METHODS, true ) ) {
				continue;
			}
			if ( 'GET' !== $method && $core ) {
				$result->add_error( 'invalid_method', sprintf( 'A core route can only cache GET, not %s.', $method ), 'methods' );
			} elseif ( $named && ! in_array( $method, $view->methods, true ) ) {
				$result->add_error( 'invalid_method', sprintf( 'The route does not register %s.', $method ), 'methods' );
			}
		}

		if ( $result->is_valid() && Route_Rule::STATUS_ENABLED === $rule->status && ( $none_chosen || ! self::registers_a_method_of( $view, $rule ) ) ) {
			$result->add_error( 'invalid_method', 'Select at least one method the route registers.', 'methods' );
		}
		return $result;
	}

	/**
	 * Whether a rule names at least one method the route registers.
	 *
	 * @param Route_View $view Route.
	 * @param Route_Rule $rule Rule.
	 *
	 * @return bool
	 */
	private static function registers_a_method_of( Route_View $view, Route_Rule $rule ): bool {
		return [] !== array_intersect( $rule->methods, $view->methods );
	}

	/**
	 * Whether a rule equals the default rule of a route, so that no override needs storing.
	 *
	 * @param bool       $is_default Whether the route is cached by default.
	 * @param Route_Rule $rule       Rule.
	 *
	 * @return bool
	 */
	private function is_default_rule( bool $is_default, Route_Rule $rule ): bool {
		$base                = $is_default ? Config::default_route_rule() : Route_Rule::defaults();
		$data                = $rule->to_array();
		$data['status']      = $this->resolved_status( $is_default, $rule->status );
		$base_data           = $base->to_array();
		$base_data['status'] = $this->resolved_status( $is_default, $base->status );
		return $base_data === $data;
	}

	/**
	 * The status with `default` resolved the way the compiler does for a route cached by default.
	 *
	 * @param bool   $is_default Whether the route is cached by default.
	 * @param string $status     Status.
	 *
	 * @return string
	 */
	private function resolved_status( bool $is_default, string $status ): string {
		return $is_default && Route_Rule::STATUS_DEFAULT === $status ? Route_Rule::STATUS_ENABLED : $status;
	}

	/**
	 * Whether an id belongs to a registered route that is not listed: it registers none of GET, POST, PUT and PATCH
	 * (for example DELETE only), or it is a core `wp/v2` route without a GET handler.
	 *
	 * @param string $route_id Route id.
	 *
	 * @return bool
	 */
	private function is_unreadable_route( string $route_id ): bool {
		foreach ( array_keys( $this->discovery->discover( Route_Discovery::server() ) ) as $route ) {
			if ( self::id_for( (string) $route ) === $route_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The unfiltered, memoised list of views.
	 *
	 * @return Route_View[]
	 * @phpstan-return list<Route_View>
	 */
	private function views(): array {
		if ( null !== $this->views ) {
			return $this->views;
		}

		$server    = Route_Discovery::server();
		$effective = $this->config->route_rules( $server );
		$stored    = $this->config->stored_route_rules();
		$constants = $this->config->constant_route_rules();
		$invalid   = $this->config->invalid_stored_route_rules();
		$default   = (int) $this->config->settings()['default_ttl'];

		$views = [];
		$known = [];
		foreach ( $this->discovery->discover( $server ) as $route => $info ) {
			$methods = self::listed_methods( $info );
			if ( [] === $methods ) {
				continue;
			}
			$route    = (string) $route;
			$denied   = $info->denied || Deny_List::is_denied( $route );
			$status   = $effective[ $route ] ?? null;
			$cached   = ! $denied && null !== $status && Route_Rule::STATUS_ENABLED === $status->rule->status && Route_Rule::MATCH_REGISTERED === $status->rule->match;
			$override = $constants[ $route ] ?? $stored[ $route ] ?? ( isset( $invalid[ $route ] ) ? self::invalid_rule( $status ) : null );
			$custom   = array_values( array_intersect( $methods, $info->custom_access ) );
			// The custom-checked methods whose responses are cached: those the effective rule caches, while it caches anything.
			$cached_custom = $cached ? array_values( array_intersect( $custom, $status->rule->methods ) ) : [];
			$view          = $this->build( $route, $info->namespace, $info->get_args, true, $denied, $info->is_default, $override, $status, isset( $constants[ $route ] ), $cached, $default, isset( $constants[ $route ] ) ? null : $invalid[ $route ] ?? null, $methods, self::method_args( $info, $methods ), $custom, $cached_custom );
			if ( $view->is_core() && ! in_array( 'GET', $methods, true ) ) {
				continue; // A core route is only ever cached for GET, so one without a GET handler has nothing to enable.
			}
			$known[ $route ] = true;
			$views[]         = $view;
		}

		foreach ( $invalid as $route => $reason ) {
			$route = (string) $route;
			if ( isset( $known[ $route ] ) || isset( $constants[ $route ] ) ) {
				continue;
			}
			$known[ $route ] = true;
			// An invalid rule caches nothing; listed so that it can be reset.
			$views[] = $this->build( $route, '', [], false, false, false, self::invalid_rule( null ), $effective[ $route ] ?? null, false, false, $default, $reason );
		}

		foreach ( array_merge( $stored, $constants ) as $route => $rule ) {
			$route = (string) $route;
			if ( isset( $known[ $route ] ) ) {
				continue;
			}
			$known[ $route ] = true;
			$status          = $effective[ $route ] ?? null;
			$wildcard        = null !== $status && Route_Rule::MATCH_WILDCARD === $status->rule->match;
			// Only a wildcard rule can be cached without a registered route; a registered match never is.
			$denied  = $wildcard ? Deny_List::is_wildcard_denied( $route ) : Deny_List::is_denied( $route );
			$cached  = $wildcard && ! $denied && Route_Rule::STATUS_ENABLED === $status->rule->status;
			$views[] = $this->build( $route, '', [], false, $denied, false, $constants[ $route ] ?? $rule, $status, isset( $constants[ $route ] ), $cached, $default );
		}

		$this->views = $views;
		return $views;
	}

	/**
	 * Build one view.
	 *
	 * @param string              $route         Route pattern.
	 * @param string              $rest_namespace Namespace.
	 * @param string[]            $args          GET argument names.
	 * @param bool                $is_registered Whether the route is registered.
	 * @param bool                $is_denied     Whether the route is denied.
	 * @param bool                $is_default    Whether the route is cached by default.
	 * @param Route_Rule|null     $override      Stored or constant rule.
	 * @param Effective_Rule|null $effective     Effective rule.
	 * @param bool                $locked        Whether a constant defines the rule.
	 * @param bool                $enabled       Whether responses are cached.
	 * @param int                 $default_ttl   Site default TTL.
	 * @param string|null         $invalid       Why the stored rule is invalid, or null.
	 * @param string[]            $methods       The methods of {@see Route_Rule::METHODS} the route registers.
	 * @phpstan-param list<string> $methods
	 * @param array               $method_args   Method => argument names of that method's handlers.
	 * @phpstan-param array<string,list<string>> $method_args
	 * @param string[]            $custom_access The listed methods with a custom permission check.
	 * @phpstan-param list<string> $custom_access
	 * @param string[]            $cached_custom The methods of `$custom_access` whose responses are cached.
	 * @phpstan-param list<string> $cached_custom
	 *
	 * @return Route_View
	 */
	private function build( string $route, string $rest_namespace, array $args, bool $is_registered, bool $is_denied, bool $is_default, ?Route_Rule $override, ?Effective_Rule $effective, bool $locked, bool $enabled, int $default_ttl, ?string $invalid = null, array $methods = Route_Rule::DEFAULT_METHODS, array $method_args = [], array $custom_access = [], array $cached_custom = [] ): Route_View {
		$ttl = null !== $effective && null !== $effective->rule->ttl ? $effective->rule->ttl : $default_ttl;

		return new Route_View(
			self::id_for( $route ),
			$route,
			$rest_namespace,
			array_values( array_map( 'strval', $args ) ),
			$is_registered,
			$is_denied,
			$is_default,
			$override,
			null === $effective ? Effective_Rule::SOURCE_DEFAULT : $effective->source,
			$locked,
			$enabled,
			$ttl,
			$invalid,
			$methods,
			$method_args,
			$custom_access,
			$cached_custom
		);
	}

	/**
	 * The methods a discovered route is listed with: the ones of {@see Route_Rule::METHODS} it registers.
	 *
	 * @param Route_Info $info Discovered route.
	 *
	 * @return string[] Empty when the route registers none of them (it is then not listed).
	 * @phpstan-return list<string>
	 */
	private static function listed_methods( Route_Info $info ): array {
		$methods = [];
		foreach ( Route_Rule::METHODS as $method ) {
			if ( $info->registers( $method ) ) {
				$methods[] = $method;
			}
		}
		return $methods;
	}

	/**
	 * The argument names of each listed method of a route.
	 *
	 * @param Route_Info $info    Discovered route.
	 * @param string[]   $methods The listed methods, see {@see self::listed_methods()}.
	 * @phpstan-param list<string> $methods
	 *
	 * @return array<string,list<string>> Method => argument names.
	 */
	private static function method_args( Route_Info $info, array $methods ): array {
		$out = [];
		foreach ( $methods as $method ) {
			$out[ $method ] = 'GET' === $method ? $info->get_args : ( $info->method_args[ $method ] ?? [] );
		}
		return $out;
	}

	/**
	 * The rule shown for an invalid stored rule: the effective (excluded) rule, else an excluded default rule.
	 *
	 * @param Effective_Rule|null $effective Effective rule of the route.
	 *
	 * @return Route_Rule
	 */
	private static function invalid_rule( ?Effective_Rule $effective ): Route_Rule {
		if ( null !== $effective ) {
			return $effective->rule;
		}
		$data           = Route_Rule::defaults()->to_array();
		$data['status'] = Route_Rule::STATUS_EXCLUDED;
		return Route_Rule::from_array( $data );
	}

	/**
	 * Whether a view belongs to a `view` filter value.
	 *
	 * @param Route_View $item View.
	 * @param string     $view Filter value.
	 *
	 * @return bool
	 */
	private function matches_view( Route_View $item, string $view ): bool {
		switch ( $view ) {
			case 'enabled':
				return $item->enabled;
			case 'disabled':
				return $item->is_registered && ! $item->enabled;
			case 'configured':
				return null !== $item->rule;
			case 'locked':
				return $item->locked;
			case 'unregistered':
				return ! $item->is_registered;
			default:
				return true;
		}
	}

	/**
	 * Clamp a rule TTL into [60, site max_ttl]; null stays null.
	 *
	 * @param int|null $ttl TTL.
	 *
	 * @return int|null
	 */
	private function clamp_ttl( ?int $ttl ): ?int {
		if ( null === $ttl ) {
			return null;
		}
		return max( Settings_Schema::MIN_TTL, min( (int) $this->config->settings()['max_ttl'], $ttl ) );
	}

	/**
	 * Convert validation errors to a `WP_Error` (the first error is the main one).
	 *
	 * @param Validation_Result $result Invalid result.
	 *
	 * @return \WP_Error
	 */
	private function to_error( Validation_Result $result ): \WP_Error {
		$error = new \WP_Error();
		foreach ( $result->errors() as $item ) {
			if ( Config::SAVE_FAILED === $item['code'] ) {
				$error->add( 'rfrac_save_failed', __( 'The changes could not be saved to the database.', 'rockfire-rest-api-cache' ) );
				continue;
			}
			$error->add( $this->map_code( $item['code'] ), $item['message'], [ 'field' => $item['field'] ] );
		}
		if ( ! $error->has_errors() ) {
			$error->add( 'rfrac_invalid_rule', __( 'The rules could not be saved.', 'rockfire-rest-api-cache' ) );
		}
		return $error;
	}

	/**
	 * Map a validation code to a public error code.
	 *
	 * @param string $code Validation code.
	 *
	 * @return string
	 */
	private function map_code( string $code ): string {
		if ( 'forbidden_vary_header' === $code ) {
			return 'rfrac_forbidden_header';
		}
		if ( 'locked_route' === $code ) {
			return 'rfrac_route_locked';
		}
		if ( str_starts_with( $code, 'invalid_' ) ) {
			return 'rfrac_' . $code;
		}
		if ( str_starts_with( $code, 'too_many_' ) ) {
			return 'rfrac_invalid_' . substr( $code, 9 );
		}
		return 'rfrac_invalid_rule';
	}
}
