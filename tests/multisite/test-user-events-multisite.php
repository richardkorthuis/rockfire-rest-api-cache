<?php
/**
 * Multisite tests for the user invalidation listeners.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Invalidation\User_Events;

/**
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\User_Events
 * @group multisite
 */
class Test_User_Events_Multisite extends Rfrac_Test_Case {

	/**
	 * Invalidator under test.
	 *
	 * @var Invalidator
	 */
	private Invalidator $inv;

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test.' );
		}
		parent::set_up();
		$this->inv = new Invalidator( new Rfrac_Spy_Store(), static fn( string $url ): ?string => null );
		( new User_Events( $this->inv ) )->register();
	}

	public function test_removing_a_user_from_another_blog_queues_on_that_blog(): void {
		$blog = self::factory()->blog->create();
		$user = self::factory()->user->create();
		add_user_to_blog( $blog, $user, 'subscriber' );
		$this->inv->commit();

		$this->assertSame( 1, get_current_blog_id() );
		remove_user_from_blog( $user, $blog );

		$pending = $this->inv->pending();
		$this->assertSame( array( 'user:' . $user ), $pending[ $blog ] );
		$this->assertArrayNotHasKey( 1, $pending );
	}

	public function test_removing_a_listed_author_from_another_blog_queues_the_list_there(): void {
		$blog = self::factory()->blog->create();
		$user = self::factory()->user->create();
		add_user_to_blog( $blog, $user, 'author' );
		switch_to_blog( $blog );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => $user ) );
		restore_current_blog();
		$this->inv->commit();

		remove_user_from_blog( $user, $blog );
		$this->assertSame( array( 'list:users', 'user:' . $user ), $this->inv->pending()[ $blog ] );
	}

	public function test_adding_a_user_to_another_blog_queues_on_that_blog(): void {
		$blog = self::factory()->blog->create();
		$user = self::factory()->user->create();
		$this->inv->commit();

		add_user_to_blog( $blog, $user, 'subscriber' );

		$this->assertSame( array( 'user:' . $user ), $this->inv->pending()[ $blog ] );
	}

	public function test_profile_update_queues_on_every_site_of_the_user(): void {
		$listed   = self::factory()->blog->create();
		$unlisted = self::factory()->blog->create();
		$other    = self::factory()->blog->create();
		$user     = self::factory()->user->create();
		add_user_to_blog( $listed, $user, 'author' );
		add_user_to_blog( $unlisted, $user, 'subscriber' );
		switch_to_blog( $listed );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => $user ) );
		restore_current_blog();
		$this->inv->commit();

		wp_update_user( array( 'ID' => $user, 'display_name' => 'Renamed everywhere' ) );

		$pending = $this->inv->pending();
		$this->assertSame( array( 'list:users', 'user:' . $user ), $pending[ $listed ] );
		$this->assertSame( array( 'user:' . $user ), $pending[ $unlisted ] );
		$this->assertSame( array( 'user:' . $user ), $pending[1] );
		$this->assertArrayNotHasKey( $other, $pending );
		$this->assertSame( 1, get_current_blog_id() );
	}

	public function test_deleting_a_user_queues_on_the_sites_still_holding_the_user(): void {
		$blog = self::factory()->blog->create();
		$user = self::factory()->user->create();
		add_user_to_blog( $blog, $user, 'author' );
		$this->inv->commit();

		( new User_Events( $this->inv ) )->on_deleted_user( $user );
		$pending = $this->inv->pending();
		$this->assertSame( array( 'list:users', 'user:' . $user ), $pending[ $blog ] );
		$this->assertSame( array( 'list:users', 'user:' . $user ), $pending[1] );
	}
}
