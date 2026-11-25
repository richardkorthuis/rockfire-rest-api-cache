<?php
/**
 * Tests for the meta invalidation listeners.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Invalidation\Meta_Events;
use Rockfire\Rest_Api_Cache\Invalidation\Relevance;

/**
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\Meta_Events
 */
class Test_Meta_Events extends Rfrac_Test_Case {

	/**
	 * Invalidator under test.
	 *
	 * @var Invalidator
	 */
	private Invalidator $inv;

	public function set_up() {
		parent::set_up();
		$this->inv = new Invalidator( new Rfrac_Spy_Store(), static fn( string $url ): ?string => null );
		( new Meta_Events( $this->inv, new Relevance( new Config() ) ) )->register();
	}

	public function tear_down() {
		unregister_meta_key( 'post', 'rfrac_price', 'post' );
		unregister_meta_key( 'post', 'rfrac_hidden_meta', 'post' );
		unregister_meta_key( 'term', 'rfrac_term_meta' );
		unregister_meta_key( 'post', 'rfrac_any_subtype' );
		unregister_meta_key( 'post', 'rfrac_page_only', 'page' );
		parent::tear_down();
	}

	/**
	 * Pending tags of the current site.
	 *
	 * @return string[]
	 */
	private function pending(): array {
		return $this->inv->pending()[ get_current_blog_id() ] ?? array();
	}

	/**
	 * Create a post, then forget what its creation queued.
	 *
	 * @param string $status Status.
	 *
	 * @return int
	 */
	private function make_post( string $status = 'publish' ): int {
		$id = self::factory()->post->create( array( 'post_status' => $status ) );
		$this->inv->commit();
		return $id;
	}

	public function test_default_does_not_flush_for_unregistered_or_private_keys(): void {
		$id = $this->make_post();
		update_post_meta( $id, '_edit_lock', '1:2' );
		update_post_meta( $id, 'field_x', 'a' );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_registered_show_in_rest_key_flushes_published_post_only(): void {
		register_post_meta( 'post', 'rfrac_price', array( 'show_in_rest' => true, 'single' => true ) );
		$published = $this->make_post();
		update_post_meta( $published, 'rfrac_price', '10' );
		$this->assertSame( array( 'post:' . $published ), $this->pending() );
		$this->inv->commit();

		$draft = $this->make_post( 'draft' );
		update_post_meta( $draft, 'rfrac_price', '10' );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_registered_key_without_rest_exposure_does_not_flush(): void {
		register_post_meta( 'post', 'rfrac_hidden_meta', array( 'show_in_rest' => false, 'single' => true ) );
		$id = $this->make_post();
		update_post_meta( $id, 'rfrac_hidden_meta', 'x' );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_added_and_deleted_meta_flush_too(): void {
		register_post_meta( 'post', 'rfrac_price', array( 'show_in_rest' => true, 'single' => true ) );
		$id = $this->make_post();
		add_post_meta( $id, 'rfrac_price', '1' );
		$this->assertSame( array( 'post:' . $id ), $this->pending() );
		$this->inv->commit();
		delete_post_meta( $id, 'rfrac_price' );
		$this->assertSame( array( 'post:' . $id ), $this->pending() );
	}

	public function test_set_post_thumbnail_flushes_the_post(): void {
		$id         = $this->make_post();
		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->inv->commit();
		set_post_thumbnail( $id, $attachment );
		$this->assertContains( 'post:' . $id, $this->pending() );
	}

	public function test_filter_can_enable_an_unregistered_key(): void {
		add_filter( Hooks::FLUSH_ON_META_UPDATE, '__return_true' );
		$id = $this->make_post();
		update_post_meta( $id, 'field_x', 'a' );
		$this->assertSame( array( 'post:' . $id ), $this->pending() );
	}

	public function test_key_specific_filter_overrides_the_general_decision(): void {
		add_filter( Hooks::FLUSH_ON_META_UPDATE, '__return_true' );
		add_filter( 'rfrac/flush_on_meta_update/post/field_x', '__return_false' );
		$id = $this->make_post();
		update_post_meta( $id, 'field_x', 'a' );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_key_specific_filter_can_enable_a_key(): void {
		add_filter( 'rfrac/flush_on_meta_update/post/special', '__return_true' );
		$id = $this->make_post();
		update_post_meta( $id, 'special', 'a' );
		$this->assertSame( array( 'post:' . $id ), $this->pending() );
	}

	public function test_non_strict_truthy_filter_result_does_not_flush(): void {
		add_filter( Hooks::FLUSH_ON_META_UPDATE, static fn() => 1 );
		$id = $this->make_post();
		update_post_meta( $id, 'field_x', 'a' );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_general_filter_receives_default_type_object_key_value_and_action(): void {
		$captured = array();
		add_filter(
			Hooks::FLUSH_ON_META_UPDATE,
			static function ( ...$args ) use ( &$captured ) {
				$captured[] = $args;
				return false;
			},
			10,
			6
		);
		$id = $this->make_post();
		add_post_meta( $id, 'k', 'v' );
		update_post_meta( $id, 'k', 'w' );
		delete_post_meta( $id, 'k' );
		$actions = array_column( array_filter( $captured, static fn( $a ) => 'k' === $a[3] ), 5 );
		// The plugin's own listener sees the same filter calls.
		$this->assertSame( array( 'added', 'updated', 'deleted' ), array_values( array_unique( $actions ) ) );
		$first = array_values( array_filter( $captured, static fn( $a ) => 'k' === $a[3] ) )[0];
		$this->assertSame( array( false, 'post', $id, 'k', 'v', 'added' ), $first );
	}

	public function test_specific_filter_receives_object_value_and_action(): void {
		$captured = array();
		add_filter(
			'rfrac/flush_on_meta_update/post/test_key',
			static function ( ...$args ) use ( &$captured ) {
				$captured = $args;
				return false;
			},
			10,
			4
		);
		$id = $this->make_post();
		update_post_meta( $id, 'test_key', 'value' );
		$this->assertSame( array( false, $id, 'value', 'added' ), $captured );
	}

	public function test_term_meta_with_a_truthy_filter_flushes_the_term(): void {
		add_filter( Hooks::FLUSH_ON_META_UPDATE, '__return_true' );
		$term = self::factory()->category->create();
		$this->inv->commit();
		update_term_meta( $term, 'field_x', 'a' );
		$this->assertSame( array( 'term:' . $term ), $this->pending() );
	}

	public function test_registered_term_meta_flushes_by_default(): void {
		register_term_meta( 'category', 'rfrac_term_meta', array( 'show_in_rest' => true, 'single' => true ) );
		$term = self::factory()->category->create();
		$this->inv->commit();
		update_term_meta( $term, 'rfrac_term_meta', 'a' );
		$this->assertSame( array( 'term:' . $term ), $this->pending() );
		unregister_meta_key( 'term', 'rfrac_term_meta', 'category' );
	}

	public function test_user_meta_with_a_truthy_filter_flushes_the_user(): void {
		add_filter( Hooks::FLUSH_ON_META_UPDATE, '__return_true' );
		$user = self::factory()->user->create();
		$this->inv->commit();
		update_user_meta( $user, 'field_x', 'a' );
		$this->assertSame( array( 'user:' . $user ), $this->pending() );
	}

	public function test_comment_meta_flushes_only_for_approved_comments(): void {
		add_filter( Hooks::FLUSH_ON_META_UPDATE, '__return_true' );
		$post     = $this->make_post();
		$approved = self::factory()->comment->create( array( 'comment_post_ID' => $post, 'comment_approved' => 1 ) );
		$held     = self::factory()->comment->create( array( 'comment_post_ID' => $post, 'comment_approved' => 0 ) );
		$this->inv->commit();

		update_comment_meta( $held, 'field_x', 'a' );
		$this->assertSame( array(), $this->pending() );
		update_comment_meta( $approved, 'field_x', 'a' );
		$this->assertSame( array( 'comment:' . $approved ), $this->pending() );
	}

	public function test_meta_of_an_irrelevant_post_type_is_ignored(): void {
		register_post_type( 'rfrac_secret', array( 'public' => true ) );
		add_filter( Hooks::FLUSH_ON_META_UPDATE, '__return_true' );
		$id = self::factory()->post->create( array( 'post_type' => 'rfrac_secret', 'post_status' => 'publish' ) );
		$this->inv->commit();
		update_post_meta( $id, 'field_x', 'a' );
		$this->assertSame( array(), $this->pending() );
		unregister_post_type( 'rfrac_secret' );
	}

	public function test_key_registered_for_every_subtype_flushes(): void {
		// register_meta() without object_subtype registers under the '' subtype: the most common real-world shape.
		register_meta( 'post', 'rfrac_any_subtype', array( 'show_in_rest' => true, 'single' => true ) );
		$post = $this->make_post();
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$this->inv->commit();

		update_post_meta( $post, 'rfrac_any_subtype', 'a' );
		update_post_meta( $page, 'rfrac_any_subtype', 'a' );
		$this->assertEqualsCanonicalizing( array( 'post:' . $post, 'post:' . $page ), $this->pending() );
	}

	public function test_key_registered_for_another_subtype_does_not_flush(): void {
		register_post_meta( 'page', 'rfrac_page_only', array( 'show_in_rest' => true, 'single' => true ) );
		$post = $this->make_post();
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$this->inv->commit();

		update_post_meta( $post, 'rfrac_page_only', 'a' );
		$this->assertSame( array(), $this->pending() );
		update_post_meta( $page, 'rfrac_page_only', 'a' );
		$this->assertSame( array( 'post:' . $page ), $this->pending() );
	}

	public function test_meta_events_pass_type_key_and_object_as_context(): void {
		$seen = array();
		add_filter(
			Hooks::INVALIDATION_TAGS,
			static function ( $tags, $event, $context ) use ( &$seen ) {
				$seen[] = array( $event, $context );
				return $tags;
			},
			10,
			3
		);
		add_filter( Hooks::FLUSH_ON_META_UPDATE, '__return_true' );
		$id   = $this->make_post();
		$seen = array();
		add_post_meta( $id, 'field_x', 'a' );
		delete_post_meta( $id, 'field_x' );
		$context = array(
			'meta_type' => 'post',
			'meta_key'  => 'field_x',
			'object_id' => $id,
		);
		$this->assertContains( array( Invalidator::EVENT_META_ADDED, $context ), $seen );
		$this->assertContains( array( Invalidator::EVENT_META_DELETED, $context ), $seen );
	}
}
