<?php
/**
 * Maps user lifecycle events to invalidation tags.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Invalidation;

use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;

defined( 'ABSPATH' ) || exit;

/**
 * Queues `user:{id}` and, for publicly listed users, `list:users` for user changes.
 *
 * Anonymous `/wp/v2/users` only lists users with published posts in a REST-visible post type, so the list tag is
 * queued for creations, updates and membership changes only when the user is listed on that site. That keeps open
 * registration and subscriber profile saves from evicting the user lists. Users are network-wide: on multisite an
 * update is queued on every site the user belongs to.
 *
 * @since 1.0.0
 */
final class User_Events {

	use Guarded_Trait;
	use Hook_Subscriber_Trait;

	/**
	 * Invalidator.
	 *
	 * @var Invalidator
	 */
	private Invalidator $invalidator;

	/**
	 * Constructor.
	 *
	 * @param Invalidator $invalidator Invalidator.
	 */
	public function __construct( Invalidator $invalidator ) {
		$this->invalidator = $invalidator;
	}

	/**
	 * The hooks: `profile_update` (10, 1), `user_register` (10, 1), `deleted_user` (10, 2),
	 * `add_user_to_blog` (10, 3) and `remove_user_from_blog` (10, 2).
	 *
	 * @return array<int,array<int,mixed>>
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		return array(
			array( 'profile_update', 'on_profile_update', 10, 1 ),
			array( 'user_register', 'on_user_register', 10, 1 ),
			array( 'deleted_user', 'on_deleted_user', 10, 2 ),
			array( 'add_user_to_blog', 'on_add_user_to_blog', 10, 3 ),
			array( 'remove_user_from_blog', 'on_remove_user_from_blog', 10, 2 ),
		);
	}

	/**
	 * `profile_update`: a user was updated.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return void
	 */
	public function on_profile_update( $user_id ): void {
		try {
			$user_id = (int) $user_id;
			foreach ( $this->blogs_of( $user_id ) as $blog_id ) {
				$this->queue_user( $user_id, $blog_id, Invalidator::EVENT_USER_UPDATED );
			}
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_USER_UPDATED );
		}
	}

	/**
	 * `user_register`: a user was created.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return void
	 */
	public function on_user_register( $user_id = 0 ): void {
		try {
			$this->queue_user( (int) $user_id, (int) get_current_blog_id(), Invalidator::EVENT_USER_REGISTERED );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_USER_REGISTERED );
		}
	}

	/**
	 * `deleted_user`: a user was deleted; with a reassignment the content of the user moved to another one.
	 *
	 * @param int      $user_id  Deleted user ID.
	 * @param int|null $reassign ID of the user the content moved to, or null.
	 *
	 * @return void
	 */
	public function on_deleted_user( $user_id, $reassign = null ): void {
		try {
			$user_id  = (int) $user_id;
			$reassign = null === $reassign ? 0 : (int) $reassign;
			$raw      = array( Tags::user( $user_id ), Tags::list_for( 'users' ) );
			if ( $reassign > 0 ) {
				$raw[] = Tags::user( $reassign );
				foreach ( array_keys( get_post_types( array( 'show_in_rest' => true ) ) ) as $post_type ) {
					if ( 'revision' !== $post_type ) {
						$raw[] = Tags::list_for( (string) $post_type );
					}
				}
			}
			$context = array(
				'user_id'  => $user_id,
				'reassign' => $reassign,
			);
			foreach ( $this->blogs_of( $user_id ) as $blog_id ) {
				$this->invalidator->queue( $this->tags( ...$raw ), Invalidator::EVENT_USER_DELETED, $context, $blog_id );
			}
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_USER_DELETED );
		}
	}

	/**
	 * `add_user_to_blog`: a user joined a site.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role.
	 * @param int    $blog_id Site ID.
	 *
	 * @return void
	 */
	public function on_add_user_to_blog( $user_id, $role = '', $blog_id = 0 ): void {
		$this->queue_membership( (int) $user_id, (int) $blog_id, Invalidator::EVENT_USER_ADDED_TO_BLOG );
	}

	/**
	 * `remove_user_from_blog`: a user left a site.
	 *
	 * @param int $user_id User ID.
	 * @param int $blog_id Site ID.
	 *
	 * @return void
	 */
	public function on_remove_user_from_blog( $user_id, $blog_id = 0 ): void {
		$this->queue_membership( (int) $user_id, (int) $blog_id, Invalidator::EVENT_USER_REMOVED_FROM_BLOG );
	}

	/**
	 * Queue the user tags on the site whose membership changed.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $blog_id Site ID.
	 * @param string $event   Event name.
	 *
	 * @return void
	 */
	private function queue_membership( int $user_id, int $blog_id, string $event ): void {
		try {
			$this->queue_user( $user_id, $blog_id > 0 ? $blog_id : (int) get_current_blog_id(), $event );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, $event );
		}
	}

	/**
	 * Queue `user:{id}` on a site, plus `list:users` when the user is publicly listed there.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $blog_id Site ID.
	 * @param string $event   Event name.
	 *
	 * @return void
	 */
	private function queue_user( int $user_id, int $blog_id, string $event ): void {
		$raw = array( Tags::user( $user_id ) );
		if ( $user_id > 0 && $this->publicly_listed( $user_id, $blog_id ) ) {
			$raw[] = Tags::list_for( 'users' );
		}
		$this->invalidator->queue( $this->tags( ...$raw ), $event, array( 'user_id' => $user_id ), $blog_id );
	}

	/**
	 * Whether anonymous `/wp/v2/users` lists the user on a site: the user has a published post in a REST-visible post type.
	 *
	 * @param int $user_id User ID.
	 * @param int $blog_id Site ID.
	 *
	 * @return bool
	 */
	private function publicly_listed( int $user_id, int $blog_id ): bool {
		$post_types = array_map( 'strval', array_keys( get_post_types( array( 'show_in_rest' => true ) ) ) );
		if ( array() === $post_types ) {
			return false;
		}
		$switched = is_multisite() && (int) get_current_blog_id() !== $blog_id;
		if ( $switched ) {
			switch_to_blog( $blog_id );
		}
		try {
			return (int) count_user_posts( $user_id, $post_types, true ) > 0;
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * The sites whose caches can show a user: the current site plus, on multisite, every site the user belongs to.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return int[]
	 */
	private function blogs_of( int $user_id ): array {
		$blogs = array( (int) get_current_blog_id() );
		if ( is_multisite() && $user_id > 0 ) {
			foreach ( array_keys( get_blogs_of_user( $user_id ) ) as $blog_id ) {
				$blogs[] = (int) $blog_id;
			}
		}
		return array_values( array_unique( $blogs ) );
	}
}
