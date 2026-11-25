<?php
/**
 * Tests of the WooCommerce compatibility declaration: the HPOS (custom order tables) and cart/checkout
 * blocks features, and the supported WooCommerce version of the plugin header.
 *
 * The suite runs once with HPOS off and once with HPOS on (`RFRAC_WC_HPOS`); the declaration is the
 * same in both modes.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module
 */
class Test_WooCommerce_Compatibility extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		// WooCommerce resolves the plugin of a declaration against the installed plugins. The suite loads the
		// plugin from the repository, outside the plugins directory, so list it as an installed plugin.
		wp_cache_set(
			'plugins',
			array(
				'' => array(
					plugin_basename( RFRAC_FILE ) => array( 'Name' => 'Rockfire – REST API Cache' ),
				),
			),
			'plugins'
		);
	}

	/**
	 * The compatibility WooCommerce holds for this plugin.
	 *
	 * @return array{compatible:string[],incompatible:string[]}
	 */
	private function declared(): array {
		/**
		 * The feature controller.
		 *
		 * @var FeaturesController $controller
		 */
		$controller = wc_get_container()->get( FeaturesController::class );

		/**
		 * The features the plugin declared.
		 *
		 * @var array{compatible:string[],incompatible:string[]} $declared
		 */
		$declared = $controller->get_compatible_features_for_plugin( plugin_basename( RFRAC_FILE ) );

		return $declared;
	}

	public function test_registering_the_module_adds_one_before_woocommerce_init_hook(): void {
		$plugin = new Plugin();
		$plugin->register_integrations();

		$this->assertInstanceOf( Module::class, $plugin->woocommerce() );

		$hooks = array_values(
			array_filter(
				$plugin->engine_hooks(),
				static fn( array $hook ): bool => 'before_woocommerce_init' === $hook['hook']
			)
		);

		// A declaration outside of the hook is a "doing it wrong" in WooCommerce, which fails this test.
		$this->assertCount( 1, $hooks );
		$this->assertSame( 10, $hooks[0]['priority'] );
		$this->assertSame( 0, $hooks[0]['args'] );
	}

	public function test_the_module_declares_both_features_compatible_when_before_woocommerce_init_fires(): void {
		$plugin = new Plugin();
		$plugin->register_integrations();

		do_action( 'before_woocommerce_init' );

		$declared = $this->declared();
		foreach ( array( 'custom_order_tables', 'cart_checkout_blocks' ) as $feature ) {
			$this->assertContains( $feature, $declared['compatible'], "{$feature} is declared compatible." );
			$this->assertNotContains( $feature, $declared['incompatible'], "{$feature} is not declared incompatible." );
		}
	}

	public function test_the_header_tested_up_to_equals_the_running_woocommerce_major_minor(): void {
		$data = get_file_data( RFRAC_FILE, array( 'tested' => 'WC tested up to' ) );

		$this->assertSame( implode( '.', array_slice( explode( '.', WC_VERSION ), 0, 2 ) ), $data['tested'] );
	}
}
