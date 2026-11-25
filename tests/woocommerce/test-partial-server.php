<?php
/**
 * Tests for the WooCommerce answer to the partial server filter, against a real WooCommerce.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Partial_Server;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Partial_Server
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module
 */
class Test_Partial_Server extends Rfrac_Wc_E2e_Test_Case {

	/**
	 * The `rest_route` query var as the test found it; `null` when it was not set.
	 *
	 * @var string|null
	 */
	private ?string $saved_rest_route = null;

	/**
	 * Whether the `rest_route` query var was set before the test.
	 *
	 * @var bool
	 */
	private bool $had_rest_route = false;

	public function set_up() {
		parent::set_up();

		$vars                   = $GLOBALS['wp']->query_vars;
		$this->had_rest_route   = array_key_exists( 'rest_route', $vars );
		$this->saved_rest_route = $this->had_rest_route ? (string) $vars['rest_route'] : null;
		$this->set_rest_route( null );
	}

	public function tear_down() {
		if ( $this->had_rest_route ) {
			$GLOBALS['wp']->query_vars['rest_route'] = $this->saved_rest_route;
		} else {
			unset( $GLOBALS['wp']->query_vars['rest_route'] );
		}
		unset( $GLOBALS['wp_rest_server'], $GLOBALS['current_screen'] );

		parent::tear_down();
	}

	/**
	 * A fresh REST server that carries the Store API namespace with one route, nothing else.
	 *
	 * @return WP_REST_Server
	 */
	private function server_with_store_api(): WP_REST_Server {
		$server = new WP_REST_Server();
		$server->register_route(
			Partial_Server::STORE_API_NAMESPACE,
			'/' . Partial_Server::STORE_API_NAMESPACE . '/products',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => '__return_true',
					'permission_callback' => '__return_true',
				),
			)
		);

		return $server;
	}

	/**
	 * Set or remove the `rest_route` query var of the current request, which WooCommerce reads.
	 *
	 * @param string|null $route Route, `null` to remove the query var.
	 *
	 * @return void
	 */
	private function set_rest_route( ?string $route ): void {
		if ( null === $route ) {
			unset( $GLOBALS['wp']->query_vars['rest_route'] );
			return;
		}
		$GLOBALS['wp']->query_vars['rest_route'] = $route;
	}

	/**
	 * Build the REST server of a request to a route, like core does on `rest_api_init`, with the request recorded.
	 *
	 * @param string $route Route with a leading slash.
	 *
	 * @return WP_REST_Server
	 */
	private function rest_request( string $route ): WP_REST_Server {
		Request_State::reset();
		$this->simulate( '/wp-json' . $route );
		$this->set_rest_route( $route );
		Request_State::record_lookup( Request_Context::from_globals( 'wp-json' ), Policy_Decision::bypass( Bypass_Reason::ERROR ), null );

		return $this->new_rest_server();
	}

	/**
	 * Keys of the Store API catalog routes the stored snapshot holds.
	 *
	 * @return list<string>
	 */
	private function catalog_keys_in_snapshot(): array {
		$config = Compiled_Config::load();
		$this->assertNotNull( $config );

		$keys = array();
		foreach ( $config->routes() as $route ) {
			if ( Store_Routes::is_catalog( $route->key ) ) {
				$keys[] = $route->key;
			}
		}

		return $keys;
	}

	/**
	 * Routes of the lazily loaded WooCommerce namespaces.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function woocommerce_routes(): array {
		return array(
			'store api'   => array( '/wc/store/v1/products' ),
			'unversioned' => array( '/wc/store/products' ),
			'rest v3'     => array( '/wc/v3/products' ),
			'rest v1'     => array( '/wc/v1/products' ),
			'analytics'   => array( '/wc-analytics/reports' ),
			'admin'       => array( '/wc-admin/options' ),
			'telemetry'   => array( '/wc-telemetry/tracker' ),
			'private'     => array( '/wc/private/export' ),
		);
	}

	/**
	 * Routes WooCommerce loads everything for.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function other_routes(): array {
		return array(
			'core'                 => array( '/wp/v2/posts' ),
			'unknown wc namespace' => array( '/wc/agentic/v1/checkout_sessions' ),
			'third party'          => array( '/acme/v1/things' ),
		);
	}

	/**
	 * @dataProvider woocommerce_routes
	 *
	 * @param string $route Route.
	 */
	public function test_a_request_to_a_woocommerce_namespace_is_partial( string $route ): void {
		$this->assertTrue( Partial_Server::filter( false, null, $route ) );
	}

	/**
	 * @dataProvider other_routes
	 *
	 * @param string $route Route.
	 */
	public function test_a_request_outside_the_woocommerce_namespaces_is_not_partial( string $route ): void {
		$this->assertFalse( Partial_Server::filter( false, null, $route ) );
	}

	public function test_an_unknown_route_without_a_rest_route_query_var_is_not_partial(): void {
		$this->assertFalse( Partial_Server::filter( false, null, '' ) );
	}

	public function test_an_unknown_route_reads_the_rest_route_query_var_like_woocommerce_does(): void {
		$this->set_rest_route( '/wc/store/v1/products' );
		$this->assertTrue( Partial_Server::filter( false, null, '' ) );

		$this->set_rest_route( '/wp/v2/posts' );
		$this->assertFalse( Partial_Server::filter( false, null, '' ) );
	}

	public function test_a_strict_true_stays_true_and_nothing_else_is_taken_for_it(): void {
		$this->assertTrue( Partial_Server::filter( true, null, '/wp/v2/posts' ) );
		$this->assertFalse( Partial_Server::filter( 'yes', null, '/wp/v2/posts' ), 'Only the strict boolean counts.' );
		$this->assertFalse( Partial_Server::filter( 1, null, '/wp/v2/posts' ) );
		$this->assertFalse( Partial_Server::filter( null, null, '/wp/v2/posts' ) );
	}

	public function test_a_route_that_is_not_a_string_counts_as_unknown(): void {
		$this->assertFalse( Partial_Server::filter( false, null, null ) );
		$this->assertFalse( Partial_Server::filter( false, null, array( '/wc/v3/products' ) ) );
	}

	public function test_woocommerce_is_asked_for_every_known_namespace_with_its_own_list(): void {
		$captured = array();
		$asked    = array();
		add_filter(
			'wc_rest_should_load_namespace',
			static function ( $should_load, $ns = '', $route = '', $known = array() ) use ( &$captured, &$asked ) {
				$captured[] = $known;
				$asked[]    = $ns;
				return true;
			},
			10,
			4
		);

		$this->assertFalse( Partial_Server::filter( false, null, '/wc/v3/products' ), 'Every namespace answered true.' );

		$this->assertNotEmpty( $captured );
		foreach ( $captured as $known ) {
			$this->assertSame( Partial_Server::KNOWN_NAMESPACES, $known );
		}
		$this->assertSame( Partial_Server::KNOWN_NAMESPACES, array_map( 'untrailingslashit', $asked ), 'WooCommerce got each known namespace, slash-terminated.' );
	}

	public function test_the_known_namespaces_are_those_of_woocommerce(): void {
		$this->assertSame(
			array( 'wc/v1', 'wc/v2', 'wc/v3', 'wc/v4', 'wc-telemetry', 'wc-admin', 'wc-analytics', 'wc/store', 'wc/private' ),
			Partial_Server::KNOWN_NAMESPACES
		);
	}

	public function test_the_filter_of_woocommerce_is_honoured(): void {
		add_filter( 'wc_rest_should_load_namespace', '__return_true' );
		$this->assertFalse( Partial_Server::filter( false, null, '/wc/store/v1/products' ), 'WooCommerce was told to load everything.' );
		remove_filter( 'wc_rest_should_load_namespace', '__return_true' );

		add_filter( 'wc_rest_should_load_namespace', '__return_false' );
		$this->assertTrue( Partial_Server::filter( false, null, '/wc/store/v1/products' ) );
	}

	public function test_the_module_hooks_the_filter_with_three_arguments_at_priority_ten(): void {
		$plugin = new Plugin();
		$plugin->register_integrations();
		$this->assertInstanceOf( Module::class, $plugin->woocommerce(), 'WooCommerce has loaded.' );

		$hooks = array_values( array_filter( $plugin->engine_hooks(), static fn( array $h ): bool => Hooks::PARTIAL_SERVER === $h['hook'] ) );

		$this->assertCount( 1, $hooks );
		$this->assertSame( 10, $hooks[0]['priority'] );
		$this->assertSame( 3, $hooks[0]['args'] );
		$this->assertSame( 10, has_filter( Hooks::PARTIAL_SERVER, $hooks[0]['callback'] ) );
	}

	public function test_the_hooked_filter_answers_through_apply_filters(): void {
		$plugin = new Plugin();
		$plugin->register_integrations();
		$this->assertInstanceOf( Module::class, $plugin->woocommerce() );
		$server = new WP_REST_Server();

		$this->assertTrue( apply_filters( Hooks::PARTIAL_SERVER, false, $server, '/wc/store/v1/products' ) );
		$this->assertTrue( apply_filters( Hooks::PARTIAL_SERVER, false, $server, '/wc-analytics/reports' ) );
		$this->assertFalse( apply_filters( Hooks::PARTIAL_SERVER, false, $server, '/wp/v2/posts' ) );
		$this->assertTrue( apply_filters( Hooks::PARTIAL_SERVER, true, $server, '/wp/v2/posts' ) );
	}

	public function test_in_admin_a_server_without_the_store_api_is_partial_whatever_the_route(): void {
		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );
		$server = new WP_REST_Server();

		$this->assertTrue( Partial_Server::filter( false, $server, '' ) );
		$this->assertTrue( Partial_Server::filter( false, $server, '/wp/v2/posts' ) );
	}

	public function test_in_admin_a_server_with_the_store_api_keeps_the_known_namespace_answer(): void {
		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );
		$server = $this->server_with_store_api();
		$this->assertContains( Partial_Server::STORE_API_NAMESPACE, $server->get_namespaces(), 'Precondition: the server carries the Store API.' );

		$this->assertFalse( Partial_Server::filter( false, $server, '' ) );
		$this->assertFalse( Partial_Server::filter( false, $server, '/wp/v2/posts' ) );
		$this->assertTrue( Partial_Server::filter( false, $server, '/wc/v3/products' ), 'WooCommerce skipping a namespace still counts.' );
	}

	public function test_outside_admin_a_server_without_the_store_api_keeps_the_known_namespace_answer(): void {
		set_current_screen( 'front' );
		$this->assertFalse( is_admin() );
		$server = new WP_REST_Server();

		$this->assertFalse( Partial_Server::filter( false, $server, '/wp/v2/posts' ) );
		$this->assertFalse( Partial_Server::filter( false, $server, '' ) );
		$this->assertTrue( Partial_Server::filter( false, $server, '/wc/store/v1/products' ) );
	}

	public function test_in_admin_a_missing_or_foreign_server_is_not_taken_for_one_without_the_store_api(): void {
		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );

		$this->assertFalse( Partial_Server::filter( false, null, '' ) );
		$this->assertFalse( Partial_Server::filter( false, new stdClass(), '' ) );
		$this->assertFalse( Partial_Server::filter( false, 'server', '' ) );
	}

	public function test_the_admin_answer_is_read_when_the_filter_runs(): void {
		$server = new WP_REST_Server();

		set_current_screen( 'front' );
		$this->assertFalse( Partial_Server::filter( false, $server, '' ) );

		set_current_screen( 'dashboard' );
		$this->assertTrue( Partial_Server::filter( false, $server, '' ) );
	}

	public function test_the_hooked_filter_answers_true_in_admin_for_a_server_without_the_store_api(): void {
		$plugin = new Plugin();
		$plugin->register_integrations();
		$this->assertInstanceOf( Module::class, $plugin->woocommerce(), 'WooCommerce has loaded.' );
		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );

		$this->assertTrue( apply_filters( Hooks::PARTIAL_SERVER, false, new WP_REST_Server(), '' ) );
		$this->assertFalse( apply_filters( Hooks::PARTIAL_SERVER, false, $this->server_with_store_api(), '' ) );
	}

	public function test_a_woocommerce_request_never_recompiles_the_snapshot_and_the_next_request_outside_does(): void {
		// The catalog routes are enabled.
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( $rules ) {
				$rules = is_array( $rules ) ? $rules : array();
				foreach ( Store_Routes::CATALOG as $key ) {
					$rules[ $key ] = array( 'status' => 'enabled' );
				}
				return $rules;
			}
		);

		// A snapshot compiled from a fully loaded server (no engine hooks are live yet) and then marked stale.
		$full = $this->rest_request( '/wp/v2/posts' );
		$this->assertArrayHasKey( '/wc/v3/products', $full->get_routes(), 'Precondition: WooCommerce registered every namespace.' );
		$compiler = new Config_Compiler();
		$compiler->compile( $full );
		$compiler->mark_stale();
		$this->assertCount( 26, $this->catalog_keys_in_snapshot(), 'Precondition: the snapshot holds the catalog routes.' );
		$this->assertSame( '', Compiled_Config::load()?->fingerprint(), 'Precondition: the snapshot is stale.' );
		$stale = get_option( Names::OPTION_CONFIG );

		$plugin = new Plugin();
		$plugin->register_engine_hooks();
		$this->assertInstanceOf( Module::class, $plugin->woocommerce(), 'Precondition: the module is wired.' );

		// A request to a WooCommerce namespace builds a server that lacks the other namespaces.
		$partial = $this->rest_request( '/wc/store/v1/products' );
		$this->assertArrayHasKey( '/wc/store/v1/products', $partial->get_routes(), 'The requested namespace is registered.' );
		$this->assertArrayNotHasKey( '/wc/v3/products', $partial->get_routes(), 'Precondition: WooCommerce skipped its other namespaces.' );
		$this->assertSame( $stale, get_option( Names::OPTION_CONFIG ), 'The snapshot is untouched.' );
		$this->assertCount( 26, $this->catalog_keys_in_snapshot() );

		// A later request outside those namespaces builds a full server and recompiles.
		$later = $this->rest_request( '/wp/v2/posts' );
		$this->assertArrayHasKey( '/wc/v3/products', $later->get_routes() );
		$config = Compiled_Config::load();
		$this->assertNotNull( $config );
		$this->assertNotSame( '', $config->fingerprint(), 'The snapshot is current again.' );
		$this->assertFalse( $config->pending_discovery() );
		$this->assertNotSame( $stale, get_option( Names::OPTION_CONFIG ) );
		$this->assertCount( 26, $this->catalog_keys_in_snapshot(), 'Every catalog route survived.' );
		foreach ( $config->routes() as $route ) {
			if ( Store_Routes::is_catalog( $route->key ) ) {
				$this->assertTrue( $route->enabled, $route->key . ' is enabled.' );
			}
		}
		$this->assertNoFailures();
	}
}
