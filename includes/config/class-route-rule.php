<?php
/**
 * Canonical per-route caching rule.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object describing how one route (or wildcard route family) is cached.
 *
 * `from_array()` is lenient: it normalises (trims, lowercases header names, removes duplicates,
 * clamps the TTL into [60, 31536000]) but keeps invalid names and enum values so that
 * `validate()` can report them. Values of the wrong type are replaced by the default and
 * remembered, so `validate()` reports them too. The site specific `max_ttl` clamp is applied by
 * {@see Config}.
 *
 * @since 1.0.0
 */
final class Route_Rule {

	public const STATUS_DEFAULT  = 'default';
	public const STATUS_ENABLED  = 'enabled';
	public const STATUS_EXCLUDED = 'excluded';

	public const MATCH_REGISTERED = 'registered';
	public const MATCH_WILDCARD   = 'wildcard';

	public const PARAM_REGISTERED = 'registered';
	public const PARAM_ALLOW_ALL  = 'allow_all';

	/**
	 * Valid statuses.
	 *
	 * @var list<string>
	 */
	public const STATUSES = [ self::STATUS_DEFAULT, self::STATUS_ENABLED, self::STATUS_EXCLUDED ];

	/**
	 * Valid match modes.
	 *
	 * @var list<string>
	 */
	public const MATCHES = [ self::MATCH_REGISTERED, self::MATCH_WILDCARD ];

	/**
	 * Valid parameter modes.
	 *
	 * @var list<string>
	 */
	public const PARAM_MODES = [ self::PARAM_REGISTERED, self::PARAM_ALLOW_ALL ];

	/**
	 * Request methods a rule can opt in to, in canonical order. Every other method is never cached.
	 *
	 * @var list<string>
	 */
	public const METHODS = [ 'GET', 'POST', 'PUT', 'PATCH' ];

	/**
	 * The methods of a rule that does not name any: only GET.
	 *
	 * @var list<string>
	 */
	public const DEFAULT_METHODS = [ 'GET' ];

	/**
	 * Header names that can never be part of the cache key (they carry credentials).
	 *
	 * @var list<string>
	 */
	public const FORBIDDEN_VARY_HEADERS = [ 'authorization', 'cookie', 'set-cookie', 'proxy-authorization', 'x-wp-nonce' ];

	public const PARAM_NAME_PATTERN   = '/^[A-Za-z0-9_\-\.\[\]]{1,64}$/';
	public const HEADER_NAME_PATTERN  = '/^[a-z0-9-]{1,64}$/';
	public const WILDCARD_KEY_PATTERN = '/^\/[A-Za-z0-9_\-.\/*]+$/';
	public const FLUSH_NAME_PATTERN   = '/^[a-z0-9_\-]{1,32}$/';
	public const MAX_PARAM_NAMES      = 20;
	public const MAX_VARY_HEADERS     = 5;
	public const MAX_FLUSH_NAMES      = 50;
	public const MAX_KEY_LENGTH       = 500;

	/**
	 * Constructor. Prefer {@see self::from_array()}.
	 *
	 * @param string               $status           One of {@see self::STATUSES}.
	 * @param string               $match            One of {@see self::MATCHES}.
	 * @param int|null             $ttl              TTL in seconds; null follows the site `default_ttl`.
	 * @param string               $param_mode       One of {@see self::PARAM_MODES}.
	 * @param string[]             $ignore_params    Query parameters ignored for the cache key.
	 * @phpstan-param list<string> $ignore_params
	 * @param string[]             $bypass_params    Query parameters that make the request bypass the cache.
	 * @phpstan-param list<string> $bypass_params
	 * @param string[]             $vary_headers     Lowercase request header names that vary the cache key.
	 * @phpstan-param list<string> $vary_headers
	 * @param string[]             $flush_post_types Post types whose changes flush this route.
	 * @phpstan-param list<string> $flush_post_types
	 * @param string[]             $flush_taxonomies Taxonomies whose changes flush this route.
	 * @phpstan-param list<string> $flush_taxonomies
	 * @param array<string,string> $input_errors     Field => message for input that could not be interpreted.
	 * @param string[]             $methods          Request methods the rule caches: a non-empty subset of
	 *                                               {@see self::METHODS}, in that order. Only a registered route can
	 *                                               use more than GET.
	 * @phpstan-param list<string> $methods
	 */
	public function __construct(
		public readonly string $status = self::STATUS_DEFAULT,
		public readonly string $match = self::MATCH_REGISTERED, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.matchFound -- Public field name mandated by the rule schema.
		public readonly ?int $ttl = null,
		public readonly string $param_mode = self::PARAM_REGISTERED,
		public readonly array $ignore_params = [],
		public readonly array $bypass_params = [],
		public readonly array $vary_headers = [],
		public readonly array $flush_post_types = [],
		public readonly array $flush_taxonomies = [],
		public readonly array $input_errors = [],
		public readonly array $methods = self::DEFAULT_METHODS
	) {
	}

	/**
	 * A rule with all defaults.
	 *
	 * @return self
	 */
	public static function defaults(): self {
		return new self();
	}

	/**
	 * Build a rule from (possibly partial or untrusted) data; missing fields take their default.
	 *
	 * @param array<mixed> $data Rule data, as produced by {@see self::to_array()} or a form/REST payload.
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$errors = [];

		$status     = self::read_string( $data, 'status', self::STATUS_DEFAULT, $errors );
		$match      = self::read_string( $data, 'match', self::MATCH_REGISTERED, $errors );
		$param_mode = self::read_string( $data, 'param_mode', self::PARAM_REGISTERED, $errors );

		$ttl = null;
		if ( isset( $data['ttl'] ) && '' !== $data['ttl'] ) {
			$number = Settings_Schema::to_int( $data['ttl'] );
			if ( null === $number ) {
				$errors['ttl'] = 'The ttl must be an integer number of seconds or null.';
			} else {
				$ttl = max( Settings_Schema::MIN_TTL, min( Settings_Schema::ABSOLUTE_MAX_TTL, $number ) );
			}
		}

		// Read before the constructor call: the arguments are evaluated in order, and `$errors` is passed by value.
		$methods = self::read_methods( $data, $errors );

		return new self(
			strtolower( $status ),
			strtolower( $match ),
			$ttl,
			strtolower( $param_mode ),
			self::read_list( $data, 'ignore_params', false, $errors ),
			self::read_list( $data, 'bypass_params', false, $errors ),
			self::read_list( $data, 'vary_headers', true, $errors ),
			self::read_list( $data, 'flush_post_types', false, $errors ),
			self::read_list( $data, 'flush_taxonomies', false, $errors ),
			$errors,
			$methods
		);
	}

	/**
	 * The rule as a plain array (round-trips through {@see self::from_array()}).
	 *
	 * `methods` is written only when the rule caches more than GET, so a GET-only rule serialises as it always did.
	 *
	 * @return array{status:string,match:string,ttl:?int,param_mode:string,ignore_params:list<string>,bypass_params:list<string>,vary_headers:list<string>,flush_post_types:list<string>,flush_taxonomies:list<string>,methods?:list<string>}
	 */
	public function to_array(): array {
		$data = [
			'status'           => $this->status,
			'match'            => $this->match,
			'ttl'              => $this->ttl,
			'param_mode'       => $this->param_mode,
			'ignore_params'    => $this->ignore_params,
			'bypass_params'    => $this->bypass_params,
			'vary_headers'     => $this->vary_headers,
			'flush_post_types' => $this->flush_post_types,
			'flush_taxonomies' => $this->flush_taxonomies,
		];
		if ( self::DEFAULT_METHODS !== $this->methods ) {
			$data['methods'] = $this->methods;
		}
		return $data;
	}

	/**
	 * Return a copy with a different TTL.
	 *
	 * @param int|null $ttl New TTL.
	 *
	 * @return self
	 */
	public function with_ttl( ?int $ttl ): self {
		return new self(
			$this->status,
			$this->match,
			$ttl,
			$this->param_mode,
			$this->ignore_params,
			$this->bypass_params,
			$this->vary_headers,
			$this->flush_post_types,
			$this->flush_taxonomies,
			$this->input_errors,
			$this->methods
		);
	}

	/**
	 * Return a copy with different methods, normalised like {@see self::from_array()}: uppercased, without duplicates,
	 * the supported ones first in {@see self::METHODS} order, and `GET` when the list is empty. A name that is not
	 * supported is kept so that `validate()` reports it.
	 *
	 * @param string[] $methods New methods.
	 * @phpstan-param list<string> $methods
	 *
	 * @return self
	 */
	public function with_methods( array $methods ): self {
		return new self(
			$this->status,
			$this->match,
			$this->ttl,
			$this->param_mode,
			$this->ignore_params,
			$this->bypass_params,
			$this->vary_headers,
			$this->flush_post_types,
			$this->flush_taxonomies,
			$this->input_errors,
			self::normalize_methods( $methods )
		);
	}

	/**
	 * Validate the rule.
	 *
	 * Error codes: `invalid_type`, `invalid_ttl`, `invalid_status`, `invalid_match`,
	 * `invalid_param_mode`, `invalid_method`, `invalid_param_name`, `too_many_params`, `invalid_vary_header`,
	 * `forbidden_vary_header`, `too_many_vary_headers`, `invalid_flush_name`, `too_many_flush_names`.
	 *
	 * @return Validation_Result
	 */
	public function validate(): Validation_Result {
		$result = new Validation_Result();

		foreach ( $this->input_errors as $field => $message ) {
			if ( 'ttl' === $field ) {
				$code = 'invalid_ttl';
			} elseif ( 'methods' === $field ) {
				$code = 'invalid_method';
			} else {
				$code = 'invalid_type';
			}
			$result->add_error( $code, $message, $field );
		}

		if ( ! in_array( $this->status, self::STATUSES, true ) ) {
			$result->add_error( 'invalid_status', 'The status must be default, enabled or excluded.', 'status' );
		}
		if ( ! in_array( $this->match, self::MATCHES, true ) ) {
			$result->add_error( 'invalid_match', 'The match mode must be registered or wildcard.', 'match' );
		}
		if ( ! in_array( $this->param_mode, self::PARAM_MODES, true ) ) {
			$result->add_error( 'invalid_param_mode', 'The param mode must be registered or allow_all.', 'param_mode' );
		}

		if ( [] === $this->methods ) {
			$result->add_error( 'invalid_method', 'At least one method is required.', 'methods' );
		}
		foreach ( $this->methods as $method ) {
			if ( ! in_array( $method, self::METHODS, true ) ) {
				$result->add_error( 'invalid_method', sprintf( 'Invalid method "%s": only GET, POST, PUT and PATCH can be cached.', $method ), 'methods' );
			}
		}
		if ( self::MATCH_WILDCARD === $this->match && self::DEFAULT_METHODS !== $this->methods ) {
			$result->add_error( 'invalid_method', 'A wildcard rule can only cache GET.', 'methods' );
		}

		foreach ( [ 'ignore_params', 'bypass_params' ] as $field ) {
			$names = $this->$field;
			if ( count( $names ) > self::MAX_PARAM_NAMES ) {
				$result->add_error( 'too_many_params', sprintf( 'At most %d names are allowed in %s.', self::MAX_PARAM_NAMES, $field ), $field );
			}
			foreach ( $names as $name ) {
				if ( 1 !== preg_match( self::PARAM_NAME_PATTERN, $name ) ) {
					$result->add_error( 'invalid_param_name', sprintf( 'Invalid parameter name "%s".', $name ), $field );
				}
			}
		}

		if ( count( $this->vary_headers ) > self::MAX_VARY_HEADERS ) {
			$result->add_error( 'too_many_vary_headers', sprintf( 'At most %d vary headers are allowed.', self::MAX_VARY_HEADERS ), 'vary_headers' );
		}
		foreach ( $this->vary_headers as $header ) {
			if ( in_array( $header, self::FORBIDDEN_VARY_HEADERS, true ) ) {
				$result->add_error( 'forbidden_vary_header', sprintf( 'The header "%s" can not be used to vary the cache.', $header ), 'vary_headers' );
			} elseif ( 1 !== preg_match( self::HEADER_NAME_PATTERN, $header ) ) {
				$result->add_error( 'invalid_vary_header', sprintf( 'Invalid header name "%s".', $header ), 'vary_headers' );
			}
		}

		foreach ( [ 'flush_post_types', 'flush_taxonomies' ] as $field ) {
			$names = $this->$field;
			if ( count( $names ) > self::MAX_FLUSH_NAMES ) {
				$result->add_error( 'too_many_flush_names', sprintf( 'At most %d names are allowed in %s.', self::MAX_FLUSH_NAMES, $field ), $field );
			}
			foreach ( $names as $name ) {
				if ( 1 !== preg_match( self::FLUSH_NAME_PATTERN, $name ) ) {
					$result->add_error( 'invalid_flush_name', sprintf( 'Invalid name "%s".', $name ), $field );
				}
			}
		}

		return $result;
	}

	/**
	 * Validate a route key for the given match mode.
	 *
	 * Wildcard keys must match {@see self::WILDCARD_KEY_PATTERN}; registered route keys are the
	 * route regexes WordPress registered and only need to start with a slash.
	 *
	 * @param string $key  Route key.
	 * @param string $mode Match mode.
	 *
	 * @return Validation_Result
	 */
	public static function validate_key( string $key, string $mode ): Validation_Result {
		$result = new Validation_Result();

		if ( '' === $key || '/' !== $key[0] || strlen( $key ) > self::MAX_KEY_LENGTH || 1 === preg_match( '/[\x00-\x1F\x7F]/', $key ) ) {
			$result->add_error( 'invalid_route_key', 'A route key must start with a slash and contain no control characters.', 'key' );
		} elseif ( self::MATCH_WILDCARD === $mode && 1 !== preg_match( self::WILDCARD_KEY_PATTERN, $key ) ) {
			$result->add_error( 'invalid_route_key', 'A wildcard route key may only contain letters, digits, _ - . / and *.', 'key' );
		}

		return $result;
	}

	/**
	 * Read a string field.
	 *
	 * @param array<mixed>         $data     Input.
	 * @param string               $field    Field name.
	 * @param string               $fallback Default.
	 * @param array<string,string> $errors   Collects type errors.
	 *
	 * @return string
	 */
	private static function read_string( array $data, string $field, string $fallback, array &$errors ): string {
		if ( ! array_key_exists( $field, $data ) || null === $data[ $field ] ) {
			return $fallback;
		}
		if ( ! is_string( $data[ $field ] ) ) {
			$errors[ $field ] = sprintf( 'The %s must be a string.', $field );
			return $fallback;
		}
		return trim( $data[ $field ] );
	}

	/**
	 * Read the methods: a list of strings, uppercased and without duplicates, `GET` when absent or empty.
	 *
	 * @param array<mixed>         $data   Input.
	 * @param array<string,string> $errors Collects type errors.
	 *
	 * @return list<string>
	 */
	private static function read_methods( array $data, array &$errors ): array {
		if ( ! array_key_exists( 'methods', $data ) || null === $data['methods'] ) {
			return self::DEFAULT_METHODS;
		}
		$value = $data['methods'];
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			$errors['methods'] = 'The methods must be a list of strings.';
			return self::DEFAULT_METHODS;
		}

		$names = [];
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				$errors['methods'] = 'The methods must be a list of strings.';
				continue;
			}
			$names[] = $item;
		}
		return self::normalize_methods( $names );
	}

	/**
	 * Uppercase and de-duplicate methods and put the supported ones first, in {@see self::METHODS} order; a name that
	 * is not supported follows in input order, so `validate()` can report it. An empty list is `GET`.
	 *
	 * @param string[] $methods Method names.
	 * @phpstan-param list<string> $methods
	 *
	 * @return list<string>
	 */
	private static function normalize_methods( array $methods ): array {
		$names = [];
		foreach ( $methods as $method ) {
			$method = strtoupper( trim( $method ) );
			if ( '' !== $method ) {
				$names[] = $method;
			}
		}
		$names = array_values( array_unique( $names ) );
		$out   = array_merge( array_values( array_intersect( self::METHODS, $names ) ), array_values( array_diff( $names, self::METHODS ) ) );

		return [] === $out ? self::DEFAULT_METHODS : $out;
	}

	/**
	 * Read a list-of-strings field, trimmed and without duplicates.
	 *
	 * @param array<mixed>         $data      Input.
	 * @param string               $field     Field name.
	 * @param bool                 $lowercase Lowercase every entry.
	 * @param array<string,string> $errors    Collects type errors.
	 *
	 * @return list<string>
	 */
	private static function read_list( array $data, string $field, bool $lowercase, array &$errors ): array {
		if ( ! array_key_exists( $field, $data ) || null === $data[ $field ] ) {
			return [];
		}
		if ( ! is_array( $data[ $field ] ) ) {
			$errors[ $field ] = sprintf( 'The %s must be a list of strings.', $field );
			return [];
		}

		$out = [];
		foreach ( $data[ $field ] as $item ) {
			if ( ! is_string( $item ) ) {
				$errors[ $field ] = sprintf( 'The %s must be a list of strings.', $field );
				continue;
			}
			$item = trim( $item );
			if ( $lowercase ) {
				$item = strtolower( $item );
			}
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
