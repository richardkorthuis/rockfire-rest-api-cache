<?php
/**
 * Tests for Cleanup — unscheduling of prefixed cron events.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Install\Cleanup;
use Rockfire\Rest_Api_Cache\Install\Deactivator;

/**
 * @covers \Rockfire\Rest_Api_Cache\Install\Cleanup
 * @covers \Rockfire\Rest_Api_Cache\Install\Deactivator
 */
class Test_Cleanup extends WP_UnitTestCase {

	public function test_unschedule_events_removes_only_prefixed_hooks_and_returns_count() {
		wp_schedule_event( time() + 100, 'hourly', 'rfrac_cleanup_deleted_caches' ); // A hook of the removed legacy engine: still swept by prefix.
		wp_schedule_event( time() + 100, 'daily', 'rfrac_regenerate_cron' );
		wp_schedule_single_event( time() + 100, 'rfrac_custom_test' );
		wp_schedule_single_event( time() + 100, 'other_plugin_event' );

		$this->assertSame( 3, Cleanup::unschedule_events() );

		$this->assertFalse( wp_next_scheduled( 'rfrac_cleanup_deleted_caches' ) );
		$this->assertFalse( wp_next_scheduled( 'rfrac_regenerate_cron' ) );
		$this->assertFalse( wp_next_scheduled( 'rfrac_custom_test' ) );
		$this->assertNotFalse( wp_next_scheduled( 'other_plugin_event' ) );
	}

	public function test_deactivate_unschedules_prefixed_events_and_keeps_others() {
		wp_schedule_event( time() + 100, 'hourly', 'rfrac_cleanup_deleted_caches' );
		wp_schedule_event( time() + 100, 'daily', 'rfrac_regenerate_cron' );
		wp_schedule_single_event( time() + 100, 'rfrac_custom_test' );
		wp_schedule_single_event( time() + 100, 'other_plugin_event' );

		Deactivator::deactivate();

		$this->assertFalse( wp_next_scheduled( 'rfrac_cleanup_deleted_caches' ) );
		$this->assertFalse( wp_next_scheduled( 'rfrac_regenerate_cron' ) );
		$this->assertFalse( wp_next_scheduled( 'rfrac_custom_test' ) );
		$this->assertNotFalse( wp_next_scheduled( 'other_plugin_event' ) );
	}

	public function test_same_hook_scheduled_multiple_times_counts_once() {
		wp_schedule_single_event( time() + 100, 'rfrac_x', [ 1 ] );
		wp_schedule_single_event( time() + 200, 'rfrac_x', [ 2 ] );

		$this->assertSame( 1, Cleanup::unschedule_events() );
		$this->assertFalse( wp_next_scheduled( 'rfrac_x', [ 1 ] ) );
		$this->assertFalse( wp_next_scheduled( 'rfrac_x', [ 2 ] ) );
	}

	public function test_empty_cron_array_returns_zero() {
		update_option( 'cron', [] );

		$this->assertSame( 0, Cleanup::unschedule_events() );
	}

	public function test_missing_cron_option_returns_zero() {
		delete_option( 'cron' );

		$this->assertSame( 0, Cleanup::unschedule_events() );
	}
}
