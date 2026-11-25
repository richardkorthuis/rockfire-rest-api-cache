<?php
/**
 * Tests for the Hook_Subscriber_Trait contract of register().
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;

/**
 * @covers \Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait
 */
class Test_Hook_Subscriber_Trait extends WP_UnitTestCase {

	/**
	 * A listener with a plain entry and an entry with bound arguments, both naming existing methods.
	 *
	 * @return object
	 */
	private function valid_listener(): object {
		return new class() {

			use Hook_Subscriber_Trait;

			/**
			 * What the callbacks received.
			 *
			 * @var array<int,array<int,mixed>>
			 */
			public array $calls = array();

			public static function hooks(): array {
				return array(
					array( 'rfrac_test_trait_plain', 'on_plain', 10, 1 ),
					array( 'rfrac_test_trait_bound', 'on_bound', 20, 2, array( 'first', 'second' ) ),
				);
			}

			public function on_plain( $value ) {
				$this->calls[] = array( $value );
				return $value;
			}

			public function on_bound( ...$args ) {
				$this->calls[] = $args;
				return $args[2];
			}
		};
	}

	/**
	 * A listener whose plain entry names a missing method, after an entry that is valid.
	 *
	 * @return object
	 */
	private function listener_with_missing_plain_method(): object {
		return new class() {

			use Hook_Subscriber_Trait;

			public static function hooks(): array {
				return array(
					array( 'rfrac_test_trait_valid', 'on_valid', 10, 1 ),
					array( 'rfrac_test_trait_missing', 'on_mistyped', 10, 1 ),
				);
			}

			public function on_valid( $value ) {
				return $value;
			}
		};
	}

	/**
	 * A listener whose entry with bound arguments names a missing method, after an entry that is valid.
	 *
	 * @return object
	 */
	private function listener_with_missing_bound_method(): object {
		return new class() {

			use Hook_Subscriber_Trait;

			public static function hooks(): array {
				return array(
					array( 'rfrac_test_trait_valid', 'on_valid', 10, 1 ),
					array( 'rfrac_test_trait_missing', 'on_mistyped', 10, 2, array( 'bound' ) ),
				);
			}

			public function on_valid( $value ) {
				return $value;
			}
		};
	}

	public function test_register_hooks_every_entry_of_hooks(): void {
		$listener = $this->valid_listener();

		$listener->register();

		$this->assertSame( 10, has_filter( 'rfrac_test_trait_plain', array( $listener, 'on_plain' ) ) );
		$this->assertNotFalse( has_filter( 'rfrac_test_trait_bound' ) );
		$this->assertSame( 'value', apply_filters( 'rfrac_test_trait_plain', 'value' ) );
		$this->assertSame( 'value', apply_filters( 'rfrac_test_trait_bound', 'value', 'ignored' ) );
		$this->assertSame(
			array(
				array( 'value' ),
				array( 'first', 'second', 'value', 'ignored' ),
			),
			$listener->calls
		);
	}

	public function test_register_throws_for_a_plain_entry_naming_a_missing_method(): void {
		$listener = $this->listener_with_missing_plain_method();

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/rfrac_test_trait_missing.*on_mistyped/' ); // Names the hook and the method.
		$listener->register();
	}

	public function test_register_leaves_no_hook_registered_for_a_missing_plain_method(): void {
		$listener = $this->listener_with_missing_plain_method();

		try {
			$listener->register();
			$this->fail( 'register() must throw for a method that does not exist.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertFalse( has_filter( 'rfrac_test_trait_valid' ), 'The valid entry before the bad one must not stay registered.' );
			$this->assertFalse( has_filter( 'rfrac_test_trait_missing' ) );
		}
	}

	public function test_register_throws_for_an_entry_with_bound_arguments_naming_a_missing_method(): void {
		$listener = $this->listener_with_missing_bound_method();

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'on_mistyped' );
		$listener->register();
	}

	public function test_register_leaves_no_hook_registered_for_a_missing_bound_method(): void {
		$listener = $this->listener_with_missing_bound_method();

		try {
			$listener->register();
			$this->fail( 'register() must throw for a method that does not exist.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertFalse( has_filter( 'rfrac_test_trait_valid' ), 'The valid entry before the bad one must not stay registered.' );
			$this->assertFalse( has_filter( 'rfrac_test_trait_missing' ) );
		}
	}
}
