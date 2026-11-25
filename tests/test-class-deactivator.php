<?php
/**
 * Tests for the Deactivator — removes the MU plugin
 * file the Activator copied into wp-content/mu-plugins.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Install\Deactivator;

/**
 * @covers \Rockfire\Rest_Api_Cache\Install\Deactivator
 */
class Test_Deactivator extends WP_UnitTestCase {

	private $mu_plugin_file;

	public function set_up() {
		parent::set_up();
		$this->mu_plugin_file = WPMU_PLUGIN_DIR . '/rfrac-early-cache.php';
	}

	public function tear_down() {
		// Clean up whatever the test wrote to the mu-plugins dir (real filesystem side effect
		// — WP_UnitTestCase's transactional rollback only covers the DB).
		if ( file_exists( $this->mu_plugin_file ) ) {
			unlink( $this->mu_plugin_file );
		}
		parent::tear_down();
	}

	// ---------- MU plugin removal ----------

	public function test_deactivate_removes_the_mu_plugin_file_when_present() {
		$this->seed_mu_plugin_file();
		$this->assertFileExists( $this->mu_plugin_file, 'precondition: MU plugin file was seeded' );

		Deactivator::deactivate();

		$this->assertFileDoesNotExist( $this->mu_plugin_file );
	}

	public function test_deactivate_is_a_noop_for_the_mu_plugin_file_when_already_absent() {
		if ( file_exists( $this->mu_plugin_file ) ) {
			unlink( $this->mu_plugin_file );
		}

		// Should not error / warn even though there's nothing to delete.
		Deactivator::deactivate();

		$this->assertFileDoesNotExist( $this->mu_plugin_file );
	}

	// ----- helpers -----

	private function seed_mu_plugin_file() {
		if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
			mkdir( WPMU_PLUGIN_DIR, 0777, true );
		}
		file_put_contents( $this->mu_plugin_file, "<?php // seeded by Test_Deactivator\n" );
	}
}
