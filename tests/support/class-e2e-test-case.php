<?php
/**
 * Shared base of the end-to-end serving tests.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Request_Context;

/**
 * Runs each test for both serving paths (`early`, `fallback`) through {@see Rfrac_Serving_Harness}.
 */
abstract class Rfrac_E2e_Test_Case extends Rfrac_Serving_Test_Case {

	/**
	 * The harness of the running test.
	 *
	 * @var Rfrac_Serving_Harness|null
	 */
	protected ?Rfrac_Serving_Harness $h = null;

	/**
	 * Data provider: the two serving paths.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function paths(): array {
		return array(
			'early'    => array( Rfrac_Serving_Harness::EARLY ),
			'fallback' => array( Rfrac_Serving_Harness::FALLBACK ),
		);
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( 0 );
		wp_cache_delete( 'count', 'rfrac' );
		delete_option( Failure_Recorder::OPTION );
		// A current snapshot inside the periodic-check window is not rebuilt by a request.
		set_transient( Config_Compiler::CHECK_TRANSIENT, time(), Config_Compiler::CHECK_INTERVAL );
		$this->configure();
	}

	public function tear_down() {
		if ( null !== $this->h ) {
			$this->h->restore();
		}
		wp_set_current_user( 0 );
		wp_cache_delete( 'count', 'rfrac' );
		delete_option( Failure_Recorder::OPTION );
		delete_transient( Config_Compiler::CHECK_TRANSIENT );
		parent::tear_down();
	}

	/**
	 * The harness for a serving path.
	 *
	 * @param string $path `early` or `fallback`.
	 *
	 * @return Rfrac_Serving_Harness
	 */
	protected function harness( string $path ): Rfrac_Serving_Harness {
		$this->h = new Rfrac_Serving_Harness( $path );
		return $this->h;
	}

	/**
	 * Store a current compiled config: `/wp/v2/posts` and `/tst/v1/*` enabled, sampling off.
	 *
	 * @param array<string,mixed>                 $overrides Top-level overrides.
	 * @param array<int,array<string,mixed>>|null $routes    Routes.
	 *
	 * @return void
	 */
	protected function configure( array $overrides = array(), ?array $routes = null ): void {
		$this->set_compiled_config( Rfrac_Serving_Harness::snapshot( $overrides, $routes ) );
	}

	/**
	 * Insert an active entry whose key matches the current request, with column overrides.
	 *
	 * @param string              $payload   Payload.
	 * @param array<string,mixed> $overrides Entry columns (e.g. `headers`).
	 *
	 * @return int Entry id.
	 */
	protected function prime_entry( string $payload, array $overrides = array() ): int {
		$config   = Compiled_Config::load();
		$this->assertNotNull( $config );
		$context  = Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() );
		$decision = $this->policy()->evaluate( $context, $config );
		$this->assertCacheable( $decision );

		return Rfrac_Entry_Fixtures::insert_entry(
			array_merge(
				array(
					'cache_key' => Cache_Key::key( $context, $decision ),
					'payload'   => $payload,
					'size'      => strlen( $payload ),
				),
				$overrides
			)
		);
	}

	/**
	 * Delete every entry (and tag) of the current site.
	 *
	 * @return void
	 */
	protected function empty_tables(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'rfrac_tags' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'rfrac_entries' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Assert that the engine recorded no failure.
	 *
	 * @return void
	 */
	protected function assertNoFailures(): void {
		$this->assertEmpty( get_option( Failure_Recorder::OPTION ), 'The engine recorded a failure.' );
	}

	/**
	 * The extra routes of the tests, for {@see Rfrac_Serving_Harness::serve()}.
	 *
	 * @return void
	 */
	public function register_test_routes(): void {
		$route = static function ( string $namespace, string $path, callable $callback ): void {
			register_rest_route(
				$namespace,
				$path,
				array(
					'methods'             => 'GET',
					'callback'            => $callback,
					'permission_callback' => '__return_true',
				)
			);
		};
		$with  = static function ( array $headers, array $data = array( 'a' => 1 ) ): WP_REST_Response {
			$response = new WP_REST_Response( $data );
			foreach ( $headers as $name => $value ) {
				$response->header( $name, $value );
			}
			return $response;
		};

		$route( 'tst/v1', '/ok', static fn() => array( 'a' => 1 ) );
		$route( 'tst/v1', '/big', static fn() => array( 'blob' => str_repeat( 'x', 1048577 ) ) );
		$route( 'tst/v1', '/set-cookie', static fn() => $with( array( 'Set-Cookie' => 'secret=1' ) ) );
		$route( 'tst/v1', '/cc-no-cache', static fn() => $with( array( 'Cache-Control' => 'no-cache, must-revalidate, max-age=0' ) ) );
		$route( 'tst/v1', '/cc-no-store', static fn() => $with( array( 'Cache-Control' => 'no-store' ) ) );
		$route( 'tst/v1', '/cc-private', static fn() => $with( array( 'Cache-Control' => 'private, max-age=60' ) ) );
		$route( 'tst/v1', '/cc-max-age-0', static fn() => $with( array( 'Cache-Control' => 'max-age=0' ) ) );
		$route(
			'tst/v1',
			'/cc-public',
			static fn() => $with(
				array(
					'Cache-Control' => 'public, max-age=300',
					'X-WP-Total'    => '7',
					'X-Custom'      => 'nope',
				)
			)
		);
		$route(
			'tst/v1',
			'/php-no-cache',
			function () {
				// Stands in for header( 'Cache-Control: no-cache, …' ) sent by a callback or another plugin.
				if ( null !== $this->h ) {
					$this->h->php_headers[] = 'Cache-Control: no-cache, must-revalidate, max-age=0';
				}
				return array( 'a' => 1 );
			}
		);
		$route(
			'tst/v1',
			'/php-cookie',
			function () {
				if ( null !== $this->h ) {
					$this->h->php_headers[] = 'Set-Cookie: visitor=1; path=/';
				}
				return array( 'a' => 1 );
			}
		);
		$route( 'wc/store/v1', '/cart', static fn() => array( 'items' => array() ) );
	}
}
