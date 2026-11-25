<?php
/**
 * Test fixture of a site that ran the original plugin: its options and a plugin namespace of routes.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Legacy\Importer;

/**
 * Writes and removes the options the original plugin keeps, and registers the `acme/v1` test routes.
 */
final class Rfrac_Legacy_Fixture {

	/**
	 * The endpoints of `wp/v2` the original allows on a stock site.
	 *
	 * @var list<string>
	 */
	const WP_ENDPOINTS = array( 'posts', 'pages', 'media', 'categories', 'tags', 'statuses', 'taxonomies', 'types', 'users', 'comments' );

	/**
	 * The test routes: the `items` collection and its item route.
	 *
	 * @var list<string>
	 */
	const ACME_ROUTES = array( '/acme/v1/items', '/acme/v1/items/(?P<id>\d+)' );

	/**
	 * The label of the cache lifetime when both of its options exist.
	 *
	 * @var string
	 */
	const TTL_OPTIONS_LABEL = 'wp_rest_cache_timeout * wp_rest_cache_timeout_interval';

	/**
	 * The endpoints of `acme/v1` the request methods fixture allows: a route that reads and writes, a route that only
	 * writes and a route that only reads.
	 *
	 * @var list<string>
	 */
	const METHOD_ENDPOINTS = array( 'read-write', 'write-only', 'read-only' );

	/**
	 * The endpoints of `wp/v2` the metric fixture allows.
	 *
	 * @var list<string>
	 */
	const METRIC_WP_ENDPOINTS = array( 'posts', 'pages', 'media', 'categories', 'tags', 'users', 'comments', 'statuses', 'taxonomies', 'types' );

	/**
	 * The allowed endpoints of the metric fixture; an empty endpoint allows the whole namespace.
	 *
	 * @var array<string,list<string>>
	 */
	const METRIC_ALLOWED = array(
		'wp/v2'       => self::METRIC_WP_ENDPOINTS,
		'fixture/v1'  => array( 'items' ),
		'fixture2/v1' => array( '' ),
	);

	/**
	 * The disallowed endpoints of the metric fixture.
	 *
	 * @var array<string,list<string>>
	 */
	const METRIC_DISALLOWED = array( 'wp/v2' => array( 'comments' ) );

	/**
	 * The cacheable request headers of the metric fixture, by `namespace/endpoint`.
	 *
	 * @var array<string,string>
	 */
	const METRIC_HEADERS = array( 'fixture/v1/items' => 'X-Lang' );

	/**
	 * The uncached parameters of the metric fixture.
	 *
	 * @var list<string>
	 */
	const METRIC_UNCACHED_PARAMETERS = array( 'utm_source' );

	/**
	 * The routes of the metric fixture: a collection, its item route, a route that shares the collection's prefix, and a
	 * route of a namespace that is allowed as a whole.
	 *
	 * @var list<string>
	 */
	const METRIC_ROUTES = array( '/fixture/v1/items', '/fixture/v1/items/(?P<id>\d+)', '/fixture/v1/items-archive', '/fixture2/v1/things' );

	/**
	 * Remove every option of the original.
	 *
	 * @return void
	 */
	public static function clear(): void {
		foreach ( Importer::OPTIONS as $suffix ) {
			delete_option( 'wp_rest_cache_' . $suffix );
		}
	}

	/**
	 * Write the options of a stock site: the original's activator defaults, a lifetime of 1 × one year, and the
	 * allowed `wp/v2` endpoints plus `acme/v1 => ['items']`.
	 *
	 * @return void
	 */
	public static function install_stock(): void {
		self::write_options(
			array(
				'wp/v2'   => self::WP_ENDPOINTS,
				'acme/v1' => array( 'items' ),
			)
		);
	}

	/**
	 * Write the options of a site whose original cached other request methods: the allowed endpoints are `wp/v2`
	 * `posts` plus the routes of {@see self::register_method_routes()}.
	 *
	 * @param list<string> $methods The allowed request methods.
	 *
	 * @return void
	 */
	public static function install_methods( array $methods ): void {
		self::write_options(
			array(
				'wp/v2'   => array( 'posts' ),
				'acme/v1' => self::METHOD_ENDPOINTS,
			)
		);
		update_option( 'wp_rest_cache_allowed_request_methods', $methods, false );
	}

	/**
	 * Write the options of the metric fixture: the original's activator defaults with a lifetime of 1 × one year, the
	 * allowed and disallowed endpoints, the cacheable request header and the uncached parameter of the metric.
	 *
	 * @return void
	 */
	public static function install_metric(): void {
		self::write_options( self::METRIC_ALLOWED, self::METRIC_DISALLOWED, self::METRIC_HEADERS, self::METRIC_UNCACHED_PARAMETERS );
	}

	/**
	 * Write the options the original's activator creates, with the given endpoint lists.
	 *
	 * @param array<string,list<string>> $allowed    Allowed endpoints by namespace.
	 * @param array<string,list<string>> $disallowed Disallowed endpoints by namespace.
	 * @param array<string,string>       $headers    Cacheable request headers by `namespace/endpoint`.
	 * @param list<string>               $uncached   Uncached parameters.
	 *
	 * @return void
	 */
	private static function write_options( array $allowed, array $disallowed = array(), array $headers = array(), array $uncached = array() ): void {
		self::clear();
		add_option( 'wp_rest_cache_allowed_endpoints', $allowed, '', false );
		add_option( 'wp_rest_cache_disallowed_endpoints', $disallowed, '', false );
		add_option( 'wp_rest_cache_rest_prefix', 'wp-json', '', false );
		add_option( 'wp_rest_cache_cacheable_request_headers', $headers, '', false );
		add_option( 'wp_rest_cache_allowed_request_methods', array( 'GET' ), '', false );
		add_option( 'wp_rest_cache_uncached_parameters', $uncached, '', false );
		add_option( 'wp_rest_cache_hit_recording', 1, '', true );
		add_option( 'wp_rest_cache_timeout', 1 );
		add_option( 'wp_rest_cache_timeout_interval', 31536000 );
		add_option( 'wp_rest_cache_database_version', '2024.1.0' );
	}

	/**
	 * Register the `acme/v1` test routes; hook it on `rest_api_init`.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$args = array(
			'methods'             => 'GET',
			'callback'            => '__return_empty_array',
			'permission_callback' => '__return_true',
		);
		register_rest_route( 'acme/v1', '/items', $args );
		register_rest_route( 'acme/v1', '/items/(?P<id>\d+)', $args );
	}

	/**
	 * Register the `acme/v1` routes of the request methods fixture, see {@see self::METHOD_ENDPOINTS}; hook it on
	 * `rest_api_init`.
	 *
	 * @return void
	 */
	public static function register_method_routes(): void {
		$handler = static function ( string $methods ): array {
			return array(
				'methods'             => $methods,
				'callback'            => '__return_empty_array',
				'permission_callback' => '__return_true',
			);
		};
		register_rest_route( 'acme/v1', '/read-write', array( $handler( 'GET' ), $handler( 'POST' ) ) );
		register_rest_route( 'acme/v1', '/write-only', $handler( 'POST' ) );
		register_rest_route( 'acme/v1', '/read-only', $handler( 'GET' ) );
	}

	/**
	 * Register the routes of the metric fixture; hook it on `rest_api_init`.
	 *
	 * Every route answers a non-empty body, which the engine stores. The items collection answers with the
	 * `X-Lang` request header it was asked with, so the entries of two languages differ.
	 *
	 * @return void
	 */
	public static function register_metric_routes(): void {
		$route = static function ( string $namespace, string $path, callable $callback ): void {
			register_rest_route(
				$namespace,
				$path,
				array(
					'methods'             => 'GET',
					'callback'            => $callback,
					'permission_callback' => '__return_true',
				)
			);
		};

		$route(
			'fixture/v1',
			'/items',
			static fn( WP_REST_Request $request ): array => array(
				'items' => array( 1, 2, 3 ),
				'lang'  => (string) $request->get_header( 'x_lang' ),
			)
		);
		$route( 'fixture/v1', '/items/(?P<id>\d+)', static fn( WP_REST_Request $request ): array => array( 'id' => (int) $request['id'] ) );
		$route( 'fixture/v1', '/items-archive', static fn(): array => array( 'archive' => array( 2020, 2021 ) ) );
		$route( 'fixture2/v1', '/things', static fn(): array => array( 'things' => array( 'a', 'b' ) ) );
	}

	/**
	 * The request paths of the end-to-end import metric, below the REST prefix: the base path of every route the
	 * metric options match, then a query string, an item route and an ignored parameter.
	 *
	 * @return list<string>
	 */
	public static function metric_urls(): array {
		$urls = array();
		foreach ( self::METRIC_WP_ENDPOINTS as $endpoint ) {
			$urls[] = '/wp/v2/' . $endpoint;
		}
		foreach ( self::METRIC_ROUTES as $route ) {
			if ( false === strpos( $route, '(' ) ) {
				$urls[] = $route;
			}
		}
		return array_merge( $urls, array( '/wp/v2/posts?per_page=2', '/fixture/v1/items/5', '/fixture/v1/items?utm_source=news' ) );
	}

	/**
	 * Whether the original plugin would cache a request: the oracle of the end-to-end import metric.
	 *
	 * A copy of the endpoint matching of the original's `skip_caching()` for a request in the path form, read from
	 * the options the original keeps (`../wp-rest-cache/includes/api/class-endpoint-api.php:370-433`): the request
	 * is cached when an allowed endpoint matches it, as a substring of the request URI or, failing that, as a regular
	 * expression, and no disallowed endpoint matches it the same way. A disallowed endpoint wins. The original's
	 * other checks (method, nonce, `skip_cache`) do not apply to a plain anonymous GET request.
	 *
	 * @param string $path Request path below the REST prefix, with its query string, e.g. `/wp/v2/posts?per_page=2`.
	 *
	 * @return bool
	 */
	public static function original_caches( string $path ): bool {
		// Source line 371: the REST prefix as the original reads it. The request URI is the path form of the request.
		$rest_prefix = sprintf( '/%s/', get_option( 'wp_rest_cache_rest_prefix', 'wp-json' ) );
		$request_uri = $rest_prefix . ltrim( $path, '/' );

		// Source lines 381-407: one allowed endpoint that matches is enough, otherwise the request is not cached.
		$allowed = false;
		foreach ( (array) get_option( 'wp_rest_cache_allowed_endpoints', array() ) as $namespace => $endpoints ) {
			foreach ( (array) $endpoints as $endpoint ) {
				if ( self::original_matches( $rest_prefix . $namespace . '/' . $endpoint, $request_uri ) ) {
					$allowed = true;
					break 2;
				}
			}
		}
		if ( ! $allowed ) {
			return false;
		}

		// Source lines 409-431: a disallowed endpoint that matches the same way wins.
		foreach ( (array) get_option( 'wp_rest_cache_disallowed_endpoints', array() ) as $namespace => $endpoints ) {
			foreach ( (array) $endpoints as $endpoint ) {
				if ( self::original_matches( $rest_prefix . $namespace . '/' . $endpoint, $request_uri ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * The match of one endpoint URI against the request URI: a substring, or the unencoded endpoint URI as a pattern.
	 *
	 * Source lines 391-401 and 418-426 of the original's `class-endpoint-api.php`.
	 *
	 * @param string $endpoint_uri Prefix, namespace and endpoint, e.g. `/wp-json/wp/v2/posts`.
	 * @param string $request_uri  Request URI.
	 *
	 * @return bool
	 */
	private static function original_matches( string $endpoint_uri, string $request_uri ): bool {
		if ( false !== strpos( $request_uri, $endpoint_uri ) ) {
			return true;
		}
		// A pattern that does not compile does not match, as in the original.
		return 1 === @preg_match( '#' . $endpoint_uri . '#', $request_uri ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
