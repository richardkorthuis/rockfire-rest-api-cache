<?php
/**
 * Multisite tests for the Uninstaller — every site and the network tables are cleaned.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Install\Uninstaller;

/**
 * @covers \Rockfire\Rest_Api_Cache\Install\Uninstaller
 * @group multisite
 */
class Test_Uninstaller_Multisite extends WP_UnitTestCase {

	use Rfrac_Drops_Site_Tables;

	/** @var int[] */
	private $sites = [];

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test — run via `composer test-multisite`.' );
		}
		parent::set_up();
		$this->sites   = [ get_current_blog_id() ];
		$this->sites[] = (int) self::factory()->blog->create();
		$this->sites[] = (int) self::factory()->blog->create();
	}

	public function tear_down() {
		$mu = WPMU_PLUGIN_DIR . '/rfrac-early-cache.php';
		if ( file_exists( $mu ) ) {
			unlink( $mu );
		}
		parent::tear_down();
		$this->drop_created_site_tables();
	}

	public function test_run_cleans_every_site_and_network() {
		global $wpdb;
		$uid = self::factory()->user->create();

		foreach ( $this->sites as $id ) {
			switch_to_blog( $id );
			update_option( 'rfrac_settings', 1 );
			update_option( 'rfrac_db_version', '1' );
			set_transient( 'rfrac_x', 1, 3600 );
			update_option( 'other_option', 1 );
			wp_schedule_single_event( time() + 100, 'rfrac_maintenance' );
			restore_current_blog();
		}
		update_site_option( 'rfrac_network', 1 );
		update_site_option( 'other_network', 1 );
		switch_to_blog( $this->sites[1] );
		update_user_option( $uid, 'rfrac_pref', 1 );
		restore_current_blog();
		update_user_meta( $uid, 'rfrac_notices', 1 );
		switch_to_blog( $this->sites[1] );
		$site_notices_key = Notices::meta_key();
		update_user_meta( $uid, $site_notices_key, 1 );
		restore_current_blog();

		switch_to_blog( $this->sites[1] );
		$this->assertSame( '1', (string) get_user_option( 'rfrac_pref', $uid ) );
		restore_current_blog();
		$this->assertSame( '1', (string) get_user_meta( $uid, $site_notices_key, true ) );

		Uninstaller::run();

		foreach ( $this->sites as $id ) {
			switch_to_blog( $id );
			$this->assertFalse( get_option( 'rfrac_settings' ), "site $id" );
			$this->assertFalse( get_option( 'rfrac_db_version' ), "site $id" );
			$this->assertFalse( get_transient( 'rfrac_x' ), "site $id" );
			$this->assertSame( '1', (string) get_option( 'other_option' ), "site $id" );
			$this->assertFalse( wp_next_scheduled( 'rfrac_maintenance' ), "site $id" );
			$this->assertSame(
				0,
				(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'rfrac\\_%' OR option_name LIKE '\\_transient\\_%rfrac\\_%'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"site $id"
			);
			restore_current_blog();
		}

		$this->assertFalse( get_site_option( 'rfrac_network' ) );
		$this->assertSame( '1', (string) get_site_option( 'other_network' ) );
		$this->assertSame(
			0,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->sitemeta} WHERE meta_key LIKE 'rfrac\\_%'" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		switch_to_blog( $this->sites[1] );
		$this->assertFalse( get_user_option( 'rfrac_pref', $uid ) );
		restore_current_blog();
		$this->assertSame( '', get_user_meta( $uid, 'rfrac_notices', true ) );
		$this->assertSame( '', get_user_meta( $uid, $site_notices_key, true ) );
	}
}
