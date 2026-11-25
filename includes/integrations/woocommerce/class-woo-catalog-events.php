<?php
/**
 * Maps WooCommerce catalog events to invalidation tags: attribute terms, attributes, reviews and term meta.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Integrations\WooCommerce;

use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Invalidation\Guarded_Trait;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Invalidation\Tags;
use Rockfire\Rest_Api_Cache\Invalidation\Term_Ancestry;

defined( 'ABSPATH' ) || exit;

/**
 * Queues the tags of the Store API responses that show product attributes, their terms, product reviews and the
 * term meta behind recounts, images and ordering.
 *
 * - Terms of a product attribute (`pa_*`): `term:{id}` and `list:{pa_x}`, plus `wc-attribute:{id}` when the term is
 *   created or deleted (the attribute's term list changes).
 * - Attributes (`woocommerce_attribute_*`): `wc-attribute:{id}`, `list:wc-attribute` and the `list:{pa_x}` tags of the
 *   old and the new slug.
 * - Reviews of a product, only while approved (as {@see \Rockfire\Rest_Api_Cache\Invalidation\Comment_Events} treats
 *   comments): `comment:{id}`, `list:wc-review`, `post:{product}` and `list:product`, plus the product's categories and
 *   brands with their ancestors, whose `review_count` includes their children. Beyond the fan-out limit the lists of
 *   both taxonomies are queued instead of the terms.
 * - Term meta (`product_count_*`, `thumbnail_id`, `order`) of product categories, tags, brands and attribute terms:
 *   `term:{id}` and `list:{taxonomy}`.
 *
 * Renames of `product_cat`, `product_tag` and `product_brand` terms are the business of the core `Term_Events`, which
 * already queues their tags, so there is no arm for them here. Handlers run no query beyond cached term, ancestor and
 * attribute lookups. Subscribed through {@see Module::subscribe()}.
 *
 * @since 1.0.0
 */
final class Woo_Catalog_Events {

	use Guarded_Trait;
	use Hook_Subscriber_Trait;

	/**
	 * Event: a product attribute was created (`woocommerce_attribute_added`). Context: `attribute_id`.
	 *
	 * @var string
	 */
	public const EVENT_ATTRIBUTE_ADDED = 'wc_attribute_added';

	/**
	 * Event: a product attribute was updated (`woocommerce_attribute_updated`). Context: `attribute_id`, `old_slug`, `new_slug`.
	 *
	 * @var string
	 */
	public const EVENT_ATTRIBUTE_UPDATED = 'wc_attribute_updated';

	/**
	 * Event: a product attribute was deleted (`woocommerce_attribute_deleted`). Context: `attribute_id`, `taxonomy`.
	 *
	 * @var string
	 */
	public const EVENT_ATTRIBUTE_DELETED = 'wc_attribute_deleted';

	/**
	 * Term meta keys whose changes show in term responses: recounts, the image and the ordering.
	 *
	 * @var list<string>
	 */
	private const TERM_META_KEYS = array(
		'product_count_product_cat',
		'product_count_product_tag',
		'product_count_product_brand',
		'thumbnail_id',
		'order',
	);

	/**
	 * Taxonomies whose term meta is watched, besides the attribute taxonomies (`pa_*`).
	 *
	 * @var list<string>
	 */
	private const TERM_META_TAXONOMIES = array( 'product_cat', 'product_tag', 'product_brand' );

	/**
	 * Taxonomies of a product whose terms show the review count of their products.
	 *
	 * @var list<string>
	 */
	private const REVIEW_TAXONOMIES = array( 'product_cat', 'product_brand' );

	/**
	 * Invalidator.
	 *
	 * @var Invalidator
	 */
	private Invalidator $invalidator;

	/**
	 * Largest number of terms a review may fan out to before the lists of their taxonomies are queued instead.
	 *
	 * @var int
	 */
	private int $max_fanout;

	/**
	 * Ancestor resolver.
	 *
	 * @var Term_Ancestry
	 */
	private Term_Ancestry $ancestry;

	/**
	 * Constructor.
	 *
	 * @param Invalidator $invalidator Invalidator.
	 * @param int         $max_fanout  Largest number of category and brand terms (with ancestors) a review queues by ID.
	 */
	public function __construct( Invalidator $invalidator, int $max_fanout = Woo_Tags::MAX_FANOUT ) {
		$this->invalidator = $invalidator;
		$this->max_fanout  = $max_fanout;
		$this->ancestry    = new Term_Ancestry();
	}

	/**
	 * The hooks: `created_term` (10, 3), `delete_term` (10, 5), `edited_term` (10, 3), `edited_term_taxonomy` (10, 2),
	 * `woocommerce_attribute_added` (10, 2), `woocommerce_attribute_updated` (10, 3), `woocommerce_attribute_deleted` (10, 3),
	 * `wp_insert_comment` (10, 2), `edit_comment` (10, 2), `transition_comment_status` (10, 3) and
	 * `added_term_meta`, `updated_term_meta` and `deleted_term_meta` (10, 4).
	 *
	 * @return array<int,array<int,mixed>>
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		return array(
			array( 'created_term', 'on_created_term', 10, 3 ),
			array( 'delete_term', 'on_delete_term', 10, 5 ),
			array( 'edited_term', 'on_edited_term', 10, 3 ),
			array( 'edited_term_taxonomy', 'on_edited_term_taxonomy', 10, 2 ),
			array( 'woocommerce_attribute_added', 'on_attribute_added', 10, 2 ),
			array( 'woocommerce_attribute_updated', 'on_attribute_updated', 10, 3 ),
			array( 'woocommerce_attribute_deleted', 'on_attribute_deleted', 10, 3 ),
			array( 'wp_insert_comment', 'on_insert_comment', 10, 2 ),
			array( 'edit_comment', 'on_edit_comment', 10, 2 ),
			array( 'transition_comment_status', 'on_transition_comment_status', 10, 3 ),
			array( 'added_term_meta', 'on_term_meta', 10, 4, array( Invalidator::EVENT_META_ADDED ) ),
			array( 'updated_term_meta', 'on_term_meta', 10, 4, array( Invalidator::EVENT_META_UPDATED ) ),
			array( 'deleted_term_meta', 'on_term_meta', 10, 4, array( Invalidator::EVENT_META_DELETED ) ),
		);
	}

	// ---------------------------------------------------------------------------------------
	// Attribute terms.
	// ---------------------------------------------------------------------------------------

	/**
	 * `created_term`: a term was created; queued for a product attribute taxonomy, with its attribute.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return void
	 */
	public function on_created_term( $term_id, $tt_id, $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Hook signature.
		try {
			$this->queue_attribute_term( (int) $term_id, (string) $taxonomy, Invalidator::EVENT_TERM_CREATED, true );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_TERM_CREATED );
		}
	}

	/**
	 * `delete_term`: a term was deleted; queued for a product attribute taxonomy, with its attribute.
	 *
	 * @param int    $term         Term ID.
	 * @param int    $tt_id        Term taxonomy ID (unused).
	 * @param string $taxonomy     Taxonomy slug.
	 * @param mixed  $deleted_term Deleted term object (unused).
	 * @param int[]  $object_ids   IDs of the objects that had the term (unused).
	 *
	 * @return void
	 */
	public function on_delete_term( $term, $tt_id, $taxonomy, $deleted_term = null, $object_ids = array() ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		try {
			$this->queue_attribute_term( (int) $term, (string) $taxonomy, Invalidator::EVENT_TERM_DELETED, true );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_TERM_DELETED );
		}
	}

	/**
	 * `edited_term`: a term was updated; queued for a product attribute taxonomy.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return void
	 */
	public function on_edited_term( $term_id, $tt_id, $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Hook signature.
		try {
			$this->queue_attribute_term( (int) $term_id, (string) $taxonomy, Invalidator::EVENT_TERM_EDITED, false );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_TERM_EDITED );
		}
	}

	/**
	 * `edited_term_taxonomy`: the term taxonomy row (its count) of a term was updated; queued for a product attribute
	 * taxonomy.
	 *
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return void
	 */
	public function on_edited_term_taxonomy( $tt_id, $taxonomy ): void {
		try {
			$taxonomy = (string) $taxonomy;
			if ( ! taxonomy_is_product_attribute( $taxonomy ) ) {
				return;
			}
			$term = get_term_by( 'term_taxonomy_id', (int) $tt_id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				return;
			}
			$this->queue_attribute_term( (int) $term->term_id, $taxonomy, Invalidator::EVENT_TERM_COUNTED, false );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_TERM_COUNTED );
		}
	}

	/**
	 * Queue `term:{id}` and `list:{taxonomy}` of a term of a product attribute taxonomy, and its attribute's tag.
	 *
	 * Does nothing for any other taxonomy.
	 *
	 * @param int    $term_id        Term ID.
	 * @param string $taxonomy       Taxonomy slug.
	 * @param string $event          Event name.
	 * @param bool   $with_attribute Whether to queue `wc-attribute:{id}` too: set when the attribute gained or lost a term.
	 *
	 * @return void
	 */
	private function queue_attribute_term( int $term_id, string $taxonomy, string $event, bool $with_attribute ): void {
		if ( ! taxonomy_is_product_attribute( $taxonomy ) ) {
			return;
		}
		$attribute_tag = $with_attribute ? Woo_Tags::attribute( (int) wc_attribute_taxonomy_id_by_name( $taxonomy ) ) : null;
		$this->invalidator->queue(
			$this->tags( Tags::term( $term_id ), Tags::list_for( $taxonomy ), $attribute_tag ),
			$event,
			array(
				'term_id'  => $term_id,
				'taxonomy' => $taxonomy,
			)
		);
	}

	// ---------------------------------------------------------------------------------------
	// Attributes.
	// ---------------------------------------------------------------------------------------

	/**
	 * `woocommerce_attribute_added`: a product attribute was created.
	 *
	 * @param int                 $id   Attribute ID.
	 * @param array<string,mixed> $data Attribute data (unused).
	 *
	 * @return void
	 */
	public function on_attribute_added( $id, $data = array() ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		try {
			$this->invalidator->queue( $this->tags( Woo_Tags::LIST_ATTRIBUTES ), self::EVENT_ATTRIBUTE_ADDED, array( 'attribute_id' => (int) $id ) );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, self::EVENT_ATTRIBUTE_ADDED );
		}
	}

	/**
	 * `woocommerce_attribute_updated`: a product attribute was updated, possibly with a new slug.
	 *
	 * @param int                 $id       Attribute ID.
	 * @param array<string,mixed> $data     Attribute data, with the new slug as `attribute_name`.
	 * @param string              $old_slug Slug before the update.
	 *
	 * @return void
	 */
	public function on_attribute_updated( $id, $data, $old_slug ): void {
		try {
			$new_slug = isset( $data['attribute_name'] ) && is_string( $data['attribute_name'] ) ? $data['attribute_name'] : '';
			$old_slug = (string) $old_slug;
			$this->invalidator->queue(
				$this->tags(
					Woo_Tags::attribute( (int) $id ),
					Woo_Tags::LIST_ATTRIBUTES,
					Tags::list_for( wc_attribute_taxonomy_name( $old_slug ) ),
					Tags::list_for( wc_attribute_taxonomy_name( $new_slug ) )
				),
				self::EVENT_ATTRIBUTE_UPDATED,
				array(
					'attribute_id' => (int) $id,
					'old_slug'     => $old_slug,
					'new_slug'     => $new_slug,
				)
			);
		} catch ( \Throwable $error ) {
			$this->swallow( $error, self::EVENT_ATTRIBUTE_UPDATED );
		}
	}

	/**
	 * `woocommerce_attribute_deleted`: a product attribute was deleted.
	 *
	 * @param int    $id       Attribute ID.
	 * @param string $name     Attribute name (unused).
	 * @param string $taxonomy Attribute taxonomy name.
	 *
	 * @return void
	 */
	public function on_attribute_deleted( $id, $name, $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Hook signature.
		try {
			$this->invalidator->queue(
				$this->tags( Woo_Tags::attribute( (int) $id ), Woo_Tags::LIST_ATTRIBUTES, Tags::list_for( (string) $taxonomy ) ),
				self::EVENT_ATTRIBUTE_DELETED,
				array(
					'attribute_id' => (int) $id,
					'taxonomy'     => (string) $taxonomy,
				)
			);
		} catch ( \Throwable $error ) {
			$this->swallow( $error, self::EVENT_ATTRIBUTE_DELETED );
		}
	}

	// ---------------------------------------------------------------------------------------
	// Reviews.
	// ---------------------------------------------------------------------------------------

	/**
	 * `wp_insert_comment`: a comment was stored; a review of a product is queued once approved.
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
			$this->queue_review( (int) $comment_id, (string) $comment->comment_type, (int) $comment->comment_post_ID, Invalidator::EVENT_COMMENT_INSERTED );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_COMMENT_INSERTED );
		}
	}

	/**
	 * `edit_comment`: a comment was edited; a review of a product is queued while approved.
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
			$this->queue_review( (int) $comment->comment_ID, (string) $comment->comment_type, (int) $comment->comment_post_ID, Invalidator::EVENT_COMMENT_EDITED );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_COMMENT_EDITED );
		}
	}

	/**
	 * `transition_comment_status`: a comment changed status; a review of a product is queued when it entered or left
	 * the approved state.
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
			$this->queue_review( (int) $comment->comment_ID, (string) $comment->comment_type, (int) $comment->comment_post_ID, Invalidator::EVENT_COMMENT_STATUS_CHANGED );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_COMMENT_STATUS_CHANGED );
		}
	}

	/**
	 * Queue the tags of a review of a product; nothing for a comment on any other post type.
	 *
	 * @param int    $comment_id   Comment ID.
	 * @param string $comment_type Comment type.
	 * @param int    $product_id   ID of the post the comment belongs to.
	 * @param string $event        Event name.
	 *
	 * @return void
	 */
	private function queue_review( int $comment_id, string $comment_type, int $product_id, string $event ): void {
		if ( 'product' !== get_post_type( $product_id ) ) {
			return;
		}
		$this->invalidator->queue(
			$this->tags(
				Tags::comment( $comment_id ),
				Woo_Tags::LIST_REVIEWS,
				Tags::post( $product_id ),
				Woo_Tags::LIST_PRODUCTS,
				...$this->review_term_tags( $product_id )
			),
			$event,
			array(
				'comment_id'   => $comment_id,
				'comment_type' => $comment_type,
				'post_id'      => $product_id,
			)
		);
	}

	/**
	 * Tags of the categories and brands of a product, and of their ancestors: their `review_count` includes the
	 * reviews of their child terms.
	 *
	 * Above the fan-out limit the lists of both taxonomies stand in for the terms.
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return array<int,string|null>
	 */
	private function review_term_tags( int $product_id ): array {
		$term_ids = array();
		foreach ( self::REVIEW_TAXONOMIES as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_the_terms( $product_id, $taxonomy );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			$ids      = array_values( array_map( static fn( \WP_Term $term ): int => (int) $term->term_id, $terms ) );
			$term_ids = array_merge( $term_ids, $ids, $this->ancestry->ancestors( $ids, $taxonomy ) );
		}
		$term_ids = array_values( array_unique( $term_ids ) );

		if ( count( $term_ids ) > $this->max_fanout ) {
			return array_map( array( Tags::class, 'list_for' ), self::REVIEW_TAXONOMIES );
		}
		return array_map( array( Tags::class, 'term' ), $term_ids );
	}

	// ---------------------------------------------------------------------------------------
	// Term meta.
	// ---------------------------------------------------------------------------------------

	/**
	 * `added_term_meta`, `updated_term_meta` and `deleted_term_meta`: meta of a term changed.
	 *
	 * Queues `term:{id}` and `list:{taxonomy}` for the recount, image and order keys of product categories, tags,
	 * brands and attribute terms; every other key and taxonomy is ignored.
	 *
	 * @param string $event      Event name (bound by the hook entry).
	 * @param mixed  $meta_id    Meta ID, or IDs when deleted (unused).
	 * @param int    $term_id    Term ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value (unused).
	 *
	 * @return void
	 */
	public function on_term_meta( string $event, $meta_id, $term_id, $meta_key, $meta_value = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		try {
			$meta_key = (string) $meta_key;
			if ( ! in_array( $meta_key, self::TERM_META_KEYS, true ) ) {
				return;
			}
			$term = get_term( (int) $term_id );
			if ( ! $term instanceof \WP_Term ) {
				return;
			}
			$taxonomy = $term->taxonomy;
			if ( ! in_array( $taxonomy, self::TERM_META_TAXONOMIES, true ) && ! taxonomy_is_product_attribute( $taxonomy ) ) {
				return;
			}
			$this->invalidator->queue(
				$this->tags( Tags::term( (int) $term->term_id ), Tags::list_for( $taxonomy ) ),
				$event,
				array(
					'meta_type' => 'term',
					'meta_key'  => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Context of the invalidation event, not a query argument.
					'object_id' => (int) $term->term_id,
				)
			);
		} catch ( \Throwable $error ) {
			$this->swallow( $error, $event );
		}
	}
}
