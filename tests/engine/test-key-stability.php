<?php
/**
 * End-to-end key stability: the cache key stored through the real request context equals the key the
 * `Request_Context::from_parts()` and `Cache_Key` pipeline computes for the same raw request.
 *
 * Each scenario is stored on the early and on the fallback serving path, both of which build their context with
 * `Request_Context::from_globals()`. The stored `cache_key` is compared with an oracle key, computed from the raw
 * server, query, cookie and body parts of the scenario written out as literals. The oracle is the byte-identity proof
 * a change of how the context reads the request has to pass untouched; the literal v5 key of
 * `Test_Cache_Key::test_get_key_and_url_hash_are_pinned()` anchors the pipeline to v5.
 *
 * Every test with a `$path` argument runs for the early server and for the fallback server.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Request_Context;

/**
 * @covers \Rockfire\Rest_Api_Cache\Request\Request_Context
 * @covers \Rockfire\Rest_Api_Cache\Request\Cache_Key
 * @covers \Rockfire\Rest_Api_Cache\Serving\Early_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Fallback_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 */
class Test_Key_Stability extends Rfrac_E2e_Test_Case {

	public function set_up() {
		parent::set_up();
		// Content to store: page 2 of five per page needs six posts.
		self::factory()->post->create_many( 6 );
		// A search or an include list may match nothing, and an empty result set is only stored when allowed.
		add_filter( Hooks::CACHE_EMPTY_RESULTS, '__return_true' );
	}

	/**
	 * Register the route of the POST scenarios: a read-style search for GET and POST that never echoes the rest of the
	 * request.
	 *
	 * @return void
	 */
	public function register_search_route(): void {
		register_rest_route(
			'tst/v1',
			'/search',
			array(
				'methods'             => 'GET, POST',
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
	 * A scenario: the raw request as the server hands it over, the rule it is served under and what its oracle context
	 * has to carry.
	 *
	 * `uri`, `server` (entries beyond `REQUEST_METHOD`, `REQUEST_URI` and the body headers), `cookie`, `get`, `post` and
	 * `body` are the raw parts of the request; `type` is its Content-Type. `route` is the REST route and rule key,
	 * `rule` the route rule fields and `config` the top-level fields of the compiled config. `cookie_names` are the
	 * cookies whose values the context keeps; `host` and `vary` are what the decision of the oracle has to hold, so a
	 * scenario cannot pass without exercising what it names.
	 *
	 * @param array<string,mixed> $scenario Fields that differ from the defaults.
	 *
	 * @return array<string,mixed>
	 */
	private static function scenario( array $scenario ): array {
		return array_merge(
			array(
				'method'       => 'GET',
				'uri'          => '',
				'server'       => array(),
				'cookie'       => array(),
				'get'          => array(),
				'post'         => array(),
				'body'         => null,
				'type'         => '',
				'route'        => '/wp/v2/posts',
				'rule'         => array(),
				'config'       => array(),
				'cookie_names' => array(),
				'host'         => null,
				'vary'         => array(),
			),
			$scenario
		);
	}

	/**
	 * The GET scenarios, all under the posts route.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function get_scenarios(): array {
		return array(
			'the pretty route'                       => self::scenario(
				array(
					'uri' => '/wp-json/wp/v2/posts?per_page=5&page=2',
					'get' => array(
						'per_page' => '5',
						'page'     => '2',
					),
				)
			),
			'the rest_route query'                   => self::scenario(
				array(
					// Five per page, like the pretty route: page 2 of the default ten per page does not exist, and its 400 is never stored.
					'uri' => '/?rest_route=/wp/v2/posts&per_page=5&page=2',
					'get' => array(
						'rest_route' => '/wp/v2/posts',
						'per_page'   => '5',
						'page'       => '2',
					),
				)
			),
			'nested list and map parameters'         => self::scenario(
				array(
					'uri'  => '/wp-json/wp/v2/posts?include[]=5&include[]=3&a[y]=2&a[x]=1',
					'get'  => array(
						'include' => array( '5', '3' ),
						'a'       => array(
							'y' => '2',
							'x' => '1',
						),
					),
					'rule' => array( 'param_mode' => 'allow_all' ),
				)
			),
			'an encoded search'                      => self::scenario(
				array(
					'uri' => '/wp-json/wp/v2/posts?search=%3Cb%3Ex%3C%2Fb%3E%27%20%C3%A9',
					'get' => array( 'search' => "<b>x</b>' \u{e9}" ),
				)
			),
			'a vary header'                          => self::scenario(
				array(
					'uri'    => '/wp-json/wp/v2/posts?per_page=5',
					'get'    => array( 'per_page' => '5' ),
					'server' => array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ),
					'rule'   => array( 'vary_headers' => array( 'accept-language' ) ),
					'vary'   => array( 'accept-language' => 'nl' ),
				)
			),
			'a vary cookie'                          => self::scenario(
				array(
					'uri'          => '/wp-json/wp/v2/posts?per_page=5',
					'get'          => array( 'per_page' => '5' ),
					'cookie'       => array( 'rfrac_cur' => 'EUR' ),
					'rule'         => array( 'vary_cookies' => array( 'rfrac_cur' => array( 'EUR', 'USD' ) ) ),
					'cookie_names' => array( 'rfrac_cur' ),
					'vary'         => array( 'cookie:rfrac_cur' => 'EUR' ),
				)
			),
			'a foreign host with a port'             => self::scenario(
				array(
					'uri'    => '/wp-json/wp/v2/posts?per_page=5',
					'get'    => array( 'per_page' => '5' ),
					'server' => array( 'HTTP_HOST' => 'evil.example:8080' ),
					'config' => array( 'home_host' => 'example.org' ),
					'host'   => 'evil.example:8080',
				)
			),
		);
	}

	/**
	 * The POST scenarios, all under a route that opted in to POST.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function post_scenarios(): array {
		$rule = array(
			'methods'    => array( 'POST' ),
			'param_mode' => 'allow_all',
		);
		return array(
			'a form body' => self::scenario(
				array(
					'method' => 'POST',
					'uri'    => '/wp-json/tst/v1/search',
					'route'  => '/tst/v1/search',
					'rule'   => $rule,
					'type'   => 'application/x-www-form-urlencoded',
					'body'   => 'b=2&a[]=1&a[]=0&q=%27x',
					'post'   => array(
						'b' => '2',
						'a' => array( '1', '0' ),
						'q' => "'x",
					),
				)
			),
			'a JSON body' => self::scenario(
				array(
					'method' => 'POST',
					'uri'    => '/wp-json/tst/v1/search',
					'route'  => '/tst/v1/search',
					'rule'   => $rule,
					'type'   => 'application/json',
					'body'   => '{"b":2,"a":[1,0]}',
				)
			),
		);
	}

	/**
	 * Every scenario on every serving path.
	 *
	 * @param array<string,array<string,mixed>> $scenarios Scenarios by name.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	private static function on_both_paths( array $scenarios ): array {
		$cases = array();
		foreach ( self::paths() as $path_name => $row ) {
			foreach ( $scenarios as $name => $scenario ) {
				$cases[ $name . ' on the ' . $path_name . ' path' ] = array( $row[0], $scenario );
			}
		}
		return $cases;
	}

	/**
	 * Data provider: the GET scenarios on both paths.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	public static function get_cases(): array {
		return self::on_both_paths( self::get_scenarios() );
	}

	/**
	 * Data provider: the POST scenarios on both paths.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	public static function post_cases(): array {
		return self::on_both_paths( self::post_scenarios() );
	}

	/**
	 * @dataProvider get_cases
	 *
	 * @param string              $path     Serving path.
	 * @param array<string,mixed> $scenario Scenario.
	 */
	public function test_a_get_is_stored_under_the_key_of_the_oracle_and_served_again( string $path, array $scenario ): void {
		$this->assert_stored_under_the_oracle_key( $path, $scenario );
	}

	/**
	 * @dataProvider post_cases
	 *
	 * @param string              $path     Serving path.
	 * @param array<string,mixed> $scenario Scenario.
	 */
	public function test_a_post_is_stored_under_the_key_of_the_oracle_and_served_again( string $path, array $scenario ): void {
		$this->assert_stored_under_the_oracle_key( $path, $scenario );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_markup_search_is_stored_apart_from_the_plain_search( string $path ): void {
		$this->configure( array(), array( Rfrac_Serving_Harness::route( '/wp/v2/posts' ) ) );
		$h = $this->harness( $path );

		foreach ( array( '/wp-json/wp/v2/posts?search=%3Cb%3Ex%3C%2Fb%3E', '/wp-json/wp/v2/posts?search=x' ) as $uri ) {
			$h->request( $uri );
			$this->assertNotNull( $h->miss( '/wp/v2/posts' ), $uri . ' is a miss of its own.' );
			$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ), $uri );
		}

		$keys = $this->stored_keys();
		$this->assertCount( 2, $keys, 'Both searches are stored.' );
		$this->assertNotSame( $keys[0], $keys[1], 'The markup search never shares the key of the plain one.' );
		$this->assertNoFailures();
	}

	/**
	 * Serve a scenario once and again: the first request stores a single entry under the oracle key, the second is a hit.
	 *
	 * @param string              $path     Serving path.
	 * @param array<string,mixed> $scenario Scenario.
	 *
	 * @return void
	 */
	private function assert_stored_under_the_oracle_key( string $path, array $scenario ): void {
		$routes = array( Rfrac_Serving_Harness::route( (string) $scenario['route'], (array) $scenario['rule'] ) );
		$this->configure( (array) $scenario['config'], $routes );
		$oracle = $this->oracle_key( $scenario, Compiled_Config::from_array( Rfrac_Serving_Harness::snapshot( (array) $scenario['config'], $routes ) ) );

		if ( isset( $scenario['rule']['vary_cookies'] ) ) {
			// The plugin that varies the route declares the same cookies live, as the capture compares the two.
			add_filter(
				Hooks::ROUTE_VARY_COOKIES,
				static fn( $declared, $key ) => $scenario['route'] === $key ? $scenario['rule']['vary_cookies'] : $declared,
				10,
				2
			);
		}

		$h = $this->harness( $path );
		$this->send( $h, $scenario );
		$this->assertNotNull( $h->miss( (string) $scenario['route'], array( $this, 'register_search_route' ) ), 'The first request is a miss.' );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ), 'The first request is stored.' );
		$this->assertSame( array( $oracle ), $this->stored_keys(), 'The one stored entry is keyed as the oracle keys the request.' );

		$this->send( $h, $scenario );
		$this->assertTrue( $h->lookup(), 'The second request is a hit.' );
		$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
		$this->assertNoFailures();
	}

	/**
	 * Point the harness at the request of a scenario.
	 *
	 * @param Rfrac_Serving_Harness $h        Harness.
	 * @param array<string,mixed>   $scenario Scenario.
	 *
	 * @return void
	 */
	private function send( Rfrac_Serving_Harness $h, array $scenario ): void {
		$h->request(
			(string) $scenario['uri'],
			(array) $scenario['server'],
			(array) $scenario['cookie'],
			(string) $scenario['method'],
			null === $scenario['body'] ? null : (string) $scenario['body'],
			(string) $scenario['type']
		);
	}

	/**
	 * The key the `Request_Context::from_parts()` and `Cache_Key` pipeline computes for the raw request of a scenario: the
	 * server variables the harness sets, the query as PHP parses it, the cookies and the body of the scenario, on a pretty
	 * permalink site of blog 1 served over http.
	 *
	 * @param array<string,mixed> $scenario Scenario.
	 * @param Compiled_Config     $config   The compiled config the scenario is served under.
	 *
	 * @return string
	 */
	private function oracle_key( array $scenario, Compiled_Config $config ): string {
		$server = array_merge(
			array(
				'REQUEST_METHOD' => $scenario['method'],
				'REQUEST_URI'    => $scenario['uri'],
			),
			(array) $scenario['server']
		);
		if ( '' !== $scenario['type'] ) {
			$server['CONTENT_TYPE'] = $scenario['type'];
		}
		if ( null !== $scenario['body'] ) {
			$server['CONTENT_LENGTH'] = (string) strlen( (string) $scenario['body'] );
		}

		$context  = Request_Context::from_parts(
			$server,
			(array) $scenario['get'],
			(array) $scenario['post'],
			(array) $scenario['cookie'],
			'wp-json',
			'',
			true,
			1,
			false,
			(array) $scenario['cookie_names'],
			null === $scenario['body'] ? null : (string) $scenario['body']
		);
		$decision = $this->policy()->evaluate( $context, $config );

		$this->assertCacheable( $decision );
		$this->assertSame( $scenario['host'], $decision->host, 'The oracle keys the host the scenario names.' );
		$this->assertSame( $scenario['vary'], $decision->vary, 'The oracle keys the vary values the scenario names.' );
		return Cache_Key::key( $context, $decision );
	}

	/**
	 * The `cache_key` column of every stored entry, oldest first.
	 *
	 * @return list<string>
	 */
	private function stored_keys(): array {
		global $wpdb;
		$keys = $wpdb->get_col( $wpdb->prepare( 'SELECT cache_key FROM %i ORDER BY id', $wpdb->prefix . Names::TABLE_ENTRIES ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_values( array_map( 'strval', $keys ) );
	}
}
