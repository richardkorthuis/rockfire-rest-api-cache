<?php
/**
 * Tests of the limits of the cache (parameters, size, TTL, hit statistics, key ambiguity), through both serving paths.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Request_State;
use Rockfire\Rest_Api_Cache\Storage\Database_Store;
use Rockfire\Rest_Api_Cache\Storage\Store_Interface;

/**
 * @covers \Rockfire\Rest_Api_Cache\Request\Cache_Policy
 * @covers \Rockfire\Rest_Api_Cache\Request\Cache_Key
 * @covers \Rockfire\Rest_Api_Cache\Request\Request_Context
 * @covers \Rockfire\Rest_Api_Cache\Serving\Lookup
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 * @covers \Rockfire\Rest_Api_Cache\Storage\Database_Store
 */
class Test_Limits extends Rfrac_E2e_Test_Case {

	public function set_up() {
		parent::set_up();
		self::factory()->post->create();
	}

	/**
	 * H3: requests with random unknown parameters never create a row.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_random_unknown_params_create_no_rows( string $path ): void {
		$h = $this->harness( $path );

		for ( $i = 0; $i < 100; $i++ ) {
			$h->request( '/wp-json/wp/v2/posts?rnd_' . wp_generate_password( 8, false ) . '=' . $i );
			$this->assertNotNull( $h->miss( '/wp/v2/posts' ), 'An unknown parameter is never a hit.' );
		}

		$this->assertSame( Bypass_Reason::UNKNOWN_PARAM, Request_State::decision()->reason );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'No row for 100 distinct unknown parameters.' );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count_tags() );
		$this->assertNoFailures();
	}

	/**
	 * H3: an oversized body is not stored.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_oversized_body_is_not_stored( string $path ): void {
		$this->assertSame( 1048576, Compiled_Config::load()->max_payload_bytes(), 'The default limit is 1 MiB.' );
		$h = $this->harness( $path );

		$h->request( '/wp-json/tst/v1/big' );
		$body = $h->miss( '/tst/v1/big', array( $this, 'register_test_routes' ) );

		$this->assertGreaterThan( 1048576, strlen( (string) $body ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count() );
		$this->assertNoFailures();
	}

	/**
	 * H3: a compiled config built by the plugin gives entries a time to live of 86400 seconds.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_default_entry_ttl_is_one_day( string $path ): void {
		global $wpdb;
		$server = $this->new_rest_server();
		( new Plugin() )->compiler()->compile( $server );
		$this->assertSame( 86400, Compiled_Config::load()->default_ttl() );

		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts' );
		$this->assertNotNull( $h->miss( '/wp/v2/posts' ) );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT created_at, expires_at FROM %i', $wpdb->prefix . Names::TABLE_ENTRIES ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertIsArray( $row, 'The response was stored.' );
		$this->assertSame( 86400, strtotime( $row['expires_at'] . ' UTC' ) - strtotime( $row['created_at'] . ' UTC' ) );
	}

	/**
	 * L1: `skip_cache` is not honoured unless allowed: the request is served from the cache.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_skip_cache_is_served_from_the_cache_unless_allowed( string $path ): void {
		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts' );
		$this->prime_entry( '{"primed":true}' );

		$h->request( '/wp-json/wp/v2/posts?skip_cache=1' );
		$this->assertTrue( $h->lookup(), 'skip_cache cannot be used to bypass or to probe the cache.' );
		$this->assertSame( '{"primed":true}', $h->output );

		$this->configure( array( 'allow_skip_cache' => true ) );
		$h->request( '/wp-json/wp/v2/posts?skip_cache=1' );
		$this->assertFalse( $h->lookup(), 'The opt-in honours skip_cache.' );
		$this->assertSame( Bypass_Reason::SKIP_CACHE, Request_State::decision()->reason );
	}

	/**
	 * L1: with hit sampling, at most one of ten hits writes, and the write carries the sampling weight.
	 *
	 * The fallback server runs with an injected RNG (exactly one of ten draws hits). The early server builds
	 * its own store with `wp_rand()`, so it runs with a sampling of 1000, where a second write in ten hits
	 * is practically impossible.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_sampled_hits_write_in_at_most_one_of_ten( string $path ): void {
		global $wpdb;
		$early    = Rfrac_Serving_Harness::EARLY === $path;
		$sampling = $early ? 1000 : 10;
		$this->configure( array( 'hit_sampling' => $sampling ) );
		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts' );
		$id = $this->prime_entry( '{"primed":true}' );

		$draws   = 0;
		$factory = function ( Compiled_Config $config ) use ( &$draws ): Store_Interface {
			$rng = static function () use ( &$draws ): int {
				return 1 === ++$draws ? 1 : 2;
			};
			return new Database_Store( new \Rockfire\Rest_Api_Cache\Storage\Repository(), $config->hit_sampling(), $rng );
		};

		$writes = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			$h->request( '/wp-json/wp/v2/posts' );
			$this->assertTrue( $h->lookup( $early ? null : $factory ) );
			$writes += count( $h->table_queries( 'UPDATE' ) );
		}

		$this->assertLessThanOrEqual( 1, $writes, 'At most one of ten hits writes.' );
		$hits = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT hits FROM %i WHERE id = %d', $wpdb->prefix . Names::TABLE_ENTRIES, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( $writes * $sampling, $hits, 'A sampled write counts for the whole sample.' );
		if ( ! $early ) {
			$this->assertSame( 1, $writes, 'The injected RNG hits exactly once.' );
		}
	}

	/**
	 * L1 control: with a sampling of 1 every hit writes, on both paths and with the real RNG.
	 *
	 * Proves that the early server (which cannot take an injected RNG) really writes sampled hits, so
	 * the "at most one write" above is not vacuously true.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_sampling_of_one_writes_every_hit( string $path ): void {
		global $wpdb;
		$this->configure( array( 'hit_sampling' => 1 ) );
		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts' );
		$id = $this->prime_entry( '{"primed":true}' );

		$writes = 0;
		for ( $i = 0; $i < 3; $i++ ) {
			$h->request( '/wp-json/wp/v2/posts' );
			$this->assertTrue( $h->lookup() );
			$writes += count( $h->table_queries( 'UPDATE' ) );
		}

		$this->assertSame( 3, $writes, 'Every hit writes.' );
		$hits = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT hits FROM %i WHERE id = %d', $wpdb->prefix . Names::TABLE_ENTRIES, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 3, $hits );
	}

	/**
	 * L4: values that concatenate to the same string get distinct keys and entries.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_ambiguous_concatenations_have_distinct_keys( string $path ): void {
		$this->configure( array(), array( Rfrac_Serving_Harness::route( '/wp/v2/posts', array( 'vary_headers' => array( 'x-a', 'x-b' ) ) ) ) );
		$h = $this->harness( $path );

		$pairs = array(
			'vary 1|23'    => array( '/wp-json/wp/v2/posts', array( 'HTTP_X_A' => '1', 'HTTP_X_B' => '23' ) ), // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
			'vary 12|3'    => array( '/wp-json/wp/v2/posts', array( 'HTTP_X_A' => '12', 'HTTP_X_B' => '3' ) ), // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
			'params a1|2'  => array( '/wp-json/wp/v2/posts?search=a1&page=2', array() ),
			'params a|12'  => array( '/wp-json/wp/v2/posts?search=a&page=12', array() ),
			'params a&b'   => array( '/wp-json/wp/v2/posts?search=a%26page%3D2', array() ),
			'params a|2'   => array( '/wp-json/wp/v2/posts?search=a&page=2', array() ),
		);

		$keys = array();
		foreach ( $pairs as $name => list( $uri, $server ) ) {
			$h->request( $uri, $server );
			$config   = Compiled_Config::load();
			$context  = Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() );
			$decision = $this->policy()->evaluate( $context, $config );
			$this->assertCacheable( $decision );
			$keys[ $name ] = Cache_Key::key( $context, $decision );
			$this->prime_entry( wp_json_encode( array( 'which' => $name ) ) );
		}
		$this->assertCount( count( $pairs ), array_unique( $keys ), 'Every pair has a distinct key.' );
		$this->assertSame( count( $pairs ), Rfrac_Entry_Fixtures::count() );

		foreach ( $pairs as $name => list( $uri, $server ) ) {
			$h->request( $uri, $server );
			$this->assertTrue( $h->lookup(), $name );
			$this->assertSame( wp_json_encode( array( 'which' => $name ) ), $h->output, 'Each request gets its own entry: ' . $name );
		}
	}

	/**
	 * L9: no notice or warning with an empty `$_SERVER` (PHPUnit converts notices to exceptions).
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_no_notices_with_an_empty_server_array( string $path ): void {
		$h = $this->harness( $path );
		$_SERVER = array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_GET    = array();
		$_POST   = array();
		$_COOKIE = array();

		$this->assertFalse( $h->lookup() );
		$context = Request_Context::from_globals( 'wp-json' );

		$this->assertSame( 'GET', $context->method );
		$this->assertFalse( $context->is_rest );
		$this->assertSame( Bypass_Reason::NOT_REST, Request_State::decision()->reason );
		$this->assertNoFailures();
	}
}
