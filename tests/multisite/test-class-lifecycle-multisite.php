<?php
/**
 * Multisite lifecycle tests: Sites::each, network-wide activation and deactivation.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Install\Activator;
use Rockfire\Rest_Api_Cache\Install\Deactivator;
use Rockfire\Rest_Api_Cache\Install\Sites;

/**
 * @covers \Rockfire\Rest_Api_Cache\Install\Sites
 * @covers \Rockfire\Rest_Api_Cache\Install\Activator
 * @covers \Rockfire\Rest_Api_Cache\Install\Deactivator
 * @group multisite
 */
class Test_Lifecycle_Multisite extends WP_UnitTestCase {

	use Rfrac_Drops_Site_Tables;

	/** @var int[] Current site plus two created sites. */
	private $sites = [];

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test — run via `composer test-multisite`.' );
		}
		parent::set_up();
		$this->sites   = [ get_current_blog_id() ];
		$this->sites[] = (int) self::factory()->blog->create();
		$this->sites[] = (int) self::factory()->blog->create();
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			$_SERVER['REQUEST_URI'] = '/';
		}
	}

	public function tear_down() {
		$mu = WPMU_PLUGIN_DIR . '/rfrac-early-cache.php';
		if ( file_exists( $mu ) ) {
			unlink( $mu );
		}
		parent::tear_down();
		$this->drop_created_site_tables();
	}

	private function schedule_on_all_sites() {
		foreach ( $this->sites as $id ) {
			switch_to_blog( $id );
			wp_schedule_single_event( time() + 100, 'rfrac_x' );
			restore_current_blog();
		}
	}

	private function has_event( int $id ): bool {
		switch_to_blog( $id );
		$has = false !== wp_next_scheduled( 'rfrac_x' );
		restore_current_blog();
		return $has;
	}

	public function test_each_visits_every_site_once_and_restores_blog() {
		$before  = get_current_blog_id();
		$visited = [];
		Sites::each(
			function () use ( &$visited ) {
				$visited[] = get_current_blog_id();
			}
		);

		foreach ( $this->sites as $id ) {
			$this->assertContains( $id, $visited );
		}
		$this->assertSame( count( $visited ), count( array_unique( $visited ) ) );
		$this->assertSame( $before, get_current_blog_id() );
	}

	public function test_each_pages_through_more_sites_than_one_batch() {
		$count  = count( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) );
		$needed = Sites::BATCH + 1 - $count;
		for ( $i = 0; $i < $needed; $i++ ) {
			self::factory()->blog->create();
		}

		$visited = [];
		Sites::each(
			function () use ( &$visited ) {
				$visited[] = get_current_blog_id();
			}
		);

		$this->assertGreaterThan( Sites::BATCH, count( $visited ) );
		$this->assertSame( count( $visited ), count( array_unique( $visited ) ) );
	}

	public function test_each_restores_blog_when_callback_throws() {
		$before = get_current_blog_id();
		try {
			Sites::each(
				function () {
					throw new RuntimeException( 'boom' );
				}
			);
			$this->fail( 'Exception expected' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertSame( $before, get_current_blog_id() );
		$this->assertFalse( ms_is_switched() );
	}

	public function test_network_activation_fires_activate_site_for_each_site() {
		$seen  = [];
		$fired = 0;
		add_action(
			'rfrac/activate_site',
			function ( $blog_id ) use ( &$seen ) {
				$seen[] = $blog_id;
			}
		);
		add_action(
			'rfrac/activated',
			function ( $network_wide ) use ( &$fired ) {
				if ( true === $network_wide ) {
					++$fired;
				}
			}
		);
		$before = get_current_blog_id();

		Activator::activate( true );

		$this->assertGreaterThanOrEqual( 3, count( array_unique( $seen ) ) );
		foreach ( $this->sites as $id ) {
			$this->assertContains( $id, $seen );
		}
		$this->assertSame( 1, $fired );
		$this->assertSame( $before, get_current_blog_id() );
	}

	public function test_single_site_activation_fires_activate_site_once() {
		$seen = [];
		add_action(
			'rfrac/activate_site',
			function ( $blog_id ) use ( &$seen ) {
				$seen[] = $blog_id;
			}
		);

		Activator::activate( false );

		$this->assertSame( [ get_current_blog_id() ], $seen );
	}

	public function test_network_deactivation_clears_all_sites() {
		$this->schedule_on_all_sites();
		$fired = [];
		add_action(
			'rfrac/deactivate_site',
			function ( $blog_id ) use ( &$fired ) {
				$fired[] = $blog_id;
			}
		);
		$before = get_current_blog_id();

		Deactivator::deactivate( true );

		foreach ( $this->sites as $id ) {
			$this->assertFalse( $this->has_event( $id ), "site $id still has its event" );
			$this->assertContains( $id, $fired );
		}
		$this->assertSame( $before, get_current_blog_id() );
	}

	public function test_non_network_deactivation_clears_only_current_site() {
		$this->schedule_on_all_sites();

		Deactivator::deactivate( false );

		$this->assertFalse( $this->has_event( $this->sites[0] ) );
		$this->assertTrue( $this->has_event( $this->sites[1] ) );
		$this->assertTrue( $this->has_event( $this->sites[2] ) );
	}
}
