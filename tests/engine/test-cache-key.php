<?php
/**
 * Tests for the structured cache key.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Route;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;

/**
 * @covers \Rockfire\Rest_Api_Cache\Request\Cache_Key
 */
class Test_Cache_Key extends Rfrac_Policy_Test_Case {

	/**
	 * Key of a URI under a config.
	 *
	 * @param string                    $uri    Request URI.
	 * @param array<string,mixed>       $args   Request arguments.
	 * @param array<string,mixed>|null  $route  Route rule (default `/wp/v2/posts`).
	 */
	private function key_for( string $uri, array $args = array(), ?array $route = null ): string {
		$context  = $this->request( $uri, $args );
		$decision = $this->policy()->evaluate( $context, $this->config( array( $route ?? $this->route( '/wp/v2/posts' ) ) ) );
		$this->assertCacheable( $decision );
		return Cache_Key::key( $context, $decision );
	}

	public function test_key_is_sha256_of_the_documented_structure(): void {
		$route    = $this->route( '/wp/v2/posts', array( 'vary_headers' => array( 'accept-language' ) ) );
		$context  = $this->request( '/wp-json/wp/v2/posts?page=2', array( 'server' => array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) ) );
		$decision = $this->policy()->evaluate( $context, $this->config( array( $route ) ) );
		$this->assertCacheable( $decision );

		$expected = hash(
			'sha256',
			(string) json_encode(
				array(
					'v'      => 1,
					'blog'   => 1,
					'scheme' => 'http',
					'method' => 'GET',
					'route'  => '/wp/v2/posts',
					'params' => array( 'page' => '2' ),
					'vary'   => array( 'accept-language' => hash( 'sha256', 'nl' ) ),
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
		$this->assertSame( $expected, Cache_Key::key( $context, $decision ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', Cache_Key::key( $context, $decision ) );
	}

	public function test_ignored_params_do_not_change_the_key(): void {
		$route = $this->route( '/wp/v2/posts', array( 'ignore_params' => array( 'utm' ) ) );
		$this->assertSame( $this->key_for( '/wp-json/wp/v2/posts?page=2', array(), $route ), $this->key_for( '/wp-json/wp/v2/posts?page=2&utm=x', array(), $route ) );
	}

	public function test_param_order_does_not_change_the_key(): void {
		$this->assertSame( $this->key_for( '/wp-json/wp/v2/posts?page=2&per_page=5' ), $this->key_for( '/wp-json/wp/v2/posts?per_page=5&page=2' ) );
	}

	public function test_key_differs_per_param_value_scheme_and_route(): void {
		$base = $this->key_for( '/wp-json/wp/v2/posts?page=2' );
		$this->assertNotSame( $base, $this->key_for( '/wp-json/wp/v2/posts?page=3' ) );
		$this->assertNotSame( $base, $this->key_for( '/wp-json/wp/v2/posts?page=2', array( 'ssl' => true ) ) );
		$this->assertNotSame( $base, $this->key_for( '/wp-json/wp/v2/pages?page=2', array(), $this->route( '/wp/v2/pages' ) ) );
	}

	public function test_scalar_and_array_params_differ(): void {
		$route = $this->route( '/wp/v2/posts', array( 'param_mode' => 'allow_all' ) );
		$this->assertNotSame( $this->key_for( '/wp-json/wp/v2/posts?a=1', array(), $route ), $this->key_for( '/wp-json/wp/v2/posts?a[]=1', array(), $route ) );
		$this->assertNotSame( $this->key_for( '/wp-json/wp/v2/posts?a[]=1', array(), $route ), $this->key_for( '/wp-json/wp/v2/posts?a[1]=1', array(), $route ) );
	}

	public function test_vary_header_changes_the_key(): void {
		$route = $this->route( '/wp/v2/posts', array( 'vary_headers' => array( 'accept-language' ) ) );
		$nl    = $this->key_for( '/wp-json/wp/v2/posts', array( 'server' => array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) ), $route );
		$en    = $this->key_for( '/wp-json/wp/v2/posts', array( 'server' => array( 'HTTP_ACCEPT_LANGUAGE' => 'en' ) ), $route );
		$none  = $this->key_for( '/wp-json/wp/v2/posts', array(), $route );
		$this->assertNotSame( $nl, $en );
		$this->assertNotSame( $nl, $none );
	}

	public function test_vary_cookie_value_changes_the_key_but_not_the_url_hash(): void {
		$route  = $this->route( '/wp/v2/posts', array( 'vary_cookies' => array( 'cur' => array( 'EUR', 'USD' ) ) ) );
		$config = $this->config( array( $route ) );
		$keys   = array();
		$hashes = array();
		foreach ( array(
			'none' => array(),
			'EUR'  => array( 'cur' => 'EUR' ),
			'USD'  => array( 'cur' => 'USD' ),
		) as $label => $cookie ) {
			$context  = $this->request( '/wp-json/wp/v2/posts?page=2', array( 'cookie' => $cookie, 'vary_cookie_names' => array( 'cur' ) ) );
			$decision = $this->policy()->evaluate( $context, $config );
			$this->assertCacheable( $decision );
			$keys[ $label ]   = Cache_Key::key( $context, $decision );
			$hashes[ $label ] = Cache_Key::url_hash( $context, $decision->key_params );
		}

		$this->assertCount( 3, array_unique( $keys ), 'The empty value, EUR and USD are three entries.' );
		$this->assertCount( 1, array_unique( $hashes ), 'They all belong to one URL.' );
	}

	public function test_a_vary_cookie_is_keyed_apart_from_a_header_of_the_same_name(): void {
		$cookie_route = $this->route( '/wp/v2/posts', array( 'vary_cookies' => array( 'cur' => array( 'EUR' ) ) ) );
		$header_route = $this->route( '/wp/v2/posts', array( 'vary_headers' => array( 'cur' ) ) );

		$by_cookie = $this->key_for( '/wp-json/wp/v2/posts', array( 'cookie' => array( 'cur' => 'EUR' ), 'vary_cookie_names' => array( 'cur' ) ), $cookie_route );
		$by_header = $this->key_for( '/wp-json/wp/v2/posts', array( 'server' => array( 'HTTP_CUR' => 'EUR' ) ), $header_route );
		$this->assertNotSame( $by_cookie, $by_header );
	}

	public function test_display_hashes_the_vary_cookie_value(): void {
		$route    = $this->route( '/wp/v2/posts', array( 'vary_cookies' => array( 'cur' => array( 'EUR' ) ) ) );
		$context  = $this->request( '/wp-json/wp/v2/posts', array( 'cookie' => array( 'cur' => 'EUR' ), 'vary_cookie_names' => array( 'cur' ) ) );
		$decision = $this->policy()->evaluate( $context, $this->config( array( $route ) ) );
		$this->assertCacheable( $decision );

		$display = Cache_Key::display( $context, $decision );
		$this->assertSame( array( 'cookie:cur' => substr( hash( 'sha256', 'EUR' ), 0, 12 ) ), $display['vary'] );
	}

	public function test_url_hash_ignores_scheme_and_vary(): void {
		$route  = $this->route( '/wp/v2/posts', array( 'vary_headers' => array( 'accept-language' ) ) );
		$config = $this->config( array( $route ) );
		$a      = $this->request( '/wp-json/wp/v2/posts?page=2', array( 'server' => array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) ) );
		$b      = $this->request( '/wp-json/wp/v2/posts?page=2', array( 'ssl' => true ) );
		$dec_a  = $this->policy()->evaluate( $a, $config );
		$dec_b  = $this->policy()->evaluate( $b, $config );

		$this->assertNotSame( Cache_Key::key( $a, $dec_a ), Cache_Key::key( $b, $dec_b ) );
		$this->assertSame( Cache_Key::url_hash( $a, $dec_a->key_params ), Cache_Key::url_hash( $b, $dec_b->key_params ) );
		$this->assertNotSame( Cache_Key::url_hash( $a, array( 'page' => '2' ) ), Cache_Key::url_hash( $a, array( 'page' => '3' ) ) );
		$this->assertNotSame( Cache_Key::url_hash( $a, $dec_a->key_params ), Cache_Key::key( $a, $dec_a ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', Cache_Key::url_hash( $a, array() ) );
	}

	public function test_url_hash_matches_between_request_and_flush_url(): void {
		update_option( 'permalink_structure', '/%postname%/' );
		$config  = $this->config();
		$request =$this->request( '/wp-json/wp/v2/posts?page=2' );
		$flush   = Request_Context::from_url( 'https://example.org/wp-json/wp/v2/posts?page=2', 'wp-json' );
		$this->assertSame(
			Cache_Key::url_hash( $request, $this->policy()->evaluate( $request, $config )->key_params ),
			Cache_Key::url_hash( $flush, $flush->params )
		);
	}

	public function test_display_never_contains_raw_vary_values(): void {
		$route    = $this->route( '/wp/v2/posts', array( 'vary_headers' => array( 'accept-language' ) ) );
		$context  = $this->request( '/wp-json/wp/v2/posts?page=2', array( 'server' => array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) ) );
		$decision = $this->policy()->evaluate( $context, $this->config( array( $route ) ) );
		$this->assertCacheable( $decision );

		$display = Cache_Key::display( $context, $decision );
		$this->assertSame( '/wp/v2/posts', $display['route'] );
		$this->assertSame( array( 'page' => '2' ), $display['params'] );
		$this->assertSame( array( 'accept-language' => substr( hash( 'sha256', 'nl' ), 0, 12 ) ), $display['vary'] );
		$this->assertStringNotContainsString( 'nl', (string) wp_json_encode( $display['vary'] ) );
		$this->assertStringNotContainsString( '"nl"', (string) wp_json_encode( $display ) );
	}

	public function test_non_utf8_input_never_fatals(): void {
		$rule = Compiled_Route::from_array( $this->route( '/x' ) );
		$this->assertNotNull( $rule );
		$context  = $this->request( '/wp-json/x?a=%FF' );
		$decision = Policy_Decision::cache( $rule, array( 'a' => "\xFF" ), array( 'accept-language' => "\xFF" ) );
		$other    = Policy_Decision::cache( $rule, array( 'a' => "\xFE" ), array() );

		$key = Cache_Key::key( $context, $decision );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $key );
		$this->assertSame( $key, Cache_Key::key( $context, $decision ) );
		$this->assertNotSame( $key, Cache_Key::key( $context, $other ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', Cache_Key::url_hash( $context, array( "\xFF" => "\xFF" ) ) );
	}

	/**
	 * A context of a method for a URI.
	 *
	 * @param string $method Method.
	 * @param string $uri    Request URI.
	 */
	private function context_for( string $method, string $uri = '/wp-json/wp/v2/posts?page=2' ): Request_Context {
		return $this->request( $uri, array( 'server' => array( 'REQUEST_METHOD' => $method ) ) );
	}

	/**
	 * A cacheable decision of the posts rule with body fields.
	 *
	 * @param string|null $body_class Body class.
	 * @param string|null $body_hash  Body hash.
	 */
	private function decision_with_body( ?string $body_class = null, ?string $body_hash = null, ?string $host = null ): Policy_Decision {
		$rule = Compiled_Route::from_array( $this->route( '/wp/v2/posts' ) );
		$this->assertNotNull( $rule );
		return Policy_Decision::cache( $rule, array( 'page' => '2' ), array( 'accept-language' => 'nl' ), $body_class, $body_hash, $host );
	}

	public function test_get_key_and_url_hash_are_pinned(): void {
		$route    = $this->route( '/wp/v2/posts', array( 'vary_headers' => array( 'accept-language' ) ) );
		$context  = $this->request( '/wp-json/wp/v2/posts?page=2', array( 'server' => array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) ) );
		$decision = $this->policy()->evaluate( $context, $this->config( array( $route ) ) );
		$this->assertCacheable( $decision );

		$json = '{"v":1,"blog":1,"scheme":"http","method":"GET","route":"/wp/v2/posts","params":{"page":"2"},"vary":{"accept-language":"1843653496800edfd0d30326c82f53b0338ed408468cca4a2f1b52f2f6395fc9"}}';
		$this->assertSame( hash( 'sha256', $json ), Cache_Key::key( $context, $decision ) );
		$this->assertSame( '60dca662556c3f01d06c1940f9db8fdaf816acee332599c55a8d750c3ee1c52f', Cache_Key::key( $context, $decision ) );
		$this->assertSame( 'ff1c2d84a1e7181cf35bacafd02df80ca7c85f880df42b10e39160e0c28f8bd4', Cache_Key::url_hash( $context, $decision->key_params ) );
		$this->assertSame(
			array(
				'route'  => '/wp/v2/posts',
				'params' => array( 'page' => '2' ),
				'vary'   => array( 'accept-language' => '184365349680' ),
			),
			Cache_Key::display( $context, $decision )
		);
	}

	/**
	 * Assert that a key is the pinned digest of the JSON structure documented next to it.
	 *
	 * The structure tests of this class recompute their expectation with `json_encode()`, so they would follow a key
	 * builder that reorders a member or adds one. A literal digest does not: it is what a stored entry was keyed by,
	 * and the member order of each builder is part of it (`key()` and `replaced()` differ, on purpose).
	 *
	 * @param string $json   The JSON the pinned digest is the sha256 of.
	 * @param string $digest Pinned digest, a literal.
	 * @param string $actual Key built by the code under test.
	 */
	private function assert_pinned( string $json, string $digest, string $actual ): void {
		$this->assertSame( $digest, hash( 'sha256', $json ), 'The pin is the digest of its documented structure.' );
		$this->assertSame( $digest, $actual, 'The builder keeps the pinned member order.' );
	}

	public function test_a_non_get_key_is_pinned(): void {
		$this->assert_pinned(
			'{"v":1,"blog":1,"scheme":"http","method":"POST","route":"/wp/v2/posts","params":{"page":"2"},"vary":{"accept-language":"1843653496800edfd0d30326c82f53b0338ed408468cca4a2f1b52f2f6395fc9"},"content_type":"json","body":"fb1a5cddbfeede9fd0da980dff08fa90af8ff767bb1f59219e2146550509c7b8"}',
			'ac603c44b83c1b12ecacd873864e7e0368f768242e2abefd307530d916d3b415',
			Cache_Key::key( $this->context_for( 'POST' ), $this->decision_with_body( 'json', hash( 'sha256', 'canonical body' ) ) )
		);
	}

	public function test_a_get_key_with_a_foreign_host_is_pinned(): void {
		$this->assert_pinned(
			'{"v":1,"blog":1,"scheme":"http","method":"GET","route":"/wp/v2/posts","params":{"page":"2"},"vary":{"accept-language":"1843653496800edfd0d30326c82f53b0338ed408468cca4a2f1b52f2f6395fc9"},"host":"evil.example"}',
			'7426b2f97ba7f02d478a6ae4f9f75ac14372b9da1683ae5c289c3f65c10efe2c',
			Cache_Key::key( $this->context_for( 'GET' ), $this->decision_with_body( null, null, 'evil.example' ) )
		);
	}

	public function test_a_non_get_key_with_a_foreign_host_is_pinned(): void {
		$this->assert_pinned(
			'{"v":1,"blog":1,"scheme":"http","method":"POST","route":"/wp/v2/posts","params":{"page":"2"},"vary":{"accept-language":"1843653496800edfd0d30326c82f53b0338ed408468cca4a2f1b52f2f6395fc9"},"content_type":"json","body":"fb1a5cddbfeede9fd0da980dff08fa90af8ff767bb1f59219e2146550509c7b8","host":"evil.example"}',
			'843d13c321acf88c9f1aeaa69cb550dc621ae84c7d41f36d8673ab761553f9ed',
			Cache_Key::key( $this->context_for( 'POST' ), $this->decision_with_body( 'json', hash( 'sha256', 'canonical body' ), 'evil.example' ) )
		);
	}

	/**
	 * A cacheable decision of the posts rule with no vary entries and no key parameters: the most common key shape.
	 */
	private function decision_without_vary_or_params(): Policy_Decision {
		$rule = Compiled_Route::from_array( $this->route( '/wp/v2/posts' ) );
		$this->assertNotNull( $rule );
		return Policy_Decision::cache( $rule, array(), array() );
	}

	public function test_a_get_key_without_vary_or_params_is_pinned(): void {
		// Empty vary and params stay JSON lists ("vary":[] and "params":[]); an object there would change every such key.
		$this->assert_pinned(
			'{"v":1,"blog":1,"scheme":"http","method":"GET","route":"/wp/v2/posts","params":[],"vary":[]}',
			'4f0869fb6d5122310f889df43c6327753be148d9fe54a682967dd93467e3a9bc',
			Cache_Key::key( $this->context_for( 'GET', '/wp-json/wp/v2/posts' ), $this->decision_without_vary_or_params() )
		);
	}

	public function test_a_get_replaced_key_without_vary_is_pinned(): void {
		// A replaced key carries no params member; its empty vary stays the JSON list "vary":[].
		$this->assert_pinned(
			'{"v":1,"legacy":"fe81f9390ffe3dc8daa4888a7dc430ce4a41f164281d15449a24840a70b5f2c2","blog":1,"scheme":"http","vary":[]}',
			'9dcf5fad17244e326d27135dd8ca7ac73ee5c4ef26a0d304a0fd18c2db825ac2',
			Cache_Key::replaced( 'prices', $this->context_for( 'GET', '/wp-json/wp/v2/posts' ), $this->decision_without_vary_or_params() )
		);
	}

	public function test_a_get_replaced_key_is_pinned(): void {
		$this->assert_pinned(
			'{"v":1,"legacy":"fe81f9390ffe3dc8daa4888a7dc430ce4a41f164281d15449a24840a70b5f2c2","blog":1,"scheme":"http","vary":{"accept-language":"1843653496800edfd0d30326c82f53b0338ed408468cca4a2f1b52f2f6395fc9","cookie:cur":"57d4846cecee3fddcb443137723fd1b46d56e64331634ef3c922b72e57f3388e"}}',
			'8951a54cc73de0f9ebd21c3088d59555ac11d53004b2c5820c81280314a2fb2a',
			Cache_Key::replaced( 'prices', $this->context_for( 'GET' ), $this->decision_with_vary( array( 'cookie:cur' => 'EUR', 'accept-language' => 'nl' ) ) )
		);
	}

	public function test_a_non_get_replaced_key_is_pinned(): void {
		$this->assert_pinned(
			'{"v":1,"legacy":"fe81f9390ffe3dc8daa4888a7dc430ce4a41f164281d15449a24840a70b5f2c2","blog":1,"scheme":"http","vary":{"cookie:cur":"57d4846cecee3fddcb443137723fd1b46d56e64331634ef3c922b72e57f3388e"},"method":"POST","content_type":"json","body":"fb1a5cddbfeede9fd0da980dff08fa90af8ff767bb1f59219e2146550509c7b8"}',
			'c92990291ed4f5d1431f3a00f55a5b18054da2d983a153fd2dbd508ec59186be',
			Cache_Key::replaced( 'prices', $this->context_for( 'POST' ), $this->decision_with_vary( array( 'cookie:cur' => 'EUR' ), 'json', hash( 'sha256', 'canonical body' ) ) )
		);
	}

	public function test_a_get_replaced_key_with_a_foreign_host_is_pinned(): void {
		$this->assert_pinned(
			'{"v":1,"legacy":"fe81f9390ffe3dc8daa4888a7dc430ce4a41f164281d15449a24840a70b5f2c2","blog":1,"scheme":"http","vary":{"accept-language":"1843653496800edfd0d30326c82f53b0338ed408468cca4a2f1b52f2f6395fc9","cookie:cur":"57d4846cecee3fddcb443137723fd1b46d56e64331634ef3c922b72e57f3388e"},"host":"evil.example"}',
			'9436f91e9746819234e2be866712ea511073c176e7c57cd3dcb4fd591b2689ef',
			Cache_Key::replaced( 'prices', $this->context_for( 'GET' ), $this->decision_with_vary( array( 'cookie:cur' => 'EUR', 'accept-language' => 'nl' ), null, null, 'evil.example' ) )
		);
	}

	public function test_a_non_get_replaced_key_with_a_foreign_host_is_pinned(): void {
		$this->assert_pinned(
			'{"v":1,"legacy":"fe81f9390ffe3dc8daa4888a7dc430ce4a41f164281d15449a24840a70b5f2c2","blog":1,"scheme":"http","vary":{"cookie:cur":"57d4846cecee3fddcb443137723fd1b46d56e64331634ef3c922b72e57f3388e"},"method":"POST","content_type":"json","body":"fb1a5cddbfeede9fd0da980dff08fa90af8ff767bb1f59219e2146550509c7b8","host":"evil.example"}',
			'61110d9038dba7713fa4f5a1dcdf9284c4ca4dc94cf435b82af75eddc12797c1',
			Cache_Key::replaced( 'prices', $this->context_for( 'POST' ), $this->decision_with_vary( array( 'cookie:cur' => 'EUR' ), 'json', hash( 'sha256', 'canonical body' ), 'evil.example' ) )
		);
	}

	public function test_the_display_of_a_non_get_decision_with_a_foreign_host_is_pinned(): void {
		$this->assertSame(
			array(
				'route'  => '/wp/v2/posts',
				'params' => array( 'page' => '2' ),
				'vary'   => array( 'accept-language' => '184365349680' ),
				'body'   => 'fb1a5cddbfee',
				'host'   => 'evil.example',
			),
			Cache_Key::display( $this->context_for( 'POST' ), $this->decision_with_body( 'json', hash( 'sha256', 'canonical body' ), 'evil.example' ) )
		);
	}

	public function test_a_get_decision_ignores_body_fields(): void {
		$context = $this->context_for( 'GET' );
		$this->assertSame(
			Cache_Key::key( $context, $this->decision_with_body() ),
			Cache_Key::key( $context, $this->decision_with_body( 'json', str_repeat( 'a', 64 ) ) )
		);
	}

	public function test_a_non_get_key_hashes_the_documented_structure(): void {
		$context  = $this->context_for( 'POST' );
		$body     = hash( 'sha256', 'canonical body' );
		$decision = $this->decision_with_body( 'json', $body );

		$expected = hash(
			'sha256',
			(string) json_encode(
				array(
					'v'            => 1,
					'blog'         => 1,
					'scheme'       => 'http',
					'method'       => 'POST',
					'route'        => '/wp/v2/posts',
					'params'       => array( 'page' => '2' ),
					'vary'         => array( 'accept-language' => hash( 'sha256', 'nl' ) ),
					'content_type' => 'json',
					'body'         => $body,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
		$this->assertSame( $expected, Cache_Key::key( $context, $decision ) );
	}

	public function test_a_non_get_key_differs_from_the_get_key_and_per_method_body_class_and_body_hash(): void {
		$body = hash( 'sha256', 'a' );
		$post = Cache_Key::key( $this->context_for( 'POST' ), $this->decision_with_body( 'json', $body ) );

		$this->assertNotSame( Cache_Key::key( $this->context_for( 'GET' ), $this->decision_with_body() ), $post );
		$this->assertNotSame( $post, Cache_Key::key( $this->context_for( 'POST' ), $this->decision_with_body( 'json', hash( 'sha256', 'b' ) ) ), 'Only the body hash differs.' );
		$this->assertNotSame( $post, Cache_Key::key( $this->context_for( 'POST' ), $this->decision_with_body( 'form', $body ) ), 'Only the body class differs.' );
		$this->assertNotSame( $post, Cache_Key::key( $this->context_for( 'PUT' ), $this->decision_with_body( 'json', $body ) ), 'Only the method differs.' );
		$this->assertSame( $post, Cache_Key::key( $this->context_for( 'POST' ), $this->decision_with_body( 'json', $body ) ) );
	}

	public function test_a_non_get_decision_without_a_body_hash_throws(): void {
		$this->expectException( \LogicException::class );
		Cache_Key::key( $this->context_for( 'POST' ), $this->decision_with_body( 'json' ) );
	}

	public function test_url_hash_is_method_free(): void {
		$get  = $this->context_for( 'GET' );
		$post = $this->context_for( 'POST' );
		$this->assertSame( Cache_Key::url_hash( $get, array( 'page' => '2' ) ), Cache_Key::url_hash( $post, array( 'page' => '2' ) ) );
	}

	public function test_display_of_a_non_get_decision_holds_the_short_body_hash_and_no_body_text(): void {
		$body    = hash( 'sha256', 'secret body text' );
		$context = $this->context_for( 'POST' );
		$display = Cache_Key::display( $context, $this->decision_with_body( 'json', $body ) );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $display['body'] ?? '' );
		$this->assertSame( substr( $body, 0, 12 ), $display['body'] ?? null );
		$this->assertStringNotContainsString( 'secret body text', (string) wp_json_encode( $display ) );
		$this->assertArrayNotHasKey( 'body', Cache_Key::display( $this->context_for( 'GET' ), $this->decision_with_body() ) );
	}

	/**
	 * A cacheable decision of the posts rule with a vary map.
	 *
	 * @param array<string,string> $vary       Vary entry => raw value.
	 * @param string|null          $body_class Body class.
	 * @param string|null          $body_hash  Body hash.
	 * @param string|null          $host       Request host that differs from the home host; null for none.
	 */
	private function decision_with_vary( array $vary, ?string $body_class = null, ?string $body_hash = null, ?string $host = null ): Policy_Decision {
		$rule = Compiled_Route::from_array( $this->route( '/wp/v2/posts' ) );
		$this->assertNotNull( $rule );
		return Policy_Decision::cache( $rule, array( 'page' => '2' ), $vary, $body_class, $body_hash, $host );
	}

	public function test_replaced_hashes_the_documented_structure_of_a_get(): void {
		$context  = $this->context_for( 'GET' );
		$decision = $this->decision_with_vary( array( 'cookie:cur' => 'EUR', 'accept-language' => 'nl' ) );

		$expected = hash(
			'sha256',
			(string) json_encode(
				array(
					'v'      => 1,
					'legacy' => hash( 'sha256', 'prices' ),
					'blog'   => 1,
					'scheme' => 'http',
					'vary'   => array(
						'accept-language' => hash( 'sha256', 'nl' ),
						'cookie:cur'      => hash( 'sha256', 'EUR' ),
					),
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
		$this->assertSame( $expected, Cache_Key::replaced( 'prices', $context, $decision ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', Cache_Key::replaced( 'prices', $context, $decision ) );
	}

	public function test_replaced_hashes_the_documented_structure_of_a_non_get(): void {
		$context  = $this->context_for( 'POST' );
		$body     = hash( 'sha256', 'canonical body' );
		$decision = $this->decision_with_vary( array( 'cookie:cur' => 'EUR' ), 'json', $body );

		$expected = hash(
			'sha256',
			(string) json_encode(
				array(
					'v'            => 1,
					'legacy'       => hash( 'sha256', 'prices' ),
					'blog'         => 1,
					'scheme'       => 'http',
					'vary'         => array( 'cookie:cur' => hash( 'sha256', 'EUR' ) ),
					'method'       => 'POST',
					'content_type' => 'json',
					'body'         => $body,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
		$this->assertSame( $expected, Cache_Key::replaced( 'prices', $context, $decision ) );
	}

	public function test_replaced_is_independent_of_the_vary_order_and_of_the_route_and_params_of_the_decision(): void {
		$context = $this->context_for( 'GET' );
		$rule    = Compiled_Route::from_array( $this->route( '/wp/v2/pages' ) );
		$this->assertNotNull( $rule );

		$one = Cache_Key::replaced( 'x', $context, $this->decision_with_vary( array( 'a' => '1', 'b' => '2' ) ) );
		$two = Cache_Key::replaced( 'x', $context, Policy_Decision::cache( $rule, array( 'other' => '9' ), array( 'b' => '2', 'a' => '1' ) ) );

		$this->assertSame( $one, $two, 'The replacement stands in for the route and the parameters.' );
	}

	public function test_replaced_differs_per_replacement_blog_scheme_and_vary_value(): void {
		$decision = $this->decision_with_vary( array( 'cookie:cur' => 'EUR', 'accept-language' => 'nl' ) );
		$base     = Cache_Key::replaced( 'x', $this->context_for( 'GET' ), $decision );

		$this->assertSame( $base, Cache_Key::replaced( 'x', $this->context_for( 'GET' ), $decision ) );
		$this->assertNotSame( $base, Cache_Key::replaced( 'y', $this->context_for( 'GET' ), $decision ), 'Only the replacement differs.' );
		$this->assertNotSame(
			$base,
			Cache_Key::replaced( 'x', $this->request( '/wp-json/wp/v2/posts?page=2', array( 'blog_id' => 2 ) ), $decision ),
			'Only the blog differs.'
		);
		$this->assertNotSame(
			$base,
			Cache_Key::replaced( 'x', $this->request( '/wp-json/wp/v2/posts?page=2', array( 'ssl' => true ) ), $decision ),
			'Only the scheme differs.'
		);
		$this->assertNotSame(
			$base,
			Cache_Key::replaced( 'x', $this->context_for( 'GET' ), $this->decision_with_vary( array( 'cookie:cur' => 'USD', 'accept-language' => 'nl' ) ) ),
			'Only a vary cookie value differs.'
		);
		$this->assertNotSame(
			$base,
			Cache_Key::replaced( 'x', $this->context_for( 'GET' ), $this->decision_with_vary( array( 'cookie:cur' => 'EUR', 'accept-language' => 'en' ) ) ),
			'Only a vary header value differs.'
		);
		$this->assertNotSame(
			$base,
			Cache_Key::replaced( 'x', $this->context_for( 'GET' ), $this->decision_with_vary( array( 'cookie:cur' => 'EUR' ) ) ),
			'Only a vary entry is missing.'
		);
	}

	public function test_replaced_differs_per_method_body_class_and_body_hash_and_from_the_get(): void {
		$body = hash( 'sha256', 'a' );
		$post = Cache_Key::replaced( 'x', $this->context_for( 'POST' ), $this->decision_with_vary( array(), 'json', $body ) );

		$this->assertNotSame( Cache_Key::replaced( 'x', $this->context_for( 'GET' ), $this->decision_with_vary( array() ) ), $post, 'A POST is not the GET of its URI.' );
		$this->assertNotSame( $post, Cache_Key::replaced( 'x', $this->context_for( 'POST' ), $this->decision_with_vary( array(), 'json', hash( 'sha256', 'b' ) ) ), 'Only the body hash differs.' );
		$this->assertNotSame( $post, Cache_Key::replaced( 'x', $this->context_for( 'POST' ), $this->decision_with_vary( array(), 'form', $body ) ), 'Only the body class differs.' );
		$this->assertNotSame( $post, Cache_Key::replaced( 'x', $this->context_for( 'PUT' ), $this->decision_with_vary( array(), 'json', $body ) ), 'Only the method differs.' );
		$this->assertSame( $post, Cache_Key::replaced( 'x', $this->context_for( 'POST' ), $this->decision_with_vary( array(), 'json', $body ) ) );
	}

	public function test_replaced_of_a_get_ignores_body_fields(): void {
		$context = $this->context_for( 'GET' );
		$this->assertSame(
			Cache_Key::replaced( 'x', $context, $this->decision_with_vary( array() ) ),
			Cache_Key::replaced( 'x', $context, $this->decision_with_vary( array(), 'json', str_repeat( 'a', 64 ) ) )
		);
	}

	public function test_replaced_is_not_the_engine_key_or_the_hash_of_the_replacement(): void {
		$context  = $this->context_for( 'GET' );
		$decision = $this->decision_with_vary( array( 'accept-language' => 'nl' ) );
		$key      = Cache_Key::key( $context, $decision );

		$this->assertNotSame( $key, Cache_Key::replaced( $key, $context, $decision ) );
		$this->assertNotSame( hash( 'sha256', 'x' ), Cache_Key::replaced( 'x', $context, $decision ) );
	}

	public function test_replaced_of_a_non_get_decision_without_a_body_hash_throws(): void {
		$this->expectException( \LogicException::class );
		Cache_Key::replaced( 'x', $this->context_for( 'POST' ), $this->decision_with_vary( array(), 'json' ) );
	}

	public function test_policy_rejects_non_utf8_before_a_key_is_built(): void {
		$route = $this->route( '/wp/v2/posts', array( 'param_mode' => 'allow_all' ) );
		$this->assertBypass( 'invalid_encoding', $this->evaluate( '/wp-json/wp/v2/posts?a=%FF', $this->config( array( $route ) ) ) );
	}
}
