<?php
/**
 * Tests for the REST tag collector.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Oembed_Tagger;
use Rockfire\Rest_Api_Cache\Invalidation\Rest_Tag_Collector;
use Rockfire\Rest_Api_Cache\Invalidation\Route_Tag_Map;
use Rockfire\Rest_Api_Cache\Invalidation\Shape_Parser;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\Rest_Tag_Collector
 */
class Test_Rest_Tag_Collector extends Rfrac_Test_Case {

	/**
	 * Collector under test.
	 *
	 * @var Rest_Tag_Collector
	 */
	private Rest_Tag_Collector $collector;

	/**
	 * Saved superglobals.
	 *
	 * @var array<string,mixed>
	 */
	private array $saved = array();

	public function set_up() {
		parent::set_up();
		$this->saved     = array(
			'get'    => $_GET,
			'server' => $_SERVER,
		);
		$this->collector = new Rest_Tag_Collector( Config::instance(), new Route_Tag_Map(), new Oembed_Tagger(), new Shape_Parser() );
		$this->collector->register();
		$this->new_rest_server();
	}

	public function tear_down() {
		$_GET    = $this->saved['get'];
		$_SERVER = $this->saved['server'];
		Request_State::reset();
		unregister_post_type( 'book' );
		unregister_post_type( 'item' );
		unregister_post_type( 'late' );
		parent::tear_down();
	}

	/**
	 * Dispatch a GET request on the global server (then `rest_post_dispatch`, which `serve_request()` applies).
	 *
	 * @param string              $route  Route.
	 * @param array<string,mixed> $params Query parameters.
	 *
	 * @return array{0:WP_REST_Request,1:WP_REST_Response}
	 */
	private function dispatch( string $route, array $params = array() ): array {
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params( $params );
		$response = rest_do_request( $request );
		$response = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request ); // As `WP_REST_Server::serve_request()` does.
		return array( $request, $response );
	}

	/**
	 * Dispatch and collect.
	 *
	 * @param string              $route  Route.
	 * @param array<string,mixed> $params Query parameters.
	 *
	 * @return string[]|null
	 */
	private function tags_of( string $route, array $params = array() ): ?array {
		list( $request, $response ) = $this->dispatch( $route, $params );
		return $this->collector->collect( $request, $response->get_data() );
	}

	public function test_list_of_posts(): void {
		$a = self::factory()->post->create( array( 'post_date' => '2024-01-01 10:00:00' ) );
		$b = self::factory()->post->create( array( 'post_date' => '2024-01-02 10:00:00' ) );
		$c = self::factory()->post->create( array( 'post_date' => '2024-01-03 10:00:00' ) );
		unset( $a );

		$this->assertSame(
			array( 'list:post', 'post:' . $b, 'post:' . $c, 'route:/wp/v2/posts', 'term-ref:1' ),
			$this->tags_of( '/wp/v2/posts', array( 'per_page' => 2 ) )
		);
	}

	public function test_fields_do_not_hide_the_tags(): void {
		$post = self::factory()->post->create();

		$tags = $this->tags_of( '/wp/v2/posts', array( '_fields' => 'title' ) );

		$this->assertContains( 'post:' . $post, $tags );
		$this->assertContains( 'list:post', $tags );
	}

	public function test_single_post_has_no_list_tag(): void {
		$post = self::factory()->post->create();

		$this->assertSame(
			array( 'post:' . $post, 'route:/wp/v2/posts/' . $post, 'term-ref:1' ),
			$this->tags_of( '/wp/v2/posts/' . $post )
		);
	}

	public function test_embed_adds_author_and_terms(): void {
		$author = self::factory()->user->create();
		$post   = self::factory()->post->create( array( 'post_author' => $author ) );
		$term   = self::factory()->category->create();
		wp_set_object_terms( $post, array( $term ), 'category' );

		$GLOBALS['wp_rest_server'] = new Spy_REST_Server();
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );

		$collected = null;
		add_filter(
			'rest_pre_echo_response',
			function ( $data, $server, $request ) use ( &$collected ) {
				$collected = $this->collector->collect( $request, $data );
				return $data;
			},
			PHP_INT_MAX,
			3
		);

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = array( '_embed' => '1' );
		ob_start();
		$GLOBALS['wp_rest_server']->serve_request( '/wp/v2/posts' );
		ob_end_clean();

		$this->assertContains( 'post:' . $post, $collected );
		$this->assertContains( 'user:' . $author, $collected );
		$this->assertContains( 'term:' . $term, $collected, 'The embedded term shows its data.' );
		$this->assertContains( 'term-ref:' . $term, $collected, 'The post refers to the term.' );
		$this->assertContains( 'list:post', $collected );
	}

	public function test_custom_post_type_without_posts(): void {
		register_post_type(
			'book',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'books',
			)
		);
		$this->new_rest_server();

		$this->assertSame( array( 'list:book', 'route:/wp/v2/books' ), $this->tags_of( '/wp/v2/books' ) );
	}

	public function test_custom_namespace_route(): void {
		register_post_type(
			'item',
			array(
				'public'         => true,
				'show_in_rest'   => true,
				'rest_namespace' => 'shop/v1',
			)
		);
		$this->new_rest_server();

		$this->assertSame( array( 'list:item', 'route:/shop/v1/item' ), $this->tags_of( '/shop/v1/item' ) );
	}

	/**
	 * Core list routes with an item.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function core_routes(): array {
		return array(
			'media'      => array( 'media', 'list:attachment' ),
			'categories' => array( 'categories', 'list:category' ),
			'users'      => array( 'users', 'list:users' ),
			'comments'   => array( 'comments', 'list:comments' ),
		);
	}

	/**
	 * @dataProvider core_routes
	 *
	 * @param string $base Route base.
	 * @param string $list Expected list tag.
	 */
	public function test_core_list_routes( string $base, string $list ): void {
		$post = self::factory()->post->create();
		self::factory()->attachment->create_object(
			array(
				'file'           => 'a.jpg',
				'post_parent'    => $post,
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			)
		);
		self::factory()->comment->create( array( 'comment_post_ID' => $post ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$tags = $this->tags_of( '/wp/v2/' . $base );

		$this->assertContains( $list, $tags );
		$this->assertContains( 'route:/wp/v2/' . $base, $tags );
		$prefix = array(
			'media'      => 'post:',
			'categories' => 'term:',
			'users'      => 'user:',
			'comments'   => 'comment:',
		)[ $base ];
		$this->assertNotEmpty( preg_grep( '/^' . $prefix . '/', $tags ), 'An item tag.' );
	}

	public function test_a_comment_carries_the_tag_of_its_post(): void {
		$post    = self::factory()->post->create();
		$other   = self::factory()->post->create();
		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $post ) );

		$tags = $this->tags_of( '/wp/v2/comments/' . $comment );

		$this->assertContains( 'comment:' . $comment, $tags );
		$this->assertContains( 'post:' . $post, $tags );
		$this->assertNotContains( 'post:' . $other, $tags );
	}

	public function test_a_comment_without_a_post_carries_no_post_tag(): void {
		$comment = new WP_Comment( (object) array( 'comment_ID' => 77, 'comment_post_ID' => 0 ) );

		$this->collector->on_prepare_comment( new WP_REST_Response( array() ), $comment, null );

		$this->assertSame( 1, $this->collector->item_tag_count() );
	}

	public function test_an_attachment_carries_the_tag_of_its_parent(): void {
		$post       = self::factory()->post->create();
		$attachment = self::factory()->attachment->create_object(
			array(
				'file'           => 'a.jpg',
				'post_parent'    => $post,
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$tags = $this->tags_of( '/wp/v2/media/' . $attachment );

		$this->assertContains( 'post:' . $attachment, $tags );
		$this->assertContains( 'post:' . $post, $tags );
	}

	public function test_an_unattached_attachment_carries_no_parent_tag(): void {
		$attachment = self::factory()->attachment->create_object(
			array(
				'file'           => 'a.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$tags = $this->tags_of( '/wp/v2/media/' . $attachment );

		$this->assertContains( 'post:' . $attachment, $tags );
		$this->assertNotContains( 'post:0', $tags );
		$this->assertCount( 1, preg_grep( '/^post:/', $tags ) );
	}

	public function test_only_attachments_carry_the_tag_of_their_parent(): void {
		$parent = self::factory()->post->create();
		$child  = self::factory()->post->create( array( 'post_type' => 'page', 'post_parent' => $parent ) );

		$this->assertNotContains( 'post:' . $parent, $this->tags_of( '/wp/v2/pages/' . $child ) );
	}

	public function test_route_rule_adds_flush_list_tags(): void {
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( $rules ) {
				$rules['/myplugin/v1/stats'] = array(
					'flush_post_types' => array( 'product' ),
					'flush_taxonomies' => array( 'product_cat' ),
				);
				return $rules;
			}
		);
		$this->new_rest_server(
			static function () {
				register_rest_route(
					'myplugin/v1',
					'/stats',
					array(
						'methods'             => 'GET',
						'callback'            => static fn() => array( 'ok' => true ),
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		$tags = $this->tags_of( '/myplugin/v1/stats' );

		$this->assertSame( array( 'list:product', 'list:product_cat', 'route:/myplugin/v1/stats' ), $tags );
	}

	public function test_route_rule_found_through_the_matched_pattern(): void {
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( $rules ) {
				$rules['/myplugin/v1/stats/(?P<id>\d+)'] = array( 'flush_post_types' => array( 'product' ) );
				return $rules;
			}
		);
		$this->new_rest_server(
			static function () {
				register_rest_route(
					'myplugin/v1',
					'/stats/(?P<id>\d+)',
					array(
						'methods'             => 'GET',
						'callback'            => static fn() => array( 'ok' => true ),
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		$this->assertSame( array( 'list:product', 'route:/myplugin/v1/stats/7' ), $this->tags_of( '/myplugin/v1/stats/7' ) );
	}

	public function test_oembed_request(): void {
		$author = self::factory()->user->create();
		$post   = self::factory()->post->create( array( 'post_author' => $author ) );

		$tags = $this->tags_of( '/oembed/1.0/embed', array( 'url' => get_permalink( $post ) ) );

		$this->assertContains( 'post:' . $post, $tags );
		$this->assertContains( 'user:' . $author, $tags );
		$this->assertContains( 'route:/oembed/1.0/embed', $tags );
	}

	public function test_overflow_returns_null_and_resets(): void {
		self::factory()->post->create_many( 5 );
		add_filter(
			Hooks::MAX_TAGS_PER_ENTRY,
			static function () {
				return 3;
			}
		);

		$this->assertNull( $this->tags_of( '/wp/v2/posts' ) );

		remove_all_filters( Hooks::MAX_TAGS_PER_ENTRY );
		$this->assertSame( 0, $this->collector->item_tag_count() );
		$this->assertSame( array( 'route:/wp/v2/posts' ), $this->collector->collect( new WP_REST_Request( 'GET', '/wp/v2/posts' ), array() ) );
	}

	public function test_nested_requests_add_to_the_same_set(): void {
		$post = self::factory()->post->create();
		$this->new_rest_server(
			static function () use ( $post ) {
				register_rest_route(
					'custom/v1',
					'/nested',
					array(
						'methods'             => 'GET',
						'callback'            => static function () use ( $post ) {
							return rest_do_request( '/wp/v2/posts/' . $post )->get_data();
						},
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		$this->assertContains( 'post:' . $post, $this->tags_of( '/custom/v1/nested' ) );
	}

	public function test_non_post_objects_are_ignored(): void {
		$response = new WP_REST_Response( array() );

		$this->assertSame( $response, $this->collector->on_prepare_post( $response, new stdClass(), null ) );
		$this->assertSame( $response, $this->collector->on_prepare_term( $response, new stdClass() ) );
		$this->assertSame( $response, $this->collector->on_prepare_user( $response, null ) );
		$this->assertSame( $response, $this->collector->on_prepare_comment( $response, 'x' ) );
		$this->assertSame( 0, $this->collector->item_tag_count() );
	}

	public function test_prepare_filters_leave_the_response_untouched_and_run_no_queries(): void {
		$post = get_post( self::factory()->post->create() );
		list( $request, $response ) = $this->dispatch( '/wp/v2/posts/' . $post->ID );
		$this->collector->reset();
		$before = $response->get_data();

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$returned = apply_filters( 'rest_prepare_post', $response, $post, $request );
		$recorder->stop();

		$this->assertSame( $response, $returned );
		$this->assertSame( $before, $response->get_data() );
		$this->assertSame( array(), $recorder->matching( '/./' ), 'No query while collecting.' );
		$this->assertSame( 2, $this->collector->item_tag_count(), 'The post and its category.' );
	}

	public function test_collect_runs_no_queries_with_a_route_rule(): void {
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( $rules ) {
				$rules['/wp/v2/posts'] = array( 'flush_post_types' => array( 'product' ) );
				return $rules;
			}
		);
		self::factory()->post->create();
		list( $request, $response ) = $this->dispatch( '/wp/v2/posts' );
		$data                       = $response->get_data();
		$this->collector->reset();
		apply_filters( 'rest_prepare_post', $response, get_post( $data[0]['id'] ), $request );
		get_option( 'rfrac_settings' ); // Warm the options cache, as on a real request (settings are autoloaded).

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$tags = $this->collector->collect( $request, $data );
		$recorder->stop();

		$this->assertSame( array(), $recorder->matching( '/./' ), 'No query while collecting.' );
		$this->assertContains( 'list:product', (array) $tags );
	}

	public function test_route_discovery_runs_once_for_several_collects(): void {
		$calls = 0;
		add_filter(
			'rest_endpoints',
			static function ( $endpoints ) use ( &$calls ) {
				++$calls;
				return $endpoints;
			}
		);
		$this->new_rest_server();
		$first  = $this->dispatch( '/wp/v2/posts' );
		$second = $this->dispatch( '/wp/v2/posts' );
		$third  = $this->dispatch( '/wp/v2/pages' );

		$calls = 0;
		$this->collector->collect( $first[0], $first[1]->get_data() );
		$after_first = $calls;
		$this->collector->collect( $second[0], $second[1]->get_data() );
		$this->collector->collect( $third[0], $third[1]->get_data() );

		$this->assertLessThanOrEqual( 1, $after_first, 'The first collect discovers the routes at most once.' );
		$this->assertSame( $after_first, $calls, 'Later collects trigger no further route discovery.' );
	}

	public function test_late_registered_types_are_hooked(): void {
		register_post_type(
			'late',
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);

		$this->assertSame( PHP_INT_MAX, has_filter( 'rest_prepare_late', array( $this->collector, 'on_prepare_post' ) ) );
	}

	public function test_add_tags_normalises_and_counts_items(): void {
		$this->collector->on_main_dispatch();
		$this->collector->add_tags( array( 'POST:5', 'custom:x', 'bogus', 'list:post' ) );

		$this->assertSame( 2, $this->collector->item_tag_count() );
		$this->assertSame( array( 'custom:x', 'list:post', 'post:5', 'route:/x' ), $this->collector->collect( new WP_REST_Request( 'GET', '/x' ), null ) );
	}

	public function test_collected_tags_filter_receives_request_and_data(): void {
		$seen = array();
		add_filter(
			Hooks::COLLECTED_TAGS,
			static function ( $tags, $request, $data ) use ( &$seen ) {
				$seen = array( $request, $data );
				$tags[] = 'Extra:Tag';
				return $tags;
			},
			10,
			3
		);
		$request = new WP_REST_Request( 'GET', '/x' );

		$tags = $this->collector->collect( $request, array( 'data' ) );

		$this->assertSame( array( $request, array( 'data' ) ), $seen );
		$this->assertSame( array( 'extra:tag', 'route:/x' ), $tags );
	}

	public function test_tags_can_only_be_added_during_a_main_dispatch(): void {
		$this->assertFalse( $this->collector->is_dispatching() );
		$this->assertFalse( $this->collector->add_tags( array( 'post:5' ) ) );
		$this->assertSame( 0, $this->collector->item_tag_count() );

		$this->collector->on_main_dispatch();
		$this->assertTrue( $this->collector->is_dispatching() );
		$this->assertTrue( $this->collector->add_tags( array( 'post:5' ) ) );

		$this->collector->collect( new WP_REST_Request( 'GET', '/x' ), null );
		$this->assertFalse( $this->collector->is_dispatching(), 'collect() ends the dispatch.' );
		$this->assertFalse( $this->collector->add_tags( array( 'post:6' ) ) );

		$this->collector->on_main_dispatch();
		$this->collector->reset();
		$this->assertFalse( $this->collector->is_dispatching(), 'reset() ends the dispatch.' );
	}

	public function test_add_tags_returns_false_when_the_tag_limit_is_exceeded(): void {
		add_filter( Hooks::MAX_TAGS_PER_ENTRY, static fn() => 1 );
		$this->collector->on_main_dispatch();

		$this->assertFalse( $this->collector->add_tags( array( 'post:1', 'post:2' ) ) );
		$this->collector->reset();
		delete_option( Failure_Recorder::OPTION );
	}

	public function test_fail_collection_marks_the_collection_incomplete(): void {
		delete_option( Failure_Recorder::OPTION );
		$this->set_permalink_structure( '/%postname%/' ); // The route is only read from a pretty `/wp-json/` path.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/wp-json/tst/v1/ok';
		Request_State::record_lookup( Request_Context::from_globals( 'wp-json' ), Policy_Decision::bypass( Bypass_Reason::ERROR ), null );
		$this->collector->on_main_dispatch();
		$this->collector->add_tags( array( 'post:5' ) );

		$this->assertTrue( $this->collector->fail_collection( new RuntimeException( 'x' ) ) );
		$this->assertNull( $this->collector->collect( new WP_REST_Request( 'GET', '/tst/v1/ok' ), null ) );

		$this->assertSame( Bypass_Reason::ERROR, $this->collector->refusal() );
		$this->assertSame( 'tags', Failure_Recorder::last()['context'] ?? null );
		$this->assertSame( '/tst/v1/ok', get_option( Failure_Recorder::OPTION )['subject'] ?? null );
		delete_option( Failure_Recorder::OPTION );
	}

	public function test_fail_collection_outside_a_dispatch_is_ignored(): void {
		delete_option( Failure_Recorder::OPTION );

		$this->assertFalse( $this->collector->fail_collection( new RuntimeException( 'x' ) ) );
		$this->assertNull( Failure_Recorder::last(), 'Nothing is recorded outside a dispatch.' );

		$this->collector->on_main_dispatch();
		$this->collector->add_tags( array( 'post:5' ) );
		$this->assertSame( array( 'post:5', 'route:/x' ), $this->collector->collect( new WP_REST_Request( 'GET', '/x' ), null ) );
		$this->assertNull( $this->collector->refusal() );
		delete_option( Failure_Recorder::OPTION );
	}

	public function test_fail_collection_does_not_leak_into_the_next_dispatch(): void {
		delete_option( Failure_Recorder::OPTION );
		$this->collector->on_main_dispatch();
		$this->assertTrue( $this->collector->fail_collection( new RuntimeException( 'x' ) ) );

		$this->collector->on_main_dispatch();
		$this->collector->add_tags( array( 'post:5' ) );

		$this->assertSame( array( 'post:5', 'route:/x' ), $this->collector->collect( new WP_REST_Request( 'GET', '/x' ), null ) );
		$this->assertNull( $this->collector->refusal() );
		delete_option( Failure_Recorder::OPTION );
	}

	public function test_collect_drains_the_state(): void {
		$this->collector->on_main_dispatch();
		$this->collector->add_tags( array( 'post:5' ) );
		$this->collector->collect( new WP_REST_Request( 'GET', '/x' ), null );

		$this->assertSame( 0, $this->collector->item_tag_count() );
		$this->assertSame( array( 'route:/y' ), $this->collector->collect( new WP_REST_Request( 'GET', '/y' ), null ) );
	}

	/**
	 * Register a custom route returning fixed data.
	 *
	 * @param string $route Route below `myplugin/v1`.
	 * @param mixed  $data  Data.
	 */
	private function register_custom_route( string $route, mixed $data = array( 'ok' => true ) ): void {
		$this->new_rest_server(
			static function () use ( $route, $data ) {
				register_rest_route(
					'myplugin/v1',
					$route,
					array(
						'methods'             => 'GET',
						'callback'            => static fn() => $data,
						'permission_callback' => '__return_true',
					)
				);
			}
		);
	}

	public function test_wildcard_rule_adds_flush_list_tags_through_the_policy_rule_key(): void {
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( $rules ) {
				$rules['/myplugin/v1/*'] = array(
					'match'            => 'wildcard',
					'status'           => 'enabled',
					'flush_post_types' => array( 'product' ),
					'flush_taxonomies' => array( 'product_cat' ),
				);
				return $rules;
			}
		);
		$this->register_custom_route( '/stats' );
		list( $request, $response ) = $this->dispatch( '/myplugin/v1/stats' );

		$this->collector->use_rule_key( '/myplugin/v1/*' );
		$tags = $this->collector->collect( $request, $response->get_data() );

		$this->assertSame( array( 'list:product', 'list:product_cat', 'route:/myplugin/v1/stats' ), $tags );
	}

	public function test_wildcard_rule_is_found_without_the_rule_key(): void {
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( $rules ) {
				$rules['/myplugin/v1/*'] = array(
					'match'            => 'wildcard',
					'status'           => 'enabled',
					'flush_post_types' => array( 'product' ),
				);
				return $rules;
			}
		);
		$this->register_custom_route( '/stats' );

		$this->assertSame( array( 'list:product', 'route:/myplugin/v1/stats' ), $this->tags_of( '/myplugin/v1/stats' ) );
		$this->assertSame( array( 'route:/other/route' ), $this->collector->collect( new WP_REST_Request( 'GET', '/other/route' ), null ) );
	}

	public function test_rule_key_is_forgotten_after_collect(): void {
		$this->collector->use_rule_key( '/myplugin/v1/*' );
		$this->collector->collect( new WP_REST_Request( 'GET', '/x' ), null );

		$property = new ReflectionProperty( Rest_Tag_Collector::class, 'rule_key' );
		$property->setAccessible( true );
		$this->assertNull( $property->getValue( $this->collector ) );
	}

	public function test_throwing_oembed_filter_makes_the_collection_incomplete(): void {
		delete_option( Failure_Recorder::OPTION );
		$post = self::factory()->post->create();
		add_filter(
			'oembed_request_post_id',
			static function () {
				throw new RuntimeException( 'third party failure' );
			}
		);

		$request = new WP_REST_Request( 'GET', '/oembed/1.0/embed' ); // The filter would throw in core's own handler too, so only the collector hook runs.
		$request->set_param( 'url', get_permalink( $post ) );
		$this->collector->on_before_callbacks( null, null, $request );
		$this->assertNull( $this->collector->collect( $request, null ) );
		$this->assertSame( 'tags', Failure_Recorder::last()['context'] ?? null );
		$this->assertSame( Bypass_Reason::ERROR, $this->collector->refusal(), 'An incomplete collection is an error, not a tag overflow.' );

		// The flag is drained: the next collection is complete again.
		remove_all_filters( 'oembed_request_post_id' );
		$this->assertSame( array( 'route:/x' ), $this->collector->collect( new WP_REST_Request( 'GET', '/x' ), null ) );
		$this->assertNull( $this->collector->refusal() );
		delete_option( Failure_Recorder::OPTION );
	}

	public function test_throwing_route_list_tags_filter_makes_the_collection_incomplete(): void {
		self::factory()->post->create();
		add_filter(
			Hooks::ROUTE_LIST_TAGS,
			static function () {
				throw new RuntimeException( 'third party failure' );
			}
		);

		$this->assertNull( $this->tags_of( '/wp/v2/posts' ) );
		delete_option( Failure_Recorder::OPTION );
	}

	public function test_main_dispatch_drops_tags_of_earlier_requests(): void {
		self::factory()->post->create();
		$this->register_custom_route( '/shape', array( array( 'id' => 5, 'post_type' => 'page' ) ) );
		$this->dispatch( '/wp/v2/posts' ); // E.g. a `rest_do_request()` during `init`.
		$this->assertGreaterThan( 0, $this->collector->item_tag_count() );

		$this->assertSame( 'untouched', $this->collector->on_main_dispatch( 'untouched' ) );
		$this->assertSame( 0, $this->collector->item_tag_count() );

		$this->assertSame( array( 'list:page', 'post:5', 'route:/myplugin/v1/shape' ), $this->tags_of( '/myplugin/v1/shape' ), 'The parser runs for the custom endpoint.' );
	}

	public function test_main_dispatch_hook_is_registered_early(): void {
		$this->assertSame( 0, has_filter( 'rest_authentication_errors', array( $this->collector, 'on_main_dispatch' ) ) );
	}

	/**
	 * @return array<string,array{0:int,1:bool}>
	 */
	public static function limit_cases(): array {
		return array(
			'count equals the limit' => array( 3, true ),
			'one over the limit'     => array( 2, false ),
		);
	}

	/**
	 * @dataProvider limit_cases
	 *
	 * @param int  $limit  Tag limit.
	 * @param bool $stored Whether the tags are returned.
	 */
	public function test_limit_boundary( int $limit, bool $stored ): void {
		add_filter( Hooks::MAX_TAGS_PER_ENTRY, static fn() => $limit );
		$this->collector->on_main_dispatch();
		$this->collector->add_tags( array( 'post:1', 'post:2' ) );

		$tags = $this->collector->collect( new WP_REST_Request( 'GET', '/x' ), null );

		$this->assertSame( $stored ? array( 'post:1', 'post:2', 'route:/x' ) : null, $tags );
		delete_option( Failure_Recorder::OPTION );
	}

	public function test_overflow_is_recorded(): void {
		delete_option( Failure_Recorder::OPTION );
		add_filter( Hooks::MAX_TAGS_PER_ENTRY, static fn() => 1 );
		$this->collector->on_main_dispatch();
		$this->collector->add_tags( array( 'post:1', 'post:2' ) );

		$this->assertNull( $this->collector->collect( new WP_REST_Request( 'GET', '/x' ), null ) );

		$last = Failure_Recorder::last();
		$this->assertSame( 'tags', $last['context'] ?? null );
		$this->assertStringContainsString( 'limit', $last['message'] ?? '' );
		$this->assertSame( Bypass_Reason::TOO_MANY_TAGS, $this->collector->refusal() );

		// Recorded for the route and never cleared by a later success, also not of the same route.
		$this->assertSame( '/x', get_option( Failure_Recorder::OPTION )['subject'] ?? null );
		Failure_Recorder::clear( array( 'tags' ), '/x' );
		$this->assertSame( 'tags', Failure_Recorder::last()['context'] ?? null );
		delete_option( Failure_Recorder::OPTION );
	}

	public function test_overflow_after_the_collected_tags_filter(): void {
		add_filter( Hooks::MAX_TAGS_PER_ENTRY, static fn() => 3 );
		add_filter( Hooks::COLLECTED_TAGS, static fn( $tags ) => array_merge( $tags, array( 'a:1', 'a:2', 'a:3' ) ) );

		$this->assertNull( $this->collector->collect( new WP_REST_Request( 'GET', '/x' ), null ) );
		delete_option( Failure_Recorder::OPTION );
	}

	public function test_parser_overflow(): void {
		add_filter( Hooks::MAX_TAGS_PER_ENTRY, static fn() => 2 );
		$data = array(
			array( 'id' => 1, 'post_type' => 'page' ),
			array( 'id' => 2, 'post_type' => 'page' ),
		);

		$this->assertNull( $this->collector->collect( new WP_REST_Request( 'GET', '/x' ), $data ) );
		delete_option( Failure_Recorder::OPTION );
	}

	public function test_collected_tags_filter_results(): void {
		$request = new WP_REST_Request( 'GET', '/x' );

		add_filter( Hooks::COLLECTED_TAGS, static fn() => 'custom:a, Custom:B' );
		$this->assertSame( array( 'custom:a', 'custom:b' ), $this->collector->collect( $request, null ), 'A string is accepted.' );

		remove_all_filters( Hooks::COLLECTED_TAGS );
		add_filter( Hooks::COLLECTED_TAGS, static fn() => 42 );
		$this->assertSame( array( 'route:/x' ), $this->collector->collect( $request, null ), 'Other values keep the collected tags.' );
	}

	public function test_throwing_collected_tags_filter_still_resets(): void {
		$this->collector->on_main_dispatch();
		$this->collector->add_tags( array( 'post:5' ) );
		add_filter(
			Hooks::COLLECTED_TAGS,
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);

		try {
			$this->collector->collect( new WP_REST_Request( 'GET', '/x' ), null );
			$this->fail( 'The exception propagates to the caller.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertSame( 0, $this->collector->item_tag_count() );
	}

	public function test_nav_menu_item_scalar_term_is_tagged(): void {
		$item = new WP_Post( (object) array( 'ID' => 8, 'post_type' => 'nav_menu_item' ) );

		$this->collector->on_prepare_post( new WP_REST_Response( array( 'menus' => 7 ) ), $item );

		$this->assertSame( array( 'post:8', 'route:/x', 'term-ref:7' ), $this->collector->collect( new WP_REST_Request( 'GET', '/x' ), null ) );
	}

	public function test_late_registered_taxonomy_is_hooked_and_mapped(): void {
		register_taxonomy( 'genre', 'post', array( 'show_in_rest' => true ) );

		$this->assertSame( PHP_INT_MAX, has_filter( 'rest_prepare_genre', array( $this->collector, 'on_prepare_term' ) ) );
		$this->collector->on_before_callbacks( null, null, new WP_REST_Request( 'GET', '/wp/v2/genre' ) );
		$this->assertContains( 'list:genre', $this->collector->collect( new WP_REST_Request( 'GET', '/wp/v2/genre' ), null ) );
		unregister_taxonomy( 'genre' );
	}

	public function test_remembered_routes_are_the_most_recent_twenty(): void {
		$requests = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$request    = new WP_REST_Request( 'GET', '/wp/v2/posts' );
			$requests[] = $request;
			$response   = new WP_REST_Response( array() );
			$response->set_matched_route( '/wp/v2/posts' );
			$this->collector->on_post_dispatch( $response, null, $request );
		}

		$property = new ReflectionProperty( Rest_Tag_Collector::class, 'matched' );
		$property->setAccessible( true );
		$expected = array_map( 'spl_object_id', array_slice( $requests, 5 ) );
		$this->assertSame( $expected, array_keys( $property->getValue( $this->collector ) ) );
	}

	public function test_cached_terms_of_public_taxonomies_outside_rest_are_referenced(): void {
		register_taxonomy( 'rfrac_public_tax', 'post', array( 'public' => true ) );
		register_taxonomy( 'rfrac_private_tax', 'post', array( 'public' => false ) );
		$item = new WP_Post( (object) array( 'ID' => 987654, 'post_type' => 'post' ) );
		wp_cache_set( 987654, array( 41 ), 'rfrac_public_tax_relationships' );
		wp_cache_set( 987654, array( 42 ), 'rfrac_private_tax_relationships' );
		wp_cache_set( 987654, array( 43 ), 'category_relationships' );

		// `_fields` left out every term field: the cached relationships still reach the tags.
		$this->collector->on_prepare_post( new WP_REST_Response( array( 'id' => 987654 ) ), $item );
		$tags = $this->collector->collect( new WP_REST_Request( 'GET', '/x' ), null );

		unregister_taxonomy( 'rfrac_public_tax' );
		unregister_taxonomy( 'rfrac_private_tax' );
		$this->assertContains( 'term-ref:41', $tags, 'Public taxonomy: class_list and permalinks use it.' );
		$this->assertNotContains( 'term-ref:42', $tags, 'Neither public nor in the REST API.' );
		$this->assertContains( 'term-ref:43', $tags );
	}
}
