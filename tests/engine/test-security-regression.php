<?php
/**
 * Regression tests of the engine-owned audit findings, through both serving paths.
 *
 * Finding ids (H1, M2, ...) refer to the security audit of the original plugin.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Deny_List;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * @covers \Rockfire\Rest_Api_Cache\Request\Cache_Policy
 * @covers \Rockfire\Rest_Api_Cache\Request\Request_Context
 * @covers \Rockfire\Rest_Api_Cache\Serving\Lookup
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 * @covers \Rockfire\Rest_Api_Cache\Serving\Response_Writer
 */
class Test_Security_Regression extends Rfrac_E2e_Test_Case {

	public function set_up() {
		parent::set_up();
		self::factory()->post->create();
	}

	/**
	 * Both paths for every item of a provider.
	 *
	 * @param array<string,array<int,mixed>> $items Name => arguments.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	private static function both_paths( array $items ): array {
		$out = array();
		foreach ( array( Rfrac_Serving_Harness::EARLY, Rfrac_Serving_Harness::FALLBACK ) as $path ) {
			foreach ( $items as $name => $args ) {
				$out[ $path . ': ' . $name ] = array_merge( array( $path ), $args );
			}
		}
		return $out;
	}

	/**
	 * H1 / M1 signals: uri, server entries, cookies, expected bypass reason.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public static function signals(): array {
		$posts = '/wp-json/wp/v2/posts';
		return self::both_paths(
			array(
				'Authorization Basic'     => array( $posts, array( 'HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz' ), array(), 'credentials' ),
				'Authorization Bearer'    => array( $posts, array( 'HTTP_AUTHORIZATION' => 'Bearer abc.def.ghi' ), array(), 'credentials' ),
				'Authorization via CGI'   => array( $posts, array( 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc.def.ghi' ), array(), 'credentials' ),
				'PHP_AUTH_USER'           => array( $posts, array( 'PHP_AUTH_USER' => 'admin' ), array(), 'credentials' ),
				'X-WP-Nonce'              => array( $posts, array( 'HTTP_X_WP_NONCE' => 'abc123def4' ), array(), 'credentials' ),
				'_wpnonce'                => array( $posts . '?_wpnonce=abc123def4', array(), array(), 'credentials' ),
				'logged-in cookie'        => array( $posts, array(), array( 'wordpress_logged_in_0123abcd' => 'admin|1|x' ), 'credentials' ),
				'context=edit'            => array( $posts . '?context=edit', array(), array(), 'context' ),
				'status=draft'            => array( $posts . '?status=draft', array(), array(), 'status_param' ),
				'wp-postpass_ cookie'     => array( $posts, array(), array( 'wp-postpass_0123abcd' => 'secret' ), 'credentials' ),
				'password parameter'      => array( $posts . '?password=secret', array(), array(), 'credentials' ),
				'X-API-Key header'        => array( $posts, array( 'HTTP_X_API_KEY' => 'k' ), array(), 'credentials' ),
				'X-Auth-Token header'     => array( $posts, array( 'HTTP_X_AUTH_TOKEN' => 'k' ), array(), 'credentials' ),
				'X-Access-Token header'   => array( $posts, array( 'HTTP_X_ACCESS_TOKEN' => 'k' ), array(), 'credentials' ),
			)
		);
	}

	/**
	 * H1, M1 and the credential headers: a primed entry is not served and a response generated under the signal is not stored.
	 *
	 * @dataProvider signals
	 *
	 * @param string              $path   Serving path.
	 * @param string              $uri    Request URI with the signal.
	 * @param array<string,mixed> $server Server entries with the signal.
	 * @param array<string,mixed> $cookie Cookies with the signal.
	 * @param string              $reason Expected bypass reason.
	 */
	public function test_signal_is_never_served_from_nor_stored_into_the_cache( string $path, string $uri, array $server, array $cookie, string $reason ): void {
		$h = $this->harness( $path );

		// Control: the anonymous request is cached, so the harness could store and serve it.
		$h->request( '/wp-json/wp/v2/posts' );
		$h->serve( '/wp/v2/posts' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'Control: the anonymous response is stored.' );
		$this->empty_tables();
		$this->prime_entry( '{"primed":true}' );

		$h->request( $uri, $server, $cookie );
		$this->assertFalse( $h->lookup(), 'The primed entry is not served to this request.' );
		$this->assertSame( '', $h->output );
		$this->assertSame( array(), $h->headers );
		$this->assertSame( $reason, Request_State::decision()->reason?->value );

		$this->empty_tables();
		$h->request( $uri, $server, $cookie );
		$h->lookup();
		$h->serve( '/wp/v2/posts' );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'The response generated under the signal is not stored.' );
		$this->assertNotSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertNoFailures();
	}

	/**
	 * On CGI and FastCGI hosts the Authorization header arrives only as REDIRECT_HTTP_AUTHORIZATION: reading the globals
	 * still yields it as the authorization header, and the request bypasses as one with credentials.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_redirected_authorization_header_is_read_from_the_globals_and_bypasses( string $path ): void {
		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts' );
		$this->prime_entry( '{"primed":true}' );

		$h->request( '/wp-json/wp/v2/posts', array( 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer x' ) );
		$this->assertArrayNotHasKey( 'HTTP_AUTHORIZATION', $_SERVER, 'Precondition: the redirected header is the only one.' );
		$this->assertArrayNotHasKey( 'PHP_AUTH_USER', $_SERVER, 'Precondition: the redirected header is the only one.' );
		$this->assertSame( 'Bearer x', Request_Context::from_globals( 'wp-json' )->header( 'authorization' ), 'The redirected header is the authorization header.' );

		$this->assertFalse( $h->lookup(), 'The primed entry is not served to this request.' );
		$this->assertSame( Bypass_Reason::CREDENTIALS, Request_State::decision()->reason );
	}

	/**
	 * Let the live request through whatever nonce it carries, standing in for the valid logged-out nonce a front end
	 * sends: core answers an invalid nonce with a 403, which is never stored, and the nonce check is not under test.
	 *
	 * @return void
	 */
	private function accept_any_nonce(): void {
		add_filter( 'rest_authentication_errors', '__return_true', 1 );
	}

	/**
	 * Signals that must still bypass next to a nonce while nonce caching is on: uri, server entries, cookies, expected bypass reason.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public static function signals_next_to_a_nonce(): array {
		$posts = '/wp-json/wp/v2/posts?_wpnonce=NONCE-7f3a9c';
		$nonce = array( 'HTTP_X_WP_NONCE' => 'NONCE-7f3a9c' );
		return self::both_paths(
			array(
				'Authorization'           => array( $posts, $nonce + array( 'HTTP_AUTHORIZATION' => 'Bearer abc.def.ghi' ), array(), 'credentials' ),
				'PHP_AUTH_USER'           => array( $posts, $nonce + array( 'PHP_AUTH_USER' => 'admin' ), array(), 'credentials' ),
				'password parameter'      => array( $posts . '&password=secret', $nonce, array(), 'credentials' ),
				'X-API-Key header'        => array( $posts, $nonce + array( 'HTTP_X_API_KEY' => 'k' ), array(), 'credentials' ),
				'bypass cookie'           => array( $posts, $nonce, array( 'wp_woocommerce_session_0123abcd' => 'abc' ), 'credentials' ),
				'logged-in cookie'        => array( $posts, $nonce, array( 'wordpress_logged_in_0123abcd' => 'admin|1|x' ), 'credentials' ),
				'secure auth cookie'      => array( $posts, $nonce, array( 'wordpress_sec_0123abcd' => 'admin|1|x' ), 'credentials' ),
				'auth cookie'             => array( $posts, $nonce, array( 'wordpress_' . str_repeat( '0123abcd', 4 ) => 'admin|1|x' ), 'credentials' ),
				'wp-postpass_ cookie'     => array( $posts, $nonce, array( 'wp-postpass_0123abcd' => 'secret' ), 'credentials' ),
				'context=edit'            => array( $posts . '&context=edit', $nonce, array(), 'context' ),
			)
		);
	}

	/**
	 * With nonce caching on, a nonce next to any other signal is still never served from nor stored into the cache.
	 *
	 * @dataProvider signals_next_to_a_nonce
	 *
	 * @param string              $path   Serving path.
	 * @param string              $uri    Request URI with the signal.
	 * @param array<string,mixed> $server Server entries with the signal.
	 * @param array<string,mixed> $cookie Cookies with the signal.
	 * @param string              $reason Expected bypass reason.
	 */
	public function test_a_nonce_does_not_hide_another_signal_with_nonce_caching_on( string $path, string $uri, array $server, array $cookie, string $reason ): void {
		$this->configure( array( 'nonce_caching' => true ) );
		$this->accept_any_nonce();
		$h = $this->harness( $path );

		// Control: the request with only the nonce is cached, so the harness could store and serve it.
		$h->request( '/wp-json/wp/v2/posts?_wpnonce=NONCE-7f3a9c', array( 'HTTP_X_WP_NONCE' => 'NONCE-7f3a9c' ) );
		$h->miss( '/wp/v2/posts' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'Control: the nonce-only response is stored.' );
		$h->request( '/wp-json/wp/v2/posts' );
		$this->assertTrue( $h->lookup(), 'Control: the nonce-only entry is served.' );
		$this->empty_tables();
		$h->request( '/wp-json/wp/v2/posts' );
		$this->prime_entry( '{"primed":true}' );

		$h->request( $uri, $server, $cookie );
		$this->assertFalse( $h->lookup(), 'The primed entry is not served to this request.' );
		$this->assertSame( '', $h->output );
		$this->assertSame( array(), $h->headers );
		$this->assertSame( $reason, Request_State::decision()->reason?->value );

		$this->empty_tables();
		$h->request( $uri, $server, $cookie );
		$h->lookup();
		$h->serve( '/wp/v2/posts' );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'The response generated under the signal is not stored.' );
		$this->assertNotSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertNoFailures();
	}

	/**
	 * A login cookie bypasses, with nonce caching on or off, even when no filtered list names it.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_login_cookie_bypasses_without_any_bypass_prefix_whatever_the_nonce_flag( string $path ): void {
		$h = $this->harness( $path );

		foreach ( array( false, true ) as $nonce_caching ) {
			$this->configure( array( 'nonce_caching' => $nonce_caching, 'bypass_cookie_prefixes' => array(), 'login_cookies' => array( 'my_login' ) ) );

			foreach ( array( 'wordpress_sec_0123abcd', 'wordpress_' . str_repeat( '0123abcd', 4 ), 'wordpress_logged_in_0123abcd', 'wp-postpass_0123abcd', 'my_login' ) as $name ) {
				$this->empty_tables();
				$h->request( '/wp-json/wp/v2/posts' );
				$this->prime_entry( '{"primed":true}' );

				$h->request( '/wp-json/wp/v2/posts', array(), array( $name => 'x' ) );
				$this->assertFalse( $h->lookup(), $name . ' is not served from the cache.' );
				$this->assertSame( Bypass_Reason::CREDENTIALS, Request_State::decision()->reason, $name );
			}

			$h->request( '/wp-json/wp/v2/posts', array(), array( 'wordpress_test_cookie' => 'WP Cookie check' ) );
			$this->assertTrue( $h->lookup(), 'The test cookie is no login cookie.' );
		}
	}

	/**
	 * With nonce caching on, neither the nonce nor its parameter name reaches a database column.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_nonce_value_and_name_appear_in_no_database_column_with_nonce_caching_on( string $path ): void {
		$secret = 'NONCE-7f3a9c';
		$this->configure( array( 'nonce_caching' => true ) );
		$this->accept_any_nonce();
		$h = $this->harness( $path );

		$h->request( '/wp-json/wp/v2/posts?_wpnonce=' . $secret, array( 'HTTP_X_WP_NONCE' => $secret ) );
		$h->miss( '/wp/v2/posts' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'The nonce-only response is stored.' );

		$dump = Rfrac_Serving_Harness::database_dump();
		$this->assertStringNotContainsString( $secret, $dump );
		$this->assertStringNotContainsString( strtolower( $secret ), strtolower( $dump ) );
		$this->assertStringNotContainsString( rawurlencode( $secret ), $dump );
		$this->assertStringNotContainsString( '_wpnonce', $dump );
		$this->assertNoFailures();

		$h->request( '/wp-json/wp/v2/posts' );
		$this->assertTrue( $h->lookup(), 'The entry is found without the nonce.' );
	}

	/**
	 * A header added through `rfrac/bypass_headers` is a credential too, once compiled.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_bypass_headers_filter_adds_credential_headers( string $path ): void {
		add_filter(
			Hooks::BYPASS_HEADERS,
			static fn( $headers ) => array_merge( (array) $headers, array( 'x-tenant-key' ) )
		);
		$server = $this->new_rest_server();
		( new Plugin() )->compiler()->compile( $server );
		$this->assertContains( 'x-tenant-key', Compiled_Config::load()->bypass_headers() );

		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts' );
		$this->prime_entry( '{"primed":true}' );

		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_X_TENANT_KEY' => 'secret' ) );
		$this->assertFalse( $h->lookup() );
		$this->assertSame( Bypass_Reason::CREDENTIALS, Request_State::decision()->reason );
	}

	/**
	 * H2 URIs: a query string must not make a concrete route cacheable under another rule.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public static function cart_requests(): array {
		return self::both_paths(
			array(
				'path form'       => array( '/wp-json/wc/store/v1/cart?x=/wp/v2/posts', '/wc/store/v1/cart' ),
				'rest_route form' => array( '/?rest_route=/wc/store/v1/cart&y=wp/v2/posts', '/wc/store/v1/cart' ),
			)
		);
	}

	/**
	 * H2: the store cart is never cached, whatever the query says and even with an enabled wildcard rule.
	 *
	 * @dataProvider cart_requests
	 *
	 * @param string $path  Serving path.
	 * @param string $uri   Request URI.
	 * @param string $route Route.
	 */
	public function test_cart_with_a_route_like_query_is_not_cached( string $path, string $uri, string $route ): void {
		$this->configure(
			array(),
			array(
				Rfrac_Serving_Harness::route( '/wp/v2/posts' ),
				Rfrac_Serving_Harness::wildcard_route( '/wc/store/v1/*', array( 'param_mode' => 'allow_all' ) ),
			)
		);
		$h = $this->harness( $path );

		$h->request( $uri );
		$this->assertFalse( $h->lookup() );
		$this->assertSame( Bypass_Reason::DENIED_ROUTE, Request_State::decision()->reason );
		$this->assertSame( $route, Request_State::context()->route, 'The query does not change the route.' );

		$h->serve( $route, array( $this, 'register_test_routes' ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'The cart response is not stored.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
	}

	/**
	 * WooCommerce 11.1 session and account routes: the request URI that carries the route, the route and a wildcard key that matches it.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public static function woocommerce_denied_requests(): array {
		return self::both_paths(
			array(
				'agentic checkout sessions' => array( '/wp-json/wc/agentic/v1/checkout_sessions', '/wc/agentic/v1/checkout_sessions', '/wc/*/v1/*' ),
				'shopper lists'             => array( '/wp-json/wc/store/v1/shopper-lists', '/wc/store/v1/shopper-lists', '/wc/*/v1/*' ),
				'shopper list items'        => array( '/?rest_route=/wc/store/v1/shopper-lists/x/items', '/wc/store/v1/shopper-lists/x/items', '/wc/store/v1/shopper-lists/*/*' ),
				'private patterns'          => array( '/wp-json/wc/private/patterns', '/wc/private/patterns', '/wc/private/*' ),
			)
		);
	}

	/**
	 * The WooCommerce shopper-list, agentic and private routes are never cached, even with an enabled wildcard rule.
	 *
	 * @dataProvider woocommerce_denied_requests
	 *
	 * @param string $path     Serving path.
	 * @param string $uri      Request URI.
	 * @param string $route    Route.
	 * @param string $wildcard Wildcard rule key that matches the route.
	 */
	public function test_woocommerce_session_routes_are_not_cached_under_a_wildcard_rule( string $path, string $uri, string $route, string $wildcard ): void {
		$this->assertSame( 1, preg_match( Deny_List::wildcard_regex( $wildcard ), $route ), 'Precondition: the wildcard rule matches the route.' );

		$this->configure(
			array(),
			array(
				Rfrac_Serving_Harness::route( '/wp/v2/posts' ),
				Rfrac_Serving_Harness::wildcard_route( $wildcard, array( 'param_mode' => 'allow_all' ) ),
			)
		);
		$h = $this->harness( $path );

		$h->request( $uri );
		$this->assertFalse( $h->lookup() );
		$this->assertSame( Bypass_Reason::DENIED_ROUTE, Request_State::decision()->reason );
		$this->assertSame( $route, Request_State::context()->route );

		$h->serve( $route, array( $this, 'register_test_routes' ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'The response is not stored.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
	}

	/**
	 * M2: a query containing the home URL does not change the route and does not collide with another entry.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_query_containing_the_home_url_does_not_change_the_route_or_collide( string $path ): void {
		$h = $this->harness( $path );

		foreach ( array( home_url(), home_url( '/wp-json/wp/v2/types' ) ) as $home ) {
			foreach ( array( rawurlencode( $home ), $home ) as $search ) {
				$uri = '/wp-json/wp/v2/posts?search=' . $search;
				$h->request( $uri );
				$context = Request_Context::from_globals( 'wp-json' );
				$this->assertSame( '/wp/v2/posts', $context->route, 'The route stays the path route.' );
			}
		}

		$with_home = '/wp-json/wp/v2/posts?search=' . rawurlencode( home_url() );
		$plain     = '/wp-json/wp/v2/posts';
		$h->request( $with_home );
		$this->prime_entry( '{"which":"home"}' );
		$h->request( $plain );
		$this->prime_entry( '{"which":"plain"}' );
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count(), 'Two entries, no collision.' );

		$h->request( $with_home );
		$this->assertTrue( $h->lookup() );
		$this->assertSame( '{"which":"home"}', $h->output );
		$h->request( $plain );
		$this->assertTrue( $h->lookup() );
		$this->assertSame( '{"which":"plain"}', $h->output );
	}

	/**
	 * M2: with the site in a subdirectory only the leading home path is removed from the request path.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_home_path_is_only_removed_from_the_start_of_the_path( string $path ): void {
		update_option( 'home', 'http://example.org/blog' );
		$h = $this->harness( $path );

		$h->request( '/blog/wp-json/wp/v2/posts?search=/blog/wp-json/wp/v2/types' );
		$this->assertSame( '/wp/v2/posts', Request_Context::from_globals( 'wp-json' )->route );
		$this->prime_entry( '{"which":"subdir"}' );

		$this->assertTrue( $h->lookup() );
		$this->assertSame( '{"which":"subdir"}', $h->output );
	}

	/**
	 * M5: the value of a vary header appears in no database column.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_vary_header_value_appears_in_no_database_column( string $path ): void {
		$secret = 'SECRET-TENANT-7f3a9c';
		$this->configure( array(), array( Rfrac_Serving_Harness::route( '/wp/v2/posts', array( 'vary_headers' => array( 'x-tenant' ) ) ) ) );
		$h = $this->harness( $path );

		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_X_TENANT' => $secret ) );
		$h->miss( '/wp/v2/posts' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$dump = Rfrac_Serving_Harness::database_dump();
		$this->assertStringNotContainsString( $secret, $dump );
		$this->assertStringNotContainsString( strtolower( $secret ), strtolower( $dump ) );
		$this->assertStringNotContainsString( rawurlencode( $secret ), $dump );

		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_X_TENANT' => $secret ) );
		$this->assertTrue( $h->lookup(), 'The entry is still found by the header value.' );
		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_X_TENANT' => 'another' ) );
		$this->assertFalse( $h->lookup() );
	}

	/**
	 * M6: stored headers that must never reach a visitor are not replayed.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_set_cookie_and_cache_control_are_not_replayed_from_a_stored_entry( string $path ): void {
		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts' );
		$this->prime_entry(
			'{"primed":true}',
			array(
				'headers' => (string) wp_json_encode(
					array(
						'Set-Cookie'    => 'session=1; path=/',
						'Cache-Control' => 'public, max-age=999',
						'Authorization' => 'Bearer x',
						'X-Custom'      => 'nope',
						'X-WP-Total'    => '9',
					)
				),
			)
		);

		$this->assertTrue( $h->lookup() );
		$this->assertNull( $h->hit_header( 'Set-Cookie' ) );
		$this->assertNull( $h->hit_header( 'Cache-Control' ) );
		$this->assertNull( $h->hit_header( 'Authorization' ) );
		$this->assertNull( $h->hit_header( 'X-Custom' ) );
		$this->assertSame( '9', $h->hit_header( 'X-WP-Total' ), 'The allow-listed header is replayed.' );
	}

	/**
	 * M6: a live response with Cache-Control is stored without it, and one with Set-Cookie is not stored at all.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_live_set_cookie_response_is_not_stored_and_cache_control_is_not_stored_with_the_entry( string $path ): void {
		$h = $this->harness( $path );

		$h->request( '/wp-json/tst/v1/set-cookie' );
		$h->miss( '/tst/v1/set-cookie', array( $this, 'register_test_routes' ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'A response with Set-Cookie is not stored.' );

		$h->request( '/wp-json/tst/v1/cc-public' );
		$h->miss( '/tst/v1/cc-public', array( $this, 'register_test_routes' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'A public response is stored.' );
		$this->assertStringNotContainsStringIgnoringCase( 'cache-control', Rfrac_Serving_Harness::database_dump() );

		$h->request( '/wp-json/tst/v1/cc-public' );
		$this->assertTrue( $h->lookup() );
		$this->assertNull( $h->hit_header( 'Cache-Control' ) );
		$this->assertNull( $h->hit_header( 'X-Custom' ) );
		$this->assertSame( '7', $h->hit_header( 'X-WP-Total' ) );
	}

	/**
	 * M6: a foreign origin gets the wildcard without credentials; an allow-listed origin is echoed.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_foreign_origin_gets_a_wildcard_without_credentials( string $path ): void {
		$this->configure(
			array(
				'cors_wildcard' => true,
				'cors_origins'  => array( 'https://good.example' ),
			)
		);
		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts' );
		$this->prime_entry( '{"primed":true}' );

		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_ORIGIN' => 'https://evil.example' ) );
		$this->assertTrue( $h->lookup() );
		$this->assertSame( '*', $h->hit_header( 'Access-Control-Allow-Origin' ) );
		$this->assertNull( $h->hit_header( 'Access-Control-Allow-Credentials' ), 'No credentials for a foreign origin.' );
		$this->assertStringContainsString( 'Origin', (string) $h->hit_header( 'Vary' ) );

		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_ORIGIN' => 'https://good.example' ) );
		$this->assertTrue( $h->lookup() );
		$this->assertSame( 'https://good.example', $h->hit_header( 'Access-Control-Allow-Origin' ) );
	}

	/**
	 * Responses that must not be stored: Set-Cookie, Cache-Control no-cache / no-store / private / max-age=0,
	 * and the same headers sent with header() by a callback.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public static function unstorable_routes(): array {
		$items = array();
		foreach ( array( 'set-cookie', 'cc-no-cache', 'cc-no-store', 'cc-private', 'cc-max-age-0', 'php-no-cache', 'php-cookie' ) as $route ) {
			$items[ $route ] = array( '/tst/v1/' . $route );
		}
		return self::both_paths( $items );
	}

	/**
	 * @dataProvider unstorable_routes
	 *
	 * @param string $path  Serving path.
	 * @param string $route Route.
	 */
	public function test_responses_with_private_or_cookie_headers_are_not_stored( string $path, string $route ): void {
		$h = $this->harness( $path );
		$h->request( '/wp-json' . $route );

		$h->miss( $route, array( $this, 'register_test_routes' ) );

		$this->assertNotNull( $h->live_header( 'X-Rfrac-Cache' ), 'The capture saw the request.' );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count() );
		$this->assertNoFailures();
	}

	/**
	 * The RFRAC_DISABLE kill switch acts at once: no hit, no store, without a recompile.
	 *
	 * @dataProvider paths
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $path Serving path.
	 */
	public function test_kill_switch_serves_and_stores_nothing_without_a_recompile( string $path ): void {
		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts' );
		$this->prime_entry( '{"primed":true}' );
		$this->assertTrue( Compiled_Config::load()->enabled() );

		define( 'RFRAC_DISABLE', true );

		$this->assertFalse( $h->lookup(), 'The primed entry is not served.' );
		$this->assertSame( '', $h->output );
		$this->assertTrue( Compiled_Config::load()->enabled(), 'The compiled config was not rebuilt.' );

		$this->empty_tables();
		$h->serve( '/wp/v2/posts' );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count() );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
	}
}
