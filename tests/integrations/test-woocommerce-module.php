<?php
/**
 * Tests for the WooCommerce module skeleton and its `subscribe()` helper.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module
 */
class Test_Woocommerce_Module extends WP_UnitTestCase {

	/**
	 * Fresh container with the WooCommerce module wired, as after `woocommerce_loaded`.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	public function set_up() {
		parent::set_up();
		do_action( 'woocommerce_loaded' );
		$this->plugin = new Plugin();
		$this->plugin->register_integrations();
	}

	public function tear_down() {
		delete_option( Failure_Recorder::OPTION );
		parent::tear_down();
	}

	/**
	 * The wired module.
	 *
	 * @return Module
	 */
	private function module(): Module {
		$module = $this->plugin->woocommerce();
		$this->assertInstanceOf( Module::class, $module );
		return $module;
	}

	/**
	 * A listener declaring two hooks, one of them with bound arguments.
	 *
	 * @return object
	 */
	private function listener(): object {
		return new class() {
			use Hook_Subscriber_Trait;

			/**
			 * Recorded calls.
			 *
			 * @var list<array{0:string,1:list<mixed>}>
			 */
			public array $calls = array();

			/**
			 * Hooks.
			 *
			 * @return array<int,array<int,mixed>>
			 *
			 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4?:list<mixed>}>
			 */
			public static function hooks(): array {
				return array(
					array( 'rfrac_test/wc_plain', 'on_plain', 10, 1 ),
					array( 'rfrac_test/wc_bound', 'on_bound', 7, 2, array( 'bound-a', 'bound-b' ) ),
					array( 'rfrac_test/wc_throws', 'on_throw', 10, 1 ),
				);
			}

			/**
			 * Plain entry.
			 *
			 * @param mixed $value Value.
			 *
			 * @return mixed
			 */
			public function on_plain( $value ) {
				$this->calls[] = array( 'plain', array( $value ) );
				return $value;
			}

			/**
			 * Entry with bound arguments.
			 *
			 * @param mixed $a     First bound argument.
			 * @param mixed $b     Second bound argument.
			 * @param mixed $value Value.
			 *
			 * @return mixed
			 */
			public function on_bound( $a, $b, $value ) {
				$this->calls[] = array( 'bound', array( $a, $b, $value ) );
				return $value . '!';
			}

			/**
			 * Failing entry.
			 *
			 * @param mixed $value Value.
			 *
			 * @return mixed
			 *
			 * @throws RuntimeException Always.
			 */
			public function on_throw( $value ) {
				throw new RuntimeException( 'listener failed' );
			}
		};
	}

	public function test_subscribe_registers_every_hook_entry_as_an_engine_hook(): void {
		$before   = count( $this->plugin->engine_hooks() );
		$listener = $this->listener();

		$this->module()->subscribe( $listener );

		$added = array_slice( $this->plugin->engine_hooks(), $before );
		$this->assertSame( array( 'rfrac_test/wc_plain', 'rfrac_test/wc_bound', 'rfrac_test/wc_throws' ), array_column( $added, 'hook' ) );
		$this->assertSame( array( 10, 7, 10 ), array_column( $added, 'priority' ) );
		$this->assertSame( array( 1, 2, 1 ), array_column( $added, 'args' ) );
		foreach ( $added as $hook ) {
			$this->assertSame( $hook['priority'], has_filter( $hook['hook'], $hook['callback'] ), $hook['hook'] . ' is registered with WordPress' );
		}
	}

	public function test_subscribed_hooks_fire_the_listener_with_the_bound_arguments(): void {
		$listener = $this->listener();
		$this->module()->subscribe( $listener );

		$this->assertSame( 'x', apply_filters( 'rfrac_test/wc_plain', 'x' ) );
		$this->assertSame( 'y!', apply_filters( 'rfrac_test/wc_bound', 'y', 'ignored' ) );

		$this->assertSame(
			array(
				array( 'plain', array( 'x' ) ),
				array( 'bound', array( 'bound-a', 'bound-b', 'y' ) ),
			),
			$listener->calls
		);
	}

	public function test_a_failing_listener_is_guarded_like_every_engine_hook(): void {
		$this->module()->subscribe( $this->listener() );

		$this->assertSame( 'kept', apply_filters( 'rfrac_test/wc_throws', 'kept' ), 'The first argument is returned unchanged.' );
		$this->assertSame( 'hook:rfrac_test/wc_throws', Failure_Recorder::last()['context'] ?? null );
	}

	public function test_subscribe_rejects_an_object_without_hooks(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->module()->subscribe( new stdClass() );
	}

	public function test_subscribe_rejects_a_component_that_does_not_use_the_hook_subscriber_trait(): void {
		$before = count( $this->plugin->engine_hooks() );
		$bare   = new class() {
			/**
			 * Hooks, in the right shape but declared without the trait.
			 *
			 * @return list<array<int,mixed>>
			 */
			public static function hooks(): array {
				return array( array( 'rfrac_test/wc_bare', 'on_bare', 10, 1 ) );
			}
		};

		try {
			$this->module()->subscribe( $bare );
			$this->fail( 'A component without Hook_Subscriber_Trait must be rejected.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'Hook_Subscriber_Trait', $e->getMessage() );
		}

		$this->assertCount( $before, $this->plugin->engine_hooks() );
		$this->assertFalse( has_filter( 'rfrac_test/wc_bare' ) );
	}

	/**
	 * A component whose `hooks()` entry breaks the `Hook_Subscriber_Trait::hooks()` contract.
	 *
	 * @return array<string,array{0:list<mixed>}>
	 */
	public static function entries_breaking_the_contract(): array {
		return array(
			'two elements only'          => array( array( array( 'rfrac_test/wc_bad', 'on_plain' ) ) ),
			'no priority'                => array( array( array( 'rfrac_test/wc_bad', 'on_plain', null, 1 ) ) ),
			'priority as a string'       => array( array( array( 'rfrac_test/wc_bad', 'on_plain', '10', 1 ) ) ),
			'accepted args as a string'  => array( array( array( 'rfrac_test/wc_bad', 'on_plain', 10, '1' ) ) ),
			'hook name not a string'     => array( array( array( 12, 'on_plain', 10, 1 ) ) ),
			'method not a string'        => array( array( array( 'rfrac_test/wc_bad', array( 'on_plain' ), 10, 1 ) ) ),
			'bound arguments not a list' => array( array( array( 'rfrac_test/wc_bad', 'on_plain', 10, 1, 'bound' ) ) ),
			'bound arguments keyed'      => array( array( array( 'rfrac_test/wc_bad', 'on_plain', 10, 1, array( 'key' => 'bound' ) ) ) ),
			'entry not an array'         => array( array( 'rfrac_test/wc_bad' ) ),
			'bad entry after a good one' => array( array( array( 'rfrac_test/wc_good', 'on_plain', 10, 1 ), array( 'rfrac_test/wc_bad', 'on_plain' ) ) ),
		);
	}

	/**
	 * @dataProvider entries_breaking_the_contract
	 *
	 * @param list<mixed> $entries The entries the component declares.
	 */
	public function test_subscribe_rejects_a_component_with_an_entry_that_breaks_the_hooks_contract( array $entries ): void {
		$before   = count( $this->plugin->engine_hooks() );
		$listener = new class() {
			use Hook_Subscriber_Trait;

			/**
			 * The entries `hooks()` returns.
			 *
			 * @var array<int,mixed>
			 */
			public static array $declared = array();

			/**
			 * Hooks: whatever the test declares, to break the contract on purpose.
			 *
			 * @return array<int,mixed>
			 */
			public static function hooks(): array {
				return self::$declared;
			}
		};
		$listener::$declared = $entries;

		try {
			$this->module()->subscribe( $listener );
			$this->fail( 'An entry breaking the hooks() contract must be rejected.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( '::hooks() must return a list of', $e->getMessage() );
		}

		$this->assertCount( $before, $this->plugin->engine_hooks(), 'Nothing is registered, not even the entries before the bad one.' );
		$this->assertFalse( has_filter( 'rfrac_test/wc_good' ) );
		$this->assertFalse( has_filter( 'rfrac_test/wc_bad' ) );
	}

	public function test_is_active_follows_the_woocommerce_loaded_action(): void {
		$this->assertTrue( Module::is_active(), 'The action fired in set_up.' );

		global $wp_actions;
		unset( $wp_actions['woocommerce_loaded'] );

		$this->assertFalse( Module::is_active() );
	}
}
