<?php
/**
 * Tests for the admin redirect (open-redirect hardening) and hostile request shapes.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Admin;
use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Admin\Capability;
use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Admin\Redirect;
use Rockfire\Rest_Api_Cache\Admin\Request;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Admin\Admin_Url
 * @covers \Rockfire\Rest_Api_Cache\Admin\Redirect
 * @covers \Rockfire\Rest_Api_Cache\Admin\Request
 */
class Test_Redirect_And_Request extends Rfrac_Test_Case {

	use Rfrac_Admin_Test_Helpers;

	public function tear_down() {
		$this->reset_admin_request();
		parent::tear_down();
	}

	/**
	 * The redirect of Redirect::safe_and_exit() for a URL.
	 *
	 * @param string $url URL.
	 */
	private function redirect_of( string $url ): Redirect_Intercepted {
		$this->intercept_redirect();
		try {
			Redirect::safe_and_exit( $url );
		} catch ( Redirect_Intercepted $redirect ) {
			return $redirect;
		}
		$this->fail( 'Expected a redirect.' );
	}

	// ---------- Redirect ----------

	public function test_a_same_site_url_is_followed_with_a_303(): void {
		$redirect = $this->redirect_of( Admin_Url::page( 'rfrac-tools' ) );
		$this->assertSame( 303, $redirect->status );
		$this->assertSame( Admin_Url::page( 'rfrac-tools' ), $redirect->location );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function foreign_url_provider(): array {
		return array(
			'other host'         => array( 'https://evil.example/wp-admin/' ),
			'protocol relative'  => array( '//evil.example/x' ),
			'userinfo trick'     => array( 'http://example.org@evil.example/' ),
			'javascript'         => array( 'javascript:alert(1)' ),
			'empty'              => array( '' ),
			'whitespace only'    => array( "  \n" ),
		);
	}

	/**
	 * @dataProvider foreign_url_provider
	 *
	 * @param string $url Hostile or empty URL.
	 */
	public function test_a_foreign_or_empty_url_goes_to_the_dashboard( string $url ): void {
		$redirect = $this->redirect_of( $url );
		$this->assertSame( 303, $redirect->status );
		$this->assertSame( admin_url(), $redirect->location );
	}

	public function test_flush_all_ignores_a_forged_wp_http_referer(): void {
		$this->as_admin();
		$plugin = Plugin::instance();
		( new Admin( new Capability(), new Notices(), $plugin->status_service(), $plugin->cache_service(), array() ) )->register();

		$this->set_request(
			array(),
			array(
				'action'           => 'rfrac_flush_all',
				'_wpnonce'         => wp_create_nonce( 'rfrac_flush_all' ),
				'_wp_http_referer' => 'https://evil.example/wp-admin/',
			)
		);
		$this->intercept_redirect();
		try {
			do_action( 'admin_post_rfrac_flush_all' );
			$this->fail( 'Expected a redirect.' );
		} catch ( Redirect_Intercepted $redirect ) {
			$this->assertSame( Admin_Url::page( Admin::TOOLS_SLUG ), $redirect->location );
		}
	}

	// ---------- Request ----------

	public function test_an_array_given_to_a_scalar_reader_is_the_fallback(): void {
		$this->set_request(
			array(
				'key'  => array( 'a' ),
				'text' => array( 'x' => 'y' ),
				'int'  => array( 5 ),
				'bool' => array( '1' ),
				'url'  => array( 'https://example.org/' ),
			)
		);
		$this->assertSame( 'fallback', Request::key( 'key', 'fallback' ) );
		$this->assertSame( 'fallback', Request::text( 'text', 'fallback' ) );
		$this->assertSame( 7, Request::int( 'int', 7 ) );
		$this->assertTrue( Request::bool( 'bool', true ) );
		$this->assertSame( '', Request::url( 'url' ) );
	}

	public function test_nested_arrays_in_list_readers_are_dropped(): void {
		$this->set_request(
			array(
				'ids'  => array( '3', array( '4' ), '-5', '0', 'abc', '6' ),
				'keys' => array( 'Ok_Key', array( 'nested' ), '<b>x</b>' ),
			)
		);
		$this->assertSame( array( 3, 5, 6 ), Request::int_list( 'ids' ) );
		$this->assertSame( array( 'ok_key', 'x' ), Request::key_list( 'keys' ) );
	}

	public function test_each_reader_sanitises_its_value_where_it_reads_it(): void {
		$this->set_request(
			array(),
			array(
				't'         => '<b>x</b>',
				'rfrac_url' => ' https://a.test/x ',
				'k'         => 'A, b ,a',
				'n'         => array( '3', array( '9' ), '0', '3' ),
			)
		);
		$this->assertSame( 'x', Request::text( 't' ) );
		$this->assertSame( 'https://a.test/x', Request::url( 'rfrac_url' ) );
		$this->assertSame( array( 'a', 'b' ), Request::key_list( 'k' ) );
		$this->assertSame( array( 3 ), Request::int_list( 'n' ) );
	}

	public function test_tags_and_percent_octets_are_stripped_before_the_key_and_int_conversion(): void {
		$this->set_request(
			array(),
			array(
				'keys' => array( '<b>Ab</b>%20c', '%e2%82%ac' ),
				'int'  => '<i>12</i>',
				'gap'  => '%e2%82%ac',
			)
		);
		$this->assertSame( array( 'abc' ), Request::key_list( 'keys' ) );
		$this->assertSame( 12, Request::int( 'int' ) );
		$this->assertSame( 4, Request::int( 'gap', 4 ) );
	}

	public function test_post_wins_over_get_also_when_its_value_is_not_a_scalar(): void {
		$this->set_request( array( 'n' => '5' ), array( 'n' => array( '1' ) ) );
		$this->assertSame( 7, Request::int( 'n', 7 ) );
		$this->assertSame( 'fallback', Request::text( 'n', 'fallback' ) );
		$this->assertSame( array( 1 ), Request::int_list( 'n' ) );
	}

	public function test_a_string_given_to_a_list_reader_is_split_on_its_line_breaks(): void {
		$this->set_request( array(), array( 'methods' => "get,\r\n <b>post</b>\nPUT" ) );
		$this->assertSame( array( 'get', 'post', 'PUT' ), Request::text_list( 'methods' ) );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function non_http_url_provider(): array {
		return array(
			'data'       => array( 'data:text/html,<script>alert(1)</script>' ),
			'ftp'        => array( 'ftp://example.org/file' ),
			'javascript' => array( 'javascript:alert(1)' ),
			'file'       => array( 'file:///etc/passwd' ),
		);
	}

	/**
	 * @dataProvider non_http_url_provider
	 *
	 * @param string $url Non-http(s) URL.
	 */
	public function test_url_accepts_only_http_and_https( string $url ): void {
		$this->set_request( array( 'u' => $url ) );
		$this->assertSame( '', Request::url( 'u' ) );
	}

	public function test_post_wins_over_get_and_values_are_unslashed(): void {
		$this->set_request( array( 'v' => 'get' ), array( 'v' => "it's" ) );
		$this->assertSame( "it's", Request::text( 'v' ) );
	}

	// ---------- Admin_Url::back() ----------

	public function test_back_returns_a_referer_under_the_admin_url(): void {
		$_SERVER['HTTP_REFERER'] = admin_url( 'plugins.php' );

		$this->assertSame( admin_url( 'plugins.php' ), Admin_Url::back( 'F' ) );
	}

	public function test_back_returns_a_referer_under_the_network_admin_url_on_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run via composer test-multisite.' );
		}
		$_SERVER['HTTP_REFERER'] = network_admin_url( 'plugins.php' );

		$this->assertSame( network_admin_url( 'plugins.php' ), Admin_Url::back( 'F' ) );
	}

	public function test_back_returns_the_fallback_for_a_front_end_referer(): void {
		$_SERVER['HTTP_REFERER'] = home_url( '/sample-page/' );

		$this->assertSame( 'F', Admin_Url::back( 'F' ) );
	}

	public function test_back_returns_the_fallback_for_a_foreign_referer(): void {
		$_SERVER['HTTP_REFERER'] = 'https://evil.example/wp-admin/';

		$this->assertSame( 'F', Admin_Url::back( 'F' ) );
	}

	public function test_back_returns_the_fallback_without_a_referer(): void {
		unset( $_SERVER['HTTP_REFERER'] );

		$this->assertSame( 'F', Admin_Url::back( 'F' ) );
	}
}
