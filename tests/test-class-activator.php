<?php
/**
 * Tests for the Activator — after the legacy engine removal (#48) activation seeds no legacy
 * options and schedules no legacy cron events (the engine set-up is covered by
 * tests/engine/test-lifecycle-wiring.php).
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Install\Activator;
use Rockfire\Rest_Api_Cache\Install\Cleanup;
use Rockfire\Rest_Api_Cache\Maintenance\Scheduler;

/**
 * @covers \Rockfire\Rest_Api_Cache\Install\Activator
 */
class Test_Activator extends WP_UnitTestCase {

	/** @var string[] Options the legacy activator seeded. */
	private const LEGACY_OPTIONS = [
		'rfrac_allowed_endpoints',
		'rfrac_disallowed_endpoints',
		'rfrac_rest_prefix',
		'rfrac_cacheable_request_headers',
		'rfrac_allowed_request_methods',
		'rfrac_uncached_parameters',
		'rfrac_hit_recording',
		'rfrac_regenerate',
		'rfrac_regenerate_interval',
		'rfrac_regenerate_number',
		'rfrac_memcache_used',
	];

	public function set_up() {
		parent::set_up();
		foreach ( self::LEGACY_OPTIONS as $option ) {
			delete_option( $option );
		}
		Cleanup::unschedule_events();
	}

	public function tear_down() {
		// Activation installs the must-use file (a real filesystem side effect).
		$mu_file = WPMU_PLUGIN_DIR . '/rfrac-early-cache.php';
		if ( file_exists( $mu_file ) ) {
			unlink( $mu_file );
		}
		Cleanup::unschedule_events();
		parent::tear_down();
	}

	public function test_activate_seeds_no_legacy_options() {
		Activator::activate();

		foreach ( self::LEGACY_OPTIONS as $option ) {
			$this->assertFalse( get_option( $option ), "$option is a legacy option" );
		}
	}

	public function test_activate_schedules_only_the_maintenance_event() {
		Activator::activate();

		$hooks = [];
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( 0 === strpos( (string) $hook, 'rfrac_' ) ) {
					$hooks[] = $hook;
				}
			}
		}

		$this->assertSame( [ Scheduler::HOOK ], $hooks );
		$this->assertSame( 'rfrac_maintenance', Scheduler::HOOK );
		$this->assertFalse( wp_next_scheduled( 'rfrac_regenerate_cron' ) );
		$this->assertFalse( wp_next_scheduled( 'rfrac_cleanup_deleted_caches' ) );
	}

	public function test_activate_fires_the_site_and_activation_actions() {
		$site      = new MockAction();
		$activated = new MockAction();
		add_action( 'rfrac/activate_site', [ $site, 'action' ] );
		add_action( 'rfrac/activated', [ $activated, 'action' ] );

		Activator::activate();

		$this->assertSame( 1, $site->get_call_count() );
		$this->assertSame( [ get_current_blog_id() ], $site->get_args()[0] );
		$this->assertSame( 1, $activated->get_call_count() );
		$this->assertSame( [ false ], $activated->get_args()[0] );
	}
}
