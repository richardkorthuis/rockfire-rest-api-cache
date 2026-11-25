<?php
/**
 * Tests for the capture of responses on rest_pre_echo_response.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Install\Mu_Installer;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Cache_Policy;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Capture;
use Rockfire\Rest_Api_Cache\Serving\Hit_Filters_Interface;
use Rockfire\Rest_Api_Cache\Serving\Lookup;
use Rockfire\Rest_Api_Cache\Serving\Null_Tag_Provider;
use Rockfire\Rest_Api_Cache\Serving\Refusal_Aware_Tag_Provider_Interface;
use Rockfire\Rest_Api_Cache\Serving\Request_State;
use Rockfire\Rest_Api_Cache\Serving\Response_Writer;
use Rockfire\Rest_Api_Cache\Serving\Rule_Aware_Tag_Provider_Interface;
use Rockfire\Rest_Api_Cache\Serving\Tag_Provider_Interface;
use Rockfire\Rest_Api_Cache\Storage\Cached_Response;
use Rockfire\Rest_Api_Cache\Storage\Entry_Data;
use Rockfire\Rest_Api_Cache\Storage\Object_Cache_Store;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Factory;
use Rockfire\Rest_Api_Cache\Storage\Store_Interface;

/**
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 * @covers \Rockfire\Rest_Api_Cache\Serving\Null_Tag_Provider
 */
class Test_Capture extends Rfrac_Serving_Test_Case {

	/**
	 * The capture under test.
	 *
	 * @var Capture
	 */
	private Capture $capture;

	/**
	 * Headers of the first main response, as seen after every other `rest_post_dispatch` filter.
	 *
	 * @var array<string,string>|null
	 */
	private ?array $seen_headers = null;

	/**
	 * Keys reported by `rfrac/stored`.
	 *
	 * @var string[]
	 */
	private array $stored_keys = array();

	/**
	 * Tags reported by `rfrac/stored`.
	 *
	 * @var string[][]
	 */
	private array $stored_tags = array();

	/**
	 * The spy server of the last served request.
	 *
	 * @var Spy_REST_Server|null
	 */
	private ?Spy_REST_Server $server = null;

	/**
	 * Header lines a route callback sent with header() (the `headers_list()` seam).
	 *
	 * @var string[]
	 */
	private array $php_sent = array();

	/**
	 * The external object cache flag to restore, null when the test did not change it.
	 *
	 * @var bool|null
	 */
	private ?bool $ext_object_cache = null;

	/**
	 * The entry the last flushing store wrote.
	 *
	 * @var Entry_Data|null
	 */
	private ?Entry_Data $written = null;

	/**
	 * Whether {@see self::serve()} leaves the request without a lookup instead of recording a cacheable miss at the current epoch.
	 *
	 * @var bool
	 */
	private bool $without_lookup = false;

	/**
	 * Whether the lookup in {@see Request_State} is the one {@see self::serve()} recorded for its last request (a test's own stays).
	 *
	 * @var bool
	 */
	private bool $lookup_of_serve = false;

	public function set_up() {
		parent::set_up();
		$this->written      = null;
		$this->seen_headers = null;
		$this->stored_keys  = array();
		$this->stored_tags  = array();
		$this->server       = null;
		$this->php_sent     = array();

		$this->without_lookup  = false;
		$this->lookup_of_serve = false;
		delete_option( Failure_Recorder::OPTION );
		wp_cache_delete( 'count', 'rfrac' );
		wp_cache_delete( 'recounted', 'rfrac' );
		$this->configure();
		add_action(
			Hooks::STORED,
			function ( string $key, string $route, array $tags ): void {
				$this->stored_keys[] = $key;
				$this->stored_tags[] = $tags;
			},
			10,
			3
		);
	}

	public function tear_down() {
		if ( null !== $this->ext_object_cache ) {
			wp_using_ext_object_cache( $this->ext_object_cache );
			wp_cache_delete( 'generation', Names::CACHE_GROUP );
			$this->ext_object_cache = null;
		}
		wp_set_current_user( 0 );
		wp_cache_delete( 'count', 'rfrac' );
		wp_cache_delete( 'recounted', 'rfrac' );
		delete_option( Failure_Recorder::OPTION );
		parent::tear_down();
	}

	/**
	 * The header lines sent so far, like `headers_list()`: what the spy server sent plus what callbacks sent.
	 *
	 * @return string[]
	 */
	public function sent_headers(): array {
		$lines = array();
		foreach ( null !== $this->server ? $this->server->sent_headers : array() as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}
		return array_merge( $lines, $this->php_sent );
	}

	/**
	 * Store a compiled config with the test routes.
	 *
	 * @param array<string,mixed> $overrides Top-level overrides.
	 *
	 * @return void
	 */
	private function configure( array $overrides = array() ): void {
		$this->set_compiled_config(
			array_merge(
				$this->snapshot(),
				array(
					'replay_headers' => array( 'x-wp-total', 'x-wp-totalpages', 'link', 'allow', 'content-language' ),
					'routes'         => array(
						$this->route( '/wp/v2/posts' ),
						$this->wildcard( '/tst/v1/*' ),
					),
				),
				$overrides
			)
		);
	}

	/**
	 * Build the capture and hook it like the container does (at PHP_INT_MAX).
	 *
	 * @param Tag_Provider_Interface|null $provider Tag provider.
	 * @param \Closure|null               $factory  Store factory.
	 * @param Cache_Policy|null           $policy   Policy (default: never blocked).
	 * @param Hit_Filters_Interface|null  $filters  Hit filter set (default: none).
	 *
	 * @return Capture
	 */
	private function wire( ?Tag_Provider_Interface $provider = null, ?\Closure $factory = null, ?Cache_Policy $policy = null, ?Hit_Filters_Interface $filters = null ): Capture {
		$this->capture = new Capture(
			$policy ?? $this->policy(),
			new Repository(),
			$provider ?? new Null_Tag_Provider(),
			Compiled_Config::load( ... ),
			static fn( Compiled_Config $config ): Request_Context => Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() ),
			null,
			$factory,
			$this->sent_headers( ... ),
			$filters
		);
		add_filter( 'rest_exposed_cors_headers', array( $this->capture, 'record_expose_headers' ), PHP_INT_MAX, 2 );
		add_filter( 'rest_allowed_cors_headers', array( $this->capture, 'record_allow_headers' ), PHP_INT_MAX, 2 );
		add_filter( 'rest_post_dispatch', array( $this->capture, 'on_post_dispatch' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_pre_echo_response', array( $this->capture, 'on_pre_echo' ), PHP_INT_MAX, 3 );
		add_filter(
			'rest_post_dispatch',
			function ( $result ) {
				$this->seen_headers ??= $result->get_headers();
				return $result;
			},
			PHP_INT_MAX
		);
		return $this->capture;
	}

	/**
	 * The context of the simulated request as the early and fallback servers build it: from the stored snapshot, reading
	 * the headers and cookies that snapshot names.
	 *
	 * @return Request_Context
	 */
	private function lookup_context(): Request_Context {
		$config = Compiled_Config::load();
		$this->assertNotNull( $config );
		return Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() );
	}

	/**
	 * Register the test routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$route = static function ( string $path, callable $callback ): void {
			register_rest_route(
				'tst/v1',
				$path,
				array(
					'methods'             => 'GET',
					'callback'            => $callback,
					'permission_callback' => '__return_true',
				)
			);
		};
		$route( '/ok', static fn() => array( 'a' => 1 ) );
		$route( '/empty', static fn() => array() );
		$route( '/missing', static fn() => new WP_Error( 'nope', 'Nope', array( 'status' => 404 ) ) );
		$route(
			'/cookie',
			static function () {
				$response = new WP_REST_Response( array( 'a' => 1 ) );
				$response->header( 'Set-Cookie', 'secret=1' );
				return $response;
			}
		);
		$route( '/big', static fn() => array( 'blob' => str_repeat( 'x', 2048 ) ) );
		$route(
			'/php-cookie',
			function () {
				// Stands in for header( 'Set-Cookie: …' ) / setcookie() in a callback.
				$this->php_sent[] = 'Set-Cookie: visitor=1; path=/';
				return array( 'a' => 1 );
			}
		);
		$route(
			'/admin',
			static function () {
				wp_set_current_user( 1 );
				return array( 'a' => 1 );
			}
		);
		$route(
			'/headers',
			static function () {
				$response = new WP_REST_Response( array( 'a' => 1 ) );
				$response->header( 'X-WP-Total', '5' );
				$response->header( 'Link', '<https://example.org/>; rel="next"' );
				$response->header( 'Authorization', 'Bearer abc' );
				$response->header( 'Access-Control-Allow-Credentials', 'true' );
				$response->header( 'X-Custom', 'nope' );
				return $response;
			}
		);
		$route(
			'/embed',
			static function () {
				$response = new WP_REST_Response( array( 'id' => 1 ) );
				$response->add_link( 'related', rest_url( 'tst/v1/ok' ), array( 'embeddable' => true ) );
				return $response;
			}
		);
	}

	/**
	 * Serve a route through the real server under output buffering.
	 *
	 * Capture stores only for a request whose lookup recorded a flush epoch, so a request without a lookup first gets the
	 * one of a cacheable miss at the current epoch, as the fallback lookup would have recorded (a later request of the same
	 * test gets its own). A lookup a test recorded stays as it is, and {@see self::serve_without_lookup()} serves a request
	 * that never had one.
	 *
	 * @param string              $route  Route, optionally with a query string.
	 * @param array<string,mixed> $server Extra `$_SERVER` entries.
	 * @param array<string,mixed> $cookie Cookies.
	 *
	 * @return string Echoed output.
	 */
	private function serve( string $route, array $server = array(), array $cookie = array() ): string {
		$this->simulate( '/wp-json' . $route, $server, $cookie );
		if ( $this->lookup_of_serve ) {
			// The lookup of the previous request of this test belongs to that request.
			Request_State::reset();
			$this->lookup_of_serve = false;
		}
		if ( ! $this->without_lookup && ! Request_State::lookup_done() ) {
			$this->record_lookup_of_request( $this->flush_epoch() );
			$this->lookup_of_serve = true;
		}
		$this->server             = new Spy_REST_Server();
		$GLOBALS['wp_rest_server'] = $this->server;
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 99 );
		do_action( 'rest_api_init', $this->server );
		remove_action( 'rest_api_init', array( $this, 'register_routes' ), 99 );

		$path = (string) wp_parse_url( $route, PHP_URL_PATH );
		// PHPUnit has already written output, so header() calls would raise warnings.
		set_error_handler(
			static fn( int $errno, string $errstr ): bool => false !== strpos( $errstr, 'headers already sent' ),
			E_WARNING
		);
		try {
			$this->server->serve_request( $path );
		} finally {
			restore_error_handler();
		}
		return $this->server->sent_body;
	}

	/**
	 * The stored entry of the only `rfrac/stored` event.
	 *
	 * @return Cached_Response
	 */
	private function stored(): Cached_Response {
		$this->assertCount( 1, $this->stored_keys, 'Exactly one rfrac/stored event.' );
		$store = Store_Factory::for_mode( 'database', new Repository(), 1 );
		$hit   = $store->get( $this->stored_keys[0] );
		$this->assertInstanceOf( Cached_Response::class, $hit );
		return $hit;
	}

	private function assert_nothing_stored(): void {
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count() );
		$this->assertSame( array(), $this->stored_keys );
	}

	public function test_anonymous_response_is_stored_exactly_as_echoed(): void {
		$this->wire();
		$output = $this->serve( '/tst/v1/ok' );

		$this->assertSame( '{"a":1}', $output );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$this->assertSame( $output, $this->stored()->payload );
		$this->assertSame( 'MISS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
	}

	public function test_stored_key_matches_the_lookup_key(): void {
		$this->wire();
		$this->serve( '/tst/v1/ok' );

		$config   = Compiled_Config::load();
		$context  = $this->lookup_context();
		$decision = $this->policy()->evaluate( $context, $config );
		$this->assertSame( Cache_Key::key( $context, $decision ), $this->stored_keys[0] );
	}

	public function test_key_is_computed_from_the_re_evaluated_decision_not_reused_from_the_lookup(): void {
		$this->wire();
		$this->simulate( '/wp-json/tst/v1/ok' );
		// The lookup read `accept-language` (another enabled route of the snapshot varies by it), which the matched route only starts to use below.
		$this->configure( array( 'routes' => array( $this->wildcard( '/tst/v1/*' ), $this->route( '/wp/v2/posts', array( 'vary_headers' => array( 'accept-language' ) ) ) ) ) );
		$context  = $this->lookup_context();
		$decision = $this->policy()->evaluate( $context, Compiled_Config::load() );
		Request_State::record_lookup( $context, $decision, str_repeat( 'a', 64 ), $this->flush_epoch() );

		// The config changes between the lookup and the capture (e.g. a recompile on rest_api_init).
		$this->configure( array( 'routes' => array( $this->wildcard( '/tst/v1/*', array( 'vary_headers' => array( 'accept-language' ), 'ttl' => 1800 ) ) ) ) );
		$this->serve( '/tst/v1/ok' );

		$new = $this->policy()->evaluate( $context, Compiled_Config::load() );
		$this->assertSame( array( Cache_Key::key( $context, $new ) ), $this->stored_keys );
		$this->assertNotSame( Cache_Key::key( $context, $decision ), $this->stored_keys[0] );
		$hit = $this->stored();
		$this->assertSame( 1800, $hit->expires_at - $hit->created_at, 'Key and TTL come from the same decision.' );
	}

	public function test_a_snapshot_recompiled_after_the_lookup_with_a_new_vary_header_is_not_stored_under_the_absent_variant(): void {
		$this->wire();
		$this->simulate( '/wp-json/tst/v1/ok', array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) );
		$context  = $this->lookup_context();
		$decision = $this->policy()->evaluate( $context, Compiled_Config::load() );
		$this->assertTrue( $decision->cacheable );
		Request_State::record_lookup( $context, $decision, str_repeat( 'a', 64 ), $this->flush_epoch() );

		// A recompile on rest_api_init varies the route by a header the lookup never read.
		$this->configure( array( 'routes' => array( $this->wildcard( '/tst/v1/*', array( 'vary_headers' => array( 'accept-language' ) ) ) ) ) );
		$this->serve( '/tst/v1/ok', array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) );

		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::ERROR->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assert_nothing_stored();
	}

	public function test_a_snapshot_recompiled_after_the_lookup_with_a_new_credential_header_is_not_stored(): void {
		$this->wire();
		$this->simulate( '/wp-json/tst/v1/ok', array( 'HTTP_X_NEW_KEY' => 'secret' ) );
		$context  = $this->lookup_context();
		$decision = $this->policy()->evaluate( $context, Compiled_Config::load() );
		$this->assertTrue( $decision->cacheable, 'Precondition: the earlier snapshot does not know the header.' );
		Request_State::record_lookup( $context, $decision, str_repeat( 'a', 64 ), $this->flush_epoch() );

		$this->configure( array( 'bypass_headers' => array( 'x-api-key', 'x-new-key' ) ) );
		$this->serve( '/tst/v1/ok', array( 'HTTP_X_NEW_KEY' => 'secret' ) );

		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::CREDENTIALS->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assert_nothing_stored();
	}

	public function test_a_snapshot_recompiled_after_the_lookup_with_headers_the_lookup_read_still_stores(): void {
		$this->wire();
		$this->simulate( '/wp-json/tst/v1/ok' );
		$this->configure( array( 'bypass_headers' => array( 'x-api-key', 'x-new-key' ) ) );
		$context  = $this->lookup_context();
		$decision = $this->policy()->evaluate( $context, Compiled_Config::load() );
		Request_State::record_lookup( $context, $decision, str_repeat( 'a', 64 ), $this->flush_epoch() );

		// A recompile that names the same headers.
		$this->configure( array( 'bypass_headers' => array( 'x-api-key', 'x-new-key' ), 'routes' => array( $this->wildcard( '/tst/v1/*', array( 'ttl' => 1800 ) ) ) ) );
		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 'MISS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertCount( 1, $this->stored_keys );
	}

	public function test_core_no_cache_headers_prevent_storing(): void {
		$this->wire();
		add_filter( 'rest_send_nocache_headers', '__return_true' );

		$this->assertSame( '{"a":1}', $this->serve( '/tst/v1/ok' ) );

		// WordPress 6.8+ sends `…, no-store, private`; older versions `no-cache, must-revalidate, max-age=0`.
		$this->assertStringContainsString( 'no-cache', (string) ( $this->server->sent_headers['Cache-Control'] ?? '' ), 'Precondition: core sent no-cache headers.' );
		$this->assert_nothing_stored();
	}

	public function test_cookie_sent_with_header_by_a_callback_prevents_storing(): void {
		$this->wire();
		$this->serve( '/tst/v1/php-cookie' );
		$this->assert_nothing_stored();
	}

	public function test_headers_sent_with_header_are_never_replayed(): void {
		$this->php_sent = array( 'X-WP-Total: 99' );
		$this->wire();
		$this->serve( '/tst/v1/ok' );

		$this->assertArrayNotHasKey( 'X-WP-Total', $this->stored()->headers );
	}

	/**
	 * Credential-bearing requests.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:array<string,mixed>}>
	 */
	public static function credentialed_requests(): array {
		return array(
			'authorization'     => array( '/tst/v1/ok', array( 'HTTP_AUTHORIZATION' => 'Bearer abc' ), array() ),
			'logged-in cookie'  => array( '/tst/v1/ok', array(), array( 'wordpress_logged_in_0123abcd' => 'admin|1|x' ) ),
			'x-wp-nonce'        => array( '/tst/v1/ok', array( 'HTTP_X_WP_NONCE' => 'abc123' ), array() ),
			'credential header' => array( '/tst/v1/ok', array( 'HTTP_X_API_KEY' => 'key' ), array() ),
			'password param'    => array( '/tst/v1/ok?password=secret', array(), array() ),
		);
	}

	/**
	 * @dataProvider credentialed_requests
	 *
	 * @param string              $route  Route.
	 * @param array<string,mixed> $server Server entries.
	 * @param array<string,mixed> $cookie Cookies.
	 */
	public function test_credentialed_request_is_bypassed_and_not_stored( string $route, array $server, array $cookie ): void {
		$this->wire();
		$this->serve( $route, $server, $cookie );

		$this->assertSame( 'BYPASS', $this->seen_headers['X-Rfrac-Cache'] ?? null );
		$this->assert_nothing_stored();
	}

	public function test_custom_credential_header_of_the_config_is_bypassed(): void {
		$this->configure( array( 'bypass_headers' => array( 'x-shop-key' ) ) );
		$this->wire();
		$this->serve( '/tst/v1/ok', array( 'HTTP_X_SHOP_KEY' => 'k' ) );

		$this->assertSame( 'BYPASS', $this->seen_headers['X-Rfrac-Cache'] ?? null );
		$this->assert_nothing_stored();
	}

	public function test_user_set_before_dispatch_is_not_stored(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->wire();
		$this->serve( '/tst/v1/ok' );
		$this->assert_nothing_stored();
	}

	public function test_nothing_is_stored_while_the_coexistence_guard_blocks(): void {
		$this->block_by_coexistence();
		$this->wire( null, null, new Cache_Policy( Coexistence_Guard::is_blocked( ... ) ) );
		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 'BYPASS', $this->seen_headers['X-Rfrac-Cache'] ?? null );
		$this->assert_nothing_stored();
	}

	public function test_rule_vary_headers_are_advertised_on_the_miss(): void {
		$this->configure( array( 'routes' => array( $this->wildcard( '/tst/v1/*', array( 'vary_headers' => array( 'accept-language' ) ) ) ) ) );
		$this->wire();
		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 'MISS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( 'Origin, accept-language', $this->seen_headers['Vary'] ?? null );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
	}

	public function test_no_vary_header_is_added_without_rule_vary_headers(): void {
		$this->wire();
		$this->serve( '/tst/v1/ok' );
		$this->assertArrayNotHasKey( 'Vary', $this->seen_headers );
	}

	public function test_logged_in_user_during_dispatch_is_not_stored(): void {
		$this->wire();
		$this->serve( '/tst/v1/admin' );
		$this->assert_nothing_stored();
	}

	public function test_non_200_is_not_stored(): void {
		$this->wire();
		$this->serve( '/tst/v1/missing' );
		$this->assert_nothing_stored();
	}

	public function test_set_cookie_response_is_not_stored(): void {
		$this->wire();
		$this->serve( '/tst/v1/cookie' );
		$this->assert_nothing_stored();
	}

	public function test_cookie_added_by_another_filter_is_not_stored(): void {
		$this->wire();
		add_filter(
			'rest_post_dispatch',
			static function ( $result ) {
				$result->header( 'Set-Cookie', 'late=1' );
				return $result;
			},
			500
		);
		$this->serve( '/tst/v1/ok' );
		$this->assert_nothing_stored();
	}

	public function test_oversized_payload_is_not_stored(): void {
		$this->configure( array( 'max_payload_bytes' => 1024 ) );
		$this->wire();
		$this->serve( '/tst/v1/big' );
		$this->assert_nothing_stored();
	}

	public function test_empty_result_is_not_stored_by_default(): void {
		$this->wire();
		$this->serve( '/tst/v1/empty' );
		$this->assert_nothing_stored();
	}

	public function test_empty_result_is_stored_when_the_filter_allows(): void {
		$this->wire();
		add_filter( Hooks::CACHE_EMPTY_RESULTS, '__return_true' );
		$this->serve( '/tst/v1/empty' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
	}

	public function test_embed_is_stored_once_for_the_main_route_with_embedded_data(): void {
		$this->wire();
		$output = $this->serve( '/tst/v1/embed?_embed=1' );

		$this->assertStringContainsString( '_embedded', $output );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$this->assertSame( $output, $this->stored()->payload );
		$this->assertSame( 'MISS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
	}

	public function test_tags_are_normalised_and_deduplicated(): void {
		$this->wire( $this->provider( array( 'post:1', 'post:1', '' ) ) );
		$this->serve( '/tst/v1/ok' );

		$this->assertSame( array( array( 'post:1' ) ), $this->stored_tags );
		$id = $this->stored()->entry_id;
		$this->assertSame( array( 'post:1' ), ( new Repository() )->tags_for( $id ) );
	}

	public function test_provider_returning_null_prevents_storing_and_is_reset(): void {
		$provider = $this->provider( null );
		$this->wire( $provider );
		$this->serve( '/tst/v1/ok' );

		$this->assert_nothing_stored();
		$this->assertSame( 1, $provider->resets );
		$this->assertSame( 1, $provider->collects );
	}

	public function test_provider_is_reset_after_storing_and_receives_the_main_request(): void {
		$provider = $this->provider( array( 'a:1' ) );
		$this->wire( $provider );
		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 1, $provider->resets );
		$this->assertSame( '/tst/v1/ok', $provider->last_route );
		$this->assertSame( array( 'a' => 1 ), $provider->last_data );
	}

	public function test_null_tag_provider_returns_no_tags(): void {
		$provider = new Null_Tag_Provider();
		$this->assertSame( array(), $provider->collect( new WP_REST_Request( 'GET', '/x' ), array() ) );
		$provider->reset();
	}

	public function test_only_allow_listed_headers_and_recorded_cors_headers_are_stored(): void {
		$this->wire();
		$this->serve( '/tst/v1/headers' );

		$headers = $this->stored()->headers;
		$names   = array_map( 'strtolower', array_keys( $headers ) );
		sort( $names );
		$this->assertSame(
			array( 'access-control-allow-headers', 'access-control-expose-headers', 'allow', 'link', 'x-wp-total' ),
			$names
		);
		$this->assertSame( '5', $headers['X-WP-Total'] );
		$this->assertStringContainsString( 'X-WP-Total', $headers['Access-Control-Expose-Headers'] );
		$this->assertStringContainsString( 'Content-Type', $headers['Access-Control-Allow-Headers'] );
	}

	public function test_stored_headers_never_contain_cookies_or_credentials(): void {
		// Even a misconfigured replay list cannot get credentials into the store.
		$this->configure( array( 'replay_headers' => array( 'set-cookie', 'authorization', 'access-control-allow-credentials', 'link', 'x-custom' ) ) );
		$this->wire();
		$this->serve( '/tst/v1/headers' );

		$headers = array_change_key_case( $this->stored()->headers );
		$this->assertArrayNotHasKey( 'set-cookie', $headers );
		$this->assertArrayNotHasKey( 'authorization', $headers );
		$this->assertStringNotContainsString( 'Bearer', (string) wp_json_encode( $headers ) );
		$this->assertArrayHasKey( 'link', $headers );
	}

	public function test_store_allowed_filter_can_veto(): void {
		$this->wire();
		add_filter( Hooks::STORE_ALLOWED, '__return_false' );
		$this->serve( '/tst/v1/ok' );
		$this->assert_nothing_stored();
	}

	public function test_store_allowed_filter_cannot_override_a_policy_refusal(): void {
		$this->wire();
		add_filter( Hooks::STORE_ALLOWED, '__return_true' );
		$this->serve( '/tst/v1/admin' );
		$this->assert_nothing_stored();
	}

	public function test_a_pass_through_hit_filter_set_changes_nothing_and_decides_and_keys_once(): void {
		$fake = new Rfrac_Fake_Hit_Filters();
		$this->wire( null, null, null, $fake );

		$this->serve( '/tst/v1/ok' );

		$this->assertSame( array( 'decide', 'key' ), $fake->calls );
		$this->assertSame( 'MISS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$context  = $this->lookup_context();
		$decision = $this->policy()->evaluate( $context, Compiled_Config::load() );
		$this->assertSame( array( Cache_Key::key( $context, $decision ) ), $this->stored_keys, 'Without a replaced key the key is the engine\'s.' );
	}

	public function test_a_bypass_of_decide_marks_the_response_and_stores_nothing(): void {
		$fake            = new Rfrac_Fake_Hit_Filters();
		$fake->on_decide = static fn( Policy_Decision $decision ): Policy_Decision => Policy_Decision::bypass( Bypass_Reason::FILTERED, $decision->rule );
		$this->wire( null, null, null, $fake );

		$this->serve( '/tst/v1/ok' );

		$this->assert_nothing_stored();
		$this->assertSame( 'BYPASS', $this->seen_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::FILTERED->value, $this->seen_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertSame( array( 'decide' ), $fake->calls, 'A decision that is not cacheable is not keyed.' );
	}

	public function test_the_entry_is_stored_under_the_key_of_the_hit_filter_set(): void {
		$fake         = new Rfrac_Fake_Hit_Filters();
		$fake->on_key = static fn( string $key ): string => 'other-' . substr( $key, 0, 8 );
		$this->wire( null, null, null, $fake );

		$this->serve( '/tst/v1/ok' );

		$context  = $this->lookup_context();
		$decision = $this->policy()->evaluate( $context, Compiled_Config::load() );
		$expected = 'other-' . substr( Cache_Key::key( $context, $decision ), 0, 8 );
		$this->assertSame( array( $expected ), $this->stored_keys );
		$this->assertSame( '{"a":1}', $this->stored()->payload );
		$this->assertSame( 'MISS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
	}

	public function test_a_null_key_of_the_hit_filter_set_stores_nothing_as_filtered(): void {
		$fake         = new Rfrac_Fake_Hit_Filters();
		$fake->on_key = static fn(): ?string => null;
		$this->wire( null, null, null, $fake );

		$this->serve( '/tst/v1/ok' );

		$this->assert_nothing_stored();
		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::FILTERED->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertNull( Failure_Recorder::last(), 'A refused key is a bypass, not a failure.' );
	}

	/**
	 * @dataProvider credentialed_requests
	 *
	 * @param string              $route  Route.
	 * @param array<string,mixed> $server Server entries.
	 * @param array<string,mixed> $cookie Cookies.
	 */
	public function test_a_credentialed_request_never_reaches_the_hit_filter_set( string $route, array $server, array $cookie ): void {
		$spy = new Rfrac_Fake_Hit_Filters();
		$this->wire( null, null, null, $spy );

		$this->serve( $route, $server, $cookie );

		$this->assertSame( array(), $spy->calls );
		$this->assert_nothing_stored();
	}

	public function test_a_throwing_hit_filter_set_is_recorded_and_the_response_is_not_stored(): void {
		$fake            = new Rfrac_Fake_Hit_Filters();
		$fake->on_decide = static function (): Policy_Decision {
			throw new RuntimeException( 'filters' );
		};
		$this->wire( null, null, null, $fake );

		$output = $this->serve( '/tst/v1/ok' );

		$this->assertSame( '{"a":1}', $output, 'The live response is served untouched.' );
		$this->assert_nothing_stored();
		$this->assertSame( 'capture', Failure_Recorder::last()['context'] ?? null );
	}

	public function test_store_allowed_receives_route_rule_and_size(): void {
		$this->wire();
		$args = array();
		add_filter(
			Hooks::STORE_ALLOWED,
			static function ( $allow, ...$rest ) use ( &$args ) {
				$args = $rest;
				return $allow;
			},
			10,
			4
		);
		$this->serve( '/tst/v1/ok' );
		$this->assertSame( array( '/tst/v1/ok', '/tst/v1/*', 7 ), $args );
	}

	/**
	 * Compile a `cur` cookie declaration on the `/tst/v1/*` route.
	 *
	 * @param array<string,list<string>> $declared Cookie name => allowed values.
	 *
	 * @return void
	 */
	private function compile_cookie_declaration( array $declared ): void {
		$this->configure( array( 'routes' => array( $this->wildcard( '/tst/v1/*', array( 'vary_cookies' => $declared ) ) ) ) );
	}

	public function test_a_declaration_added_after_the_compile_stores_nothing(): void {
		$this->assertTrue( WP_DEBUG, 'The test suite runs with WP_DEBUG.' );
		$this->wire();
		add_filter( Hooks::ROUTE_VARY_COOKIES, static fn(): array => array( 'cur' => array( 'EUR' ) ) );

		$this->serve( '/tst/v1/ok', array(), array( 'cur' => 'EUR' ) );

		$this->assert_nothing_stored();
		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::VARY_COOKIE->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertNull( Failure_Recorder::last(), 'A stale declaration is a bypass, not a failure.' );
	}

	public function test_a_declaration_dropped_after_the_compile_stores_nothing(): void {
		$this->compile_cookie_declaration( array( 'cur' => array( 'EUR' ) ) );
		$this->wire();

		$this->serve( '/tst/v1/ok', array(), array( 'cur' => 'EUR' ) );

		$this->assert_nothing_stored();
		$this->assertSame( Bypass_Reason::VARY_COOKIE->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
	}

	public function test_a_changed_value_list_stores_nothing(): void {
		$this->compile_cookie_declaration( array( 'cur' => array( 'EUR' ) ) );
		$this->wire();
		add_filter( Hooks::ROUTE_VARY_COOKIES, static fn(): array => array( 'cur' => array( 'EUR', 'USD' ) ) );

		$this->serve( '/tst/v1/ok', array(), array( 'cur' => 'EUR' ) );

		$this->assert_nothing_stored();
		$this->assertSame( Bypass_Reason::VARY_COOKIE->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
	}

	public function test_an_invalid_live_declaration_stores_nothing(): void {
		$this->wire();
		add_filter( Hooks::ROUTE_VARY_COOKIES, '__return_null' );

		$this->serve( '/tst/v1/ok' );

		$this->assert_nothing_stored();
		$this->assertSame( Bypass_Reason::VARY_COOKIE->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
	}

	public function test_an_equal_live_declaration_stores_the_response(): void {
		$this->compile_cookie_declaration( array( 'cur' => array( 'EUR', 'USD' ) ) );
		$this->wire();
		$seen = array();
		add_filter(
			Hooks::ROUTE_VARY_COOKIES,
			static function ( $declared, $key ) use ( &$seen ) {
				$seen[] = $key;
				// Not sorted: the live declaration is normalised before it is compared.
				return array( 'cur' => array( 'USD', 'EUR', 'EUR' ) );
			},
			10,
			2
		);

		$this->serve( '/tst/v1/ok', array(), array( 'cur' => 'USD' ) );

		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$this->assertSame( 'MISS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertArrayNotHasKey( 'X-Rfrac-Cache-Reason', $this->server->sent_headers );
		$this->assertSame( array( '/tst/v1/*' ), $seen, 'Asked once per store attempt, for the rule key.' );
	}

	public function test_a_stale_declaration_is_refused_before_store_allowed_is_asked(): void {
		$this->wire();
		add_filter( Hooks::ROUTE_VARY_COOKIES, static fn(): array => array( 'cur' => array( 'EUR' ) ) );
		$asked = 0;
		add_filter(
			Hooks::STORE_ALLOWED,
			static function ( $allow ) use ( &$asked ) {
				++$asked;
				return $allow;
			}
		);

		$this->serve( '/tst/v1/ok', array(), array( 'cur' => 'EUR' ) );

		$this->assertSame( 0, $asked );
		$this->assert_nothing_stored();
	}

	public function test_entry_ttl_is_clamped(): void {
		$this->configure( array( 'max_ttl' => 7200 ) );
		$this->wire();

		add_filter( Hooks::ENTRY_TTL, static fn() => 5, 10, 1 );
		$this->serve( '/tst/v1/ok' );
		$low = $this->stored();
		$this->assertSame( 60, $low->expires_at - $low->created_at );
	}

	public function test_entry_ttl_is_capped_at_the_maximum(): void {
		$this->configure( array( 'max_ttl' => 7200 ) );
		$this->wire();
		add_filter( Hooks::ENTRY_TTL, static fn() => 999999, 10, 1 );
		$this->serve( '/tst/v1/ok' );
		$high = $this->stored();
		$this->assertSame( 7200, $high->expires_at - $high->created_at );
	}

	public function test_rule_ttl_is_used_by_default(): void {
		$this->wire();
		$this->serve( '/tst/v1/ok' );
		$hit = $this->stored();
		$this->assertSame( 3600, $hit->expires_at - $hit->created_at );
	}

	public function test_bypassed_request_is_marked_with_a_reason_in_debug(): void {
		$this->configure( array( 'enabled' => false ) );
		$this->wire();
		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 'BYPASS', $this->seen_headers['X-Rfrac-Cache'] ?? null );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->assertSame( Bypass_Reason::DISABLED->value, $this->seen_headers['X-Rfrac-Cache-Reason'] ?? null );
		} else {
			$this->assertArrayNotHasKey( 'X-Rfrac-Cache-Reason', $this->seen_headers );
		}
		$this->assert_nothing_stored();
	}

	public function test_sub_request_for_another_route_is_ignored(): void {
		$capture = $this->wire();
		$this->simulate( '/wp-json/tst/v1/ok' );

		$other    = new WP_REST_Response( array( 'x' => 1 ) );
		$request  = new WP_REST_Request( 'GET', '/tst/v1/other' );
		$returned = $capture->on_post_dispatch( $other, rest_get_server(), $request );

		$this->assertSame( $other, $returned );
		$this->assertArrayNotHasKey( 'X-Rfrac-Cache', $other->get_headers() );
		$this->assertSame( array( 'x' => 1 ), $capture->on_pre_echo( array( 'x' => 1 ), rest_get_server(), $request ) );
		$this->assert_nothing_stored();
	}

	public function test_a_dispatch_with_another_method_than_the_request_is_ignored(): void {
		$capture = $this->wire();
		$this->simulate( '/wp-json/tst/v1/ok' );
		$response = new WP_REST_Response( array() );

		$capture->on_post_dispatch( $response, rest_get_server(), new WP_REST_Request( 'POST', '/tst/v1/ok' ) );

		$this->assertArrayNotHasKey( 'X-Rfrac-Cache', $response->get_headers() );
	}

	public function test_a_method_override_stores_nothing_adds_no_header_and_ends_the_capture(): void {
		$capture = $this->wire();
		// A POST that core dispatches as a GET (a method override).
		$this->simulate( '/wp-json/tst/v1/ok', array( 'REQUEST_METHOD' => 'POST' ) );

		$first   = new WP_REST_Response( array( 'a' => 1 ) );
		$request = new WP_REST_Request( 'GET', '/tst/v1/ok' );
		$capture->on_post_dispatch( $first, rest_get_server(), $request );

		$this->assertArrayNotHasKey( 'X-Rfrac-Cache', $first->get_headers() );
		$this->assertSame( array( 'a' => 1 ), $capture->on_pre_echo( array( 'a' => 1 ), rest_get_server(), $request ) );
		$this->assert_nothing_stored();

		// The request is handled: a later dispatch of the same method is ignored as well.
		$second = new WP_REST_Response( array( 'a' => 1 ) );
		$capture->on_post_dispatch( $second, rest_get_server(), new WP_REST_Request( 'POST', '/tst/v1/ok' ) );
		$this->assertArrayNotHasKey( 'X-Rfrac-Cache', $second->get_headers() );
		$this->assert_nothing_stored();
	}

	public function test_a_non_get_dispatch_of_the_request_method_is_marked_with_the_policy_reason(): void {
		$capture = $this->wire();
		$this->simulate( '/wp-json/tst/v1/ok', array( 'REQUEST_METHOD' => 'POST' ) );

		$response = new WP_REST_Response( array( 'a' => 1 ) );
		$request  = new WP_REST_Request( 'POST', '/tst/v1/ok' );
		$capture->on_post_dispatch( $response, rest_get_server(), $request );
		$capture->on_pre_echo( array( 'a' => 1 ), rest_get_server(), $request );

		$this->assertSame( 'BYPASS', $response->get_headers()['X-Rfrac-Cache'] ?? null );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->assertSame( Bypass_Reason::METHOD->value, $response->get_headers()['X-Rfrac-Cache-Reason'] ?? null );
		} else {
			$this->assertArrayNotHasKey( 'X-Rfrac-Cache-Reason', $response->get_headers() );
		}
		$this->assert_nothing_stored();
	}

	public function test_a_stored_get_entry_has_the_get_method(): void {
		global $wpdb;
		$this->wire();
		$this->serve( '/tst/v1/ok' );
		$this->stored();

		$table = $wpdb->prefix . Names::TABLE_ENTRIES;
		$this->assertSame( 'GET', $wpdb->get_var( $wpdb->prepare( 'SELECT method FROM %i WHERE cache_key = %s', $table, $this->stored_keys[0] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function test_only_the_first_dispatch_of_the_route_counts(): void {
		$capture = $this->wire();
		$this->simulate( '/wp-json/tst/v1/ok' );

		$first  = new WP_REST_Response( array( 'a' => 1 ) );
		$second = new WP_REST_Response( array( 'a' => 1 ) );
		$req    = new WP_REST_Request( 'GET', '/tst/v1/ok' );
		$capture->on_post_dispatch( $first, rest_get_server(), $req );
		$capture->on_post_dispatch( $second, rest_get_server(), new WP_REST_Request( 'GET', '/tst/v1/ok' ) );

		$this->assertSame( 'BYPASS', $first->get_headers()['X-Rfrac-Cache'] ?? null, 'Provisional until the outcome of storing is known.' );
		$this->assertArrayNotHasKey( 'X-Rfrac-Cache', $second->get_headers() );
	}

	public function test_pre_echo_ignores_an_unstashed_request_but_resets_the_tag_provider(): void {
		$provider = $this->provider( array( 'a:1' ) );
		$capture  = $this->wire( $provider );
		$this->simulate( '/wp-json/tst/v1/ok' );
		$capture->on_post_dispatch( new WP_REST_Response( array( 'a' => 1 ) ), rest_get_server(), new WP_REST_Request( 'GET', '/tst/v1/ok' ) );

		$data = array( 'a' => 1 );
		$this->assertSame( $data, $capture->on_pre_echo( $data, rest_get_server(), new WP_REST_Request( 'GET', '/tst/v1/ok' ) ) );
		$this->assert_nothing_stored();
		$this->assertSame( 1, $provider->resets, 'Tags cannot leak into the next request.' );
		$this->assertSame( 0, $provider->collects );

		$capture->on_pre_echo( 'odd', null, null );
		$this->assertSame( 2, $provider->resets );
	}

	public function test_bypassed_request_resets_the_tag_provider(): void {
		$this->configure( array( 'enabled' => false ) );
		$provider = $this->provider( array( 'a:1' ) );
		$this->wire( $provider );
		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 1, $provider->resets );
	}

	public function test_context_comes_from_request_state_before_the_factory(): void {
		$this->simulate( '/wp-json/tst/v1/ok' );
		$context  = $this->lookup_context();
		$decision = $this->policy()->evaluate( $context, Compiled_Config::load() );
		Request_State::record_lookup( $context, $decision, null );

		$calls   = 0;
		$capture = new Capture(
			$this->policy(),
			new Repository(),
			new Null_Tag_Provider(),
			static fn(): ?Compiled_Config => Compiled_Config::load(),
			static function () use ( &$calls ): Request_Context {
				++$calls;
				throw new RuntimeException( 'factory must not run' );
			}
		);
		$response = new WP_REST_Response( array( 'a' => 1 ) );
		$capture->on_post_dispatch( $response, rest_get_server(), new WP_REST_Request( 'GET', '/tst/v1/ok' ) );

		$this->assertSame( 0, $calls );
		$this->assertSame( 'BYPASS', $response->get_headers()['X-Rfrac-Cache'] ?? null, 'Provisional until the outcome of storing is known.' );
	}

	public function test_errors_never_break_the_response(): void {
		$this->simulate( '/wp-json/tst/v1/ok' );
		$capture  = new Capture(
			$this->policy(),
			new Repository(),
			new Null_Tag_Provider(),
			static function (): ?Compiled_Config {
				throw new Error( 'config' );
			},
			static fn(): Request_Context => Request_Context::from_globals( 'wp-json' )
		);
		$response = new WP_REST_Response( array( 'a' => 1 ) );

		$this->assertSame( $response, $capture->on_post_dispatch( $response, rest_get_server(), new WP_REST_Request( 'GET', '/tst/v1/ok' ) ) );
		$this->assertSame( 'BYPASS', $response->get_headers()['X-Rfrac-Cache'] ?? null );
		$this->assertSame( 'capture', Failure_Recorder::last()['context'] ?? null );
	}

	public function test_failing_store_is_swallowed_and_the_body_is_unchanged(): void {
		$store = $this->createMock( Store_Interface::class );
		$store->method( 'set' )->willThrowException( new RuntimeException( 'db down' ) );
		$provider = $this->provider( array( 'a:1' ) );
		$this->wire( $provider, static fn() => $store );

		$output = $this->serve( '/tst/v1/ok' );

		$this->assertSame( '{"a":1}', $output );
		$this->assertSame( array(), $this->stored_keys );
		$this->assertSame( 1, $provider->resets );
		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null, 'A failed store is no longer reported as MISS.' );
		$last = Failure_Recorder::last();
		$this->assertSame( 'capture', $last['context'] ?? null );
		$this->assertStringContainsString( 'db down', $last['message'] ?? '' );
	}

	public function test_store_returning_null_fires_no_stored_action_and_is_a_failure(): void {
		$store = $this->createMock( Store_Interface::class );
		$store->method( 'set' )->willReturn( null );
		$this->wire( null, static fn() => $store );

		$this->serve( '/tst/v1/ok' );

		$this->assertSame( array(), $this->stored_keys );
		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( 'error', $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertSame( 'capture', Failure_Recorder::last()['context'] ?? null );
	}

	public function test_entry_flushed_while_it_was_stored_is_a_bypass_but_not_a_failure(): void {
		update_option( Failure_Recorder::OPTION, $this->failure( 'capture', '/tst/v1/ok' ), false );
		$store = $this->createMock( Store_Interface::class );
		$store->method( 'set' )->willReturn( 0 );
		wp_cache_set( 'count', 5, 'rfrac' );
		$this->wire( null, static fn() => $store );

		$this->serve( '/tst/v1/ok' );

		$this->assertSame( array(), $this->stored_keys, 'No rfrac/stored event for an entry that is not served.' );
		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::FLUSHED->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertSame( 'boom', Failure_Recorder::last()['message'] ?? null, 'Nothing was stored, so nothing is cleared, and nothing failed.' );
		$this->assertSame( 5, wp_cache_get( 'count', 'rfrac' ), 'An entry that was not kept is not counted.' );
	}

	/**
	 * Serve a route whose request never had a lookup: nothing recorded a flush epoch for it.
	 *
	 * @param string $route Route, optionally with a query string.
	 *
	 * @return string Echoed output.
	 */
	private function serve_without_lookup( string $route ): string {
		$this->without_lookup = true;
		try {
			return $this->serve( $route );
		} finally {
			$this->without_lookup = false;
		}
	}

	/**
	 * Record a lookup of the simulated request that read a flush epoch (null: the lookup did not read it).
	 *
	 * @param int|null $epoch Epoch the lookup saw.
	 */
	private function record_lookup_of_request( ?int $epoch ): void {
		$this->lookup_of_serve = false;
		$context               = $this->lookup_context();
		Request_State::record_lookup( $context, $this->policy()->evaluate( $context, Compiled_Config::load() ), null, $epoch );
	}

	/**
	 * Record a lookup of the test route that read a flush epoch (null: the lookup did not read it).
	 *
	 * @param int|null $epoch Epoch the lookup saw.
	 */
	private function record_lookup_at( ?int $epoch ): void {
		$this->simulate( '/wp-json/tst/v1/ok' );
		$this->record_lookup_of_request( $epoch );
	}

	/**
	 * The flush epoch of the current site, read from the database.
	 */
	private function flush_epoch(): int {
		$epoch = ( new Repository() )->flush_epoch();
		$this->assertNotNull( $epoch );
		return (int) $epoch;
	}

	/**
	 * Commit a flush (that matches no entry) on the current site.
	 */
	private function commit_a_flush(): void {
		( new Repository() )->flush_by_tags( array( 'custom:other' ), gmdate( 'Y-m-d H:i:s' ) );
	}

	/**
	 * A store that writes through an inner store, then commits a flush that matches nothing: a take-down landing while the entry is written.
	 *
	 * @param Store_Interface $inner      Real store.
	 * @param string[]        $then_break Public `query` filter callbacks of this test, added once the entry is written (the caller removes them).
	 */
	private function flushing_store( Store_Interface $inner, array $then_break = array() ): Store_Interface {
		$store = $this->createMock( Store_Interface::class );
		$store->method( 'set' )->willReturnCallback(
			function ( Entry_Data $entry ) use ( $inner, $then_break ): ?int {
				$this->written = $entry;
				$id            = $inner->set( $entry );
				$this->commit_a_flush();
				foreach ( $then_break as $callback ) {
					add_filter( 'query', array( $this, $callback ) );
				}
				return $id;
			}
		);
		$store->method( 'delete' )->willReturnCallback( static fn( array $ids ): int => $inner->delete( $ids ) );
		$store->method( 'flush_by_ids' )->willReturnCallback( static fn( array $ids ): int => $inner->flush_by_ids( $ids ) );
		return $store;
	}

	/**
	 * Serve the test route with the `query` filters of a flushing store removed and the database errors silenced afterwards.
	 *
	 * @param string[] $callbacks The `$then_break` callbacks of the flushing store.
	 */
	private function serve_with_failing_writes( array $callbacks ): void {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		try {
			$this->serve( '/tst/v1/ok' );
		} finally {
			foreach ( $callbacks as $callback ) {
				remove_filter( 'query', array( $this, $callback ) );
			}
			$wpdb->suppress_errors( $suppress );
		}
	}

	/**
	 * Run a callback while the reads of the flush epoch fail.
	 *
	 * @param callable $callback Callback; its return value is returned.
	 *
	 * @return mixed
	 */
	private function failing_epoch_reads( callable $callback ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', array( $this, 'break_epoch_read' ) );
		try {
			return $callback();
		} finally {
			remove_filter( 'query', array( $this, 'break_epoch_read' ) );
			$wpdb->suppress_errors( $suppress );
		}
	}

	public function test_a_response_is_stored_when_the_flush_epoch_has_not_moved_since_the_lookup(): void {
		$this->commit_a_flush();
		$this->record_lookup_at( $this->flush_epoch() );
		$this->wire();

		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 'MISS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertCount( 1, $this->stored_keys, 'rfrac/stored fired once.' );
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_a_flush_committed_since_the_lookup_stores_nothing_and_calls_no_store(): void {
		$recorded = $this->flush_epoch();
		$this->record_lookup_at( $recorded );
		$this->commit_a_flush();
		$store = $this->createMock( Store_Interface::class );
		$store->expects( $this->never() )->method( 'set' );
		$this->wire( null, static fn() => $store );

		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::FLUSHED->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertSame( array(), $this->stored_keys, 'No rfrac/stored event.' );
		$this->assertNull( Failure_Recorder::last(), 'A refusal is not a failure.' );
		$this->assertSame( $recorded + 1, $this->flush_epoch(), 'The refusal itself flushes nothing.' );
	}

	public function test_a_flush_committed_while_a_database_entry_is_written_retracts_it(): void {
		$before = $this->flush_epoch();
		$this->record_lookup_at( $before );
		$this->wire( null, fn() => $this->flushing_store( Store_Factory::for_mode( 'database', new Repository(), 1 ) ) );

		$this->serve( '/tst/v1/ok' );

		$this->assertInstanceOf( Entry_Data::class, $this->written );
		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::FLUSHED->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertSame( array(), $this->stored_keys, 'No rfrac/stored event.' );
		$this->assertNull( Failure_Recorder::last() );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'The row was deleted.' );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count_tags() );
		$this->assertSame( $before + 1, $this->flush_epoch(), 'The retraction deletes, so the epoch advanced exactly once.' );
	}

	public function test_a_flush_committed_while_an_object_cache_entry_is_written_retracts_it(): void {
		$this->ext_object_cache = (bool) wp_using_ext_object_cache();
		wp_using_ext_object_cache( true );
		wp_cache_delete( 'generation', Names::CACHE_GROUP );
		$inner  = new Object_Cache_Store( new Repository(), 1 );
		$before = $this->flush_epoch();
		$this->record_lookup_at( $before );
		$this->wire( null, fn() => $this->flushing_store( $inner ) );

		$this->serve( '/tst/v1/ok' );

		$this->assertInstanceOf( Entry_Data::class, $this->written );
		$this->assertSame( Bypass_Reason::FLUSHED->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertSame( array(), $this->stored_keys );
		$this->assertNull( Failure_Recorder::last() );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'No row with that key exists.' );
		$this->assertNull( $inner->get( $this->written->cache_key ), 'The object was dropped.' );
		$this->assertSame( $before + 1, $this->flush_epoch() );
	}

	public function test_a_retraction_delete_that_fails_on_a_database_entry_flags_it_flushed_instead(): void {
		$inner = Store_Factory::for_mode( 'database', new Repository(), 1 );
		$this->record_lookup_at( $this->flush_epoch() );
		$this->wire( null, fn() => $this->flushing_store( $inner, array( 'break_entry_deletes' ) ) );

		$this->serve_with_failing_writes( array( 'break_entry_deletes' ) );

		$this->assertInstanceOf( Entry_Data::class, $this->written );
		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::FLUSHED->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertSame( array(), $this->stored_keys, 'No rfrac/stored event.' );
		$this->assertNull( Failure_Recorder::last() );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'The delete failed, so the row is still there.' );
		$this->assertSame( 0, ( new Repository() )->count_active(), 'It is flagged flushed, not left active.' );
		$this->assertNull( $inner->get( $this->written->cache_key ), 'It is not served.' );
	}

	public function test_a_retraction_delete_that_fails_on_an_object_cache_entry_flags_it_flushed_instead(): void {
		$this->ext_object_cache = (bool) wp_using_ext_object_cache();
		wp_using_ext_object_cache( true );
		wp_cache_delete( 'generation', Names::CACHE_GROUP );
		$inner = new Object_Cache_Store( new Repository(), 1 );
		$this->record_lookup_at( $this->flush_epoch() );
		$this->wire( null, fn() => $this->flushing_store( $inner, array( 'break_entry_deletes' ) ) );

		$this->serve_with_failing_writes( array( 'break_entry_deletes' ) );

		$this->assertInstanceOf( Entry_Data::class, $this->written );
		$this->assertSame( Bypass_Reason::FLUSHED->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertSame( array(), $this->stored_keys );
		$this->assertNull( Failure_Recorder::last() );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'The delete failed, so the row is still there.' );
		$this->assertSame( 0, ( new Repository() )->count_active(), 'It is flagged flushed, not left active.' );
		$this->assertNull( $inner->get( $this->written->cache_key ), 'The object was dropped.' );
	}

	public function test_a_retraction_that_removes_nothing_and_flushes_nothing_is_a_recorded_error(): void {
		$inner = Store_Factory::for_mode( 'database', new Repository(), 1 );
		$this->record_lookup_at( $this->flush_epoch() );
		$store = $this->createMock( Store_Interface::class );
		$store->method( 'set' )->willReturnCallback(
			function ( Entry_Data $entry ) use ( $inner ): ?int {
				$this->written = $entry;
				$id            = $inner->set( $entry );
				$this->commit_a_flush();
				return $id;
			}
		);
		$store->expects( $this->once() )->method( 'delete' )->willReturn( 0 );
		$store->expects( $this->once() )->method( 'flush_by_ids' )->willReturn( 0 );
		$this->wire( null, static fn() => $store );

		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::ERROR->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null, 'The entry may still be active: not a clean flushed bypass.' );
		$this->assertSame( 'capture', Failure_Recorder::last()['context'] ?? null );
		$this->assertSame( array(), $this->stored_keys, 'No rfrac/stored event.' );
	}

	public function test_a_retraction_delete_that_removed_the_row_needs_no_fallback_flush(): void {
		$inner = Store_Factory::for_mode( 'database', new Repository(), 1 );
		$this->record_lookup_at( $this->flush_epoch() );
		$store = $this->createMock( Store_Interface::class );
		$store->method( 'set' )->willReturnCallback(
			function ( Entry_Data $entry ) use ( $inner ): ?int {
				$this->written = $entry;
				$id            = $inner->set( $entry );
				$this->commit_a_flush();
				return $id;
			}
		);
		$store->method( 'delete' )->willReturnCallback( static fn( array $ids ): int => $inner->delete( $ids ) );
		$store->expects( $this->never() )->method( 'flush_by_ids' );
		$this->wire( null, static fn() => $store );

		$this->serve( '/tst/v1/ok' );

		$this->assertSame( Bypass_Reason::FLUSHED->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertNull( Failure_Recorder::last() );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count() );
	}

	/**
	 * The `query` filter that breaks the deletes of entries and tags.
	 *
	 * @param mixed $query SQL.
	 *
	 * @return mixed
	 */
	public function break_entry_deletes( $query ) {
		return is_string( $query ) && 1 === preg_match( '/^\s*DELETE\b.*rfrac_(entries|tags)/s', $query ) ? 'RFRAC INVALID SQL' : $query;
	}

	public function test_a_request_without_a_lookup_stores_nothing_as_no_lookup(): void {
		$store = $this->createMock( Store_Interface::class );
		$store->expects( $this->never() )->method( 'set' );
		$this->wire( null, static fn() => $store );
		$this->assertFalse( Request_State::lookup_done(), 'Precondition: no lookup ran.' );

		$this->serve_without_lookup( '/tst/v1/ok' );

		$this->assertNull( Request_State::flush_epoch() );
		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::NO_LOOKUP->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assert_nothing_stored();
		$this->assert_no_lookup_failure_recorded();
	}

	/**
	 * Assert that the refusal for a missing flush epoch left an operator-visible `capture` failure of the test route.
	 *
	 * @return void
	 */
	private function assert_no_lookup_failure_recorded(): void {
		$last = Failure_Recorder::last();
		$this->assertIsArray( $last, 'A cacheable response refused for lack of a flush epoch is an operator-visible failure.' );
		$this->assertSame( 'capture', $last['context'] );
		$this->assertStringContainsString( 'No lookup recorded a flush epoch', $last['message'] );
		$this->assertSame( '/tst/v1/ok', get_option( Failure_Recorder::OPTION )['subject'] ?? null, 'Recorded for its route, so only a stored response of that route clears it.' );
	}

	public function test_a_request_without_a_lookup_reads_no_flush_epoch_and_clears_no_recorded_failure(): void {
		update_option( Failure_Recorder::OPTION, $this->failure( 'capture', '/tst/v1/ok' ), false );
		$this->wire();
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();

		$this->serve_without_lookup( '/tst/v1/ok' );

		$recorder->stop();
		$this->assertSame( Bypass_Reason::NO_LOOKUP->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertSame( array(), $recorder->matching( '/rfrac_flush_epoch/' ), 'There is no baseline to compare an epoch read now with.' );
		$this->assert_no_lookup_failure_recorded();
		$this->assertSame( 2, Failure_Recorder::last()['count'] ?? null, 'Nothing was stored, so nothing is cleared: the refusal is recorded after the earlier failure.' );
	}

	public function test_the_next_stored_response_of_the_route_clears_the_failure_recorded_for_a_missing_flush_epoch(): void {
		$this->wire();
		$this->serve_without_lookup( '/tst/v1/ok' );

		$this->assertSame( Bypass_Reason::NO_LOOKUP->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assert_nothing_stored();
		$this->assert_no_lookup_failure_recorded();

		// The next request of the route has its lookup, so it is stored (a new Capture: each one handles one main request).
		Request_State::reset();
		$this->wire();
		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 'MISS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertCount( 1, $this->stored_keys, 'The route is stored once its request has a lookup.' );
		$this->assertNull( Failure_Recorder::last(), 'The next successful store of the route clears the failure.' );
	}

	public function test_a_failure_recorded_for_a_missing_flush_epoch_stays_until_a_response_of_its_route_is_stored(): void {
		$this->wire();
		$this->serve_without_lookup( '/tst/v1/ok' );
		$this->assert_no_lookup_failure_recorded();

		Request_State::reset();
		$this->wire();
		$this->serve( '/tst/v1/headers' );

		$this->assertSame( 'MISS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null, 'Another route was stored.' );
		$this->assertSame( 'capture', Failure_Recorder::last()['context'] ?? null, 'That success says nothing about the route that was refused.' );
	}

	/**
	 * @dataProvider lookups_without_an_epoch
	 *
	 * @param Bypass_Reason|null $reason Reason the lookup ended with, null for the cacheable decision of the request.
	 */
	public function test_a_lookup_that_recorded_no_epoch_stores_nothing_as_no_lookup_when_the_capture_finds_the_request_cacheable( ?Bypass_Reason $reason ): void {
		$this->simulate( '/wp-json/tst/v1/ok' );
		$context  = $this->lookup_context();
		$decision = null === $reason ? $this->policy()->evaluate( $context, Compiled_Config::load() ) : Policy_Decision::bypass( $reason );
		Request_State::record_lookup( $context, $decision, null, null );
		$store = $this->createMock( Store_Interface::class );
		$store->expects( $this->never() )->method( 'set' );
		$this->wire( null, static fn() => $store );

		$this->serve( '/tst/v1/ok' );

		$this->assertTrue( Request_State::lookup_done() );
		$this->assertNull( Request_State::flush_epoch() );
		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::NO_LOOKUP->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assert_nothing_stored();
		$this->assert_no_lookup_failure_recorded();
	}

	public static function lookups_without_an_epoch(): array {
		return array(
			'a cacheable decision whose epoch was not read' => array( null ),
			'a lookup that ended as a bypass'               => array( Bypass_Reason::FILTERED ),
		);
	}

	/**
	 * Record the lookup of the simulated request as one that read no epoch, as on a pending or stale snapshot.
	 *
	 * @param bool $observed Whether the lookup saw the snapshot outdated.
	 * @param bool $rebuilt  Whether the recompile of the request then rebuilt it (it counts only after the observation).
	 *
	 * @return void
	 */
	private function record_lookup_on_a_snapshot( bool $observed, bool $rebuilt ): void {
		$this->simulate( '/wp-json/tst/v1/ok' );
		if ( $observed ) {
			Request_State::record_outdated_snapshot();
		}
		Request_State::record_lookup( $this->lookup_context(), Policy_Decision::bypass( Bypass_Reason::ROUTE_NOT_ENABLED ), null, null );
		if ( $rebuilt ) {
			Request_State::record_snapshot_rebuilt();
		}
	}

	public function test_a_request_that_rebuilt_its_outdated_snapshot_is_refused_no_lookup_without_a_failure(): void {
		$this->record_lookup_on_a_snapshot( true, true );
		$this->assertTrue( Request_State::outdated_snapshot_rebuilt(), 'Precondition: the request rebuilt its outdated snapshot.' );
		$store = $this->createMock( Store_Interface::class );
		$store->expects( $this->never() )->method( 'set' );
		$this->wire( null, static fn() => $store );

		$this->serve( '/tst/v1/ok' );

		$this->assertNull( Request_State::flush_epoch() );
		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::NO_LOOKUP->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null, 'The verdict is still no_lookup.' );
		$this->assert_nothing_stored();
		$this->assertNull( Failure_Recorder::last(), 'The next request of the route is stored, so nothing is recorded.' );
	}

	/**
	 * @dataProvider lookups_that_did_not_rebuild_an_outdated_snapshot
	 *
	 * @param bool $observed Whether the lookup saw the snapshot outdated.
	 * @param bool $rebuilt  Whether the recompile reported a rebuild.
	 */
	public function test_a_refusal_without_both_an_outdated_observation_and_a_rebuild_is_still_recorded( bool $observed, bool $rebuilt ): void {
		$this->record_lookup_on_a_snapshot( $observed, $rebuilt );
		$this->assertFalse( Request_State::outdated_snapshot_rebuilt(), 'Precondition: this request did not rebuild an outdated snapshot.' );
		$store = $this->createMock( Store_Interface::class );
		$store->expects( $this->never() )->method( 'set' );
		$this->wire( null, static fn() => $store );

		$this->serve( '/tst/v1/ok' );

		$this->assertSame( Bypass_Reason::NO_LOOKUP->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assert_nothing_stored();
		$this->assert_no_lookup_failure_recorded();
	}

	public static function lookups_that_did_not_rebuild_an_outdated_snapshot(): array {
		return array(
			'an outdated snapshot that no recompile rebuilt' => array( true, false ),
			'a rebuild of a snapshot seen as current'        => array( false, true ),
			'neither'                                        => array( false, false ),
		);
	}

	public function test_a_request_that_rebuilt_its_outdated_snapshot_leaves_an_earlier_failure_of_the_route_unchanged(): void {
		update_option( Failure_Recorder::OPTION, $this->failure( 'capture', '/tst/v1/ok' ), false );
		$this->record_lookup_on_a_snapshot( true, true );
		$this->wire();

		$this->serve( '/tst/v1/ok' );

		$this->assertSame( Bypass_Reason::NO_LOOKUP->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$last = Failure_Recorder::last();
		$this->assertIsArray( $last );
		$this->assertSame( 'boom', $last['message'] ?? null, 'The refusal neither recorded nor cleared anything.' );
		$this->assertSame( 1, $last['count'] ?? null );
		$this->assertSame( '/tst/v1/ok', get_option( Failure_Recorder::OPTION )['subject'] ?? null );
	}

	public function test_a_lookup_that_failed_is_still_an_error_whatever_the_snapshot(): void {
		$this->simulate( '/wp-json/tst/v1/ok' );
		Request_State::record_outdated_snapshot();
		Request_State::record_lookup( $this->lookup_context(), Policy_Decision::bypass( Bypass_Reason::ERROR ), null );
		Request_State::record_snapshot_rebuilt();
		$this->wire();

		$this->serve( '/tst/v1/ok' );

		$this->assertSame( Bypass_Reason::ERROR->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assert_nothing_stored();
	}

	public function test_a_lookup_that_threw_stores_nothing_when_a_flush_committed_before_the_store(): void {
		$this->simulate( '/wp-json/tst/v1/ok' );
		Request_State::record_lookup( $this->lookup_context(), Policy_Decision::bypass( Bypass_Reason::ERROR ), null );
		update_option( Failure_Recorder::OPTION, $this->failure( 'lookup', '/tst/v1/ok' ), false );
		$this->commit_a_flush();
		$this->wire();

		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::ERROR->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assert_nothing_stored();
		$this->assertSame( 'lookup', Failure_Recorder::last()['context'] ?? null, 'The failure of the lookup is not cleared by a response that was not stored.' );
	}

	public function test_a_miss_whose_lookup_could_not_read_the_epoch_stores_nothing_when_a_flush_committed_before_the_store(): void {
		$this->simulate( '/wp-json/tst/v1/ok' );
		$config = Compiled_Config::load();
		$this->assertNotNull( $config );
		$lookup = new Lookup( $this->policy(), new Repository(), $this->capturing_writer() );
		$context = $this->lookup_context();
		$this->failing_epoch_reads( static fn() => $lookup->run( $context, $config ) );
		$this->assertTrue( Request_State::lookup_done() );
		$this->assertNull( Request_State::flush_epoch(), 'Precondition: the lookup recorded no epoch.' );
		$this->commit_a_flush();
		$this->wire();

		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::ERROR->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assert_nothing_stored();
	}

	public function test_a_failing_epoch_read_before_the_write_is_an_error_and_stores_nothing(): void {
		$this->record_lookup_at( $this->flush_epoch() );
		$this->wire();

		$this->failing_epoch_reads( fn() => $this->serve( '/tst/v1/ok' ) );

		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::ERROR->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertSame( 'capture', Failure_Recorder::last()['context'] ?? null );
		$this->assert_nothing_stored();
	}

	public function test_a_failing_epoch_read_after_the_write_deletes_the_entry_and_is_an_error(): void {
		$this->record_lookup_at( $this->flush_epoch() );
		$inner = Store_Factory::for_mode( 'database', new Repository(), 1 );
		$store = $this->createMock( Store_Interface::class );
		$store->method( 'set' )->willReturnCallback(
			function ( Entry_Data $entry ) use ( $inner ): ?int {
				$this->written = $entry;
				$id            = $inner->set( $entry );
				// From here on, the epoch cannot be read any more.
				add_filter( 'query', array( $this, 'break_epoch_read' ) );
				return $id;
			}
		);
		$store->method( 'delete' )->willReturnCallback( static fn( array $ids ): int => $inner->delete( $ids ) );
		$this->wire( null, static fn() => $store );

		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		try {
			$this->serve( '/tst/v1/ok' );
		} finally {
			remove_filter( 'query', array( $this, 'break_epoch_read' ) );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertInstanceOf( Entry_Data::class, $this->written );
		$this->assertSame( Bypass_Reason::ERROR->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertSame( 'capture', Failure_Recorder::last()['context'] ?? null );
		$this->assertSame( array(), $this->stored_keys );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'The entry written before the failed read was deleted.' );
	}

	/**
	 * The `query` filter that breaks the reads of the flush epoch.
	 *
	 * @param mixed $query SQL.
	 *
	 * @return mixed
	 */
	public function break_epoch_read( $query ) {
		return is_string( $query ) && 1 === preg_match( '/^\s*SELECT\b.*rfrac_flush_epoch/s', $query ) ? 'RFRAC INVALID SQL' : $query;
	}

	public function test_successful_store_stays_a_miss_and_records_no_failure(): void {
		$this->wire();
		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 'MISS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertArrayNotHasKey( 'X-Rfrac-Cache-Reason', $this->server->sent_headers );
		$this->assertNull( Failure_Recorder::last() );
	}

	/**
	 * A response that ends up not stored is never reported as MISS, but as BYPASS with the store-phase reason.
	 *
	 * @dataProvider not_stored_responses
	 *
	 * @param string        $route  Route to serve.
	 * @param Bypass_Reason $reason Expected reason.
	 * @param callable|null $setup  Prepares the test; may return the tag provider to wire.
	 */
	public function test_not_stored_response_is_marked_bypass_with_the_store_reason( string $route, Bypass_Reason $reason, ?callable $setup = null ): void {
		$this->assertTrue( WP_DEBUG, 'The test suite runs with WP_DEBUG.' );
		$provider = null !== $setup ? $setup( $this ) : null;
		$this->wire( $provider instanceof Tag_Provider_Interface ? $provider : null );
		$output = $this->serve( $route );

		$this->assertNotSame( '', $output, 'The response itself is served unchanged.' );
		$this->assert_nothing_stored();
		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( $reason->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
	}

	public static function not_stored_responses(): array {
		return array(
			'not 200'                  => array( '/tst/v1/missing', Bypass_Reason::STATUS ),
			'Set-Cookie header'        => array( '/tst/v1/cookie', Bypass_Reason::SET_COOKIE ),
			'header() cookie'          => array( '/tst/v1/php-cookie', Bypass_Reason::SET_COOKIE ),
			'empty result'             => array( '/tst/v1/empty', Bypass_Reason::EMPTY ),
			'logged in'                => array( '/tst/v1/admin', Bypass_Reason::USER ),
			'no-cache'                 => array(
				'/tst/v1/ok',
				Bypass_Reason::PRIVATE,
				static fn() => add_filter( 'rest_send_nocache_headers', '__return_true' ),
			),
			'max-age=0'                => array(
				'/tst/v1/ok',
				Bypass_Reason::PRIVATE,
				static function ( self $test ): void {
					$test->php_sent[] = 'Cache-Control: max-age=0';
				},
			),
			'too large'                => array(
				'/tst/v1/big',
				Bypass_Reason::TOO_LARGE,
				static fn( self $test ) => $test->configure( array( 'max_payload_bytes' => 1024 ) ),
			),
			'too many tags'            => array(
				'/tst/v1/ok',
				Bypass_Reason::TOO_MANY_TAGS,
				static fn( self $test ) => $test->provider( array_map( static fn( int $i ): string => 'post:' . $i, range( 1, Cache_Policy::MAX_TAGS + 1 ) ) ),
			),
			'vetoed'                   => array(
				'/tst/v1/ok',
				Bypass_Reason::FILTERED,
				static fn() => add_filter( Hooks::STORE_ALLOWED, '__return_false' ),
			),
			'provider refused'         => array(
				'/tst/v1/ok',
				Bypass_Reason::FILTERED,
				static fn( self $test ) => $test->provider( null ),
			),
			'collector overflow'       => array(
				'/tst/v1/ok',
				Bypass_Reason::TOO_MANY_TAGS,
				static fn( self $test ) => $test->refusing_provider( Bypass_Reason::TOO_MANY_TAGS ),
			),
			'collection incomplete'    => array(
				'/tst/v1/ok',
				Bypass_Reason::ERROR,
				static fn( self $test ) => $test->refusing_provider( Bypass_Reason::ERROR ),
			),
			'store threw an exception' => array(
				'/tst/v1/ok',
				Bypass_Reason::ERROR,
				static fn() => add_filter(
					Hooks::ENTRY_TTL,
					static function () {
						throw new RuntimeException( 'ttl filter broke' );
					}
				),
			),
		);
	}

	/**
	 * A tag provider that refuses every response with a reason.
	 *
	 * @param Bypass_Reason $reason Reason.
	 *
	 * @return Refusal_Aware_Tag_Provider_Interface
	 */
	private function refusing_provider( Bypass_Reason $reason ): Refusal_Aware_Tag_Provider_Interface {
		return new class( $reason ) implements Refusal_Aware_Tag_Provider_Interface {
			public function __construct( private readonly Bypass_Reason $reason ) {
			}

			public function collect( WP_REST_Request $request, mixed $data ): ?array {
				return null;
			}

			public function reset(): void {
			}

			public function refusal(): ?Bypass_Reason {
				return $this->reason;
			}
		};
	}

	public function test_reason_header_is_only_sent_for_rest_requests_in_debug(): void {
		$capture = $this->wire();
		$server  = new Spy_REST_Server();
		$method  = new ReflectionMethod( Capture::class, 'mark_outcome' );
		$method->setAccessible( true );

		$method->invoke( $capture, $server, Bypass_Reason::STATUS, false );
		$this->assertSame( 'BYPASS', $server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertArrayNotHasKey( 'X-Rfrac-Cache-Reason', $server->sent_headers, 'Not for a request that is not a REST request.' );

		$this->assertSame( array( 'X-Rfrac-Cache' => 'BYPASS' ), Response_Writer::bypass_headers( Bypass_Reason::STATUS, false ), 'Not without WP_DEBUG.' );
	}

	public function test_outcome_is_not_sent_by_core_once_output_has_started(): void {
		if ( ! headers_sent() ) {
			$this->markTestSkipped( 'Needs output to have started, as it has under PHPUnit.' );
		}
		$capture  = $this->wire();
		$server   = new WP_REST_Server();
		$request  = new WP_REST_Request( 'GET', '/tst/v1/ok' );
		$response = new WP_REST_Response( array( 'a' => 1 ) );
		$this->simulate( '/wp-json/tst/v1/ok' );
		$this->record_lookup_of_request( $this->flush_epoch() );
		$capture->on_post_dispatch( $response, $server, $request );

		$warnings = array();
		set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
				$warnings[] = $errstr;
				return true;
			}
		);
		try {
			$capture->on_pre_echo( array( 'a' => 1 ), $server, $request );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $warnings, 'No "headers already sent" warning.' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'The response is still stored.' );
	}

	/**
	 * @dataProvider serving_contexts
	 *
	 * @param string $context Failure context.
	 * @param string $subject Route the failure was recorded for.
	 */
	public function test_stored_response_clears_a_recorded_failure_of_the_serving_path( string $context, string $subject ): void {
		$this->simulate( '/wp-json/tst/v1/ok' );
		$this->record_lookup_of_request( $this->flush_epoch() );
		update_option( Failure_Recorder::OPTION, $this->failure( $context, $subject ), false );

		$this->wire();
		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 'MISS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertNull( Failure_Recorder::last(), $context . ' succeeded again, so its failure is cleared.' );
	}

	public static function serving_contexts(): array {
		return array(
			'capture of the route'       => array( 'capture', '/tst/v1/ok' ),
			'capture without a route'    => array( 'capture', '' ),
			'tags of the route'          => array( 'tags', '/tst/v1/ok' ),
			'lookup of the route'        => array( 'lookup', '/tst/v1/ok' ),
			'fallback server preparation' => array( 'fallback_server', '' ),
		);
	}

	public function test_early_server_failure_is_cleared_when_the_early_server_ran_the_lookup(): void {
		$this->simulate( '/wp-json/tst/v1/ok' );
		Request_State::record_boot( Mu_Installer::TEMPLATE_VERSION, true );
		$this->record_lookup_of_request( $this->flush_epoch() );
		update_option( Failure_Recorder::OPTION, $this->failure( 'early_server' ), false );

		$this->wire();
		$this->serve( '/tst/v1/ok' );

		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_fallback_server_failure_stays_when_the_early_server_ran_the_lookup(): void {
		$this->simulate( '/wp-json/tst/v1/ok' );
		Request_State::record_boot( Mu_Installer::TEMPLATE_VERSION, true );
		$this->record_lookup_of_request( $this->flush_epoch() );
		update_option( Failure_Recorder::OPTION, $this->failure( 'fallback_server' ), false );

		$this->wire();
		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 'fallback_server', Failure_Recorder::last()['context'] ?? null );
	}

	/**
	 * @dataProvider other_failures
	 *
	 * @param string $context   Failure context.
	 * @param string $subject   Route the failure was recorded for.
	 * @param bool   $clearable Whether it may be cleared.
	 */
	public function test_stored_response_keeps_another_failure( string $context, string $subject = '', bool $clearable = true ): void {
		update_option( Failure_Recorder::OPTION, $this->failure( $context, $subject, $clearable ), false );

		$this->wire();
		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 'MISS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( $context, Failure_Recorder::last()['context'] ?? null, 'This success says nothing about that failure.' );
	}

	public static function other_failures(): array {
		return array(
			'compile'                    => array( 'compile' ),
			'hit'                        => array( 'hit' ),
			'hook'                       => array( 'hook:save_post' ),
			'migration'                  => array( 'migration' ),
			'flush'                      => array( 'flush' ),
			'early server'               => array( 'early_server' ),
			// A failure of another route stays: the routes would otherwise clear each other's failures.
			'capture of another route'   => array( 'capture', '/tst/v1/big' ),
			'tags of another route'      => array( 'tags', '/tst/v1/big' ),
			'tag limit overflow'         => array( 'tags', '/tst/v1/ok', false ),
		);
	}

	public function test_capture_failure_is_recorded_for_its_route(): void {
		$store = $this->createMock( Store_Interface::class );
		$store->method( 'set' )->willThrowException( new RuntimeException( 'db down' ) );
		$this->wire( null, static fn() => $store );
		$this->serve( '/tst/v1/ok' );

		$this->assertSame( '/tst/v1/ok', get_option( Failure_Recorder::OPTION )['subject'] ?? null );
	}

	public function test_response_served_by_another_plugin_is_never_reported_as_miss(): void {
		$this->wire();
		add_filter( 'rest_pre_serve_request', '__return_true' );
		$this->serve( '/tst/v1/ok' );

		$this->assert_nothing_stored();
		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null, 'The provisional marker stays.' );
	}

	public function test_a_response_that_is_not_stored_clears_nothing(): void {
		update_option( Failure_Recorder::OPTION, $this->failure( 'capture', '/tst/v1/missing' ), false );
		$this->wire();
		$this->serve( '/tst/v1/missing' );
		$this->assertSame( 'capture', Failure_Recorder::last()['context'] ?? null );
	}

	/**
	 * A recorded failure, a minute old.
	 *
	 * @param string $context   Context.
	 * @param string $subject   Route it was recorded for ('' for none).
	 * @param bool   $clearable Whether a success may clear it.
	 *
	 * @return array<string,mixed>
	 */
	private function failure( string $context, string $subject = '', bool $clearable = true ): array {
		return array(
			'context'   => $context,
			'message'   => 'boom',
			'time'      => time() - 60,
			'count'     => 1,
			'subject'   => $subject,
			'clearable' => $clearable,
			'cleared'   => false,
		);
	}

	public function test_rule_aware_provider_receives_the_policy_rule_key(): void {
		$provider = new class() implements Rule_Aware_Tag_Provider_Interface {
			public ?string $key = null;

			public function use_rule_key( ?string $key ): void {
				$this->key = $key;
			}

			public function collect( WP_REST_Request $request, mixed $data ): ?array {
				return array( 'post:1' );
			}

			public function reset(): void {
			}
		};
		$this->wire( $provider );

		$this->serve( '/tst/v1/ok' );

		$this->assertSame( '/tst/v1/*', $provider->key );
	}

	public function test_throwing_provider_is_swallowed(): void {
		$provider = new class() implements Tag_Provider_Interface {
			public function collect( WP_REST_Request $request, mixed $data ): ?array {
				throw new LogicException( 'boom' );
			}
			public function reset(): void {
				throw new LogicException( 'boom again' );
			}
		};
		$this->wire( $provider );

		$this->assertSame( '{"a":1}', $this->serve( '/tst/v1/ok' ) );
		$this->assert_nothing_stored();
	}

	public function test_filters_return_their_input_unchanged(): void {
		$capture = $this->wire();
		$this->assertSame( array( 'X' ), $capture->record_expose_headers( array( 'X' ), null ) );
		$this->assertSame( 'odd', $capture->record_allow_headers( 'odd', null ) );
		$this->assertSame( 'odd', $capture->on_post_dispatch( 'odd', null, null ) );
		$this->assertSame( 'odd', $capture->on_pre_echo( 'odd', null, null ) );
	}

	public function test_entry_count_is_cached_in_the_object_cache(): void {
		$this->wire();
		$this->assertFalse( wp_cache_get( 'count', 'rfrac' ) );
		$this->serve( '/tst/v1/ok' );
		$this->assertSame( 1, wp_cache_get( 'count', 'rfrac' ), 'Counted fresh (0), then bumped by the entry just stored.' );
	}

	public function test_every_store_bumps_the_cached_entry_count(): void {
		wp_cache_set( 'count', 0, 'rfrac' );
		$this->wire();
		$this->serve( '/tst/v1/ok' );
		$this->wire();
		$this->serve( '/tst/v1/headers' );

		$this->assertSame( 2, Rfrac_Entry_Fixtures::count() );
		$this->assertSame( 2, wp_cache_get( 'count', 'rfrac' ) );
	}

	public function test_a_stale_cached_count_is_recounted_at_the_floor_and_the_ceiling_holds(): void {
		$this->configure( array( 'max_entries' => 100 ) );
		$this->insert_entries( 150 );
		wp_cache_set( 'count', 120, 'rfrac' );
		$this->wire();

		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::ENTRY_CEILING->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertSame( 150, Rfrac_Entry_Fixtures::count(), 'Nothing was stored.' );
		$this->assertSame( array(), $this->stored_keys );
		$this->assertSame( 150, wp_cache_get( 'count', 'rfrac' ), 'The fresh count replaced the stale one.' );
	}

	public function test_a_missing_count_is_counted_fresh_and_the_ceiling_holds(): void {
		$this->configure( array( 'max_entries' => 100 ) );
		$this->insert_entries( 150 );
		$this->wire();

		$this->serve( '/tst/v1/ok' );

		$this->assertSame( Bypass_Reason::ENTRY_CEILING->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertSame( 150, wp_cache_get( 'count', 'rfrac' ) );
	}

	public function test_a_count_below_the_floor_is_trusted_and_bumped(): void {
		$this->configure( array( 'max_entries' => 100 ) );
		wp_cache_set( 'count', 119, 'rfrac' );
		$this->wire();

		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 'MISS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$this->assertSame( 120, wp_cache_get( 'count', 'rfrac' ), 'Not recounted: the stored entry is added to the cached value.' );
	}

	public function test_a_count_at_the_floor_is_recounted_before_the_store_is_bumped(): void {
		$this->configure( array( 'max_entries' => 100 ) );
		wp_cache_set( 'count', 120, 'rfrac' );
		$this->wire();

		$this->serve( '/tst/v1/ok' );

		$this->assertSame( 'MISS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( 1, wp_cache_get( 'count', 'rfrac' ), 'Recounted (0 active), then bumped by the entry just stored.' );
	}

	public function test_a_response_that_is_not_stored_leaves_the_cached_count_alone(): void {
		wp_cache_set( 'count', 5, 'rfrac' );
		$this->wire();

		$this->serve( '/tst/v1/missing' );

		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( 5, wp_cache_get( 'count', 'rfrac' ) );
	}

	public function test_misses_at_or_above_the_recount_floor_recount_at_most_once_per_interval(): void {
		$this->configure( array( 'max_entries' => 100 ) );
		$this->insert_entries( 125 );
		$recounts = $this->count_recounts();

		for ( $i = 0; $i < 5; ++$i ) {
			$this->wire();
			$this->serve( '/tst/v1/ok?search=q' . $i );
			$this->assertSame( 'MISS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null, 'Every miss is still stored below the ceiling.' );
		}

		$this->assertSame( 1, $recounts(), 'One recount for five unique-query misses in the band, not one per miss.' );
		$this->assertSame( 130, Rfrac_Entry_Fixtures::count() );
		$this->assertSame( 130, wp_cache_get( 'count', 'rfrac' ), 'Every store bumped the cached count.' );
	}

	public function test_the_entry_ceiling_holds_while_the_cached_count_is_not_recounted(): void {
		$this->configure( array( 'max_entries' => 100 ) );
		$this->insert_entries( 148 );
		$recounts = $this->count_recounts();

		foreach ( array( 'MISS', 'MISS', 'BYPASS' ) as $i => $outcome ) {
			$this->wire();
			$this->serve( '/tst/v1/ok?search=q' . $i );
			$this->assertSame( $outcome, $this->server->sent_headers['X-Rfrac-Cache'] ?? null, 'Request ' . $i );
		}

		$this->assertSame( Bypass_Reason::ENTRY_CEILING->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertSame( 1, $recounts(), 'Only the first request counted; the bumped cached count stopped the third.' );
		$this->assertSame( 150, Rfrac_Entry_Fixtures::count() );
	}

	public function test_the_count_is_taken_again_once_the_recount_interval_has_passed(): void {
		$this->configure( array( 'max_entries' => 100 ) );
		$this->insert_entries( 125 );
		$recounts = $this->count_recounts();

		$this->wire();
		$this->serve( '/tst/v1/ok?search=q0' );
		$this->wire();
		$this->serve( '/tst/v1/ok?search=q1' );
		$this->assertSame( 1, $recounts() );

		wp_cache_delete( 'recounted', 'rfrac' ); // The interval passed.
		$this->wire();
		$this->serve( '/tst/v1/ok?search=q2' );

		$this->assertSame( 2, $recounts() );
	}

	public function test_a_response_the_policy_refuses_is_not_counted(): void {
		$this->configure( array( 'max_entries' => 100 ) );
		$recounts = $this->count_recounts();
		$this->wire();

		$this->serve( '/tst/v1/missing' );

		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( 0, $recounts(), 'A refused response costs no COUNT query.' );
		$this->assertFalse( wp_cache_get( 'count', 'rfrac' ) );
	}

	/**
	 * Count the entry recounts (`SELECT COUNT(*) … WHERE state = 1`) that run from now on.
	 *
	 * @return \Closure Returns the number of recounts so far.
	 */
	private function count_recounts(): \Closure {
		$recounts = 0;
		add_filter(
			'query',
			static function ( $query ) use ( &$recounts ) {
				if ( 1 === preg_match( '/SELECT\s+COUNT\(\*\)\s+FROM\s+\S+\s+WHERE\s+state\s*=\s*1\b/i', (string) $query ) ) {
					++$recounts;
				}
				return $query;
			}
		);
		return static function () use ( &$recounts ): int {
			return $recounts;
		};
	}

	/**
	 * Insert active entries directly.
	 *
	 * @param int $count Number of entries.
	 *
	 * @return void
	 */
	private function insert_entries( int $count ): void {
		for ( $i = 0; $i < $count; ++$i ) {
			Rfrac_Entry_Fixtures::insert_entry();
		}
	}

	public function test_core_serving_is_unchanged_without_a_hit_on_pre_serve_request(): void {
		foreach ( Plugin::instance()->engine_hooks() as $hook ) {
			$this->assertNotSame( 'rest_pre_serve_request', $hook['hook'], 'The engine never takes over serving from core.' );
		}

		$served = array();
		add_filter(
			'rest_pre_serve_request',
			static function ( $value ) use ( &$served ) {
				$served[] = $value;
				return $value;
			},
			PHP_INT_MAX
		);
		$this->wire();
		$output = $this->serve( '/tst/v1/ok' );

		$this->assertSame( array( false ), $served, 'Core still serves the miss itself.' );
		$this->assertSame( '{"a":1}', $output, 'Echoed once, by core.' );
		$this->assertSame( $output, $this->stored()->payload );
	}

	/**
	 * A stub provider.
	 *
	 * @param string[]|null $tags Tags to return.
	 *
	 * @return object
	 */
	private function provider( ?array $tags ): object {
		return new class( $tags ) implements Tag_Provider_Interface {
			public int $collects      = 0;
			public int $resets        = 0;
			public ?string $last_route = null;
			public mixed $last_data   = null;

			public function __construct( private readonly ?array $tags ) {
			}

			public function collect( WP_REST_Request $request, mixed $data ): ?array {
				++$this->collects;
				$this->last_route = $request->get_route();
				$this->last_data  = $data;
				return $this->tags;
			}

			public function reset(): void {
				++$this->resets;
			}
		};
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_kill_switch_bypasses_and_stores_nothing_without_a_recompile(): void {
		define( 'RFRAC_DISABLE', true );
		$this->assertTrue( Compiled_Config::load()->enabled(), 'The compiled config is not rebuilt.' );
		$this->wire();

		$this->assertSame( '{"a":1}', $this->serve( '/tst/v1/ok' ) );

		$this->assertSame( 'BYPASS', $this->seen_headers['X-Rfrac-Cache'] ?? null );
		$this->assert_nothing_stored();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_kill_switch_engaged_after_dispatch_still_prevents_storing(): void {
		$this->wire();
		add_filter(
			'rest_pre_echo_response',
			static function ( $data ) {
				define( 'RFRAC_DISABLE', true );
				return $data;
			},
			0
		);

		$this->assertSame( '{"a":1}', $this->serve( '/tst/v1/ok' ) );

		$this->assertSame( 'BYPASS', $this->seen_headers['X-Rfrac-Cache'] ?? null, 'Provisional at dispatch.' );
		$this->assertArrayNotHasKey( 'X-Rfrac-Cache-Reason', $this->seen_headers, 'The switch was off at dispatch.' );
		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::DISABLED->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assert_nothing_stored();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_kill_switch_stays_the_first_check_for_a_request_without_a_lookup(): void {
		$this->wire();
		add_filter(
			'rest_pre_echo_response',
			static function ( $data ) {
				define( 'RFRAC_DISABLE', true );
				return $data;
			},
			0
		);

		$this->serve_without_lookup( '/tst/v1/ok' );

		$this->assertSame( 'BYPASS', $this->server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::DISABLED->value, $this->server->sent_headers['X-Rfrac-Cache-Reason'] ?? null, 'Not no_lookup.' );
		$this->assert_nothing_stored();
	}
}
