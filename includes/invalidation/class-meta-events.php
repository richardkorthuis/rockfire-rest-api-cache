<?php
/**
 * Maps meta changes to invalidation tags (opt-in, REST-aware defaults).
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
 * Flushes the object whose meta changed when the key is exposed in REST (or a filter opts in).
 *
 * @since 1.0.0
 */
final class Meta_Events {

	use Guarded_Trait;
	use Hook_Subscriber_Trait;

	/**
	 * Core post meta keys that change public output although they are not registered.
	 *
	 * @var string[]
	 */
	private const CORE_POST_KEYS = array( '_thumbnail_id', '_wp_attachment_metadata', '_wp_attachment_image_alt', '_wp_page_template' );

	/**
	 * Meta types handled.
	 *
	 * @var string[]
	 */
	private const TYPES = array( 'post', 'term', 'user', 'comment' );

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
	 * The hooks: `added_{type}_meta`, `updated_{type}_meta` and `deleted_{type}_meta` (10, 4) for post, term, user
	 * and comment, each bound to {@see self::on_meta_change()} with its type and action.
	 *
	 * @return array<int,array<int,mixed>>
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		$hooks = array();
		foreach ( self::TYPES as $type ) {
			foreach ( array( 'added', 'updated', 'deleted' ) as $action ) {
				$hooks[] = array( "{$action}_{$type}_meta", 'on_meta_change', 10, 4, array( $type, $action ) );
			}
		}
		return $hooks;
	}

	/**
	 * `{action}_{type}_meta`: a meta value was added, updated or deleted.
	 *
	 * @param string $meta_type  Meta type (bound by {@see self::hooks()}).
	 * @param string $action     `added`, `updated` or `deleted` (bound by {@see self::hooks()}).
	 * @param mixed  $meta_id    Meta ID (or IDs).
	 * @param mixed  $object_id  Object ID.
	 * @param mixed  $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 *
	 * @return void
	 */
	public function on_meta_change( string $meta_type, string $action, $meta_id, $object_id, $meta_key, $meta_value = null ): void {
		unset( $meta_id );
		$this->handle( $meta_type, $action, (int) $object_id, (string) $meta_key, $meta_value );
	}

	/**
	 * Decide whether a meta change flushes, and queue the object's tag.
	 *
	 * @param string $meta_type  Meta type.
	 * @param string $action     `added`, `updated` or `deleted`.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 *
	 * @return void
	 */
	private function handle( string $meta_type, string $action, int $object_id, string $meta_key, mixed $meta_value ): void {
		$event = match ( $action ) {
			'added'   => Invalidator::EVENT_META_ADDED,
			'deleted' => Invalidator::EVENT_META_DELETED,
			default   => Invalidator::EVENT_META_UPDATED,
		};
		try {
			if ( $object_id < 1 || '' === $meta_key ) {
				return;
			}
			$default = $this->default_decision( $meta_type, $object_id, $meta_key );
			/**
			 * Filters whether a meta change flushes caches.
			 *
			 * @see Hooks::FLUSH_ON_META_UPDATE
			 */
			$flush = apply_filters( Hooks::FLUSH_ON_META_UPDATE, $default, $meta_type, $object_id, $meta_key, $meta_value, $action ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
			/**
			 * Filters whether a change of one specific meta key flushes caches.
			 *
			 * @see Hooks::FLUSH_ON_META_UPDATE_KEY
			 */
			$flush = apply_filters( "rfrac/flush_on_meta_update/{$meta_type}/{$meta_key}", $flush, $object_id, $meta_value, $action ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
			if ( true !== $flush ) {
				return;
			}

			$tag = $this->tag_for( $meta_type, $object_id );
			if ( null !== $tag ) {
				$this->invalidator->queue(
					array( $tag ),
					$event,
					array(
						'meta_type' => $meta_type,
						'meta_key'  => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Context of the invalidation event, not a query argument.
						'object_id' => $object_id,
					)
				);
			}
		} catch ( \Throwable $error ) {
			$this->swallow( $error, $event );
		}
	}

	/**
	 * Default decision: the key is exposed in REST, or is a core post key that shapes public output.
	 *
	 * @param string $meta_type Meta type.
	 * @param int    $object_id Object ID.
	 * @param string $meta_key  Meta key.
	 *
	 * @return bool
	 */
	private function default_decision( string $meta_type, int $object_id, string $meta_key ): bool {
		if ( 'post' === $meta_type && in_array( $meta_key, self::CORE_POST_KEYS, true ) ) {
			return true;
		}
		$subtype = (string) get_object_subtype( $meta_type, $object_id );
		$keys    = get_registered_meta_keys( $meta_type, $subtype );
		if ( isset( $keys[ $meta_key ] ) && ! empty( $keys[ $meta_key ]['show_in_rest'] ) ) {
			return true;
		}
		if ( '' !== $subtype ) {
			$keys = get_registered_meta_keys( $meta_type, '' );
			return isset( $keys[ $meta_key ] ) && ! empty( $keys[ $meta_key ]['show_in_rest'] );
		}
		return false;
	}

	/**
	 * The tag of the object whose meta changed, or null when the object does not affect cached output.
	 *
	 * @param string $meta_type Meta type.
	 * @param int    $object_id Object ID.
	 *
	 * @return string|null
	 */
	private function tag_for( string $meta_type, int $object_id ): ?string {
		switch ( $meta_type ) {
			case 'post':
				$post = get_post( $object_id );
				if ( $post instanceof \WP_Post && $this->relevance->post_type( $post->post_type ) && $this->relevance->post_is_public( $post ) ) {
					return Tags::post( $object_id );
				}
				return null;
			case 'term':
				$term = get_term( $object_id );
				if ( $term instanceof \WP_Term && $this->relevance->taxonomy( $term->taxonomy ) ) {
					return Tags::term( $object_id );
				}
				return null;
			case 'user':
				return Tags::user( $object_id );
			case 'comment':
				$comment = get_comment( $object_id );
				if ( $comment instanceof \WP_Comment && '1' === (string) $comment->comment_approved ) {
					return Tags::comment( $object_id );
				}
				return null;
		}
		return null;
	}
}
