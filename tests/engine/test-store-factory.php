<?php
/**
 * Tests for the store factory.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Storage\Database_Store;
use Rockfire\Rest_Api_Cache\Storage\Object_Cache_Store;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Factory;

/**
 * @covers \Rockfire\Rest_Api_Cache\Storage\Store_Factory
 */
class Test_Store_Factory extends Rfrac_Test_Case {

	/**
	 * Original external object cache flag.
	 *
	 * @var bool
	 */
	private bool $ext_cache;

	public function set_up() {
		parent::set_up();
		$this->ext_cache = (bool) wp_using_ext_object_cache();
	}

	public function tear_down() {
		wp_using_ext_object_cache( $this->ext_cache );
		parent::tear_down();
	}

	public function test_explicit_modes_resolve_to_themselves(): void {
		$this->assertSame( 'database', Store_Factory::resolve_mode( 'database' ) );
		$this->assertSame( 'object_cache', Store_Factory::resolve_mode( 'object_cache' ) );
		$this->assertSame( 'database', Store_Factory::resolve_mode( 'bogus' ) );
	}

	public function test_auto_follows_external_object_cache(): void {
		wp_using_ext_object_cache( false );
		$this->assertSame( 'database', Store_Factory::resolve_mode( 'auto' ) );
		wp_using_ext_object_cache( true );
		$this->assertSame( 'object_cache', Store_Factory::resolve_mode( 'auto' ) );
	}

	public function test_modes_return_the_matching_store(): void {
		$cases = array(
			array( 'auto', true, Object_Cache_Store::class, 'object_cache' ),
			array( 'auto', false, Database_Store::class, 'database' ),
			array( 'database', true, Database_Store::class, 'database' ),
			array( 'object_cache', false, Object_Cache_Store::class, 'object_cache' ),
			array( 'bogus', true, Database_Store::class, 'database' ),
		);
		foreach ( $cases as $case ) {
			wp_using_ext_object_cache( $case[1] );
			$store = Store_Factory::for_mode( $case[0], new Repository(), 10 );
			$this->assertInstanceOf( $case[2], $store, $case[0] );
			$this->assertSame( $case[3], $store->name(), $case[0] );
		}
	}
}
