<?php
/**
 * Tests of the WooCommerce location pricing component: the compile-time bypass of the price-bearing Store API
 * routes while a tax-location filter is hooked, and the store-time guard that backs it up.
 *
 * Every test with a `$path` argument runs for the early server and for the fallback server.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Location_Pricing;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Location_Pricing
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module
 */
class Test_Location_Pricing extends Rfrac_Wc_E2e_Test_Case {

	/**
	 * Product id, set in set_up().
	 *
	 * @var int
	 */
	private int $product_id = 0;

	/**
	 * The product slug.
	 *
	 * @var string
	 */
	private const SLUG = 'fixture-hat';

	public function set_up() {
		parent::set_up();

		// A product in a category: the price routes and the category routes all have a non-empty result.
		$category         = $this->wc_category( 'Hats' );
		$this->product_id = $this->wc_product(
			array(
				'name'         => 'Hat',
				'slug'         => self::SLUG,
				'category_ids' => array( $category ),
			)
		)->get_id();
	}

	public function tear_down() {
		unset( $GLOBALS['wp_rest_server'] );

		parent::tear_down();
	}

	/**
	 * Every price-bearing request, on both serving paths: products (list, by id, by slug) and collection data,
	 * under the versioned and the unversioned namespace.
	 *
	 * `{id}` and `{slug}` are the fixture product's.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function price_requests(): array {
		$routes = array(
			'products'        => '/wc/store/v1/products',
			'product by id'   => '/wc/store/v1/products/{id}',
			'product by slug' => '/wc/store/v1/products/{slug}',
			'collection data' => '/wc/store/v1/products/collection-data',
			'alias products'  => '/wc/store/products',
			'alias by id'     => '/wc/store/products/{id}',
			'alias by slug'   => '/wc/store/products/{slug}',
			'alias coll data' => '/wc/store/products/collection-data',
		);

		$sets = array();
		foreach ( self::paths() as $path_name => $path ) {
			foreach ( $routes as $label => $route ) {
				$sets[ $path_name . ': ' . $label ] = array( $path[0], $route );
			}
		}
		return $sets;
	}

	/**
	 * Catalog requests that carry no price, on both serving paths.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function other_catalog_requests(): array {
		$routes = array(
			'categories'       => '/wc/store/v1/products/categories',
			'alias categories' => '/wc/store/products/categories',
		);

		$sets = array();
		foreach ( self::paths() as $path_name => $path ) {
			foreach ( $routes as $label => $route ) {
				$sets[ $path_name . ': ' . $label ] = array( $path[0], $route );
			}
		}
		return $sets;
	}

	/**
	 * Enable the price-bearing routes and the category routes, and compile from a full server.
	 *
	 * The container is built here because the engine hooks of the booted one are detached for every test; its
	 * module attaches the component to the compiler's seam.
	 *
	 * @return void
	 */
	private function compile(): void {
		( new Plugin() )->register_integrations();

		$config = new Config();
		foreach ( array_merge( Store_Routes::PRICE_BEARING, array( '/wc/store/products/categories', '/wc/store/v1/products/categories' ) ) as $key ) {
			$config->save_route_rule( $key, array( 'status' => 'enabled' ) );
		}

		unset( $GLOBALS['wp_rest_server'] );
		( new Config_Compiler() )->compile( rest_get_server() );
	}

	/**
	 * A callback on the tax-location filter, like a geolocation or multi-currency plugin adds.
	 *
	 * @return void
	 */
	private function hook_location_filter(): void {
		add_filter( 'woocommerce_get_tax_location', static fn( $location ) => $location );
	}

	/**
	 * Resolve the placeholders of a price route.
	 *
	 * @param string $route Route with `{id}` and `{slug}`.
	 *
	 * @return string
	 */
	private function resolve( string $route ): string {
		return str_replace( array( '{id}', '{slug}' ), array( (string) $this->product_id, self::SLUG ), $route );
	}

	/**
	 * Point the harness at the anonymous request of a route.
	 *
	 * @param Rfrac_Serving_Harness $h     Harness.
	 * @param string                $route Route.
	 *
	 * @return void
	 */
	private function visit( Rfrac_Serving_Harness $h, string $route ): void {
		$h->request( '/wp-json' . $route );
	}

	/**
	 * Assert that a route is a miss that is stored, and then a hit.
	 *
	 * @param Rfrac_Serving_Harness $h     Harness.
	 * @param string                $route Route.
	 *
	 * @return void
	 */
	private function assert_miss_then_hit( Rfrac_Serving_Harness $h, string $route ): void {
		$this->visit( $h, $route );
		$body = $h->miss( $route );
		$this->assertNotNull( $body, $route . ' is a miss.' );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ), $route );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), $route . ' is stored.' );

		$this->visit( $h, $route );
		$this->assertTrue( $h->lookup(), $route . ' is a hit.' );
		$this->assertSame( $body, $h->output );
	}

	/**
	 * Assert that the response of a miss was not stored, and why.
	 *
	 * @param Rfrac_Serving_Harness $h        Harness.
	 * @param string                $route    Route.
	 * @param Bypass_Reason         $reason   Expected reason.
	 * @param int                   $expected Entries that exist.
	 *
	 * @return void
	 */
	private function assert_bypass( Rfrac_Serving_Harness $h, string $route, Bypass_Reason $reason, int $expected = 0 ): void {
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ), $route );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->assertSame( $reason->value, $h->live_header( 'X-Rfrac-Cache-Reason' ), $route );
		}
		$this->assertSame( $expected, Rfrac_Entry_Fixtures::count(), $route . ': nothing is stored.' );
	}

	/**
	 * Assert that a primed entry of a route is not served and nothing is stored, with a reason of the policy.
	 *
	 * @param Rfrac_Serving_Harness $h     Harness.
	 * @param string                $route Route.
	 *
	 * @return void
	 */
	private function assert_not_served_or_stored( Rfrac_Serving_Harness $h, string $route ): void {
		$this->visit( $h, $route );
		$this->assertFalse( $h->lookup(), $route . ': the primed entry is not served.' );
		$this->assertSame( Bypass_Reason::VISITOR_VARIES, Request_State::decision()->reason, $route );

		$h->serve( $route );
		$this->assert_bypass( $h, $route, Bypass_Reason::VISITOR_VARIES, 1 );
		$this->assertNoFailures();
	}

	/**
	 * Compile while the price route is cacheable, then prime an entry for it.
	 *
	 * @param Rfrac_Serving_Harness $h     Harness.
	 * @param string                $route Route.
	 *
	 * @return void
	 */
	private function compile_and_prime( Rfrac_Serving_Harness $h, string $route ): void {
		$this->compile();
		$this->visit( $h, $route );
		$this->prime( '{"primed":true}' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
	}

	// ---------- AC-1: a hooked location filter bypasses the price routes ----------

	/**
	 * @dataProvider price_requests
	 *
	 * @param string $path  Serving path.
	 * @param string $route Price route.
	 */
	public function test_a_hooked_location_filter_bypasses_the_price_routes( string $path, string $route ): void {
		$route = $this->resolve( $route );
		$h     = $this->harness( $path );
		$this->compile_and_prime( $h, $route );

		$this->hook_location_filter();
		$this->compile();

		$this->assert_not_served_or_stored( $h, $route );
	}

	/**
	 * @dataProvider other_catalog_requests
	 *
	 * @param string $path  Serving path.
	 * @param string $route Catalog route that carries no price.
	 */
	public function test_a_hooked_location_filter_leaves_the_other_catalog_routes_cached( string $path, string $route ): void {
		$this->hook_location_filter();
		$this->compile();

		$this->assert_miss_then_hit( $this->harness( $path ), $route );
		$this->assertNoFailures();
	}

	// ---------- AC-2: without a location filter the price routes are cached ----------

	/**
	 * @dataProvider price_requests
	 *
	 * @param string $path  Serving path.
	 * @param string $route Price route.
	 */
	public function test_without_a_location_filter_the_price_routes_are_cached( string $path, string $route ): void {
		$this->compile();

		$this->assert_miss_then_hit( $this->harness( $path ), $this->resolve( $route ) );
		$this->assertNoFailures();
	}

	// ---------- AC-3: the force filter can switch the bypass on ----------

	/**
	 * @dataProvider price_requests
	 *
	 * @param string $path  Serving path.
	 * @param string $route Price route.
	 */
	public function test_the_force_filter_switches_the_bypass_on_without_a_location_filter( string $path, string $route ): void {
		$route = $this->resolve( $route );
		$h     = $this->harness( $path );
		$this->compile_and_prime( $h, $route );

		add_filter( Hooks::WC_LOCATION_PRICING, '__return_true' );
		$this->compile();

		$this->assert_not_served_or_stored( $h, $route );
	}

	// ---------- AC-4: the force filter can switch the bypass off ----------

	/**
	 * @dataProvider price_requests
	 *
	 * @param string $path  Serving path.
	 * @param string $route Price route.
	 */
	public function test_the_force_filter_switches_the_bypass_off_while_a_location_filter_is_hooked( string $path, string $route ): void {
		$this->hook_location_filter();
		add_filter( Hooks::WC_LOCATION_PRICING, '__return_false' );
		$this->compile();

		$this->assert_miss_then_hit( $this->harness( $path ), $this->resolve( $route ) );
		$this->assertNoFailures();
	}

	// ---------- AC-5 to AC-8: the store-time guard ----------

	/**
	 * @dataProvider price_requests
	 *
	 * @param string $path  Serving path.
	 * @param string $route Price route.
	 */
	public function test_a_snapshot_compiled_without_detection_stores_nothing_once_a_location_filter_is_hooked( string $path, string $route ): void {
		$route = $this->resolve( $route );
		$this->compile();
		$h = $this->harness( $path );
		$this->visit( $h, $route );

		$this->hook_location_filter();
		$this->assertNotNull( $h->miss( $route ) );

		$this->assert_bypass( $h, $route, Bypass_Reason::FILTERED );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider price_requests
	 *
	 * @param string $path  Serving path.
	 * @param string $route Price route.
	 */
	public function test_a_loaded_customer_stores_nothing_for_a_price_route( string $path, string $route ): void {
		$route = $this->resolve( $route );
		$this->compile();
		$h = $this->harness( $path );
		$this->visit( $h, $route );

		WC()->customer = new WC_Customer();
		$this->assertNotNull( $h->miss( $route ) );

		$this->assert_bypass( $h, $route, Bypass_Reason::FILTERED );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider price_requests
	 *
	 * @param string $path  Serving path.
	 * @param string $route Price route.
	 */
	public function test_the_force_filter_lets_a_price_route_be_stored_while_a_location_filter_is_hooked( string $path, string $route ): void {
		$this->compile();
		$h = $this->harness( $path );

		$this->hook_location_filter();
		add_filter( Hooks::WC_LOCATION_PRICING, '__return_false' );
		$this->visit( $h, $this->resolve( $route ) );
		$this->assertNotNull( $h->miss( $this->resolve( $route ) ) );

		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider other_catalog_requests
	 *
	 * @param string $path  Serving path.
	 * @param string $route Catalog route that carries no price.
	 */
	public function test_a_hooked_location_filter_does_not_stop_a_route_without_prices_from_being_stored( string $path, string $route ): void {
		$this->compile();
		$h = $this->harness( $path );
		$this->visit( $h, $route );

		$this->hook_location_filter();
		$this->assertNotNull( $h->miss( $route ) );

		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$this->assertNoFailures();
	}

	// ---------- The seam and the veto without a request ----------

	public function test_the_module_attaches_the_component_to_the_compiler_and_the_store_veto(): void {
		$plugin = new Plugin();
		$plugin->register_integrations();

		$hooks = array();
		foreach ( $plugin->engine_hooks() as $hook ) {
			$hooks[ $hook['hook'] ] = $hook;
		}

		$this->assertArrayHasKey( Hooks::ROUTE_VARIES_PER_VISITOR, $hooks );
		$this->assertSame( 2, $hooks[ Hooks::ROUTE_VARIES_PER_VISITOR ]['args'] );
		$this->assertArrayHasKey( Hooks::STORE_ALLOWED, $hooks );
		$this->assertSame( 4, $hooks[ Hooks::STORE_ALLOWED ]['args'] );
	}

	public function test_detected_follows_the_tax_location_filter(): void {
		$this->assertFalse( Location_Pricing::detected() );

		$this->hook_location_filter();

		$this->assertTrue( Location_Pricing::detected() );
	}

	public function test_only_the_price_routes_vary_and_an_earlier_answer_is_kept(): void {
		$component = new Location_Pricing();
		$this->hook_location_filter();

		foreach ( Store_Routes::PRICE_BEARING as $key ) {
			$this->assertTrue( $component->varies( false, $key ), $key );
		}
		$this->assertFalse( $component->varies( false, '/wc/store/v1/products/categories' ) );
		$this->assertFalse( $component->varies( false, '/wp/v2/posts' ) );
		$this->assertTrue( $component->varies( true, '/wp/v2/posts' ), 'The answer of another callback is kept.' );
	}

	public function test_nothing_varies_without_a_location_filter_and_the_force_filter_decides(): void {
		$component = new Location_Pricing();
		$key       = '/wc/store/v1/products';

		$this->assertFalse( $component->varies( false, $key ) );

		add_filter( Hooks::WC_LOCATION_PRICING, '__return_true' );
		$this->assertTrue( $component->varies( false, $key ) );
		$this->assertFalse( $component->varies( false, '/wc/store/v1/products/tags' ), 'Only a price route is forced.' );
	}

	public function test_the_force_filter_receives_the_detected_value(): void {
		$seen = array();
		add_filter(
			Hooks::WC_LOCATION_PRICING,
			static function ( $detected ) use ( &$seen ) {
				$seen[] = $detected;
				return $detected;
			}
		);
		$component = new Location_Pricing();

		$component->varies( false, '/wc/store/v1/products' );
		$this->hook_location_filter();
		$component->varies( false, '/wc/store/v1/products' );

		$this->assertSame( array( false, true ), $seen );
	}

	public function test_the_veto_leaves_a_refusal_untouched_and_passes_other_routes(): void {
		$component = new Location_Pricing();
		$this->hook_location_filter();

		$this->assertFalse( $component->allow_store( false, '/wc/store/v1/products', '/wc/store/v1/products', 10 ) );
		$this->assertTrue( $component->allow_store( true, '/wc/store/v1/products/categories', '/wc/store/v1/products/categories', 10 ) );
		$this->assertTrue( $component->allow_store( true, '/wp/v2/posts', '/wp/v2/posts', 10 ) );
	}

	public function test_the_veto_refuses_a_price_route_while_a_location_filter_is_hooked(): void {
		$component = new Location_Pricing();

		$this->assertTrue( $component->allow_store( true, '/wc/store/v1/products', '/wc/store/v1/products', 10 ), 'Nothing varies the prices.' );

		$this->hook_location_filter();
		$this->assertFalse( $component->allow_store( true, '/wc/store/v1/products', '/wc/store/v1/products', 10 ) );
		$this->assertFalse( $component->allow_store( true, '/wc/store/v1/products/collection-data', '/wc/store/v1/products/collection-data', 10 ) );
	}

	public function test_the_veto_refuses_a_price_route_while_a_customer_is_loaded(): void {
		$component = new Location_Pricing();
		$this->assertNull( WC()->customer );

		WC()->customer = new WC_Customer();

		$this->assertFalse( $component->allow_store( true, '/wc/store/v1/products', '/wc/store/v1/products', 10 ) );
		$this->assertTrue( $component->allow_store( true, '/wc/store/v1/products/categories', '/wc/store/v1/products/categories', 10 ) );
	}

	public function test_the_veto_covers_a_price_route_matched_by_a_wildcard_rule(): void {
		$component = new Location_Pricing();
		$this->hook_location_filter();

		$this->assertFalse( $component->allow_store( true, '/wc/store/v1/products/12', '/wc/store/v1/*/*', 10 ) );
		$this->assertFalse( $component->allow_store( true, '/wc/store/products/hat', '/wc/store/products/*', 10 ) );
		$this->assertFalse( $component->allow_store( true, '/wc/store/v1/products/collection-data', '/wc/store/v1/products/*', 10 ) );
		$this->assertTrue( $component->allow_store( true, '/wc/store/v1/products/categories', '/wc/store/v1/products/*', 10 ), 'Categories are not price-bearing, although the slug route would match them.' );
		$this->assertTrue( $component->allow_store( true, '/wc/store/v1/products/categories/12', '/wc/store/v1/products/*/*', 10 ) );
		$this->assertTrue( $component->allow_store( true, '/wc/store/v1/products/attributes/3/terms', '/wc/store/v1/products/*/*/*', 10 ) );
		$this->assertTrue( $component->allow_store( true, '/wp/v2/posts', '/wp/v2/*', 10 ) );
	}

	public function test_the_force_filter_off_lets_the_veto_pass_and_only_false_does(): void {
		$component = new Location_Pricing();
		$this->hook_location_filter();

		add_filter( Hooks::WC_LOCATION_PRICING, '__return_false' );
		$this->assertTrue( $component->allow_store( true, '/wc/store/v1/products', '/wc/store/v1/products', 10 ) );
		remove_filter( Hooks::WC_LOCATION_PRICING, '__return_false' );

		add_filter( Hooks::WC_LOCATION_PRICING, '__return_zero' );
		$this->assertFalse( $component->allow_store( true, '/wc/store/v1/products', '/wc/store/v1/products', 10 ), 'Only the strict false forces the bypass off.' );
		remove_filter( Hooks::WC_LOCATION_PRICING, '__return_zero' );

		add_filter( Hooks::WC_LOCATION_PRICING, '__return_true' );
		$this->assertFalse( $component->allow_store( true, '/wc/store/v1/products', '/wc/store/v1/products', 10 ) );
	}

	public function test_the_force_filter_off_lets_a_loaded_customer_through_too(): void {
		$component = new Location_Pricing();
		WC()->customer = new WC_Customer();
		add_filter( Hooks::WC_LOCATION_PRICING, '__return_false' );

		$this->assertTrue( $component->allow_store( true, '/wc/store/v1/products', '/wc/store/v1/products', 10 ) );
	}

	// ---------- AC-9: the status ----------

	public function test_the_status_is_not_detected_without_a_location_filter(): void {
		$this->assertSame( 'not_detected', Location_Pricing::status() );
	}

	public function test_the_status_is_detected_while_a_location_filter_is_hooked(): void {
		$this->hook_location_filter();

		$this->assertSame( 'detected', Location_Pricing::status() );
	}

	public function test_the_status_is_forced_on_when_the_filter_forces_the_bypass_on(): void {
		add_filter( Hooks::WC_LOCATION_PRICING, '__return_true' );

		$this->assertSame( 'forced_on', Location_Pricing::status() );
	}

	public function test_the_status_is_forced_off_when_the_filter_forces_the_bypass_off(): void {
		$this->hook_location_filter();
		add_filter( Hooks::WC_LOCATION_PRICING, '__return_false' );

		$this->assertSame( 'forced_off', Location_Pricing::status() );
	}
}
