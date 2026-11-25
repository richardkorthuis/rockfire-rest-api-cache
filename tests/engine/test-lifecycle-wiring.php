<?php
/**
 * Tests for the activation and deactivation wiring of the plugin container (#26).
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Install\Activator;
use Rockfire\Rest_Api_Cache\Install\Deactivator;
use Rockfire\Rest_Api_Cache\Install\Mu_Status;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Schema\Schema;

/**
 * @covers \Rockfire\Rest_Api_Cache\Plugin
 * @covers \Rockfire\Rest_Api_Cache\Install\Activator
 * @covers \Rockfire\Rest_Api_Cache\Install\Deactivator
 */
class Test_Lifecycle_Wiring extends Rfrac_Test_Case {

	/**
	 * Fresh container whose engine hooks are registered for this test.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Path of the must-use file.
	 *
	 * @var string
	 */
	private string $mu_file;

	public function set_up() {
		parent::set_up();
		$this->plugin = new Plugin();
		$this->plugin->register_engine_hooks();
		$this->mu_file = $this->plugin->mu_installer()->target_path();
		$this->remove_mu_file();
	}

	public function tear_down() {
		$this->remove_mu_file();
		parent::tear_down();
	}

	private function remove_mu_file(): void {
		if ( file_exists( $this->mu_file ) ) {
			unlink( $this->mu_file );
		}
	}

	/**
	 * Make the filesystem method non-direct, so the must-use file cannot be written.
	 */
	private function make_mu_dir_unwritable(): void {
		add_filter( 'filesystem_method', static fn() => 'ftpext' );
	}

	public function test_fresh_activation_installs_schema_config_and_must_use_file(): void {
		delete_option( Names::OPTION_DB_VERSION );
		delete_option( Names::OPTION_CONFIG );

		Activator::activate();

		$this->assertSame( Schema::VERSION, (int) get_option( Names::OPTION_DB_VERSION ) );
		$this->assertSame( 1, (int) get_option( Names::OPTION_DB_VERSION ) );
		$config = Compiled_Config::load();
		$this->assertNotNull( $config );
		$this->assertTrue( $config->pending_discovery() );
		$this->assertSame( Mu_Status::CURRENT, $this->plugin->mu_installer()->status() );
	}

	public function test_single_site_activation_without_a_user_installs_the_must_use_file(): void {
		wp_set_current_user( 0 );

		Activator::activate();

		$this->assertSame( Mu_Status::CURRENT, $this->plugin->mu_installer()->status(), 'Single-site activation is not gated on the current user.' );
	}

	public function test_activation_without_a_writable_must_use_directory_leaves_it_missing(): void {
		$this->make_mu_dir_unwritable();

		Activator::activate();

		$this->assertSame( Mu_Status::MISSING, $this->plugin->mu_installer()->status() );
		$this->assertSame( 'not_direct', $this->plugin->mu_installer()->last_result()['code'] ?? null );
		$this->assertTrue( Compiled_Config::load()->pending_discovery(), 'The plugin still works in fallback mode.' );
	}

	public function test_activation_deletes_a_legacy_must_use_file_it_cannot_replace(): void {
		if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
			mkdir( WPMU_PLUGIN_DIR, 0777, true );
		}
		file_put_contents( $this->mu_file, "<?php\n/**\n * Plugin Name: REST API Cache - Must-Use Plugin\n * Version: 0.9\n */\n" );
		$this->make_mu_dir_unwritable();

		Activator::activate();

		$this->assertFileDoesNotExist( $this->mu_file );
		$this->assertSame( Mu_Status::MISSING, $this->plugin->mu_installer()->status() );
	}

	public function test_activation_keeps_an_outdated_template_file_it_cannot_replace(): void {
		if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
			mkdir( WPMU_PLUGIN_DIR, 0777, true );
		}
		file_put_contents( $this->mu_file, "<?php\n/**\n * Plugin Name: early cache\n * RFRAC Template: 0\n */\n" );
		$this->make_mu_dir_unwritable();

		Activator::activate();

		$this->assertSame( Mu_Status::OUTDATED, $this->plugin->mu_installer()->status(), 'The early server ignores a mismatching template.' );
	}

	public function test_activation_replaces_a_legacy_must_use_file(): void {
		if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
			mkdir( WPMU_PLUGIN_DIR, 0777, true );
		}
		file_put_contents( $this->mu_file, "<?php\n// legacy loader\n" );

		Activator::activate();

		$this->assertSame( Mu_Status::CURRENT, $this->plugin->mu_installer()->status() );
	}

	public function test_reactivation_flushes_stale_entries(): void {
		Activator::activate();
		$id = Rfrac_Entry_Fixtures::insert_entry();
		wp_cache_set( 'generation', 5, Names::CACHE_GROUP );

		Activator::activate();

		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
		$this->assertNotEquals( 5, wp_cache_get( 'generation', Names::CACHE_GROUP ), 'Object-cache generation bumped.' );
	}

	public function test_every_single_site_activation_sets_up_the_site(): void {
		$batch = static fn() => 1;
		add_filter( 'rfrac/network_activation_batch', $batch );

		Activator::activate();
		delete_option( Names::OPTION_CONFIG );
		Activator::activate();

		$this->assertNotNull( Compiled_Config::load(), 'The counter is reset after each activation.' );
	}

	public function test_deactivation_flushes_entries_and_removes_the_must_use_file(): void {
		Activator::activate();
		$this->assertFileExists( $this->mu_file );
		$id = Rfrac_Entry_Fixtures::insert_entry();

		Deactivator::deactivate();

		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
		$this->assertFileDoesNotExist( $this->mu_file );
	}

	public function test_deactivation_suppresses_database_errors(): void {
		global $wpdb;
		Activator::activate();
		$entries = $wpdb->prefix . Names::TABLE_ENTRIES;
		$broken  = static fn( $query ) => is_string( $query ) && 0 === stripos( ltrim( $query ), 'UPDATE' ) && false !== strpos( $query, $entries ) ? 'RFRAC INVALID SQL' : $query;
		add_filter( 'query', $broken );
		$show = $wpdb->show_errors( true );

		ob_start();
		try {
			Deactivator::deactivate();
		} finally {
			$output = (string) ob_get_clean();
			remove_filter( 'query', $broken );
			$wpdb->show_errors( $show );
		}

		$this->assertSame( '', $output );
		$this->assertFileDoesNotExist( $this->mu_file, 'Deactivation completes.' );
	}
}
