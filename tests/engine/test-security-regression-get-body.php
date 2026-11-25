<?php
/**
 * Regression tests of the GET-with-a-body finding, through both serving paths and the capture.
 *
 * Core reads the JSON body ahead of the query and URL parameters for every method, so a GET that carries a body is
 * answered from parameters the cache key never sees: it must neither be served from nor stored into the cache. The
 * capture also refuses a dispatched request that carries a `password` parameter from any source.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Capture;
use Rockfire\Rest_Api_Cache\Serving\Null_Tag_Provider;
use Rockfire\Rest_Api_Cache\Serving\Request_State;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Request\Cache_Policy
 * @covers \Rockfire\Rest_Api_Cache\Request\Request_Context
 * @covers \Rockfire\Rest_Api_Cache\Serving\Lookup
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 */
class Test_Security_Regression_Get_Body extends Rfrac_E2e_Test_Case {

	/**
	 * The request URI under test.
	 *
	 * @var string
	 */
	private const URI = '/wp-json/wp/v2/posts';

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
	 * A GET that carries a body: extra server entries, raw body (null for none), Content-Type.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public static function get_bodies(): array {
		return self::both_paths(
			array(
				'JSON body'                 => array( array(), '{"per_page":1}', 'application/json' ),
				'form body'                 => array( array(), 'per_page=1', 'application/x-www-form-urlencoded' ),
				'JSON body with a password' => array( array(), '{"password":"x"}', 'application/json' ),
				'chunked without a length'  => array( array( 'HTTP_TRANSFER_ENCODING' => 'chunked' ), null, '' ),
			)
		);
	}

	/**
	 * A GET with a body is not served the primed entry of the same URL and its response is not stored.
	 *
	 * @dataProvider get_bodies
	 *
	 * @param string              $path         Serving path.
	 * @param array<string,mixed> $server       Extra `$_SERVER` entries.
	 * @param string|null         $body         Raw body.
	 * @param string              $content_type Content-Type.
	 */
	public function test_a_get_with_a_body_is_never_served_from_nor_stored_into_the_cache( string $path, array $server, ?string $body, string $content_type ): void {
		$h = $this->harness( $path );

		// Control: the body-less request is cached, so the harness could store and serve it.
		$h->request( self::URI );
		$h->serve( '/wp/v2/posts' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'Control: the body-less response is stored.' );
		$this->empty_tables();
		$this->prime_entry( '{"primed":true}' );
		$this->assertTrue( $h->lookup(), 'Control: the primed entry is served to the body-less request.' );

		$h->request( self::URI, $server, array(), 'GET', $body, $content_type );
		$this->assertFalse( $h->lookup(), 'The primed entry is not served to a GET with a body.' );
		$this->assertSame( '', $h->output );
		$this->assertSame( array(), $h->headers );
		$this->assertSame( Bypass_Reason::BODY, Request_State::decision()->reason );

		$this->empty_tables();
		$h->request( self::URI, $server, array(), 'GET', $body, $content_type );
		$h->lookup();
		$h->serve( '/wp/v2/posts' );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'The response generated for a GET with a body is not stored.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertNoFailures();
	}

	/**
	 * A body-less GET on the home host, with or without a zero length, is cached as before.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_get_without_a_body_is_still_stored_and_served( string $path ): void {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$h    = $this->harness( $path );

		$h->request( self::URI, array( 'HTTP_HOST' => $host, 'CONTENT_LENGTH' => '0' ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$h->miss( '/wp/v2/posts' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );

		$h->request( self::URI, array( 'HTTP_HOST' => $host ) );
		$this->assertTrue( $h->lookup(), 'The stored entry is served.' );
		$this->assertNoFailures();
	}

	/**
	 * A GET that carries a body of any kind bypasses with `body` and keeps the matched rule.
	 */
	public function test_the_policy_bypasses_a_get_with_any_body(): void {
		$cases = array(
			'JSON string'           => array( 'body' => '{"per_page":1}' ),
			'form string'           => array( 'body' => 'per_page=1' ),
			'one byte'              => array( 'body' => 'x' ),
			'reader'                => array( 'body' => static fn( int $max ): ?string => 'x' ),
			'JSON password'         => array(
				'body'   => '{"password":"x"}',
				'server' => array( 'CONTENT_TYPE' => 'application/json' ),
			),
			'declared length'       => array( 'server' => array( 'CONTENT_LENGTH' => '14' ) ),
			'declared length no ct' => array( 'server' => array( 'CONTENT_LENGTH' => '1' ) ),
			'chunked'               => array( 'server' => array( 'HTTP_TRANSFER_ENCODING' => 'chunked' ) ),
		);
		foreach ( $cases as $label => $args ) {
			$decision = $this->evaluate( self::URI, null, $args );
			$this->assertBypass( 'body', $decision );
			$this->assertNotNull( $decision->rule, $label );
			$this->assertSame( '/wp/v2/posts', $decision->rule->key, $label );
		}
	}

	/**
	 * A GET without a body is cacheable, whatever it declares as zero.
	 */
	public function test_the_policy_keeps_a_body_less_get_cacheable(): void {
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$cases = array(
			'plain'              => array(),
			'home host'          => array( 'server' => array( 'HTTP_HOST' => $host ) ),
			'empty string'       => array( 'body' => '' ),
			'empty reader'       => array( 'body' => static fn( int $max ): ?string => null ),
			'zero length'        => array( 'server' => array( 'CONTENT_LENGTH' => '0' ) ),
			'zero length string' => array( 'server' => array( 'CONTENT_LENGTH' => '000' ), 'body' => '' ),
		);
		foreach ( $cases as $args ) {
			$this->assertCacheable( $this->evaluate( self::URI, null, $args ) );
		}

		// A flushed URL is hashed without the method check or a body.
		$context = Request_Context::from_url( home_url( self::URI ), 'wp-json' );
		$this->assertFalse( $context->has_body );
		$this->assertCacheable( $this->policy()->evaluate_for_url( $context, $this->config() ) );
	}

	/**
	 * The rules before the body check keep their reason for a GET with a body.
	 */
	public function test_a_rule_before_the_body_check_keeps_its_reason(): void {
		$body = array( 'body' => '{"per_page":1}' );
		$this->assertBypass( 'credentials', $this->evaluate( self::URI, null, $body + array( 'server' => array( 'HTTP_AUTHORIZATION' => 'Bearer abc' ) ) ) );
		$this->assertBypass( 'credentials', $this->evaluate( self::URI . '?password=x', null, $body ) );
		$this->assertBypass( 'method_override', $this->evaluate( self::URI, null, $body + array( 'server' => array( 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'POST' ) ) ) );
		$this->assertBypass( 'context', $this->evaluate( self::URI . '?context=edit', null, $body ) );
		$this->assertBypass( 'status_param', $this->evaluate( self::URI . '?status=draft', null, $body ) );
		$this->assertBypass( 'denied_route', $this->evaluate( '/wp-json/wp/v2/users/me', null, $body ) );
		$this->assertBypass( 'route_not_enabled', $this->evaluate( '/wp-json/wp/v2/pages', null, $body ) );

		// And the body check comes before the parameter rules.
		$this->assertBypass( 'body', $this->evaluate( self::URI . '?_jsonp=cb', null, $body ) );
		$this->assertBypass( 'body', $this->evaluate( self::URI . '?unknown=1', null, $body ) );
	}

	/**
	 * A route that opted in to POST keeps keying the body: a body of a non-GET request is no reason to bypass.
	 */
	public function test_an_opted_in_post_with_a_body_is_still_cacheable_and_keyed_by_it(): void {
		$config = $this->config(
			array(
				$this->route(
					'/wp/v2/posts',
					array(
						'methods'    => array( 'POST' ),
						'param_mode' => 'allow_all',
					)
				),
			)
		);
		$json   = array(
			'server' => array(
				'REQUEST_METHOD' => 'POST',
				'CONTENT_TYPE'   => 'application/json',
			),
			'body'   => '{"per_page":1}',
		);

		$decision = $this->evaluate( self::URI, $config, $json );
		$this->assertCacheable( $decision );
		$this->assertSame( 'json', $decision->body_class );
		$this->assertNotNull( $decision->body_hash );

		$multipart = array(
			'server' => array(
				'REQUEST_METHOD' => 'POST',
				'CONTENT_TYPE'   => 'multipart/form-data; boundary=x',
			),
			'body'   => '--x--',
		);
		$this->assertBypass( 'body', $this->evaluate( self::URI, $config, $multipart ) );

		// A POST without a body is no GET with one: it is keyed by the empty body.
		$empty = array( 'server' => array( 'REQUEST_METHOD' => 'POST' ) );
		$this->assertCacheable( $this->evaluate( self::URI, $config, $empty ) );
	}

	/**
	 * Build a capture like the container does, with a context taken from the superglobals and no hit filters.
	 *
	 * @return Capture
	 */
	private function new_capture(): Capture {
		return new Capture(
			$this->policy(),
			new Repository(),
			new Null_Tag_Provider(),
			Compiled_Config::load( ... ),
			static fn( Compiled_Config $config ): Request_Context => Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() ),
			null,
			null,
			static fn(): array => array(),
			null
		);
	}

	/**
	 * Run a dispatched request through the capture: `rest_post_dispatch`, then `rest_pre_echo_response`.
	 *
	 * @param WP_REST_Request $request Dispatched request; its method is that of the simulated request.
	 *
	 * @return WP_REST_Response The live response, as marked by the capture.
	 */
	private function capture_dispatch( WP_REST_Request $request ): WP_REST_Response {
		Request_State::reset();
		// Capture stores only for a request whose lookup recorded a flush epoch: this request's is a miss at the current epoch.
		Rfrac_Serving_Harness::record_lookup_at_current_epoch();
		$capture  = $this->new_capture();
		$response = new WP_REST_Response( array( array( 'id' => 1 ) ) );

		$capture->on_post_dispatch( $response, null, $request );
		$capture->on_pre_echo( $response->get_data(), null, $request );
		return $response;
	}

	/**
	 * Assert that a response was marked BYPASS (and, under WP_DEBUG, with the reason).
	 *
	 * @param WP_REST_Response $response Live response.
	 * @param Bypass_Reason    $reason   Expected reason.
	 */
	private function assert_marked_bypass( WP_REST_Response $response, Bypass_Reason $reason ): void {
		$this->assertSame( 'BYPASS', $response->get_headers()['X-Rfrac-Cache'] ?? null );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->assertSame( $reason->value, $response->get_headers()['X-Rfrac-Cache-Reason'] ?? null );
		}
	}

	/**
	 * A dispatched request with a `password` parameter: the label and how the parameter reaches the request.
	 *
	 * @return array<string,array{0:callable}>
	 */
	public static function password_sources(): array {
		return array(
			'query'            => array( static fn( WP_REST_Request $r ) => $r->set_query_params( array( 'password' => 'x' ) ) ),
			'query, empty'     => array( static fn( WP_REST_Request $r ) => $r->set_query_params( array( 'password' => '' ) ) ),
			'query, null'      => array( static fn( WP_REST_Request $r ) => $r->set_query_params( array( 'password' => null ) ) ),
			'query, array'     => array( static fn( WP_REST_Request $r ) => $r->set_query_params( array( 'password' => array( 'x' ) ) ) ),
			'form body'        => array( static fn( WP_REST_Request $r ) => $r->set_body_params( array( 'password' => 'x' ) ) ),
			'URL'              => array( static fn( WP_REST_Request $r ) => $r->set_url_params( array( 'password' => 'x' ) ) ),
			'JSON body'        => array(
				static function ( WP_REST_Request $r ): void {
					$r->set_header( 'content-type', 'application/json' );
					$r->set_body( '{"password":"x"}' );
				},
			),
			'set_param'        => array( static fn( WP_REST_Request $r ) => $r->set_param( 'password', 'x' ) ),
		);
	}

	/**
	 * The capture stores nothing of a dispatched request with a `password` parameter although the context saw none.
	 *
	 * @dataProvider password_sources
	 *
	 * @param callable $add Adds the parameter to the dispatched request.
	 */
	public function test_the_capture_never_stores_a_dispatched_request_with_a_password_parameter( callable $add ): void {
		$h = $this->harness( Rfrac_Serving_Harness::EARLY );
		$h->request( self::URI );

		$control = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$this->capture_dispatch( $control );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'Control: the same dispatch without the parameter is stored.' );
		$this->empty_tables();

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$add( $request );
		$response = $this->capture_dispatch( $request );

		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'Nothing is stored.' );
		$this->assert_marked_bypass( $response, Bypass_Reason::CREDENTIALS );
		$this->assertNoFailures();
	}

	/**
	 * A default of the route is no parameter of the request: it does not make the capture refuse.
	 */
	public function test_a_default_password_of_the_route_is_not_a_parameter_of_the_request(): void {
		$h = $this->harness( Rfrac_Serving_Harness::EARLY );
		$h->request( self::URI );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_default_params( array( 'password' => 'x' ) );
		$this->assertTrue( $request->has_param( 'password' ) );
		$this->capture_dispatch( $request );

		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
	}

	/**
	 * A body the context did not see: the capture refuses a dispatched GET whose body is not empty.
	 */
	public function test_the_capture_never_stores_a_dispatched_get_with_a_body_the_context_did_not_see(): void {
		$h = $this->harness( Rfrac_Serving_Harness::EARLY );
		$h->request( self::URI );
		$this->assertFalse( Request_Context::from_globals( 'wp-json' )->has_body, 'Precondition: the context has no body.' );

		foreach ( array( 'x', 'per_page=1', '{"per_page":1}', '0' ) as $body ) {
			$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
			$request->set_body( $body );
			$response = $this->capture_dispatch( $request );

			$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'Nothing is stored for the body ' . $body );
			$this->assert_marked_bypass( $response, Bypass_Reason::BODY );
		}

		// An empty body is no body.
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_body( '' );
		$this->capture_dispatch( $request );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$this->assertNoFailures();
	}

	/**
	 * A decision that already bypassed keeps its reason: the dispatch checks only look at a cacheable one.
	 */
	public function test_the_dispatch_checks_do_not_replace_the_reason_of_a_bypass(): void {
		$h = $this->harness( Rfrac_Serving_Harness::EARLY );
		$h->request( self::URI . '?context=edit' );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_query_params( array( 'context' => 'edit', 'password' => 'x' ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$response = $this->capture_dispatch( $request );

		$this->assertSame( 0, Rfrac_Entry_Fixtures::count() );
		$this->assert_marked_bypass( $response, Bypass_Reason::CONTEXT );
	}

	/**
	 * An opted-in POST is keyed by its body: the capture does not refuse a non-GET dispatch for having one.
	 */
	public function test_the_capture_still_stores_an_opted_in_post_with_a_json_body(): void {
		$this->configure(
			array(),
			array(
				Rfrac_Serving_Harness::route(
					'/wp/v2/posts',
					array(
						'methods'    => array( 'POST' ),
						'param_mode' => 'allow_all',
					)
				),
			)
		);
		$body = '{"per_page":1}';
		$h    = $this->harness( Rfrac_Serving_Harness::EARLY );
		$h->request( self::URI, array(), array(), 'POST', $body, 'application/json' );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( $body );
		$this->capture_dispatch( $request );

		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$this->assertNoFailures();
	}

	/**
	 * Both paths: a parameter a callback adds to the dispatched request after the lookup is never stored.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_password_added_on_rest_request_before_callbacks_is_never_stored( string $path ): void {
		$h = $this->harness( $path );

		// Control: the route is stored on this path.
		$h->request( '/wp-json/tst/v1/ok' );
		$h->miss( '/tst/v1/ok', array( $this, 'register_test_routes' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'Control: the response is stored.' );
		$this->empty_tables();

		$add = static function ( $response, $handler, $request ) {
			unset( $handler );
			if ( $request instanceof WP_REST_Request ) {
				$request->set_param( 'password', 'x' );
			}
			return $response;
		};
		add_filter( 'rest_request_before_callbacks', $add, 10, 3 );

		$h->request( '/wp-json/tst/v1/ok' );
		$h->miss( '/tst/v1/ok', array( $this, 'register_test_routes' ) );
		remove_filter( 'rest_request_before_callbacks', $add, 10 );

		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'The response is not stored.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertNoFailures();
	}

	/**
	 * Both paths: a `password` captured in the URL of the route is never stored, a differently named capture is.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_password_in_the_url_of_the_route_is_never_stored( string $path ): void {
		$this->configure( array(), array( Rfrac_Serving_Harness::wildcard_route( '/tst/v1/*/*' ) ) );
		$h = $this->harness( $path );

		$h->request( '/wp-json/tst/v1/item/abc' );
		$h->miss( '/tst/v1/item/abc', array( $this, 'register_url_routes' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'Control: a route with another URL parameter is stored.' );
		$this->empty_tables();

		$h->request( '/wp-json/tst/v1/secret/hunter2' );
		$h->miss( '/tst/v1/secret/hunter2', array( $this, 'register_url_routes' ) );

		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'The response is not stored.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertNoFailures();
	}

	/**
	 * Register two routes whose URL carries a parameter: `slug` and `password`.
	 *
	 * @return void
	 */
	public function register_url_routes(): void {
		foreach ( array( 'item/(?P<slug>[a-z0-9]+)', 'secret/(?P<password>[a-z0-9]+)' ) as $pattern ) {
			register_rest_route(
				'tst/v1',
				'/' . $pattern,
				array(
					'methods'             => 'GET',
					'callback'            => static fn() => array( 'a' => 1 ),
					'permission_callback' => '__return_true',
				)
			);
		}
	}
}
