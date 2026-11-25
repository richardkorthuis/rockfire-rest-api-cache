<?php
/**
 * Tests for the maintenance lock.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Maintenance\Lock;

/**
 * @covers \Rockfire\Rest_Api_Cache\Maintenance\Lock
 */
class Test_Lock extends Rfrac_Test_Case {

	public function set_up() {
		parent::set_up();
		delete_option( Lock::OPTION );
	}

	public function tear_down() {
		delete_option( Lock::OPTION );
		parent::tear_down();
	}

	public function test_acquire_stores_the_expiry_and_blocks_others(): void {
		$first  = new Lock();
		$second = new Lock();

		$this->assertTrue( $first->acquire( 300 ) );
		$this->assertEqualsWithDelta( time() + 300, (int) get_option( Lock::OPTION ), 5 );
		$this->assertFalse( $second->acquire( 300 ) );
	}

	public function test_release_frees_the_lock(): void {
		$first = new Lock();
		$first->acquire( 300 );
		$first->release();

		$this->assertFalse( get_option( Lock::OPTION ) );
		$this->assertTrue( ( new Lock() )->acquire( 300 ) );
	}

	public function test_release_without_holding_does_not_remove_a_foreign_lock(): void {
		( new Lock() )->acquire( 300 );

		( new Lock() )->release();

		$this->assertNotFalse( get_option( Lock::OPTION ) );
	}

	public function test_release_does_not_delete_a_lock_taken_over_by_another_holder(): void {
		$first = new Lock();
		$first->acquire( 300 );
		update_option( Lock::OPTION, time() + 999, false );

		$first->release();

		$this->assertEqualsWithDelta( time() + 999, (int) get_option( Lock::OPTION ), 5 );
	}

	public function test_expired_lock_is_taken_over(): void {
		add_option( Lock::OPTION, time() - 5, '', false );
		$lock = new Lock();

		$this->assertTrue( $lock->acquire( 300 ) );
		$this->assertGreaterThan( time(), (int) get_option( Lock::OPTION ) );
		$this->assertFalse( ( new Lock() )->acquire( 300 ) );
	}

	public function test_lock_is_an_option_not_a_transient(): void {
		( new Lock() )->acquire( 300 );

		$this->assertSame( 'rfrac_maintenance_lock', Lock::OPTION );
		$this->assertFalse( get_transient( 'rfrac_maintenance_lock' ) );
	}
}
