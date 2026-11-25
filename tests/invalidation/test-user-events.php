<?php
/**
 * Tests for the user invalidation listeners.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Invalidation\User_Events;

/**
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\User_Events
 */
class Test_User_Events extends Rfrac_Test_Case {

	/**
	 * Invalidator under test.
	 *
	 * @var Invalidator
	 */
	private Invalidator $inv;

	public function set_up() {
		parent::set_up();
		$this->inv = new Invalidator( new Rfrac_Spy_Store(), static fn( string $url ): ?string => null );
		( new User_Events( $this->inv ) )->register();
	}

	/**
	 * Pending tags of the current site.
	 *
	 * @return string[]
	 */
	private function pending(): array {
		return $this->inv->pending()[ get_current_blog_id() ] ?? array();
	}

	public function test_profile_update_of_an_unlisted_user_queues_only_the_user(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->inv->commit();
		wp_update_user( array( 'ID' => $user, 'display_name' => 'Changed' ) );
		$this->assertSame( array( 'user:' . $user ), $this->pending() );
	}

	public function test_profile_update_of_a_user_with_published_posts_queues_the_list_too(): void {
		$user = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => $user ) );
		$this->inv->commit();
		wp_update_user( array( 'ID' => $user, 'display_name' => 'Changed' ) );
		$this->assertSame( array( 'list:users', 'user:' . $user ), $this->pending() );
	}

	public function test_drafts_or_unexposed_post_types_do_not_list_a_user(): void {
		register_post_type( 'rfrac_hidden_type', array( 'public' => true ) );
		$user = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create( array( 'post_status' => 'draft', 'post_author' => $user ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => $user, 'post_type' => 'rfrac_hidden_type' ) );
		$this->inv->commit();
		wp_update_user( array( 'ID' => $user, 'display_name' => 'Changed' ) );
		$this->assertSame( array( 'user:' . $user ), $this->pending() );
		unregister_post_type( 'rfrac_hidden_type' );
	}

	public function test_user_register_queues_only_the_new_user(): void {
		$user = wp_insert_user( array( 'user_login' => 'rfrac_new_user', 'user_pass' => 'secret-pass-123' ) );
		$this->assertSame( array( 'user:' . $user ), $this->pending() );
	}

	public function test_deleting_without_reassign_queues_user_and_list(): void {
		$user = self::factory()->user->create();
		$this->inv->commit();
		wp_delete_user( $user );
		$this->assertSame( array( 'list:users', 'user:' . $user ), $this->pending() );
	}

	public function test_deleting_with_reassign_queues_both_users_and_post_type_lists(): void {
		$user     = self::factory()->user->create();
		$reassign = self::factory()->user->create();
		$this->inv->commit();
		wp_delete_user( $user, $reassign );
		$pending = $this->pending();
		foreach ( array( 'user:' . $user, 'user:' . $reassign, 'list:users', 'list:post', 'list:page', 'list:attachment' ) as $tag ) {
			$this->assertContains( $tag, $pending );
		}
		$this->assertNotContains( 'list:revision', $pending );
	}

	public function test_user_events_pass_the_user_as_context(): void {
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
		$user = self::factory()->user->create();
		$seen = array();
		wp_update_user( array( 'ID' => $user, 'display_name' => 'Ctx' ) );
		$this->assertContains( array( Invalidator::EVENT_USER_UPDATED, array( 'user_id' => $user ) ), $seen );
	}
}
