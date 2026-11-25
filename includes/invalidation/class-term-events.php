<?php
/**
 * Maps term CRUD and term assignment to invalidation tags.
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
 * Queues `term:{id}` and `list:{taxonomy}` for term changes, and `post:{id}` for the objects they affect.
 *
 * `term-ref:{id}` marks responses that refer to a term without showing its data, like a post with its `categories`,
 * `class_list` or a `%category%` link. It is queued when the term is deleted, and when its slug or (in a hierarchical
 * taxonomy) its parent changes, for the term and its descendants: both appear in `class_list` and in the links of
 * posts. Renaming, describing or recounting a term leaves those responses as they are.
 *
 * Term assignments are netted per request: they are handed to the invalidator as deferred changes
 * ({@see Invalidator::defer()}) that only count the relationships an object gained or lost compared to before its
 * first change of the request. So a term that one save assigns and then replaces again (wp_insert_post() assigns the
 * default category to a new post, the REST controller then sets the requested categories) is not queued as an
 * assignment; its recounts are still queued as `term_counted`.
 *
 * @since 1.0.0
 */
final class Term_Events {

	use Guarded_Trait;
	use Hook_Subscriber_Trait;

	/**
	 * Largest number of objects of a deleted term that get their own `post:{id}` tag.
	 *
	 * @var int
	 */
	private const MAX_OBJECT_TAGS = 200;

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
	 * Ancestor resolver.
	 *
	 * @var Term_Ancestry
	 */
	private Term_Ancestry $ancestry;

	/**
	 * Object term assignments changed in this request and not yet settled, by `{blog}|{object}|{taxonomy}`.
	 *
	 * `added` / `removed`: term taxonomy IDs the object gained / lost since before its first change of the request
	 * (as sets; a term added and removed again is in neither); `terms`: term taxonomy ID => IDs of the term and its
	 * ancestors (resolved while the term still exists); `post`: tags of the object; `context`: the event context;
	 * `touched`: whether any relationship of the object changed in the window, even if the changes cancel out;
	 * `held`: not used here.
	 *
	 * @var array<string,array<string,mixed>>
	 * @phpstan-var array<string,array{added:array<int,true>,removed:array<int,true>,terms:array<int,list<int>>,post:list<string>,context:array<string,mixed>,held:bool,touched:bool,relevant:bool}>
	 */
	private array $assignments = array();

	/**
	 * Relationships inserted and deleted since the last `set_object_terms` of an object and taxonomy, by
	 * `{blog}|{object}|{taxonomy}`, in the shape of {@see self::$assignments} with `added` / `removed` sets.
	 *
	 * Core inserts (`added_term_relationship`) and deletes (`deleted_term_relationships`) relationships before it fires
	 * `set_object_terms`, which then takes them over (or drops them when {@see Hooks::FLUSH_ON_SET_TERMS} says so).
	 * Deletions without a `set_object_terms` (wp_remove_object_terms(), wp_delete_object_term_relationships()) are
	 * taken over when the change is settled. `held` marks relationships changed inside a wp_set_object_terms() call
	 * that has not fired `set_object_terms` yet: a commit in between (after `shutdown`, or at the auto-commit
	 * threshold) leaves them to that call and its {@see Hooks::FLUSH_ON_SET_TERMS} decision.
	 *
	 * @var array<string,array<string,mixed>>
	 * @phpstan-var array<string,array{added:array<int,true>,removed:array<int,true>,terms:array<int,list<int>>,post:list<string>,context:array<string,mixed>,held:bool,touched:bool,relevant:bool}>
	 */
	private array $relationships = array();

	/**
	 * Slug and parent of terms before their first update in this request, by `{blog}|{term_id}`.
	 *
	 * @var array<string,array{0:string,1:int}|null>
	 */
	private array $terms_before = array();

	/**
	 * Term taxonomy ID => IDs of the term and its ancestors, memoised per request by `{blog}|{taxonomy}`, so bulk
	 * assignments and wp_delete_term() resolve each distinct term once. Dropped for a taxonomy when a parent changes
	 * or a term is deleted.
	 *
	 * @var array<string,array<int,list<int>>>
	 */
	private array $resolved = array();

	/**
	 * Constructor.
	 *
	 * @param Invalidator   $invalidator Invalidator.
	 * @param Relevance     $relevance   Relevance rules.
	 * @param Term_Ancestry $ancestry    Ancestor resolver.
	 */
	public function __construct( Invalidator $invalidator, Relevance $relevance, Term_Ancestry $ancestry ) {
		$this->invalidator = $invalidator;
		$this->relevance   = $relevance;
		$this->ancestry    = $ancestry;
	}

	/**
	 * The hooks: `created_term` (10, 3), `edit_terms` (10, 2), `edited_term` (10, 3), `delete_term` (10, 5),
	 * `added_term_relationship` (10, 3), `deleted_term_relationships` (10, 3), `set_object_terms` (10, 6),
	 * `edited_term_taxonomy` (10, 2) and `edited_term_taxonomies` (10, 1).
	 *
	 * @return array<int,array<int,mixed>>
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		return array(
			array( 'created_term', 'on_created_term', 10, 3 ),
			array( 'edit_terms', 'on_edit_terms', 10, 2 ),
			array( 'edited_term', 'on_edited_term', 10, 3 ),
			array( 'delete_term', 'on_delete_term', 10, 5 ),
			array( 'added_term_relationship', 'on_added_term_relationship', 10, 3 ),
			array( 'deleted_term_relationships', 'on_deleted_term_relationships', 10, 3 ),
			array( 'set_object_terms', 'on_set_object_terms', 10, 6 ),
			array( 'edited_term_taxonomy', 'on_edited_term_taxonomy', 10, 2 ),
			array( 'edited_term_taxonomies', 'on_edited_term_taxonomies', 10, 1 ),
		);
	}

	/**
	 * `created_term`: a term was created.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return void
	 */
	public function on_created_term( $term_id, $tt_id, $taxonomy ): void {
		try {
			unset( $this->terms_before[ get_current_blog_id() . '|' . (int) $term_id ] ); // wp_insert_term() fires edit_terms for a slug fix-up without edited_term.
			if ( ! $this->relevance->taxonomy( (string) $taxonomy ) ) {
				return;
			}
			$this->invalidator->queue( $this->tags( Tags::list_for( (string) $taxonomy ) ), Invalidator::EVENT_TERM_CREATED, $this->context( (int) $term_id, (string) $taxonomy ) );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_TERM_CREATED );
		}
	}

	/**
	 * `edit_terms`: a term is about to be updated; remember its slug and parent as they were before the first update
	 * of this request.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return void
	 */
	public function on_edit_terms( $term_id, $taxonomy ): void {
		try {
			if ( ! $this->refers( (string) $taxonomy ) ) {
				return;
			}
			$key = get_current_blog_id() . '|' . (int) $term_id;
			if ( ! array_key_exists( $key, $this->terms_before ) ) {
				$this->terms_before[ $key ] = $this->slug_and_parent( (int) $term_id, (string) $taxonomy );
			}
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_TERM_EDITED );
		}
	}

	/**
	 * `edited_term`: a term was updated.
	 *
	 * Queues the term and its taxonomy list. When the slug changed, or the parent of a hierarchical term, the term and
	 * its descendants also get `term-ref:{id}`: posts show those in `class_list` and (with `%category%` permalinks) in
	 * their links.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return void
	 */
	public function on_edited_term( $term_id, $tt_id, $taxonomy ): void {
		try {
			$taxonomy = (string) $taxonomy;
			if ( ! $this->refers( $taxonomy ) ) {
				return;
			}
			$term_id = (int) $term_id;
			$raw     = $this->relevance->taxonomy( $taxonomy ) ? array( Tags::term( $term_id ), Tags::list_for( $taxonomy ) ) : array();

			$key    = get_current_blog_id() . '|' . $term_id;
			$before = $this->terms_before[ $key ] ?? null;
			unset( $this->terms_before[ $key ] );
			$after = $this->slug_and_parent( $term_id, $taxonomy );
			if ( null === $before || $before !== $after ) {
				$raw = array_merge( $raw, $this->reference_tags( array( $term_id ), $taxonomy ) );
			}
			if ( null === $before || null === $after || $before[1] !== $after[1] ) {
				$this->forget_resolved( $taxonomy ); // The ancestors of the term and its descendants changed.
			}

			$this->invalidator->queue( $this->tags( ...$raw ), Invalidator::EVENT_TERM_EDITED, $this->context( $term_id, $taxonomy ) );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_TERM_EDITED );
		}
	}

	/**
	 * `edited_term_taxonomy`: a term taxonomy row was updated.
	 *
	 * Core fires it when it recounts a term (a post is published, unpublished, trashed or deleted; terms are assigned
	 * or removed) and from wp_update_term(). The `count` of a term is part of its REST output; responses that only
	 * refer to the term (`term-ref:{id}`) are not affected.
	 *
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return void
	 */
	public function on_edited_term_taxonomy( $tt_id, $taxonomy ): void {
		try {
			$taxonomy = (string) $taxonomy;
			if ( ! $this->relevance->taxonomy( $taxonomy ) ) {
				return;
			}
			$term = get_term_by( 'term_taxonomy_id', (int) $tt_id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				return;
			}
			$this->invalidator->queue( $this->tags( Tags::term( (int) $term->term_id ), Tags::list_for( $taxonomy ) ), Invalidator::EVENT_TERM_COUNTED, $this->context( (int) $term->term_id, $taxonomy ) );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_TERM_COUNTED );
		}
	}

	/**
	 * `edited_term_taxonomies`: the children of a term being deleted were re-parented.
	 *
	 * The children and their descendants also get `term-ref:{id}`: their path in `%category%` links changed.
	 *
	 * @param int[] $tt_ids Term taxonomy IDs of the re-parented children.
	 *
	 * @return void
	 */
	public function on_edited_term_taxonomies( $tt_ids ): void {
		try {
			$tt_ids = array_values( array_filter( array_map( 'intval', (array) $tt_ids ) ) );
			if ( array() === $tt_ids ) {
				return;
			}
			$terms       = get_terms(
				array(
					'term_taxonomy_id'       => $tt_ids,
					'hide_empty'             => false,
					'update_term_meta_cache' => false,
				)
			);
			$by_taxonomy = array();
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$by_taxonomy[ $term->taxonomy ][] = (int) $term->term_id;
			}
			foreach ( $by_taxonomy as $taxonomy => $term_ids ) {
				$taxonomy = (string) $taxonomy;
				$this->forget_resolved( $taxonomy );
				if ( ! $this->refers( $taxonomy ) ) {
					continue;
				}
				$raw = $this->relevance->taxonomy( $taxonomy ) ? array_merge( array_map( array( Tags::class, 'term' ), $term_ids ), array( Tags::list_for( $taxonomy ) ) ) : array();
				$raw = array_merge( $raw, $this->reference_tags( $term_ids, $taxonomy ) );
				$this->invalidator->queue(
					$this->tags( ...$raw ),
					Invalidator::EVENT_TERMS_REPARENTED,
					array(
						'term_ids' => $term_ids,
						'taxonomy' => $taxonomy,
					)
				);
			}
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_TERMS_REPARENTED );
		}
	}

	/**
	 * `delete_term`: a term was deleted.
	 *
	 * Queues the term (`term:{id}`), every response that refers to it (`term-ref:{id}`) and the taxonomy list. Up to
	 * 200 objects also get a `post:{id}` tag; beyond that every relevant post type of the taxonomy gets its list tag.
	 *
	 * @param int    $term         Term ID.
	 * @param int    $tt_id        Term taxonomy ID.
	 * @param string $taxonomy     Taxonomy slug.
	 * @param mixed  $deleted_term Deleted term object.
	 * @param int[]  $object_ids   IDs of the objects that had the term.
	 *
	 * @return void
	 */
	public function on_delete_term( $term, $tt_id, $taxonomy, $deleted_term = null, $object_ids = array() ): void {
		try {
			$this->forget_resolved( (string) $taxonomy );
			if ( ! $this->refers( (string) $taxonomy ) ) {
				return;
			}
			$object_ids = array_map( 'intval', (array) $object_ids );
			$raw        = array( Tags::term_ref( (int) $term ) );
			if ( $this->relevance->taxonomy( (string) $taxonomy ) ) {
				array_push( $raw, Tags::term( (int) $term ), Tags::list_for( (string) $taxonomy ) );
			}
			if ( count( $object_ids ) <= self::MAX_OBJECT_TAGS ) {
				foreach ( $object_ids as $object_id ) {
					$raw[] = Tags::post( $object_id );
				}
			} else {
				$taxonomy_object = get_taxonomy( (string) $taxonomy );
				foreach ( $taxonomy_object ? (array) $taxonomy_object->object_type : array() as $post_type ) {
					if ( $this->relevance->post_type( (string) $post_type ) ) {
						$raw[] = Tags::list_for( (string) $post_type );
					}
				}
			}
			$context               = $this->context( (int) $term, (string) $taxonomy );
			$context['object_ids'] = $object_ids;
			$this->invalidator->queue( $this->tags( ...$raw ), Invalidator::EVENT_TERM_DELETED, $context );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_TERM_DELETED );
		}
	}

	/**
	 * `added_term_relationship`: wp_set_object_terms() related a term to an object it was not related to yet.
	 *
	 * Remembered until the `set_object_terms` of the same call; runs no queries.
	 *
	 * @param int    $object_id Object ID.
	 * @param int    $tt_id     Term taxonomy ID.
	 * @param string $taxonomy  Taxonomy slug.
	 *
	 * @return void
	 */
	public function on_added_term_relationship( $object_id, $tt_id, $taxonomy ): void {
		$key = $this->assignment_key( (int) $object_id, (string) $taxonomy );
		if ( ! isset( $this->relationships[ $key ] ) ) {
			$this->relationships[ $key ] = $this->empty_change( (int) $object_id, (string) $taxonomy );
		}
		$this->relationships[ $key ]['added'][ (int) $tt_id ] = true;
		// Only wp_set_object_terms() inserts relationships: its `set_object_terms` follows.
		$this->relationships[ $key ]['held'] = true;
	}

	/**
	 * `deleted_term_relationships`: terms were removed from an object (wp_remove_object_terms(), also from within
	 * wp_set_object_terms() and wp_delete_object_term_relationships()).
	 *
	 * The removed terms, the terms added so far by the same wp_set_object_terms() call and the object's tags are
	 * resolved now, while the terms still exist. Inside wp_set_object_terms() the change waits for its
	 * `set_object_terms` (and the {@see Hooks::FLUSH_ON_SET_TERMS} decision); otherwise it is settled with the other
	 * changes of the object at commit time.
	 *
	 * @param int    $object_id Object ID.
	 * @param int[]  $tt_ids    Term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy slug.
	 *
	 * @return void
	 */
	public function on_deleted_term_relationships( $object_id, $tt_ids, $taxonomy ): void {
		try {
			$taxonomy = (string) $taxonomy;
			$tt_ids   = self::id_set( $tt_ids );
			if ( array() === $tt_ids || ! taxonomy_exists( $taxonomy ) || ! $this->refers( $taxonomy ) ) {
				return;
			}
			$key    = $this->assignment_key( (int) $object_id, $taxonomy );
			$change = $this->relationships[ $key ] ?? $this->empty_change( (int) $object_id, $taxonomy );
			foreach ( $tt_ids as $tt_id ) {
				$change['removed'][ $tt_id ] = true;
			}
			$unresolved                  = array_values( array_diff( array_merge( $tt_ids, array_keys( $change['added'] ) ), array_keys( $change['terms'] ) ) );
			$change['terms']            += $this->resolve_terms( $unresolved, $taxonomy );
			$change                      = $this->with_post( $change, (int) $object_id, $taxonomy );
			$change['held']              = self::inside_set_object_terms();
			$change['relevant']          = $this->relevance->taxonomy( $taxonomy );
			$this->relationships[ $key ] = $change;
			if ( ! $change['held'] ) {
				$this->invalidator->defer( 'object_terms|' . $key, fn(): ?array => $this->settle_assignment( $key ) );
			}
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_OBJECT_TERMS_SET );
		}
	}

	/**
	 * `set_object_terms`: the terms of an object changed.
	 *
	 * Takes over the relationships the call inserted and deleted. The change is deferred and settled at commit time:
	 * only the terms the object gained or lost compared to before its first change in this request count. Those terms
	 * (and their ancestors) get `term:{id}`, plus `list:{taxonomy}` and, for a public post, `post:{id}` and its list.
	 * A term that was assigned and replaced again in the same request (the default category of a new post) is not
	 * queued as an assignment; when the terms end up unchanged only the object's own tags are queued.
	 *
	 * {@see Hooks::FLUSH_ON_SET_TERMS} governs these tags only (the relationships of a call it rejects are dropped);
	 * the term count updates core runs for the same assignment arrive as {@see Invalidator::EVENT_TERM_COUNTED}.
	 *
	 * @param int          $object_id  Object ID.
	 * @param array<mixed> $terms      Terms as passed to wp_set_object_terms().
	 * @param int[]        $tt_ids     New term taxonomy IDs.
	 * @param string       $taxonomy   Taxonomy slug.
	 * @param bool         $append     Whether the terms were appended.
	 * @param int[]        $old_tt_ids Previous term taxonomy IDs.
	 *
	 * @return void
	 */
	public function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		try {
			$key    = $this->assignment_key( (int) $object_id, (string) $taxonomy );
			$change = $this->relationships[ $key ] ?? null;
			unset( $this->relationships[ $key ] );

			/**
			 * Filters whether a change of an object's terms flushes caches.
			 *
			 * @see Hooks::FLUSH_ON_SET_TERMS
			 */
			$flush = apply_filters( Hooks::FLUSH_ON_SET_TERMS, true, $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
			if ( true !== $flush || null === $change ) {
				return;
			}

			$taxonomy = (string) $taxonomy;
			if ( ! taxonomy_exists( $taxonomy ) || ! $this->refers( $taxonomy ) ) {
				return;
			}

			// Resolve the added terms while they exist (the removed ones were resolved when they were removed).
			$unresolved = array_values( array_diff( array_keys( $change['added'] ), array_keys( $change['terms'] ) ) );
			if ( array() !== $unresolved ) {
				$change['terms'] += $this->resolve_terms( $unresolved, $taxonomy );
			}
			$change['relevant'] = $this->relevance->taxonomy( $taxonomy );
			$this->merge( $key, $this->with_post( $change, (int) $object_id, $taxonomy ) );
			$this->invalidator->defer( 'object_terms|' . $key, fn(): ?array => $this->settle_assignment( $key ) );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_OBJECT_TERMS_SET );
		}
	}

	/**
	 * Net a change into the assignment of its object: a term added after it was removed (or the other way round)
	 * cancels out.
	 *
	 * @param string              $key    Assignment key.
	 * @param array<string,mixed> $change Change.
	 * @phpstan-param array{added:array<int,true>,removed:array<int,true>,terms:array<int,list<int>>,post:list<string>,context:array<string,mixed>,held:bool,touched:bool,relevant:bool} $change
	 *
	 * @return void
	 */
	private function merge( string $key, array $change ): void {
		$record = $this->assignments[ $key ] ?? $this->empty_change( 0, '' );
		foreach ( array_keys( $change['added'] ) as $tt_id ) {
			if ( isset( $record['removed'][ $tt_id ] ) ) {
				unset( $record['removed'][ $tt_id ] );
			} else {
				$record['added'][ $tt_id ] = true;
			}
		}
		foreach ( array_keys( $change['removed'] ) as $tt_id ) {
			if ( isset( $record['added'][ $tt_id ] ) ) {
				unset( $record['added'][ $tt_id ] );
			} else {
				$record['removed'][ $tt_id ] = true;
			}
		}
		$record['terms']   += $change['terms'];
		$record['touched']  = $record['touched'] || array() !== $change['added'] || array() !== $change['removed'];
		$record['relevant'] = $record['relevant'] || $change['relevant'];
		if ( array() !== $change['post'] ) {
			$record['post'] = $change['post'];
		}
		$record['context']         = $change['context'] + $record['context'];
		$this->assignments[ $key ] = $record;
	}

	/**
	 * The net change of an object's terms in this request, for {@see Invalidator::defer()}. Runs no queries.
	 *
	 * Takes over relationships deleted without a `set_object_terms` first (not those of a wp_set_object_terms() call
	 * still running). Only the terms the object gained or lost compared to before its first change of the request get
	 * `term:{id}`, so a term that was assigned and removed again within the request is not queued. The object's own
	 * tags are queued whenever any of its relationships changed: a request reading it in between may have cached
	 * the intermediate state.
	 *
	 * @param string $key Assignment key.
	 *
	 * @return array{0:string[],1:string,2:array<string,mixed>}|null Null when nothing changed.
	 */
	private function settle_assignment( string $key ): ?array {
		if ( isset( $this->relationships[ $key ] ) && ! $this->relationships[ $key ]['held'] ) {
			$this->merge( $key, $this->relationships[ $key ] );
			unset( $this->relationships[ $key ] );
		}
		$record = $this->assignments[ $key ] ?? null;
		unset( $this->assignments[ $key ] );
		if ( null === $record || ! $record['touched'] ) {
			return null;
		}
		$affected = $record['added'] + $record['removed'];
		if ( array() === $affected ) {
			if ( array() === $record['post'] ) {
				return null;
			}
			$context             = $record['context'];
			$context['term_ids'] = array();
			return array( $record['post'], Invalidator::EVENT_OBJECT_TERMS_SET, $context );
		}
		ksort( $affected );

		$term_ids = array();
		foreach ( array_keys( $affected ) as $tt_id ) {
			foreach ( $record['terms'][ $tt_id ] ?? array() as $term_id ) {
				$term_ids[ $term_id ] = true;
			}
		}
		$term_ids = array_keys( $term_ids );

		$raw                 = $record['relevant'] ? array_merge( array_map( array( Tags::class, 'term' ), $term_ids ), array( Tags::list_for( (string) $record['context']['taxonomy'] ) ) ) : array();
		$context             = $record['context'];
		$context['term_ids'] = $term_ids;
		return array( array_merge( $this->tags( ...$raw ), $record['post'] ), Invalidator::EVENT_OBJECT_TERMS_SET, $context );
	}

	/**
	 * An empty change of an object's terms.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $taxonomy  Taxonomy slug.
	 *
	 * @return array<string,mixed>
	 * @phpstan-return array{added:array<int,true>,removed:array<int,true>,terms:array<int,list<int>>,post:list<string>,context:array<string,mixed>,held:bool,touched:bool,relevant:bool}
	 */
	private function empty_change( int $object_id, string $taxonomy ): array {
		return array(
			'added'    => array(),
			'removed'  => array(),
			'terms'    => array(),
			'post'     => array(),
			'context'  => array(
				'object_id' => $object_id,
				'taxonomy'  => $taxonomy,
			),
			'held'     => false,
			'touched'  => false,
			'relevant' => false,
		);
	}

	/**
	 * Whether the current hook runs inside wp_set_object_terms(), which fires `set_object_terms` when it is done.
	 *
	 * @return bool
	 */
	private static function inside_set_object_terms(): bool {
		// Bounded and without arguments: hook handler -> WP_Hook -> do_action() -> wp_remove_object_terms() -> wp_set_object_terms().
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 24 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Detects the calling core function; no output.
			if ( 'wp_set_object_terms' === $frame['function'] && ! isset( $frame['class'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether posts can refer to the terms of a taxonomy in cached output: a relevant (REST) taxonomy, or a public one,
	 * whose terms appear in `class_list` and permalinks even when the taxonomy is not in the REST API. Only relevant
	 * taxonomies get `term:{id}` and `list:{taxonomy}` tags.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return bool
	 */
	private function refers( string $taxonomy ): bool {
		if ( $this->relevance->taxonomy( $taxonomy ) ) {
			return true;
		}
		$object = get_taxonomy( $taxonomy );
		return $object instanceof \WP_Taxonomy && ! empty( $object->public );
	}

	/**
	 * Forget the memoised terms of a taxonomy on the current site.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return void
	 */
	private function forget_resolved( string $taxonomy ): void {
		unset( $this->resolved[ get_current_blog_id() . '|' . $taxonomy ] );
	}

	/**
	 * Add the tags of the object (a public post of a relevant type) to a change.
	 *
	 * @param array<string,mixed> $change    Change.
	 * @param int                 $object_id Object ID.
	 * @param string              $taxonomy  Taxonomy slug.
	 * @phpstan-param array{added:array<int,true>,removed:array<int,true>,terms:array<int,list<int>>,post:list<string>,context:array<string,mixed>,held:bool,touched:bool,relevant:bool} $change
	 *
	 * @return array<string,mixed>
	 * @phpstan-return array{added:array<int,true>,removed:array<int,true>,terms:array<int,list<int>>,post:list<string>,context:array<string,mixed>,held:bool,touched:bool,relevant:bool}
	 */
	private function with_post( array $change, int $object_id, string $taxonomy ): array {
		$post = get_post( $object_id );
		if ( $post instanceof \WP_Post
			&& is_object_in_taxonomy( $post->post_type, $taxonomy )
			&& $this->relevance->post_type( $post->post_type )
			&& $this->relevance->post_is_public( $post ) ) {
			$change['post']                 = $this->tags( Tags::post( (int) $post->ID ), Tags::list_for( $post->post_type ) );
			$change['context']['post_id']   = (int) $post->ID;
			$change['context']['post_type'] = (string) $post->post_type;
		}
		return $change;
	}

	/**
	 * Key of the assignment of an object in a taxonomy on the current site.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $taxonomy  Taxonomy slug.
	 *
	 * @return string
	 */
	private function assignment_key( int $object_id, string $taxonomy ): string {
		return get_current_blog_id() . '|' . $object_id . '|' . $taxonomy;
	}

	/**
	 * The IDs of the terms behind term taxonomy IDs, each followed by its ancestors.
	 *
	 * @param int[]  $tt_ids   Term taxonomy IDs.
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @phpstan-param list<int> $tt_ids
	 *
	 * @return array<int,int[]> Term taxonomy ID => term IDs; empty for an ID that matches no term.
	 *
	 * @phpstan-return array<int,list<int>>
	 */
	private function resolve_terms( array $tt_ids, string $taxonomy ): array {
		if ( array() === $tt_ids ) {
			return array();
		}
		$memo    = get_current_blog_id() . '|' . $taxonomy;
		$known   = $this->resolved[ $memo ] ?? array();
		$missing = array_values( array_diff( $tt_ids, array_keys( $known ) ) );
		if ( array() !== $missing ) {
			$found = array_fill_keys( $missing, array() );
			// Full terms: the tt_id => term_id mapping is needed, which no `fields` value returns.
			$terms = get_terms(
				array(
					'taxonomy'               => $taxonomy,
					'term_taxonomy_id'       => $missing,
					'hide_empty'             => false,
					'update_term_meta_cache' => false,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( isset( $found[ (int) $term->term_taxonomy_id ] ) ) {
					$term_id                                = (int) $term->term_id;
					$found[ (int) $term->term_taxonomy_id ] = array_merge( array( $term_id ), $this->ancestry->ancestors( array( $term_id ), $taxonomy ) );
				}
			}
			$known                  += $found;
			$this->resolved[ $memo ] = $known;
		}
		return array_intersect_key( $known, array_flip( $tt_ids ) );
	}

	/**
	 * `term-ref:{id}` for terms and, in a hierarchical taxonomy, all their descendants.
	 *
	 * @param int[]  $term_ids Term IDs.
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return array<int,string|null>
	 */
	private function reference_tags( array $term_ids, string $taxonomy ): array {
		$all = array();
		foreach ( $term_ids as $term_id ) {
			$all[ (int) $term_id ] = true;
			if ( is_taxonomy_hierarchical( $taxonomy ) ) {
				$children = get_term_children( (int) $term_id, $taxonomy );
				foreach ( is_array( $children ) ? $children : array() as $child ) {
					$all[ (int) $child ] = true;
				}
			}
		}
		return array_map( array( Tags::class, 'term_ref' ), array_keys( $all ) );
	}

	/**
	 * The slug and parent of a term, or null when it does not exist.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return array{0:string,1:int}|null
	 */
	private function slug_and_parent( int $term_id, string $taxonomy ): ?array {
		$term = get_term( $term_id, $taxonomy );
		return $term instanceof \WP_Term ? array( (string) $term->slug, (int) $term->parent ) : null;
	}

	/**
	 * Sorted unique positive integer IDs.
	 *
	 * @param mixed $ids IDs.
	 *
	 * @return int[]
	 *
	 * @phpstan-return list<int>
	 */
	private static function id_set( mixed $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * The subject context of a term event.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return array<string,mixed>
	 */
	private function context( int $term_id, string $taxonomy ): array {
		return array(
			'term_id'  => $term_id,
			'taxonomy' => $taxonomy,
		);
	}
}
