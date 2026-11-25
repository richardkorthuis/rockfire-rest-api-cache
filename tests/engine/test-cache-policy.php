<?php
/**
 * Tests for the cache policy rules (request and store phase).
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Route;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Cache_Policy;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;

/**
 * @covers \Rockfire\Rest_Api_Cache\Request\Cache_Policy
 * @covers \Rockfire\Rest_Api_Cache\Request\Policy_Decision
 * @covers \Rockfire\Rest_Api_Cache\Request\Bypass_Reason
 */
class Test_Cache_Policy extends Rfrac_Policy_Test_Case {

	public function test_plain_request_is_cacheable(): void {
		$decision = $this->evaluate( '/wp-json/wp/v2/posts?page=2' );
		$this->assertCacheable( $decision );
		$this->assertSame( '/wp/v2/posts', $decision->rule->key );
		$this->assertSame( array( 'page' => '2' ), $decision->key_params );
		$this->assertSame( array(), $decision->vary );
	}

	public function test_disabled(): void {
		$this->assertBypass( 'disabled', $this->evaluate( '/wp-json/wp/v2/posts', $this->config( null, array( 'enabled' => false ) ) ) );
	}

	public function test_coexistence(): void {
		$decision = $this->policy( true )->evaluate( $this->request( '/wp-json/wp/v2/posts' ), $this->config() );
		$this->assertBypass( 'coexistence', $decision );
	}

	public function test_coexistence_guard_is_not_called_when_disabled(): void {
		$called = false;
		$policy = new Cache_Policy(
			static function () use ( &$called ): bool {
				$called = true;
				return false;
			}
		);
		$decision = $policy->evaluate( $this->request( '/wp-json/wp/v2/posts' ), $this->config( null, array( 'enabled' => false ) ) );
		$this->assertBypass( 'disabled', $decision );
		$this->assertFalse( $called );
	}

	public function test_not_rest(): void {
		$this->assertBypass( 'not_rest', $this->evaluate( '/blog/hello-world/' ) );
		$this->assertBypass( 'not_rest', $this->evaluate( '/wp-json/wp/v2/posts', null, array( 'pretty' => false ) ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function methods(): array {
		return array(
			'post'    => array( 'POST' ),
			'head'    => array( 'HEAD' ),
			'put'     => array( 'PUT' ),
			'delete'  => array( 'DELETE' ),
			'options' => array( 'OPTIONS' ),
		);
	}

	/**
	 * @dataProvider methods
	 */
	public function test_non_get_method( string $method ): void {
		$this->assertBypass( 'method', $this->evaluate( '/wp-json/wp/v2/posts', null, array( 'server' => array( 'REQUEST_METHOD' => $method ) ) ) );
	}

	public function test_lower_case_get_is_get(): void {
		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts', null, array( 'server' => array( 'REQUEST_METHOD' => 'get' ) ) ) );
	}

	public function test_first_failure_wins(): void {
		$config = $this->config( null, array( 'enabled' => false ) );
		$args   = array( 'server' => array( 'REQUEST_METHOD' => 'POST' ) );
		$this->assertBypass( 'disabled', $this->evaluate( '/wp-json/wc/store/v1/cart?_jsonp=x', $config, $args ) );
		$this->assertBypass( 'method_override', $this->evaluate( '/wp-json/wc/store/v1/cart?context=edit', null, array( 'server' => array( 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'GET', 'HTTP_AUTHORIZATION' => 'Bearer x' ) ) ) );
		$this->assertBypass( 'denied_route', $this->evaluate( '/wp-json/wc/store/v1/cart?_jsonp=x', null, $args ) );
		$this->assertBypass( 'method', $this->evaluate( '/wp-json/wp/v2/posts?_jsonp=x', null, $args ) );
		$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wc/store/v1/cart?context=edit', null, array( 'cookie' => array( 'wordpress_logged_in_x' => '1' ) ) ) );
		$this->assertBypass( 'context', $this->evaluate( '/wp-json/wc/store/v1/cart?context=edit' ) );
		$this->assertBypass( 'denied_route', $this->evaluate( '/wp-json/wc/store/v1/cart?_jsonp=x' ) );
		$this->assertBypass( 'route_not_enabled', $this->evaluate( '/wp-json/myns/v1/x?_jsonp=x' ) );
	}

	public function test_bypass_all_bypasses_every_request_as_filtered(): void {
		$config = $this->config( null, array( 'bypass_all' => true ) );

		$this->assertTrue( $config->bypass_all() );
		$this->assertBypass( 'filtered', $this->evaluate( '/wp-json/wp/v2/posts', $config ) );
		$this->assertBypass( 'filtered', $this->evaluate( '/wp-json/wp/v2/posts?page=2', $config ) );
		$this->assertBypass( 'filtered', $this->evaluate( '/wp-json/wp/v2/posts?context=edit', $config ) );
		$this->assertBypass( 'filtered', $this->evaluate( '/wp-json/myns/v1/x', $config ) );
		$this->assertBypass( 'filtered', $this->evaluate( '/wp-json/wc/store/v1/cart', $config ) );
	}

	public function test_bypass_all_keeps_the_rules_that_come_before_the_credential_checks(): void {
		$all = array( 'bypass_all' => true );

		$this->assertBypass( 'disabled', $this->evaluate( '/wp-json/wp/v2/posts', $this->config( null, array_merge( $all, array( 'enabled' => false ) ) ) ) );
		$this->assertBypass( 'not_rest', $this->evaluate( '/blog/hello-world/', $this->config( null, $all ) ) );
		$this->assertBypass( 'method_override', $this->evaluate( '/wp-json/wp/v2/posts', $this->config( null, $all ), array( 'server' => array( 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'GET' ) ) ) );
		$this->assertBypass( 'coexistence', $this->policy( true )->evaluate( $this->request( '/wp-json/wp/v2/posts' ), $this->config( null, $all ) ) );
		// The method check comes after the route match, so after the flag.
		$this->assertBypass( 'filtered', $this->evaluate( '/wp-json/wp/v2/posts', $this->config( null, $all ), array( 'server' => array( 'REQUEST_METHOD' => 'POST' ) ) ) );
	}

	public function test_bypass_all_reports_credentials_first(): void {
		$config = $this->config( null, array( 'bypass_all' => true ) );

		$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'server' => array( 'HTTP_AUTHORIZATION' => 'Bearer abc.def.ghi' ) ) ) );
		$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'cookie' => array( 'wordpress_logged_in_x' => '1' ) ) ) );
		$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'server' => array( 'HTTP_X_API_KEY' => 'k' ) ) ) );
	}

	public function test_a_snapshot_without_bypass_all_bypasses_nothing_extra(): void {
		$this->assertFalse( $this->config()->bypass_all() );
		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts' ) );
		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts', $this->config( null, array( 'bypass_all' => false ) ) ) );
	}

	public function test_configured_bypass_cookie_prefix(): void {
		$config = $this->config( null, array( 'bypass_cookie_prefixes' => array( 'my_session_' ) ) );
		$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'cookie' => array( 'my_session_abc' => '1' ) ) ) );
		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'cookie' => array( 'other' => '1' ) ) ) );
	}

	public function test_login_and_postpass_cookies_bypass_even_without_configured_prefixes(): void {
		$config = $this->config( null, array( 'bypass_cookie_prefixes' => array() ) );
		$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'cookie' => array( 'wordpress_logged_in_abc' => '1' ) ) ) );
		$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'cookie' => array( 'wp-postpass_abc' => '1' ) ) ) );
	}

	/**
	 * The three carriers of a nonce, as request arguments.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	public static function nonce_carriers(): array {
		return array(
			'X-WP-Nonce header' => array( '/wp-json/wp/v2/posts', array( 'server' => array( 'HTTP_X_WP_NONCE' => 'abc123' ) ) ),
			'_wpnonce query'    => array( '/wp-json/wp/v2/posts?_wpnonce=abc123', array() ),
			'_wpnonce body'     => array( '/wp-json/wp/v2/posts', array( 'post' => array( '_wpnonce' => 'abc123' ) ) ),
		);
	}

	/**
	 * @dataProvider nonce_carriers
	 *
	 * @param string              $uri  Request URI.
	 * @param array<string,mixed> $args Request arguments.
	 */
	public function test_a_nonce_bypasses_as_credentials_while_nonce_caching_is_off( string $uri, array $args ): void {
		$this->assertFalse( $this->config()->nonce_caching() );
		$this->assertBypass( 'credentials', $this->evaluate( $uri, null, $args ) );
		$this->assertBypass( 'credentials', $this->evaluate( $uri, $this->config( null, array( 'nonce_caching' => false ) ), $args ) );
	}

	/**
	 * @dataProvider nonce_carriers
	 *
	 * @param string              $uri  Request URI.
	 * @param array<string,mixed> $args Request arguments.
	 */
	public function test_a_request_whose_only_signal_is_a_nonce_is_cacheable_with_nonce_caching_on( string $uri, array $args ): void {
		$config   = $this->config( null, array( 'nonce_caching' => true ) );
		$context  = $this->request( $uri, $args );
		$decision = $this->policy()->evaluate( $context, $config );

		$this->assertNotSame( array(), $context->signals );
		$this->assertCacheable( $decision );
		$this->assertSame( array(), $decision->key_params );
	}

	public function test_credential_signals_drop_only_the_nonce_carriers_with_nonce_caching_on(): void {
		$on  = $this->config( null, array( 'nonce_caching' => true ) );
		$off = $this->config();
		$all = $this->request(
			'/wp-json/wp/v2/posts?_wpnonce=a&password=p',
			array(
				'server' => array(
					'HTTP_AUTHORIZATION' => 'Bearer x',
					'PHP_AUTH_USER'      => 'u',
					'HTTP_X_WP_NONCE'    => 'a',
				),
			)
		);

		$this->assertEqualsCanonicalizing( array( 'authorization', 'php_auth_user', 'x_wp_nonce', 'wpnonce_param', 'password_param' ), Cache_Policy::credential_signals( $all, $off ) );
		$this->assertEqualsCanonicalizing( array( 'authorization', 'php_auth_user', 'password_param' ), Cache_Policy::credential_signals( $all, $on ) );
		$this->assertSame( array(), Cache_Policy::credential_signals( $this->request( '/wp-json/wp/v2/posts' ), $on ) );
		$this->assertSame( array(), Cache_Policy::credential_signals( $this->request( '/wp-json/wp/v2/posts?_wpnonce=a' ), $on ) );
	}

	public function test_the_nonce_parameter_is_left_out_of_the_key_with_nonce_caching_on(): void {
		$config  = $this->config( null, array( 'nonce_caching' => true ) );
		$plain   = $this->evaluate( '/wp-json/wp/v2/posts?page=2', $config );
		$nonced  = $this->evaluate( '/wp-json/wp/v2/posts?_wpnonce=abc&page=2', $config );
		$other   = $this->evaluate( '/wp-json/wp/v2/posts?_wpnonce=def&page=2', $config, array( 'server' => array( 'HTTP_X_WP_NONCE' => 'ghi' ) ) );
		$context = $this->request( '/wp-json/wp/v2/posts?page=2' );

		$this->assertCacheable( $nonced );
		$this->assertSame( array( 'page' => '2' ), $nonced->key_params );
		$this->assertSame( $plain->key_params, $nonced->key_params );
		$this->assertSame(
			Cache_Key::key( $context, $plain ),
			Cache_Key::key( $this->request( '/wp-json/wp/v2/posts?_wpnonce=abc&page=2' ), $nonced )
		);
		$this->assertSame( Cache_Key::key( $context, $plain ), Cache_Key::key( $context, $other ) );
	}

	public function test_the_nonce_parameter_is_no_unknown_parameter_and_does_not_count_with_nonce_caching_on(): void {
		$config = $this->config( null, array( 'nonce_caching' => true, 'max_params' => 1 ) );

		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts?_wpnonce=abc&page=2', $config ) );
		$this->assertBypass( 'unknown_param', $this->evaluate( '/wp-json/wp/v2/posts?_wpnonce=abc&bogus=2', $config ) );
		$this->assertBypass( 'too_many_params', $this->evaluate( '/wp-json/wp/v2/posts?_wpnonce=abc&page=2&per_page=3', $config ) );
	}

	public function test_the_nonce_parameter_is_stripped_before_the_skip_and_bypass_parameter_rules(): void {
		$config = $this->config(
			array( $this->route( '/wp/v2/posts', array( 'bypass_params' => array( '_wpnonce' ) ) ) ),
			array( 'nonce_caching' => true )
		);
		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts?_wpnonce=abc', $config ) );
	}

	public function test_every_other_signal_still_bypasses_a_nonce_with_nonce_caching_on(): void {
		$config = $this->config( null, array( 'nonce_caching' => true ) );
		$nonce  = array( 'HTTP_X_WP_NONCE' => 'abc' );

		$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'server' => $nonce + array( 'HTTP_AUTHORIZATION' => 'Bearer x' ) ) ) );
		$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'server' => $nonce + array( 'PHP_AUTH_USER' => 'u' ) ) ) );
		$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wp/v2/posts?password=p', $config, array( 'server' => $nonce ) ) );
		$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'server' => $nonce + array( 'HTTP_X_API_KEY' => 'k' ) ) ) );
		$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'server' => $nonce, 'cookie' => array( 'wp_woocommerce_session_x' => '1' ) ) ) );
		$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'server' => $nonce, 'cookie' => array( 'wordpress_logged_in_x' => '1' ) ) ) );
		$this->assertBypass( 'context', $this->evaluate( '/wp-json/wp/v2/posts?context=edit', $config, array( 'server' => $nonce ) ) );
	}

	public function test_a_nonce_does_not_hide_the_bypass_all_flag_or_the_other_rules(): void {
		$config = $this->config( null, array( 'nonce_caching' => true, 'bypass_all' => true ) );

		$this->assertBypass( 'filtered', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'server' => array( 'HTTP_X_WP_NONCE' => 'abc' ) ) ) );
	}

	/**
	 * Cookie names that bypass whatever the configured prefixes and the nonce flag are.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function login_cookie_names(): array {
		return array(
			'logged in'         => array( 'wordpress_logged_in_' . str_repeat( 'a', 32 ) ),
			'secure auth'       => array( 'wordpress_sec_' . str_repeat( 'a', 32 ) ),
			'secure auth short' => array( 'wordpress_sec_x' ),
			'post password'     => array( 'wp-postpass_' . str_repeat( 'a', 32 ) ),
			'auth cookie'       => array( 'wordpress_' . str_repeat( '0123456789abcdef', 2 ) ),
		);
	}

	/**
	 * @dataProvider login_cookie_names
	 *
	 * @param string $name Cookie name.
	 */
	public function test_a_login_cookie_bypasses_without_a_configured_prefix_and_whatever_the_nonce_flag( string $name ): void {
		foreach ( array( false, true ) as $nonce_caching ) {
			$config = $this->config( null, array( 'bypass_cookie_prefixes' => array(), 'nonce_caching' => $nonce_caching ) );

			$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'cookie' => array( $name => '1' ) ) ) );
			$this->assertTrue( Cache_Policy::is_unfilterable_login_cookie( $name ) );
		}
	}

	public function test_a_compiled_login_cookie_bypasses_without_a_configured_prefix_and_whatever_the_nonce_flag(): void {
		foreach ( array( false, true ) as $nonce_caching ) {
			$config = $this->config(
				null,
				array(
					'bypass_cookie_prefixes' => array(),
					'nonce_caching'          => $nonce_caching,
					'login_cookies'          => array( 'my_login' ),
				)
			);

			$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'cookie' => array( 'my_login' => '1' ) ) ) );
			$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'cookie' => array( 'my_login_other' => '1', 'other_my_login' => '1' ) ) ) );
		}
	}

	public function test_cookies_that_only_look_like_login_cookies_do_not_bypass(): void {
		$config = $this->config( null, array( 'bypass_cookie_prefixes' => array() ) );

		foreach ( array( 'wordpress_test_cookie', 'wordpress_' . str_repeat( 'a', 31 ), 'wordpress_' . str_repeat( 'a', 33 ), 'wordpress_' . str_repeat( 'A', 32 ), 'wordpress_' . str_repeat( 'g', 32 ), 'xwordpress_' . str_repeat( 'a', 32 ), 'wp-settings-1' ) as $name ) {
			$this->assertFalse( Cache_Policy::is_unfilterable_login_cookie( $name ), $name );
			$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'cookie' => array( $name => '1' ) ) ) );
		}
	}

	public function test_view_and_embed_context_are_cacheable(): void {
		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts?context=view' ) );
		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts?context=embed' ) );
		$this->assertBypass( 'context', $this->evaluate( '/wp-json/wp/v2/posts?context[]=view' ) );
	}

	public function test_status_publish_is_cacheable(): void {
		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts?status=publish' ) );
		$this->assertBypass( 'status_param', $this->evaluate( '/wp-json/wp/v2/posts?status=' ) );
		$this->assertBypass( 'status_param', $this->evaluate( '/wp-json/wp/v2/posts?status[]=publish' ) );
	}

	public function test_route_too_long(): void {
		$long   = '/myns/v1/' . str_repeat( 'a', 191 );
		$config = $this->config( array( $this->wildcard( '/myns/v1/*' ) ) );
		$this->assertBypass( 'route_too_long', $this->evaluate( '/wp-json' . $long, $config ) );

		$exact = '/myns/v1/' . str_repeat( 'a', 191 - strlen( '/myns/v1/' ) );
		$this->assertSame( 191, strlen( $exact ) );
		$this->assertCacheable( $this->evaluate( '/wp-json' . $exact, $config ) );
	}

	public function test_first_matching_negative_entry_wins_over_a_later_enabled_route(): void {
		$config = $this->config(
			array(
				array(
					'key'     => '/myns/v1/private',
					'regex'   => '@^/myns/v1/private$@i',
					'enabled' => false,
				),
				$this->route( '/myns/v1/(?P<id>[\w-]+)' ),
				$this->wildcard( '/myns/v1/*' ),
			)
		);

		$this->assertBypass( 'route_not_enabled', $this->evaluate( '/wp-json/myns/v1/private', $config ) );
		$this->assertCacheable( $this->evaluate( '/wp-json/myns/v1/other', $config ) );
	}

	public function test_route_not_enabled(): void {
		$this->assertBypass( 'route_not_enabled', $this->evaluate( '/wp-json/wp/v2/pages' ) );
		$this->assertBypass( 'route_not_enabled', $this->evaluate( '/wp-json/wp/v2/posts/5' ) );
		$this->assertBypass( 'route_not_enabled', $this->evaluate( '/wp-json/wp/v2/posts', $this->config( array() ) ) );
	}

	public function test_first_matching_rule_wins(): void {
		$config   = $this->config(
			array(
				$this->route( '/wp/v2/posts/(?P<id>[\d]+)', array( 'ttl' => 10 ) ),
				$this->wildcard( '/wp/v2/posts/*', array( 'ttl' => 20 ) ),
			)
		);
		$decision = $this->evaluate( '/wp-json/wp/v2/posts/12', $config );
		$this->assertCacheable( $decision );
		$this->assertSame( 10, $decision->rule->ttl );
		$this->assertSame( 20, $this->evaluate( '/wp-json/wp/v2/posts/abc', $config )->rule->ttl );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function format_params(): array {
		return array(
			'jsonp'    => array( '_jsonp=cb' ),
			'envelope' => array( '_envelope' ),
			'pretty'   => array( '_pretty=1' ),
			'method'   => array( '_method=DELETE' ),
		);
	}

	/**
	 * @dataProvider format_params
	 */
	public function test_format_param( string $query ): void {
		$config   = $this->config( array( $this->route( '/wp/v2/posts', array( 'param_mode' => 'allow_all' ) ) ) );
		$decision = $this->evaluate( '/wp-json/wp/v2/posts?' . $query, $config );
		$this->assertBypass( 'format_param', $decision );
		$this->assertNotNull( $decision->rule );
	}

	public function test_skip_cache_honoured_when_allowed(): void {
		$config = $this->config( null, array( 'allow_skip_cache' => true ) );
		$this->assertBypass( 'skip_cache', $this->evaluate( '/wp-json/wp/v2/posts?skip_cache=1', $config ) );
	}

	public function test_bypass_param(): void {
		$config = $this->config( array( $this->route( '/wp/v2/posts', array( 'bypass_params' => array( 'preview' ) ) ) ) );
		$this->assertBypass( 'bypass_param', $this->evaluate( '/wp-json/wp/v2/posts?preview=1', $config ) );
		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts?page=1', $config ) );
	}

	public function test_bypass_param_wins_over_ignore(): void {
		$config = $this->config(
			array(
				$this->route(
					'/wp/v2/posts',
					array(
						'bypass_params' => array( 'x' ),
						'ignore_params' => array( 'x' ),
					)
				),
			)
		);
		$this->assertBypass( 'bypass_param', $this->evaluate( '/wp-json/wp/v2/posts?x=1', $config ) );
	}

	public function test_ignored_params_are_dropped_from_key_params(): void {
		$config   = $this->config( array( $this->route( '/wp/v2/posts', array( 'ignore_params' => array( 'utm', 'fbclid' ) ) ) ) );
		$decision = $this->evaluate( '/wp-json/wp/v2/posts?page=2&utm=x&fbclid=y', $config );
		$this->assertCacheable( $decision );
		$this->assertSame( array( 'page' => '2' ), $decision->key_params );
	}

	public function test_universal_params_are_allowed_in_registered_mode(): void {
		$config   = $this->config( array( $this->route( '/wp/v2/posts', array( 'allowed_params' => array() ) ) ) );
		$decision = $this->evaluate( '/wp-json/wp/v2/posts?_fields=id&_embed=1&lang=nl', $config );
		$this->assertCacheable( $decision );
		$this->assertSame(
			array(
				'_embed'  => '1',
				'_fields' => 'id',
				'lang'    => 'nl',
			),
			$decision->key_params
		);
	}

	public function test_numeric_param_name_is_unknown(): void {
		$this->assertBypass( 'unknown_param', $this->evaluate( '/wp-json/wp/v2/posts?0=x' ) );
	}

	public function test_too_many_params_registered_mode(): void {
		$config = $this->config( null, array( 'max_params' => 2 ) );
		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts?page=1&per_page=2', $config ) );
		$this->assertBypass( 'too_many_params', $this->evaluate( '/wp-json/wp/v2/posts?page=1&per_page=2&search=x', $config ) );
	}

	public function test_ignored_params_do_not_count(): void {
		$config = $this->config( array( $this->route( '/wp/v2/posts', array( 'ignore_params' => array( 'utm' ) ) ) ), array( 'max_params' => 1 ) );
		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts?page=1&utm=x', $config ) );
	}

	public function test_query_too_long(): void {
		$config = $this->config( array( $this->route( '/wp/v2/posts', array( 'param_mode' => 'allow_all' ) ) ) );
		$this->assertCacheable( $this->evaluate( '/wp-json/wp/v2/posts?a=' . str_repeat( 'x', 2046 ), $config ) );
		$this->assertBypass( 'query_too_long', $this->evaluate( '/wp-json/wp/v2/posts?a=' . str_repeat( 'x', 2047 ), $config ) );
	}

	public function test_invalid_encoding(): void {
		$config = $this->config( array( $this->wildcard( '/myns/v1/*', array( 'param_mode' => 'allow_all' ) ) ) );
		$this->assertBypass( 'invalid_encoding', $this->evaluate( '/wp-json/myns/v1/x?a=%FF', $config ) );
		$this->assertBypass( 'invalid_encoding', $this->evaluate( '/wp-json/myns/v1/x?a[]=%C3%28', $config ) );
		$this->assertBypass( 'invalid_encoding', $this->evaluate( '/wp-json/myns/v1/x?%FF=1', $config ) );
		$this->assertBypass( 'invalid_encoding', $this->evaluate( "/wp-json/myns/v1/\xFF", $config ) );
		// Core dispatches on the raw path, so a percent-encoded byte in the path is plain ASCII.
		$this->assertCacheable( $this->evaluate( '/wp-json/myns/v1/%FF', $config ) );
		$this->assertCacheable( $this->evaluate( '/wp-json/myns/v1/caf%C3%A9?q=%E2%82%AC', $config ) );
	}

	public function test_vary_headers_are_collected_with_empty_default(): void {
		$config   = $this->config( array( $this->route( '/wp/v2/posts', array( 'vary_headers' => array( 'accept-language', 'x-currency' ) ) ) ) );
		$decision = $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'server' => array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) ) );
		$this->assertCacheable( $decision );
		$this->assertSame(
			array(
				'accept-language' => 'nl',
				'x-currency'      => '',
			),
			$decision->vary
		);
	}

	public function test_a_visitor_varying_route_is_never_cacheable(): void {
		$config = $this->config( array( $this->route( '/wp/v2/posts', array( 'visitor_varies' => true ) ) ) );

		$decision = $this->evaluate( '/wp-json/wp/v2/posts?page=2', $config );
		$this->assertBypass( 'visitor_varies', $decision );
		$this->assertSame( '/wp/v2/posts', $decision->rule->key ?? null, 'The rule is attached to the bypass.' );

		$jsonp = $this->evaluate( '/wp-json/wp/v2/posts?_jsonp=cb', $config );
		$this->assertBypass( 'visitor_varies', $jsonp );
		$this->assertNotNull( $jsonp->rule );
	}

	public function test_a_disabled_route_is_reported_before_visitor_varies(): void {
		$config = $this->config( array( $this->route( '/wp/v2/posts', array( 'enabled' => false, 'visitor_varies' => true ) ) ) );
		$this->assertBypass( 'route_not_enabled', $this->evaluate( '/wp-json/wp/v2/posts', $config ) );
	}

	/**
	 * A configuration with one cookie-varying route.
	 *
	 * @param array<string,mixed> $route Route overrides.
	 *
	 * @return \Rockfire\Rest_Api_Cache\Config\Compiled_Config
	 */
	private function cookie_config( array $route = array() ): \Rockfire\Rest_Api_Cache\Config\Compiled_Config {
		return $this->config( array( $this->route( '/wp/v2/posts', array_merge( array( 'vary_cookies' => array( 'cur' => array( 'EUR', 'USD' ) ) ), $route ) ) ) );
	}

	public function test_an_allowed_cookie_value_is_keyed_under_the_reserved_vary_entry(): void {
		$config = $this->cookie_config();
		$args   = array( 'vary_cookie_names' => array( 'cur' ) );

		$usd = $this->evaluate( '/wp-json/wp/v2/posts', $config, $args + array( 'cookie' => array( 'cur' => 'USD' ) ) );
		$this->assertCacheable( $usd );
		$this->assertSame( array( 'cookie:cur' => 'USD' ), $usd->vary );

		$none = $this->evaluate( '/wp-json/wp/v2/posts', $config, $args );
		$this->assertCacheable( $none );
		$this->assertSame( array( 'cookie:cur' => '' ), $none->vary, 'No cookie is the empty value.' );

		$empty = $this->evaluate( '/wp-json/wp/v2/posts', $config, $args + array( 'cookie' => array( 'cur' => '' ) ) );
		$this->assertCacheable( $empty );
		$this->assertSame( array( 'cookie:cur' => '' ), $empty->vary );
	}

	public function test_a_cookie_value_outside_the_allow_list_bypasses(): void {
		$config = $this->cookie_config();
		$args   = array( 'vary_cookie_names' => array( 'cur' ) );

		$gbp = $this->evaluate( '/wp-json/wp/v2/posts', $config, $args + array( 'cookie' => array( 'cur' => 'GBP' ) ) );
		$this->assertBypass( 'vary_cookie', $gbp );
		$this->assertNotNull( $gbp->rule );

		$lower = $this->evaluate( '/wp-json/wp/v2/posts', $config, $args + array( 'cookie' => array( 'cur' => 'usd' ) ) );
		// Values match exactly.
		$this->assertBypass( 'vary_cookie', $lower );

		$array = $this->evaluate( '/wp-json/wp/v2/posts', $config, $args + array( 'cookie' => array( 'cur' => array( 'x' ) ) ) );
		// A cookie sent as an array (cur[]=x) is not a value.
		$this->assertBypass( 'vary_cookie', $array );
	}

	public function test_a_declared_cookie_the_context_did_not_read_bypasses_instead_of_keying_as_empty(): void {
		$config = $this->cookie_config();

		$this->assertBypass( 'vary_cookie', $this->evaluate( '/wp-json/wp/v2/posts', $config ) );
		$this->assertBypass( 'vary_cookie', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'cookie' => array( 'cur' => 'USD' ) ) ) );
		$this->assertBypass( 'vary_cookie', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'vary_cookie_names' => array( 'other' ) ) ) );
	}

	public function test_every_declared_cookie_must_pass(): void {
		$config = $this->cookie_config( array( 'vary_cookies' => array( 'cur' => array( 'EUR' ), 'lang' => array( 'nl', 'en' ) ) ) );
		$args   = array( 'vary_cookie_names' => array( 'cur', 'lang' ) );

		$ok = $this->evaluate( '/wp-json/wp/v2/posts', $config, $args + array( 'cookie' => array( 'cur' => 'EUR', 'lang' => 'nl' ) ) );
		$this->assertCacheable( $ok );
		$this->assertSame( array( 'cookie:cur' => 'EUR', 'cookie:lang' => 'nl' ), $ok->vary );

		$this->assertBypass( 'vary_cookie', $this->evaluate( '/wp-json/wp/v2/posts', $config, $args + array( 'cookie' => array( 'cur' => 'EUR', 'lang' => 'de' ) ) ) );
		$this->assertBypass( 'vary_cookie', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'vary_cookie_names' => array( 'cur' ) ) ) );
	}

	public function test_vary_headers_and_vary_cookies_share_the_vary_map(): void {
		$config   = $this->cookie_config( array( 'vary_headers' => array( 'accept-language' ) ) );
		$decision = $this->evaluate(
			'/wp-json/wp/v2/posts',
			$config,
			array(
				'server'            => array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ),
				'cookie'            => array( 'cur' => 'EUR' ),
				'vary_cookie_names' => array( 'cur' ),
			)
		);
		$this->assertCacheable( $decision );
		$this->assertSame(
			array(
				'accept-language' => 'nl',
				'cookie:cur'      => 'EUR',
			),
			$decision->vary
		);
	}

	public function test_the_cookie_check_runs_after_the_parameter_rules(): void {
		$config = $this->cookie_config();
		$this->assertBypass( 'unknown_param', $this->evaluate( '/wp-json/wp/v2/posts?evil=1', $config, array( 'cookie' => array( 'cur' => 'GBP' ), 'vary_cookie_names' => array( 'cur' ) ) ) );
	}

	public function test_reasons_never_contain_request_data(): void {
		$values = array_map( static fn( Bypass_Reason $r ): string => $r->value, Bypass_Reason::cases() );
		$this->assertCount( 33, $values );
		$this->assertContains( 'method_override', $values );
		$this->assertContains( 'body', $values );
		$this->assertContains( 'no_lookup', $values );
		foreach ( $values as $value ) {
			$this->assertMatchesRegularExpression( '/^[a-z_]+$/', $value );
		}
		$this->assertSame( Bypass_Reason::UNKNOWN_PARAM, $this->evaluate( '/wp-json/wp/v2/posts?evil=<script>' )->reason );
	}

	public function test_evaluate_fires_no_hooks_and_reads_no_options(): void {
		$hooks   = array();
		$counter = static function () use ( &$hooks ) {
			$hooks[] = current_filter();
			return func_num_args() > 0 ? func_get_arg( 0 ) : null;
		};
		$context = $this->request( '/wp-json/wp/v2/posts?page=2', array( 'server' => array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) ) );
		$post    = $this->with_body( 'POST', '{"q":1,"_wpnonce":"a"}' );
		$config  = $this->config( array( $this->route( '/wp/v2/posts', array( 'vary_headers' => array( 'accept-language' ), 'methods' => array( 'GET', 'POST' ) ) ) ), array( 'nonce_caching' => true ) );
		$policy  = $this->policy();

		add_filter( 'all', $counter );
		try {
			$decision = $policy->evaluate( $context, $config );
			$posted   = $policy->evaluate( $post, $config );
			$for_url  = $policy->evaluate_for_url( $context, $config );
			$during   = $hooks;
			get_option( 'blogname' ); // Control: the recorder does see option reads.
		} finally {
			remove_filter( 'all', $counter );
		}

		$this->assertCacheable( $decision );
		$this->assertCacheable( $posted );
		$this->assertCacheable( $for_url );
		$this->assertSame( array(), $during );
		$this->assertNotEmpty( $hooks );
	}

	// Request methods and bodies.

	/**
	 * A configuration whose `/wp/v2/posts` rule caches the given methods.
	 *
	 * @param list<string>        $methods   Methods of the rule.
	 * @param array<string,mixed> $route     Other rule fields.
	 * @param array<string,mixed> $overrides Top-level fields.
	 *
	 * @return \Rockfire\Rest_Api_Cache\Config\Compiled_Config
	 */
	private function opted( array $methods, array $route = array(), array $overrides = array() ): \Rockfire\Rest_Api_Cache\Config\Compiled_Config {
		return $this->config( array( $this->route( '/wp/v2/posts', array_merge( array( 'methods' => $methods ), $route ) ) ), $overrides );
	}

	/**
	 * A request with a body.
	 *
	 * @param string              $method       Method.
	 * @param string|null         $body         Raw body; null sends none.
	 * @param string              $content_type Content-Type; empty sends none.
	 * @param array<string,mixed> $server       Extra server variables.
	 * @param string              $uri          Request URI.
	 *
	 * @return \Rockfire\Rest_Api_Cache\Request\Request_Context
	 */
	private function with_body( string $method, ?string $body, string $content_type = 'application/json', array $server = array(), string $uri = '/wp-json/wp/v2/posts' ): \Rockfire\Rest_Api_Cache\Request\Request_Context {
		$post = array();
		if ( 'POST' === $method && 'application/x-www-form-urlencoded' === $content_type && null !== $body ) {
			parse_str( $body, $post );
		}
		$vars = array( 'REQUEST_METHOD' => $method );
		if ( '' !== $content_type ) {
			$vars['CONTENT_TYPE'] = $content_type;
		}
		return $this->request(
			$uri,
			array(
				'server' => array_merge( $vars, $server ),
				'post'   => $post,
				'body'   => $body,
			)
		);
	}

	/**
	 * The decision for a request with a body.
	 *
	 * @param \Rockfire\Rest_Api_Cache\Config\Compiled_Config $config       Configuration.
	 * @param string                                          $body         Raw body.
	 * @param string                                          $method       Method.
	 * @param string                                          $content_type Content-Type.
	 * @param array<string,mixed>                             $server       Extra server variables.
	 *
	 * @return Policy_Decision
	 */
	private function decide_body( \Rockfire\Rest_Api_Cache\Config\Compiled_Config $config, string $body, string $method = 'POST', string $content_type = 'application/json', array $server = array() ): Policy_Decision {
		return $this->policy()->evaluate( $this->with_body( $method, $body, $content_type, $server ), $config );
	}

	public function test_an_opted_in_post_with_a_json_body_is_cacheable_with_its_body_class_and_hash(): void {
		$decision = $this->decide_body( $this->opted( array( 'GET', 'POST' ) ), '{ "q" : 1 }' );

		$this->assertCacheable( $decision );
		$this->assertSame( 'json', $decision->body_class );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) $decision->body_hash );
		$this->assertSame( hash( 'sha256', '{"q":1}' ), $decision->body_hash, 'The hash of the canonical body.' );
		$this->assertSame( array(), $decision->key_params, 'Body keys never enter the query key parameters.' );
	}

	public function test_a_form_post_is_keyed_by_its_form_class(): void {
		$config = $this->opted( array( 'POST' ) );
		$form   = $this->decide_body( $config, 'q=1', 'POST', 'application/x-www-form-urlencoded' );
		$json   = $this->decide_body( $config, '{"q":"1"}' );
		$none   = $this->policy()->evaluate( $this->with_body( 'POST', null, '' ), $config );

		$this->assertCacheable( $form );
		$this->assertSame( 'form', $form->body_class );
		$this->assertSame( $json->body_hash, $form->body_hash, 'The same canonical body …' );
		$this->assertNotSame( $json->body_class, $form->body_class, '… in another class.' );
		$this->assertCacheable( $none );
		$this->assertSame( 'none', $none->body_class );
		$this->assertSame( hash( 'sha256', '' ), $none->body_hash );
	}

	/**
	 * @return array<string,array{0:string,1:string|null,2:string}>
	 */
	public static function methods_not_opted_in(): array {
		return array(
			'PUT with a body' => array( 'PUT', '{"q":1}', 'application/json' ),
			'PATCH'           => array( 'PATCH', '{"q":1}', 'application/json' ),
			'DELETE'          => array( 'DELETE', null, '' ),
			'HEAD'            => array( 'HEAD', null, '' ),
			'OPTIONS'         => array( 'OPTIONS', null, '' ),
		);
	}

	/**
	 * @dataProvider methods_not_opted_in
	 *
	 * @param string      $method       Method.
	 * @param string|null $body         Body.
	 * @param string      $content_type Content-Type.
	 */
	public function test_a_method_the_route_did_not_opt_in_to_bypasses_as_method( string $method, ?string $body, string $content_type ): void {
		$decision = $this->policy()->evaluate( $this->with_body( $method, $body, $content_type ), $this->opted( array( 'GET', 'POST' ) ) );

		$this->assertBypass( 'method', $decision );
		$this->assertSame( '/wp/v2/posts', $decision->rule->key ?? null, 'The method is checked after the route match.' );
	}

	public function test_a_get_to_an_opted_in_route_is_decided_as_before(): void {
		$uri   = '/wp-json/wp/v2/posts?page=2&context=view';
		$args  = array( 'server' => array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) );
		$route = array( 'vary_headers' => array( 'accept-language' ) );

		$before = $this->evaluate( $uri, $this->config( array( $this->route( '/wp/v2/posts', $route ) ) ), $args );
		$after  = $this->evaluate( $uri, $this->opted( array( 'GET', 'POST' ), $route ), $args );

		$this->assertCacheable( $before );
		$this->assertCacheable( $after );
		$this->assertSame( $before->key_params, $after->key_params );
		$this->assertSame( $before->vary, $after->vary );
		$this->assertNull( $after->body_class );
		$this->assertNull( $after->body_hash );
		$this->assertSame( Cache_Key::key( $this->request( $uri, $args ), $before ), Cache_Key::key( $this->request( $uri, $args ), $after ) );
		$this->assertSame( Cache_Key::display( $this->request( $uri, $args ), $before ), Cache_Key::display( $this->request( $uri, $args ), $after ) );
	}

	public function test_a_get_to_a_post_only_route_bypasses_as_method(): void {
		$decision = $this->evaluate( '/wp-json/wp/v2/posts', $this->opted( array( 'POST' ) ) );

		$this->assertBypass( 'method', $decision );
		$this->assertNotNull( $decision->rule );
		$this->assertCacheable( $this->decide_body( $this->opted( array( 'POST' ) ), '{"q":1}' ) );
	}

	public function test_a_post_to_a_denied_route_is_denied_whatever_its_rule(): void {
		foreach ( array( '/wc/store/v1/cart', '/wp/v2/users/me', '/batch/v1' ) as $route ) {
			$config  = $this->config( array( $this->route( $route, array( 'methods' => array( 'POST' ) ) ) ) );
			$context = $this->with_body( 'POST', '{"q":1}', 'application/json', array(), '/wp-json' . $route );

			$this->assertBypass( 'denied_route', $this->policy()->evaluate( $context, $config ) );
		}
	}

	public function test_credentials_win_over_the_method_check(): void {
		$config = $this->config();

		$this->assertBypass( 'credentials', $this->decide_body( $config, '{"q":1}', 'POST', 'application/json', array( 'HTTP_AUTHORIZATION' => 'Bearer x' ) ) );
		$this->assertBypass( 'credentials', $this->decide_body( $config, '{"password":"x"}' ) );
		$this->assertBypass( 'credentials', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'server' => array( 'REQUEST_METHOD' => 'DELETE' ), 'cookie' => array( 'wordpress_logged_in_x' => '1' ) ) ) );
		$this->assertBypass( 'method', $this->decide_body( $config, '{"q":1}' ), 'Control: without credentials it is the method.' );
	}

	public function test_a_method_override_bypasses_every_method(): void {
		$config = $this->opted( array( 'GET', 'POST', 'PUT' ) );

		foreach ( array( 'DELETE', 'GET', '' ) as $override ) {
			$header = array( 'HTTP_X_HTTP_METHOD_OVERRIDE' => $override );

			$this->assertBypass( 'method_override', $this->evaluate( '/wp-json/wp/v2/posts', $config, array( 'server' => $header ) ), 'GET ' . $override );
			$this->assertBypass( 'method_override', $this->decide_body( $config, '{"q":1}', 'POST', 'application/json', $header ), 'POST ' . $override );
			$this->assertBypass( 'method_override', $this->decide_body( $config, '{"q":1}', 'PUT', 'application/json', $header ), 'PUT ' . $override );
			$this->assertBypass( 'method_override', $this->decide_body( $this->config(), '{"q":1}', 'POST', 'application/json', $header ), 'Not opted in ' . $override );
		}
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string,3:array<string,mixed>}>
	 */
	public static function unusable_bodies(): array {
		return array(
			'multipart'              => array( 'POST', '--x--', 'multipart/form-data; boundary=x', array() ),
			'unsupported'            => array( 'POST', 'hello', 'text/plain', array() ),
			'unsupported, no type'   => array( 'POST', 'hello', '', array() ),
			'invalid json'           => array( 'POST', '{"q":', 'application/json', array() ),
			'json that is no object' => array( 'PUT', '[1,2]', 'application/json', array() ),
			'invalid form'           => array( 'PUT', 'q=%FF', 'application/x-www-form-urlencoded', array() ),
			'too large'              => array( 'POST', '{"q":"' . str_repeat( 'x', 65536 ) . '"}', 'application/json', array() ),
			'declared too large'     => array( 'POST', '{"q":1}', 'application/json', array( 'CONTENT_LENGTH' => '65537' ) ),
			'overflowing number'     => array( 'POST', '{"q":1e400}', 'application/json', array() ),
			'overflowing nested'     => array( 'PUT', '{"n":[{"x":-1e400}],"search":"a"}', 'application/json', array() ),
		);
	}

	/**
	 * @dataProvider unusable_bodies
	 *
	 * @param string              $method       Method.
	 * @param string              $body         Body.
	 * @param string              $content_type Content-Type.
	 * @param array<string,mixed> $server       Extra server variables.
	 */
	public function test_a_body_that_cannot_be_keyed_bypasses_as_body( string $method, string $body, string $content_type, array $server ): void {
		$decision = $this->decide_body( $this->opted( array( 'POST', 'PUT' ) ), $body, $method, $content_type, $server );

		$this->assertBypass( 'body', $decision );
		$this->assertNotNull( $decision->rule );
	}

	public function test_bodies_with_an_overflowing_number_never_share_the_hash_of_an_empty_body(): void {
		$config = $this->opted( array( 'POST' ) );

		foreach ( array( '{"q":1e400}', '{"n":1e400,"search":"a"}', '{"n":1e400,"search":"b"}' ) as $body ) {
			$decision = $this->decide_body( $config, $body );

			$this->assertBypass( 'body', $decision, $body );
			$this->assertNull( $decision->body_hash, $body );
		}
		$this->assertCacheable( $this->decide_body( $config, '{"q":1e300}' ), 'Control: a large finite number is keyable.' );
	}

	public function test_a_big_integer_and_the_same_digits_as_a_string_get_different_body_hashes(): void {
		$config = $this->opted( array( 'POST' ) );
		$number = $this->decide_body( $config, '{"id":12345678901234567890}' );
		$string = $this->decide_body( $config, '{"id":"12345678901234567890"}' );

		$this->assertCacheable( $number );
		$this->assertCacheable( $string );
		$this->assertNotSame( $number->body_hash, $string->body_hash );
	}

	public function test_body_parameters_follow_the_rules_of_the_query(): void {
		$config = $this->opted( array( 'POST' ), array( 'bypass_params' => array( 'preview' ) ), array( 'allow_skip_cache' => true ) );

		$this->assertBypass( 'credentials', $this->decide_body( $config, '{"password":"x"}' ) );
		$this->assertBypass( 'context', $this->decide_body( $config, '{"context":"edit"}' ) );
		$this->assertBypass( 'status_param', $this->decide_body( $config, '{"status":"draft"}' ) );
		$this->assertBypass( 'bypass_param', $this->decide_body( $config, '{"preview":1}' ) );
		$this->assertBypass( 'skip_cache', $this->decide_body( $config, '{"skip_cache":1}' ) );
		$this->assertBypass( 'context', $this->decide_body( $config, 'context=edit', 'POST', 'application/x-www-form-urlencoded' ) );
		$this->assertBypass( 'credentials', $this->decide_body( $config, 'password=x', 'POST', 'application/x-www-form-urlencoded' ) );
		$this->assertBypass( 'context', $this->decide_body( $config, '{"context":1}' ), 'Only the string values are public.' );

		$this->assertCacheable( $this->decide_body( $config, '{"context":"view","status":"publish","q":{"preview":1,"skip_cache":1}}' ), 'Nested keys are not parameters.' );
	}

	public function test_the_query_is_checked_before_the_body_and_query_only_rules_ignore_the_body(): void {
		$config = $this->opted( array( 'POST' ), array(), array( 'max_params' => 1 ) );

		$this->assertBypass( 'context', $this->policy()->evaluate( $this->with_body( 'POST', '{"status":"draft"}', 'application/json', array(), '/wp-json/wp/v2/posts?context=edit' ), $config ) );
		$this->assertBypass( 'format_param', $this->policy()->evaluate( $this->with_body( 'POST', '{"q":1}', 'application/json', array(), '/wp-json/wp/v2/posts?_jsonp=x' ), $config ) );
		$this->assertBypass( 'unknown_param', $this->policy()->evaluate( $this->with_body( 'POST', '{"q":1}', 'application/json', array(), '/wp-json/wp/v2/posts?bogus=1' ), $config ) );
		$this->assertCacheable( $this->decide_body( $config, '{"_jsonp":"x","bogus":1,"a":1,"b":2}' ), 'Format, unknown and count checks are for the query only.' );
	}

	public function test_skip_cache_in_the_body_is_left_out_of_the_hash_while_skipping_is_not_allowed(): void {
		$config = $this->opted( array( 'POST' ) );
		$plain  = $this->decide_body( $config, '{"q":1}' );
		$skip   = $this->decide_body( $config, '{"q":1,"skip_cache":1}' );

		$this->assertCacheable( $skip );
		$this->assertSame( $plain->body_hash, $skip->body_hash );
	}

	public function test_the_nonce_is_left_out_of_the_body_hash_with_nonce_caching_on(): void {
		$on  = $this->opted( array( 'POST' ), array( 'bypass_params' => array( '_wpnonce' ) ), array( 'nonce_caching' => true ) );
		$off = $this->opted( array( 'POST' ) );

		$a = $this->decide_body( $on, '{"_wpnonce":"a","q":1}' );
		$b = $this->decide_body( $on, '{"_wpnonce":"b","q":1}' );
		$this->assertCacheable( $a );
		$this->assertCacheable( $b );
		$this->assertSame( $a->body_hash, $b->body_hash );
		$this->assertSame( $this->decide_body( $on, '{"q":1}' )->body_hash, $a->body_hash );

		$this->assertBypass( 'credentials', $this->decide_body( $off, '{"_wpnonce":"a","q":1}' ) );
		$this->assertBypass( 'credentials', $this->decide_body( $off, '{"_wpnonce":"b","q":1}' ) );
	}

	public function test_ignored_keys_and_key_order_do_not_change_the_body_hash_but_value_types_do(): void {
		$config = $this->opted( array( 'POST' ), array( 'ignore_params' => array( 'utm' ) ) );
		$hash   = fn( string $body ): ?string => $this->decide_body( $config, $body )->body_hash;

		$this->assertSame( $hash( '{"a":1,"b":{"y":2,"x":1}}' ), $hash( '{"b":{"x":1,"y":2},"a":1}' ), 'Key order.' );
		$this->assertSame( $hash( '{"a":1}' ), $hash( '{"a":1,"utm":"x"}' ), 'An ignored key.' );
		$this->assertNotSame( $hash( '{"a":1}' ), $hash( '{"a":"1"}' ), 'A number is not a string.' );
		$this->assertNotSame( $hash( '{"a":[1,2]}' ), $hash( '{"a":[2,1]}' ), 'Lists keep their order.' );
		$this->assertNotSame( $hash( '{"a":{}}' ), $hash( '{"a":[]}' ) );
	}

	public function test_evaluate_for_url_leaves_out_only_the_method_check(): void {
		$config  = $this->opted( array( 'POST' ), array( 'param_mode' => 'allow_all', 'ignore_params' => array( 'a' ) ) );
		$context = $this->request( '/wp-json/wp/v2/posts?a=1&b=2' );

		$this->assertBypass( 'method', $this->policy()->evaluate( $context, $config ) );
		$decision = $this->policy()->evaluate_for_url( $context, $config );
		$this->assertCacheable( $decision );
		$this->assertSame( array( 'b' => '2' ), $decision->key_params );
		$this->assertNull( $decision->body_hash );

		$this->assertBypass( 'context', $this->policy()->evaluate_for_url( $this->request( '/wp-json/wp/v2/posts?context=edit' ), $config ) );
		$this->assertBypass( 'denied_route', $this->policy()->evaluate_for_url( $this->request( '/wp-json/wp/v2/users/me' ), $config ) );
		$this->assertBypass( 'route_not_enabled', $this->policy()->evaluate_for_url( $this->request( '/wp-json/wp/v2/pages' ), $config ) );
	}

	// Store phase.

	/**
	 * A cacheable decision for `/wp/v2/posts`.
	 */
	private function cacheable(): Policy_Decision {
		$decision = $this->evaluate( '/wp-json/wp/v2/posts' );
		$this->assertCacheable( $decision );
		return $decision;
	}

	/**
	 * Store verdict with defaults for everything not overridden.
	 *
	 * @param array<string,mixed> $args Overrides.
	 */
	private function verdict( array $args = array() ): ?Bypass_Reason {
		return $this->policy()->store_verdict(
			$args['decision'] ?? $this->cacheable(),
			$args['user'] ?? 0,
			$args['status'] ?? 200,
			$args['size'] ?? 100,
			$args['headers'] ?? array( 'Content-Type' => 'application/json; charset=UTF-8' ),
			$args['empty'] ?? false,
			$args['tags'] ?? 3,
			$args['entries'] ?? 0,
			$args['config'] ?? $this->config()
		);
	}

	public function test_store_allowed(): void {
		$this->assertNull( $this->verdict() );
	}

	public function test_store_returns_request_reason_for_bypass_decision(): void {
		$this->assertSame( Bypass_Reason::METHOD, $this->verdict( array( 'decision' => Policy_Decision::bypass( Bypass_Reason::METHOD ) ) ) );
	}

	public function test_store_user(): void {
		$this->assertSame( Bypass_Reason::USER, $this->verdict( array( 'user' => 1 ) ) );
	}

	public function test_store_status(): void {
		$this->assertSame( Bypass_Reason::STATUS, $this->verdict( array( 'status' => 404 ) ) );
		$this->assertSame( Bypass_Reason::STATUS, $this->verdict( array( 'status' => 201 ) ) );
	}

	public function test_store_too_large(): void {
		$config = $this->config( null, array( 'max_payload_bytes' => 1000 ) );
		$this->assertNull( $this->verdict( array( 'size' => 1000, 'config' => $config ) ) );
		$this->assertSame( Bypass_Reason::TOO_LARGE, $this->verdict( array( 'size' => 1001, 'config' => $config ) ) );
	}

	public function test_store_empty_unless_filtered(): void {
		$this->assertSame( Bypass_Reason::EMPTY, $this->verdict( array( 'empty' => true ) ) );

		$seen = null;
		$cb   = static function ( $cache, $route_key ) use ( &$seen ) {
			$seen = $route_key;
			return true;
		};
		add_filter( Hooks::CACHE_EMPTY_RESULTS, $cb, 10, 2 );
		try {
			$this->assertNull( $this->verdict( array( 'empty' => true ) ) );
		} finally {
			remove_filter( Hooks::CACHE_EMPTY_RESULTS, $cb, 10 );
		}
		$this->assertSame( '/wp/v2/posts', $seen );
	}

	public function test_store_set_cookie(): void {
		$this->assertSame( Bypass_Reason::SET_COOKIE, $this->verdict( array( 'headers' => array( 'Set-Cookie' => 'a=b' ) ) ) );
		$this->assertSame( Bypass_Reason::SET_COOKIE, $this->verdict( array( 'headers' => array( 'set-cookie' => array( 'a=b', 'c=d' ) ) ) ) );
		$this->assertSame( Bypass_Reason::SET_COOKIE, $this->verdict( array( 'headers' => array( 'X-Foo: 1', 'Set-Cookie: a=b' ) ) ) );
	}

	public function test_store_private(): void {
		$this->assertSame( Bypass_Reason::PRIVATE, $this->verdict( array( 'headers' => array( 'Cache-Control' => 'private, max-age=0' ) ) ) );
		$this->assertSame( Bypass_Reason::PRIVATE, $this->verdict( array( 'headers' => array( 'cache-control' => 'No-Store' ) ) ) );
		$this->assertSame( Bypass_Reason::PRIVATE, $this->verdict( array( 'headers' => array( 'Cache-Control: no-cache, no-store, must-revalidate' ) ) ) );
		$this->assertSame( Bypass_Reason::PRIVATE, $this->verdict( array( 'headers' => array( 'Cache-Control: no-cache, must-revalidate, max-age=0' ) ) ) );
		$this->assertSame( Bypass_Reason::PRIVATE, $this->verdict( array( 'headers' => array( 'Cache-Control' => 'public, max-age=0' ) ) ) );
		$this->assertNull( $this->verdict( array( 'headers' => array( 'Cache-Control' => 'public, max-age=60' ) ) ) );
		$this->assertNull( $this->verdict( array( 'headers' => array( 'Cache-Control' => 'public, max-age=600' ) ) ) );
	}

	public function test_store_too_many_tags(): void {
		$this->assertNull( $this->verdict( array( 'tags' => 5000 ) ) );
		$this->assertSame( Bypass_Reason::TOO_MANY_TAGS, $this->verdict( array( 'tags' => 5001 ) ) );
	}

	public function test_store_entry_ceiling(): void {
		$config = $this->config( null, array( 'max_entries' => 10000 ) );
		$this->assertNull( $this->verdict( array( 'entries' => 14999, 'config' => $config ) ) );
		$this->assertSame( Bypass_Reason::ENTRY_CEILING, $this->verdict( array( 'entries' => 15000, 'config' => $config ) ) );

		// The ceiling rounds up: 1.5 × 101 is 151.5, so 151 entries still fit.
		$config = $this->config( null, array( 'max_entries' => 101 ) );
		$this->assertNull( $this->verdict( array( 'entries' => 151, 'config' => $config ) ) );
		$this->assertSame( Bypass_Reason::ENTRY_CEILING, $this->verdict( array( 'entries' => 152, 'config' => $config ) ) );
	}

	public function test_entry_ceiling_helpers(): void {
		$ceilings = array();
		$floors   = array();
		foreach ( array( 10000, 100, 101 ) as $max_entries ) {
			$config     = $this->config( null, array( 'max_entries' => $max_entries ) );
			$ceilings[] = Cache_Policy::entry_ceiling( $config );
			$floors[]   = Cache_Policy::entry_recount_floor( $config );
		}
		$this->assertSame( array( 15000, 150, 152 ), $ceilings );
		$this->assertSame( 12000, $floors[0] );
		$this->assertSame( 120, $floors[1] );
	}

	public function test_store_first_failure_wins(): void {
		$this->assertSame(
			Bypass_Reason::USER,
			$this->verdict(
				array(
					'user'    => 1,
					'status'  => 500,
					'headers' => array( 'Set-Cookie' => 'a=b' ),
				)
			)
		);
	}

	public function test_bypass_decision_shape(): void {
		$decision = Policy_Decision::bypass( Bypass_Reason::DENIED_ROUTE );
		$this->assertFalse( $decision->cacheable );
		$this->assertNull( $decision->rule );
		$this->assertSame( array(), $decision->key_params );
		$this->assertSame( array(), $decision->vary );

		$rule = Compiled_Route::from_array( $this->route( '/x' ) );
		$this->assertNotNull( $rule );
		$this->assertSame( $rule, Policy_Decision::bypass( Bypass_Reason::BYPASS_PARAM, $rule )->rule );
	}

	/**
	 * Run a callback with the PCRE JIT off and a tiny backtrack limit, so `(a+)+` fails at runtime.
	 *
	 * Skips when the environment does not let the limits be changed.
	 *
	 * @param callable $callback Callback.
	 *
	 * @return mixed What the callback returns.
	 */
	private function with_failing_regex( callable $callback ) {
		$jit       = ini_get( 'pcre.jit' );
		$backtrack = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.jit', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restored below.
		ini_set( 'pcre.backtrack_limit', '10' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restored below.
		try {
			if ( false !== preg_match( '@^/(a+)+$@i', '/' . str_repeat( 'a', 30 ) . '!' ) ) {
				$this->markTestSkipped( 'The PCRE limits can not be lowered in this environment.' );
			}
			return $callback();
		} finally {
			ini_set( 'pcre.jit', (string) $jit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restoring.
			ini_set( 'pcre.backtrack_limit', (string) $backtrack ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restoring.
		}
	}

	/**
	 * Forget that a regex failure was recorded in this request.
	 */
	private function reset_regex_failure_guard(): void {
		$property = new ReflectionProperty( Cache_Policy::class, 'regex_failure_recorded' );
		$property->setAccessible( true );
		$property->setValue( null, false );
	}

	public function test_regex_runtime_error_bypasses_instead_of_falling_through(): void {
		delete_option( Failure_Recorder::OPTION );
		$this->reset_regex_failure_guard();
		$config = $this->config(
			array(
				$this->route( '/other' ),
				$this->route( '/(a+)+', array( 'enabled' => false ) ),
				$this->route( '/(?P<x>.+)' ),
			)
		);

		$decision = $this->with_failing_regex( fn() => $this->evaluate( '/wp-json/' . str_repeat( 'a', 30 ) . '!', $config ) );

		$this->assertBypass( 'error', $decision );
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'policy', $last['context'] );
		$this->assertStringContainsString( '/(a+)+', $last['message'] );
	}

	public function test_a_regex_failure_is_recorded_once_per_request(): void {
		delete_option( Failure_Recorder::OPTION );
		$this->reset_regex_failure_guard();
		$reads = 0;
		add_filter(
			'pre_option_' . Failure_Recorder::OPTION,
			static function ( $value ) use ( &$reads ) {
				++$reads;
				return $value;
			}
		);
		$config = $this->config( array( $this->route( '/(a+)+' ) ) );

		$after_first = 0;
		$decisions   = $this->with_failing_regex(
			function () use ( $config, &$reads, &$after_first ): array {
				$first       = $this->evaluate( '/wp-json/' . str_repeat( 'a', 30 ) . '!', $config );
				$after_first = $reads;
				return array( $first, $this->evaluate( '/wp-json/' . str_repeat( 'a', 31 ) . '!', $config ) );
			}
		);

		$this->assertBypass( 'error', $decisions[0] );
		$this->assertBypass( 'error', $decisions[1] );
		$this->assertGreaterThan( 0, $after_first, 'The first failure is recorded.' );
		$this->assertSame( $after_first, $reads, 'The second evaluation does not touch the last-error option.' );
	}
}
