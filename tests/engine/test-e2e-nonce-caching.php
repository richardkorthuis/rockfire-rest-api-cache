<?php
/**
 * End-to-end tests of nonce caching: with the compiled flag on, a logged-out request whose only credential signal
 * is a REST nonce is stored and served under the key of the same request without it, and a default site's
 * snapshot is what it was before the flag existed.
 *
 * Every test with a `$path` argument runs for the early server and for the fallback server.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * @covers \Rockfire\Rest_Api_Cache\Config\Config_Compiler
 * @covers \Rockfire\Rest_Api_Cache\Config\Compiled_Config
 * @covers \Rockfire\Rest_Api_Cache\Request\Cache_Policy
 * @covers \Rockfire\Rest_Api_Cache\Serving\Early_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Fallback_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 */
class Test_E2e_Nonce_Caching extends Rfrac_E2e_Test_Case {

	/**
	 * The routes under test: the core posts route and a plugin route under the `/tst/v1/*` wildcard.
	 *
	 * @var list<string>
	 */
	private const ROUTES = array( '/wp/v2/posts', '/tst/v1/ok' );

	public function set_up() {
		parent::set_up();
		// Content to store: an empty result set is never stored.
		self::factory()->post->create_many( 3 );
	}

	/**
	 * The three ways a request carries a nonce: the header, the query parameter, or both.
	 *
	 * @return array<string,array{0:bool,1:bool}>
	 */
	public static function carriers(): array {
		return array(
			'header'    => array( true, false ),
			'parameter' => array( false, true ),
			'both'      => array( true, true ),
		);
	}

	/**
	 * Point the harness at a request for a route that carries a nonce.
	 *
	 * @param Rfrac_Serving_Harness $h      Harness.
	 * @param string                $route  Route, e.g. `/wp/v2/posts`.
	 * @param string                $nonce  Nonce value.
	 * @param bool                  $header Whether the nonce travels in the `X-WP-Nonce` header.
	 * @param bool                  $param  Whether the nonce travels in the `_wpnonce` query parameter.
	 *
	 * @return void
	 */
	private function nonce_request( Rfrac_Serving_Harness $h, string $route, string $nonce, bool $header, bool $param ): void {
		$h->request(
			'/wp-json' . $route . ( $param ? '?_wpnonce=' . rawurlencode( $nonce ) : '' ),
			$header ? array( 'HTTP_X_WP_NONCE' => $nonce ) : array()
		);
	}

	/**
	 * With nonce caching on, a repeat of a nonce request with another nonce value is a hit, for each route.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_repeat_with_another_nonce_is_a_hit_with_nonce_caching_on( string $path ): void {
		$this->configure( array( 'nonce_caching' => true ) );
		$h = $this->harness( $path );

		foreach ( self::ROUTES as $route ) {
			// The first request of a visitor carries a real, valid logged-out nonce, like a front end sends.
			$this->nonce_request( $h, $route, wp_create_nonce( 'wp_rest' ), true, true );
			$this->assertNotNull( $h->miss( $route, array( $this, 'register_test_routes' ) ), $route . ' is a miss the first time.' );
			$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ), $route );

			$requests = 0;
			$hits     = 0;
			foreach ( array( 'ce11f4c7a0', 'NONCE-7f3a9c', '0000000000', 'a1b2c3d4e5' ) as $nonce ) {
				foreach ( self::carriers() as [ $header, $param ] ) {
					$this->nonce_request( $h, $route, $nonce, $header, $param );
					++$requests;
					if ( $h->lookup() && 'HIT' === $h->hit_header( 'X-Rfrac-Cache' ) ) {
						++$hits;
					}
				}
			}
			$this->assertSame( $requests, $hits, $route . ': every repeat is a hit.' );

			$h->request( '/wp-json' . $route );
			$this->assertTrue( $h->lookup(), $route . ': the request without a nonce is served from the same entry.' );
		}

		$this->assertSame( count( self::ROUTES ), Rfrac_Entry_Fixtures::count(), 'One entry per route, whatever the nonce.' );
		$this->assertNoFailures();
	}

	/**
	 * With nonce caching off, a nonce request is neither served nor stored.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_nonce_request_is_never_served_or_stored_with_nonce_caching_off( string $path ): void {
		$h = $this->harness( $path );

		$h->request( '/wp-json/wp/v2/posts' );
		$h->miss( '/wp/v2/posts' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'Control: the request without a nonce is stored.' );

		foreach ( self::carriers() as [ $header, $param ] ) {
			$this->nonce_request( $h, '/wp/v2/posts', 'ce11f4c7a0', $header, $param );
			$this->assertFalse( $h->lookup(), 'The entry is not served to a nonce request.' );
			$this->assertSame( Bypass_Reason::CREDENTIALS, Request_State::decision()->reason );
		}
	}

	/**
	 * A compile writes the flag only when the filter returns the strict boolean `true`, and the lookup of either
	 * path honours the compiled snapshot.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_the_compiled_filter_switches_nonce_caching_on_for_both_paths( string $path ): void {
		add_filter( Hooks::NONCE_CACHING, '__return_true' );
		( new Config_Compiler() )->compile( $this->new_rest_server() );
		$this->assertTrue( Compiled_Config::load()->nonce_caching() );
		$this->assertTrue( get_option( Names::OPTION_CONFIG )['nonce_caching'] );

		$h = $this->harness( $path );
		$this->nonce_request( $h, '/wp/v2/posts', wp_create_nonce( 'wp_rest' ), true, true );
		$this->assertNotNull( $h->miss( '/wp/v2/posts' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$this->nonce_request( $h, '/wp/v2/posts', 'ce11f4c7a0', true, true );
		$this->assertTrue( $h->lookup() );
	}

	public function test_a_default_site_snapshot_has_the_keys_of_the_previous_version_and_the_home_host(): void {
		$compiler = new Config_Compiler();
		$server   = $this->new_rest_server();

		$compiler->compile( $server );
		$stored = get_option( Names::OPTION_CONFIG );

		$this->assertSame( 2, Compiled_Config::FORMAT );
		$this->assertIsArray( $stored );
		$this->assertSame( 2, $stored['format'] );
		$this->assertEqualsCanonicalizing(
			array(
				'format',
				'enabled',
				'store_mode',
				'rest_prefix',
				'charset',
				'default_ttl',
				'max_ttl',
				'max_entries',
				'max_payload_bytes',
				'max_params',
				'hit_sampling',
				'universal_params',
				'bypass_cookie_prefixes',
				'bypass_headers',
				'cors_origins',
				'cors_wildcard',
				'replay_headers',
				'allow_skip_cache',
				'schema_version',
				'pending_discovery',
				'routes',
				'fingerprint',
				'home_host', // Written whenever the home URL has a host, so every compiled site carries it.
			),
			array_keys( $stored )
		);

		$fingerprint = $stored['fingerprint'];
		add_filter( Hooks::NONCE_CACHING, '__return_false' );
		$this->assertSame( $fingerprint, $compiler->fingerprint( $server ), 'A filter that keeps the default leaves the fingerprint alone.' );
		$compiler->compile( $server );
		$this->assertSame( $stored, get_option( Names::OPTION_CONFIG ), 'Nor does it change the stored snapshot.' );
	}
}
