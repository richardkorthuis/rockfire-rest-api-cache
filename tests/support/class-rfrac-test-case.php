<?php
/**
 * Shared base test case for the caching engine tests.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Schema\Migrator;
use Rockfire\Rest_Api_Cache\Schema\Schema;
use Rockfire\Rest_Api_Cache\Serving\Early_Server;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * Base class for engine tests: helpers to seed the compiled config and reset per-request state.
 */
abstract class Rfrac_Test_Case extends WP_UnitTestCase {

	use Rfrac_Drops_Site_Tables;

	/**
	 * Reset the options and per-request state touched by the engine.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Names::OPTION_SETTINGS );
		delete_option( Names::OPTION_CONFIG );
		$this->reset_request_state();
		$this->detach_engine_hooks();
		$this->reset_plugin_invalidators();
	}

	/**
	 * Remove the engine hooks of the booted plugin container for this test, so a unit under test
	 * is not joined by the live wiring (e.g. a recompile on `rest_api_init` or a second Capture).
	 * WP_UnitTestCase restores every hook after the test. The wiring itself is tested in
	 * tests/engine/test-plugin-wiring.php.
	 */
	protected function detach_engine_hooks(): void {
		foreach ( Plugin::instance()->engine_hooks() as $hook ) {
			remove_filter( $hook['hook'], $hook['callback'], $hook['priority'] );
		}
	}

	/**
	 * Store a compiled config snapshot: the defaults with the given top-level overrides.
	 *
	 * @param array<string,mixed> $overrides Snapshot fields to override (`routes` as list of route arrays).
	 *
	 * @return array<string,mixed> The stored snapshot.
	 */
	protected function set_compiled_config( array $overrides = array() ): array {
		$config = array_merge( Compiled_Config::defaults(), $overrides );
		update_option( Names::OPTION_CONFIG, $config, false );
		return $config;
	}

	/**
	 * Create a fresh global REST server with the core routes registered.
	 *
	 * The callback runs on `rest_api_init` (after core), so it may call `register_rest_route()`.
	 * The global server is reset again in tear_down().
	 *
	 * @param callable|null $register Registers extra routes.
	 *
	 * @return WP_REST_Server
	 */
	protected function new_rest_server( ?callable $register = null ): WP_REST_Server {
		$server                    = new WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;
		if ( null !== $register ) {
			add_action( 'rest_api_init', $register, 99 );
		}
		do_action( 'rest_api_init', $server );
		if ( null !== $register ) {
			remove_action( 'rest_api_init', $register, 99 );
		}
		return $server;
	}

	/**
	 * Reset the global REST server after every test.
	 */
	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null;
		$this->reset_request_state();
		parent::tear_down();
		$this->drop_created_site_tables();
	}

	/**
	 * Reset the engine's per-request static state: the request hand-off and the early server's test writer and hit filter set.
	 */
	protected function reset_request_state(): void {
		Request_State::reset();
		Early_Server::use_writer( null );
		Early_Server::use_hit_filters( null );
	}

	/**
	 * Run a callback while every tag write statement (DELETE or INSERT IGNORE on the tags table) is invalid SQL.
	 *
	 * @param callable $callback Callback; its return value is returned.
	 * @param string   $verb     Statement to break: `INSERT IGNORE` or `DELETE`.
	 *
	 * @return mixed
	 */
	protected function with_failing_tag_writes( callable $callback, string $verb = 'INSERT IGNORE' ) {
		global $wpdb;
		$tags_table = $wpdb->prefix . Names::TABLE_TAGS;
		$filter     = static function ( $query ) use ( $verb, $tags_table ) {
			if ( is_string( $query ) && 0 === stripos( ltrim( $query ), $verb ) && false !== strpos( $query, $tags_table ) ) {
				return 'RFRAC INVALID SQL';
			}
			return $query;
		};
		$suppress   = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			return $callback();
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppress );
		}
	}

	/**
	 * Restore the bootstrap schema state after a test that ran real DDL.
	 *
	 * DDL commits implicitly, so everything written earlier in the test's transaction survives its rollback.
	 * Call this from tear_down() after deleting the rows the test created: it recreates the real tables,
	 * clears the migration lock, resets the db version to current and commits.
	 */
	protected function restore_schema_state(): void {
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		delete_option( Migrator::LOCK_OPTION );
		( new Migrator() )->install_site();
		delete_option( Migrator::LOCK_OPTION );
		update_option( Names::OPTION_DB_VERSION, Schema::VERSION, true );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Empty the buffer of the booted container's Invalidator (when built) and of every Invalidator hooked
	 * directly to `shutdown`: the pending tags, the failed ones and the deferred changes.
	 *
	 * Their `shutdown` commit never runs in tests, so without this the tags queued by earlier tests pile up until
	 * an auto-commit hits the real store in some unrelated test. The deferred changes matter as much as the tags: the
	 * test library deletes the default post while it bootstraps, with the plugin loaded, and that leaves a deferred
	 * `object_terms` change in the booted container. Resolved and committed by the first request a test serves, it
	 * advances the flush epoch in the middle of that request, and the capture refuses the response as `flushed`.
	 */
	protected function reset_plugin_invalidators(): void {
		global $wp_filter;
		$invalidators = array();
		$built        = ( new ReflectionProperty( Plugin::class, 'invalidator' ) )->getValue( Plugin::instance() );
		if ( $built instanceof Invalidator ) {
			$invalidators[] = $built;
		}
		if ( isset( $wp_filter['shutdown'] ) ) {
			foreach ( $wp_filter['shutdown']->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( is_array( $function ) && $function[0] instanceof Invalidator ) {
						$invalidators[] = $function[0];
					}
				}
			}
		}
		foreach ( $invalidators as $invalidator ) {
			foreach ( array( 'pending', 'deferred', 'failed' ) as $property ) {
				( new ReflectionProperty( Invalidator::class, $property ) )->setValue( $invalidator, array() );
			}
		}
	}
}
