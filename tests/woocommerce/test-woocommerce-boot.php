<?php
/**
 * Smoke test for the WooCommerce suite — proves tests/bootstrap-woocommerce.php boots a real WooCommerce
 * next to the plugin, with the HPOS mode selected by RFRAC_WC_HPOS.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Automattic\WooCommerce\Utilities\OrderUtil;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @coversNothing
 */
class Test_WooCommerce_Boot extends WP_UnitTestCase {

	public function tear_down() {
		unset( $GLOBALS['wp_rest_server'] );

		parent::tear_down();
	}

	public function test_woocommerce_is_loaded_at_the_pinned_minimum_version() {
		$this->assertTrue( class_exists( 'WooCommerce' ) );
		$this->assertTrue( version_compare( WC()->version, '11.2.0', '>=' ), 'WooCommerce ' . WC()->version );
	}

	public function test_the_plugin_loads_next_to_woocommerce() {
		$this->assertInstanceOf( Plugin::class, Plugin::instance() );
	}

	public function test_hpos_usage_follows_the_environment() {
		$this->assertSame( RFRAC_WC_HPOS, OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	public function test_the_hpos_and_product_lookup_tables_exist() {
		global $wpdb;

		foreach ( array( 'wc_orders', 'wc_product_meta_lookup' ) as $table ) {
			$this->assertSame(
				$wpdb->prefix . $table,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $table ) ) ),
				"The {$table} table exists."
			);
		}
	}

	public function test_the_store_api_catalog_routes_are_registered() {
		unset( $GLOBALS['wp_rest_server'] );

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/wc/store/v1/products', $routes );
		$this->assertArrayHasKey( '/wc/store/products', $routes );
	}
}
