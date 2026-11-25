<?php
/**
 * End-to-end tests of request methods: a route that opted in to POST stores and serves a POST per canonical body,
 * and never serves that entry to another body, another method, a method override or a GET of the same URL.
 *
 * Every test with a `$path` argument runs for the early server and for the fallback server.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * @covers \Rockfire\Rest_Api_Cache\Request\Cache_Policy
 * @covers \Rockfire\Rest_Api_Cache\Serving\Early_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Fallback_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Lookup
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 */
class Test_E2e_Request_Methods extends Rfrac_E2e_Test_Case {

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

	public function set_up() {
		parent::set_up();
		$this->configure_methods( array( 'POST' ) );
	}

	/**
	 * Store a snapshot whose only rule is the route under test, caching the given methods.
	 *
	 * @param list<string> $methods Methods of the rule.
	 *
	 * @return void
	 */
	private function configure_methods( array $methods ): void {
		$this->configure(
			array(),
			array(
				Rfrac_Serving_Harness::route(
					self::ROUTE,
					array(
						'methods'    => $methods,
						'param_mode' => 'allow_all',
					)
				),
			)
		);
	}

	/**
	 * Register the route under test: a read-style search for GET, POST and PUT that answers with `q` and never echoes
	 * the rest of the request.
	 *
	 * @return void
	 */
	public function register_search_route(): void {
		register_rest_route(
			'tst/v1',
			'/search',
			array(
				'methods'             => 'GET, POST, PUT',
				'callback'            => static function ( WP_REST_Request $request ): array {
					$q = $request->get_param( 'q' );
					return array(
						'q'       => is_scalar( $q ) ? (string) $q : '',
						'results' => array( 1, 2, 3 ),
					);
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Point the harness at a POST of a JSON body to the route under test.
	 *
	 * @param Rfrac_Serving_Harness $h      Harness.
	 * @param string                $body   JSON body.
	 * @param array<string,mixed>   $server Extra `$_SERVER` entries.
	 *
	 * @return void
	 */
	private function post_json( Rfrac_Serving_Harness $h, string $body, array $server = array() ): void {
		$h->request( self::URI, $server, array(), 'POST', $body, 'application/json' );
	}

	/**
	 * The `method` column of every stored entry.
	 *
	 * @return list<string>
	 */
	private function stored_methods(): array {
		global $wpdb;
		$methods = $wpdb->get_col( $wpdb->prepare( 'SELECT method FROM %i ORDER BY id', $wpdb->prefix . Names::TABLE_ENTRIES ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_values( array_map( 'strval', $methods ) );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_the_same_canonical_body_is_a_hit_and_another_body_is_another_entry( string $path ): void {
		$h = $this->harness( $path );

		$this->post_json( $h, '{"q":"alpha","page":1,"filter":{"b":2,"a":1}}' );
		$live = $h->miss( self::ROUTE, array( $this, 'register_search_route' ) );
		$this->assertNotNull( $live, 'Body A is a miss the first time.' );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$this->post_json( $h, '{ "filter" : { "a" : 1, "b" : 2 }, "page" : 1, "q" : "alpha" }' );
		$this->assertTrue( $h->lookup(), 'Body A with its keys reordered is a hit.' );
		$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( $live, $h->output, 'The hit is byte-identical to the live body.' );

		$this->post_json( $h, '{"q":"beta","page":1,"filter":{"b":2,"a":1}}' );
		$other = $h->miss( self::ROUTE, array( $this, 'register_search_route' ) );
		$this->assertNotNull( $other, 'Body B is a miss of its own.' );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertNotSame( $live, $other );
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count() );

		$this->post_json( $h, '{"page":1,"q":"beta","filter":{"a":1,"b":2}}' );
		$this->assertTrue( $h->lookup() );
		$this->assertSame( $other, $h->output );

		$this->assertSame( array( 'POST', 'POST' ), $this->stored_methods() );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_put_to_the_post_only_rule_is_never_served_or_stored( string $path ): void {
		$h = $this->harness( $path );
		$this->post_json( $h, '{"q":"alpha"}' );
		$this->assertNotNull( $h->miss( self::ROUTE, array( $this, 'register_search_route' ) ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$h->request( self::URI, array(), array(), 'PUT', '{"q":"alpha"}', 'application/json' );
		$this->assertFalse( $h->lookup(), 'A PUT of the same body is not served the POST entry.' );
		$this->assertSame( Bypass_Reason::METHOD, Request_State::decision()->reason );
		$this->assertNotSame( '', $h->serve( self::ROUTE, array( $this, 'register_search_route' ) ) );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'Nothing is stored for the PUT.' );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_method_override_is_never_served_or_stored( string $path ): void {
		$h = $this->harness( $path );
		$this->post_json( $h, '{"q":"alpha"}' );
		$this->assertNotNull( $h->miss( self::ROUTE, array( $this, 'register_search_route' ) ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		foreach ( array( 'POST', 'PUT', 'GET', '' ) as $override ) {
			$this->post_json( $h, '{"q":"alpha"}', array( 'HTTP_X_HTTP_METHOD_OVERRIDE' => $override ) );
			$this->assertFalse( $h->lookup(), "An override to '$override' is not served the entry of the same body." );
			$this->assertSame( Bypass_Reason::METHOD_OVERRIDE, Request_State::decision()->reason, $override );
			$h->serve( self::ROUTE, array( $this, 'register_search_route' ) );
			if ( 'POST' === $override ) {
				// Core dispatches the request method itself, so the capture marks the policy's bypass.
				$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ), $override );
			}
			$this->assertNotSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ), $override );
			$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), "Nothing is stored for an override to '$override'." );
		}
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_get_of_the_url_is_never_served_the_post_entry( string $path ): void {
		$this->configure_methods( array( 'GET', 'POST' ) );
		$h = $this->harness( $path );

		$this->post_json( $h, '{"q":"alpha"}' );
		$posted = $h->miss( self::ROUTE, array( $this, 'register_search_route' ) );
		$this->assertNotNull( $posted );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$h->request( self::URI );
		$this->assertFalse( $h->lookup(), 'A GET of the URL misses the POST entry.' );
		$this->assertTrue( Request_State::decision()->cacheable, 'The GET itself is cacheable on this rule.' );
		$got = $h->serve( self::ROUTE, array( $this, 'register_search_route' ) );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertNotSame( $posted, $got );
		$this->assertSame( array( 'POST', 'GET' ), $this->stored_methods(), 'The GET is an entry of its own.' );

		$h->request( self::URI );
		$this->assertTrue( $h->lookup() );
		$this->assertSame( $got, $h->output, 'The GET is served its own entry.' );
		$this->post_json( $h, '{"q":"alpha"}' );
		$this->assertTrue( $h->lookup() );
		$this->assertSame( $posted, $h->output, 'The POST is served its own entry.' );

		$this->configure_methods( array( 'POST' ) );
		$h->request( self::URI );
		$this->assertFalse( $h->lookup(), 'On a POST-only rule a GET is never served.' );
		$this->assertSame( Bypass_Reason::METHOD, Request_State::decision()->reason );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_stored_post_keeps_neither_its_raw_body_nor_a_value_the_handler_did_not_echo( string $path ): void {
		$h      = $this->harness( $path );
		$marker = 'rfrac-marker-' . bin2hex( random_bytes( 8 ) );
		$json   = '{"q":"alpha","secret":"' . $marker . '-json"}';
		$form   = 'q=beta&secret=' . $marker . '-form';

		$this->post_json( $h, $json );
		$this->assertNotNull( $h->miss( self::ROUTE, array( $this, 'register_search_route' ) ) );
		$h->request( self::URI, array(), array(), 'POST', $form, 'application/x-www-form-urlencoded' );
		$this->assertNotNull( $h->miss( self::ROUTE, array( $this, 'register_search_route' ) ) );
		$this->assertSame( array( 'POST', 'POST' ), $this->stored_methods(), 'Both bodies are stored.' );

		$dump = Rfrac_Serving_Harness::database_dump();
		$this->assertStringNotContainsString( $marker, $dump );
		$this->assertStringNotContainsString( $json, $dump );
		$this->assertStringNotContainsString( $form, $dump );

		$this->post_json( $h, $json );
		$this->assertTrue( $h->lookup(), 'Control: the JSON body is served from its entry.' );
		$this->assertStringNotContainsString( $marker, $h->output );
		$this->assertNoFailures();
	}
}
