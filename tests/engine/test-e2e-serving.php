<?php
/**
 * End-to-end tests of the serving paths: a real REST request is captured and served from the cache.
 *
 * Every test with a `$path` argument runs for the early server and for the fallback server.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Serving\Early_Server;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * @covers \Rockfire\Rest_Api_Cache\Serving\Early_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Fallback_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Lookup
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 * @covers \Rockfire\Rest_Api_Cache\Serving\Response_Writer
 */
class Test_E2e_Serving extends Rfrac_E2e_Test_Case {

	/**
	 * Original external object cache flag.
	 *
	 * @var bool
	 */
	private bool $ext_cache = false;

	public function set_up() {
		parent::set_up();
		$this->ext_cache = (bool) wp_using_ext_object_cache();
		self::factory()->post->create_many( 3 );
	}

	public function tear_down() {
		wp_using_ext_object_cache( $this->ext_cache );
		wp_cache_flush_group( 'rfrac' );
		parent::tear_down();
	}

	public function test_request_does_not_leak_server_entries_into_the_next_request(): void {
		$harness = $this->harness( Rfrac_Serving_Harness::EARLY );
		$harness->request( '/wp-json/wp/v2/posts', array( 'HTTP_X_RFRAC_LEAK' => '1' ) );
		$this->assertSame( '1', $_SERVER['HTTP_X_RFRAC_LEAK'] );
		$harness->request( '/wp-json/wp/v2/posts' );
		$this->assertArrayNotHasKey( 'HTTP_X_RFRAC_LEAK', $_SERVER );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_miss_stores_and_the_next_request_is_a_hit_with_one_select_on_the_database_store( string $path ): void {
		$this->configure( array( 'store_mode' => 'database' ) );
		$h = $this->harness( $path );

		$h->request( '/wp-json/wp/v2/posts' );
		$body = $h->miss( '/wp/v2/posts' );
		$this->assertNotNull( $body, 'The first request is a miss.' );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'The miss stored the response.' );
		$total       = $h->live_header( 'X-WP-Total' );
		$total_pages = $h->live_header( 'X-WP-TotalPages' );
		$this->assertSame( '3', $total );

		$h->request( '/wp-json/wp/v2/posts' );
		$this->assertTrue( $h->lookup(), 'The second request is a hit.' );

		$this->assertSame( $body, $h->output, 'The hit is byte-identical to the live body.' );
		$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( $total, $h->hit_header( 'X-WP-Total' ) );
		$this->assertSame( $total_pages, $h->hit_header( 'X-WP-TotalPages' ) );
		$this->assertLessThanOrEqual( 1, count( $h->table_queries( 'SELECT' ) ), 'At most one SELECT on the hit path.' );
		$this->assertSame( array(), $h->table_queries( 'UPDATE|INSERT|DELETE' ), 'No write with hit sampling off.' );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_miss_stores_and_the_next_request_is_a_hit_with_no_query_on_the_object_cache_store( string $path ): void {
		wp_using_ext_object_cache( true );
		$this->configure( array( 'store_mode' => 'object_cache' ) );
		$h = $this->harness( $path );

		$h->request( '/wp-json/wp/v2/posts' );
		$body = $h->miss( '/wp/v2/posts' );
		$this->assertNotNull( $body );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$total = $h->live_header( 'X-WP-Total' );

		$h->request( '/wp-json/wp/v2/posts' );
		$this->assertTrue( $h->lookup() );

		$this->assertSame( $body, $h->output );
		$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( $total, $h->hit_header( 'X-WP-Total' ) );
		$this->assertSame( array(), $h->queries, 'The object cache hit path runs no query.' );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_vary_headers_are_advertised_on_the_miss_and_the_hit_and_split_entries( string $path ): void {
		$this->configure( array(), array( Rfrac_Serving_Harness::route( '/wp/v2/posts', array( 'vary_headers' => array( 'accept-language' ) ) ) ) );
		$h = $this->harness( $path );

		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) );
		$h->miss( '/wp/v2/posts' );
		$this->assertStringContainsString( 'accept-language', (string) $h->live_header( 'Vary' ), 'The miss advertises the vary header.' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) );
		$this->assertTrue( $h->lookup() );
		$this->assertSame( 'Origin, accept-language', $h->hit_header( 'Vary' ) );

		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_ACCEPT_LANGUAGE' => 'de' ) );
		$this->assertFalse( $h->lookup(), 'Another header value is another entry.' );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_path_form_and_rest_route_form_share_one_entry_with_a_vary_header( string $path ): void {
		$this->configure( array(), array( Rfrac_Serving_Harness::route( '/wp/v2/posts', array( 'vary_headers' => array( 'accept-language' ) ) ) ) );
		$h = $this->harness( $path );

		// Stored through the path form, served through the rest_route form.
		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) );
		$body = $h->miss( '/wp/v2/posts' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$h->request( '/?rest_route=/wp/v2/posts', array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) );
		$this->assertTrue( $h->lookup(), 'The rest_route form hits the entry of the path form.' );
		$this->assertSame( $body, $h->output );

		// And the other way round, with a fresh table.
		$this->empty_tables();
		$h->request( '/?rest_route=/wp/v2/posts', array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) );
		$body = $h->miss( '/wp/v2/posts' );
		$this->assertNotNull( $body );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) );
		$this->assertTrue( $h->lookup(), 'The path form hits the entry of the rest_route form.' );
		$this->assertSame( $body, $h->output );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'Still one entry.' );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_plain_permalinks_serve_rest_route_and_do_not_treat_wp_json_as_rest( string $path ): void {
		update_option( 'permalink_structure', '' );
		$h = $this->harness( $path );

		$h->request( '/?rest_route=/wp/v2/posts' );
		$body = $h->miss( '/wp/v2/posts' );
		$this->assertNotNull( $body );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$h->request( '/?rest_route=/wp/v2/posts' );
		$this->assertTrue( $h->lookup(), '?rest_route= is served from the cache.' );
		$this->assertSame( $body, $h->output );

		$h->request( '/wp-json/wp/v2/posts' );
		$this->assertFalse( $h->lookup(), '/wp-json/ is no REST request without pretty permalinks.' );
		$this->assertSame( Bypass_Reason::NOT_REST, Request_State::decision()->reason );
	}

	public function test_template_version_zero_leaves_serving_to_the_fallback_server(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$this->prime( '{"primed":"fallback"}' );

		Early_Server::boot( 0 );
		$this->assertFalse( Request_State::early_booted() );
		$this->assertSame( 0, Request_State::mu_template_version() );
		$this->assertFalse( Request_State::lookup_done(), 'An outdated must-use file serves nothing.' );

		$h = $this->harness( Rfrac_Serving_Harness::FALLBACK );
		$this->assertTrue( $h->lookup(), 'The fallback server serves the hit.' );
		$this->assertSame( '{"primed":"fallback"}', $h->output );
		$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_coexistence_guard_blocks_hit_and_store( string $path ): void {
		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts' );
		$this->prime();
		$this->block_by_coexistence();

		$this->assertFalse( $h->lookup(), 'No hit while the original plugin is active.' );
		$this->assertSame( Bypass_Reason::COEXISTENCE, Request_State::decision()->reason );

		$this->empty_tables();
		$h->serve( '/wp/v2/posts' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'Nothing is stored while blocked.' );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_current_config_is_not_recompiled_by_a_request( string $path ): void {
		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts' );
		$before = get_option( Names::OPTION_CONFIG );

		$h->miss( '/wp/v2/posts' );

		$this->assertSame( $before, get_option( Names::OPTION_CONFIG ), 'The snapshot is only rebuilt when stale.' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_stale_config_is_recompiled_once_and_the_response_is_stored( string $path ): void {
		// A real compile only ever writes the resolved store ('database' or 'object_cache'), never 'auto'; a stale
		// snapshot that carried 'auto' would make the recompile see a store change and flush, so Capture would
		// rightly refuse the store.
		$this->configure( array( 'fingerprint' => '', 'store_mode' => 'database' ) );
		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts' );

		$h->miss( '/wp/v2/posts' );

		$config = Compiled_Config::load();
		$this->assertNotSame( '', $config->fingerprint(), 'The stale snapshot was rebuilt on rest_api_init.' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$sealed = get_option( Names::OPTION_CONFIG );
		$h->request( '/wp-json/wp/v2/posts' );
		$this->assertTrue( $h->lookup() );
		$h->serve( '/wp/v2/posts' );
		$this->assertSame( $sealed, get_option( Names::OPTION_CONFIG ), 'A current snapshot is not rebuilt again.' );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_hit_sends_no_allow_headers_when_the_snapshot_switches_cors_off_but_still_sends_vary( string $path ): void {
		$origin = home_url();
		$this->configure( array( 'cors_origins' => array( $origin ), 'cors_wildcard' => true ) );
		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_ORIGIN' => $origin ) );
		$this->prime();

		$this->assertTrue( $h->lookup() );
		$this->assertSame( $origin, $h->hit_header( 'Access-Control-Allow-Origin' ), 'Control: an allowed origin is echoed.' );

		$this->configure( array( 'cors_origins' => array( $origin ), 'cors_wildcard' => true, 'cors_on_hits' => false ) );
		foreach ( array( $origin, 'https://other.example' ) as $request_origin ) {
			$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_ORIGIN' => $request_origin ) );
			$this->assertTrue( $h->lookup() );
			$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
			$this->assertNull( $h->hit_header( 'Access-Control-Allow-Origin' ) );
			$this->assertNull( $h->hit_header( 'Access-Control-Allow-Methods' ) );
			$this->assertNull( $h->hit_header( 'Access-Control-Allow-Credentials' ) );
			$this->assertStringContainsString( 'Origin', (string) $h->hit_header( 'Vary' ) );
		}
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_the_cors_on_hits_filter_reaches_the_hit_through_the_compile( string $path ): void {
		$h = $this->harness( $path );
		( new Plugin() )->compiler()->compile( $this->new_rest_server() );
		$config = Compiled_Config::load();
		$this->assertNotNull( $config );
		$origins = $config->cors_origins();
		$this->assertNotSame( array(), $origins );
		$origin = $origins[0];

		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_ORIGIN' => $origin ) );
		$this->prime();
		$this->assertTrue( $h->lookup() );
		$this->assertSame( $origin, $h->hit_header( 'Access-Control-Allow-Origin' ), 'Control: without the filter the origin is echoed.' );

		add_filter( Hooks::CORS_ON_HITS, '__return_false' );
		( new Plugin() )->compiler()->compile( $this->new_rest_server() );
		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_ORIGIN' => $origin ) );

		$this->assertTrue( $h->lookup() );
		$this->assertNull( $h->hit_header( 'Access-Control-Allow-Origin' ) );
		$this->assertStringContainsString( 'Origin', (string) $h->hit_header( 'Vary' ) );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_snapshot_that_bypasses_everything_serves_and_stores_nothing( string $path ): void {
		$this->assertTrue( WP_DEBUG, 'The test suite runs with WP_DEBUG.' );
		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts' );
		$this->prime();
		$this->assertTrue( $h->lookup(), 'Control: the primed entry is a hit.' );

		$this->configure( array( 'bypass_all' => true ) );
		$h->request( '/wp-json/wp/v2/posts' );

		$this->assertFalse( $h->lookup(), 'The primed entry is not served.' );
		$this->assertSame( '', $h->output );
		$this->assertSame( array(), $h->headers );
		$this->assertSame( Bypass_Reason::FILTERED, Request_State::decision()->reason );
		$this->assertSame( array(), $h->table_queries( 'SELECT' ), 'The cache is not even read.' );

		$h->serve( '/wp/v2/posts' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( Bypass_Reason::FILTERED->value, $h->live_header( 'X-Rfrac-Cache-Reason' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'Nothing is stored.' );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_the_bypass_all_filter_reaches_the_request_through_the_compile( string $path ): void {
		$h = $this->harness( $path );
		( new Plugin() )->compiler()->compile( $this->new_rest_server() );
		$h->request( '/wp-json/wp/v2/posts' );
		$this->prime();
		$this->assertTrue( $h->lookup(), 'Control: without the filter the primed entry is a hit.' );

		add_filter( Hooks::BYPASS_ALL, '__return_true' );
		( new Plugin() )->compiler()->compile( $this->new_rest_server() );
		$h->request( '/wp-json/wp/v2/posts' );

		$this->assertFalse( $h->lookup() );
		$this->assertSame( Bypass_Reason::FILTERED, Request_State::decision()->reason );

		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_AUTHORIZATION' => 'Bearer abc.def.ghi' ) );
		$this->assertFalse( $h->lookup() );
		$this->assertSame( Bypass_Reason::CREDENTIALS, Request_State::decision()->reason, 'The credential checks keep their own reason.' );
	}
}
