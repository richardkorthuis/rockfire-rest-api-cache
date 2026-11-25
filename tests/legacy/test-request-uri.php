<?php
/**
 * Tests for the request URI of the legacy hooks.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Legacy\Request_Uri;
use Rockfire\Rest_Api_Cache\Request\Request_Context;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Request_Uri
 */
class Test_Request_Uri extends WP_UnitTestCase {

	/**
	 * A request for a route, as the early path sees it.
	 *
	 * @param string       $route  REST route.
	 * @param array<mixed> $params GET parameters.
	 *
	 * @return Request_Context
	 */
	private function context( string $route, array $params = array() ): Request_Context {
		return Request_Context::from_parts(
			array( 'REQUEST_METHOD' => 'GET' ),
			array( 'rest_route' => $route ) + $params,
			array(),
			array(),
			'wp-json',
			'',
			false,
			1,
			false
		);
	}

	public function test_a_route_with_a_parameter(): void {
		$this->assertSame(
			'/wp-json/wp/v2/posts?per_page=5',
			Request_Uri::of( $this->context( '/wp/v2/posts', array( 'per_page' => '5' ) ), 'wp-json' )
		);
	}

	public function test_a_route_without_parameters_has_no_query_string(): void {
		$this->assertSame( '/wp-json/wp/v2/posts', Request_Uri::of( $this->context( '/wp/v2/posts' ), 'wp-json' ) );
	}

	public function test_the_rest_route_parameter_is_not_part_of_the_query(): void {
		$uri = Request_Uri::of( $this->context( '/wp/v2/posts', array( 'page' => '2' ) ), 'wp-json' );
		$this->assertSame( '/wp-json/wp/v2/posts?page=2', $uri );
		$this->assertStringNotContainsString( 'rest_route', $uri );
	}

	public function test_the_parameters_are_sorted_and_rfc_3986_encoded(): void {
		$uri = Request_Uri::of( $this->context( '/wp/v2/posts', array( 'search' => 'a b&c', 'orderby' => 'date' ) ), 'wp-json' );
		$this->assertSame( '/wp-json/wp/v2/posts?orderby=date&search=a%20b%26c', $uri );
	}

	public function test_nested_parameters(): void {
		$uri = Request_Uri::of( $this->context( '/wp/v2/posts', array( 'include' => array( '3', '5' ) ) ), 'wp-json' );
		$this->assertSame( '/wp-json/wp/v2/posts?include%5B0%5D=3&include%5B1%5D=5', $uri );
	}

	/**
	 * AC-7: a legacy callback never sees a nonce.
	 */
	public function test_the_nonce_is_not_part_of_the_uri(): void {
		$uri = Request_Uri::of( $this->context( '/wp/v2/posts', array( '_wpnonce' => 'abc', 'page' => '2' ) ), 'wp-json' );

		$this->assertSame( '/wp-json/wp/v2/posts?page=2', $uri );
		$this->assertStringNotContainsString( 'nonce', $uri );
	}

	public function test_a_request_with_only_a_nonce_gives_the_bare_uri(): void {
		$this->assertSame( '/wp-json/wp/v2/posts', Request_Uri::of( $this->context( '/wp/v2/posts', array( '_wpnonce' => 'abc' ) ), 'wp-json' ) );
	}

	public function test_params_are_those_of_the_request_without_the_nonce(): void {
		$context = $this->context( '/wp/v2/posts', array( '_wpnonce' => 'abc', 'page' => '2', 'include' => array( '3', '5' ) ) );

		$this->assertArrayHasKey( '_wpnonce', $context->params, 'Precondition: the context holds the nonce.' );
		$this->assertSame( array( 'include' => array( '3', '5' ), 'page' => '2' ), Request_Uri::params( $context ) );
		$this->assertArrayHasKey( '_wpnonce', $context->params, 'The context itself is not changed.' );
	}

	public function test_the_rest_prefix_is_used_as_given(): void {
		$this->assertSame( '/api/wp/v2/posts', Request_Uri::of( $this->context( '/wp/v2/posts' ), 'api' ) );
	}
}
