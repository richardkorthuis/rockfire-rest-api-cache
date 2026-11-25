<?php
/**
 * End-to-end invalidation matrix: real cached REST responses, real content changes, real flushes.
 *
 * Every row serves anonymous requests through the REST server so the production capture stores entries with
 * their collected tags, changes content through WordPress APIs, commits the invalidator and asserts which
 * entries were flushed. Each row runs against the database store and the object-cache store.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Hooks;

/**
 * @coversNothing
 */
class Test_Invalidation_Matrix extends Rfrac_Matrix_Test_Case {

	public function tear_down() {
		unregister_post_type( 'book' );
		parent::tear_down();
	}

	/**
	 * Register the `book` post type (REST base `books`) and enable its routes.
	 *
	 * @return void
	 */
	private function register_books(): void {
		register_post_type(
			'book',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'books',
			)
		);
		$this->enable_route( '/wp/v2/books' );
		$this->enable_route( '/wp/v2/books/(?P<id>[\d]+)' );
	}

	/**
	 * Register custom routes of the `test/v1` namespace on `rest_api_init`.
	 *
	 * @param array<string,callable> $callbacks Route path => callback.
	 *
	 * @return void
	 */
	private function register_custom_routes( array $callbacks ): void {
		add_action(
			'rest_api_init',
			static function () use ( $callbacks ): void {
				foreach ( $callbacks as $path => $callback ) {
					register_rest_route(
						'test/v1',
						$path,
						array(
							'methods'             => 'GET',
							'permission_callback' => '__return_true',
							'callback'            => $callback,
						)
					);
				}
			}
		);
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_1_post_title_update_flushes_the_post_and_its_lists( string $store ): void {
		$post_id = $this->factory()->post->create( array( 'post_title' => 'First' ) );
		$this->factory()->post->create( array( 'post_title' => 'Second' ) );
		$this->factory()->post->create( array( 'post_type' => 'page' ) );
		$this->arm( $store );

		$uris = array(
			'/wp/v2/posts/' . $post_id,
			'/wp/v2/posts',
			'/wp/v2/posts?_fields=title',
			'/wp/v2/posts?page=2&per_page=1',
		);
		foreach ( array_merge( $uris, array( '/wp/v2/pages' ) ) as $uri ) {
			$this->serve( $uri );
		}
		$this->assert_active( array_merge( $uris, array( '/wp/v2/pages' ) ) );

		$this->change_and_commit( fn() => wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Renamed' ) ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

		$this->assert_only_flushed( $uris, 'post:' . $post_id );
		$this->assert_one_flush_query( $store );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_2_new_post_flushes_the_list_and_the_previously_empty_category_list( string $store ): void {
		$category = $this->factory()->category->create();
		$this->factory()->post->create();
		$this->factory()->post->create( array( 'post_type' => 'page' ) );
		add_filter( Hooks::CACHE_EMPTY_RESULTS, '__return_true' ); // Empty result sets are not stored by default.
		$this->arm( $store );

		$empty = '/wp/v2/posts?categories=' . $category;
		$this->serve( '/wp/v2/posts' );
		$this->serve( $empty );
		$this->serve( '/wp/v2/pages' );

		$this->change_and_commit(
			fn() => wp_insert_post(
				array(
					'post_title'    => 'New',
					'post_status'   => 'publish',
					'post_category' => array( $category ),
				)
			)
		);

		$this->assert_only_flushed( array( '/wp/v2/posts', $empty ), 'list:post' );
		$this->assert_one_flush_query( $store );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_3_custom_post_type_update_flushes_only_its_routes( string $store ): void {
		$this->register_books();
		$book = $this->factory()->post->create( array( 'post_type' => 'book' ) );
		$this->factory()->post->create();
		$this->arm( $store );

		$this->serve( '/wp/v2/books' );
		$this->serve( '/wp/v2/books/' . $book );
		$this->serve( '/wp/v2/posts' );

		$this->change_and_commit( fn() => wp_update_post( array( 'ID' => $book, 'post_title' => 'Updated book' ) ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

		$this->assert_only_flushed( array( '/wp/v2/books', '/wp/v2/books/' . $book ), 'post:' . $book );
		$this->assert_one_flush_query( $store );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_4_attachment_alt_text_flushes_the_media_routes( string $store ): void {
		$attachment = $this->factory()->attachment->create_object(
			array(
				'file'           => 'alt.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Image',
			)
		);
		$this->factory()->post->create( array( 'post_type' => 'page' ) );
		$this->arm( $store );

		$this->serve( '/wp/v2/media/' . $attachment );
		$this->serve( '/wp/v2/media' );
		$this->serve( '/wp/v2/pages' );

		$this->change_and_commit( fn() => update_post_meta( $attachment, '_wp_attachment_image_alt', 'A description' ) );

		$this->assert_only_flushed( array( '/wp/v2/media/' . $attachment, '/wp/v2/media' ), 'post:' . $attachment );
		$this->assert_one_flush_query( $store );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_5_assigning_a_grandchild_term_flushes_the_ancestors_lists_and_the_post( string $store ): void {
		$root       = $this->factory()->category->create( array( 'name' => 'Root' ) );
		$child      = $this->factory()->category->create( array( 'name' => 'Child', 'parent' => $root ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$grandchild = $this->factory()->category->create( array( 'name' => 'Grandchild', 'parent' => $child ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$post_id    = $this->factory()->post->create();
		$this->factory()->post->create( array( 'post_type' => 'page' ) );
		$this->arm( $store );

		$uris = array( '/wp/v2/categories/' . $root, '/wp/v2/categories', '/wp/v2/posts/' . $post_id );
		foreach ( array_merge( $uris, array( '/wp/v2/pages' ) ) as $uri ) {
			$this->serve( $uri );
		}

		$this->change_and_commit( fn() => wp_set_object_terms( $post_id, array( $grandchild ), 'category' ) );

		$this->assert_only_flushed( $uris, 'term:' . $root );
		$this->assert_one_flush_query( $store );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_6_term_rename_flushes_the_term_and_embedded_terms( string $store ): void {
		$category = $this->factory()->category->create( array( 'name' => 'Before' ) );
		$post_id  = $this->factory()->post->create();
		wp_set_object_terms( $post_id, array( $category ), 'category' );
		$this->factory()->post->create( array( 'post_type' => 'page' ) );
		$this->arm( $store );

		$uris = array( '/wp/v2/categories/' . $category, '/wp/v2/posts?_embed=wp:term' );
		foreach ( array_merge( $uris, array( '/wp/v2/pages' ) ) as $uri ) {
			$this->serve( $uri );
		}

		$this->change_and_commit( fn() => wp_update_term( $category, 'category', array( 'name' => 'After' ) ) );

		$this->assert_only_flushed( $uris, 'term:' . $category );
		$this->assert_one_flush_query( $store );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_7_user_profile_update_flushes_the_user_and_embedded_authors( string $store ): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'author', 'display_name' => 'Before' ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->factory()->post->create( array( 'post_author' => $user_id ) );
		$this->factory()->post->create( array( 'post_type' => 'page' ) );
		$this->arm( $store );

		$uris = array( '/wp/v2/users/' . $user_id, '/wp/v2/posts?_embed=author' );
		foreach ( array_merge( $uris, array( '/wp/v2/pages' ) ) as $uri ) {
			$this->serve( $uri );
		}

		$this->change_and_commit( fn() => wp_update_user( array( 'ID' => $user_id, 'display_name' => 'After' ) ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

		$this->assert_only_flushed( $uris, 'user:' . $user_id );
		$this->assert_one_flush_query( $store );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_8_approved_comment_edit_flushes_the_comment_routes( string $store ): void {
		$post_id = $this->factory()->post->create();
		$comment = $this->factory()->comment->create( array( 'comment_post_ID' => $post_id, 'comment_approved' => '1' ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->factory()->post->create( array( 'post_type' => 'page' ) );
		$this->arm( $store );

		$uris = array( '/wp/v2/comments?post=' . $post_id, '/wp/v2/comments/' . $comment );
		foreach ( array_merge( $uris, array( '/wp/v2/pages' ) ) as $uri ) {
			$this->serve( $uri );
		}

		$this->change_and_commit( fn() => wp_update_comment( array( 'comment_ID' => $comment, 'comment_content' => 'Edited' ) ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

		$this->assert_only_flushed( $uris, 'comment:' . $comment );
		$this->assert_one_flush_query( $store );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_9_spam_and_held_comments_flush_nothing( string $store ): void {
		$post_id = $this->factory()->post->create();
		$this->factory()->comment->create( array( 'comment_post_ID' => $post_id, 'comment_approved' => '1' ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->arm( $store );

		foreach ( array( '/wp/v2/comments?post=' . $post_id, '/wp/v2/posts/' . $post_id, '/wp/v2/posts' ) as $uri ) {
			$this->serve( $uri );
		}

		$this->change_and_commit(
			function () use ( $post_id ): void {
				$base = array(
					'comment_post_ID'      => $post_id,
					'comment_author'       => 'Visitor',
					'comment_author_email' => 'visitor@example.org',
					'comment_content'      => 'Held',
				);
				wp_insert_comment( array_merge( $base, array( 'comment_approved' => '0' ) ) );
				wp_insert_comment( array_merge( $base, array( 'comment_content' => 'Spam', 'comment_approved' => 'spam' ) ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
			}
		);

		$this->assert_only_flushed( array() );
		$this->assertSame( array(), $this->entry_updates(), 'No UPDATE on the entries table.' );
		$this->assertSame( array(), $this->flushed );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_10_route_rule_flush_post_types_flushes_a_custom_route_on_a_new_book( string $store ): void {
		$this->register_books();
		$this->register_custom_routes( array( '/latest' => static fn() => array( 'latest' => 'book' ) ) );
		$this->enable_route( '/test/v1/latest', array( 'flush_post_types' => array( 'book' ) ) );
		$this->factory()->post->create( array( 'post_type' => 'page' ) );
		$this->arm( $store );

		$this->serve( '/test/v1/latest' );
		$this->serve( '/wp/v2/pages' );

		$this->change_and_commit( fn() => wp_insert_post( array( 'post_type' => 'book', 'post_title' => 'New book', 'post_status' => 'publish' ) ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

		$this->assert_only_flushed( array( '/test/v1/latest' ), 'list:book' );
		$this->assert_one_flush_query( $store );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_11_fallback_parser_flushes_a_route_returning_posts( string $store ): void {
		$posts = $this->factory()->post->create_many( 2 );
		$this->register_custom_routes( array( '/raw' => static fn() => get_posts( array( 'numberposts' => 5 ) ) ) );
		$this->enable_route( '/test/v1/raw' );
		$this->factory()->post->create( array( 'post_type' => 'page' ) );
		$this->arm( $store );

		$this->serve( '/test/v1/raw' );
		$this->serve( '/wp/v2/pages' );

		$this->change_and_commit( fn() => wp_update_post( array( 'ID' => $posts[0], 'post_title' => 'Changed' ) ) );

		$this->assert_only_flushed( array( '/test/v1/raw' ), 'post:' . $posts[0] );
		$this->assert_one_flush_query( $store );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_12_custom_response_tag_is_flushed_by_rfrac_flush_tag( string $store ): void {
		$this->register_custom_routes(
			array(
				'/tagged' => static function () {
					rfrac_tag_response( 'acf-options:header' );
					return array( 'tagged' => true );
				},
				'/other'  => static function () {
					rfrac_tag_response( 'acf-options:footer' );
					return array( 'other' => true );
				},
			)
		);
		$this->enable_route( '/test/v1/tagged' );
		$this->enable_route( '/test/v1/other' );
		$this->arm( $store );

		$this->serve( '/test/v1/tagged' );
		$this->serve( '/test/v1/other' );
		$this->assertContains( 'acf-options:header', $this->tags_of( '/test/v1/tagged' ) );

		$this->change_and_commit( fn() => rfrac_flush_tag( 'acf-options:header' ) );

		$this->assert_only_flushed( array( '/test/v1/tagged' ), 'acf-options:header' );
		$this->assert_one_flush_query( $store );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_13_post_update_flushes_its_oembed_response( string $store ): void {
		$post_id = $this->factory()->post->create( array( 'post_title' => 'Embedded' ) );
		$this->factory()->post->create( array( 'post_type' => 'page' ) );
		$this->arm( $store );

		$oembed = '/oembed/1.0/embed?url=' . rawurlencode( get_permalink( $post_id ) );
		$this->serve( $oembed );
		$this->serve( '/wp/v2/pages' );

		$this->change_and_commit( fn() => wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Embedded again' ) ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

		$this->assert_only_flushed( array( $oembed ), 'post:' . $post_id );
		$this->assert_one_flush_query( $store );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_14_repeated_changes_in_one_request_cost_one_update_without_duplicate_targets( string $store ): void {
		$category = $this->factory()->category->create( array( 'name' => 'Before' ) );
		$post_id  = $this->factory()->post->create();
		wp_set_object_terms( $post_id, array( $category ), 'category' );
		$this->factory()->post->create( array( 'post_type' => 'page' ) );
		$this->arm( $store );

		$uris = array( '/wp/v2/posts/' . $post_id, '/wp/v2/posts', '/wp/v2/categories/' . $category );
		foreach ( array_merge( $uris, array( '/wp/v2/pages' ) ) as $uri ) {
			$this->serve( $uri );
		}

		$this->change_and_commit(
			function () use ( $post_id, $category ): void {
				for ( $i = 1; $i <= 3; $i++ ) {
					wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Title ' . $i ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
				}
				wp_update_term( $category, 'category', array( 'name' => 'After' ) );
			}
		);

		$this->assert_only_flushed( $uris, 'post:' . $post_id );
		$this->assert_one_flush_query( $store );

		$targets = array();
		foreach ( $this->flushed as $event ) {
			$targets = array_merge( $targets, $event[1] );
		}
		$this->assertNotEmpty( $targets, 'rfrac/flushed reported the tags.' );
		$this->assertSame( array_values( array_unique( $targets ) ), $targets, 'rfrac/flushed targets contain no duplicates: ' . implode( ', ', $targets ) );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_16_a_post_made_private_during_its_own_dispatch_is_not_stored( string $store ): void {
		$post_id = $this->factory()->post->create( array( 'post_title' => 'Public' ) );
		$this->arm( $store );

		$uri = '/wp/v2/posts/' . $post_id;
		$this->serve_after_lookup( $uri, fn() => $this->change_and_commit( fn() => wp_update_post( array( 'ID' => $post_id, 'post_status' => 'private' ) ) ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

		$this->assertNotEmpty( $this->flushed, 'The invalidator committed the take-down during the dispatch.' );
		$this->assertContains( 'post:' . $post_id, array_merge( ...array_column( $this->flushed, 1 ) ) );
		$this->assertSame( 0, array_sum( array_column( $this->flushed, 2 ) ), 'Nothing was stored yet, so the flush matched no entry.' );
		$this->assertSame( 'BYPASS', $this->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 'flushed', $this->live_header( 'X-Rfrac-Cache-Reason' ) );
		$this->assertSame( 0, $this->stored_count(), 'No rfrac/stored event.' );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'No entry was stored.' );

		foreach ( array( Rfrac_Serving_Harness::EARLY, Rfrac_Serving_Harness::FALLBACK ) as $path ) {
			$harness = new Rfrac_Serving_Harness( $path );
			try {
				$harness->request( untrailingslashit( (string) wp_parse_url( rest_url(), PHP_URL_PATH ) ) . $uri );
				$this->assertFalse( $harness->lookup(), 'The next lookup is not a hit on the ' . $path . ' path.' );
			} finally {
				$harness->restore();
			}
		}
	}
}
