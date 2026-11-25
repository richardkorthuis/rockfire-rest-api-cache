<?php
/**
 * End-to-end tests of the Store API product list against a real WooCommerce: with the catalog preset applied an
 * anonymous request is a MISS and then a HIT on both serving paths, and the route snapshot survives the request
 * to the WooCommerce namespace, for which WooCommerce registers only its own routes.
 *
 * Every test with a `$path` argument runs for the early server and for the fallback server.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Config\Route_Discovery;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Location_Pricing;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Serving\Request_State;
use Rockfire\Rest_Api_Cache\Services\Route_Service;

/**
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Partial_Server
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Location_Pricing
 * @covers \Rockfire\Rest_Api_Cache\Serving\Early_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Fallback_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 */
class Test_E2e_Store_Catalog extends Rfrac_Wc_E2e_Test_Case {

	/**
	 * Key of the route preset.
	 *
	 * @var string
	 */
	private const PRESET = 'woocommerce-catalog';

	/**
	 * The Store API product list.
	 *
	 * @var string
	 */
	private const PRODUCTS = '/wc/store/v1/products';

	/**
	 * The Store API cart, a route the plugin never caches.
	 *
	 * @var string
	 */
	private const CART = '/wc/store/v1/cart';

	/**
	 * Names of the two published products.
	 *
	 * @var list<string>
	 */
	private const PRODUCT_NAMES = array( 'First product', 'Second product' );

	/**
	 * The `rest_route` query var as the test found it; `null` when it was not set.
	 *
	 * @var string|null
	 */
	private ?string $saved_rest_route = null;

	/**
	 * Whether the `rest_route` query var was set before the test.
	 *
	 * @var bool
	 */
	private bool $had_rest_route = false;

	public function set_up() {
		parent::set_up();

		$vars                   = $GLOBALS['wp']->query_vars;
		$this->had_rest_route   = array_key_exists( 'rest_route', $vars );
		$this->saved_rest_route = $this->had_rest_route ? (string) $vars['rest_route'] : null;
		$this->set_rest_route( null );

		foreach ( self::PRODUCT_NAMES as $name ) {
			$this->wc_product( array( 'name' => $name ) );
		}

		// The price guards are the real ones: no customer is loaded and nothing is hooked on the tax location.
		$this->assertNull( WC()->customer, 'No customer is loaded.' );
		$this->assertFalse( has_filter( 'woocommerce_get_tax_location' ), 'No callback is hooked on the tax location.' );
		$this->assertFalse( Location_Pricing::detected() );

		$this->apply_catalog_preset_and_compile();

		// A periodic check is due, so a request that is allowed to rebuild the snapshot would do so.
		delete_transient( Config_Compiler::CHECK_TRANSIENT );
	}

	public function tear_down() {
		if ( $this->had_rest_route ) {
			$GLOBALS['wp']->query_vars['rest_route'] = $this->saved_rest_route;
		} else {
			unset( $GLOBALS['wp']->query_vars['rest_route'] );
		}
		unset( $GLOBALS['wp_rest_server'] );

		parent::tear_down();
	}

	/**
	 * Set or remove the `rest_route` query var of the current request, which WooCommerce reads to load only the
	 * namespace that is requested.
	 *
	 * @param string|null $route Route, `null` to remove the query var.
	 *
	 * @return void
	 */
	private function set_rest_route( ?string $route ): void {
		if ( null === $route ) {
			unset( $GLOBALS['wp']->query_vars['rest_route'] );
			return;
		}
		$GLOBALS['wp']->query_vars['rest_route'] = $route;
	}

	/**
	 * Apply the `woocommerce-catalog` preset through the route service and compile from a fully loaded server.
	 *
	 * The container is built here because the engine hooks of the booted one are detached for every test. Its hooks
	 * are removed again before the requests, so each request runs with the wiring of its own container only.
	 *
	 * @return void
	 */
	private function apply_catalog_preset_and_compile(): void {
		$plugin = new Plugin();
		$plugin->register_integrations();
		try {
			$this->assertInstanceOf( Module::class, $plugin->woocommerce(), 'WooCommerce has loaded.' );
			$this->assertNotFalse( has_filter( Hooks::ROUTE_VARIES_PER_VISITOR ), 'The location pricing seam is wired.' );
			$this->assertNotFalse( has_filter( Hooks::STORE_ALLOWED ), 'The store-time price guard is wired.' );

			// A request for a core route: WooCommerce registers every namespace, the Store API catalog included.
			$server = $this->new_rest_server();
			$this->assertArrayHasKey( self::PRODUCTS, $server->get_routes(), 'Precondition: the Store API is registered.' );
			$this->assertArrayHasKey( '/wc/v3/products', $server->get_routes(), 'Precondition: the server is not partial.' );

			$enabled = ( new Route_Service( new Config(), new Route_Discovery() ) )->enable_preset( self::PRESET );
			$this->assertSame( count( Store_Routes::CATALOG ), $enabled, 'The preset enabled every catalog route.' );

			( new Config_Compiler() )->compile( $server );
		} finally {
			foreach ( $plugin->engine_hooks() as $hook ) {
				remove_filter( $hook['hook'], $hook['callback'], $hook['priority'] );
			}
		}

		$config = Compiled_Config::load();
		$this->assertNotNull( $config );
		$this->assertNotSame( '', $config->fingerprint(), 'The snapshot is current.' );
		$this->assertFalse( $config->pending_discovery() );

		$products = null;
		foreach ( $config->routes() as $route ) {
			if ( self::PRODUCTS === $route->key ) {
				$products = $route;
			}
		}
		$this->assertNotNull( $products, 'The snapshot holds the product list route.' );
		$this->assertTrue( $products->enabled, 'The product list route is enabled.' );
		$this->assertFalse( $products->visitor_varies, 'The product list does not vary per visitor.' );
	}

	/**
	 * The fingerprint of the stored snapshot.
	 *
	 * @return string
	 */
	private function fingerprint(): string {
		$config = Compiled_Config::load();
		$this->assertNotNull( $config );

		return $config->fingerprint();
	}

	/**
	 * Point the harness at an anonymous request of a WooCommerce route, like WordPress does: the query var of the
	 * route is set, so WooCommerce registers only the namespace that is requested.
	 *
	 * @param Rfrac_Serving_Harness $h     Harness.
	 * @param string                $route Route.
	 * @param bool                  $pretty Whether to use the `/wp-json/` form instead of `?rest_route=`.
	 *
	 * @return void
	 */
	private function visit( Rfrac_Serving_Harness $h, string $route, bool $pretty = true ): void {
		$h->request( $pretty ? '/wp-json' . $route : '/?rest_route=' . $route );
		$this->set_rest_route( $route );
	}

	/**
	 * Assert that the product list is a MISS that is stored, and then a HIT with the same body.
	 *
	 * @param Rfrac_Serving_Harness $h      Harness.
	 * @param bool                  $pretty Whether to request the `/wp-json/` form instead of `?rest_route=`.
	 *
	 * @return string The body of the miss.
	 */
	private function assert_miss_then_hit( Rfrac_Serving_Harness $h, bool $pretty = true ): string {
		$this->visit( $h, self::PRODUCTS, $pretty );
		$body = $h->miss( self::PRODUCTS );
		$this->assertNotNull( $body, 'The first request is a miss.' );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'The miss stored one entry.' );

		$products = json_decode( $body, true );
		$this->assertIsArray( $products );
		$this->assertEqualsCanonicalizing( self::PRODUCT_NAMES, wp_list_pluck( $products, 'name' ), 'The body is the product list.' );

		$this->visit( $h, self::PRODUCTS, $pretty );
		$this->assertTrue( $h->lookup(), 'The second request is a hit.' );
		$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( $body, $h->output, 'The hit is byte-identical to the live body.' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'Still one entry.' );
		$this->assertNoFailures();

		return $body;
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_the_product_list_is_a_miss_and_then_a_hit_with_the_same_body( string $path ): void {
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'Precondition: nothing is cached yet.' );

		$this->assert_miss_then_hit( $this->harness( $path ) );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_the_snapshot_fingerprint_survives_the_request_to_the_woocommerce_namespace( string $path ): void {
		$before = $this->fingerprint();
		$stored = get_option( Names::OPTION_CONFIG );
		$h      = $this->harness( $path );

		$this->assert_miss_then_hit( $h );

		$this->assertNotNull( $h->server, 'The miss ran a live request.' );
		$this->assertArrayHasKey( self::PRODUCTS, $h->server->get_routes(), 'The requested namespace is registered.' );
		$this->assertArrayNotHasKey( '/wc/v3/products', $h->server->get_routes(), 'Precondition: WooCommerce left its other namespaces unregistered.' );
		$this->assertFalse( get_transient( Config_Compiler::CHECK_TRANSIENT ), 'The periodic check is still due: the rebuild was skipped, not run.' );
		$this->assertSame( $before, $this->fingerprint(), 'The fingerprint is unchanged.' );
		$this->assertSame( $stored, get_option( Names::OPTION_CONFIG ), 'The snapshot is untouched.' );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_the_rest_route_form_is_a_miss_and_then_a_hit_with_the_same_body( string $path ): void {
		$before = $this->fingerprint();

		$this->assert_miss_then_hit( $this->harness( $path ), false );

		$this->assertSame( $before, $this->fingerprint(), 'The fingerprint is unchanged.' );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_the_cart_is_a_bypass_and_nothing_is_stored( string $path ): void {
		$h = $this->harness( $path );

		$this->visit( $h, self::CART );
		$this->assertFalse( $h->lookup(), 'The cart is never served from the cache.' );
		$this->assertSame( Bypass_Reason::DENIED_ROUTE, Request_State::decision()->reason );

		// The cart route loads the cart through WooCommerce's own init, which ran once for this process; the state
		// reset of the test case dropped it, so a shopper's request loads it again.
		wc_load_cart();
		$this->assertNotNull( $h->serve( self::CART ) );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->assertSame( Bypass_Reason::DENIED_ROUTE->value, $h->live_header( 'X-Rfrac-Cache-Reason' ) );
		}
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'Nothing is stored.' );
		$this->assertNoFailures();
	}
}
