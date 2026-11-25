<?php
/**
 * Drives a request through the real serving chain for the end-to-end tests.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Config\Deny_List;
use Rockfire\Rest_Api_Cache\Install\Mu_Installer;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Schema\Schema;
use Rockfire\Rest_Api_Cache\Serving\Capture;
use Rockfire\Rest_Api_Cache\Serving\Early_Server;
use Rockfire\Rest_Api_Cache\Serving\Fallback_Server;
use Rockfire\Rest_Api_Cache\Serving\Hit_Filters_Interface;
use Rockfire\Rest_Api_Cache\Serving\Lookup;
use Rockfire\Rest_Api_Cache\Serving\Request_State;
use Rockfire\Rest_Api_Cache\Serving\Response_Writer;

/**
 * One simulated request: sets the globals, runs the lookup of the chosen serving path and, on a miss, the
 * live REST request with the engine's capture hooked.
 *
 * The lookup runs `Early_Server::boot()` (path `early`) or `Fallback_Server::maybe_serve()` (path `fallback`)
 * with a terminate closure that throws {@see Rfrac_Test_Exit}; emitted headers and output are captured and the
 * queries of the lookup are counted through the `query` filter. The REST request runs through a spy server with
 * a fresh container (a new capture per request, like a new PHP process), and `headers_list()` is replaced by
 * {@see self::$php_headers}, since the CLI sends no headers.
 */
final class Rfrac_Serving_Harness {

	public const EARLY    = 'early';
	public const FALLBACK = 'fallback';

	/**
	 * Header lines emitted by the hit writer.
	 *
	 * @var string[]
	 */
	public array $headers = array();

	/**
	 * Output of the hit writer.
	 *
	 * @var string
	 */
	public string $output = '';

	/**
	 * Whether the last lookup served a hit (ended the request).
	 *
	 * @var bool
	 */
	public bool $hit = false;

	/**
	 * Queries run by the last lookup.
	 *
	 * @var string[]
	 */
	public array $queries = array();

	/**
	 * Header lines a route callback "sent" with header(); seen by the capture's store verdict.
	 *
	 * @var string[]
	 */
	public array $php_headers = array();

	/**
	 * The spy server of the last live request.
	 *
	 * @var Spy_REST_Server|null
	 */
	public ?Spy_REST_Server $server = null;

	/**
	 * Hit filter set handed to the lookup of either path and to the capture; null takes the one the site opted into
	 * (see {@see Lookup::hit_filters()}), which is none unless `RFRAC_LEGACY_HIT_FILTERS` is defined.
	 *
	 * @var Hit_Filters_Interface|null
	 */
	public ?Hit_Filters_Interface $filters = null;

	/**
	 * Container of the last live request.
	 *
	 * @var Plugin|null
	 */
	private ?Plugin $plugin = null;

	/**
	 * Whether the live request also registers the legacy hook bridges.
	 *
	 * @var bool
	 */
	private bool $legacy = false;

	/**
	 * Saved superglobals.
	 *
	 * @var array<string,mixed>
	 */
	private array $saved;

	/**
	 * Constructor.
	 *
	 * @param string $path `early` or `fallback`.
	 */
	public function __construct( private readonly string $path ) {
		$this->saved = array(
			'server' => $_SERVER,
			'get'    => $_GET,
			'post'   => $_POST,
			'cookie' => $_COOKIE,
		);
	}

	/**
	 * Restore the superglobals and per-request state.
	 *
	 * @return void
	 */
	public function restore(): void {
		$_SERVER = $this->saved['server'];
		$_GET    = $this->saved['get'];
		$_POST   = $this->saved['post'];
		$_COOKIE = $this->saved['cookie'];
		unset( $GLOBALS['HTTP_RAW_POST_DATA'] );
		$this->detach();
		Early_Server::use_writer( null );
		Early_Server::use_hit_filters( null );
		Request_State::reset();
	}

	/**
	 * The serving path under test.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Register the legacy hook bridges on the live request, as the booted plugin does.
	 *
	 * @param bool $on Whether {@see self::serve()} also calls `register_legacy_hooks()` on its container.
	 *
	 * @return $this
	 */
	public function with_legacy( bool $on = true ): self {
		$this->legacy = $on;
		return $this;
	}

	/**
	 * Point the superglobals at a request (a GET one by default).
	 *
	 * @param string              $uri          Request URI.
	 * @param array<string,mixed> $server       Extra `$_SERVER` entries.
	 * @param array<string,mixed> $cookie       Cookies.
	 * @param string              $method       Request method.
	 * @param string|null         $body         Raw request body: sets `CONTENT_LENGTH` and `HTTP_RAW_POST_DATA`, and for a
	 *                                          form POST `$_POST`.
	 * @param string              $content_type Content-Type header (`CONTENT_TYPE`); empty sends none.
	 *
	 * @return $this
	 */
	public function request( string $uri, array $server = array(), array $cookie = array(), string $method = 'GET', ?string $body = null, string $content_type = '' ): self {
		$_SERVER = array_merge( $this->saved['server'], array( 'REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri ), $server ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$_GET    = array();
		$_POST   = array();
		parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $_GET );
		$_COOKIE = $cookie;

		unset( $GLOBALS['HTTP_RAW_POST_DATA'] );
		if ( '' !== $content_type ) {
			$_SERVER['CONTENT_TYPE'] = $content_type;
		}
		if ( null !== $body ) {
			$_SERVER['CONTENT_LENGTH']     = (string) strlen( $body );
			$GLOBALS['HTTP_RAW_POST_DATA'] = $body;
			if ( 'POST' === $method && 'application/x-www-form-urlencoded' === strtolower( trim( explode( ';', $content_type )[0] ) ) ) {
				$post = array();
				parse_str( $body, $post );
				$_POST = wp_slash( $post ); // As wp_magic_quotes() leaves it; the context unslashes it again.
			}
		}
		return $this;
	}

	/**
	 * A writer capturing headers and output, ending the request with {@see Rfrac_Test_Exit}.
	 *
	 * @return Response_Writer
	 */
	private function writer(): Response_Writer {
		return new Response_Writer(
			function ( string $line ): void {
				$this->headers[] = $line;
			},
			function (): never {
				throw new Rfrac_Test_Exit( 'exit' );
			},
			function ( string $out ): void {
				$this->output .= $out;
			},
			static function ( int $code ): void {}
		);
	}

	/**
	 * Run the lookup of the chosen serving path for the current request.
	 *
	 * @param Closure|null $store_factory Fallback path only: `( Compiled_Config ): Store_Interface`, e.g. to inject the sampling RNG.
	 *
	 * @return bool Whether the request was served from the cache.
	 */
	public function lookup( ?Closure $store_factory = null ): bool {
		Request_State::reset();
		$this->headers = array();
		$this->output  = '';
		$this->hit     = false;
		$this->queries = array();

		$record = function ( $query ) {
			$this->queries[] = (string) $query;
			return $query;
		};
		add_filter( 'query', $record, PHP_INT_MAX );
		try {
			if ( self::EARLY === $this->path ) {
				Early_Server::use_writer( $this->writer() );
				Early_Server::use_hit_filters( $this->filters );
				Early_Server::boot( Mu_Installer::TEMPLATE_VERSION );
			} elseif ( null === $store_factory && null === $this->filters ) {
				( new Plugin( $this->writer() ) )->fallback_server()->maybe_serve();
			} else {
				$plugin = new Plugin();
				$lookup = new Lookup( $plugin->policy(), $plugin->repository(), $this->writer(), $plugin->cache_key(), $store_factory, $this->filters );
				( new Fallback_Server( $lookup, $plugin->compiled_config() ) )->maybe_serve();
			}
		} catch ( Rfrac_Test_Exit $e ) {
			$this->hit = true;
		} finally {
			remove_filter( 'query', $record, PHP_INT_MAX );
			Early_Server::use_writer( null );
			Early_Server::use_hit_filters( null );
		}
		return $this->hit;
	}

	/**
	 * Record the lookup of the simulated request as a cacheable miss that saw the current flush epoch, unless a lookup is recorded.
	 *
	 * Capture stores only for a request whose lookup recorded a flush epoch. A test that runs the live request without
	 * the lookup before it calls this first, as the fallback lookup would have; a lookup the test ran or recorded stays.
	 * Without a compiled configuration of the current schema there is nothing to record, as for the fallback server.
	 *
	 * @return void
	 */
	public static function record_lookup_at_current_epoch(): void {
		if ( Request_State::lookup_done() ) {
			return;
		}
		$config = Compiled_Config::load();
		if ( null === $config || Schema::VERSION !== $config->schema_version() ) {
			return;
		}
		$plugin  = Plugin::instance();
		$context = Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() );
		Request_State::record_lookup( $context, $plugin->policy()->evaluate( $context, $config ), null, $plugin->repository()->flush_epoch() );
	}

	/**
	 * Run the live REST request of a route for the current request, with the engine's hooks and capture.
	 *
	 * A request without a lookup first gets the one of a cacheable miss at the current epoch
	 * ({@see self::record_lookup_at_current_epoch()}); {@see self::lookup()} before it records the real one.
	 *
	 * @param string        $route    Route, e.g. `/wp/v2/posts` (without query string).
	 * @param callable|null $register Registers extra routes (runs on `rest_api_init`).
	 *
	 * @return string The echoed body.
	 */
	public function serve( string $route, ?callable $register = null ): string {
		self::record_lookup_at_current_epoch();
		$this->detach();
		$plugin = new Plugin();
		$plugin->register_engine_hooks();
		if ( $this->legacy ) {
			$plugin->register_legacy_hooks();
		}
		$this->plugin = $plugin;
		$this->preset_capture( $plugin );

		$server                    = new Spy_REST_Server();
		$this->server              = $server;
		$GLOBALS['wp_rest_server'] = $server;
		if ( null !== $register ) {
			add_action( 'rest_api_init', $register, 99 );
		}
		do_action( 'rest_api_init', $server );
		if ( null !== $register ) {
			remove_action( 'rest_api_init', $register, 99 );
		}

		// PHPUnit has already written output, so core's header() calls would raise warnings.
		set_error_handler(
			static fn( int $errno, string $errstr ): bool => false !== strpos( $errstr, 'headers already sent' ),
			E_WARNING
		);
		try {
			$server->serve_request( $route );
		} finally {
			restore_error_handler();
		}
		return $server->sent_body;
	}

	/**
	 * A complete request: the lookup, and when it misses the live request.
	 *
	 * @param string        $route    Route.
	 * @param callable|null $register Registers extra routes.
	 *
	 * @return string|null The live body, null when the lookup served a hit.
	 */
	public function miss( string $route, ?callable $register = null ): ?string {
		if ( $this->lookup() ) {
			return null;
		}
		return $this->serve( $route, $register );
	}

	/**
	 * The value of a hit header (case-insensitive name), null when not emitted.
	 *
	 * @param string $name Header name.
	 *
	 * @return string|null
	 */
	public function hit_header( string $name ): ?string {
		foreach ( $this->headers as $line ) {
			[ $key, $value ] = array_pad( explode( ':', $line, 2 ), 2, '' );
			if ( 0 === strcasecmp( trim( $key ), $name ) ) {
				return trim( $value );
			}
		}
		return null;
	}

	/**
	 * A header of the live response (case-insensitive name), null when not sent.
	 *
	 * @param string $name Header name.
	 *
	 * @return string|null
	 */
	public function live_header( string $name ): ?string {
		foreach ( null !== $this->server ? $this->server->sent_headers : array() as $key => $value ) {
			if ( 0 === strcasecmp( (string) $key, $name ) ) {
				return (string) $value;
			}
		}
		return null;
	}

	/**
	 * Queries of the last lookup that read or write the cache tables, matching a verb.
	 *
	 * @param string $verb `SELECT`, `UPDATE`, `INSERT`, `DELETE` or a regular expression fragment.
	 *
	 * @return string[]
	 */
	public function table_queries( string $verb = 'SELECT' ): array {
		$tables = '(' . preg_quote( Names::TABLE_ENTRIES, '/' ) . '|' . preg_quote( Names::TABLE_TAGS, '/' ) . ')';
		return array_values(
			array_filter(
				$this->queries,
				static fn( string $query ): bool => 1 === preg_match( '/^\s*(' . $verb . ')\b/i', $query ) && 1 === preg_match( '/' . $tables . '/', $query )
			)
		);
	}

	/**
	 * Every value of every column of the cache tables, as one string.
	 *
	 * @return string
	 */
	public static function database_dump(): string {
		global $wpdb;
		$dump = '';
		foreach ( array( Names::TABLE_ENTRIES, Names::TABLE_TAGS ) as $table ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $wpdb->prefix . $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $row ) {
				$dump .= implode( "\n", array_map( 'strval', $row ) ) . "\n";
			}
		}
		return $dump;
	}

	/**
	 * A complete compiled-config snapshot that is current (no recompile on `rest_api_init`).
	 *
	 * @param array<string,mixed>                 $overrides Top-level fields.
	 * @param array<int,array<string,mixed>>|null $routes    Routes (default: `/wp/v2/posts` and `/tst/v1/*`).
	 *
	 * @return array<string,mixed>
	 */
	public static function snapshot( array $overrides = array(), ?array $routes = null ): array {
		$base = Compiled_Config::defaults();
		return array_merge(
			$base,
			array(
				'universal_params'       => Config_Compiler::UNIVERSAL_PARAMS,
				'bypass_cookie_prefixes' => Config_Compiler::BYPASS_COOKIE_PREFIXES,
				'bypass_headers'         => Config_Compiler::BYPASS_HEADERS,
				'replay_headers'         => Config_Compiler::REPLAY_HEADERS,
				'schema_version'         => Schema::VERSION,
				'fingerprint'            => 'e2e',
				'hit_sampling'           => 0,
				'routes'                 => $routes ?? array( self::route( '/wp/v2/posts' ), self::wildcard_route( '/tst/v1/*' ) ),
			),
			$overrides
		);
	}

	/**
	 * A route rule as stored in the compiled config.
	 *
	 * @param string              $key       Route key.
	 * @param array<string,mixed> $overrides Rule fields.
	 *
	 * @return array<string,mixed>
	 */
	public static function route( string $key, array $overrides = array() ): array {
		return array_merge(
			array(
				'key'            => $key,
				'regex'          => '@^' . $key . '$@i',
				'ttl'            => 3600,
				'param_mode'     => 'registered',
				'allowed_params' => array( 'context', 'page', 'per_page', 'search', 'status' ),
				'ignore_params'  => array(),
				'bypass_params'  => array(),
				'vary_headers'   => array(),
			),
			$overrides
		);
	}

	/**
	 * A wildcard route rule as stored in the compiled config.
	 *
	 * @param string              $key       Wildcard key, e.g. `/tst/v1/*`.
	 * @param array<string,mixed> $overrides Rule fields.
	 *
	 * @return array<string,mixed>
	 */
	public static function wildcard_route( string $key, array $overrides = array() ): array {
		return self::route( $key, array_merge( array( 'regex' => Deny_List::wildcard_regex( $key ) ), $overrides ) );
	}

	/**
	 * Replace the container's capture by one that sees {@see self::$php_headers} instead of `headers_list()`.
	 *
	 * @param Plugin $plugin Container.
	 *
	 * @return void
	 */
	private function preset_capture( Plugin $plugin ): void {
		$capture = new Capture(
			$plugin->policy(),
			$plugin->repository(),
			$plugin->tag_provider(),
			$plugin->compiled_config(),
			static fn( Compiled_Config $config ): Request_Context => Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() ),
			$plugin->cache_key(),
			null,
			fn(): array => $this->php_headers,
			// Without a set of its own the capture gets what the container resolves, like the lookup of either path.
			$this->filters ?? $plugin->hit_filters()
		);
		( new ReflectionProperty( Plugin::class, 'capture' ) )->setValue( $plugin, $capture );
	}

	/**
	 * Remove the engine and legacy hooks of the last live request's container.
	 *
	 * @return void
	 */
	private function detach(): void {
		if ( null === $this->plugin ) {
			return;
		}
		foreach ( array_merge( $this->plugin->engine_hooks(), $this->plugin->legacy_hooks() ) as $hook ) {
			remove_filter( $hook['hook'], $hook['callback'], $hook['priority'] );
		}
		$this->plugin = null;
	}
}
