<?php
/**
 * Tests for the Uninstaller — full prefix-based removal on a single site.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Dismissals;
use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Install\Uninstaller;
use Rockfire\Rest_Api_Cache\Legacy\Finding;
use Rockfire\Rest_Api_Cache\Legacy\Findings;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Schema\Migrator;

/**
 * @covers \Rockfire\Rest_Api_Cache\Install\Uninstaller
 */
class Test_Uninstaller extends Rfrac_Test_Case {

	/** @var string */
	private $mu_file;

	/** @var int|null */
	private $user_id = null;

	public function set_up() {
		parent::set_up();
		$this->mu_file = WPMU_PLUGIN_DIR . '/rfrac-early-cache.php';

		// The bootstrap installed the real engine tables: work on real DDL and start without them.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		( new Migrator() )->drop_tables();
	}

	public function tear_down() {
		if ( file_exists( $this->mu_file ) ) {
			unlink( $this->mu_file );
		}

		// DDL commits implicitly: delete everything these tests created (or the code under test may have left)
		// before restoring the bootstrap state, so nothing accumulates in the shared test DB.
		foreach ( array( 'rfrac_settings', 'rfrac_config', 'rfrac_maintenance_last_run', 'rfrac_legacy_findings', 'other_option', 'rfracx_keep', 'my_rfrac_keep' ) as $option ) {
			delete_option( $option );
		}
		delete_transient( 'rfrac_x' );
		wp_clear_scheduled_hook( 'rfrac_maintenance' );
		wp_clear_scheduled_hook( 'other_plugin_event' );
		if ( null !== $this->user_id ) {
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			delete_user_option( $this->user_id, 'rfrac_pref' );
			foreach ( array( 'rfrac_notices', Notices::meta_key(), Dismissals::meta_key(), 'rfrac_entries_per_page', 'other_meta' ) as $meta_key ) {
				delete_user_meta( $this->user_id, $meta_key );
			}
			wp_delete_user( $this->user_id );
			$this->user_id = null;
		}
		// The tests create stub tables: drop them so install_site() recreates the real schema.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		( new Migrator() )->drop_tables();
		$this->restore_schema_state();
		parent::tear_down();
	}

	/**
	 * Whether a table answers a SELECT (errors suppressed).
	 */
	private function table_exists( string $name ): bool {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->get_var( 'SELECT 1 FROM `' . $wpdb->prefix . $name . '` LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$error = $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );
		return '' === $error;
	}

	private function create_table( string $name ) {
		global $wpdb;
		$wpdb->query( 'CREATE TABLE `' . $wpdb->prefix . $name . '` ( id int NOT NULL )' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	public function test_run_removes_all_prefixed_data_and_keeps_controls() {
		global $wpdb;
		$this->create_table( 'rfrac_entries' );
		$this->create_table( 'rfrac_tags' );

		update_option( 'rfrac_settings', [ 'a' => 1 ] );
		update_option( 'rfrac_config', [ 'b' => 1 ] );
		update_option( 'rfrac_db_version', '1' );
		update_option( 'rfrac_maintenance_last_run', 123 );
		set_transient( 'rfrac_x', 1, 3600 );
		update_option( 'other_option', 1 );
		update_option( 'rfracx_keep', 1 );
		update_option( 'my_rfrac_keep', 1 );

		$uid           = self::factory()->user->create();
		$this->user_id = $uid;
		update_user_meta( $uid, 'rfrac_notices', 1 );
		update_user_meta( $uid, Dismissals::meta_key(), array( Dismissals::COEXISTENCE => 1 ) );
		update_user_meta( $uid, 'rfrac_entries_per_page', 20 );
		update_user_meta( $uid, 'other_meta', 1 );
		update_user_option( $uid, 'rfrac_pref', 1 );

		wp_schedule_single_event( time() + 100, 'rfrac_maintenance' );
		wp_schedule_single_event( time() + 100, 'other_plugin_event' );

		file_put_contents( $this->mu_file, "<?php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->assertTrue( $this->table_exists( 'rfrac_entries' ) );

		Uninstaller::run();

		$this->assertFalse( $this->table_exists( 'rfrac_entries' ) );
		$this->assertFalse( $this->table_exists( 'rfrac_tags' ) );

		$this->assertFalse( get_option( 'rfrac_settings' ) );
		$this->assertFalse( get_option( 'rfrac_config' ) );
		$this->assertFalse( get_option( 'rfrac_db_version' ) );
		$this->assertFalse( get_option( 'rfrac_maintenance_last_run' ) );
		$this->assertFalse( get_transient( 'rfrac_x' ) );
		$this->assertSame(
			0,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%rfrac\\_x'" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$this->assertSame( '', get_user_meta( $uid, 'rfrac_notices', true ) );
		$this->assertSame( '', get_user_meta( $uid, Dismissals::meta_key(), true ), 'The dismissed notices are removed.' );
		$this->assertSame(
			0,
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", '%' . $wpdb->esc_like( Dismissals::META ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$this->assertSame( '', get_user_meta( $uid, 'rfrac_entries_per_page', true ) );
		$this->assertFalse( get_user_option( 'rfrac_pref', $uid ) );

		$this->assertFalse( wp_next_scheduled( 'rfrac_maintenance' ) );
		$this->assertFileDoesNotExist( $this->mu_file );

		$this->assertSame( '1', (string) get_option( 'other_option' ) );
		$this->assertSame( '1', (string) get_option( 'rfracx_keep' ) );
		$this->assertSame( '1', (string) get_option( 'my_rfrac_keep' ) );
		$this->assertSame( '1', (string) get_user_meta( $uid, 'other_meta', true ) );
		$this->assertNotFalse( wp_next_scheduled( 'other_plugin_event' ) );
	}

	public function test_run_removes_the_non_autoloaded_legacy_findings() {
		global $wpdb;
		( new Findings() )->replace( Findings::GROUP_IMPORT, array( new Finding( Finding::SKIPPED, 'a_code', 'a_subject', 'A message.' ) ) );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Names::OPTION_LEGACY_FINDINGS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $autoload, array( 'no', 'off' ) );

		Uninstaller::run();

		$this->assertFalse( get_option( Names::OPTION_LEGACY_FINDINGS ) );
		$this->assertSame( array(), ( new Findings() )->all() );
	}

	public function test_run_with_nothing_installed_is_silent() {
		global $wpdb;
		$this->assertFileDoesNotExist( $this->mu_file );

		Uninstaller::run();
		Uninstaller::run();

		$this->assertSame( '', $wpdb->last_error );
		$this->assertFalse( $this->table_exists( 'rfrac_entries' ) );
	}

	public function test_run_completes_whatever_the_object_cache_supports() {
		wp_cache_set( 'k', 'v', 'rfrac' );

		Uninstaller::run();

		if ( wp_cache_supports( 'flush_group' ) ) {
			$this->assertFalse( wp_cache_get( 'k', 'rfrac' ) );
		} else {
			$this->assertSame( 'v', wp_cache_get( 'k', 'rfrac' ) );
		}
	}
}
