<?php
/**
 * Tests for the compiled config snapshot.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Compiled_Route;
use Rockfire\Rest_Api_Cache\Names;

/**
 * @covers \Rockfire\Rest_Api_Cache\Config\Compiled_Config
 * @covers \Rockfire\Rest_Api_Cache\Config\Compiled_Route
 */
class Test_Compiled_Config extends Rfrac_Test_Case {

	/**
	 * A valid compiled route as stored.
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 *
	 * @return array<string,mixed>
	 */
	private function route( array $overrides = array() ): array {
		return array_merge(
			array(
				'key'            => '/wp/v2/posts',
				'regex'          => '#^/wp/v2/posts$#',
				'ttl'            => 600,
				'param_mode'     => 'registered',
				'allowed_params' => array( 'page' ),
				'ignore_params'  => array( '_locale' ),
				'bypass_params'  => array( 'preview' ),
				'vary_headers'   => array( 'accept-language' ),
			),
			$overrides
		);
	}

	public function test_load_returns_null_when_missing(): void {
		$this->assertNull( Compiled_Config::load() );
	}

	public function test_load_returns_null_when_not_an_array(): void {
		update_option( Names::OPTION_CONFIG, 'nope' );
		$this->assertNull( Compiled_Config::load() );
	}

	public function test_load_returns_null_for_other_format(): void {
		$this->set_compiled_config( array( 'format' => 1 ) ); // Format 1 had no bypass_headers.
		$this->assertNull( Compiled_Config::load() );
		update_option( Names::OPTION_CONFIG, array( 'enabled' => true ) );
		$this->assertNull( Compiled_Config::load() );
	}

	public function test_load_performs_exactly_one_get_option_and_no_filters(): void {
		$this->set_compiled_config( array( 'default_ttl' => 123, 'routes' => array( $this->route() ) ) );
		wp_cache_flush();

		$calls = 0;
		$count = static function ( $value ) use ( &$calls ) {
			++$calls;
			return $value;
		};
		add_filter( 'pre_option_' . Names::OPTION_CONFIG, $count );
		$config = Compiled_Config::load();
		remove_filter( 'pre_option_' . Names::OPTION_CONFIG, $count );

		$this->assertSame( 1, $calls );
		$this->assertInstanceOf( Compiled_Config::class, $config );
		$this->assertSame( 123, $config->default_ttl() );
	}

	public function test_getters_expose_every_field(): void {
		$data   = array(
			'enabled'                => false,
			'store_mode'             => 'database',
			'rest_prefix'            => 'api',
			'charset'                => 'ISO-8859-1',
			'default_ttl'            => 10,
			'max_ttl'                => 20,
			'max_entries'            => 30,
			'max_payload_bytes'      => 40,
			'max_params'             => 50,
			'hit_sampling'           => 60,
			'universal_params'       => array( '_fields' ),
			'bypass_cookie_prefixes' => array( 'wordpress_logged_in_' ),
			'bypass_headers'         => array( 'x-api-key' ),
			'cors_origins'           => array( 'https://example.org' ),
			'cors_wildcard'          => true,
			'replay_headers'         => array( 'content-type' ),
			'allow_skip_cache'       => true,
			'schema_version'         => 3,
			'fingerprint'            => 'abc',
			'pending_discovery'      => true,
			'routes'                 => array( $this->route() ),
		);
		$config = Compiled_Config::from_array( array_merge( Compiled_Config::defaults(), $data ) );

		$this->assertFalse( $config->enabled() );
		$this->assertSame( 'database', $config->store_mode() );
		$this->assertSame( 'api', $config->rest_prefix() );
		$this->assertSame( 'ISO-8859-1', $config->charset() );
		$this->assertSame( 10, $config->default_ttl() );
		$this->assertSame( 20, $config->max_ttl() );
		$this->assertSame( 30, $config->max_entries() );
		$this->assertSame( 40, $config->max_payload_bytes() );
		$this->assertSame( 50, $config->max_params() );
		$this->assertSame( 60, $config->hit_sampling() );
		$this->assertSame( array( '_fields' ), $config->universal_params() );
		$this->assertSame( array( 'wordpress_logged_in_' ), $config->bypass_cookie_prefixes() );
		$this->assertSame( array( 'x-api-key' ), $config->bypass_headers() );
		$this->assertSame( array( 'https://example.org' ), $config->cors_origins() );
		$this->assertTrue( $config->cors_wildcard() );
		$this->assertSame( array( 'content-type' ), $config->replay_headers() );
		$this->assertTrue( $config->allow_skip_cache() );
		$this->assertSame( 3, $config->schema_version() );
		$this->assertSame( 'abc', $config->fingerprint() );
		$this->assertTrue( $config->pending_discovery() );

		$routes = $config->routes();
		$this->assertCount( 1, $routes );
		$this->assertInstanceOf( Compiled_Route::class, $routes[0] );
		$this->assertSame( '/wp/v2/posts', $routes[0]->key );
		$this->assertSame( '#^/wp/v2/posts$#', $routes[0]->regex );
		$this->assertSame( 600, $routes[0]->ttl );
		$this->assertSame( 'registered', $routes[0]->param_mode );
		$this->assertSame( array( 'page' ), $routes[0]->allowed_params );
		$this->assertSame( array( '_locale' ), $routes[0]->ignore_params );
		$this->assertSame( array( 'preview' ), $routes[0]->bypass_params );
		$this->assertSame( array( 'accept-language' ), $routes[0]->vary_headers );

		$this->assertEquals( $config, Compiled_Config::from_array( $config->to_array() ) );
		$this->assertSame( Compiled_Config::FORMAT, $config->to_array()['format'] );
	}

	public function test_the_name_free_flags_default_to_serving_cors_and_bypassing_nothing(): void {
		$config = Compiled_Config::from_array( array() );

		$this->assertTrue( $config->cors_on_hits() );
		$this->assertFalse( $config->bypass_all() );
		$this->assertTrue( Compiled_Config::from_array( Compiled_Config::defaults() )->cors_on_hits() );
		$this->assertFalse( Compiled_Config::from_array( Compiled_Config::defaults() )->bypass_all() );
	}

	public function test_the_name_free_flags_are_read_from_the_snapshot(): void {
		$config = Compiled_Config::from_array(
			array_merge( Compiled_Config::defaults(), array( 'cors_on_hits' => false, 'bypass_all' => true ) )
		);

		$this->assertFalse( $config->cors_on_hits() );
		$this->assertTrue( $config->bypass_all() );
		$this->assertEquals( $config, Compiled_Config::from_array( $config->to_array() ) );
	}

	public function test_the_name_free_flags_are_written_only_when_they_differ_from_the_default(): void {
		$plain = Compiled_Config::from_array( Compiled_Config::defaults() )->to_array();
		$this->assertArrayNotHasKey( 'cors_on_hits', $plain );
		$this->assertArrayNotHasKey( 'bypass_all', $plain );
		$this->assertSame( Compiled_Config::FORMAT, $plain['format'] );

		$cors = Compiled_Config::from_array( array( 'cors_on_hits' => false ) )->to_array();
		$this->assertFalse( $cors['cors_on_hits'] );
		$this->assertArrayNotHasKey( 'bypass_all', $cors );

		$all = Compiled_Config::from_array( array( 'bypass_all' => true ) )->to_array();
		$this->assertTrue( $all['bypass_all'] );
		$this->assertArrayNotHasKey( 'cors_on_hits', $all );

		$explicit = Compiled_Config::from_array( array( 'cors_on_hits' => true, 'bypass_all' => false ) )->to_array();
		$this->assertArrayNotHasKey( 'cors_on_hits', $explicit );
		$this->assertArrayNotHasKey( 'bypass_all', $explicit );
	}

	public function test_malformed_name_free_flags_fall_back_to_their_defaults(): void {
		$config = Compiled_Config::from_array( array( 'cors_on_hits' => 'no', 'bypass_all' => 1 ) );

		$this->assertTrue( $config->cors_on_hits() );
		$this->assertFalse( $config->bypass_all() );
	}

	public function test_a_snapshot_with_the_name_free_flags_still_loads(): void {
		$this->set_compiled_config( array( 'cors_on_hits' => false, 'bypass_all' => true ) );
		$config = Compiled_Config::load();

		$this->assertInstanceOf( Compiled_Config::class, $config );
		$this->assertFalse( $config->cors_on_hits() );
		$this->assertTrue( $config->bypass_all() );
	}

	public function test_a_snapshot_without_the_nonce_fields_loads_with_nonce_caching_off_and_no_login_cookies(): void {
		$config = Compiled_Config::from_array( array() );

		$this->assertFalse( $config->nonce_caching() );
		$this->assertSame( array(), $config->login_cookies() );

		$plain = Compiled_Config::from_array( Compiled_Config::defaults() )->to_array();
		$this->assertArrayNotHasKey( 'nonce_caching', $plain );
		$this->assertArrayNotHasKey( 'login_cookies', $plain );
		$this->assertSame( 2, Compiled_Config::FORMAT );
		$this->assertSame( 2, $plain['format'] );
	}

	public function test_the_nonce_fields_are_written_only_when_set_and_round_trip(): void {
		$config = Compiled_Config::from_array( array( 'nonce_caching' => true, 'login_cookies' => array( 'auth_cookie', 'my_login' ) ) );

		$this->assertTrue( $config->nonce_caching() );
		$this->assertSame( array( 'auth_cookie', 'my_login' ), $config->login_cookies() );

		$data = $config->to_array();
		$this->assertTrue( $data['nonce_caching'] );
		$this->assertSame( array( 'auth_cookie', 'my_login' ), $data['login_cookies'] );
		$this->assertEquals( $config, Compiled_Config::from_array( $data ) );

		$flag_only = Compiled_Config::from_array( array( 'nonce_caching' => true ) )->to_array();
		$this->assertTrue( $flag_only['nonce_caching'] );
		$this->assertArrayNotHasKey( 'login_cookies', $flag_only );

		$cookies_only = Compiled_Config::from_array( array( 'login_cookies' => array( 'my_login' ) ) )->to_array();
		$this->assertArrayNotHasKey( 'nonce_caching', $cookies_only );
		$this->assertSame( array( 'my_login' ), $cookies_only['login_cookies'] );

		$explicit = Compiled_Config::from_array( array( 'nonce_caching' => false, 'login_cookies' => array() ) )->to_array();
		$this->assertArrayNotHasKey( 'nonce_caching', $explicit );
		$this->assertArrayNotHasKey( 'login_cookies', $explicit );
	}

	public function test_malformed_nonce_fields_load_as_off_and_empty(): void {
		foreach ( array( 1, '1', 'yes', array( true ), null ) as $value ) {
			$this->assertFalse( Compiled_Config::from_array( array( 'nonce_caching' => $value ) )->nonce_caching() );
		}

		foreach ( array( 'my_login', 7, true, null ) as $value ) {
			$this->assertSame( array(), Compiled_Config::from_array( array( 'login_cookies' => $value ) )->login_cookies() );
		}
		$this->assertSame(
			array( 'a', 'b' ),
			Compiled_Config::from_array( array( 'login_cookies' => array( 'a', 5, null, 'b' ) ) )->login_cookies()
		);
	}

	public function test_a_snapshot_with_the_nonce_fields_still_loads(): void {
		$this->set_compiled_config( array( 'nonce_caching' => true, 'login_cookies' => array( 'my_login' ) ) );
		$config = Compiled_Config::load();

		$this->assertInstanceOf( Compiled_Config::class, $config );
		$this->assertTrue( $config->nonce_caching() );
		$this->assertSame( array( 'my_login' ), $config->login_cookies() );
	}

	public function test_negative_route_round_trips_compactly(): void {
		$negative = array(
			'key'     => '/my/v1/private',
			'regex'   => '@^/my/v1/private$@i',
			'enabled' => false,
		);
		$config   = Compiled_Config::from_array(
			array_merge( Compiled_Config::defaults(), array( 'routes' => array( $negative, $this->route() ) ) )
		);

		$routes = $config->routes();
		$this->assertCount( 2, $routes );
		$this->assertFalse( $routes[0]->enabled );
		$this->assertSame( '/my/v1/private', $routes[0]->key );
		$this->assertTrue( $routes[1]->enabled, 'A stored positive entry without the flag stays enabled.' );
		$this->assertSame( $negative, $config->to_array()['routes'][0] );
		$this->assertArrayNotHasKey( 'enabled', $config->to_array()['routes'][1] );
		$this->assertEquals( $config, Compiled_Config::from_array( $config->to_array() ) );
	}

	public function test_route_with_broken_regex_is_dropped(): void {
		$config = Compiled_Config::from_array(
			array_merge(
				Compiled_Config::defaults(),
				array(
					'routes' => array(
						$this->route( array( 'key' => '/bad', 'regex' => '#^(unclosed$#' ) ),
						$this->route( array( 'key' => '/nodelim', 'regex' => 'no delimiters' ) ),
						$this->route(),
					),
				)
			)
		);

		$this->assertCount( 1, $config->routes() );
		$this->assertSame( '/wp/v2/posts', $config->routes()[0]->key );
		$this->assertCount( 1, $config->to_array()['routes'] );
	}

	public function test_malformed_fields_fall_back_to_defaults(): void {
		$config = Compiled_Config::from_array(
			array(
				'format'      => Compiled_Config::FORMAT,
				'enabled'     => 'yes',
				'default_ttl' => 'abc',
				'cors_origins' => 'x',
				'routes'      => 'x',
			)
		);
		$defaults = Compiled_Config::defaults();

		$this->assertSame( $defaults['enabled'], $config->enabled() );
		$this->assertSame( $defaults['default_ttl'], $config->default_ttl() );
		$this->assertSame( array(), $config->cors_origins() );
		$this->assertSame( array(), $config->routes() );
	}

	public function test_route_without_the_vary_fields_keeps_todays_behaviour(): void {
		$stored = $this->route();
		$route  = Compiled_Route::from_array( $stored );

		$this->assertInstanceOf( Compiled_Route::class, $route );
		$this->assertFalse( $route->visitor_varies );
		$this->assertFalse( $route->vary_invalid );
		$this->assertSame( array(), $route->vary_cookies );
		$this->assertSame( $stored, $route->to_array(), 'Default values are not written.' );
	}

	public function test_route_that_varies_without_the_vary_invalid_field_declares_a_valid_declaration(): void {
		$stored = $this->route( array( 'visitor_varies' => true ) );
		$route  = Compiled_Route::from_array( $stored );

		$this->assertInstanceOf( Compiled_Route::class, $route );
		$this->assertTrue( $route->visitor_varies );
		$this->assertFalse( $route->vary_invalid, 'A snapshot from before the field existed does not blame the declaration.' );
		$this->assertSame( $stored, $route->to_array() );
	}

	public function test_route_with_an_invalid_declaration_round_trips_with_the_reason(): void {
		$stored = $this->route(
			array(
				'visitor_varies' => true,
				'vary_invalid'   => true,
			)
		);
		$route  = Compiled_Route::from_array( $stored );

		$this->assertInstanceOf( Compiled_Route::class, $route );
		$this->assertTrue( $route->visitor_varies );
		$this->assertTrue( $route->vary_invalid );
		$this->assertSame( array(), $route->vary_cookies );
		$this->assertSame( $stored, $route->to_array() );
	}

	public function test_vary_invalid_alone_still_fails_closed_and_only_a_literal_true_counts(): void {
		$route = Compiled_Route::from_array( $this->route( array( 'vary_invalid' => true ) ) );

		$this->assertInstanceOf( Compiled_Route::class, $route );
		$this->assertTrue( $route->visitor_varies );
		$this->assertTrue( $route->vary_invalid );

		$route = Compiled_Route::from_array( $this->route( array( 'vary_invalid' => 'yes' ) ) );

		$this->assertInstanceOf( Compiled_Route::class, $route );
		$this->assertFalse( $route->visitor_varies );
		$this->assertFalse( $route->vary_invalid );
	}

	public function test_a_snapshot_with_vary_invalid_but_no_cause_loads_as_an_invalid_cookie_declaration(): void {
		$stored = $this->route(
			array(
				'visitor_varies' => true,
				'vary_invalid'   => true,
			)
		);
		$route  = Compiled_Route::from_array( $stored );

		$this->assertInstanceOf( Compiled_Route::class, $route );
		$this->assertTrue( $route->vary_invalid );
		$this->assertSame( Compiled_Route::VARY_COOKIES, $route->vary_cause, 'The only cause there was before the field existed.' );
		$this->assertSame( $stored, $route->to_array(), 'The snapshot is written as it was.' );
	}

	public function test_route_without_an_invalid_declaration_has_no_vary_cause(): void {
		$route = Compiled_Route::from_array( $this->route( array( 'visitor_varies' => true ) ) );

		$this->assertInstanceOf( Compiled_Route::class, $route );
		$this->assertNull( $route->vary_cause );
		$this->assertFalse( $route->vary_invalid );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function global_vary_causes(): array {
		return array(
			'unusable global headers' => array( Compiled_Route::VARY_GLOBAL_HEADERS ),
			'too many merged headers' => array( Compiled_Route::VARY_MERGED_HEADERS ),
		);
	}

	/**
	 * @dataProvider global_vary_causes
	 *
	 * @param string $cause The vary cause.
	 */
	public function test_a_global_vary_cause_round_trips_and_derives_vary_invalid(string $cause): void {
		$stored = $this->route(
			array(
				'visitor_varies' => true,
				'vary_invalid'   => true,
				'vary_cause'     => $cause,
			)
		);
		$route  = Compiled_Route::from_array( $stored );

		$this->assertInstanceOf( Compiled_Route::class, $route );
		$this->assertSame( $cause, $route->vary_cause );
		$this->assertTrue( $route->vary_invalid );
		$this->assertTrue( $route->visitor_varies );
		$this->assertSame( $stored, $route->to_array() );

		$alone = Compiled_Route::from_array( $this->route( array( 'vary_cause' => $cause ) ) );
		$this->assertInstanceOf( Compiled_Route::class, $alone );
		$this->assertTrue( $alone->vary_invalid, 'A cause alone is an invalid declaration.' );
		$this->assertTrue( $alone->visitor_varies, 'A cause alone fails closed.' );
		$this->assertSame( $cause, $alone->vary_cause );
	}

	public function test_an_unknown_vary_cause_fails_closed_as_an_invalid_cookie_declaration(): void {
		foreach ( array( 'bogus', 7, true, array( 'global_headers' ) ) as $unknown ) {
			$route = Compiled_Route::from_array( $this->route( array( 'vary_cause' => $unknown ) ) );

			$this->assertInstanceOf( Compiled_Route::class, $route );
			$this->assertTrue( $route->visitor_varies );
			$this->assertTrue( $route->vary_invalid );
			$this->assertSame( Compiled_Route::VARY_COOKIES, $route->vary_cause );
		}
	}

	public function test_a_global_vary_cause_wins_over_malformed_vary_cookies(): void {
		$route = Compiled_Route::from_array(
			$this->route(
				array(
					'vary_cause'   => Compiled_Route::VARY_MERGED_HEADERS,
					'vary_cookies' => 'garbage',
				)
			)
		);

		$this->assertInstanceOf( Compiled_Route::class, $route );
		$this->assertSame( Compiled_Route::VARY_MERGED_HEADERS, $route->vary_cause );
		$this->assertSame( array(), $route->vary_cookies );
	}

	public function test_route_with_the_vary_fields_round_trips(): void {
		$stored = $this->route(
			array(
				'visitor_varies' => true,
				'vary_cookies'   => array( 'my_cur' => array( 'EUR', 'USD' ) ),
			)
		);
		$route  = Compiled_Route::from_array( $stored );

		$this->assertInstanceOf( Compiled_Route::class, $route );
		$this->assertTrue( $route->visitor_varies );
		$this->assertSame( array( 'my_cur' => array( 'EUR', 'USD' ) ), $route->vary_cookies );
		$this->assertSame( $stored, $route->to_array() );

		$cookies_only = $this->route( array( 'vary_cookies' => array( 'my_cur' => array( 'EUR' ) ) ) );
		$this->assertSame( $cookies_only, Compiled_Route::from_array( $cookies_only )->to_array(), 'visitor_varies is written only when true.' );
	}

	public function test_route_without_methods_loads_as_get_and_writes_none(): void {
		$stored = $this->route();
		$route  = Compiled_Route::from_array( $stored );

		$this->assertInstanceOf( Compiled_Route::class, $route );
		$this->assertSame( array( 'GET' ), $route->methods );
		$this->assertSame( $stored, $route->to_array() );
		$this->assertSame( array( 'GET' ), Compiled_Route::negative( '/x', '@^/x$@i' )->methods );
	}

	public function test_route_with_methods_round_trips(): void {
		$stored = $this->route( array( 'methods' => array( 'GET', 'POST', 'PATCH' ) ) );
		$route  = Compiled_Route::from_array( $stored );

		$this->assertInstanceOf( Compiled_Route::class, $route );
		$this->assertSame( array( 'GET', 'POST', 'PATCH' ), $route->methods );
		$this->assertSame( $stored, $route->to_array() );

		$config = Compiled_Config::from_array( array_merge( Compiled_Config::defaults(), array( 'routes' => array( $stored ) ) ) );
		$this->assertSame( array( 'GET', 'POST', 'PATCH' ), $config->routes()[0]->methods );
		$this->assertSame( $stored, $config->to_array()['routes'][0] );

		$unordered = Compiled_Route::from_array( $this->route( array( 'methods' => array( 'PATCH', 'POST', 'PATCH' ) ) ) );
		$this->assertInstanceOf( Compiled_Route::class, $unordered );
		$this->assertSame( array( 'POST', 'PATCH' ), $unordered->methods );
	}

	public function test_malformed_methods_load_as_get(): void {
		$malformed = array(
			'a string'            => 'POST',
			'an empty list'       => array(),
			'a map'               => array( 'a' => 'POST' ),
			'a method not in set' => array( 'GET', 'DELETE' ),
			'only such a method'  => array( 'HEAD' ),
			'lowercase'           => array( 'post' ),
			'a non-string entry'  => array( 'POST', 5 ),
			'null'                => null,
			'a bool'              => true,
		);
		foreach ( $malformed as $label => $value ) {
			$route = Compiled_Route::from_array( $this->route( array( 'methods' => $value ) ) );

			$this->assertInstanceOf( Compiled_Route::class, $route, $label );
			$this->assertSame( array( 'GET' ), $route->methods, $label );
			$this->assertArrayNotHasKey( 'methods', $route->to_array(), $label );
		}
	}

	public function test_methods_of_a_negative_route_are_ignored(): void {
		$route = Compiled_Route::from_array(
			array(
				'key'     => '/my/v1/private',
				'regex'   => '@^/my/v1/private$@i',
				'enabled' => false,
				'methods' => array( 'POST' ),
			)
		);

		$this->assertInstanceOf( Compiled_Route::class, $route );
		$this->assertSame( array( 'GET' ), $route->methods );
		$this->assertArrayNotHasKey( 'methods', $route->to_array() );
	}

	public function test_malformed_vary_cookies_load_as_visitor_varying(): void {
		foreach ( array( 'garbage', array( 'bad%name' => array( 'x' ) ), array( 'c' => array() ), array( 'c' => 'x' ) ) as $malformed ) {
			$route = Compiled_Route::from_array( $this->route( array( 'vary_cookies' => $malformed ) ) );

			$this->assertInstanceOf( Compiled_Route::class, $route );
			$this->assertTrue( $route->visitor_varies );
			$this->assertTrue( $route->vary_invalid );
			$this->assertSame( array(), $route->vary_cookies );
			$this->assertArrayNotHasKey( 'vary_cookies', $route->to_array() );
			$this->assertTrue( $route->to_array()['vary_invalid'] ?? false );
		}

		$route = Compiled_Route::from_array( $this->route( array( 'visitor_varies' => 'yes' ) ) );
		$this->assertTrue( $route->visitor_varies, 'A malformed visitor_varies does not leave the route cacheable.' );
	}

	public function test_vary_cookies_of_a_negative_route_are_ignored(): void {
		$route = Compiled_Route::from_array(
			array(
				'key'            => '/my/v1/private',
				'regex'          => '@^/my/v1/private$@i',
				'enabled'        => false,
				'visitor_varies' => true,
				'vary_invalid'   => true,
				'vary_cause'     => Compiled_Route::VARY_GLOBAL_HEADERS,
				'vary_cookies'   => array( 'a' => array( 'x' ) ),
			)
		);

		$this->assertInstanceOf( Compiled_Route::class, $route );
		$this->assertFalse( $route->visitor_varies );
		$this->assertFalse( $route->vary_invalid );
		$this->assertNull( $route->vary_cause );
		$this->assertSame( array(), $route->vary_cookies );
	}

	public function test_vary_cookie_names_is_the_sorted_unique_union_of_enabled_routes(): void {
		$config = Compiled_Config::from_array(
			array_merge(
				Compiled_Config::defaults(),
				array(
					'routes' => array(
						$this->route( array( 'vary_cookies' => array( 'b' => array( 'x' ) ) ) ),
						$this->route(
							array(
								'key'          => '/wp/v2/pages',
								'regex'        => '#^/wp/v2/pages$#',
								'vary_cookies' => array(
									'b' => array( 'y' ),
									'a' => array( 'z' ),
								),
							)
						),
						$this->route( array( 'key' => '/wp/v2/tags', 'regex' => '#^/wp/v2/tags$#' ) ),
						array(
							'key'          => '/my/v1/private',
							'regex'        => '@^/my/v1/private$@i',
							'enabled'      => false,
							'vary_cookies' => array( 'ignored' => array( 'x' ) ),
						),
					),
				)
			)
		);

		$this->assertSame( array( 'a', 'b' ), $config->vary_cookie_names() );
		$this->assertSame( array(), Compiled_Config::from_array( Compiled_Config::defaults() )->vary_cookie_names() );
		$this->assertEquals( $config, Compiled_Config::from_array( $config->to_array() ) );
	}

	public function test_request_header_names_is_the_sorted_unique_lowercase_union_of_enabled_vary_and_bypass_headers(): void {
		$config = Compiled_Config::from_array(
			array_merge(
				Compiled_Config::defaults(),
				array(
					'bypass_headers' => array( 'x-api-key' ),
					'routes'         => array(
						$this->route( array( 'vary_headers' => array( 'Accept-Language', 'x-b' ) ) ),
						$this->route(
							array(
								'key'          => '/wp/v2/pages',
								'regex'        => '#^/wp/v2/pages$#',
								'vary_headers' => array( 'x-b', 'accept-language' ),
							)
						),
						array(
							'key'          => '/my/v1/private',
							'regex'        => '@^/my/v1/private$@i',
							'enabled'      => false,
							'vary_headers' => array( 'x-c' ),
						),
					),
				)
			)
		);

		$this->assertSame( array( 'accept-language', 'x-api-key', 'x-b' ), $config->request_header_names() );
		$this->assertSame( array(), Compiled_Config::from_array( Compiled_Config::defaults() )->request_header_names() );
		$this->assertEquals( $config, Compiled_Config::from_array( $config->to_array() ) );
	}

	public function test_a_snapshot_without_home_host_loads_as_no_host_keying_and_writes_none(): void {
		$legacy = Compiled_Config::defaults();
		unset( $legacy['home_host'] );

		$this->assertSame( '', Compiled_Config::from_array( $legacy )->home_host() );
		$this->assertSame( '', Compiled_Config::from_array( array() )->home_host() );

		$this->assertArrayNotHasKey( 'home_host', Compiled_Config::from_array( Compiled_Config::defaults() )->to_array() );
		$this->assertArrayNotHasKey( 'home_host', Compiled_Config::from_array( $legacy )->to_array() );
		$this->assertSame( 2, Compiled_Config::FORMAT );
	}

	public function test_home_host_is_written_only_when_set_and_round_trips(): void {
		$config = Compiled_Config::from_array( array( 'home_host' => 'shop.example:8443' ) );

		$this->assertSame( 'shop.example:8443', $config->home_host() );
		$this->assertSame( 'shop.example:8443', $config->to_array()['home_host'] );
		$this->assertEquals( $config, Compiled_Config::from_array( $config->to_array() ) );

		$this->assertArrayNotHasKey( 'home_host', Compiled_Config::from_array( array( 'home_host' => '' ) )->to_array() );
	}

	public function test_a_malformed_home_host_loads_as_empty(): void {
		foreach ( array( 1, true, null, array( 'example.org' ) ) as $value ) {
			$this->assertSame( '', Compiled_Config::from_array( array( 'home_host' => $value ) )->home_host() );
		}
	}

	public function test_home_host_is_read_from_the_stored_snapshot(): void {
		$this->set_compiled_config( array( 'home_host' => 'example.org' ) );

		$this->assertSame( 'example.org', Compiled_Config::load()->home_host() );
	}
}
