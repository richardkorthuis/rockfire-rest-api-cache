<?php
/**
 * Tests for the post and attachment invalidation listeners.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Invalidation\Post_Events;
use Rockfire\Rest_Api_Cache\Invalidation\Relevance;
use Rockfire\Rest_Api_Cache\Storage\Database_Store;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\Post_Events
 */
class Test_Post_Events extends Rfrac_Test_Case {

	/**
	 * Invalidator under test.
	 *
	 * @var Invalidator
	 */
	private Invalidator $inv;

	/**
	 * Spy store.
	 *
	 * @var Rfrac_Spy_Store
	 */
	private Rfrac_Spy_Store $spy;

	public function set_up() {
		parent::set_up();
		register_post_type( 'rfrac_book', array( 'public' => true, 'show_in_rest' => true, 'supports' => array( 'title', 'comments' ) ) );
		register_post_type( 'rfrac_secret', array( 'public' => true ) );
		register_post_type( 'rfrac_nocomments', array( 'public' => true, 'show_in_rest' => true, 'supports' => array( 'title' ) ) );
		$this->spy = new Rfrac_Spy_Store();
		$this->inv = new Invalidator( $this->spy, static fn( string $url ): ?string => null );
		( new Post_Events( $this->inv, new Relevance( new Config() ) ) )->register();
	}

	public function tear_down() {
		unregister_post_type( 'rfrac_book' );
		unregister_post_type( 'rfrac_secret' );
		unregister_post_type( 'rfrac_nocomments' );
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
	 * @param array<string,mixed> $args Post args.
	 *
	 * @return int
	 */
	private function make( array $args = array() ): int {
		$id = self::factory()->post->create( $args );
		$this->inv->commit();
		$this->spy->calls = array();
		return $id;
	}

	public function test_updating_a_published_post_queues_post_and_list(): void {
		$id = $this->make( array( 'post_status' => 'publish' ) );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'New title' ) );
		$this->assertSame( array( 'list:post', 'post:' . $id ), $this->pending() );
	}

	public function test_commit_after_update_is_a_single_update_query_with_database_store(): void {
		$id = $this->make( array( 'post_status' => 'publish' ) );
		$inv = new Invalidator( new Database_Store( new Repository(), 0 ), static fn( string $url ): ?string => null );
		remove_all_actions( 'wp_after_insert_post' );
		( new Post_Events( $inv, new Relevance( new Config() ) ) )->register();
		$cats = self::factory()->category->create_many( 2 );
		$tags = self::factory()->tag->create_many( 2 );
		$new  = self::factory()->post->create( array( 'post_status' => 'publish', 'post_category' => $cats, 'tags_input' => $tags ) );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'x' ) );
		$this->assertContains( 'post:' . $new, $inv->pending()[ get_current_blog_id() ] );

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$inv->commit();
		$recorder->stop();
		$this->assertCount( 1, $recorder->matching( '/^\s*UPDATE.*rfrac_entries/is' ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function non_public_statuses(): array {
		return array(
			'draft'   => array( 'draft' ),
			'pending' => array( 'pending' ),
			'private' => array( 'private' ),
			'future'  => array( 'future' ),
		);
	}

	/**
	 * @dataProvider non_public_statuses
	 */
	public function test_non_public_saves_queue_nothing_and_run_no_queries( string $status ): void {
		$args = array( 'post_status' => $status );
		if ( 'future' === $status ) {
			$args['post_date'] = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		}
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$id = self::factory()->post->create( $args );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'changed' ) );
		$recorder->stop();
		$this->assertSame( array(), $this->pending() );
		$this->assertSame( array(), $recorder->matching( '/rfrac_(entries|tags)/' ) );
	}

	public function test_auto_draft_revision_and_autosave_queue_nothing(): void {
		$events = new Post_Events( $this->inv, new Relevance( new Config() ) );
		$id     = $this->make( array( 'post_status' => 'publish' ) );

		$auto = self::factory()->post->create_and_get( array( 'post_status' => 'auto-draft' ) );
		$events->on_after_insert_post( $auto->ID, $auto, false, null );

		$revision_id = wp_save_post_revision( $id );
		if ( $revision_id ) {
			$revision = get_post( $revision_id );
			$events->on_after_insert_post( $revision->ID, $revision, true, null );
			$events->on_deleted_post( $revision->ID, $revision );
		}
		$autosave = self::factory()->post->create_and_get( array( 'post_status' => 'inherit', 'post_type' => 'revision', 'post_parent' => $id, 'post_name' => $id . '-autosave-v1' ) );
		$events->on_after_insert_post( $autosave->ID, $autosave, true, null );

		$this->assertSame( array(), $this->pending() );
	}

	public function test_publish_to_draft_also_queues_comment_list(): void {
		$id = $this->make( array( 'post_status' => 'publish' ) );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		$this->assertSame( array( 'list:attachment', 'list:comments', 'list:post', 'post:' . $id ), $this->pending() );
	}

	public function test_publish_to_draft_without_comment_support_skips_comment_list(): void {
		$id = $this->make( array( 'post_status' => 'publish', 'post_type' => 'rfrac_nocomments' ) );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		$this->assertSame( array( 'list:attachment', 'list:rfrac_nocomments', 'post:' . $id ), $this->pending() );
	}

	public function test_draft_to_publish_does_not_queue_comment_list(): void {
		$id = $this->make( array( 'post_status' => 'draft' ) );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		$this->assertSame( array( 'list:post', 'post:' . $id ), $this->pending() );
	}

	public function test_transition_with_same_or_non_viewable_statuses_does_nothing(): void {
		$events = new Post_Events( $this->inv, new Relevance( new Config() ) );
		$post   = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		$this->inv->commit();
		$events->on_transition_post_status( 'publish', 'publish', $post );
		$events->on_transition_post_status( 'draft', 'pending', $post );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_trashing_a_published_post_queues_and_deleting_the_trashed_post_does_not(): void {
		$id = $this->make( array( 'post_status' => 'publish' ) );
		wp_trash_post( $id );
		$this->assertSame( array( 'list:attachment', 'list:comments', 'list:post', 'post:' . $id ), $this->pending() );
		$this->inv->commit();

		wp_delete_post( $id, true );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_deleting_a_published_post_queues_post_list_and_comments(): void {
		$id = $this->make( array( 'post_status' => 'publish' ) );
		wp_delete_post( $id, true );
		$this->assertSame( array( 'list:comments', 'list:post', 'post:' . $id ), $this->pending() );
	}

	public function test_deleted_post_with_null_post_does_nothing(): void {
		( new Post_Events( $this->inv, new Relevance( new Config() ) ) )->on_deleted_post( 5, null );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_publishing_a_future_post_queues_post_and_list(): void {
		$id = $this->make(
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		wp_publish_post( $id );
		$this->assertContains( 'post:' . $id, $this->pending() );
		$this->assertContains( 'list:post', $this->pending() );
	}

	public function test_password_protected_published_post_update_queues_post(): void {
		$id = $this->make( array( 'post_status' => 'publish', 'post_password' => 'secret' ) );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'again' ) );
		$this->assertContains( 'post:' . $id, $this->pending() );
	}

	public function test_custom_post_type_in_rest_queues_its_list(): void {
		$id = $this->make( array( 'post_status' => 'publish', 'post_type' => 'rfrac_book' ) );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'again' ) );
		$this->assertSame( array( 'list:rfrac_book', 'post:' . $id ), $this->pending() );
	}

	public function test_custom_post_type_outside_rest_queues_nothing_unless_a_rule_references_it(): void {
		$id = $this->make( array( 'post_status' => 'publish', 'post_type' => 'rfrac_secret' ) );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'again' ) );
		$this->assertSame( array(), $this->pending() );

		add_filter(
			Hooks::ROUTE_RULES,
			static function ( $rules ) {
				$rules['/my/v1/things'] = array( 'status' => 'enabled', 'flush_post_types' => array( 'rfrac_secret' ) );
				return $rules;
			}
		);
		$this->new_rest_server();
		( new Post_Events( $this->inv, new Relevance( new Config() ) ) )->register();
		wp_update_post( array( 'ID' => $id, 'post_title' => 'once more' ) );
		$this->assertContains( 'list:rfrac_secret', $this->pending() );
	}

	public function test_attachment_upload_edit_and_delete_queue_post_and_attachment_list(): void {
		$id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		$this->assertSame( array( 'list:attachment', 'post:' . $id ), $this->pending() );
		$this->inv->commit();

		wp_update_post( array( 'ID' => $id, 'post_title' => 'renamed' ) );
		$this->assertSame( array( 'list:attachment', 'post:' . $id ), $this->pending() );
		$this->inv->commit();

		wp_delete_attachment( $id, true );
		$this->assertContains( 'post:' . $id, $this->pending() );
		$this->assertContains( 'list:attachment', $this->pending() );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function media_attach_actions(): array {
		return array(
			'attach' => array( 'attach' ),
			'detach' => array( 'detach' ),
		);
	}

	/**
	 * @dataProvider media_attach_actions
	 *
	 * @param string $action Action.
	 */
	public function test_attaching_and_detaching_media_queues_the_attachment_and_the_attachment_list( string $action ): void {
		$id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		$this->inv->commit();
		$this->spy->calls = array();

		do_action( 'wp_media_attach_action', $action, $id, 'attach' === $action ? 55 : 0 );

		$this->assertSame( array( 'list:attachment', 'post:' . $id ), $this->pending() );
	}

	public function test_attaching_media_passes_the_action_and_the_parent_as_context(): void {
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

		do_action( 'wp_media_attach_action', 'attach', 12, 34 );

		$this->assertSame(
			array(
				array(
					Invalidator::EVENT_ATTACHMENT_SAVED,
					array(
						'post_id'   => 12,
						'post_type' => 'attachment',
						'action'    => 'attach',
						'parent_id' => 34,
					),
				),
			),
			$seen
		);
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public function invalid_attachment_ids(): array {
		return array(
			'zero'         => array( 0 ),
			'negative'     => array( -3 ),
			'non-numeric'  => array( 'abc' ),
			'empty string' => array( '' ),
			'null'         => array( null ),
			'array'        => array( array( 5 ) ),
		);
	}

	/**
	 * @dataProvider invalid_attachment_ids
	 *
	 * @param mixed $attachment_id Attachment ID.
	 */
	public function test_attaching_media_with_an_invalid_id_queues_nothing_and_throws_nothing( mixed $attachment_id ): void {
		do_action( 'wp_media_attach_action', 'attach', $attachment_id, 34 );

		$this->assertSame( array(), $this->pending() );
	}

	public function test_a_failing_media_attach_is_swallowed(): void {
		$failures = 0;
		add_action(
			Hooks::INVALIDATION_FAILED,
			static function () use ( &$failures ): void {
				++$failures;
			},
			10,
			0
		);
		add_filter(
			Hooks::INVALIDATION_TAGS,
			static function (): never {
				throw new RuntimeException( 'listener failure' );
			}
		);

		$log     = (string) wp_tempnam( 'rfrac-error-log' );
		$old_log = (string) ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		try {
			do_action( 'wp_media_attach_action', 'attach', 12, 34 );
		} finally {
			ini_set( 'error_log', $old_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			wp_delete_file( $log );
		}

		$this->assertSame( 1, $failures );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_save_post_is_not_hooked(): void {
		$this->assertFalse( has_action( 'save_post', array( new Post_Events( $this->inv, new Relevance( new Config() ) ), 'on_after_insert_post' ) ) );
		$id = $this->make( array( 'post_status' => 'publish' ) );
		$this->spy->calls = array();
		do_action( 'save_post', $id, get_post( $id ), true );
		$this->assertSame( array(), $this->pending() );
	}

	/**
	 * Create a published post with one approved comment and one attachment.
	 *
	 * @param array<string,mixed> $args Post args.
	 *
	 * @return array{0:int,1:int,2:int} Post, comment and attachment IDs.
	 */
	private function make_with_children( array $args = array() ): array {
		$id         = self::factory()->post->create( array_merge( array( 'post_status' => 'publish' ), $args ) );
		$comment    = self::factory()->comment->create( array( 'comment_post_ID' => $id, 'comment_approved' => 1 ) );
		$held       = self::factory()->comment->create( array( 'comment_post_ID' => $id, 'comment_approved' => 0 ) );
		$attachment = self::factory()->attachment->create( array( 'post_parent' => $id, 'post_mime_type' => 'image/jpeg' ) );
		unset( $held );
		$this->inv->commit();
		return array( $id, $comment, $attachment );
	}

	public function test_adding_a_password_queues_comments_and_attachments_of_the_post(): void {
		list( $id, $comment, $attachment ) = $this->make_with_children();
		wp_update_post( array( 'ID' => $id, 'post_password' => 'secret' ) );
		$this->assertEqualsCanonicalizing(
			array( 'comment:' . $comment, 'list:attachment', 'list:comments', 'list:post', 'post:' . $attachment, 'post:' . $id ),
			$this->pending()
		);
	}

	public function test_removing_a_password_queues_comments_and_attachments_too(): void {
		list( $id, $comment ) = $this->make_with_children( array( 'post_password' => 'secret' ) );
		wp_update_post( array( 'ID' => $id, 'post_password' => '' ) );
		$this->assertContains( 'list:comments', $this->pending() );
		$this->assertContains( 'comment:' . $comment, $this->pending() );
		$this->assertContains( 'list:attachment', $this->pending() );
	}

	public function test_unpublishing_queues_each_comment_and_attachment_of_the_post(): void {
		list( $id, $comment, $attachment ) = $this->make_with_children();
		wp_update_post( array( 'ID' => $id, 'post_status' => 'private' ) );
		foreach ( array( 'comment:' . $comment, 'list:comments', 'post:' . $attachment, 'list:attachment' ) as $tag ) {
			$this->assertContains( $tag, $this->pending() );
		}
	}

	public function test_update_without_visibility_change_does_not_queue_children(): void {
		list( $id ) = $this->make_with_children();
		wp_update_post( array( 'ID' => $id, 'post_title' => 'New title' ) );
		$this->assertSame( array( 'list:post', 'post:' . $id ), $this->pending() );
	}

	public function test_publishing_and_unpublishing_queue_the_author(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$id     = $this->make( array( 'post_status' => 'draft', 'post_author' => $author ) );

		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		$this->assertContains( 'user:' . $author, $this->pending() );
		$this->assertContains( 'list:users', $this->pending() );
		$this->inv->commit();

		wp_update_post( array( 'ID' => $id, 'post_title' => 'Edit only' ) );
		$this->assertNotContains( 'list:users', $this->pending() );
		$this->inv->commit();

		wp_trash_post( $id );
		$this->assertContains( 'user:' . $author, $this->pending() );
		$this->assertContains( 'list:users', $this->pending() );
	}

	public function test_deleting_a_published_post_queues_its_author(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$id     = $this->make( array( 'post_status' => 'publish', 'post_author' => $author ) );
		wp_delete_post( $id, true );
		$this->assertContains( 'user:' . $author, $this->pending() );
		$this->assertContains( 'list:users', $this->pending() );
	}

	public function test_changing_the_author_of_a_published_post_queues_both_authors(): void {
		$old = self::factory()->user->create( array( 'role' => 'author' ) );
		$new = self::factory()->user->create( array( 'role' => 'author' ) );
		$id  = $this->make( array( 'post_status' => 'publish', 'post_author' => $old ) );
		wp_update_post( array( 'ID' => $id, 'post_author' => $new ) );
		foreach ( array( 'user:' . $old, 'user:' . $new, 'list:users', 'post:' . $id ) as $tag ) {
			$this->assertContains( $tag, $this->pending() );
		}
	}

	public function test_events_pass_the_post_as_context(): void {
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
		$id = $this->make( array( 'post_status' => 'publish', 'post_type' => 'rfrac_book' ) );
		$seen = array();
		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		$this->assertContains(
			array(
				Invalidator::EVENT_POST_STATUS_CHANGED,
				array(
					'post_id'    => $id,
					'post_type'  => 'rfrac_book',
					'old_status' => 'publish',
					'new_status' => 'draft',
				),
			),
			$seen
		);
		$this->assertContains( array( Invalidator::EVENT_POST_SAVED, array( 'post_id' => $id, 'post_type' => 'rfrac_book' ) ), $seen );
	}
}
