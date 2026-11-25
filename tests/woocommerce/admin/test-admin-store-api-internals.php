<?php
/**
 * Pins, against the real wp-admin boot, that the discovery path of the plugin runs the unmocked WooCommerce Store API
 * initialisation exactly once per request: across containers, on repeated discovery, and when the Store API was
 * already initialised the way WooCommerce does it.
 *
 * WordPress restores the hooks after every test, so a Store API initialisation of one test, and the session handler
 * callback it leaves hooked, never reaches the next one.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\StoreApi\StoreApi;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Admin_Store_Api;

/**
 * @coversNothing
 */
class Test_Admin_Store_Api_Internals extends Rfrac_Wc_Admin_Test_Case {

	/**
	 * How many times the Store API was initialised in this request.
	 *
	 * Every `StoreApi::init()` call hooks a new closure of its own on `woocommerce_blocks_pre_get_routes_from_namespace`
	 * (and two on `rest_api_init`), so the closures defined by `StoreApi` on that action, at any priority, count the
	 * calls. The session handler callback of the shared Authentication instance does not: `WP_Hook` keys it by that
	 * instance, so a second call overwrites it and a count of it stays 1.
	 *
	 * @return int
	 */
	private function store_api_init_count(): int {
		global $wp_filter;

		if ( ! isset( $wp_filter['woocommerce_blocks_pre_get_routes_from_namespace'] ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $wp_filter['woocommerce_blocks_pre_get_routes_from_namespace']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( $function instanceof Closure && StoreApi::class === ( new ReflectionFunction( $function ) )->getClosureScopeClass()?->getName() ) {
					++$count;
				}
			}
		}

		return $count;
	}

	public function test_the_admin_boot_leaves_the_store_api_uninitialised_before_any_discovery() {
		$this->assertTrue( is_admin() );
		$this->assertSame( 0, $this->store_api_init_count() );
		$this->assertFalse( Admin_Store_Api::store_api_initialised() );
	}

	public function test_the_required_woocommerce_internals_exist_in_the_installed_woocommerce() {
		foreach ( Admin_Store_Api::REQUIRED as list( $class, $method ) ) {
			$this->assertTrue( method_exists( $class, $method ), $class . '::' . $method . ' no longer exists in the installed WooCommerce.' );
		}
	}

	public function test_the_detector_woocommerce_internals_exist_in_the_installed_woocommerce() {
		foreach ( Admin_Store_Api::DETECTOR_REQUIRED as list( $class, $method ) ) {
			$this->assertTrue( method_exists( $class, $method ), $class . '::' . $method . ' no longer exists in the installed WooCommerce.' );
		}
	}

	public function test_the_init_count_sees_every_store_api_initialisation() {
		$store_api = Package::container()->get( StoreApi::class );
		$this->assertInstanceOf( StoreApi::class, $store_api );

		$store_api->init();
		$this->assertSame( 1, $this->store_api_init_count() );

		$store_api->init();
		$this->assertSame( 2, $this->store_api_init_count() );
	}

	public function test_one_container_initialises_the_store_api_once_on_discovery() {
		$this->wire_plugin();

		$server = $this->discovery_server();

		$this->assertArrayHasKey( $this->store_route( 'products' ), $server->get_routes() );
		$this->assertSame( 1, $this->store_api_init_count() );
		$this->assertNoFailures();
	}

	public function test_two_containers_and_a_repeated_discovery_initialise_the_store_api_once() {
		$this->wire_plugin();
		$this->wire_plugin();

		$this->discovery_server();
		$this->assertSame( 1, $this->store_api_init_count() );

		// The server is built again: `rest_api_init` has not fired, so both components reach their guards.
		$this->reset_rest_request();
		do_action( Hooks::DISCOVERY_SERVER );

		$this->assertSame( 1, $this->store_api_init_count() );
		$this->assertNoFailures();
	}

	public function test_a_component_that_already_ran_does_not_initialise_the_store_api_again() {
		$component = new Admin_Store_Api( null, static fn(): bool => false );

		$component->before_discovery();
		$this->assertSame( 1, $this->store_api_init_count() );

		$this->assertSame( 0, did_action( 'rest_api_init' ) );
		$component->before_discovery();
		$this->assertSame( 1, $this->store_api_init_count() );
		$this->assertNoFailures();
	}

	public function test_discovery_does_not_initialise_a_store_api_that_was_already_initialised() {
		$store_api = Package::container()->get( StoreApi::class );
		$this->assertInstanceOf( StoreApi::class, $store_api );
		$store_api->init();
		$this->assertSame( 1, $this->store_api_init_count() );
		$this->wire_plugin();

		$server = $this->discovery_server();

		$this->assertSame( 1, $this->store_api_init_count() );
		$this->assertArrayHasKey( $this->store_route( 'products' ), $server->get_routes() );
		$this->assertNoFailures();
	}
}
