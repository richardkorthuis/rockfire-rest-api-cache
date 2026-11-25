<?php
/**
 * Settings schema: defaults, ranges and normalisation.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the plugin settings (scalars and lists of names) and normalises values against that description.
 *
 * Pure (no WordPress functions) so it can be reused from any phase. The `routes` setting is not
 * part of the schema; it is owned by {@see Config} and {@see Route_Rule}.
 *
 * @since 1.0.0
 */
final class Settings_Schema {

	/**
	 * Allowed values of the `store` setting.
	 *
	 * @var list<string>
	 */
	public const STORE_MODES = [ 'auto', 'database', 'object_cache' ];

	/**
	 * Lowest allowed TTL in seconds.
	 *
	 * @var int
	 */
	public const MIN_TTL = 60;

	/**
	 * Highest allowed `max_ttl` in seconds.
	 *
	 * @var int
	 */
	public const ABSOLUTE_MAX_TTL = 31536000;

	/**
	 * The `item` of a list setting whose entries are query parameter names.
	 *
	 * @var string
	 */
	public const ITEM_PARAM = 'param';

	/**
	 * The `item` of a list setting whose entries are request header names.
	 *
	 * @var string
	 */
	public const ITEM_HEADER = 'header';

	/**
	 * Longest entry quoted in a list error.
	 *
	 * @var int
	 */
	private const ERROR_ENTRY_LENGTH = 40;

	/**
	 * Setting definitions. `default_ttl` has no static upper bound beyond the current `max_ttl`.
	 *
	 * A `list` setting holds a list of names: `item` is `param` (a query parameter name, see
	 * {@see Route_Rule::PARAM_NAME_PATTERN}) or `header` (a request header name that may vary the cache, see
	 * {@see Route_Rule::HEADER_NAME_PATTERN}) and `max_items` the most entries it can hold.
	 *
	 * @return array<string,array{type:string,default:mixed,constant:string,min?:int,max?:int,values?:list<string>,item?:string,max_items?:int}>
	 */
	public static function definitions(): array {
		return [
			'enabled'              => [
				'type'     => 'bool',
				'default'  => true,
				'constant' => 'RFRAC_DISABLE',
			],
			'store'                => [
				'type'     => 'enum',
				'default'  => 'auto',
				'constant' => 'RFRAC_STORE',
				'values'   => self::STORE_MODES,
			],
			'default_ttl'          => [
				'type'     => 'int',
				'default'  => 86400,
				'constant' => 'RFRAC_DEFAULT_TTL',
				'min'      => self::MIN_TTL,
				'max'      => self::ABSOLUTE_MAX_TTL,
			],
			'max_ttl'              => [
				'type'     => 'int',
				'default'  => 2592000,
				'constant' => 'RFRAC_MAX_TTL',
				'min'      => self::MIN_TTL,
				'max'      => self::ABSOLUTE_MAX_TTL,
			],
			'max_entries'          => [
				'type'     => 'int',
				'default'  => 10000,
				'constant' => 'RFRAC_MAX_ENTRIES',
				'min'      => 100,
				'max'      => 1000000,
			],
			'max_payload_kb'       => [
				'type'     => 'int',
				'default'  => 1024,
				'constant' => 'RFRAC_MAX_PAYLOAD_KB',
				'min'      => 1,
				'max'      => 8192,
			],
			'max_params'           => [
				'type'     => 'int',
				'default'  => 20,
				'constant' => 'RFRAC_MAX_PARAMS',
				'min'      => 1,
				'max'      => 100,
			],
			'hit_sampling'         => [
				'type'     => 'int',
				'default'  => 10,
				'constant' => 'RFRAC_HIT_SAMPLING',
				'min'      => 0,
				'max'      => 1000,
			],
			'global_ignore_params' => [
				'type'      => 'list',
				'default'   => [],
				'constant'  => 'RFRAC_GLOBAL_IGNORE_PARAMS',
				'item'      => self::ITEM_PARAM,
				'max_items' => Route_Rule::MAX_PARAM_NAMES,
			],
			'global_vary_headers'  => [
				'type'      => 'list',
				'default'   => [],
				'constant'  => 'RFRAC_GLOBAL_VARY_HEADERS',
				'item'      => self::ITEM_HEADER,
				'max_items' => Route_Rule::MAX_VARY_HEADERS,
			],
			'nonce_caching'        => [
				'type'     => 'bool',
				'default'  => false,
				'constant' => 'RFRAC_NONCE_CACHING',
			],
		];
	}

	/**
	 * Default values of the settings, in schema order.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array_map(
			static fn( array $definition ) => $definition['default'],
			self::definitions()
		);
	}

	/**
	 * Settings keys that can be overridden by a constant, mapped to the constant name.
	 *
	 * @return array<string,string>
	 */
	public static function constant_names(): array {
		return array_map(
			static fn( array $definition ) => $definition['constant'],
			self::definitions()
		);
	}

	/**
	 * Normalise one value against its definition.
	 *
	 * Integers are clamped into their range; `default_ttl` is clamped to `$max_ttl`. A list is an array of
	 * strings or one string separated by commas and whitespace; its entries are trimmed, empty entries and
	 * duplicates are dropped, header names are lowercased with `_` as `-`. One invalid entry, an entry that is
	 * not a string or more entries than allowed make the whole list invalid (see {@see self::list_error()}).
	 *
	 * @param string $key     Settings key.
	 * @param mixed  $value   Raw value.
	 * @param int    $max_ttl The `max_ttl` that applies to `default_ttl`.
	 *
	 * @return mixed The normalised value, or null when the value has the wrong type or is not allowed.
	 */
	public static function normalize_value( string $key, mixed $value, int $max_ttl ): mixed {
		$definitions = self::definitions();
		if ( ! isset( $definitions[ $key ] ) ) {
			return null;
		}
		$definition = $definitions[ $key ];

		switch ( $definition['type'] ) {
			case 'bool':
				return self::to_bool( $value );
			case 'enum':
				return is_string( $value ) && in_array( $value, $definition['values'] ?? [], true ) ? $value : null;
			case 'list':
				return self::parse_list( $key, $value )['list'];
			default:
				$number = self::to_int( $value );
				if ( null === $number ) {
					return null;
				}
				$upper = 'default_ttl' === $key ? max( self::MIN_TTL, $max_ttl ) : ( $definition['max'] ?? PHP_INT_MAX );
				return max( $definition['min'] ?? 0, min( $upper, $number ) );
		}
	}

	/**
	 * Why a value is not a valid list for a list setting.
	 *
	 * @param string $key   Settings key.
	 * @param mixed  $value Raw value.
	 *
	 * @return string|null The reason (invalid name, forbidden header, too many entries, wrong type); null when the
	 *                     value is a valid list, and for a key that is not a list setting.
	 */
	public static function list_error( string $key, mixed $value ): ?string {
		if ( 'list' !== ( self::definitions()[ $key ]['type'] ?? '' ) ) {
			return null;
		}
		return self::parse_list( $key, $value )['error'];
	}

	/**
	 * Parse a list setting value.
	 *
	 * @param string $key   Key of a list setting.
	 * @param mixed  $value Raw value.
	 *
	 * @return array{list:?list<string>,error:?string} The normalised list, or null and the reason.
	 */
	private static function parse_list( string $key, mixed $value ): array {
		$definition = self::definitions()[ $key ] ?? [];
		$header     = self::ITEM_HEADER === ( $definition['item'] ?? self::ITEM_PARAM );
		$max_items  = $definition['max_items'] ?? PHP_INT_MAX;

		if ( is_string( $value ) ) {
			$entries = preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
			$entries = false === $entries ? [] : $entries;
		} elseif ( is_array( $value ) ) {
			$entries = $value;
		} else {
			return [
				'list'  => null,
				'error' => 'Expected an array of strings, or one string separated by commas or spaces.',
			];
		}

		$list = [];
		foreach ( $entries as $entry ) {
			if ( ! is_string( $entry ) ) {
				return [
					'list'  => null,
					'error' => 'Every entry must be a string.',
				];
			}
			$entry = trim( $entry );
			if ( '' === $entry ) {
				continue;
			}
			if ( $header ) {
				$entry = strtolower( str_replace( '_', '-', $entry ) );
				if ( in_array( $entry, Route_Rule::FORBIDDEN_VARY_HEADERS, true ) ) {
					return [
						'list'  => null,
						'error' => sprintf( 'The header "%s" can not be used to vary the cache.', self::quote_entry( $entry ) ),
					];
				}
				if ( 1 !== preg_match( Route_Rule::HEADER_NAME_PATTERN, $entry ) ) {
					return [
						'list'  => null,
						'error' => sprintf( 'Invalid header name "%s".', self::quote_entry( $entry ) ),
					];
				}
			} elseif ( 1 !== preg_match( Route_Rule::PARAM_NAME_PATTERN, $entry ) ) {
				return [
					'list'  => null,
					'error' => sprintf( 'Invalid parameter name "%s".', self::quote_entry( $entry ) ),
				];
			}
			$list[] = $entry;
		}

		$list = array_values( array_unique( $list ) );
		if ( count( $list ) > $max_items ) {
			return [
				'list'  => null,
				'error' => sprintf( 'At most %d entries are allowed, got %d.', $max_items, count( $list ) ),
			];
		}
		return [
			'list'  => $list,
			'error' => null,
		];
	}

	/**
	 * An entry as short, printable text for an error.
	 *
	 * @param string $entry Entry.
	 *
	 * @return string
	 */
	private static function quote_entry( string $entry ): string {
		$entry = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $entry );
		return mb_strlen( $entry, 'UTF-8' ) > self::ERROR_ENTRY_LENGTH ? mb_substr( $entry, 0, self::ERROR_ENTRY_LENGTH, 'UTF-8' ) . '...' : $entry;
	}

	/**
	 * Apply input values onto a base settings array.
	 *
	 * Unknown keys and keys listed in `$skip` are ignored. A value of the wrong type or outside the
	 * enum, or a list that is not valid, records an error and keeps the base value.
	 *
	 * @param array<mixed>        $input  Raw input.
	 * @param array<string,mixed> $base   Current scalar settings.
	 * @param Validation_Result   $result Receives the errors.
	 * @param string[]            $skip   Keys that must not be changed.
	 * @phpstan-param list<string> $skip
	 *
	 * @return array<string,mixed> The new, complete scalar settings.
	 */
	public static function apply( array $input, array $base, Validation_Result $result, array $skip = [] ): array {
		$out = array_merge( self::defaults(), array_intersect_key( $base, self::definitions() ) );

		// The max_ttl goes first: default_ttl is clamped against it.
		$keys = array_unique( array_merge( [ 'max_ttl' ], array_keys( self::definitions() ) ) );
		foreach ( $keys as $key ) {
			if ( in_array( $key, $skip, true ) || ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = self::normalize_value( $key, $input[ $key ], (int) $out['max_ttl'] );
			if ( null === $value ) {
				$reason = self::list_error( $key, $input[ $key ] );
				$result->add_error(
					'invalid_value',
					sprintf( 'Invalid value for setting "%s".', $key ) . ( null === $reason ? '' : ' ' . $reason ),
					$key
				);
				continue;
			}
			$out[ $key ] = $value;
		}

		$out['default_ttl'] = max( self::MIN_TTL, min( (int) $out['max_ttl'], (int) $out['default_ttl'] ) );

		return $out;
	}

	/**
	 * Build a complete, valid scalar settings array from stored data, ignoring anything invalid.
	 *
	 * @param mixed $stored Stored value (any type).
	 *
	 * @return array<string,mixed>
	 */
	public static function resolve( mixed $stored ): array {
		return self::apply( is_array( $stored ) ? $stored : [], self::defaults(), new Validation_Result() );
	}

	/**
	 * Whether the `RFRAC_DISABLE` kill switch is engaged: the constant is defined and boolean-like true.
	 *
	 * The same rule as the `enabled` override of {@see Config} (an invalid value is a constant error there and
	 * does not disable). Checked directly by the serving path, so the switch acts on the next request without
	 * waiting for a recompile.
	 *
	 * @return bool
	 */
	public static function kill_switch(): bool {
		return defined( 'RFRAC_DISABLE' ) && true === self::to_bool( constant( 'RFRAC_DISABLE' ) );
	}

	/**
	 * Convert a boolean-like value.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return bool|null Null when not boolean-like.
	 */
	public static function to_bool( mixed $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( 1 === $value || 0 === $value ) {
			return 1 === $value;
		}
		if ( is_string( $value ) ) {
			$lower = strtolower( trim( $value ) );
			if ( in_array( $lower, [ '1', 'true', 'yes', 'on' ], true ) ) {
				return true;
			}
			if ( in_array( $lower, [ '', '0', 'false', 'no', 'off' ], true ) ) {
				return false;
			}
		}
		return null;
	}

	/**
	 * Convert an integer-like value (int, integral float, integer string).
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return int|null Null when not integer-like.
	 */
	public static function to_int( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value ) {
			return (int) $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\s*-?\d{1,18}\s*$/', $value ) ) {
			return (int) trim( $value );
		}
		return null;
	}
}
