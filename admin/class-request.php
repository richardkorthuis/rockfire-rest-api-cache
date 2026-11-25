<?php
/**
 * Sanitised access to the request input of the admin.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Admin;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification -- Class-level: this class only reads and sanitises input; callers verify the nonce first (Action_Handler runs check_admin_referer() before the action callback) or read only non-mutating view parameters.

/**
 * The only reader of `$_GET` / `$_POST` in `admin/`.
 *
 * Every value is unslashed and sanitised right where it is read, by the sanitiser of its type; a missing
 * parameter returns the fallback. `$_POST` wins over `$_GET`, also when its value is not a scalar (then
 * the fallback applies); cookies are never read.
 *
 * @since 1.0.0
 */
final class Request {

	/**
	 * Whether a parameter is present.
	 *
	 * @param string $name Parameter name.
	 *
	 * @return bool
	 */
	public static function has( string $name ): bool {
		return isset( $_POST[ $name ] ) || isset( $_GET[ $name ] );
	}

	/**
	 * A key (`sanitize_key`: lowercase letters, digits, `_` and `-`).
	 *
	 * @param string $name    Parameter name.
	 * @param string $fallback Fallback value.
	 *
	 * @return string
	 */
	public static function key( string $name, string $fallback = '' ): string {
		if ( isset( $_POST[ $name ] ) ) {
			return is_scalar( $_POST[ $name ] ) ? sanitize_key( (string) wp_unslash( $_POST[ $name ] ) ) : $fallback;
		}
		if ( isset( $_GET[ $name ] ) ) {
			return is_scalar( $_GET[ $name ] ) ? sanitize_key( (string) wp_unslash( $_GET[ $name ] ) ) : $fallback;
		}
		return $fallback;
	}

	/**
	 * A single-line text (`sanitize_text_field`).
	 *
	 * @param string $name    Parameter name.
	 * @param string $fallback Fallback value.
	 *
	 * @return string
	 */
	public static function text( string $name, string $fallback = '' ): string {
		if ( isset( $_POST[ $name ] ) ) {
			return is_scalar( $_POST[ $name ] ) ? sanitize_text_field( (string) wp_unslash( $_POST[ $name ] ) ) : $fallback;
		}
		if ( isset( $_GET[ $name ] ) ) {
			return is_scalar( $_GET[ $name ] ) ? sanitize_text_field( (string) wp_unslash( $_GET[ $name ] ) ) : $fallback;
		}
		return $fallback;
	}

	/**
	 * A non-negative integer (`absint` of the `sanitize_text_field` value); an empty value is the fallback.
	 *
	 * @param string $name    Parameter name.
	 * @param int    $fallback Fallback value.
	 *
	 * @return int
	 */
	public static function int( string $name, int $fallback = 0 ): int {
		$value = null;
		if ( isset( $_POST[ $name ] ) ) {
			$value = is_scalar( $_POST[ $name ] ) ? sanitize_text_field( (string) wp_unslash( $_POST[ $name ] ) ) : null;
		} elseif ( isset( $_GET[ $name ] ) ) {
			$value = is_scalar( $_GET[ $name ] ) ? sanitize_text_field( (string) wp_unslash( $_GET[ $name ] ) ) : null;
		}
		return null === $value || '' === $value ? $fallback : absint( $value );
	}

	/**
	 * A boolean: `1`, `true`, `on` and `yes` are true; anything else present is false.
	 *
	 * @param string $name    Parameter name.
	 * @param bool   $fallback Fallback value when the parameter is missing.
	 *
	 * @return bool
	 */
	public static function bool( string $name, bool $fallback = false ): bool {
		$value = null;
		if ( isset( $_POST[ $name ] ) ) {
			$value = is_scalar( $_POST[ $name ] ) ? sanitize_text_field( (string) wp_unslash( $_POST[ $name ] ) ) : null;
		} elseif ( isset( $_GET[ $name ] ) ) {
			$value = is_scalar( $_GET[ $name ] ) ? sanitize_text_field( (string) wp_unslash( $_GET[ $name ] ) ) : null;
		}
		if ( null === $value ) {
			return $fallback;
		}
		return in_array( strtolower( $value ), array( '1', 'true', 'on', 'yes' ), true );
	}

	/**
	 * A list of keys from an array parameter (`name[]`) or a comma, whitespace or newline separated string.
	 *
	 * Tags and `%xx` octets are stripped before the key conversion; empty and duplicate values are dropped.
	 *
	 * @param string $name Parameter name.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function key_list( string $name ): array {
		return self::unique( array_map( 'sanitize_key', self::split( $name, '/[\s,]+/' ) ) );
	}

	/**
	 * A list of texts from an array parameter (`name[]`) or a comma or newline separated string (a textarea).
	 *
	 * Every item is trimmed and sanitised with `sanitize_text_field`; empty and duplicate values are dropped.
	 *
	 * @param string $name Parameter name.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function text_list( string $name ): array {
		return self::unique( array_map( 'sanitize_text_field', self::split( $name, '/[\r\n,]+/' ) ) );
	}

	/**
	 * A list of non-negative integers from an array parameter (`name[]`) or a comma separated string; zeros are dropped.
	 *
	 * @param string $name Parameter name.
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public static function int_list( string $name ): array {
		$ids = array_filter( array_map( 'absint', self::split( $name, '/[\s,]+/' ) ) );
		return array_values( array_unique( $ids ) );
	}

	/**
	 * A URL (`esc_url_raw`); empty when it is not a valid http(s) URL.
	 *
	 * @param string $name Parameter name.
	 *
	 * @return string
	 */
	public static function url( string $name ): string {
		if ( isset( $_POST[ $name ] ) ) {
			return is_scalar( $_POST[ $name ] ) ? esc_url_raw( wp_strip_all_tags( (string) wp_unslash( $_POST[ $name ] ) ), array( 'http', 'https' ) ) : '';
		}
		if ( isset( $_GET[ $name ] ) ) {
			return is_scalar( $_GET[ $name ] ) ? esc_url_raw( wp_strip_all_tags( (string) wp_unslash( $_GET[ $name ] ) ), array( 'http', 'https' ) ) : '';
		}
		return '';
	}

	/**
	 * The trimmed, non-empty items of an array parameter (each sanitised with `sanitize_text_field`; a nested
	 * array is not an item) or of a string parameter (`sanitize_textarea_field`) split by a pattern.
	 *
	 * `map_deep()` applies the sanitiser to the unslashed array: like `array_map()` it is a callback-applying
	 * function the WordPress sanitising-input sniff recognises, and it accepts the untyped array items.
	 *
	 * @param string $name    Parameter name.
	 * @param string $pattern Split pattern for a string value.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private static function split( string $name, string $pattern ): array {
		$items = array();
		if ( isset( $_POST[ $name ] ) ) {
			if ( is_array( $_POST[ $name ] ) ) {
				$items = map_deep( wp_unslash( $_POST[ $name ] ), 'sanitize_text_field' );
			} elseif ( is_scalar( $_POST[ $name ] ) ) {
				$items = preg_split( $pattern, sanitize_textarea_field( (string) wp_unslash( $_POST[ $name ] ) ) );
			}
		} elseif ( isset( $_GET[ $name ] ) ) {
			if ( is_array( $_GET[ $name ] ) ) {
				$items = map_deep( wp_unslash( $_GET[ $name ] ), 'sanitize_text_field' );
			} elseif ( is_scalar( $_GET[ $name ] ) ) {
				$items = preg_split( $pattern, sanitize_textarea_field( (string) wp_unslash( $_GET[ $name ] ) ) );
			}
		}
		if ( ! is_array( $items ) ) {
			return array();
		}
		$items = array_map( static fn( $item ): string => is_string( $item ) ? trim( $item ) : '', $items );
		return array_values( array_filter( $items, static fn( string $item ): bool => '' !== $item ) );
	}

	/**
	 * Drop empty and duplicate strings.
	 *
	 * @param string[] $items Items.
	 * @phpstan-param array<string> $items
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private static function unique( array $items ): array {
		return array_values( array_unique( array_filter( $items, static fn( string $item ): bool => '' !== $item ) ) );
	}
}
// phpcs:enable WordPress.Security.NonceVerification
