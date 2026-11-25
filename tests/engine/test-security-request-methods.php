<?php
/**
 * Security tests of request methods: on a route that opted in to POST, a request carrying any credential signal,
 * a request for non-public data or a method override bypasses on both serving paths, is never served the entry of
 * the same body and stores nothing.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * @covers \Rockfire\Rest_Api_Cache\Request\Cache_Policy
 * @covers \Rockfire\Rest_Api_Cache\Request\Request_Context
 * @covers \Rockfire\Rest_Api_Cache\Serving\Early_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Fallback_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 */
class Test_Security_Request_Methods extends Rfrac_E2e_Test_Case {

	/**
	 * The route under test.
	 *
	 * @var string
	 */
	private const ROUTE = '/tst/v1/search';

	/**
	 * The request URI of the route.
	 *
	 * @var string
	 */
	private const URI = '/wp-json/tst/v1/search';

	/**
	 * The clean JSON body.
	 *
	 * @var string
	 */
	private const JSON = '{"q":"alpha"}';

	/**
	 * The clean form body.
	 *
	 * @var string
	 */
	private const FORM = 'q=alpha';

	/**
	 * Content-Type of a JSON body.
	 *
	 * @var string
	 */
	private const TYPE_JSON = 'application/json';

	/**
	 * Content-Type of a form body.
	 *
	 * @var string
	 */
	private const TYPE_FORM = 'application/x-www-form-urlencoded';

	/**
	 * The clean JSON body with a body nonce.
	 *
	 * @var string
	 */
	private const JSON_NONCE = '{"q":"alpha","_wpnonce":"NONCE-7f3a9c"}';

	/**
	 * The clean form body with a body nonce.
	 *
	 * @var string
	 */
	private const FORM_NONCE = 'q=alpha&_wpnonce=NONCE-7f3a9c';

	public function set_up() {
		parent::set_up();
		// Nonce caching stays off (the default).
		$this->configure_route();
	}

	/**
	 * Store the compiled config: the route under test opted in to POST, with the given top-level overrides.
	 *
	 * @param array<string,mixed> $overrides Top-level overrides (e.g. `nonce_caching`).
	 *
	 * @return void
	 */
	private function configure_route( array $overrides = array() ): void {
		$this->configure(
			$overrides,
			array(
				Rfrac_Serving_Harness::route(
					self::ROUTE,
					array(
						'methods'    => array( 'POST' ),
						'param_mode' => 'allow_all',
					)
				),
			)
		);
	}

	/**
	 * Register the route under test: a read-style search that answers with `q`.
	 *
	 * @return void
	 */
	public function register_search_route(): void {
		register_rest_route(
			'tst/v1',
			'/search',
			array(
				'methods'             => 'POST',
				'callback'            => static function ( WP_REST_Request $request ): array {
					$q = $request->get_param( 'q' );
					return array( 'q' => is_scalar( $q ) ? (string) $q : '' );
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Every signal on the route under test: query suffix, server entries, cookies, body, Content-Type, expected reason.
	 *
	 * @return array<string,array{0:string,1:array<string,string>,2:array<string,string>,3:string,4:string,5:string}>
	 */
	private static function signal_cases(): array {
		return array(
			'Authorization'          => array( '', array( 'HTTP_AUTHORIZATION' => 'Bearer abc.def.ghi' ), array(), self::JSON, self::TYPE_JSON, 'credentials' ),
			'PHP_AUTH_USER'          => array( '', array( 'PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'secret' ), array(), self::JSON, self::TYPE_JSON, 'credentials' ),
			'X-WP-Nonce'             => array( '', array( 'HTTP_X_WP_NONCE' => 'abc123' ), array(), self::JSON, self::TYPE_JSON, 'credentials' ),
			'_wpnonce query'         => array( '?_wpnonce=abc123', array(), array(), self::JSON, self::TYPE_JSON, 'credentials' ),
			'_wpnonce form'          => array( '', array(), array(), self::FORM . '&_wpnonce=abc123', self::TYPE_FORM, 'credentials' ),
			'_wpnonce JSON'          => array( '', array(), array(), '{"q":"alpha","_wpnonce":"abc123"}', self::TYPE_JSON, 'credentials' ),
			'password query'         => array( '?password=x', array(), array(), self::JSON, self::TYPE_JSON, 'credentials' ),
			'password form'          => array( '', array(), array(), self::FORM . '&password=x', self::TYPE_FORM, 'credentials' ),
			'password JSON'          => array( '', array(), array(), '{"q":"alpha","password":"x"}', self::TYPE_JSON, 'credentials' ),
			'JSON context=edit'      => array( '', array(), array(), '{"q":"alpha","context":"edit"}', self::TYPE_JSON, 'context' ),
			'JSON status=draft'      => array( '', array(), array(), '{"q":"alpha","status":"draft"}', self::TYPE_JSON, 'status_param' ),
			'logged-in cookie'       => array( '', array(), array( 'wordpress_logged_in_' . str_repeat( 'a', 32 ) => 'u' ), self::JSON, self::TYPE_JSON, 'credentials' ),
			'secure auth cookie'     => array( '', array(), array( 'wordpress_sec_' . str_repeat( 'a', 32 ) => 'u' ), self::JSON, self::TYPE_JSON, 'credentials' ),
			'post password cookie'   => array( '', array(), array( 'wp-postpass_' . str_repeat( 'a', 32 ) => 'p' ), self::JSON, self::TYPE_JSON, 'credentials' ),
			'auth cookie'            => array( '', array(), array( 'wordpress_' . str_repeat( '0123456789abcdef', 2 ) => 'u' ), self::JSON, self::TYPE_JSON, 'credentials' ),
			'session cookie'         => array( '', array(), array( 'wp_woocommerce_session_x' => 's' ), self::JSON, self::TYPE_JSON, 'credentials' ),
			'API key header'         => array( '', array( 'HTTP_X_API_KEY' => 'k' ), array(), self::JSON, self::TYPE_JSON, 'credentials' ),
			'auth token header'      => array( '', array( 'HTTP_X_AUTH_TOKEN' => 't' ), array(), self::JSON, self::TYPE_JSON, 'credentials' ),
			'access token header'    => array( '', array( 'HTTP_X_ACCESS_TOKEN' => 't' ), array(), self::JSON, self::TYPE_JSON, 'credentials' ),
			'override header'        => array( '', array( 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'POST' ), array(), self::JSON, self::TYPE_JSON, 'method_override' ),
			'override header (form)' => array( '', array( 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'POST' ), array(), self::FORM, self::TYPE_FORM, 'method_override' ),
		);
	}

	/**
	 * Data provider: every signal on each serving path.
	 *
	 * @return array<string,array{0:string,1:string,2:array<string,string>,3:array<string,string>,4:string,5:string,6:string}>
	 */
	public static function signals(): array {
		$out = array();
		foreach ( self::paths() as $name => $path ) {
			foreach ( self::signal_cases() as $label => $case ) {
				$out[ $name . ': ' . $label ] = array( $path[0], $case[0], $case[1], $case[2], $case[3], $case[4], $case[5] );
			}
		}
		return $out;
	}

	/**
	 * @dataProvider signals
	 *
	 * @param string               $path         Serving path.
	 * @param string               $query        Query string suffix.
	 * @param array<string,string> $server       Extra `$_SERVER` entries.
	 * @param array<string,string> $cookie       Cookies.
	 * @param string               $body         Body.
	 * @param string               $content_type Content-Type.
	 * @param string               $reason       Expected bypass reason.
	 */
	public function test_a_credentialed_non_public_or_overridden_post_is_never_served_or_stored( string $path, string $query, array $server, array $cookie, string $body, string $content_type, string $reason ): void {
		$h = $this->harness( $path );

		// Control: the clean bodies of both classes are stored.
		foreach ( array( self::TYPE_JSON => self::JSON, self::TYPE_FORM => self::FORM ) as $type => $clean ) {
			$h->request( self::URI, array(), array(), 'POST', $clean, $type );
			$this->assertNotNull( $h->miss( self::ROUTE, array( $this, 'register_search_route' ) ) );
			$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ), $type );
		}
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count() );

		$h->request( self::URI . $query, $server, $cookie, 'POST', $body, $content_type );
		$this->assertFalse( $h->lookup(), 'The request is never served from the cache.' );
		$this->assertSame( $reason, Request_State::decision()->reason->value ?? null );

		$h->serve( self::ROUTE, array( $this, 'register_search_route' ) );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count(), 'Nothing is stored.' );
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
	 * The cache key the current request would have if no signal bypassed it: its canonical body without the body
	 * nonce (nonce caching is on) and without `skip_cache`, under the route's only rule.
	 *
	 * @param Request_Context $context Request.
	 * @param Compiled_Config $config  Compiled configuration.
	 *
	 * @return string
	 */
	private function key_of_the_same_canonical_body( Request_Context $context, Compiled_Config $config ): string {
		$decision = Policy_Decision::cache(
			$config->routes()[0],
			array(),
			array(),
			$context->content_class,
			hash( 'sha256', $context->canonical_body( array( '_wpnonce', 'skip_cache' ) ) )
		);
		return Cache_Key::key( $context, $decision );
	}

	/**
	 * Signals that must still bypass next to a body `_wpnonce` while nonce caching is on: server entries, cookies,
	 * body (with the nonce), Content-Type, expected reason.
	 *
	 * @return array<string,array{0:array<string,string>,1:array<string,string>,2:string,3:string,4:string}>
	 */
	private static function signals_next_to_a_body_nonce_cases(): array {
		return array(
			'Authorization'          => array( array( 'HTTP_AUTHORIZATION' => 'Bearer abc.def.ghi' ), array(), self::JSON_NONCE, self::TYPE_JSON, 'credentials' ),
			'Authorization (form)'   => array( array( 'HTTP_AUTHORIZATION' => 'Bearer abc.def.ghi' ), array(), self::FORM_NONCE, self::TYPE_FORM, 'credentials' ),
			'PHP_AUTH_USER'          => array( array( 'PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'secret' ), array(), self::JSON_NONCE, self::TYPE_JSON, 'credentials' ),
			'API key header'         => array( array( 'HTTP_X_API_KEY' => 'k' ), array(), self::JSON_NONCE, self::TYPE_JSON, 'credentials' ),
			'logged-in cookie'       => array( array(), array( 'wordpress_logged_in_' . str_repeat( 'a', 32 ) => 'u' ), self::JSON_NONCE, self::TYPE_JSON, 'credentials' ),
			'login cookie (form)'    => array( array(), array( 'wordpress_logged_in_' . str_repeat( 'a', 32 ) => 'u' ), self::FORM_NONCE, self::TYPE_FORM, 'credentials' ),
			'secure auth cookie'     => array( array(), array( 'wordpress_sec_' . str_repeat( 'a', 32 ) => 'u' ), self::JSON_NONCE, self::TYPE_JSON, 'credentials' ),
			'auth cookie'            => array( array(), array( 'wordpress_' . str_repeat( '0123456789abcdef', 2 ) => 'u' ), self::JSON_NONCE, self::TYPE_JSON, 'credentials' ),
			'post password cookie'   => array( array(), array( 'wp-postpass_' . str_repeat( 'a', 32 ) => 'p' ), self::JSON_NONCE, self::TYPE_JSON, 'credentials' ),
			'session cookie'         => array( array(), array( 'wp_woocommerce_session_x' => 's' ), self::JSON_NONCE, self::TYPE_JSON, 'credentials' ),
			'password JSON'          => array( array(), array(), '{"q":"alpha","_wpnonce":"NONCE-7f3a9c","password":"x"}', self::TYPE_JSON, 'credentials' ),
			'password form'          => array( array(), array(), self::FORM_NONCE . '&password=x', self::TYPE_FORM, 'credentials' ),
			'JSON context=edit'      => array( array(), array(), '{"q":"alpha","_wpnonce":"NONCE-7f3a9c","context":"edit"}', self::TYPE_JSON, 'context' ),
			'form context=edit'      => array( array(), array(), self::FORM_NONCE . '&context=edit', self::TYPE_FORM, 'context' ),
		);
	}

	/**
	 * Data provider: every signal next to a body `_wpnonce` on each serving path.
	 *
	 * @return array<string,array{0:string,1:array<string,string>,2:array<string,string>,3:string,4:string,5:string}>
	 */
	public static function signals_next_to_a_body_nonce(): array {
		$out = array();
		foreach ( self::paths() as $name => $path ) {
			foreach ( self::signals_next_to_a_body_nonce_cases() as $label => $case ) {
				$out[ $name . ': ' . $label ] = array( $path[0], $case[0], $case[1], $case[2], $case[3], $case[4] );
			}
		}
		return $out;
	}

	/**
	 * With nonce caching on, a body `_wpnonce` does not hide another signal on an opted-in POST: the entry of the same
	 * canonical body is not served, the reason is credentials or context and nothing is stored.
	 *
	 * @dataProvider signals_next_to_a_body_nonce
	 *
	 * @param string               $path         Serving path.
	 * @param array<string,string> $server       Extra `$_SERVER` entries.
	 * @param array<string,string> $cookie       Cookies.
	 * @param string               $body         Body, with the nonce.
	 * @param string               $content_type Content-Type.
	 * @param string               $reason       Expected bypass reason.
	 */
	public function test_a_body_nonce_does_not_hide_another_signal_with_nonce_caching_on( string $path, array $server, array $cookie, string $body, string $content_type, string $reason ): void {
		$this->configure_route( array( 'nonce_caching' => true ) );
		$this->accept_any_nonce();
		$h      = $this->harness( $path );
		$config = Compiled_Config::load();
		$this->assertNotNull( $config );

		// Control: a nonce-only body of both classes is stored and served to the same body with another nonce.
		foreach ( array( self::TYPE_JSON => self::JSON_NONCE, self::TYPE_FORM => self::FORM_NONCE ) as $type => $clean ) {
			$h->request( self::URI, array(), array(), 'POST', $clean, $type );
			$context  = Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() );
			$decision = $this->policy()->evaluate( $context, $config );
			$this->assertCacheable( $decision );
			$this->assertSame( Cache_Key::key( $context, $decision ), $this->key_of_the_same_canonical_body( $context, $config ), 'Control: the key of the same canonical body is the real one.' );

			$this->assertNotNull( $h->miss( self::ROUTE, array( $this, 'register_search_route' ) ) );
			$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ), $type );
		}
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count(), 'Control: the nonce-only bodies are stored.' );
		$h->request( self::URI, array(), array(), 'POST', str_replace( 'NONCE-7f3a9c', 'ANOTHER-NONCE', self::JSON_NONCE ), self::TYPE_JSON );
		$this->assertTrue( $h->lookup(), 'Control: the nonce-only entry is served whatever the nonce.' );

		// The entry of the very body of the request, so only the signal can keep it from being served.
		$this->empty_tables();
		$h->request( self::URI, $server, $cookie, 'POST', $body, $content_type );
		$context = Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() );
		$payload = '{"primed":true}';
		Rfrac_Entry_Fixtures::insert_entry(
			array(
				'cache_key' => $this->key_of_the_same_canonical_body( $context, $config ),
				'method'    => 'POST',
				'route'     => self::ROUTE,
				'rule_key'  => self::ROUTE,
				'request'   => self::ROUTE,
				'payload'   => $payload,
				'size'      => strlen( $payload ),
			)
		);
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$this->assertFalse( $h->lookup(), 'The primed entry is not served to this request.' );
		$this->assertSame( '', $h->output );
		$this->assertSame( array(), $h->headers );
		$this->assertSame( $reason, Request_State::decision()->reason->value ?? null );

		$this->empty_tables();
		$h->request( self::URI, $server, $cookie, 'POST', $body, $content_type );
		$this->assertFalse( $h->lookup() );
		$h->serve( self::ROUTE, array( $this, 'register_search_route' ) );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'The response generated under the signal is not stored.' );
		$this->assertNoFailures();
	}
}
