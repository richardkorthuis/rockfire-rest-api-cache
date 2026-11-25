<?php
/**
 * Security regression tests for the cache policy and cache key (audit findings H1–L4 and GitHub #34).
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Request_Context;

/**
 * @covers \Rockfire\Rest_Api_Cache\Request\Cache_Policy
 * @covers \Rockfire\Rest_Api_Cache\Request\Cache_Key
 */
class Test_Security_Policy extends Rfrac_Policy_Test_Case {

	/**
	 * Credential-bearing requests (H1).
	 *
	 * @return array<string,array{0:string,1:string,2:array<string,mixed>}>
	 */
	public static function h1_requests(): array {
		return array(
			'authorization basic'  => array( '/wp-json/wp/v2/posts', 'credentials', array( 'server' => array( 'HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz' ) ) ),
			'authorization bearer' => array( '/wp-json/wp/v2/posts', 'credentials', array( 'server' => array( 'HTTP_AUTHORIZATION' => 'Bearer abc.def.ghi' ) ) ),
			'redirect auth header' => array( '/wp-json/wp/v2/posts', 'credentials', array( 'server' => array( 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer x' ) ) ),
			'php auth user'        => array( '/wp-json/wp/v2/posts', 'credentials', array( 'server' => array( 'PHP_AUTH_USER' => 'admin' ) ) ),
			'x-wp-nonce'           => array( '/wp-json/wp/v2/posts', 'credentials', array( 'server' => array( 'HTTP_X_WP_NONCE' => 'abc123' ) ) ),
			'_wpnonce get'         => array( '/wp-json/wp/v2/posts?_wpnonce=abc123', 'credentials', array() ),
			'_wpnonce post'        => array( '/wp-json/wp/v2/posts', 'credentials', array( 'post' => array( '_wpnonce' => 'abc123' ) ) ),
			'logged in cookie'     => array( '/wp-json/wp/v2/posts', 'credentials', array( 'cookie' => array( 'wordpress_logged_in_0123abcd' => 'admin|1|x' ) ) ),
			'x-api-key header'     => array( '/wp-json/wp/v2/posts', 'credentials', array( 'server' => array( 'HTTP_X_API_KEY' => 'k' ) ) ),
			'x-auth-token header'  => array( '/wp-json/wp/v2/posts', 'credentials', array( 'server' => array( 'HTTP_X_AUTH_TOKEN' => 't' ) ) ),
			'x-access-token'       => array( '/wp-json/wp/v2/posts', 'credentials', array( 'server' => array( 'HTTP_X_ACCESS_TOKEN' => 't' ) ) ),
			'password get'         => array( '/wp-json/wp/v2/posts?password=secret', 'credentials', array() ),
			'password post'        => array( '/wp-json/wp/v2/posts', 'credentials', array( 'post' => array( 'password' => 'secret' ) ) ),
			'context edit'         => array( '/wp-json/wp/v2/posts?context=edit', 'context', array() ),
			'status draft'         => array( '/wp-json/wp/v2/posts?status=draft', 'status_param', array() ),
			'status array draft'   => array( '/wp-json/wp/v2/posts?status[]=draft', 'status_param', array() ),
			'status array mixed'   => array( '/wp-json/wp/v2/posts?status[]=publish&status[]=draft', 'status_param', array() ),
		);
	}

	/**
	 * @dataProvider h1_requests
	 *
	 * @param string              $uri    Request URI.
	 * @param string              $reason Expected reason.
	 * @param array<string,mixed> $args   Request arguments.
	 */
	public function test_h1_credentials_bypass( string $uri, string $reason, array $args ): void {
		$this->assertBypass( $reason, $this->evaluate( $uri, null, $args ) );
	}

	public function test_h1_query_route_form_also_bypasses(): void {
		$this->assertBypass(
			'credentials',
			$this->evaluate( '/?rest_route=/wp/v2/posts', null, array( 'server' => array( 'HTTP_X_WP_NONCE' => 'abc' ) ) )
		);
		$this->assertBypass( 'context', $this->evaluate( '/?rest_route=/wp/v2/posts&context=edit' ) );
	}

	public function test_custom_credential_header_from_the_config_bypasses(): void {
		$config = $this->config( null, array( 'bypass_headers' => array( 'x-shop-key' ) ) );
		$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'server' => array( 'HTTP_X_SHOP_KEY' => 'k' ) ) ) );
		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'server' => array( 'HTTP_X_SHOP_KEY' => '' ) ) ) );
		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'server' => array( 'HTTP_X_API_KEY' => 'k' ) ) ), 'Removed from the list: no longer a credential.' );
	}

	/**
	 * A context built through `from_globals()` for the given header names, as the early and fallback servers build it.
	 *
	 * @param array<string,string> $headers Request headers by `HTTP_*` server key.
	 * @param string[]             $names   The header names the context is asked to read.
	 *
	 * @return Request_Context
	 */
	private function context_reading( array $headers, array $names ): Request_Context {
		$saved   = array( $_SERVER, $_GET, $_POST, $_COOKIE );
		$_SERVER = array_merge(
			array(
				'REQUEST_METHOD' => 'GET',
				'REQUEST_URI'    => '/?rest_route=/wp/v2/posts',
			),
			$headers
		);
		$_GET    = array( 'rest_route' => '/wp/v2/posts' );
		$_POST   = array();
		$_COOKIE = array();
		try {
			return Request_Context::from_globals( 'wp-json', array(), $names );
		} finally {
			list( $_SERVER, $_GET, $_POST, $_COOKIE ) = $saved;
		}
	}

	public function test_a_credential_header_the_context_did_not_read_bypasses(): void {
		// The lookup read the headers of the earlier snapshot; the request is then evaluated against one recompiled with a new credential header.
		$early      = $this->context_reading( array( 'HTTP_X_NEW_KEY' => 'secret' ), array( 'x-api-key' ) );
		$recompiled = $this->config( null, array( 'bypass_headers' => array( 'x-api-key', 'x-new-key' ) ) );

		$this->assertNull( $early->header( 'x-new-key' ), 'Precondition: the early context did not read the header.' );
		$this->assertFalse( $early->has_read_header( 'x-new-key' ) );
		$this->assertBypass( 'credentials', $this->policy()->evaluate( $early, $recompiled ) );
		$this->assertBypass( 'credentials', $this->policy()->evaluate_for_url( $early, $recompiled ) );
	}

	public function test_a_credential_header_the_context_read_decides_on_its_value(): void {
		$recompiled = $this->config( null, array( 'bypass_headers' => array( 'x-api-key', 'x-new-key' ) ) );
		$names      = array( 'x-api-key', 'x-new-key' );

		$this->assertBypass( 'credentials', $this->policy()->evaluate( $this->context_reading( array( 'HTTP_X_NEW_KEY' => 'secret' ), $names ), $recompiled ) );
		$this->assertCacheable( $this->policy()->evaluate( $this->context_reading( array(), $names ), $recompiled ), 'Read and absent: no credential.' );
		$this->assertCacheable( $this->policy()->evaluate( $this->context_reading( array( 'HTTP_X_NEW_KEY' => '' ), $names ), $recompiled ), 'Read and empty: no credential.' );
	}

	public function test_a_vary_header_the_context_did_not_read_bypasses(): void {
		$early      = $this->context_reading( array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ), array_merge( $this->config()->bypass_headers(), array( 'accept-language' ) ) );
		$recompiled = $this->config( array( $this->route( '/wp/v2/posts', array( 'vary_headers' => array( 'accept-language', 'x-currency' ) ) ) ) );

		$this->assertFalse( $early->has_read_header( 'x-currency' ) );
		$this->assertBypass( 'error', $this->policy()->evaluate( $early, $recompiled ) );
	}

	public function test_a_vary_header_the_context_read_keys_the_entry_even_when_absent(): void {
		$recompiled = $this->config( array( $this->route( '/wp/v2/posts', array( 'vary_headers' => array( 'accept-language', 'x-currency' ) ) ) ) );
		$names      = array_merge( $recompiled->request_header_names(), $recompiled->bypass_headers() );

		$decision = $this->policy()->evaluate( $this->context_reading( array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ), $names ), $recompiled );
		$this->assertCacheable( $decision );
		$this->assertSame(
			array(
				'accept-language' => 'nl',
				'x-currency'      => '',
			),
			$decision->vary
		);
	}

	public function test_a_vary_header_of_another_route_does_not_bypass_this_one(): void {
		$config = $this->config(
			array(
				$this->route( '/wp/v2/posts' ),
				$this->route( '/wp/v2/pages', array( 'vary_headers' => array( 'x-currency' ) ) ),
			)
		);

		$context = $this->context_reading( array(), $config->bypass_headers() );
		$this->assertFalse( $context->has_read_header( 'x-currency' ), 'Precondition: the vary header of the other route is unread.' );
		$this->assertCacheable( $this->policy()->evaluate( $context, $config ) );
	}

	public function test_a_context_built_from_parts_holds_every_header_it_was_given(): void {
		$config = $this->config( array( $this->route( '/wp/v2/posts', array( 'vary_headers' => array( 'x-currency' ) ) ) ), array( 'bypass_headers' => array( 'x-shop-key' ) ) );

		$this->assertTrue( $this->request( '/wp-json/wp/v2/posts' )->has_read_header( 'x-never-sent' ) );
		$decision = $this->evaluate( '/wp-json/wp/v2/posts', $config );
		$this->assertCacheable( $decision );
		$this->assertSame( array( 'x-currency' => '' ), $decision->vary );
	}

	public function test_empty_password_parameter_is_not_a_credential(): void {
		$config = $this->config( array( $this->route( '/wp/v2/posts', array( 'allowed_params' => array( 'password' ) ) ) ) );
		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts?password=', $config ) );
		$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wp/v2/posts?password=x', $config ) );
	}

	public function test_h1_anonymous_request_is_cacheable(): void {
		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts', null, array( 'cookie' => array( 'wordpress_test_cookie' => 'WP Cookie check' ) ) ) );
	}

	public function test_m1_postpass_cookie_bypasses(): void {
		$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wp/v2/posts', null, array( 'cookie' => array( 'wp-postpass_0123abcd' => '$P$hash' ) ) ) );
	}

	public function test_h2_query_string_never_selects_a_route(): void {
		$this->assertBypass( 'denied_route', $this->evaluate( '/wp-json/wc/store/v1/cart?x=/wp/v2/posts' ) );
		$this->assertBypass( 'route_not_enabled', $this->evaluate( '/wp-json/myns/v1/secret?a=/wp/v2/posts' ) );
	}

	public function test_h2_matching_is_anchored(): void {
		$this->assertBypass( 'route_not_enabled', $this->evaluate( '/wp-json/wp/v2/posts.*' ) );
		$this->assertBypass( 'route_not_enabled', $this->evaluate( '/wp-json/wp/v2/postsX' ) );
		$this->assertBypass( 'route_not_enabled', $this->evaluate( '/wp-json/wp/v2/posts/secret' ) );
		$this->assertBypass( 'route_not_enabled', $this->evaluate( '/wp-json/x/wp/v2/posts' ) );
		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts' ) );
	}

	public function test_h2_wildcard_is_escaped_and_segment_aware(): void {
		$config = $this->config( array( $this->wildcard( '/myns/v1.0/*' ) ) );
		$this->assertCacheable( $this->evaluate( '/wp-json/myns/v1.0/items', $config ) );
		$this->assertBypass( 'route_not_enabled', $this->evaluate( '/wp-json/myns/v1x0/items', $config ) );
		$this->assertBypass( 'route_not_enabled', $this->evaluate( '/wp-json/myns/v1.0/items/secret', $config ) );
		$this->assertBypass( 'route_not_enabled', $this->evaluate( '/wp-json/myns/v1.0/', $config ) );
	}

	public function test_h2_runtime_deny_list_beats_a_wildcard_rule(): void {
		$config = $this->config( array( $this->wildcard( '/wc/store/*/*', array( 'param_mode' => 'allow_all' ) ) ) );
		$this->assertSame( 1, preg_match( $config->routes()[0]->regex, '/wc/store/v2/cart' ) );

		$decision = $this->evaluate( '/wp-json/wc/store/v2/cart', $config );
		$this->assertBypass( 'denied_route', $decision );
		$this->assertNull( $decision->rule );
		$this->assertBypass( 'denied_route', $this->evaluate( '/?rest_route=/wc/store/v2/cart', $config ) );
		$this->assertCacheable( $this->evaluate( '/wp-json/wc/store/v2/products', $config ) );
	}

	public function test_h2_runtime_deny_list_beats_a_registered_pattern(): void {
		$config = $this->config( array( $this->route( '/wc/store/v1/(?P<x>[\w-]+)' ) ) );
		$this->assertBypass( 'denied_route', $this->evaluate( '/wp-json/wc/store/v1/cart', $config ) );
		$this->assertBypass( 'denied_route', $this->evaluate( '/wp-json/WC/Store/V1/Checkout', $config ) );
		$this->assertCacheable( $this->evaluate( '/wp-json/wc/store/v1/products', $config ) );

		$users = $this->config( array( $this->route( '/wp/v2/users/(?P<id>[\w]+)' ) ) );
		$this->assertBypass( 'denied_route', $this->evaluate( '/wp-json/wp/v2/users/me', $users ) );
	}

	public function test_h3_unknown_param_bypasses_in_registered_mode(): void {
		$this->assertBypass( 'unknown_param', $this->evaluate( '/wp-json/wp/v2/posts?foo=1' ) );
		$this->assertBypass( 'unknown_param', $this->evaluate( '/wp-json/wp/v2/posts?page=1&foo[]=1' ) );
	}

	public function test_h3_allow_all_is_capped_by_max_params(): void {
		$config = $this->config( array( $this->route( '/wp/v2/posts', array( 'param_mode' => 'allow_all' ) ) ), array( 'max_params' => 3 ) );
		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts?a=1&b=2&c=3', $config ) );
		$this->assertBypass( 'too_many_params', $this->evaluate( '/wp-json/wp/v2/posts?a=1&b=2&c=3&d=4', $config ) );
	}

	public function test_h3_random_params_create_no_new_keys(): void {
		$base = $this->evaluate( '/wp-json/wp/v2/posts?page=1' );
		$this->assertCacheable( $base );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertFalse( $this->evaluate( '/wp-json/wp/v2/posts?page=1&r' . $i . '=' . wp_rand() )->cacheable );
		}
	}

	public function test_m2_foreign_url_param(): void {
		$a = '/wp-json/wp/v2/posts?page=1&x=http://home.test';
		$b = '/wp-json/wp/v2/posts?page=1&x=http://evil.test';
		$this->assertBypass( 'unknown_param', $this->evaluate( $a ) );
		$this->assertBypass( 'unknown_param', $this->evaluate( $b ) );

		$config = $this->config( array( $this->route( '/wp/v2/posts', array( 'param_mode' => 'allow_all' ) ) ) );
		$ctx_a  = $this->request( $a );
		$ctx_b  = $this->request( $b );
		$dec_a  = $this->policy()->evaluate( $ctx_a, $config );
		$dec_b  = $this->policy()->evaluate( $ctx_b, $config );
		$this->assertCacheable( $dec_a );
		$this->assertCacheable( $dec_b );
		$this->assertNotSame( Cache_Key::key( $ctx_a, $dec_a ), Cache_Key::key( $ctx_b, $dec_b ) );
		$this->assertSame( '/wp/v2/posts', Cache_Key::display( $ctx_a, $dec_a )['route'] );
		$this->assertSame( '/wp/v2/posts', Cache_Key::display( $ctx_b, $dec_b )['route'] );
		$this->assertSame( $dec_a->rule, $dec_b->rule );
	}

	public function test_l1_skip_cache_is_ignored_by_default(): void {
		$with    = $this->request( '/wp-json/wp/v2/posts?page=2&skip_cache=1' );
		$without = $this->request( '/wp-json/wp/v2/posts?page=2' );
		$config  = $this->config();
		$this->assertFalse( $config->allow_skip_cache() );

		$dec_with    = $this->policy()->evaluate( $with, $config );
		$dec_without = $this->policy()->evaluate( $without, $config );
		$this->assertCacheable( $dec_with );
		$this->assertSame( array( 'page' => '2' ), $dec_with->key_params );
		$this->assertSame( Cache_Key::key( $without, $dec_without ), Cache_Key::key( $with, $dec_with ) );
	}

	public function test_l1_skip_cache_honoured_only_when_allowed(): void {
		$this->assertBypass( 'skip_cache', $this->evaluate( '/wp-json/wp/v2/posts?skip_cache=1', $this->config( null, array( 'allow_skip_cache' => true ) ) ) );
	}

	public function test_l4_key_is_structured(): void {
		$config  = $this->config( array( $this->route( '/wp/v2/posts', array( 'param_mode' => 'allow_all' ) ) ) );
		$encoded = $this->request( '/wp-json/wp/v2/posts?a=b%26c%3Dd' );
		$split   = $this->request( '/wp-json/wp/v2/posts?a=b&c=d' );
		$this->assertSame( array( 'a' => 'b&c=d' ), $encoded->params );
		$this->assertSame(
			array(
				'a' => 'b',
				'c' => 'd',
			),
			$split->params
		);

		$dec_encoded = $this->policy()->evaluate( $encoded, $config );
		$dec_split   = $this->policy()->evaluate( $split, $config );
		$this->assertNotSame( Cache_Key::key( $encoded, $dec_encoded ), Cache_Key::key( $split, $dec_split ) );
	}

	public function test_gh34_path_and_rest_route_forms_share_a_key(): void {
		$config = $this->config();
		$path   = $this->request( '/wp-json/wp/v2/posts?page=2' );
		$query  = $this->request( '/?rest_route=/wp/v2/posts&page=2' );
		$plain  = $this->request( '/index.php?rest_route=%2Fwp%2Fv2%2Fposts%2F&page=2', array( 'pretty' => false ) );

		$key = Cache_Key::key( $path, $this->policy()->evaluate( $path, $config ) );
		$this->assertSame( $key, Cache_Key::key( $query, $this->policy()->evaluate( $query, $config ) ) );
		$this->assertSame( $key, Cache_Key::key( $plain, $this->policy()->evaluate( $plain, $config ) ) );
	}

	public function test_gh34_forms_share_a_key_with_vary_header(): void {
		$config = $this->config( array( $this->route( '/wp/v2/posts', array( 'vary_headers' => array( 'accept-language' ) ) ) ) );
		$server = array( 'server' => array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) );
		$path   = $this->request( '/wp-json/wp/v2/posts?page=2', $server );
		$query  = $this->request( '/?rest_route=/wp/v2/posts&page=2', $server );
		$other  = $this->request( '/wp-json/wp/v2/posts?page=2', array( 'server' => array( 'HTTP_ACCEPT_LANGUAGE' => 'en' ) ) );

		$key = Cache_Key::key( $path, $this->policy()->evaluate( $path, $config ) );
		$this->assertSame( $key, Cache_Key::key( $query, $this->policy()->evaluate( $query, $config ) ) );
		$this->assertNotSame( $key, Cache_Key::key( $other, $this->policy()->evaluate( $other, $config ) ) );
	}

	public function test_gh34_keys_differ_per_blog(): void {
		$config = $this->config();
		$one    = $this->request( '/wp-json/wp/v2/posts', array( 'blog_id' => 1 ) );
		$two    = $this->request( '/wp-json/wp/v2/posts', array( 'blog_id' => 2 ) );
		$this->assertNotSame(
			Cache_Key::key( $one, $this->policy()->evaluate( $one, $config ) ),
			Cache_Key::key( $two, $this->policy()->evaluate( $two, $config ) )
		);
		$this->assertNotSame( Cache_Key::url_hash( $one, array() ), Cache_Key::url_hash( $two, array() ) );
	}
}
