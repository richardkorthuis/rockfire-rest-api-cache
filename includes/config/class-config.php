<?php
/**
 * Settings access, constant overrides and route-rule persistence.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Config;

use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Names;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the `rfrac_settings` option and applies the `wp-config.php` constant overrides.
 *
 * Precedence: constants > stored settings > defaults. A key controlled by a valid constant is
 * "locked": it is never persisted. Invalid constant values are ignored and reported through
 * {@see self::constant_errors()}. The effective route rules are available through
 * {@see self::route_rules()}.
 *
 * @since 1.0.0
 */
class Config {

	/**
	 * Name of the constant holding route rules.
	 *
	 * @var string
	 */
	public const ROUTES_CONSTANT = 'RFRAC_ROUTES';

	/**
	 * Validation code of a save whose database write failed.
	 *
	 * @var string
	 */
	public const SAVE_FAILED = 'save_failed';

	/**
	 * Settings that are stored only on an explicit decision: the option holds the key once a save carried a valid
	 * value for it (a ticked or unticked box, an imported choice) and never from a save that did not touch it, so
	 * "nothing stored" stays distinguishable from a stored `false` (see {@see self::is_stored()}).
	 *
	 * @var list<string>
	 */
	private const OPT_IN_KEYS = [ 'nonce_caching' ];

	/**
	 * Shared instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * True while persist() writes the option; lets sanitize_settings() pass validated data through.
	 *
	 * Static because instances are not shared (the Settings API callback may use another Config).
	 *
	 * @var bool
	 */
	private static bool $persisting = false;

	/**
	 * Source of values that apply for a setting whose `RFRAC_*` constant is not defined.
	 *
	 * @var Fallback_Constants_Interface|null
	 */
	private static ?Fallback_Constants_Interface $fallback = null;

	/**
	 * Invalid route rules found by the last {@see self::route_rules()} call.
	 *
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $rule_problems = [];

	/**
	 * The shared instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register the source of fallback constants, or remove it with null.
	 *
	 * The provider is asked for a setting only when the `RFRAC_*` constant of that setting is not defined;
	 * its value is normalised like the constant's, locks the setting under its label and, when invalid, is
	 * reported under that label by {@see self::constant_errors()}; the error of an invalid list is also reported
	 * under the `RFRAC_*` constant, where the compiler looks for it. `enabled`, `RFRAC_ROUTES` and the
	 * compile-only constants never take a fallback. Tests reset the provider with null.
	 *
	 * @param Fallback_Constants_Interface|null $provider The provider.
	 *
	 * @return void
	 */
	public static function set_fallback_constants( ?Fallback_Constants_Interface $provider ): void {
		self::$fallback = $provider;
	}

	/**
	 * Effective settings: stored values with the constants applied; always complete and valid.
	 *
	 * `routes` is the stored rules merged with `RFRAC_ROUTES` (the constant wins), as arrays.
	 *
	 * @return array<string,mixed>
	 */
	public function settings(): array {
		$overrides = $this->read_overrides();
		$settings  = array_merge( $this->raw_settings(), $overrides['values'] );

		$settings['default_ttl'] = max(
			Settings_Schema::MIN_TTL,
			min( (int) $settings['max_ttl'], (int) $settings['default_ttl'] )
		);

		$routes = [];
		foreach ( array_merge( $this->stored_route_rules(), $overrides['routes'] ) as $key => $rule ) {
			$routes[ $key ] = $rule->with_ttl( $this->clamp_rule_ttl( $rule->ttl, (int) $settings['max_ttl'] ) )->to_array();
		}
		$settings['routes'] = $routes;

		return $settings;
	}

	/**
	 * Stored settings without the constants applied; always complete and valid.
	 *
	 * @return array<string,mixed>
	 */
	public function raw_settings(): array {
		$stored = get_option( Names::OPTION_SETTINGS, [] );
		$out    = Settings_Schema::resolve( $stored );

		$out['routes'] = [];
		foreach ( $this->stored_route_rules() as $key => $rule ) {
			$out['routes'][ $key ] = $rule->to_array();
		}
		return $out;
	}

	/**
	 * Settings keys controlled by a constant, mapped to the constant name (or, for a value from the
	 * fallback constants, to its label; see {@see self::set_fallback_constants()}).
	 *
	 * `routes` is listed when `RFRAC_ROUTES` holds at least one valid rule; only the rules that
	 * constant defines are locked (see {@see self::is_route_locked()}).
	 *
	 * @return array<string,string>
	 */
	public function locked(): array {
		return $this->read_overrides()['locked'];
	}

	/**
	 * Whether a settings key is controlled by a constant.
	 *
	 * @param string $key Settings key.
	 *
	 * @return bool
	 */
	public function is_locked( string $key ): bool {
		return isset( $this->locked()[ $key ] );
	}

	/**
	 * Whether a route key is defined by `RFRAC_ROUTES` (and therefore read-only).
	 *
	 * @param string $key Route key.
	 *
	 * @return bool
	 */
	public function is_route_locked( string $key ): bool {
		return isset( $this->read_overrides()['routes'][ $key ] );
	}

	/**
	 * Invalid constant values that were ignored.
	 *
	 * @return array<string,string> Constant name (for routes `RFRAC_ROUTES[key]`, for a fallback value its label, and for an
	 *                              invalid list also the `RFRAC_*` constant it stands in for) => reason.
	 */
	public function constant_errors(): array {
		return $this->read_overrides()['errors'];
	}

	/**
	 * Compile-only constant overrides (`RFRAC_ALLOW_SKIP_CACHE`, `RFRAC_BYPASS_COOKIES`, `RFRAC_BYPASS_HEADERS`, `RFRAC_CORS_WILDCARD`).
	 *
	 * A value is null / empty when the constant is not defined or invalid (invalid ones are listed
	 * in {@see self::constant_errors()}).
	 *
	 * @return array{allow_skip_cache:?bool,bypass_cookies:list<string>,bypass_headers:list<string>,cors_wildcard:?bool}
	 */
	public function compile_overrides(): array {
		$overrides = $this->read_overrides();
		return [
			'allow_skip_cache' => $overrides['compile']['allow_skip_cache'],
			'bypass_cookies'   => $overrides['compile']['bypass_cookies'],
			'bypass_headers'   => $overrides['compile']['bypass_headers'],
			'cors_wildcard'    => $overrides['compile']['cors_wildcard'],
		];
	}

	/**
	 * Whether `RFRAC_DISABLE_MU_INSTALL` is set to a truthy value.
	 *
	 * @return bool
	 */
	public function mu_install_disabled(): bool {
		return true === $this->read_overrides()['compile']['disable_mu_install'];
	}

	/**
	 * Validate and persist settings; locked keys are never persisted.
	 *
	 * Values of the wrong type or outside an enum, and lists that are not valid, record an error and keep
	 * the old value; numbers outside their range are clamped. Valid keys are saved even when other keys failed. Unknown
	 * keys and `routes` are ignored (see {@see self::save_route_rules()}). New global vary headers that make a stored
	 * or `RFRAC_ROUTES` rule vary on too many headers (see {@see self::vary_fit()}) record `too_many_vary_headers`
	 * under `global_vary_headers` and keep the old value. An opt-in setting (`nonce_caching`) is written only when
	 * the input carries a valid value for it or the stored option already holds it, by every save path.
	 *
	 * @param array<mixed> $input Raw input.
	 *
	 * @return Validation_Result Also invalid (`save_failed`) when the database write failed.
	 */
	public function save_settings( array $input ): Validation_Result {
		$result = new Validation_Result();
		$raw    = $this->raw_settings();
		$rules  = $this->stored_routes_raw();
		unset( $raw['routes'] );

		$locked = array_keys( $this->locked() );
		$new    = Settings_Schema::apply( $input, $raw, $result, $locked );
		$error  = $this->vary_overflow( $new, $raw, $locked );
		if ( null !== $error ) {
			$new['global_vary_headers'] = $raw['global_vary_headers'];
			$result->add_error( $error['code'], $error['message'], 'global_vary_headers' );
		}
		if ( ( $new !== $raw || $this->adds_opt_in( $input, $locked ) ) && ! $this->persist( $new, $rules, $input, $locked ) ) {
			$result->add_error( self::SAVE_FAILED, 'The settings could not be saved to the database.' );
		}
		return $result;
	}

	/**
	 * Settings API sanitize callback: returns the complete settings array to store.
	 *
	 * Idempotent. Locked keys keep their stored value, invalid values fall back to the stored
	 * value, `routes` are preserved from the stored option (never taken from the input).
	 * New global vary headers that do not fit the route rules (see {@see self::vary_fit()}) fall back
	 * to the stored value too.
	 * While {@see self::persist()} is writing, the already-validated array is passed through
	 * unchanged so that route-rule saves are not overwritten by this callback.
	 *
	 * @param mixed $input Submitted value.
	 *
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( mixed $input ): array {
		if ( self::$persisting && is_array( $input ) ) {
			return $input;
		}
		$raw = $this->raw_settings();
		if ( ! is_array( $input ) ) {
			return $raw;
		}
		$rules = $this->stored_routes_raw();
		unset( $raw['routes'] );

		$locked = array_keys( $this->locked() );
		$new    = Settings_Schema::apply( $input, $raw, new Validation_Result(), $locked );
		if ( null !== $this->vary_overflow( $new, $raw, $locked ) ) {
			$new['global_vary_headers'] = $raw['global_vary_headers'];
		}
		$new           = $this->storable( $new, $input, $locked );
		$new['routes'] = $rules;
		return $new;
	}

	/**
	 * Check that no route rule would vary on too many request headers once the global vary headers are added.
	 *
	 * A route varies on its own `vary_headers` plus the global ones, without duplicates; the union may hold at
	 * most {@see Route_Rule::MAX_VARY_HEADERS} headers. Combinations that only arise from the `rfrac/route_rules`
	 * and `rfrac/global_vary_headers` filters are not checked here; the compiler handles those.
	 *
	 * @param array<string>     $global_vary Normalised global vary header names.
	 * @param array<mixed>|null $rules       Route key => rule data to check; null checks the stored rules and the
	 *                                       valid `RFRAC_ROUTES` rules, whatever their status.
	 *
	 * @return Validation_Result One `too_many_vary_headers` error per route that does not fit, on the field of the route key.
	 */
	public function vary_fit( array $global_vary, ?array $rules = null ): Validation_Result {
		if ( null === $rules ) {
			$rules = [];
			foreach ( array_merge( $this->stored_route_rules(), $this->constant_route_rules() ) as $key => $rule ) {
				$rules[ $key ] = $rule->to_array();
			}
		}

		$result = new Validation_Result();
		foreach ( $rules as $key => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$key   = (string) $key;
			$total = count( array_unique( array_merge( Route_Rule::from_array( $data )->vary_headers, $global_vary ) ) );
			if ( $total > Route_Rule::MAX_VARY_HEADERS ) {
				$result->add_error(
					'too_many_vary_headers',
					sprintf( 'With the global vary headers, the route "%s" would vary on %d request headers; at most %d are allowed.', $key, $total, Route_Rule::MAX_VARY_HEADERS ),
					$key
				);
			}
		}
		return $result;
	}

	/**
	 * The first problem of new global vary headers that do not fit the route rules.
	 *
	 * Null when the key is locked, did not change or fits (see {@see self::vary_fit()}).
	 *
	 * @param array<string,mixed> $applied Scalar settings after {@see Settings_Schema::apply()}.
	 * @param array<string,mixed> $base    Scalar settings the new ones were applied onto.
	 * @param string[]            $locked  Settings keys that must not change.
	 * @param array<mixed>|null   $rules   Route key => rule data the headers have to fit; null checks the stored and `RFRAC_ROUTES` rules.
	 *
	 * @return array{code:string,message:string,field:string}|null
	 */
	private function vary_overflow( array $applied, array $base, array $locked, ?array $rules = null ): ?array {
		$headers = self::strings( $applied['global_vary_headers'] ?? [] );
		if ( in_array( 'global_vary_headers', $locked, true ) || self::strings( $base['global_vary_headers'] ?? [] ) === $headers ) {
			return null;
		}
		return $this->vary_fit( $headers, $rules )->errors()[0] ?? null;
	}

	/**
	 * The string entries of a list value.
	 *
	 * @param mixed $value List value.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private static function strings( mixed $value ): array {
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : [];
	}

	/**
	 * The stored route rules (without the constant rules).
	 *
	 * @return array<string,Route_Rule>
	 */
	public function stored_route_rules(): array {
		return $this->read_stored_route_rules()['rules'];
	}

	/**
	 * The stored route rules that are not valid, which {@see self::route_rules()} treats as `excluded`.
	 *
	 * They stay stored (and visible) until they are replaced or deleted through {@see self::save_route_rules()}.
	 *
	 * @return array<string,string> Route key => why the rule is invalid.
	 */
	public function invalid_stored_route_rules(): array {
		return $this->read_stored_route_rules()['invalid'];
	}

	/**
	 * The stored route rules as written: the valid ones normalised, the invalid ones unchanged.
	 *
	 * @return array<string,mixed>
	 */
	private function stored_routes_raw(): array {
		$stored = get_option( Names::OPTION_SETTINGS, [] );
		$routes = is_array( $stored ) && isset( $stored['routes'] ) && is_array( $stored['routes'] ) ? $stored['routes'] : [];
		$valid  = $this->stored_route_rules();

		$out = [];
		foreach ( $routes as $key => $data ) {
			$key         = (string) $key;
			$out[ $key ] = isset( $valid[ $key ] ) ? $valid[ $key ]->to_array() : $data;
		}
		return $out;
	}

	/**
	 * The stored route rules, split into the valid rules and the reasons the invalid ones were rejected.
	 *
	 * @return array{rules:array<string,Route_Rule>,invalid:array<string,string>}
	 */
	private function read_stored_route_rules(): array {
		$stored = get_option( Names::OPTION_SETTINGS, [] );
		$routes = is_array( $stored ) && isset( $stored['routes'] ) && is_array( $stored['routes'] ) ? $stored['routes'] : [];

		$out     = [];
		$invalid = [];
		foreach ( $routes as $key => $data ) {
			$key = (string) $key;
			if ( ! is_array( $data ) ) {
				$invalid[ $key ] = 'The rule is not an array.';
				continue;
			}
			$rule   = Route_Rule::from_array( $data );
			$result = $rule->validate()->merge( Route_Rule::validate_key( $key, $rule->match ) );
			if ( $result->is_valid() ) {
				$out[ $key ] = $rule;
			} else {
				$invalid[ $key ] = self::first_error( $result );
			}
		}
		return [
			'rules'   => $out,
			'invalid' => $invalid,
		];
	}

	/**
	 * The message of the first error of a result.
	 *
	 * @param Validation_Result $result Invalid result.
	 *
	 * @return string
	 */
	private static function first_error( Validation_Result $result ): string {
		$errors = $result->errors();
		return isset( $errors[0]['message'] ) ? (string) $errors[0]['message'] : 'Invalid rule.';
	}

	/**
	 * A rule with status `excluded`: what an invalid rule on top of a lower layer's rule resolves to.
	 *
	 * @param Route_Rule $rule Rule of the lower layer.
	 *
	 * @return Route_Rule
	 */
	private static function excluded( Route_Rule $rule ): Route_Rule {
		$data           = $rule->to_array();
		$data['status'] = Route_Rule::STATUS_EXCLUDED;
		return Route_Rule::from_array( $data );
	}


	/**
	 * The route rules defined by `RFRAC_ROUTES` (valid entries only).
	 *
	 * @return array<string,Route_Rule>
	 */
	public function constant_route_rules(): array {
		return $this->read_overrides()['routes'];
	}

	/**
	 * The built-in rule of a route that is cached by default: enabled, registered parameters, and
	 * `search` bypasses the cache.
	 *
	 * @return Route_Rule
	 */
	public static function default_route_rule(): Route_Rule {
		return new Route_Rule( Route_Rule::STATUS_ENABLED, Route_Rule::MATCH_REGISTERED, null, Route_Rule::PARAM_REGISTERED, [], [ 'search' ] );
	}

	/**
	 * The effective route rules: built-in defaults, stored rules, `RFRAC_ROUTES` and the
	 * `rfrac/route_rules` filter applied in that order, each layer replacing the rule of the key
	 * below it. The filter also receives the layer of each rule below it and the discovered routes
	 * (see {@see Hooks::ROUTE_RULES}); the routes are discovered once and shared with the default rules.
	 *
	 * Default routes (see {@see Route_Info::$is_default}) start as enabled rules with
	 * `param_mode` registered and `bypass_params` `search`. A stored rule with status `excluded`
	 * disables a default route; a rule with status `default` follows the built-in default, so it
	 * is enabled for a default route and inactive for any other route. TTLs are clamped to the site `max_ttl`.
	 *
	 * Invalid stored rules and invalid rules returned by the filter fail closed: such a route is
	 * excluded from caching (when a lower layer had a rule for it) instead of keeping the rule below,
	 * and the compiler records the problem with {@see \Rockfire\Rest_Api_Cache\Failure_Recorder} (context `route_rules`, see
	 * {@see self::route_rule_problems()}), which the Status screen and Site Health show.
	 *
	 * Without a server argument this builds the REST server when none exists yet, through
	 * {@see Route_Discovery::server()}: {@see Hooks::DISCOVERY_SERVER} fires and `rest_api_init` runs. The
	 * invalidation listeners reach it through {@see \Rockfire\Rest_Api_Cache\Invalidation\Relevance} during content
	 * saves, so a wp-admin save such as a WooCommerce product save may build the server.
	 *
	 * @param \WP_REST_Server|null $server Server to read the default routes from; null uses `Route_Discovery::server()`.
	 *
	 * @return array<string,Effective_Rule> Route key => rule and the layer that produced it.
	 */
	public function route_rules( ?\WP_REST_Server $server = null ): array {
		$server ??= Route_Discovery::server();
		$max_ttl = (int) $this->settings()['max_ttl'];

		$routes   = ( new Route_Discovery() )->discover( $server );
		$defaults = [];
		foreach ( $routes as $route => $info ) {
			if ( $info->is_default && ! $info->denied ) {
				$defaults[ $route ] = true;
			}
		}

		$rules = [];
		foreach ( array_keys( $defaults ) as $route ) {
			$rules[ $route ] = new Effective_Rule( self::default_route_rule(), Effective_Rule::SOURCE_DEFAULT );
		}
		$stored   = $this->read_stored_route_rules();
		$problems = [];
		foreach ( $stored['invalid'] as $key => $reason ) {
			$problems[] = sprintf( 'stored rule "%s": %s', $key, $reason );
			if ( isset( $rules[ $key ] ) ) {
				$rules[ $key ] = new Effective_Rule( self::excluded( $rules[ $key ]->rule ), Effective_Rule::SOURCE_SETTINGS );
			}
		}
		foreach ( $stored['rules'] as $key => $rule ) {
			$rules[ $key ] = new Effective_Rule( $this->resolve_rule( $rule, isset( $defaults[ $key ] ), $max_ttl ), Effective_Rule::SOURCE_SETTINGS );
		}
		foreach ( $this->constant_route_rules() as $key => $rule ) {
			$rules[ $key ] = new Effective_Rule( $this->resolve_rule( $rule, isset( $defaults[ $key ] ), $max_ttl ), Effective_Rule::SOURCE_CONSTANT );
		}

		$input   = [];
		$sources = [];
		foreach ( $rules as $key => $effective ) {
			$input[ $key ]   = $effective->rule->to_array();
			$sources[ $key ] = $effective->source;
		}
		/**
		 * Filters the effective route rules.
		 *
		 * @see Hooks::ROUTE_RULES
		 */
		$filtered = apply_filters( Hooks::ROUTE_RULES, $input, $sources, $routes ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		if ( ! is_array( $filtered ) ) {
			$problems[]          = sprintf( 'the %s filter returned %s instead of an array and was ignored', Hooks::ROUTE_RULES, gettype( $filtered ) );
			$this->rule_problems = $problems;
			return $rules;
		}

		$out = [];
		foreach ( $filtered as $key => $data ) {
			$key    = (string) $key;
			$rule   = is_array( $data ) ? Route_Rule::from_array( $data ) : null;
			$result = null !== $rule ? $rule->validate()->merge( Route_Rule::validate_key( $key, $rule->match ) ) : null;
			if ( null === $rule || null === $result || ! $result->is_valid() ) {
				// Fail closed: an invalid rule excludes the route instead of leaving the rule of the layer below.
				$problems[] = sprintf( '%s rule "%s": %s', Hooks::ROUTE_RULES, $key, null === $result ? 'The rule is not an array.' : self::first_error( $result ) );
				if ( isset( $rules[ $key ] ) ) {
					$out[ $key ] = new Effective_Rule( self::excluded( $rules[ $key ]->rule ), Effective_Rule::SOURCE_FILTER );
				}
				continue;
			}
			$rule = $this->resolve_rule( $rule, isset( $defaults[ $key ] ), $max_ttl );
			if ( isset( $rules[ $key ] ) && $rules[ $key ]->rule->to_array() === $rule->to_array() ) {
				$out[ $key ] = $rules[ $key ];
			} else {
				$out[ $key ] = new Effective_Rule( $rule, Effective_Rule::SOURCE_FILTER );
			}
		}
		$this->rule_problems = $problems;
		return $out;
	}

	/**
	 * The invalid route rules found by the last {@see self::route_rules()} call, one description each.
	 *
	 * The compiler records them once per compile ({@see Config_Compiler}), so a broken rule does not
	 * overwrite the last-error slot on every request.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function route_rule_problems(): array {
		return $this->rule_problems;
	}

	/**
	 * Resolve the `default` status and clamp the TTL of a rule.
	 *
	 * @param Route_Rule $rule       Rule.
	 * @param bool       $is_default Whether the route is cached by default.
	 * @param int        $max_ttl    Site maximum TTL.
	 *
	 * @return Route_Rule
	 */
	private function resolve_rule( Route_Rule $rule, bool $is_default, int $max_ttl ): Route_Rule {
		$rule = $rule->with_ttl( $this->clamp_rule_ttl( $rule->ttl, $max_ttl ) );
		if ( Route_Rule::STATUS_DEFAULT === $rule->status && $is_default ) {
			$data           = $rule->to_array();
			$data['status'] = Route_Rule::STATUS_ENABLED;
			return Route_Rule::from_array( $data );
		}
		return $rule;
	}

	/**
	 * Validate and persist one route rule. The rule replaces any stored rule for the key; fields
	 * that are missing take their default and the TTL is clamped to the site `max_ttl`.
	 *
	 * @param string       $key  Route key.
	 * @param array<mixed> $rule Rule data.
	 *
	 * @return Validation_Result
	 */
	public function save_route_rule( string $key, array $rule ): Validation_Result {
		return $this->save_route_rules( [ $key => $rule ] );
	}

	/**
	 * Validate and persist several route rules with a single `update_option()` call.
	 *
	 * A `null` value deletes the rule. The operation is atomic: when any entry is invalid or locked
	 * nothing is saved. A rule that would vary on more than {@see Route_Rule::MAX_VARY_HEADERS} request headers
	 * together with the effective global vary headers is invalid (`too_many_vary_headers` on `vary_headers`).
	 *
	 * @param array<string,mixed> $rules Route key => rule data array (or null to delete; anything else is rejected).
	 *
	 * @return Validation_Result Also invalid (`save_failed`) when the database write failed.
	 */
	public function save_route_rules( array $rules ): Validation_Result {
		$result = new Validation_Result();
		$raw    = $this->raw_settings();
		$stored = $this->stored_routes_raw();
		unset( $raw['routes'] );

		$settings = $this->settings();
		$changed  = $this->apply_route_rules( $rules, (int) $settings['max_ttl'], $stored, $result, self::strings( $settings['global_vary_headers'] ?? [] ) );

		if ( $result->is_valid() && $changed && ! $this->persist( $raw, $stored ) ) {
			$result->add_error( self::SAVE_FAILED, 'The route rules could not be saved to the database.' );
		}
		return $result;
	}

	/**
	 * Validate and persist scalar settings and route rules together with a single `update_option()` call.
	 *
	 * The settings and rule semantics are those of {@see self::save_settings()} and
	 * {@see self::save_route_rules()} (a `null` rule deletes it, rule TTLs are clamped), with these differences:
	 * the operation is atomic, so an invalid setting value, a setting controlled by a constant
	 * (`locked_setting`), an invalid rule or a route defined by `RFRAC_ROUTES` (`locked_route`) saves nothing;
	 * and the rule TTLs are clamped to the `max_ttl` that applies after the save (`RFRAC_MAX_TTL` still wins).
	 * The `routes` key of `$settings` is ignored. Input equal to what is stored writes nothing.
	 * The vary header limit is held as well, and breaking it saves nothing: a rule may not vary on more than
	 * {@see Route_Rule::MAX_VARY_HEADERS} request headers together with the global vary headers that apply after the save
	 * (`too_many_vary_headers` on `vary_headers`), and new global vary headers may not make a stored or `RFRAC_ROUTES`
	 * rule that this save leaves alone do so (`too_many_vary_headers` on `global_vary_headers`).
	 *
	 * @param array<mixed>        $settings Raw settings input.
	 * @param array<string,mixed> $rules    Route key => rule data array (or null to delete; anything else is rejected).
	 *
	 * @return Validation_Result Also invalid (`save_failed`) when the database write failed.
	 */
	public function save_settings_and_rules( array $settings, array $rules ): Validation_Result {
		$result = new Validation_Result();
		$raw    = $this->raw_settings();
		$stored = $this->stored_routes_raw();
		unset( $raw['routes'], $settings['routes'] );

		$overrides = $this->read_overrides();
		$locked    = array_values( array_diff( array_keys( $overrides['locked'] ), [ 'routes' ] ) );
		foreach ( $locked as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$result->add_error( 'locked_setting', sprintf( 'The setting "%s" is defined by %s.', $key, $overrides['locked'][ $key ] ), $key );
			}
		}

		$new     = Settings_Schema::apply( $settings, $raw, $result, $locked );
		$max_ttl = Settings_Schema::to_int( $overrides['values']['max_ttl'] ?? $new['max_ttl'] ) ?? Settings_Schema::ABSOLUTE_MAX_TTL;
		$before  = $stored;
		$this->apply_route_rules( $rules, $max_ttl, $stored, $result, self::strings( $overrides['values']['global_vary_headers'] ?? $new['global_vary_headers'] ?? [] ) );

		// A changed global list also has to fit the stored and `RFRAC_ROUTES` rules that this save leaves alone.
		$untouched = [];
		foreach ( array_merge( array_diff_key( $this->stored_route_rules(), $rules ), $this->constant_route_rules() ) as $key => $rule ) {
			$untouched[ $key ] = $rule->to_array();
		}
		$error = $this->vary_overflow( $new, $raw, $locked, $untouched );
		if ( null !== $error ) {
			$result->add_error( $error['code'], $error['message'], 'global_vary_headers' );
		}

		if ( $result->is_valid() && ( $new !== $raw || $stored !== $before || $this->adds_opt_in( $settings, $locked ) ) && ! $this->persist( $new, $stored, $settings, $locked ) ) {
			$result->add_error( self::SAVE_FAILED, 'The settings and route rules could not be saved to the database.' );
		}
		return $result;
	}

	/**
	 * Validate route rules against the stored ones, one entry at a time.
	 *
	 * Locked, mistyped and invalid entries add their errors to the result and are left out; a `null` value
	 * deletes the stored rule; a valid rule replaces the stored one with its TTL clamped to `$max_ttl`.
	 *
	 * @param array<string,mixed> $rules       Route key => rule data array (or null to delete).
	 * @param int                 $max_ttl     Site maximum TTL the rule TTLs are clamped to.
	 * @param array<string,mixed> $stored      Stored route rules as written; receives the accepted changes.
	 * @param Validation_Result   $result      Receives the errors.
	 * @param array<string>|null  $global_vary Global vary headers a valid rule has to fit with (see {@see self::vary_fit()}); null skips the check.
	 *
	 * @return bool Whether an entry changed, or was meant to change, the stored rules.
	 */
	private function apply_route_rules( array $rules, int $max_ttl, array &$stored, Validation_Result $result, ?array $global_vary = null ): bool {
		$changed = false;

		foreach ( $rules as $key => $data ) {
			$key = (string) $key;
			if ( $this->is_route_locked( $key ) ) {
				$result->add_error( 'locked_route', sprintf( 'The route "%s" is defined by %s.', $key, self::ROUTES_CONSTANT ), $key );
				continue;
			}
			if ( null === $data ) {
				if ( array_key_exists( $key, $stored ) ) {
					unset( $stored[ $key ] );
					$changed = true;
				}
				continue;
			}
			if ( ! is_array( $data ) ) {
				$result->add_error( 'invalid_type', sprintf( 'The rule for "%s" must be an array or null.', $key ), $key );
				continue;
			}

			$rule      = Route_Rule::from_array( $data );
			$rule      = $rule->with_ttl( $this->clamp_rule_ttl( $rule->ttl, $max_ttl ) );
			$validated = $rule->validate();
			$validated->merge( Route_Rule::validate_key( $key, $rule->match ) );
			if ( null !== $global_vary && $validated->is_valid() ) {
				foreach ( $this->vary_fit( $global_vary, [ $key => $rule->to_array() ] )->errors() as $error ) {
					$validated->add_error( $error['code'], $error['message'], 'vary_headers' );
				}
			}
			if ( ! $validated->is_valid() ) {
				$result->merge( $validated );
				continue;
			}

			$stored[ $key ] = $rule->to_array();
			$changed        = true;
		}

		return $changed;
	}

	/**
	 * Delete a stored route rule.
	 *
	 * @param string $key Route key.
	 *
	 * @return Validation_Result Invalid (`locked_route`) when the rule is defined by `RFRAC_ROUTES`.
	 */
	public function delete_route_rule( string $key ): Validation_Result {
		return $this->save_route_rules( [ $key => null ] );
	}

	/**
	 * Clamp a rule TTL into [60, max_ttl]; null stays null.
	 *
	 * @param int|null $ttl     TTL.
	 * @param int      $max_ttl Site maximum.
	 *
	 * @return int|null
	 */
	private function clamp_rule_ttl( ?int $ttl, int $max_ttl ): ?int {
		return null === $ttl ? null : max( Settings_Schema::MIN_TTL, min( $max_ttl, $ttl ) );
	}

	/**
	 * Whether the stored option holds a valid value for a setting.
	 *
	 * An opt-in setting such as `nonce_caching` (see {@see self::OPT_IN_KEYS}) is stored only once a site owner
	 * decided on it, so this tells a stored choice (`setting`) from the default.
	 *
	 * @param string $key Settings key.
	 *
	 * @return bool False for an absent key and for a stored value that is not valid.
	 */
	public function is_stored( string $key ): bool {
		$stored = get_option( Names::OPTION_SETTINGS, [] );
		return is_array( $stored )
			&& array_key_exists( $key, $stored )
			&& null !== Settings_Schema::normalize_value( $key, $stored[ $key ], Settings_Schema::ABSOLUTE_MAX_TTL );
	}

	/**
	 * Whether a save decides on an opt-in setting: the input carries a valid value for it and it is not locked.
	 *
	 * @param string       $key    Settings key.
	 * @param array<mixed> $input  Raw input of the save.
	 * @param string[]     $locked Settings keys that must not change.
	 * @phpstan-param list<string> $locked
	 *
	 * @return bool
	 */
	private function decides( string $key, array $input, array $locked ): bool {
		return ! in_array( $key, $locked, true )
			&& array_key_exists( $key, $input )
			&& null !== Settings_Schema::normalize_value( $key, $input[ $key ], Settings_Schema::ABSOLUTE_MAX_TTL );
	}

	/**
	 * Whether a save adds an opt-in setting to the stored option: it decides on a key the option does not hold yet.
	 *
	 * @param array<mixed> $input  Raw input of the save.
	 * @param string[]     $locked Settings keys that must not change.
	 * @phpstan-param list<string> $locked
	 *
	 * @return bool
	 */
	private function adds_opt_in( array $input, array $locked ): bool {
		foreach ( self::OPT_IN_KEYS as $key ) {
			if ( $this->decides( $key, $input, $locked ) && ! $this->is_stored( $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Drop the opt-in settings no one decided on: an opt-in key is kept when the stored option already holds it or
	 * the save's input carries a valid value for it, and a locked key is never kept from the input.
	 *
	 * @param array<string,mixed> $scalars Scalar settings.
	 * @param array<mixed>        $input   Raw input of the save; empty for a save that carries no settings.
	 * @param string[]            $locked  Settings keys that must not change.
	 * @phpstan-param list<string> $locked
	 *
	 * @return array<string,mixed>
	 */
	private function storable( array $scalars, array $input, array $locked ): array {
		foreach ( self::OPT_IN_KEYS as $key ) {
			if ( ! $this->is_stored( $key ) && ! $this->decides( $key, $input, $locked ) ) {
				unset( $scalars[ $key ] );
			}
		}
		return $scalars;
	}

	/**
	 * Write the settings option (never autoloaded).
	 *
	 * Every programmatic save path ({@see self::save_settings()}, {@see self::save_route_rules()} and
	 * {@see self::save_settings_and_rules()}) writes through here, so this is where an opt-in setting is dropped
	 * unless it was decided on (see {@see self::storable()}). The Settings API path does not write through here:
	 * {@see self::sanitize_settings()} applies `storable()` itself.
	 *
	 * `update_option()` returns false both when the write failed and when the value did not change,
	 * so a false result is checked against the stored value.
	 *
	 * @param array<string,mixed> $scalars Scalar settings.
	 * @param array<string,mixed> $routes  Route key => rule array.
	 * @param array<mixed>        $input   Raw settings input of the save; empty when the save carries none.
	 * @param string[]            $locked  Settings keys that must not change.
	 * @phpstan-param list<string> $locked
	 *
	 * @return bool Whether the option now holds the value (written, or already equal).
	 */
	private function persist( array $scalars, array $routes, array $input = [], array $locked = [] ): bool {
		$value            = array_merge( $this->storable( $scalars, $input, $locked ), [ 'routes' => $routes ] );
		self::$persisting = true;
		try {
			if ( update_option( Names::OPTION_SETTINGS, $value, false ) ) {
				return true;
			}
		} finally {
			self::$persisting = false;
		}
		$stored = get_option( Names::OPTION_SETTINGS );
		return $stored === $value || maybe_serialize( $stored ) === maybe_serialize( $value );
	}

	/**
	 * Read and validate all constants.
	 *
	 * @return array{values:array<string,mixed>,locked:array<string,string>,errors:array<string,string>,routes:array<string,Route_Rule>,compile:array{allow_skip_cache:?bool,bypass_cookies:list<string>,bypass_headers:list<string>,cors_wildcard:?bool,disable_mu_install:?bool}}
	 */
	private function read_overrides(): array {
		$values = [];
		$locked = [];
		$errors = [];

		$fallbacks = null === self::$fallback ? [] : self::$fallback->values();

		// The max_ttl goes first: the default_ttl is clamped against it.
		$names = Settings_Schema::constant_names();
		$names = array_merge( [ 'max_ttl' => $names['max_ttl'] ], $names );
		foreach ( $names as $key => $constant ) {
			// What the key is locked by, and what an error is reported under: the constant, or the label of its fallback.
			$label = $constant;
			if ( defined( $constant ) ) {
				$raw = constant( $constant );
			} elseif ( 'enabled' !== $key && isset( $fallbacks[ $key ] ) ) {
				$raw   = $fallbacks[ $key ]['value'];
				$label = $fallbacks[ $key ]['label'];
			} else {
				continue;
			}

			if ( 'enabled' === $key ) {
				// RFRAC_DISABLE: truthy disables caching; falsy does not lock anything.
				$disable = Settings_Schema::to_bool( $raw );
				if ( null === $disable ) {
					$errors[ $constant ] = 'Expected a boolean.';
				} elseif ( $disable ) {
					$values['enabled'] = false;
					$locked['enabled'] = $constant;
				}
				continue;
			}

			$max_ttl = (int) ( $values['max_ttl'] ?? Settings_Schema::resolve( get_option( Names::OPTION_SETTINGS, [] ) )['max_ttl'] );
			$value   = Settings_Schema::normalize_value( $key, $raw, $max_ttl );
			if ( null === $value ) {
				$definition = Settings_Schema::definitions()[ $key ] ?? [];
				if ( 'list' === ( $definition['type'] ?? '' ) ) {
					$errors[ $label ] = trim(
						sprintf(
							'Expected a list of %s. %s',
							Settings_Schema::ITEM_HEADER === ( $definition['item'] ?? '' ) ? 'request header names' : 'query parameter names',
							Settings_Schema::list_error( $key, $raw ) ?? ''
						)
					);
					if ( $label !== $constant ) {
						// The compiler looks for the error of a list under the RFRAC constant (the global vary list fails closed
						// on it), so a fallback's error is also kept there, naming the constant that was actually defined.
						$errors[ $constant ] = sprintf( 'The fallback %s is invalid. %s', $label, $errors[ $label ] );
					}
				} elseif ( 'bool' === ( $definition['type'] ?? '' ) ) {
					$errors[ $label ] = 'Expected a boolean.';
				} else {
					$errors[ $label ] = 'enum' === ( $definition['type'] ?? '' )
						? 'Expected one of: ' . implode( ', ', Settings_Schema::STORE_MODES ) . '.'
						: 'Expected an integer.';
				}
				continue;
			}
			$values[ $key ] = $value;
			$locked[ $key ] = $label;
		}

		$compile = [
			'allow_skip_cache'   => $this->read_bool_constant( 'RFRAC_ALLOW_SKIP_CACHE', $errors ),
			'bypass_cookies'     => [],
			'bypass_headers'     => [],
			'cors_wildcard'      => $this->read_bool_constant( 'RFRAC_CORS_WILDCARD', $errors ),
			'disable_mu_install' => $this->read_bool_constant( 'RFRAC_DISABLE_MU_INSTALL', $errors ),
		];
		// One invalid entry never removes the valid ones: dropping a credential bypass would fail open.
		if ( defined( 'RFRAC_BYPASS_COOKIES' ) ) {
			$compile['bypass_cookies'] = $this->read_list_constant(
				'RFRAC_BYPASS_COOKIES',
				[ Config_Compiler::class, 'normalize_cookie_prefix' ],
				'cookie name prefixes (letters, digits and !#$&\'*+-.^_`|~, at most 128 characters)',
				$errors
			);
		}
		if ( defined( 'RFRAC_BYPASS_HEADERS' ) ) {
			$compile['bypass_headers'] = $this->read_list_constant(
				'RFRAC_BYPASS_HEADERS',
				[ Config_Compiler::class, 'normalize_header_name' ],
				'header names (letters, digits, dashes and underscores, at most 64 characters)',
				$errors
			);
		}

		$max_ttl = (int) ( $values['max_ttl'] ?? Settings_Schema::resolve( get_option( Names::OPTION_SETTINGS, [] ) )['max_ttl'] );
		$routes  = $this->read_routes_constant( $errors, $max_ttl );
		if ( [] !== $routes ) {
			$locked['routes'] = self::ROUTES_CONSTANT;
		}

		return [
			'values'  => $values,
			'locked'  => $locked,
			'errors'  => $errors,
			'routes'  => $routes,
			'compile' => $compile,
		];
	}

	/**
	 * Read a list constant: keep the valid entries, report the invalid ones.
	 *
	 * @param string               $constant  Constant name.
	 * @param callable             $normalize Returns the normalised entry, or null when it is invalid.
	 * @param string               $expected  What the entries must be, for the error.
	 * @param array<string,string> $errors    Receives an error when the value or an entry is invalid.
	 *
	 * @return list<string> The valid, normalised, unique entries.
	 */
	private function read_list_constant( string $constant, callable $normalize, string $expected, array &$errors ): array {
		$raw = constant( $constant );
		if ( ! is_array( $raw ) ) {
			$errors[ $constant ] = 'Expected a list of ' . $expected . '.';
			return [];
		}
		$valid   = [];
		$invalid = [];
		foreach ( $raw as $entry ) {
			$normalized = $normalize( $entry );
			if ( is_string( $normalized ) ) {
				$valid[] = $normalized;
			} else {
				$invalid[] = is_scalar( $entry ) ? '"' . (string) $entry . '"' : gettype( $entry );
			}
		}
		if ( [] !== $invalid ) {
			$errors[ $constant ] = 'Ignored the invalid entries ' . implode( ', ', $invalid ) . '; expected ' . $expected . '.';
		}
		return array_values( array_unique( $valid ) );
	}

	/**
	 * Read a boolean constant.
	 *
	 * @param string               $constant Constant name.
	 * @param array<string,string> $errors   Receives an error when the value is invalid.
	 *
	 * @return bool|null Null when not defined or invalid.
	 */
	private function read_bool_constant( string $constant, array &$errors ): ?bool {
		if ( ! defined( $constant ) ) {
			return null;
		}
		$value = Settings_Schema::to_bool( constant( $constant ) );
		if ( null === $value ) {
			$errors[ $constant ] = 'Expected a boolean.';
		}
		return $value;
	}

	/**
	 * Read `RFRAC_ROUTES`.
	 *
	 * @param array<string,string> $errors  Receives the problems.
	 * @param int                  $max_ttl Effective site maximum TTL.
	 *
	 * @return array<string,Route_Rule>
	 */
	private function read_routes_constant( array &$errors, int $max_ttl ): array {
		if ( ! defined( self::ROUTES_CONSTANT ) ) {
			return [];
		}
		$raw = constant( self::ROUTES_CONSTANT );
		if ( ! is_array( $raw ) ) {
			$errors[ self::ROUTES_CONSTANT ] = 'Expected an array of route key => rule.';
			return [];
		}

		$out = [];
		foreach ( $raw as $key => $data ) {
			$key = (string) $key;
			if ( ! is_array( $data ) ) {
				$errors[ self::ROUTES_CONSTANT . '[' . $key . ']' ] = 'Expected a rule array.';
				continue;
			}
			$rule      = Route_Rule::from_array( $data );
			$validated = $rule->validate()->merge( Route_Rule::validate_key( $key, $rule->match ) );
			if ( ! $validated->is_valid() ) {
				$errors[ self::ROUTES_CONSTANT . '[' . $key . ']' ] = implode( ' ', array_column( $validated->errors(), 'message' ) );
				continue;
			}
			$out[ $key ] = $rule->with_ttl( $this->clamp_rule_ttl( $rule->ttl, $max_ttl ) );
		}
		return $out;
	}
}
