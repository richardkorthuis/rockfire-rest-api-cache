<?php
/**
 * End-to-end: post responses refer to their terms by ID (`term-ref:`), so only their own term changes and the
 * deletion of a term they list flush them; term edits and recounts flush the responses that show the term.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Invalidation\Term_Events;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @coversNothing
 */
class Test_Term_Reference_Matrix extends Rfrac_Matrix_Test_Case {

	/**
	 * Administrator who saves posts through the REST API.
	 *
	 * @var int
	 */
	private int $admin = 0;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Run a REST write as the administrator, like the block editor does.
	 *
	 * @param string              $route  Route.
	 * @param array<string,mixed> $params Body parameters.
	 *
	 * @return WP_REST_Response
	 */
	private function rest_write( string $route, array $params ): WP_REST_Response {
		wp_set_current_user( $this->admin );
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( $params );
		$response = rest_do_request( $request );
		wp_set_current_user( 0 );
		$this->assertLessThan( 300, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		return $response;
	}

	/**
	 * The tags of every flush of the last change.
	 *
	 * @return string[]
	 */
	private function flushed_tags(): array {
		$tags = array();
		foreach ( $this->flushed as $event ) {
			if ( 'tags' === $event[0] ) {
				$tags = array_merge( $tags, $event[1] );
			}
		}
		sort( $tags );
		return array_values( array_unique( $tags ) );
	}

	/**
	 * Data provider: the stores crossed with the two ways a new post is published.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function stores_and_flows(): array {
		$out = array();
		foreach ( self::stores() as $name => $store ) {
			$out[ $name . ', REST create' ]                = array( $store[0], 'create' );
			$out[ $name . ', editor (auto-draft, update)' ] = array( $store[0], 'editor' );
		}
		return $out;
	}

	/**
	 * Publish a new post in the given categories through the REST API.
	 *
	 * @param string $flow       `create` (POST /wp/v2/posts) or `editor` (auto-draft, then POST /wp/v2/posts/{id}).
	 * @param int[]  $categories Category IDs.
	 * @param int    $draft      The auto-draft of the editor flow.
	 *
	 * @return void
	 */
	private function publish( string $flow, array $categories, int $draft ): void {
		$params = array(
			'title'      => 'New',
			'status'     => 'publish',
			'categories' => $categories,
		);
		$this->rest_write( 'create' === $flow ? '/wp/v2/posts' : '/wp/v2/posts/' . $draft, $params );
	}

	/**
	 * The auto-draft the editor creates when it opens a new post (an earlier request).
	 *
	 * @param string $flow Flow.
	 *
	 * @return int Post ID; 0 for the create flow.
	 */
	private function auto_draft( string $flow ): int {
		if ( 'editor' !== $flow ) {
			return 0;
		}
		wp_set_current_user( $this->admin );
		$id = wp_insert_post(
			array(
				'post_title'  => 'Auto Draft',
				'post_status' => 'auto-draft',
			)
		);
		wp_set_current_user( 0 );
		$this->assertSame( array(), wp_get_post_categories( $id ), 'An auto-draft has no default category.' );
		return $id;
	}

	/**
	 * @dataProvider stores_and_flows
	 *
	 * @param string $store Store.
	 * @param string $flow  Flow.
	 */
	public function test_publishing_a_post_in_another_category_keeps_the_posts_of_the_default_category( string $store, string $flow ): void {
		$default = (int) get_option( 'default_category' );
		$other   = $this->factory()->category->create( array( 'name' => 'Other' ) );
		$post_id = $this->factory()->post->create();
		$this->assertSame( array( $default ), wp_get_post_categories( $post_id ) );
		$this->arm( $store );
		$draft = $this->auto_draft( $flow );
		Plugin::instance()->invalidator()->commit();

		$single = '/wp/v2/posts/' . $post_id;
		$uris   = array( $single, '/wp/v2/posts', '/wp/v2/categories/' . $default, '/wp/v2/categories/' . $other );
		foreach ( $uris as $uri ) {
			$this->serve( $uri );
		}
		$this->assertContains( 'term-ref:' . $default, $this->tags_of( $single ) );
		$this->assertNotContains( 'term:' . $default, $this->tags_of( $single ) );

		$this->change_and_commit( fn() => $this->publish( $flow, array( $other ), $draft ) );

		$tags = $this->flushed_tags();
		$this->assertNotContains( 'term-ref:' . $default, $tags, implode( ' ', $tags ) );
		$this->assertContains( 'term:' . $other, $tags );
		// The default category was counted up and down again while the post was saved: its own response is
		// flushed (a concurrent request may have cached the intermediate count), its posts are not.
		$this->assert_only_flushed( array( '/wp/v2/posts', '/wp/v2/categories/' . $default, '/wp/v2/categories/' . $other ) );
	}

	/**
	 * @dataProvider stores
	 *
	 * @param string $store Store.
	 */
	public function test_removing_another_post_from_a_category_keeps_the_other_posts_in_it( string $store ): void {
		$category = $this->factory()->category->create();
		$post_id  = $this->factory()->post->create( array( 'post_category' => array( $category ) ) );
		$leaving  = $this->factory()->post->create( array( 'post_category' => array( $category ) ) );
		$this->arm( $store );

		$single = '/wp/v2/posts/' . $post_id;
		foreach ( array( $single, '/wp/v2/posts/' . $leaving, '/wp/v2/categories/' . $category ) as $uri ) {
			$this->serve( $uri );
		}

		$this->change_and_commit( fn() => $this->rest_write( '/wp/v2/posts/' . $leaving, array( 'categories' => array( (int) get_option( 'default_category' ) ) ) ) );

		$this->assert_flushed( array( '/wp/v2/posts/' . $leaving, '/wp/v2/categories/' . $category ) );
		$this->assert_active( array( $single ) );
	}

	/**
	 * @dataProvider stores_and_flows
	 *
	 * @param string $store Store.
	 * @param string $flow  Flow.
	 */
	public function test_publishing_a_post_in_the_same_category_flushes_the_category_but_not_its_posts( string $store, string $flow ): void {
		$default = (int) get_option( 'default_category' );
		$post_id = $this->factory()->post->create();
		$this->arm( $store );
		$draft = $this->auto_draft( $flow );
		Plugin::instance()->invalidator()->commit();

		$single = '/wp/v2/posts/' . $post_id;
		foreach ( array( $single, '/wp/v2/posts', '/wp/v2/categories/' . $default ) as $uri ) {
			$this->serve( $uri );
		}

		$this->change_and_commit( fn() => $this->publish( $flow, array( $default ), $draft ) );

		$this->assertNotContains( 'term-ref:' . $default, $this->flushed_tags() );
		$this->assert_only_flushed( array( '/wp/v2/posts', '/wp/v2/categories/' . $default ), 'term:' . $default );
	}

	/**
	 * @dataProvider stores
	 *
	 * @param string $store Store.
	 */
	public function test_renaming_a_term_flushes_the_term_and_embeds_but_not_the_posts_listing_it( string $store ): void {
		$category = $this->factory()->category->create( array( 'name' => 'Before' ) );
		$post_id  = $this->factory()->post->create( array( 'post_category' => array( $category ) ) );
		$this->arm( $store );

		$single = '/wp/v2/posts/' . $post_id;
		$uris   = array( $single, '/wp/v2/posts', '/wp/v2/categories/' . $category, '/wp/v2/posts?_embed=wp:term', '/wp/v2/categories' );
		foreach ( $uris as $uri ) {
			$this->serve( $uri );
		}

		$this->change_and_commit( fn() => wp_update_term( $category, 'category', array( 'name' => 'After' ) ) );

		$this->assertNotContains( 'term-ref:' . $category, $this->flushed_tags() );
		$this->assert_only_flushed( array( '/wp/v2/categories/' . $category, '/wp/v2/posts?_embed=wp:term', '/wp/v2/categories' ), 'term:' . $category );
	}

	/**
	 * @dataProvider stores
	 *
	 * @param string $store Store.
	 */
	public function test_deleting_a_term_flushes_the_posts_listing_it( string $store ): void {
		$default  = (int) get_option( 'default_category' );
		$category = $this->factory()->category->create();
		$post_id  = $this->factory()->post->create( array( 'post_category' => array( $category ) ) );
		$sibling  = $this->factory()->post->create(); // In the default category only.
		$this->arm( $store );

		$single = '/wp/v2/posts/' . $post_id;
		foreach ( array( $single, '/wp/v2/posts/' . $sibling, '/wp/v2/categories/' . $category, '/wp/v2/categories/' . $default ) as $uri ) {
			$this->serve( $uri );
		}

		// The post loses its only category and is moved to the default category.
		$this->change_and_commit( fn() => wp_delete_term( $category, 'category' ) );

		$this->assertSame( array( $default ), wp_get_post_categories( $post_id ) );
		$this->assertContains( 'term-ref:' . $category, $this->flushed_tags() );
		$this->assert_only_flushed( array( $single, '/wp/v2/categories/' . $category, '/wp/v2/categories/' . $default ) );
	}

	/**
	 * @dataProvider stores
	 *
	 * @param string $store Store.
	 */
	public function test_deleting_a_term_of_more_than_200_real_posts_flushes_them_and_nothing_else( string $store ): void {
		$category = $this->factory()->category->create();
		$posts    = $this->factory()->post->create_many( 201, array( 'post_category' => array( $category ) ) );
		$outside  = $this->factory()->post->create( array( 'post_category' => array( $this->factory()->category->create() ) ) );
		$this->arm( $store );

		$single = '/wp/v2/posts/' . $posts[100];
		foreach ( array( $single, '/wp/v2/posts/' . $outside ) as $uri ) {
			$this->serve( $uri );
		}

		$this->change_and_commit( fn() => wp_delete_term( $category, 'category' ) );

		$this->assert_flushed( array( $single ) );
		$this->assert_active( array( '/wp/v2/posts/' . $outside ) );
	}

	/**
	 * @dataProvider stores
	 *
	 * @param string $store Store.
	 */
	public function test_deleting_a_tag_flushes_its_posts_but_not_the_others( string $store ): void {
		$tag     = $this->factory()->tag->create();
		$post_id = $this->factory()->post->create( array( 'tags_input' => array( $tag ) ) );
		$other   = $this->factory()->post->create();
		$this->arm( $store );

		$single = '/wp/v2/posts/' . $post_id;
		foreach ( array( $single, '/wp/v2/posts/' . $other ) as $uri ) {
			$this->serve( $uri );
		}

		$this->change_and_commit( fn() => wp_delete_term( $tag, 'post_tag' ) );

		$this->assert_only_flushed( array( $single ) );
	}

	/**
	 * Data provider: the stores crossed with the ways terms change outside wp_set_object_terms() replacing them.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function stores_and_relationship_changes(): array {
		$out = array();
		foreach ( self::stores() as $name => $store ) {
			foreach ( array( 'wp_remove_object_terms', 'wp_delete_object_term_relationships', 'wp_add_object_terms', 'rest_add_tag' ) as $change ) {
				$out[ $name . ', ' . $change ] = array( $store[0], $change );
			}
		}
		return $out;
	}

	/**
	 * @dataProvider stores_and_relationship_changes
	 *
	 * @param string $store  Store.
	 * @param string $change Change.
	 */
	public function test_changing_the_terms_of_a_post_flushes_that_post_only( string $store, string $change ): void {
		$category = $this->factory()->category->create();
		$tag      = $this->factory()->tag->create();
		$new_tag  = $this->factory()->tag->create();
		$post_id  = $this->factory()->post->create(
			array(
				'post_category' => array( $category ),
				'tags_input'    => array( $tag ),
			)
		);
		$sibling  = $this->factory()->post->create(
			array(
				'post_category' => array( $category ),
				'tags_input'    => array( $tag ),
			)
		);
		$this->arm( $store );

		$single = '/wp/v2/posts/' . $post_id;
		foreach ( array( $single, '/wp/v2/posts/' . $sibling, '/wp/v2/posts?categories=' . $category ) as $uri ) {
			$this->serve( $uri );
		}

		$this->change_and_commit(
			function () use ( $change, $post_id, $tag, $new_tag ): void {
				switch ( $change ) {
					case 'wp_remove_object_terms':
						wp_remove_object_terms( $post_id, array( $tag ), 'post_tag' );
						break;
					case 'wp_delete_object_term_relationships':
						wp_delete_object_term_relationships( $post_id, array( 'post_tag' ) );
						break;
					case 'wp_add_object_terms':
						wp_add_object_terms( $post_id, array( $new_tag ), 'post_tag' );
						break;
					default:
						$this->rest_write( '/wp/v2/posts/' . $post_id, array( 'tags' => array( $tag, $new_tag ) ) );
				}
			}
		);

		$this->assertSame( array(), preg_grep( '/^term-ref:/', $this->flushed_tags() ) );
		$this->assert_flushed( array( $single, '/wp/v2/posts?categories=' . $category ) );
		$this->assert_active( array( '/wp/v2/posts/' . $sibling ) );
	}

	/**
	 * @dataProvider stores
	 *
	 * @param string $store Store.
	 */
	public function test_resaving_a_post_with_unchanged_terms_keeps_its_siblings( string $store ): void {
		$category = $this->factory()->category->create();
		$post_id  = $this->factory()->post->create( array( 'post_category' => array( $category ) ) );
		$sibling  = $this->factory()->post->create( array( 'post_category' => array( $category ) ) );
		$this->arm( $store );

		foreach ( array( '/wp/v2/posts/' . $post_id, '/wp/v2/posts/' . $sibling ) as $uri ) {
			$this->serve( $uri );
		}

		$this->change_and_commit( fn() => $this->rest_write( '/wp/v2/posts/' . $post_id, array( 'categories' => array( $category ) ) ) );

		$this->assert_flushed( array( '/wp/v2/posts/' . $post_id ) );
		$this->assert_active( array( '/wp/v2/posts/' . $sibling ) );
	}

	/**
	 * Data provider: the stores crossed with term edits and whether they change what posts show.
	 *
	 * @return array<string,array{0:string,1:string,2:bool}>
	 */
	public static function stores_and_term_edits(): array {
		$out = array();
		foreach ( self::stores() as $name => $store ) {
			$out[ $name . ', rename' ]        = array( $store[0], 'name', false );
			$out[ $name . ', description' ]   = array( $store[0], 'description', false );
			$out[ $name . ', slug' ]          = array( $store[0], 'slug', true );
			$out[ $name . ', parent slug' ]   = array( $store[0], 'parent_slug', true );
			$out[ $name . ', parent change' ] = array( $store[0], 'parent', true );
		}
		return $out;
	}

	/**
	 * With `%category%` permalinks a post's `link` holds the path of its category, and `class_list` holds the slugs of
	 * its terms.
	 *
	 * @dataProvider stores_and_term_edits
	 *
	 * @param string $store   Store.
	 * @param string $edit    What is edited.
	 * @param bool   $flushes Whether post responses change.
	 */
	public function test_term_edits_flush_posts_only_when_their_link_or_class_list_changes( string $store, string $edit, bool $flushes ): void {
		$this->set_permalink_structure( '/%category%/%postname%/' );
		$parent  = $this->factory()->category->create( array( 'slug' => 'parent-cat' ) );
		$child   = $this->factory()->category->create(
			array(
				'slug'   => 'child-cat',
				'parent' => $parent,
			)
		);
		$other   = $this->factory()->category->create( array( 'slug' => 'other-cat' ) );
		$post_id = $this->factory()->post->create( array( 'post_category' => array( $child ) ) );
		$outside = $this->factory()->post->create( array( 'post_category' => array( $other ) ) );
		$this->arm( $store );

		$single = '/wp/v2/posts/' . $post_id;
		$link   = '/wp/v2/posts/' . $post_id . '?_fields=link';
		$body   = $this->serve( $single );
		$this->assertStringContainsString( 'parent-cat\/child-cat', $body );
		$this->assertStringContainsString( 'category-child-cat', $body, 'class_list holds the category slug.' );
		$this->serve( $link );
		$this->serve( '/wp/v2/posts/' . $outside );
		$this->assertContains( 'term-ref:' . $child, $this->tags_of( $link ), 'Tagged although `_fields` leaves out `categories`.' );

		$this->change_and_commit(
			function () use ( $edit, $parent, $child, $other ): void {
				switch ( $edit ) {
					case 'name':
						wp_update_term( $child, 'category', array( 'name' => 'Renamed' ) );
						break;
					case 'description':
						wp_update_term( $child, 'category', array( 'description' => 'Described' ) );
						break;
					case 'slug':
						wp_update_term( $child, 'category', array( 'slug' => 'new-child' ) );
						break;
					case 'parent_slug':
						wp_update_term( $parent, 'category', array( 'slug' => 'new-parent' ) );
						break;
					default:
						wp_update_term( $child, 'category', array( 'parent' => $other ) );
				}
			}
		);

		if ( $flushes ) {
			$this->assert_flushed( array( $single, $link ) );
		} else {
			$this->assert_active( array( $single, $link ) );
		}
		$this->assert_active( array( '/wp/v2/posts/' . $outside ) );
	}

	/**
	 * @dataProvider stores
	 *
	 * @param string $store Store.
	 */
	public function test_a_tag_slug_change_flushes_the_posts_showing_it_in_class_list( string $store ): void {
		$tag     = $this->factory()->tag->create( array( 'slug' => 'old-tag' ) );
		$post_id = $this->factory()->post->create( array( 'tags_input' => array( $tag ) ) );
		$other   = $this->factory()->post->create();
		$this->arm( $store );

		$single = '/wp/v2/posts/' . $post_id;
		$this->assertStringContainsString( 'tag-old-tag', $this->serve( $single ) );
		$this->serve( '/wp/v2/posts/' . $other );

		$this->change_and_commit( fn() => wp_update_term( $tag, 'post_tag', array( 'slug' => 'new-tag' ) ) );

		$this->assert_flushed( array( $single ) );
		$this->assert_active( array( '/wp/v2/posts/' . $other ) );
	}

	/**
	 * @dataProvider stores
	 *
	 * @param string $store Store.
	 */
	public function test_deleting_a_term_with_more_objects_than_post_tags_flushes_through_the_term_reference( string $store ): void {
		$category = $this->factory()->category->create();
		$post_id  = $this->factory()->post->create( array( 'post_category' => array( $category ) ) );
		$outside  = $this->factory()->post->create();
		$this->arm( $store );

		$single = '/wp/v2/posts/' . $post_id;
		foreach ( array( $single, '/wp/v2/posts/' . $outside ) as $uri ) {
			$this->serve( $uri );
		}

		// 201 objects (none of them the post above): no post:{id} tags, only the term and its references.
		$term = get_term( $category );
		$this->change_and_commit(
			fn() => Plugin::instance()->listener( Term_Events::class )->on_delete_term( $category, (int) $term->term_taxonomy_id, 'category', $term, range( 900000, 900200 ) )
		);

		$this->assertNotContains( 'post:' . $post_id, $this->flushed_tags() );
		$this->assert_only_flushed( array( $single ), 'term-ref:' . $category );
	}

	/**
	 * @dataProvider stores
	 *
	 * @param string $store Store.
	 */
	public function test_changing_the_categories_of_a_post_flushes_that_post( string $store ): void {
		$other   = $this->factory()->category->create();
		$post_id = $this->factory()->post->create();
		$sibling = $this->factory()->post->create();
		$this->arm( $store );

		$single = '/wp/v2/posts/' . $post_id;
		foreach ( array( $single, '/wp/v2/posts/' . $sibling ) as $uri ) {
			$this->serve( $uri );
		}

		$this->change_and_commit( fn() => $this->rest_write( '/wp/v2/posts/' . $post_id, array( 'categories' => array( $other ) ) ) );

		$this->assert_flushed( array( $single ), 'post:' . $post_id );
		$this->assert_active( array( '/wp/v2/posts/' . $sibling ) );
	}
}
