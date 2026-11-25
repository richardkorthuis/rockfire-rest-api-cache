<?php
/**
 * Tests for the bridge of the store-phase hooks of the original plugin: the TTL, the empty result sets and the
 * store veto of a captured response.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Legacy\Findings;
use Rockfire\Rest_Api_Cache\Legacy\Hook_Findings;
use Rockfire\Rest_Api_Cache\Legacy\Hook_Registry;
use Rockfire\Rest_Api_Cache\Legacy\Store_Bridge;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Request_State;
use Rockfire\Rest_Api_Cache\Storage\Cached_Response;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Factory;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Store_Bridge
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Module
 */
class Test_Store_Bridge extends Rfrac_E2e_Test_Case {

	/**
	 * The seven legacy hooks of the store phase and the tagging of a response.
	 */
	private const LEGACY_HOOKS = array(
		'wp_rest_cache/timeout',
		'wp_rest_cache/skip_empty_result_set',
		'wp_rest_cache/skip_caching',
		'wp_rest_cache/determine_object_type',
		'wp_rest_cache/is_single_item',
		'wp_rest_cache/process_cache_relations',
		'wp_rest_cache/insert_cache_relation',
	);

	/**
	 * The `[ hook, replacement ]` pairs of every `deprecated_hook_run` of the test.
	 *
	 * @var list<array{0:string,1:string}>
	 */
	private array $deprecated = array();

	public function set_up() {
		parent::set_up();
		// The booted container wired its legacy hooks; the live request of the harness wires its own.
		foreach ( Plugin::instance()->legacy_hooks() as $hook ) {
			remove_filter( $hook['hook'], $hook['callback'], $hook['priority'] );
		}
		$this->deprecated = array();
		add_action(
			'deprecated_hook_run',
			function ( $hook, $replacement ): void {
				$this->deprecated[] = array( (string) $hook, (string) $replacement );
			},
			10,
			2
		);
		self::factory()->post->create_many( 3 );
		$this->configure( array( 'store_mode' => 'database' ) );
	}

	/**
	 * Register the routes of the tests (runs on `rest_api_init`).
	 *
	 * @return void
	 */
	public function register_bridge_routes(): void {
		$route = static function ( string $path, mixed $data ): void {
			register_rest_route(
				'tst/v1',
				$path,
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => static fn() => $data,
				)
			);
		};

		$route( '/empty', array() );
		$route( '/item', array( 'id' => 7 ) );
		$route( '/items', array( array( 'id' => 7 ), array( 'id' => 9 ) ) );
	}

	/**
	 * The names of the deprecated hooks that were run.
	 *
	 * @return list<string>
	 */
	private function deprecated_names(): array {
		return array_column( $this->deprecated, 0 );
	}

	/**
	 * The entry stored for the current request, null when there is none.
	 *
	 * @return Cached_Response|null
	 */
	private function stored(): ?Cached_Response {
		$config = Compiled_Config::load();
		$this->assertNotNull( $config );
		$context  = Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() );
		$decision = $this->policy()->evaluate( $context, $config );
		$this->assertCacheable( $decision );
		return Store_Factory::for_mode( 'database', new Repository(), 0 )->get( Cache_Key::key( $context, $decision ) );
	}

	/**
	 * The stored lifetime of an entry in seconds.
	 *
	 * @param Cached_Response|null $entry The entry.
	 *
	 * @return int
	 */
	private function ttl_of( ?Cached_Response $entry ): int {
		$this->assertInstanceOf( Cached_Response::class, $entry, 'The response was stored.' );
		return $entry->expires_at - $entry->created_at;
	}

	/**
	 * Assert that the live response was not stored, with the reason of the debug header.
	 *
	 * @param Rfrac_Serving_Harness $h      The harness.
	 * @param Bypass_Reason         $reason The reason it was not stored.
	 *
	 * @return void
	 */
	private function assertNotStored( Rfrac_Serving_Harness $h, Bypass_Reason $reason ): void {
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'Nothing was stored.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->assertSame( $reason->value, $h->live_header( 'X-Rfrac-Cache-Reason' ) );
		}
	}

	/**
	 * Run the lookup of a request that is not served from the cache, so its decision is recorded.
	 *
	 * @param string              $uri    Request URI.
	 * @param array<string,mixed> $server Extra `$_SERVER` entries.
	 * @param array<string,mixed> $cookie Cookies.
	 *
	 * @return void
	 */
	private function lookup_of( string $uri, array $server = array(), array $cookie = array() ): void {
		$h = $this->harness( Rfrac_Serving_Harness::EARLY );
		$h->request( $uri, $server, $cookie );
		$this->assertFalse( $h->lookup() );
	}

	/**
	 * Run the lookup of a cacheable request and record it again as a request of another method.
	 *
	 * The policy of the engine decides the lookup, so the decision is the cacheable one of the GET request; only the
	 * context carries the method, as it does for a route that opted in to it.
	 *
	 * @param string $uri          Request URI.
	 * @param string $method       Upper-case method of the recorded context.
	 * @param string $content_type Content-Type of the request body, empty for a request without a body.
	 * @param string $body         Raw request body; a form body is also parsed into the body parameters PHP would have.
	 *
	 * @return void
	 */
	private function lookup_of_method( string $uri, string $method, string $content_type = '', string $body = '' ): void {
		$this->lookup_of( $uri );
		$decision = Request_State::decision();
		$this->assertNotNull( $decision );
		$this->assertTrue( $decision->cacheable, 'Precondition: the lookup was cacheable.' );

		$query = array();
		parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
		$server = array(
			'REQUEST_METHOD' => $method,
			'REQUEST_URI'    => $uri,
		);
		$post   = array();
		if ( '' !== $content_type ) {
			$server['CONTENT_TYPE'] = $content_type;
			if ( 'application/x-www-form-urlencoded' === $content_type ) {
				parse_str( $body, $post );
			}
		}
		$context = Request_Context::from_parts(
			$server,
			$query,
			$post,
			array(),
			rest_get_url_prefix(),
			'',
			true,
			1,
			false,
			array(),
			'' === $body ? null : $body
		);
		Request_State::record_lookup( $context, $decision, Request_State::cache_key() );
	}

	/**
	 * Data provider: the methods a request context can carry.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function methods(): array {
		return array(
			'GET'   => array( 'GET' ),
			'POST'  => array( 'POST' ),
			'PUT'   => array( 'PUT' ),
			'PATCH' => array( 'PATCH' ),
		);
	}

	/**
	 * Hook a spy that counts the runs of each legacy hook of the store phase and passes the value on.
	 *
	 * @param array<string,int> $runs Runs by hook name, filled as they happen.
	 *
	 * @return void
	 */
	private function count_runs_of_the_store_hooks( array &$runs ): void {
		foreach ( array( 'wp_rest_cache/timeout', 'wp_rest_cache/skip_empty_result_set', 'wp_rest_cache/skip_caching' ) as $name ) {
			add_filter(
				$name,
				static function ( $value ) use ( $name, &$runs ) {
					$runs[ $name ] = ( $runs[ $name ] ?? 0 ) + 1;
					return $value;
				},
				10,
				3
			);
		}
	}

	/**
	 * Data provider: each kind of credential, as `$_SERVER` entries and cookies.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>}>
	 */
	public static function credentials(): array {
		return array(
			'an Authorization header' => array( array( 'HTTP_AUTHORIZATION' => 'Bearer abc' ), array() ),
			'a logged-in cookie'      => array( array(), array( 'wordpress_logged_in_abc' => 'visitor|1|2|3' ) ),
		);
	}

	/**
	 * Data provider: each kind of credential on each serving path.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:array<string,mixed>}>
	 */
	public static function credentials_on_each_path(): array {
		$cases = array();
		foreach ( array( Rfrac_Serving_Harness::EARLY, Rfrac_Serving_Harness::FALLBACK ) as $path ) {
			foreach ( self::credentials() as $label => $credential ) {
				$cases[ $path . ' with ' . $label ] = array( $path, $credential[0], $credential[1] );
			}
		}
		return $cases;
	}

	// ---------- Registration ----------

	public function test_the_hooks_of_the_bridge_are_the_three_store_hooks_and_the_last_refusal(): void {
		$this->assertSame(
			array(
				array( Hooks::ENTRY_TTL, 'timeout', 10, 3 ),
				array( Hooks::CACHE_EMPTY_RESULTS, 'empty_results', 10, 2 ),
				array( Hooks::STORE_ALLOWED, 'store_allowed', 10, 4 ),
				array( Hooks::STORE_ALLOWED, 'refuse_unapplied_hit_filters', PHP_INT_MAX, 1 ),
			),
			Store_Bridge::hooks()
		);
	}

	public function test_the_module_registers_the_bridge_among_the_legacy_hooks_only(): void {
		$plugin = new Plugin();

		$plugin->register_legacy_hooks();

		$legacy = array();
		foreach ( $plugin->legacy_hooks() as $hook ) {
			$legacy[ $hook['hook'] ][] = array( $hook['priority'], $hook['args'] );
		}
		$this->assertSame( array( array( 10, 3 ) ), $legacy[ Hooks::ENTRY_TTL ] );
		$this->assertSame( array( array( 10, 2 ) ), $legacy[ Hooks::CACHE_EMPTY_RESULTS ] );
		$this->assertSame( array( array( 10, 4 ), array( PHP_INT_MAX, 1 ) ), $legacy[ Hooks::STORE_ALLOWED ] );
		$this->assertSame( array(), $plugin->engine_hooks(), 'No engine hook was registered.' );
	}

	// ---------- rfrac/entry_ttl (wp_rest_cache/timeout) ----------

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_timeout_callback_sets_the_stored_ttl_and_the_deprecation_names_the_replacement( string $path ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/timeout' );
		$seen = array();
		add_filter(
			'wp_rest_cache/timeout',
			static function ( $ttl, $options ) use ( &$seen ) {
				$seen = array( $ttl, $options );
				return 120;
			},
			10,
			2
		);
		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/wp/v2/posts' );

		$this->assertNotNull( $h->miss( '/wp/v2/posts' ) );

		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 120, $this->ttl_of( $this->stored() ) );
		$this->assertContains( array( 'wp_rest_cache/timeout', Hooks::ENTRY_TTL ), $this->deprecated );
		$this->assertSame(
			array(
				3600,
				array(
					'uri'             => '/wp-json/wp/v2/posts',
					'object_type'     => '',
					'request_headers' => array(),
					'request_method'  => 'GET',
				),
			),
			$seen,
			'The callback gets the rule TTL and the options of the original.'
		);
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider methods
	 *
	 * @param string $method Method of the recorded context.
	 */
	public function test_the_timeout_options_and_the_empty_result_request_carry_the_method_of_the_context( string $method ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/timeout' );
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_empty_result_set' );
		$this->lookup_of_method( '/wp-json/wp/v2/posts?per_page=2', $method );
		$options = null;
		$request = null;
		add_filter(
			'wp_rest_cache/timeout',
			static function ( $ttl, $given ) use ( &$options ) {
				$options = $given;
				return $ttl;
			},
			10,
			2
		);
		add_filter(
			'wp_rest_cache/skip_empty_result_set',
			static function ( $skip, $given ) use ( &$request ) {
				$request = $given;
				return $skip;
			},
			10,
			2
		);
		$bridge = new Store_Bridge();

		$this->assertSame( 3600, $bridge->timeout( 3600 ) );
		$bridge->empty_results( false );

		$this->assertIsArray( $options );
		$this->assertSame( $method, $options['request_method'] );
		$this->assertInstanceOf( WP_REST_Request::class, $request );
		$this->assertSame( $method, $request->get_method() );
		$this->assertSame( '/wp/v2/posts', $request->get_route() );
		$this->assertSame( array( 'per_page' => '2' ), $request->get_query_params() );
	}

	/**
	 * Data provider: the methods that can carry a body, with a JSON and a form body for each.
	 *
	 * @return array<string,array{0:string,1:string,2:string}> Method, Content-Type and raw body.
	 */
	public static function bodies(): array {
		$json = '{"q":"needle","page":2,"filter":{"tag":"news"}}';
		$form = 'q=needle&page=2&filter%5Btag%5D=news';
		$type = 'application/x-www-form-urlencoded';

		return array(
			'POST with a JSON body'  => array( 'POST', 'application/json', $json ),
			'PUT with a JSON body'   => array( 'PUT', 'application/json', $json ),
			'PATCH with a JSON body' => array( 'PATCH', 'application/json', $json ),
			'POST with a form body'  => array( 'POST', $type, $form ),
			'PUT with a form body'   => array( 'PUT', $type, $form ),
			'PATCH with a form body' => array( 'PATCH', $type, $form ),
		);
	}

	/**
	 * @dataProvider bodies
	 *
	 * @param string $method       Method of the recorded context.
	 * @param string $content_type Content-Type of its body.
	 * @param string $body         Its raw body.
	 */
	public function test_the_empty_result_request_never_carries_the_body_of_the_context( string $method, string $content_type, string $body ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_empty_result_set' );
		$this->lookup_of_method( '/wp-json/wp/v2/posts?per_page=2', $method, $content_type, $body );
		$context = Request_State::context();
		$this->assertNotNull( $context );
		$this->assertNotSame( array(), $context->body_params, 'Precondition: the recorded context carries the body.' );
		$request = null;
		add_filter(
			'wp_rest_cache/skip_empty_result_set',
			static function ( $skip, $given ) use ( &$request ) {
				$request = $given;
				return $skip;
			},
			10,
			2
		);

		( new Store_Bridge() )->empty_results( false );

		$this->assertInstanceOf( WP_REST_Request::class, $request );
		$this->assertSame( $method, $request->get_method() );
		$this->assertSame( array( 'per_page' => '2' ), $request->get_query_params() );
		$this->assertSame( array(), $request->get_body_params(), 'No body parameters are passed.' );
		$this->assertEmpty( $request->get_json_params(), 'No JSON parameters are passed.' );
		$this->assertEmpty( $request->get_body(), 'No raw body is passed.' );
		$this->assertSame( array( 'per_page' => '2' ), $request->get_params(), 'The parameters are the query alone.' );
	}

	public function test_the_uri_of_the_timeout_options_has_the_sorted_query_string(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/timeout' );
		$uri = null;
		add_filter(
			'wp_rest_cache/timeout',
			static function ( $ttl, $options ) use ( &$uri ) {
				$uri = $options['uri'];
				return $ttl;
			},
			10,
			2
		);
		$h = $this->harness( Rfrac_Serving_Harness::EARLY )->with_legacy();
		$h->request( '/wp-json/wp/v2/posts?per_page=2&page=1' );

		$h->miss( '/wp/v2/posts' );

		$this->assertSame( '/wp-json/wp/v2/posts?page=1&per_page=2', $uri );
		$this->assertSame( 3600, $this->ttl_of( $this->stored() ) );
	}

	/**
	 * Data provider: what a timeout callback returns and the lifetime that is stored.
	 *
	 * @return array<string,array{0:mixed,1:int}>
	 */
	public static function timeouts(): array {
		return array(
			'an integer'        => array( 600, 600 ),
			'a numeric string'  => array( '300', 300 ),
			'a float'           => array( 90.7, 90 ),
			'zero'              => array( 0, 3600 ),
			'a negative number' => array( -30, 3600 ),
			'text'              => array( 'soon', 3600 ),
			'null'              => array( null, 3600 ),
			'false'             => array( false, 3600 ),
			'an array'          => array( array( 120 ), 3600 ),
		);
	}

	/**
	 * @dataProvider timeouts
	 *
	 * @param mixed $returned What the callback returns.
	 * @param int   $expected The stored lifetime.
	 */
	public function test_only_a_number_above_zero_replaces_the_rule_ttl( mixed $returned, int $expected ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/timeout' );
		add_filter( 'wp_rest_cache/timeout', static fn() => $returned );
		$h = $this->harness( Rfrac_Serving_Harness::EARLY )->with_legacy();
		$h->request( '/wp-json/wp/v2/posts' );

		$h->miss( '/wp/v2/posts' );

		$this->assertSame( $expected, $this->ttl_of( $this->stored() ) );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_throwing_timeout_callback_stores_the_entry_with_the_rule_ttl_and_is_recorded( string $path ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/timeout' );
		add_filter(
			'wp_rest_cache/timeout',
			static function () {
				throw new RuntimeException( 'timeout failed' );
			}
		);
		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/wp/v2/posts' );

		$this->assertNotNull( $h->miss( '/wp/v2/posts' ) );

		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 3600, $this->ttl_of( $this->stored() ) );
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'hook:wp_rest_cache/timeout', $last['context'] );
		$this->assertStringContainsString( 'timeout failed', $last['message'] );
	}

	// ---------- rfrac/cache_empty_results (wp_rest_cache/skip_empty_result_set) ----------

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_without_a_callback_an_empty_result_set_is_not_stored( string $path ): void {
		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/tst/v1/empty' );

		$this->assertNotNull( $h->miss( '/tst/v1/empty', array( $this, 'register_bridge_routes' ) ) );

		$this->assertNotStored( $h, Bypass_Reason::EMPTY );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_callback_that_does_not_skip_the_empty_result_set_gets_it_stored( string $path ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_empty_result_set' );
		$seen = array();
		add_filter(
			'wp_rest_cache/skip_empty_result_set',
			static function ( $skip, $request, $uri ) use ( &$seen ) {
				$seen = array( $skip, $request, $uri );
				return false;
			},
			10,
			3
		);
		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/tst/v1/empty' );

		$this->assertNotNull( $h->miss( '/tst/v1/empty', array( $this, 'register_bridge_routes' ) ) );

		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'The empty result set was stored.' );
		$this->assertContains( array( 'wp_rest_cache/skip_empty_result_set', Hooks::CACHE_EMPTY_RESULTS ), $this->deprecated );
		$this->assertTrue( $seen[0], 'The default of the legacy filter is to skip.' );
		$this->assertInstanceOf( WP_REST_Request::class, $seen[1] );
		$this->assertSame( '/tst/v1/empty', $seen[1]->get_route() );
		$this->assertSame( '/wp-json/tst/v1/empty', $seen[2] );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_callback_that_skips_the_empty_result_set_leaves_it_unstored( string $path ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_empty_result_set' );
		add_filter( 'wp_rest_cache/skip_empty_result_set', '__return_true' );
		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/tst/v1/empty' );

		$h->miss( '/tst/v1/empty', array( $this, 'register_bridge_routes' ) );

		$this->assertNotStored( $h, Bypass_Reason::EMPTY );
	}

	public function test_empty_results_hands_the_inverse_and_the_request_of_the_lookup_to_the_legacy_callback(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_empty_result_set' );
		$this->lookup_of( '/wp-json/wp/v2/posts?per_page=2&page=1' );
		$seen = array();
		add_filter(
			'wp_rest_cache/skip_empty_result_set',
			static function ( $skip, $request, $uri ) use ( &$seen ) {
				$seen[] = array( $skip, $request, $uri );
				return false;
			},
			10,
			3
		);
		$bridge = new Store_Bridge();

		$this->assertTrue( $bridge->empty_results( false, '/wp/v2/posts' ), 'Not skipping stores the empty result set.' );
		$this->assertTrue( $bridge->empty_results( true, '/wp/v2/posts' ) );

		$this->assertCount( 2, $seen );
		$this->assertTrue( $seen[0][0], 'Rockfire does not store: the legacy filter is told to skip.' );
		$this->assertFalse( $seen[1][0], 'Rockfire stores: the legacy filter is told not to skip.' );
		$this->assertInstanceOf( WP_REST_Request::class, $seen[0][1] );
		$this->assertSame( 'GET', $seen[0][1]->get_method() );
		$this->assertSame( '/wp/v2/posts', $seen[0][1]->get_route() );
		$this->assertSame( array( 'page' => '1', 'per_page' => '2' ), $seen[0][1]->get_query_params() ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame( '/wp-json/wp/v2/posts?page=1&per_page=2', $seen[0][2] );
	}

	public function test_empty_results_inverts_the_answer_of_the_legacy_callback(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_empty_result_set' );
		$this->lookup_of( '/wp-json/wp/v2/posts' );
		$bridge = new Store_Bridge();

		$this->assertFalse( $bridge->empty_results( false ), 'Without a callback the answer is unchanged.' );
		$this->assertTrue( $bridge->empty_results( true ), 'Without a callback the answer is unchanged.' );

		add_filter( 'wp_rest_cache/skip_empty_result_set', '__return_true' );
		$this->assertFalse( $bridge->empty_results( true ), 'A callback that skips refuses the empty result set.' );
		$this->assertFalse( $bridge->empty_results( false ) );

		remove_filter( 'wp_rest_cache/skip_empty_result_set', '__return_true' );
		add_filter( 'wp_rest_cache/skip_empty_result_set', '__return_false' );
		$this->assertTrue( $bridge->empty_results( false ), 'A callback that does not skip stores it.' );
	}

	/**
	 * AC-8: the request handed to the legacy callback has no nonce, whatever the request carried.
	 */
	public function test_empty_results_hands_a_request_without_a_nonce_to_the_legacy_callback(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_empty_result_set' );
		$this->lookup_of( '/wp-json/wp/v2/posts' );
		$decision = Request_State::decision();
		$this->assertNotNull( $decision );
		$this->assertTrue( $decision->cacheable, 'Precondition: the lookup was cacheable.' );
		$context = Request_Context::from_parts(
			array(
				'REQUEST_METHOD' => 'GET',
				'REQUEST_URI'    => '/wp-json/wp/v2/posts?_wpnonce=abc&page=1',
			),
			array(
				'_wpnonce' => 'abc',
				'page'     => '1',
			),
			array(),
			array(),
			rest_get_url_prefix(),
			'',
			true,
			1,
			false
		);
		$this->assertArrayHasKey( '_wpnonce', $context->params, 'Precondition: the context holds the nonce.' );
		Request_State::record_lookup( $context, $decision, Request_State::cache_key() );
		$seen = array();
		add_filter(
			'wp_rest_cache/skip_empty_result_set',
			static function ( $skip, $request, $uri ) use ( &$seen ) {
				$seen = array( $request, $uri );
				return $skip;
			},
			10,
			3
		);

		( new Store_Bridge() )->empty_results( false, '/wp/v2/posts' );

		$this->assertInstanceOf( WP_REST_Request::class, $seen[0] );
		$this->assertNull( $seen[0]->get_param( '_wpnonce' ) );
		$this->assertSame( array( 'page' => '1' ), $seen[0]->get_query_params() );
		$this->assertSame( '/wp-json/wp/v2/posts?page=1', $seen[1] );
	}

	// ---------- rfrac/store_allowed (wp_rest_cache/skip_caching) ----------

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_skip_caching_callback_keeps_a_cacheable_response_out_of_the_cache( string $path ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_caching' );
		$seen = array();
		add_filter(
			'wp_rest_cache/skip_caching',
			static function ( $skip ) use ( &$seen ) {
				$seen[] = $skip;
				return true;
			}
		);
		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/wp/v2/posts' );

		$this->assertNotNull( $h->miss( '/wp/v2/posts' ) );

		$this->assertNotStored( $h, Bypass_Reason::FILTERED );
		$this->assertContains( 'wp_rest_cache/skip_caching', $this->deprecated_names() );
		$this->assertSame( array( false ), $seen, 'The callback is asked once, with the default false.' );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_skip_caching_callback_that_does_not_skip_leaves_the_response_stored( string $path ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_caching' );
		add_filter( 'wp_rest_cache/skip_caching', '__return_false' );
		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/wp/v2/posts' );

		$h->miss( '/wp/v2/posts' );

		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
	}

	public function test_store_allowed_can_only_veto(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_caching' );
		$this->lookup_of( '/wp-json/wp/v2/posts' );
		$bridge = new Store_Bridge();
		add_filter( 'wp_rest_cache/skip_caching', '__return_false' );

		$this->assertTrue( $bridge->store_allowed( true, '/wp/v2/posts', '/wp/v2/posts', 10 ) );
		$this->assertFalse( $bridge->store_allowed( false, '/wp/v2/posts', '/wp/v2/posts', 10 ), 'A callback that does not skip never allows what Rockfire refused.' );

		remove_filter( 'wp_rest_cache/skip_caching', '__return_false' );
		add_filter( 'wp_rest_cache/skip_caching', static fn() => 'yes' );

		$this->assertFalse( $bridge->store_allowed( true, '/wp/v2/posts', '/wp/v2/posts', 10 ), 'Any truthy answer vetoes.' );
		$this->assertFalse( $bridge->store_allowed( false, '/wp/v2/posts', '/wp/v2/posts', 10 ) );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_throwing_skip_caching_callback_keeps_the_response_out_of_the_cache_and_is_recorded( string $path ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_caching' );
		add_filter(
			'wp_rest_cache/skip_caching',
			static function () {
				throw new RuntimeException( 'skip failed' );
			}
		);
		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/wp/v2/posts' );

		$this->assertNotNull( $h->miss( '/wp/v2/posts' ) );

		$this->assertNotStored( $h, Bypass_Reason::FILTERED );
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'hook:wp_rest_cache/skip_caching', $last['context'] );
		$this->assertStringContainsString( 'skip failed', $last['message'] );
	}

	public function test_store_allowed_fails_closed_when_a_skip_caching_callback_throws_whatever_the_verdict(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_caching' );
		$this->lookup_of( '/wp-json/wp/v2/posts' );
		$bridge = new Store_Bridge();
		$later  = 0;
		add_filter(
			'wp_rest_cache/skip_caching',
			static function () {
				throw new RuntimeException( 'skip failed' );
			}
		);
		add_filter(
			'wp_rest_cache/skip_caching',
			static function ( $skip ) use ( &$later ) {
				++$later;
				return $skip;
			},
			20
		);

		$this->assertFalse( $bridge->store_allowed( true, '/wp/v2/posts', '/wp/v2/posts', 10 ), 'The failure refuses the response.' );
		$this->assertFalse( $bridge->store_allowed( false, '/wp/v2/posts', '/wp/v2/posts', 10 ) );

		$this->assertSame( 0, $later, 'The chain stopped at the callback that threw.' );
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'hook:wp_rest_cache/skip_caching', $last['context'] );
	}

	public function test_store_allowed_leaves_no_trace_on_the_legacy_hook_and_a_callback_after_the_last_one_still_allows(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_caching' );
		$this->lookup_of( '/wp-json/wp/v2/posts' );
		$bridge = new Store_Bridge();

		$this->assertTrue( $bridge->store_allowed( true, '/wp/v2/posts', '/wp/v2/posts', 10 ) );
		$this->assertFalse( has_filter( 'wp_rest_cache/skip_caching' ), 'Without a callback nothing is attached.' );
		$this->assertSame( array(), $this->deprecated, 'Without a callback no deprecation is reported.' );

		add_filter( 'wp_rest_cache/skip_caching', '__return_false', PHP_INT_MAX );
		$this->assertTrue( $bridge->store_allowed( true, '/wp/v2/posts', '/wp/v2/posts', 10 ) );
		$this->assertSame( 1, count( $GLOBALS['wp_filter']['wp_rest_cache/skip_caching']->callbacks[ PHP_INT_MAX ] ), 'The marker was detached.' );

		add_filter( 'wp_rest_cache/skip_caching', static fn() => throw new RuntimeException( 'late' ), PHP_INT_MAX );
		$this->assertFalse( $bridge->store_allowed( true, '/wp/v2/posts', '/wp/v2/posts', 10 ) );
		$this->assertSame( 2, count( $GLOBALS['wp_filter']['wp_rest_cache/skip_caching']->callbacks[ PHP_INT_MAX ] ), 'The marker was detached after a throw as well.' );
	}

	/**
	 * With `RFRAC_LEGACY_HIT_FILTERS` the capture also asks `skip_caching` when it decides; the veto of the store
	 * phase is the same with or without the constant.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_store_veto_is_the_same_when_the_hit_filters_are_enabled(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_caching' );
		define( 'RFRAC_LEGACY_HIT_FILTERS', true );
		$this->lookup_of( '/wp-json/wp/v2/posts' );
		$bridge = new Store_Bridge();

		$this->assertTrue( $bridge->store_allowed( true, '/wp/v2/posts', '/wp/v2/posts', 10 ), 'Without a callback the verdict is unchanged.' );

		add_filter( 'wp_rest_cache/skip_caching', '__return_true' );

		$this->assertFalse( $bridge->store_allowed( true, '/wp/v2/posts', '/wp/v2/posts', 10 ) );
	}

	// ---------- rfrac/store_allowed, last (wp_rest_cache/cache_key, wp_rest_cache/filter_cache_output) ----------

	/**
	 * Data provider: the hit filters a cache hit cannot apply without the constant, and that stop storing.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function store_refusing_filters(): array {
		return array(
			'cache_key'           => array( 'wp_rest_cache/cache_key' ),
			'filter_cache_output' => array( 'wp_rest_cache/filter_cache_output' ),
		);
	}

	/**
	 * Data provider: every state the lookup of the request can be in when the store verdict is asked.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function lookup_states(): array {
		return array(
			'no lookup'                    => array( 'none' ),
			'a lookup that ended in error' => array( 'error' ),
			'a lookup that was a bypass'   => array( 'bypass' ),
			'a lookup that was cacheable'  => array( 'cacheable' ),
		);
	}

	/**
	 * Put the request state in one of the states of {@see self::lookup_states()}.
	 *
	 * @param string $state `none`, `error`, `bypass` or `cacheable`.
	 *
	 * @return void
	 */
	private function put_lookup_in_state( string $state ): void {
		Request_State::reset();
		if ( 'none' === $state ) {
			return;
		}

		$this->lookup_of( '/wp-json/wp/v2/posts', 'bypass' === $state ? array( 'HTTP_AUTHORIZATION' => 'Bearer abc' ) : array() );
		if ( 'error' === $state ) {
			$context = Request_State::context();
			$this->assertNotNull( $context );
			Request_State::record_lookup( $context, Policy_Decision::bypass( Bypass_Reason::ERROR ), null );
		}

		$decision = Request_State::decision();
		$this->assertNotNull( $decision );
		$this->assertSame( 'cacheable' === $state, $decision->cacheable, 'Precondition: the state of the lookup.' );
	}

	/**
	 * Register the legacy bridges of a container, as the booted plugin does.
	 *
	 * @return void
	 */
	private function register_the_bridges(): void {
		( new Plugin() )->register_legacy_hooks();
	}

	/**
	 * AC-3: whatever the lookup state, a hooked cache_key or filter_cache_output refuses without the constant, and the
	 * refusal runs no callback and logs no deprecation.
	 *
	 * @dataProvider lookup_states
	 *
	 * @param string $state The state of the lookup.
	 */
	public function test_without_the_constant_a_hooked_hit_filter_refuses_whatever_the_lookup_state( string $state ): void {
		$this->assertFalse( defined( Hook_Registry::HIT_FILTERS_CONSTANT ), 'Precondition.' );
		$this->put_lookup_in_state( $state );
		$this->register_the_bridges();
		$runs = array();
		foreach ( array( 'wp_rest_cache/cache_key', 'wp_rest_cache/filter_cache_output' ) as $name ) {
			add_filter(
				$name,
				static function ( $value ) use ( $name, &$runs ) {
					$runs[] = $name;
					return $value;
				}
			);
		}

		$this->assertFalse( apply_filters( Hooks::STORE_ALLOWED, true, '/wp/v2/posts', '/wp/v2/posts', 10 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		$this->assertFalse( ( new Store_Bridge() )->refuse_unapplied_hit_filters( true ) );

		$this->assertSame( array(), $runs, 'No legacy callback ran.' );
		$this->assertSame( array(), $this->deprecated, 'No deprecation is reported.' );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider store_refusing_filters
	 *
	 * @param string $name The hooked hit filter.
	 */
	public function test_each_of_the_two_hit_filters_alone_refuses_and_unhooking_it_lifts_the_refusal( string $name ): void {
		$bridge = new Store_Bridge();

		$this->assertTrue( $bridge->refuse_unapplied_hit_filters( true ), 'Nothing is hooked: the verdict is unchanged.' );

		add_filter( $name, '__return_true' );

		$this->assertFalse( $bridge->refuse_unapplied_hit_filters( true ) );
		$this->assertFalse( $bridge->refuse_unapplied_hit_filters( false ) );

		remove_filter( $name, '__return_true' );

		$this->assertTrue( $bridge->refuse_unapplied_hit_filters( true ) );
		$this->assertFalse( $bridge->refuse_unapplied_hit_filters( false ), 'It never allows what was refused.' );
	}

	/**
	 * AC-6: the other hit filters do not stop storing, and the verdict given comes back as it was.
	 */
	public function test_the_other_hit_filters_leave_the_verdict_alone(): void {
		$bridge = new Store_Bridge();
		foreach ( array( 'pre_output_headers', 'skip_caching', 'allow_skip_cache', 'disable_cors_headers' ) as $name ) {
			add_filter( 'wp_rest_cache/' . $name, '__return_true' );
		}

		$this->assertTrue( $bridge->refuse_unapplied_hit_filters( true ) );
		$this->assertFalse( $bridge->refuse_unapplied_hit_filters( false ) );
		$this->assertSame( 'yes', $bridge->refuse_unapplied_hit_filters( 'yes' ), 'A verdict that is not a boolean comes back untouched.' );
		$this->assertSame( array(), $this->deprecated, 'No legacy hook was fired.' );
	}

	/**
	 * AC-4: a site callback after the others, even one at priority 100 that says yes, cannot lift the refusal.
	 */
	public function test_a_site_callback_that_allows_cannot_lift_the_refusal(): void {
		$this->register_the_bridges();
		add_filter( 'wp_rest_cache/cache_key', '__return_empty_string' );
		add_filter( Hooks::STORE_ALLOWED, '__return_true', 100 );

		$this->assertFalse( apply_filters( Hooks::STORE_ALLOWED, true, '/wp/v2/posts', '/wp/v2/posts', 10 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.

		remove_filter( 'wp_rest_cache/cache_key', '__return_empty_string' );

		$this->assertTrue( apply_filters( Hooks::STORE_ALLOWED, true, '/wp/v2/posts', '/wp/v2/posts', 10 ), 'Control: without the callback the site decides.' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
	}

	/**
	 * Data provider: each way a site can undo a filter on a serving path, a late callback that allows and the removal of every callback.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function ways_to_undo_the_filter_on_each_path(): array {
		$cases = array();
		foreach ( array( Rfrac_Serving_Harness::EARLY, Rfrac_Serving_Harness::FALLBACK ) as $path ) {
			$cases[ 'an allowing callback at the last priority on the ' . $path . ' path' ] = array( $path, 'allow' );
			$cases[ 'the removal of every callback on the ' . $path . ' path' ]            = array( $path, 'remove' );
		}
		return $cases;
	}

	/**
	 * A site callback that runs after the refusal of the filter, at its own priority or by removing it, does not get the response stored.
	 *
	 * The callback is added once the plugin has loaded, so it runs after the refusal at the same priority; the control
	 * shows that `rfrac/store_allowed` then answers `true`, and the capture refuses all the same: it asks the bridge
	 * itself, not the filter.
	 *
	 * @dataProvider ways_to_undo_the_filter_on_each_path
	 *
	 * @param string $path   Serving path.
	 * @param string $tamper `allow` adds `__return_true` at the last priority, `remove` removes every callback.
	 */
	public function test_a_site_callback_that_undoes_the_filter_cannot_get_a_response_stored_without_the_constant( string $path, string $tamper ): void {
		$this->assertFalse( defined( Hook_Registry::HIT_FILTERS_CONSTANT ), 'Precondition.' );
		add_filter( 'wp_rest_cache/cache_key', '__return_empty_string' );
		$h        = $this->harness( $path )->with_legacy();
		$register = function () use ( $tamper ): void {
			$this->register_bridge_routes();
			if ( 'allow' === $tamper ) {
				add_filter( Hooks::STORE_ALLOWED, '__return_true', PHP_INT_MAX );
			} else {
				remove_all_filters( Hooks::STORE_ALLOWED );
			}
		};
		$h->request( '/wp-json/tst/v1/item' );

		$this->assertNotNull( $h->miss( '/tst/v1/item', $register ) );

		$this->assertTrue( apply_filters( Hooks::STORE_ALLOWED, true, '/tst/v1/item', '/tst/v1/item', 10 ), 'Control: the filter alone no longer refuses.' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		$this->assertNotStored( $h, Bypass_Reason::FILTERED );
		$this->assertNoFailures();
	}

	/**
	 * The refusal does not depend on the filter: it holds when asked directly, and ends with the callback.
	 */
	public function test_the_bridge_refuses_to_store_while_a_hit_filter_is_hooked_without_the_constant(): void {
		$this->assertFalse( defined( Hook_Registry::HIT_FILTERS_CONSTANT ), 'Precondition.' );

		$this->assertFalse( Store_Bridge::refuses_store(), 'Nothing is hooked.' );

		add_filter( 'wp_rest_cache/filter_cache_output', '__return_empty_array' );

		$this->assertTrue( Store_Bridge::refuses_store() );

		remove_filter( 'wp_rest_cache/filter_cache_output', '__return_empty_array' );

		$this->assertFalse( Store_Bridge::refuses_store() );
	}

	/**
	 * A callback added by a code edit changes no snapshot, so no compile records it: the capture that refuses to store
	 * records the finding that names the hook and the constant, no later than that first refused store.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_the_first_refused_store_records_the_finding_that_names_the_constant_without_a_compile( string $path ): void {
		$this->assertFalse( defined( Hook_Registry::HIT_FILTERS_CONSTANT ), 'Precondition.' );
		$compiled = 0;
		add_action(
			Hooks::COMPILED,
			static function () use ( &$compiled ): void {
				++$compiled;
			}
		);
		add_filter( 'wp_rest_cache/cache_key', '__return_empty_string' );
		$this->assertNull( ( new Findings() )->get( Findings::GROUP_HOOKS ), 'Precondition: nothing is recorded yet.' );
		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/tst/v1/item' );

		$this->assertNotNull( $h->miss( '/tst/v1/item', array( $this, 'register_bridge_routes' ) ) );

		$this->assertNotStored( $h, Bypass_Reason::FILTERED );
		$this->assertSame( 0, $compiled, 'No compile recorded the finding.' );
		$stored = ( new Findings() )->get( Findings::GROUP_HOOKS );
		$this->assertNotNull( $stored, 'The refused store recorded the finding.' );
		$this->assertCount( 1, $stored['findings'] );
		$this->assertSame( Hook_Findings::CODE_STORE_REFUSED, $stored['findings'][0]->code );
		$this->assertSame( 'wp_rest_cache/cache_key', $stored['findings'][0]->subject );
		$this->assertStringContainsString( Hook_Registry::HIT_FILTERS_CONSTANT, $stored['findings'][0]->message );
		$this->assertNoFailures();
	}

	/**
	 * AC-5: with the constant the rule does not veto, whatever its value but false.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_with_the_constant_the_hooked_hit_filters_do_not_veto(): void {
		define( 'RFRAC_LEGACY_HIT_FILTERS', true );
		$this->register_the_bridges();
		add_filter( 'wp_rest_cache/cache_key', '__return_empty_string' );
		add_filter( 'wp_rest_cache/filter_cache_output', '__return_empty_array' );

		$this->assertTrue( ( new Store_Bridge() )->refuse_unapplied_hit_filters( true ) );
		$this->assertFalse( Store_Bridge::refuses_store() );
		$this->assertTrue( apply_filters( Hooks::STORE_ALLOWED, true, '/wp/v2/posts', '/wp/v2/posts', 10 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		$this->assertNull( ( new Findings() )->get( Findings::GROUP_HOOKS ), 'With the constant nothing is refused, so nothing is reported.' );
	}

	/**
	 * A constant that is false does not apply the hit filters, so the refusal stays.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_constant_that_is_false_does_not_lift_the_refusal(): void {
		define( 'RFRAC_LEGACY_HIT_FILTERS', false );
		add_filter( 'wp_rest_cache/filter_cache_output', '__return_empty_array' );

		$this->assertFalse( ( new Store_Bridge() )->refuse_unapplied_hit_filters( true ) );
	}

	// ---------- Only a cacheable lookup is bridged ----------

	public function test_without_a_lookup_no_callback_runs_and_every_value_is_unchanged(): void {
		Request_State::reset();
		$runs = array();
		$this->count_runs_of_the_store_hooks( $runs );
		$bridge = new Store_Bridge();

		$this->assertSame( 3600, $bridge->timeout( 3600, '/wp/v2/posts', '/wp/v2/posts' ) );
		$this->assertFalse( $bridge->empty_results( false, '/wp/v2/posts' ) );
		$this->assertTrue( $bridge->empty_results( true, '/wp/v2/posts' ) );
		$this->assertTrue( $bridge->store_allowed( true, '/wp/v2/posts', '/wp/v2/posts', 10 ) );

		$this->assertSame( array(), $runs );
		$this->assertSame( array(), $this->deprecated );
	}

	/**
	 * @dataProvider credentials
	 *
	 * @param array<string,mixed> $server Extra `$_SERVER` entries.
	 * @param array<string,mixed> $cookie Cookies.
	 */
	public function test_a_lookup_that_was_a_bypass_runs_no_callback_and_leaves_every_value_unchanged( array $server, array $cookie ): void {
		$this->lookup_of( '/wp-json/wp/v2/posts', $server, $cookie );
		$decision = Request_State::decision();
		$this->assertNotNull( $decision );
		$this->assertFalse( $decision->cacheable, 'Precondition: the credentials made the lookup a bypass.' );
		$runs = array();
		$this->count_runs_of_the_store_hooks( $runs );
		$bridge = new Store_Bridge();

		$this->assertSame( 3600, $bridge->timeout( 3600, '/wp/v2/posts', '/wp/v2/posts' ) );
		$this->assertTrue( $bridge->empty_results( true, '/wp/v2/posts' ) );
		$this->assertFalse( $bridge->empty_results( false, '/wp/v2/posts' ) );
		$this->assertTrue( $bridge->store_allowed( true, '/wp/v2/posts', '/wp/v2/posts', 10 ) );

		$this->assertSame( array(), $runs );
		$this->assertSame( array(), $this->deprecated );
	}

	/**
	 * A request with credentials reaches none of the seven legacy hooks, on either path, and is not stored.
	 *
	 * The control run shows that the same routes do fire all seven hooks without credentials.
	 *
	 * @dataProvider credentials_on_each_path
	 *
	 * @param string              $path   Serving path.
	 * @param array<string,mixed> $server Extra `$_SERVER` entries.
	 * @param array<string,mixed> $cookie Cookies.
	 */
	public function test_a_request_with_credentials_reaches_none_of_the_seven_legacy_hooks_and_stores_nothing( string $path, array $server, array $cookie ): void {
		foreach ( self::LEGACY_HOOKS as $name ) {
			add_filter( $name, static fn( $value ) => $value, 10, 4 );
		}
		add_filter( 'wp_rest_cache/determine_object_type', static fn() => 'post' );
		$this->setExpectedDeprecated( 'wp_rest_cache/timeout' );
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_empty_result_set' );
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_caching' );
		$this->setExpectedDeprecated( 'wp_rest_cache/determine_object_type' );
		$this->setExpectedDeprecated( 'wp_rest_cache/is_single_item' );
		$this->setExpectedDeprecated( 'wp_rest_cache/process_cache_relations' );
		$this->setExpectedDeprecated( 'wp_rest_cache/insert_cache_relation' );
		$fired = array();
		add_filter(
			'all',
			static function () use ( &$fired ): void {
				$fired[] = current_filter();
			}
		);
		$h = $this->harness( $path )->with_legacy();

		foreach ( array( '/tst/v1/item', '/tst/v1/empty' ) as $route ) {
			$h->request( '/wp-json' . $route );
			$this->assertNotNull( $h->miss( $route, array( $this, 'register_bridge_routes' ) ) );
		}
		$control = array_values( array_intersect( self::LEGACY_HOOKS, $fired ) );
		$this->assertEqualsCanonicalizing( self::LEGACY_HOOKS, $control, 'Control: without credentials all seven hooks fire.' );

		$fired = array();
		$this->empty_tables();
		foreach ( array( '/tst/v1/item', '/tst/v1/empty' ) as $route ) {
			$h->request( '/wp-json' . $route, $server, $cookie );
			$this->assertNotNull( $h->miss( $route, array( $this, 'register_bridge_routes' ) ) );

			$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		}

		$this->assertSame( array(), array_values( array_intersect( self::LEGACY_HOOKS, $fired ) ), 'No legacy hook was fired.' );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'Nothing was stored.' );
		$this->assertNoFailures();
	}
}
