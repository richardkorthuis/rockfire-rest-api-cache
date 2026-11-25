<?php
/**
 * The bridge of the invalidation and capability hooks of the original plugin.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Tags;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the flush decisions, the pre-delete actions and the settings capability of the original onto their Rockfire seams.
 *
 * The flush decisions are filters on the filters of the engine, so a legacy callback that answers `false` stops the
 * matching flush: `wp_rest_cache/flush_on_meta_update` (and its `/{meta_type}/{meta_key}` variant) rides on
 * {@see Hooks::FLUSH_ON_META_UPDATE}, `wp_rest_cache/flush_on_set_terms` on {@see Hooks::FLUSH_ON_SET_TERMS}. They are
 * seeded with the decision of the engine, which is true for a key that is shown in the REST API (the original started
 * from false), and they get a meta ID of 0: Rockfire does not have one.
 *
 * The three pre-delete actions observe {@see Hooks::INVALIDATION_TAGS}. They fire when a change is queued, not right
 * before the entries are deleted, for the subject the context of the change names (a post ID with its type, a term, a
 * list of terms, a user, a comment) and for each `list:` tag. The tags are never changed. A callback that flushes a
 * tag or queues another invalidation does not fire the actions again: while they run, the bridge is closed.
 *
 * `wp_rest_cache/settings_capability` rides on {@see Hooks::SETTINGS_CAPABILITY}, whose default is now `manage_options`.
 *
 * @since 1.0.0
 */
final class Invalidation_Bridge {

	use Hook_Subscriber_Trait;

	/**
	 * The object types of the lists whose name differs from the one the original used.
	 *
	 * @var array<string,string>
	 */
	private const OBJECT_TYPES = array(
		Tags::LIST_USERS    => 'users',
		Tags::LIST_COMMENTS => 'comment',
	);

	/**
	 * Whether the legacy actions are being fired (a re-entrant queue call is not bridged again).
	 *
	 * @var bool
	 */
	private bool $firing = false;

	/**
	 * The hooks of the bridge.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,array<int,mixed>> `[ hook, method, priority, accepted args, bound args? ]` entries.
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		return array(
			array( Hooks::FLUSH_ON_META_UPDATE, 'meta', 10, 6 ),
			array( Hooks::FLUSH_ON_SET_TERMS, 'set_terms', 10, 7 ),
			array( Hooks::INVALIDATION_TAGS, 'invalidation_tags', 10, 3 ),
			array( Hooks::SETTINGS_CAPABILITY, 'capability', 10, 1 ),
		);
	}

	/**
	 * Filter whether a meta change flushes: the general filter of the original, then the one of the meta key.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $flush      The decision of the engine.
	 * @param string $meta_type  Meta type.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @param string $action     What happened (`added`, `updated` or `deleted`); the original has no such argument.
	 *
	 * @return mixed The decision; only a strict `true` flushes.
	 */
	public function meta( mixed $flush, string $meta_type, int $object_id, string $meta_key, mixed $meta_value, string $action = '' ): mixed {
		unset( $action );

		$flush = Bridge::filter( 'wp_rest_cache/flush_on_meta_update', array( $flush, $meta_type, 0, $object_id, $meta_key, $meta_value ) );
		if ( '' === $meta_type || '' === $meta_key ) {
			return $flush;
		}

		return Bridge::filter( "wp_rest_cache/flush_on_meta_update/{$meta_type}/{$meta_key}", array( $flush, 0, $object_id, $meta_value ) );
	}

	/**
	 * Filter whether a change of the terms of an object flushes; the arguments are the ones of the original.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $flush      The decision of the engine.
	 * @param mixed $object_id  Object ID.
	 * @param mixed $terms      Terms as passed to `wp_set_object_terms()`.
	 * @param mixed $tt_ids     New term taxonomy IDs.
	 * @param mixed $taxonomy   Taxonomy slug.
	 * @param mixed $append     Whether the terms were appended.
	 * @param mixed $old_tt_ids Previous term taxonomy IDs.
	 *
	 * @return mixed The decision; only a strict `true` flushes.
	 */
	public function set_terms( mixed $flush, mixed $object_id, mixed $terms, mixed $tt_ids, mixed $taxonomy, mixed $append, mixed $old_tt_ids ): mixed {
		return Bridge::filter( 'wp_rest_cache/flush_on_set_terms', array( $flush, $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) );
	}

	/**
	 * Fire the pre-delete actions of the original for a queued change, and hand the tags back unchanged.
	 *
	 * Every action fires once per queued change. The bridge is closed while they run, so a callback that queues
	 * another invalidation is not bridged in turn.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $tags    The tags the change invalidates.
	 * @param mixed $event   What changed; the original has no such argument.
	 * @param mixed $context Subject of the change.
	 *
	 * @return mixed The tags, as they were given.
	 */
	public function invalidation_tags( mixed $tags, mixed $event = '', mixed $context = array() ): mixed {
		unset( $event );
		if ( $this->firing || ! is_array( $tags ) ) {
			return $tags;
		}

		$this->firing = true;
		try {
			$this->fire( $tags, is_array( $context ) ? $context : array() );
		} finally {
			$this->firing = false;
		}

		return $tags;
	}

	/**
	 * Filter the capability that guards the admin of the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $capability The capability of the engine.
	 *
	 * @return mixed The capability; the engine falls back to its default for anything but a non-empty string.
	 */
	public function capability( mixed $capability ): mixed {
		return Bridge::filter( 'wp_rest_cache/settings_capability', array( $capability ) );
	}

	/**
	 * Fire the actions of one change: the subject of its context, then the object type of each of its lists.
	 *
	 * @param array<mixed> $tags    Tags of the change.
	 * @param array<mixed> $context Subject of the change.
	 *
	 * @return void
	 */
	private function fire( array $tags, array $context ): void {
		$taxonomy = self::name( $context['taxonomy'] ?? null );

		$post_id   = self::id( $context['post_id'] ?? null );
		$post_type = self::name( $context['post_type'] ?? null );
		if ( $post_id > 0 && '' !== $post_type ) {
			Bridge::action( 'wp_rest_cache/pre_delete_related_caches', array( $post_id, $post_type ) );
		}

		$term_id = self::id( $context['term_id'] ?? null );
		if ( $term_id > 0 && '' !== $taxonomy ) {
			Bridge::action( 'wp_rest_cache/pre_delete_related_caches', array( $term_id, $taxonomy ) );
		}

		$term_ids = $context['term_ids'] ?? null;
		if ( is_array( $term_ids ) && '' !== $taxonomy ) {
			$ids = array_values( array_filter( array_map( static fn( mixed $id ): int => self::id( $id ), $term_ids ), static fn( int $id ): bool => $id > 0 ) );
			if ( array() !== $ids ) {
				Bridge::action( 'wp_rest_cache/pre_delete_related_caches_batch', array( $ids, $taxonomy ) );
			}
		}

		$user_id = self::id( $context['user_id'] ?? null );
		if ( $user_id > 0 ) {
			Bridge::action( 'wp_rest_cache/pre_delete_related_caches', array( $user_id, 'user' ) );
		}

		$comment_id = self::id( $context['comment_id'] ?? null );
		if ( $comment_id > 0 ) {
			Bridge::action( 'wp_rest_cache/pre_delete_related_caches', array( $comment_id, 'comment' ) );
		}

		foreach ( $tags as $tag ) {
			if ( ! is_string( $tag ) || ! str_starts_with( $tag, 'list:' ) || strlen( $tag ) < 6 ) {
				continue;
			}
			Bridge::action( 'wp_rest_cache/pre_delete_object_type_caches', array( self::OBJECT_TYPES[ $tag ] ?? substr( $tag, 5 ) ) );
		}
	}

	/**
	 * The ID a context value holds.
	 *
	 * @param mixed $value Context value.
	 *
	 * @return int The ID, 0 when the value is neither an integer nor a string of digits.
	 */
	private static function id( mixed $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}
		return is_string( $value ) && ctype_digit( $value ) ? (int) $value : 0;
	}

	/**
	 * A name of a context value.
	 *
	 * @param mixed $value Context value.
	 *
	 * @return string The name, `''` when the value is not a string.
	 */
	private static function name( mixed $value ): string {
		return is_string( $value ) ? $value : '';
	}
}
