<?php
/**
 * Tests for the migrator.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Schema\Migrator;
use Rockfire\Rest_Api_Cache\Schema\Schema;

/**
 * @covers \Rockfire\Rest_Api_Cache\Schema\Migrator
 */
class Test_Migrator extends Rfrac_Test_Case {

	/**
	 * Whether a table (real or temporary) is queryable.
	 *
	 * @param string $table Full table name.
	 *
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$result   = $wpdb->query( "SELECT 1 FROM `{$table}` LIMIT 0" ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( $suppress );
		return false !== $result;
	}

	/**
	 * Remove the WP test suite's rewriting of CREATE/DROP TABLE into temporary tables.
	 */
	private function allow_real_ddl(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Recreate the real tables and restore the committed option state so later tests are unaffected.
	 *
	 * DDL commits implicitly, so rows written before it (e.g. the lock) outlive the test's rollback.
	 */
	public function tear_down() {
		// Drop the failure-simulating query filters so the schema restore below really creates the tables.
		remove_all_filters( 'query' );
		$this->restore_schema_state();
		parent::tear_down();
	}

	public function test_no_queries_when_version_is_current(): void {
		update_option( Names::OPTION_DB_VERSION, Schema::VERSION, true );
		wp_load_alloptions();

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$migrator = new Migrator();
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertFalse( $migrator->maybe_migrate() );
		}
		$recorder->stop();

		$this->assertSame( array(), $recorder->matching( '/./' ) );
	}

	public function test_version_option_is_autoloaded(): void {
		$this->allow_real_ddl();
		delete_option( Names::OPTION_DB_VERSION );
		( new Migrator() )->maybe_migrate();
		$this->assertArrayHasKey( Names::OPTION_DB_VERSION, wp_load_alloptions( true ) );
	}

	public function test_missing_option_installs_tables_and_fires_hook_once(): void {
		global $wpdb;
		$this->allow_real_ddl();
		( new Migrator() )->drop_tables();
		delete_option( Names::OPTION_DB_VERSION );
		$this->assertFalse( $this->table_exists( $wpdb->prefix . Names::TABLE_ENTRIES ) );

		$fired = array();
		add_action(
			Hooks::MIGRATED,
			static function ( $from, $to ) use ( &$fired ) {
				$fired[] = array( $from, $to );
			},
			10,
			2
		);

		$this->assertTrue( ( new Migrator() )->maybe_migrate() );

		$this->assertTrue( $this->table_exists( $wpdb->prefix . Names::TABLE_ENTRIES ) );
		$this->assertTrue( $this->table_exists( $wpdb->prefix . Names::TABLE_TAGS ) );
		$this->assertSame( 1, (int) get_option( Names::OPTION_DB_VERSION ) );
		$this->assertSame( array( array( 0, 1 ) ), $fired );
		$this->assertFalse( get_option( Migrator::LOCK_OPTION ), 'The lock is released.' );
	}

	public function test_fresh_lock_blocks_migration(): void {
		delete_option( Names::OPTION_DB_VERSION );
		add_option( Migrator::LOCK_OPTION, time() - 5, '', false );
		$fired = 0;
		add_action(
			Hooks::MIGRATED,
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$this->assertFalse( ( new Migrator() )->maybe_migrate() );

		$this->assertFalse( get_option( Names::OPTION_DB_VERSION ) );
		$this->assertSame( 0, $fired );
		$this->assertNotFalse( get_option( Migrator::LOCK_OPTION ), 'A foreign lock is left alone.' );
	}

	public function test_stale_lock_is_replaced(): void {
		$this->allow_real_ddl();
		delete_option( Names::OPTION_DB_VERSION );
		add_option( Migrator::LOCK_OPTION, time() - 120, '', false );

		$this->assertTrue( ( new Migrator() )->maybe_migrate() );

		$this->assertSame( 1, (int) get_option( Names::OPTION_DB_VERSION ) );
		$this->assertFalse( get_option( Migrator::LOCK_OPTION ) );
	}

	public function test_install_site_recreates_dropped_tables_even_when_version_is_current(): void {
		global $wpdb;
		$this->allow_real_ddl();
		$migrator = new Migrator();
		$migrator->drop_tables();
		update_option( Names::OPTION_DB_VERSION, Schema::VERSION, true );
		$this->assertFalse( $this->table_exists( $wpdb->prefix . Names::TABLE_TAGS ) );

		$migrator->install_site();

		$this->assertTrue( $this->table_exists( $wpdb->prefix . Names::TABLE_ENTRIES ) );
		$this->assertTrue( $this->table_exists( $wpdb->prefix . Names::TABLE_TAGS ) );
	}

	/**
	 * Make the plugin's CREATE TABLE statements silently do nothing.
	 */
	private function swallow_create_table(): callable {
		$filter = static function ( $query ) {
			return ( is_string( $query ) && false !== stripos( $query, 'CREATE TABLE' ) && false !== strpos( $query, 'rfrac_' ) ) ? 'SELECT 1' : $query;
		};
		add_filter( 'query', $filter, 1 );
		return $filter;
	}

	public function test_failed_create_does_not_store_version_or_fire_hook_and_is_retried(): void {
		global $wpdb;
		$this->allow_real_ddl();
		( new Migrator() )->drop_tables();
		delete_option( Names::OPTION_DB_VERSION );
		$before = did_action( Hooks::MIGRATED );
		$fired  = 0;
		add_action(
			Hooks::MIGRATED,
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		$filter = $this->swallow_create_table();

		$this->assertFalse( ( new Migrator() )->maybe_migrate() );

		$this->assertFalse( get_option( Names::OPTION_DB_VERSION ), 'The version is not stored.' );
		$this->assertSame( 0, $fired );
		$this->assertSame( $before, did_action( Hooks::MIGRATED ) );
		$this->assertFalse( get_option( Migrator::LOCK_OPTION ), 'The lock is released.' );
		$this->assertNotFalse( get_option( Migrator::ERROR_OPTION ) );
		$this->assertSame( 'migration', \Rockfire\Rest_Api_Cache\Failure_Recorder::last()['context'] ?? null );
		delete_option( \Rockfire\Rest_Api_Cache\Failure_Recorder::OPTION );

		remove_filter( 'query', $filter, 1 );
		$this->assertTrue( ( new Migrator() )->maybe_migrate() );
		$this->assertSame( 1, (int) get_option( Names::OPTION_DB_VERSION ) );
		$this->assertSame( 1, $fired );
		$this->assertFalse( get_option( Migrator::ERROR_OPTION ), 'The error is cleared on success.' );
		$this->assertTrue( $this->table_exists( $wpdb->prefix . Names::TABLE_TAGS ) );
	}

	public function test_install_site_returns_false_when_create_fails(): void {
		$this->allow_real_ddl();
		( new Migrator() )->drop_tables();
		$this->swallow_create_table();

		$this->assertFalse( ( new Migrator() )->install_site() );
	}

	public function test_install_site_returns_false_while_fresh_lock_is_held(): void {
		add_option( Migrator::LOCK_OPTION, time() - 5, '', false );

		$this->assertFalse( ( new Migrator() )->install_site() );
	}

	public function test_install_site_returns_true_on_success(): void {
		$this->assertTrue( ( new Migrator() )->install_site() );
	}

	public function test_throwing_migration_releases_lock_and_does_not_advance_version(): void {
		$this->allow_real_ddl();
		( new Migrator() )->drop_tables();
		delete_option( Names::OPTION_DB_VERSION );
		add_filter(
			'query',
			static function ( $query ) {
				if ( is_string( $query ) && false !== stripos( $query, 'CREATE TABLE' ) ) {
					throw new RuntimeException( 'boom' );
				}
				return $query;
			},
			1
		);
		$before = did_action( Hooks::MIGRATED );

		try {
			( new Migrator() )->maybe_migrate();
			$this->fail( 'The exception should propagate.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertFalse( get_option( Names::OPTION_DB_VERSION ) );
		$this->assertFalse( get_option( Migrator::LOCK_OPTION ), 'The lock is released.' );
		$this->assertSame( $before, did_action( Hooks::MIGRATED ) );
	}

	public function test_drop_tables_uses_current_prefix(): void {
		global $wpdb;
		$this->allow_real_ddl();
		$migrator = new Migrator();

		$migrator->drop_tables();

		$this->assertFalse( $this->table_exists( $wpdb->prefix . Names::TABLE_ENTRIES ) );
		$this->assertFalse( $this->table_exists( $wpdb->prefix . Names::TABLE_TAGS ) );
	}
}
