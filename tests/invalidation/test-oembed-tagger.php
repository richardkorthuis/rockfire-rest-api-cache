<?php
/**
 * Tests for the oEmbed tagger.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Invalidation\Oembed_Tagger;

/**
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\Oembed_Tagger
 */
class Test_Oembed_Tagger extends Rfrac_Test_Case {

	/**
	 * Tagger under test.
	 *
	 * @var Oembed_Tagger
	 */
	private Oembed_Tagger $tagger;

	public function set_up() {
		parent::set_up();
		$this->tagger = new Oembed_Tagger();
	}

	/**
	 * Build a request.
	 *
	 * @param string $route Route.
	 * @param mixed  $url   The `url` parameter, when not null.
	 *
	 * @return WP_REST_Request
	 */
	private function request( string $route, mixed $url ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', $route );
		if ( null !== $url ) {
			$request->set_param( 'url', $url );
		}
		return $request;
	}

	public function test_post_and_author(): void {
		$user = self::factory()->user->create();
		$post = self::factory()->post->create( array( 'post_author' => $user ) );

		$tags = $this->tagger->tags_for( $this->request( '/oembed/1.0/embed', get_permalink( $post ) ) );

		$this->assertSame( array( 'post:' . $post, 'user:' . $user ), $tags );
	}

	public function test_page_resolves_like_any_post(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$tags = $this->tagger->tags_for( $this->request( '/oembed/1.0/embed', get_permalink( $page ) ) );

		$this->assertContains( 'post:' . $page, $tags );
	}

	public function test_relative_url_gets_the_home_url(): void {
		$post = self::factory()->post->create();

		$tags = $this->tagger->tags_for( $this->request( '/oembed/1.0/embed', '/?p=' . $post ) );

		$this->assertContains( 'post:' . $post, $tags );
	}

	public function test_scheme_relative_and_absolute_foreign_urls_are_not_resolved_as_paths(): void {
		$post = self::factory()->post->create();

		$this->assertSame( array(), $this->tagger->tags_for( $this->request( '/oembed/1.0/embed', '//elsewhere.test/?p=' . $post ) ) );
		$this->assertSame( array(), $this->tagger->tags_for( $this->request( '/oembed/1.0/embed', 'https://elsewhere.test/?p=' . $post ) ) );
	}

	public function test_featured_image_is_tagged(): void {
		$post       = self::factory()->post->create();
		$attachment = self::factory()->attachment->create_object( array( 'file' => 'cover.jpg', 'post_mime_type' => 'image/jpeg' ), $post );
		set_post_thumbnail( $post, $attachment );

		$tags = $this->tagger->tags_for( $this->request( '/oembed/1.0/embed', get_permalink( $post ) ) );

		$this->assertContains( 'post:' . $post, $tags );
		$this->assertContains( 'post:' . $attachment, $tags );
	}

	public function test_unknown_and_foreign_urls_give_no_post_tag(): void {
		$this->assertSame( array(), $this->tagger->tags_for( $this->request( '/oembed/1.0/embed', 'https://elsewhere.test/hello/' ) ) );
		$this->assertSame( array(), $this->tagger->tags_for( $this->request( '/oembed/1.0/embed', home_url( '/?p=999999' ) ) ) );
	}

	public function test_filtered_post_id(): void {
		$other = self::factory()->post->create();
		add_filter(
			'oembed_request_post_id',
			static function () use ( $other ) {
				return $other;
			}
		);

		$tags = $this->tagger->tags_for( $this->request( '/oembed/1.0/embed', 'https://elsewhere.test/hello/' ) );

		$this->assertContains( 'post:' . $other, $tags );
	}

	public function test_filter_returning_zero_gives_no_tags(): void {
		$post = self::factory()->post->create();
		add_filter( 'oembed_request_post_id', '__return_zero' );

		$this->assertSame( array(), $this->tagger->tags_for( $this->request( '/oembed/1.0/embed', get_permalink( $post ) ) ) );
	}

	public function test_author_zero_gives_no_user_tag(): void {
		$post = self::factory()->post->create( array( 'post_author' => 0 ) );

		$this->assertSame( array( 'post:' . $post ), $this->tagger->tags_for( $this->request( '/oembed/1.0/embed', get_permalink( $post ) ) ) );
	}

	public function test_other_routes_and_missing_or_invalid_url_are_ignored(): void {
		$post = self::factory()->post->create();

		$this->assertSame( array(), $this->tagger->tags_for( $this->request( '/wp/v2/posts', get_permalink( $post ) ) ) );
		$this->assertSame( array(), $this->tagger->tags_for( $this->request( '/oembed/1.0/embed', null ) ) );
		$this->assertSame( array(), $this->tagger->tags_for( $this->request( '/oembed/1.0/embed', array( 'x' ) ) ) );
		$this->assertSame( array(), $this->tagger->tags_for( $this->request( '/oembed/1.0/embed', '' ) ) );
	}
}
