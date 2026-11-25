<?php
/**
 * Tests for the status service.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Install\Mu_Installer;
use Rockfire\Rest_Api_Cache\Install\Mu_Status;
use Rockfire\Rest_Api_Cache\Maintenance\Maintenance;
use Rockfire\Rest_Api_Cache\Maintenance\Scheduler;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Schema\Migrator;
use Rockfire\Rest_Api_Cache\Schema\Schema;
use Rockfire\Rest_Api_Cache\Services\Cache_Service;
use Rockfire\Rest_Api_Cache\Services\Settings_Service;
use Rockfire\Rest_Api_Cache\Services\Status;
use Rockfire\Rest_Api_Cache\Services\Status_Service;
use Rockfire\Rest_Api_Cache\Storage\Database_Store;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Services\Status_Service
 * @covers \Rockfire\Rest_Api_Cache\Services\Status
 * @covers \Rockfire\Rest_Api_Cache\Schema\Schema::tables_exist
 */
class Test_Status_Service extends Rfrac_Test_Case {

	private const BASENAME = 'rockfire-rest-api-cache/rockfire-rest-api-cache.php';

	/**
	 * Temp directories created by the test.
	 *
	 * @var string[]
	 */
	private array $dirs = array();

	/**
	 * Original external object cache flag.
	 *
	 * @var bool
	 */
	private bool $ext_cache;

	public function set_up() {
		parent::set_up();
		$this->ext_cache = (bool) wp_using_ext_object_cache();
	}

	public function tear_down() {
		wp_using_ext_object_cache( $this->ext_cache );
		foreach ( $this->dirs as $dir ) {
			foreach ( (array) glob( $dir . '/*' ) as $file ) {
				@unlink( $file );
			}
			@rmdir( $dir );
		}
		$this->dirs = array();
		delete_option( Mu_Installer::OPTION_NOTICE );
		delete_option( Migrator::ERROR_OPTION );
		delete_option( Failure_Recorder::OPTION );
		delete_option( Maintenance::LAST_RUN_OPTION );
		wp_clear_scheduled_hook( Scheduler::HOOK );
		parent::tear_down();
	}

	private function installer(): Mu_Installer {
		$dir = str_replace( '\\', '/', sys_get_temp_dir() ) . '/rfrac-status-' . uniqid();
		mkdir( $dir, 0755, true );
		$this->dirs[] = $dir;
		return new Mu_Installer( self::BASENAME, dirname( __DIR__, 2 ) . '/mu-plugin/' . Names::MU_FILE, '9.8.7', $dir );
	}

	private function service( ?Mu_Installer $installer = null ): Status_Service {
		$repository = new Repository();
		$store      = new Database_Store( $repository, 1 );
		$cache      = new Cache_Service( $repository, $store, Plugin::instance()->maintenance() );
		return new Status_Service( new Config(), $store, new Schema(), $installer ?? $this->installer(), $cache );
	}

	/**
	 * Queries that list tables.
	 *
	 * @param callable $callback Code to run.
	 *
	 * @return list<string>
	 */
	private function show_tables_queries( callable $callback ): array {
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$callback();
		$recorder->stop();
		return $recorder->matching( '/^\s*SHOW TABLES/i' );
	}

	public function test_get_never_runs_show_tables_unless_asked(): void {
		$service = $this->service();

		$queries = $this->show_tables_queries( fn() => $service->get() );
		$this->assertSame( array(), $queries );
		$this->assertNull( $service->get()->tables_present );

		$queries = $this->show_tables_queries( fn() => $service->get( true ) );
		$this->assertCount( 2, $queries ); // One per table.
		$this->assertTrue( $service->get( true )->tables_present );
	}

	public function test_tables_present_is_false_when_the_tables_are_missing(): void {
		global $wpdb;
		$prefix       = $wpdb->prefix;
		$wpdb->prefix = 'rfrac_missing_';
		try {
			$this->assertFalse( ( new Schema() )->tables_exist() );
		} finally {
			$wpdb->prefix = $prefix;
		}
		$this->assertTrue( ( new Schema() )->tables_exist() );
	}

	public function test_missing_tables_give_zero_counts_without_querying_the_stats(): void {
		global $wpdb;
		$service  = $this->service();
		$prefix   = $wpdb->prefix;
		$recorder = new Rfrac_Query_Recorder();
		$errors   = $wpdb->suppress_errors( true );
		$recorder->start();
		$wpdb->prefix = 'rfrac_missing_';
		try {
			$status = $service->get( true );
		} finally {
			$wpdb->prefix = $prefix;
			$recorder->stop();
			$wpdb->suppress_errors( $errors );
		}

		$this->assertFalse( $status->tables_present );
		$this->assertSame( 0, $status->counts['total'] );
		$this->assertSame( 0, $status->total_size );
		$this->assertSame( 0, $status->backlog );
		$this->assertSame( array(), $recorder->matching( '/COUNT\(|SUM\(/i' ) );
		$this->assertSame( '', $wpdb->last_error );
	}

	public function test_snapshot_is_memoised_per_flag(): void {
		$service = $this->service();

		$first = $service->get();
		$this->assertSame( $first, $service->get() );
		$this->assertNotSame( $first, $service->get( true ) );
		$this->assertSame( $service->get( true ), $service->get( true ) );
	}

	public function test_snapshot_fields(): void {
		$installer = $this->installer();
		$status    = $this->service( $installer )->get();

		$this->assertInstanceOf( Status::class, $status );
		$this->assertSame( RFRAC_VERSION, $status->plugin_version );
		$this->assertSame( Schema::VERSION, $status->db_version_expected );
		$this->assertSame( (int) get_option( Names::OPTION_DB_VERSION, 0 ), $status->db_version );
		$this->assertSame( $installer->mode(), $status->mu_mode );
		$this->assertSame( Mu_Status::MISSING, $status->mu_status );
		$this->assertSame( 'fallback', $status->mu_mode );
		$this->assertSame( $installer->target_path(), $status->mu_target_path );
		$this->assertSame( $installer->template_path(), $status->mu_template_path );
		$this->assertTrue( $status->mu_dir_writable );
		$this->assertSame( 'database', $status->store_type );
		$this->assertSame( 10000, $status->max_entries );
		$this->assertSame( array(), $status->locked_settings );
		$this->assertFalse( $status->coexistence_blocked );
		$this->assertNull( $status->last_maintenance );
		$this->assertNull( $status->next_maintenance );
		$this->assertNull( $status->migration_error );
		$this->assertNull( $status->last_error );
		$this->assertNull( $status->tables_present );
	}

	public function test_counts_size_and_backlog_come_from_the_cache_stats(): void {
		Rfrac_Entry_Fixtures::insert_entry();
		Rfrac_Entry_Fixtures::insert_entry( array( 'state' => 2 ) );

		$status = $this->service()->get();

		$this->assertSame( 2, $status->counts['total'] );
		$this->assertSame( $status->counts['size'], $status->total_size );
		$this->assertSame( $status->counts['flushed'] + $status->counts['expired'], $status->backlog );
		$this->assertGreaterThanOrEqual( 1, $status->backlog );
	}

	public function test_object_cache_detection_follows_the_wordpress_flag(): void {
		wp_using_ext_object_cache( true );
		$this->assertTrue( $this->service()->get()->object_cache_detected );

		wp_using_ext_object_cache( false );
		$this->assertFalse( $this->service()->get()->object_cache_detected );
	}

	public function test_maintenance_schedule_and_last_run_are_reported(): void {
		wp_schedule_event( 1900000000, 'daily', Scheduler::HOOK );
		update_option(
			Maintenance::LAST_RUN_OPTION,
			array(
				'context'     => 'cron',
				'finished_at' => 123,
			)
		);

		$status = $this->service()->get();

		$this->assertSame( 1900000000, $status->next_maintenance );
		$this->assertSame( 123, $status->last_maintenance['finished_at'] );
	}

	public function test_recorded_failures_are_reported(): void {
		update_option( Migrator::ERROR_OPTION, 'Missing tables after migration: x' );
		Failure_Recorder::record( 'migration', 'boom' );

		$status = $this->service()->get();

		$this->assertSame( 'Missing tables after migration: x', $status->migration_error );
		$this->assertSame( 'migration', $status->last_error['context'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_locked_settings_are_reported(): void {
		define( 'RFRAC_MAX_PARAMS', 5 );

		$status = $this->service()->get();

		$this->assertSame( array( 'max_params' => 'RFRAC_MAX_PARAMS' ), $status->locked_settings );
	}

	/**
	 * The WordPress test configuration defines `DISABLE_WP_CRON` as true, so the flag is true here.
	 */
	public function test_cron_disabled_follows_the_constant(): void {
		$this->assertTrue( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
		$this->assertTrue( $this->service()->get()->cron_disabled );
	}

	public function test_mu_status_reads_the_file_without_building_a_snapshot(): void {
		$installer = $this->installer();
		$service   = $this->service( $installer );
		$recorder  = new Rfrac_Query_Recorder();
		$recorder->start();

		$missing = $service->mu_status();

		$recorder->stop();
		$this->assertSame( Mu_Status::MISSING, $missing );
		$this->assertSame( array(), $recorder->matching( '/rfrac_entries/' ), 'No stats query.' );

		file_put_contents( $installer->target_path(), "<?php // old\n" );
		$this->assertSame( Mu_Status::OUTDATED, $service->mu_status() );

		$service->install_mu();
		$this->assertSame( Mu_Status::CURRENT, $service->mu_status() );
	}

	public function test_install_mu_delegates_and_refreshes_the_snapshot(): void {
		$installer = $this->installer();
		$service   = $this->service( $installer );
		$this->assertSame( Mu_Status::MISSING, $service->get()->mu_status );

		$result = $service->install_mu();

		$this->assertSame( Mu_Status::CURRENT, $result );
		$this->assertFileExists( $installer->target_path() );
		$this->assertSame( Mu_Status::CURRENT, $service->get()->mu_status );
		$this->assertSame( 'early', $service->get()->mu_mode );
	}

	public function test_install_mu_replaces_an_outdated_file(): void {
		$installer = $this->installer();
		file_put_contents( $installer->target_path(), "<?php // old\n" );
		$service = $this->service( $installer );
		$this->assertSame( Mu_Status::OUTDATED, $service->get()->mu_status );

		$this->assertSame( Mu_Status::CURRENT, $service->install_mu() );

		$this->assertSame( Mu_Status::CURRENT, $service->get()->mu_status, 'The install dropped the memoised snapshot.' );
	}

	public function test_install_mu_removes_an_unreplaceable_legacy_loader(): void {
		$installer = $this->installer();
		file_put_contents( $installer->target_path(), "<?php\n/**\n * Plugin Name: REST API Cache - Must-Use Plugin\n */\n" );
		add_filter( 'file_mod_allowed', '__return_false' );
		$service = $this->service( $installer );
		$this->assertSame( Mu_Status::OUTDATED, $service->get()->mu_status );

		$result = $service->install_mu();

		$this->assertSame( Mu_Status::MISSING, $result );
		$this->assertFileDoesNotExist( $installer->target_path() );
		$this->assertSame( Mu_Status::MISSING, $service->get()->mu_status, 'The deletion dropped the memoised snapshot.' );
	}

	public function test_a_flush_or_a_maintenance_run_in_the_same_request_refreshes_the_snapshot(): void {
		$service = $this->service();
		$id      = Rfrac_Entry_Fixtures::insert_entry();
		$this->assertSame( 1, $service->get()->counts['active'] );

		do_action( Hooks::FLUSHED, 'entries', array( (string) $id ), 1 );
		Rfrac_Entry_Fixtures::insert_entry();
		$this->assertSame( 2, $service->get()->counts['active'], 'rfrac/flushed forgot the snapshot.' );

		Rfrac_Entry_Fixtures::insert_entry();
		$this->assertSame( 2, $service->get()->counts['active'], 'Memoised again.' );
		do_action( Hooks::MAINTENANCE_COMPLETED, null );
		$this->assertSame( 3, $service->get()->counts['active'], 'rfrac/maintenance_completed forgot the snapshot.' );

		Rfrac_Entry_Fixtures::insert_entry();
		$service->forget();
		$this->assertSame( 4, $service->get()->counts['active'] );
	}

	public function test_the_container_builds_both_services_once(): void {
		$plugin = new Plugin();

		$this->assertInstanceOf( Settings_Service::class, $plugin->settings_service() );
		$this->assertSame( $plugin->settings_service(), $plugin->settings_service() );
		$this->assertInstanceOf( Status_Service::class, $plugin->status_service() );
		$this->assertSame( $plugin->status_service(), $plugin->status_service() );
		$this->assertSame( 'database', $plugin->status_service()->get()->store_type );
	}
}
