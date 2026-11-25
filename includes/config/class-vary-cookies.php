<?php
/**
 * The cookies that vary a route's cache key.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Validation and naming of a route's vary-cookie declaration.
 *
 * A declaration maps a cookie name to the list of values the cache accepts for it. It is validated
 * once, at compile time ({@see Config_Compiler}), and again when a stored route is loaded
 * ({@see Compiled_Route::from_array()}); the request pipeline only ever reads the validated form.
 *
 * Uses no WordPress functions (mu-plugin phase).
 *
 * @since 1.0.0
 */
final class Vary_Cookies {

	/**
	 * Prefix of the reserved cache-key entry of a vary cookie, which keeps it apart from header names.
	 *
	 * @var string
	 */
	public const KEY_PREFIX = 'cookie:';

	/**
	 * Maximum number of cookies a route may declare.
	 *
	 * @var int
	 */
	public const MAX_COOKIES = 8;

	/**
	 * Maximum number of allowed values per cookie.
	 *
	 * @var int
	 */
	public const MAX_VALUES = 1000;

	/**
	 * Maximum length of an allowed value, in bytes.
	 *
	 * @var int
	 */
	public const MAX_VALUE_BYTES = 128;

	/**
	 * Validate and normalise a vary-cookie declaration.
	 *
	 * Cookie names are validated and converted to the name PHP gives the cookie in `$_COOKIE`
	 * ({@see Config_Compiler::normalize_cookie_prefix()}); two names that end up equal are invalid.
	 * Values are strings or integers (cast to strings), non-empty, at most {@see self::MAX_VALUE_BYTES}
	 * bytes and free of control characters. Values are de-duplicated and sorted, names are sorted.
	 * An empty declaration is valid and stays empty. Anything else that is wrong (not an array, too many
	 * cookies or values, an invalid name or value, a cookie without values) makes the whole declaration
	 * invalid, so a caller can fail closed.
	 *
	 * @param mixed $declared Cookie name => list of allowed values.
	 *
	 * @return array<string,list<string>>|null The normalised declaration, or null when it is invalid.
	 */
	public static function normalize( mixed $declared ): ?array {
		if ( ! is_array( $declared ) ) {
			return null;
		}
		if ( [] === $declared ) {
			return [];
		}
		if ( count( $declared ) > self::MAX_COOKIES ) {
			return null;
		}

		$out = [];
		foreach ( $declared as $name => $values ) {
			$name = Config_Compiler::normalize_cookie_prefix( (string) $name );
			if ( null === $name || isset( $out[ $name ] ) ) {
				return null;
			}
			$values = self::normalize_values( $values );
			if ( null === $values ) {
				return null;
			}
			$out[ $name ] = $values;
		}

		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * The reserved cache-key entry name of a vary cookie.
	 *
	 * @param string $name Cookie name, as in `$_COOKIE`.
	 *
	 * @return string
	 */
	public static function key( string $name ): string {
		return self::KEY_PREFIX . $name;
	}

	/**
	 * Validate, de-duplicate and sort the allowed values of one cookie.
	 *
	 * @param mixed $values Declared values.
	 *
	 * @return list<string>|null Null when there is no value, too many, or any of them is invalid.
	 */
	private static function normalize_values( mixed $values ): ?array {
		if ( ! is_array( $values ) || [] === $values || count( $values ) > self::MAX_VALUES ) {
			return null;
		}

		$out = [];
		foreach ( $values as $value ) {
			if ( is_int( $value ) ) {
				$value = (string) $value;
			}
			if (
				! is_string( $value )
				|| '' === $value
				|| strlen( $value ) > self::MAX_VALUE_BYTES
				|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $value )
			) {
				return null;
			}
			$out[ $value ] = $value;
		}

		$out = array_values( $out );
		sort( $out, SORT_STRING );
		return $out;
	}
}
