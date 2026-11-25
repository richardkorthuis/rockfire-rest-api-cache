<?php
/**
 * Security regression tests: a response generated for a foreign request host is never served to the home host.
 *
 * On a site whose `WP_HOME` follows `HTTP_HOST`, a response carries links to the host of the request that generated
 * it. The request host therefore enters the cache key (and the key of a replaced legacy key) when it differs from
 * the compiled home host (a request that sends no Host header included, which keys as the empty host), and never
 * otherwise, so no key of a normally served site changes.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Storage\Cached_Response;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Factory;

/**
 * @covers \Rockfire\Rest_Api_Cache\Request\Cache_Policy
 * @covers \Rockfire\Rest_Api_Cache\Request\Cache_Key
 * @covers \Rockfire\Rest_Api_Cache\Request\Policy_Decision
 */
class Test_Security_Regression_Host extends Rfrac_E2e_Test_Case {

	private const HOME = 'example.org';

	private const FOREIGN = 'evil.example';

	private const ROUTE = '/tst/v1/linked';

	private const URI = '/wp-json/tst/v1/linked';

	public function set_up() {
		parent::set_up();
		$this->configure(
			array(
				'home_host'  => self::HOME,
				'store_mode' => 'database',
			),
			array( Rfrac_Serving_Harness::route( self::ROUTE ) )
		);
	}

	/**
	 * Register the route of the test: its response links to the home URL, which follows the request host.
	 *
	 * @return void
	 */
	public function register_linked_route(): void {
		register_rest_route(
			'tst/v1',
			'/linked',
			array(
				'methods'             => 'GET',
				'callback'            => static function () {
					$response = new WP_REST_Response( array( 'site' => home_url() ) );
					$response->add_link( 'self', rest_url( 'tst/v1/linked' ) );
					return $response;
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Let `home_url()` follow the host of the request, like a site that builds `WP_HOME` from `HTTP_HOST`.
	 *
	 * @param string $without_host The host of the home URL for a request that sends none: the home host unless given
	 *                             (`''` is a `WP_HOME` of `http://`, whose links have no host).
	 *
	 * @return void
	 */
	private function home_follows_the_request_host( string $without_host = self::HOME ): void {
		add_filter(
			'option_home',
			static fn() => 'http://' . ( isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : $without_host ) // phpcs:ignore WordPress.Security
		);
	}

	/**
	 * Make the current request (set by the harness's `request()`) one that sends no Host header, like an HTTP/1.0 request.
	 *
	 * @return void
	 */
	private function without_host_header(): void {
		unset( $_SERVER['HTTP_HOST'] );
	}

	/**
	 * Sha256 of the JSON encoding of a key structure, as the engine hashes it.
	 *
	 * @param array<string,mixed> $structure Key structure.
	 *
	 * @return string
	 */
	private static function digest( array $structure ): string {
		return hash( 'sha256', (string) json_encode( $structure, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * A compiled config with the posts rule and a home host.
	 *
	 * @param string              $home_host Compiled home host.
	 * @param array<string,mixed> $route     Route fields.
	 *
	 * @return Compiled_Config
	 */
	private function posts_config( string $home_host = self::HOME, array $route = array() ): Compiled_Config {
		return $this->config( array( $this->route( '/wp/v2/posts', $route ) ), array( 'home_host' => $home_host ) );
	}

	/**
	 * A GET request for the posts, on a host.
	 *
	 * @param string|null $host Value of `HTTP_HOST`; null sends none.
	 * @param bool        $ssl  Whether the request uses HTTPS.
	 *
	 * @return Request_Context
	 */
	private function posts_request( ?string $host, bool $ssl = false ): Request_Context {
		return $this->request( '/wp-json/wp/v2/posts?page=2', array( 'server' => null === $host ? array() : array( 'HTTP_HOST' => $host ), 'ssl' => $ssl ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
	}

	/**
	 * The key structure of a GET for the posts as v4 builds it, plus the host when one is given.
	 *
	 * @param string      $scheme Scheme.
	 * @param string|null $host   Host member; null leaves it out.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_structure( string $scheme, ?string $host ): array {
		$structure = array(
			'v'      => 1,
			'blog'   => 1,
			'scheme' => $scheme,
			'method' => 'GET',
			'route'  => '/wp/v2/posts',
			'params' => array( 'page' => '2' ),
			'vary'   => array(),
		);
		if ( null !== $host ) {
			$structure['host'] = $host;
		}
		return $structure;
	}

	/**
	 * Home host of the snapshot, request host, whether the request uses HTTPS, expected host of the decision (`''` for
	 * a request that names no host, which is not on the home host either).
	 *
	 * @return array<string,array{0:string,1:string|null,2:bool,3:string|null}>
	 */
	public static function hosts(): array {
		return array(
			'the home host'                            => array( 'example.org', 'example.org', false, null ),
			'the home host in upper case'              => array( 'example.org', 'EXAMPLE.ORG', false, null ),
			'the home host with the default http port' => array( 'example.org', 'example.org:80', false, null ),
			'the home host with the default https port' => array( 'example.org', 'example.org:443', true, null ),
			'a home host with a port'                  => array( 'example.org:8080', 'example.org:8080', false, null ),
			'no host'                                  => array( 'example.org', null, false, '' ),
			'no host over https'                       => array( 'example.org', null, true, '' ),
			'an empty host'                            => array( 'example.org', '', false, '' ),
			'a blank host'                             => array( 'example.org', '   ', false, '' ),
			'no host and a home host with a port'      => array( 'example.org:8080', null, false, '' ),
			'a foreign host'                           => array( 'example.org', 'evil.example', false, 'evil.example' ),
			'a foreign host in mixed case'             => array( 'example.org', 'Evil.Example', false, 'evil.example' ),
			'a foreign host over https'                => array( 'example.org', 'evil.example', true, 'evil.example' ),
			'a subdomain of the home host'             => array( 'example.org', 'www.example.org', false, 'www.example.org' ),
			'the home host on another port'            => array( 'example.org', 'example.org:8080', false, 'example.org:8080' ),
			'the https port on an http request'        => array( 'example.org', 'example.org:443', false, 'example.org:443' ),
			'the bare host of a home host with a port' => array( 'example.org:8080', 'example.org', false, 'example.org' ),
			'an old snapshot and a foreign host'       => array( '', 'evil.example', false, null ),
			'an old snapshot and the home host'        => array( '', 'example.org', false, null ),
			'an old snapshot and no host'              => array( '', null, false, null ),
		);
	}

	/**
	 * @dataProvider hosts
	 *
	 * @param string      $home     Compiled home host.
	 * @param string|null $host     Request host as sent.
	 * @param bool        $ssl      Whether the request uses HTTPS.
	 * @param string|null $expected Expected host of the decision.
	 */
	public function test_the_decision_carries_the_request_host_only_when_it_differs_from_the_home_host( string $home, ?string $host, bool $ssl, ?string $expected ): void {
		$context  = $this->posts_request( $host, $ssl );
		$decision = $this->policy()->evaluate( $context, $this->posts_config( $home ) );

		$this->assertCacheable( $decision );
		$this->assertSame( $expected, $decision->host );

		$scheme = $ssl ? 'https' : 'http';
		$this->assertSame( self::digest( self::get_structure( $scheme, $expected ) ), Cache_Key::key( $context, $decision ) );
		$this->assertSame( $expected, Cache_Key::display( $context, $decision )['host'] ?? null );
		if ( null === $expected ) {
			// The key of the home host is the v4 key: the one of the same request on a snapshot that knows no home host.
			$old = $this->policy()->evaluate( $context, $this->posts_config( '' ) );
			$this->assertSame( Cache_Key::key( $context, $old ), Cache_Key::key( $context, $decision ) );
		}
	}

	public function test_a_request_without_a_host_is_keyed_apart_from_the_home_host_and_every_named_host(): void {
		$config  = $this->posts_config();
		$home    = $this->posts_request( self::HOME );
		$foreign = $this->posts_request( self::FOREIGN );
		$none    = $this->posts_request( null );
		$empty   = $this->posts_request( '' );
		$h_dec   = $this->policy()->evaluate( $home, $config );
		$f_dec   = $this->policy()->evaluate( $foreign, $config );
		$n_dec   = $this->policy()->evaluate( $none, $config );
		$e_dec   = $this->policy()->evaluate( $empty, $config );

		$this->assertCacheable( $n_dec );
		$this->assertNotSame( Cache_Key::key( $home, $h_dec ), Cache_Key::key( $none, $n_dec ), 'A request without a host never shares the key of the home host.' );
		$this->assertNotSame( Cache_Key::key( $foreign, $f_dec ), Cache_Key::key( $none, $n_dec ), 'Nor the key of a named host.' );
		$this->assertSame( Cache_Key::key( $none, $n_dec ), Cache_Key::key( $empty, $e_dec ), 'A missing and an empty Host header are the same request.' );
		$this->assertSame( Cache_Key::url_hash( $home, $h_dec->key_params ), Cache_Key::url_hash( $none, $n_dec->key_params ), 'The URL hash is host-free, so a flush by URL reaches it.' );
	}

	public function test_a_foreign_host_ends_the_key_structure_after_the_vary_entries(): void {
		$route    = array( 'vary_headers' => array( 'accept-language' ) );
		$context  = $this->request( '/wp-json/wp/v2/posts?page=2', array( 'server' => array( 'HTTP_HOST' => self::FOREIGN, 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$decision = $this->policy()->evaluate( $context, $this->posts_config( self::HOME, $route ) );
		$this->assertCacheable( $decision );

		$expected = self::get_structure( 'http', self::FOREIGN );
		$expected['vary'] = array( 'accept-language' => hash( 'sha256', 'nl' ) );
		$this->assertSame( self::digest( $expected ), Cache_Key::key( $context, $decision ) );
		$this->assertSame( 'host', array_key_last( $expected ) );
	}

	public function test_a_foreign_host_and_the_home_host_key_apart_and_share_the_url_hash(): void {
		$config  = $this->posts_config();
		$foreign = $this->posts_request( self::FOREIGN );
		$home    = $this->posts_request( self::HOME );
		$f_dec   = $this->policy()->evaluate( $foreign, $config );
		$h_dec   = $this->policy()->evaluate( $home, $config );

		$this->assertNotSame( Cache_Key::key( $home, $h_dec ), Cache_Key::key( $foreign, $f_dec ) );
		$this->assertSame( Cache_Key::url_hash( $home, $h_dec->key_params ), Cache_Key::url_hash( $foreign, $f_dec->key_params ), 'The URL hash is host-free, so a flush by URL reaches every host.' );
		$this->assertSame(
			self::digest(
				array(
					'v'      => 1,
					'blog'   => 1,
					'method' => 'GET',
					'route'  => '/wp/v2/posts',
					'params' => array( 'page' => '2' ),
				)
			),
			Cache_Key::url_hash( $foreign, $f_dec->key_params ),
			'The URL hash is the v4 one.'
		);
	}

	public function test_a_foreign_host_post_ends_the_key_structure_with_the_host_after_the_body(): void {
		$config  = $this->posts_config( self::HOME, array( 'methods' => array( 'GET', 'POST' ) ) );
		$context = $this->request(
			'/wp-json/wp/v2/posts',
			array(
				'server' => array(
					'REQUEST_METHOD' => 'POST',
					'CONTENT_TYPE'   => 'application/json',
					'HTTP_HOST'      => self::FOREIGN,
				),
				'body'   => '{"q":1}',
			)
		);

		$decision = $this->policy()->evaluate( $context, $config );
		$this->assertCacheable( $decision );
		$this->assertSame( self::FOREIGN, $decision->host );

		$structure = array(
			'v'            => 1,
			'blog'         => 1,
			'scheme'       => 'http',
			'method'       => 'POST',
			'route'        => '/wp/v2/posts',
			'params'       => array(),
			'vary'         => array(),
			'content_type' => 'json',
			'body'         => hash( 'sha256', '{"q":1}' ),
			'host'         => self::FOREIGN,
		);
		$this->assertSame( self::digest( $structure ), Cache_Key::key( $context, $decision ) );

		$display = Cache_Key::display( $context, $decision );
		$this->assertSame( self::FOREIGN, $display['host'] ?? null );
		$this->assertSame( substr( hash( 'sha256', '{"q":1}' ), 0, Cache_Key::DISPLAY_HASH_LENGTH ), $display['body'] ?? null );
		$this->assertSame( array( 'route', 'params', 'vary', 'body', 'host' ), array_keys( $display ) );

		// The same POST on the home host is the v4 key and displays no host.
		$home_context = $this->request(
			'/wp-json/wp/v2/posts',
			array(
				'server' => array(
					'REQUEST_METHOD' => 'POST',
					'CONTENT_TYPE'   => 'application/json',
					'HTTP_HOST'      => self::HOME,
				),
				'body'   => '{"q":1}',
			)
		);
		$home_decision = $this->policy()->evaluate( $home_context, $config );
		unset( $structure['host'] );
		$this->assertNull( $home_decision->host );
		$this->assertSame( self::digest( $structure ), Cache_Key::key( $home_context, $home_decision ) );
		$this->assertArrayNotHasKey( 'host', Cache_Key::display( $home_context, $home_decision ) );
	}

	public function test_a_bypass_carries_no_host(): void {
		$this->assertNull( Policy_Decision::bypass( Bypass_Reason::FILTERED )->host );

		$context  = $this->request( '/wp-json/wp/v2/posts', array( 'server' => array( 'HTTP_HOST' => self::FOREIGN, 'HTTP_AUTHORIZATION' => 'Bearer abc.def.ghi' ) ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$decision = $this->policy()->evaluate( $context, $this->posts_config() );
		$this->assertBypass( 'credentials', $decision );
		$this->assertNull( $decision->host );
	}

	public function test_the_contexts_of_a_flushed_url_carry_no_host_and_hash_to_the_host_free_url_hash_of_every_host(): void {
		$config = $this->posts_config();
		$home   = $this->posts_request( self::HOME );
		$h_dec  = $this->policy()->evaluate( $home, $config );
		foreach ( Request_Context::url_variants( 'http://' . self::FOREIGN . '/wp-json/wp/v2/posts?page=2', 'wp-json' ) as $context ) {
			$this->assertNull( $context->host );

			$decision = $this->policy()->evaluate_for_url( $context, $config );
			$this->assertCacheable( $decision );
			$this->assertSame( Cache_Key::url_hash( $home, $h_dec->key_params ), Cache_Key::url_hash( $context, $decision->key_params ) );
		}
	}

	public function test_the_replaced_key_of_a_foreign_host_differs_from_the_home_host_key_which_stays_without_a_host_member(): void {
		$config  = $this->posts_config( self::HOME, array( 'vary_headers' => array( 'accept-language' ) ) );
		$foreign = $this->request( '/wp-json/wp/v2/posts?page=2', array( 'server' => array( 'HTTP_HOST' => self::FOREIGN, 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$home    = $this->request( '/wp-json/wp/v2/posts?page=2', array( 'server' => array( 'HTTP_HOST' => self::HOME, 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$f_dec   = $this->policy()->evaluate( $foreign, $config );
		$h_dec   = $this->policy()->evaluate( $home, $config );

		$structure = array(
			'v'      => 1,
			'legacy' => hash( 'sha256', 'prices' ),
			'blog'   => 1,
			'scheme' => 'http',
			'vary'   => array( 'accept-language' => hash( 'sha256', 'nl' ) ),
		);
		$this->assertSame( self::digest( $structure ), Cache_Key::replaced( 'prices', $home, $h_dec ), 'The home host key has no host member.' );
		$this->assertSame( self::digest( $structure + array( 'host' => self::FOREIGN ) ), Cache_Key::replaced( 'prices', $foreign, $f_dec ) );
		$this->assertNotSame( Cache_Key::replaced( 'prices', $home, $h_dec ), Cache_Key::replaced( 'prices', $foreign, $f_dec ) );
	}

	public function test_the_replaced_key_of_a_foreign_host_post_ends_with_the_host(): void {
		$config  = $this->posts_config( self::HOME, array( 'methods' => array( 'GET', 'POST' ) ) );
		$context = $this->request(
			'/wp-json/wp/v2/posts',
			array(
				'server' => array(
					'REQUEST_METHOD' => 'POST',
					'CONTENT_TYPE'   => 'application/json',
					'HTTP_HOST'      => self::FOREIGN,
				),
				'body'   => '{"q":1}',
			)
		);
		$decision = $this->policy()->evaluate( $context, $config );
		$this->assertCacheable( $decision );

		$structure = array(
			'v'            => 1,
			'legacy'       => hash( 'sha256', 'prices' ),
			'blog'         => 1,
			'scheme'       => 'http',
			'vary'         => array(),
			'method'       => 'POST',
			'content_type' => 'json',
			'body'         => hash( 'sha256', '{"q":1}' ),
			'host'         => self::FOREIGN,
		);
		$this->assertSame( self::digest( $structure ), Cache_Key::replaced( 'prices', $context, $decision ) );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_response_stored_for_a_foreign_host_is_never_served_to_the_home_host( string $path ): void {
		$this->home_follows_the_request_host();
		$h        = $this->harness( $path );
		$register = array( $this, 'register_linked_route' );

		$h->request( self::URI, array( 'HTTP_HOST' => self::FOREIGN ) );
		$foreign = $h->miss( self::ROUTE, $register );
		$this->assertNotNull( $foreign, 'The foreign host request is a miss.' );
		$this->assertStringContainsString( self::FOREIGN, $foreign, 'The response links to the foreign host.' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'The foreign host response is stored.' );

		$h->request( self::URI, array( 'HTTP_HOST' => self::FOREIGN ) );
		$this->assertTrue( $h->lookup(), 'Control: the foreign host is served its own entry.' );
		$this->assertStringContainsString( self::FOREIGN, $h->output );

		$h->request( self::URI, array( 'HTTP_HOST' => self::HOME ) );
		$home = $h->miss( self::ROUTE, $register );
		$this->assertNotNull( $home, 'The home host is not served the foreign host entry.' );
		$this->assertSame( '', $h->output );
		$this->assertStringNotContainsString( self::FOREIGN, $home );
		$this->assertStringContainsString( self::HOME, $home );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count(), 'The home host response is stored apart.' );

		$h->request( self::URI, array( 'HTTP_HOST' => self::HOME ) );
		$this->assertTrue( $h->lookup(), 'The home host is served its own entry.' );
		$this->assertStringNotContainsString( self::FOREIGN, $h->output );
		$this->assertStringContainsString( self::HOME, $h->output );

		$h->request( self::URI, array( 'HTTP_HOST' => self::FOREIGN ) );
		$this->assertTrue( $h->lookup(), 'The foreign host is still served its own entry.' );
		$this->assertStringContainsString( self::FOREIGN, $h->output );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_response_stored_for_a_request_without_a_host_is_never_served_to_the_home_host( string $path ): void {
		$this->home_follows_the_request_host( '' );
		$h        = $this->harness( $path );
		$register = array( $this, 'register_linked_route' );

		// An HTTP/1.0 request sends no Host header, so the links of its response have no host.
		$h->request( self::URI );
		$this->without_host_header();
		$hostless = $h->miss( self::ROUTE, $register );
		$this->assertNotNull( $hostless, 'The request without a host is a miss.' );
		$this->assertStringNotContainsString( self::HOME, $hostless, 'The response links to no host.' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'The response without a host is stored.' );

		$h->request( self::URI );
		$this->without_host_header();
		$this->assertTrue( $h->lookup(), 'Control: a request without a host is served its own entry.' );
		$this->assertStringNotContainsString( self::HOME, $h->output );

		$h->request( self::URI, array( 'HTTP_HOST' => self::HOME ) );
		$home = $h->miss( self::ROUTE, $register );
		$this->assertNotNull( $home, 'The home host is not served the entry of a request without a host.' );
		$this->assertSame( '', $h->output );
		$this->assertStringContainsString( self::HOME, $home );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count(), 'The home host response is stored apart.' );

		$h->request( self::URI, array( 'HTTP_HOST' => self::HOME ) );
		$this->assertTrue( $h->lookup(), 'The home host is served its own entry.' );
		$this->assertStringContainsString( self::HOME, $h->output );

		$h->request( self::URI );
		$this->without_host_header();
		$this->assertTrue( $h->lookup(), 'A request without a host is still served its own entry.' );
		$this->assertStringNotContainsString( self::HOME, $h->output );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_response_stored_for_the_home_host_is_never_served_to_a_request_without_a_host( string $path ): void {
		$this->home_follows_the_request_host( '' );
		$h        = $this->harness( $path );
		$register = array( $this, 'register_linked_route' );

		$h->request( self::URI, array( 'HTTP_HOST' => self::HOME ) );
		$this->assertNotNull( $h->miss( self::ROUTE, $register ), 'The home host request is a miss.' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$h->request( self::URI );
		$this->without_host_header();
		$hostless = $h->miss( self::ROUTE, $register );
		$this->assertNotNull( $hostless, 'A request without a host is not served the home host entry.' );
		$this->assertSame( '', $h->output );
		$this->assertStringNotContainsString( self::HOME, $hostless );
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count(), 'The response without a host is stored apart.' );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_flushing_a_url_flushes_the_entries_of_every_host( string $path ): void {
		$this->home_follows_the_request_host();
		$h        = $this->harness( $path );
		$register = array( $this, 'register_linked_route' );

		$h->request( self::URI, array( 'HTTP_HOST' => self::HOME ) );
		$this->assertNotNull( $h->miss( self::ROUTE, $register ) );
		$h->request( self::URI, array( 'HTTP_HOST' => self::FOREIGN ) );
		$this->assertNotNull( $h->miss( self::ROUTE, $register ) );
		$h->request( self::URI );
		$this->without_host_header();
		$this->assertNotNull( $h->miss( self::ROUTE, $register ) );
		$this->assertSame( 3, Rfrac_Entry_Fixtures::count() );

		$this->assertSame( 3, rfrac_flush_url( self::URI ) );

		foreach ( array( self::HOME, self::FOREIGN ) as $host ) {
			$h->request( self::URI, array( 'HTTP_HOST' => $host ) );
			$this->assertFalse( $h->lookup(), $host . ' is flushed.' );
		}
		$h->request( self::URI );
		$this->without_host_header();
		$this->assertFalse( $h->lookup(), 'The request without a host is flushed.' );
		$this->assertNoFailures();
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
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_cache_key_callback_answering_one_string_never_merges_the_hosts( string $path ): void {
		define( 'RFRAC_LEGACY_HIT_FILTERS', true );
		add_filter( 'wp_rest_cache/cache_key', static fn() => 'prices' );
		$this->home_follows_the_request_host();
		$h        = $this->harness( $path );
		$register = array( $this, 'register_linked_route' );

		$h->request( self::URI, array( 'HTTP_HOST' => self::FOREIGN ) );
		list( $foreign_engine_key, $foreign_key ) = $this->keys( 'prices' );
		$foreign                                  = $h->miss( self::ROUTE, $register );
		$this->assertNotNull( $foreign, 'The foreign host request is a miss.' );
		$this->assertStringContainsString( self::FOREIGN, $foreign );
		$this->assertNotSame( $foreign_engine_key, $foreign_key );
		$this->assertInstanceOf( Cached_Response::class, $this->stored( $foreign_key ), 'The foreign host entry is stored under its replaced key.' );

		$h->request( self::URI, array( 'HTTP_HOST' => self::HOME ) );
		list( , $home_key ) = $this->keys( 'prices' );
		$this->assertNotSame( $foreign_key, $home_key, 'The same replacement on two hosts is two keys.' );
		$this->assertSame(
			self::digest(
				array(
					'v'      => 1,
					'legacy' => hash( 'sha256', 'prices' ),
					'blog'   => get_current_blog_id(),
					'scheme' => 'http',
					'vary'   => array(),
				)
			),
			$home_key,
			'The home host key has no host member.'
		);
		$home = $h->miss( self::ROUTE, $register );
		$this->assertNotNull( $home, 'The home host is not served the foreign host entry.' );
		$this->assertSame( '', $h->output );
		$this->assertStringNotContainsString( self::FOREIGN, $home );
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count() );
		$this->assertInstanceOf( Cached_Response::class, $this->stored( $home_key ), 'The home host entry is stored under its replaced key.' );

		$h->request( self::URI, array( 'HTTP_HOST' => self::HOME ) );
		$this->assertTrue( $h->lookup(), 'The home host is a hit.' );
		$this->assertStringNotContainsString( self::FOREIGN, $h->output );

		$h->request( self::URI, array( 'HTTP_HOST' => self::FOREIGN ) );
		$this->assertTrue( $h->lookup(), 'The foreign host is a hit.' );
		$this->assertStringContainsString( self::FOREIGN, $h->output );
		$this->assertNoFailures();
	}
}
