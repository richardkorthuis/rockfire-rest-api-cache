<?php
/**
 * End-to-end invalidation matrix, take-down rows: what a post's comments and attachments show once the post is
 * taken down or a media item is attached to a post.
 *
 * Every row serves anonymous requests through the REST server so the production capture stores entries with
 * their collected tags, changes content through WordPress APIs, commits the invalidator and asserts which
 * entries were flushed and that neither serving path (early, fallback) serves them any more. Each row runs against
 * the database store and the object-cache store.
 *
 * @package Rockfire_Rest_Api_Cache
 */

/**
 * @coversNothing
 */
class Test_Invalidation_Matrix_Take_Down extends Rfrac_Matrix_Test_Case {

	/**
	 * Children of a post: one more than the 200 that get a tag of their own when the post is taken down.
	 *
	 * @var int
	 */
	private const CHILDREN = 201;

	/**
	 * Request URI of a path below the REST prefix, as the serving paths see it.
	 *
	 * @param string $uri Path below the REST prefix, e.g. `/wp/v2/media/5`.
	 *
	 * @return string
	 */
	private function request_uri( string $uri ): string {
		return untrailingslashit( (string) wp_parse_url( rest_url(), PHP_URL_PATH ) ) . $uri;
	}

	/**
	 * Assert whether both serving paths serve a stored entry from the cache.
	 *
	 * @param string $uri    Served URI.
	 * @param bool   $served Whether a request for it is a hit.
	 *
	 * @return void
	 */
	private function assert_served( string $uri, bool $served ): void {
		foreach ( array( Rfrac_Serving_Harness::EARLY, Rfrac_Serving_Harness::FALLBACK ) as $path ) {
			$harness = new Rfrac_Serving_Harness( $path );
			try {
				$harness->request( $this->request_uri( $uri ) );
				$this->assertSame( $served, $harness->lookup(), sprintf( '%s is %sserved on the %s path.', $uri, $served ? '' : 'not ', $path ) );
			} finally {
				$harness->restore();
			}
		}
	}

	/**
	 * The tags of every tag flush of the last change.
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
	 * Create an attachment.
	 *
	 * @param int    $parent Parent post, 0 for none.
	 * @param string $file   File name.
	 *
	 * @return int
	 */
	private function attachment( int $parent, string $file ): int {
		return (int) $this->factory()->attachment->create_object(
			array(
				'file'           => $file,
				'post_parent'    => $parent,
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $file,
			)
		);
	}

	/**
	 * @dataProvider stores
	 *
	 * @param string $store Store.
	 */
	public function test_row_17_attaching_media_to_a_draft_flushes_the_media_item_and_list( string $store ): void {
		$draft      = $this->factory()->post->create( array( 'post_status' => 'draft' ) );
		$attachment = $this->attachment( 0, 'attach.jpg' );
		$other      = $this->attachment( 0, 'other.jpg' );
		$admin      = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$this->arm( $store );

		$item = '/wp/v2/media/' . $attachment;
		foreach ( array( $item, '/wp/v2/media', '/wp/v2/media/' . $other ) as $uri ) {
			$this->serve( $uri );
		}
		$this->assert_served( $item, true );
		$this->assert_served( '/wp/v2/media', true );

		$this->change_and_commit(
			function () use ( $admin, $draft, $attachment ): void {
				// The "Attach" action of the media library: it updates `post_parent` with SQL and ends in a redirect.
				$redirect = static function ( $location, $status ) {
					throw new Redirect_Intercepted( (string) $location, (int) $status );
				};
				add_filter( 'wp_redirect', $redirect, PHP_INT_MAX, 2 );
				wp_set_current_user( $admin );
				$_REQUEST['media'] = array( $attachment );
				try {
					wp_media_attach_action( $draft );
					$this->fail( 'The attach action ends in a redirect.' );
				} catch ( Redirect_Intercepted $intercepted ) {
					$this->assertSame( $draft, (int) wp_get_post_parent_id( $attachment ) );
				} finally {
					unset( $_REQUEST['media'] );
					remove_filter( 'wp_redirect', $redirect, PHP_INT_MAX );
					wp_set_current_user( 0 );
				}
			}
		);

		$this->assertContains( 'post:' . $attachment, $this->flushed_tags() );
		$this->assertContains( 'list:attachment', $this->flushed_tags() );
		$this->assert_only_flushed( array( $item, '/wp/v2/media' ), 'post:' . $attachment );
		$this->assert_served( $item, false );
		$this->assert_served( '/wp/v2/media', false );
	}

	/**
	 * @dataProvider stores
	 *
	 * @param string $store Store.
	 */
	public function test_row_18_making_a_post_private_flushes_the_201st_comment( string $store ): void {
		$post     = $this->factory()->post->create( array( 'post_status' => 'publish' ) );
		$comments = $this->factory()->comment->create_many(
			self::CHILDREN,
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '1',
			)
		);
		$other    = $this->factory()->post->create( array( 'post_status' => 'publish' ) );
		$sibling  = $this->factory()->comment->create(
			array(
				'comment_post_ID'  => $other,
				'comment_approved' => '1',
			)
		);
		$this->arm( $store );

		$last = '/wp/v2/comments/' . $comments[ self::CHILDREN - 1 ];
		foreach ( array( $last, '/wp/v2/comments/' . $sibling ) as $uri ) {
			$this->serve( $uri );
		}
		$this->assertContains( 'post:' . $post, $this->tags_of( $last ) );
		$this->assert_served( $last, true );

		$this->change_and_commit( fn() => wp_update_post( array( 'ID' => $post, 'post_status' => 'private' ) ) );

		$this->assertSame( array(), preg_grep( '/^comment:/', $this->flushed_tags() ), 'Past the limit no comment has a tag of its own.' );
		$this->assert_only_flushed( array( $last ), 'post:' . $post );
		$this->assert_served( $last, false );
	}

	/**
	 * @dataProvider stores
	 *
	 * @param string $store Store.
	 */
	public function test_row_19_making_a_post_private_flushes_the_201st_attachment( string $store ): void {
		$post        = $this->factory()->post->create( array( 'post_status' => 'publish' ) );
		$attachments = array();
		for ( $i = 0; $i < self::CHILDREN; $i++ ) {
			$attachments[] = $this->attachment( $post, 'child-' . $i . '.jpg' );
		}
		$other   = $this->factory()->post->create( array( 'post_status' => 'publish' ) );
		$sibling = $this->attachment( $other, 'sibling.jpg' );
		$this->arm( $store );

		$last = '/wp/v2/media/' . $attachments[ self::CHILDREN - 1 ];
		foreach ( array( $last, '/wp/v2/media/' . $sibling ) as $uri ) {
			$this->serve( $uri );
		}
		$this->assertContains( 'post:' . $post, $this->tags_of( $last ) );
		$this->assert_served( $last, true );

		$this->change_and_commit( fn() => wp_update_post( array( 'ID' => $post, 'post_status' => 'private' ) ) );

		$flushed = $this->flushed_tags();
		$this->assertNotContains( 'post:' . $attachments[ self::CHILDREN - 1 ], $flushed, 'Past the limit no attachment has a tag of its own.' );
		$this->assertContains( 'post:' . $post, $flushed );
		$this->assert_only_flushed( array( $last ), 'post:' . $post );
		$this->assert_served( $last, false );
	}
}
