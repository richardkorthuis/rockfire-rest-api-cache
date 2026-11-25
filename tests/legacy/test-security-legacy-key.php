<?php
/**
 * Security regression tests: a `wp_rest_cache/cache_key` callback can not merge visitors the engine keeps apart.
 *
 * A callback of the original that answers one string for every visitor (here `prices`) used to make the key of
 * a replaced GET that string alone, so a route that varies on a cookie served the payload of the first currency to
 * every other one. The replaced key now keeps every component of the engine key.
 *
 * Every test runs for the early server and for the fallback server, each in a separate process because the
 * constant cannot be undefined again once it is defined.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Storage\Cached_Response;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Factory;

/**
 * @covers \Rockfire\Rest_Api_Cache\Request\Cache_Key::replaced
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Hit_Filter_Pass
 * @covers \Rockfire\Rest_Api_Cache\Serving\Lookup
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 */
class Test_Security_Legacy_Key extends Rfrac_E2e_Test_Case {

	private const ROUTE = '/tst/v1/prices';

	private const URI = '/wp-json/tst/v1/prices';

	public function set_up() {
		parent::set_up();
		$this->configure(
			array( 'store_mode' => 'database' ),
			array( Rfrac_Serving_Harness::route( self::ROUTE, array( 'vary_cookies' => array( 'cur' => array( 'EUR', 'USD' ) ) ) ) )
		);
		// The plugin that sets the cookie declares it on every request; the capture compares the two declarations.
		add_filter(
			Hooks::ROUTE_VARY_COOKIES,
			static fn( $declared, $key ) => self::ROUTE === $key ? array( 'cur' => array( 'USD', 'EUR' ) ) : $declared,
			10,
			2
		);
	}

	/**
	 * Register the route of the test: its payload names the currency of the visitor.
	 *
	 * @return void
	 */
	public function register_prices_route(): void {
		register_rest_route(
			'tst/v1',
			'/prices',
			array(
				'methods'             => 'GET',
				'callback'            => static fn() => array( 'currency' => isset( $_COOKIE['cur'] ) ? (string) $_COOKIE['cur'] : '' ), // phpcs:ignore WordPress.Security
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Cookies of a visitor.
	 *
	 * @param string $currency Value of the `cur` cookie.
	 *
	 * @return array<string,string>
	 */
	private function visitor( string $currency ): array {
		return array( 'cur' => $currency );
	}

	/**
	 * The keys of the current request: the one of the engine and the one a replacement of the callback gets.
	 *
	 * @param string $replacement What the callback returned.
	 *
	 * @return array{0:string,1:string}
	 */
	private function keys( string $replacement ): array {
		$config = Compiled_Config::load();
		$this->assertNotNull( $config );
		$context  = Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() );
		$decision = $this->policy()->evaluate( $context, $config );
		$this->assertCacheable( $decision );
		return array( Cache_Key::key( $context, $decision ), Cache_Key::replaced( $replacement, $context, $decision ) );
	}

	/**
	 * A stored entry of the database store, null when there is none.
	 *
	 * @param string $key Cache key.
	 *
	 * @return Cached_Response|null
	 */
	private function stored( string $key ): ?Cached_Response {
		return Store_Factory::for_mode( 'database', new Repository(), 0 )->get( $key );
	}

	/**
	 * Currency of a payload.
	 *
	 * @param string|null $payload Payload.
	 *
	 * @return string|null
	 */
	private function currency_of( ?string $payload ): ?string {
		$data = json_decode( (string) $payload, true );
		return is_array( $data ) && isset( $data['currency'] ) ? (string) $data['currency'] : null;
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_cache_key_callback_answering_one_string_never_serves_one_currency_to_another( string $path ): void {
		define( 'RFRAC_LEGACY_HIT_FILTERS', true );
		add_filter( 'wp_rest_cache/cache_key', static fn() => 'prices' );
		$h        = $this->harness( $path );
		$register = array( $this, 'register_prices_route' );

		$h->request( self::URI, array(), $this->visitor( 'EUR' ) );
		list( $eur_engine_key, $eur_key ) = $this->keys( 'prices' );
		$eur                              = $h->miss( self::ROUTE, $register );
		$this->assertNotNull( $eur, 'The EUR request is a miss.' );
		$this->assertSame( 'EUR', $this->currency_of( $eur ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$this->assertNotSame( hash( 'sha256', 'prices' ), $eur_key, 'The replacement alone is not the key.' );
		$this->assertNotSame( $eur_engine_key, $eur_key );
		$this->assertInstanceOf( Cached_Response::class, $this->stored( $eur_key ), 'The EUR entry is stored under the replaced key.' );

		$h->request( self::URI, array(), $this->visitor( 'USD' ) );
		list( , $usd_key ) = $this->keys( 'prices' );
		$this->assertNotSame( $eur_key, $usd_key, 'The same replacement for two currencies is two keys.' );
		$usd = $h->miss( self::ROUTE, $register );
		$this->assertNotNull( $usd, 'USD is not served the EUR entry.' );
		$this->assertSame( '', $h->output );
		$this->assertSame( 'USD', $this->currency_of( $usd ) );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count(), 'A second entry is stored.' );
		$this->assertInstanceOf( Cached_Response::class, $this->stored( $usd_key ) );

		$h->request( self::URI, array(), $this->visitor( 'EUR' ) );
		$this->assertTrue( $h->lookup(), 'EUR is a hit.' );
		$this->assertSame( 'EUR', $this->currency_of( $h->output ), 'EUR is served its own payload.' );

		$h->request( self::URI, array(), $this->visitor( 'USD' ) );
		$this->assertTrue( $h->lookup(), 'USD is a hit.' );
		$this->assertSame( 'USD', $this->currency_of( $h->output ), 'USD is served its own payload.' );
		$this->assertNoFailures();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_cache_key_callback_answering_the_engine_key_keeps_the_key_of_the_engine( string $path ): void {
		define( 'RFRAC_LEGACY_HIT_FILTERS', true );
		add_filter( 'wp_rest_cache/cache_key', static fn( $key ) => $key );
		$h        = $this->harness( $path );
		$register = array( $this, 'register_prices_route' );

		$h->request( self::URI, array(), $this->visitor( 'EUR' ) );
		list( $eur_key ) = $this->keys( 'unused' );
		$eur             = $h->miss( self::ROUTE, $register );
		$this->assertNotNull( $eur );
		$this->assertInstanceOf( Cached_Response::class, $this->stored( $eur_key ), 'The entry is stored under the key of the engine, byte for byte.' );

		$h->request( self::URI, array(), $this->visitor( 'USD' ) );
		$this->assertNotNull( $h->miss( self::ROUTE, $register ), 'USD is not served the EUR entry.' );
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count() );

		$h->request( self::URI, array(), $this->visitor( 'EUR' ) );
		$this->assertTrue( $h->lookup() );
		$this->assertSame( 'EUR', $this->currency_of( $h->output ) );
		$this->assertNoFailures();
	}
}
