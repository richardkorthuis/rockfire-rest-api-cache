<?php
/**
 * Shared base of the admin-context WooCommerce tests.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Admin\Action_Handler;
use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Route_Discovery;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * The base of the tests that run in a wp-admin request (`WP_ADMIN` is defined before WooCommerce loads, see
 * tests/bootstrap-woocommerce-admin.php), with WooCommerce fixtures and the admin helpers.
 *
 * It starts every test as a fresh admin request: no REST server, no failure recorded, redirects intercepted. It ends
 * every test with the request input, the WooCommerce state, the action counters it cleared and the screen reset. A
 * test never simulates admin: it is admin from the boot.
 *
 * It does not clear the WooCommerce activation redirect the install leaves pending: the bootstrap deletes it once,
 * and Test_Admin_Boot proves that deletion. Clearing it here too would hide a bootstrap that stopped doing it.
 */
abstract class Rfrac_Wc_Admin_Test_Case extends Rfrac_Test_Case {

	use Rfrac_Wc_Test_Helpers;
	use Rfrac_Admin_Test_Helpers;

	/**
	 * The count of every action {@see self::clear_action()} cleared, before the first clear.
	 *
	 * @var array<string,int>
	 */
	private array $cleared_actions = array();

	public function set_up() {
		parent::set_up();
		delete_option( Failure_Recorder::OPTION );
		$this->reset_rest_request();
		$this->reset_wc_state();
		$this->intercept_redirect();
	}

	public function tear_down() {
		global $wp_actions;

		$this->reset_admin_request();
		$this->reset_wc_state();
		foreach ( $this->cleared_actions as $action => $count ) {
			if ( $count > 0 ) {
				$wp_actions[ $action ] = $count; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the counters this test cleared.
			} else {
				unset( $wp_actions[ $action ] );
			}
		}
		$this->cleared_actions = array();
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	/**
	 * Wire a new container the way the plugin boots in this admin request: the engine hooks, and with them the
	 * WooCommerce module.
	 *
	 * The container booted with the plugin has its engine hooks detached for every test; this one carries its own.
	 *
	 * @return Plugin
	 */
	protected function wire_plugin(): Plugin {
		$plugin = new Plugin();
		$plugin->register_engine_hooks();
		$this->assertInstanceOf( Module::class, $plugin->woocommerce() );

		return $plugin;
	}

	/**
	 * Make an action count as not yet fired in this request until tear_down().
	 *
	 * Clearing an action again keeps the count it had before the first clear.
	 *
	 * @param string $action Action name.
	 *
	 * @return void
	 */
	protected function clear_action( string $action ): void {
		global $wp_actions;

		if ( ! array_key_exists( $action, $this->cleared_actions ) ) {
			$this->cleared_actions[ $action ] = (int) ( $wp_actions[ $action ] ?? 0 );
		}
		unset( $wp_actions[ $action ] );
	}

	/**
	 * Start a fresh admin request for the REST server: no server is built and `rest_api_init` has not fired.
	 *
	 * Usable in the middle of a test, whenever the next read of the routes must build the server again.
	 *
	 * @return void
	 */
	protected function reset_rest_request(): void {
		$GLOBALS['wp_rest_server'] = null;
		$this->clear_action( 'rest_api_init' );
	}

	/**
	 * The REST server the plugin builds to read the routes, as its route manager and settings-save compile do.
	 *
	 * @return WP_REST_Server
	 */
	protected function discovery_server(): WP_REST_Server {
		return Route_Discovery::server();
	}

	/**
	 * The Store API catalog route patterns registered on a server, sorted.
	 *
	 * @param WP_REST_Server $server REST server.
	 *
	 * @return list<string>
	 */
	protected function catalog_routes_on( WP_REST_Server $server ): array {
		$registered = array_map( 'strval', array_keys( $server->get_routes() ) );
		$found      = array_values(
			array_filter(
				Store_Routes::CATALOG,
				static fn( string $pattern ): bool => in_array( $pattern, $registered, true )
			)
		);
		sort( $found );

		return $found;
	}

	/**
	 * Keys of the Store API catalog routes the stored snapshot holds.
	 *
	 * @return list<string>
	 */
	protected function catalog_keys_in_snapshot(): array {
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
	 * Post an admin-post action of the plugin with a valid nonce and return the redirect.
	 *
	 * @param string $action Action name without the `rfrac_` prefix.
	 * @param string $target Target of the action, e.g. a preset key.
	 *
	 * @return Redirect_Intercepted
	 */
	protected function post_admin_action( string $action, string $target ): Redirect_Intercepted {
		$this->set_request(
			array(),
			array(
				'action'   => 'rfrac_' . $action,
				'target'   => $target,
				'_wpnonce' => wp_create_nonce( Action_Handler::nonce_action( $action, $target ) ),
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
	 * Assert that no failure is recorded: the plugin wrote nothing to its failure option.
	 *
	 * @return void
	 */
	protected function assertNoFailures(): void {
		$failure = Failure_Recorder::last();

		$this->assertNull(
			$failure,
			null === $failure ? '' : sprintf( 'A failure was recorded: [%s] %s', $failure['context'], $failure['message'] )
		);
	}
}
