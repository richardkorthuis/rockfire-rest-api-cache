<?php
/**
 * Tests of the `woocommerce-catalog` route preset against a real WooCommerce: one click on the Routes page enables
 * the Store API catalog routes and nothing else, and nothing is enabled until that click.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Action_Handler;
use Rockfire\Rest_Api_Cache\Admin\Admin;
use Rockfire\Rest_Api_Cache\Admin\Capability;
use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Admin\Pages\Routes_Page;
use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Config\Route_Discovery;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Services\Route_Service;
use Rockfire\Rest_Api_Cache\Services\Settings_Service;

/**
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module
 */
class Test_Catalog_Preset extends Rfrac_Test_Case {

	use Rfrac_Admin_Test_Helpers;

	/**
	 * Key of the preset.
	 *
	 * @var string
	 */
	private const PRESET = 'woocommerce-catalog';

	/**
	 * Page under test.
	 *
	 * @var Routes_Page
	 */
	private Routes_Page $page;

	/**
	 * Route service of the page.
	 *
	 * @var Route_Service
	 */
	private Route_Service $routes;

	public function set_up() {
		parent::set_up();

		// The live wiring is detached by the base class: wire only the WooCommerce module, like after `woocommerce_loaded`.
		$this->assertGreaterThan( 0, did_action( 'woocommerce_loaded' ), 'WooCommerce is loaded.' );
		$plugin = new Plugin();
		$plugin->register_integrations();
		$this->assertInstanceOf( Module::class, $plugin->woocommerce() );

		// A fresh server registers the Store API routes of the loaded WooCommerce.
		unset( $GLOBALS['wp_rest_server'] );
		rest_get_server();

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
		$this->reset_admin_request();
		unset( $GLOBALS['wp_rest_server'] );
		parent::tear_down();
	}

	/**
	 * Post the admin-post action of the preset with a valid nonce and return the redirect.
	 *
	 * @param string $key Preset key.
	 */
	private function enable_preset( string $key = self::PRESET ): Redirect_Intercepted {
		return $this->post_preset( 'enable_route_preset', $key );
	}

	/**
	 * Post the disable action of the preset with a valid nonce and return the redirect.
	 *
	 * @param string $key Preset key.
	 */
	private function disable_preset( string $key = self::PRESET ): Redirect_Intercepted {
		return $this->post_preset( 'disable_route_preset', $key );
	}

	/**
	 * Post a preset admin-post action with a valid nonce and return the redirect.
	 *
	 * @param string $action Action name without the `rfrac_` prefix.
	 * @param string $key    Preset key.
	 */
	private function post_preset( string $action, string $key ): Redirect_Intercepted {
		$this->set_request(
			array(),
			array(
				'action'   => 'rfrac_' . $action,
				'target'   => $key,
				'_wpnonce' => wp_create_nonce( Action_Handler::nonce_action( $action, $key ) ),
			)
		);
		try {
			do_action( 'admin_post_rfrac_' . $action );
		} catch ( Redirect_Intercepted $redirect ) {
			return $redirect;
		}
		$this->fail( 'Expected a redirect.' );
	}

	/**
	 * The routes of the WooCommerce namespaces (`wc/...`) that are enabled, as listed by the route service.
	 *
	 * @return string[]
	 */
	private function enabled_wc_routes(): array {
		$routes = array();
		foreach ( $this->routes->all( array( 'view' => 'enabled' ) ) as $view ) {
			if ( str_starts_with( $view->route, '/wc/' ) ) {
				$routes[] = $view->route;
			}
		}
		return $routes;
	}

	/**
	 * The stored route rules.
	 *
	 * @return array<string,mixed>
	 */
	private function stored_rules(): array {
		$settings = get_option( Names::OPTION_SETTINGS, array() );
		return is_array( $settings ) && isset( $settings['routes'] ) ? (array) $settings['routes'] : array();
	}

	/**
	 * The Routes page as HTML.
	 */
	private function render(): string {
		ob_start();
		$this->page->render();
		return (string) ob_get_clean();
	}

	public function test_the_woocommerce_module_offers_the_catalog_preset_with_exactly_the_catalog_routes(): void {
		$presets = $this->routes->presets();

		$this->assertArrayHasKey( self::PRESET, $presets );
		$this->assertSame( 'WooCommerce catalog', $presets[ self::PRESET ]['label'] );
		$this->assertSame( Store_Routes::CATALOG, $presets[ self::PRESET ]['routes'] );
		$this->assertCount( 26, $presets[ self::PRESET ]['routes'] );
	}

	public function test_the_preset_is_merged_into_the_incoming_presets_and_a_non_array_input_is_an_empty_list(): void {
		$other = array(
			'label'  => 'Other',
			'routes' => array( '/other/v1/items' ),
		);

		$merged = apply_filters( Hooks::ROUTE_PRESETS, array( 'other' => $other ) );

		$this->assertIsArray( $merged );
		$this->assertSame( array( 'other', self::PRESET ), array_keys( $merged ) );
		$this->assertSame( $other, $merged['other'] );
		$this->assertSame( Store_Routes::CATALOG, $merged[ self::PRESET ]['routes'] );

		add_filter( Hooks::ROUTE_PRESETS, static fn() => 'not an array', 5 );
		$from_junk = apply_filters( Hooks::ROUTE_PRESETS, array( 'other' => $other ) );

		$this->assertIsArray( $from_junk );
		$this->assertSame( array( self::PRESET ), array_keys( $from_junk ), 'A non-array input is treated as an empty list.' );
	}

	public function test_the_woocommerce_namespaces_register_more_routes_than_the_preset_enables(): void {
		$server = rest_get_server();
		foreach ( array( '/wc/store/v1/cart', '/wc/store/v1/checkout', '/wc/store/cart', '/wc/v3/products', '/wc/v3/orders' ) as $route ) {
			$this->assertArrayHasKey( $route, $server->get_routes(), 'Precondition: ' . $route . ' is registered.' );
		}
		foreach ( Store_Routes::CATALOG as $route ) {
			$this->assertArrayHasKey( $route, $server->get_routes(), 'Precondition: ' . $route . ' is registered.' );
		}
	}

	public function test_no_catalog_route_is_enabled_until_an_administrator_uses_the_preset(): void {
		$this->as_admin();
		$this->routes->flush();

		$this->assertSame( array(), $this->enabled_wc_routes() );
		$this->assertSame( array(), $this->stored_rules() );

		$compiled = array_map( static fn( $route ) => $route->key, Compiled_Config::load()?->routes() ?? array() );
		foreach ( $compiled as $key ) {
			$this->assertStringStartsNotWith( '/wc/', $key, 'The compiled config holds no WooCommerce route.' );
		}

		$html = $this->render_list();
		$this->assertStringContainsString( 'Enable WooCommerce catalog', $html, 'The button is offered, not pressed.' );
		$this->assertSame( array(), $this->enabled_wc_routes() );
	}

	public function test_the_list_renders_an_enable_woocommerce_catalog_form(): void {
		$this->as_admin();

		$html = $this->render_list();

		$this->assertSame( 1, substr_count( $html, 'value="rfrac_enable_route_preset"' ) );
		$this->assertStringContainsString( '<button type="submit" class="button">Enable WooCommerce catalog</button>', $html );
		$this->assertStringContainsString( 'name="target" value="' . self::PRESET . '"', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( Action_Handler::nonce_action( 'enable_route_preset', self::PRESET ) ) . '"', $html );
	}

	public function test_posting_the_preset_enables_exactly_the_26_catalog_routes(): void {
		$this->as_admin();

		$redirect = $this->enable_preset();

		$this->assertStringContainsString( 'rfrac-routes', $redirect->location );
		$this->assertSame( array( array( 'type' => 'success', 'message' => 'Enabled 26 routes of WooCommerce catalog.' ) ), ( new Notices() )->queued() );
		$this->assertCount( 26, Store_Routes::CATALOG );
		$this->assertEqualsCanonicalizing( Store_Routes::CATALOG, $this->enabled_wc_routes(), 'Exactly the catalog routes of the two Store API namespaces are enabled.' );
		$this->assertEqualsCanonicalizing( Store_Routes::CATALOG, array_keys( $this->stored_rules() ), 'Nothing else was stored.' );

		foreach ( $this->enabled_wc_routes() as $route ) {
			$this->assertDoesNotMatchRegularExpression( '#^/wc/store(/v\d+)?/(cart|checkout|order|batch|shopper-lists)(/|$)#', $route );
			$this->assertDoesNotMatchRegularExpression( '#^/wc/(v\d+|agentic|private)(/|$)#', $route );
		}
	}

	public function test_posting_the_preset_again_changes_nothing_and_shows_the_info_notice(): void {
		$this->as_admin();
		$this->enable_preset();
		$rules = $this->stored_rules();
		delete_user_meta( get_current_user_id(), Notices::meta_key() );

		$this->enable_preset();

		$this->assertSame( array( array( 'type' => 'info', 'message' => 'Every registered route of WooCommerce catalog is already enabled.' ) ), ( new Notices() )->queued() );
		$this->assertSame( $rules, $this->stored_rules(), '0 routes changed.' );
		$this->assertEqualsCanonicalizing( Store_Routes::CATALOG, $this->enabled_wc_routes() );
	}

	public function test_once_enabled_the_list_offers_to_disable_the_catalog_which_stops_caching_it(): void {
		$this->as_admin();
		$this->enable_preset();

		$html = $this->render_list();
		$this->assertStringContainsString( '<button type="submit" class="button">Disable WooCommerce catalog</button>', $html );
		$this->assertStringNotContainsString( 'Enable WooCommerce catalog', $html );

		delete_user_meta( get_current_user_id(), Notices::meta_key() );
		$this->disable_preset();

		$this->assertSame( array( array( 'type' => 'success', 'message' => 'Disabled 26 routes of WooCommerce catalog.' ) ), ( new Notices() )->queued() );
		$this->assertSame( array(), $this->enabled_wc_routes(), 'No catalog route is cached any more.' );
		$this->assertSame( array(), $this->stored_rules(), 'Nothing stays stored.' );
		$this->assertStringContainsString( '<button type="submit" class="button">Enable WooCommerce catalog</button>', $this->render_list() );
	}

	public function test_a_subscriber_can_not_enable_the_preset(): void {
		$this->as_subscriber();

		try {
			$this->enable_preset();
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		$this->routes->flush();
		$this->assertSame( array(), $this->enabled_wc_routes() );
		$this->assertSame( array(), $this->stored_rules() );
	}

	/**
	 * The Routes page as HTML, on its screen.
	 */
	private function render_list(): string {
		set_current_screen( 'toplevel_page_rfrac-routes' );
		$this->set_request( array( 'page' => 'rfrac-routes' ) );

		return $this->render();
	}
}
