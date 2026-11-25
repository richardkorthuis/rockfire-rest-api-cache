<?php
/**
 * Tests for the Plugin bootstrap class — verifies the hook wiring stays correct.
 *
 * The plugin is loaded once by tests/bootstrap.php, which runs `Plugin::boot()` and registers
 * every callback via `register_hooks()`. These tests inspect the
 * resulting $wp_filter state — i.e. they verify production wiring at test time without
 * re-registering hooks. The engine wiring is covered by tests/engine/test-plugin-wiring.php.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Admin;
use Rockfire\Rest_Api_Cache\Admin\Admin_Bar;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Plugin
 */
class Test_Plugin extends WP_UnitTestCase {

	// ---------- Identity getters ----------

	public function test_get_plugin_name_returns_plugin_slug() {
		$plugin = new Plugin();

		$this->assertSame( 'rfrac', $plugin->get_plugin_name() );
	}

	public function test_get_version_returns_the_version_constant() {
		$plugin = new Plugin();

		$this->assertSame( RFRAC_VERSION, $plugin->get_version() );
	}

	// ---------- Plugin header ----------

	public function test_the_plugin_header_declares_the_supported_woocommerce_versions() {
		$data = get_file_data(
			RFRAC_FILE,
			[
				'wc_requires' => 'WC requires at least',
				'wc_tested'   => 'WC tested up to',
			]
		);

		$this->assertSame( '11.2', $data['wc_requires'] );
		$this->assertSame( '11.3', $data['wc_tested'] );
	}

	public function test_woocommerce_stays_optional_in_the_plugin_header() {
		$data = get_file_data( RFRAC_FILE, [ 'requires_plugins' => 'Requires Plugins' ] );

		$this->assertSame( '', $data['requires_plugins'] );
	}

	public function test_the_readme_header_has_no_woocommerce_fields() {
		$readme = (string) file_get_contents( RFRAC_DIR . 'readme.txt' );
		$header = (string) strstr( $readme, '== Description ==', true );

		$this->assertNotSame( '', $header, 'The readme has a header block.' );
		$this->assertDoesNotMatchRegularExpression( '/^\s*WC (requires at least|tested up to)\s*:/mi', $header );
	}

	// ---------- Legacy engine (removed, #48) ----------

	public function test_legacy_engine_classes_are_gone() {
		foreach ( [ 'Caching\\Caching', 'API\\Endpoint_Api', 'API\\Item_Api', 'API\\Oembed_Api', 'Controller\\Post_Controller', 'Util' ] as $class ) {
			$this->assertFalse( class_exists( 'Rockfire\\Rest_Api_Cache\\' . $class ), "$class was removed" );
		}
		$this->assertFalse( trait_exists( 'Rockfire\\Rest_Api_Cache\\Controller\\Controller_Trait' ) );
	}

	public function test_legacy_invalidation_cron_and_flush_hooks_are_not_registered() {
		foreach ( [ 'rfrac_cleanup_deleted_caches', 'rfrac_regenerate_cron' ] as $hook ) {
			$this->assertFalse( has_action( $hook ), "Legacy cron hook $hook" );
		}
		foreach ( [ 'wp_ajax_rfrac_flush_caches', 'activated_plugin', 'cli_init', 'update_option_rfrac_regenerate', 'update_option_rfrac_regenerate_interval' ] as $hook ) {
			$this->assert_no_admin_callback( $hook );
		}
	}

	// ---------- Admin hooks ----------

	public function test_admin_bar_flush_is_registered_on_boot() {
		$this->assert_filter_registered( 'admin_bar_menu', Admin_Bar::class, 'add_node' );
	}

	public function test_legacy_admin_hooks_are_gone() {
		$this->assertFalse( has_action( 'wp_ajax_rfrac_flush_caches' ) );
		$this->assertFalse( has_filter( 'rfrac/settings_panels' ) );
		foreach ( [ 'admin_init', 'admin_menu', 'set-screen-option', 'wp_before_admin_bar_render' ] as $hook ) {
			$this->assert_no_admin_callback( $hook );
		}
	}

	public function test_admin_is_not_registered_outside_wp_admin() {
		// The test bootstrap is not an admin request, so the booted container registered no Admin.
		$this->assert_no_admin_callback( 'admin_menu' );
		$this->assert_no_admin_callback( 'plugin_action_links_' . plugin_basename( RFRAC_FILE ) );
	}

	// ----- helpers -----

	/**
	 * Assert that no `[<Admin instance>, *]` callback is registered on $hook.
	 *
	 * @param string $hook Hook name.
	 */
	private function assert_no_admin_callback( $hook ) {
		global $wp_filter;
		if ( isset( $wp_filter[ $hook ] ) ) {
			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'];
					$this->assertFalse( is_array( $fn ) && $fn[0] instanceof Admin, "Legacy admin callback on {$hook}" );
				}
			}
		}
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Assert that *some* callback on $hook is `[<instance-of $class>, $method]`. WP indexes
	 * object-method callbacks by spl_object_hash, so without access to the actual instance
	 * registered by Plugin we can't use has_action() directly — we walk $wp_filter instead.
	 */
	private function assert_filter_registered( $hook, $class, $method ) {
		global $wp_filter;

		$this->assertArrayHasKey( $hook, $wp_filter, "No callbacks registered for {$hook}" );

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];
				if ( is_array( $fn ) && is_object( $fn[0] ) && $fn[0] instanceof $class && $fn[1] === $method ) {
					$this->addToAssertionCount( 1 );
					return;
				}
			}
		}

		$this->fail( "Expected an instance-callback {$class}::{$method} on hook {$hook}, none found" );
	}
}
