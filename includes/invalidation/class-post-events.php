<?php
/**
 * Maps post and attachment lifecycle events to invalidation tags.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Invalidation;

use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;

defined( 'ABSPATH' ) || exit;

/**
 * Queues `post:{ID}` and `list:{type}` for public post changes, plus the tags of what the change hides or reveals:
 * the author (`user:{id}`, `list:users`), and, when a post stops being public or its password changes, its comments
 * (`list:comments`) and attachments (`list:attachment`).
 *
 * Not hooked to `save_post`: `wp_after_insert_post` runs after terms and meta are saved, so one save yields one
 * queue call. Attachments never reach `wp_after_insert_post` and have their own hooks.
 *
 * @since 1.0.0
 */
final class Post_Events {

	use Guarded_Trait;
	use Hook_Subscriber_Trait;

	/**
	 * Largest number of comments or attachments of a post that get their own tag; beyond that only the list tag is queued.
	 *
	 * @var int
	 */
	private const MAX_CHILD_TAGS = 200;

	/**
	 * Invalidator.
	 *
	 * @var Invalidator
	 */
	private Invalidator $invalidator;

	/**
	 * Relevance rules.
	 *
	 * @var Relevance
	 */
	private Relevance $relevance;

	/**
	 * Constructor.
	 *
	 * @param Invalidator $invalidator Invalidator.
	 * @param Relevance   $relevance   Relevance rules.
	 */
	public function __construct( Invalidator $invalidator, Relevance $relevance ) {
		$this->invalidator = $invalidator;
		$this->relevance   = $relevance;
	}

	/**
	 * The hooks: `wp_after_insert_post` (10, 4), `transition_post_status` (10, 3), `deleted_post` (10, 2),
	 * `add_attachment` (10, 1), `edit_attachment` (10, 1) and `wp_media_attach_action` (10, 3).
	 *
	 * @return array<int,array<int,mixed>>
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		return array(
			array( 'wp_after_insert_post', 'on_after_insert_post', 10, 4 ),
			array( 'transition_post_status', 'on_transition_post_status', 10, 3 ),
			array( 'deleted_post', 'on_deleted_post', 10, 2 ),
			array( 'add_attachment', 'on_attachment', 10, 1 ),
			array( 'edit_attachment', 'on_attachment', 10, 1 ),
			array( 'wp_media_attach_action', 'on_media_attach', 10, 3 ),
		);
	}

	/**
	 * `wp_after_insert_post`: a post was saved.
	 *
	 * A password change also queues the post's comments and attachments; an author change queues both authors.
	 *
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post        Post after the save.
	 * @param bool          $update      Whether this updated an existing post.
	 * @param \WP_Post|null $post_before Post before the save (null for new posts).
	 *
	 * @return void
	 */
	public function on_after_insert_post( $post_id, $post, $update = false, $post_before = null ): void {
		try {
			if ( 'auto-draft' === $post->post_status || 'revision' === $post->post_type ) {
				return;
			}
			if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || ! $this->relevance->post_type( $post->post_type ) ) {
				return;
			}
			$before = $post_before instanceof \WP_Post ? $post_before : null;
			if ( ! $this->relevance->post_is_public( $post ) && ( null === $before || ! $this->relevance->post_is_public( $before ) ) ) {
				return;
			}
			$raw = array( Tags::post( (int) $post->ID ), Tags::list_for( $post->post_type ) );
			if ( null !== $before && (string) $before->post_password !== (string) $post->post_password ) {
				$raw = array_merge( $raw, $this->dependent_tags( $post ) );
			}
			if ( null !== $before && (int) $before->post_author !== (int) $post->post_author ) {
				$raw = array_merge( $raw, $this->author_tags( (int) $before->post_author ), $this->author_tags( (int) $post->post_author ) );
			}
			$this->invalidator->queue( $this->tags( ...$raw ), Invalidator::EVENT_POST_SAVED, $this->context( $post ) );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_POST_SAVED );
		}
	}

	/**
	 * `transition_post_status`: the status of a post changed.
	 *
	 * When the post becomes or stops being public its author's visibility may change; when it stops being public
	 * its comments and attachments disappear from public responses.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 *
	 * @return void
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ): void {
		try {
			if ( $new_status === $old_status ) {
				return;
			}
			$new_viewable = is_post_status_viewable( (string) $new_status );
			$old_viewable = is_post_status_viewable( (string) $old_status );
			if ( ! $new_viewable && ! $old_viewable ) {
				return;
			}
			if ( ! $this->relevance->post_type( $post->post_type ) ) {
				return;
			}
			$raw = array( Tags::post( (int) $post->ID ), Tags::list_for( $post->post_type ) );
			if ( $old_viewable !== $new_viewable ) {
				$raw = array_merge( $raw, $this->author_tags( (int) $post->post_author ) );
			}
			if ( $old_viewable && ! $new_viewable ) {
				$raw = array_merge( $raw, $this->dependent_tags( $post ) );
			}
			$context = $this->context( $post ) + array(
				'old_status' => (string) $old_status,
				'new_status' => (string) $new_status,
			);
			$this->invalidator->queue( $this->tags( ...$raw ), Invalidator::EVENT_POST_STATUS_CHANGED, $context );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_POST_STATUS_CHANGED );
		}
	}

	/**
	 * `deleted_post`: a post was deleted for good.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post as it was before deletion.
	 *
	 * @return void
	 */
	public function on_deleted_post( $post_id, $post = null ): void {
		try {
			if ( ! $post instanceof \WP_Post || 'revision' === $post->post_type ) {
				return;
			}
			if ( ! $this->relevance->post_type( $post->post_type ) || ! $this->relevance->post_is_public( $post ) ) {
				return;
			}
			$raw = array( Tags::post( (int) $post->ID ), Tags::list_for( $post->post_type ) );
			if ( 'attachment' !== $post->post_type ) {
				$raw = array_merge( $raw, $this->author_tags( (int) $post->post_author ) );
			}
			if ( post_type_supports( $post->post_type, 'comments' ) ) {
				$raw[] = Tags::list_for( 'comments' );
			}
			$this->invalidator->queue( $this->tags( ...$raw ), Invalidator::EVENT_POST_DELETED, $this->context( $post ) );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_POST_DELETED );
		}
	}

	/**
	 * `add_attachment` and `edit_attachment`: a media item was uploaded or edited.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return void
	 */
	public function on_attachment( $attachment_id ): void {
		try {
			$this->invalidator->queue(
				$this->tags( Tags::post( (int) $attachment_id ), Tags::list_for( 'attachment' ) ),
				Invalidator::EVENT_ATTACHMENT_SAVED,
				array(
					'post_id'   => (int) $attachment_id,
					'post_type' => 'attachment',
				)
			);
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_ATTACHMENT_SAVED );
		}
	}

	/**
	 * `wp_media_attach_action`: a media item was attached to a post or detached from it.
	 *
	 * Attaching changes `post_parent` of the media item with SQL, without going through `edit_attachment`. The
	 * arguments come from a core hook that any code can fire, so they are checked rather than trusted.
	 *
	 * @param mixed $action        `attach` or `detach`.
	 * @param mixed $attachment_id Attachment ID.
	 * @param mixed $parent_id     Post ID the item is attached to (0 when detaching).
	 *
	 * @return void
	 */
	public function on_media_attach( $action, $attachment_id, $parent_id = 0 ): void {
		try {
			if ( ! is_numeric( $attachment_id ) || (int) $attachment_id < 1 ) {
				return;
			}
			$this->invalidator->queue(
				$this->tags( Tags::post( (int) $attachment_id ), Tags::list_for( 'attachment' ) ),
				Invalidator::EVENT_ATTACHMENT_SAVED,
				array(
					'post_id'   => (int) $attachment_id,
					'post_type' => 'attachment',
					'action'    => is_string( $action ) ? $action : '',
					'parent_id' => is_numeric( $parent_id ) ? (int) $parent_id : 0,
				)
			);
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_ATTACHMENT_SAVED );
		}
	}

	/**
	 * The subject context of a post event.
	 *
	 * @param \WP_Post $post Post.
	 *
	 * @return array<string,mixed>
	 */
	private function context( \WP_Post $post ): array {
		return array(
			'post_id'   => (int) $post->ID,
			'post_type' => (string) $post->post_type,
		);
	}

	/**
	 * Tags of an author whose public visibility may have changed: anonymous `/wp/v2/users` lists only users with
	 * published posts.
	 *
	 * @param int $author_id Author ID.
	 *
	 * @return array<int,string|null>
	 */
	private function author_tags( int $author_id ): array {
		if ( $author_id < 1 ) {
			return array();
		}
		return array( Tags::user( $author_id ), Tags::list_for( 'users' ) );
	}

	/**
	 * Tags of the comments and attachments of a post whose visibility changed (status or password).
	 *
	 * Each approved comment and each attachment gets its own tag while there are at most 200 of them.
	 *
	 * @param \WP_Post $post Post.
	 *
	 * @return array<int,string|null>
	 */
	private function dependent_tags( \WP_Post $post ): array {
		$raw = array();
		if ( post_type_supports( $post->post_type, 'comments' ) ) {
			$raw[]    = Tags::list_for( 'comments' );
			$comments = get_comments(
				array(
					'post_id'                   => (int) $post->ID,
					'status'                    => 'approve',
					'fields'                    => 'ids',
					'number'                    => self::MAX_CHILD_TAGS + 1,
					'no_found_rows'             => true,
					'update_comment_meta_cache' => false,
				)
			);
			if ( is_array( $comments ) && count( $comments ) <= self::MAX_CHILD_TAGS ) {
				foreach ( $comments as $comment_id ) {
					$raw[] = Tags::comment( (int) $comment_id );
				}
			}
		}
		if ( 'attachment' !== $post->post_type && $this->relevance->post_type( 'attachment' ) ) {
			$raw[]       = Tags::list_for( 'attachment' );
			$attachments = get_posts(
				array(
					'post_type'   => 'attachment',
					'post_parent' => (int) $post->ID,
					'post_status' => 'any',
					'fields'      => 'ids',
					'numberposts' => self::MAX_CHILD_TAGS + 1,
					'orderby'     => 'ID',
				)
			);
			if ( count( $attachments ) <= self::MAX_CHILD_TAGS ) {
				foreach ( $attachments as $attachment_id ) {
					$raw[] = Tags::post( (int) $attachment_id );
				}
			}
		}
		return $raw;
	}
}
