<?php
/**
 * Tests for the bounded maintenance run.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Maintenance\Lock;
use Rockfire\Rest_Api_Cache\Maintenance\Maintenance;
use Rockfire\Rest_Api_Cache\Maintenance\Maintenance_Result;
use Rockfire\Rest_Api_Cache\Maintenance\Scheduler;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Storage\Database_Store;
use Rockfire\Rest_Api_Cache\Storage\Entry_Data;
use Rockfire\Rest_Api_Cache\Storage\Object_Cache_Store;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Interface;

/**
 * @covers \Rockfire\Rest_Api_Cache\Maintenance\Maintenance
 * @covers \Rockfire\Rest_Api_Cache\Maintenance\Maintenance_Result
 */
class Test_Maintenance extends Rfrac_Test_Case {

	/**
	 * Original external object cache flag.
	 *
	 * @var bool
	 */
	private bool $ext_cache;

	public function set_up() {
		parent::set_up();
		$this->ext_cache = (bool) wp_using_ext_object_cache();
		delete_option( Lock::OPTION );
		delete_option( Maintenance::LAST_RUN_OPTION );
		Scheduler::unschedule();
	}

	public function tear_down() {
		delete_option( Lock::OPTION );
		delete_option( Maintenance::LAST_RUN_OPTION );
		Scheduler::unschedule();
		wp_using_ext_object_cache( $this->ext_cache );
		wp_cache_flush_group( 'rfrac' );
		parent::tear_down();
	}

	private function maintenance( ?Store_Interface $store = null ): Maintenance {
		$repository = new Repository();
		return new Maintenance( $repository, $store ?? new Database_Store( $repository, 0 ), Config::instance(), new Lock() );
	}

	/**
	 * Insert entries in the given state.
	 *
	 * @param int      $count  Number of entries.
	 * @param array    $fields Column overrides.
	 * @param string[] $tags   Tags.
	 *
	 * @return int[]
	 */
	private function insert( int $count, array $fields, array $tags = array() ): array {
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$ids[] = Rfrac_Entry_Fixtures::insert_entry( $fields, $tags );
		}
		return $ids;
	}

	private function flushed_fields(): array {
		return array(
			'state'      => 2,
			'flushed_at' => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	private function expired_fields(): array {
		return array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) );
	}

	/**
	 * Queries on the cache tables run by a callback.
	 *
	 * @param callable $callback Callback.
	 *
	 * @return int
	 */
	private function count_rfrac_queries( callable $callback ): int {
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$callback();
		$recorder->stop();
		return count( $recorder->matching( '/rfrac_(entries|tags)/i' ) );
	}

	public function test_cli_run_deletes_flushed_and_expired_in_few_queries(): void {
		$tags = array( 'a:1', 'b:2', 'c:3' );
		$this->insert( 1200, $this->flushed_fields(), $tags );
		$this->insert( 300, $this->expired_fields(), $tags );
		$active_ids = $this->insert( 500, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ), $tags );
		$maintenance = $this->maintenance();

		$result  = null;
		$queries = $this->count_rfrac_queries(
			function () use ( $maintenance, &$result ) {
				$result = $maintenance->run( array(), 'cli' );
			}
		);

		$this->assertInstanceOf( Maintenance_Result::class, $result );
		$this->assertSame( 1500, $result->deleted_stale );
		$this->assertSame( 0, $result->backlog_remaining );
		$this->assertSame( 500, $result->entries_after );
		$this->assertTrue( $result->complete );
		$this->assertSame( 500, Rfrac_Entry_Fixtures::count() );
		$this->assertSame( 1500, Rfrac_Entry_Fixtures::count_tags(), 'Only the tags of the surviving entries remain.' );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $active_ids[0] ) );
		$this->assertLessThanOrEqual( 13, $queries );
	}

	public function test_query_count_scales_with_batches_not_rows(): void {
		$this->insert( 150, $this->flushed_fields(), array( 'a:1', 'b:2', 'c:3' ) );
		$maintenance = $this->maintenance();

		$result  = null;
		$queries = $this->count_rfrac_queries(
			function () use ( $maintenance, &$result ) {
				$result = $maintenance->run( array(), 'cli' );
			}
		);

		$this->assertSame( 150, $result->deleted_stale );
		$this->assertLessThanOrEqual( 7, $queries );
	}

	public function test_entry_expiring_soon_is_kept(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ), array( 'a:1' ) );

		$result = $this->maintenance()->run( array(), 'cli' );

		$this->assertSame( 0, $result->deleted_stale );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count_tags() );
	}

	public function test_cap_evicts_least_recently_hit_active_entries(): void {
		update_option( Names::OPTION_SETTINGS, array( 'max_entries' => 100 ) );
		$ids = array();
		for ( $i = 0; $i < 120; $i++ ) {
			$ids[] = Rfrac_Entry_Fixtures::insert_entry( array( 'last_hit_at' => gmdate( 'Y-m-d H:i:s', time() - 10000 + $i ) ) );
		}

		$result = $this->maintenance()->run( array(), 'cli' );

		$this->assertSame( 20, $result->evicted );
		$this->assertSame( 100, $result->entries_after );
		$this->assertSame( 0, $result->deleted_stale );
		$this->assertTrue( $result->complete );
		$this->assertSame( '', Rfrac_Entry_Fixtures::get_state( $ids[0] ) );
		$this->assertSame( '', Rfrac_Entry_Fixtures::get_state( $ids[19] ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $ids[20] ) );
		$this->assertSame( 100, Rfrac_Entry_Fixtures::count() );
	}

	public function test_exhausted_budget_in_cron_is_incomplete_and_schedules_one_continuation(): void {
		$this->insert( 2000, $this->flushed_fields() );
		$maintenance = $this->maintenance();
		$tiny        = array( 'time_budget' => 0.000001 );

		$first = $maintenance->run( $tiny, 'cron' );

		$this->assertFalse( $first->complete );
		$this->assertGreaterThan( 0, $first->backlog_remaining );
		$next = wp_next_scheduled( Scheduler::CONTINUE_HOOK );
		$this->assertNotFalse( $next );
		$this->assertEqualsWithDelta( time() + 60, $next, 5 );

		$maintenance->run( $tiny, 'cron' );
		$this->assertSame( $next, wp_next_scheduled( Scheduler::CONTINUE_HOOK ) );
		$this->assertCount( 1, array_filter( array_keys( (array) _get_cron_array() ), static fn( $t ) => isset( _get_cron_array()[ $t ][ Scheduler::CONTINUE_HOOK ] ) ) );
	}

	public function test_incomplete_run_outside_cron_schedules_nothing(): void {
		$this->insert( 600, $this->flushed_fields() );

		$result = $this->maintenance()->run( array( 'max_batches' => 1 ), 'tools' );

		$this->assertFalse( $result->complete );
		$this->assertFalse( wp_next_scheduled( Scheduler::CONTINUE_HOOK ) );
	}

	public function test_default_limits_per_context(): void {
		$seen = array();
		add_filter(
			Hooks::MAINTENANCE_ARGS,
			static function ( $args, $context ) use ( &$seen ) {
				$seen[ $context ] = $args;
				return $args;
			},
			10,
			2
		);
		$maintenance = $this->maintenance();
		foreach ( array( 'cron', 'admin', 'tools', 'cli' ) as $context ) {
			$maintenance->run( array(), $context );
		}

		$this->assertSame( array( 500, 20.0, 20 ), array_values( $seen['cron'] ) );
		$this->assertSame( array( 250, 2.0, 4 ), array_values( $seen['admin'] ) );
		$this->assertSame( array( 500, 10.0, 10 ), array_values( $seen['tools'] ) );
		$this->assertSame( array( 500, 0.0, 0 ), array_values( $seen['cli'] ) );
	}

	public function test_batch_size_is_clamped(): void {
		$this->insert( 60, $this->flushed_fields() );
		$maintenance = $this->maintenance();

		$low = $maintenance->run( array( 'batch_size' => 1, 'max_batches' => 1 ), 'cli' );
		$this->assertSame( 50, $low->deleted_stale, 'A batch size below 50 is raised to 50.' );

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$maintenance->run( array( 'batch_size' => 999999 ), 'cli' );
		$recorder->stop();
		$this->assertNotEmpty( $recorder->matching( '/LIMIT 5000/' ), 'A batch size above 5000 is lowered to 5000.' );
	}

	public function test_filter_can_override_arguments(): void {
		$this->insert( 120, $this->flushed_fields() );
		add_filter(
			Hooks::MAINTENANCE_ARGS,
			static function ( $args ) {
				$args['batch_size']  = 50;
				$args['max_batches'] = 1;
				return $args;
			}
		);

		$result = $this->maintenance()->run( array(), 'cli' );

		$this->assertSame( 50, $result->deleted_stale );
		$this->assertFalse( $result->complete );
	}

	public function test_held_lock_skips_the_run(): void {
		$this->insert( 10, $this->flushed_fields() );
		$this->assertTrue( ( new Lock() )->acquire( 300 ) );
		$completed = 0;
		add_action(
			Hooks::MAINTENANCE_COMPLETED,
			static function () use ( &$completed ) {
				++$completed;
			}
		);

		$result = $this->maintenance()->run( array(), 'cli' );

		$this->assertTrue( $result->skipped );
		$this->assertSame( 0, $result->deleted_stale );
		$this->assertSame( 10, Rfrac_Entry_Fixtures::count() );
		$this->assertSame( 0, $completed );
		$this->assertFalse( get_option( Maintenance::LAST_RUN_OPTION ) );
		$this->assertNotFalse( get_option( Lock::OPTION ), 'The foreign lock is left alone.' );
	}

	public function test_expired_lock_is_taken_over_and_released_after_the_run(): void {
		$this->insert( 10, $this->flushed_fields() );
		add_option( Lock::OPTION, time() - 10, '', false );

		$result = $this->maintenance()->run( array(), 'cli' );

		$this->assertFalse( $result->skipped );
		$this->assertSame( 10, $result->deleted_stale );
		$this->assertFalse( get_option( Lock::OPTION ) );
	}

	public function test_lock_is_released_when_the_run_throws(): void {
		add_action(
			Hooks::MAINTENANCE_COMPLETED,
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);

		try {
			$this->maintenance()->run( array(), 'cli' );
			$this->fail( 'Expected exception.' );
		} catch ( RuntimeException $e ) {
			$this->assertFalse( get_option( Lock::OPTION ) );
		}
	}

	public function test_last_run_option_and_completed_action(): void {
		$this->insert( 5, $this->flushed_fields() );
		$received = array();
		add_action(
			Hooks::MAINTENANCE_COMPLETED,
			static function ( $result ) use ( &$received ) {
				$received[] = $result;
			}
		);

		$result = $this->maintenance()->run( array(), 'tools' );

		$this->assertCount( 1, $received );
		$this->assertSame( $result, $received[0] );
		$this->assertSame( $result->to_array(), get_option( Maintenance::LAST_RUN_OPTION ) );
		$this->assertSame( 'tools', $result->context );
		$this->assertSame( 5, $result->deleted_stale );
		$autoload = $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", Maintenance::LAST_RUN_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $autoload, array( 'off', 'no' ) );
	}

	public function test_object_cache_store_payload_keys_are_removed(): void {
		wp_using_ext_object_cache( true );
		wp_cache_flush_group( 'rfrac' );
		$repository = new Repository();
		$store      = new Object_Cache_Store( $repository, 0 );
		$entry      = static fn( string $key ): Entry_Data => new Entry_Data(
			$key,
			hash( 'sha256', 'u' . $key ),
			'/wp/v2/posts',
			'/wp/v2/posts',
			array( 'path' => '/wp/v2/posts' ),
			'GET',
			'{"a":1}',
			array(),
			600,
			array( 'post:1' )
		);
		$id         = $store->set( $entry( 'k-stale' ) );
		$kept       = $store->set( $entry( 'k-kept' ) );
		$generation = wp_cache_get( 'generation', 'rfrac' );
		$this->assertIsArray( wp_cache_get( $generation . ':k-stale', 'rfrac' ) );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . Names::TABLE_ENTRIES, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$result = $this->maintenance( $store )->run( array(), 'cli' );

		$this->assertSame( 1, $result->deleted_stale );
		$this->assertFalse( wp_cache_get( $generation . ':k-stale', 'rfrac' ) );
		$this->assertIsArray( wp_cache_get( $generation . ':k-kept', 'rfrac' ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( (int) $kept ) );
	}

	public function test_cap_phase_cut_by_a_limit_is_incomplete_and_continues(): void {
		update_option( Names::OPTION_SETTINGS, array( 'max_entries' => 100 ) );
		$this->insert( 120, array() );

		$result = $this->maintenance()->run( array( 'max_batches' => 1 ), 'cron' );

		$this->assertFalse( $result->complete );
		$this->assertFalse( $result->stalled );
		$this->assertSame( 0, $result->evicted );
		$this->assertSame( 120, $result->entries_after );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::CONTINUE_HOOK ) );
	}

	public function test_last_full_batch_that_clears_the_backlog_is_complete(): void {
		$this->insert( 50, $this->flushed_fields() );

		$result = $this->maintenance()->run( array( 'batch_size' => 50, 'max_batches' => 1 ), 'cron' );

		$this->assertSame( 50, $result->deleted_stale );
		$this->assertSame( 0, $result->backlog_remaining );
		$this->assertTrue( $result->complete, 'A full batch that emptied the backlog is not a cut.' );
		$this->assertFalse( wp_next_scheduled( Scheduler::CONTINUE_HOOK ), 'No no-op continuation.' );
	}

	public function test_full_batch_with_rows_left_is_incomplete(): void {
		$this->insert( 51, $this->flushed_fields() );

		$result = $this->maintenance()->run( array( 'batch_size' => 50, 'max_batches' => 1 ), 'cron' );

		$this->assertSame( 50, $result->deleted_stale );
		$this->assertSame( 1, $result->backlog_remaining );
		$this->assertFalse( $result->complete );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::CONTINUE_HOOK ) );
	}

	public function test_exact_multiple_of_the_batch_size_completes_without_a_limit(): void {
		$this->insert( 100, $this->flushed_fields() );

		$result = $this->maintenance()->run( array( 'batch_size' => 50 ), 'cli' );

		$this->assertSame( 100, $result->deleted_stale );
		$this->assertTrue( $result->complete );
	}

	public function test_skipped_run_leaves_last_run_and_continuation_untouched(): void {
		$previous = array(
			'context'     => 'cron',
			'finished_at' => time() - 100,
		);
		update_option( Maintenance::LAST_RUN_OPTION, $previous, false );
		$this->insert( 600, $this->flushed_fields() );
		( new Lock() )->acquire( 300 );

		$result = $this->maintenance()->run( array( 'max_batches' => 1 ), 'cron' );

		$this->assertTrue( $result->skipped );
		$this->assertFalse( $result->stalled );
		$this->assertSame( $previous, get_option( Maintenance::LAST_RUN_OPTION ) );
		$this->assertFalse( wp_next_scheduled( Scheduler::CONTINUE_HOOK ) );
	}

	public function test_stalled_run_does_not_chain(): void {
		update_option( Names::OPTION_SETTINGS, array( 'max_entries' => 100 ) );
		$this->insert( 120, array() );
		$store = $this->createMock( Store_Interface::class );
		$store->method( 'prune' )->willReturn( 0 );
		$store->method( 'enforce_cap' )->willReturn( 0 );

		$result = $this->maintenance( $store )->run( array(), 'cron' );

		$this->assertFalse( $result->complete );
		$this->assertTrue( $result->stalled );
		$this->assertTrue( $result->to_array()['stalled'] );
		$this->assertFalse( wp_next_scheduled( Scheduler::CONTINUE_HOOK ), 'No progress and no limit: do not chain every minute.' );
	}

	public function test_continuation_is_scheduled_even_when_a_listener_throws(): void {
		$this->insert( 600, $this->flushed_fields() );
		add_action(
			Hooks::MAINTENANCE_COMPLETED,
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);

		try {
			$this->maintenance()->run( array( 'max_batches' => 1 ), 'cron' );
			$this->fail( 'Expected exception.' );
		} catch ( RuntimeException $e ) {
			$this->assertNotFalse( wp_next_scheduled( Scheduler::CONTINUE_HOOK ) );
			$this->assertFalse( get_option( Lock::OPTION ) );
		}
	}

	public function test_continuation_chain_runs_to_completion_under_simulated_wp_cron(): void {
		$this->insert( 1200, $this->flushed_fields() );
		add_filter(
			Hooks::MAINTENANCE_ARGS,
			static function ( $args ) {
				$args['batch_size']  = 500;
				$args['max_batches'] = 1;
				return $args;
			}
		);

		( new Plugin() )->register_maintenance_hooks();
		do_action( Scheduler::HOOK );
		$runs = 1;
		while ( false !== wp_next_scheduled( Scheduler::CONTINUE_HOOK ) && $runs < 10 ) {
			// What wp-cron does: the event is consumed, then the hook fires.
			wp_clear_scheduled_hook( Scheduler::CONTINUE_HOOK );
			do_action( Scheduler::CONTINUE_HOOK );
			++$runs;
		}

		$this->assertSame( 3, $runs );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count() );
		$this->assertTrue( get_option( Maintenance::LAST_RUN_OPTION )['complete'] );
		$this->assertFalse( wp_next_scheduled( Scheduler::CONTINUE_HOOK ) );
	}
}
