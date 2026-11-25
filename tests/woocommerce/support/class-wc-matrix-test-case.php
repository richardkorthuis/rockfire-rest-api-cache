<?php
/**
 * Shared base of the WooCommerce invalidation matrix tests.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module;
use Rockfire\Rest_Api_Cache\Invalidation\Option_Events;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * The invalidation matrix base (see {@see Rfrac_Matrix_Test_Case}) with WooCommerce fixtures, a clean WooCommerce
 * state at the start and the end of every test and a freshly wired WooCommerce module once a test is armed.
 *
 * Build the fixtures before {@see self::arm()}, so building them queues nothing.
 *
 * The booted container wires its WooCommerce module once, when the plugin boots, and the engine test base detaches
 * that wiring for every test; a container never wires its module twice. So {@see self::arm()} sets the booted module
 * aside, wires a new one together with the engine hooks (the Store API tagger and the WooCommerce listeners are live,
 * and the compile sees the module's seams) and {@see self::tear_down()} puts the booted module back.
 */
abstract class Rfrac_Wc_Matrix_Test_Case extends Rfrac_Matrix_Test_Case {

	use Rfrac_Wc_Test_Helpers;

	/**
	 * The module of the booted container, while {@see self::arm()} has wired a fresh one in its place.
	 *
	 * @var Module|null
	 */
	private ?Module $booted_module = null;

	/**
	 * Whether {@see self::arm()} replaced the booted container's module in this test.
	 *
	 * @var bool
	 */
	private bool $module_replaced = false;

	public function set_up() {
		parent::set_up();
		$this->reset_wc_state();
	}

	public function tear_down() {
		$this->reset_wc_state();
		if ( $this->module_replaced ) {
			( new ReflectionProperty( Plugin::class, 'woocommerce' ) )->setValue( Plugin::instance(), $this->booted_module );
			$this->booted_module   = null;
			$this->module_replaced = false;
			$this->reset_option_map();
		}
		parent::tear_down();
	}

	/**
	 * Wire a fresh WooCommerce module into the booted container, then arm the engine on top of it.
	 *
	 * @param string $store `database` or `object_cache`.
	 *
	 * @return void
	 */
	protected function arm( string $store = 'database' ): void {
		$plugin   = Plugin::instance();
		$property = new ReflectionProperty( Plugin::class, 'woocommerce' );
		if ( ! $this->module_replaced ) {
			$booted                = $property->getValue( $plugin );
			$this->booted_module   = $booted instanceof Module ? $booted : null;
			$this->module_replaced = true;
		}
		$property->setValue( $plugin, null );
		$plugin->register_integrations();
		$this->assertInstanceOf( Module::class, $plugin->woocommerce(), 'WooCommerce is loaded and its module is wired.' );

		parent::arm( $store );
		$this->reset_option_map();
	}

	/**
	 * Drop the option map the option listener of the booted container built on an earlier option write, so the next
	 * write builds it again, through the filters of the moment (the fresh module's among them).
	 *
	 * @return void
	 */
	private function reset_option_map(): void {
		( new ReflectionProperty( Option_Events::class, 'map' ) )->setValue( Plugin::instance()->listener( Option_Events::class ), null );
	}
}
