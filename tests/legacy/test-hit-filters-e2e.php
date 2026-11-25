<?php
/**
 * End-to-end tests of the legacy hit filters: a real request is served from the cache on the early and on the
 * fallback path, with and without `RFRAC_LEGACY_HIT_FILTERS`.
 *
 * Every test runs for the early server and for the fallback server, each in a separate process because the
 * constant cannot be undefined again once it is defined.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Legacy\Hit_Filter_Pass;
use Rockfire\Rest_Api_Cache\Legacy\Hook_Registry;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Capture;
use Rockfire\Rest_Api_Cache\Serving\Lookup;
use Rockfire\Rest_Api_Cache\Serving\Request_State;
use Rockfire\Rest_Api_Cache\Storage\Cached_Response;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Factory;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Hit_Filter_Pass
 * @covers \Rockfire\Rest_Api_Cache\Serving\Lookup
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 */
class Test_Hit_Filters_E2e extends Rfrac_E2e_Test_Case {

	private const URI = '/wp-json/wp/v2/posts';

	/**
	 * What a hit may fire on its own: the option, URL and query filters of core.
	 */
	private const CORE_HOOKS = '/^(pre_option$|pre_wp_load_alloptions$|alloptions$|set_url_scheme$|pre_option_|option_|default_option_|pre_site_option_|site_option_|default_site_option_|home_url$|query$)/';

	public function set_up() {
		parent::set_up();
		// A response with posts is stored; an empty result set never is.
		self::factory()->post->create_many( 3 );
		$this->configure( array( 'store_mode' => 'database' ) );
	}

	/**
	 * Hook a callback on each of the six legacy hit filters that records the name and passes the value on.
	 *
	 * @param list<string> $calls Names of the filters that ran, filled as they do.
	 *
	 * @return void
	 */
	private function spy_on_the_legacy_filters( array &$calls ): void {
		foreach ( Hook_Registry::hit_filters() as $name ) {
			add_filter(
				$name,
				static function ( $value ) use ( $name, &$calls ) {
					$calls[] = $name;
					return $value;
				},
				10,
				4
			);
		}
	}

	/**
	 * Every hook that fires while a callback runs.
	 *
	 * @param Closure $run Callback.
	 *
	 * @return list<string>
	 */
	private function hooks_during( Closure $run ): array {
		$hooks = array();
		$spy   = static function () use ( &$hooks ): void {
			$hooks[] = current_filter();
		};
		add_filter( 'all', $spy );
		try {
			$run();
		} finally {
			remove_filter( 'all', $spy );
		}
		return $hooks;
	}

	/**
	 * The cache key of the engine for the current request.
	 *
	 * @return string
	 */
	private function engine_key(): string {
		$config = Compiled_Config::load();
		$this->assertNotNull( $config );
		$context  = Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() );
		$decision = $this->policy()->evaluate( $context, $config );
		$this->assertCacheable( $decision );
		return Cache_Key::key( $context, $decision );
	}

	/**
	 * The key a replacement string of the `cache_key` filter is stored under for the current request.
	 *
	 * @param string $replacement What the callback returned.
	 *
	 * @return string
	 */
	private function replaced_key( string $replacement ): string {
		$config = Compiled_Config::load();
		$this->assertNotNull( $config );
		$context  = Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() );
		$decision = $this->policy()->evaluate( $context, $config );
		$this->assertCacheable( $decision );
		return Cache_Key::replaced( $replacement, $context, $decision );
	}

	/**
	 * A stored entry of the database store, null when there is none.
	 *
	 * @param string $key Cache key.
	 *
	 * @return Cached_Response|null
	 */
	private function stored( string $key ): ?Cached_Response {
		return Store_Factory::for_mode( 'database', new Repository(), 0 )->get( $key );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_without_the_constant_a_hit_fires_only_core_hooks_and_no_legacy_callback_runs( string $path ): void {
		$this->assertFalse( defined( Hook_Registry::HIT_FILTERS_CONSTANT ) );
		$this->assertTrue( class_exists( Hook_Registry::class ), 'The legacy module is there.' );
		$calls = array();
		$this->spy_on_the_legacy_filters( $calls );
		$h = $this->harness( $path );
		$h->request( self::URI . '?skip_cache=1' );
		$this->prime( '{"stored":1}' );
		$this->assertTrue( $h->lookup(), 'Warms the options the hit path reads.' );

		$hooks = $this->hooks_during(
			static function () use ( $h ): void {
				$h->request( self::URI . '?skip_cache=1' );
				$h->lookup();
			}
		);

		$this->assertTrue( $h->hit );
		$this->assertSame( '{"stored":1}', $h->output );
		$this->assertNotEmpty( $hooks );
		foreach ( $hooks as $hook ) {
			$this->assertMatchesRegularExpression( self::CORE_HOOKS, $hook, 'Unexpected hook on the hit path.' );
		}
		$this->assertSame( array(), $calls, 'No legacy callback ran.' );
		$this->assertNoFailures();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_with_the_constant_a_hit_fires_the_six_legacy_filters_and_nothing_else_but_core_hooks( string $path ): void {
		define( 'RFRAC_LEGACY_HIT_FILTERS', true );
		$calls = array();
		$this->spy_on_the_legacy_filters( $calls );
		$h = $this->harness( $path );
		$h->request( self::URI . '?skip_cache=1' );
		$this->prime( '{"stored":1}' );
		$this->assertTrue( $h->lookup(), 'Warms the options the hit path reads.' );
		$calls = array();

		$hooks = $this->hooks_during(
			static function () use ( $h ): void {
				$h->request( self::URI . '?skip_cache=1' );
				$h->lookup();
			}
		);

		$this->assertTrue( $h->hit );
		$this->assertSame( '{"stored":1}', $h->output );
		$this->assertContains( 'X-Rfrac-Cache: HIT', $h->headers );
		$allowed = Hook_Registry::hit_filters();
		foreach ( $hooks as $hook ) {
			$this->assertTrue( in_array( $hook, $allowed, true ) || 1 === preg_match( self::CORE_HOOKS, $hook ), 'Unexpected hook on the hit path: ' . $hook );
		}
		$this->assertNotContains( 'deprecated_hook_run', $hooks, 'A hit logs no deprecation.' );
		$this->assertEqualsCanonicalizing( $allowed, array_values( array_unique( array_intersect( $hooks, $allowed ) ) ), 'All six filters fired.' );
		$this->assertEqualsCanonicalizing( $allowed, array_values( array_unique( $calls ) ) );
		$this->assertNoFailures();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_the_output_headers_and_cors_filters_shape_the_hit( string $path ): void {
		define( 'RFRAC_LEGACY_HIT_FILTERS', true );
		$origin = home_url();
		$this->configure( array( 'store_mode' => 'database', 'cors_origins' => array( $origin ) ) );
		$h = $this->harness( $path );
		$h->request( self::URI, array( 'HTTP_ORIGIN' => $origin ) );
		$this->prime( '{"stored":1}' );

		$this->assertTrue( $h->lookup() );
		$this->assertSame( '{"stored":1}', $h->output, 'Control: the hit is served as it is.' );
		$this->assertSame( $origin, $h->hit_header( 'Access-Control-Allow-Origin' ), 'Control: the origin is allowed.' );
		$this->assertNull( $h->hit_header( 'X-WP-Total' ) );

		add_filter(
			'wp_rest_cache/filter_cache_output',
			static function ( $data ) {
				$data->added = 'yes';
				return $data;
			}
		);
		add_filter(
			'wp_rest_cache/pre_output_headers',
			static function ( $headers ) {
				$headers['X-WP-Total']   = '9';
				$headers['X-Not-Listed'] = 'nope';
				return $headers;
			}
		);
		add_filter( 'wp_rest_cache/disable_cors_headers', '__return_true' );
		$h->request( self::URI, array( 'HTTP_ORIGIN' => $origin ) );

		$this->assertTrue( $h->lookup() );

		$this->assertSame( '{"stored":1,"added":"yes"}', $h->output, 'The body has the field.' );
		$this->assertSame( '9', $h->hit_header( 'X-WP-Total' ), 'The allow-listed header is emitted.' );
		$this->assertNull( $h->hit_header( 'X-Not-Listed' ), 'The header that is not on the allow-list is not.' );
		$this->assertNull( $h->hit_header( 'Access-Control-Allow-Origin' ) );
		$this->assertNull( $h->hit_header( 'Access-Control-Allow-Methods' ) );
		$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
		$this->assertNoFailures();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_skip_caching_and_skip_cache_with_allow_skip_cache_are_no_hit_and_store_nothing( string $path ): void {
		define( 'RFRAC_LEGACY_HIT_FILTERS', true );
		$h = $this->harness( $path );
		$h->request( self::URI );
		$this->prime( '{"stored":1}' );
		$this->assertTrue( $h->lookup(), 'Control: a hit.' );

		add_filter( 'wp_rest_cache/skip_caching', '__return_true' );
		$h->request( self::URI );

		$this->assertFalse( $h->lookup() );

		$this->assertSame( '', $h->output );
		$this->assertSame( Bypass_Reason::FILTERED, Request_State::decision()->reason );
		$this->empty_tables();
		$h->request( self::URI );
		$this->assertNotNull( $h->miss( '/wp/v2/posts' ), 'The live response is returned.' );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'The capture honours the filter.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		remove_filter( 'wp_rest_cache/skip_caching', '__return_true' );

		// The configuration does not honour skip_cache, so it is not part of the key and the entry is a hit.
		$h->request( self::URI . '?skip_cache=1' );
		$this->prime( '{"stored":2}' );
		$this->assertTrue( $h->lookup(), 'Control: skip_cache is ignored.' );
		$this->assertSame( '{"stored":2}', $h->output );

		add_filter( 'wp_rest_cache/allow_skip_cache', '__return_true' );
		$h->request( self::URI . '?skip_cache=1' );

		$this->assertFalse( $h->lookup() );

		$this->assertSame( '', $h->output );
		$this->assertSame( Bypass_Reason::SKIP_CACHE, Request_State::decision()->reason );
		$this->empty_tables();
		$h->request( self::URI . '?skip_cache=1' );
		$this->assertNotNull( $h->miss( '/wp/v2/posts' ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'The capture honours skip_cache.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertNoFailures();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_cache_key_callback_keys_the_capture_and_the_lookup_alike( string $path ): void {
		define( 'RFRAC_LEGACY_HIT_FILTERS', true );
		add_filter(
			'wp_rest_cache/cache_key',
			static fn( $key ) => $key . ( isset( $_SERVER['HTTP_X_LANG'] ) ? (string) $_SERVER['HTTP_X_LANG'] : '' ) // phpcs:ignore WordPress.Security
		);
		$h = $this->harness( $path );

		$h->request( self::URI, array( 'HTTP_X_LANG' => 'en' ) );
		$engine_key = $this->engine_key();
		$body       = $h->miss( '/wp/v2/posts' );
		$this->assertNotNull( $body, 'The first request is a miss.' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$stored = $this->stored( $this->replaced_key( $engine_key . 'en' ) );
		$this->assertInstanceOf( Cached_Response::class, $stored, 'The capture stores under the key the callback made.' );
		$this->assertSame( $body, $stored->payload );

		$h->request( self::URI, array( 'HTTP_X_LANG' => 'en' ) );
		$this->assertTrue( $h->lookup(), 'The same language is a hit.' );
		$this->assertSame( $body, $h->output );

		$h->request( self::URI, array( 'HTTP_X_LANG' => 'fr' ) );
		$this->assertFalse( $h->lookup(), 'Another language is a miss.' );
		$this->assertSame( '', $h->output );
		$this->assertNotNull( $h->serve( '/wp/v2/posts' ) );
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count() );
		$this->assertInstanceOf( Cached_Response::class, $this->stored( $this->replaced_key( $engine_key . 'fr' ) ) );

		$h->request( self::URI, array( 'HTTP_X_LANG' => 'fr' ) );
		$this->assertTrue( $h->lookup() );
		$this->assertNoFailures();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_request_with_credentials_reaches_none_of_the_six_filters_and_is_neither_served_nor_stored( string $path ): void {
		define( 'RFRAC_LEGACY_HIT_FILTERS', true );
		$calls = array();
		$this->spy_on_the_legacy_filters( $calls );
		$h = $this->harness( $path );
		$h->request( self::URI );
		$this->prime( '{"stored":1}' );

		$h->request( self::URI . '?skip_cache=1', array( 'HTTP_AUTHORIZATION' => 'Bearer abc' ) );

		$this->assertFalse( $h->lookup() );

		$this->assertSame( '', $h->output );
		$this->assertSame( Bypass_Reason::CREDENTIALS, Request_State::decision()->reason );
		$this->assertSame( array(), $calls, 'The lookup applied no filter.' );

		$this->assertNotNull( $h->serve( '/wp/v2/posts' ) );
		$this->assertSame( array(), $calls, 'The capture applied no filter.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'Nothing was stored.' );
		$this->assertNoFailures();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_throwing_output_filter_serves_nothing_from_the_cache_and_returns_the_live_response( string $path ): void {
		define( 'RFRAC_LEGACY_HIT_FILTERS', true );
		add_filter(
			'wp_rest_cache/filter_cache_output',
			static function () {
				throw new RuntimeException( 'output failed' );
			}
		);
		$h = $this->harness( $path );
		$h->request( self::URI );
		$this->prime( '{"stored":1}' );

		$body = $h->miss( '/wp/v2/posts' );

		$this->assertNotNull( $body, 'The lookup did not serve the entry.' );
		$this->assertSame( '', $h->output );
		$this->assertSame( Bypass_Reason::FILTERED, Request_State::decision()->reason );
		$decoded = json_decode( $body, true );
		$this->assertIsArray( $decoded );
		$this->assertCount( 3, $decoded, 'The live response lists the posts.' );

		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'hook:wp_rest_cache/filter_cache_output', $last['context'] );
		$this->assertStringContainsString( 'output failed', $last['message'] );
	}

	/**
	 * Every serving path with each way a callback of the hit pass can make `response()` refuse a hit.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function refusals(): array {
		$sets = array();
		foreach ( self::paths() as $path_name => $path ) {
			foreach ( array( 'output_throws', 'headers_throw', 'headers_not_an_array' ) as $refusal ) {
				$sets[ $path_name . ': ' . $refusal ] = array( $path[0], $refusal );
			}
		}
		return $sets;
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider refusals
	 *
	 * @param string $path    Serving path.
	 * @param string $refusal How a callback makes the pass refuse the hit.
	 */
	public function test_a_hit_the_pass_refused_is_not_stored_by_the_capture_either( string $path, string $refusal ): void {
		define( 'RFRAC_LEGACY_HIT_FILTERS', true );
		if ( 'output_throws' === $refusal ) {
			add_filter(
				'wp_rest_cache/filter_cache_output',
				static function () {
					throw new RuntimeException( 'output failed' );
				}
			);
		} else {
			add_filter(
				'wp_rest_cache/pre_output_headers',
				static function () use ( $refusal ) {
					if ( 'headers_throw' === $refusal ) {
						throw new RuntimeException( 'headers failed' );
					}
					return 'not headers';
				}
			);
		}
		$h = $this->harness( $path );
		$h->request( self::URI );
		$this->prime( '{"stored":1}' );
		$key = $this->engine_key();

		$body = $h->miss( '/wp/v2/posts' );

		$this->assertNotNull( $body, 'The lookup refused the hit, so the request is live.' );
		$this->assertSame( Bypass_Reason::FILTERED, Request_State::decision()->reason );
		$this->assertCount( 3, (array) json_decode( $body, true ), 'The live response lists the posts.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ), 'The live response is not marked as stored.' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$stored = $this->stored( $key );
		$this->assertInstanceOf( Cached_Response::class, $stored );
		$this->assertSame( '{"stored":1}', $stored->payload, 'The refused entry was not replaced by the live response.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_container_hands_the_pass_to_the_lookup_and_the_capture(): void {
		define( 'RFRAC_LEGACY_HIT_FILTERS', true );

		$plugin = new Plugin();

		$this->assertInstanceOf( Hit_Filter_Pass::class, $plugin->hit_filters() );
		$this->assertSame( $plugin->hit_filters(), $plugin->hit_filters(), 'Resolved once per container.' );
		$this->assertSame( $plugin->hit_filters(), ( new ReflectionProperty( Lookup::class, 'filters' ) )->getValue( $plugin->lookup() ) );
		$this->assertSame( $plugin->hit_filters(), ( new ReflectionProperty( Capture::class, 'filters' ) )->getValue( $plugin->capture() ) );
	}
}
