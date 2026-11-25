<?php
/**
 * Tests for REST route discovery.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Route_Discovery;
use Rockfire\Rest_Api_Cache\Config\Route_Info;
use Rockfire\Rest_Api_Cache\Hooks;

/**
 * @covers \Rockfire\Rest_Api_Cache\Config\Route_Discovery
 * @covers \Rockfire\Rest_Api_Cache\Config\Route_Info
 */
class Test_Route_Discovery extends Rfrac_Test_Case {

	/**
	 * Fresh server with the core routes plus a few test routes.
	 *
	 * @return WP_REST_Server
	 */
	private function server(): WP_REST_Server {
		return $this->new_rest_server(
			static function () {
				register_rest_route(
					'rfrac-test/v1',
					'/things',
					array(
						array(
							'methods'             => 'GET',
							'callback'            => '__return_true',
							'permission_callback' => '__return_true',
							'args'                => array(
								'b_arg' => array(),
								'a_arg' => array(),
							),
						),
						array(
							'methods'             => 'POST',
							'callback'            => '__return_true',
							'permission_callback' => '__return_true',
							'args'                => array( 'post_only' => array() ),
						),
					)
				);
				register_rest_route(
					'rfrac-test/v1',
					'/write-only',
					array(
						'methods'             => 'POST',
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
					)
				);
				register_rest_route(
					'wp/v2',
					'/rfrac-write-only',
					array(
						'methods'             => 'POST',
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
					)
				);
			}
		);
	}

	public function test_discovers_routes_in_registration_order_with_info(): void {
		$server = $this->server();
		$routes = ( new Route_Discovery() )->discover( $server );

		$this->assertSame( array_map( 'strval', array_keys( $server->get_routes() ) ), array_keys( $routes ) );
		$this->assertContainsOnlyInstancesOf( Route_Info::class, $routes );

		$things = $routes['/rfrac-test/v1/things'];
		$this->assertSame( 'rfrac-test/v1', $things->namespace );
		$this->assertTrue( $things->readable );
		$this->assertSame( array( 'a_arg', 'b_arg' ), $things->get_args );
		$this->assertFalse( $things->denied );
		$this->assertFalse( $things->is_default );

		$this->assertFalse( $routes['/rfrac-test/v1/write-only']->readable );
		$this->assertSame( array(), $routes['/rfrac-test/v1/write-only']->get_args );
	}

	public function test_records_the_methods_and_arguments_of_every_handler(): void {
		$server = $this->new_rest_server(
			static function () {
				register_rest_route(
					'rfrac-test/v1',
					'/read-write',
					array(
						array(
							'methods'             => 'GET',
							'callback'            => '__return_true',
							'permission_callback' => '__return_true',
							'args'                => array( 'a' => array() ),
						),
						array(
							'methods'             => 'POST, PUT, PATCH',
							'callback'            => '__return_true',
							'permission_callback' => '__return_true',
							'args'                => array(
								'c' => array(),
								'b' => array(),
							),
						),
						array(
							'methods'             => 'DELETE',
							'callback'            => '__return_true',
							'permission_callback' => '__return_true',
							'args'                => array( 'force' => array() ),
						),
					)
				);
				register_rest_route(
					'rfrac-test/v1',
					'/post-only',
					array(
						'methods'             => 'POST',
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
						'args'                => array( 'body' => array() ),
					)
				);
			}
		);
		$routes = ( new Route_Discovery() )->discover( $server );

		$both = $routes['/rfrac-test/v1/read-write'];
		$this->assertSame( array( 'GET', 'POST', 'PUT', 'PATCH' ), $both->methods );
		$this->assertSame(
			array(
				'GET'   => array( 'a' ),
				'POST'  => array( 'b', 'c' ),
				'PUT'   => array( 'b', 'c' ),
				'PATCH' => array( 'b', 'c' ),
			),
			$both->method_args
		);
		$this->assertSame( array( 'a' ), $both->get_args );
		$this->assertTrue( $both->readable );
		$this->assertTrue( $both->registers( 'get' ) );
		$this->assertTrue( $both->registers( 'PATCH' ) );
		$this->assertFalse( $both->registers( 'DELETE' ) );

		$post = $routes['/rfrac-test/v1/post-only'];
		$this->assertFalse( $post->readable );
		$this->assertSame( array( 'POST' ), $post->methods );
		$this->assertSame( array( 'POST' => array( 'body' ) ), $post->method_args );
		$this->assertSame( array(), $post->get_args );
		$this->assertFalse( $post->registers( 'GET' ) );
	}

	public function test_a_route_info_built_without_methods_answers_get_from_readable(): void {
		$readable = new Route_Info( '/x', 'x', true, array( 'a' ), false, false );
		$write    = new Route_Info( '/y', 'y', false, array(), false, false );

		$this->assertSame( array(), $readable->methods );
		$this->assertSame( array(), $readable->method_args );
		$this->assertTrue( $readable->registers( 'GET' ) );
		$this->assertFalse( $readable->registers( 'POST' ) );
		$this->assertFalse( $write->registers( 'GET' ) );
	}

	public function test_default_routes(): void {
		$routes = ( new Route_Discovery() )->discover( $this->server() );

		$this->assertTrue( $routes['/wp/v2/posts']->is_default );
		$this->assertTrue( $routes['/wp/v2/posts/(?P<id>[\d]+)']->is_default );
		$this->assertTrue( $routes['/oembed/1.0/embed']->is_default );
		$this->assertFalse( $routes['/wp/v2']->is_default, 'The namespace index is not a default route.' );
		$this->assertFalse( $routes['/wp/v2/users/me']->is_default );
		$this->assertTrue( $routes['/wp/v2/users/me']->denied );
		$this->assertFalse( $routes['/']->is_default );
	}

	public function test_write_only_wp_v2_route_is_not_default(): void {
		$routes = ( new Route_Discovery() )->discover( $this->server() );

		$this->assertFalse( $routes['/wp/v2/rfrac-write-only']->readable );
		$this->assertFalse( $routes['/wp/v2/rfrac-write-only']->is_default );
	}

	/**
	 * @dataProvider public_permission_callbacks
	 *
	 * @param mixed $callback Permission callback.
	 */
	public function test_an_absent_empty_or_return_true_permission_callback_is_public( mixed $callback ): void {
		$this->assertSame( Route_Discovery::ACCESS_PUBLIC, Route_Discovery::access_kind( $callback ) );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function public_permission_callbacks(): array {
		return array(
			'absent'                     => array( null ),
			'empty string, core skips'   => array( '' ),
			'false, core skips'          => array( false ),
			'__return_true'              => array( '__return_true' ),
			'__return_true, other case'  => array( '\\__Return_True' ),
		);
	}

	public function test_a_permission_check_declared_in_wp_includes_is_core(): void {
		$controller = new WP_REST_Posts_Controller( 'post' );

		$this->assertSame( Route_Discovery::ACCESS_CORE, Route_Discovery::access_kind( array( $controller, 'get_items_permissions_check' ) ) );
		$this->assertSame( Route_Discovery::ACCESS_CORE, Route_Discovery::access_kind( 'is_user_logged_in' ) );
		$this->assertSame( Route_Discovery::ACCESS_CORE, Route_Discovery::access_kind( '__return_false' ), 'Denies everyone, so nothing is ever stored.' );
	}

	public function test_a_plugin_controller_that_inherits_a_core_permission_check_is_custom(): void {
		$this->assertSame(
			Route_Discovery::ACCESS_CUSTOM,
			Route_Discovery::access_kind( array( new Rfrac_Test_Inheriting_Posts_Controller( 'post' ), 'get_items_permissions_check' ) ),
			'The plugin class can override what core\'s check calls, so it is no longer core\'s own.'
		);
	}

	public function test_a_plugin_controller_that_overrides_only_a_helper_of_a_core_permission_check_is_custom(): void {
		$controller = new Rfrac_Test_Helper_Overriding_Posts_Controller( 'post' );

		$this->assertSame( Route_Discovery::ACCESS_CUSTOM, Route_Discovery::access_kind( array( $controller, 'get_items_permissions_check' ) ) );
		$this->assertSame( Route_Discovery::ACCESS_CUSTOM, Route_Discovery::access_kind( array( $controller, 'get_item_permissions_check' ) ) );
	}

	public function test_a_callable_declared_under_abspath_but_outside_wp_includes_is_custom(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$file = ( new ReflectionFunction( 'is_plugin_active' ) )->getFileName();
		$this->assertIsString( $file );
		$this->assertStringStartsWith( wp_normalize_path( ABSPATH . 'wp-admin/' ), wp_normalize_path( $file ), 'Fixture: declared in core, but not in wp-includes.' );

		$this->assertSame( Route_Discovery::ACCESS_CUSTOM, Route_Discovery::access_kind( 'is_plugin_active' ) );
	}

	/**
	 * @dataProvider custom_permission_callbacks
	 *
	 * @param mixed $callback Permission callback.
	 */
	public function test_any_other_permission_callback_is_custom( mixed $callback ): void {
		$this->assertSame( Route_Discovery::ACCESS_CUSTOM, Route_Discovery::access_kind( $callback ) );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function custom_permission_callbacks(): array {
		return array(
			'closure'                          => array( static fn(): bool => isset( $_SERVER['HTTP_X_PARTNER_KEY'] ) ),
			'plugin function'                  => array( 'rfrac_test_partner_key_check' ),
			'overriding method'                => array( array( new Rfrac_Test_Overriding_Posts_Controller( 'post' ), 'get_items_permissions_check' ) ),
			'static method string'             => array( 'Rfrac_Test_Overriding_Posts_Controller::static_check' ),
			'invokable object'                 => array( new Rfrac_Test_Invokable_Check() ),
			'magic method that has no body'    => array( array( new Rfrac_Test_Magic_Check(), 'anything' ) ),
			'not callable'                     => array( 'rfrac_test_no_such_function' ),
			'not callable, true'               => array( true ),
		);
	}

	public function test_discovery_records_the_methods_with_a_custom_permission_check_in_canonical_order(): void {
		$server = $this->new_rest_server(
			static function () {
				register_rest_route(
					'rfrac-test/v1',
					'/partner',
					array(
						array(
							'methods'             => 'POST',
							'callback'            => '__return_true',
							'permission_callback' => 'rfrac_test_partner_key_check',
						),
						array(
							'methods'             => 'GET',
							'callback'            => '__return_true',
							'permission_callback' => static fn(): bool => true,
						),
						array(
							'methods'             => 'DELETE',
							'callback'            => '__return_true',
							'permission_callback' => '__return_true',
						),
					)
				);
				register_rest_route(
					'rfrac-test/v1',
					'/open',
					array(
						'methods'             => 'GET',
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		$routes = ( new Route_Discovery() )->discover( $server );

		$this->assertSame( array( 'GET', 'POST' ), $routes['/rfrac-test/v1/partner']->custom_access );
		$this->assertSame( array(), $routes['/rfrac-test/v1/open']->custom_access );
		$this->assertSame( array(), $routes['/wp/v2/posts']->custom_access, 'Core controllers check access with core code.' );
		$this->assertSame( array(), $routes['/oembed/1.0/embed']->custom_access );
	}

	public function test_discovery_flags_a_core_controller_subclass_that_overrides_a_helper_of_the_permission_check(): void {
		$server = $this->new_rest_server(
			static function () {
				$controller = new Rfrac_Test_Helper_Overriding_Posts_Controller( 'post' );
				register_rest_route(
					'rfrac-test/v1',
					'/partner-posts',
					array(
						'methods'             => 'GET',
						'callback'            => '__return_true',
						'permission_callback' => array( $controller, 'get_items_permissions_check' ),
					)
				);
				$core = new WP_REST_Posts_Controller( 'post' );
				register_rest_route(
					'rfrac-test/v1',
					'/core-posts',
					array(
						'methods'             => 'GET',
						'callback'            => '__return_true',
						'permission_callback' => array( $core, 'get_items_permissions_check' ),
					)
				);
			}
		);

		$routes = ( new Route_Discovery() )->discover( $server );

		$this->assertSame( array( 'GET' ), $routes['/rfrac-test/v1/partner-posts']->custom_access );
		$this->assertSame( array(), $routes['/rfrac-test/v1/core-posts']->custom_access );
	}

	public function test_the_discovery_server_fires_its_action_before_returning_the_server(): void {
		$fired = 0;
		$count = static function () use ( &$fired ): void {
			++$fired;
		};
		add_action( Hooks::DISCOVERY_SERVER, $count, 10, 0 );

		$server = Route_Discovery::server();

		$this->assertSame( 1, $fired );
		$this->assertSame( rest_get_server(), $server );
	}
}

/**
 * A plugin permission check that reads a secret header (a fixture).
 *
 * @return bool
 */
function rfrac_test_partner_key_check(): bool {
	return isset( $_SERVER['HTTP_X_PARTNER_KEY'] );
}

/**
 * A plugin controller that keeps core's permission checks.
 */
class Rfrac_Test_Inheriting_Posts_Controller extends WP_REST_Posts_Controller {
}

/**
 * A plugin controller that keeps core's permission checks but overrides a helper they call.
 */
class Rfrac_Test_Helper_Overriding_Posts_Controller extends WP_REST_Posts_Controller {

	/**
	 * Own helper: core's permission checks call it (`get_item_permissions_check()` for one).
	 *
	 * @param WP_Post $post Post.
	 *
	 * @return bool
	 */
	public function check_read_permission( $post ) {
		return isset( $_SERVER['HTTP_X_PARTNER_KEY'] );
	}
}

/**
 * A plugin controller with its own permission check.
 */
class Rfrac_Test_Overriding_Posts_Controller extends WP_REST_Posts_Controller {

	/**
	 * Own check.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return bool
	 */
	public function get_items_permissions_check( $request ) {
		return isset( $_SERVER['HTTP_X_PARTNER_KEY'] );
	}

	/**
	 * Static check.
	 *
	 * @return bool
	 */
	public static function static_check(): bool {
		return true;
	}
}

/**
 * An invokable permission check.
 */
class Rfrac_Test_Invokable_Check {

	/**
	 * Check.
	 *
	 * @return bool
	 */
	public function __invoke(): bool {
		return true;
	}
}

/**
 * A permission check reached through `__call`, which cannot be reflected.
 */
class Rfrac_Test_Magic_Check {

	/**
	 * Any method.
	 *
	 * @param string       $name Method.
	 * @param array<mixed> $args Arguments.
	 *
	 * @return bool
	 */
	public function __call( $name, $args ) {
		return true;
	}
}
