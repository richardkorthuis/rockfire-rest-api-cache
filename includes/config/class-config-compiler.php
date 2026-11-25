<?php
/**
 * Compiles settings, constants and the registered REST routes into `rfrac_config`.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Config;

use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Api;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Request\Cache_Policy;
use Rockfire\Rest_Api_Cache\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the autoloaded `rfrac_config` snapshot read by the request pipeline.
 *
 * The snapshot is rewritten only when it changed. A snapshot compiled without a REST server has no
 * routes and is flagged `pending_discovery`; {@see self::maybe_recompile_for_server()} completes it
 * on the first request that has a server. Its fingerprint is an md5 of everything the snapshot
 * contains (settings, constants and filter results, REST prefix, charset, CORS origins, the route
 * list).
 *
 * Route discovery is expensive, so a REST request only rebuilds a snapshot that is missing, pending
 * discovery or marked stale (empty fingerprint). Settings saves recompile directly; plugin
 * (de)activation, theme switches and upgrades call {@see self::mark_stale()}. Anything else
 * (a changed constant or filter, a plugin updated by hand) is picked up by the periodic check: at
 * most once per {@see self::CHECK_INTERVAL} seconds an anonymous GET compares the fingerprint.
 *
 * @since 1.0.0
 */
class Config_Compiler {

	/**
	 * Default universal query parameters.
	 *
	 * @var list<string>
	 */
	public const UNIVERSAL_PARAMS = [ '_fields', '_embed', '_locale', 'lang' ];

	/**
	 * Default cookie name prefixes that bypass the cache.
	 *
	 * @var list<string>
	 */
	public const BYPASS_COOKIE_PREFIXES = [ 'wordpress_logged_in_', 'wordpress_sec_', 'wp-postpass_', 'wp_woocommerce_session_' ];

	/**
	 * A valid cookie name prefix: 1-128 characters of an RFC 6265 cookie name (an HTTP token), without
	 * `%` (a percent-encoded name is not a reliable prefix to match).
	 *
	 * @var string
	 */
	public const COOKIE_PREFIX_PATTERN = '/^[A-Za-z0-9!#$&\'*+\-.^_`|~]{1,128}$/D';

	/**
	 * Default request headers that carry credentials and bypass the cache.
	 *
	 * @var list<string>
	 */
	public const BYPASS_HEADERS = [ 'x-api-key', 'x-auth-token', 'x-access-token' ];

	/**
	 * Transient that rate-limits the periodic fingerprint check.
	 *
	 * @var string
	 */
	public const CHECK_TRANSIENT = 'rfrac_config_checked';

	/**
	 * Minimum number of seconds between two periodic fingerprint checks.
	 *
	 * @var int
	 */
	public const CHECK_INTERVAL = 300;

	/**
	 * Default response headers replayed on a hit.
	 *
	 * @var list<string>
	 */
	public const REPLAY_HEADERS = [ 'x-wp-total', 'x-wp-totalpages', 'link', 'allow', 'content-language' ];

	/**
	 * Response headers that are never replayed.
	 *
	 * @var list<string>
	 */
	public const FORBIDDEN_REPLAY_HEADERS = [
		'set-cookie',
		'cache-control',
		'authorization',
		'www-authenticate',
		'proxy-authenticate',
		'proxy-authorization',
		'expires',
		'pragma',
		'access-control-allow-origin',
		'access-control-allow-credentials',
		'vary',
		'content-length',
		'transfer-encoding',
		'connection',
		'etag',
		'age',
		'x-wp-nonce',
	];

	/**
	 * Settings and constants access.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Invalid route rules found by the last build.
	 *
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $route_problems = [];

	/**
	 * Request methods a rule asked for that the last build dropped (the route has no handler for them, or it is a core route).
	 *
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $method_problems = [];

	/**
	 * Invalid vary declarations (the `rfrac/route_vary_cookies` filter) found by the last build.
	 *
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $vary_problems = [];

	/**
	 * Why the global vary headers of the last build are unusable, or null when they are usable. Unlike the other
	 * problems it stops caching on every route, so {@see self::record_problems()} reports it first.
	 *
	 * @var string|null
	 */
	private ?string $global_vary_problem = null;

	/**
	 * Global ignored parameters that could not be applied (the constant, the filter, the per-route limit) in the last build.
	 *
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $global_problems = [];

	/**
	 * Invalid bypass entries (constants and filters) found by the last build.
	 *
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $bypass_problems = [];

	/**
	 * The global ignored parameters of the last build: the setting or constant with the filter applied.
	 *
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $global_ignore = [];

	/**
	 * The global vary headers of the last build: the setting or constant with the filter applied, or null
	 * when that list is unusable, which makes every route fail closed.
	 *
	 * @var string[]|null
	 * @phpstan-var list<string>|null
	 */
	private ?array $global_vary = [];

	/**
	 * Flushes every entry of the current site; called when the site's home host changed.
	 *
	 * @var \Closure
	 */
	private \Closure $flush_site;

	/**
	 * Constructor.
	 *
	 * @param Config|null   $config     Settings access; defaults to the shared instance.
	 * @param \Closure|null $flush_site Flushes the current site when the compiled home host changed; defaults to
	 *                                  the invalidator's `flush_all()`, a no-op while the API is not registered.
	 */
	public function __construct( ?Config $config = null, ?\Closure $flush_site = null ) {
		$this->config     = $config ?? Config::instance();
		$this->flush_site = $flush_site ?? static function (): void {
			Api::get()?->invalidator()->flush_all();
		};
	}

	/**
	 * Compile and save the configuration.
	 *
	 * Without a server the routes are left empty and `pending_discovery` is set. Fires
	 * {@see Hooks::COMPILED} with the compiled configuration once the snapshot is saved.
	 *
	 * This never applies the {@see Hooks::PARTIAL_SERVER} filter: the caller decides whether the server
	 * is complete enough. A settings save goes through {@see self::compile_unless_partial()}, a live
	 * request through {@see self::maybe_recompile_for_server()}.
	 *
	 * @param \WP_REST_Server|null $server REST server with its routes registered.
	 *
	 * @return Compiled_Config The compiled configuration.
	 */
	public function compile( ?\WP_REST_Server $server = null ): Compiled_Config {
		$data = $this->seal( $this->build( $server ), $server );
		$this->record_problems();
		$this->save( $data );
		$config = Compiled_Config::from_array( $data );
		$this->announce( $config );
		return $config;
	}

	/**
	 * Compile from a server unless the {@see Hooks::PARTIAL_SERVER} filter marks it as partial.
	 *
	 * When the filter returns the strict boolean `true`, the server only registered some routes: nothing
	 * is built or saved, a current snapshot keeps serving and is marked stale
	 * ({@see self::mark_stale()}), and a missing one stays missing, so the next request with a full
	 * server rebuilds it with the current settings. A current snapshot keeps serving the settings it was
	 * compiled with, so the skip is recorded as a clearable `compile` failure (cleared by the next
	 * successful rebuild or compile, see {@see Failure_Recorder::clear()}) for the Status and Site Health
	 * screens; a missing or pending snapshot serves nothing and records nothing. Otherwise this is
	 * {@see self::compile()}, which also clears a recorded `compile` failure.
	 *
	 * @param \WP_REST_Server $server REST server with its routes registered.
	 * @param string          $route  Request route with a leading slash, `''` when unknown; passed to
	 *                                the {@see Hooks::PARTIAL_SERVER} filter.
	 *
	 * @return bool Whether the configuration was compiled.
	 */
	public function compile_unless_partial( \WP_REST_Server $server, string $route = '' ): bool {
		/**
		 * Filters whether this request's REST server registered only some routes.
		 *
		 * @see Hooks::PARTIAL_SERVER
		 */
		if ( true === apply_filters( Hooks::PARTIAL_SERVER, false, $server, $route ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
			$serving = Compiled_Config::load();
			$this->mark_stale();
			if ( null !== $serving && ! $serving->pending_discovery() ) {
				Failure_Recorder::record(
					'compile',
					'The saved settings were not compiled: this request\'s REST server is partial, so the previous cache configuration keeps serving until a request with a full REST server rebuilds it.'
				);
			}
			return false;
		}

		$this->compile( $server );
		Failure_Recorder::clear( [ 'compile' ] );
		return true;
	}

	/**
	 * Fingerprint of everything the snapshot contains.
	 *
	 * An md5 of the canonical JSON of the snapshot without the fingerprint itself: the effective
	 * settings and constants, the filtered lists, the REST prefix, the charset, the CORS origins and
	 * the compiled route list.
	 *
	 * @param \WP_REST_Server $server REST server.
	 *
	 * @return string
	 */
	public function fingerprint( \WP_REST_Server $server ): string {
		return $this->hash( $this->build( $server ) );
	}

	/**
	 * Recompile when the stored snapshot is missing, pending discovery or marked stale.
	 *
	 * A current snapshot is left alone, without building anything, unless `$periodic` is set and the
	 * last periodic check is older than {@see self::CHECK_INTERVAL}: then it is rebuilt and saved
	 * when its fingerprint changed. {@see Hooks::COMPILED} fires only when the snapshot was saved.
	 *
	 * Whenever a recompile is due, the {@see Hooks::PARTIAL_SERVER} filter is asked first: when it
	 * returns the strict boolean `true`, this request's server only registered some routes and the
	 * recompile is skipped altogether (nothing is built, saved or recorded, and the periodic check is
	 * not consumed). {@see self::compile()} never applies the filter; a settings save applies it through
	 * {@see self::compile_unless_partial()}.
	 *
	 * The rebuild keeps the stored home host, so a request cannot re-point it (and flush the site) by sending a
	 * Host header; only a stale rebuild for a trusted request ({@see self::is_trusted_request()}) derives it fresh.
	 *
	 * @param \WP_REST_Server $server   REST server with its routes registered.
	 * @param bool            $periodic Whether this request may run the rate-limited fingerprint check
	 *                                  (the caller passes true only for anonymous GET requests).
	 * @param string          $route    Request route with a leading slash, `''` when unknown; passed to
	 *                                  the {@see Hooks::PARTIAL_SERVER} filter.
	 *
	 * @return bool Whether the configuration was recompiled.
	 */
	public function maybe_recompile_for_server( \WP_REST_Server $server, bool $periodic = false, string $route = '' ): bool {
		$current = Compiled_Config::load();
		$stale   = null === $current || $current->pending_discovery() || '' === $current->fingerprint();
		if ( ! $stale && ( ! $periodic || false !== get_transient( self::CHECK_TRANSIENT ) ) ) {
			return false;
		}

		/**
		 * Filters whether this request's REST server registered only some routes.
		 *
		 * @see Hooks::PARTIAL_SERVER
		 */
		if ( true === apply_filters( Hooks::PARTIAL_SERVER, false, $server, $route ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
			return false;
		}

		if ( ! $stale ) {
			set_transient( self::CHECK_TRANSIENT, time(), self::CHECK_INTERVAL );
		}

		$built = $this->build( $server );
		// A request must not re-point the home host: on a site whose `WP_HOME` follows `HTTP_HOST` the freshly derived host
		// is the requester's. So a rebuild keeps the stored host, whether the snapshot is stale or only checked
		// periodically. Only a stale rebuild made for a trusted request derives it fresh, as do a missing snapshot, one
		// that carries no host yet and every compile() (a settings save, a migration, an activation).
		if ( null !== $current && '' !== $current->home_host() && ! ( $stale && $this->is_trusted_request() ) ) {
			$built['home_host'] = $current->home_host();
		}
		$data = $this->seal( $built, $server );
		if ( ! $stale && $current->fingerprint() === $data['fingerprint'] ) {
			return false;
		}
		$this->record_problems();
		$this->save( $data );
		$this->announce( Compiled_Config::from_array( $data ) );
		return true;
	}

	/**
	 * Whether the current request may re-point the compiled home host.
	 *
	 * Only a request whose host is not attacker input: WP-CLI (no `HTTP_HOST`) or a user who can change the site's
	 * settings, who is the one changing the Site Address. Any other request, including a cron one that an anonymous
	 * hit on `wp-cron.php` can start under a Host of its own choosing, keeps the stored host.
	 *
	 * @return bool
	 */
	private function is_trusted_request(): bool {
		return ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( 'manage_options' );
	}

	/**
	 * Build the snapshot, without its fingerprint.
	 *
	 * @param \WP_REST_Server|null $server REST server with its routes registered.
	 *
	 * @return array<string,mixed>
	 */
	private function build( ?\WP_REST_Server $server ): array {
		$settings  = $this->config->settings();
		$store     = $this->resolve_store( (string) $settings['store'] );
		$origins   = $this->origins();
		$prefix    = rest_get_url_prefix();
		$charset   = (string) get_bloginfo( 'charset' );
		$universal = $this->universal_params();

		$this->route_problems      = [];
		$this->method_problems     = [];
		$this->vary_problems       = [];
		$this->global_vary_problem = null;
		$this->global_problems     = [];
		$this->bypass_problems     = [];
		foreach ( [ 'RFRAC_BYPASS_COOKIES', 'RFRAC_BYPASS_HEADERS' ] as $constant ) {
			$error = $this->config->constant_errors()[ $constant ] ?? null;
			if ( null !== $error ) {
				$this->bypass_problems[] = $constant . ': ' . $error;
			}
		}

		// Resolved once per compile, never per route or request.
		$this->global_ignore = $this->global_ignore_params( $settings['global_ignore_params'] ?? [] );
		$this->global_vary   = $this->global_vary_headers( $settings['global_vary_headers'] ?? [] );

		$routes = [];
		if ( null !== $server ) {
			$infos                = ( new Route_Discovery() )->discover( $server );
			$rules                = $this->config->route_rules( $server );
			$this->route_problems = $this->config->route_rule_problems();
			$routes               = $this->compile_routes( $infos, $rules, $settings, $universal );
		}

		$overrides = $this->config->compile_overrides();
		if ( null !== $overrides['allow_skip_cache'] ) {
			$allow_skip_cache = $overrides['allow_skip_cache'];
		} else {
			/**
			 * Filters whether `skip_cache` is honoured.
			 *
			 * @see Hooks::ALLOW_SKIP_CACHE
			 */
			$allow_skip_cache = (bool) apply_filters( Hooks::ALLOW_SKIP_CACHE, false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		}

		$data = [
			'format'                 => Compiled_Config::FORMAT,
			'enabled'                => (bool) $settings['enabled'],
			'store_mode'             => $store,
			'rest_prefix'            => $prefix,
			'charset'                => $charset,
			'default_ttl'            => (int) $settings['default_ttl'],
			'max_ttl'                => (int) $settings['max_ttl'],
			'max_entries'            => (int) $settings['max_entries'],
			'max_payload_bytes'      => (int) $settings['max_payload_kb'] * 1024,
			'max_params'             => (int) $settings['max_params'],
			'hit_sampling'           => (int) $settings['hit_sampling'],
			'universal_params'       => $universal,
			'bypass_cookie_prefixes' => $this->bypass_cookie_prefixes( $overrides['bypass_cookies'] ),
			'bypass_headers'         => $this->bypass_headers( $overrides['bypass_headers'] ),
			'cors_origins'           => $origins,
			'cors_wildcard'          => $overrides['cors_wildcard'] ?? true,
			'replay_headers'         => $this->replay_headers(),
			'allow_skip_cache'       => $allow_skip_cache,
			'schema_version'         => Schema::VERSION,
			'pending_discovery'      => null === $server,
			'routes'                 => array_map(
				static fn( Compiled_Route $route ): array => $route->to_array(),
				$routes
			),
		];

		// The name-free flags are written only when a filter moves them off their default, so a compile without
		// one yields the same snapshot and fingerprint as before.
		/**
		 * Filters whether a cache hit carries the `Access-Control-Allow-*` headers.
		 *
		 * @see Hooks::CORS_ON_HITS
		 */
		if ( false === apply_filters( Hooks::CORS_ON_HITS, true ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
			$data['cors_on_hits'] = false;
		}
		/**
		 * Filters whether every request bypasses the cache.
		 *
		 * @see Hooks::BYPASS_ALL
		 */
		if ( true === apply_filters( Hooks::BYPASS_ALL, false ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
			$data['bypass_all'] = true;
		}
		// The value follows constant > stored setting > default; the source says which of them it is.
		if ( $this->config->is_locked( 'nonce_caching' ) ) {
			$nonce_source = 'constant';
		} else {
			$nonce_source = $this->config->is_stored( 'nonce_caching' ) ? 'setting' : 'default';
		}
		$nonce_value = 'default' !== $nonce_source && true === ( $settings['nonce_caching'] ?? false );
		/**
		 * Filters whether a logged-out request that only carries a REST nonce may be cached.
		 *
		 * @see Hooks::NONCE_CACHING
		 */
		if ( true === apply_filters( Hooks::NONCE_CACHING, $nonce_value, $nonce_source ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
			$data['nonce_caching'] = true;
		}
		// The site's live login cookie names, so a customised name is recognised without any filterable list.
		$login_cookies = self::login_cookie_names( $this->defined_login_cookies() );
		if ( [] !== $login_cookies ) {
			$data['login_cookies'] = $login_cookies;
		}
		// Last, so that overwriting it later (see maybe_recompile_for_server()) keeps the key order and the fingerprint.
		$home_host = self::home_host( home_url() );
		if ( '' !== $home_host ) {
			$data['home_host'] = $home_host;
		}

		return $data;
	}

	/**
	 * The canonical host of a URL: lower-case, with `:port` only when the URL names a port that is not the default of its scheme.
	 *
	 * @param string $url URL, normally `home_url()`.
	 *
	 * @return string `''` when the host cannot be parsed.
	 */
	private static function home_host( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$host = strtolower( $parts['host'] ?? '' );
		if ( '' === $host ) {
			return '';
		}
		$port    = $parts['port'] ?? 0;
		$scheme  = strtolower( $parts['scheme'] ?? '' );
		$default = 'https' === $scheme ? 443 : ( 'http' === $scheme ? 80 : 0 );
		if ( $port > 0 && $default !== $port ) {
			$host .= ':' . $port;
		}
		return $host;
	}

	/**
	 * The names of the login cookies this site defines (`LOGGED_IN_COOKIE`, `SECURE_AUTH_COOKIE`, `AUTH_COOKIE`).
	 *
	 * Protected only so that a test can stand in for the constants, which WordPress fixes at bootstrap.
	 *
	 * @return list<string>
	 */
	protected function defined_login_cookies(): array {
		$names = [];
		foreach ( [ 'LOGGED_IN_COOKIE', 'SECURE_AUTH_COOKIE', 'AUTH_COOKIE' ] as $constant ) {
			$value = defined( $constant ) ? constant( $constant ) : null;
			if ( is_string( $value ) ) {
				$names[] = $value;
			}
		}
		return $names;
	}

	/**
	 * The login cookie names to compile: the given names as PHP exposes them in `$_COOKIE` (a `.` or space becomes `_`,
	 * and only the text before a `[` is the key), without those an unfilterable bypass rule already covers
	 * ({@see Cache_Policy::is_unfilterable_login_cookie()}), sorted and unique. The default WordPress names yield none.
	 *
	 * @param array<mixed> $names Cookie names, as defined by the site.
	 *
	 * @return list<string>
	 */
	public static function login_cookie_names( array $names ): array {
		$out = [];
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			$name = str_replace( [ '.', ' ' ], '_', explode( '[', $name, 2 )[0] );
			if ( '' !== $name && ! Cache_Policy::is_unfilterable_login_cookie( $name ) ) {
				$out[] = $name;
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Add the fingerprint to a built snapshot; it is empty without a server.
	 *
	 * @param array<string,mixed>  $data   Snapshot without fingerprint.
	 * @param \WP_REST_Server|null $server REST server the snapshot was built with.
	 *
	 * @return array<string,mixed>
	 */
	private function seal( array $data, ?\WP_REST_Server $server ): array {
		$data['fingerprint'] = null === $server ? '' : $this->hash( $data );
		return $data;
	}

	/**
	 * Hash of a snapshot without its fingerprint.
	 *
	 * @param array<string,mixed> $data Snapshot without fingerprint.
	 *
	 * @return string
	 */
	private function hash( array $data ): string {
		return md5( (string) wp_json_encode( $data ) );
	}

	/**
	 * Invalidate the fingerprint so that the next {@see self::maybe_recompile_for_server()} recompiles.
	 * The compiled routes are left untouched.
	 *
	 * @return void
	 */
	public function mark_stale(): void {
		$stored = get_option( Names::OPTION_CONFIG );
		if ( is_array( $stored ) && '' !== ( $stored['fingerprint'] ?? '' ) ) {
			$stored['fingerprint'] = '';
			update_option( Names::OPTION_CONFIG, $stored, true );
		}
	}

	/**
	 * Compile the rules into routes, mirroring the dispatch order of core.
	 *
	 * Every discovered, non-denied registered route comes first in registration order: enabled ones
	 * as full entries, all others as negative entries, so a not-enabled route that WordPress would
	 * dispatch to is never overtaken by a later overlapping rule. Wildcards follow: excluded ones as
	 * negative entries, then enabled ones.
	 *
	 * An enabled route is compiled for the methods of its rule that it registers (see {@see self::route_methods()});
	 * with none left it is a negative entry. Its allowed parameters are those of the compiled methods and the
	 * universal ones. A wildcard is compiled for GET only.
	 *
	 * @param array<string,Route_Info>     $infos     Discovered routes, in registration order.
	 * @param array<string,Effective_Rule> $rules     Effective rules by key.
	 * @param array<string,mixed>          $settings  Effective settings.
	 * @param string[]                     $universal Universal query parameters.
	 * @phpstan-param list<string> $universal
	 *
	 * @return list<Compiled_Route>
	 */
	private function compile_routes( array $infos, array $rules, array $settings, array $universal ): array {
		$ordered           = [];
		$negative_wildcard = [];
		$enabled_wildcard  = [];

		foreach ( $rules as $key => $effective ) {
			$rule = $effective->rule;
			if ( Route_Rule::MATCH_WILDCARD !== $rule->match || Deny_List::is_wildcard_denied( $key ) ) {
				continue;
			}
			$regex = Deny_List::wildcard_regex( $key );
			if ( Route_Rule::STATUS_ENABLED === $rule->status ) {
				// A wildcard only ever caches GET: a rule that says otherwise is invalid and not enabled.
				$enabled_wildcard[] = $this->build_route( $key, $regex, $rule, $universal, $this->wildcard_registered( $regex, $infos, $universal ), $settings, Route_Rule::DEFAULT_METHODS );
			} else {
				$negative_wildcard[] = Compiled_Route::negative( $key, $regex );
			}
		}

		foreach ( $infos as $key => $info ) {
			$key = (string) $key;
			if ( $info->denied || Deny_List::is_denied( $key ) ) {
				continue;
			}
			$regex = '@^' . $key . '$@i';
			if ( ! $this->is_valid_regex( $regex ) ) {
				continue;
			}
			$effective = $rules[ $key ] ?? null;
			$rule      = null !== $effective ? $effective->rule : null;
			if ( null !== $rule && Route_Rule::MATCH_WILDCARD !== $rule->match && Route_Rule::STATUS_ENABLED === $rule->status ) {
				$methods = $this->route_methods( $key, $info, $rule );
				if ( [] === $methods ) {
					// Not one of the requested methods can be cached: the route is known, but not cached.
					$ordered[] = Compiled_Route::negative( $key, $regex );
					continue;
				}
				$args = [];
				foreach ( $methods as $method ) {
					$args = array_merge( $args, 'GET' === $method ? $info->get_args : ( $info->method_args[ $method ] ?? [] ) );
				}
				$registered = array_merge( $args, $universal );
				$ordered[]  = $this->build_route( $key, $regex, $rule, $registered, $registered, $settings, $methods );
			} else {
				$ordered[] = Compiled_Route::negative( $key, $regex );
			}
		}

		return array_merge( $ordered, $negative_wildcard, $enabled_wildcard );
	}

	/**
	 * The methods an enabled registered route is compiled for: those of the rule that the route can be cached for.
	 *
	 * GET is kept as the rule says. Any other method is dropped, and recorded, when the route has no handler for it
	 * or when it is a core `wp/v2` route, which is only ever cached for GET.
	 *
	 * @param string     $key  Route key.
	 * @param Route_Info $info The discovered route.
	 * @param Route_Rule $rule Effective rule.
	 *
	 * @return list<string> In {@see Route_Rule::METHODS} order; empty when none is left.
	 */
	private function route_methods( string $key, Route_Info $info, Route_Rule $rule ): array {
		$core = 'wp/v2' === $info->namespace || str_starts_with( $key, '/wp/v2' );
		$kept = [];
		foreach ( Route_Rule::METHODS as $method ) {
			if ( ! in_array( $method, $rule->methods, true ) ) {
				continue;
			}
			if ( 'GET' === $method ) {
				$kept[] = $method;
			} elseif ( $core ) {
				$this->method_problems[] = sprintf( 'the route "%s" is a core route, which is only cached for GET; %s was dropped', $key, $method );
			} elseif ( ! $info->registers( $method ) ) {
				$this->method_problems[] = sprintf( 'the route "%s" does not register %s; %s was dropped', $key, $method, $method );
			} else {
				$kept[] = $method;
			}
		}
		return $kept;
	}

	/**
	 * Build a compiled route.
	 *
	 * The global ignored parameters and vary headers of the build are layered under the rule's own lists:
	 * a global parameter the route registers (or that the rule makes a bypass parameter) is not ignored on it,
	 * and a merged vary list that is unusable or too long makes the route fail closed (`vary_invalid`, with
	 * the reason in `vary_cause`).
	 *
	 * @param string              $key        Route key.
	 * @param string              $regex      Complete pattern.
	 * @param Route_Rule          $rule       Effective rule.
	 * @param string[]            $allowed    Allowed query parameters (rule arguments plus universal ones).
	 * @phpstan-param list<string> $allowed
	 * @param string[]            $registered Query parameters the route registers: its own arguments and the universal
	 *                                        ones; for a wildcard those of every route it covers. A global ignored
	 *                                        parameter in this set is never ignored on the route.
	 * @phpstan-param list<string> $registered
	 * @param array<string,mixed> $settings   Effective settings.
	 * @param string[]            $methods    Methods the route is cached for (see {@see self::route_methods()}).
	 * @phpstan-param list<string> $methods
	 *
	 * @return Compiled_Route
	 */
	private function build_route( string $key, string $regex, Route_Rule $rule, array $allowed, array $registered, array $settings, array $methods ): Compiled_Route {
		$max_ttl = (int) $settings['max_ttl'];
		$ttl     = max( Settings_Schema::MIN_TTL, min( $max_ttl, $rule->ttl ?? (int) $settings['default_ttl'] ) );

		$allowed = array_values( array_unique( $allowed ) );
		sort( $allowed );

		$ignore_params = $this->merged_ignore_params( $key, $rule, $registered );
		$vary_headers  = $rule->vary_headers;
		$vary_conflict = null;
		if ( null === $this->global_vary ) {
			// The unusable list is already recorded, once, by the build.
			$vary_conflict = Compiled_Route::VARY_GLOBAL_HEADERS;
		} elseif ( [] !== $this->global_vary ) {
			$merged = array_values( array_unique( array_merge( $rule->vary_headers, $this->global_vary ) ) );
			if ( count( $merged ) > Route_Rule::MAX_VARY_HEADERS ) {
				$vary_conflict         = Compiled_Route::VARY_MERGED_HEADERS;
				$this->vary_problems[] = sprintf(
					'the route "%s" would vary on %d request headers with the global vary headers; at most %d are allowed',
					$key,
					count( $merged ),
					Route_Rule::MAX_VARY_HEADERS
				);
			} else {
				$vary_headers = $merged;
			}
		}

		/**
		 * Filters whether the responses of a route differ per visitor.
		 *
		 * @see Hooks::ROUTE_VARIES_PER_VISITOR
		 */
		$varies = (bool) apply_filters( Hooks::ROUTE_VARIES_PER_VISITOR, false, $key ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.

		/**
		 * Filters the cookies that vary the cache key of a route.
		 *
		 * @see Hooks::ROUTE_VARY_COOKIES
		 */
		$cookies = Vary_Cookies::normalize( apply_filters( Hooks::ROUTE_VARY_COOKIES, [], $key ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		$cause   = null;
		if ( null === $cookies ) {
			// Fails closed: an invalid declaration makes the route visitor-varying, so it is not cached.
			$cause                 = Compiled_Route::VARY_COOKIES;
			$varies                = true;
			$cookies               = [];
			$this->vary_problems[] = sprintf( 'the %s filter returned an invalid declaration for the route "%s"', Hooks::ROUTE_VARY_COOKIES, $key );
		}
		if ( null !== $vary_conflict ) {
			// Fails closed the same way: the route would vary on headers it can not key on, so it is not cached.
			// The site-wide header cause is the one reported when the cookie declaration is invalid as well.
			$cause  = $vary_conflict;
			$varies = true;
		}

		return new Compiled_Route(
			$key,
			$regex,
			$ttl,
			$rule->param_mode,
			$allowed,
			$ignore_params,
			$rule->bypass_params,
			$vary_headers,
			true,
			$varies,
			$cookies,
			$cause,
			$methods
		);
	}

	/**
	 * The ignored parameters of a route: the rule's own, then the global ones the route does not register and
	 * does not bypass on, up to {@see Route_Rule::MAX_PARAM_NAMES}. Global names past the limit are reported.
	 *
	 * @param string     $key        Route key.
	 * @param Route_Rule $rule       Effective rule.
	 * @param string[]   $registered Query parameters the route registers, universal ones included.
	 * @phpstan-param list<string> $registered
	 *
	 * @return list<string>
	 */
	private function merged_ignore_params( string $key, Route_Rule $rule, array $registered ): array {
		if ( [] === $this->global_ignore ) {
			return $rule->ignore_params;
		}

		$ignore  = array_values( array_unique( $rule->ignore_params ) );
		$taken   = array_flip( $ignore );
		$skip    = array_flip( array_merge( $registered, $rule->bypass_params ) );
		$skipped = [];
		foreach ( $this->global_ignore as $name ) {
			if ( isset( $skip[ $name ] ) || isset( $taken[ $name ] ) ) {
				continue;
			}
			if ( count( $ignore ) >= Route_Rule::MAX_PARAM_NAMES ) {
				$skipped[] = $name;
				continue;
			}
			$ignore[]       = $name;
			$taken[ $name ] = true;
		}
		if ( [] !== $skipped ) {
			$this->global_problems[] = sprintf(
				'the route "%s" can ignore at most %d parameters; skipped %s',
				$key,
				Route_Rule::MAX_PARAM_NAMES,
				implode( ', ', $skipped )
			);
		}
		return $ignore;
	}

	/**
	 * The query parameters an enabled wildcard registers: the universal ones and the arguments of every
	 * discovered, non-denied route its key matches.
	 *
	 * @param string                   $regex     Complete wildcard pattern.
	 * @param array<string,Route_Info> $infos     Discovered routes.
	 * @param string[]                 $universal Universal query parameters.
	 * @phpstan-param list<string> $universal
	 *
	 * @return list<string>
	 */
	private function wildcard_registered( string $regex, array $infos, array $universal ): array {
		if ( [] === $this->global_ignore ) {
			return $universal;
		}

		$registered = $universal;
		foreach ( $infos as $key => $info ) {
			$key = (string) $key;
			if ( $info->denied || Deny_List::is_denied( $key ) || 1 !== preg_match( $regex, $key ) ) {
				continue;
			}
			$registered = array_merge( $registered, $info->get_args );
		}
		return array_values( array_unique( $registered ) );
	}

	/**
	 * Whether a pattern compiles, without emitting a warning.
	 *
	 * @param string $regex Complete pattern.
	 *
	 * @return bool
	 */
	private function is_valid_regex( string $regex ): bool {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Silences the warning of compiling an untrusted pattern for one preg_match; restored in finally.
		set_error_handler( static fn() => true );
		try {
			return false !== preg_match( $regex, '' );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Resolve the `auto` store mode to the store that is actually used.
	 *
	 * @param string $store Configured store.
	 *
	 * @return string `database` or `object_cache`.
	 */
	private function resolve_store( string $store ): string {
		if ( 'auto' === $store ) {
			return wp_using_ext_object_cache() ? 'object_cache' : 'database';
		}
		return 'object_cache' === $store ? 'object_cache' : 'database';
	}

	/**
	 * The allowed CORS origins.
	 *
	 * @return list<string>
	 */
	private function origins(): array {
		return array_values( array_unique( array_filter( array_map( 'strval', get_allowed_http_origins() ), static fn( string $o ): bool => '' !== $o ) ) );
	}

	/**
	 * The universal query parameters.
	 *
	 * @return list<string>
	 */
	private function universal_params(): array {
		/**
		 * Filters the universal query parameters.
		 *
		 * @see Hooks::UNIVERSAL_PARAMS
		 */
		$params = apply_filters( Hooks::UNIVERSAL_PARAMS, self::UNIVERSAL_PARAMS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		return $this->clean_list( $params, Route_Rule::PARAM_NAME_PATTERN, false );
	}

	/**
	 * The global ignored query parameters: the setting (or its constant) with the
	 * {@see Hooks::GLOBAL_IGNORE_PARAMS} filter applied.
	 *
	 * Fails safe: a filter value that is not an array keeps the unfiltered list, an invalid entry is dropped
	 * without removing a valid one, and at most {@see Route_Rule::MAX_PARAM_NAMES} names are kept. Every
	 * problem, and an invalid `RFRAC_GLOBAL_IGNORE_PARAMS`, is collected for {@see self::record_problems()}.
	 *
	 * @param mixed $setting The effective `global_ignore_params` setting.
	 *
	 * @return list<string>
	 */
	private function global_ignore_params( mixed $setting ): array {
		$error = $this->config->constant_errors()['RFRAC_GLOBAL_IGNORE_PARAMS'] ?? null;
		if ( null !== $error ) {
			$this->global_problems[] = 'RFRAC_GLOBAL_IGNORE_PARAMS: ' . $error;
		}

		$unfiltered = is_array( $setting ) ? array_values( array_filter( $setting, 'is_string' ) ) : [];
		/**
		 * Filters the query parameters that never change the cache key on any route.
		 *
		 * @see Hooks::GLOBAL_IGNORE_PARAMS
		 */
		$params = apply_filters( Hooks::GLOBAL_IGNORE_PARAMS, $unfiltered ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		if ( ! is_array( $params ) ) {
			$this->global_problems[] = sprintf( 'the %s filter returned %s instead of an array; the unfiltered list is used', Hooks::GLOBAL_IGNORE_PARAMS, gettype( $params ) );
			$params                  = $unfiltered;
		}

		$out     = [];
		$invalid = [];
		foreach ( $params as $param ) {
			$name = is_string( $param ) ? trim( $param ) : null;
			if ( null !== $name && 1 === preg_match( Route_Rule::PARAM_NAME_PATTERN, $name ) ) {
				$out[] = $name;
			} else {
				$invalid[] = is_scalar( $param ) ? '"' . (string) $param . '"' : gettype( $param );
			}
		}
		if ( [] !== $invalid ) {
			$this->global_problems[] = sprintf( 'the %s filter returned the invalid entries %s', Hooks::GLOBAL_IGNORE_PARAMS, implode( ', ', $invalid ) );
		}

		$out = array_values( array_unique( $out ) );
		if ( count( $out ) > Route_Rule::MAX_PARAM_NAMES ) {
			$this->global_problems[] = sprintf(
				'at most %d global ignored parameters are allowed; skipped %s',
				Route_Rule::MAX_PARAM_NAMES,
				implode( ', ', array_slice( $out, Route_Rule::MAX_PARAM_NAMES ) )
			);
			$out                     = array_slice( $out, 0, Route_Rule::MAX_PARAM_NAMES );
		}
		return $out;
	}

	/**
	 * The global vary headers: the setting (or its constant) with the {@see Hooks::GLOBAL_VARY_HEADERS}
	 * filter applied.
	 *
	 * Fails closed: the list is unusable, and null is returned, when `RFRAC_GLOBAL_VARY_HEADERS` (or the constant that
	 * stands in for it, see {@see Config::set_fallback_constants()}) is invalid or the filter returns a value that is not an array, an entry that is not a header name, a header that can not vary
	 * the cache or more than {@see Route_Rule::MAX_VARY_HEADERS} headers. One problem is collected for
	 * {@see self::record_problems()}; every route then compiles as not cached.
	 *
	 * This is a deliberate exception to the settings precedence (valid constant > stored setting > default): an
	 * invalid `RFRAC_GLOBAL_VARY_HEADERS` is not set aside for the stored list, as the other constants are, because
	 * caching with a different list than the one the site asked for would serve one shared variant to visitors who
	 * should get their own. Caching stays off on every route until the constant is fixed.
	 *
	 * @param mixed $setting The effective `global_vary_headers` setting.
	 *
	 * @return list<string>|null The lowercase header names, null when the list is unusable.
	 */
	private function global_vary_headers( mixed $setting ): ?array {
		$reason = null;
		$error  = $this->config->constant_errors()['RFRAC_GLOBAL_VARY_HEADERS'] ?? null;
		if ( null !== $error ) {
			$reason = 'RFRAC_GLOBAL_VARY_HEADERS: ' . $error;
		}

		$unfiltered = is_array( $setting ) ? array_values( array_filter( $setting, 'is_string' ) ) : [];
		/**
		 * Filters the request headers that vary the cache key on every route.
		 *
		 * @see Hooks::GLOBAL_VARY_HEADERS
		 */
		$headers = apply_filters( Hooks::GLOBAL_VARY_HEADERS, $unfiltered ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.

		$out = [];
		if ( ! is_array( $headers ) ) {
			$reason ??= sprintf( 'the %s filter returned %s instead of an array', Hooks::GLOBAL_VARY_HEADERS, gettype( $headers ) );
		} else {
			foreach ( $headers as $header ) {
				$name = self::normalize_header_name( $header );
				if ( null === $name ) {
					$reason ??= sprintf( 'the %s filter returned the invalid entry %s', Hooks::GLOBAL_VARY_HEADERS, is_scalar( $header ) ? '"' . (string) $header . '"' : gettype( $header ) );
				} elseif ( in_array( $name, Route_Rule::FORBIDDEN_VARY_HEADERS, true ) ) {
					$reason ??= sprintf( 'the %s filter returned the header "%s", which can not be used to vary the cache', Hooks::GLOBAL_VARY_HEADERS, $name );
				} else {
					$out[] = $name;
				}
			}
			$out = array_values( array_unique( $out ) );
			if ( count( $out ) > Route_Rule::MAX_VARY_HEADERS ) {
				$reason ??= sprintf( 'the %s filter returned %d headers; at most %d are allowed', Hooks::GLOBAL_VARY_HEADERS, count( $out ), Route_Rule::MAX_VARY_HEADERS );
			}
		}

		if ( null !== $reason ) {
			$this->global_vary_problem = 'the global vary headers are invalid: ' . $reason;
			return null;
		}
		return $out;
	}

	/**
	 * The cookie prefixes that bypass the cache.
	 *
	 * @param string[] $from_constant Prefixes from `RFRAC_BYPASS_COOKIES`.
	 * @phpstan-param list<string> $from_constant
	 *
	 * @return list<string>
	 */
	private function bypass_cookie_prefixes( array $from_constant ): array {
		/**
		 * Filters the cookie prefixes that bypass the cache.
		 *
		 * @see Hooks::BYPASS_COOKIE_PREFIXES
		 */
		$defaults = array_merge( self::BYPASS_COOKIE_PREFIXES, $from_constant );
		$prefixes = apply_filters( Hooks::BYPASS_COOKIE_PREFIXES, $defaults ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		return $this->clean_bypass_list( $prefixes, $defaults, [ self::class, 'normalize_cookie_prefix' ], Hooks::BYPASS_COOKIE_PREFIXES );
	}

	/**
	 * Normalise a filtered bypass list. Fails safe: a value that is not an array, or a list with only
	 * invalid entries, keeps the defaults; one invalid entry never removes a valid one. Every problem
	 * is collected for {@see self::record_problems()}.
	 *
	 * @param mixed    $filtered  Value returned by the filter.
	 * @param string[] $defaults  Unfiltered list (defaults plus the constant's valid entries).
	 * @param callable $normalize Returns the normalised entry, or null when it is invalid.
	 * @param string   $hook      Filter name, for the problem description.
	 * @phpstan-param list<string> $defaults
	 *
	 * @return list<string>
	 */
	private function clean_bypass_list( mixed $filtered, array $defaults, callable $normalize, string $hook ): array {
		if ( ! is_array( $filtered ) ) {
			$this->bypass_problems[] = sprintf( 'the %s filter returned %s instead of an array; the defaults are used', $hook, gettype( $filtered ) );
			$filtered                = $defaults;
		}

		$out     = [];
		$invalid = [];
		foreach ( $filtered as $entry ) {
			$normalized = $normalize( $entry );
			if ( is_string( $normalized ) ) {
				$out[] = $normalized;
			} else {
				$invalid[] = is_scalar( $entry ) ? '"' . (string) $entry . '"' : gettype( $entry );
			}
		}
		if ( [] !== $invalid ) {
			$this->bypass_problems[] = sprintf( 'the %s filter returned the invalid entries %s', $hook, implode( ', ', $invalid ) );
		}
		if ( [] === $out && [] !== $filtered ) {
			// Only invalid entries: a mistake, not a request to drop every bypass.
			$out = array_filter( array_map( $normalize, $defaults ), 'is_string' );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Record the invalid route rules, dropped request methods, vary declarations, global ignored parameters and
	 * bypass entries of the last build with {@see Failure_Recorder}, once per compile and not auto-cleared (only
	 * fixing the configuration helps). Unusable global vary headers come first, whatever else is wrong, because they
	 * stop caching on every route; then route rules, dropped methods, vary declarations and global ignored
	 * parameters (all five context `route_rules`), else context `bypass`.
	 *
	 * @return void
	 */
	private function record_problems(): void {
		$problems = array_merge(
			null === $this->global_vary_problem ? [] : [ $this->global_vary_problem ],
			$this->route_problems,
			$this->method_problems,
			$this->vary_problems,
			$this->global_problems,
			$this->bypass_problems
		);
		if ( [] === $problems ) {
			return;
		}
		if ( null !== $this->global_vary_problem ) {
			$context = 'route_rules';
			$message = 'No route is cached, ' . $problems[0];
		} elseif ( [] !== $this->route_problems ) {
			$context = 'route_rules';
			$message = 'Invalid route rule ignored, the route is not cached: ' . $problems[0];
		} elseif ( [] !== $this->method_problems ) {
			$context = 'route_rules';
			$message = 'Request method not cached: ' . $problems[0];
		} elseif ( [] !== $this->vary_problems ) {
			$context = 'route_rules';
			$message = 'Invalid vary declaration, the route is not cached: ' . $problems[0];
		} elseif ( [] !== $this->global_problems ) {
			$context = 'route_rules';
			$message = 'Global ignored parameter not applied: ' . $problems[0];
		} else {
			$context = 'bypass';
			$message = 'Invalid bypass entry ignored: ' . $problems[0];
		}
		if ( count( $problems ) > 1 ) {
			$message .= sprintf( ' (and %d more)', count( $problems ) - 1 );
		}
		Failure_Recorder::record( $context, $message, '', false );
	}

	/**
	 * Validate a request header name: lower-case, and `_` as `-` (PHP passes both as `_` in `$_SERVER`).
	 *
	 * @param mixed $name Candidate header name.
	 *
	 * @return string|null The normalised name, or null when it is not a valid header name.
	 */
	public static function normalize_header_name( mixed $name ): ?string {
		if ( ! is_string( $name ) ) {
			return null;
		}
		$name = str_replace( '_', '-', strtolower( trim( $name ) ) );
		return 1 === preg_match( Route_Rule::HEADER_NAME_PATTERN, $name ) ? $name : null;
	}

	/**
	 * Validate a cookie name prefix and convert it to the name PHP gives the cookie in `$_COOKIE`.
	 *
	 * PHP replaces `.` (and space) in an incoming cookie name by `_`, so `my.app_` must be matched as `my_app_`.
	 * PHP (7.4.11 and later) no longer urldecodes cookie names, so `+` is matched as sent; `%` is rejected
	 * because a percent-encoded name can not be matched reliably.
	 *
	 * @param mixed $prefix Candidate prefix.
	 *
	 * @return string|null The prefix as it appears in `$_COOKIE`, or null when it is not a valid cookie name prefix.
	 */
	public static function normalize_cookie_prefix( mixed $prefix ): ?string {
		if ( ! is_string( $prefix ) ) {
			return null;
		}
		$prefix = trim( $prefix );
		if ( 1 !== preg_match( self::COOKIE_PREFIX_PATTERN, $prefix ) ) {
			return null;
		}
		return str_replace( '.', '_', $prefix );
	}

	/**
	 * The request headers that carry credentials and bypass the cache.
	 *
	 * @param string[] $from_constant Lowercase header names from `RFRAC_BYPASS_HEADERS`.
	 * @phpstan-param list<string> $from_constant
	 *
	 * @return list<string>
	 */
	private function bypass_headers( array $from_constant ): array {
		/**
		 * Filters the credential request headers that bypass the cache.
		 *
		 * @see Hooks::BYPASS_HEADERS
		 */
		$defaults = array_merge( self::BYPASS_HEADERS, $from_constant );
		$headers  = apply_filters( Hooks::BYPASS_HEADERS, $defaults ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		return $this->clean_bypass_list( $headers, $defaults, [ self::class, 'normalize_header_name' ], Hooks::BYPASS_HEADERS );
	}

	/**
	 * The response headers replayed on a hit, without the forbidden ones.
	 *
	 * @return list<string>
	 */
	private function replay_headers(): array {
		/**
		 * Filters the replayed response headers.
		 *
		 * @see Hooks::REPLAY_HEADERS
		 */
		$headers = apply_filters( Hooks::REPLAY_HEADERS, self::REPLAY_HEADERS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		$headers = $this->clean_list( $headers, Route_Rule::HEADER_NAME_PATTERN, true );
		return array_values( array_diff( $headers, self::FORBIDDEN_REPLAY_HEADERS ) );
	}

	/**
	 * Normalise a filtered list: keep unique strings that match a pattern.
	 *
	 * @param mixed  $items     Value returned by a filter.
	 * @param string $pattern   Pattern every entry must match.
	 * @param bool   $lowercase Lowercase the entries first.
	 *
	 * @return list<string>
	 */
	private function clean_list( mixed $items, string $pattern, bool $lowercase ): array {
		if ( ! is_array( $items ) ) {
			return [];
		}
		$out = [];
		foreach ( $items as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$item = trim( $item );
			if ( $lowercase ) {
				$item = strtolower( $item );
			}
			if ( 1 === preg_match( $pattern, $item ) ) {
				$out[] = $item;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Announce a compiled snapshot that was just saved.
	 *
	 * @param Compiled_Config $config The saved snapshot.
	 *
	 * @return void
	 */
	private function announce( Compiled_Config $config ): void {
		/**
		 * Fires after a compile saved the compiled configuration.
		 *
		 * @see Hooks::COMPILED
		 */
		do_action( Hooks::COMPILED, $config ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
	}

	/**
	 * Save the snapshot when it changed and announce a store mode change.
	 *
	 * @param array<string,mixed> $data Snapshot.
	 *
	 * @return void
	 */
	private function save( array $data ): void {
		$old = get_option( Names::OPTION_CONFIG );
		if ( $old === $data ) {
			return;
		}
		update_option( Names::OPTION_CONFIG, $data, true );

		// A changed home host re-points every cached link: drop what was cached under the old one. A snapshot that
		// carried no host yet (or none now) is not a change.
		$old_host = is_array( $old ) && isset( $old['home_host'] ) && is_string( $old['home_host'] ) ? $old['home_host'] : '';
		$new_host = isset( $data['home_host'] ) && is_string( $data['home_host'] ) ? $data['home_host'] : '';
		if ( '' !== $old_host && '' !== $new_host && $old_host !== $new_host ) {
			( $this->flush_site )();
		}

		$old_mode = is_array( $old ) && isset( $old['store_mode'] ) && is_string( $old['store_mode'] ) ? $old['store_mode'] : null;
		if ( null !== $old_mode && $old_mode !== $data['store_mode'] ) {
			/**
			 * Fires when the effective store mode changed.
			 *
			 * @see Hooks::CONFIG_STORE_CHANGED
			 */
			do_action( Hooks::CONFIG_STORE_CHANGED, $old_mode, $data['store_mode'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		}
	}
}
