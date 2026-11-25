<?php
/**
 * Tests for the detector of the original plugin.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Legacy\Detector;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Detector
 */
class Test_Legacy_Detector extends WP_UnitTestCase {

	const OWN      = 'rockfire-rest-api-cache/rockfire-rest-api-cache.php';
	const ORIGINAL = 'wp-rest-cache/wp-rest-cache.php';

	public function set_up() {
		parent::set_up();
		delete_option( 'wp_rest_cache_database_version' );
		update_option( 'active_plugins', array( self::OWN ) );
		Coexistence_Guard::reset();
	}

	public function tear_down() {
		delete_option( 'wp_rest_cache_database_version' );
		Coexistence_Guard::reset();
		parent::tear_down();
	}

	public function test_nothing_is_present_without_the_original_and_its_leftovers() {
		$this->assertFalse( Detector::original_active() );
		$this->assertFalse( Detector::has_leftovers() );
		$this->assertFalse( Detector::present() );
	}

	public function test_leftovers_are_detected_from_the_database_version_option() {
		update_option( 'wp_rest_cache_database_version', '2026.1.0' );

		$this->assertTrue( Detector::has_leftovers() );
		$this->assertTrue( Detector::present() );
		$this->assertFalse( Detector::original_active() );
	}

	public function test_an_active_original_is_detected() {
		update_option( 'active_plugins', array( self::ORIGINAL, self::OWN ) );
		Coexistence_Guard::reset();

		$this->assertTrue( Detector::original_active() );
		$this->assertFalse( Detector::has_leftovers() );
		$this->assertTrue( Detector::present() );
	}

	public function test_an_original_that_is_active_and_has_leftovers_is_present() {
		update_option( 'active_plugins', array( self::ORIGINAL, self::OWN ) );
		update_option( 'wp_rest_cache_database_version', '2026.1.0' );
		Coexistence_Guard::reset();

		$this->assertTrue( Detector::original_active() );
		$this->assertTrue( Detector::has_leftovers() );
		$this->assertTrue( Detector::present() );
	}
}
