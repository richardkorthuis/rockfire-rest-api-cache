<?php
/**
 * Multisite tests for maintenance scheduling.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Install\Activator;
use Rockfire\Rest_Api_Cache\Install\Deactivator;
use Rockfire\Rest_Api_Cache\Maintenance\Scheduler;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Maintenance\Scheduler
 * @group multisite
 */
class Test_Maintenance_Multisite extends Rfrac_Test_Case {

	/**
	 * Original network-active plugins.
	 *
	 * @var mixed
	 */
	private $sitewide;

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run via composer test-multisite.' );
		}
		parent::set_up();
		// The booted container's hooks are detached; wire only the maintenance group (the full engine would install tables).
		( new Plugin() )->register_maintenance_hooks();
		$this->sitewide = get_site_option( 'active_sitewide_plugins', array() );
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			$_SERVER['REQUEST_URI'] = '/';
		}
	}

	public function tear_down() {
		update_site_option( 'active_sitewide_plugins', $this->sitewide );
		$mu = WPMU_PLUGIN_DIR . '/rfrac-early-cache.php';
		if ( file_exists( $mu ) ) {
			unlink( $mu );
		}
		parent::tear_down();
	}

	private function network_activate_flag( bool $active ): void {
		$plugins = (array) $this->sitewide;
		unset( $plugins[ plugin_basename( RFRAC_FILE ) ] );
		if ( $active ) {
			$plugins[ plugin_basename( RFRAC_FILE ) ] = time();
		}
		update_site_option( 'active_sitewide_plugins', $plugins );
	}

	private function scheduled_on( int $blog_id ): bool {
		switch_to_blog( $blog_id );
		$scheduled = false !== wp_next_scheduled( Scheduler::HOOK );
		restore_current_blog();
		return $scheduled;
	}

	public function test_new_site_gets_the_event_when_network_active(): void {
		$this->network_activate_flag( true );
		$before = get_current_blog_id();

		$blog_id = (int) self::factory()->blog->create();

		$this->assertTrue( $this->scheduled_on( $blog_id ) );
		$this->assertSame( $before, get_current_blog_id() );
		$this->assertFalse( ms_is_switched() );
	}

	public function test_new_site_gets_nothing_when_not_network_active(): void {
		$this->network_activate_flag( false );

		$blog_id = (int) self::factory()->blog->create();

		$this->assertFalse( $this->scheduled_on( $blog_id ) );
	}

	public function test_network_activation_and_deactivation_cover_every_site(): void {
		$blogs = array( get_current_blog_id(), (int) self::factory()->blog->create(), (int) self::factory()->blog->create() );

		Activator::activate( true );
		foreach ( $blogs as $id ) {
			$this->assertTrue( $this->scheduled_on( $id ), "site $id" );
		}

		Deactivator::deactivate( true );
		foreach ( $blogs as $id ) {
			$this->assertFalse( $this->scheduled_on( $id ), "site $id" );
		}
	}
}
