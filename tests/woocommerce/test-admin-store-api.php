<?php
/**
 * Tests of the Store API initialisation when the plugin reads the routes in a wp-admin request.
 *
 * WooCommerce initialises the Store API only outside wp-admin, so without this component the REST server the
 * route manager, the catalog preset and the settings-save compile read in an admin request has no `/wc/store`
 * routes.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Automattic\WooCommerce\Blocks\Registry\Container;
use Automattic\WooCommerce\StoreApi\Authentication;
use Automattic\WooCommerce\StoreApi\StoreApi;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Admin_Store_Api;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Admin_Store_Api
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module
 */
class Test_Admin_Store_Api extends WP_UnitTestCase {

	/**
	 * The action counters a test cleared, restored in tear_down().
	 *
	 * @var array<string,int>
	 */
	private array $cleared = array();

	public function set_up() {
		parent::set_up();
		delete_option( Failure_Recorder::OPTION ); // Writes are throttled to one per minute.
	}

	public function tear_down() {
		global $wp_actions;
		foreach ( $this->cleared as $action => $count ) {
			$wp_actions[ $action ] = $count; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the counters this test cleared.
		}
		$this->cleared = array();
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	/**
	 * Make an action count as not yet fired in this request.
	 *
	 * @param string $action Action name.
	 *
	 * @return void
	 */
	private function clear_action( string $action ): void {
		global $wp_actions;
		if ( isset( $wp_actions[ $action ] ) ) {
			$this->cleared[ $action ] = $wp_actions[ $action ];
			unset( $wp_actions[ $action ] );
		}
	}

	/**
	 * An initialisation that only counts its calls.
	 *
	 * @param int $calls Incremented on every initialisation.
	 *
	 * @return \Closure
	 */
	private function counting_init( int &$calls ): \Closure {
		return static function () use ( &$calls ): void {
			++$calls;
		};
	}

	/**
	 * A component whose initialisation only counts its calls, and for which the Store API is never initialised yet.
	 *
	 * @param int $calls Incremented on every initialisation.
	 *
	 * @return Admin_Store_Api
	 */
	private function component( int &$calls ): Admin_Store_Api {
		return new Admin_Store_Api( $this->counting_init( $calls ), static fn(): bool => false );
	}

	/**
	 * The engine hooks of a freshly wired container on the discovery-server action.
	 *
	 * @return list<array{hook:string,callback:\Closure,priority:int,args:int}>
	 */
	private function discovery_hooks(): array {
		$plugin = new Plugin();
		$plugin->register_integrations();
		$this->assertInstanceOf( Module::class, $plugin->woocommerce() );

		return array_values(
			array_filter(
				$plugin->engine_hooks(),
				static fn( array $hook ): bool => Hooks::DISCOVERY_SERVER === $hook['hook']
			)
		);
	}

	public function test_the_module_answers_the_discovery_server_action_in_an_admin_request(): void {
		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );

		$hooks = $this->discovery_hooks();

		$this->assertCount( 1, $hooks );
		$this->assertSame( 0, $hooks[0]['args'] );
	}

	public function test_the_module_leaves_the_store_api_to_woocommerce_outside_admin(): void {
		$this->assertFalse( is_admin() );

		$this->assertSame( array(), $this->discovery_hooks() );
	}

	public function test_the_module_does_not_hook_rest_api_init_for_the_store_api(): void {
		set_current_screen( 'dashboard' );

		$plugin = new Plugin();
		$plugin->register_integrations();
		$early = array_filter(
			$plugin->engine_hooks(),
			static fn( array $hook ): bool => 'rest_api_init' === $hook['hook'] && $hook['priority'] < 10
		);

		$this->assertSame( array(), $early, 'Admin requests that build a REST server for other reasons are left alone.' );
	}

	public function test_the_store_api_is_initialised_once_before_the_server_is_built(): void {
		$this->clear_action( 'rest_api_init' );
		$calls     = 0;
		$component = $this->component( $calls );

		$component->before_discovery();
		$component->before_discovery();

		$this->assertSame( 1, $calls );
	}

	public function test_nothing_is_initialised_once_the_server_is_built(): void {
		global $wp_actions;
		$wp_actions['rest_api_init'] = max( 1, $wp_actions['rest_api_init'] ?? 0 ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The server of this request exists.
		$calls = 0;

		$this->component( $calls )->before_discovery();

		$this->assertSame( 0, $calls );
	}

	public function test_nothing_is_initialised_before_the_blocks_package_has_loaded(): void {
		$this->clear_action( 'rest_api_init' );
		$this->clear_action( 'woocommerce_blocks_loaded' );
		$calls = 0;

		$this->component( $calls )->before_discovery();

		$this->assertSame( 0, $calls );
	}

	public function test_a_missing_internal_class_is_recorded_and_nothing_is_initialised(): void {
		$this->clear_action( 'rest_api_init' );
		$calls     = 0;
		$component = new Admin_Store_Api(
			$this->counting_init( $calls ),
			static fn(): bool => false,
			array( array( 'Rfrac_No_Such_Wc_Class', 'container' ) )
		);

		$component->before_discovery();
		$component->before_discovery();

		$this->assertSame( 0, $calls );
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( Admin_Store_Api::FAILURE_CONTEXT, $last['context'] );
		$this->assertStringContainsString( 'Rfrac_No_Such_Wc_Class::container', $last['message'] );
	}

	public function test_a_missing_internal_method_is_recorded_and_nothing_is_initialised(): void {
		$this->clear_action( 'rest_api_init' );
		$calls     = 0;
		$component = new Admin_Store_Api(
			$this->counting_init( $calls ),
			static fn(): bool => false,
			array( array( StoreApi::class, 'no_such_method' ) )
		);

		$component->before_discovery();

		$this->assertSame( 0, $calls );
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( Admin_Store_Api::FAILURE_CONTEXT, $last['context'] );
		$this->assertStringContainsString( 'no_such_method', $last['message'] );
	}

	public function test_a_missing_internal_is_recorded_ahead_of_a_detector_that_cannot_tell(): void {
		$this->clear_action( 'rest_api_init' );
		// The detector and the initialisation share the Container::get internal: a WooCommerce build without it breaks both.
		$missing   = array( Container::class, 'no_such_get' );
		$calls     = 0;
		$detector  = static function () use ( $missing ): bool {
			Failure_Recorder::record( Admin_Store_Api::FAILURE_CONTEXT, sprintf( 'The WooCommerce Store API internal %s::%s is missing; an initialised Store API cannot be told apart.', $missing[0], $missing[1] ), '', false );
			return false;
		};
		$component = new Admin_Store_Api( $this->counting_init( $calls ), $detector, array( $missing ) );

		$component->before_discovery();

		$this->assertSame( 0, $calls );
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( Admin_Store_Api::FAILURE_CONTEXT, $last['context'] );
		$this->assertStringContainsString( 'the Store API routes are not discovered in wp-admin', $last['message'] );
	}

	public function test_an_initialisation_that_throws_is_recorded_and_not_retried(): void {
		$this->clear_action( 'rest_api_init' );
		$calls     = 0;
		$component = new Admin_Store_Api(
			static function () use ( &$calls ): void {
				++$calls;
				throw new RuntimeException( 'boom' );
			},
			static fn(): bool => false
		);

		$component->before_discovery();
		$component->before_discovery();

		$this->assertSame( 1, $calls );
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( Admin_Store_Api::FAILURE_CONTEXT, $last['context'] );
		$this->assertStringStartsWith( 'RuntimeException: boom', $last['message'] );
	}

	public function test_a_successful_initialisation_clears_a_recorded_store_api_failure(): void {
		$this->clear_action( 'rest_api_init' );
		Failure_Recorder::record( Admin_Store_Api::FAILURE_CONTEXT, 'The Store API failed in an earlier request.' );
		$this->assertNotNull( Failure_Recorder::last() );
		$calls = 0;

		$this->component( $calls )->before_discovery();

		$this->assertSame( 1, $calls );
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_a_successful_initialisation_leaves_a_failure_of_another_context(): void {
		$this->clear_action( 'rest_api_init' );
		Failure_Recorder::record( 'compile', 'The compile failed in an earlier request.' );
		$calls = 0;

		$this->component( $calls )->before_discovery();

		$this->assertSame( 1, $calls );
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'compile', $last['context'] );
	}

	public function test_nothing_is_initialised_when_woocommerce_already_initialised_the_store_api(): void {
		$this->clear_action( 'rest_api_init' );
		$calls     = 0;
		$component = new Admin_Store_Api( $this->counting_init( $calls ) );

		$component->before_discovery();

		$this->assertTrue( Admin_Store_Api::store_api_initialised() );
		$this->assertSame( 0, $calls );
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_the_store_api_is_not_initialised_without_its_session_handler_callback(): void {
		remove_all_filters( 'woocommerce_session_handler' );

		$this->assertFalse( Admin_Store_Api::store_api_initialised() );
	}

	public function test_the_store_api_is_initialised_once_across_components(): void {
		$this->clear_action( 'rest_api_init' );
		remove_all_filters( 'woocommerce_session_handler' );
		$authentication = StoreApi::container()->get( Authentication::class );
		$this->assertInstanceOf( Authentication::class, $authentication );
		$calls = 0;
		$init  = static function () use ( &$calls, $authentication ): void {
			++$calls;
			add_filter( 'woocommerce_session_handler', array( $authentication, 'maybe_use_store_api_session_handler' ), 0 );
		};
		$first  = new Admin_Store_Api( $init );
		$second = new Admin_Store_Api( $init );

		$first->before_discovery();
		$second->before_discovery();

		$this->assertSame( 1, $calls );
		$this->assertTrue( Admin_Store_Api::store_api_initialised() );
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_the_required_woocommerce_internals_exist(): void {
		foreach ( Admin_Store_Api::REQUIRED as list( $class, $method ) ) {
			$this->assertTrue( method_exists( $class, $method ), $class . '::' . $method . ' no longer exists in the installed WooCommerce.' );
		}
	}

	public function test_the_detector_woocommerce_internals_exist(): void {
		foreach ( Admin_Store_Api::DETECTOR_REQUIRED as list( $class, $method ) ) {
			$this->assertTrue( method_exists( $class, $method ), $class . '::' . $method . ' no longer exists in the installed WooCommerce.' );
		}
	}

	public function test_the_component_declares_one_discovery_server_hook(): void {
		$this->assertSame( array( array( Hooks::DISCOVERY_SERVER, 'before_discovery', 10, 0 ) ), Admin_Store_Api::hooks() );
	}
}
