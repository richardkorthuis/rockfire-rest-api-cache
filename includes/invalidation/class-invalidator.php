<?php
/**
 * Buffered, de-duplicated cache invalidation.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Invalidation;

use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Storage\Store_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Collects invalidation tags per site during a request and flushes them with one store call per chunk.
 *
 * Queueing runs no queries unless it triggers a commit. {@see Invalidator::commit()} runs at `shutdown` and before a REST
 * response is echoed; after the `shutdown` commit every queue call commits immediately. The immediate methods
 * (`flush_tags()`, `flush_url()`, `flush_route()`, `flush_all()`) bypass the buffer. Every flush fires {@see Hooks::FLUSHED}.
 *
 * The `EVENT_*` constants are the event names the built-in listeners pass to {@see Hooks::INVALIDATION_TAGS}.
 *
 * @since 1.0.0
 */
final class Invalidator {

	use Guarded_Trait;
	use Hook_Subscriber_Trait;

	/**
	 * Event: a post was saved (`wp_after_insert_post`). Context: `post_id`, `post_type`.
	 *
	 * @var string
	 */
	public const EVENT_POST_SAVED = 'post_saved';

	/**
	 * Event: the status of a post changed (`transition_post_status`). Context: `post_id`, `post_type`, `old_status`, `new_status`.
	 *
	 * @var string
	 */
	public const EVENT_POST_STATUS_CHANGED = 'post_status_changed';

	/**
	 * Event: a post was deleted (`deleted_post`). Context: `post_id`, `post_type`.
	 *
	 * @var string
	 */
	public const EVENT_POST_DELETED = 'post_deleted';

	/**
	 * Event: an attachment was uploaded, edited, attached or detached (`add_attachment`, `edit_attachment`, `wp_media_attach_action`).
	 * Context: `post_id`, `post_type`; for attaching and detaching also `action` and `parent_id`.
	 *
	 * @var string
	 */
	public const EVENT_ATTACHMENT_SAVED = 'attachment_saved';

	/**
	 * Event: a term was created (`created_term`). Context: `term_id`, `taxonomy`.
	 *
	 * @var string
	 */
	public const EVENT_TERM_CREATED = 'term_created';

	/**
	 * Event: a term was edited (`edited_term`). Context: `term_id`, `taxonomy`.
	 *
	 * @var string
	 */
	public const EVENT_TERM_EDITED = 'term_edited';

	/**
	 * Event: a term was deleted (`delete_term`). Context: `term_id`, `taxonomy`, `object_ids`.
	 *
	 * @var string
	 */
	public const EVENT_TERM_DELETED = 'term_deleted';

	/**
	 * Event: a term taxonomy row (its count) was updated (`edited_term_taxonomy`). Context: `term_id`, `taxonomy`.
	 *
	 * @var string
	 */
	public const EVENT_TERM_COUNTED = 'term_counted';

	/**
	 * Event: children of a deleted term were re-parented (`edited_term_taxonomies`). Context: `term_ids`, `taxonomy`.
	 *
	 * @var string
	 */
	public const EVENT_TERMS_REPARENTED = 'terms_reparented';

	/**
	 * Event: the terms of an object changed (`set_object_terms`). Context: `object_id`, `taxonomy`, `term_ids`, plus `post_id`/`post_type` for a post.
	 *
	 * Queued at commit time with the net change of the request (see {@see Invalidator::defer()}).
	 *
	 * @var string
	 */
	public const EVENT_OBJECT_TERMS_SET = 'object_terms_set';

	/**
	 * Event: a user was updated (`profile_update`). Context: `user_id`.
	 *
	 * @var string
	 */
	public const EVENT_USER_UPDATED = 'user_updated';

	/**
	 * Event: a user was created (`user_register`). Context: `user_id`.
	 *
	 * @var string
	 */
	public const EVENT_USER_REGISTERED = 'user_registered';

	/**
	 * Event: a user was deleted (`deleted_user`). Context: `user_id`, `reassign`.
	 *
	 * @var string
	 */
	public const EVENT_USER_DELETED = 'user_deleted';

	/**
	 * Event: a user joined a site (`add_user_to_blog`). Context: `user_id`, `blog_id`.
	 *
	 * @var string
	 */
	public const EVENT_USER_ADDED_TO_BLOG = 'user_added_to_blog';

	/**
	 * Event: a user left a site (`remove_user_from_blog`). Context: `user_id`, `blog_id`.
	 *
	 * @var string
	 */
	public const EVENT_USER_REMOVED_FROM_BLOG = 'user_removed_from_blog';

	/**
	 * Event: an approved comment was stored (`wp_insert_comment`). Context: `comment_id`, `comment_type`, `post_id`.
	 *
	 * @var string
	 */
	public const EVENT_COMMENT_INSERTED = 'comment_inserted';

	/**
	 * Event: an approved comment was edited (`edit_comment`). Context: `comment_id`, `comment_type`, `post_id`.
	 *
	 * @var string
	 */
	public const EVENT_COMMENT_EDITED = 'comment_edited';

	/**
	 * Event: a comment entered or left the approved state (`transition_comment_status`). Context: `comment_id`, `comment_type`, `post_id`.
	 *
	 * @var string
	 */
	public const EVENT_COMMENT_STATUS_CHANGED = 'comment_status_changed';

	/**
	 * Event: meta was added (`added_{type}_meta`). Context: `meta_type`, `meta_key`, `object_id`.
	 *
	 * @var string
	 */
	public const EVENT_META_ADDED = 'meta_added';

	/**
	 * Event: meta was updated (`updated_{type}_meta`). Context: `meta_type`, `meta_key`, `object_id`.
	 *
	 * @var string
	 */
	public const EVENT_META_UPDATED = 'meta_updated';

	/**
	 * Event: meta was deleted (`deleted_{type}_meta`). Context: `meta_type`, `meta_key`, `object_id`.
	 *
	 * @var string
	 */
	public const EVENT_META_DELETED = 'meta_deleted';

	/**
	 * Event: a mapped option was updated (`updated_option`). Context: `option`.
	 *
	 * @var string
	 */
	public const EVENT_OPTION_UPDATED = 'option_updated';

	/**
	 * Event: a mapped option was created (`added_option`). Context: `option`.
	 *
	 * @var string
	 */
	public const EVENT_OPTION_ADDED = 'option_added';

	/**
	 * Maximum number of tags per store call.
	 *
	 * @var int
	 */
	public const CHUNK_SIZE = 1000;

	/**
	 * Maximum number of commit passes at `shutdown` (re-queued tags are drained, the loop stays bounded).
	 *
	 * @var int
	 */
	private const MAX_DRAIN_PASSES = 3;

	/**
	 * Tag count that triggers an early commit unless filtered.
	 *
	 * @var int
	 */
	private const DEFAULT_THRESHOLD = 500;

	/**
	 * Resolves the store the flushes go to; called per flush, so it follows `switch_to_blog()`.
	 *
	 * @var \Closure
	 * @phpstan-var \Closure(): Store_Interface
	 */
	private \Closure $store;

	/**
	 * Maps a URL to its URL hash, or null when the URL has no cacheable route.
	 *
	 * @var \Closure
	 */
	private \Closure $url_hasher;

	/**
	 * Pending tags: blog ID => tag => true.
	 *
	 * @var array<int,array<string,true>>
	 */
	private array $pending = array();

	/**
	 * Deferred changes: key => [ blog ID, resolver ]. Resolved (and queued) at the start of the next commit.
	 *
	 * @var array<string,array{0:int,1:\Closure}>
	 */
	private array $deferred = array();

	/**
	 * Tags whose flush failed once and were re-queued: blog ID => tag => true. A second failure drops them.
	 *
	 * @var array<int,array<string,true>>
	 */
	private array $failed = array();

	/**
	 * Whether a commit is running (it never auto-commits recursively).
	 *
	 * @var bool
	 */
	private bool $committing = false;

	/**
	 * Whether the `shutdown` commit has run; from then on queue() commits immediately.
	 *
	 * @var bool
	 */
	private bool $shut_down = false;

	/**
	 * Constructor.
	 *
	 * @param Store_Interface|\Closure $store      Store, or `fn(): Store_Interface` resolving the store of the
	 *                                            current site (the plugin passes its per-site container store).
	 * @param \Closure                 $url_hasher `fn( string $url ): string|string[]|null`: the URL hash(es) of a URL (Request_Context::url_variants + Cache_Key::url_hash).
	 */
	public function __construct( Store_Interface|\Closure $store, \Closure $url_hasher ) {
		$this->store      = $store instanceof Store_Interface ? static fn(): Store_Interface => $store : $store;
		$this->url_hasher = $url_hasher;
	}

	/**
	 * The hooks: commit at `shutdown` (priority 0) and before a REST response is echoed
	 * (`rest_pre_echo_response`, PHP_INT_MAX).
	 *
	 * @return array<int,array<int,mixed>>
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		return array(
			array( 'shutdown', 'commit_action', 0, 1 ),
			array( 'rest_pre_echo_response', 'commit_filter', PHP_INT_MAX, 1 ),
		);
	}

	/**
	 * The store of the current site.
	 *
	 * @return Store_Interface
	 */
	private function store(): Store_Interface {
		return ( $this->store )();
	}

	/**
	 * `shutdown` callback: commit the buffer; afterwards every queue() call commits immediately.
	 *
	 * @return void
	 */
	public function commit_action(): void {
		// Later `shutdown` callbacks (priority >= 1) may still save content: their tags are committed as they are queued.
		$this->shut_down = true;
		// Bounded, so a listener that always re-queues cannot loop forever.
		$this->drain();
	}

	/**
	 * Pass-through `rest_pre_echo_response` callback that commits the buffer. Never throws.
	 *
	 * @param mixed $response The response, returned unchanged.
	 *
	 * @return mixed
	 */
	public function commit_filter( $response ) {
		$this->safe_commit();
		return $response;
	}

	/**
	 * Buffer tags for the next commit.
	 *
	 * Tags are normalised, passed through {@see Hooks::INVALIDATION_TAGS}, normalised again and de-duplicated.
	 * Runs no queries, unless the buffer is committed: when the unique pending tags plus the deferred changes over all sites reach
	 * {@see Hooks::AUTO_COMMIT_THRESHOLD} (default 500), or always once the `shutdown` commit has run. Never commits
	 * while a commit is running.
	 *
	 * @param string[]            $tags    Tags.
	 * @param string              $event   What changed: an `EVENT_*` constant or a custom name.
	 * @param array<string,mixed> $context Subject of the change (see the `EVENT_*` constants), handed to the filter.
	 * @param int|null            $blog_id Site the tags belong to; default the current site.
	 *
	 * @phpstan-param list<string> $tags
	 *
	 * @return void
	 */
	public function queue( array $tags, string $event, array $context = array(), ?int $blog_id = null ): void {
		$tags = Tags::normalize_many( $tags );
		/**
		 * Filters the tags a change invalidates.
		 *
		 * @see Hooks::INVALIDATION_TAGS
		 */
		$filtered = apply_filters( Hooks::INVALIDATION_TAGS, $tags, $event, $context ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		$tags     = is_iterable( $filtered ) ? Tags::normalize_many( $filtered ) : array();
		if ( array() === $tags ) {
			return;
		}

		$blog_id = $blog_id ?? (int) get_current_blog_id();
		foreach ( $tags as $tag ) {
			$this->pending[ $blog_id ][ $tag ] = true;
		}

		$this->maybe_commit();
	}

	/**
	 * Commit when the buffer must not wait: always after the `shutdown` commit (drained like it), and before it once
	 * the pending tags plus the deferred changes over all sites reach {@see Hooks::AUTO_COMMIT_THRESHOLD}. Never
	 * while a commit is running.
	 *
	 * @return void
	 */
	private function maybe_commit(): void {
		if ( $this->committing ) {
			return;
		}
		if ( $this->shut_down ) {
			$this->drain();
			return;
		}

		/**
		 * Filters the pending tag count at which the queue is committed early.
		 *
		 * @see Hooks::AUTO_COMMIT_THRESHOLD
		 */
		$threshold = (int) apply_filters( Hooks::AUTO_COMMIT_THRESHOLD, self::DEFAULT_THRESHOLD ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		if ( $this->pending_count() + count( $this->deferred ) >= max( 1, $threshold ) ) {
			$this->safe_commit();
		}
	}

	/**
	 * Commit until nothing is pending or deferred, in at most 3 passes (tags that `rfrac/flushed` listeners or failed
	 * chunks re-queue, changes deferred while committing); whatever is left is reported, not dropped silently.
	 *
	 * @return void
	 */
	private function drain(): void {
		for ( $pass = 0; $pass < self::MAX_DRAIN_PASSES && ( array() !== $this->pending || array() !== $this->deferred ); $pass++ ) {
			$this->safe_commit();
		}
		if ( array() === $this->pending && array() === $this->deferred ) {
			return;
		}
		$pending = array();
		foreach ( $this->pending as $blog_id => $tags ) {
			$list = array_map( 'strval', array_keys( $tags ) );
			sort( $list, SORT_STRING );
			$pending[ $blog_id ] = $list;
		}
		$this->swallow(
			new \RuntimeException( sprintf( '%d invalidation tags and %d deferred changes still pending after %d shutdown passes.', $this->pending_count(), count( $this->deferred ), self::MAX_DRAIN_PASSES ) ),
			'',
			array(
				'pending'  => $pending,
				'deferred' => array_map( 'strval', array_keys( $this->deferred ) ),
			)
		);
	}

	/**
	 * Defer a change whose tags depend on the net effect of everything the request does, e.g. the terms of a post
	 * that one save assigns, replaces and assigns again.
	 *
	 * The resolver runs once, at the start of the next commit (or when {@see self::pending()} is read), and returns
	 * the change to queue as `array{0: string[] tags, 1: string event, 2: array context}`, or null when the net
	 * effect invalidates nothing. Deferring again under the same key before that replaces the resolver, so a
	 * listener can keep one resolver per subject. The resolver must not run queries: it may run while another site
	 * is switched to. Deferred changes count toward {@see Hooks::AUTO_COMMIT_THRESHOLD}; after the `shutdown`
	 * commit the change is resolved and committed at once (a change deferred while committing is picked up by the
	 * next drain pass, and reported when it is still left after the last one).
	 *
	 * @param string   $key     Subject of the change, unique per listener and including the site, e.g.
	 *                          `object_terms|1|12|category` (blog 1, object 12, taxonomy `category`).
	 * @param \Closure $resolve `fn(): ?array{0:string[],1:string,2:array<string,mixed>}`.
	 * @param int|null $blog_id Site the tags belong to; default the current site.
	 *
	 * @return void
	 */
	public function defer( string $key, \Closure $resolve, ?int $blog_id = null ): void {
		$blog_id                = $blog_id ?? (int) get_current_blog_id();
		$this->deferred[ $key ] = array( $blog_id, $resolve );
		$this->maybe_commit();
	}

	/**
	 * Queue what the deferred changes resolve to. Never commits.
	 *
	 * A resolver that throws is reported; the other changes are still queued.
	 *
	 * @return void
	 */
	private function resolve_deferred(): void {
		$was_committing   = $this->committing;
		$this->committing = true;
		try {
			while ( array() !== $this->deferred ) {
				$key                       = array_key_first( $this->deferred );
				list( $blog_id, $resolve ) = $this->deferred[ $key ];
				unset( $this->deferred[ $key ] );
				try {
					$change = $resolve();
					if ( is_array( $change ) && isset( $change[0], $change[1] ) && is_array( $change[0] ) ) {
						$this->queue( array_values( $change[0] ), (string) $change[1], is_array( $change[2] ?? null ) ? $change[2] : array(), $blog_id );
					}
				} catch ( \Throwable $error ) {
					$this->swallow( $error, '', array( 'deferred' => (string) $key ) );
				}
			}
		} finally {
			$this->committing = $was_committing;
		}
	}

	/**
	 * Flush everything buffered: one store call per chunk of 1,000 tags and site.
	 *
	 * Deferred changes ({@see self::defer()}) are resolved and queued first.
	 *
	 * The buffer is emptied first, so tags queued while committing (for example by a `rfrac/flushed`
	 * listener) wait for the next commit; {@see Invalidator::commit_action()} drains them in up to 3 passes.
	 * A site deleted since queueing is skipped. Failures never stop the other sites or chunks: when the store or
	 * `switch_to_blog()` throws, the tags not flushed are re-queued once (a second failure drops and reports them),
	 * and an exception of a `rfrac/flushed` listener is reported without affecting the flush.
	 *
	 * @return int Entries flushed.
	 */
	public function commit(): int {
		$this->resolve_deferred();
		if ( array() === $this->pending ) {
			return 0;
		}
		$batch         = $this->pending;
		$this->pending = array();

		$was_committing   = $this->committing;
		$this->committing = true;
		$total            = 0;
		try {
			foreach ( $batch as $blog_id => $tags ) {
				$total += $this->commit_blog( (int) $blog_id, array_map( 'strval', array_keys( $tags ) ) );
			}
		} finally {
			$this->committing = $was_committing;
		}
		return $total;
	}

	/**
	 * Flush tags immediately, bypassing the buffer.
	 *
	 * @param string[] $tags Tags (normalised here).
	 *
	 * @phpstan-param list<string> $tags
	 *
	 * @return int Entries flushed.
	 */
	public function flush_tags( array $tags ): int {
		$total = 0;
		foreach ( array_chunk( Tags::normalize_many( $tags ), self::CHUNK_SIZE ) as $chunk ) {
			$affected = $this->store()->flush_by_tags( $chunk );
			$this->fire_flushed( 'tags', $chunk, $affected );
			$total += $affected;
		}
		return $total;
	}

	/**
	 * Flush the entries of a URL immediately.
	 *
	 * @param string $url Absolute or root-relative URL.
	 *
	 * @return int Entries flushed; 0 when the URL maps to no cacheable route.
	 */
	public function flush_url( string $url ): int {
		$hashes = ( $this->url_hasher )( $url );
		$hashes = array_values( array_unique( array_filter( is_array( $hashes ) ? $hashes : array( $hashes ), static fn( $h ): bool => is_string( $h ) && '' !== $h ) ) );
		if ( array() === $hashes ) {
			return 0;
		}
		$affected = 0;
		foreach ( $hashes as $hash ) {
			$affected += $this->store()->flush_by_url_hash( $hash );
		}
		$this->fire_flushed( 'url', array( $url ), $affected );
		return $affected;
	}

	/**
	 * Flush the entries of a route (or rule key) immediately.
	 *
	 * @param string $route Route or rule key.
	 *
	 * @return int Entries flushed.
	 */
	public function flush_route( string $route ): int {
		$affected = $this->store()->flush_route( $route );
		$this->fire_flushed( 'route', array( $route ), $affected );
		return $affected;
	}

	/**
	 * Flush every entry of the current site immediately, then drop its pending tags.
	 *
	 * When the store throws, the pending tags are kept and the exception propagates.
	 *
	 * @return int Entries flushed.
	 */
	public function flush_all(): int {
		$affected = $this->store()->flush_all();
		$blog_id  = (int) get_current_blog_id();
		unset( $this->pending[ $blog_id ] );
		foreach ( $this->deferred as $key => $deferred ) {
			if ( $blog_id === $deferred[0] ) {
				unset( $this->deferred[ $key ] );
			}
		}
		$this->fire_flushed( 'all', array(), $affected );
		return $affected;
	}

	/**
	 * The pending tags per site.
	 *
	 * Resolves the deferred changes first ({@see self::defer()}), so a change made after this call is no longer
	 * netted against the ones before it.
	 *
	 * @return array<int,string[]> Blog ID => unique tags in ascending order.
	 *
	 * @phpstan-return array<int,list<string>>
	 */
	public function pending(): array {
		$this->resolve_deferred();
		$out = array();
		foreach ( $this->pending as $blog_id => $tags ) {
			$list = array_map( 'strval', array_keys( $tags ) );
			sort( $list, SORT_STRING );
			$out[ $blog_id ] = $list;
		}
		return $out;
	}

	/**
	 * Commit, reporting anything that still escapes instead of throwing.
	 *
	 * @return void
	 */
	private function safe_commit(): void {
		try {
			$this->commit();
		} catch ( \Throwable $error ) {
			$this->swallow( $error );
		}
	}

	/**
	 * Flush the tags of one site, chunked. Tags that could not be flushed are handed to {@see Invalidator::retry_later()}.
	 *
	 * @param int      $blog_id Site ID.
	 * @param string[] $tags    Pending tags.
	 *
	 * @phpstan-param list<string> $tags
	 *
	 * @return int Entries flushed.
	 */
	private function commit_blog( int $blog_id, array $tags ): int {
		sort( $tags, SORT_STRING );

		$switched = false;
		$total    = 0;
		$chunks   = array_chunk( $tags, self::CHUNK_SIZE );
		$index    = 0;
		try {
			if ( is_multisite() && (int) get_current_blog_id() !== $blog_id ) {
				if ( ! get_site( $blog_id ) ) {
					return 0;
				}
				switch_to_blog( $blog_id );
				$switched = true;
			}
			for ( $count = count( $chunks ); $index < $count; $index++ ) {
				$affected = $this->store()->flush_by_tags( $chunks[ $index ] );
				$this->forget_failures( $blog_id, $chunks[ $index ] );
				$this->fire_flushed( 'tags', $chunks[ $index ], $affected );
				$total += $affected;
			}
		} catch ( \Throwable $error ) {
			$this->retry_later( $blog_id, array_merge( ...array_slice( $chunks, $index ) ), $error );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
		return $total;
	}

	/**
	 * Re-queue tags whose flush failed for the first time; drop (and report) tags that already failed once.
	 *
	 * @param int        $blog_id Site ID.
	 * @param string[]   $tags    Tags that were not flushed.
	 * @param \Throwable $error   The failure.
	 *
	 * @phpstan-param list<string> $tags
	 *
	 * @return void
	 */
	private function retry_later( int $blog_id, array $tags, \Throwable $error ): void {
		$requeued = array();
		$dropped  = array();
		foreach ( $tags as $tag ) {
			if ( isset( $this->failed[ $blog_id ][ $tag ] ) ) {
				unset( $this->failed[ $blog_id ][ $tag ] );
				$dropped[] = $tag;
				continue;
			}
			$this->failed[ $blog_id ][ $tag ]  = true;
			$this->pending[ $blog_id ][ $tag ] = true;
			$requeued[]                        = $tag;
		}
		$this->swallow(
			$error,
			'',
			array(
				'blog_id'  => $blog_id,
				'requeued' => $requeued,
				'dropped'  => $dropped,
			)
		);
	}

	/**
	 * Forget the earlier failures of tags that were flushed now.
	 *
	 * @param int      $blog_id Site ID.
	 * @param string[] $tags    Flushed tags.
	 *
	 * @return void
	 */
	private function forget_failures( int $blog_id, array $tags ): void {
		if ( ! isset( $this->failed[ $blog_id ] ) ) {
			return;
		}
		foreach ( $tags as $tag ) {
			unset( $this->failed[ $blog_id ][ $tag ] );
		}
		if ( array() === $this->failed[ $blog_id ] ) {
			unset( $this->failed[ $blog_id ] );
		}
	}

	/**
	 * Total number of pending tags over all sites.
	 *
	 * @return int
	 */
	private function pending_count(): int {
		$count = 0;
		foreach ( $this->pending as $tags ) {
			$count += count( $tags );
		}
		return $count;
	}
}
