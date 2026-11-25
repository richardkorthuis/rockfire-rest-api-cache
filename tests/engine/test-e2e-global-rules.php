<?php
/**
 * End-to-end tests of the global ignored parameters and vary headers: the compiler layers them under every
 * route's own lists, and the serving code keys, serves and stores accordingly without knowing about them.
 *
 * The site is compiled for real from a REST server (`Config_Compiler::compile()`), not from a hand-made snapshot.
 * Every test with a `$path` argument runs for the early server and for the fallback server.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Compiled_Route;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * @covers \Rockfire\Rest_Api_Cache\Config\Config_Compiler
 * @covers \Rockfire\Rest_Api_Cache\Config\Compiled_Route
 * @covers \Rockfire\Rest_Api_Cache\Request\Cache_Policy
 * @covers \Rockfire\Rest_Api_Cache\Serving\Early_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Fallback_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 */
class Test_E2e_Global_Rules extends Rfrac_E2e_Test_Case {

	private const THINGS   = '/rfrac-test/v1/things';
	private const WILDCARD = '/rfrac-test/v1/*';

	/**
	 * The arguments `/rfrac-test/v1/things` registers.
	 *
	 * @var array<string,array<mixed>>
	 */
	private array $things_args = array( 'page' => array() );

	public function set_up() {
		parent::set_up();
		// A settings save must not compile on its own: every test compiles the site itself, once.
		remove_all_actions( 'add_option_' . Names::OPTION_SETTINGS );
		remove_all_actions( 'update_option_' . Names::OPTION_SETTINGS );
	}

	/**
	 * Register the test routes: `/rfrac-test/v1/things` and `/rfrac-test/v1/refs` (which registers `ref`).
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$route = static function ( string $path, array $args ): void {
			register_rest_route(
				'rfrac-test/v1',
				$path,
				array(
					'methods'             => 'GET',
					'callback'            => static fn() => array( 'a' => 1 ),
					'permission_callback' => '__return_true',
					'args'                => $args,
				)
			);
		};
		$route( '/things', $this->things_args );
		$route( '/refs', array( 'ref' => array() ) );
	}

	/**
	 * Save settings and rules, then compile the site from a REST server with the test routes.
	 *
	 * `/rfrac-test/v1/things` is always enabled; `$rules` adds or replaces rules.
	 *
	 * @param array<string,mixed> $settings Settings to save.
	 * @param array<string,mixed> $rules    Route rules to save, by route key.
	 *
	 * @return Compiled_Config
	 */
	private function compile_site( array $settings = array(), array $rules = array() ): Compiled_Config {
		$rules  = array_merge( array( self::THINGS => array( 'status' => 'enabled' ) ), $rules );
		$result = ( new Config() )->save_settings_and_rules( $settings, $rules );
		$this->assertTrue( $result->is_valid(), 'The settings and rules are valid.' );

		return $this->compile_saved_site();
	}

	/**
	 * Compile the site, with the test routes, from the settings option as it is stored.
	 *
	 * @return Compiled_Config
	 */
	private function compile_saved_site(): Compiled_Config {
		delete_option( Failure_Recorder::OPTION );
		$server = $this->new_rest_server( array( $this, 'register_routes' ) );
		return ( new Config_Compiler() )->compile( $server );
	}

	/**
	 * An enabled compiled route.
	 *
	 * @param Compiled_Config $config Compiled configuration.
	 * @param string          $key    Route key.
	 *
	 * @return Compiled_Route
	 */
	private function compiled_route( Compiled_Config $config, string $key ): Compiled_Route {
		foreach ( $config->routes() as $route ) {
			if ( $key === $route->key && $route->enabled ) {
				return $route;
			}
		}
		$this->fail( 'The route ' . $key . ' is not compiled as enabled.' );
	}

	/**
	 * The enabled compiled routes.
	 *
	 * @param Compiled_Config $config Compiled configuration.
	 *
	 * @return list<Compiled_Route>
	 */
	private function enabled_routes( Compiled_Config $config ): array {
		return array_values( array_filter( $config->routes(), static fn( Compiled_Route $route ): bool => $route->enabled ) );
	}

	/**
	 * The recorded failure, after asserting it is a `route_rules` one.
	 *
	 * @return array<string,mixed>
	 */
	private function recorded_route_rules_failure(): array {
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last, 'A problem is recorded.' );
		$this->assertSame( 'route_rules', $last['context'] );
		return $last;
	}

	/**
	 * The stored snapshot's routes, by key.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function stored_routes(): array {
		$stored = get_option( Names::OPTION_CONFIG );
		$out    = array();
		foreach ( $stored['routes'] as $route ) {
			$out[ $route['key'] ] = $route;
		}
		return $out;
	}

	/**
	 * A request for the things route.
	 *
	 * @param Rfrac_Serving_Harness $h      Harness.
	 * @param string                $query  Query string with the leading `?`, or ''.
	 * @param array<string,mixed>   $server Extra `$_SERVER` entries.
	 *
	 * @return void
	 */
	private function request_things( Rfrac_Serving_Harness $h, string $query = '', array $server = array() ): void {
		$h->request( '/wp-json' . self::THINGS . $query, $server );
	}

	/**
	 * A complete request for the things route: the lookup, then the live request on a miss.
	 *
	 * @param Rfrac_Serving_Harness $h Harness.
	 *
	 * @return string|null The live body, null when the lookup served a hit.
	 */
	private function miss_things( Rfrac_Serving_Harness $h ): ?string {
		return $h->miss( self::THINGS, array( $this, 'register_routes' ) );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_global_ignored_parameter_is_dropped_from_the_key_on_a_route_that_does_not_register_it( string $path ): void {
		$h = $this->harness( $path );

		$this->compile_site();
		$this->request_things( $h, '?utm_source=a' );
		$this->assertFalse( $h->lookup(), 'Without the global an unregistered parameter bypasses.' );
		$this->assertSame( Bypass_Reason::UNKNOWN_PARAM, Request_State::decision()->reason );

		$config = $this->compile_site( array( 'global_ignore_params' => array( 'utm_source' ) ) );
		$this->assertSame( array( 'utm_source' ), $this->compiled_route( $config, self::THINGS )->ignore_params );

		$this->request_things( $h, '?utm_source=a' );
		$body = $this->miss_things( $h );
		$this->assertNotNull( $body, 'The first request is a miss.' );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$this->request_things( $h, '?utm_source=b' );
		$this->assertTrue( $h->lookup(), 'Another value of the ignored parameter hits the same entry.' );
		$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( $body, $h->output );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_global_ignored_parameter_the_route_registers_still_keys_the_entry( string $path ): void {
		$this->things_args = array(
			'page'       => array(),
			'utm_source' => array(),
		);
		$config            = $this->compile_site( array( 'global_ignore_params' => array( 'utm_source' ) ) );
		$this->assertNotContains( 'utm_source', $this->compiled_route( $config, self::THINGS )->ignore_params );
		$this->assertContains( 'utm_source', $this->compiled_route( $config, self::THINGS )->allowed_params );

		$h = $this->harness( $path );
		$this->request_things( $h, '?utm_source=a' );
		$this->assertNotNull( $this->miss_things( $h ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$this->request_things( $h, '?utm_source=b' );
		$this->assertNotNull( $this->miss_things( $h ), 'Another value is another entry.' );
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count() );

		$this->request_things( $h, '?utm_source=a' );
		$this->assertTrue( $h->lookup(), 'A repeat is a hit.' );
		$this->assertNoFailures();
	}

	public function test_a_wildcard_ignores_the_global_parameters_none_of_its_routes_registers(): void {
		$config = $this->compile_site(
			array( 'global_ignore_params' => array( 'utm_source', 'ref', '_fields' ) ),
			array( self::WILDCARD => array( 'status' => 'enabled', 'match' => 'wildcard' ) )
		);

		$this->assertSame( array( 'utm_source' ), $this->compiled_route( $config, self::WILDCARD )->ignore_params, '`ref` is registered by a route it covers and `_fields` is universal.' );
		$this->assertSame( array( 'utm_source', 'ref' ), $this->compiled_route( $config, self::THINGS )->ignore_params, 'A route that does not register `ref` ignores it.' );
		$this->assertNoFailures();
	}

	public function test_a_wildcard_does_not_count_the_arguments_of_a_route_it_does_not_cover(): void {
		$config = $this->compile_site(
			array( 'global_ignore_params' => array( 'ref', 'color' ) ),
			array( '/rfrac-test/v1/refs/*' => array( 'status' => 'enabled', 'match' => 'wildcard' ) )
		);

		$this->assertSame( array( 'ref', 'color' ), $this->compiled_route( $config, '/rfrac-test/v1/refs/*' )->ignore_params, 'No registered route matches `/rfrac-test/v1/refs/*`.' );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_bypass_parameter_is_never_ignored_and_still_bypasses( string $path ): void {
		$config = $this->compile_site(
			array( 'global_ignore_params' => array( 'utm_source', 'fbclid' ) ),
			array( self::THINGS => array( 'status' => 'enabled', 'bypass_params' => array( 'utm_source' ) ) )
		);
		$this->assertSame( array( 'fbclid' ), $this->compiled_route( $config, self::THINGS )->ignore_params );
		$this->assertSame( array( 'utm_source' ), $this->compiled_route( $config, self::THINGS )->bypass_params );

		$h = $this->harness( $path );
		$this->request_things( $h, '?utm_source=a' );
		$this->assertFalse( $h->lookup() );
		$this->assertSame( Bypass_Reason::BYPASS_PARAM, Request_State::decision()->reason );

		$h->serve( self::THINGS, array( $this, 'register_routes' ) );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count() );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_global_vary_headers_merge_after_the_routes_own_and_split_the_entries( string $path ): void {
		$config = $this->compile_site(
			array( 'global_vary_headers' => array( 'accept-language' ) ),
			array( self::THINGS => array( 'status' => 'enabled', 'vary_headers' => array( 'x-foo' ) ) )
		);
		$this->assertSame( array( 'x-foo', 'accept-language' ), $this->compiled_route( $config, self::THINGS )->vary_headers );
		$this->assertFalse( $this->compiled_route( $config, self::THINGS )->vary_invalid );
		$this->assertSame( array( 'accept-language' ), $this->compiled_route( $config, '/wp/v2/posts' )->vary_headers, 'A route without its own headers varies by the global ones.' );

		$h = $this->harness( $path );
		$this->request_things( $h, '', array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) );
		$this->assertNotNull( $this->miss_things( $h ) );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertStringContainsString( 'accept-language', (string) $h->live_header( 'Vary' ), 'The miss advertises the global header.' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$this->request_things( $h, '', array( 'HTTP_ACCEPT_LANGUAGE' => 'de' ) );
		$this->assertNotNull( $this->miss_things( $h ), 'Another header value is another entry.' );
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count() );

		$this->request_things( $h, '', array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) );
		$this->assertTrue( $h->lookup(), 'A repeat is a hit.' );
		$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 'Origin, x-foo, accept-language', $h->hit_header( 'Vary' ) );
		$this->assertNoFailures();
	}

	public function test_a_global_vary_header_the_route_also_declares_is_merged_once(): void {
		$config = $this->compile_site(
			array( 'global_vary_headers' => array( 'x-foo', 'Accept_Language' ) ),
			array( self::THINGS => array( 'status' => 'enabled', 'vary_headers' => array( 'x-foo' ) ) )
		);

		$this->assertSame( array( 'x-foo', 'accept-language' ), $this->compiled_route( $config, self::THINGS )->vary_headers );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_route_over_the_vary_header_limit_is_not_cached_and_the_problem_names_it( string $path ): void {
		$own = array( 'x-a', 'x-b', 'x-c', 'x-d' );

		// No save accepts this state, so it is written the way an old or hand-edited option holds it: the rule first, the global list after.
		$this->assertTrue( ( new Config() )->save_route_rules( array( self::THINGS => array( 'status' => 'enabled', 'vary_headers' => $own ) ) )->is_valid() );
		update_option( Names::OPTION_SETTINGS, array_merge( get_option( Names::OPTION_SETTINGS ), array( 'global_vary_headers' => array( 'accept-language', 'x-e' ) ) ), false );
		$config = $this->compile_saved_site();

		$things = $this->compiled_route( $config, self::THINGS );
		$this->assertTrue( $things->vary_invalid );
		$this->assertSame( Compiled_Route::VARY_MERGED_HEADERS, $things->vary_cause, 'The cause is the merged limit, not the cookie declaration.' );
		$this->assertTrue( $things->visitor_varies );
		$this->assertSame( $own, $things->vary_headers, 'The route keeps its own headers.' );
		$posts = $this->compiled_route( $config, '/wp/v2/posts' );
		$this->assertFalse( $posts->vary_invalid, 'A route within the limit is not affected.' );
		$this->assertNull( $posts->vary_cause );
		$this->assertFalse( $posts->visitor_varies );
		$stored = $this->stored_routes();
		$this->assertSame( Compiled_Route::VARY_MERGED_HEADERS, $stored[ self::THINGS ]['vary_cause'] );
		$this->assertArrayNotHasKey( 'vary_cause', $stored['/wp/v2/posts'] );
		$loaded = Compiled_Route::from_array( $stored[ self::THINGS ] );
		$this->assertInstanceOf( Compiled_Route::class, $loaded );
		$this->assertSame( Compiled_Route::VARY_MERGED_HEADERS, $loaded->vary_cause, 'The cause survives the snapshot.' );

		$last = $this->recorded_route_rules_failure();
		$this->assertStringStartsWith( 'Invalid vary declaration, the route is not cached: ', $last['message'] );
		$this->assertStringContainsString( self::THINGS, $last['message'] );
		$this->assertStringContainsString( 'would vary on 6 request headers with the global vary headers; at most 5 are allowed', $last['message'] );
		$this->assertStringNotContainsString( 'more)', $last['message'], 'Exactly one problem.' );

		$h = $this->harness( $path );
		$this->request_things( $h );
		$this->assertFalse( $h->lookup() );
		$this->assertSame( Bypass_Reason::VISITOR_VARIES, Request_State::decision()->reason );
		$h->serve( self::THINGS, array( $this, 'register_routes' ) );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count() );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function unusable_vary_lists(): array {
		return array(
			'a forbidden header'         => array( array( 'cookie' ) ),
			'a credential header'        => array( array( 'accept-language', 'Authorization' ) ),
			'a non-array'                => array( 'accept-language' ),
			'null'                       => array( null ),
			'six headers'                => array( array( 'x-1', 'x-2', 'x-3', 'x-4', 'x-5', 'x-6' ) ),
			'an invalid header name'     => array( array( 'bad name' ) ),
			'an entry that is no string' => array( array( 'accept-language', 7 ) ),
		);
	}

	/**
	 * @dataProvider unusable_vary_lists
	 *
	 * @param mixed $returned What the vary filter returns.
	 */
	public function test_an_unusable_global_vary_list_compiles_every_route_as_not_cached_with_one_problem( $returned ): void {
		add_filter( Hooks::GLOBAL_VARY_HEADERS, static fn() => $returned );

		$config = $this->compile_site( array( 'global_vary_headers' => array( 'accept-language' ) ) );

		$enabled = $this->enabled_routes( $config );
		$this->assertGreaterThan( 1, count( $enabled ) );
		foreach ( $enabled as $route ) {
			$this->assertTrue( $route->vary_invalid, $route->key );
			$this->assertSame( Compiled_Route::VARY_GLOBAL_HEADERS, $route->vary_cause, $route->key . ' is blamed on the global vary headers, not on a cookie declaration.' );
			$this->assertTrue( $route->visitor_varies, $route->key );
			$this->assertNotContains( 'accept-language', $route->vary_headers, $route->key . ' keeps its own headers.' );
		}
		$stored = $this->stored_routes();
		$this->assertTrue( $stored[ self::THINGS ]['vary_invalid'] );
		$this->assertSame( Compiled_Route::VARY_GLOBAL_HEADERS, $stored[ self::THINGS ]['vary_cause'] );
		$this->assertTrue( $stored[ self::THINGS ]['visitor_varies'] );

		$last = $this->recorded_route_rules_failure();
		$this->assertStringStartsWith( 'No route is cached, the global vary headers are invalid', $last['message'] );
		$this->assertStringNotContainsString( 'more)', $last['message'], 'Exactly one problem.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_invalid_global_vary_constant_compiles_every_route_as_not_cached_with_one_problem(): void {
		define( 'RFRAC_GLOBAL_VARY_HEADERS', array( 'authorization' ) );

		$config = $this->compile_site();

		$enabled = $this->enabled_routes( $config );
		$this->assertGreaterThan( 1, count( $enabled ) );
		foreach ( $enabled as $route ) {
			$this->assertTrue( $route->vary_invalid, $route->key );
			$this->assertSame( Compiled_Route::VARY_GLOBAL_HEADERS, $route->vary_cause, $route->key );
			$this->assertTrue( $route->visitor_varies, $route->key );
		}
		$last = $this->recorded_route_rules_failure();
		$this->assertStringStartsWith( 'No route is cached, the global vary headers are invalid: RFRAC_GLOBAL_VARY_HEADERS: ', $last['message'] );
		$this->assertStringNotContainsString( 'more)', $last['message'], 'Exactly one problem.' );
	}

	/**
	 * An invalid `RFRAC_GLOBAL_VARY_HEADERS` is the one constant that is not set aside for the stored setting: it
	 * fails closed, because caching with another list than the site asked for would serve one shared variant to
	 * visitors who should be varied.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_invalid_global_vary_constant_stops_caching_on_every_route_even_with_a_valid_stored_list(): void {
		define( 'RFRAC_GLOBAL_VARY_HEADERS', array( 'cookie' ) );

		$config = $this->compile_site( array( 'global_vary_headers' => array( 'accept-language' ) ) );

		$this->assertSame( array( 'accept-language' ), ( new Config() )->settings()['global_vary_headers'], 'The stored list is valid and not locked.' );
		$enabled = $this->enabled_routes( $config );
		$this->assertGreaterThan( 1, count( $enabled ) );
		foreach ( $enabled as $route ) {
			$this->assertTrue( $route->visitor_varies, $route->key . ' is not cached.' );
			$this->assertTrue( $route->vary_invalid, $route->key );
			$this->assertSame( Compiled_Route::VARY_GLOBAL_HEADERS, $route->vary_cause, $route->key );
			$this->assertNotContains( 'accept-language', $route->vary_headers, $route->key . ' does not use the stored list.' );
		}
		$last = $this->recorded_route_rules_failure();
		$this->assertStringContainsString( 'RFRAC_GLOBAL_VARY_HEADERS', $last['message'] );
	}

	public function test_the_readme_says_an_invalid_global_vary_constant_stops_caching_instead_of_being_ignored(): void {
		$readme = (string) file_get_contents( RFRAC_DIR . 'readme.txt' );
		$this->assertSame( 1, preg_match( '/^\* `RFRAC_GLOBAL_VARY_HEADERS` .*$/m', $readme, $matches ), 'The readme documents the constant.' );

		$line = $matches[0];
		$this->assertStringNotContainsString( 'is ignored', $line, 'An invalid value is not ignored.' );
		$this->assertStringContainsString( 'no route is cached until it is fixed', $line );
		$this->assertStringContainsString( 'reported', $line );
	}

	public function test_an_unusable_global_vary_list_is_reported_first_whatever_else_is_wrong(): void {
		add_filter( Hooks::GLOBAL_VARY_HEADERS, '__return_null' );
		add_filter( Hooks::ROUTE_RULES, static fn( array $rules ): array => array_merge( $rules, array( '/wp/v2/tags' => array( 'status' => 'bogus' ) ) ) );
		add_filter( Hooks::ROUTE_VARY_COOKIES, '__return_null' );
		add_filter( Hooks::GLOBAL_IGNORE_PARAMS, '__return_null' );
		add_filter( Hooks::BYPASS_HEADERS, static fn( array $headers ): array => array_merge( $headers, array( 'bad header' ) ) );

		$this->compile_site();

		$last = $this->recorded_route_rules_failure();
		$this->assertStringStartsWith( 'No route is cached, the global vary headers are invalid', $last['message'], 'The site-wide failure is the headline.' );
		$this->assertStringContainsString( 'more)', $last['message'], 'The other problems follow.' );
		$this->assertStringNotContainsString( 'Invalid route rule', $last['message'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_global_ignore_constant_and_the_filter_both_apply(): void {
		define( 'RFRAC_GLOBAL_IGNORE_PARAMS', array( 'utm_source' ) );
		add_filter( Hooks::GLOBAL_IGNORE_PARAMS, static fn( array $params ): array => array_merge( $params, array( 'fbclid' ) ) );

		$config = $this->compile_site();

		$this->assertSame( array( 'utm_source', 'fbclid' ), $this->compiled_route( $config, self::THINGS )->ignore_params );
		$this->assertSame( array( 'utm_source', 'fbclid' ), $this->compiled_route( $config, '/wp/v2/posts' )->ignore_params );
		$this->assertNull( Failure_Recorder::last() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_invalid_global_ignore_constant_is_reported_and_the_stored_list_is_used(): void {
		define( 'RFRAC_GLOBAL_IGNORE_PARAMS', array( 'utm_source', 'bad name' ) );

		$config = $this->compile_site( array( 'global_ignore_params' => array( 'fbclid' ) ) );

		$this->assertSame( array( 'fbclid' ), $this->compiled_route( $config, self::THINGS )->ignore_params );
		$last = $this->recorded_route_rules_failure();
		$this->assertStringStartsWith( 'Global ignored parameter not applied: RFRAC_GLOBAL_IGNORE_PARAMS: ', $last['message'] );
	}

	public function test_an_ignore_filter_that_returns_no_array_keeps_the_unfiltered_list(): void {
		add_filter( Hooks::GLOBAL_IGNORE_PARAMS, static fn() => 'x' );

		$config = $this->compile_site( array( 'global_ignore_params' => array( 'utm_source' ) ) );

		$this->assertSame( array( 'utm_source' ), $this->compiled_route( $config, self::THINGS )->ignore_params );
		$last = $this->recorded_route_rules_failure();
		$this->assertStringStartsWith( 'Global ignored parameter not applied: ', $last['message'] );
		$this->assertStringContainsString( Hooks::GLOBAL_IGNORE_PARAMS, $last['message'] );
		$this->assertStringContainsString( 'instead of an array', $last['message'] );
	}

	public function test_an_invalid_ignore_entry_is_skipped_and_the_valid_ones_are_kept(): void {
		add_filter( Hooks::GLOBAL_IGNORE_PARAMS, static fn( array $params ): array => array_merge( $params, array( 'bad name', 7 ) ) );

		$config = $this->compile_site( array( 'global_ignore_params' => array( 'utm_source' ) ) );

		$this->assertSame( array( 'utm_source' ), $this->compiled_route( $config, self::THINGS )->ignore_params );
		$last = $this->recorded_route_rules_failure();
		$this->assertStringStartsWith( 'Global ignored parameter not applied: ', $last['message'] );
		$this->assertStringContainsString( '"bad name"', $last['message'] );
	}

	public function test_an_ignore_filter_with_only_invalid_entries_leaves_the_list_empty(): void {
		add_filter( Hooks::GLOBAL_IGNORE_PARAMS, static fn() => array( 'bad name' ) );

		$config = $this->compile_site( array( 'global_ignore_params' => array( 'utm_source' ) ) );

		$this->assertSame( array(), $this->compiled_route( $config, self::THINGS )->ignore_params, 'The remaining valid list is empty.' );
		$last = $this->recorded_route_rules_failure();
		$this->assertStringContainsString( '"bad name"', $last['message'] );
	}

	public function test_at_most_twenty_global_ignored_parameters_are_kept(): void {
		$names = array_map( static fn( int $i ): string => sprintf( 'g_%02d', $i ), range( 1, 22 ) );
		add_filter( Hooks::GLOBAL_IGNORE_PARAMS, static fn() => $names );

		$config = $this->compile_site();

		$this->assertSame( array_slice( $names, 0, 20 ), $this->compiled_route( $config, self::THINGS )->ignore_params );
		$last = $this->recorded_route_rules_failure();
		$this->assertStringContainsString( 'g_21, g_22', $last['message'] );
	}

	public function test_a_route_with_eighteen_own_ignored_parameters_takes_only_two_global_ones(): void {
		$own    = array_map( static fn( int $i ): string => sprintf( 'own_%02d', $i ), range( 1, 18 ) );
		$global = array( 'g_1', 'g_2', 'g_3', 'g_4', 'g_5' );
		$config = $this->compile_site(
			array( 'global_ignore_params' => $global ),
			array( self::THINGS => array( 'status' => 'enabled', 'ignore_params' => $own ) )
		);

		$ignore = $this->compiled_route( $config, self::THINGS )->ignore_params;
		$this->assertCount( 20, $ignore );
		$this->assertSame( array_merge( $own, array( 'g_1', 'g_2' ) ), $ignore, 'The own entries come first.' );
		$this->assertSame( $global, $this->compiled_route( $config, '/wp/v2/posts' )->ignore_params, 'A route with no own entries takes them all.' );

		$last = $this->recorded_route_rules_failure();
		$this->assertStringStartsWith( 'Global ignored parameter not applied: ', $last['message'] );
		$this->assertStringContainsString( self::THINGS, $last['message'] );
		$this->assertStringContainsString( 'g_3, g_4, g_5', $last['message'] );
		$this->assertStringNotContainsString( 'g_1', $last['message'] );
	}

	public function test_both_filters_fire_once_per_compile_however_many_routes_there_are(): void {
		$calls = array();
		foreach ( array( Hooks::GLOBAL_IGNORE_PARAMS, Hooks::GLOBAL_VARY_HEADERS ) as $hook ) {
			add_filter(
				$hook,
				static function ( $list ) use ( &$calls, $hook ) {
					$calls[] = $hook;
					return $list;
				}
			);
		}

		$config = $this->compile_site( array( 'global_ignore_params' => array( 'utm_source' ), 'global_vary_headers' => array( 'accept-language' ) ) );

		$this->assertGreaterThan( 5, count( $this->enabled_routes( $config ) ) );
		$this->assertSame( array( Hooks::GLOBAL_IGNORE_PARAMS, Hooks::GLOBAL_VARY_HEADERS ), $calls );
	}

	public function test_the_filters_receive_the_setting_and_a_change_alters_the_fingerprint(): void {
		$seen = array();
		add_filter(
			Hooks::GLOBAL_IGNORE_PARAMS,
			static function ( $list ) use ( &$seen ) {
				$seen['ignore'] = $list;
				return $list;
			}
		);
		add_filter(
			Hooks::GLOBAL_VARY_HEADERS,
			static function ( $list ) use ( &$seen ) {
				$seen['vary'] = $list;
				return $list;
			}
		);

		$this->compile_site( array( 'global_ignore_params' => array( 'utm_source' ), 'global_vary_headers' => array( 'accept-language' ) ) );
		$this->assertSame( array( 'ignore' => array( 'utm_source' ), 'vary' => array( 'accept-language' ) ), $seen );

		$compiler = new Config_Compiler();
		$server   = $this->new_rest_server( array( $this, 'register_routes' ) );
		$before   = $compiler->fingerprint( $server );
		add_filter( Hooks::GLOBAL_IGNORE_PARAMS, static fn( array $list ): array => array_merge( $list, array( 'fbclid' ) ), 20 );
		$this->assertNotSame( $before, $compiler->fingerprint( $server ) );
	}

	public function test_a_vary_problem_is_reported_before_a_global_ignored_parameter_problem(): void {
		add_filter( Hooks::ROUTE_VARY_COOKIES, '__return_null' );
		add_filter( Hooks::GLOBAL_IGNORE_PARAMS, '__return_null' );

		$config = $this->compile_site();

		foreach ( $this->enabled_routes( $config ) as $route ) {
			$this->assertSame( Compiled_Route::VARY_COOKIES, $route->vary_cause, $route->key );
		}
		$this->assertArrayNotHasKey( 'vary_cause', $this->stored_routes()[ self::THINGS ], 'A bare `vary_invalid` is the cookie declaration.' );
		$last = $this->recorded_route_rules_failure();
		$this->assertStringStartsWith( 'Invalid vary declaration, the route is not cached: ', $last['message'] );
		$this->assertStringContainsString( 'more)', $last['message'], 'The ignored parameter problem follows.' );
	}

	public function test_a_global_ignored_parameter_problem_is_reported_before_a_bypass_problem(): void {
		add_filter( Hooks::GLOBAL_IGNORE_PARAMS, '__return_null' );
		add_filter( Hooks::BYPASS_HEADERS, static fn( array $headers ): array => array_merge( $headers, array( 'bad header' ) ) );

		$this->compile_site();

		$last = $this->recorded_route_rules_failure();
		$this->assertStringStartsWith( 'Global ignored parameter not applied: ', $last['message'] );
		$this->assertStringContainsString( 'more)', $last['message'], 'The bypass problem follows.' );
	}

	public function test_without_global_lists_the_snapshot_is_what_it_was_before_and_the_format_is_unchanged(): void {
		$compiler = new Config_Compiler();
		$config   = $this->compile_site(
			array( 'global_ignore_params' => array(), 'global_vary_headers' => array() ),
			array( self::THINGS => array( 'status' => 'enabled', 'ignore_params' => array( 'own' ), 'vary_headers' => array( 'x-foo' ) ) )
		);

		$this->assertSame( 2, Compiled_Config::FORMAT );
		$stored = get_option( Names::OPTION_CONFIG );
		$this->assertSame( Compiled_Config::FORMAT, $stored['format'] );
		foreach ( array_keys( $stored ) as $field ) {
			$this->assertStringNotContainsString( 'global', (string) $field, 'The snapshot has no field for the global lists.' );
		}

		$things = $this->stored_routes()[ self::THINGS ];
		$this->assertSame(
			array( 'key', 'regex', 'ttl', 'param_mode', 'allowed_params', 'ignore_params', 'bypass_params', 'vary_headers' ),
			array_keys( $things ),
			'No route field was added.'
		);
		$this->assertSame( array( 'own' ), $things['ignore_params'] );
		$this->assertSame( array( 'x-foo' ), $things['vary_headers'] );
		foreach ( $this->enabled_routes( $config ) as $route ) {
			$this->assertFalse( $route->vary_invalid, $route->key );
			$this->assertFalse( $route->visitor_varies, $route->key );
		}
		$this->assertSame( array(), $this->compiled_route( $config, '/wp/v2/posts' )->ignore_params );
		$this->assertSame( array(), $this->compiled_route( $config, '/wp/v2/posts' )->vary_headers );
		$this->assertNoFailures();

		$server = $this->new_rest_server( array( $this, 'register_routes' ) );
		$plain  = $compiler->fingerprint( $server );
		add_filter( Hooks::GLOBAL_IGNORE_PARAMS, '__return_empty_array' );
		add_filter( Hooks::GLOBAL_VARY_HEADERS, '__return_empty_array' );
		$this->assertSame( $plain, $compiler->fingerprint( $server ), 'Empty lists leave the fingerprint alone.' );
	}
}
