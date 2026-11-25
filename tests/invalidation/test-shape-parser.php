<?php
/**
 * Tests for the fallback shape parser, including the ported cases of the legacy relation parser.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Oembed_Tagger;
use Rockfire\Rest_Api_Cache\Invalidation\Rest_Tag_Collector;
use Rockfire\Rest_Api_Cache\Invalidation\Route_Tag_Map;
use Rockfire\Rest_Api_Cache\Invalidation\Shape_Parser;
use Rockfire\Rest_Api_Cache\Invalidation\Tags;

/**
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\Shape_Parser
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\Rest_Tag_Collector
 */
class Test_Shape_Parser extends Rfrac_Test_Case {

	/**
	 * Parser under test.
	 *
	 * @var Shape_Parser
	 */
	private Shape_Parser $parser;

	public function set_up() {
		parent::set_up();
		$this->parser = new Shape_Parser();
	}

	/**
	 * Parse and return the tags.
	 *
	 * @param mixed $data Data.
	 *
	 * @return string[]
	 */
	private function parse( mixed $data ): array {
		return $this->parser->parse( $data );
	}

	// ---------- Ported cases of the legacy process_recursive_cache_relations tests. ----------

	public function test_non_array_input_is_ignored(): void {
		$this->assertSame( array(), $this->parse( null ) );
		$this->assertSame( array(), $this->parse( 'not-an-array' ) );
		$this->assertSame( array(), $this->parse( 42 ) );
	}

	public function test_record_with_none_of_the_recognized_shapes_gives_nothing(): void {
		$this->assertSame( array(), $this->parse( array( 'foo' => 'bar', 'count' => 3 ) ) );
	}

	public function test_post_type_shape(): void {
		$this->assertSame( array( 'list:page', 'post:5' ), $this->parse( array( array( 'id' => 5, 'post_type' => 'page' ) ) ) );
	}

	public function test_post_type_shape_wins_over_type_shape(): void {
		$this->assertSame( array( 'list:page', 'post:5' ), $this->parse( array( 'id' => 5, 'post_type' => 'page', 'type' => 'post', 'slug' => 'x' ) ) );
	}

	public function test_taxonomy_shape_with_id_name_slug(): void {
		$this->assertSame(
			array( 'list:category', 'term:7' ),
			$this->parse(
				array(
					'id'       => 7,
					'name'     => 'Category Name',
					'slug'     => 'category-name',
					'taxonomy' => 'category',
				)
			)
		);
	}

	public function test_taxonomy_shape_falls_back_to_term_id(): void {
		$this->assertSame( array( 'list:post_tag', 'term:9' ), $this->parse( array( 'term_id' => 9, 'taxonomy' => 'post_tag' ) ) );
		$this->assertContains( 'term:7', $this->parse( array( 'term_id' => 7, 'taxonomy' => 'category' ) ) );
	}

	public function test_taxonomy_shape_without_any_id_gives_nothing(): void {
		$this->assertSame( array(), $this->parse( array( 'taxonomy' => 'category' ) ) );
		$this->assertSame( array(), $this->parse( array( 'taxonomy' => 'category', 'slug' => 'x' ) ) );
	}

	public function test_unknown_taxonomy_gives_nothing(): void {
		$this->assertSame( array(), $this->parse( array( 'term_id' => 7, 'taxonomy' => 'no_such_taxonomy' ) ) );
	}

	public function test_rest_item_with_slug_or_status(): void {
		$this->assertSame( array( 'list:post', 'post:1' ), $this->parse( array( 'id' => 1, 'type' => 'post', 'slug' => 'hello-world' ) ) );
		$this->assertSame( array( 'list:post', 'post:1' ), $this->parse( array( 'id' => 1, 'type' => 'post', 'status' => 'publish' ) ) );
	}

	public function test_rest_item_without_slug_or_status_gives_nothing(): void {
		$this->assertSame( array(), $this->parse( array( 'id' => 1, 'type' => 'post' ) ) );
	}

	public function test_unknown_item_type_gives_nothing(): void {
		$this->assertSame( array(), $this->parse( array( 'id' => 3, 'type' => 'unknown_type', 'slug' => 'a' ) ) );
	}

	public function test_user_via_links_collection(): void {
		$this->assertSame(
			array( 'list:users', 'user:42' ),
			$this->parse(
				array(
					'id'     => 42,
					'slug'   => 'jane',
					'_links' => array( 'collection' => array( array( 'href' => 'https://example.test/wp-json/wp/v2/users' ) ) ),
				)
			)
		);
	}

	public function test_links_collection_to_another_endpoint_gives_nothing(): void {
		$this->assertSame(
			array(),
			$this->parse(
				array(
					'id'     => 42,
					'slug'   => 'jane',
					'_links' => array( 'collection' => array( array( 'href' => 'https://example.test/wp-json/wp/v2/pages' ) ) ),
				)
			)
		);
	}

	public function test_comment_shape_with_existing_post(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertSame( array( 'list:comments', 'post:' . $page ), $this->parse( array( 'type' => 'comment', 'post' => $page ) ) );
	}

	public function test_comment_shape_with_missing_post(): void {
		$this->assertSame( array( 'list:comments' ), $this->parse( array( 'type' => 'comment', 'post' => 999999 ) ) );
	}

	public function test_comment_branch_runs_independently_of_the_chain(): void {
		$post = self::factory()->post->create();

		$this->assertSame(
			array( 'comment:17', 'list:comments', 'post:' . $post ),
			$this->parse( array( 'id' => 17, 'type' => 'comment', 'slug' => 'a-comment', 'post' => $post ) )
		);
	}

	public function test_recurses_into_nested_arrays(): void {
		$this->assertSame(
			array( 'list:attachment', 'post:3' ),
			$this->parse( array( 'meta' => array( 'related' => array( 'id' => 3, 'post_type' => 'attachment' ) ) ) )
		);
	}

	public function test_recurses_into_a_list_of_items(): void {
		$this->assertSame(
			array( 'list:post', 'post:1', 'post:2', 'post:3' ),
			$this->parse(
				array(
					'data' => array(
						array( 'id' => 1, 'type' => 'post', 'slug' => 'a' ),
						array( 'id' => 2, 'type' => 'post', 'slug' => 'b' ),
						array( 'id' => 3, 'type' => 'post', 'slug' => 'c' ),
					),
				)
			)
		);
	}

	public function test_keys_are_lower_cased(): void {
		$this->assertSame( array( 'list:post', 'post:5' ), $this->parse( array( 'ID' => 5, 'Post_Type' => 'post' ) ) );
	}

	// ---------- Other shapes. ----------

	public function test_id_must_be_a_positive_int_or_digit_string(): void {
		$this->assertSame( array( 'list:post', 'post:5' ), $this->parse( array( 'id' => '5', 'post_type' => 'post' ) ) );
		$this->assertSame( array(), $this->parse( array( 'id' => 0, 'post_type' => 'post' ) ) );
		$this->assertSame( array(), $this->parse( array( 'id' => '5x', 'post_type' => 'post' ) ) );
		$this->assertSame( array(), $this->parse( array( 'id' => 5.5, 'post_type' => 'post' ) ) );
		$this->assertSame( array(), $this->parse( array( 'id' => -3, 'post_type' => 'post' ) ) );
	}

	public function test_search_item(): void {
		$this->assertSame( array( 'list:page', 'post:5' ), $this->parse( array( 'id' => 5, 'type' => 'post', 'subtype' => 'page', 'title' => 'x', 'url' => 'https://example.test/' ) ) );
		$this->assertSame( array( 'list:category', 'term:6' ), $this->parse( array( 'id' => 6, 'type' => 'term', 'subtype' => 'category', 'title' => 'x' ) ) );
		$this->assertSame( array( 'post:5' ), $this->parse( array( 'id' => 5, 'type' => 'post', 'subtype' => 'nothing' ) ) );
	}

	public function test_comment_id_and_user_login_shapes(): void {
		$this->assertSame( array( 'comment:8' ), $this->parse( array( 'comment_id' => 8 ) ) );
		$this->assertSame( array( 'user:9' ), $this->parse( array( 'id' => 9, 'user_login' => 'jane' ) ) );
	}

	public function test_wordpress_objects(): void {
		$post    = get_post( self::factory()->post->create() );
		$term    = get_term( self::factory()->category->create(), 'category' );
		$user    = get_userdata( self::factory()->user->create() );
		$comment = get_comment( self::factory()->comment->create( array( 'comment_post_ID' => $post->ID ) ) );

		$tags = $this->parse( array( $post, $term, $user, $comment ) );

		$this->assertSame(
			array( 'comment:' . $comment->comment_ID, 'list:category', 'list:comments', 'list:post', 'list:users', 'post:' . $post->ID, 'term:' . $term->term_id, 'user:' . $user->ID ),
			$tags
		);
	}

	public function test_json_serializable_and_plain_objects(): void {
		$serializable = new class() implements JsonSerializable {
			/**
			 * Data.
			 *
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return array( 'id' => 5, 'post_type' => 'page' );
			}
		};
		$plain        = (object) array( 'inner' => array( 'id' => 6, 'post_type' => 'post' ) );

		$this->assertSame( array( 'list:page', 'list:post', 'post:5', 'post:6' ), $this->parse( array( $serializable, $plain ) ) );
	}

	public function test_depth_limit(): void {
		$item  = array( 'id' => 5, 'post_type' => 'page' );
		$deep  = $item;
		$limit = Shape_Parser::MAX_DEPTH;
		for ( $i = 1; $i < 20; $i++ ) {
			$deep = array( 'child' => $deep );
		}

		$this->assertSame( array(), $this->parse( $deep ), 'The item is 20 levels deep.' );

		$ok = $item;
		for ( $i = 1; $i < $limit; $i++ ) {
			$ok = array( 'child' => $ok );
		}
		$this->assertSame( array( 'list:page', 'post:5' ), $this->parse( $ok ), 'The item is on the last parsed level.' );

		$too_deep = array( 'child' => $ok );
		$this->assertSame( array(), $this->parse( $too_deep ) );
	}

	public function test_node_limit_returns_what_was_found(): void {
		$items = array();
		for ( $i = 1; $i <= 10000; $i++ ) {
			$items[] = array( 'id' => $i, 'post_type' => 'post' );
		}
		$tags = $this->parse( $items );
		$this->assertCount( 10001, $tags, '10,000 posts and list:post.' );

		$items = array();
		for ( $i = 1; $i <= 25000; $i++ ) {
			$items[] = array( 'id' => $i, 'post_type' => 'post' );
		}
		$tags = $this->parse( $items );
		$this->assertCount( Shape_Parser::MAX_NODES, $tags, 'The root and 19,999 items, plus list:post, minus the root.' );
	}

	public function test_cyclic_object_graph_terminates(): void {
		$a        = new stdClass();
		$a->self  = $a;
		$a->child = array( 'id' => 5, 'post_type' => 'page' );

		$this->assertSame( array( 'list:page', 'post:5' ), $this->parse( $a ) );
	}

	public function test_post_shape_term_ids_are_not_tagged(): void {
		$this->assertSame(
			array( 'list:post', 'post:5' ),
			$this->parse( array( 'id' => 5, 'post_type' => 'post', 'categories' => array( 1, 2 ), 'tags' => array( 3 ) ) )
		);
	}

	public function test_comment_shape_runs_one_post_lookup_and_no_writes(): void {
		$post = self::factory()->post->create();
		wp_cache_delete( $post, 'posts' );

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$this->parse( array( 'id' => 3, 'type' => 'comment', 'post' => $post ) );
		$recorder->stop();

		$this->assertCount( 1, $recorder->matching( '/^\s*SELECT .* FROM `?[a-z_0-9]*posts`? /is' ), 'One get_post().' );
		$this->assertSame( array(), $recorder->matching( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)/i' ) );
	}

	// ---------- Collector integration. ----------

	/**
	 * Collector with every hook registered on a fresh server.
	 *
	 * @return Rest_Tag_Collector
	 */
	private function collector(): Rest_Tag_Collector {
		$collector = new Rest_Tag_Collector( Config::instance(), new Route_Tag_Map(), new Oembed_Tagger(), new Shape_Parser() );
		$collector->register();
		return $collector;
	}

	/**
	 * Dispatch a GET request as `serve_request()` does and return the request and the response data.
	 *
	 * @param string $route Route.
	 *
	 * @return array{0:WP_REST_Request,1:mixed}
	 */
	private function serve( string $route ): array {
		// Capture stores only for a request whose lookup recorded a flush epoch: this one's is a miss at the current epoch.
		Rfrac_Serving_Harness::record_lookup_at_current_epoch();
		$request  = new WP_REST_Request( 'GET', $route );
		$response = rest_do_request( $request );
		$response = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
		return array( $request, $response->get_data() );
	}

	public function test_cyclic_and_branching_structures_terminate(): void {
		$node        = new stdClass();
		$node->self  = $node;
		$node->child = array( 'id' => 5, 'post_type' => 'page', 'again' => $node );
		$wide        = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$wide[] = array( 'a' => array( 'b' => array( 'c' => $node ) ), 'd' => array( 'e' => $node ) );
		}

		$this->assertSame( array( 'list:page', 'post:5' ), $this->parser->parse( array( $node, $wide ) ) );
	}

	public function test_json_serializable_objects_are_walked(): void {
		$object = new class() implements JsonSerializable {
			public function jsonSerialize(): array {
				return array( 'id' => 7, 'post_type' => 'page' );
			}
		};

		$this->assertSame( array( 'list:page', 'post:7' ), $this->parser->parse( array( $object ) ) );
	}

	public function test_non_int_term_ids_are_ignored_by_the_post_filter(): void {
		$collector = $this->collector();
		$post      = new WP_Post( (object) array( 'ID' => 5, 'post_type' => 'post' ) );

		$collector->on_prepare_post( new WP_REST_Response( array( 'categories' => array( '3', 4.5, null, -2, 0, array( 9 ), 6 ) ) ), $post );

		$this->assertSame( array( 'post:5', 'route:/x', 'term-ref:6' ), $collector->collect( new WP_REST_Request( 'GET', '/x' ), null ) );
	}

	public function test_very_large_flat_arrays_are_not_treated_as_shapes(): void {
		$flat       = array_fill( 0, Shape_Parser::MAX_RECORD_KEYS + 10, 'x' );
		$flat['id'] = 5;
		$flat['post_type'] = 'page';
		$wrapped    = array( $flat, array( 'id' => 6, 'post_type' => 'page' ) );

		$this->assertSame( array( 'list:page', 'post:6' ), $this->parser->parse( $wrapped ) );
	}

	public function test_custom_route_returning_posts_is_tagged_by_the_parser(): void {
		$posts     = self::factory()->post->create_many( 2 );
		$collector = $this->collector();
		$this->new_rest_server(
			static function () {
				register_rest_route(
					'custom/v1',
					'/posts',
					array(
						'methods'             => 'GET',
						'callback'            => static fn() => get_posts(),
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		list( $request, $data ) = $this->serve( '/custom/v1/posts' );

		$this->assertSame(
			Tags::normalize_many( array( 'list:post', 'post:' . $posts[0], 'post:' . $posts[1], 'route:/custom/v1/posts' ) ),
			$collector->collect( $request, $data )
		);
	}

	public function test_parser_does_not_run_for_core_controller_routes(): void {
		self::factory()->post->create();
		$collector = $this->collector();
		$this->new_rest_server();
		$seen = array();
		add_filter(
			Hooks::RUN_FALLBACK_PARSER,
			static function ( $run, $request, $data ) use ( &$seen ) {
				$seen = array( $run, $request, $data );
				return $run;
			},
			10,
			3
		);

		list( $request, $data ) = $this->serve( '/wp/v2/posts' );
		$collector->collect( $request, $data );

		$this->assertFalse( $seen[0] );
		$this->assertSame( $request, $seen[1] );
	}

	public function test_filter_can_force_or_prevent_the_parser(): void {
		$collector = $this->collector();
		$this->new_rest_server();
		$request = new WP_REST_Request( 'GET', '/x' );
		$data    = array( array( 'id' => 5, 'post_type' => 'page' ) );

		$this->assertSame( array( 'list:page', 'post:5', 'route:/x' ), $collector->collect( $request, $data ) );

		add_filter( Hooks::RUN_FALLBACK_PARSER, '__return_false' );
		$this->assertSame( array( 'route:/x' ), $collector->collect( $request, $data ) );

		remove_all_filters( Hooks::RUN_FALLBACK_PARSER );
		$collector->on_main_dispatch();
		$collector->add_tags( array( 'custom:x' ) );
		$this->assertSame( array( 'custom:x', 'route:/x' ), $collector->collect( $request, $data ), 'Tagged responses skip the parser.' );

		add_filter( Hooks::RUN_FALLBACK_PARSER, '__return_true' );
		$collector->on_main_dispatch();
		$collector->add_tags( array( 'custom:x' ) );
		$this->assertSame( array( 'custom:x', 'list:page', 'post:5', 'route:/x' ), $collector->collect( $request, $data ) );
	}
}
