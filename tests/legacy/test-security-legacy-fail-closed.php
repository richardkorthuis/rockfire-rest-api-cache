<?php
/**
 * Security regression test of the legacy fail-closed rule: without `RFRAC_LEGACY_HIT_FILTERS`, a hooked `cache_key` or
 * `filter_cache_output` callback of the original plugin stops the plugin from storing a response, so a per-visitor
 * variant (here: a currency cookie) is never stored and never served to another visitor.
 *
 * Every test runs for the early server and for the fallback server; the ones that define the constant run in a
 * separate process, because a constant cannot be undefined again.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Legacy\Hook_Registry;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Store_Bridge
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 */
class Test_Security_Legacy_Fail_Closed extends Rfrac_E2e_Test_Case {

	private const ROUTE = '/tst/v1/price';

	private const URI = '/wp-json/tst/v1/price';

	public function set_up() {
		parent::set_up();
		// The booted container wired its legacy hooks; the live request of the harness wires its own.
		foreach ( Plugin::instance()->legacy_hooks() as $hook ) {
			remove_filter( $hook['hook'], $hook['callback'], $hook['priority'] );
		}
		$this->configure(
			array( 'store_mode' => 'database' ),
			array( Rfrac_Serving_Harness::route( self::ROUTE, array( 'vary_cookies' => array( 'rfrac_cur' => array( 'EUR', 'USD' ) ) ) ) )
		);
		// The plugin that declares the currency cookie does it on every request (capture compares it with the compiled one).
		add_filter(
			Hooks::ROUTE_VARY_COOKIES,
			static fn( $declared, $key ) => self::ROUTE === $key ? array( 'rfrac_cur' => array( 'USD', 'EUR' ) ) : $declared,
			10,
			2
		);
	}

	/**
	 * Register the route of the tests: its response shows the currency of the cookie of the request.
	 *
	 * @return void
	 */
	public function register_price_route(): void {
		register_rest_route(
			'tst/v1',
			'/price',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static fn() => array(
					'currency' => isset( $_COOKIE['rfrac_cur'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['rfrac_cur'] ) ) : '',
					'price'    => 100,
				),
			)
		);
	}

	/**
	 * Data provider: each hit filter that stops storing, on each serving path.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function store_refusing_filters_on_each_path(): array {
		$cases = array();
		foreach ( array( Rfrac_Serving_Harness::EARLY, Rfrac_Serving_Harness::FALLBACK ) as $path ) {
			$cases[ 'cache_key on the ' . $path . ' path' ]           = array( $path, 'wp_rest_cache/cache_key' );
			$cases[ 'filter_cache_output on the ' . $path . ' path' ] = array( $path, 'wp_rest_cache/filter_cache_output' );
		}
		return $cases;
	}

	/**
	 * Hook a callback on a legacy hit filter, as a currency plugin of the original would.
	 *
	 * @param string $name Full name of the hook.
	 *
	 * @return void
	 */
	private function hook_a_callback_on( string $name ): void {
		if ( 'wp_rest_cache/cache_key' === $name ) {
			add_filter( $name, static fn() => 'prices' );
			return;
		}
		add_filter( $name, static fn( $data ) => $data );
	}

	/**
	 * The currency cookie of a request.
	 *
	 * @param string $currency Currency code.
	 *
	 * @return array<string,string>
	 */
	private function cur( string $currency ): array {
		return array( 'rfrac_cur' => $currency );
	}

	/**
	 * Assert that the live response was not stored, with the reason of the debug header.
	 *
	 * @param Rfrac_Serving_Harness $h      The harness.
	 * @param Bypass_Reason         $reason The reason it was not stored.
	 *
	 * @return void
	 */
	private function assert_not_stored( Rfrac_Serving_Harness $h, Bypass_Reason $reason ): void {
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'Nothing was stored.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->assertSame( $reason->value, $h->live_header( 'X-Rfrac-Cache-Reason' ) );
		}
	}

	/**
	 * AC-1, AC-2: without the constant the requests of two currencies run through lookup and serve, nothing is
	 * stored, every live response is a bypass with the reason `filtered`, and no visitor is served another's currency.
	 *
	 * @dataProvider store_refusing_filters_on_each_path
	 *
	 * @param string $path Serving path.
	 * @param string $name The hooked hit filter.
	 */
	public function test_without_the_constant_a_hooked_hit_filter_stores_nothing_across_currencies( string $path, string $name ): void {
		$this->assertFalse( defined( Hook_Registry::HIT_FILTERS_CONSTANT ), 'Precondition.' );
		$this->hook_a_callback_on( $name );
		$h = $this->harness( $path )->with_legacy();

		foreach ( array( 'EUR', 'USD' ) as $currency ) {
			$h->request( self::URI, array(), $this->cur( $currency ) );
			$body = $h->miss( self::ROUTE, array( $this, 'register_price_route' ) );

			$this->assertNotNull( $body, $currency . ' is not served from the cache.' );
			$this->assertSame( $currency, json_decode( $body, true )['currency'] ?? null, 'The response is the one of its own currency.' );
			$this->assert_not_stored( $h, Bypass_Reason::FILTERED );
		}

		foreach ( array( 'EUR', 'USD' ) as $currency ) {
			$h->request( self::URI, array(), $this->cur( $currency ) );
			$this->assertFalse( $h->lookup(), $currency . ' finds nothing to serve.' );
			$this->assertSame( '', $h->output );
		}
		$this->assertNoFailures();
	}

	/**
	 * AC-6: a hit filter that only changes the headers of a hit does not stop storing without the constant.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_without_the_constant_a_hooked_pre_output_headers_still_stores( string $path ): void {
		$this->assertFalse( defined( Hook_Registry::HIT_FILTERS_CONSTANT ), 'Precondition.' );
		add_filter( 'wp_rest_cache/pre_output_headers', static fn( $headers ) => $headers );
		$h = $this->harness( $path )->with_legacy();
		$h->request( self::URI, array(), $this->cur( 'EUR' ) );

		$this->assertNotNull( $h->miss( self::ROUTE, array( $this, 'register_price_route' ) ) );

		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'The response was stored.' );
		$this->assertNoFailures();
	}

	/**
	 * The refusal ends with the callback: unhooked, the same response is stored again.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_unhooking_the_callback_lets_the_response_be_stored( string $path ): void {
		$callback = static fn() => 'prices';
		add_filter( 'wp_rest_cache/cache_key', $callback );
		$h = $this->harness( $path )->with_legacy();
		$h->request( self::URI, array(), $this->cur( 'EUR' ) );

		$this->assertNotNull( $h->miss( self::ROUTE, array( $this, 'register_price_route' ) ) );
		$this->assert_not_stored( $h, Bypass_Reason::FILTERED );

		remove_filter( 'wp_rest_cache/cache_key', $callback );
		$h->request( self::URI, array(), $this->cur( 'EUR' ) );

		$this->assertNotNull( $h->miss( self::ROUTE, array( $this, 'register_price_route' ) ) );

		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
	}

	/**
	 * AC-5: with the constant the rule does not veto, so the response is stored as before.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider store_refusing_filters_on_each_path
	 *
	 * @param string $path Serving path.
	 * @param string $name The hooked hit filter.
	 */
	public function test_with_the_constant_a_hooked_hit_filter_does_not_veto_and_the_response_is_stored( string $path, string $name ): void {
		define( 'RFRAC_LEGACY_HIT_FILTERS', true );
		$this->hook_a_callback_on( $name );
		$h = $this->harness( $path )->with_legacy();
		$h->request( self::URI, array(), $this->cur( 'EUR' ) );

		$body = $h->miss( self::ROUTE, array( $this, 'register_price_route' ) );

		$this->assertNotNull( $body );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'The response was stored.' );
		$this->assertNoFailures();
	}
}
