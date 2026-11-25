<?php
/**
 * Tests for the request context.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Request\Request_Context;

/**
 * @covers \Rockfire\Rest_Api_Cache\Request\Request_Context
 */
class Test_Request_Context extends Rfrac_Test_Case {

	/**
	 * Build a context from parts with sensible defaults.
	 *
	 * @param array<string,mixed> $server Server vars.
	 * @param array<string,mixed> $get    GET.
	 * @param array<string,mixed> $args   Other arguments: post, cookie, vary_cookie_names, home_path, pretty, ssl, body (a string or a reader closure).
	 *
	 * @return Request_Context
	 */
	private function ctx( array $server, array $get = array(), array $args = array() ): Request_Context {
		return Request_Context::from_parts(
			$server,
			$get,
			$args['post'] ?? array(),
			$args['cookie'] ?? array(),
			'wp-json',
			$args['home_path'] ?? '',
			$args['pretty'] ?? true,
			1,
			$args['ssl'] ?? false,
			$args['vary_cookie_names'] ?? array(),
			$args['body'] ?? null
		);
	}

	/**
	 * A context for a request to a REST route with a body.
	 *
	 * @param string                    $method       Request method.
	 * @param string                    $content_type Content-Type header (empty sends none).
	 * @param string|Closure|null       $body         Body or reader.
	 * @param array<string,mixed>       $server       Extra server variables.
	 * @param array<string,mixed>       $post         What `$_POST` holds; only its `_wpnonce` and `password` count, as signals.
	 *
	 * @return Request_Context
	 */
	private function body_ctx( string $method, string $content_type, string|Closure|null $body, array $server = array(), array $post = array() ): Request_Context {
		$base = array(
			'REQUEST_METHOD' => $method,
			'REQUEST_URI'    => '/wp-json/wp/v2/posts',
		);
		if ( '' !== $content_type ) {
			$base['CONTENT_TYPE'] = $content_type;
		}
		return $this->ctx(
			array_merge( $base, $server ),
			array(),
			array(
				'post' => $post,
				'body' => $body,
			)
		);
	}

	/**
	 * A body reader that counts its calls.
	 *
	 * @param string $body  The body it returns.
	 * @param int    $calls Number of calls made.
	 *
	 * @return Closure
	 */
	private function counting_reader( string $body, int &$calls ): Closure {
		return static function ( int $max ) use ( $body, &$calls ): ?string {
			++$calls;
			return substr( $body, 0, $max );
		};
	}

	public function test_path_and_query_routes_are_identical(): void {
		$path  = $this->ctx( array( 'REQUEST_URI' => '/wp-json/wp/v2/posts?per_page=5' ), array( 'per_page' => '5' ) );
		$query = $this->ctx(
			array( 'REQUEST_URI' => '/?rest_route=/wp/v2/posts&per_page=5' ),
			array(
				'rest_route' => '/wp/v2/posts',
				'per_page'   => '5',
			)
		);
		$this->assertTrue( $path->is_rest );
		$this->assertTrue( $query->is_rest );
		$this->assertSame( '/wp/v2/posts', $path->route );
		$this->assertSame( $path->route, $query->route );
		$this->assertSame( $path->params, $query->params );
		$this->assertSame( array( 'per_page' => '5' ), $query->params );
		$this->assertSame( 'path', $path->route_source );
		$this->assertSame( 'query', $query->route_source );
	}

	public function test_home_subdirectory_is_stripped(): void {
		$ctx = $this->ctx( array( 'REQUEST_URI' => '/sub/wp-json/wp/v2/posts/' ), array(), array( 'home_path' => '/sub' ) );
		$this->assertSame( '/wp/v2/posts', $ctx->route );
		$this->assertTrue( $ctx->is_rest );
	}

	public function test_path_outside_home_path_is_not_rest(): void {
		$ctx = $this->ctx( array( 'REQUEST_URI' => '/other/wp-json/wp/v2/posts' ), array(), array( 'home_path' => '/sub' ) );
		$this->assertFalse( $ctx->is_rest );
	}

	public function test_index_php_and_bare_prefix(): void {
		$this->assertSame( '/wp/v2/posts', $this->ctx( array( 'REQUEST_URI' => '/index.php/wp-json/wp/v2/posts' ) )->route );
		$this->assertSame( '/', $this->ctx( array( 'REQUEST_URI' => '/wp-json' ) )->route );
		$this->assertSame( '/', $this->ctx( array( 'REQUEST_URI' => '/wp-json/' ) )->route );
		$this->assertFalse( $this->ctx( array( 'REQUEST_URI' => '/wp-jsonx/foo' ) )->is_rest );
		$this->assertFalse( $this->ctx( array( 'REQUEST_URI' => '/blog/' ) )->is_rest );
	}

	public function test_path_route_is_not_urldecoded_like_core(): void {
		$this->assertSame( '/wp/v2/a%20b', $this->ctx( array( 'REQUEST_URI' => '/wp-json/wp/v2/a%20b' ) )->route );
		$this->assertSame( '/wp/v2/a+b', $this->ctx( array( 'REQUEST_URI' => '/wp-json/wp/v2/a+b' ) )->route );
		$this->assertSame( '/wp/v2/users/%6De', $this->ctx( array( 'REQUEST_URI' => '/wp-json/wp/v2/users/%6De' ) )->route );
	}

	/**
	 * The route of a path request equals the `rest_route` core's own WP::parse_request() dispatches.
	 */
	public function test_path_route_matches_what_core_dispatches(): void {
		$routes = array();
		foreach ( self::PARITY_TAILS as $tail ) {
			$server = array(
				'REQUEST_URI' => '/wp-json/myplugin/v1/' . $tail,
				'PHP_SELF'    => '/index.php',
			);
			$route  = $this->ctx( $server )->route;
			$this->assertSame( $this->core_rest_route( $server ), $route, $server['REQUEST_URI'] );
			if ( ! str_ends_with( $tail, '/' ) ) {
				$routes[] = $route; // A trailing slash is the same route.
			}
		}
		$this->assertSame( $routes, array_unique( $routes ), 'Each raw path is its own route, as in core.' );
	}

	/**
	 * `/index.php/wp-json/...` requests, with the web server's (decoded) PATH_INFO and without it.
	 */
	public function test_index_php_route_matches_what_core_dispatches_with_and_without_path_info(): void {
		foreach ( self::PARITY_TAILS as $tail ) {
			$without = array(
				'REQUEST_URI' => '/index.php/wp-json/myplugin/v1/' . $tail,
				'PHP_SELF'    => '/index.php',
			);
			$this->assertSame( $this->core_rest_route( $without ), $this->ctx( $without )->route, 'Without PATH_INFO: ' . $tail );

			$with = $without + array( 'PATH_INFO' => rawurldecode( '/wp-json/myplugin/v1/' . $tail ) );
			$this->assertSame( $this->core_rest_route( $with ), $this->ctx( $with )->route, 'With PATH_INFO: ' . $tail );
		}

		$with = array(
			'REQUEST_URI' => '/index.php/wp-json/myplugin/v1/%41',
			'PHP_SELF'    => '/index.php',
			'PATH_INFO'   => '/wp-json/myplugin/v1/A',
		);
		$this->assertSame( '/myplugin/v1/A', $this->ctx( $with )->route, 'Core dispatches the decoded PATH_INFO.' );
	}

	public function test_query_route_is_the_decoded_parameter_like_core(): void {
		$ctx = $this->ctx(
			array( 'REQUEST_URI' => '/?rest_route=/myplugin/v1/a%20b%2Bc' ),
			array( 'rest_route' => '/myplugin/v1/a b+c' )
		);
		$this->assertSame( '/myplugin/v1/a b+c', $ctx->route );
	}

	/**
	 * Path tails tried against core.
	 */
	private const PARITY_TAILS = array( 'a+b', 'a%20b', 'a%2Bb', 'caf%C3%A9', 'caf%c3%a9', 'x%2Fy', 'p,q', '%FF', 'a%25b', '%2e%2e', 'a+b/', '%41' );

	/**
	 * The `rest_route` core's WP::parse_request() derives for a request (untrailingslashed like rest_api_loaded()).
	 *
	 * @param array<string,string> $server Server variables.
	 *
	 * @return string|null
	 */
	private function core_rest_route( array $server ): ?string {
		global $wp_rewrite;
		$saved_server = $_SERVER;
		$saved_extra  = $wp_rewrite->extra_rules_top;
		$this->set_permalink_structure( '/%postname%/' );
		rest_api_register_rewrites();
		$wp_rewrite->flush_rules();

		try {
			unset( $_SERVER['PATH_INFO'] );
			foreach ( $server as $key => $value ) {
				$_SERVER[ $key ] = $value;
			}
			$wp = new WP();
			$wp->add_query_var( 'rest_route' );
			$wp->parse_request();
			return isset( $wp->query_vars['rest_route'] ) ? untrailingslashit( $wp->query_vars['rest_route'] ) : null;
		} finally {
			$_SERVER                     = $saved_server;
			$wp_rewrite->extra_rules_top = $saved_extra;
			$this->set_permalink_structure( '' );
		}
	}

	public function test_url_variants_cover_the_forms_a_request_may_be_stored_under(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$routes = static fn( string $url ): array => array_map( static fn( Request_Context $c ): ?string => $c->route, Request_Context::url_variants( $url, 'wp-json' ) );

		$this->assertSame( array( '/myplugin/v1/café/a b', '/myplugin/v1/caf%C3%A9/a%20b' ), $routes( '/wp-json/myplugin/v1/café/a b' ) );
		$this->assertSame( array( '/myplugin/v1/caf%c3%a9', '/myplugin/v1/caf%C3%A9' ), $routes( '/wp-json/myplugin/v1/caf%c3%a9' ), 'As sent (lower case) and canonical.' );
		$this->assertSame( array( '/myplugin/v1/a|b[0]^{x}', '/myplugin/v1/a%7Cb%5B0%5D%5E%7Bx%7D' ), $routes( '/wp-json/myplugin/v1/a|b[0]^{x}' ), 'Characters browsers send unescaped are tried raw too.' );
		$this->assertSame( array( '/myplugin/v1/a+b,c' ), $routes( '/wp-json/myplugin/v1/a+b,c' ), 'A canonical path is one variant.' );
		$this->assertSame( array( '/myplugin/v1/caf%C3%A9', '/myplugin/v1/café' ), $routes( '/index.php/wp-json/myplugin/v1/caf%C3%A9' ), 'An index.php/ URL also tries the decoded PATH_INFO form.' );
		$this->assertSame( '/myplugin/v1/caf%c3%a9', Request_Context::from_url( '/wp-json/myplugin/v1/caf%c3%a9', 'wp-json' )->route, 'from_url() keeps the path as given.' );
		$this->set_permalink_structure( '' );
	}

	public function test_canonical_path(): void {
		$this->assertSame( '/a%C3%A9%20b/%2F/~x', Request_Context::canonical_path( "/a\xC3\xA9 b/%2f/~x" ) );
		$this->assertSame( '/100%', Request_Context::canonical_path( '/100%' ), 'A lone % is kept.' );
	}

	public function test_query_route_wins_over_path(): void {
		$ctx = $this->ctx(
			array( 'REQUEST_URI' => '/wp-json/wp/v2/posts?rest_route=/wp/v2/users' ),
			array( 'rest_route' => '/wp/v2/users' )
		);
		$this->assertSame( '/wp/v2/users', $ctx->route );
		$this->assertSame( 'query', $ctx->route_source );
		$this->assertSame( array(), $ctx->params );
	}

	public function test_query_route_works_with_plain_permalinks_and_drops_a_trailing_slash(): void {
		$ctx = $this->ctx( array( 'REQUEST_URI' => '/?rest_route=/' ), array( 'rest_route' => '/' ), array( 'pretty' => false ) );
		$this->assertTrue( $ctx->is_rest );
		$this->assertSame( '/', $ctx->route );
		$ctx = $this->ctx( array( 'REQUEST_URI' => '/?rest_route=/wp/v2/posts/' ), array( 'rest_route' => '/wp/v2/posts/' ), array( 'pretty' => false ) );
		$this->assertSame( '/wp/v2/posts', $ctx->route );
	}

	public function test_query_routes_core_does_not_serve_as_given_are_not_rest(): void {
		foreach ( array( '', '0', 'wp/v2/posts', '//wp/v2/posts', array( '/wp/v2/posts' ) ) as $value ) {
			$ctx = $this->ctx( array( 'REQUEST_URI' => '/' ), array( 'rest_route' => $value ) );
			$this->assertFalse( $ctx->is_rest, var_export( $value, true ) );
			$this->assertNull( $ctx->route );
			$this->assertArrayNotHasKey( 'rest_route', $ctx->params );
		}
	}

	public function test_a_double_slash_after_the_prefix_is_not_rewritten_into_another_route(): void {
		$this->assertNull( $this->ctx( array( 'REQUEST_URI' => '/wp-json//wp/v2/posts' ) )->route );
		$this->assertNull( $this->ctx( array( 'REQUEST_URI' => '/index.php/wp-json//wp/v2/posts' ) )->route );
		$this->assertSame( '/wp/v2//posts', $this->ctx( array( 'REQUEST_URI' => '/wp-json/wp/v2//posts' ) )->route, 'Inside the route it stays as sent.' );
	}

	public function test_path_info_that_core_ignores_is_not_cacheable(): void {
		$ctx = $this->ctx(
			array(
				'REQUEST_URI' => '/wp-json/wp/v2/posts',
				'PATH_INFO'   => '/x/index.php',
			)
		);
		$this->assertNull( $ctx->route, 'Fail safe instead of falling back to the request URI.' );
		$this->assertFalse( $ctx->is_rest );
	}

	public function test_plain_permalinks_ignore_wp_json_path(): void {
		$ctx = $this->ctx( array( 'REQUEST_URI' => '/wp-json/wp/v2/posts' ), array(), array( 'pretty' => false ) );
		$this->assertFalse( $ctx->is_rest );
		$this->assertNull( $ctx->route );
		$this->assertNull( $ctx->route_source );
	}

	public function test_home_url_in_query_value_changes_nothing(): void {
		$home = home_url();
		$uri  = '/wp-json/wp/v2/posts?redirect=' . rawurlencode( $home ) . '&per_page=5';
		$ctx  = $this->ctx(
			array( 'REQUEST_URI' => $uri ),
			array(
				'redirect' => $home,
				'per_page' => '5',
			),
			array( 'home_path' => (string) wp_parse_url( $home, PHP_URL_PATH ) )
		);
		$this->assertSame( '/wp/v2/posts', $ctx->route );
		$this->assertSame(
			array(
				'per_page' => '5',
				'redirect' => $home,
			),
			$ctx->params
		);
		$this->assertSame( strlen( substr( $uri, (int) strpos( $uri, '?' ) + 1 ) ), $ctx->query_length );
	}

	public function test_empty_server_is_safe(): void {
		$ctx = $this->ctx( array() );
		$this->assertSame( 'GET', $ctx->method );
		$this->assertFalse( $ctx->is_rest );
		$this->assertSame( array(), $ctx->headers );
		$this->assertSame( array(), $ctx->signals );
		$this->assertSame( 0, $ctx->query_length );
		$this->assertNull( $ctx->origin );
		$this->assertSame( 'http', $ctx->scheme );
	}

	public function test_from_globals_with_empty_server_emits_no_notices(): void {
		$saved   = array( $_SERVER, $_GET, $_POST, $_COOKIE );
		$_SERVER = array();
		$_GET    = array();
		$_POST   = array();
		$_COOKIE = array();
		try {
			$ctx = Request_Context::from_globals( 'wp-json' );
		} finally {
			list( $_SERVER, $_GET, $_POST, $_COOKIE ) = $saved;
		}
		$this->assertSame( 'GET', $ctx->method );
		$this->assertFalse( $ctx->is_rest );
	}

	public function test_from_globals_reads_superglobals_and_unslashes(): void {
		$this->assertGreaterThan( 0, did_action( 'sanitize_comment_cookies' ) );
		$saved   = array( $_SERVER, $_GET, $_POST, $_COOKIE );
		$_SERVER = array(
			'REQUEST_METHOD' => 'post',
			'REQUEST_URI'    => '/?rest_route=/x/y&a=O%5C%27x',
		);
		$_GET    = array(
			'rest_route' => '/x/y',
			'a'          => "O\\'x",
		);
		$_POST   = array( '_wpnonce' => 'abc' );
		$_COOKIE = array( 'wordpress_logged_in_x' => '1' );
		try {
			$ctx = Request_Context::from_globals( 'wp-json' );
		} finally {
			list( $_SERVER, $_GET, $_POST, $_COOKIE ) = $saved;
		}
		$this->assertSame( 'POST', $ctx->method );
		$this->assertSame( "O'x", $ctx->params['a'] );
		$this->assertSame( '/x/y', $ctx->route );
		$this->assertSame( array( 'wpnonce_param' ), $ctx->signals );
		$this->assertTrue( $ctx->has_cookie_with_prefix( 'wordpress_logged_in_' ) );
		$this->assertSame( get_current_blog_id(), $ctx->blog_id );
	}

	/**
	 * Build the context through `from_globals()` from the given superglobals; the real ones are restored afterwards.
	 *
	 * @param array<string,mixed> $superglobals `server`, `get`, `post`, `cookie`, `vary_cookie_names`, `header_names` and `raw` (the raw body).
	 *
	 * @return Request_Context
	 */
	private function from_globals_with( array $superglobals ): Request_Context {
		$saved   = array( $_SERVER, $_GET, $_POST, $_COOKIE );
		$_SERVER = (array) ( $superglobals['server'] ?? array() );
		$_GET    = (array) ( $superglobals['get'] ?? array() );
		$_POST   = (array) ( $superglobals['post'] ?? array() );
		$_COOKIE = (array) ( $superglobals['cookie'] ?? array() );
		if ( isset( $superglobals['raw'] ) ) {
			$GLOBALS['HTTP_RAW_POST_DATA'] = $superglobals['raw'];
		}
		try {
			return Request_Context::from_globals(
				'wp-json',
				(array) ( $superglobals['vary_cookie_names'] ?? array() ),
				(array) ( $superglobals['header_names'] ?? array() )
			);
		} finally {
			unset( $GLOBALS['HTTP_RAW_POST_DATA'] );
			list( $_SERVER, $_GET, $_POST, $_COOKIE ) = $saved;
		}
	}

	public function test_from_globals_reads_only_the_headers_it_is_given(): void {
		$server = array(
			'REQUEST_METHOD'     => 'GET',
			'REQUEST_URI'        => '/wp-json/wp/v2/posts',
			'HTTP_X_UNRELATED'   => 'v',
			'HTTP_AUTHORIZATION' => 'Bearer t',
		);

		$none = $this->from_globals_with( array( 'server' => $server ) );
		$this->assertNull( $none->header( 'x-unrelated' ) );
		$this->assertSame( 'Bearer t', $none->header( 'authorization' ), 'A fixed key is always read.' );

		$named = $this->from_globals_with(
			array(
				'server'       => $server,
				'header_names' => array( 'x-unrelated', 7, '', null ),
			)
		);
		$this->assertSame( 'v', $named->header( 'x-unrelated' ) );
		$this->assertSame( array( 'authorization', 'x-unrelated' ), array_keys( $named->headers ) );
	}

	public function test_from_globals_records_the_header_names_it_read(): void {
		$server = array(
			'REQUEST_METHOD'   => 'GET',
			'REQUEST_URI'      => '/wp-json/wp/v2/posts',
			'HTTP_X_UNRELATED' => 'v',
		);

		$none = $this->from_globals_with( array( 'server' => $server ) );
		$this->assertSame(
			array( 'authorization', 'content-type', 'host', 'origin', 'transfer-encoding', 'x-http-method-override', 'x-wp-nonce' ),
			$none->read_headers,
			'The fixed keys are always read.'
		);
		$this->assertFalse( $none->has_read_header( 'x-unrelated' ), 'A header it did not read is unknown, whatever the request holds.' );
		$this->assertTrue( $none->has_read_header( 'Authorization' ) );

		$named = $this->from_globals_with(
			array(
				'server'       => $server,
				'header_names' => array( 'X_Unrelated', 'x-absent', 'authorization', 7, '', null ),
			)
		);
		$this->assertSame(
			array( 'authorization', 'content-type', 'host', 'origin', 'transfer-encoding', 'x-absent', 'x-http-method-override', 'x-unrelated', 'x-wp-nonce' ),
			$named->read_headers
		);
		$this->assertTrue( $named->has_read_header( 'x-unrelated' ) );
		$this->assertTrue( $named->has_read_header( 'X-Unrelated' ) );
		$this->assertTrue( $named->has_read_header( 'x_unrelated' ) );
		$this->assertTrue( $named->has_read_header( 'x-absent' ), 'Read and absent is not unknown.' );
		$this->assertNull( $named->header( 'x-absent' ) );
		$this->assertFalse( $named->has_read_header( 'x-other' ) );
	}

	public function test_from_parts_holds_every_header_unless_given_the_names_it_was_limited_to(): void {
		$server = array(
			'REQUEST_METHOD'   => 'GET',
			'REQUEST_URI'      => '/wp-json/wp/v2/posts',
			'HTTP_X_UNRELATED' => 'v',
		);

		$all = $this->ctx( $server );
		$this->assertNull( $all->read_headers );
		$this->assertTrue( $all->has_read_header( 'x-unrelated' ) );
		$this->assertTrue( $all->has_read_header( 'x-never-sent' ) );

		$limited = Request_Context::from_parts( $server, array(), array(), array(), 'wp-json', '', true, 1, false, array(), null, array( 'X_Unrelated', 'x-unrelated', 7, 'Accept' ) );
		$this->assertSame( array( 'accept', 'x-unrelated' ), $limited->read_headers );
		$this->assertTrue( $limited->has_read_header( 'x-unrelated' ) );
		$this->assertFalse( $limited->has_read_header( 'x-never-sent' ) );

		$none = Request_Context::from_parts( $server, array(), array(), array(), 'wp-json', '', true, 1, false, array(), null, array() );
		$this->assertSame( array(), $none->read_headers );
		$this->assertFalse( $none->has_read_header( 'x-unrelated' ) );
	}

	public function test_from_globals_reads_no_server_variable_it_does_not_name(): void {
		$context = $this->from_globals_with(
			array(
				'server' => array(
					'REQUEST_METHOD'  => 'POST',
					'REQUEST_URI'     => '/wp-json/tst/v1/form',
					'CONTENT_TYPE'    => 'multipart/form-data; boundary=x',
					'SERVER_SOFTWARE' => 'SRVSECRET',
				),
				'cookie' => array(
					'other' => 'CKSECRET',
				),
				'post'   => array(
					'field' => 'PSTSECRET',
				),
			)
		);

		$dump = var_export( $context, true );
		foreach ( array( 'SRVSECRET', 'CKSECRET', 'PSTSECRET' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $dump );
		}
		$this->assertSame( array( 'other' ), $context->cookie_names );
	}

	public function test_from_globals_keeps_a_cookie_value_only_for_a_vary_cookie(): void {
		$context = $this->from_globals_with(
			array(
				'cookie'            => array(
					'cur'   => 'EUR',
					'other' => 'x',
					'list'  => array( 'a' ),
				),
				'vary_cookie_names' => array( 'cur', 'list', 'gone', 7 ),
			)
		);
		$this->assertSame( array( 'cur', 'other', 'list' ), $context->cookie_names );
		$this->assertSame(
			array(
				'cur'  => 'EUR',
				'list' => null,
				'gone' => '',
			),
			$context->vary_cookies
		);
	}

	public function test_from_globals_puts_the_content_type_after_the_http_content_type(): void {
		$context = $this->from_globals_with(
			array(
				'server' => array(
					'CONTENT_TYPE'      => 'application/json',
					'HTTP_CONTENT_TYPE' => 'text/plain',
				),
			)
		);
		$this->assertSame( 'application/json', $context->header( 'content-type' ) );
	}

	public function test_from_globals_reads_the_content_length_as_a_string_or_an_integer(): void {
		foreach ( array( '12', 12 ) as $length ) {
			$context = $this->from_globals_with(
				array(
					'server' => array(
						'REQUEST_METHOD' => 'POST',
						'REQUEST_URI'    => '/wp-json/wp/v2/posts',
						'CONTENT_LENGTH' => $length,
					),
				)
			);
			$this->assertTrue( $context->has_body, 'The length ' . var_export( $length, true ) . ' announces a body.' );
		}

		$context = $this->from_globals_with(
			array(
				'server' => array(
					'REQUEST_METHOD' => 'POST',
					'REQUEST_URI'    => '/wp-json/wp/v2/posts',
					'CONTENT_LENGTH' => 12.5,
				),
			)
		);
		$this->assertFalse( $context->has_body, 'Any other type is not read.' );
	}

	public function test_from_globals_unslashes_the_server_and_query_values(): void {
		$this->assertGreaterThan( 0, did_action( 'sanitize_comment_cookies' ) );
		$context = $this->from_globals_with(
			array(
				'server'       => array(
					'REQUEST_URI'      => '/wp-json/wp/v2/posts',
					'HTTP_X_UNSLASHED' => "a\\'b",
				),
				'get'          => array(
					'a' => "O\\'x",
					'b' => array( 'c' => "d\\'e" ),
				),
				'header_names' => array( 'x-unslashed' ),
			)
		);
		$this->assertSame( "a'b", $context->header( 'x-unslashed' ) );
		$this->assertSame( "O'x", $context->params['a'] );
		$this->assertSame( array( 'c' => "d'e" ), $context->params['b'] );
	}

	public function test_from_globals_keeps_the_get_strings_and_arrays_of_them_and_drops_other_types(): void {
		$context = $this->from_globals_with(
			array(
				'server' => array( 'REQUEST_URI' => '/wp-json/wp/v2/posts' ),
				'get'    => array(
					'a'       => 5,
					'b'       => new stdClass(),
					'c'       => 'kept',
					'list'    => array( '3', '5' ),
					'nested'  => array(
						'x' => array( 'y' => 'deep' ),
						'z' => 1.5,
					),
					'mixed'   => array( 'ok', null, true ),
					'nothing' => null,
				),
			)
		);
		$this->assertSame(
			array(
				'c'      => 'kept',
				'list'   => array( '3', '5' ),
				'mixed'  => array( 'ok' ),
				'nested' => array(
					'x' => array( 'y' => 'deep' ),
				),
			),
			$context->params
		);
	}

	public function test_from_globals_reads_only_the_nonce_and_password_of_post(): void {
		$server = array(
			'REQUEST_METHOD' => 'POST',
			'REQUEST_URI'    => '/wp-json/tst/v1/form',
			'CONTENT_TYPE'   => 'multipart/form-data; boundary=x',
		);
		foreach ( array(
			'_wpnonce' => 'wpnonce_param',
			'password' => 'password_param',
		) as $key => $signal ) {
			$context = $this->from_globals_with(
				array(
					'server' => $server,
					'post'   => array(
						$key      => 'v',
						'unknown' => 'u',
					),
				)
			);
			$this->assertSame( array( $signal ), $context->signals, $key );
		}

		$none = $this->from_globals_with(
			array(
				'server' => $server,
				'post'   => array(
					'unknown'  => 'u',
					'password' => 5,
				),
			)
		);
		$this->assertSame( array(), $none->signals );
	}

	public function test_redirect_http_authorization_is_a_signal(): void {
		$ctx = $this->ctx( array( 'REDIRECT_HTTP_AUTHORIZATION' => 'Basic x' ) );
		$this->assertContains( 'authorization', $ctx->signals );
		$this->assertSame( 'Basic x', $ctx->header( 'Authorization' ) );
	}

	public function test_other_signals(): void {
		$ctx = $this->ctx(
			array(
				'PHP_AUTH_USER'      => 'bob',
				'HTTP_X_WP_NONCE'    => 'n',
				'HTTP_AUTHORIZATION' => 'Bearer t',
			),
			array( '_wpnonce' => 'z' )
		);
		$this->assertEqualsCanonicalizing( array( 'authorization', 'php_auth_user', 'x_wp_nonce', 'wpnonce_param' ), $ctx->signals );
		$this->assertSame( array(), $this->ctx( array( 'REQUEST_URI' => '/wp-json/a' ) )->signals );
	}

	public function test_headers_and_origin(): void {
		$ctx = $this->ctx(
			array(
				'HTTP_ACCEPT_LANGUAGE' => 'nl',
				'CONTENT_TYPE'         => 'application/json',
				'HTTP_ORIGIN'          => 'https://a.test',
				'SERVER_NAME'          => 'ignored',
			)
		);
		$this->assertSame( 'nl', $ctx->header( 'Accept-Language' ) );
		$this->assertSame( 'application/json', $ctx->header( 'content-type' ) );
		$this->assertSame( 'https://a.test', $ctx->origin );
		$this->assertNull( $ctx->header( 'server-name' ) );
	}

	public function test_params_are_sorted_recursively_and_kept_as_strings(): void {
		$ctx = $this->ctx(
			array(),
			array(
				'c' => array(
					'b' => '1',
					'a' => '2',
				),
				'b' => '1',
				'a' => '3',
			)
		);
		$this->assertSame(
			array(
				'a' => '3',
				'b' => '1',
				'c' => array(
					'a' => '2',
					'b' => '1',
				),
			),
			$ctx->params
		);
	}

	public function test_nested_lists_keep_their_order(): void {
		$get = array();
		parse_str( 'include[1]=5&include[0]=3&b=1', $get );
		$reversed = $this->ctx( array(), $get );
		parse_str( 'include[]=3&include[]=5&b=1', $get );
		$sent_in_order = $this->ctx( array(), $get );

		$this->assertSame( array( 1 => '5', 0 => '3' ), $reversed->params['include'] );
		$this->assertSame( array( '3', '5' ), $sent_in_order->params['include'] );
		$this->assertNotSame( $reversed->params, $sent_in_order->params, 'WordPress orders include[] by the order sent, so the keys must differ.' );
		$this->assertSame( array( 'b', 'include' ), array_keys( $reversed->params ), 'The top level is still sorted by name.' );
	}

	public function test_param_normalisation_edge_cases(): void {
		$params = static function ( string $query ): array {
			$get = array();
			parse_str( $query, $get );
			return Request_Context::from_parts( array(), $get, array(), array(), 'wp-json', '', true, 1, false )->params;
		};

		$this->assertSame( array( 'a' => array( 'x' => '1', 0 => '2' ) ), $params( 'a[x]=1&a[]=2' ), 'Mixed keys keep their order.' );
		$this->assertSame( array( 'a' => array( 'y' => array( '2', '1' ), 'z' => '3' ) ), $params( 'a[z]=3&a[y][]=2&a[y][]=1' ), 'A string-keyed array is sorted, the list inside keeps its order.' );
		$this->assertSame( array( 'a' => array( array( 'b' => '1' ), array( 'a' => '2' ) ) ), $params( 'a[0][b]=1&a[1][a]=2' ), 'A list of arrays keeps its order.' );
		$this->assertSame( array( 5 => 'x', 'b' => '1' ), $params( 'b=1&5=x' ), 'The top level is sorted, also with an integer key.' );
		$this->assertSame( $params( 'b=1&5=x' ), $params( '5=x&b=1' ) );
		$this->assertNotSame( $params( 'a[]=1&a[]=2' ), $params( 'a[]=2&a[]=1' ), 'Another order is another key.' );
	}

	public function test_cookie_prefix_helper(): void {
		$ctx = $this->ctx(
			array(),
			array(),
			array(
				'cookie' => array(
					'wp-settings-1'      => 'x',
					'comment_author_abc' => 'y',
				),
			)
		);
		$this->assertTrue( $ctx->has_cookie_with_prefix( 'foo_', 'wp-settings-' ) );
		$this->assertFalse( $ctx->has_cookie_with_prefix( 'woocommerce_' ) );
		$this->assertFalse( $ctx->has_cookie_with_prefix() );
		$this->assertFalse( $ctx->has_cookie_with_prefix( '' ) );
	}

	public function test_vary_cookies_hold_only_the_requested_values(): void {
		$ctx = $this->ctx(
			array(),
			array(),
			array(
				'cookie'            => array(
					'cur'   => 'EUR',
					'other' => 'x',
				),
				'vary_cookie_names' => array( 'cur' ),
			)
		);
		$this->assertSame( array( 'cur' => 'EUR' ), $ctx->vary_cookies );
		$this->assertSame( array( 'cur', 'other' ), $ctx->cookie_names, 'The names of all cookies are still recorded.' );
	}

	public function test_vary_cookies_record_every_requested_name(): void {
		$names = array( 'cur' );

		$absent = $this->ctx( array(), array(), array( 'vary_cookie_names' => $names ) );
		$this->assertSame( array( 'cur' => '' ), $absent->vary_cookies, 'A cookie that was not sent reads as empty.' );

		$empty = $this->ctx(
			array(),
			array(),
			array(
				'cookie'            => array( 'cur' => '' ),
				'vary_cookie_names' => $names,
			)
		);
		$this->assertSame( array( 'cur' => '' ), $empty->vary_cookies );

		$array = $this->ctx(
			array(),
			array(),
			array(
				'cookie'            => array( 'cur' => array( 'a' ) ),
				'vary_cookie_names' => $names,
			)
		);
		$this->assertSame( array( 'cur' => null ), $array->vary_cookies, 'A cookie that is not a string reads as null.' );
	}

	public function test_vary_cookie_names_ignore_non_strings_and_collapse_duplicates(): void {
		$ctx = $this->ctx(
			array(),
			array(),
			array(
				'cookie'            => array(
					'cur' => 'EUR',
					'lng' => 'nl',
				),
				'vary_cookie_names' => array( 'cur', 7, null, array( 'lng' ), 'lng', 'cur', 'gone' ),
			)
		);
		$this->assertSame(
			array(
				'cur'  => 'EUR',
				'lng'  => 'nl',
				'gone' => '',
			),
			$ctx->vary_cookies
		);
	}

	public function test_vary_cookies_are_empty_without_names(): void {
		$ctx = $this->ctx( array(), array(), array( 'cookie' => array( 'cur' => 'EUR' ) ) );
		$this->assertSame( array(), $ctx->vary_cookies );
		$this->assertSame( array(), Request_Context::from_url( '/wp-json/wp/v2/posts', 'wp-json' )->vary_cookies );
	}

	public function test_from_globals_unslashes_the_vary_cookie_values(): void {
		$this->assertGreaterThan( 0, did_action( 'sanitize_comment_cookies' ) );
		$saved   = array( $_SERVER, $_GET, $_POST, $_COOKIE );
		$_SERVER = array();
		$_GET    = array();
		$_POST   = array();
		$_COOKIE = array( 'cur' => "a\\'b" );
		try {
			$ctx = Request_Context::from_globals( 'wp-json', array( 'cur', 'missing' ) );
		} finally {
			list( $_SERVER, $_GET, $_POST, $_COOKIE ) = $saved;
		}
		$this->assertSame(
			array(
				'cur'     => "a'b",
				'missing' => '',
			),
			$ctx->vary_cookies
		);
	}

	public function test_url_variants_pass_the_vary_cookie_names_to_every_variant(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$variants = Request_Context::url_variants( '/wp-json/myplugin/v1/café/a b', 'wp-json', array( 'cur' ) );
		$this->assertGreaterThan( 1, count( $variants ) );
		foreach ( $variants as $variant ) {
			$this->assertSame( array( 'cur' => '' ), $variant->vary_cookies );
		}
		$this->set_permalink_structure( '' );
	}

	public function test_scheme_and_blog_id(): void {
		$ctx = Request_Context::from_parts( array(), array(), array(), array(), 'wp-json', '', true, 7, true );
		$this->assertSame( 'https', $ctx->scheme );
		$this->assertSame( 7, $ctx->blog_id );
	}

	public function test_from_url(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$ctx = Request_Context::from_url( home_url( '/wp-json/wp/v2/posts/?per_page=5&a=1' ), 'wp-json' );
		$this->assertSame( 'GET', $ctx->method );
		$this->assertTrue( $ctx->is_rest );
		$this->assertSame( '/wp/v2/posts', $ctx->route );
		$this->assertSame(
			array(
				'a'        => '1',
				'per_page' => '5',
			),
			$ctx->params
		);
		$this->assertSame( array(), $ctx->headers );
		$this->assertSame( array(), $ctx->cookie_names );
		$this->assertSame( strlen( 'per_page=5&a=1' ), $ctx->query_length );

		$q = Request_Context::from_url( home_url( '/?rest_route=/wp/v2/posts&per_page=5' ), 'wp-json' );
		$this->assertSame( '/wp/v2/posts', $q->route );
	}

	public function test_json_body_is_classified_and_canonicalised(): void {
		$json = 'application/json; charset=utf-8';
		$ctx  = $this->body_ctx( 'POST', $json, '{"b":1,"a":{"d":[2,1],"c":"x"}}' );
		$this->assertSame( 'json', $ctx->content_class );
		$this->assertNull( $ctx->body_error );
		$this->assertSame( '{"a":{"c":"x","d":[2,1]},"b":1}', $ctx->canonical_body( array() ) );
		$this->assertSame( array( 'a', 'b' ), array_keys( $ctx->body_params ) );

		// Key order and whitespace do not matter, list order does.
		$respaced = $this->body_ctx( 'POST', $json, "{ \"a\" : { \"c\":\"x\",\n \"d\" : [2, 1] }, \"b\" : 1 }" );
		$this->assertSame( $ctx->canonical_body( array() ), $respaced->canonical_body( array() ) );
		$reordered = $this->body_ctx( 'POST', $json, '{"a":{"d":[2,1],"c":"x"},"b":1}' );
		$this->assertSame( $ctx->canonical_body( array() ), $reordered->canonical_body( array() ) );
		$other_list = $this->body_ctx( 'POST', $json, '{"b":1,"a":{"d":[1,2],"c":"x"}}' );
		$this->assertNotSame( $ctx->canonical_body( array() ), $other_list->canonical_body( array() ) );
	}

	public function test_json_media_types(): void {
		foreach ( array( 'application/json', 'APPLICATION/JSON; charset=UTF-8', ' application/vnd.api+json ', 'application/ld+json;x=y' ) as $type ) {
			$this->assertSame( 'json', $this->body_ctx( 'PUT', $type, '{"a":1}' )->content_class, $type );
		}
		foreach ( array( 'text/json', 'application/+json', 'application/jsonx', 'application/json/x' ) as $type ) {
			$ctx = $this->body_ctx( 'PUT', $type, '{"a":1}' );
			$this->assertNull( $ctx->content_class, $type );
			$this->assertSame( 'unsupported', $ctx->body_error, $type );
		}
	}

	public function test_json_canonical_form_keeps_empty_objects_and_lists_apart(): void {
		$json  = 'application/json';
		$empty = $this->body_ctx( 'POST', $json, '{"a":{},"b":[]}' );
		$this->assertSame( '{"a":{},"b":[]}', $empty->canonical_body( array() ) );
		$this->assertNotSame( $empty->canonical_body( array() ), $this->body_ctx( 'POST', $json, '{"a":[],"b":{}}' )->canonical_body( array() ) );

		// Numeric-looking keys stay an object, never a list.
		$this->assertSame( '{"0":"a","1":"b"}', $this->body_ctx( 'POST', $json, '{"1":"b","0":"a"}' )->canonical_body( array() ) );
		$this->assertSame( '{"x":{"0":"a"}}', $this->body_ctx( 'POST', $json, '{"x":{"0":"a"}}' )->canonical_body( array() ) );
		$this->assertSame( '{"x":["a"]}', $this->body_ctx( 'POST', $json, '{"x":["a"]}' )->canonical_body( array() ) );
		$this->assertSame( '{"":1,"a":2}', $this->body_ctx( 'POST', $json, '{"a":2,"":1}' )->canonical_body( array() ) );
	}

	public function test_json_canonical_form_encoding(): void {
		$ctx = $this->body_ctx( 'POST', 'application/json', '{"u":"caf\u00e9 \u20ac","s":"a\/b","f":1.0,"i":1,"big":12345678901234567890,"n":null,"t":true}' );
		$this->assertSame( '{"big":1.2345678901234567e+19,"f":1.0,"i":1,"n":null,"s":"a/b","t":true,"u":"café €"}', $ctx->canonical_body( array() ) );
	}

	public function test_a_big_integer_and_the_same_digits_as_a_string_are_different_canonical_bodies(): void {
		$json   = 'application/json';
		$number = $this->body_ctx( 'POST', $json, '{"id":12345678901234567890}' );
		$string = $this->body_ctx( 'POST', $json, '{"id":"12345678901234567890"}' );

		$this->assertNull( $number->body_error );
		$this->assertNull( $string->body_error );
		$this->assertSame( '{"id":"12345678901234567890"}', $string->canonical_body( array() ) );
		$this->assertNotSame( $string->canonical_body( array() ), $number->canonical_body( array() ), 'The handler gets a float in one case and a string in the other.' );
		$this->assertIsFloat( $number->body_params['id'], 'As core decodes it (no JSON_BIGINT_AS_STRING).' );
		$this->assertIsString( $string->body_params['id'] );

		// Digits that fit an integer stay an integer, apart from the string.
		$this->assertNotSame(
			$this->body_ctx( 'POST', $json, '{"id":1234567890}' )->canonical_body( array() ),
			$this->body_ctx( 'POST', $json, '{"id":"1234567890"}' )->canonical_body( array() )
		);
	}

	public function test_a_json_number_that_cannot_be_re_encoded_is_an_invalid_body(): void {
		$overflows = array(
			'overflowing float'          => '{"q":1e400}',
			'negative overflowing float' => '{"q":-1e400}',
			'nested in an object'        => '{"a":{"b":{"q":1e400}}}',
			'nested in a list'           => '{"a":[1,[2,1e400]]}',
			'huge integer'               => '{"q":1' . str_repeat( '0', 400 ) . '}',
			'in a dropped key'           => '{"_wpnonce":1e400,"q":1}',
		);
		foreach ( $overflows as $label => $body ) {
			$ctx = $this->body_ctx( 'POST', 'application/json', $body );
			$this->assertSame( 'json', $ctx->content_class, $label );
			$this->assertSame( 'invalid', $ctx->body_error, $label );
			$this->assertSame( array(), $ctx->body_params, $label );
			$this->assertSame( '', $ctx->canonical_body( array() ), $label );
		}

		// Control: a large finite number is keyable.
		$finite = $this->body_ctx( 'POST', 'application/json', '{"q":1e300}' );
		$this->assertNull( $finite->body_error );
		$this->assertNotSame( '', $finite->canonical_body( array() ) );
	}

	public function test_empty_json_body_is_a_json_class_without_parameters(): void {
		$ctx = $this->body_ctx( 'POST', 'application/json', '' );
		$this->assertSame( 'json', $ctx->content_class );
		$this->assertNull( $ctx->body_error );
		$this->assertSame( array(), $ctx->body_params );
		$this->assertSame( '{}', $ctx->canonical_body( array() ) );
		$this->assertSame( '{}', $this->body_ctx( 'POST', 'application/json', '{}' )->canonical_body( array() ) );
		$this->assertSame( 'json', $this->body_ctx( 'POST', 'application/json', null )->content_class );
	}

	public function test_canonical_body_drops_the_given_top_level_keys(): void {
		$ctx = $this->body_ctx( 'POST', 'application/json', '{"_wpnonce":"n","a":1,"b":{"_wpnonce":"nested"}}' );
		$this->assertSame( '{"a":1,"b":{"_wpnonce":"nested"}}', $ctx->canonical_body( array( '_wpnonce' ) ) );
		$this->assertSame( '{"_wpnonce":"n","a":1,"b":{"_wpnonce":"nested"}}', $ctx->canonical_body( array() ) );
		$this->assertSame( '{}', $ctx->canonical_body( array( '_wpnonce', 'a', 'b', 'missing' ) ) );
		$this->assertSame( '{"a":1}', $this->body_ctx( 'POST', 'application/json', '{"_wpnonce":"n","a":1}' )->canonical_body( array( '_wpnonce' ) ) );

		$numeric = $this->body_ctx( 'POST', 'application/json', '{"0":"a","1":"b"}' );
		$this->assertSame( '{"1":"b"}', $numeric->canonical_body( array( '0' ) ) );
		$this->assertSame( '{"1":"b"}', $numeric->canonical_body( array( 0 ) ) );
	}

	public function test_form_put_and_patch_are_built_from_the_raw_body(): void {
		$form = 'application/x-www-form-urlencoded';
		foreach ( array( 'PUT', 'PATCH' ) as $method ) {
			$ctx = $this->body_ctx( $method, $form, 'b=2&a[]=1&a[]=0' );
			$this->assertSame( 'form', $ctx->content_class, $method );
			$this->assertNull( $ctx->body_error, $method );
			$this->assertSame( '{"a":["1","0"],"b":"2"}', $ctx->canonical_body( array() ), $method );
			$this->assertSame( array( 'a', 'b' ), array_keys( $ctx->body_params ), $method );
		}
		$reordered = $this->body_ctx( 'PUT', $form, 'a[]=1&a[]=0&b=2' );
		$this->assertSame( '{"a":["1","0"],"b":"2"}', $reordered->canonical_body( array() ) );
		$other = $this->body_ctx( 'PUT', $form, 'b=2&a[]=0&a[]=1' );
		$this->assertSame( '{"a":["0","1"],"b":"2"}', $other->canonical_body( array() ) );
		$this->assertSame( '{"a":{"x":"1","y":"2"}}', $this->body_ctx( 'PUT', $form, 'a[y]=2&a[x]=1' )->canonical_body( array() ) );
		$this->assertSame( '{"0":"a","1":"b"}', $this->body_ctx( 'PUT', $form, '1=b&0=a' )->canonical_body( array() ) );
		$this->assertSame( '{}', $this->body_ctx( 'PUT', $form, '' )->canonical_body( array() ) );
		$this->assertSame( '{"a":"é"}', $this->body_ctx( 'PUT', $form, 'a=%C3%A9' )->canonical_body( array() ) );
	}

	public function test_form_post_is_built_from_the_raw_body(): void {
		$form = 'application/x-www-form-urlencoded';

		// An empty parsed `$_POST`: the body is all there is.
		$ctx = $this->body_ctx( 'POST', $form, 'b=2&a[]=1&a[]=0' );
		$this->assertSame( 'form', $ctx->content_class );
		$this->assertNull( $ctx->body_error );
		$this->assertSame( '{"a":["1","0"],"b":"2"}', $ctx->canonical_body( array() ) );
		$this->assertSame( array( 'a', 'b' ), array_keys( $ctx->body_params ) );

		// A parsed `$_POST` that differs from the raw body is never read: the raw body wins.
		$differing = $this->body_ctx(
			'POST',
			$form,
			'b=2&a[]=1&a[]=0',
			array(),
			array(
				'b'     => '9',
				'extra' => 'x',
			)
		);
		$this->assertSame( '{"a":["1","0"],"b":"2"}', $differing->canonical_body( array() ) );

		$empty = $this->body_ctx( 'POST', $form, '', array(), array( 'extra' => 'x' ) );
		$this->assertSame( 'form', $empty->content_class );
		$this->assertSame( '{}', $empty->canonical_body( array() ) );
	}

	public function test_from_globals_builds_a_form_post_from_the_raw_body_whatever_post_holds(): void {
		$saved   = array( $_SERVER, $_GET, $_POST, $_COOKIE );
		$_SERVER = array(
			'REQUEST_METHOD' => 'POST',
			'REQUEST_URI'    => '/wp-json/wp/v2/posts',
			'CONTENT_TYPE'   => 'application/x-www-form-urlencoded',
		);
		$_GET    = array();
		$_COOKIE = array();
		$posts   = array(
			array(),
			array(
				'b'     => '9',
				'extra' => 'x',
			),
		);
		$bodies  = array();
		// The `/wp-json/` path is a REST route only with pretty permalinks, and only a REST route's body is read.
		$this->set_permalink_structure( '/%postname%/' );
		try {
			$GLOBALS['HTTP_RAW_POST_DATA'] = 'b=2&a[]=1&a[]=0';
			foreach ( $posts as $post ) {
				$_POST    = $post;
				$bodies[] = Request_Context::from_globals( 'wp-json' )->canonical_body( array() );
			}
		} finally {
			unset( $GLOBALS['HTTP_RAW_POST_DATA'] );
			list( $_SERVER, $_GET, $_POST, $_COOKIE ) = $saved;
			$this->set_permalink_structure( '' );
		}
		$this->assertSame( array( '{"a":["1","0"],"b":"2"}', '{"a":["1","0"],"b":"2"}' ), $bodies );
	}

	public function test_form_with_invalid_utf8_is_invalid(): void {
		$form = 'application/x-www-form-urlencoded';
		foreach ( array( 'a=%FF', '%FF=1', 'a[%C3]=1', 'a[]=%FE' ) as $body ) {
			$ctx = $this->body_ctx( 'PUT', $form, $body );
			$this->assertSame( 'invalid', $ctx->body_error, $body );
			$this->assertSame( array(), $ctx->body_params, $body );
			$this->assertSame( '', $ctx->canonical_body( array() ), $body );
		}
		$this->assertSame( 'invalid', $this->body_ctx( 'POST', $form, 'a=%FF' )->body_error );
	}

	public function test_multipart_is_not_read(): void {
		foreach ( array( 'multipart/form-data; boundary=x', 'Multipart/Mixed' ) as $type ) {
			$calls = 0;
			$ctx   = $this->body_ctx( 'POST', $type, $this->counting_reader( 'x', $calls ) );
			$this->assertSame( 'multipart', $ctx->body_error, $type );
			$this->assertNull( $ctx->content_class, $type );
			$this->assertSame( array(), $ctx->body_params, $type );
			$this->assertSame( '', $ctx->canonical_body( array() ), $type );
			$this->assertSame( 0, $calls, $type );
		}
	}

	public function test_a_declared_length_over_the_cap_is_not_read(): void {
		foreach ( array( '70000', '65537' ) as $length ) {
			$calls = 0;
			$ctx   = $this->body_ctx( 'POST', 'application/json', $this->counting_reader( '{}', $calls ), array( 'CONTENT_LENGTH' => $length ) );
			$this->assertSame( 'too_large', $ctx->body_error, $length );
			$this->assertSame( 0, $calls, $length );
		}
		$calls = 0;
		$ctx   = $this->body_ctx( 'POST', 'application/json', $this->counting_reader( '{}', $calls ), array( 'CONTENT_LENGTH' => 70000 ) );
		$this->assertSame( 'too_large', $ctx->body_error );
		$this->assertSame( 0, $calls );

		// At the cap, or not a number, the body is read.
		foreach ( array( '65536', '2', '', 'abc', '-1' ) as $length ) {
			$calls = 0;
			$ctx   = $this->body_ctx( 'POST', 'application/json', $this->counting_reader( '{}', $calls ), array( 'CONTENT_LENGTH' => $length ) );
			$this->assertNull( $ctx->body_error, $length );
			$this->assertSame( 1, $calls, $length );
		}
	}

	public function test_a_body_over_the_cap_without_a_length_is_too_large(): void {
		$prefix = '{"a":"';
		$suffix = '"}';
		$fill   = static fn( int $total ): string => $prefix . str_repeat( 'x', $total - strlen( $prefix ) - strlen( $suffix ) ) . $suffix;

		$calls = 0;
		$ctx   = $this->body_ctx( 'POST', 'application/json', $this->counting_reader( $fill( 65537 ), $calls ) );
		$this->assertSame( 'too_large', $ctx->body_error );
		$this->assertSame( 1, $calls );
		$this->assertSame( '', $ctx->canonical_body( array() ) );

		$this->assertSame( 'too_large', $this->body_ctx( 'POST', 'application/json', $fill( 65537 ) )->body_error );
		$this->assertSame( 'too_large', $this->body_ctx( 'PUT', 'text/plain', str_repeat( 'x', 65537 ) )->body_error );

		$at_cap = $this->body_ctx( 'POST', 'application/json', $fill( 65536 ) );
		$this->assertNull( $at_cap->body_error );
		$this->assertSame( 'json', $at_cap->content_class );
	}

	public function test_the_reader_is_asked_for_at_most_the_cap_plus_one_byte(): void {
		$asked  = array();
		$reader = static function ( int $max ) use ( &$asked ): ?string {
			$asked[] = $max;
			return '{}';
		};
		$this->body_ctx( 'POST', 'application/json', $reader );
		$this->assertSame( array( Request_Context::MAX_BODY_BYTES + 1 ), $asked );
	}

	public function test_invalid_bodies(): void {
		$deep = static fn( int $levels ): string => str_repeat( '{"a":', $levels ) . '1' . str_repeat( '}', $levels );
		$bad  = array(
			'broken'       => '{bad',
			'string'       => '"x"',
			'number'       => '5',
			'null'         => 'null',
			'list'         => '[1]',
			'empty list'   => '[]',
			'trailing'     => '{"a":1}x',
			'blank'        => ' ',
			'invalid utf8' => "{\"a\":\"\xff\"}",
			'depth 40'     => $deep( 40 ),
			'depth 33'     => $deep( 33 ),
			'nul key'      => "{\"\\u0000a\":1}",
		);
		foreach ( $bad as $label => $body ) {
			$ctx = $this->body_ctx( 'POST', 'application/json', $body );
			$this->assertSame( 'invalid', $ctx->body_error, $label );
			$this->assertSame( array(), $ctx->body_params, $label );
			$this->assertSame( '', $ctx->canonical_body( array() ), $label );
		}
		// json_decode() counts the scalar inside the innermost object, so 31 nested objects are within depth 32.
		$ok = $this->body_ctx( 'POST', 'application/json', $deep( 31 ) );
		$this->assertNull( $ok->body_error );
		$this->assertSame( 'json', $ok->content_class );
	}

	public function test_unsupported_and_missing_content_types(): void {
		$text = $this->body_ctx( 'POST', 'text/plain', 'hello' );
		$this->assertSame( 'unsupported', $text->body_error );
		$this->assertNull( $text->content_class );
		$this->assertSame( 'unsupported', $this->body_ctx( 'POST', 'text/plain', '' )->body_error );
		$this->assertSame( 'unsupported', $this->body_ctx( 'POST', '', '{"a":1}' )->body_error );

		foreach ( array( null, '' ) as $body ) {
			$none = $this->body_ctx( 'POST', '', $body );
			$this->assertSame( 'none', $none->content_class );
			$this->assertNull( $none->body_error );
			$this->assertSame( array(), $none->body_params );
			$this->assertSame( '', $none->canonical_body( array() ) );
		}
	}

	public function test_the_body_is_read_only_for_a_rest_post_put_or_patch(): void {
		foreach ( array( 'POST', 'PUT', 'PATCH' ) as $method ) {
			$calls = 0;
			$this->body_ctx( $method, 'application/json', $this->counting_reader( '{"a":1}', $calls ) );
			$this->assertSame( 1, $calls, $method );
		}

		// Any other method: one byte at most, to learn whether there is a body; never classified or decoded.
		foreach ( array( 'GET', 'DELETE', 'HEAD', 'OPTIONS' ) as $method ) {
			$maxes  = array();
			$reader = static function ( int $max ) use ( &$maxes ): ?string {
				$maxes[] = $max;
				return substr( '{"a":1}', 0, $max );
			};
			$ctx    = $this->body_ctx( $method, 'application/json', $reader );
			$this->assertSame( array( 1 ), $maxes, $method );
			$this->assertTrue( $ctx->has_body, $method );
			$this->assertNull( $ctx->content_class, $method );
			$this->assertNull( $ctx->body_error, $method );
			$this->assertSame( array(), $ctx->body_params, $method );
			$this->assertSame( '', $ctx->canonical_body( array() ), $method );
		}

		$calls = 0;
		$ctx   = $this->ctx(
			array(
				'REQUEST_METHOD' => 'POST',
				'REQUEST_URI'    => '/blog/contact/',
				'CONTENT_TYPE'   => 'application/json',
			),
			array(),
			array( 'body' => $this->counting_reader( '{"password":"x"}', $calls ) )
		);
		$this->assertFalse( $ctx->is_rest );
		$this->assertSame( 0, $calls );
		$this->assertNull( $ctx->content_class );
		$this->assertFalse( $ctx->has_body );
		$this->assertSame( array(), $ctx->signals );

		$calls = 0;
		$this->ctx(
			array(
				'REQUEST_METHOD' => 'POST',
				'REQUEST_URI'    => '/?rest_route=/wp/v2/posts',
			),
			array( 'rest_route' => '/wp/v2/posts' ),
			array( 'body' => $this->counting_reader( '', $calls ) )
		);
		$this->assertSame( 1, $calls, 'The rest_route form is a REST request as well.' );
	}

	public function test_a_request_without_a_body_argument_has_no_body_state_beyond_the_class(): void {
		$get = $this->ctx( array( 'REQUEST_URI' => '/wp-json/wp/v2/posts' ) );
		$this->assertNull( $get->content_class );
		$this->assertNull( $get->body_error );
		$this->assertSame( array(), $get->body_params );
		$this->assertFalse( $get->method_override );

		$post = $this->ctx(
			array(
				'REQUEST_METHOD' => 'POST',
				'REQUEST_URI'    => '/wp-json/wp/v2/posts',
			)
		);
		$this->assertSame( 'none', $post->content_class );
		$this->assertSame( array(), $post->signals );
	}

	public function test_json_body_credential_signals(): void {
		$json = 'application/json';
		$this->assertSame( array( 'password_param' ), $this->body_ctx( 'POST', $json, '{"password":"x"}' )->signals );
		$this->assertSame( array( 'wpnonce_param' ), $this->body_ctx( 'POST', $json, '{"_wpnonce":"n"}' )->signals );
		$this->assertSame( array( 'wpnonce_param', 'password_param' ), $this->body_ctx( 'PUT', $json, '{"password":"x","_wpnonce":"n"}' )->signals );
		$this->assertSame( array( 'password_param' ), $this->body_ctx( 'PATCH', $json, '{"password":0}' )->signals );
		$this->assertSame( array( 'password_param' ), $this->body_ctx( 'PATCH', $json, '{"password":{"a":1}}' )->signals );

		foreach ( array( '""', 'null', 'false', '[]', '{}' ) as $empty ) {
			$this->assertSame( array(), $this->body_ctx( 'POST', $json, '{"password":' . $empty . ',"_wpnonce":' . $empty . '}' )->signals, $empty );
		}
		$this->assertSame( array(), $this->body_ctx( 'POST', $json, '{"nested":{"password":"x","_wpnonce":"n"}}' )->signals, 'Only top-level keys count.' );
		$this->assertSame( array(), $this->body_ctx( 'POST', $json, '{"other":"x"}' )->signals );
	}

	public function test_form_body_credential_signals(): void {
		$form = 'application/x-www-form-urlencoded';
		$this->assertSame( array( 'password_param' ), $this->body_ctx( 'PUT', $form, 'password=x' )->signals );
		$this->assertSame( array( 'wpnonce_param' ), $this->body_ctx( 'PATCH', $form, '_wpnonce=n&a=1' )->signals );
		$this->assertSame( array(), $this->body_ctx( 'PUT', $form, 'password=&_wpnonce=' )->signals );
		$this->assertSame( array( 'password_param' ), $this->body_ctx( 'POST', $form, '', array(), array( 'password' => 'x' ) )->signals );
	}

	public function test_body_signals_come_on_top_of_the_other_signals(): void {
		$ctx = $this->body_ctx( 'POST', 'application/json', '{"password":"x"}', array( 'HTTP_AUTHORIZATION' => 'Bearer t' ) );
		$this->assertSame( array( 'authorization', 'password_param' ), $ctx->signals );

		// The same signal from several sources counts once.
		$both = $this->ctx(
			array(
				'REQUEST_METHOD' => 'POST',
				'REQUEST_URI'    => '/wp-json/wp/v2/posts?password=x',
				'CONTENT_TYPE'   => 'application/json',
			),
			array( 'password' => 'x' ),
			array(
				'post' => array( 'password' => 'x' ),
				'body' => '{"password":"x"}',
			)
		);
		$this->assertSame( array( 'password_param' ), $both->signals );
	}

	public function test_method_override_flag(): void {
		foreach ( array( 'GET', 'POST', 'PUT', 'DELETE' ) as $method ) {
			$server = array(
				'REQUEST_METHOD' => $method,
				'REQUEST_URI'    => '/wp-json/wp/v2/posts',
			);
			$this->assertFalse( $this->ctx( $server )->method_override, $method );
			$this->assertTrue( $this->ctx( $server + array( 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE' ) )->method_override, $method );
			$this->assertTrue( $this->ctx( $server + array( 'HTTP_X_HTTP_METHOD_OVERRIDE' => '' ) )->method_override, $method . ' empty' );
		}
		$not_rest = $this->ctx(
			array(
				'REQUEST_URI'                => '/blog/',
				'HTTP_X_HTTP_METHOD_OVERRIDE' => 'PUT',
			)
		);
		$this->assertTrue( $not_rest->method_override );
		$this->assertFalse( $this->ctx( array( 'REQUEST_URI' => '/wp-json/a', 'HTTP_X_OTHER' => 'PUT' ) )->method_override ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
	}

	public function test_a_rest_get_with_a_content_length_has_a_body_that_is_never_classified(): void {
		$ctx = $this->body_ctx( 'GET', 'application/json', '{"a":1}', array( 'CONTENT_LENGTH' => '5' ) );
		$this->assertTrue( $ctx->has_body );
		$this->assertNull( $ctx->content_class );
		$this->assertNull( $ctx->body_error );
		$this->assertSame( array(), $ctx->body_params );
		$this->assertSame( '', $ctx->canonical_body( array() ) );
		$this->assertSame( array(), $ctx->signals );
	}

	public function test_a_rest_get_with_a_transfer_encoding_has_a_body(): void {
		$calls = 0;
		$ctx   = $this->body_ctx( 'GET', '', null, array( 'HTTP_TRANSFER_ENCODING' => 'chunked' ) );
		$this->assertTrue( $ctx->has_body );
		$this->assertNull( $ctx->content_class );

		// The header settles it: no byte is read.
		$ctx = $this->body_ctx( 'GET', '', $this->counting_reader( 'x', $calls ), array( 'HTTP_TRANSFER_ENCODING' => 'chunked' ) );
		$this->assertTrue( $ctx->has_body );
		$this->assertSame( 0, $calls );
	}

	public function test_a_rest_get_without_a_declared_body_reads_one_byte(): void {
		$maxes  = array();
		$reader = static function ( int $max ) use ( &$maxes ): ?string {
			$maxes[] = $max;
			return 'x';
		};
		$ctx    = $this->body_ctx( 'GET', '', $reader );
		$this->assertTrue( $ctx->has_body );
		$this->assertSame( array( 1 ), $maxes );

		// A string body is no more than its first byte either way.
		$this->assertTrue( $this->body_ctx( 'DELETE', '', 'xyz' )->has_body );
		$this->assertFalse( $this->body_ctx( 'DELETE', '', '' )->has_body );
	}

	public function test_a_rest_get_without_a_body_has_none(): void {
		$this->assertFalse( $this->body_ctx( 'GET', '', null )->has_body );
		$this->assertFalse( $this->body_ctx( 'GET', '', '' )->has_body );
		$this->assertFalse( $this->body_ctx( 'GET', '', '', array( 'CONTENT_LENGTH' => '0' ) )->has_body );
		$this->assertFalse( $this->body_ctx( 'GET', '', null, array( 'CONTENT_LENGTH' => '000' ) )->has_body );
		$this->assertFalse( $this->body_ctx( 'GET', '', null, array( 'CONTENT_LENGTH' => '' ) )->has_body );
		$this->assertFalse( $this->body_ctx( 'GET', '', null, array( 'CONTENT_LENGTH' => 0 ) )->has_body );
		$this->assertFalse( $this->ctx( array( 'REQUEST_URI' => '/wp-json/wp/v2/posts' ) )->has_body );
	}

	public function test_a_content_length_that_is_not_all_zeros_counts_as_a_body(): void {
		foreach ( array( '1', '5', '10', '0123', 'abc', '-1', '0x', ' ' ) as $length ) {
			$this->assertTrue( $this->body_ctx( 'GET', '', null, array( 'CONTENT_LENGTH' => $length ) )->has_body, $length );
		}
		$this->assertTrue( $this->body_ctx( 'GET', '', null, array( 'CONTENT_LENGTH' => 7 ) )->has_body );
	}

	public function test_a_content_length_or_transfer_encoding_is_a_body_on_a_request_that_is_not_rest(): void {
		$calls = 0;
		$ctx   = $this->ctx(
			array(
				'REQUEST_URI'    => '/blog/',
				'CONTENT_LENGTH' => '12',
			),
			array(),
			array( 'body' => $this->counting_reader( 'x', $calls ) )
		);
		$this->assertFalse( $ctx->is_rest );
		$this->assertTrue( $ctx->has_body );
		$this->assertSame( 0, $calls );

		$chunked = $this->ctx(
			array(
				'REQUEST_URI'            => '/blog/',
				'HTTP_TRANSFER_ENCODING' => 'chunked',
			)
		);
		$this->assertTrue( $chunked->has_body );
	}

	public function test_a_rest_post_put_or_patch_has_a_body_when_one_byte_was_read(): void {
		foreach ( array( 'POST', 'PUT', 'PATCH' ) as $method ) {
			$this->assertTrue( $this->body_ctx( $method, 'application/json', '{"a":1}' )->has_body, $method );
			$this->assertFalse( $this->body_ctx( $method, 'application/json', null )->has_body, $method );
			$this->assertFalse( $this->body_ctx( $method, '', '' )->has_body, $method );
			$this->assertTrue( $this->body_ctx( $method, 'application/json', null, array( 'CONTENT_LENGTH' => '3' ) )->has_body, $method );
			$this->assertTrue( $this->body_ctx( $method, 'application/json', str_repeat( 'x', Request_Context::MAX_BODY_BYTES + 5 ) )->has_body, $method );
		}
		$this->assertTrue( $this->body_ctx( 'POST', 'multipart/form-data; boundary=x', null, array( 'CONTENT_LENGTH' => '99' ) )->has_body );
		$this->assertTrue( $this->body_ctx( 'POST', 'application/json', null, array( 'CONTENT_LENGTH' => (string) ( Request_Context::MAX_BODY_BYTES + 1 ) ) )->has_body );
	}

	public function test_a_post_with_a_json_body_is_as_before_plus_has_body(): void {
		$ctx = $this->body_ctx( 'POST', 'application/json; charset=utf-8', '{"b":1,"a":{"d":[2,1],"c":"x"},"password":"p"}', array( 'CONTENT_LENGTH' => '45' ) );
		$this->assertTrue( $ctx->has_body );
		$this->assertSame( 'json', $ctx->content_class );
		$this->assertNull( $ctx->body_error );
		$this->assertSame( '{"a":{"c":"x","d":[2,1]},"b":1,"password":"p"}', $ctx->canonical_body( array() ) );
		$this->assertSame( array( 'password_param' ), $ctx->signals );
	}

	public function test_the_host_is_lower_cased_and_loses_the_default_port_of_the_scheme(): void {
		$cases = array(
			'mixed case'               => array( 'Example.ORG', false, 'example.org' ),
			'http default port'        => array( 'example.org:80', false, 'example.org' ),
			'https default port'       => array( 'example.org:443', true, 'example.org' ),
			'other port'               => array( 'example.org:8080', false, 'example.org:8080' ),
			'https port over http'     => array( 'example.org:443', false, 'example.org:443' ),
			'http port over https'     => array( 'example.org:80', true, 'example.org:80' ),
			'port that ends in 80'     => array( 'example.org:180', false, 'example.org:180' ),
			'upper case with port'     => array( ' EXAMPLE.org:80 ', false, 'example.org' ),
			'ipv6'                     => array( '[::1]:80', false, '[::1]' ),
			'ipv6 without port'        => array( '[::80]', false, '[::80]' ),
			'nothing but the port'     => array( ':80', false, null ),
			'empty'                    => array( '', false, null ),
			'blank'                    => array( '  ', false, null ),
		);
		foreach ( $cases as $label => $case ) {
			$ctx = $this->ctx(
				array(
					'REQUEST_URI' => '/wp-json/wp/v2/posts',
					'HTTP_HOST'   => $case[0],
				),
				array(),
				array( 'ssl' => $case[1] )
			);
			$this->assertSame( $case[2], $ctx->host, $label );
		}
	}

	public function test_the_host_is_null_without_a_usable_http_host(): void {
		$this->assertNull( $this->ctx( array( 'REQUEST_URI' => '/wp-json/wp/v2/posts' ) )->host );
		$this->assertNull( $this->ctx( array( 'HTTP_HOST' => array( 'example.org' ) ) )->host );
		$this->assertNull( $this->ctx( array( 'HTTP_HOST' => 42 ) )->host );
		$this->assertNull( Request_Context::from_parts( array(), array(), array(), array(), 'wp-json', '', true, 1, true )->host );
	}

	public function test_url_variants_stay_get_without_a_body(): void {
		$this->set_permalink_structure( '/%postname%/' );
		foreach ( Request_Context::url_variants( '/wp-json/myplugin/v1/x?a=1', 'wp-json' ) as $variant ) {
			$this->assertSame( 'GET', $variant->method );
			$this->assertNull( $variant->content_class );
			$this->assertNull( $variant->body_error );
			$this->assertSame( array(), $variant->body_params );
			$this->assertFalse( $variant->method_override );
			$this->assertFalse( $variant->has_body );
			$this->assertNull( $variant->host );
		}
		$this->set_permalink_structure( '' );
	}

	public function test_from_globals_reads_the_body_from_the_raw_post_data_global(): void {
		$saved   = array( $_SERVER, $_GET, $_POST, $_COOKIE );
		$_SERVER = array(
			'REQUEST_METHOD' => 'PUT',
			'REQUEST_URI'    => '/?rest_route=/wp/v2/posts',
			'CONTENT_TYPE'   => 'application/json',
		);
		$_GET    = array( 'rest_route' => '/wp/v2/posts' );
		$_POST   = array();
		$_COOKIE = array();
		try {
			$GLOBALS['HTTP_RAW_POST_DATA'] = '{"b":1,"a":2}';
			$ctx                           = Request_Context::from_globals( 'wp-json' );
			$this->assertSame( 'json', $ctx->content_class );
			$this->assertSame( '{"a":2,"b":1}', $ctx->canonical_body( array() ) );

			$GLOBALS['HTTP_RAW_POST_DATA'] = str_repeat( 'x', Request_Context::MAX_BODY_BYTES + 100 );
			$this->assertSame( 'too_large', Request_Context::from_globals( 'wp-json' )->body_error );

			// Not a string: the request stream, which is empty here.
			$GLOBALS['HTTP_RAW_POST_DATA'] = array( 'x' );
			$this->assertSame( 'json', Request_Context::from_globals( 'wp-json' )->content_class );
			$this->assertSame( '{}', Request_Context::from_globals( 'wp-json' )->canonical_body( array() ) );

			// A GET is never classified or decoded, but its first byte tells that it carries a body.
			$_SERVER['REQUEST_METHOD']     = 'GET';
			$GLOBALS['HTTP_RAW_POST_DATA'] = '{"a":1}';
			$get                           = Request_Context::from_globals( 'wp-json' );
			$this->assertNull( $get->content_class );
			$this->assertNull( $get->body_error );
			$this->assertSame( array(), $get->body_params );
			$this->assertTrue( $get->has_body );

			// No body: the request stream is empty here.
			unset( $GLOBALS['HTTP_RAW_POST_DATA'] );
			$this->assertFalse( Request_Context::from_globals( 'wp-json' )->has_body );

			// The host comes from `HTTP_HOST`.
			$_SERVER['HTTP_HOST'] = 'Example.ORG:80';
			$this->assertSame( 'example.org', Request_Context::from_globals( 'wp-json' )->host );
		} finally {
			unset( $GLOBALS['HTTP_RAW_POST_DATA'] );
			list( $_SERVER, $_GET, $_POST, $_COOKIE ) = $saved;
		}
	}

	public function test_the_raw_body_is_not_kept_on_the_context(): void {
		$body = '{"secret":"value-that-must-not-be-kept"}';
		$ctx  = $this->body_ctx( 'POST', 'application/json', $body );
		foreach ( ( new ReflectionObject( $ctx ) )->getProperties() as $property ) {
			$value = $property->getValue( $ctx );
			$this->assertNotSame( $body, $value, $property->getName() );
			if ( is_string( $value ) ) {
				$this->assertStringNotContainsString( $body, $value, $property->getName() );
			}
		}
	}

	public function test_context_is_readonly(): void {
		$ctx = $this->ctx( array() );
		$this->expectException( Error::class );
		// @phpstan-ignore-next-line
		$ctx->method = 'POST';
	}
}
