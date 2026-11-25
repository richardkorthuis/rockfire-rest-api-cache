<?php
/**
 * Tests for the hit filter seam on both serving paths and in the capture.
 *
 * Every test with a `$path` argument runs for the early server and for the fallback server.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Serving\Early_Server;
use Rockfire\Rest_Api_Cache\Serving\Request_State;
use Rockfire\Rest_Api_Cache\Storage\Cached_Response;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Factory;

/**
 * @covers \Rockfire\Rest_Api_Cache\Serving\Lookup
 * @covers \Rockfire\Rest_Api_Cache\Serving\Early_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Response_Writer
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 */
class Test_Hit_Filters extends Rfrac_E2e_Test_Case {

	private const URI = '/wp-json/wp/v2/posts';

	public function set_up() {
		parent::set_up();
		// A response with posts is stored; an empty result set never is.
		self::factory()->post->create_many( 3 );
	}

	/**
	 * A pass-through filter set, attached to the harness.
	 *
	 * @param Rfrac_Serving_Harness $h Harness.
	 *
	 * @return Rfrac_Fake_Hit_Filters
	 */
	private function fake( Rfrac_Serving_Harness $h ): Rfrac_Fake_Hit_Filters {
		$fake       = new Rfrac_Fake_Hit_Filters();
		$h->filters = $fake;
		return $fake;
	}

	/**
	 * A response of the hit with another payload and header set.
	 *
	 * @param Cached_Response      $hit     Hit.
	 * @param string               $payload Payload.
	 * @param array<string,string> $headers Headers.
	 *
	 * @return Cached_Response
	 */
	private function changed( Cached_Response $hit, string $payload, array $headers = array() ): Cached_Response {
		return new Cached_Response( $hit->entry_id, $hit->cache_key, $payload, $headers, $hit->created_at, $hit->expires_at );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_without_a_filter_set_a_hit_costs_one_select_and_a_pass_through_set_costs_the_same( string $path ): void {
		$this->configure( array( 'store_mode' => 'database' ) );
		$h = $this->harness( $path );
		$h->request( self::URI );
		$this->prime( '{"hit":1}' );

		$this->assertTrue( $h->lookup() );
		$this->assertSame( '{"hit":1}', $h->output );
		$this->assertContains( 'X-Rfrac-Cache: HIT', $h->headers );
		$this->assertCount( 1, $h->table_queries( 'SELECT' ) );
		$this->assertSame( array(), $h->table_queries( 'UPDATE|INSERT|DELETE' ) );

		$fake = $this->fake( $h );
		$h->request( self::URI );
		$this->assertTrue( $h->lookup() );

		$this->assertSame( '{"hit":1}', $h->output );
		$this->assertCount( 1, $h->table_queries( 'SELECT' ), 'A pass-through set adds no query.' );
		$this->assertSame( array( 'decide', 'key', 'response', 'cors' ), $fake->calls );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_credentialed_request_never_reaches_the_filter_set( string $path ): void {
		$h = $this->harness( $path );
		$h->request( self::URI );
		$this->prime();
		$spy = $this->fake( $h );

		$requests = array(
			'authorization'    => array( array( 'HTTP_AUTHORIZATION' => 'Bearer abc' ), array() ),
			'logged-in cookie' => array( array(), array( 'wordpress_logged_in_0123abcd' => 'admin|1|x' ) ),
		);
		foreach ( $requests as $label => $request ) {
			$h->request( self::URI, $request[0], $request[1] );

			$this->assertFalse( $h->lookup(), $label );
			$this->assertSame( Bypass_Reason::CREDENTIALS, Request_State::decision()->reason, $label );
			$this->assertSame( '', $h->output, $label );
			$this->assertSame( array(), $spy->calls, "$label: the lookup." );

			$h->serve( '/wp/v2/posts' );
			$this->assertSame( array(), $spy->calls, "$label: the capture." );
			$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), "$label: nothing was stored." );
		}
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_bypass_of_decide_is_no_hit_and_stores_nothing( string $path ): void {
		$h = $this->harness( $path );
		$h->request( self::URI );
		$this->prime();
		$fake            = $this->fake( $h );
		$fake->on_decide = static fn( Policy_Decision $decision ): Policy_Decision => Policy_Decision::bypass( Bypass_Reason::FILTERED, $decision->rule );

		$this->assertFalse( $h->lookup() );

		$this->assertSame( '', $h->output );
		$this->assertSame( Bypass_Reason::FILTERED, Request_State::decision()->reason );
		$this->assertNull( Request_State::cache_key() );
		$this->assertSame( array( 'decide' ), $fake->calls );

		$this->empty_tables();
		$h->request( self::URI );
		$this->assertNotNull( $h->miss( '/wp/v2/posts' ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'The capture honours the bypass.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_the_key_of_the_filter_set_selects_the_entry_and_the_capture_stores_under_it( string $path ): void {
		$h = $this->harness( $path );
		$h->request( self::URI );
		$this->prime( '{"normal":1}' );
		Rfrac_Entry_Fixtures::insert_entry(
			array(
				'cache_key' => 'other',
				'payload'   => '{"other":1}',
				'size'      => 11,
			)
		);
		$fake         = $this->fake( $h );
		$fake->on_key = static fn(): string => 'other';

		$this->assertTrue( $h->lookup() );

		$this->assertSame( '{"other":1}', $h->output, 'The hit comes from the entry of the replaced key.' );
		$this->assertSame( array( 'decide', 'key', 'response', 'cors' ), $fake->calls );

		$this->empty_tables();
		$h->request( self::URI );
		$body = $h->miss( '/wp/v2/posts' );

		$this->assertNotNull( $body );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$stored = Store_Factory::for_mode( 'database', new Repository(), 0 )->get( 'other' );
		$this->assertInstanceOf( Cached_Response::class, $stored, 'The capture stores under the key of the filter set.' );
		$this->assertSame( $body, $stored->payload );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_null_key_is_a_filtered_bypass_on_the_lookup_and_in_the_capture( string $path ): void {
		$h = $this->harness( $path );
		$h->request( self::URI );
		$this->prime();
		$fake         = $this->fake( $h );
		$fake->on_key = static fn(): ?string => null;

		$this->assertFalse( $h->lookup() );

		$this->assertSame( '', $h->output );
		$this->assertSame( Bypass_Reason::FILTERED, Request_State::decision()->reason );
		$this->assertNull( Request_State::cache_key() );
		$this->assertSame( array( 'decide', 'key' ), $fake->calls );

		$this->empty_tables();
		$h->request( self::URI );
		$this->assertNotNull( $h->miss( '/wp/v2/posts' ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'The capture stores nothing without a key.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( Bypass_Reason::FILTERED->value, $h->live_header( 'X-Rfrac-Cache-Reason' ) );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_the_response_of_the_filter_set_is_what_is_served_and_null_serves_nothing( string $path ): void {
		$h = $this->harness( $path );
		$h->request( self::URI );
		$this->prime( '{"stored":1}' );
		$fake              = $this->fake( $h );
		$fake->on_response = fn( Cached_Response $hit ): Cached_Response => $this->changed( $hit, '{"changed":true}' );

		$this->assertTrue( $h->lookup() );

		$this->assertSame( '{"changed":true}', $h->output, 'The transformed payload is emitted.' );
		$this->assertContains( 'X-Rfrac-Cache: HIT', $h->headers );

		$fake->on_response = static fn(): ?Cached_Response => null;
		$fake->calls       = array();
		$h->request( self::URI );

		$this->assertFalse( $h->lookup() );

		$this->assertSame( '', $h->output );
		$this->assertSame( Bypass_Reason::FILTERED, Request_State::decision()->reason );
		$this->assertSame( array( 'decide', 'key', 'response' ), $fake->calls, 'A response that is not served asks nothing more.' );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_cors_of_the_filter_set_overrides_the_flag_of_the_snapshot_in_both_directions( string $path ): void {
		$origin = home_url();
		$this->configure( array( 'cors_origins' => array( $origin ) ) );
		$h = $this->harness( $path );
		$h->request( self::URI, array( 'HTTP_ORIGIN' => $origin ) );
		$this->prime();

		$this->assertTrue( $h->lookup() );
		$this->assertSame( $origin, $h->hit_header( 'Access-Control-Allow-Origin' ), 'Control: the snapshot sends the allow headers.' );

		$fake          = $this->fake( $h );
		$fake->on_cors = static fn(): bool => false;
		$h->request( self::URI, array( 'HTTP_ORIGIN' => $origin ) );

		$this->assertTrue( $h->lookup() );

		$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
		$this->assertNull( $h->hit_header( 'Access-Control-Allow-Origin' ) );
		$this->assertNull( $h->hit_header( 'Access-Control-Allow-Methods' ) );
		$this->assertNull( $h->hit_header( 'Access-Control-Allow-Credentials' ) );
		$this->assertStringContainsString( 'Origin', (string) $h->hit_header( 'Vary' ), 'Vary is always sent.' );

		$this->configure( array( 'cors_origins' => array( $origin ), 'cors_on_hits' => false ) );
		$fake->on_cors = static fn(): bool => true;
		$h->request( self::URI, array( 'HTTP_ORIGIN' => $origin ) );

		$this->assertTrue( $h->lookup() );
		$this->assertSame( $origin, $h->hit_header( 'Access-Control-Allow-Origin' ), 'The filter set wins over the snapshot flag.' );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_headers_of_the_filter_set_still_pass_the_allow_list_and_the_sanitiser( string $path ): void {
		$this->configure( array( 'replay_headers' => array( 'x-ok', 'x-wp-total' ) ) );
		$h = $this->harness( $path );
		$h->request( self::URI );
		$this->prime();
		$fake              = $this->fake( $h );
		$fake->on_response = fn( Cached_Response $hit ): Cached_Response => $this->changed(
			$hit,
			$hit->payload,
			array(
				'X-Evil'                => 'yes',
				"X-Ok\r\nSet-Cookie"    => 'a=b',
				'X-WP-Total'            => "5\r\nSet-Cookie: pwn=1",
				'Set-Cookie'            => 'pwn=1',
				"X-Ok\r\nX-Injected: 1" => '1',
				'X-Ok'                  => "fine\r\nX-Injected: 1",
			)
		);

		$this->assertTrue( $h->lookup() );

		foreach ( $h->headers as $line ) {
			$this->assertDoesNotMatchRegularExpression( '/[\r\n]/', $line );
			$this->assertDoesNotMatchRegularExpression( '/^(X-Evil|Set-Cookie|X-Injected)\b/i', $line );
		}
		$this->assertNull( $h->hit_header( 'X-Evil' ) );
		$this->assertNull( $h->hit_header( 'Set-Cookie' ) );
		$this->assertNull( $h->hit_header( 'X-Injected' ) );
		$this->assertNotNull( $h->hit_header( 'X-Ok' ), 'An allow-listed name is still replayed.' );
		$this->assertNotNull( $h->hit_header( 'X-WP-Total' ) );
		$this->assertNoFailures();
	}

	public function test_the_early_server_seam_hands_the_filter_set_to_its_lookup_and_null_restores_the_default(): void {
		$this->simulate( self::URI );
		$this->prime( '{"early":1}' );
		$fake = new Rfrac_Fake_Hit_Filters();
		Early_Server::use_writer( $this->capturing_writer() );
		Early_Server::use_hit_filters( $fake );

		try {
			Early_Server::boot( 1 );
			$this->fail( 'The primed entry was not served.' );
		} catch ( Rfrac_Test_Exit $e ) {
			$this->assertSame( array( 'decide', 'key', 'response', 'cors' ), $fake->calls );
		}

		Early_Server::use_hit_filters( null );
		Request_State::reset();
		$fake->calls = array();
		try {
			Early_Server::boot( 1 );
			$this->fail( 'The primed entry was not served.' );
		} catch ( Rfrac_Test_Exit $e ) {
			$this->assertSame( array(), $fake->calls, 'Without a seam the default of the lookup applies: no set.' );
		}
	}
}
