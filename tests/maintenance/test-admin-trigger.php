<?php
/**
 * Tests for the admin-triggered maintenance.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Maintenance\Admin_Trigger;
use Rockfire\Rest_Api_Cache\Maintenance\Lock;
use Rockfire\Rest_Api_Cache\Maintenance\Maintenance;
use Rockfire\Rest_Api_Cache\Maintenance\Scheduler;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Storage\Database_Store;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Maintenance\Admin_Trigger
 */
class Test_Admin_Trigger extends Rfrac_Test_Case {

	public function set_up() {
		parent::set_up();
		delete_option( Lock::OPTION );
		delete_option( Maintenance::LAST_RUN_OPTION );
		delete_option( Admin_Trigger::LAST_ATTEMPT_OPTION );
		Scheduler::unschedule();
	}

	public function tear_down() {
		delete_option( Lock::OPTION );
		delete_option( Maintenance::LAST_RUN_OPTION );
		delete_option( Admin_Trigger::LAST_ATTEMPT_OPTION );
		Scheduler::unschedule();
		remove_all_actions( Hooks::ADMIN_PAGE_LOAD );
		parent::tear_down();
	}

	private function trigger( ?bool $cron_disabled ): Admin_Trigger {
		$repository = new Repository();
		return new Admin_Trigger( new Maintenance( $repository, new Database_Store( $repository, 0 ), Config::instance(), new Lock() ), $cron_disabled );
	}

	private function last_run_ago( int $seconds ): void {
		update_option( Maintenance::LAST_RUN_OPTION, array( 'finished_at' => time() - $seconds ), false );
	}

	private function insert_stale( int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			Rfrac_Entry_Fixtures::insert_entry(
				array(
					'state'      => 2,
					'flushed_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}
	}

	public function test_cron_disabled_and_old_last_run_runs_one_bounded_admin_pass(): void {
		$this->insert_stale( 3000 );
		$this->last_run_ago( 2 * HOUR_IN_SECONDS );

		$result = $this->trigger( true )->maybe_run();

		$this->assertNotNull( $result );
		$this->assertSame( 'admin', $result->context );
		$this->assertLessThanOrEqual( 1000, $result->deleted_stale );
		$this->assertGreaterThan( 0, $result->deleted_stale );
		$this->assertFalse( $result->complete );
	}

	public function test_recent_last_run_does_nothing(): void {
		$this->insert_stale( 10 );
		$this->last_run_ago( 10 * MINUTE_IN_SECONDS );

		$this->assertNull( $this->trigger( true )->maybe_run() );
		$this->assertSame( 10, Rfrac_Entry_Fixtures::count() );
	}

	public function test_with_cron_enabled_only_a_six_hour_old_run_triggers(): void {
		$this->insert_stale( 10 );
		$this->last_run_ago( 2 * HOUR_IN_SECONDS );
		$this->assertNull( $this->trigger( false )->maybe_run() );

		$this->last_run_ago( 7 * HOUR_IN_SECONDS );
		$result = $this->trigger( false )->maybe_run();

		$this->assertNotNull( $result );
		$this->assertSame( 10, $result->deleted_stale );
	}

	public function test_never_run_before_runs(): void {
		$this->assertNotNull( $this->trigger( false )->maybe_run() );
		$this->assertNull( $this->trigger( true )->maybe_run(), 'The run just recorded is recent.' );
	}

	public function test_maybe_run_ensures_the_event_is_scheduled(): void {
		$this->last_run_ago( 60 );

		$this->trigger( false )->maybe_run();

		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK ) );
	}

	public function test_default_cron_disabled_follows_the_constant(): void {
		$this->last_run_ago( 2 * HOUR_IN_SECONDS );
		$expected_run = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		$result = $this->trigger( null )->maybe_run();

		$this->assertSame( $expected_run, null !== $result );
	}

	public function test_admin_page_load_is_the_only_entry_point(): void {
		$trigger = $this->trigger( true );
		$trigger->register();

		$this->assertNotFalse( has_action( Hooks::ADMIN_PAGE_LOAD, array( $trigger, 'on_admin_page_load' ) ) );

		$this->assertFalse( has_action( 'admin_init', array( $trigger, 'maybe_run' ) ), 'Nothing runs on admin_init.' );
		$this->assertFalse( get_option( Maintenance::LAST_RUN_OPTION ), 'Registering alone does nothing.' );

		do_action( Hooks::ADMIN_PAGE_LOAD );
		$this->assertIsArray( get_option( Maintenance::LAST_RUN_OPTION ) );
	}

	public function test_plugin_registers_the_trigger_and_lifecycle_hooks(): void {
		$plugin = new Plugin();
		$plugin->register_maintenance_hooks();
		$hooks = array();
		foreach ( $plugin->engine_hooks() as $hook ) {
			$hooks[ $hook['hook'] ] = $hook['priority'];
		}
		$this->assertSame(
			array(
				Scheduler::HOOK          => 10,
				Scheduler::CONTINUE_HOOK => 10,
				Hooks::ADMIN_PAGE_LOAD   => 10,
				Hooks::ACTIVATE_SITE     => 10,
				Hooks::DEACTIVATE_SITE   => 10,
				'wp_initialize_site'     => 1000,
			),
			$hooks
		);

		// The booted container registered the same hooks at boot (so they exist during an activation).
		$booted = array_column( Plugin::instance()->engine_hooks(), 'hook' );
		foreach ( array_keys( $hooks ) as $hook ) {
			$this->assertContains( $hook, $booted );
		}
	}

	public function test_admin_page_load_runs_the_trigger_through_the_plugin(): void {
		$this->last_run_ago( 7 * HOUR_IN_SECONDS );
		( new Plugin() )->register_maintenance_hooks();

		do_action( Hooks::ADMIN_PAGE_LOAD );

		$this->assertIsArray( get_option( Maintenance::LAST_RUN_OPTION ) );
	}

	public function test_activation_action_schedules_and_deactivation_action_unschedules(): void {
		// Only the maintenance group: the full engine wiring would also install tables on activation.
		( new Plugin() )->register_maintenance_hooks();

		// Fire the lifecycle actions directly: Cleanup::unschedule_events() would mask a missing hook.
		do_action( Hooks::ACTIVATE_SITE );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK ) );

		wp_schedule_single_event( time() + 60, Scheduler::CONTINUE_HOOK );
		do_action( Hooks::DEACTIVATE_SITE );

		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK ) );
		$this->assertFalse( wp_next_scheduled( Scheduler::CONTINUE_HOOK ) );
	}

	public function test_lock_skipped_run_is_not_retried_on_every_page_load(): void {
		$this->insert_stale( 5 );
		$this->last_run_ago( 7 * HOUR_IN_SECONDS );
		( new Lock() )->acquire( 300 );

		$first = $this->trigger( false )->maybe_run();

		$this->assertNotNull( $first );
		$this->assertTrue( $first->skipped );
		$this->assertNull( $this->trigger( false )->maybe_run(), 'The attempt is recorded, so the next page load does not retry.' );
		$this->assertSame( 5, Rfrac_Entry_Fixtures::count() );
	}

	public function test_retry_after_a_failed_attempt_follows_the_same_window(): void {
		$this->last_run_ago( 8 * HOUR_IN_SECONDS );
		update_option( Admin_Trigger::LAST_ATTEMPT_OPTION, time() - 7 * HOUR_IN_SECONDS, false );

		$this->assertNotNull( $this->trigger( false )->maybe_run() );
		$this->assertEqualsWithDelta( time(), (int) get_option( Admin_Trigger::LAST_ATTEMPT_OPTION ), 5 );
	}
}
