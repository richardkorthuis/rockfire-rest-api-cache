<?php
/**
 * Multisite tests for the Coexistence_Guard (network-activated original plugin).
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Capability;
use Rockfire\Rest_Api_Cache\Admin\Coexistence_Notice;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;

/**
 * @covers \Rockfire\Rest_Api_Cache\Install\Coexistence_Guard
 * @covers \Rockfire\Rest_Api_Cache\Admin\Coexistence_Notice
 * @group multisite
 */
class Test_Coexistence_Guard_Multisite extends WP_UnitTestCase {

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test - run via `composer test-multisite`.' );
		}
		parent::set_up();
		Coexistence_Guard::reset();
	}

	public function tear_down() {
		set_current_screen( 'front' );
		Coexistence_Guard::reset();
		parent::tear_down();
	}

	public function test_blocked_when_original_is_network_active() {
		update_site_option( 'active_sitewide_plugins', [ 'wp-rest-cache/wp-rest-cache.php' => time() ] );
		Coexistence_Guard::reset();

		$this->assertTrue( Coexistence_Guard::is_blocked() );
	}

	public function test_blocked_when_renamed_original_is_network_active() {
		update_site_option( 'active_sitewide_plugins', [ 'wp-rest-cache-old/wp-rest-cache.php' => time() ] );
		Coexistence_Guard::reset();

		$this->assertTrue( Coexistence_Guard::is_blocked() );
	}

	public function test_not_blocked_when_only_other_plugins_are_network_active() {
		update_site_option( 'active_sitewide_plugins', [ 'foo/not-wp-rest-cache.php' => time() ] );
		Coexistence_Guard::reset();

		$this->assertFalse( Coexistence_Guard::is_blocked() );
	}

	public function test_blocked_when_original_is_active_on_current_site() {
		update_option( 'active_plugins', [ 'wp-rest-cache/wp-rest-cache.php' ] );
		Coexistence_Guard::reset();

		$this->assertTrue( Coexistence_Guard::is_blocked() );
	}

	public function test_notice_shown_to_super_admin_on_the_network_plugins_screen_when_network_blocked() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		update_site_option( 'active_sitewide_plugins', [ 'wp-rest-cache/wp-rest-cache.php' => time() ] );
		Coexistence_Guard::reset();
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );
		set_current_screen( 'plugins-network' );

		ob_start();
		( new Coexistence_Notice( new Capability() ) )->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice notice-error', $html );
		$this->assertStringContainsString( 'name="target" value="network"', $html );
	}
}
