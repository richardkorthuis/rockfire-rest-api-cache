<?php
/**
 * Read-only snapshot of the compiled runtime configuration.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Config;

use Rockfire\Rest_Api_Cache\Names;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable view of the `rfrac_config` option written by the compiler.
 *
 * Loaded with a single `get_option()` and no filters so it is usable from the must-use phase.
 * Unknown or malformed fields fall back to their defaults; routes whose regex does not compile
 * are dropped.
 *
 * @since 1.0.0
 */
final class Compiled_Config {

	/**
	 * Supported snapshot format. Format 2 added `bypass_headers`; older snapshots load as missing and
	 * are rebuilt on the next REST request.
	 *
	 * The route fields `visitor_varies`, `vary_cookies`, `vary_invalid` and `vary_cause` did not change the
	 * format: they are additive and written only when set, so a snapshot without them loads with today's
	 * behaviour (the route does not vary and declares no cookies), a `vary_invalid` without a `vary_cause`
	 * loads as an invalid cookie declaration, and a compile without any seam answer yields the same snapshot
	 * and fingerprint as before.
	 *
	 * The name-free flags `cors_on_hits` (default true) and `bypass_all` (default false) are additive in the
	 * same way: each is written only when it differs from its default, so a snapshot without them serves
	 * CORS headers on hits and bypasses nothing, as before.
	 *
	 * The fields `nonce_caching` (default false) and `login_cookies` (default empty) follow the same rule: the
	 * flag is written only when true and the list only when non-empty, so a default site's snapshot and
	 * fingerprint are byte-identical to before.
	 *
	 * The field `home_host` (default empty) is additive too: the lower-case host of `home_url()`, with `:port` only
	 * when the port is not the scheme's default, written only when non-empty. A snapshot without it loads as empty,
	 * which means no host keying.
	 *
	 * @var int
	 */
	public const FORMAT = 2;

	/**
	 * Constructor. Prefer {@see self::load()} or {@see self::from_array()}.
	 *
	 * @param bool             $enabled                Whether caching is enabled.
	 * @param string           $store_mode             Resolved store: `auto`, `database` or `object_cache`.
	 * @param string           $rest_prefix            REST URL prefix, without slashes.
	 * @param string           $charset                Blog charset.
	 * @param int              $default_ttl            Default TTL in seconds.
	 * @param int              $max_ttl                Maximum TTL in seconds.
	 * @param int              $max_entries            Maximum number of entries.
	 * @param int              $max_payload_bytes      Maximum payload size in bytes.
	 * @param int              $max_params             Maximum number of query parameters.
	 * @param int              $hit_sampling           Hit sampling rate (0 = off, 1 = every hit).
	 * @param string[]         $universal_params       Query parameters every route accepts.
	 * @phpstan-param list<string> $universal_params
	 * @param string[]         $bypass_cookie_prefixes Cookie name prefixes that bypass the cache.
	 * @phpstan-param list<string> $bypass_cookie_prefixes
	 * @param string[]         $bypass_headers         Lowercase request header names that carry credentials and bypass the cache.
	 * @phpstan-param list<string> $bypass_headers
	 * @param string[]         $cors_origins           Allowed CORS origins.
	 * @phpstan-param list<string> $cors_origins
	 * @param bool             $cors_wildcard          Whether `Access-Control-Allow-Origin: *` is allowed.
	 * @param string[]         $replay_headers         Response headers replayed on a hit.
	 * @phpstan-param list<string> $replay_headers
	 * @param bool             $allow_skip_cache       Whether `?skip_cache` is honoured.
	 * @param int              $schema_version         Database schema version the snapshot was built for.
	 * @param string           $fingerprint            Fingerprint of the inputs the snapshot was built from.
	 * @param bool             $pending_discovery      Whether route discovery still has to run.
	 * @param Compiled_Route[] $routes                 Compiled routes.
	 * @phpstan-param list<Compiled_Route> $routes
	 * @param bool             $cors_on_hits           Whether hits carry the `Access-Control-Allow-*` headers.
	 * @param bool             $bypass_all             Whether every request bypasses the cache.
	 * @param bool             $nonce_caching          Whether the nonce carriers (`X-WP-Nonce`, `_wpnonce`) are ignored as credential signals.
	 * @param string[]         $login_cookies          Exact `$_COOKIE` names of the site's login cookies not covered by an unfilterable rule.
	 * @phpstan-param list<string> $login_cookies
	 * @param string           $home_host              Lower-case host of the site's home URL (`:port` only when non-default), `''` when unknown.
	 */
	public function __construct(
		private readonly bool $enabled,
		private readonly string $store_mode,
		private readonly string $rest_prefix,
		private readonly string $charset,
		private readonly int $default_ttl,
		private readonly int $max_ttl,
		private readonly int $max_entries,
		private readonly int $max_payload_bytes,
		private readonly int $max_params,
		private readonly int $hit_sampling,
		private readonly array $universal_params,
		private readonly array $bypass_cookie_prefixes,
		private readonly array $bypass_headers,
		private readonly array $cors_origins,
		private readonly bool $cors_wildcard,
		private readonly array $replay_headers,
		private readonly bool $allow_skip_cache,
		private readonly int $schema_version,
		private readonly string $fingerprint,
		private readonly bool $pending_discovery,
		private readonly array $routes,
		private readonly bool $cors_on_hits = true,
		private readonly bool $bypass_all = false,
		private readonly bool $nonce_caching = false,
		private readonly array $login_cookies = [],
		private readonly string $home_host = ''
	) {
	}

	/**
	 * Default snapshot data (current format), also used by the test support.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'format'                 => self::FORMAT,
			'enabled'                => true,
			'store_mode'             => 'auto',
			'rest_prefix'            => 'wp-json',
			'charset'                => 'UTF-8',
			'default_ttl'            => 86400,
			'max_ttl'                => 2592000,
			'max_entries'            => 10000,
			'max_payload_bytes'      => 1048576,
			'max_params'             => 20,
			'hit_sampling'           => 10,
			'universal_params'       => [],
			'bypass_cookie_prefixes' => [],
			'bypass_headers'         => [],
			'cors_origins'           => [],
			'cors_wildcard'          => false,
			'replay_headers'         => [],
			'allow_skip_cache'       => false,
			'schema_version'         => 0,
			'fingerprint'            => '',
			'pending_discovery'      => false,
			'routes'                 => [],
			'cors_on_hits'           => true,
			'bypass_all'             => false,
			'nonce_caching'          => false,
			'login_cookies'          => [],
			'home_host'              => '',
		];
	}

	/**
	 * Load the snapshot with exactly one `get_option()` call.
	 *
	 * @return self|null Null when the option is missing, not an array or has an unsupported format.
	 */
	public static function load(): ?self {
		$data = get_option( Names::OPTION_CONFIG );
		if ( ! is_array( $data ) || ! isset( $data['format'] ) || self::FORMAT !== $data['format'] ) {
			return null;
		}
		return self::from_array( $data );
	}

	/**
	 * Build a snapshot from an array; missing or malformed fields take their default.
	 *
	 * @param array<mixed> $data Snapshot data.
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$d = self::defaults();

		$routes = [];
		if ( isset( $data['routes'] ) && is_array( $data['routes'] ) ) {
			foreach ( $data['routes'] as $route ) {
				$compiled = is_array( $route ) ? Compiled_Route::from_array( $route ) : null;
				if ( null !== $compiled ) {
					$routes[] = $compiled;
				}
			}
		}

		return new self(
			self::bool( $data, 'enabled', (bool) $d['enabled'] ),
			self::string( $data, 'store_mode', (string) $d['store_mode'] ),
			self::string( $data, 'rest_prefix', (string) $d['rest_prefix'] ),
			self::string( $data, 'charset', (string) $d['charset'] ),
			self::int( $data, 'default_ttl', (int) $d['default_ttl'] ),
			self::int( $data, 'max_ttl', (int) $d['max_ttl'] ),
			self::int( $data, 'max_entries', (int) $d['max_entries'] ),
			self::int( $data, 'max_payload_bytes', (int) $d['max_payload_bytes'] ),
			self::int( $data, 'max_params', (int) $d['max_params'] ),
			self::int( $data, 'hit_sampling', (int) $d['hit_sampling'] ),
			self::strings( $data, 'universal_params' ),
			self::strings( $data, 'bypass_cookie_prefixes' ),
			self::strings( $data, 'bypass_headers' ),
			self::strings( $data, 'cors_origins' ),
			self::bool( $data, 'cors_wildcard', (bool) $d['cors_wildcard'] ),
			self::strings( $data, 'replay_headers' ),
			self::bool( $data, 'allow_skip_cache', (bool) $d['allow_skip_cache'] ),
			self::int( $data, 'schema_version', (int) $d['schema_version'] ),
			self::string( $data, 'fingerprint', (string) $d['fingerprint'] ),
			self::bool( $data, 'pending_discovery', (bool) $d['pending_discovery'] ),
			$routes,
			self::bool( $data, 'cors_on_hits', (bool) $d['cors_on_hits'] ),
			self::bool( $data, 'bypass_all', (bool) $d['bypass_all'] ),
			self::bool( $data, 'nonce_caching', false ),
			self::strings( $data, 'login_cookies' ),
			self::string( $data, 'home_host', '' )
		);
	}

	/**
	 * The snapshot as the array stored in the `rfrac_config` option.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		$data = [
			'format'                 => self::FORMAT,
			'enabled'                => $this->enabled,
			'store_mode'             => $this->store_mode,
			'rest_prefix'            => $this->rest_prefix,
			'charset'                => $this->charset,
			'default_ttl'            => $this->default_ttl,
			'max_ttl'                => $this->max_ttl,
			'max_entries'            => $this->max_entries,
			'max_payload_bytes'      => $this->max_payload_bytes,
			'max_params'             => $this->max_params,
			'hit_sampling'           => $this->hit_sampling,
			'universal_params'       => $this->universal_params,
			'bypass_cookie_prefixes' => $this->bypass_cookie_prefixes,
			'bypass_headers'         => $this->bypass_headers,
			'cors_origins'           => $this->cors_origins,
			'cors_wildcard'          => $this->cors_wildcard,
			'replay_headers'         => $this->replay_headers,
			'allow_skip_cache'       => $this->allow_skip_cache,
			'schema_version'         => $this->schema_version,
			'fingerprint'            => $this->fingerprint,
			'pending_discovery'      => $this->pending_discovery,
			'routes'                 => array_map(
				static fn( Compiled_Route $route ) => $route->to_array(),
				$this->routes
			),
		];

		if ( ! $this->cors_on_hits ) {
			$data['cors_on_hits'] = false;
		}
		if ( $this->bypass_all ) {
			$data['bypass_all'] = true;
		}
		if ( $this->nonce_caching ) {
			$data['nonce_caching'] = true;
		}
		if ( [] !== $this->login_cookies ) {
			$data['login_cookies'] = $this->login_cookies;
		}
		if ( '' !== $this->home_host ) {
			$data['home_host'] = $this->home_host;
		}

		return $data;
	}

	/**
	 * Whether caching is enabled.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Resolved store mode.
	 *
	 * @return string
	 */
	public function store_mode(): string {
		return $this->store_mode;
	}

	/**
	 * REST URL prefix without slashes (e.g. `wp-json`).
	 *
	 * @return string
	 */
	public function rest_prefix(): string {
		return $this->rest_prefix;
	}

	/**
	 * Blog charset.
	 *
	 * @return string
	 */
	public function charset(): string {
		return $this->charset;
	}

	/**
	 * Default TTL in seconds.
	 *
	 * @return int
	 */
	public function default_ttl(): int {
		return $this->default_ttl;
	}

	/**
	 * Maximum TTL in seconds.
	 *
	 * @return int
	 */
	public function max_ttl(): int {
		return $this->max_ttl;
	}

	/**
	 * Maximum number of entries.
	 *
	 * @return int
	 */
	public function max_entries(): int {
		return $this->max_entries;
	}

	/**
	 * Maximum payload size in bytes.
	 *
	 * @return int
	 */
	public function max_payload_bytes(): int {
		return $this->max_payload_bytes;
	}

	/**
	 * Maximum number of query parameters.
	 *
	 * @return int
	 */
	public function max_params(): int {
		return $this->max_params;
	}

	/**
	 * Hit sampling rate.
	 *
	 * @return int
	 */
	public function hit_sampling(): int {
		return $this->hit_sampling;
	}

	/**
	 * Query parameters every route accepts.
	 *
	 * @return list<string>
	 */
	public function universal_params(): array {
		return $this->universal_params;
	}

	/**
	 * Cookie name prefixes that bypass the cache.
	 *
	 * @return list<string>
	 */
	public function bypass_cookie_prefixes(): array {
		return $this->bypass_cookie_prefixes;
	}

	/**
	 * Lowercase request header names whose presence (non-empty) bypasses the cache as credentials.
	 *
	 * @return list<string>
	 */
	public function bypass_headers(): array {
		return $this->bypass_headers;
	}

	/**
	 * Allowed CORS origins.
	 *
	 * @return list<string>
	 */
	public function cors_origins(): array {
		return $this->cors_origins;
	}

	/**
	 * Whether a wildcard CORS origin is allowed.
	 *
	 * @return bool
	 */
	public function cors_wildcard(): bool {
		return $this->cors_wildcard;
	}

	/**
	 * Response headers replayed on a hit.
	 *
	 * @return list<string>
	 */
	public function replay_headers(): array {
		return $this->replay_headers;
	}

	/**
	 * Whether `?skip_cache` is honoured.
	 *
	 * @return bool
	 */
	public function allow_skip_cache(): bool {
		return $this->allow_skip_cache;
	}

	/**
	 * Whether hits carry the `Access-Control-Allow-*` headers. `Vary` is sent either way.
	 *
	 * @return bool
	 */
	public function cors_on_hits(): bool {
		return $this->cors_on_hits;
	}

	/**
	 * Whether every request bypasses the cache (reason `filtered`), once the credential checks have run.
	 *
	 * @return bool
	 */
	public function bypass_all(): bool {
		return $this->bypass_all;
	}

	/**
	 * Whether the nonce carriers (`X-WP-Nonce`, `_wpnonce`) are ignored as credential signals. Every other
	 * credential signal still bypasses.
	 *
	 * @return bool
	 */
	public function nonce_caching(): bool {
		return $this->nonce_caching;
	}

	/**
	 * Exact `$_COOKIE` names of the site's login cookies that no unfilterable rule already covers. A request
	 * carrying one of them bypasses the cache as credentials, whatever the bypass prefixes say.
	 *
	 * @return list<string>
	 */
	public function login_cookies(): array {
		return $this->login_cookies;
	}

	/**
	 * The lower-case host of the site's home URL, with `:port` only when the port is not the scheme's default; `''`
	 * when the snapshot carries none, which means the request host never enters the cache key.
	 *
	 * @return string
	 */
	public function home_host(): string {
		return $this->home_host;
	}

	/**
	 * Database schema version the snapshot was built for.
	 *
	 * @return int
	 */
	public function schema_version(): int {
		return $this->schema_version;
	}

	/**
	 * Fingerprint of the inputs the snapshot was built from.
	 *
	 * @return string
	 */
	public function fingerprint(): string {
		return $this->fingerprint;
	}

	/**
	 * Whether route discovery still has to run.
	 *
	 * @return bool
	 */
	public function pending_discovery(): bool {
		return $this->pending_discovery;
	}

	/**
	 * The compiled routes, in match order. Entries with `enabled` false are negative: when one of them
	 * matches first, the route is not cached.
	 *
	 * @return list<Compiled_Route>
	 */
	public function routes(): array {
		return $this->routes;
	}

	/**
	 * The names of the cookies that vary the cache key of any enabled route: the sorted, unique union
	 * of the routes' `vary_cookies` names, as they appear in `$_COOKIE`.
	 *
	 * @return list<string>
	 */
	public function vary_cookie_names(): array {
		$names = [];
		foreach ( $this->routes as $route ) {
			if ( ! $route->enabled ) {
				continue;
			}
			foreach ( array_keys( $route->vary_cookies ) as $name ) {
				$names[ (string) $name ] = (string) $name;
			}
		}
		$names = array_values( $names );
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * The names of the request headers the policy reads: the sorted, unique, lower-case union of the `vary_headers` of
	 * every enabled route and the credential `bypass_headers()`. The request context reads only these server
	 * variables, besides the fixed ones it always needs.
	 *
	 * @return list<string>
	 */
	public function request_header_names(): array {
		$names = [];
		foreach ( $this->routes as $route ) {
			if ( ! $route->enabled ) {
				continue;
			}
			foreach ( $route->vary_headers as $name ) {
				$names[ strtolower( $name ) ] = true;
			}
		}
		foreach ( $this->bypass_headers as $name ) {
			$names[ strtolower( $name ) ] = true;
		}
		$names = array_map( 'strval', array_keys( $names ) );
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * Read a boolean field.
	 *
	 * @param array<mixed> $data     Data.
	 * @param string       $key      Field.
	 * @param bool         $fallback Default.
	 *
	 * @return bool
	 */
	private static function bool( array $data, string $key, bool $fallback ): bool {
		return isset( $data[ $key ] ) && is_bool( $data[ $key ] ) ? $data[ $key ] : $fallback;
	}

	/**
	 * Read an integer field.
	 *
	 * @param array<mixed> $data     Data.
	 * @param string       $key      Field.
	 * @param int          $fallback Default.
	 *
	 * @return int
	 */
	private static function int( array $data, string $key, int $fallback ): int {
		return isset( $data[ $key ] ) && is_int( $data[ $key ] ) ? $data[ $key ] : $fallback;
	}

	/**
	 * Read a string field.
	 *
	 * @param array<mixed> $data     Data.
	 * @param string       $key      Field.
	 * @param string       $fallback Default.
	 *
	 * @return string
	 */
	private static function string( array $data, string $key, string $fallback ): string {
		return isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? $data[ $key ] : $fallback;
	}

	/**
	 * Read a list-of-strings field.
	 *
	 * @param array<mixed> $data Data.
	 * @param string       $key  Field.
	 *
	 * @return list<string>
	 */
	private static function strings( array $data, string $key ): array {
		if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
			return [];
		}
		return array_values( array_filter( $data[ $key ], 'is_string' ) );
	}
}
