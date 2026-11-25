<?php
/**
 * Tests for the legacy hit filter pass.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Legacy\Hit_Filter_Pass;
use Rockfire\Rest_Api_Cache\Legacy\Hook_Registry;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Hit_Filters_Interface;
use Rockfire\Rest_Api_Cache\Serving\Lookup;
use Rockfire\Rest_Api_Cache\Storage\Cached_Response;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Hit_Filter_Pass
 * @covers \Rockfire\Rest_Api_Cache\Serving\Lookup::hit_filters
 */
class Test_Hit_Filter_Pass extends Rfrac_Policy_Test_Case {

	private const URI = '/wp-json/wp/v2/posts?per_page=5';

	public function set_up() {
		parent::set_up();
		delete_option( Failure_Recorder::OPTION );
	}

	public function tear_down() {
		delete_option( Failure_Recorder::OPTION );
		parent::tear_down();
	}

	/**
	 * A cacheable decision of the default route, with its context and config.
	 *
	 * @param string                   $uri    Request URI.
	 * @param array<string,mixed>      $args   Request arguments (see {@see Rfrac_Policy_Test_Case::request()}).
	 * @param array<string,mixed>|null $routes Routes of the config (default: `/wp/v2/posts`).
	 *
	 * @return array{0:Policy_Decision,1:Request_Context,2:Compiled_Config}
	 */
	private function cacheable( string $uri = self::URI, array $args = array(), ?array $routes = null ): array {
		$config   = $this->config( $routes );
		$context  = $this->request( $uri, $args );
		$decision = $this->policy()->evaluate( $context, $config );
		$this->assertCacheable( $decision );
		return array( $decision, $context, $config );
	}

	/**
	 * A stored response.
	 *
	 * @param string               $payload Payload.
	 * @param array<string,string> $headers Headers.
	 *
	 * @return Cached_Response
	 */
	private function hit( string $payload = '{"a":1}', array $headers = array( 'X-WP-Total' => '3' ) ): Cached_Response {
		return new Cached_Response( 7, 'stored-key', $payload, $headers, 1000, 2000 );
	}

	/**
	 * A callback that throws.
	 *
	 * @return Closure
	 */
	private static function thrower(): Closure {
		return static function () {
			throw new RuntimeException( 'callback failed' );
		};
	}

	/**
	 * Assert that the engine recorded a failure of a hook, for the route of the request.
	 *
	 * @param string $name    Name of the hook without its prefix.
	 * @param string $message Part of the recorded message (default: what the throwing callback says).
	 *
	 * @return void
	 */
	private function assertRecorded( string $name, string $message = 'callback failed' ): void {
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last, 'A failure was recorded.' );
		$this->assertSame( 'hook:wp_rest_cache/' . $name, $last['context'] );
		$this->assertStringContainsString( $message, $last['message'] );
		$stored = get_option( Failure_Recorder::OPTION );
		$this->assertIsArray( $stored );
		$this->assertSame( '/wp/v2/posts', $stored['subject'] );
	}

	/**
	 * Data provider: values a filter answers that are truthy.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function truthy(): array {
		return array(
			'true'   => array( true ),
			'one'    => array( 1 ),
			'string' => array( 'yes' ),
		);
	}

	/**
	 * Data provider: values a filter answers that are not truthy.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function falsy(): array {
		return array(
			'false'        => array( false ),
			'zero'         => array( 0 ),
			'empty string' => array( '' ),
			'null'         => array( null ),
			'empty array'  => array( array() ),
		);
	}

	public function test_it_is_a_hit_filter_set(): void {
		$this->assertInstanceOf( Hit_Filters_Interface::class, new Hit_Filter_Pass() );
	}

	// Decide.

	public function test_a_decision_passes_unchanged_without_callbacks(): void {
		list( $decision, $context, $config ) = $this->cacheable();

		$this->assertSame( $decision, ( new Hit_Filter_Pass() )->decide( $decision, $context, $config ) );
		$this->assertNull( Failure_Recorder::last() );
	}

	/**
	 * @dataProvider truthy
	 *
	 * @param mixed $answer Answer of the filter.
	 */
	public function test_a_truthy_skip_caching_bypasses_as_filtered_and_keeps_the_rule( $answer ): void {
		list( $decision, $context, $config ) = $this->cacheable();
		$args                                = null;
		add_filter(
			'wp_rest_cache/skip_caching',
			static function ( $skip ) use ( $answer, &$args ) {
				$args = func_get_args();
				return $answer;
			}
		);

		$result = ( new Hit_Filter_Pass() )->decide( $decision, $context, $config );

		$this->assertFalse( $result->cacheable );
		$this->assertSame( Bypass_Reason::FILTERED, $result->reason );
		$this->assertSame( $decision->rule, $result->rule );
		$this->assertSame( array( false ), $args, 'The filter gets the default `false` and nothing else.' );
	}

	/**
	 * @dataProvider falsy
	 *
	 * @param mixed $answer Answer of the filter.
	 */
	public function test_a_falsy_skip_caching_changes_nothing( $answer ): void {
		list( $decision, $context, $config ) = $this->cacheable();
		add_filter( 'wp_rest_cache/skip_caching', static fn() => $answer );

		$this->assertSame( $decision, ( new Hit_Filter_Pass() )->decide( $decision, $context, $config ) );
	}

	public function test_skip_cache_meets_allow_skip_cache_with_the_default_of_the_config(): void {
		list( $decision, $context, $config ) = $this->cacheable( '/wp-json/wp/v2/posts?skip_cache=1' );
		$defaults                            = array();
		add_filter(
			'wp_rest_cache/allow_skip_cache',
			static function ( $allow ) use ( &$defaults ) {
				$defaults[] = $allow;
				return $allow;
			}
		);

		$this->assertSame( $decision, ( new Hit_Filter_Pass() )->decide( $decision, $context, $config ), 'The configuration does not honour skip_cache.' );
		$this->assertSame( array( false ), $defaults, 'The default is the flag of the configuration.' );

		$honoured = $this->config( null, array( 'allow_skip_cache' => true ) );
		$result   = ( new Hit_Filter_Pass() )->decide( $decision, $context, $honoured );

		$this->assertSame( Bypass_Reason::SKIP_CACHE, $result->reason );
		$this->assertSame( $decision->rule, $result->rule );
		$this->assertSame( array( false, true ), $defaults );
	}

	/**
	 * @dataProvider truthy
	 *
	 * @param mixed $answer Answer of the filter.
	 */
	public function test_a_truthy_allow_skip_cache_bypasses_a_request_with_skip_cache( $answer ): void {
		list( $decision, $context, $config ) = $this->cacheable( '/wp-json/wp/v2/posts?skip_cache=1' );
		add_filter( 'wp_rest_cache/allow_skip_cache', static fn() => $answer );

		$result = ( new Hit_Filter_Pass() )->decide( $decision, $context, $config );

		$this->assertFalse( $result->cacheable );
		$this->assertSame( Bypass_Reason::SKIP_CACHE, $result->reason );
	}

	public function test_allow_skip_cache_is_only_asked_for_a_request_with_skip_cache(): void {
		list( $decision, $context, $config ) = $this->cacheable();
		$calls                               = 0;
		add_filter(
			'wp_rest_cache/allow_skip_cache',
			static function () use ( &$calls ) {
				++$calls;
				return true;
			}
		);

		$this->assertSame( $decision, ( new Hit_Filter_Pass() )->decide( $decision, $context, $config ) );
		$this->assertSame( 0, $calls );
	}

	public function test_skip_caching_wins_and_ends_the_decision(): void {
		list( $decision, $context, $config ) = $this->cacheable( '/wp-json/wp/v2/posts?skip_cache=1' );
		$asked                               = false;
		add_filter( 'wp_rest_cache/skip_caching', '__return_true' );
		add_filter(
			'wp_rest_cache/allow_skip_cache',
			static function ( $allow ) use ( &$asked ) {
				$asked = true;
				return $allow;
			}
		);

		$result = ( new Hit_Filter_Pass() )->decide( $decision, $context, $config );

		$this->assertSame( Bypass_Reason::FILTERED, $result->reason );
		$this->assertFalse( $asked );
	}

	public function test_a_throwing_skip_caching_is_recorded_and_fails_closed(): void {
		list( $decision, $context, $config ) = $this->cacheable();
		add_filter( 'wp_rest_cache/skip_caching', self::thrower() );

		$result = ( new Hit_Filter_Pass() )->decide( $decision, $context, $config );

		$this->assertFalse( $result->cacheable );
		$this->assertSame( Bypass_Reason::FILTERED, $result->reason );
		$this->assertSame( $decision->rule, $result->rule );
		$this->assertRecorded( 'skip_caching' );
	}

	public function test_a_throwing_allow_skip_cache_is_recorded_and_fails_closed(): void {
		list( $decision, $context, $config ) = $this->cacheable( '/wp-json/wp/v2/posts?skip_cache=1' );
		add_filter( 'wp_rest_cache/allow_skip_cache', self::thrower() );

		$result = ( new Hit_Filter_Pass() )->decide( $decision, $context, $config );

		$this->assertSame( Bypass_Reason::FILTERED, $result->reason );
		$this->assertRecorded( 'allow_skip_cache' );
	}

	// Key.

	public function test_the_key_is_returned_as_it_is_without_callbacks_and_for_an_identical_result(): void {
		list( $decision, $context, $config ) = $this->cacheable();
		$pass                                = new Hit_Filter_Pass();

		$this->assertSame( 'engine-key', $pass->key( 'engine-key', $context, $decision, $config ) );

		add_filter( 'wp_rest_cache/cache_key', static fn( $key ) => $key );
		$this->assertSame( 'engine-key', $pass->key( 'engine-key', $context, $decision, $config ) );
	}

	public function test_the_key_filter_gets_the_key_the_uri_the_vary_headers_and_the_method(): void {
		$routes                              = array( $this->route( '/wp/v2/posts', array( 'vary_headers' => array( 'accept-language' ) ) ) );
		list( $decision, $context, $config ) = $this->cacheable( self::URI, array( 'server' => array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) ), $routes );
		$args                                = null;
		add_filter(
			'wp_rest_cache/cache_key',
			static function ( $key ) use ( &$args ) {
				$args = func_get_args();
				return $key;
			},
			10,
			4
		);

		( new Hit_Filter_Pass() )->key( 'engine-key', $context, $decision, $config );

		$this->assertSame( array( 'engine-key', '/wp-json/wp/v2/posts?per_page=5', array( 'accept-language' => 'nl' ), 'GET' ), $args );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function methods(): array {
		return array(
			'GET'   => array( 'GET' ),
			'POST'  => array( 'POST' ),
			'PUT'   => array( 'PUT' ),
			'PATCH' => array( 'PATCH' ),
		);
	}

	/**
	 * @dataProvider methods
	 *
	 * @param string $method Method of the request.
	 */
	public function test_the_key_filter_gets_the_method_of_the_request( string $method ): void {
		list( $decision, , $config ) = $this->cacheable();
		$context                     = $this->request( self::URI, array( 'server' => array( 'REQUEST_METHOD' => $method ) ) );
		$this->assertSame( $method, $context->method );
		$seen = null;
		add_filter(
			'wp_rest_cache/cache_key',
			static function ( $key, $uri, $headers, $given ) use ( &$seen ) {
				$seen = $given;
				return $key;
			},
			10,
			4
		);

		( new Hit_Filter_Pass() )->key( 'engine-key', $context, $decision, $config );

		$this->assertSame( $method, $seen );
	}

	public function test_the_vary_cookies_are_not_passed_as_request_headers(): void {
		$routes                              = array( $this->route( '/wp/v2/posts', array( 'vary_headers' => array( 'accept-language' ) ) ) );
		list( $decision, $context, $config ) = $this->cacheable( self::URI, array( 'server' => array( 'HTTP_ACCEPT_LANGUAGE' => 'nl' ) ), $routes );
		$this->assertNotNull( $decision->rule );
		$with_cookie = Policy_Decision::cache( $decision->rule, $decision->key_params, array( 'accept-language' => 'nl', 'cookie:currency' => 'EUR' ) );
		$seen        = null;
		add_filter(
			'wp_rest_cache/cache_key',
			static function ( $key, $uri, $headers ) use ( &$seen ) {
				$seen = $headers;
				return $key;
			},
			10,
			3
		);

		( new Hit_Filter_Pass() )->key( 'engine-key', $context, $with_cookie, $config );

		$this->assertSame( array( 'accept-language' => 'nl' ), $seen );
	}

	public function test_another_string_is_hashed_into_a_key(): void {
		list( $decision, $context, $config ) = $this->cacheable();
		add_filter( 'wp_rest_cache/cache_key', static fn( $key ) => $key . '-nl' );

		$key = ( new Hit_Filter_Pass() )->key( 'engine-key', $context, $decision, $config );

		$this->assertSame( Cache_Key::replaced( 'engine-key-nl', $context, $decision ), $key );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) $key );
	}

	/**
	 * A cacheable decision of an opted-in request with a body, with its context and config.
	 *
	 * @param string $method       Method of the request.
	 * @param string $body         Raw body.
	 * @param string $content_type Content-Type of the body.
	 *
	 * @return array{0:Policy_Decision,1:Request_Context,2:Compiled_Config}
	 */
	private function cacheable_with_body( string $method, string $body, string $content_type = 'application/json' ): array {
		$config = $this->config( array( $this->route( '/wp/v2/posts', array( 'methods' => array( 'GET', 'POST', 'PUT', 'PATCH' ) ) ) ) );
		$post   = array();
		if ( 'POST' === $method && 'application/x-www-form-urlencoded' === $content_type ) {
			parse_str( $body, $post );
		}
		$context = $this->request(
			'/wp-json/wp/v2/posts',
			array(
				'server' => array(
					'REQUEST_METHOD' => $method,
					'CONTENT_TYPE'   => $content_type,
				),
				'post'   => $post,
				'body'   => $body,
			)
		);

		$decision = $this->policy()->evaluate( $context, $config );
		$this->assertCacheable( $decision );
		$this->assertNotNull( $decision->body_hash, 'A non-GET decision carries the body hash.' );
		return array( $decision, $context, $config );
	}

	/**
	 * Replace the key of every request by a string a callback of the original builds: the URI only, no body.
	 *
	 * @return void
	 */
	private function replace_key_by_uri(): void {
		add_filter( 'wp_rest_cache/cache_key', static fn( $key, $uri ) => 'custom:' . $uri, 10, 2 );
	}

	public function test_a_replaced_key_of_a_get_is_the_replaced_key_of_the_engine_components(): void {
		list( $decision, $context, $config ) = $this->cacheable();
		$this->replace_key_by_uri();

		$key = ( new Hit_Filter_Pass() )->key( 'engine-key', $context, $decision, $config );

		$this->assertSame( Cache_Key::replaced( 'custom:/wp-json/wp/v2/posts?per_page=5', $context, $decision ), $key );
		$this->assertNotSame( hash( 'sha256', 'custom:/wp-json/wp/v2/posts?per_page=5' ), $key, 'The replacement alone is no longer the key.' );
	}

	public function test_a_replaced_key_keeps_the_vary_cookie_entries_of_the_decision(): void {
		list( $decision, $context, $config ) = $this->cacheable();
		$this->assertNotNull( $decision->rule );
		$eur = Policy_Decision::cache( $decision->rule, $decision->key_params, array( 'cookie:cur' => 'EUR' ) );
		$usd = Policy_Decision::cache( $decision->rule, $decision->key_params, array( 'cookie:cur' => 'USD' ) );
		add_filter( 'wp_rest_cache/cache_key', static fn() => 'prices' );
		$pass = new Hit_Filter_Pass();

		$eur_key = $pass->key( 'engine-key', $context, $eur, $config );
		$usd_key = $pass->key( 'engine-key', $context, $usd, $config );

		$this->assertNotNull( $eur_key );
		$this->assertNotSame( $eur_key, $usd_key, 'One replacement for two currencies is two keys.' );
		$this->assertSame( Cache_Key::replaced( 'prices', $context, $eur ), $eur_key );
	}

	public function test_a_replaced_key_differs_per_body_of_a_post(): void {
		$pass = new Hit_Filter_Pass();
		$this->replace_key_by_uri();

		list( $first, $first_context, $config ) = $this->cacheable_with_body( 'POST', '{"q":1}' );
		list( $other, $other_context )          = $this->cacheable_with_body( 'POST', '{"q":2}' );
		list( $same, $same_context )            = $this->cacheable_with_body( 'POST', '{ "q" : 1 }' );

		$first_key = $pass->key( 'engine-key', $first_context, $first, $config );
		$other_key = $pass->key( 'engine-key', $other_context, $other, $config );
		$same_key  = $pass->key( 'engine-key', $same_context, $same, $config );

		$this->assertNotNull( $first_key );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $first_key );
		$this->assertSame( Cache_Key::replaced( 'custom:/wp-json/wp/v2/posts', $first_context, $first ), $first_key );
		$this->assertNotSame( $first_key, $other_key, 'Another body is another key.' );
		$this->assertSame( $first_key, $same_key, 'The same canonical body is the same key.' );
	}

	public function test_a_replaced_key_of_a_post_is_not_the_key_of_a_get_of_the_same_uri(): void {
		$pass = new Hit_Filter_Pass();
		$this->replace_key_by_uri();

		list( $get, $get_context, $config ) = $this->cacheable( '/wp-json/wp/v2/posts' );
		list( $post, $post_context )        = $this->cacheable_with_body( 'POST', '{"q":1}' );

		$get_key  = $pass->key( 'engine-key', $get_context, $get, $config );
		$post_key = $pass->key( 'engine-key', $post_context, $post, $config );

		$this->assertSame( Cache_Key::replaced( 'custom:/wp-json/wp/v2/posts', $get_context, $get ), $get_key );
		$this->assertNotNull( $post_key );
		$this->assertNotSame( $get_key, $post_key, 'A POST never shares the key of the GET of its URI.' );
	}

	public function test_a_replaced_key_differs_per_method_and_body_class(): void {
		$pass = new Hit_Filter_Pass();
		$this->replace_key_by_uri();

		list( $post, $post_context, $config ) = $this->cacheable_with_body( 'POST', '{"q":"1"}' );
		list( $put, $put_context )            = $this->cacheable_with_body( 'PUT', '{"q":"1"}' );
		list( $form, $form_context )          = $this->cacheable_with_body( 'POST', 'q=1', 'application/x-www-form-urlencoded' );

		$this->assertSame( $post->body_hash, $put->body_hash, 'The same canonical body in two methods …' );
		$this->assertSame( $post->body_hash, $form->body_hash, '… and in two classes.' );

		$post_key = $pass->key( 'engine-key', $post_context, $post, $config );
		$put_key  = $pass->key( 'engine-key', $put_context, $put, $config );
		$form_key = $pass->key( 'engine-key', $form_context, $form, $config );

		$this->assertNotNull( $post_key );
		$this->assertNotSame( $post_key, $put_key, 'Another method is another key.' );
		$this->assertNotSame( $post_key, $form_key, 'Another body class is another key.' );
		$this->assertNotSame( $put_key, $form_key );
	}

	public function test_an_identical_result_of_a_post_keeps_the_key_of_the_engine(): void {
		list( $decision, $context, $config ) = $this->cacheable_with_body( 'POST', '{"q":1}' );
		add_filter( 'wp_rest_cache/cache_key', static fn( $key ) => $key );

		$this->assertSame( 'engine-key', ( new Hit_Filter_Pass() )->key( 'engine-key', $context, $decision, $config ) );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function bad_keys(): array {
		return array(
			'empty string' => array( '' ),
			'null'         => array( null ),
			'false'        => array( false ),
			'true'         => array( true ),
			'integer'      => array( 42 ),
			'array'        => array( array( 'engine-key' ) ),
		);
	}

	/**
	 * @dataProvider bad_keys
	 *
	 * @param mixed $answer Answer of the filter.
	 */
	public function test_anything_but_a_non_empty_string_bypasses_the_cache_and_is_recorded( $answer ): void {
		list( $decision, $context, $config ) = $this->cacheable();
		add_filter( 'wp_rest_cache/cache_key', static fn() => $answer );

		$this->assertNull( ( new Hit_Filter_Pass() )->key( 'engine-key', $context, $decision, $config ) );
		$this->assertRecorded( 'cache_key', 'instead of a non-empty string' );
	}

	public function test_a_key_that_is_kept_or_hashed_records_nothing(): void {
		list( $decision, $context, $config ) = $this->cacheable();
		$pass                                = new Hit_Filter_Pass();

		add_filter( 'wp_rest_cache/cache_key', static fn( $key ) => $key );
		$this->assertSame( 'engine-key', $pass->key( 'engine-key', $context, $decision, $config ) );
		remove_all_filters( 'wp_rest_cache/cache_key' );

		add_filter( 'wp_rest_cache/cache_key', static fn( $key ) => $key . '-nl' );
		$this->assertNotNull( $pass->key( 'engine-key', $context, $decision, $config ) );

		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_a_throwing_cache_key_is_recorded_and_bypasses_the_cache(): void {
		list( $decision, $context, $config ) = $this->cacheable();
		add_filter( 'wp_rest_cache/cache_key', self::thrower() );

		$this->assertNull( ( new Hit_Filter_Pass() )->key( 'engine-key', $context, $decision, $config ) );
		$this->assertRecorded( 'cache_key' );
	}

	// Response.

	public function test_a_hit_without_callbacks_is_served_byte_for_byte_with_its_identity(): void {
		list( , $context, $config ) = $this->cacheable();
		$hit                        = $this->hit( "{ \"a\" : 1,\n \"b\":[] }" );

		$response = ( new Hit_Filter_Pass() )->response( $hit, $context, $config );

		$this->assertInstanceOf( Cached_Response::class, $response );
		$this->assertNotSame( $hit, $response );
		$this->assertSame( $hit->payload, $response->payload, 'The payload is not decoded without a callback on the output.' );
		$this->assertSame( $hit->headers, $response->headers );
		$this->assertSame( array( 7, 'stored-key', 1000, 2000 ), array( $response->entry_id, $response->cache_key, $response->created_at, $response->expires_at ) );
	}

	public function test_the_output_filter_gets_the_decoded_objects_and_its_answer_is_encoded(): void {
		list( , $context, $config ) = $this->cacheable();
		$seen                       = null;
		add_filter(
			'wp_rest_cache/filter_cache_output',
			static function ( $data ) use ( &$seen ) {
				$seen        = $data;
				$data->extra = array( 'x' => true );
				return $data;
			}
		);

		$response = ( new Hit_Filter_Pass() )->response( $this->hit( '{"a":1,"list":[{"b":2}]}' ), $context, $config );

		$this->assertInstanceOf( stdClass::class, $seen );
		$this->assertInstanceOf( Cached_Response::class, $response );
		$this->assertSame( '{"a":1,"list":[{"b":2}],"extra":{"x":true}}', $response->payload );
	}

	public function test_a_payload_that_does_not_decode_serves_nothing_and_asks_no_callback(): void {
		list( , $context, $config ) = $this->cacheable();
		$asked                      = false;
		add_filter(
			'wp_rest_cache/filter_cache_output',
			static function ( $data ) use ( &$asked ) {
				$asked = true;
				return $data;
			}
		);

		$this->assertNull( ( new Hit_Filter_Pass() )->response( $this->hit( '{"broken"' ), $context, $config ) );
		$this->assertFalse( $asked );
		$this->assertRecorded( 'filter_cache_output', 'could not be decoded' );
	}

	public function test_an_answer_that_cannot_be_encoded_serves_nothing_and_is_recorded(): void {
		list( , $context, $config ) = $this->cacheable();
		add_filter( 'wp_rest_cache/filter_cache_output', static fn() => NAN );

		$this->assertNull( ( new Hit_Filter_Pass() )->response( $this->hit(), $context, $config ) );
		$this->assertRecorded( 'filter_cache_output', 'could not be encoded' );
	}

	public function test_a_hit_that_is_served_records_nothing(): void {
		list( , $context, $config ) = $this->cacheable();
		add_filter( 'wp_rest_cache/filter_cache_output', static fn( $data ) => $data );
		add_filter( 'wp_rest_cache/pre_output_headers', static fn( $headers ) => $headers );

		$this->assertInstanceOf( Cached_Response::class, ( new Hit_Filter_Pass() )->response( $this->hit(), $context, $config ) );
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_the_headers_filter_gets_the_headers_and_the_uri_and_its_scalars_become_strings(): void {
		list( , $context, $config ) = $this->cacheable();
		$args                       = null;
		add_filter(
			'wp_rest_cache/pre_output_headers',
			static function ( $headers ) use ( &$args ) {
				$args                = func_get_args();
				$headers['X-Number'] = 5;
				$headers['X-Flag']   = true;
				return $headers;
			},
			10,
			2
		);

		$response = ( new Hit_Filter_Pass() )->response( $this->hit(), $context, $config );

		$this->assertSame( array( array( 'X-WP-Total' => '3' ), '/wp-json/wp/v2/posts?per_page=5' ), $args );
		$this->assertInstanceOf( Cached_Response::class, $response );
		$this->assertSame( array( 'X-WP-Total' => '3', 'X-Number' => '5', 'X-Flag' => '1' ), $response->headers );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function bad_headers(): array {
		return array(
			'not an array'          => array( 'X-A: 1' ),
			'null'                  => array( null ),
			'a non-scalar value'    => array( array( 'X-A' => array( '1' ) ) ),
			'an object value'       => array( array( 'X-A' => new stdClass() ) ),
			'a null value'          => array( array( 'X-A' => null ) ),
			'a good then a bad one' => array( array( 'X-A' => '1', 'X-B' => array() ) ),
		);
	}

	/**
	 * @dataProvider bad_headers
	 *
	 * @param mixed $answer Answer of the filter.
	 */
	public function test_headers_that_are_not_an_array_of_scalars_serve_nothing_and_are_recorded( $answer ): void {
		list( , $context, $config ) = $this->cacheable();
		add_filter( 'wp_rest_cache/pre_output_headers', static fn() => $answer );

		$this->assertNull( ( new Hit_Filter_Pass() )->response( $this->hit(), $context, $config ) );
		$this->assertRecorded( 'pre_output_headers', 'the hit was not served' );
	}

	public function test_a_throwing_output_filter_is_recorded_and_serves_nothing(): void {
		list( , $context, $config ) = $this->cacheable();
		add_filter( 'wp_rest_cache/filter_cache_output', self::thrower() );

		$this->assertNull( ( new Hit_Filter_Pass() )->response( $this->hit(), $context, $config ) );
		$this->assertRecorded( 'filter_cache_output' );
	}

	public function test_a_throwing_headers_filter_is_recorded_and_serves_nothing(): void {
		list( , $context, $config ) = $this->cacheable();
		add_filter( 'wp_rest_cache/pre_output_headers', self::thrower() );

		$this->assertNull( ( new Hit_Filter_Pass() )->response( $this->hit(), $context, $config ) );
		$this->assertRecorded( 'pre_output_headers' );
	}

	// CORS.

	public function test_cors_follows_the_configuration_without_a_callback(): void {
		list( , $context )   = $this->cacheable();
		$pass                = new Hit_Filter_Pass();
		$on                  = $this->config( null, array( 'cors_on_hits' => true ) );
		$off                 = $this->config( null, array( 'cors_on_hits' => false ) );

		$this->assertTrue( $pass->cors( $context, $on ) );
		$this->assertFalse( $pass->cors( $context, $off ) );
	}

	public function test_the_cors_filter_starts_from_false_and_is_only_asked_while_the_configuration_sends_the_headers(): void {
		list( , $context ) = $this->cacheable();
		$seen              = array();
		add_filter(
			'wp_rest_cache/disable_cors_headers',
			static function ( $disable ) use ( &$seen ) {
				$seen[] = $disable;
				return $disable;
			}
		);

		( new Hit_Filter_Pass() )->cors( $context, $this->config( null, array( 'cors_on_hits' => true ) ) );
		( new Hit_Filter_Pass() )->cors( $context, $this->config( null, array( 'cors_on_hits' => false ) ) );

		$this->assertSame( array( false ), $seen, 'Like its fold at compile time, the filter can only turn the headers off.' );
	}

	public function test_only_true_disables_the_cors_headers(): void {
		list( , $context ) = $this->cacheable();
		$config            = $this->config( null, array( 'cors_on_hits' => true ) );
		$pass              = new Hit_Filter_Pass();
		$answer            = true;
		add_filter( 'wp_rest_cache/disable_cors_headers', static function () use ( &$answer ) {
			return $answer;
		} );

		$this->assertFalse( $pass->cors( $context, $config ), 'true turns them off.' );
		foreach ( array( 1, '1', 'yes', false, null, array() ) as $answer ) {
			$this->assertTrue( $pass->cors( $context, $config ), 'Only true disables.' );
		}
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function cors_answers(): array {
		return array(
			'true'         => array( true ),
			'false'        => array( false ),
			'null'         => array( null ),
			'zero'         => array( 0 ),
			'empty string' => array( '' ),
			'one'          => array( 1 ),
			'string'       => array( 'yes' ),
			'empty array'  => array( array() ),
		);
	}

	/**
	 * @dataProvider cors_answers
	 *
	 * @param mixed $answer Answer of the filter.
	 */
	public function test_a_cors_filter_can_never_turn_the_headers_on_when_the_configuration_has_them_off( $answer ): void {
		list( , $context ) = $this->cacheable();
		add_filter( 'wp_rest_cache/disable_cors_headers', static fn() => $answer );

		$this->assertFalse( ( new Hit_Filter_Pass() )->cors( $context, $this->config( null, array( 'cors_on_hits' => false ) ) ) );
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_a_throwing_cors_filter_is_not_asked_when_the_configuration_has_the_headers_off(): void {
		list( , $context ) = $this->cacheable();
		add_filter( 'wp_rest_cache/disable_cors_headers', self::thrower() );

		$this->assertFalse( ( new Hit_Filter_Pass() )->cors( $context, $this->config( null, array( 'cors_on_hits' => false ) ) ) );
		$this->assertNull( Failure_Recorder::last(), 'Nothing was asked, so nothing failed.' );
	}

	public function test_a_throwing_cors_filter_is_recorded_and_turns_the_headers_off(): void {
		list( , $context ) = $this->cacheable();
		add_filter( 'wp_rest_cache/disable_cors_headers', self::thrower() );

		$this->assertFalse( ( new Hit_Filter_Pass() )->cors( $context, $this->config( null, array( 'cors_on_hits' => true ) ) ) );
		$this->assertRecorded( 'disable_cors_headers' );
	}

	// Source and wiring.

	public function test_the_source_applies_only_the_six_hit_filters_and_fires_nothing_else(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/legacy/class-hit-filter-pass.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertNotSame( '', $source );

		preg_match_all( '/apply_filters\(\s*[\'"]([^\'"]+)[\'"]/', $source, $found );
		$this->assertSame( substr_count( $source, 'apply_filters(' ), count( $found[1] ), 'Every call names its hook with a literal.' );
		foreach ( $found[1] as $name ) {
			$this->assertContains( $name, Hook_Registry::hit_filters() );
		}
		$this->assertEqualsCanonicalizing( Hook_Registry::hit_filters(), array_values( array_unique( $found[1] ) ), 'All six are applied.' );

		$this->assertDoesNotMatchRegularExpression( '/do_action|_deprecated|require|include/', $source );
	}

	public function test_lookup_spells_the_constant_of_the_registry(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/serving/class-lookup.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringContainsString( "defined( '" . Hook_Registry::HIT_FILTERS_CONSTANT . "' )", $source );
		$this->assertStringContainsString( "constant( '" . Hook_Registry::HIT_FILTERS_CONSTANT . "' )", $source );
	}

	public function test_a_site_without_the_constant_gets_no_pass(): void {
		$this->assertFalse( defined( Hook_Registry::HIT_FILTERS_CONSTANT ) );
		$this->assertNull( Lookup::hit_filters() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_defining_the_constant_gives_the_pass(): void {
		define( 'RFRAC_LEGACY_HIT_FILTERS', true );

		$pass = Lookup::hit_filters();

		$this->assertInstanceOf( Hit_Filter_Pass::class, $pass );
		$this->assertNotSame( $pass, Lookup::hit_filters(), 'Every call builds a pass; the container resolves it once.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_defining_the_constant_as_false_keeps_the_pass_off(): void {
		define( 'RFRAC_LEGACY_HIT_FILTERS', false );

		$this->assertNull( Lookup::hit_filters() );
	}

	// Deprecation.

	public function test_a_hit_applies_every_filter_as_a_plain_filter_without_a_deprecation_notice(): void {
		list( $decision, $context, $config ) = $this->cacheable();
		$pass                                = new Hit_Filter_Pass();
		$deprecated                          = array();
		add_action(
			'deprecated_hook_run',
			static function ( $hook ) use ( &$deprecated ) {
				$deprecated[] = $hook;
			}
		);

		add_filter( 'wp_rest_cache/skip_caching', '__return_false' );
		add_filter( 'wp_rest_cache/allow_skip_cache', '__return_false' );
		add_filter( 'wp_rest_cache/cache_key', static fn( $key ) => $key );
		add_filter( 'wp_rest_cache/filter_cache_output', static fn( $data ) => $data );
		add_filter( 'wp_rest_cache/pre_output_headers', static fn( $headers ) => $headers );
		add_filter( 'wp_rest_cache/disable_cors_headers', '__return_false' );

		$pass->decide( $decision, $context, $config );
		$this->assertSame( 'engine-key', $pass->key( 'engine-key', $context, $decision, $config ) );
		$this->assertNotNull( $pass->response( $this->hit(), $context, $config ) );
		$this->assertTrue( $pass->cors( $context, $this->config( null, array( 'cors_on_hits' => true ) ) ) );

		$this->assertSame( array(), $deprecated, 'A hit logs no deprecation notice, and three of the six filters have no replacement hook.' );
		$this->assertNull( Failure_Recorder::last() );
	}

	// Documentation. The legacy hooks reference on rockfire.nl explains the hit filters in full; the readme summarises it.

	public function test_three_of_the_hit_filters_have_no_rockfire_hook(): void {
		// The legacy hooks reference says cache_key, filter_cache_output and pre_output_headers have no replacement hook;
		// the test above shows a hit logs no deprecation notice for them.
		foreach ( array( 'cache_key', 'filter_cache_output', 'pre_output_headers' ) as $name ) {
			$this->assertSame( '', Hook_Registry::replacement( 'wp_rest_cache/' . $name ), $name );
		}
	}

	/**
	 * The readme answer to "Do my WP REST Cache hooks still work?", heading included.
	 *
	 * @return string
	 */
	private function readme_hooks_answer(): string {
		$readme = (string) file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$start  = strpos( $readme, '= Do my WP REST Cache hooks still work? =' );
		$this->assertIsInt( $start );
		$end = strpos( $readme, '= Does code that calls WP REST Cache classes still work? =', $start );
		$this->assertIsInt( $end );

		return substr( $readme, $start, $end - $start );
	}

	public function test_the_readme_answer_says_a_hit_of_the_must_use_file_skips_the_callbacks_of_plugins_and_the_theme(): void {
		$section = $this->readme_hooks_answer();

		// Callbacks in regular plugins or the theme do not run on a hit of the must-use file (Hook_Findings::GROUP_LOAD_ORDER).
		$this->assertStringContainsString( 'rfrac-early-cache.php', $section );
		$this->assertStringContainsString( 'before plugins and the theme load', $section );
		$this->assertStringContainsString( 'their callbacks do not run', $section );
		$this->assertStringContainsString( 'must-use plugin that sorts before `rfrac-early-cache.php`', $section );
	}

	public function test_the_readme_answer_does_not_claim_every_old_hook_fires_with_a_deprecation_notice(): void {
		$section = $this->readme_hooks_answer();

		$this->assertStringNotContainsString( 'fires each old', $section );
		$this->assertStringNotContainsString( 'fires every old', $section );
		$this->assertStringContainsString( 'fires most old', $section );
		// Hits apply the filters as plain filters, which log no notice.
		$this->assertStringContainsString( 'plain filters that never log a notice', $section );
		// The three hooks without a replacement hook are named, and the registry agrees.
		$this->assertStringContainsString( 'have no replacement hook', $section );
		foreach ( array( 'cache_key', 'filter_cache_output', 'pre_output_headers' ) as $name ) {
			$this->assertSame( '', Hook_Registry::replacement( 'wp_rest_cache/' . $name ), $name );
			$this->assertStringContainsString( '`wp_rest_cache/' . $name . '`', $section, $name );
		}
	}

	public function test_the_readme_answer_names_the_constant_and_the_filters_that_stop_storing(): void {
		$section = $this->readme_hooks_answer();

		$this->assertStringContainsString( "define( 'RFRAC_LEGACY_HIT_FILTERS', true )", $section );
		$this->assertStringContainsString( 'slower', $section );
		// Without the constant nothing is stored while these two are hooked, so the readme names them itself.
		foreach ( array( 'wp_rest_cache/cache_key', 'wp_rest_cache/filter_cache_output' ) as $name ) {
			$this->assertContains( $name, Hook_Registry::hit_filters(), $name );
			$this->assertStringContainsString( '`' . $name . '`', $section, $name );
		}
		$this->assertStringContainsString( 'https://rockfire.nl/docs/migration/legacy-hooks/', $section );
	}
}
