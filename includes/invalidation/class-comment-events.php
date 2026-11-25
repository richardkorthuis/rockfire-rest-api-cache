<?php
/**
 * Maps comment events to invalidation tags (approved comments only).
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Invalidation;

use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Queues `comment:{id}` and `list:comments` only when an approved comment is created, edited or changes status.
 *
 * Spam and held comments never appear in public responses, so an anonymous visitor cannot trigger a flush by
 * posting them (audit finding L2). There are no `deleted_comment`, `trashed_comment` or `spammed_comment` listeners:
 * `transition_comment_status` covers every status change including deletion.
 *
 * @since 1.0.0
 */
final class Comment_Events {

	use Guarded_Trait;
	use Hook_Subscriber_Trait;

	/**
	 * Comment types that invalidate caches by default.
	 *
	 * @var string[]
	 */
	private const DEFAULT_TYPES = array( 'comment', 'pingback', 'trackback', '' );

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
	 * The hooks: `wp_insert_comment` (10, 2), `edit_comment` (10, 2) and `transition_comment_status` (10, 3).
	 *
	 * @return array<int,array<int,mixed>>
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		return array(
			array( 'wp_insert_comment', 'on_insert_comment', 10, 2 ),
			array( 'edit_comment', 'on_edit_comment', 10, 2 ),
			array( 'transition_comment_status', 'on_transition_comment_status', 10, 3 ),
		);
	}

	/**
	 * `wp_insert_comment`: a comment was stored.
	 *
	 * @param int         $comment_id Comment ID.
	 * @param \WP_Comment $comment    Comment.
	 *
	 * @return void
	 */
	public function on_insert_comment( $comment_id, $comment ): void {
		try {
			if ( '1' !== (string) $comment->comment_approved ) {
				return;
			}
			$this->queue( (int) $comment_id, (string) $comment->comment_type, Invalidator::EVENT_COMMENT_INSERTED, (int) $comment->comment_post_ID );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_COMMENT_INSERTED );
		}
	}

	/**
	 * `edit_comment`: a comment was edited.
	 *
	 * @param int                 $comment_id Comment ID.
	 * @param array<string,mixed> $data       Comment data (unused: the stored comment is re-read).
	 *
	 * @return void
	 */
	public function on_edit_comment( $comment_id, $data = array() ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		try {
			$comment = get_comment( (int) $comment_id );
			if ( ! $comment instanceof \WP_Comment || '1' !== (string) $comment->comment_approved ) {
				return;
			}
			$this->queue( (int) $comment->comment_ID, (string) $comment->comment_type, Invalidator::EVENT_COMMENT_EDITED, (int) $comment->comment_post_ID );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_COMMENT_EDITED );
		}
	}

	/**
	 * `transition_comment_status`: a comment changed status (approve, unapprove, spam, trash, untrash, delete).
	 *
	 * Fires only when the old or the new status is `approved`.
	 *
	 * @param string      $new_status New status.
	 * @param string      $old_status Old status.
	 * @param \WP_Comment $comment    Comment.
	 *
	 * @return void
	 */
	public function on_transition_comment_status( $new_status, $old_status, $comment ): void {
		try {
			if ( $new_status === $old_status || ( 'approved' !== $new_status && 'approved' !== $old_status ) ) {
				return;
			}
			$this->queue( (int) $comment->comment_ID, (string) $comment->comment_type, Invalidator::EVENT_COMMENT_STATUS_CHANGED, (int) $comment->comment_post_ID );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_COMMENT_STATUS_CHANGED );
		}
	}

	/**
	 * Queue the comment tags when the comment type is relevant.
	 *
	 * @param int    $comment_id   Comment ID.
	 * @param string $comment_type Comment type.
	 * @param string $event        Event name.
	 * @param int    $post_id      ID of the post the comment belongs to.
	 *
	 * @return void
	 */
	private function queue( int $comment_id, string $comment_type, string $event, int $post_id ): void {
		/**
		 * Filters whether changes to a comment type invalidate caches.
		 *
		 * @see Hooks::INVALIDATE_COMMENT_TYPE
		 */
		$relevant = (bool) apply_filters( Hooks::INVALIDATE_COMMENT_TYPE, in_array( $comment_type, self::DEFAULT_TYPES, true ), $comment_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		if ( ! $relevant ) {
			return;
		}
		$this->invalidator->queue(
			$this->tags( Tags::comment( $comment_id ), Tags::list_for( 'comments' ) ),
			$event,
			array(
				'comment_id'   => $comment_id,
				'comment_type' => $comment_type,
				'post_id'      => $post_id,
			)
		);
	}
}
