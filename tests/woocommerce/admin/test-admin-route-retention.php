<?php
/**
 * Route retention tests of the admin-context suite: every enabled Store API catalog route stays in the compiled
 * routes after each wp-admin path that can rebuild the route snapshot, against the real wp-admin boot.
 *
 * The paths are enabling the catalog preset, saving settings (also after a foreign REST server was built first),
 * saving a product from a stale snapshot and a REST server built outside the plugin from a stale or a missing
 * snapshot. WooCommerce initialises the Store API only outside wp-admin, so in these requests only the plugin's own
 * discovery path (`Route_Discovery::server()`) may compile the snapshot.
 *
 * Enabling the preset and saving settings also run with the plugin's own Store API initialisation failing (a missing
 * WooCommerce internal, an initialisation that throws): the failure is recorded, nothing fatals and the snapshot keeps
 * every catalog route.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Admin_Store_Api;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * Every test runs its requests one after the other through {@see self::fresh_admin_request()}: what a request leaves
 * behind in the process (its REST server, its `rest_api_init` count, the hooks of its plugin container and of the
 * Store API it initialised) is gone when the next one starts, while the options it wrote stay, like on a site.
 *
 * @coversNothing
 */
class Test_Admin_Route_Retention extends Rfrac_Wc_Admin_Test_Case {

	/**
	 * Key of the catalog preset.
	 *
	 * @var string
	 */
	private const PRESET = 'woocommerce-catalog';

	/**
	 * The default TTL the settings save of these tests writes; different from the default of the plugin.
	 *
	 * @var int
	 */
	private const SAVED_TTL = 1234;

	/**
	 * The hook table as the test starts: the hooks of the boot of this wp-admin request, no plugin container wired
	 * yet and no Store API initialised.
	 *
	 * @var array<string,WP_Hook>
	 */
	private array $boot_hooks = array();

	public function set_up() {
		parent::set_up();
		$this->boot_hooks = $this->copy_of_hooks( $GLOBALS['wp_filter'] );
	}

	public function tear_down() {
		$this->forget_route_views();
		parent::tear_down();
	}

	/**
	 * Forget the route views the admin-post handlers of the boot container memoised.
	 *
	 * Their route service belongs to the container booted with the process, so what it read from a request, with or
	 * without the Store API, would otherwise outlive that request and the test.
	 *
	 * @return void
	 */
	private function forget_route_views(): void {
		Plugin::instance()->route_service()->flush();
	}

	/**
	 * Clones of hook objects, as the WordPress test case backs the hooks up.
	 *
	 * @param array<string,WP_Hook> $hooks Hook objects by hook name.
	 *
	 * @return array<string,WP_Hook>
	 */
	private function copy_of_hooks( array $hooks ): array {
		$copy = array();
		foreach ( $hooks as $name => $hook ) {
			$copy[ $name ] = clone $hook;
		}

		return $copy;
	}

	/**
	 * Start a fresh wp-admin request after the one before it.
	 *
	 * The hooks go back to the boot of the test: the plugin container of the earlier request and the Store API it
	 * initialised are gone, like in a new process where WooCommerce does not initialise the Store API in wp-admin. The
	 * request input, the REST server and the route views of the admin-post handlers are reset, and a new plugin
	 * container is wired the way the plugin boots in this request ({@see self::wire_plugin()}), so its Store API
	 * initialisation runs again when the plugin reads the routes. Options and database rows stay.
	 *
	 * @return void
	 */
	private function fresh_admin_request(): void {
		$GLOBALS['wp_filter'] = $this->copy_of_hooks( $this->boot_hooks ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A new request starts with the hooks of the boot.
		$this->reset_admin_request();
		$this->reset_rest_request();
		$this->forget_route_views();
		$this->wire_plugin();
	}

	/**
	 * Enable the catalog the way an administrator does, through the admin-post handler registered at boot, and
	 * leave the compiled snapshot current with every catalog route enabled.
	 *
	 * @return void
	 */
	private function enable_the_catalog(): void {
		$this->as_admin();
		$this->fresh_admin_request();

		$this->post_admin_action( 'enable_route_preset', self::PRESET );

		$config = $this->assert_catalog_enabled_in_snapshot();
		$this->assertNotSame( '', $config->fingerprint(), 'Precondition: the snapshot is current.' );
	}

	/**
	 * Assert that the stored snapshot holds all 26 catalog routes, each enabled.
	 *
	 * @return Compiled_Config The snapshot.
	 */
	private function assert_catalog_enabled_in_snapshot(): Compiled_Config {
		$keys = $this->catalog_keys_in_snapshot();
		$this->assertCount( 26, $keys, 'The snapshot holds every catalog route.' );
		$this->assertEqualsCanonicalizing( Store_Routes::CATALOG, $keys );

		$config = Compiled_Config::load();
		$this->assertNotNull( $config );
		foreach ( $config->routes() as $route ) {
			if ( Store_Routes::is_catalog( $route->key ) ) {
				$this->assertTrue( $route->enabled, $route->key . ' is enabled.' );
			}
		}

		return $config;
	}

	/**
	 * Assert that a REST server registered no Store API route, like the one of an admin request in which
	 * WooCommerce did not initialise the Store API.
	 *
	 * @param WP_REST_Server $server REST server.
	 *
	 * @return void
	 */
	private function assert_without_store_api( WP_REST_Server $server ): void {
		$this->assertArrayNotHasKey( $this->store_route( 'products' ), $server->get_routes(), 'Precondition: the server has no Store API route.' );
		$this->assertSame( array(), $this->catalog_routes_on( $server ) );
	}

	/**
	 * Make the plugin container of this request initialise the Store API through a component that fails.
	 *
	 * The module builds its component without arguments, so the component of the container is replaced on the
	 * discovery-server action by one whose required internals or initialisation are the given ones; the detector of an
	 * already initialised Store API is the real one, which finds the Store API uninitialised in a fresh admin request.
	 *
	 * @param \Closure|null                            $init     Initialisation; null for WooCommerce's own.
	 * @param array<int,array{0:string,1:string}>|null $required The `[ class, method ]` internals checked before it; null for the real ones.
	 *
	 * @return void
	 */
	private function wire_failing_store_api( ?\Closure $init, ?array $required ): void {
		remove_all_filters( Hooks::DISCOVERY_SERVER );
		( new Admin_Store_Api( $init, null, $required ) )->register();
	}

	/**
	 * Assert that the failure of the Store API initialisation is recorded.
	 *
	 * The recorder keeps one record, the last one, and writes at most once a minute: the guard records before the
	 * REST server is built, so a later record of the same request (a deferred compile) does not replace it.
	 *
	 * @param string $message Part of the recorded message that names what failed.
	 *
	 * @return void
	 */
	private function assert_store_api_failure_recorded( string $message ): void {
		$failure = Failure_Recorder::last();

		$this->assertNotNull( $failure, 'The failed Store API initialisation is recorded.' );
		$this->assertSame( Admin_Store_Api::FAILURE_CONTEXT, $failure['context'] );
		$this->assertStringContainsString( $message, $failure['message'] );
	}

	/**
	 * Assert that a failed Store API initialisation left the snapshot as it was: every catalog route still enabled,
	 * nothing compiled from the server without the Store API, and the fingerprint stale or unchanged.
	 *
	 * @param Compiled_Config $before The snapshot before the request.
	 *
	 * @return void
	 */
	private function assert_snapshot_kept( Compiled_Config $before ): void {
		$kept = $this->assert_catalog_enabled_in_snapshot();

		$this->assertSame( $before->default_ttl(), $kept->default_ttl(), 'Nothing was compiled from the server without the Store API.' );
		$this->assertContains( $kept->fingerprint(), array( '', $before->fingerprint() ), 'The snapshot is stale or unchanged.' );
	}

	/**
	 * The Store API initialisation failures of the plugin's own discovery path, as the arguments of
	 * {@see self::wire_failing_store_api()} and the part of the recorded message that names the failure.
	 *
	 * @return array<string,array{0:\Closure|null,1:array<int,array{0:string,1:string}>|null,2:string}>
	 */
	public function failing_store_api_provider(): array {
		return array(
			'a missing WooCommerce internal' => array(
				null,
				array( array( 'Rfrac_No_Such_Wc_Class', 'container' ) ),
				'Rfrac_No_Such_Wc_Class::container',
			),
			'an initialisation that throws'  => array(
				static function (): void {
					throw new RuntimeException( 'The Store API initialisation failed.' );
				},
				null,
				'RuntimeException: The Store API initialisation failed.',
			),
		);
	}

	public function test_enabling_the_catalog_preset_puts_every_catalog_route_in_the_compiled_routes(): void {
		$this->as_admin();
		$this->fresh_admin_request();
		$this->assertSame( array(), Config::instance()->stored_route_rules(), 'Precondition: no rule is stored.' );
		$this->assertFalse( get_option( Names::OPTION_CONFIG ), 'Precondition: no snapshot is stored.' );

		$redirect = $this->post_admin_action( 'enable_route_preset', self::PRESET );

		$this->assertStringContainsString( 'rfrac-routes', $redirect->location );
		$this->assertSame( array( array( 'type' => 'success', 'message' => 'Enabled 26 routes of WooCommerce catalog.' ) ), ( new Notices() )->queued() );
		$config = $this->assert_catalog_enabled_in_snapshot();
		$this->assertNotSame( '', $config->fingerprint(), 'The snapshot is current.' );
		$this->assertFalse( $config->pending_discovery() );
		$this->assertNoFailures();
	}

	public function test_a_settings_save_keeps_every_catalog_route_and_compiles_the_saved_settings(): void {
		$this->enable_the_catalog();
		$this->fresh_admin_request();

		$result = Config::instance()->save_settings( array( 'default_ttl' => self::SAVED_TTL ) );

		$this->assertTrue( $result->is_valid() );
		$config = $this->assert_catalog_enabled_in_snapshot();
		$this->assertSame( self::SAVED_TTL, $config->default_ttl() );
		$this->assertNotSame( '', $config->fingerprint(), 'The snapshot is current.' );
		$this->assertNoFailures();
	}

	public function test_a_settings_save_after_a_foreign_server_compiles_nothing_and_the_next_discovery_compiles_the_saved_settings(): void {
		$this->enable_the_catalog();
		$previous_ttl = $this->assert_catalog_enabled_in_snapshot()->default_ttl();
		$this->assertNotSame( self::SAVED_TTL, $previous_ttl, 'Precondition: the save changes the default TTL.' );

		// Another plugin builds the REST server before the plugin reads the routes: it has no Store API route.
		$this->fresh_admin_request();
		$foreign = rest_get_server();
		$this->assertSame( 1, did_action( 'rest_api_init' ) );
		$this->assert_without_store_api( $foreign );

		$result = Config::instance()->save_settings( array( 'default_ttl' => self::SAVED_TTL ) );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( self::SAVED_TTL, Config::instance()->settings()['default_ttl'], 'The setting is saved.' );
		$this->assertSame( $foreign, rest_get_server(), 'The save did not build another server.' );
		$kept = $this->assert_catalog_enabled_in_snapshot();
		$this->assertSame( $previous_ttl, $kept->default_ttl(), 'Nothing was compiled from the server without the Store API.' );
		$this->assertSame( '', $kept->fingerprint(), 'The snapshot is marked stale.' );

		// The next request that reads the routes through the plugin compiles with the saved settings.
		$this->fresh_admin_request();
		$server = $this->discovery_server();
		$this->assertCount( 26, $this->catalog_routes_on( $server ), 'The discovery server has every catalog route.' );

		$compiled = $this->assert_catalog_enabled_in_snapshot();
		$this->assertSame( self::SAVED_TTL, $compiled->default_ttl() );
		$this->assertNotSame( '', $compiled->fingerprint(), 'The snapshot is current again.' );
		$this->assertNoFailures();
	}

	public function test_a_product_save_from_a_stale_snapshot_recompiles_it_with_every_catalog_route(): void {
		$this->enable_the_catalog();
		( new Config_Compiler() )->mark_stale();
		$this->assertSame( '', Compiled_Config::load()?->fingerprint(), 'Precondition: the snapshot is stale.' );

		// The content save reads the invalidation rules, which builds the REST server through the discovery path.
		$this->fresh_admin_request();
		$this->assertSame( 0, did_action( 'rest_api_init' ), 'Precondition: no REST server is built yet.' );

		$product = new WC_Product_Simple();
		$product->set_name( 'Retention product' );
		$product->set_regular_price( '10' );
		$product->set_status( 'publish' );
		$product->save();

		$this->assertGreaterThan( 0, $product->get_id(), 'The product is saved.' );
		$this->assertSame( 1, did_action( 'rest_api_init' ), 'The product save built exactly one REST server.' );
		$config = $this->assert_catalog_enabled_in_snapshot();
		$this->assertNotSame( '', $config->fingerprint(), 'The snapshot is current again.' );
		$this->assertNoFailures();
	}

	public function test_a_foreign_server_leaves_a_stale_snapshot_untouched(): void {
		$this->enable_the_catalog();
		( new Config_Compiler() )->mark_stale();
		$stale = get_option( Names::OPTION_CONFIG );
		$this->assertIsArray( $stale );
		$this->assertSame( '', $stale['fingerprint'] ?? null, 'Precondition: the snapshot is stale.' );

		$this->fresh_admin_request();
		$server = rest_get_server();

		$this->assert_without_store_api( $server );
		$this->assertSame( $stale, get_option( Names::OPTION_CONFIG ), 'The stored snapshot is the stale one.' );
		$this->assert_catalog_enabled_in_snapshot();
		$this->assertNoFailures();
	}

	public function test_a_foreign_server_does_not_compile_a_missing_snapshot_and_the_next_discovery_does(): void {
		$this->enable_the_catalog();
		$this->assertCount( 26, Config::instance()->stored_route_rules(), 'Precondition: the catalog rules are saved.' );
		delete_option( Names::OPTION_CONFIG );
		$this->assertFalse( get_option( Names::OPTION_CONFIG ), 'Precondition: no snapshot is stored.' );

		$this->fresh_admin_request();
		$server = rest_get_server();

		$this->assert_without_store_api( $server );
		$this->assertFalse( get_option( Names::OPTION_CONFIG ), 'Nothing was compiled from the server without the Store API.' );

		// The next request that reads the routes through the plugin compiles from the saved rules.
		$this->fresh_admin_request();
		$this->assertCount( 26, $this->catalog_routes_on( $this->discovery_server() ), 'The discovery server has every catalog route.' );

		$config = $this->assert_catalog_enabled_in_snapshot();
		$this->assertNotSame( '', $config->fingerprint(), 'The snapshot is current.' );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider failing_store_api_provider
	 *
	 * @param \Closure|null                            $init     Initialisation; null for WooCommerce's own.
	 * @param array<int,array{0:string,1:string}>|null $required The internals checked before it; null for the real ones.
	 * @param string                                   $message  Part of the recorded message.
	 */
	public function test_a_settings_save_with_a_failing_store_api_init_records_it_and_keeps_every_catalog_route( ?\Closure $init, ?array $required, string $message ): void {
		$this->enable_the_catalog();
		$before = $this->assert_catalog_enabled_in_snapshot();
		$this->assertNotSame( self::SAVED_TTL, $before->default_ttl(), 'Precondition: the save changes the default TTL.' );
		$this->assertNoFailures();

		// A later wp-admin request, in which the Store API initialisation of the plugin fails.
		$this->fresh_admin_request();
		$this->wire_failing_store_api( $init, $required );

		$result = Config::instance()->save_settings( array( 'default_ttl' => self::SAVED_TTL ) );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( self::SAVED_TTL, Config::instance()->settings()['default_ttl'], 'The setting is saved.' );
		$this->assertSame( 1, did_action( 'rest_api_init' ), 'The save built the REST server of the discovery path.' );
		$this->assert_without_store_api( rest_get_server() );
		$this->assert_store_api_failure_recorded( $message );
		$this->assert_snapshot_kept( $before );
	}

	/**
	 * @dataProvider failing_store_api_provider
	 *
	 * @param \Closure|null                            $init     Initialisation; null for WooCommerce's own.
	 * @param array<int,array{0:string,1:string}>|null $required The internals checked before it; null for the real ones.
	 * @param string                                   $message  Part of the recorded message.
	 */
	public function test_enabling_the_catalog_preset_with_a_failing_store_api_init_records_it_and_keeps_every_catalog_route( ?\Closure $init, ?array $required, string $message ): void {
		$this->enable_the_catalog();
		$before = $this->assert_catalog_enabled_in_snapshot();
		$rules  = Config::instance()->stored_route_rules();
		$this->assertCount( 26, $rules, 'Precondition: the catalog rules are saved.' );
		$this->assertNoFailures();

		// A later wp-admin request, in which the Store API initialisation of the plugin fails.
		$this->fresh_admin_request();
		$this->wire_failing_store_api( $init, $required );

		$redirect = $this->post_admin_action( 'enable_route_preset', self::PRESET );

		$this->assertStringContainsString( 'rfrac-routes', $redirect->location );
		$this->assertSame( 1, did_action( 'rest_api_init' ), 'The preset built the REST server of the discovery path.' );
		$this->assert_without_store_api( rest_get_server() );
		$this->assert_store_api_failure_recorded( $message );
		$this->assertEquals( $rules, Config::instance()->stored_route_rules(), 'The saved catalog rules are untouched.' );
		$this->assert_snapshot_kept( $before );
	}
}
