<?php
/**
 * Settings schema view and sanitising for the Settings page.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Services;

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Route_Rule;
use Rockfire\Rest_Api_Cache\Config\Settings_Schema;
use Rockfire\Rest_Api_Cache\Names;

defined( 'ABSPATH' ) || exit;

/**
 * The settings as the Settings page and WP-CLI see them: the field schema, the effective values with
 * their constant locks, and the sanitize callback of the Settings API. It writes nothing itself;
 * {@see Config} and the Settings API persist.
 *
 * @since 1.0.0
 */
class Settings_Service {

	/**
	 * Settings error group.
	 *
	 * @var string
	 */
	public const ERROR_SLUG = 'rfrac_settings';

	/**
	 * Settings.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Constructor.
	 *
	 * @param Config $config Settings.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * The editable settings and how to present them.
	 *
	 * `default_ttl` has the effective `max_ttl` as its maximum.
	 *
	 * @return array<string,array<string,mixed>> Key => `type` (`int`, `enum`, `list` or `bool`), `label`, `description`, `section`
	 *                                           (`defaults`, `request`, `limits` or `performance`) and, for integers, `min`,
	 *                                           `max` and optionally `unit`; for enums `options` (value => label); for
	 *                                           lists `item` (`param` or `header`) and `max_items`. A `bool` is a checkbox.
	 */
	public function schema(): array {
		$max_ttl     = (int) $this->config->settings()['max_ttl'];
		$def         = Settings_Schema::definitions();
		$max_ignored = $def['global_ignore_params']['max_items'] ?? Route_Rule::MAX_PARAM_NAMES;
		$max_headers = $def['global_vary_headers']['max_items'] ?? Route_Rule::MAX_VARY_HEADERS;

		return array(
			'default_ttl'          => array(
				'type'        => 'int',
				'label'       => __( 'Default TTL', 'rockfire-rest-api-cache' ),
				'description' => __( 'How long a cached response stays valid when a route sets no TTL of its own.', 'rockfire-rest-api-cache' ),
				'min'         => $def['default_ttl']['min'] ?? Settings_Schema::MIN_TTL,
				'max'         => $max_ttl,
				'unit'        => __( 'seconds', 'rockfire-rest-api-cache' ),
				'section'     => 'defaults',
			),
			'max_ttl'              => array(
				'type'        => 'int',
				'label'       => __( 'Maximum TTL', 'rockfire-rest-api-cache' ),
				'description' => __( 'The longest TTL any route can have.', 'rockfire-rest-api-cache' ),
				'min'         => $def['max_ttl']['min'] ?? 0,
				'max'         => $def['max_ttl']['max'] ?? PHP_INT_MAX,
				'unit'        => __( 'seconds', 'rockfire-rest-api-cache' ),
				'section'     => 'defaults',
			),
			'max_entries'          => array(
				'type'        => 'int',
				'label'       => __( 'Maximum entries', 'rockfire-rest-api-cache' ),
				'description' => __( 'The number of active cache entries kept; the least recently used ones are evicted above it. Between two maintenance runs the cache may hold up to 1.5 times this number.', 'rockfire-rest-api-cache' ),
				'min'         => $def['max_entries']['min'] ?? 0,
				'max'         => $def['max_entries']['max'] ?? PHP_INT_MAX,
				'section'     => 'limits',
			),
			'max_payload_kb'       => array(
				'type'        => 'int',
				'label'       => __( 'Maximum response size', 'rockfire-rest-api-cache' ),
				'description' => __( 'Responses larger than this are not cached. With the database store, the active entries can take up to about 1.5 × Maximum entries × this size of database space; flushed entries keep their space until the hourly maintenance deletes them.', 'rockfire-rest-api-cache' ),
				'min'         => $def['max_payload_kb']['min'] ?? 0,
				'max'         => $def['max_payload_kb']['max'] ?? PHP_INT_MAX,
				'unit'        => __( 'KB', 'rockfire-rest-api-cache' ),
				'section'     => 'limits',
			),
			'max_params'           => array(
				'type'        => 'int',
				'label'       => __( 'Maximum query parameters', 'rockfire-rest-api-cache' ),
				'description' => __( 'Requests with more query parameters are not cached.', 'rockfire-rest-api-cache' ),
				'min'         => $def['max_params']['min'] ?? 0,
				'max'         => $def['max_params']['max'] ?? PHP_INT_MAX,
				'section'     => 'limits',
			),
			'hit_sampling'         => array(
				'type'        => 'int',
				'label'       => __( 'Hit sampling', 'rockfire-rest-api-cache' ),
				'description' => __( 'Record one in this many cache hits in the hit counter. 0 turns hit counting off.', 'rockfire-rest-api-cache' ),
				'min'         => $def['hit_sampling']['min'] ?? 0,
				'max'         => $def['hit_sampling']['max'] ?? PHP_INT_MAX,
				'section'     => 'performance',
			),
			'store'                => array(
				'type'        => 'enum',
				'label'       => __( 'Storage', 'rockfire-rest-api-cache' ),
				'description' => __( 'Where cached responses are stored. Automatic uses the object cache when a persistent one is available.', 'rockfire-rest-api-cache' ),
				'options'     => $this->store_options( $def['store']['values'] ?? Settings_Schema::STORE_MODES ),
				'section'     => 'performance',
			),
			'global_ignore_params' => array(
				'type'        => 'list',
				'label'       => __( 'Ignored query parameters', 'rockfire-rest-api-cache' ),
				'description' => sprintf(
					/* translators: %d: the number of parameters a list can hold. */
					__( 'Query parameters that never change the cache key on any route, such as utm_source and fbclid. One per line, at most %d. A parameter a route registers is never ignored on that route.', 'rockfire-rest-api-cache' ),
					$max_ignored
				),
				'item'        => Settings_Schema::ITEM_PARAM,
				'max_items'   => $max_ignored,
				'section'     => 'request',
			),
			'global_vary_headers'  => array(
				'type'        => 'list',
				'label'       => __( 'Vary request headers', 'rockfire-rest-api-cache' ),
				'description' => sprintf(
					/* translators: %d: the number of headers a route can vary by. */
					__( 'Request headers that vary the cache key on every route, such as Accept-Language. One per line. At most %d headers together with each route\'s own; Authorization, Cookie and similar credential headers are not allowed.', 'rockfire-rest-api-cache' ),
					$max_headers
				),
				'item'        => Settings_Schema::ITEM_HEADER,
				'max_items'   => $max_headers,
				'section'     => 'request',
			),
			'nonce_caching'        => array(
				'type'        => 'bool',
				'label'       => __( 'Cache requests with a REST nonce', 'rockfire-rest-api-cache' ),
				'description' => __( 'Logged-out requests that carry a REST nonce (the X-WP-Nonce header or the _wpnonce parameter), as wp.apiFetch and WooCommerce Blocks send them, are cached. The nonce is never checked on a cache hit, and any login still bypasses the cache.', 'rockfire-rest-api-cache' ),
				'section'     => 'request',
			),
		);
	}

	/**
	 * Labels of the storage modes, in the order of {@see Settings_Schema::STORE_MODES}.
	 *
	 * @param string[] $modes Mode values.
	 * @phpstan-param list<string> $modes
	 *
	 * @return array<string,string> Value => label.
	 */
	private function store_options( array $modes ): array {
		$labels  = array(
			'auto'         => __( 'Automatic', 'rockfire-rest-api-cache' ),
			'database'     => __( 'Database', 'rockfire-rest-api-cache' ),
			'object_cache' => __( 'Object cache', 'rockfire-rest-api-cache' ),
		);
		$options = array();
		foreach ( $modes as $mode ) {
			$options[ $mode ] = $labels[ $mode ] ?? $mode;
		}
		return $options;
	}

	/**
	 * The effective value of every editable setting with its lock.
	 *
	 * @return array<string,array{value:mixed,locked:bool,constant:?string}>
	 */
	public function view(): array {
		$effective = $this->config->settings();
		$locked    = $this->config->locked();
		$view      = array();
		foreach ( array_keys( $this->schema() ) as $key ) {
			$view[ $key ] = array(
				'value'    => $effective[ $key ] ?? null,
				'locked'   => isset( $locked[ $key ] ),
				'constant' => $locked[ $key ] ?? null,
			);
		}
		return $view;
	}

	/**
	 * Settings API sanitize callback: {@see Config::sanitize_settings()} plus a message for every problem.
	 *
	 * A value that is not valid (not a whole number, not one of the options, a list with an invalid or too
	 * many entries) keeps the stored value and reports an `error` with the code `rfrac_invalid_{key}`, which
	 * gives the reason for a list; global vary headers that would make a route vary on too many headers keep
	 * the stored value too and name that route in the same kind of error; a number outside its range is clamped and reports a `warning`
	 * (`rfrac_clamped_{key}`). `default_ttl` is checked against the effective `max_ttl`, including one
	 * locked by a constant. Locked settings keep their stored value (and give no message); `routes` are
	 * preserved.
	 *
	 * @param mixed $input Submitted value.
	 *
	 * @return array<string,mixed> The settings to store.
	 */
	public function sanitize( mixed $input ): array {
		$clean = $this->config->sanitize_settings( $input );
		if ( ! is_array( $input ) ) {
			return $clean;
		}

		if ( ! function_exists( 'add_settings_error' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}

		$locked  = $this->config->locked();
		$max_ttl = isset( $locked['max_ttl'] ) ? (int) $this->config->settings()['max_ttl'] : (int) ( $clean['max_ttl'] ?? $this->config->settings()['max_ttl'] );

		// A locked max_ttl is not part of the validated base, so the default TTL is held to it here.
		if ( ! isset( $locked['default_ttl'] ) && isset( $clean['default_ttl'] ) && (int) $clean['default_ttl'] > $max_ttl ) {
			$clean['default_ttl'] = max( Settings_Schema::MIN_TTL, $max_ttl );
		}

		foreach ( $this->schema() as $key => $field ) {
			if ( isset( $locked[ $key ] ) || ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$normalized = Settings_Schema::normalize_value( $key, $input[ $key ], $max_ttl );
			if ( null === $normalized ) {
				$reason = Settings_Schema::list_error( $key, $input[ $key ] );
				if ( null !== $reason ) {
					$message = sprintf(
						/* translators: 1: setting label, 2: the reason the value was not accepted. */
						__( '%1$s: %2$s The previous value was kept.', 'rockfire-rest-api-cache' ),
						is_string( $field['label'] ) ? $field['label'] : $key,
						esc_html( $reason )
					);
				} else {
					$message = sprintf(
						/* translators: 1: setting label, 2: the submitted value */
						__( '%1$s: "%2$s" is not a valid value; the previous value was kept.', 'rockfire-rest-api-cache' ),
						$field['label'],
						$this->echo_value( $input[ $key ] )
					);
				}
				add_settings_error( self::ERROR_SLUG, Names::PREFIX . 'invalid_' . $key, $message, 'error' );
				continue;
			}
			if ( 'global_vary_headers' === $key && is_array( $normalized ) && ( $clean[ $key ] ?? null ) !== $normalized ) {
				// The list is valid but does not fit the route rules, so sanitize_settings() kept the stored one.
				$overflow = $this->config->vary_fit( array_values( array_filter( $normalized, 'is_string' ) ) )->errors()[0] ?? null;
				if ( null !== $overflow ) {
					add_settings_error(
						self::ERROR_SLUG,
						Names::PREFIX . 'invalid_' . $key,
						sprintf(
							/* translators: 1: setting label, 2: the reason the value was not accepted. */
							__( '%1$s: %2$s The previous value was kept.', 'rockfire-rest-api-cache' ),
							is_string( $field['label'] ) ? $field['label'] : $key,
							esc_html( $overflow['message'] )
						),
						'error'
					);
					continue;
				}
			}
			if ( 'int' !== $field['type'] || ! isset( $clean[ $key ] ) ) {
				continue;
			}
			$submitted = Settings_Schema::to_int( $input[ $key ] );
			if ( null === $submitted || $submitted === $clean[ $key ] ) {
				continue;
			}
			add_settings_error(
				self::ERROR_SLUG,
				Names::PREFIX . 'clamped_' . $key,
				sprintf(
					$submitted < $clean[ $key ]
						/* translators: 1: setting label, 2: the minimum that was saved */
						? __( '%1$s was raised to the minimum of %2$s.', 'rockfire-rest-api-cache' )
						/* translators: 1: setting label, 2: the maximum that was saved */
						: __( '%1$s was lowered to the maximum of %2$s.', 'rockfire-rest-api-cache' ),
					$field['label'],
					(string) $clean[ $key ]
				),
				'warning'
			);
		}

		return $clean;
	}

	/**
	 * A submitted value as safe, short text for a message.
	 *
	 * @param mixed $value Submitted value.
	 *
	 * @return string
	 */
	private function echo_value( mixed $value ): string {
		$text = is_scalar( $value ) ? (string) $value : gettype( $value );
		return mb_strlen( $text ) > 64 ? mb_substr( $text, 0, 64 ) . '...' : $text;
	}

	/**
	 * One effective setting.
	 *
	 * @param string $key Settings key.
	 *
	 * @return mixed The value; null for an unknown key.
	 */
	public function get( string $key ): mixed {
		return $this->config->settings()[ $key ] ?? null;
	}

	/**
	 * All effective settings (stored values with the constants applied), including `routes`.
	 *
	 * @return array<string,mixed>
	 */
	public function all_effective(): array {
		return $this->config->settings();
	}

	/**
	 * Where the effective value of every editable setting comes from.
	 *
	 * @return array<string,string> Key => `constant:RFRAC_X` (locked by a constant), `clamped` (the stored
	 *                              number was outside its range), `stored`, or `default` (nothing stored, or
	 *                              the stored value was invalid and the default is used). A list is
	 *                              `constant:RFRAC_X`, `stored` or `default`.
	 */
	public function sources(): array {
		$stored = get_option( Names::OPTION_SETTINGS, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$locked = $this->config->locked();
		$max    = (int) Settings_Schema::resolve( $stored )['max_ttl'];

		$sources = array();
		foreach ( array_keys( $this->schema() ) as $key ) {
			if ( isset( $locked[ $key ] ) ) {
				$sources[ $key ] = 'constant:' . $locked[ $key ];
				continue;
			}
			if ( ! array_key_exists( $key, $stored ) ) {
				$sources[ $key ] = 'default';
				continue;
			}
			$normalized = Settings_Schema::normalize_value( $key, $stored[ $key ], $max );
			if ( null === $normalized ) {
				$sources[ $key ] = 'default';
			} elseif ( is_int( $normalized ) && Settings_Schema::to_int( $stored[ $key ] ) !== $normalized ) {
				$sources[ $key ] = 'clamped';
			} else {
				$sources[ $key ] = 'stored';
			}
		}
		return $sources;
	}

	/**
	 * The stored compiled config (`rfrac_config`), for `wp rfrac config get --compiled`.
	 *
	 * @return array<string,mixed> Empty when nothing was compiled yet.
	 */
	public function compiled(): array {
		$stored = get_option( Names::OPTION_CONFIG, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Whether the compiled configuration has a flag switched on.
	 *
	 * Tells the Settings page that a flag is on although nothing is stored or locked for it: the compiler turned it on
	 * through a filter (a `rfrac/*` filter, or a carried-over callback of the original plugin).
	 *
	 * @param string $key Settings key of the flag, such as `nonce_caching`.
	 *
	 * @return bool
	 */
	public function compiled_flag( string $key ): bool {
		return true === ( $this->compiled()[ $key ] ?? null );
	}
}
