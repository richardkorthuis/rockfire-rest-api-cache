<?php
/**
 * Reader of the options and constants of the original plugin.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Config\Settings_Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads what the original WP REST Cache plugin kept in `wp_rest_cache_*` options and `WP_REST_CACHE_*`
 * constants, the way the original resolved it: a constant wins over the option, the option over the
 * original's default.
 *
 * One reader for everything that needs those values: the constant fallback, the Site Health test and the
 * importer. It only reads; nothing here writes an option or applies a filter.
 *
 * @since 1.0.0
 */
final class Original_Settings {

	/**
	 * Constant: the number of intervals a cache record lives.
	 *
	 * @var string
	 */
	public const TIMEOUT = 'WP_REST_CACHE_TIMEOUT';

	/**
	 * Constant: the length of one timeout interval in seconds.
	 *
	 * @var string
	 */
	public const TIMEOUT_INTERVAL = 'WP_REST_CACHE_TIMEOUT_INTERVAL';

	/**
	 * Constant: whether expired caches are regenerated.
	 *
	 * @var string
	 */
	public const REGENERATE = 'WP_REST_CACHE_REGENERATE';

	/**
	 * Constant: how often expired caches are regenerated.
	 *
	 * @var string
	 */
	public const REGENERATE_INTERVAL = 'WP_REST_CACHE_REGENERATE_INTERVAL';

	/**
	 * Constant: how many caches one regeneration run handles.
	 *
	 * @var string
	 */
	public const REGENERATE_NUMBER = 'WP_REST_CACHE_REGENERATE_NUMBER';

	/**
	 * Constant: whether the object cache is a Memcache(d) server.
	 *
	 * @var string
	 */
	public const MEMCACHE_USED = 'WP_REST_CACHE_MEMCACHE_USED';

	/**
	 * Constant: the request headers that vary every cached response, as one comma separated string.
	 *
	 * @var string
	 */
	public const GLOBAL_HEADERS = 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS';

	/**
	 * The constants of the original plugin.
	 *
	 * @var list<string>
	 */
	public const CONSTANTS = [
		self::TIMEOUT,
		self::TIMEOUT_INTERVAL,
		self::REGENERATE,
		self::REGENERATE_INTERVAL,
		self::REGENERATE_NUMBER,
		self::MEMCACHE_USED,
		self::GLOBAL_HEADERS,
	];

	/**
	 * Start of the name of every option of the original plugin.
	 *
	 * @var string
	 */
	private const OPTION_PREFIX = 'wp_rest_cache_';

	/**
	 * Default of the timeout: one interval.
	 *
	 * @var int
	 */
	private const DEFAULT_TIMEOUT = 1;

	/**
	 * Default of the timeout interval: one year in seconds.
	 *
	 * @var int
	 */
	private const DEFAULT_TIMEOUT_INTERVAL = 31536000;

	/**
	 * The constants of the original plugin that are defined, with their values.
	 *
	 * @return array<string,mixed> Constant name => value, in the order of {@see self::CONSTANTS}.
	 */
	public static function defined_constants(): array {
		$defined = [];
		foreach ( self::CONSTANTS as $constant ) {
			if ( defined( $constant ) ) {
				$defined[ $constant ] = constant( $constant );
			}
		}
		return $defined;
	}

	/**
	 * One option of the original plugin.
	 *
	 * @param string $suffix  The option name without `wp_rest_cache_`.
	 * @param mixed  $default Returned when the option does not exist.
	 *
	 * @return mixed
	 */
	public static function option( string $suffix, mixed $default = null ): mixed { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- The name of the argument is part of the signature of the reader.
		return get_option( self::OPTION_PREFIX . $suffix, $default );
	}

	/**
	 * Whether an option of the original plugin exists.
	 *
	 * @param string $suffix The option name without `wp_rest_cache_`.
	 *
	 * @return bool
	 */
	public static function has_option( string $suffix ): bool {
		$missing = new \stdClass();
		return self::option( $suffix, $missing ) !== $missing;
	}

	/**
	 * The cache lifetime of the original: timeout times timeout interval.
	 *
	 * Each half is the constant when it is defined, else the option, else the original's default (1 and one year).
	 * `seconds` is the product, or null when a half is not an integer or is not greater than zero.
	 * `label` names what supplies the value: the constants that are defined, joined with ` * `, or, when none is,
	 * the options that exist.
	 *
	 * @return array{seconds:?int,label:string,from_constant:bool}|null Null when neither a constant nor an option sets either half.
	 */
	public static function ttl(): ?array {
		$halves = [
			self::half( self::TIMEOUT, 'timeout', self::DEFAULT_TIMEOUT ),
			self::half( self::TIMEOUT_INTERVAL, 'timeout_interval', self::DEFAULT_TIMEOUT_INTERVAL ),
		];

		$constants = [];
		$options   = [];
		foreach ( $halves as $half ) {
			if ( 'constant' === $half['source'] ) {
				$constants[] = $half['name'];
			} elseif ( 'option' === $half['source'] ) {
				$options[] = $half['name'];
			}
		}
		$from_constant = [] !== $constants;
		$names         = $from_constant ? $constants : $options;
		if ( [] === $names ) {
			return null;
		}

		$timeout  = Settings_Schema::to_int( $halves[0]['value'] );
		$interval = Settings_Schema::to_int( $halves[1]['value'] );
		$seconds  = null;
		if ( null !== $timeout && null !== $interval && $timeout > 0 && $interval > 0 ) {
			// A product beyond the integer range stays an integer; the settings clamp it to `max_ttl`.
			$seconds = $timeout > intdiv( PHP_INT_MAX, $interval ) ? PHP_INT_MAX : $timeout * $interval;
		}

		return [
			'seconds'       => $seconds,
			'label'         => implode( ' * ', $names ),
			'from_constant' => $from_constant,
		];
	}

	/**
	 * The global cacheable request headers of the original.
	 *
	 * The value is the constant when it is defined, else the option; it is split on commas, the entries are trimmed
	 * and empty entries dropped. The names are returned as written: they are not validated or lowercased.
	 *
	 * @return array{headers:list<string>,from_constant:bool,label:string}|null Null when neither the constant nor the option is set.
	 */
	public static function global_headers(): ?array {
		if ( defined( self::GLOBAL_HEADERS ) ) {
			$raw   = constant( self::GLOBAL_HEADERS );
			$label = self::GLOBAL_HEADERS;
			$from  = true;
		} elseif ( self::has_option( 'global_cacheable_request_headers' ) ) {
			$raw   = self::option( 'global_cacheable_request_headers' );
			$label = self::OPTION_PREFIX . 'global_cacheable_request_headers';
			$from  = false;
		} else {
			return null;
		}

		$headers = [];
		if ( is_string( $raw ) ) {
			foreach ( explode( ',', $raw ) as $entry ) {
				$entry = trim( $entry );
				if ( '' !== $entry ) {
					$headers[] = $entry;
				}
			}
		}

		return [
			'headers'       => $headers,
			'from_constant' => $from,
			'label'         => $label,
		];
	}

	/**
	 * One half of the cache lifetime: the constant, else the option, else the original's default.
	 *
	 * @param string $constant Constant name.
	 * @param string $suffix   Option name without `wp_rest_cache_`.
	 * @param int    $original The original's default.
	 *
	 * @return array{value:mixed,name:string,source:string} `source` is `constant`, `option` or `default`; `name` is the
	 *                                                       constant or the option, empty for the default.
	 */
	private static function half( string $constant, string $suffix, int $original ): array {
		if ( defined( $constant ) ) {
			return [
				'value'  => constant( $constant ),
				'name'   => $constant,
				'source' => 'constant',
			];
		}
		if ( self::has_option( $suffix ) ) {
			return [
				'value'  => self::option( $suffix ),
				'name'   => self::OPTION_PREFIX . $suffix,
				'source' => 'option',
			];
		}
		return [
			'value'  => $original,
			'name'   => '',
			'source' => 'default',
		];
	}
}
