<?php
/**
 * End-to-end tests of the per-visitor variance of a route: a visitor-varying route is never served or
 * stored, and a cookie-varying route stores one entry per allowed cookie value.
 *
 * Every test with a `$path` argument runs for the early server and for the fallback server.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * @covers \Rockfire\Rest_Api_Cache\Request\Cache_Policy
 * @covers \Rockfire\Rest_Api_Cache\Serving\Early_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Fallback_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 * @covers \Rockfire\Rest_Api_Cache\Plugin
 */
class Test_E2e_Visitor_Vary extends Rfrac_E2e_Test_Case {

	public function set_up() {
		parent::set_up();
		// Content to store: an empty result set is never stored.
		self::factory()->post->create_many( 3 );
	}

	/**
	 * The `/wp/v2/posts` route varying by the `rfrac_cur` cookie.
	 *
	 * @param array<string,mixed> $overrides Rule fields.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function cookie_routes( array $overrides = array() ): array {
		return array(
			Rfrac_Serving_Harness::route(
				'/wp/v2/posts',
				array_merge( array( 'vary_cookies' => array( 'rfrac_cur' => array( 'EUR', 'USD' ) ) ), $overrides )
			),
		);
	}

	/**
	 * Compile the `rfrac_cur` declaration of {@see self::cookie_routes()} and declare the same cookies live,
	 * as the plugin that declared them does on every request (capture compares the two).
	 *
	 * @param array<string,mixed> $overrides Rule fields.
	 *
	 * @return void
	 */
	private function configure_cookie_routes( array $overrides = array() ): void {
		$this->configure( array(), $this->cookie_routes( $overrides ) );
		add_filter(
			Hooks::ROUTE_VARY_COOKIES,
			static fn( $declared, $key ) => '/wp/v2/posts' === $key ? array( 'rfrac_cur' => array( 'USD', 'EUR' ) ) : $declared,
			10,
			2
		);
	}

	/**
	 * A cookie jar with the `rfrac_cur` cookie.
	 *
	 * @param mixed $value Cookie value.
	 *
	 * @return array<string,mixed>
	 */
	private function cur( mixed $value ): array {
		return array( 'rfrac_cur' => $value );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_visitor_varying_route_is_never_served_or_stored( string $path ): void {
		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts' );
		$this->prime( '{"primed":true}' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$this->configure( array(), array( Rfrac_Serving_Harness::route( '/wp/v2/posts', array( 'visitor_varies' => true ) ) ) );
		$h->request( '/wp-json/wp/v2/posts' );

		$this->assertFalse( $h->lookup(), 'The primed entry is not served.' );
		$this->assertSame( Bypass_Reason::VISITOR_VARIES, Request_State::decision()->reason );
		$this->assertSame( array(), $h->table_queries( 'SELECT' ), 'The cache is not even read.' );

		$h->serve( '/wp/v2/posts' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'Nothing is stored.' );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_each_allowed_cookie_value_has_its_own_entry_and_other_values_bypass( string $path ): void {
		$this->configure_cookie_routes();
		$h = $this->harness( $path );

		// EUR: a miss, then a hit.
		$h->request( '/wp-json/wp/v2/posts', array(), $this->cur( 'EUR' ) );
		$body = $h->miss( '/wp/v2/posts' );
		$this->assertNotNull( $body );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$h->request( '/wp-json/wp/v2/posts', array(), $this->cur( 'EUR' ) );
		$this->assertTrue( $h->lookup(), 'The second EUR request is a hit.' );
		$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( $body, $h->output );

		// USD: a miss that stores a second entry.
		$h->request( '/wp-json/wp/v2/posts', array(), $this->cur( 'USD' ) );
		$this->assertNotNull( $h->miss( '/wp/v2/posts' ), 'USD does not hit the EUR entry.' );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count() );

		// No cookie: a miss stored under the empty value.
		$h->request( '/wp-json/wp/v2/posts' );
		$this->assertNotNull( $h->miss( '/wp/v2/posts' ), 'No cookie does not hit a currency entry.' );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 3, Rfrac_Entry_Fixtures::count() );

		$h->request( '/wp-json/wp/v2/posts' );
		$this->assertTrue( $h->lookup(), 'The empty value has its own entry.' );
		$h->request( '/wp-json/wp/v2/posts', array(), $this->cur( '' ) );
		$this->assertTrue( $h->lookup(), 'An empty cookie is the same as no cookie.' );

		// A value outside the allow-list: neither served nor stored.
		$h->request( '/wp-json/wp/v2/posts', array(), $this->cur( 'GBP' ) );
		$this->assertFalse( $h->lookup() );
		$this->assertSame( Bypass_Reason::VARY_COOKIE, Request_State::decision()->reason );
		$h->serve( '/wp/v2/posts' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 3, Rfrac_Entry_Fixtures::count(), 'GBP stores nothing.' );

		// A cookie sent as an array (`rfrac_cur[]=x`) is no value either.
		$h->request( '/wp-json/wp/v2/posts', array(), $this->cur( array( 'x' ) ) );
		$this->assertFalse( $h->lookup() );
		$this->assertSame( Bypass_Reason::VARY_COOKIE, Request_State::decision()->reason );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_snapshot_that_gained_a_declaration_after_the_lookup_stores_nothing( string $path ): void {
		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts', array(), $this->cur( 'USD' ) );
		$this->assertFalse( $h->lookup(), 'Without a declaration the request is a plain miss.' );
		$this->assertTrue( Request_State::decision()->cacheable );
		$this->assertSame( array(), Request_State::context()->vary_cookies, 'The lookup read no cookie value.' );

		// The snapshot is rebuilt between the lookup and the capture.
		$this->configure_cookie_routes();
		$h->serve( '/wp/v2/posts' );

		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ), 'A cookie the lookup did not read never keys as empty.' );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count() );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_declaration_added_after_the_compile_stores_nothing( string $path ): void {
		$this->assertTrue( WP_DEBUG, 'The test suite runs with WP_DEBUG.' );
		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts', array(), $this->cur( 'EUR' ) );
		$this->assertFalse( $h->lookup(), 'The compiled route declares no cookie, so the request is a plain miss.' );
		$this->assertTrue( Request_State::decision()->cacheable );

		// A currency plugin activated after the last compile declares its cookie.
		add_filter(
			Hooks::ROUTE_VARY_COOKIES,
			static fn( $declared, $key ) => '/wp/v2/posts' === $key ? array( 'rfrac_cur' => array( 'EUR' ) ) : $declared,
			10,
			2
		);
		$h->serve( '/wp/v2/posts' );

		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( Bypass_Reason::VARY_COOKIE->value, $h->live_header( 'X-Rfrac-Cache-Reason' ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'No entry is stored without the declared cookie in its key.' );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_equal_compiled_and_live_declarations_store_the_response( string $path ): void {
		$this->configure_cookie_routes();
		$h = $this->harness( $path );

		$h->request( '/wp-json/wp/v2/posts', array(), $this->cur( 'EUR' ) );
		$this->assertNotNull( $h->miss( '/wp/v2/posts' ) );

		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertNull( $h->live_header( 'X-Rfrac-Cache-Reason' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$this->assertNoFailures();
	}

	/**
	 * Store the EUR and USD variants of a URL through the live path.
	 *
	 * @param Rfrac_Serving_Harness $h   Harness.
	 * @param string                $uri Request URI.
	 *
	 * @return void
	 */
	private function store_currency_variants( Rfrac_Serving_Harness $h, string $uri ): void {
		// Eleven posts in all, so that page 2 exists.
		self::factory()->post->create_many( 8 );
		foreach ( array( 'EUR', 'USD' ) as $currency ) {
			$h->request( $uri, array(), $this->cur( $currency ) );
			$this->assertNotNull( $h->miss( '/wp/v2/posts' ) );
			$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ), $currency );
		}
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count() );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_flush_url_flushes_every_cookie_variant_of_the_url( string $path ): void {
		$this->configure_cookie_routes();
		$h = $this->harness( $path );
		$this->store_currency_variants( $h, '/wp-json/wp/v2/posts?page=2' );

		$this->assertSame( 2, rfrac_flush_url( '/wp-json/wp/v2/posts?page=2' ) );

		foreach ( array( 'EUR', 'USD' ) as $currency ) {
			$h->request( '/wp-json/wp/v2/posts?page=2', array(), $this->cur( $currency ) );
			$this->assertFalse( $h->lookup(), $currency . ' is flushed.' );
		}
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_flush_url_drops_ignored_parameters_on_a_cookie_varying_route( string $path ): void {
		$this->configure_cookie_routes( array( 'ignore_params' => array( 'utm' ) ) );
		$this->store_currency_variants( $this->harness( $path ), '/wp-json/wp/v2/posts?page=2' );

		$this->assertSame( 2, rfrac_flush_url( '/wp-json/wp/v2/posts?page=2&utm=other' ), 'The flush reads the declared cookies like a request does.' );
		$this->assertNoFailures();
	}
}
