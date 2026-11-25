<?php
/**
 * Tests for the comment invalidation listeners.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Comment_Events;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;

/**
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\Comment_Events
 */
class Test_Comment_Events extends Rfrac_Test_Case {

	/**
	 * Invalidator under test.
	 *
	 * @var Invalidator
	 */
	private Invalidator $inv;

	/**
	 * Post the comments belong to.
	 *
	 * @var int
	 */
	private int $post_id;

	public function set_up() {
		parent::set_up();
		$this->inv     = new Invalidator( new Rfrac_Spy_Store(), static fn( string $url ): ?string => null );
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		( new Comment_Events( $this->inv ) )->register();
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
	 * Create a comment, then forget what its creation queued.
	 *
	 * @param string|int $approved Approval state.
	 * @param string     $type     Comment type.
	 *
	 * @return int
	 */
	private function make( $approved = 1, string $type = 'comment' ): int {
		$id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_approved' => $approved,
				'comment_type'     => $type,
			)
		);
		$this->inv->commit();
		return $id;
	}

	public function test_inserting_an_approved_comment_queues_comment_and_list(): void {
		$id = self::factory()->comment->create( array( 'comment_post_ID' => $this->post_id, 'comment_approved' => 1 ) );
		$this->assertSame( array( 'comment:' . $id, 'list:comments' ), $this->pending() );
	}

	public function test_inserting_held_spam_or_trashed_comments_queues_nothing(): void {
		foreach ( array( 0, 'spam', 'trash' ) as $state ) {
			self::factory()->comment->create( array( 'comment_post_ID' => $this->post_id, 'comment_approved' => $state ) );
		}
		$this->assertSame( array(), $this->pending() );
	}

	public function test_approving_a_held_comment_queues(): void {
		$id = $this->make( 0 );
		wp_set_comment_status( $id, 'approve' );
		$this->assertSame( array( 'comment:' . $id, 'list:comments' ), $this->pending() );
	}

	public function test_unapproving_marking_spam_trashing_and_untrashing_queue(): void {
		$id = $this->make( 1 );
		wp_set_comment_status( $id, 'hold' );
		$this->assertSame( array( 'comment:' . $id, 'list:comments' ), $this->pending() );
		$this->inv->commit();

		wp_set_comment_status( $id, 'approve' );
		$this->inv->commit();
		wp_spam_comment( $id );
		$this->assertSame( array( 'comment:' . $id, 'list:comments' ), $this->pending() );
		$this->inv->commit();

		wp_unspam_comment( $id );
		$this->assertSame( array( 'comment:' . $id, 'list:comments' ), $this->pending() );
		$this->inv->commit();

		wp_trash_comment( $id );
		$this->assertSame( array( 'comment:' . $id, 'list:comments' ), $this->pending() );
		$this->inv->commit();

		wp_untrash_comment( $id );
		$this->assertSame( array( 'comment:' . $id, 'list:comments' ), $this->pending() );
	}

	public function test_editing_an_approved_comment_queues(): void {
		$id = $this->make( 1 );
		wp_update_comment( array( 'comment_ID' => $id, 'comment_content' => 'Edited' ) );
		$this->assertSame( array( 'comment:' . $id, 'list:comments' ), $this->pending() );
	}

	public function test_editing_a_held_comment_queues_nothing(): void {
		$id = $this->make( 0 );
		wp_update_comment( array( 'comment_ID' => $id, 'comment_content' => 'Edited' ) );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_deleting_a_spam_comment_queues_nothing(): void {
		$id = $this->make( 'spam' );
		wp_delete_comment( $id, true );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_deleting_an_approved_comment_queues(): void {
		$id = $this->make( 1 );
		wp_delete_comment( $id, true );
		$this->assertSame( array( 'comment:' . $id, 'list:comments' ), $this->pending() );
	}

	public function test_note_comments_are_ignored_until_a_filter_allows_them(): void {
		$id = $this->make( 1, 'note' );
		wp_update_comment( array( 'comment_ID' => $id, 'comment_content' => 'Edited' ) );
		wp_set_comment_status( $id, 'hold' );
		$this->assertSame( array(), $this->pending() );

		add_filter(
			Hooks::INVALIDATE_COMMENT_TYPE,
			static fn( $relevant, $type ) => 'note' === $type ? true : $relevant,
			10,
			2
		);
		wp_set_comment_status( $id, 'approve' );
		$this->assertSame( array( 'comment:' . $id, 'list:comments' ), $this->pending() );
	}

	public function test_pingbacks_trackbacks_and_empty_types_are_relevant(): void {
		foreach ( array( 'pingback', 'trackback', '' ) as $type ) {
			$id = self::factory()->comment->create( array( 'comment_post_ID' => $this->post_id, 'comment_approved' => 1, 'comment_type' => $type ) );
			$this->assertContains( 'comment:' . $id, $this->pending(), "type '{$type}'" );
		}
	}

	public function test_status_hooks_other_than_transition_are_not_used(): void {
		$events = new Comment_Events( $this->inv );
		$events->register();
		foreach ( array( 'deleted_comment', 'trashed_comment', 'spammed_comment' ) as $hook ) {
			$this->assertFalse( has_action( $hook, array( $events, 'on_transition_comment_status' ) ) );
		}
		$id = $this->make( 1 );
		$this->assertSame( array(), $this->pending() );
		do_action( 'trashed_comment', $id, get_comment( $id ) );
		$this->assertSame( array(), $this->pending() );
	}
}
