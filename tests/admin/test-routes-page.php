<?php
/**
 * Tests for the Routes admin page: actions, bulk actions and the edit view.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Action_Handler;
use Rockfire\Rest_Api_Cache\Admin\Admin;
use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Admin\Capability;
use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Admin\Pages\Routes_Page;
use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Config\Route_Discovery;
use Rockfire\Rest_Api_Cache\Config\Route_Rule;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Services\Route_Service;
use Rockfire\Rest_Api_Cache\Services\Settings_Service;

/**
 * @covers \Rockfire\Rest_Api_Cache\Admin\Pages\Routes_Page
 */
class Test_Routes_Page extends Rfrac_Test_Case {

	use Rfrac_Admin_Test_Helpers;
	use Rfrac_Fails_Option_Writes;

	private const ITEMS = '/myplugin/v1/items';

	private const ITEM = '/myplugin/v1/items/(?P<id>[\d]+)';

	/**
	 * Page under test.
	 *
	 * @var Routes_Page
	 */
	private Routes_Page $page;

	/**
	 * Route service.
	 *
	 * @var Route_Service
	 */
	private Route_Service $routes;

	public function set_up() {
		parent::set_up();
		$this->new_rest_server(
			static function () {
				$get = array(
					'methods'             => 'GET',
					'callback'            => '__return_true',
					'permission_callback' => '__return_true',
				);
				register_rest_route( 'myplugin/v1', '/items', $get + array( 'args' => array( 'page' => array() ) ) );
				register_rest_route( 'myplugin/v1', '/items/(?P<id>[\d]+)', $get );
				register_rest_route( 'myplugin/v1', '/<b>x</b>', $get );
				register_rest_route(
					'myplugin/v1',
					'/search',
					array(
						$get + array( 'args' => array( 'q' => array() ) ),
						array(
							'methods'             => 'POST',
							'callback'            => '__return_true',
							'permission_callback' => '__return_true',
							'args'                => array( 'term' => array() ),
						),
					)
				);
				register_rest_route(
					'myplugin/v1',
					'/lookup',
					array(
						'methods'             => array( 'POST', 'PUT', 'PATCH' ),
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
						'args'                => array( 'key' => array() ),
					)
				);
			}
		);
		register_post_type( 'product', array( 'public' => true, 'show_in_rest' => true ) );
		update_option( Names::OPTION_SETTINGS, array(), false );
		add_action(
			'update_option_' . Names::OPTION_SETTINGS,
			static function () {
				( new Config_Compiler() )->compile( rest_get_server() );
			}
		);
		$config       = new Config();
		$this->routes = new Route_Service( $config, new Route_Discovery() );
		$this->page   = new Routes_Page( new Notices(), $this->routes, Plugin::instance()->cache_service(), new Settings_Service( $config ) );
		$admin        = new Admin( new Capability(), new Notices(), Plugin::instance()->status_service(), Plugin::instance()->cache_service(), array( $this->page ) );
		$admin->register();
		$this->intercept_redirect();
	}

	public function tear_down() {
		_unregister_post_type( 'product' );
		$this->reset_admin_request();
		parent::tear_down();
	}

	private function id( string $route = self::ITEMS ): string {
		return Route_Service::id_for( $route );
	}

	/**
	 * Post an admin-post action with a valid nonce and return the redirect.
	 *
	 * @param string              $action Action.
	 * @param string              $target Target.
	 * @param array<string,mixed> $post   Extra POST fields.
	 * @param string|null         $nonce_target Target of the nonce (defaults to the target).
	 * @param string|null         $nonce_action Action of the nonce (defaults to the action).
	 */
	private function post_action( string $action, string $target, array $post = array(), ?string $nonce_target = null, ?string $nonce_action = null ): Redirect_Intercepted {
		$post = $post + array(
			'action'   => 'rfrac_' . $action,
			'target'   => $target,
			'_wpnonce' => wp_create_nonce( Action_Handler::nonce_action( $nonce_action ?? $action, $nonce_target ?? $target ) ),
		);
		$this->set_request( array(), $post );
		try {
			do_action( 'admin_post_rfrac_' . $action );
		} catch ( Redirect_Intercepted $redirect ) {
			return $redirect;
		}
		$this->fail( 'Expected a redirect.' );
	}

	/**
	 * The messages of the queued notices.
	 *
	 * @return string[]
	 */
	private function notices(): array {
		return array_column( ( new Notices() )->queued(), 'message' );
	}

	private function stored_rules(): array {
		$settings = get_option( Names::OPTION_SETTINGS, array() );
		return is_array( $settings ) && isset( $settings['routes'] ) ? (array) $settings['routes'] : array();
	}

	private function enable_items(): Redirect_Intercepted {
		return $this->post_action(
			'save_route',
			$this->id(),
			array(
				'status'           => 'enabled',
				'ttl'              => '600',
				'param_mode'       => 'registered',
				'ignore_params'    => 'utm_source',
				'flush_post_types' => array( 'product' ),
			)
		);
	}

	// ---------- Identity ----------

	public function test_slug_position_and_actions(): void {
		$this->assertSame( 'rfrac-routes', $this->page->slug() );
		$this->assertSame( 10, $this->page->position() );
		$this->assertSame( array( 'save_route', 'reset_route', 'flush_route_cache', 'enable_route_preset', 'disable_route_preset' ), array_keys( $this->page->actions() ) );
	}

	// ---------- Capability and nonce, every action ----------

	/**
	 * @return array<string,array{string}>
	 */
	public function action_provider(): array {
		return array(
			'save'    => array( 'save_route' ),
			'reset'   => array( 'reset_route' ),
			'flush'   => array( 'flush_route_cache' ),
			'preset'  => array( 'enable_route_preset' ),
			'disable' => array( 'disable_route_preset' ),
		);
	}

	/**
	 * Seed an override and a cached entry for both item routes, so a rejected action has something it could have changed.
	 *
	 * @return array{rules:array<string,mixed>,entries:list<int>}
	 */
	private function seed(): array {
		$user = get_current_user_id();
		$this->as_admin();
		$entries = array();
		foreach ( array( self::ITEMS, self::ITEM ) as $route ) {
			$this->assertInstanceOf( Route_Rule::class, $this->routes->save( $this->id( $route ), array( 'status' => 'excluded', 'ttl' => 900 ) ) );
			$entries[] = Rfrac_Entry_Fixtures::insert_entry(
				array(
					'route'    => $route,
					'rule_key' => $route,
					'request'  => $route,
				)
			);
		}
		wp_set_current_user( $user );
		$rules = $this->stored_rules();
		$this->assertArrayHasKey( self::ITEMS, $rules );
		return array(
			'rules'   => $rules,
			'entries' => $entries,
		);
	}

	/**
	 * Assert that the seeded state is unchanged.
	 *
	 * @param array{rules:array<string,mixed>,entries:list<int>} $seed Seeded state.
	 */
	private function assert_unchanged( array $seed ): void {
		$this->assertSame( $seed['rules'], $this->stored_rules() );
		foreach ( $seed['entries'] as $id ) {
			$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ) );
		}
		$this->assertSame( array(), $this->notices() );
	}

	/**
	 * @dataProvider action_provider
	 */
	public function test_action_without_capability_dies_and_changes_nothing( string $action ): void {
		$seed = $this->seed();
		$this->as_subscriber();
		try {
			$this->post_action( $action, $this->id(), array( 'status' => 'enabled' ) );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assert_unchanged( $seed );
	}

	/**
	 * @dataProvider action_provider
	 */
	public function test_action_with_a_bad_nonce_dies_and_changes_nothing( string $action ): void {
		$this->as_admin();
		$seed = $this->seed();
		try {
			$this->post_action( $action, $this->id(), array( 'status' => 'enabled', '_wpnonce' => 'bad' ) );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assert_unchanged( $seed );
	}

	/**
	 * @dataProvider action_provider
	 */
	public function test_a_nonce_of_route_a_is_rejected_for_route_b( string $action ): void {
		$this->as_admin();
		$seed = $this->seed();
		try {
			$this->post_action( $action, $this->id( self::ITEM ), array( 'status' => 'enabled' ), $this->id() );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assert_unchanged( $seed );
	}

	public function test_a_nonce_of_another_action_is_rejected(): void {
		$this->as_admin();
		$seed = $this->seed();
		foreach ( array( 'reset_route' => 'flush_route_cache', 'flush_route_cache' => 'save_route', 'save_route' => 'reset_route', 'enable_route_preset' => 'save_route', 'disable_route_preset' => 'enable_route_preset' ) as $action => $other ) {
			try {
				$this->post_action( $action, $this->id(), array( 'status' => 'enabled' ), null, $other );
				$this->fail( $action . ' accepted the nonce of ' . $other );
			} catch ( WPDieException $e ) {
				$this->assertSame( 403, $e->getCode() );
			}
		}
		$this->assert_unchanged( $seed );
	}

	// ---------- save_route ----------

	public function test_save_route_persists_the_rule_recompiles_and_redirects_to_the_list(): void {
		$this->as_admin();
		$redirect = $this->enable_items();

		$this->assertSame( Admin_Url::page( 'rfrac-routes' ), $redirect->location );
		$this->assertContains( 'Route saved.', $this->notices() );
		$rule = $this->stored_rules()[ self::ITEMS ];
		$this->assertSame( 'enabled', $rule['status'] );
		$this->assertSame( 600, $rule['ttl'] );
		$this->assertSame( array( 'utm_source' ), $rule['ignore_params'] );
		$this->assertSame( array( 'product' ), $rule['flush_post_types'] );

		$keys = array_map( static fn( $r ) => $r->key, Compiled_Config::load()->routes() );
		$this->assertContains( self::ITEMS, $keys, 'The compiled config contains the new rule.' );
	}

	public function test_an_empty_ttl_means_the_site_default(): void {
		$this->as_admin();
		$this->post_action( 'save_route', $this->id(), array( 'status' => 'enabled', 'ttl' => '' ) );
		$this->assertNull( $this->stored_rules()[ self::ITEMS ]['ttl'] );
	}

	public function test_a_clamped_ttl_is_reported(): void {
		$this->as_admin();
		$this->post_action( 'save_route', $this->id(), array( 'status' => 'enabled', 'ttl' => '5' ) );

		$this->assertSame( 60, $this->stored_rules()[ self::ITEMS ]['ttl'] );
		$this->assertContains( 'Route saved.', $this->notices() );
		$this->assertContains( 'TTL adjusted to 60 seconds.', $this->notices() );
	}

	public function test_a_zero_or_negative_ttl_is_reported_as_adjusted(): void {
		$this->as_admin();
		foreach ( array( '0', '-5' ) as $ttl ) {
			delete_user_meta( get_current_user_id(), Notices::meta_key() );
			$this->post_action( 'save_route', $this->id(), array( 'status' => 'enabled', 'ttl' => $ttl ) );
			$this->assertSame( 60, $this->stored_rules()[ self::ITEMS ]['ttl'], $ttl );
			$this->assertContains( 'TTL adjusted to 60 seconds.', $this->notices(), $ttl );
		}
	}

	public function test_a_failed_database_write_is_an_error(): void {
		$this->as_admin();
		$this->fail_option_writes( Names::OPTION_SETTINGS );

		$this->post_action( 'save_route', $this->id(), array( 'status' => 'enabled' ) );

		$queued = ( new Notices() )->queued();
		$this->assertSame( 'error', $queued[0]['type'] );
		$this->assertSame( 'The changes could not be saved to the database.', $queued[0]['message'] );
		$this->assertNotContains( 'Route saved.', $this->notices() );
	}

	public function test_a_forbidden_vary_header_is_an_error_and_redirects_to_the_edit_url(): void {
		$this->as_admin();
		$redirect = $this->post_action( 'save_route', $this->id(), array( 'status' => 'enabled', 'vary_headers' => 'Authorization' ) );

		$this->assertSame( Admin_Url::page( 'rfrac-routes', array( 'action' => 'edit', 'route_id' => $this->id() ) ), $redirect->location );
		$queued = ( new Notices() )->queued();
		$this->assertSame( 'error', $queued[0]['type'] );
		$this->assertSame( array(), $this->stored_rules() );
	}

	public function test_a_tampered_route_id_is_route_not_found(): void {
		$this->as_admin();
		$redirect = $this->post_action( 'save_route', 'deadbeef0000', array( 'status' => 'enabled' ) );
		$this->assertContains( 'Route not found.', $this->notices() );
		$this->assertSame( Admin_Url::page( 'rfrac-routes', array( 'action' => 'edit', 'route_id' => 'deadbeef0000' ) ), $redirect->location );
		$this->assertSame( array(), $this->stored_rules() );
	}

	public function test_saving_a_denied_route_is_rejected_as_locked(): void {
		$this->as_admin();
		$id = Route_Service::id_for( '/wp/v2/users/me' );
		$this->post_action( 'save_route', $id, array( 'status' => 'enabled' ) );

		$this->assertCount( 1, $this->notices() );
		$this->assertSame( 'This route can not be changed.', $this->notices()[0] );
		$this->assertSame( array(), $this->stored_rules() );
	}

	// ---------- reset_route and flush_route ----------

	public function test_reset_route_removes_the_override(): void {
		$this->as_admin();
		$this->enable_items();
		delete_user_meta( get_current_user_id(), Notices::meta_key() );

		$redirect = $this->post_action( 'reset_route', $this->id() );
		$this->assertSame( Admin_Url::page( 'rfrac-routes' ), $redirect->location );
		$this->assertContains( 'Route reset to defaults.', $this->notices() );
		$this->assertArrayNotHasKey( self::ITEMS, $this->stored_rules() );
	}

	public function test_flush_route_flushes_only_that_route_and_reports_the_number(): void {
		$this->as_admin();
		$seed = $this->seed();

		$redirect = $this->post_action( 'flush_route_cache', $this->id( self::ITEM ) );

		$this->assertSame( Admin_Url::page( 'rfrac-routes' ), $redirect->location );
		$this->assertSame( array( 'Flushed 1 cached response of this route.' ), $this->notices() );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $seed['entries'][0] ), 'The parent route is untouched.' );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $seed['entries'][1] ) );
	}

	public function test_flush_route_of_an_unknown_route_is_route_not_found(): void {
		$this->as_admin();
		$this->post_action( 'flush_route_cache', 'deadbeef0000' );
		$this->assertSame( array( 'Route not found.' ), $this->notices() );
	}

	public function test_row_actions_return_to_the_list_as_it_was(): void {
		$this->as_admin();
		$this->enable_items();
		delete_user_meta( get_current_user_id(), Notices::meta_key() );
		$_SERVER['HTTP_REFERER'] = Admin_Url::page(
			'rfrac-routes',
			array(
				'view'      => 'configured',
				'namespace' => 'myplugin/v1',
				's'         => 'items',
				'orderby'   => 'namespace',
				'order'     => 'desc',
				'evil'      => '<script>',
			)
		);

		$redirect = $this->post_action( 'flush_route_cache', $this->id() );

		$this->assertSame(
			Admin_Url::page(
				'rfrac-routes',
				array(
					'view'      => 'configured',
					'namespace' => 'myplugin/v1',
					's'         => 'items',
					'orderby'   => 'namespace',
					'order'     => 'desc',
				)
			),
			$redirect->location
		);

		$_SERVER['HTTP_REFERER'] = 'https://evil.example/wp-admin/admin.php?page=rfrac-routes&view=locked';
		$this->assertSame( Admin_Url::page( 'rfrac-routes' ), $this->post_action( 'flush_route_cache', $this->id() )->location );
	}

	// ---------- enable_route_preset ----------

	/**
	 * Register a preset of both item routes, `items`, through the filter.
	 */
	private function register_presets(): void {
		add_filter(
			Hooks::ROUTE_PRESETS,
			static function () {
				return array(
					'items' => array(
						'label'  => 'Demo items',
						'routes' => array( self::ITEMS, self::ITEM ),
					),
				);
			}
		);
	}

	public function test_enable_preset_without_capability_dies_and_changes_nothing(): void {
		$this->register_presets();
		$this->as_admin();
		$seed = $this->seed();
		$this->as_subscriber();
		try {
			$this->post_action( 'enable_route_preset', 'items' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assert_unchanged( $seed );
	}

	public function test_enable_preset_rejects_a_bad_nonce_and_a_nonce_of_another_target_or_action(): void {
		$this->register_presets();
		$this->as_admin();
		$seed = $this->seed();
		$cases = array(
			'bad nonce'          => array( 'items', array( '_wpnonce' => 'bad' ), null, null ),
			'other target'       => array( 'items', array(), 'other', null ),
			'no target in nonce' => array( 'items', array(), '', null ),
			'other action'       => array( 'items', array(), null, 'save_route' ),
		);
		foreach ( $cases as $case => $args ) {
			try {
				$this->post_action( 'enable_route_preset', $args[0], $args[1], $args[2], $args[3] );
				$this->fail( 'Expected wp_die for: ' . $case );
			} catch ( WPDieException $e ) {
				$this->assertSame( 403, $e->getCode(), $case );
			}
		}
		$this->assert_unchanged( $seed );
	}

	public function test_enable_preset_enables_the_routes_reports_the_count_and_redirects_to_the_list(): void {
		$this->register_presets();
		$this->as_admin();
		$seed = $this->seed();

		$redirect = $this->post_action( 'enable_route_preset', 'items' );

		$this->assertSame( Admin_Url::page( 'rfrac-routes' ), $redirect->location );
		$queued = ( new Notices() )->queued();
		$this->assertSame( array( array( 'type' => 'success', 'message' => 'Enabled 2 routes of Demo items.' ) ), $queued );
		$rules = $this->stored_rules();
		foreach ( array( self::ITEMS, self::ITEM ) as $route ) {
			$this->assertSame( 'enabled', $rules[ $route ]['status'], $route );
			$this->assertSame( 900, $rules[ $route ]['ttl'], 'The rest of the stored rule is kept: ' . $route );
		}
		$this->assertSame( array_keys( $seed['rules'] ), array_keys( $rules ) );
		$keys = array_map( static fn( $r ) => $r->key, Compiled_Config::load()->routes() );
		$this->assertContains( self::ITEMS, $keys, 'The compiled config contains the enabled route.' );

		delete_user_meta( get_current_user_id(), Notices::meta_key() );
		$this->post_action( 'enable_route_preset', 'items' );
		$this->assertSame( array( array( 'type' => 'info', 'message' => 'Every registered route of Demo items is already enabled.' ) ), ( new Notices() )->queued() );
		$this->assertSame( $rules, $this->stored_rules() );
	}

	public function test_enable_preset_reports_a_single_route_in_the_singular(): void {
		$this->register_presets();
		$this->as_admin();
		$this->enable_items();
		delete_user_meta( get_current_user_id(), Notices::meta_key() );

		$this->post_action( 'enable_route_preset', 'items' );

		$this->assertSame( array( 'Enabled 1 route of Demo items.' ), $this->notices() );
		$this->assertSame( 'enabled', $this->stored_rules()[ self::ITEM ]['status'] );
		$this->assertSame( 600, $this->stored_rules()[ self::ITEMS ]['ttl'], 'An enabled route is not touched.' );
	}

	/**
	 * Register a preset, `mixed`, of the given routes through the filter.
	 *
	 * @param string[] $routes Route patterns.
	 */
	private function register_mixed_preset( array $routes ): void {
		add_filter(
			Hooks::ROUTE_PRESETS,
			static function () use ( $routes ) {
				return array(
					'mixed' => array(
						'label'  => 'Demo mixed',
						'routes' => $routes,
					),
				);
			}
		);
	}

	public function test_enable_preset_of_denied_and_unregistered_routes_reports_them_skipped_not_already_enabled(): void {
		$this->register_mixed_preset( array( '/wp/v2/users/me', '/nope/v1/x' ) );
		$this->as_admin();

		$this->post_action( 'enable_route_preset', 'mixed' );

		$this->assertSame(
			array( array( 'type' => 'warning', 'message' => 'No routes of Demo mixed were enabled. 2 routes were skipped because they can not be changed.' ) ),
			( new Notices() )->queued()
		);
		$this->assertSame( array(), $this->stored_rules() );
	}

	public function test_enable_preset_with_a_skipped_route_next_to_an_enabled_one_does_not_claim_all_enabled(): void {
		$this->register_mixed_preset( array( self::ITEMS, '/nope/v1/x' ) );
		$this->as_admin();
		$this->enable_items();
		delete_user_meta( get_current_user_id(), Notices::meta_key() );

		$this->post_action( 'enable_route_preset', 'mixed' );

		$this->assertSame( array( 'No routes of Demo mixed were enabled. 1 route was skipped because it can not be changed.' ), $this->notices() );
	}

	public function test_enable_preset_mentions_the_skipped_routes_of_a_partial_success(): void {
		$this->register_mixed_preset( array( self::ITEMS, self::ITEM, '/wp/v2/users/me', '/nope/v1/x' ) );
		$this->as_admin();

		$this->post_action( 'enable_route_preset', 'mixed' );

		$this->assertSame(
			array( array( 'type' => 'success', 'message' => 'Enabled 2 routes of Demo mixed. 2 routes were skipped because they can not be changed.' ) ),
			( new Notices() )->queued()
		);
		$this->assertSame( 'enabled', $this->stored_rules()[ self::ITEMS ]['status'] );
		$this->assertArrayNotHasKey( '/wp/v2/users/me', $this->stored_rules() );
	}

	public function test_enable_preset_of_a_post_only_route_asks_for_its_methods_instead_of_saying_it_can_not_be_changed(): void {
		$this->register_mixed_preset( array( '/myplugin/v1/lookup' ) );
		$this->as_admin();

		$this->post_action( 'enable_route_preset', 'mixed' );

		$this->assertSame(
			array( array( 'type' => 'warning', 'message' => 'No routes of Demo mixed were enabled. 1 route was skipped because it needs its methods chosen on its edit screen.' ) ),
			( new Notices() )->queued()
		);
		$this->assertSame( array(), $this->stored_rules() );
	}

	public function test_enable_preset_tells_the_routes_that_need_methods_from_the_ones_that_can_not_be_changed(): void {
		$this->register_mixed_preset( array( self::ITEMS, '/myplugin/v1/lookup', '/myplugin/v1/search', '/wp/v2/users/me', '/nope/v1/x' ) );
		$this->as_admin();

		$this->post_action( 'enable_route_preset', 'mixed' );

		$this->assertSame(
			array( array( 'type' => 'success', 'message' => 'Enabled 2 routes of Demo mixed. 2 routes were skipped because they can not be changed. 1 route was skipped because it needs its methods chosen on its edit screen.' ) ),
			( new Notices() )->queued()
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enable_preset_of_a_constant_locked_route_reports_it_skipped_not_already_enabled(): void {
		define( 'RFRAC_ROUTES', array( self::ITEMS => array( 'status' => 'excluded', 'ttl' => 900 ) ) );
		$this->register_mixed_preset( array( self::ITEMS ) );
		$this->as_admin();

		$this->post_action( 'enable_route_preset', 'mixed' );

		$this->assertSame(
			array( array( 'type' => 'warning', 'message' => 'No routes of Demo mixed were enabled. 1 route was skipped because it can not be changed.' ) ),
			( new Notices() )->queued()
		);
		$this->assertSame( array(), $this->stored_rules() );
	}

	public function test_enable_preset_of_an_unknown_key_is_an_error_and_changes_nothing(): void {
		$this->register_presets();
		$this->as_admin();
		$this->post_action( 'enable_route_preset', 'nope' );

		$this->assertSame( array( array( 'type' => 'error', 'message' => 'Unknown route preset.' ) ), ( new Notices() )->queued() );
		$this->assertSame( array(), $this->stored_rules() );
	}

	public function test_enable_preset_returns_to_the_list_as_it_was(): void {
		$this->register_presets();
		$this->as_admin();
		$_SERVER['HTTP_REFERER'] = Admin_Url::page( 'rfrac-routes', array( 'view' => 'disabled', 's' => 'items' ) );

		$redirect = $this->post_action( 'enable_route_preset', 'items' );

		$this->assertSame( Admin_Url::page( 'rfrac-routes', array( 'view' => 'disabled', 's' => 'items' ) ), $redirect->location );
	}

	public function test_disable_preset_returns_the_routes_to_their_default_and_reports_the_count(): void {
		$this->register_presets();
		$this->as_admin();
		$this->post_action( 'enable_route_preset', 'items' );
		delete_user_meta( get_current_user_id(), Notices::meta_key() );

		$redirect = $this->post_action( 'disable_route_preset', 'items' );

		$this->assertSame( Admin_Url::page( 'rfrac-routes' ), $redirect->location );
		$this->assertSame( array( array( 'type' => 'success', 'message' => 'Disabled 2 routes of Demo items.' ) ), ( new Notices() )->queued() );
		$this->assertSame( array(), $this->stored_rules(), 'The default rules are not stored.' );
		$keys = array_map( static fn( $r ) => $r->key, array_filter( Compiled_Config::load()->routes(), static fn( $r ) => $r->enabled ) );
		$this->assertNotContains( self::ITEMS, $keys, 'The compiled config no longer caches the route.' );

		delete_user_meta( get_current_user_id(), Notices::meta_key() );
		$this->post_action( 'disable_route_preset', 'items' );
		$this->assertSame( array( array( 'type' => 'info', 'message' => 'No route of Demo items is enabled.' ) ), ( new Notices() )->queued() );
	}

	public function test_disable_preset_without_capability_dies_and_changes_nothing(): void {
		$this->register_presets();
		$this->as_admin();
		$seed = $this->seed();
		$this->as_subscriber();
		try {
			$this->post_action( 'disable_route_preset', 'items' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assert_unchanged( $seed );
	}

	public function test_disable_preset_of_an_unknown_key_is_an_error(): void {
		$this->register_presets();
		$this->as_admin();

		$this->post_action( 'disable_route_preset', 'nope' );

		$this->assertSame( array( array( 'type' => 'error', 'message' => 'Unknown route preset.' ) ), ( new Notices() )->queued() );
	}

	public function test_the_list_renders_a_disable_form_once_the_preset_is_enabled(): void {
		$this->register_presets();
		$this->as_admin();
		set_current_screen( 'toplevel_page_rfrac-routes' );

		$this->post_action( 'enable_route_preset', 'items' );
		$this->set_request( array( 'page' => 'rfrac-routes' ) );
		$html = $this->render();

		$this->assertStringContainsString( '<button type="submit" class="button">Disable Demo items</button>', $html );
		$this->assertStringContainsString( 'value="rfrac_disable_route_preset"', $html );
		$this->assertStringNotContainsString( 'value="rfrac_enable_route_preset"', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( Action_Handler::nonce_action( 'disable_route_preset', 'items' ) ) . '"', $html );

		$this->post_action( 'disable_route_preset', 'items' );
		$this->set_request( array( 'page' => 'rfrac-routes' ) );
		$this->assertStringContainsString( '<button type="submit" class="button">Enable Demo items</button>', $this->render() );
	}

	public function test_the_list_renders_an_enable_form_per_preset_and_none_without_presets(): void {
		$this->as_admin();
		set_current_screen( 'toplevel_page_rfrac-routes' );
		$this->set_request( array( 'page' => 'rfrac-routes' ) );
		$this->assertStringNotContainsString( 'rfrac_enable_route_preset', $this->render() );

		add_filter(
			Hooks::ROUTE_PRESETS,
			static function () {
				return array(
					'items'  => array(
						'label'  => 'Demo items',
						'routes' => array( self::ITEMS ),
					),
					'markup' => array(
						'label'  => '<b>Bold</b>',
						'routes' => array( self::ITEM ),
					),
				);
			}
		);
		$html = $this->render();

		$this->assertSame( 2, substr_count( $html, 'value="rfrac_enable_route_preset"' ) );
		$this->assertStringContainsString( '<button type="submit" class="button">Enable Demo items</button>', $html );
		$this->assertStringContainsString( 'name="target" value="items"', $html );
		$this->assertStringContainsString( 'name="target" value="markup"', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( Action_Handler::nonce_action( 'enable_route_preset', 'items' ) ) . '"', $html );
		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $html );
		$this->assertStringNotContainsString( '<b>Bold</b>', $html );
		$this->assertStringContainsString( 'Enable &lt;b&gt;Bold&lt;/b&gt;', $html );
	}

	// ---------- Edit view actions ----------

	public function test_the_edit_view_flushes_and_resets_through_post_forms(): void {
		$this->as_admin();
		$this->enable_items();
		$this->set_request( array( 'page' => 'rfrac-routes', 'action' => 'edit', 'route_id' => $this->id() ) );
		$html = $this->render();

		$this->assertSame( 3, substr_count( $html, '<form method="post"' ), 'Save, flush and reset forms.' );
		$this->assertStringContainsString( 'value="rfrac_flush_route_cache"', $html );
		$this->assertStringContainsString( 'value="rfrac_reset_route"', $html );
		$this->assertStringNotContainsString( 'admin-post.php?action=', $html, 'No state-changing links.' );
		$this->assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( Action_Handler::nonce_action( 'reset_route', $this->id() ) ) . '"', $html );
	}

	// ---------- Bulk ----------

	/**
	 * Run a bulk action through load() and return the redirect.
	 *
	 * @param string              $action Bulk action.
	 * @param string[]            $ids    Route ids.
	 * @param string|null         $nonce  Nonce; a valid one by default.
	 * @param array<string,mixed> $extra  Extra GET parameters.
	 */
	private function bulk( string $action, array $ids, ?string $nonce = null, array $extra = array() ): Redirect_Intercepted {
		$this->set_request(
			$extra + array(
				'page'     => 'rfrac-routes',
				'action'   => $action,
				'route'    => $ids,
				'_wpnonce' => $nonce ?? wp_create_nonce( 'bulk-rfrac_routes' ),
			)
		);
		try {
			$this->page->load();
		} catch ( Redirect_Intercepted $redirect ) {
			return $redirect;
		}
		$this->fail( 'Expected a redirect.' );
	}

	public function test_bulk_enable_updates_the_selected_routes_and_skips_a_denied_one(): void {
		$this->as_admin();
		$writes = 0;
		add_action(
			'update_option_' . Names::OPTION_SETTINGS,
			static function () use ( &$writes ) {
				++$writes;
			}
		);

		$redirect = $this->bulk( 'enable', array( $this->id(), $this->id( self::ITEM ), Route_Service::id_for( '/wp/v2/users/me' ) ) );

		$this->assertSame( Admin_Url::page( 'rfrac-routes' ), $redirect->location );
		$this->assertSame( array( '2 routes updated. 1 route was skipped because it can not be changed.' ), $this->notices() );
		$this->assertSame( 1, $writes, 'One persist.' );
		$this->assertSame( 'enabled', $this->stored_rules()[ self::ITEMS ]['status'] );
		$this->assertArrayNotHasKey( '/wp/v2/users/me', $this->stored_rules() );
	}

	public function test_bulk_enable_of_a_post_only_route_without_methods_says_it_needs_them_on_the_edit_screen(): void {
		$this->as_admin();

		$this->bulk( 'enable', array( $this->id( '/myplugin/v1/lookup' ) ) );

		$this->assertSame( array( '0 routes updated. 1 route was skipped because it needs its methods chosen on its edit screen.' ), $this->notices() );
		$this->assertSame( array(), $this->stored_rules() );
	}

	public function test_bulk_enable_tells_the_routes_that_need_methods_from_the_ones_that_can_not_be_changed(): void {
		$this->as_admin();

		$this->bulk( 'enable', array( $this->id(), $this->id( '/myplugin/v1/lookup' ), Route_Service::id_for( '/wp/v2/users/me' ) ) );

		$this->assertSame(
			array( '1 route updated. 1 route was skipped because it can not be changed. 1 route was skipped because it needs its methods chosen on its edit screen.' ),
			$this->notices()
		);
		$this->assertSame( array( self::ITEMS ), array_keys( $this->stored_rules() ) );

		delete_user_meta( get_current_user_id(), Notices::meta_key() );
		$this->bulk( 'exclude', array( $this->id( '/myplugin/v1/lookup' ) ) );
		$this->assertSame( array( '1 route updated.' ), $this->notices(), 'Excluding a POST-only route needs no method.' );
	}

	public function test_a_single_route_update_is_singular(): void {
		$this->as_admin();
		$this->bulk( 'exclude', array( $this->id() ) );
		$this->assertSame( array( '1 route updated.' ), $this->notices() );
	}

	public function test_bulk_exclude_and_reset(): void {
		$this->as_admin();
		$this->bulk( 'exclude', array( $this->id() ) );
		$this->assertSame( 'excluded', $this->stored_rules()[ self::ITEMS ]['status'] );

		$this->bulk( 'reset', array( $this->id() ) );
		$this->assertArrayNotHasKey( self::ITEMS, $this->stored_rules() );
	}

	public function test_bulk_reset_removes_the_whole_override_like_the_row_action(): void {
		$this->as_admin();
		$this->enable_items(); // Status, TTL, ignore list and flush-on post types.
		$this->post_action( 'save_route', $this->id( self::ITEM ), array( 'status' => 'excluded', 'ttl' => '900', 'vary_headers' => 'Accept-Language' ) );
		$this->assertCount( 2, $this->stored_rules() );
		delete_user_meta( get_current_user_id(), Notices::meta_key() );

		$this->bulk( 'reset', array( $this->id(), $this->id( self::ITEM ), Route_Service::id_for( '/wp/v2/users/me' ) ) );

		$this->assertSame( array(), $this->stored_rules(), 'TTL, parameters, vary and flush-on are gone too.' );
		$this->assertSame( array( '2 routes updated. 1 route was skipped because it can not be changed.' ), $this->notices() );
	}

	public function test_the_filter_button_never_applies_a_selected_bulk_action(): void {
		$this->as_admin();
		$this->set_request(
			array(
				'page'          => 'rfrac-routes',
				'action'        => 'enable',
				'action2'       => 'enable',
				'route'         => array( $this->id() ),
				'_wpnonce'      => wp_create_nonce( 'bulk-rfrac_routes' ),
				'namespace'     => 'myplugin/v1',
				'filter_action' => 'Filter',
			)
		);

		$this->page->load();

		$this->assertSame( array(), $this->stored_rules() );
		$this->assertSame( array(), $this->notices() );
	}

	public function test_the_bottom_bulk_select_works(): void {
		$this->as_admin();
		$redirect = $this->bulk( '-1', array( $this->id() ), null, array( 'action2' => 'exclude' ) );
		$this->assertSame( 303, $redirect->status );
		$this->assertSame( 'excluded', $this->stored_rules()[ self::ITEMS ]['status'] );
	}

	public function test_bulk_keeps_the_view_search_sorting_and_page(): void {
		$this->as_admin();
		update_user_option( get_current_user_id(), 'rfrac_routes_per_page', 1 );

		$redirect = $this->bulk(
			'exclude',
			array( $this->id() ),
			null,
			array(
				'view'      => 'disabled',
				'namespace' => 'myplugin/v1',
				's'         => 'items',
				'orderby'   => 'namespace',
				'order'     => 'desc',
				'paged'     => '2',
			)
		);

		parse_str( (string) wp_parse_url( $redirect->location, PHP_URL_QUERY ), $query );
		$this->assertSame(
			array(
				'page'      => 'rfrac-routes',
				'view'      => 'disabled',
				'namespace' => 'myplugin/v1',
				's'         => 'items',
				'orderby'   => 'namespace',
				'order'     => 'desc',
				'paged'     => '2',
			),
			$query,
			'Both item routes are still disabled (one now excluded), so page 2 at 1 per page still exists.'
		);

		$redirect = $this->bulk( 'exclude', array( $this->id( self::ITEM ) ), null, array( 'view' => 'enabled', 'namespace' => 'myplugin/v1', 'paged' => '9' ) );
		$this->assertStringNotContainsString( 'paged=', $redirect->location, 'A page past the end becomes the last page.' );
	}

	public function test_the_list_form_keeps_the_current_view(): void {
		$this->as_admin();
		set_current_screen( 'toplevel_page_rfrac-routes' );
		$this->set_request( array( 'page' => 'rfrac-routes', 'view' => 'configured' ) );
		$html = $this->render();
		$this->assertStringContainsString( '<input type="hidden" name="view" value="configured" />', $html );
	}

	// ---------- Screen option ----------

	public function test_the_per_page_screen_option_is_saved_clamped_to_1_200(): void {
		$this->assertSame( 200, apply_filters( 'set_screen_option_rfrac_routes_per_page', false, 'rfrac_routes_per_page', '5000' ) );
		$this->assertSame( 35, apply_filters( 'set_screen_option_rfrac_routes_per_page', false, 'rfrac_routes_per_page', '35' ) );
		$this->assertSame( 1, apply_filters( 'set_screen_option_rfrac_routes_per_page', false, 'rfrac_routes_per_page', 'abc' ) );
	}

	// ---------- Stored values are escaped (XSS round trip) ----------

	public function test_hostile_stored_rule_values_are_escaped_in_the_edit_form_and_the_list(): void {
		$this->as_admin();
		$payload  = '</textarea><script>alert(1)</script>"><img src=x onerror=alert(2)>';
		$settings = array(
			'routes' => array(
				self::ITEMS => array(
					'status'           => 'enabled',
					'ttl'              => 600,
					'param_mode'       => 'registered',
					'ignore_params'    => array( $payload ),
					'bypass_params'    => array( $payload ),
					'vary_headers'     => array( $payload ),
					'flush_post_types' => array( $payload ),
					'flush_taxonomies' => array( $payload ),
				),
			),
		);
		// Written directly, bypassing sanitising, as a compromised or hand-edited option would be.
		update_option( Names::OPTION_SETTINGS, $settings, false );
		$this->routes->flush();

		$this->set_request( array( 'page' => 'rfrac-routes', 'action' => 'edit', 'route_id' => $this->id() ) );
		$edit = $this->render();
		set_current_screen( 'toplevel_page_rfrac-routes' );
		$this->set_request( array( 'page' => 'rfrac-routes', 's' => 'myplugin' ) );
		$list = $this->render();

		foreach ( array( 'edit form' => $edit, 'list' => $list ) as $where => $html ) {
			$this->assertStringNotContainsString( '<script>alert(1)', $html, $where );
			$this->assertStringNotContainsString( '<img src=x', $html, $where );
			$this->assertStringNotContainsString( '</textarea><script', $html, $where );
		}
	}

	public function test_bulk_with_a_bad_nonce_dies_and_changes_nothing(): void {
		$this->as_admin();
		try {
			$this->bulk( 'enable', array( $this->id() ), 'bad' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array(), $this->stored_rules() );
		}
	}

	public function test_bulk_without_capability_does_not_change_anything_through_the_admin(): void {
		$this->as_subscriber();
		$admin = new Admin( new Capability(), new Notices(), Plugin::instance()->status_service(), Plugin::instance()->cache_service(), array( $this->page ) );
		$this->set_request(
			array(
				'action'   => 'enable',
				'route'    => array( $this->id() ),
				'_wpnonce' => wp_create_nonce( 'bulk-rfrac_routes' ),
			)
		);
		try {
			$admin->load( $this->page );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array(), $this->stored_rules() );
		}
	}

	// ---------- Rendering ----------

	private function render(): string {
		ob_start();
		$this->page->render();
		return (string) ob_get_clean();
	}

	public function test_the_list_renders_the_enabled_route_with_its_policy(): void {
		$this->as_admin();
		set_current_screen( 'toplevel_page_rfrac-routes' );
		$this->enable_items();
		$this->set_request( array( 'page' => 'rfrac-routes', 's' => 'myplugin/v1/items' ) );

		$html = $this->render();
		$this->assertStringContainsString( 'Enabled', $html );
		$this->assertStringContainsString( human_time_diff( 0, 600 ), $html );
		$this->assertStringContainsString( 'Registered args, 1 ignored', $html );
		$this->assertStringContainsString( 'Also on changes of the post types: product', $html );
		$this->assertStringContainsString( '(?P&lt;id&gt;[\d]+)', $html, 'A pattern is shown intact and escaped.' );
	}

	public function test_a_route_with_markup_in_its_pattern_is_escaped(): void {
		$this->as_admin();
		set_current_screen( 'toplevel_page_rfrac-routes' );
		$this->set_request( array( 'page' => 'rfrac-routes' ) );
		$html = $this->render();
		$this->assertStringNotContainsString( '<b>x</b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;x&lt;/b&gt;', $html );

		$this->set_request( array( 'page' => 'rfrac-routes', 'action' => 'edit', 'route_id' => $this->id( '/myplugin/v1/<b>x</b>' ) ) );
		$html = $this->render();
		$this->assertStringNotContainsString( '<b>x</b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;x&lt;/b&gt;', $html );
	}

	public function test_the_edit_view_has_the_full_form(): void {
		$this->as_admin();
		$this->set_request( array( 'page' => 'rfrac-routes', 'action' => 'edit', 'route_id' => $this->id() ) );
		$html = $this->render();

		foreach ( array( 'name="status"', 'name="ttl"', 'name="param_mode"', 'name="ignore_params"', 'name="bypass_params"', 'name="vary_headers"', 'name="flush_post_types[]"', 'name="flush_taxonomies[]"', 'data-rfrac-show-if="param_mode:allow_all"', 'value="rfrac_save_route"', 'Save route' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html, $needle );
		}
		$this->assertStringNotContainsString( 'disabled', $html );
		$this->assertStringNotContainsString( 'Also applied to every route', $html, 'Without global lists there is nothing to add.' );
	}

	public function test_the_edit_view_lists_the_global_entries_under_the_ignore_and_vary_fields(): void {
		$this->as_admin();
		$this->assertTrue( ( new Config() )->save_settings( array( 'global_ignore_params' => 'utm_source fbclid', 'global_vary_headers' => 'Accept-Language' ) )->is_valid() );
		$this->set_request( array( 'page' => 'rfrac-routes', 'action' => 'edit', 'route_id' => $this->id() ) );
		$html = $this->render();

		$ignored = strpos( $html, 'Also applied to every route: utm_source, fbclid' );
		$varied  = strpos( $html, 'Also applied to every route: accept-language' );
		$this->assertNotFalse( $ignored );
		$this->assertNotFalse( $varied );
		$this->assertGreaterThan( strpos( $html, 'name="ignore_params"' ), $ignored );
		$this->assertLessThan( strpos( $html, 'name="bypass_params"' ), $ignored );
		$this->assertGreaterThan( strpos( $html, 'name="vary_headers"' ), $varied );
		$this->assertLessThan( strpos( $html, 'name="flush_post_types[]"' ), $varied );
		$this->assertStringContainsString( 'aria-describedby="rfrac-ignore-params-description rfrac-ignore-params-global"', $html );
		$this->assertStringContainsString( 'aria-describedby="rfrac-vary-headers-description rfrac-vary-headers-global"', $html );
	}

	public function test_the_edit_view_lists_only_the_global_lists_that_are_not_empty(): void {
		$this->as_admin();
		$this->assertTrue( ( new Config() )->save_settings( array( 'global_ignore_params' => 'utm_source' ) )->is_valid() );
		$this->set_request( array( 'page' => 'rfrac-routes', 'action' => 'edit', 'route_id' => $this->id() ) );
		$html = $this->render();

		$this->assertSame( 1, substr_count( $html, 'Also applied to every route' ) );
		$this->assertStringContainsString( 'Also applied to every route: utm_source', $html );
		$this->assertStringContainsString( 'aria-describedby="rfrac-vary-headers-description"', $html );
	}

	public function test_a_route_save_that_overflows_the_global_vary_headers_is_an_error_and_redirects_to_the_edit_url(): void {
		$this->as_admin();
		$this->assertTrue( ( new Config() )->save_settings( array( 'global_vary_headers' => 'a b' ) )->is_valid() );

		$redirect = $this->post_action( 'save_route', $this->id(), array( 'status' => 'enabled', 'vary_headers' => "c\nd\ne\nf" ) );

		$this->assertSame( Admin_Url::page( 'rfrac-routes', array( 'action' => 'edit', 'route_id' => $this->id() ) ), $redirect->location );
		$queued = ( new Notices() )->queued();
		$this->assertSame( 'error', $queued[0]['type'] );
		$this->assertStringContainsString( 'would vary on 6 request headers; at most 5 are allowed.', $queued[0]['message'] );
		$this->assertSame( array(), $this->stored_rules() );

		$this->post_action( 'save_route', $this->id(), array( 'status' => 'enabled', 'vary_headers' => "c\nd\ne" ) );
		$this->assertSame( array( 'c', 'd', 'e' ), $this->stored_rules()[ self::ITEMS ]['vary_headers'] );
	}

	public function test_the_edit_view_of_a_denied_route_is_read_only(): void {
		$this->as_admin();
		$this->set_request( array( 'page' => 'rfrac-routes', 'action' => 'edit', 'route_id' => Route_Service::id_for( '/wp/v2/users/me' ) ) );
		$html = $this->render();

		$this->assertStringContainsString( 'This route is always bypassed for security (cart/checkout, current user, batch).', $html );
		$this->assertStringContainsString( 'disabled="disabled"', $html );
		$this->assertStringNotContainsString( 'Save route', $html );
	}

	public function test_the_edit_view_warns_about_a_custom_permission_check_and_more_strongly_once_cached(): void {
		$this->new_rest_server(
			static function () {
				register_rest_route(
					'partner/v1',
					'/feed',
					array(
						'methods'             => 'GET',
						'callback'            => '__return_true',
						'permission_callback' => static fn(): bool => isset( $_SERVER['HTTP_X_PARTNER_KEY'] ),
					)
				);
			}
		);
		$this->routes->flush();
		$feed = Route_Service::id_for( '/partner/v1/feed' );
		$this->as_admin();
		$this->set_request( array( 'page' => 'rfrac-routes', 'action' => 'edit', 'route_id' => $feed ) );

		$html = $this->render();
		$this->assertStringContainsString( 'notice notice-info inline rfrac-custom-access-notice', $html );
		$this->assertStringContainsString( 'This route checks access with its own permission callback for GET.', $html );
		$this->assertStringContainsString( 'Before you cache this route', $html );
		$this->assertStringContainsString( '<code>RFRAC_BYPASS_HEADERS</code>', $html );

		$this->assertInstanceOf( Route_Rule::class, $this->routes->save( $feed, array( 'status' => 'enabled' ) ) );
		$this->routes->flush();
		$html = $this->render();
		$this->assertStringContainsString( 'notice notice-warning inline rfrac-custom-access-notice', $html );
		$this->assertStringContainsString( 'Responses of this route are cached', $html );
	}

	public function test_the_edit_view_of_a_public_route_has_no_custom_access_notice(): void {
		$this->as_admin();
		$this->set_request( array( 'page' => 'rfrac-routes', 'action' => 'edit', 'route_id' => $this->id() ) );
		$this->assertStringNotContainsString( 'rfrac-custom-access-notice', $this->render() );

		$this->set_request( array( 'page' => 'rfrac-routes', 'action' => 'edit', 'route_id' => Route_Service::id_for( '/wp/v2/posts' ) ) );
		$this->assertStringNotContainsString( 'rfrac-custom-access-notice', $this->render(), 'Core checks are not custom.' );
	}

	public function test_an_unknown_route_id_renders_route_not_found(): void {
		$this->as_admin();
		$this->set_request( array( 'page' => 'rfrac-routes', 'action' => 'edit', 'route_id' => 'nope' ) );
		$this->assertStringContainsString( 'Route not found.', $this->render() );
	}
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_constant_locked_route_is_read_only(): void {
		define( 'RFRAC_ROUTES', array( self::ITEMS => array( 'status' => 'enabled', 'ttl' => 900 ) ) );
		$this->as_admin();
		$this->set_request( array( 'page' => 'rfrac-routes', 'action' => 'edit', 'route_id' => $this->id() ) );
		$html = $this->render();
		$this->assertStringContainsString( 'RFRAC_ROUTES', $html );
		$this->assertStringContainsString( 'disabled="disabled"', $html );
		$this->assertStringNotContainsString( 'Save route', $html );

		$this->post_action( 'save_route', $this->id(), array( 'status' => 'excluded' ) );
		$this->assertSame( array(), $this->stored_rules() );
		$this->assertSame( array( 'This route can not be changed.' ), $this->notices() );
	}

	// ---------- request methods ----------

	private function edit_html( string $route ): string {
		$this->as_admin();
		$this->set_request( array( 'page' => 'rfrac-routes', 'action' => 'edit', 'route_id' => $this->id( $route ) ) );
		return $this->render();
	}

	public function test_save_route_reads_the_methods_as_a_list_of_texts(): void {
		$this->as_admin();
		$redirect = $this->post_action( 'save_route', $this->id( '/myplugin/v1/lookup' ), array( 'status' => 'enabled', 'methods' => array( 'POST', 'patch' ) ) );

		$this->assertSame( Admin_Url::page( 'rfrac-routes' ), $redirect->location );
		$this->assertContains( 'Route saved.', $this->notices() );
		$this->assertSame( array( 'POST', 'PATCH' ), $this->stored_rules()['/myplugin/v1/lookup']['methods'] );

		$keys = array_map( static fn( $r ) => $r->key, Compiled_Config::load()->routes() );
		$this->assertContains( '/myplugin/v1/lookup', $keys, 'The compiled config contains the route.' );
	}

	public function test_save_route_without_methods_stores_a_get_only_rule(): void {
		$this->as_admin();
		$this->enable_items();

		$this->assertArrayNotHasKey( 'methods', $this->stored_rules()[ self::ITEMS ] );
	}

	public function test_unticking_every_method_box_of_an_enabled_route_is_refused_and_not_stored_as_get(): void {
		$this->as_admin();
		$search = '/myplugin/v1/search';
		$this->routes->save( $this->id( $search ), array( 'status' => 'enabled', 'methods' => array( 'GET', 'POST' ) ) );
		$stored = $this->stored_rules();

		// A browser posts no `methods` at all when no box is ticked; the marker tells that the boxes were there.
		$redirect = $this->post_action( 'save_route', $this->id( $search ), array( 'status' => 'enabled', Routes_Page::METHODS_SHOWN_FIELD => '1' ) );

		$this->assertSame( Admin_Url::page( 'rfrac-routes', array( 'action' => 'edit', 'route_id' => $this->id( $search ) ) ), $redirect->location );
		$queued = ( new Notices() )->queued();
		$this->assertSame( 'error', $queued[0]['type'] );
		$this->assertNotContains( 'Route saved.', $this->notices() );
		$this->assertSame( $stored, $this->stored_rules(), 'The GET and POST rule is kept, not replaced by GET.' );

		// The same on a route that was never enabled stores nothing.
		$this->post_action( 'save_route', $this->id( self::ITEMS ), array( 'status' => 'enabled', Routes_Page::METHODS_SHOWN_FIELD => '1' ) );
		$this->assertSame( $stored, $this->stored_rules() );
	}

	public function test_a_ticked_method_box_or_a_status_that_does_not_cache_still_saves_with_the_methods_marker(): void {
		$this->as_admin();
		$field = Routes_Page::METHODS_SHOWN_FIELD;

		$this->post_action( 'save_route', $this->id( '/myplugin/v1/search' ), array( 'status' => 'enabled', $field => '1', 'methods' => array( 'POST' ) ) );
		$this->assertSame( array( 'POST' ), $this->stored_rules()['/myplugin/v1/search']['methods'] );

		// Excluding a POST-only route, whose boxes are all unticked, is not a method choice.
		$this->post_action( 'save_route', $this->id( '/myplugin/v1/lookup' ), array( 'status' => 'excluded', $field => '1' ) );
		$this->assertSame( 'excluded', $this->stored_rules()['/myplugin/v1/lookup']['status'] );
		$this->assertContains( 'Route saved.', $this->notices() );
	}

	public function test_the_edit_form_posts_the_methods_marker_with_its_boxes(): void {
		foreach ( array( self::ITEMS, '/myplugin/v1/search', '/myplugin/v1/lookup', '/wp/v2/posts' ) as $route ) {
			$this->assertStringContainsString( '<input type="hidden" name="methods_shown" value="1" />', $this->edit_html( $route ), $route );
		}
	}

	public function test_a_core_route_that_only_registers_post_is_not_listed_nor_editable(): void {
		$this->as_admin();
		$route = '/wp/v2/media/(?P<id>[\d]+)/edit';
		$this->assertArrayHasKey( $route, rest_get_server()->get_routes( 'wp/v2' ), 'Core registers it.' );

		$this->set_request( array( 'page' => 'rfrac-routes', 's' => 'media' ) );
		$html = $this->render();

		$this->assertStringContainsString( '/wp/v2/media', $html, 'The media routes with a GET handler are listed.' );
		$this->assertStringNotContainsString( esc_html( $route ), $html );
		$this->assertStringContainsString( 'Route not found.', $this->edit_html( $route ) );
	}

	public function test_a_method_the_route_does_not_register_is_an_error_and_redirects_to_the_edit_url(): void {
		$this->as_admin();
		$redirect = $this->post_action( 'save_route', $this->id( '/myplugin/v1/lookup' ), array( 'status' => 'enabled', 'methods' => array( 'GET' ) ) );

		$this->assertSame( Admin_Url::page( 'rfrac-routes', array( 'action' => 'edit', 'route_id' => $this->id( '/myplugin/v1/lookup' ) ) ), $redirect->location );
		$queued = ( new Notices() )->queued();
		$this->assertSame( 'error', $queued[0]['type'] );
		$this->assertSame( array(), $this->stored_rules() );

		$this->post_action( 'save_route', $this->id( '/wp/v2/posts' ), array( 'status' => 'enabled', 'methods' => array( 'POST' ) ) );
		$this->assertSame( array(), $this->stored_rules(), 'POST is never cached on wp/v2.' );

		$this->post_action( 'save_route', $this->id( '/myplugin/v1/lookup' ), array( 'status' => 'excluded' ) );
		$this->assertSame( 'excluded', $this->stored_rules()['/myplugin/v1/lookup']['status'] );
	}

	public function test_the_edit_view_of_a_get_and_post_route_offers_both_methods_with_their_arguments_and_the_warning(): void {
		$this->routes->save( $this->id( '/myplugin/v1/search' ), array( 'status' => 'enabled', 'methods' => array( 'GET', 'POST' ) ) );
		$html = $this->edit_html( '/myplugin/v1/search' );

		$this->assertMatchesRegularExpression( '/<input type="checkbox" name="methods\[\]" value="GET" checked=\'checked\'/', $html );
		$this->assertMatchesRegularExpression( '/<input type="checkbox" name="methods\[\]" value="POST" checked=\'checked\'/', $html );
		$this->assertStringNotContainsString( 'value="PUT"', $html );
		$this->assertStringNotContainsString( 'value="PATCH"', $html );
		$this->assertStringContainsString( 'Registered GET arguments', $html );
		$this->assertStringContainsString( 'Registered POST arguments', $html );
		$this->assertStringContainsString( '<code>q</code>', $html );
		$this->assertStringContainsString( '<code>term</code>', $html );
		$this->assertStringContainsString( 'class="notice notice-warning inline" data-rfrac-show-if="methods[]:POST|PUT|PATCH"', $html );
		$this->assertStringContainsString( 'A cached POST, PUT or PATCH response is served without running the route&#039;s handler: anything the handler does besides returning data does not happen on a cache hit. Enable these methods only for routes that only read data.', $html );
		$this->assertStringNotContainsString( 'disabled', $html );
	}

	public function test_the_edit_view_checks_the_methods_of_the_rule_and_only_those(): void {
		$html = $this->edit_html( '/myplugin/v1/search' );

		$this->assertMatchesRegularExpression( '/<input type="checkbox" name="methods\[\]" value="GET" checked=\'checked\'/', $html, 'A rule without methods caches GET.' );
		$this->assertDoesNotMatchRegularExpression( '/value="POST" checked/', $html );
		$this->assertStringContainsString( 'name="methods[]" value="POST"', $html );
	}

	public function test_the_edit_view_of_a_post_only_route_offers_post_only(): void {
		$html = $this->edit_html( '/myplugin/v1/lookup' );

		$this->assertStringContainsString( 'name="methods[]" value="POST"', $html );
		$this->assertStringContainsString( 'name="methods[]" value="PUT"', $html );
		$this->assertStringContainsString( 'name="methods[]" value="PATCH"', $html );
		$this->assertStringNotContainsString( 'name="methods[]" value="GET"', $html );
		$this->assertStringContainsString( 'Registered POST arguments', $html );
		$this->assertStringContainsString( 'Registered PATCH arguments', $html );
		$this->assertStringNotContainsString( 'Registered GET arguments', $html );
		$this->assertStringContainsString( 'data-rfrac-show-if="methods[]:POST|PUT|PATCH"', $html );
	}

	public function test_the_edit_view_of_a_get_only_or_core_route_offers_get_only_and_no_warning(): void {
		foreach ( array( self::ITEMS, '/wp/v2/posts' ) as $route ) {
			$html = $this->edit_html( $route );

			$this->assertSame( 1, substr_count( $html, 'name="methods[]"' ), $route );
			$this->assertMatchesRegularExpression( '/name="methods\[\]" value="GET" checked=\'checked\'/', $html, $route );
			$this->assertStringNotContainsString( 'value="POST"', $html, $route );
			$this->assertStringNotContainsString( 'methods[]:POST|PUT|PATCH', $html, $route );
			$this->assertStringContainsString( 'Registered GET arguments', $html, $route );
			$this->assertStringNotContainsString( 'Registered POST arguments', $html, $route );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_constant_locked_route_shows_its_methods_read_only(): void {
		define( 'RFRAC_ROUTES', array( '/myplugin/v1/search' => array( 'status' => 'enabled', 'methods' => array( 'GET', 'POST' ) ) ) );
		$html = $this->edit_html( '/myplugin/v1/search' );

		$this->assertMatchesRegularExpression( '/name="methods\[\]" value="GET" checked=\'checked\' disabled=\'disabled\'/', $html );
		$this->assertMatchesRegularExpression( '/name="methods\[\]" value="POST" checked=\'checked\' disabled=\'disabled\'/', $html );
		$this->assertStringNotContainsString( 'Save route', $html );
	}

	public function test_the_list_shows_the_methods_of_a_rule_that_is_not_get_only(): void {
		$this->as_admin();
		$this->routes->save( $this->id( '/myplugin/v1/search' ), array( 'status' => 'enabled', 'methods' => array( 'GET', 'POST' ) ) );
		$this->routes->save( $this->id( self::ITEMS ), array( 'status' => 'enabled' ) );
		$this->set_request( array( 'page' => 'rfrac-routes', 's' => 'myplugin' ) );
		$html = $this->render();

		$this->assertSame( 1, substr_count( $html, 'Methods: GET, POST' ) );
		$this->assertStringContainsString( '/myplugin/v1/lookup', $html, 'A route with only non-GET handlers is listed.' );
	}
}