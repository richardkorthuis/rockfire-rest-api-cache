<?php
/**
 * Tests of the WooCommerce currency-cookie declaration (`rfrac/woocommerce/currency_cookies`): what the compiled
 * snapshot carries for the price-bearing Store API routes and how a request is served, keyed and refused.
 *
 * The serving tests run for the early server and for the fallback server.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Config\Vary_Cookies;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Currency_Cookies;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Currency_Cookies
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module
 */
class Test_Currency_Cookies extends Rfrac_Wc_E2e_Test_Case {

	/**
	 * The cookie a currency plugin keeps the visitor's currency in.
	 *
	 * @var string
	 */
	private const COOKIE = 'wmc_current_currency';

	/**
	 * The route keys of the price-bearing Store API routes: the product list, a product by id, a product by slug
	 * and the collection data, under both namespaces.
	 *
	 * @var list<string>
	 */
	private const PRICE_ROUTES = array(
		'/wc/store/products',
		'/wc/store/products/(?P<id>[\d]+)',
		'/wc/store/products/(?P<slug>[\S]+)',
		'/wc/store/products/collection-data',
		'/wc/store/v1/products',
		'/wc/store/v1/products/(?P<id>[\d]+)',
		'/wc/store/v1/products/(?P<slug>[\S]+)',
		'/wc/store/v1/products/collection-data',
	);

	/**
	 * Catalog routes without prices, enabled next to the price-bearing ones.
	 *
	 * @var list<string>
	 */
	private const OTHER_ROUTES = array(
		'/wc/store/products/categories',
		'/wc/store/v1/products/categories',
	);

	/**
	 * Declare the currency cookie, like a currency plugin does.
	 *
	 * @param mixed $declaration What the `rfrac/woocommerce/currency_cookies` filter returns.
	 *
	 * @return void
	 */
	private function declare_cookies( mixed $declaration ): void {
		add_filter( Hooks::WC_CURRENCY_COOKIES, static fn() => $declaration );
	}

	/**
	 * Wire the WooCommerce module of a fresh container, as the container of a request does.
	 *
	 * The booted container's hooks are detached by the base test case, so each test wires its own.
	 *
	 * @return Plugin
	 */
	private function wire_module(): Plugin {
		$plugin = new Plugin();
		$plugin->register_integrations();
		$this->assertNotNull( $plugin->woocommerce(), 'WooCommerce is loaded.' );

		return $plugin;
	}

	/**
	 * Enable the price-bearing routes and the routes without prices for caching.
	 *
	 * @return void
	 */
	private function enable_store_routes(): void {
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( $rules ) {
				foreach ( array_merge( self::PRICE_ROUTES, self::OTHER_ROUTES ) as $key ) {
					$rules[ $key ] = array( 'status' => 'enabled' );
				}
				return $rules;
			}
		);
	}

	/**
	 * Recompile the configuration against a server with the Store API routes, with the module wired.
	 *
	 * The hooks of the container wired for the compile are removed again afterwards, so each request runs with the
	 * wiring of its own container only, as in production.
	 *
	 * @return array<string,array<string,mixed>> The stored routes by route key.
	 */
	private function recompile(): array {
		$plugin = $this->wire_module();
		$this->enable_store_routes();
		delete_option( Failure_Recorder::OPTION );

		try {
			( new Config_Compiler() )->compile( $this->new_rest_server() );
		} finally {
			foreach ( $plugin->engine_hooks() as $hook ) {
				remove_filter( $hook['hook'], $hook['callback'], $hook['priority'] );
			}
		}

		$stored = get_option( Names::OPTION_CONFIG );
		$this->assertIsArray( $stored );
		$routes = array();
		foreach ( (array) $stored['routes'] as $route ) {
			$routes[ $route['key'] ] = $route;
		}
		return $routes;
	}

	/**
	 * A cookie jar with the currency cookie.
	 *
	 * @param mixed $value Cookie value.
	 *
	 * @return array<string,mixed>
	 */
	private function cur( mixed $value ): array {
		return array( self::COOKIE => $value );
	}

	/**
	 * Make the price of every product depend on the currency cookie, like a currency switcher does.
	 *
	 * @return void
	 */
	private function price_by_currency(): void {
		add_filter(
			'woocommerce_product_get_price',
			static function ( $price ) {
				$prices   = array(
					'EUR' => '10',
					'USD' => '11',
				);
				$currency = isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
				return $prices[ $currency ] ?? $price;
			}
		);
	}

	// ---------------------------------------------------------------------------------------
	// The component.
	// ---------------------------------------------------------------------------------------

	public function test_the_component_declares_the_engine_seam_with_two_arguments(): void {
		$this->assertSame(
			array( array( Hooks::ROUTE_VARY_COOKIES, 'route_cookies', 10, 2 ) ),
			Currency_Cookies::hooks()
		);
	}

	public function test_the_module_wires_the_component_as_a_guarded_engine_hook(): void {
		$plugin = $this->wire_module();
		$this->declare_cookies( array( self::COOKIE => array( 'EUR', 'USD' ) ) );

		$hooks = array_values( array_filter( $plugin->engine_hooks(), static fn( array $h ): bool => Hooks::ROUTE_VARY_COOKIES === $h['hook'] ) );

		$this->assertCount( 1, $hooks );
		$this->assertSame( 2, $hooks[0]['args'] );
		$this->assertSame( 10, has_filter( Hooks::ROUTE_VARY_COOKIES, $hooks[0]['callback'] ) );
		$this->assertSame(
			array( self::COOKIE => array( 'EUR', 'USD' ) ),
			apply_filters( Hooks::ROUTE_VARY_COOKIES, array(), '/wc/store/v1/products' )
		);
	}

	public function test_a_route_that_is_not_price_bearing_is_left_untouched(): void {
		$calls = 0;
		add_filter(
			Hooks::WC_CURRENCY_COOKIES,
			static function () use ( &$calls ) {
				++$calls;
				return array( self::COOKIE => array( 'EUR' ) );
			}
		);
		$component = new Currency_Cookies();
		$incoming  = array( 'lang' => array( 'nl', 'en' ) );

		foreach ( array_merge( self::OTHER_ROUTES, array( '/wp/v2/posts', '/wc/store/v1/cart', '/wc/store/v1/products/attributes', '' ) ) as $route ) {
			$this->assertSame( $incoming, $component->route_cookies( $incoming, $route ), "Route {$route}" );
		}
		$this->assertSame( 'EUR', $component->route_cookies( 'EUR', '/wp/v2/posts' ), 'A value that is not an array is left as it is, too.' );
		$this->assertSame( 0, $calls, 'The declaration is not even read for them.' );
	}

	public function test_a_price_route_without_a_declaration_keeps_its_cookies(): void {
		$component = new Currency_Cookies();

		foreach ( self::PRICE_ROUTES as $route ) {
			$this->assertSame( array(), $component->route_cookies( array(), $route ), $route );
			$this->assertSame( array( 'lang' => array( 'nl' ) ), $component->route_cookies( array( 'lang' => array( 'nl' ) ), $route ), $route );
		}
	}

	public function test_a_price_route_gets_the_declaration(): void {
		$this->declare_cookies( array( self::COOKIE => array( 'EUR', 'USD' ) ) );
		$component = new Currency_Cookies();

		foreach ( self::PRICE_ROUTES as $route ) {
			$this->assertSame( array( self::COOKIE => array( 'EUR', 'USD' ) ), $component->route_cookies( array(), $route ), $route );
		}
	}

	public function test_the_declaration_is_merged_per_name_with_the_union_of_the_values(): void {
		$this->declare_cookies(
			array(
				'cur'  => array( 'USD', 'GBP', 'EUR' ),
				'zone' => array( 'a' ),
			)
		);

		$merged = ( new Currency_Cookies() )->route_cookies(
			array(
				'cur'  => array( 'EUR', 'USD' ),
				'lang' => array( 'nl' ),
			),
			'/wc/store/v1/products'
		);

		$this->assertSame(
			array(
				'cur'  => array( 'EUR', 'USD', 'GBP' ),
				'lang' => array( 'nl' ),
				'zone' => array( 'a' ),
			),
			$merged
		);
		$this->assertSame(
			array(
				'cur'  => array( 'EUR', 'GBP', 'USD' ),
				'lang' => array( 'nl' ),
				'zone' => array( 'a' ),
			),
			Vary_Cookies::normalize( $merged ),
			'The engine accepts the merged declaration.'
		);
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function non_array_declarations(): array {
		return array(
			'string' => array( 'EUR' ),
			'null'   => array( null ),
			'false'  => array( false ),
			'int'    => array( 1 ),
		);
	}

	/**
	 * @dataProvider non_array_declarations
	 *
	 * @param mixed $declared What the filter returns.
	 */
	public function test_a_declaration_that_is_not_an_array_is_returned_as_it_is( mixed $declared ): void {
		$this->declare_cookies( $declared );

		$this->assertSame( $declared, ( new Currency_Cookies() )->route_cookies( array(), '/wc/store/v1/products' ) );
		$this->assertSame( $declared, ( new Currency_Cookies() )->route_cookies( array( 'lang' => array( 'nl' ) ), '/wc/store/v1/products' ) );
		$this->assertNull( Vary_Cookies::normalize( $declared ), 'The engine rejects it, so the route fails closed.' );
	}

	public function test_cookies_that_are_not_an_array_are_returned_as_they_are(): void {
		$this->declare_cookies( array( self::COOKIE => array( 'EUR' ) ) );

		$this->assertSame( 'broken', ( new Currency_Cookies() )->route_cookies( 'broken', '/wc/store/v1/products' ) );
		$this->assertNull( ( new Currency_Cookies() )->route_cookies( null, '/wc/store/v1/products' ) );
	}

	public function test_a_value_that_is_not_a_list_is_kept_so_the_engine_rejects_it(): void {
		$this->declare_cookies( array( 'cur' => 'EUR' ) );
		$component = new Currency_Cookies();

		$this->assertSame( array( 'cur' => 'EUR' ), $component->route_cookies( array(), '/wc/store/v1/products' ) );
		$this->assertSame( array( 'cur' => 'EUR' ), $component->route_cookies( array( 'cur' => array( 'USD' ) ), '/wc/store/v1/products' ) );
		$this->assertSame( array( 'cur' => 'USD' ), $component->route_cookies( array( 'cur' => 'USD' ), '/wc/store/v1/products' ), 'An earlier invalid value is not repaired.' );
		$this->assertNull( Vary_Cookies::normalize( $component->route_cookies( array(), '/wc/store/v1/products' ) ) );
	}

	public function test_a_declaration_that_cannot_be_read_fails_closed(): void {
		add_filter(
			Hooks::WC_CURRENCY_COOKIES,
			static function () {
				throw new RuntimeException( 'currency plugin failed' );
			}
		);

		$this->assertNull( ( new Currency_Cookies() )->route_cookies( array( 'lang' => array( 'nl' ) ), '/wc/store/v1/products' ) );
		$this->assertSame( array( 'lang' => array( 'nl' ) ), ( new Currency_Cookies() )->route_cookies( array( 'lang' => array( 'nl' ) ), '/wp/v2/posts' ) );
	}

	// ---------------------------------------------------------------------------------------
	// The compiled snapshot.
	// ---------------------------------------------------------------------------------------

	public function test_the_price_routes_carry_the_declaration_after_a_recompile(): void {
		$this->declare_cookies( array( self::COOKIE => array( 'EUR', 'USD' ) ) );

		$routes = $this->recompile();

		foreach ( self::PRICE_ROUTES as $key ) {
			$this->assertArrayHasKey( $key, $routes );
			$this->assertSame( array( self::COOKIE => array( 'EUR', 'USD' ) ), $routes[ $key ]['vary_cookies'] ?? null, $key );
			$this->assertArrayNotHasKey( 'visitor_varies', $routes[ $key ], $key );
		}
		foreach ( self::OTHER_ROUTES as $key ) {
			$this->assertArrayHasKey( $key, $routes );
			$this->assertTrue( $routes[ $key ]['enabled'] ?? true, "{$key} is enabled." );
			$this->assertArrayNotHasKey( 'vary_cookies', $routes[ $key ], $key );
			$this->assertArrayNotHasKey( 'visitor_varies', $routes[ $key ], $key );
		}
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_without_a_declaration_no_route_is_keyed_by_a_cookie(): void {
		$routes = $this->recompile();

		foreach ( array_merge( self::PRICE_ROUTES, self::OTHER_ROUTES ) as $key ) {
			$this->assertArrayHasKey( $key, $routes );
			$this->assertArrayNotHasKey( 'vary_cookies', $routes[ $key ], $key );
			$this->assertArrayNotHasKey( 'visitor_varies', $routes[ $key ], $key );
		}
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_the_cookie_name_is_normalised_like_cookie(): void {
		$this->declare_cookies( array( 'wmc.currency' => array( 'EUR' ) ) );

		$routes = $this->recompile();

		$this->assertSame( array( 'wmc_currency' => array( 'EUR' ) ), $routes['/wc/store/v1/products']['vary_cookies'] ?? null );
	}

	public function test_a_declaration_that_is_a_string_makes_the_price_routes_visitor_varying(): void {
		$this->declare_cookies( 'EUR' );

		$routes = $this->recompile();

		foreach ( self::PRICE_ROUTES as $key ) {
			$this->assertTrue( $routes[ $key ]['visitor_varies'] ?? false, $key );
			$this->assertArrayNotHasKey( 'vary_cookies', $routes[ $key ], $key );
		}
		foreach ( self::OTHER_ROUTES as $key ) {
			$this->assertArrayNotHasKey( 'visitor_varies', $routes[ $key ], $key );
		}
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'route_rules', $last['context'] );
		$this->assertStringStartsWith( 'Invalid vary declaration, the route is not cached: ', $last['message'] );
		$this->assertStringContainsString( '/wc/store/', $last['message'] );
	}

	public function test_a_declaration_that_throws_blocks_the_price_routes_and_records_the_exception(): void {
		add_filter(
			Hooks::WC_CURRENCY_COOKIES,
			static function () {
				throw new RuntimeException( 'currency plugin failed' );
			}
		);

		$routes = $this->recompile();

		foreach ( self::PRICE_ROUTES as $key ) {
			$this->assertTrue( $routes[ $key ]['visitor_varies'] ?? false, $key );
			$this->assertArrayNotHasKey( 'vary_cookies', $routes[ $key ], $key );
		}
		foreach ( self::OTHER_ROUTES as $key ) {
			$this->assertArrayNotHasKey( 'visitor_varies', $routes[ $key ], $key );
		}
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'hook:' . Hooks::WC_CURRENCY_COOKIES, $last['context'] );
		$this->assertStringContainsString( 'RuntimeException: currency plugin failed', $last['message'] );
	}

	// ---------------------------------------------------------------------------------------
	// Serving.
	// ---------------------------------------------------------------------------------------

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_catalog_responses_are_keyed_by_the_allowed_currency_and_other_values_bypass( string $path ): void {
		$this->wc_product( array( 'regular_price' => '12' ) );
		$this->price_by_currency();
		$this->declare_cookies( array( self::COOKIE => array( 'EUR', 'USD' ) ) );
		$this->recompile();
		$h   = $this->harness( $path );
		$uri = '/wp-json/wc/store/v1/products';

		// EUR: a miss, then a hit.
		$h->request( $uri, array(), $this->cur( 'EUR' ) );
		$eur = $h->miss( '/wc/store/v1/products' );
		$this->assertNotNull( $eur );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$h->request( $uri, array(), $this->cur( 'EUR' ) );
		$this->assertTrue( $h->lookup(), 'The second EUR request is a hit.' );
		$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( $eur, $h->output );

		// USD: never served the EUR body; it is a miss that stores its own entry.
		$h->request( $uri, array(), $this->cur( 'USD' ) );
		$usd = $h->miss( '/wc/store/v1/products' );
		$this->assertNotNull( $usd, 'USD does not hit the EUR entry.' );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertNotSame( $eur, $usd, 'The two currencies price the product differently.' );
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count() );

		$h->request( $uri, array(), $this->cur( 'USD' ) );
		$this->assertTrue( $h->lookup(), 'The second USD request is a hit.' );
		$this->assertSame( $usd, $h->output, 'USD is served the USD body.' );

		// No cookie: keyed separately, under the empty value.
		$h->request( $uri );
		$this->assertNotNull( $h->miss( '/wc/store/v1/products' ), 'No cookie does not hit a currency entry.' );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 3, Rfrac_Entry_Fixtures::count() );

		$h->request( $uri );
		$this->assertTrue( $h->lookup(), 'The empty value has its own entry.' );
		$this->assertNotSame( $eur, $h->output );
		$this->assertNotSame( $usd, $h->output );

		// A value outside the allow-list: neither served nor stored.
		$before = Rfrac_Serving_Harness::database_dump();
		$h->request( $uri, array(), $this->cur( 'GBP' ) );
		$this->assertFalse( $h->lookup(), 'GBP is not served.' );
		$this->assertSame( Bypass_Reason::VARY_COOKIE, Request_State::decision()->reason );
		$h->serve( '/wc/store/v1/products' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 3, Rfrac_Entry_Fixtures::count(), 'GBP stores nothing.' );
		$this->assertSame( $before, Rfrac_Serving_Harness::database_dump(), 'GBP changes nothing in the cache tables.' );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_the_declaration_is_applied_again_when_a_price_response_is_stored( string $path ): void {
		$this->wc_product( array( 'regular_price' => '12' ) );
		$calls = 0;
		add_filter(
			Hooks::WC_CURRENCY_COOKIES,
			static function () use ( &$calls ) {
				++$calls;
				return array( self::COOKIE => array( 'EUR', 'USD' ) );
			}
		);
		$this->recompile();
		$this->assertGreaterThan( 0, $calls, 'The declaration is read while the configuration is compiled.' );
		$h = $this->harness( $path );
		$h->request( '/wp-json/wc/store/v1/products', array(), $this->cur( 'EUR' ) );
		$calls = 0;
		// The capture stores on `rest_pre_echo_response` at PHP_INT_MAX: count the reads up to that step.
		$before_store = null;
		add_filter(
			'rest_pre_echo_response',
			static function ( $data ) use ( &$calls, &$before_store ) {
				$before_store = $calls;
				return $data;
			}
		);

		$this->assertNotNull( $h->miss( '/wc/store/v1/products' ) );

		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'The response is stored.' );
		$this->assertSame( 0, $before_store, 'Neither the lookup nor the live request reads the declaration: the key comes from the compiled configuration.' );
		$this->assertSame( 1, $calls, 'The store-time veto reads the declaration once more.' );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_the_categories_route_is_shared_by_every_currency( string $path ): void {
		// An empty category is hidden and an empty result is never stored, so the category holds a product.
		$this->wc_product( array( 'category_ids' => array( $this->wc_category( 'Fixture category' ) ) ) );
		$this->declare_cookies( array( self::COOKIE => array( 'EUR', 'USD' ) ) );
		$this->recompile();
		$h   = $this->harness( $path );
		$uri = '/wp-json/wc/store/v1/products/categories';

		$h->request( $uri, array(), $this->cur( 'EUR' ) );
		$this->assertNotNull( $h->miss( '/wc/store/v1/products/categories' ) );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );

		foreach ( array( 'USD', 'GBP' ) as $currency ) {
			$h->request( $uri, array(), $this->cur( $currency ) );
			$this->assertTrue( $h->lookup(), "{$currency} is served the shared entry." );
		}
		$h->request( $uri );
		$this->assertTrue( $h->lookup(), 'A request without the cookie is served the shared entry.' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$this->assertNoFailures();
	}
}
