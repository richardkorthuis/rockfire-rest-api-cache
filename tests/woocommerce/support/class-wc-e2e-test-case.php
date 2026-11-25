<?php
/**
 * Shared base of the WooCommerce end-to-end serving tests.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

/**
 * The end-to-end serving base (both serving paths, see {@see Rfrac_E2e_Test_Case}) with WooCommerce fixtures
 * and a clean WooCommerce state at the start and the end of every test.
 *
 * The WooCommerce module is wired by the engine hooks of every container on its own, so nothing is set up here.
 */
abstract class Rfrac_Wc_E2e_Test_Case extends Rfrac_E2e_Test_Case {

	use Rfrac_Wc_Test_Helpers;

	public function set_up() {
		parent::set_up();
		$this->reset_wc_state();
	}

	public function tear_down() {
		$this->reset_wc_state();
		parent::tear_down();
	}
}
