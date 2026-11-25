<?php
/**
 * Tests for the maintenance scheduler.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Maintenance\Maintenance;
use Rockfire\Rest_Api_Cache\Maintenance\Scheduler;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Maintenance\Scheduler
 */
class Test_Scheduler extends Rfrac_Test_Case {

	public function set_up() {
		parent::set_up();
		Scheduler::unschedule();
	}

	public function tear_down() {
		Scheduler::unschedule();
		parent::tear_down();
	}

	public function test_schedule_creates_one_hourly_event_an_hour_from_now(): void {
		Scheduler::schedule();
		Scheduler::schedule();
		Scheduler::ensure_scheduled();

		$next = wp_next_scheduled( Scheduler::HOOK );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $next, 5 );
		$this->assertSame( 'hourly', wp_get_schedule( Scheduler::HOOK ) );
		$count = 0;
		foreach ( _get_cron_array() as $events ) {
			$count += isset( $events[ Scheduler::HOOK ] ) ? count( $events[ Scheduler::HOOK ] ) : 0;
		}
		$this->assertSame( 1, $count );
	}

	public function test_ensure_scheduled_creates_a_missing_event(): void {
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK ) );

		Scheduler::ensure_scheduled();

		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK ) );
	}

	public function test_unschedule_clears_both_hooks(): void {
		Scheduler::schedule();
		wp_schedule_single_event( time() + 60, Scheduler::CONTINUE_HOOK );

		Scheduler::unschedule();

		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK ) );
		$this->assertFalse( wp_next_scheduled( Scheduler::CONTINUE_HOOK ) );
	}

	public function test_cron_hooks_run_maintenance_through_the_plugin(): void {
		$booted = array_column( Plugin::instance()->engine_hooks(), 'hook' );
		$this->assertContains( Scheduler::HOOK, $booted );
		$this->assertContains( Scheduler::CONTINUE_HOOK, $booted );

		( new Plugin() )->register_maintenance_hooks();
		delete_option( Maintenance::LAST_RUN_OPTION );
		do_action( Scheduler::HOOK );
		$this->assertIsArray( get_option( Maintenance::LAST_RUN_OPTION ) );
	}
}
