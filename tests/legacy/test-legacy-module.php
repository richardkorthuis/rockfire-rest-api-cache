<?php
/**
 * Tests for the legacy hook-bridge module and its wiring into the container.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Legacy\Module;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Module
 * @covers \Rockfire\Rest_Api_Cache\Plugin
 */
class Test_Legacy_Module extends Rfrac_E2e_Test_Case {

	/**
	 * Fresh container whose legacy module is wired for this test.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	public function set_up() {
		parent::set_up();
		Coexistence_Guard::reset();
		$this->plugin = new Plugin();
		$this->plugin->register_legacy_hooks();
	}

	public function tear_down() {
		Coexistence_Guard::reset();
		delete_option( Failure_Recorder::OPTION );
		parent::tear_down();
	}

	/**
	 * The wired module.
	 *
	 * @return Module
	 */
	private function module(): Module {
		$module = $this->plugin->legacy();
		$this->assertInstanceOf( Module::class, $module );
		return $module;
	}

	/**
	 * A component declaring three hooks, one of them with bound arguments and one that throws.
	 *
	 * @return object
	 */
	private function component(): object {
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
					array( 'rfrac_test/legacy_plain', 'on_plain', 10, 1 ),
					array( 'rfrac_test/legacy_bound', 'on_bound', 7, 2, array( 'bound-a', 'bound-b' ) ),
					array( 'rfrac_test/legacy_throws', 'on_throw', 10, 1 ),
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
				throw new RuntimeException( 'component failed' );
			}
		};
	}

	// ---------- Wiring into the container ----------

	public function test_a_container_wires_the_module_once_while_the_guard_does_not_block(): void {
		$this->assertFalse( Coexistence_Guard::is_blocked(), 'Precondition: only Rockfire is active.' );
		$plugin = new Plugin();
		$this->assertNull( $plugin->legacy(), 'A new container wires nothing.' );
		$this->assertSame( array(), $plugin->legacy_hooks() );

		$plugin->register_hooks();

		$module = $plugin->legacy();
		$this->assertInstanceOf( Module::class, $module );
		$this->assertSame( $plugin, $module->plugin() );

		$legacy = count( $plugin->legacy_hooks() );
		$engine = count( $plugin->engine_hooks() );
		$plugin->register_legacy_hooks();
		$this->assertSame( $module, $plugin->legacy(), 'A second call keeps the module.' );
		$this->assertCount( $legacy, $plugin->legacy_hooks(), 'A second call adds no legacy hook.' );
		$this->assertCount( $engine, $plugin->engine_hooks(), 'A second call adds no engine hook.' );

		$other = new Plugin();
		$other->register_legacy_hooks();
		$this->assertNotSame( $module, $other->legacy(), 'One module per container.' );
	}

	public function test_registering_the_engine_hooks_alone_wires_no_module(): void {
		$plugin = new Plugin();

		$plugin->register_engine_hooks();

		$this->assertNull( $plugin->legacy() );
		$this->assertSame( array(), $plugin->legacy_hooks() );
	}

	public function test_a_hook_of_the_module_goes_to_the_legacy_list_and_never_to_the_engine_list(): void {
		$engine = $this->plugin->engine_hooks();
		$legacy = $this->plugin->legacy_hooks();

		$this->module()->subscribe( $this->component() );

		$this->assertSame( $engine, $this->plugin->engine_hooks(), 'The engine list is untouched.' );
		$this->assertSame( $legacy, array_slice( $this->plugin->legacy_hooks(), 0, count( $legacy ) ), 'The hooks of the module itself stay first.' );
		$added = array_slice( $this->plugin->legacy_hooks(), count( $legacy ) );
		$this->assertSame( array( 'rfrac_test/legacy_plain', 'rfrac_test/legacy_bound', 'rfrac_test/legacy_throws' ), array_column( $added, 'hook' ) );
		$this->assertSame( array( 10, 7, 10 ), array_column( $added, 'priority' ) );
		$this->assertSame( array( 1, 2, 1 ), array_column( $added, 'args' ) );
		foreach ( $added as $hook ) {
			$this->assertSame( $hook['priority'], has_filter( $hook['hook'], $hook['callback'] ), $hook['hook'] . ' is registered with WordPress' );
		}
	}

	public function test_the_booted_container_wires_the_module_apart_from_its_engine_hooks(): void {
		$booted = Plugin::instance();

		$this->assertInstanceOf( Module::class, $booted->legacy() );
		$engine = array_column( $booted->engine_hooks(), 'callback' );
		foreach ( $booted->legacy_hooks() as $hook ) {
			$this->assertNotContains( $hook['callback'], $engine, $hook['hook'] );
		}
	}

	/**
	 * The guard blocks while the original's `Plugin` class is declared: no module, no legacy hook.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_blocking_guard_wires_no_module(): void {
		require_once dirname( __DIR__ ) . '/support/fixture-original-plugin-class.php';
		Coexistence_Guard::reset();
		$this->assertTrue( Coexistence_Guard::is_blocked(), 'Precondition: the original is loaded.' );

		$plugin = new Plugin();
		$plugin->register_hooks();
		$plugin->register_legacy_hooks();

		$this->assertNull( $plugin->legacy() );
		$this->assertSame( array(), $plugin->legacy_hooks() );
	}

	// ---------- The serving harness ----------

	/**
	 * The container of the last live request of a harness.
	 *
	 * @param Rfrac_Serving_Harness $harness The harness.
	 *
	 * @return Plugin
	 */
	private function live_container( Rfrac_Serving_Harness $harness ): Plugin {
		$plugin = ( new ReflectionProperty( Rfrac_Serving_Harness::class, 'plugin' ) )->getValue( $harness );
		$this->assertInstanceOf( Plugin::class, $plugin );
		return $plugin;
	}

	public function test_the_harness_registers_the_legacy_hooks_of_a_live_request_only_on_demand(): void {
		$h = $this->harness( Rfrac_Serving_Harness::EARLY );

		$h->request( '/wp-json/wp/v2/posts' );
		$h->serve( '/wp/v2/posts' );
		$this->assertNull( $this->live_container( $h )->legacy(), 'The default live request registers the engine hooks only.' );

		$h->with_legacy();
		$h->serve( '/wp/v2/posts' );
		$this->assertInstanceOf( Module::class, $this->live_container( $h )->legacy() );

		$this->assertSame( $h, $h->with_legacy( false ) );
		$h->serve( '/wp/v2/posts' );
		$this->assertNull( $this->live_container( $h )->legacy() );
	}

	// ---------- subscribe() ----------

	public function test_subscribed_hooks_fire_the_component_with_the_bound_arguments(): void {
		$component = $this->component();
		$this->module()->subscribe( $component );

		$this->assertSame( 'x', apply_filters( 'rfrac_test/legacy_plain', 'x' ) );
		$this->assertSame( 'y!', apply_filters( 'rfrac_test/legacy_bound', 'y', 'ignored' ) );

		$this->assertSame(
			array(
				array( 'plain', array( 'x' ) ),
				array( 'bound', array( 'bound-a', 'bound-b', 'y' ) ),
			),
			$component->calls
		);
	}

	public function test_a_failing_component_is_guarded_like_every_engine_hook(): void {
		$this->module()->subscribe( $this->component() );

		$this->assertSame( 'kept', apply_filters( 'rfrac_test/legacy_throws', 'kept' ), 'The first argument is returned unchanged.' );
		$this->assertSame( 'hook:rfrac_test/legacy_throws', Failure_Recorder::last()['context'] ?? null );
	}

	public function test_subscribe_rejects_a_component_that_does_not_use_the_hook_subscriber_trait(): void {
		$legacy = $this->plugin->legacy_hooks();
		$engine = $this->plugin->engine_hooks();
		$bare   = new class() {
			/**
			 * Hooks, in the right shape but declared without the trait.
			 *
			 * @return list<array<int,mixed>>
			 */
			public static function hooks(): array {
				return array( array( 'rfrac_test/legacy_bare', 'on_bare', 10, 1 ) );
			}
		};

		try {
			$this->module()->subscribe( $bare );
			$this->fail( 'A component without Hook_Subscriber_Trait must be rejected.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'A legacy component must use Hook_Subscriber_Trait.', $e->getMessage() );
		}

		$this->assertSame( $legacy, $this->plugin->legacy_hooks() );
		$this->assertSame( $engine, $this->plugin->engine_hooks() );
		$this->assertFalse( has_filter( 'rfrac_test/legacy_bare' ) );
	}

	public function test_subscribe_rejects_an_object_without_hooks(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->module()->subscribe( new stdClass() );
	}

	/**
	 * A component whose `hooks()` entry breaks the `Hook_Subscriber_Trait::hooks()` contract.
	 *
	 * @return array<string,array{0:list<mixed>}>
	 */
	public static function entries_breaking_the_contract(): array {
		return array(
			'two elements only'          => array( array( array( 'rfrac_test/legacy_bad', 'on_plain' ) ) ),
			'no priority'                => array( array( array( 'rfrac_test/legacy_bad', 'on_plain', null, 1 ) ) ),
			'priority as a string'       => array( array( array( 'rfrac_test/legacy_bad', 'on_plain', '10', 1 ) ) ),
			'accepted args as a string'  => array( array( array( 'rfrac_test/legacy_bad', 'on_plain', 10, '1' ) ) ),
			'hook name not a string'     => array( array( array( 12, 'on_plain', 10, 1 ) ) ),
			'method not a string'        => array( array( array( 'rfrac_test/legacy_bad', array( 'on_plain' ), 10, 1 ) ) ),
			'bound arguments not a list' => array( array( array( 'rfrac_test/legacy_bad', 'on_plain', 10, 1, 'bound' ) ) ),
			'bound arguments keyed'      => array( array( array( 'rfrac_test/legacy_bad', 'on_plain', 10, 1, array( 'key' => 'bound' ) ) ) ),
			'entry not an array'         => array( array( 'rfrac_test/legacy_bad' ) ),
			'bad entry after a good one' => array( array( array( 'rfrac_test/legacy_good', 'on_plain', 10, 1 ), array( 'rfrac_test/legacy_bad', 'on_plain' ) ) ),
		);
	}

	/**
	 * @dataProvider entries_breaking_the_contract
	 *
	 * @param list<mixed> $entries The entries the component declares.
	 */
	public function test_subscribe_rejects_a_component_with_an_entry_that_breaks_the_hooks_contract( array $entries ): void {
		$legacy    = $this->plugin->legacy_hooks();
		$component = new class() {
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
		$component::$declared = $entries;

		try {
			$this->module()->subscribe( $component );
			$this->fail( 'An entry breaking the hooks() contract must be rejected.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( '::hooks() must return a list of', $e->getMessage() );
		}

		$this->assertSame( $legacy, $this->plugin->legacy_hooks(), 'Nothing is registered, not even the entries before the bad one.' );
		$this->assertFalse( has_filter( 'rfrac_test/legacy_good' ) );
		$this->assertFalse( has_filter( 'rfrac_test/legacy_bad' ) );
	}
}
