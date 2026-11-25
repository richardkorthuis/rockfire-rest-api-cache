<?php
/**
 * Regression test for audit finding L2: anonymous visitors must not be able to flush caches by posting comments.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Invalidation\Comment_Events;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Storage\Database_Store;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\Comment_Events
 */
class Test_L2_Anonymous_Comment_Flush extends Rfrac_Test_Case {

	/**
	 * Invalidator under test (real store).
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
		$this->inv     = new Invalidator( new Database_Store( new Repository(), 0 ), static fn( string $url ): ?string => null );
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		( new Comment_Events( $this->inv ) )->register();
		wp_set_current_user( 0 );
	}

	/**
	 * Commit and assert that nothing was written and an entry tagged `list:comments` is still active.
	 *
	 * @param int $entry Entry ID.
	 *
	 * @return void
	 */
	private function assert_nothing_flushed( int $entry ): void {
		$this->assertSame( array(), $this->inv->pending() );
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$this->inv->commit();
		$recorder->stop();
		$this->assertSame( array(), $recorder->matching( '/^\s*UPDATE.*rfrac_entries/is' ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $entry ) );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public function unapproved_states(): array {
		return array(
			'spam' => array( 'spam' ),
			'held' => array( 0 ),
		);
	}

	/**
	 * @dataProvider unapproved_states
	 *
	 * @param mixed $state Value of the `pre_comment_approved` filter.
	 */
	public function test_anonymous_comment_that_is_not_approved_flushes_nothing( $state ): void {
		$entry = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'list:comments' ) );
		add_filter( 'pre_comment_approved', static fn() => $state );

		$id = wp_new_comment(
			array(
				'comment_post_ID'      => $this->post_id,
				'comment_author'       => 'Anon',
				'comment_author_email' => 'anon@example.org',
				'comment_author_url'   => '',
				'comment_content'      => 'Hello',
				'comment_type'         => 'comment',
			)
		);
		$this->assertIsInt( $id );
		$this->assert_nothing_flushed( $entry );
	}

	public function test_anonymous_rest_comment_in_moderation_flushes_nothing(): void {
		$entry = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'list:comments' ) );
		update_option( 'comment_moderation', '1' );
		update_option( 'comment_previously_approved', '0' );
		add_filter( 'rest_allow_anonymous_comments', '__return_true' );
		$this->new_rest_server();

		$request = new WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_body_params(
			array(
				'post'         => $this->post_id,
				'author_name'  => 'Anon',
				'author_email' => 'anon@example.org',
				'content'      => 'Hello from REST',
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'hold', $response->get_data()['status'] );
		$this->assert_nothing_flushed( $entry );
	}

	public function test_approving_the_held_comment_flushes_the_comment_list(): void {
		$entry = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'list:comments' ) );
		$id    = self::factory()->comment->create( array( 'comment_post_ID' => $this->post_id, 'comment_approved' => 0 ) );
		$this->assertSame( array(), $this->inv->pending() );

		wp_set_comment_status( $id, 'approve' );
		$this->assertSame( 1, $this->inv->commit() );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $entry ) );
	}

	/**
	 * Submit an anonymous comment through wp_new_comment().
	 *
	 * @return int Comment ID.
	 */
	private function anonymous_comment(): int {
		$id = wp_new_comment(
			array(
				'comment_post_ID'      => $this->post_id,
				'comment_author'       => 'Anon',
				'comment_author_email' => 'anon@example.org',
				'comment_author_url'   => '',
				'comment_content'      => 'Hello ' . wp_generate_password( 8, false ),
				'comment_type'         => 'comment',
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	public function test_positive_control_an_approved_anonymous_comment_flushes_through_the_same_path(): void {
		$entry = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'list:comments' ) );
		add_filter( 'pre_comment_approved', static fn() => 1 );

		$id = $this->anonymous_comment();
		$this->assertSame( array( get_current_blog_id() => array( 'comment:' . $id, 'list:comments' ) ), $this->inv->pending() );
		$this->assertSame( 1, $this->inv->commit() );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $entry ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function moderation_actions(): array {
		return array(
			'spam'  => array( 'wp_spam_comment' ),
			'trash' => array( 'wp_trash_comment' ),
		);
	}

	/**
	 * @dataProvider moderation_actions
	 *
	 * @param string $action Moderation function applied to the held comment.
	 */
	public function test_moderating_a_held_anonymous_comment_away_flushes_nothing( string $action ): void {
		$entry = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'list:comments' ) );
		add_filter( 'pre_comment_approved', static fn() => 0 );
		$id = $this->anonymous_comment();
		$this->assertSame( array(), $this->inv->pending() );

		$this->assertNotFalse( $action( $id ) );
		$this->assert_nothing_flushed( $entry );
	}
}
