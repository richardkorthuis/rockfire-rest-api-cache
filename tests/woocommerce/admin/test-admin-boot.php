<?php
/**
 * Smoke test for the admin-context WooCommerce suite — proves tests/bootstrap-woocommerce-admin.php boots WordPress,
 * WooCommerce and the plugin as a wp-admin request, and that the unmocked Store API initialisation of the plugin runs
 * on that boot.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Automattic\WooCommerce\Utilities\OrderUtil;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @coversNothing
 */
class Test_Admin_Boot extends Rfrac_Wc_Admin_Test_Case {

	public function test_the_suite_boots_as_an_admin_request_from_the_constant() {
		$this->assertTrue( defined( 'WP_ADMIN' ), 'WP_ADMIN is defined.' );
		$this->assertTrue( constant( 'WP_ADMIN' ), 'WP_ADMIN is true.' );
		$this->assertTrue( is_admin() );
		$this->assertFalse( isset( $GLOBALS['current_screen'] ), 'No screen is set: the constant alone makes the request an admin one.' );
	}

	public function test_the_wp_admin_code_is_loaded() {
		$this->assertTrue( class_exists( 'WC_Admin', false ), 'WooCommerce loaded its admin code at boot.' );
		$this->assertTrue( function_exists( 'wp_terms_checklist' ), 'The wp-admin includes are loaded.' );
	}

	public function test_the_plugin_wires_its_admin_and_the_discovery_hooks_at_boot() {
		$plugin = Plugin::instance();

		$this->assertNotFalse( has_action( 'admin_menu', array( $plugin->admin(), 'register_menu' ) ), 'The admin menu is registered.' );

		$hooks = array_column( $plugin->engine_hooks(), 'hook' );
		$this->assertContains( 'debug_information', $hooks, 'The Site Health debug information is registered.' );
		$this->assertContains( Hooks::DISCOVERY_SERVER, $hooks, 'The Store API initialisation answers the discovery action.' );
	}

	public function test_woocommerce_leaves_the_store_api_uninitialised_in_an_admin_request() {
		$this->reset_rest_request();

		$server = rest_get_server();

		$this->assertSame( array(), $this->catalog_routes_on( $server ) );
		$this->assertArrayNotHasKey( $this->store_route( 'products' ), $server->get_routes() );
	}

	public function test_the_discovery_path_exposes_every_catalog_route() {
		$this->wire_plugin();
		$this->reset_rest_request();

		$server = $this->discovery_server();

		$expected = Store_Routes::CATALOG;
		sort( $expected );
		$this->assertCount( 26, $expected );
		$this->assertSame( $expected, $this->catalog_routes_on( $server ) );
		$this->assertNoFailures();
	}

	public function test_the_bootstrap_leaves_no_activation_redirect_pending() {
		// The install sets this transient and the bootstrap deletes it; the test case does not delete it again, so what is
		// read here is what the bootstrap left. The stored rows are read, not get_transient(): it reports an expired
		// transient as absent, and WooCommerce sets this one for 30 seconds only, so it would pass again once the run is older.
		$this->assertFalse( get_option( '_transient__wc_activation_redirect' ), 'The setup wizard redirect is not pending.' );
		$this->assertFalse( get_option( '_transient_timeout__wc_activation_redirect' ), 'The setup wizard redirect has no timeout row left.' );
	}

	public function test_the_suite_runs_with_hpos_off() {
		$this->assertFalse( RFRAC_WC_HPOS, 'The composer script pins RFRAC_WC_HPOS=0.' );
		$this->assertFalse( OrderUtil::custom_orders_table_usage_is_enabled() );
	}
}
