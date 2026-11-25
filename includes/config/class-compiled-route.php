<?php
/**
 * One route of the compiled configuration.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only compiled form of a route rule, ready for matching at request time.
 *
 * Uses no WordPress functions (mu-plugin phase).
 *
 * @since 1.0.0
 */
final class Compiled_Route {

	/**
	 * The vary-cookie declaration of the route was invalid.
	 *
	 * @var string
	 */
	public const VARY_COOKIES = 'cookies';

	/**
	 * The global vary headers were unusable, so no route can be cached.
	 *
	 * @var string
	 */
	public const VARY_GLOBAL_HEADERS = 'global_headers';

	/**
	 * The route's own vary headers together with the global ones were more than the limit allows.
	 *
	 * @var string
	 */
	public const VARY_MERGED_HEADERS = 'merged_headers';

	/**
	 * Whether the vary declaration of the route was invalid: true exactly when `$vary_cause` is set.
	 *
	 * @var bool
	 */
	public readonly bool $vary_invalid;

	/**
	 * Constructor.
	 *
	 * @param string      $key            Route key (as in the settings).
	 * @param string      $regex          Complete PCRE pattern (with delimiters) matching the REST route.
	 * @param int         $ttl            Resolved TTL in seconds.
	 * @param string      $param_mode     `registered` or `allow_all`.
	 * @param string[]    $allowed_params Query parameters that take part in the cache key (registered mode).
	 * @phpstan-param list<string> $allowed_params
	 * @param string[]    $ignore_params  Query parameters ignored for the cache key.
	 * @phpstan-param list<string> $ignore_params
	 * @param string[]    $bypass_params  Query parameters that bypass the cache.
	 * @phpstan-param list<string> $bypass_params
	 * @param string[]    $vary_headers   Lowercase request header names that vary the cache key.
	 * @phpstan-param list<string> $vary_headers
	 * @param bool        $enabled        False for a negative entry: a route that is known but not cached.
	 * @param bool        $visitor_varies True when the responses differ per visitor in a way the cache key does not
	 *                                    capture, so the route is never served from or stored in the cache.
	 * @param array       $vary_cookies   Cookie name => allowed values; each cookie varies the cache key.
	 * @phpstan-param array<string,list<string>> $vary_cookies
	 * @param string|null $vary_cause     Why the vary declaration of the route was invalid, which is why `$visitor_varies`
	 *                                    is true, or null when it was not: {@see self::VARY_COOKIES} (its vary-cookie
	 *                                    declaration; then `$vary_cookies` is empty), {@see self::VARY_GLOBAL_HEADERS}
	 *                                    (the global vary headers are unusable) or {@see self::VARY_MERGED_HEADERS} (the
	 *                                    global vary headers together with its own are more than 5; then `$vary_headers`
	 *                                    stays the route's own). Tells these apart from a route that varies per visitor
	 *                                    on purpose and from each other; it changes no behaviour. `$vary_invalid` is true
	 *                                    exactly when it is set.
	 * @phpstan-param self::VARY_*|null $vary_cause
	 * @param string[]    $methods        Request methods the route is cached for: a non-empty subset of
	 *                                    {@see Route_Rule::METHODS}, in that order. GET unless the rule opted in to more.
	 * @phpstan-param list<string> $methods
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $regex,
		public readonly int $ttl,
		public readonly string $param_mode,
		public readonly array $allowed_params,
		public readonly array $ignore_params,
		public readonly array $bypass_params,
		public readonly array $vary_headers,
		public readonly bool $enabled = true,
		public readonly bool $visitor_varies = false,
		public readonly array $vary_cookies = [],
		public readonly ?string $vary_cause = null,
		public readonly array $methods = Route_Rule::DEFAULT_METHODS
	) {
		$this->vary_invalid = null !== $vary_cause;
	}

	/**
	 * A negative entry: a route that matches first but must not be cached.
	 *
	 * @param string $key   Route key.
	 * @param string $regex Complete PCRE pattern.
	 *
	 * @return self
	 */
	public static function negative( string $key, string $regex ): self {
		return new self( $key, $regex, 0, 'registered', [], [], [], [], false );
	}

	/**
	 * Build a route from stored data.
	 *
	 * @param array<mixed> $data Stored route.
	 *
	 * @return self|null Null when the key or regex is missing or the regex does not compile.
	 */
	public static function from_array( array $data ): ?self {
		$key   = $data['key'] ?? null;
		$regex = $data['regex'] ?? null;
		if ( ! is_string( $key ) || '' === $key || ! is_string( $regex ) || '' === $regex || ! self::compiles( $regex ) ) {
			return null;
		}

		if ( false === ( $data['enabled'] ?? true ) ) {
			return self::negative( $key, $regex );
		}

		$param_mode = isset( $data['param_mode'] ) && 'allow_all' === $data['param_mode'] ? 'allow_all' : 'registered';
		$ttl        = isset( $data['ttl'] ) && is_numeric( $data['ttl'] ) ? (int) $data['ttl'] : 0;

		// Fails closed: only an absent or literally false `visitor_varies` means "does not vary", and a
		// malformed vary declaration makes the route visitor-varying, never cacheable. A snapshot without
		// `vary_invalid` (written before the field existed) loads as a valid declaration, and one with
		// `vary_invalid` but without `vary_cause` (written before that field existed) as an invalid cookie
		// declaration, the only cause there was. A `vary_cause` this version does not know fails closed the same way.
		$visitor_varies = $data['visitor_varies'] ?? false;
		$stored_cause   = $data['vary_cause'] ?? null;
		$vary_cause     = null;
		if ( self::VARY_GLOBAL_HEADERS === $stored_cause ) {
			$vary_cause = self::VARY_GLOBAL_HEADERS;
		} elseif ( self::VARY_MERGED_HEADERS === $stored_cause ) {
			$vary_cause = self::VARY_MERGED_HEADERS;
		} elseif ( null !== $stored_cause || true === ( $data['vary_invalid'] ?? false ) ) {
			$vary_cause = self::VARY_COOKIES;
		}
		$vary_cookies = Vary_Cookies::normalize( $data['vary_cookies'] ?? [] );
		if ( null === $vary_cookies ) {
			$vary_cause ??= self::VARY_COOKIES;
			$vary_cookies = [];
		}
		if ( null !== $vary_cause ) {
			$visitor_varies = true;
		}

		return new self(
			$key,
			$regex,
			$ttl,
			$param_mode,
			self::strings( $data['allowed_params'] ?? null ),
			self::strings( $data['ignore_params'] ?? null ),
			self::strings( $data['bypass_params'] ?? null ),
			self::strings( $data['vary_headers'] ?? null ),
			true,
			false !== $visitor_varies,
			$vary_cookies,
			$vary_cause,
			self::read_methods( $data['methods'] ?? null )
		);
	}

	/**
	 * The route as a plain array. A negative entry is stored compactly: key, regex and `enabled => false`.
	 * `visitor_varies`, `vary_cookies`, `vary_invalid` and `vary_cause` are written only when they differ from their
	 * default; `vary_cause` only for a cause other than the cookie declaration, which a bare `vary_invalid` stands for.
	 * `methods` is written only when it is not `['GET']`, so a GET-only snapshot is byte-identical to one written before it existed.
	 *
	 * @return array{key:string,regex:string,enabled?:false,ttl?:int,param_mode?:string,allowed_params?:list<string>,ignore_params?:list<string>,bypass_params?:list<string>,vary_headers?:list<string>,visitor_varies?:true,vary_cookies?:array<string,list<string>>,vary_invalid?:true,vary_cause?:string,methods?:list<string>}
	 */
	public function to_array(): array {
		if ( ! $this->enabled ) {
			return [
				'key'     => $this->key,
				'regex'   => $this->regex,
				'enabled' => false,
			];
		}
		$data = [
			'key'            => $this->key,
			'regex'          => $this->regex,
			'ttl'            => $this->ttl,
			'param_mode'     => $this->param_mode,
			'allowed_params' => $this->allowed_params,
			'ignore_params'  => $this->ignore_params,
			'bypass_params'  => $this->bypass_params,
			'vary_headers'   => $this->vary_headers,
		];
		if ( $this->visitor_varies ) {
			$data['visitor_varies'] = true;
		}
		if ( [] !== $this->vary_cookies ) {
			$data['vary_cookies'] = $this->vary_cookies;
		}
		if ( $this->vary_invalid ) {
			$data['vary_invalid'] = true;
		}
		if ( null !== $this->vary_cause && self::VARY_COOKIES !== $this->vary_cause ) {
			$data['vary_cause'] = $this->vary_cause;
		}
		if ( Route_Rule::DEFAULT_METHODS !== $this->methods ) {
			$data['methods'] = $this->methods;
		}
		return $data;
	}

	/**
	 * Read the stored methods. Fails closed: anything but a non-empty list of supported method names is `['GET']`,
	 * the behaviour of a snapshot written before `methods` existed, which caches no other method.
	 *
	 * @param mixed $value Stored value.
	 *
	 * @return list<string> The supported methods, without duplicates, in {@see Route_Rule::METHODS} order.
	 */
	private static function read_methods( mixed $value ): array {
		if ( ! is_array( $value ) || [] === $value || ! array_is_list( $value ) ) {
			return Route_Rule::DEFAULT_METHODS;
		}
		foreach ( $value as $method ) {
			if ( ! is_string( $method ) || ! in_array( $method, Route_Rule::METHODS, true ) ) {
				return Route_Rule::DEFAULT_METHODS;
			}
		}
		return array_values( array_intersect( Route_Rule::METHODS, $value ) );
	}

	/**
	 * Whether a PCRE pattern compiles, without emitting a warning.
	 *
	 * @param string $regex Complete pattern.
	 *
	 * @return bool
	 */
	private static function compiles( string $regex ): bool {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Silences the warning of compiling an untrusted pattern for one preg_match; restored in finally.
		set_error_handler( static fn() => true );
		try {
			return false !== preg_match( $regex, '' );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Keep only the string entries of a list.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return list<string>
	 */
	private static function strings( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		return array_values( array_filter( $value, 'is_string' ) );
	}
}
