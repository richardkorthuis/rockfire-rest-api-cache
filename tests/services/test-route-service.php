<?php
/**
 * Tests for the route service.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Config\Route_Discovery;
use Rockfire\Rest_Api_Cache\Config\Route_Rule;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Services\Route_Service;
use Rockfire\Rest_Api_Cache\Services\Route_View;

/**
 * @covers \Rockfire\Rest_Api_Cache\Services\Route_Service
 * @covers \Rockfire\Rest_Api_Cache\Services\Route_View
 */
class Test_Route_Service extends Rfrac_Test_Case {

	use Rfrac_Fails_Option_Writes;

	private const ITEMS = '/myplugin/v1/items';

	/**
	 * Number of times the settings option was written.
	 *
	 * @var int
	 */
	private int $writes = 0;

	/**
	 * Fresh server with test routes, and a service on it.
	 *
	 * @return Route_Service
	 */
	private function service(): Route_Service {
		$this->new_rest_server(
			static function () {
				$get = array(
					'methods'             => 'GET',
					'callback'            => '__return_true',
					'permission_callback' => '__return_true',
				);
				register_rest_route( 'myplugin/v1', '/items', $get + array( 'args' => array( 'page' => array(), 'color' => array() ) ) );
				register_rest_route( 'myplugin/v1', '/items/(?P<id>\d+)', $get );
				register_rest_route(
					'myplugin/v1',
					'/write',
					array(
						'methods'             => 'POST',
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
					)
				);
				register_rest_route(
					'myplugin/v1',
					'/remove',
					array(
						'methods'             => 'DELETE',
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
					)
				);
				register_rest_route(
					'myplugin/v1',
					'/search',
					array(
						$get + array( 'args' => array( 'q' => array() ) ),
						array(
							'methods'             => 'POST',
							'callback'            => '__return_true',
							'permission_callback' => '__return_true',
							'args'                => array( 'term' => array(), 'page' => array() ),
						),
					)
				);
				register_rest_route( 'wc/store/v1', '/cart', $get );
			}
		);
		// Existing option: update_option() (not add_option()) fires `update_option_rfrac_settings`.
		update_option( Names::OPTION_SETTINGS, array(), false );
		return new Route_Service( new Config(), new Route_Discovery() );
	}

	private function id( string $route ): string {
		return Route_Service::id_for( $route );
	}

	/**
	 * Count writes of the settings option.
	 */
	private function count_writes(): void {
		add_action(
			'update_option_' . Names::OPTION_SETTINGS,
			function () {
				++$this->writes;
			}
		);
	}

	public function test_all_lists_core_routes_and_get_args_and_the_routes_that_register_a_cacheable_method(): void {
		$all    = $this->service()->all();
		$routes = wp_list_pluck( $all, 'route' );

		$this->assertContains( '/wp/v2/posts', $routes );
		$this->assertContains( '/wp/v2/posts/(?P<id>[\d]+)', $routes );
		$this->assertContains( '/myplugin/v1/write', $routes, 'A POST-only route is listed.' );
		$this->assertNotContains( '/myplugin/v1/remove', $routes, 'A DELETE-only route is not.' );

		$by_route = array_combine( $routes, $all );
		$this->assertSame( 'wp/v2', $by_route['/wp/v2/posts']->namespace );
		$this->assertInstanceOf( Route_View::class, $by_route[ self::ITEMS ] );
		$this->assertSame( array( 'color', 'page' ), array_values( array_intersect( array( 'color', 'page' ), $by_route[ self::ITEMS ]->args ) ) );
		$this->assertTrue( $by_route['/wp/v2/posts']->is_registered );
		$this->assertTrue( $by_route['/wp/v2/posts']->is_default );
		$this->assertTrue( $by_route['/wp/v2/posts']->enabled );
		$this->assertSame( Config::instance()->settings()['default_ttl'], $by_route['/wp/v2/posts']->ttl );
		$this->assertFalse( $by_route[ self::ITEMS ]->enabled );
		$this->assertSame( 'default', $by_route[ self::ITEMS ]->source );
	}

	public function test_denied_routes_are_never_enabled(): void {
		$config  = new Config();
		$service = $this->service();
		$this->assertTrue( $config->save_route_rules( array( '/wp/v2/users/me' => array( 'status' => 'enabled' ), '/wc/store/v1/cart' => array( 'status' => 'enabled' ) ) )->is_valid() );

		$service = new Route_Service( $config, new Route_Discovery() );
		foreach ( array( '/wp/v2/users/me', '/wc/store/v1/cart' ) as $route ) {
			$view = $service->find( $this->id( $route ) );
			$this->assertNotNull( $view, $route );
			$this->assertTrue( $view->is_denied );
			$this->assertFalse( $view->enabled );
			$this->assertNotNull( $view->rule );
		}
	}

	public function test_save_enables_route_and_compiled_config_contains_it(): void {
		add_action(
			'update_option_' . Names::OPTION_SETTINGS,
			static function () {
				( new Config_Compiler() )->compile( rest_get_server() );
			}
		);
		$service = $this->service();

		$result = $service->save( $this->id( self::ITEMS ), array( 'status' => 'enabled', 'ttl' => 3600, 'param_mode' => 'allow_all' ) );

		$this->assertInstanceOf( Route_Rule::class, $result );
		$view = $service->find( $this->id( self::ITEMS ) );
		$this->assertTrue( $view->enabled );
		$this->assertSame( 3600, $view->ttl );
		$this->assertSame( 'settings', $view->source );
		$this->assertNotNull( $view->rule );

		$keys = array_map( static fn( $r ) => $r->key, Compiled_Config::load()->routes() );
		$this->assertContains( self::ITEMS, $keys );
	}

	public function test_forbidden_header_is_rejected_and_nothing_persisted(): void {
		$service = $this->service();
		$this->count_writes();

		$result = $service->save( $this->id( self::ITEMS ), array( 'status' => 'enabled', 'vary_headers' => array( 'Authorization' ) ) );

		$this->assertWPError( $result );
		$this->assertContains( 'rfrac_forbidden_header', $result->get_error_codes() );
		$this->assertSame( 0, $this->writes );
		$this->assertSame( array(), ( new Config() )->stored_route_rules() );
	}

	public function test_vary_headers_that_overflow_the_limit_with_the_global_ones_are_rejected_and_nothing_persisted(): void {
		$service = $this->service();
		$this->assertTrue( ( new Config() )->save_settings( array( 'global_vary_headers' => 'a b' ) )->is_valid() );
		$this->count_writes();

		$result = $service->save( $this->id( self::ITEMS ), array( 'status' => 'enabled', 'vary_headers' => array( 'c', 'd', 'e', 'f' ) ) );

		$this->assertWPError( $result );
		$this->assertContains( 'rfrac_invalid_vary_headers', $result->get_error_codes() );
		$this->assertStringContainsString( 'the route "' . self::ITEMS . '" would vary on 6 request headers', $result->get_error_message() );
		$this->assertSame( 0, $this->writes );
		$this->assertSame( array(), ( new Config() )->stored_route_rules() );

		$this->assertInstanceOf( Route_Rule::class, $service->save( $this->id( self::ITEMS ), array( 'status' => 'enabled', 'vary_headers' => array( 'c', 'd', 'e' ) ) ) );
		$this->assertSame( 1, $this->writes );
	}

	public function test_a_bulk_status_change_is_refused_for_a_route_that_overflows_the_global_vary_headers(): void {
		$service = $this->service();
		$config  = new Config();
		$this->assertTrue( $config->save_route_rule( self::ITEMS, array( 'status' => 'excluded', 'vary_headers' => array( 'a', 'b', 'c', 'd', 'e' ) ) )->is_valid() );
		update_option( Names::OPTION_SETTINGS, array_merge( (array) get_option( Names::OPTION_SETTINGS ), array( 'global_vary_headers' => array( 'x' ) ) ), false );
		$service->flush();

		$result = $service->set_status( array( $this->id( self::ITEMS ) ), 'enabled' );

		$this->assertWPError( $result );
		$this->assertContains( 'rfrac_invalid_vary_headers', $result->get_error_codes() );
		$this->assertSame( 'excluded', ( new Config() )->stored_route_rules()[ self::ITEMS ]->status );
	}

	public function test_ttl_is_clamped_and_null_ttl_uses_default(): void {
		$service = $this->service();

		$rule = $service->save( $this->id( self::ITEMS ), array( 'status' => 'enabled', 'ttl' => 30 ) );
		$this->assertInstanceOf( Route_Rule::class, $rule );
		$this->assertSame( 60, $rule->ttl );
		$this->assertSame( 60, $service->find( $this->id( self::ITEMS ) )->ttl );

		$bad = $service->save( $this->id( self::ITEMS ), array( 'status' => 'enabled', 'ttl' => 'abc' ) );
		$this->assertWPError( $bad );
		$this->assertContains( 'rfrac_invalid_ttl', $bad->get_error_codes() );

		$service->save( $this->id( self::ITEMS ), array( 'status' => 'enabled', 'ttl' => null ) );
		$this->assertSame( Config::instance()->settings()['default_ttl'], $service->find( $this->id( self::ITEMS ) )->ttl );
	}

	public function test_invalid_input_codes(): void {
		$service = $this->service();
		$id      = $this->id( self::ITEMS );

		$this->assertContains( 'rfrac_invalid_status', $service->save( $id, array( 'status' => 'bogus' ) )->get_error_codes() );
		$this->assertContains( 'rfrac_invalid_param_mode', $service->save( $id, array( 'param_mode' => 'x' ) )->get_error_codes() );
		$this->assertContains( 'rfrac_invalid_post_type', $service->save( $id, array( 'flush_post_types' => array( 'nope' ) ) )->get_error_codes() );
		$this->assertContains( 'rfrac_invalid_taxonomy', $service->save( $id, array( 'flush_taxonomies' => array( 'nope' ) ) )->get_error_codes() );

		$ok = $service->save( $id, array( 'status' => 'enabled', 'flush_post_types' => array( 'post' ), 'flush_taxonomies' => array( 'category' ) ) );
		$this->assertInstanceOf( Route_Rule::class, $ok );
	}

	public function test_save_rejects_denied_unregistered_and_unknown(): void {
		$service = $this->service();

		$this->assertContains( 'rfrac_route_locked', $service->save( $this->id( '/wp/v2/users/me' ), array( 'status' => 'enabled' ) )->get_error_codes() );
		$this->assertContains( 'rfrac_route_not_found', $service->save( $this->id( '/nope/v1/x' ), array( 'status' => 'enabled' ) )->get_error_codes() );

		( new Config() )->save_route_rule( '/old/v1/x', array( 'status' => 'enabled' ) );
		$service = new Route_Service( new Config(), new Route_Discovery() );
		$this->assertContains( 'rfrac_route_unregistered', $service->save( $this->id( '/old/v1/x' ), array( 'status' => 'excluded' ) )->get_error_codes() );
	}

	public function test_default_rule_removes_override(): void {
		$service = $this->service();
		$id      = $this->id( self::ITEMS );
		$service->save( $id, array( 'status' => 'enabled' ) );
		$this->assertArrayHasKey( self::ITEMS, ( new Config() )->stored_route_rules() );

		$result = $service->save( $id, array() );

		$this->assertInstanceOf( Route_Rule::class, $result );
		$this->assertArrayNotHasKey( self::ITEMS, ( new Config() )->stored_route_rules() );
		$this->assertNull( $service->find( $id )->rule );
	}

	public function test_orphan_is_listed_as_unregistered_and_reset_removes_it(): void {
		$this->service();
		( new Config() )->save_route_rule( '/old/v1/x', array( 'status' => 'enabled' ) );
		$service = new Route_Service( new Config(), new Route_Discovery() );

		$orphans = $service->all( array( 'view' => 'unregistered' ) );
		$this->assertCount( 1, $orphans );
		$this->assertSame( '/old/v1/x', $orphans[0]->route );
		$this->assertFalse( $orphans[0]->is_registered );

		$this->assertTrue( $service->reset( $orphans[0]->id ) );
		$this->assertSame( array(), $service->all( array( 'view' => 'unregistered' ) ) );
		$this->assertContains( 'rfrac_route_not_found', $service->reset( $orphans[0]->id )->get_error_codes() );
	}

	public function test_resolve(): void {
		$service = $this->service();

		$this->assertSame( '/wp/v2/posts/(?P<id>[\d]+)', $service->resolve( '/wp/v2/posts/5' )->route );
		$this->assertSame( '/wp/v2/posts', $service->resolve( '/wp/v2/posts' )->route );
		$this->assertSame( '/wp/v2/posts/(?P<id>[\d]+)', $service->resolve( '/wp/v2/posts/(?P<id>[\d]+)' )->route );
		$this->assertSame( '/wp/v2/posts/(?P<id>[\d]+)', $service->resolve( '/WP/v2/posts/5?x=1' )->route );
		$this->assertNull( $service->resolve( '/nope' ) );
	}

	// ---------- request methods ----------

	public function test_a_post_only_route_is_listed_with_its_methods_and_resolves(): void {
		$service = $this->service();
		$write   = '/myplugin/v1/write';

		$view = $service->find( $this->id( $write ) );
		$this->assertInstanceOf( Route_View::class, $view );
		$this->assertTrue( $view->is_registered );
		$this->assertSame( array( 'POST' ), $view->methods );
		$this->assertSame( array(), $view->args );
		$this->assertSame( array( 'POST' => array() ), $view->method_args );
		$this->assertContains( $write, wp_list_pluck( $service->all(), 'route' ) );
		$this->assertSame( $write, $service->resolve( $write )->route );
		$this->assertSame( $write, $service->resolve( '/myplugin/v1/write?x=1' )->route );

		$remove = '/myplugin/v1/remove';
		$this->assertNull( $service->find( $this->id( $remove ) ) );
		$this->assertNull( $service->resolve( $remove ) );
		$this->assertNotContains( $remove, wp_list_pluck( $service->all(), 'route' ) );
		$this->assertContains( 'rfrac_route_unregistered', $service->save( $this->id( $remove ), array( 'status' => 'excluded' ) )->get_error_codes() );
	}

	public function test_a_view_carries_the_registered_methods_and_the_arguments_of_each(): void {
		$service = $this->service();

		$search = $service->find( $this->id( '/myplugin/v1/search' ) );
		$this->assertSame( array( 'GET', 'POST' ), $search->methods );
		$this->assertSame( array( 'q' ), $search->args );
		$this->assertSame( array( 'GET' => array( 'q' ), 'POST' => array( 'page', 'term' ) ), $search->method_args );

		$this->assertSame( array( 'GET' ), $service->find( $this->id( self::ITEMS ) )->methods );
		$this->assertSame( array( 'GET', 'POST' ), array_values( array_intersect( array( 'GET', 'POST' ), $service->find( $this->id( '/wp/v2/posts' ) )->methods ) ) );
		$orphan = new Route_View( 'id', '/x', '', array(), false, false, false, null, 'default', false, false, 60 );
		$this->assertSame( array( 'GET' ), $orphan->methods );
		$this->assertSame( array(), $orphan->method_args );
	}

	public function test_a_view_lists_its_custom_checked_methods_and_the_cached_ones_only_while_enabled(): void {
		$this->new_rest_server(
			static function () {
				register_rest_route(
					'partner/v1',
					'/feed',
					array(
						array(
							'methods'             => 'GET',
							'callback'            => '__return_true',
							'permission_callback' => static fn(): bool => isset( $_SERVER['HTTP_X_PARTNER_KEY'] ),
						),
						array(
							'methods'             => 'POST',
							'callback'            => '__return_true',
							'permission_callback' => static fn(): bool => true,
						),
					)
				);
				register_rest_route(
					'partner/v1',
					'/open',
					array(
						'methods'             => 'GET',
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
					)
				);
			}
		);
		update_option( Names::OPTION_SETTINGS, array(), false );
		$feed    = Route_Service::id_for( '/partner/v1/feed' );
		$open    = Route_Service::id_for( '/partner/v1/open' );
		$service = new Route_Service( new Config(), new Route_Discovery() );

		$view = $service->find( $feed );
		$this->assertSame( array( 'GET', 'POST' ), $view->custom_access );
		$this->assertSame( array(), $view->cached_custom, 'Not cached yet.' );
		$this->assertSame( array(), $service->cached_custom_access() );
		$this->assertSame( array(), $service->find( Route_Service::id_for( '/wp/v2/posts' ) )->cached_custom, 'Core checks are not custom.' );

		$this->assertInstanceOf( Route_Rule::class, $service->save( $feed, array( 'status' => 'enabled', 'methods' => array( 'GET' ) ) ) );
		$this->assertInstanceOf( Route_Rule::class, $service->save( $open, array( 'status' => 'enabled' ) ) );
		$service->flush();

		$this->assertSame( array( 'GET' ), $service->find( $feed )->cached_custom, 'Only the methods the rule caches.' );
		$this->assertSame( array( '/partner/v1/feed' ), wp_list_pluck( $service->cached_custom_access(), 'route' ) );

		$this->assertInstanceOf( Route_Rule::class, $service->save( $feed, array( 'status' => 'excluded' ) ) );
		$service->flush();
		$this->assertSame( array(), $service->find( $feed )->cached_custom );
		$this->assertSame( array( 'GET', 'POST' ), $service->find( $feed )->custom_access );
		$this->assertSame( array(), $service->cached_custom_access() );
	}

	public function test_a_custom_checked_route_cached_by_default_is_listed_without_a_stored_rule(): void {
		$this->new_rest_server(
			static function () {
				register_rest_route(
					'wp/v2',
					'/partner-items',
					array(
						'methods'             => 'GET',
						'callback'            => '__return_true',
						'permission_callback' => static fn(): bool => isset( $_SERVER['HTTP_X_PARTNER_KEY'] ),
					)
				);
				register_rest_route(
					'wp/v2',
					'/open-items',
					array(
						'methods'             => 'GET',
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
					)
				);
			}
		);
		update_option( Names::OPTION_SETTINGS, array(), false );
		$partner = Route_Service::id_for( '/wp/v2/partner-items' );
		$service = new Route_Service( new Config(), new Route_Discovery() );

		$view = $service->find( $partner );
		$this->assertTrue( $view->is_default, 'Readable wp/v2 routes are cached by default.' );
		$this->assertNull( $view->rule, 'No rule is stored for it.' );
		$this->assertSame( array(), ( new Config() )->stored_route_rules() );
		$this->assertSame( array( 'GET' ), $view->custom_access );
		$this->assertSame( array( 'GET' ), $view->cached_custom );
		$this->assertSame( array(), $service->find( Route_Service::id_for( '/wp/v2/open-items' ) )->cached_custom, 'A core check is not custom.' );
		$this->assertSame( array( '/wp/v2/partner-items' ), wp_list_pluck( $service->cached_custom_access(), 'route' ) );
		$this->assertSame( array( 'GET' ), array_values( $service->cached_custom_access() )[0]->cached_custom );

		$this->assertInstanceOf( Route_Rule::class, $service->save( $partner, array( 'status' => 'excluded' ) ) );
		$service->flush();
		$this->assertSame( array(), $service->find( $partner )->cached_custom );
		$this->assertSame( array( 'GET' ), $service->find( $partner )->custom_access );
		$this->assertSame( array(), $service->cached_custom_access() );
	}

	public function test_save_stores_the_methods_the_route_registers(): void {
		$service = $this->service();
		$search  = '/myplugin/v1/search';

		$rule = $service->save( $this->id( $search ), array( 'status' => 'enabled', 'methods' => array( 'GET', 'POST' ) ) );

		$this->assertInstanceOf( Route_Rule::class, $rule );
		$this->assertSame( array( 'GET', 'POST' ), $rule->methods );
		$this->assertSame( array( 'GET', 'POST' ), ( new Config() )->stored_route_rules()[ $search ]->methods );
		$this->assertSame( array( 'GET', 'POST' ), $service->find( $this->id( $search ) )->rule->methods );

		$this->assertInstanceOf( Route_Rule::class, $service->save( $this->id( '/myplugin/v1/write' ), array( 'status' => 'enabled', 'methods' => array( 'post' ) ) ) );
		$this->assertSame( array( 'POST' ), ( new Config() )->stored_route_rules()['/myplugin/v1/write']->methods );
	}

	public function test_save_refuses_the_methods_a_route_does_not_offer(): void {
		$service = $this->service();
		$this->count_writes();
		$cases = array(
			'a method the route does not register'  => array( '/myplugin/v1/write', array( 'status' => 'enabled', 'methods' => array( 'PUT' ) ) ),
			'a registered and an unregistered one'  => array( '/myplugin/v1/write', array( 'status' => 'enabled', 'methods' => array( 'GET', 'POST' ) ) ),
			'an unregistered method, not enabled'   => array( '/myplugin/v1/write', array( 'status' => 'excluded', 'methods' => array( 'PUT' ) ) ),
			'post on a core route'                  => array( '/wp/v2/posts', array( 'status' => 'enabled', 'methods' => array( 'POST' ) ) ),
			'get and post on a core route'          => array( '/wp/v2/posts', array( 'methods' => array( 'GET', 'POST' ) ) ),
			'an enabled rule without a method'      => array( '/myplugin/v1/write', array( 'status' => 'enabled' ) ),
			'an enabled rule with get on post only' => array( '/myplugin/v1/write', array( 'status' => 'enabled', 'methods' => array( 'GET' ) ) ),
			'delete'                                => array( '/myplugin/v1/write', array( 'status' => 'enabled', 'methods' => array( 'POST', 'DELETE' ) ) ),
			'post on a get-only route'              => array( '/myplugin/v1/items', array( 'status' => 'enabled', 'methods' => array( 'POST' ) ) ),
		);
		foreach ( $cases as $label => $case ) {
			$result = $service->save( $this->id( $case[0] ), $case[1] );
			$this->assertWPError( $result, $label );
			$this->assertSame( 'rfrac_invalid_method', $result->get_error_code(), $label );
			$this->assertSame( array( 'field' => 'methods' ), $result->get_error_data(), $label );
		}
		$this->assertNothingPersisted( array() );
	}

	public function test_save_refuses_an_enabled_rule_with_an_empty_method_list_instead_of_storing_get(): void {
		$service = $this->service();
		$this->count_writes();

		foreach ( array( '/myplugin/v1/search', self::ITEMS, '/wp/v2/posts', '/myplugin/v1/write' ) as $route ) {
			$result = $service->save( $this->id( $route ), array( 'status' => 'enabled', 'methods' => array() ) );

			$this->assertWPError( $result, $route );
			$this->assertSame( 'rfrac_invalid_method', $result->get_error_code(), $route );
			$this->assertSame( array( 'field' => 'methods' ), $result->get_error_data(), $route );
		}
		$this->assertNothingPersisted( array() );

		// A rule that is not enabled, and one that names no `methods` at all, still cache GET.
		$items = $this->id( self::ITEMS );
		$this->assertInstanceOf( Route_Rule::class, $service->save( $items, array( 'status' => 'excluded', 'methods' => array() ) ) );
		$this->assertSame( array( 'GET' ), $service->find( $items )->rule->methods );
		$this->assertInstanceOf( Route_Rule::class, $service->save( $items, array( 'status' => 'enabled' ) ) );
		$this->assertSame( array( 'GET' ), $service->find( $items )->rule->methods );
		$this->assertTrue( $service->find( $items )->enabled );
	}

	public function test_an_excluded_or_default_rule_saves_without_methods_on_a_post_only_route(): void {
		$service = $this->service();
		$write   = '/myplugin/v1/write';

		$rule = $service->save( $this->id( $write ), array( 'status' => 'excluded' ) );
		$this->assertInstanceOf( Route_Rule::class, $rule );
		$this->assertSame( 'excluded', ( new Config() )->stored_route_rules()[ $write ]->status );
		$this->assertFalse( $service->find( $this->id( $write ) )->enabled );

		$this->assertInstanceOf( Route_Rule::class, $service->save( $this->id( $write ), array( 'status' => 'default' ) ) );
		$this->assertArrayNotHasKey( $write, ( new Config() )->stored_route_rules() );

		$this->assertInstanceOf( Route_Rule::class, $service->save( $this->id( $write ), array( 'status' => 'enabled', 'methods' => array( 'POST' ) ) ) );
		$this->assertTrue( $service->find( $this->id( $write ) )->enabled );
		$this->assertInstanceOf( Route_Rule::class, $service->save( $this->id( $write ), array( 'status' => 'excluded' ) ) );
		$this->assertFalse( $service->find( $this->id( $write ) )->enabled );
	}

	public function test_a_default_core_route_saved_get_only_writes_no_override(): void {
		$service = $this->service();
		$posts   = $this->id( '/wp/v2/posts' );

		$builtin = Config::default_route_rule()->to_array();
		$this->assertInstanceOf( Route_Rule::class, $service->save( $posts, $builtin + array( 'methods' => array( 'GET' ) ) ) );
		$this->assertSame( array(), ( new Config() )->stored_route_rules() );
		$this->assertNull( $service->find( $posts )->rule );
	}

	public function test_bulk_enabling_skips_a_route_whose_methods_it_does_not_register(): void {
		$service = $this->service();
		$this->count_writes();
		$write = $this->id( '/myplugin/v1/write' );

		$skipped = null;
		$this->assertSame( 0, $service->set_status( array( $write ), 'enabled', $skipped ) );
		$this->assertSame( 1, $skipped );
		$this->assertNothingPersisted( array() );

		$this->assertInstanceOf( Route_Rule::class, $service->save( $write, array( 'status' => 'excluded', 'methods' => array( 'POST' ) ) ) );
		$this->assertSame( 1, $service->set_status( array( $write ), 'enabled', $skipped ) );
		$this->assertSame( 0, $skipped );
		$this->assertSame( array( 'POST' ), $service->find( $write )->rule->methods );
		$this->assertTrue( $service->find( $write )->enabled );
	}

	public function test_set_status_tells_the_routes_that_need_their_methods_from_the_ones_that_can_not_be_changed(): void {
		$service = $this->service();
		$write   = $this->id( '/myplugin/v1/write' );
		$ids     = array( $this->id( self::ITEMS ), $write, $this->id( '/wp/v2/users/me' ), $this->id( '/nope/v1/x' ) );

		$skipped       = null;
		$needs_methods = null;
		$this->assertSame( 1, $service->set_status( $ids, 'enabled', $skipped, $needs_methods ) );
		$this->assertSame( 3, $skipped, 'Every route that is not enabled counts as skipped.' );
		$this->assertSame( 1, $needs_methods, 'Only the POST-only route is waiting for its methods.' );

		$this->assertSame( 0, $service->set_status( $ids, 'enabled', $skipped, $needs_methods ) );
		$this->assertSame( 3, $skipped );
		$this->assertSame( 1, $needs_methods );

		$this->assertSame( 1, $service->set_status( $ids, 'default', $skipped, $needs_methods ), 'Only enabling needs a method: the enabled route goes back to its default.' );
		$this->assertSame( 2, $skipped );
		$this->assertSame( 0, $needs_methods );
	}

	public function test_enable_preset_reports_the_routes_that_need_their_methods_apart(): void {
		$service = $this->service();
		$this->with_presets( array( 'demo' => array( 'label' => 'Demo', 'routes' => array( self::ITEMS, '/myplugin/v1/write', '/nope/v1/x' ) ) ) );

		$skipped       = null;
		$needs_methods = null;
		$this->assertSame( 1, $service->enable_preset( 'demo', $skipped, $needs_methods ) );
		$this->assertSame( 2, $skipped );
		$this->assertSame( 1, $needs_methods );
	}

	public function test_a_core_route_without_a_get_handler_is_not_listed(): void {
		$service = $this->service();

		$post_only = array();
		foreach ( rest_get_server()->get_routes( 'wp/v2' ) as $route => $handlers ) {
			$has_get = false;
			foreach ( $handlers as $handler ) {
				$has_get = $has_get || ! empty( $handler['methods']['GET'] );
			}
			if ( ! $has_get ) {
				$post_only[] = (string) $route;
			}
		}
		$this->assertContains( '/wp/v2/media/(?P<id>[\d]+)/edit', $post_only, 'Core has routes that only register POST.' );

		$listed = wp_list_pluck( $service->all(), 'route' );
		foreach ( $post_only as $route ) {
			$this->assertNotContains( $route, $listed, $route );
			$this->assertNull( $service->find( $this->id( $route ) ), $route );
			$this->assertNull( $service->resolve( $route ), $route );
			$this->assertContains( 'rfrac_route_unregistered', $service->save( $this->id( $route ), array( 'status' => 'enabled' ) )->get_error_codes(), $route );
		}

		$this->assertContains( '/wp/v2/posts', $listed, 'A core route with a GET handler (and a POST one) is listed.' );
		$this->assertContains( '/myplugin/v1/write', $listed, 'A POST-only route of another namespace still is.' );
	}

	public function test_a_preset_leaves_out_the_routes_it_can_not_enable(): void {
		$service = $this->service();
		$this->with_presets( array( 'demo' => array( 'label' => 'Demo', 'routes' => array( self::ITEMS, '/myplugin/v1/write' ) ) ) );

		$this->assertFalse( $service->preset_enabled( 'demo' ) );
		$this->assertSame( 1, $service->enable_preset( 'demo' ) );
		$this->assertTrue( $service->preset_enabled( 'demo' ), 'The POST-only route, whose methods are not chosen, does not count.' );
		$this->assertFalse( $service->find( $this->id( '/myplugin/v1/write' ) )->enabled );
	}

	public function test_set_status_is_bulk_with_one_persist_and_skips(): void {
		$service = $this->service();
		$this->count_writes();

		$ids = array(
			$this->id( self::ITEMS ),
			$this->id( '/myplugin/v1/items/(?P<id>\d+)' ),
			$this->id( '/wp/v2/users/me' ),
			$this->id( '/nope/v1/x' ),
		);

		$this->assertSame( 2, $service->set_status( $ids, 'enabled' ) );
		$this->assertSame( 1, $this->writes );
		$this->assertTrue( $service->find( $ids[0] )->enabled );
		$this->assertTrue( $service->find( $ids[1] )->enabled );
		$this->assertFalse( $service->find( $ids[2] )->enabled );

		$this->assertSame( 0, $service->set_status( $ids, 'enabled' ) );
		$this->assertContains( 'rfrac_invalid_status', $service->set_status( $ids, 'bogus' )->get_error_codes() );
		$this->assertSame( 1, $this->writes );

		$this->assertSame( 2, $service->set_status( $ids, 'default' ) );
		$this->assertSame( array(), ( new Config() )->stored_route_rules() );
		$this->assertFalse( $service->find( $ids[0] )->enabled );

		$this->assertSame( 1, $service->set_status( array( $this->id( '/wp/v2/posts' ) ), 'excluded' ) );
		$this->assertFalse( $service->find( $this->id( '/wp/v2/posts' ) )->enabled );
	}

	public function test_filters_and_namespaces(): void {
		$service = $this->service();
		$service->save( $this->id( self::ITEMS ), array( 'status' => 'enabled' ) );

		$this->assertContains( 'myplugin/v1', $service->namespaces() );
		$this->assertContains( 'wp/v2', $service->namespaces() );

		$this->assertSame( array( '/myplugin/v1', self::ITEMS, '/myplugin/v1/items/(?P<id>\d+)', '/myplugin/v1/search', '/myplugin/v1/write' ), wp_list_pluck( $service->all( array( 'search' => 'MYPLUGIN' ) ), 'route' ) );
		$this->assertSame( array( '/myplugin/v1/write', '/myplugin/v1/search', '/myplugin/v1/items/(?P<id>\d+)', self::ITEMS, '/myplugin/v1' ), wp_list_pluck( $service->all( array( 'search' => 'myplugin', 'order' => 'desc' ) ), 'route' ) );
		$this->assertSame( array( self::ITEMS ), wp_list_pluck( $service->all( array( 'namespace' => 'myplugin/v1', 'view' => 'configured' ) ), 'route' ) );
		$this->assertSame( array( self::ITEMS ), wp_list_pluck( $service->all( array( 'namespace' => 'myplugin/v1', 'view' => 'enabled' ) ), 'route' ) );
		$this->assertSame( array( '/myplugin/v1', '/myplugin/v1/items/(?P<id>\d+)', '/myplugin/v1/search', '/myplugin/v1/write' ), wp_list_pluck( $service->all( array( 'namespace' => 'myplugin/v1', 'view' => 'disabled' ) ), 'route' ) );
		$this->assertSame( array(), $service->all( array( 'view' => 'locked' ) ) );

		$by_ns = wp_list_pluck( $service->all( array( 'orderby' => 'namespace' ) ), 'namespace' );
		$sorted = $by_ns;
		sort( $sorted );
		$this->assertSame( $sorted, $by_ns );
	}

	public function test_pattern_with_group_round_trips_unaltered(): void {
		$service = $this->service();
		$pattern = '/myplugin/v1/items/(?P<id>\d+)';
		$id      = Route_Service::id_for( $pattern );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $id );
		$this->assertSame( $pattern, $service->find( $id )->route );

		$this->assertInstanceOf( Route_Rule::class, $service->save( $id, array( 'status' => 'enabled', 'ttl' => 600 ) ) );
		$this->assertArrayHasKey( $pattern, ( new Config() )->stored_route_rules() );
		$this->assertSame( $pattern, $service->find( $id )->route );
		$this->assertSame( 600, $service->find( $id )->ttl );
	}

	public function test_all_is_memoised_per_instance(): void {
		$service = $this->service();
		$first   = $service->all();

		( new Config() )->save_route_rule( self::ITEMS, array( 'status' => 'enabled' ) );

		$this->assertSame( $first, $service->all() );
		$this->assertFalse( $service->find( $this->id( self::ITEMS ) )->enabled );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_rule_is_locked(): void {
		define( 'RFRAC_ROUTES', array( self::ITEMS => array( 'status' => 'enabled', 'ttl' => 900 ) ) );
		$service = $this->service();
		$view    = $service->find( $this->id( self::ITEMS ) );

		$this->assertTrue( $view->locked );
		$this->assertTrue( $view->enabled );
		$this->assertSame( 900, $view->ttl );
		$this->assertSame( 'constant', $view->source );
		$this->assertSame( array( self::ITEMS ), wp_list_pluck( $service->all( array( 'view' => 'locked' ) ), 'route' ) );

		$this->count_writes();
		$this->assertContains( 'rfrac_route_locked', $service->save( $view->id, array( 'status' => 'excluded' ) )->get_error_codes() );
		$this->assertSame( 0, $service->set_status( array( $view->id ), 'excluded' ) );
		$this->assertContains( 'rfrac_route_locked', $service->reset( $view->id )->get_error_codes() );
		$this->assertNothingPersisted( array() );
		$this->assertTrue( $service->find( $view->id )->enabled );
	}

	/**
	 * Assert no write happened and the stored rules are as before.
	 *
	 * @param array<string,mixed> $before Stored rules before the rejected call.
	 */
	private function assertNothingPersisted( array $before ): void {
		$this->assertSame( 0, $this->writes, 'No write expected' );
		$this->assertSame( $before, ( new Config() )->stored_route_rules() === array() ? array() : array_map( static fn( $r ) => $r->to_array(), ( new Config() )->stored_route_rules() ) );
	}

	public function test_rejected_saves_persist_nothing(): void {
		$service = $this->service();
		$this->count_writes();
		$id    = $this->id( self::ITEMS );
		$cases = array(
			'invalid input'    => array( $id, array( 'status' => 'bogus' ), 'rfrac_invalid_status' ),
			'ttl not a number' => array( $id, array( 'status' => 'enabled', 'ttl' => 'abc' ), 'rfrac_invalid_ttl' ),
			'denied core'      => array( $this->id( '/wp/v2/users/me' ), array( 'status' => 'enabled' ), 'rfrac_route_locked' ),
			'denied woo cart'  => array( $this->id( '/wc/store/v1/cart' ), array( 'status' => 'enabled' ), 'rfrac_route_locked' ),
			'unknown'          => array( $this->id( '/nope/v1/x' ), array( 'status' => 'enabled' ), 'rfrac_route_not_found' ),
			'delete only'      => array( $this->id( '/myplugin/v1/remove' ), array( 'status' => 'enabled', 'methods' => array( 'POST' ) ), 'rfrac_route_unregistered' ),
			'post only'        => array( $this->id( '/myplugin/v1/write' ), array( 'status' => 'enabled' ), 'rfrac_invalid_method' ),
			'unregistered put' => array( $this->id( '/myplugin/v1/write' ), array( 'status' => 'enabled', 'methods' => array( 'PUT' ) ), 'rfrac_invalid_method' ),
			'core post'        => array( $this->id( '/wp/v2/posts' ), array( 'status' => 'enabled', 'methods' => array( 'POST' ) ), 'rfrac_invalid_method' ),
			'delete method'    => array( $this->id( '/myplugin/v1/write' ), array( 'status' => 'enabled', 'methods' => array( 'DELETE' ) ), 'rfrac_invalid_method' ),
			'wildcard match'   => array( $id, array( 'status' => 'enabled', 'match' => 'wildcard' ), 'rfrac_invalid_match' ),
		);
		foreach ( $cases as $label => $case ) {
			$result = $service->save( $case[0], $case[1] );
			$this->assertWPError( $result, $label );
			$this->assertContains( $case[2], $result->get_error_codes(), $label );
		}
		$this->assertNothingPersisted( array() );
		$this->assertFalse( $service->find( $id )->enabled );
	}

	public function test_orphan_save_and_reset_persist_nothing_when_rejected(): void {
		$this->service();
		( new Config() )->save_route_rule( '/old/v1/x', array( 'status' => 'enabled' ) );
		$before  = array_map( static fn( $r ) => $r->to_array(), ( new Config() )->stored_route_rules() );
		$service = new Route_Service( new Config(), new Route_Discovery() );
		$this->count_writes();

		$this->assertContains( 'rfrac_route_unregistered', $service->save( $this->id( '/old/v1/x' ), array( 'status' => 'excluded' ) )->get_error_codes() );
		$this->assertContains( 'rfrac_route_not_found', $service->reset( $this->id( '/nope/v1/x' ) )->get_error_codes() );
		$this->assertNothingPersisted( $before );
	}

	public function test_set_status_rejections_persist_nothing(): void {
		$service = $this->service();
		$this->count_writes();
		$ids = array( $this->id( '/wp/v2/users/me' ), $this->id( '/wc/store/v1/cart' ), $this->id( '/nope/v1/x' ), $this->id( '/myplugin/v1/write' ) );

		$this->assertSame( 0, $service->set_status( $ids, 'enabled' ) );
		$this->assertWPError( $service->set_status( $ids, 'bogus' ) );
		$this->assertNothingPersisted( array() );
		$this->assertFalse( $service->find( $this->id( '/wc/store/v1/cart' ) )->enabled );
	}

	public function test_reset_without_override_is_false(): void {
		$service = $this->service();
		$this->count_writes();

		$this->assertFalse( $service->reset( $this->id( self::ITEMS ) ) );
		$this->assertNothingPersisted( array() );
	}

	public function test_reset_removes_registered_override(): void {
		$service = $this->service();
		$id      = $this->id( self::ITEMS );
		$service->save( $id, array( 'status' => 'enabled', 'ttl' => 600 ) );

		$this->assertTrue( $service->reset( $id ) );
		$this->assertSame( array(), ( new Config() )->stored_route_rules() );
		$this->assertNull( $service->find( $id )->rule );
	}

	public function test_ttl_is_clamped_to_max_ttl(): void {
		$service = $this->service();
		$max     = (int) Config::instance()->settings()['max_ttl'];

		$rule = $service->save( $this->id( self::ITEMS ), array( 'status' => 'enabled', 'ttl' => $max + 1000 ) );

		$this->assertInstanceOf( Route_Rule::class, $rule );
		$this->assertSame( $max, $rule->ttl );
		$this->assertSame( $max, $service->find( $this->id( self::ITEMS ) )->ttl );
	}

	public function test_filter_source_and_denied_via_filter(): void {
		$service = $this->service();
		add_filter(
			Rockfire\Rest_Api_Cache\Hooks::ROUTE_RULES,
			static function ( array $rules ): array {
				$rules['/myplugin/v1/items']     = array( 'status' => 'enabled', 'ttl' => 777 );
				$rules['/wc/store/v1/cart']      = array( 'status' => 'enabled' );
				$rules['/wp/v2/users/me']        = array( 'status' => 'enabled' );
				return $rules;
			}
		);
		$service->flush();

		$items = $service->find( $this->id( self::ITEMS ) );
		$this->assertSame( 'filter', $items->source );
		$this->assertTrue( $items->enabled );
		$this->assertSame( 777, $items->ttl );
		foreach ( array( '/wc/store/v1/cart', '/wp/v2/users/me' ) as $route ) {
			$view = $service->find( $this->id( $route ) );
			$this->assertTrue( $view->is_denied, $route );
			$this->assertFalse( $view->enabled, $route );
		}
	}

	public function test_set_status_keeps_other_fields(): void {
		$service = $this->service();
		$id      = $this->id( self::ITEMS );
		$service->save( $id, array( 'status' => 'excluded', 'ttl' => 600, 'param_mode' => 'allow_all' ) );

		$this->assertSame( 1, $service->set_status( array( $id ), 'enabled' ) );

		$rule = $service->find( $id )->rule;
		$this->assertSame( 'enabled', $rule->status );
		$this->assertSame( 600, $rule->ttl );
		$this->assertSame( 'allow_all', $rule->param_mode );
	}

	public function test_set_status_keeps_search_bypass_of_default_route(): void {
		$service = $this->service();
		$posts   = $this->id( '/wp/v2/posts' );
		$this->count_writes();

		// Already enabled by default: nothing to change.
		$this->assertSame( 0, $service->set_status( array( $posts ), 'enabled' ) );
		$this->assertSame( 0, $service->set_status( array( $posts ), 'default' ) );
		$this->assertNothingPersisted( array() );

		$this->assertSame( 1, $service->set_status( array( $posts ), 'excluded' ) );
		$this->assertSame( 1, $service->set_status( array( $posts ), 'enabled' ) );

		// Back to the built-in rule: the override is gone and `search` still bypasses.
		$this->assertSame( array(), ( new Config() )->stored_route_rules() );
		$view = $service->find( $posts );
		$this->assertTrue( $view->enabled );
		$this->assertNull( $view->rule );

		$this->assertSame( 1, $service->set_status( array( $posts ), 'excluded' ) );
		$this->assertSame( 1, $service->set_status( array( $posts ), 'default' ) );
		$this->assertSame( array(), ( new Config() )->stored_route_rules() );
		$effective = ( new Config() )->route_rules( rest_get_server() )['/wp/v2/posts'];
		$this->assertSame( array( 'search' ), $effective->rule->bypass_params );
	}

	public function test_save_builtin_rule_of_default_route_removes_override(): void {
		$service = $this->service();
		$posts   = $this->id( '/wp/v2/posts' );
		$service->save( $posts, array( 'status' => 'excluded' ) );
		$this->assertArrayHasKey( '/wp/v2/posts', ( new Config() )->stored_route_rules() );

		$builtin = Config::default_route_rule()->to_array();
		$this->assertInstanceOf( Route_Rule::class, $service->save( $posts, $builtin ) );
		$this->assertSame( array(), ( new Config() )->stored_route_rules() );

		// `status: default` with the built-in fields counts as the built-in rule too.
		$service->save( $posts, array( 'status' => 'excluded' ) );
		$this->assertInstanceOf( Route_Rule::class, $service->save( $posts, array( 'status' => 'default' ) + $builtin ) );
		$this->assertSame( array(), ( new Config() )->stored_route_rules() );

		// Without the `search` bypass it is a real override.
		$service->save( $posts, array( 'status' => 'enabled' ) );
		$this->assertArrayHasKey( '/wp/v2/posts', ( new Config() )->stored_route_rules() );
	}

	public function test_enabled_requires_registered_match(): void {
		$service = $this->service();
		add_filter(
			Rockfire\Rest_Api_Cache\Hooks::ROUTE_RULES,
			static function ( array $rules ): array {
				$rules['/myplugin/v1/items'] = array( 'status' => 'enabled', 'match' => 'wildcard' );
				return $rules;
			}
		);
		$service->flush();

		$this->assertFalse( $service->find( $this->id( self::ITEMS ) )->enabled );
	}

	public function test_orphans_respect_deny_list_and_match_mode(): void {
		$this->service();
		$config = new Config();
		$this->assertTrue(
			$config->save_route_rules(
				array(
					'/wc/store/v1/checkout' => array( 'status' => 'enabled' ),
					'/myplugin/v1/gone'     => array( 'status' => 'enabled' ),
					'/wc/store/v1/*'        => array( 'status' => 'enabled', 'match' => 'wildcard' ),
					'/old/v1/*'             => array( 'status' => 'enabled', 'match' => 'wildcard' ),
					'/older/v1/*'           => array( 'status' => 'excluded', 'match' => 'wildcard' ),
				)
			)->is_valid()
		);
		$service = new Route_Service( $config, new Route_Discovery() );

		$denied = $service->find( $this->id( '/wc/store/v1/checkout' ) );
		$this->assertTrue( $denied->is_denied );
		$this->assertFalse( $denied->enabled );

		$registered_match = $service->find( $this->id( '/myplugin/v1/gone' ) );
		$this->assertFalse( $registered_match->is_denied );
		$this->assertFalse( $registered_match->enabled );

		$wild_denied = $service->find( $this->id( '/wc/store/v1/*' ) );
		$this->assertTrue( $wild_denied->is_denied );
		$this->assertFalse( $wild_denied->enabled );

		$wild_ok = $service->find( $this->id( '/old/v1/*' ) );
		$this->assertFalse( $wild_ok->is_denied );
		$this->assertTrue( $wild_ok->enabled );

		$this->assertFalse( $service->find( $this->id( '/older/v1/*' ) )->enabled );

		$enabled = wp_list_pluck( $service->all( array( 'view' => 'enabled' ) ), 'route' );
		$this->assertContains( '/old/v1/*', $enabled );
		$this->assertNotContains( '/wc/store/v1/checkout', $enabled );
		$this->assertNotContains( '/wc/store/v1/*', $enabled );
	}

	public function test_flush_clears_the_memo(): void {
		$service = $this->service();
		$service->all();
		( new Config() )->save_route_rule( self::ITEMS, array( 'status' => 'enabled' ) );
		$this->assertFalse( $service->find( $this->id( self::ITEMS ) )->enabled );

		$service->flush();

		$this->assertTrue( $service->find( $this->id( self::ITEMS ) )->enabled );
	}

	/**
	 * Make every write of the settings option fail (the UPDATE matches no row).
	 */
	private function fail_settings_writes(): void {
		$this->fail_option_writes( Names::OPTION_SETTINGS );
	}

	public function test_save_reports_the_ttl_adjustment(): void {
		$service = $this->service();
		$id      = $this->id( self::ITEMS );
		$max     = (int) Config::instance()->settings()['max_ttl'];

		$adjusted = 0;
		$this->assertInstanceOf( Route_Rule::class, $service->save( $id, array( 'status' => 'enabled', 'ttl' => 30 ), $adjusted ) );
		$this->assertSame( 60, $adjusted );

		$this->assertInstanceOf( Route_Rule::class, $service->save( $id, array( 'status' => 'enabled', 'ttl' => $max + 1000 ), $adjusted ) );
		$this->assertSame( $max, $adjusted );

		foreach ( array( 600, null ) as $ttl ) {
			$this->assertInstanceOf( Route_Rule::class, $service->save( $id, array( 'status' => 'enabled', 'ttl' => $ttl ), $adjusted ) );
			$this->assertNull( $adjusted );
		}

		$service->save( $id, array( 'status' => 'enabled', 'ttl' => 'abc' ), $adjusted );
		$this->assertNull( $adjusted, 'A rejected save adjusts nothing.' );
	}

	public function test_failed_write_is_reported_but_an_unchanged_save_is_not(): void {
		$service = $this->service();
		$id      = $this->id( self::ITEMS );
		$this->assertInstanceOf( Route_Rule::class, $service->save( $id, array( 'status' => 'enabled', 'ttl' => 600 ) ) );
		$this->assertInstanceOf( Route_Rule::class, $service->save( $id, array( 'status' => 'enabled', 'ttl' => 600 ) ), 'Saving the same rule again is not a failure.' );

		$this->fail_settings_writes();
		$result = $service->save( $id, array( 'status' => 'enabled', 'ttl' => 900 ) );
		$this->assertWPError( $result );
		$this->assertSame( 'rfrac_save_failed', $result->get_error_code() );
		$this->assertSame( 600, ( new Config() )->stored_route_rules()[ self::ITEMS ]->ttl );

		$this->assertSame( 'rfrac_save_failed', $service->reset( $id )->get_error_code() );
		$this->assertSame( 'rfrac_save_failed', $service->set_status( array( $id ), 'excluded' )->get_error_code() );
	}

	public function test_config_saves_report_a_failed_write(): void {
		$this->service();
		$config = new Config();
		$this->assertTrue( $config->save_settings( array( 'default_ttl' => 600 ) )->is_valid() );
		$this->assertTrue( $config->save_settings( array( 'default_ttl' => 600 ) )->is_valid(), 'An unchanged value is not a failure.' );

		$this->fail_settings_writes();
		$this->assertTrue( $config->save_settings( array( 'default_ttl' => 900 ) )->has_code( Config::SAVE_FAILED ) );
		$this->assertTrue( $config->save_route_rule( self::ITEMS, array( 'status' => 'enabled' ) )->has_code( Config::SAVE_FAILED ) );
		$this->assertSame( 600, $config->settings()['default_ttl'] );
	}

	public function test_resolve_skips_and_records_a_broken_pattern(): void {
		$this->new_rest_server(
			static function () {
				$get = array(
					'methods'             => 'GET',
					'callback'            => '__return_true',
					'permission_callback' => '__return_true',
				);
				register_rest_route( 'broken/v1', '/(?P<x', $get );
				register_rest_route( 'broken/v1', '/(?P<y>.+)', $get );
			}
		);
		delete_option( Rockfire\Rest_Api_Cache\Failure_Recorder::OPTION );
		$service = new Route_Service( new Config(), new Route_Discovery() );

		$view = $service->resolve( '/broken/v1/z' );

		$this->assertNotNull( $view );
		$this->assertSame( '/broken/v1/(?P<y>.+)', $view->route, 'Like core, the broken pattern does not match.' );
		$last = Rockfire\Rest_Api_Cache\Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'routes', $last['context'] );
		$this->assertStringContainsString( '/broken/v1/(?P<x', $last['message'] );
	}

	/**
	 * Store raw route rules, bypassing validation.
	 *
	 * @param array<string,mixed> $routes Route key => raw rule.
	 */
	private function store_raw_rules( array $routes ): void {
		update_option( Names::OPTION_SETTINGS, array( 'routes' => $routes ), false );
	}

	public function test_invalid_stored_rules_are_listed_and_can_be_reset(): void {
		$service = $this->service();
		$this->store_raw_rules(
			array(
				'/wp/v2/posts' => array( 'status' => 'bogus' ),
				'/gone/v1/x'   => 'not an array',
			)
		);
		$service->flush();

		$posts = $service->find( $this->id( '/wp/v2/posts' ) );
		$this->assertNotNull( $posts );
		$this->assertNotNull( $posts->invalid );
		$this->assertFalse( $posts->enabled, 'The invalid rule fails closed.' );
		$this->assertNotNull( $posts->rule, 'It counts as configured, so the list offers Reset.' );
		$this->assertSame( 'excluded', $posts->rule->status );

		$gone = $service->find( $this->id( '/gone/v1/x' ) );
		$this->assertNotNull( $gone, 'An invalid orphan rule is listed.' );
		$this->assertFalse( $gone->is_registered );
		$this->assertSame( 'The rule is not an array.', $gone->invalid );
		$this->assertContains( '/gone/v1/x', wp_list_pluck( $service->all( array( 'view' => 'configured' ) ), 'route' ) );

		$this->assertTrue( $service->reset( $gone->id ) );
		$this->assertTrue( $service->reset( $posts->id ) );
		$this->assertSame( array(), get_option( Names::OPTION_SETTINGS )['routes'], 'Both raw keys are gone.' );
		$this->assertTrue( $service->find( $this->id( '/wp/v2/posts' ) )->enabled, 'The default applies again.' );
		$this->assertNull( $service->find( $this->id( '/gone/v1/x' ) ) );
	}

	public function test_set_status_replaces_an_invalid_stored_rule(): void {
		$service = $this->service();
		$this->store_raw_rules( array( '/wp/v2/posts' => array( 'status' => 'bogus' ) ) );
		$service->flush();

		$this->assertSame( 1, $service->set_status( array( $this->id( '/wp/v2/posts' ) ), 'enabled' ), 'Not skipped although the shown rule would resolve the same.' );

		$view = $service->find( $this->id( '/wp/v2/posts' ) );
		$this->assertNull( $view->invalid );
		$this->assertTrue( $view->enabled );
	}

	public function test_saving_another_route_keeps_an_invalid_stored_rule_visible(): void {
		$service = $this->service();
		$this->store_raw_rules( array( '/gone/v1/x' => array( 'status' => 'bogus' ) ) );
		$service->flush();

		$this->assertInstanceOf( Route_Rule::class, $service->save( $this->id( self::ITEMS ), array( 'status' => 'enabled' ) ) );

		$routes = get_option( Names::OPTION_SETTINGS )['routes'];
		$this->assertSame( array( 'status' => 'bogus' ), $routes['/gone/v1/x'], 'Kept as stored until it is reset.' );
		$this->assertArrayHasKey( self::ITEMS, $routes );
	}

	public function test_persist_compares_the_stored_value_strictly(): void {
		$this->service();
		$persist = new ReflectionMethod( Config::class, 'persist' );
		$persist->setAccessible( true );
		update_option( Names::OPTION_SETTINGS, array( 'default_ttl' => '600', 'routes' => array() ), false );

		$this->assertTrue( $persist->invoke( new Config(), array( 'default_ttl' => '600' ), array() ), 'An unchanged value is a success.' );

		$this->fail_settings_writes();
		$this->assertFalse( $persist->invoke( new Config(), array( 'default_ttl' => 600 ), array() ), 'An int where a string is stored is a change, and the failed write is reported.' );
		$this->assertSame( '600', get_option( Names::OPTION_SETTINGS )['default_ttl'] );
	}

	// ---------- Route presets ----------

	/**
	 * Register the presets the `rfrac/route_presets` filter returns.
	 *
	 * @param mixed $presets Filter result.
	 */
	private function with_presets( $presets ): void {
		add_filter(
			Hooks::ROUTE_PRESETS,
			static function () use ( $presets ) {
				return $presets;
			}
		);
	}

	public function test_there_are_no_presets_by_default(): void {
		$service = $this->service();
		$this->assertSame( array(), $service->presets() );
		$this->assertWPError( $service->enable_preset( 'demo' ) );
	}

	public function test_enable_preset_enables_its_routes_with_one_write_and_counts_the_changed_ones(): void {
		$service = $this->service();
		$routes  = array( self::ITEMS, '/myplugin/v1/items/(?P<id>\d+)', '/wp/v2/posts' );
		$this->with_presets( array( 'demo' => array( 'label' => 'Demo', 'routes' => $routes ) ) );
		$this->count_writes();

		$this->assertTrue( $service->find( $this->id( '/wp/v2/posts' ) )->enabled, 'One route of the preset is enabled already.' );
		$this->assertFalse( $service->find( $this->id( self::ITEMS ) )->enabled );

		$this->assertSame( 2, $service->enable_preset( 'demo' ) );
		$this->assertSame( 1, $this->writes );
		foreach ( $routes as $route ) {
			$this->assertTrue( $service->find( $this->id( $route ) )->enabled, $route );
		}

		$this->assertSame( 0, $service->enable_preset( 'demo' ) );
		$this->assertSame( 1, $this->writes, 'Nothing to change: no second write.' );
	}

	public function test_enable_preset_leaves_denied_unregistered_and_unreadable_routes_alone(): void {
		$service = $this->service();
		$this->with_presets(
			array(
				'demo' => array(
					'label'  => 'Demo',
					'routes' => array( '/wc/store/v1/cart', '/wp/v2/users/me', '/nope/v1/x', '/myplugin/v1/write' ),
				),
			)
		);
		$this->count_writes();

		$this->assertSame( 0, $service->enable_preset( 'demo' ) );
		$this->assertNothingPersisted( array() );
		$this->assertFalse( $service->find( $this->id( '/wc/store/v1/cart' ) )->enabled );
		$this->assertNull( $service->find( $this->id( '/nope/v1/x' ) ), 'An unregistered route is not created.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enable_preset_leaves_a_constant_locked_route_alone(): void {
		define( 'RFRAC_ROUTES', array( self::ITEMS => array( 'status' => 'excluded', 'ttl' => 900 ) ) );
		$service = $this->service();
		$this->with_presets( array( 'demo' => array( 'label' => 'Demo', 'routes' => array( self::ITEMS, '/wc/store/v1/cart', '/nope/v1/x' ) ) ) );
		$this->count_writes();

		$this->assertTrue( $service->find( $this->id( self::ITEMS ) )->locked );
		$this->assertSame( 0, $service->enable_preset( 'demo' ) );
		$this->assertNothingPersisted( array() );

		$view = $service->find( $this->id( self::ITEMS ) );
		$this->assertFalse( $view->enabled );
		$this->assertSame( 'excluded', $view->rule->status );
		$this->assertSame( 900, $view->rule->ttl );
	}

	public function test_enable_preset_keeps_the_ttl_of_an_excluded_route(): void {
		$service = $this->service();
		$id      = $this->id( self::ITEMS );
		$this->assertInstanceOf( Route_Rule::class, $service->save( $id, array( 'status' => 'excluded', 'ttl' => 900 ) ) );
		$this->with_presets( array( 'demo' => array( 'label' => 'Demo', 'routes' => array( self::ITEMS ) ) ) );

		$this->assertSame( 1, $service->enable_preset( 'demo' ) );

		$view = $service->find( $id );
		$this->assertTrue( $view->enabled );
		$this->assertSame( 'enabled', $view->rule->status );
		$this->assertSame( 900, $view->rule->ttl );
	}

	public function test_disable_preset_returns_its_routes_to_their_default_with_one_write(): void {
		$service = $this->service();
		$routes  = array( self::ITEMS, '/myplugin/v1/items/(?P<id>\d+)' );
		$this->with_presets( array( 'demo' => array( 'label' => 'Demo', 'routes' => $routes ) ) );
		$this->assertSame( 2, $service->enable_preset( 'demo' ) );
		$this->count_writes();

		$this->assertSame( 2, $service->disable_preset( 'demo' ) );
		$this->assertSame( 1, $this->writes );
		foreach ( $routes as $route ) {
			$view = $service->find( $this->id( $route ) );
			$this->assertFalse( $view->enabled, $route );
			$this->assertNull( $view->rule, 'The stored rule of ' . $route . ' is removed: it equals the default.' );
		}

		$this->assertSame( 0, $service->disable_preset( 'demo' ) );
		$this->assertSame( 1, $this->writes, 'Nothing to change: no second write.' );
	}

	public function test_disable_preset_keeps_the_ttl_of_an_enabled_route(): void {
		$service = $this->service();
		$id      = $this->id( self::ITEMS );
		$this->assertInstanceOf( Route_Rule::class, $service->save( $id, array( 'status' => 'enabled', 'ttl' => 900 ) ) );
		$this->with_presets( array( 'demo' => array( 'label' => 'Demo', 'routes' => array( self::ITEMS ) ) ) );

		$this->assertSame( 1, $service->disable_preset( 'demo' ) );

		$view = $service->find( $id );
		$this->assertFalse( $view->enabled );
		$this->assertSame( 'default', $view->rule->status );
		$this->assertSame( 900, $view->rule->ttl );
	}

	public function test_disable_preset_counts_the_routes_it_can_not_change_and_rejects_an_unknown_key(): void {
		$service = $this->service();
		$this->with_presets( array( 'demo' => array( 'label' => 'Demo', 'routes' => array( self::ITEMS, '/wc/store/v1/cart', '/nope/v1/x' ) ) ) );
		$this->assertSame( 1, $service->enable_preset( 'demo' ) );

		$skipped = null;
		$this->assertSame( 1, $service->disable_preset( 'demo', $skipped ) );
		$this->assertSame( 2, $skipped );

		$result = $service->disable_preset( 'nope' );
		$this->assertWPError( $result );
		$this->assertSame( 'rfrac_preset_not_found', $result->get_error_code() );
	}

	public function test_a_preset_is_enabled_once_every_route_it_can_change_is_cached(): void {
		$service = $this->service();
		$this->with_presets( array( 'demo' => array( 'label' => 'Demo', 'routes' => array( self::ITEMS, '/myplugin/v1/items/(?P<id>\d+)', '/wc/store/v1/cart', '/nope/v1/x' ) ) ) );

		$this->assertFalse( $service->preset_enabled( 'demo' ) );

		$this->assertInstanceOf( Route_Rule::class, $service->save( $this->id( self::ITEMS ), array( 'status' => 'enabled' ) ) );
		$this->assertFalse( $service->preset_enabled( 'demo' ), 'One of the two routes it can change is not cached yet.' );

		$service->enable_preset( 'demo' );
		$this->assertTrue( $service->preset_enabled( 'demo' ), 'The denied and unregistered routes do not count.' );

		$service->disable_preset( 'demo' );
		$this->assertFalse( $service->preset_enabled( 'demo' ) );
		$this->assertFalse( $service->preset_enabled( 'nope' ) );
	}

	public function test_a_preset_without_a_route_it_can_change_is_not_enabled(): void {
		$service = $this->service();
		$this->with_presets( array( 'demo' => array( 'label' => 'Demo', 'routes' => array( '/wc/store/v1/cart', '/nope/v1/x' ) ) ) );

		$this->assertFalse( $service->preset_enabled( 'demo' ) );
	}

	public function test_presets_drops_the_invalid_entries_and_keeps_their_valid_siblings(): void {
		$service = $this->service();
		$valid   = array( 'label' => 'Valid', 'routes' => array( self::ITEMS ) );
		$this->with_presets(
			array(
				'Bad Key'             => $valid,
				'UPPER'               => $valid,
				str_repeat( 'a', 65 ) => $valid,
				'empty-label'         => array( 'label' => '', 'routes' => array( self::ITEMS ) ),
				'array-label'         => array( 'label' => array( 'Valid' ), 'routes' => array( self::ITEMS ) ),
				'no-label'            => array( 'routes' => array( self::ITEMS ) ),
				'no-routes'           => array( 'label' => 'None', 'routes' => array() ),
				'missing-routes'      => array( 'label' => 'None' ),
				'string-routes'       => array( 'label' => 'None', 'routes' => self::ITEMS ),
				'keyed-routes'        => array( 'label' => 'None', 'routes' => array( 'items' => self::ITEMS ) ),
				'non-string-route'    => array( 'label' => 'None', 'routes' => array( self::ITEMS, 5 ) ),
				'not-an-array'        => 'Valid',
				'null'                => null,
				'valid_1'             => $valid,
				str_repeat( 'b', 64 ) => $valid,
				'valid-2'             => array( 'label' => 'Two', 'routes' => array( '/a', '/b' ) ),
			)
		);

		$this->assertSame(
			array(
				'valid_1'             => $valid,
				str_repeat( 'b', 64 ) => $valid,
				'valid-2'             => array( 'label' => 'Two', 'routes' => array( '/a', '/b' ) ),
			),
			$service->presets()
		);
		$this->assertSame( array( 'valid_1', str_repeat( 'b', 64 ), 'valid-2' ), array_keys( $service->presets() ) );
	}

	public function test_a_filter_result_that_is_not_an_array_means_no_presets(): void {
		$service = $this->service();
		$this->with_presets( 'demo' );
		$this->assertSame( array(), $service->presets() );
	}

	public function test_an_unknown_or_dropped_preset_is_not_found_and_persists_nothing(): void {
		$service = $this->service();
		$this->with_presets(
			array(
				'demo'    => array( 'label' => 'Demo', 'routes' => array( self::ITEMS ) ),
				'Bad Key' => array( 'label' => 'Bad', 'routes' => array( self::ITEMS ) ),
				'empty'   => array( 'label' => 'Empty', 'routes' => array() ),
			)
		);
		$this->count_writes();

		foreach ( array( 'nope', 'Bad Key', 'bad-key', 'empty', '' ) as $key ) {
			$result = $service->enable_preset( $key );
			$this->assertWPError( $result, $key );
			$this->assertSame( array( 'rfrac_preset_not_found' ), $result->get_error_codes(), $key );
		}
		$this->assertNothingPersisted( array() );
		$this->assertFalse( $service->find( $this->id( self::ITEMS ) )->enabled );
	}

	public function test_a_failed_preset_write_is_reported(): void {
		$service = $this->service();
		$this->with_presets( array( 'demo' => array( 'label' => 'Demo', 'routes' => array( self::ITEMS ) ) ) );
		$this->fail_settings_writes();

		$result = $service->enable_preset( 'demo' );

		$this->assertWPError( $result );
		$this->assertContains( 'rfrac_save_failed', $result->get_error_codes() );
	}
}
