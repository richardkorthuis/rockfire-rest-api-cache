<?php
/**
 * Maps WooCommerce product, variation, stock and sale events to invalidation tags.
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

defined( 'ABSPATH' ) || exit;

/**
 * Queues `post:{id}` and the parent's `post:` tag whenever a product or variation changes, and `list:product` unless the
 * change is a stock quantity change alone.
 *
 * WooCommerce saves a product through its own CRUD layer: a change to meta only never reaches `wp_update_post()`, so the
 * core `wp_after_insert_post` listener does not see it, and stock and sales counts are written with direct SQL. This
 * listener hooks the WooCommerce actions instead: product and variation create, update, trash and delete, the stock
 * quantity setters and the bracket that opens them, the stock status setters, the direct-SQL stock update, catalog
 * visibility, the price sync of variable and grouped products, scheduled sales and the drag-and-drop reordering of
 * products. A variation always invalidates its parent product too. Products and variations use the core tag
 * vocabulary, so a response that lists or shows one is already tagged with it.
 *
 * A stock quantity change alters the responses that show the product, not which products a list holds, so it queues
 * the product's own `post:` tags and no `list:product`; the list is queued when the stock status changes, as a product
 * going in or out of stock moves in or out of the lists. Without this every order line would keep every product list
 * cold. `wc_update_product_stock()` fires `woocommerce_{product,variation}_before_set_stock`, updates the stock with
 * direct SQL, saves the product and fires `woocommerce_{product,variation}_set_stock`. The first hook marks the product
 * as stock-only; the save in between would queue the list like any save, so a marked product's save consumes the mark
 * and omits the list, and the last hook clears it. A save that changes the stock quantity together with other
 * properties fires the last hook before its save hook, which then queues the list. A variation save defers a sync of
 * its parent to shutdown that updates the parent's price and saves it, so a stock-only variation save marks its parent
 * too, and both omit the list as well. Accepted leak: an unconsumed mark (a stock update that never reaches its save or its last hook)
 * makes at most one later save of that id in the same process omit `list:product`, while its `post:` tag still flushes
 * every list containing it.
 *
 * A sales count change does not alter any response of a single product, only the order of a catalog sorted by
 * popularity, so it queues `list:wc-popularity` alone, a tag only the product lists requested with `orderby=popularity`
 * carry. The same holds for reordering products in wp-admin: it rewrites `menu_order` with direct SQL, which no Store
 * API product response exposes, so only the catalogs sorted by menu order go stale.
 *
 * Fan-out cap: per site, the listener remembers the distinct product and parent ids it has queued in this process.
 * Once one more id would exceed the cap it stops queueing per-product tags and queues the catalog list and the price
 * tag instead, on that event and on every later one in the process (the flush that covers the catalog makes the
 * single tags redundant, and a bulk import must not queue thousands of tags).
 *
 * @since 1.0.0
 */
final class Woo_Product_Events {

	use Guarded_Trait;
	use Hook_Subscriber_Trait;

	/**
	 * Event: a product was created or updated.
	 *
	 * @var string
	 */
	public const EVENT_PRODUCT_SAVED = 'wc_product_saved';

	/**
	 * Event: a variation was created or updated.
	 *
	 * @var string
	 */
	public const EVENT_VARIATION_SAVED = 'wc_variation_saved';

	/**
	 * Event: a variation was trashed.
	 *
	 * @var string
	 */
	public const EVENT_VARIATION_TRASHED = 'wc_variation_trashed';

	/**
	 * Event: a variation was deleted.
	 *
	 * @var string
	 */
	public const EVENT_VARIATION_DELETED = 'wc_variation_deleted';

	/**
	 * Event: the stock quantity of a product or variation changed.
	 *
	 * @var string
	 */
	public const EVENT_STOCK_CHANGED = 'wc_stock_changed';

	/**
	 * Event: the stock status of a product or variation changed.
	 *
	 * @var string
	 */
	public const EVENT_STOCK_STATUS_CHANGED = 'wc_stock_status_changed';

	/**
	 * Event: the catalog visibility of a product changed.
	 *
	 * @var string
	 */
	public const EVENT_VISIBILITY_CHANGED = 'wc_visibility_changed';

	/**
	 * Event: the price of a variable or grouped product was synced from its children.
	 *
	 * @var string
	 */
	public const EVENT_PRICE_UPDATED = 'wc_price_updated';

	/**
	 * Event: the sales count of a product changed.
	 *
	 * @var string
	 */
	public const EVENT_SALES_COUNT_CHANGED = 'wc_sales_count_changed';

	/**
	 * Event: a scheduled sale started.
	 *
	 * @var string
	 */
	public const EVENT_SALE_STARTED = 'wc_sale_started';

	/**
	 * Event: a scheduled sale ended.
	 *
	 * @var string
	 */
	public const EVENT_SALE_ENDED = 'wc_sale_ended';

	/**
	 * Event: products were reordered by drag and drop in wp-admin.
	 *
	 * @var string
	 */
	public const EVENT_PRODUCTS_REORDERED = 'wc_products_reordered';

	/**
	 * Event: the fan-out cap is exceeded, so the catalog tags are queued instead of per-product tags.
	 *
	 * @var string
	 */
	public const EVENT_PRODUCTS_SATURATED = 'wc_products_saturated';

	/**
	 * Invalidator.
	 *
	 * @var Invalidator
	 */
	private Invalidator $invalidator;

	/**
	 * Largest number of distinct product and parent ids queued per site before the catalog tags are queued instead.
	 *
	 * @var int
	 */
	private int $max_fanout;

	/**
	 * Distinct product and parent ids queued in this process, by site: `[ blog_id => [ id => true ] ]`.
	 *
	 * @var array<int,array<int,true>>
	 */
	private array $seen = array();

	/**
	 * Sites whose cap is exceeded in this process: `[ blog_id => true ]`.
	 *
	 * @var array<int,true>
	 */
	private array $saturated = array();

	/**
	 * Products whose stock quantity is changing, by site: `[ blog_id => [ id => true ] ]`. Their next save queues no list.
	 *
	 * @var array<int,array<int,true>>
	 */
	private array $stock_only = array();

	/**
	 * Constructor.
	 *
	 * @param Invalidator $invalidator Invalidator.
	 * @param int         $max_fanout  Largest number of distinct products queued per site and process.
	 */
	public function __construct( Invalidator $invalidator, int $max_fanout = Woo_Tags::MAX_FANOUT ) {
		$this->invalidator = $invalidator;
		$this->max_fanout  = $max_fanout;
	}

	/**
	 * The hooks.
	 *
	 * The product, variation, stock status and `deleted_post` hooks pass the object as their last argument, which
	 * saves a lookup of the parent. The sale hooks run at priority 20, after WooCommerce has applied the sale price.
	 *
	 * @return array<int,array<int,mixed>>
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		return array(
			array( 'woocommerce_new_product', 'on_product_saved', 10, 2 ),
			array( 'woocommerce_update_product', 'on_product_saved', 10, 2 ),
			array( 'woocommerce_new_product_variation', 'on_variation_saved', 10, 2 ),
			array( 'woocommerce_update_product_variation', 'on_variation_saved', 10, 2 ),
			array( 'woocommerce_trash_product_variation', 'on_variation_trashed', 10, 1 ),
			array( 'deleted_post', 'on_deleted_post', 10, 2 ),
			array( 'woocommerce_product_before_set_stock', 'on_before_stock_set', 10, 1 ),
			array( 'woocommerce_variation_before_set_stock', 'on_before_stock_set', 10, 1 ),
			array( 'woocommerce_product_set_stock', 'on_stock_set', 10, 1 ),
			array( 'woocommerce_variation_set_stock', 'on_stock_set', 10, 1 ),
			array( 'woocommerce_product_set_stock_status', 'on_stock_status_set', 10, 3 ),
			array( 'woocommerce_variation_set_stock_status', 'on_stock_status_set', 10, 3 ),
			array( 'woocommerce_updated_product_stock', 'on_stock_updated', 10, 1 ),
			array( 'woocommerce_product_set_visibility', 'on_visibility_set', 10, 2 ),
			array( 'woocommerce_updated_product_price', 'on_price_updated', 10, 1 ),
			array( 'woocommerce_updated_product_sales', 'on_sales_count_updated', 10, 1 ),
			array( 'woocommerce_after_product_ordering', 'on_products_reordered', 10, 1 ),
			array( 'wc_after_products_starting_sales', 'on_sale_started', 10, 1 ),
			array( 'wc_after_products_ending_sales', 'on_sale_ended', 10, 1 ),
			array( 'wc_product_start_scheduled_sale', 'on_sale_started', 20, 1 ),
			array( 'wc_product_end_scheduled_sale', 'on_sale_ended', 20, 1 ),
		);
	}

	/**
	 * `woocommerce_new_product` and `woocommerce_update_product`: a product was saved.
	 *
	 * @param mixed $id      Product ID.
	 * @param mixed $product The product (`WC_Product`).
	 *
	 * @return void
	 */
	public function on_product_saved( mixed $id, mixed $product = null ): void {
		try {
			$id = self::id_of( $id );
			$this->handle( array( $id ), self::EVENT_PRODUCT_SAVED, $product, ! $this->take_stock_only( $id ) );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, self::EVENT_PRODUCT_SAVED );
		}
	}

	/**
	 * `woocommerce_new_product_variation` and `woocommerce_update_product_variation`: a variation was saved.
	 *
	 * @param mixed $id      Variation ID.
	 * @param mixed $product The variation (`WC_Product_Variation`).
	 *
	 * @return void
	 */
	public function on_variation_saved( mixed $id, mixed $product = null ): void {
		try {
			$id         = self::id_of( $id );
			$stock_only = $this->take_stock_only( $id );
			if ( $stock_only ) {
				// The deferred sync of the parent saves it at shutdown; that save is part of the same stock change.
				$this->mark_stock_only( self::parent_of( $id, $product ) );
			}
			$this->handle( array( $id ), self::EVENT_VARIATION_SAVED, $product, ! $stock_only );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, self::EVENT_VARIATION_SAVED );
		}
	}

	/**
	 * `woocommerce_trash_product_variation`: a variation was trashed.
	 *
	 * @param mixed $id Variation ID.
	 *
	 * @return void
	 */
	public function on_variation_trashed( mixed $id ): void {
		$this->handle( array( self::id_of( $id ) ), self::EVENT_VARIATION_TRASHED );
	}

	/**
	 * `deleted_post`: a post was deleted; only a variation matters here (products are covered by the core post listener).
	 *
	 * @param mixed $post_id Post ID.
	 * @param mixed $post    The deleted post (`WP_Post`).
	 *
	 * @return void
	 */
	public function on_deleted_post( mixed $post_id, mixed $post = null ): void {
		$is_variation = $post instanceof \WP_Post && 'product_variation' === $post->post_type;
		$this->handle( $is_variation ? array( self::id_of( $post_id ) ) : array(), self::EVENT_VARIATION_DELETED, $post );
	}

	/**
	 * `woocommerce_product_before_set_stock` and `woocommerce_variation_before_set_stock`: the stock quantity is about to change.
	 *
	 * Marks the product as stock-only, so its next save queues no list.
	 *
	 * @param mixed $product The product or variation (`WC_Product`).
	 *
	 * @return void
	 */
	public function on_before_stock_set( mixed $product ): void {
		try {
			$this->mark_stock_only( self::id_of( $product ) );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, self::EVENT_STOCK_CHANGED );
		}
	}

	/**
	 * `woocommerce_product_set_stock` and `woocommerce_variation_set_stock`: the stock quantity was set.
	 *
	 * Ends the stock-only mark of the product and queues its tags without the list.
	 *
	 * @param mixed $product The product or variation (`WC_Product`).
	 *
	 * @return void
	 */
	public function on_stock_set( mixed $product ): void {
		try {
			$id = self::id_of( $product );
			$this->take_stock_only( $id );
			$this->handle( array( $id ), self::EVENT_STOCK_CHANGED, $product, false );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, self::EVENT_STOCK_CHANGED );
		}
	}

	/**
	 * `woocommerce_product_set_stock_status` and `woocommerce_variation_set_stock_status`: the stock status was set.
	 *
	 * @param mixed $id      Product or variation ID.
	 * @param mixed $status  New stock status (unused: the tags do not depend on it).
	 * @param mixed $product The product or variation (`WC_Product`).
	 *
	 * @return void
	 */
	public function on_stock_status_set( mixed $id, mixed $status = null, mixed $product = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Hook signature.
		$this->handle( array( self::id_of( $id ) ), self::EVENT_STOCK_STATUS_CHANGED, $product );
	}

	/**
	 * `woocommerce_updated_product_stock`: the stock was updated with a direct SQL query.
	 *
	 * Queues the tags of the product without the list.
	 *
	 * @param mixed $id Product or variation ID.
	 *
	 * @return void
	 */
	public function on_stock_updated( mixed $id ): void {
		$this->handle( array( self::id_of( $id ) ), self::EVENT_STOCK_CHANGED, null, false );
	}

	/**
	 * `woocommerce_product_set_visibility`: the catalog visibility changed.
	 *
	 * @param mixed $id Product ID.
	 *
	 * @return void
	 */
	public function on_visibility_set( mixed $id ): void {
		$this->handle( array( self::id_of( $id ) ), self::EVENT_VISIBILITY_CHANGED );
	}

	/**
	 * `woocommerce_updated_product_price`: a variable or grouped product's price was synced from its children.
	 *
	 * The sync of a parent that a stock-only variation save deferred runs this before it saves the parent, so a parent
	 * that is marked stock-only keeps its mark here and queues no list.
	 *
	 * @param mixed $id Product ID.
	 *
	 * @return void
	 */
	public function on_price_updated( mixed $id ): void {
		try {
			$id = self::id_of( $id );
			$this->handle( array( $id ), self::EVENT_PRICE_UPDATED, null, ! $this->is_stock_only( $id ) );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, self::EVENT_PRICE_UPDATED );
		}
	}

	/**
	 * `woocommerce_updated_product_sales`: the sales count was updated with a direct SQL query.
	 *
	 * Queues the popularity list tag only: the count is not part of a single product's public data, it orders the
	 * catalogs sorted by popularity, the only lists that carry that tag. Not counted against the fan-out cap, as no
	 * per-product tag is queued.
	 *
	 * @param mixed $id Product ID.
	 *
	 * @return void
	 */
	public function on_sales_count_updated( mixed $id ): void {
		try {
			$id = self::id_of( $id );
			if ( $id < 1 ) {
				return;
			}
			$this->invalidator->queue( $this->tags( Woo_Tags::LIST_POPULAR ), self::EVENT_SALES_COUNT_CHANGED, array( 'post_id' => $id ) );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, self::EVENT_SALES_COUNT_CHANGED );
		}
	}

	/**
	 * `woocommerce_after_product_ordering`: products were reordered by drag and drop in wp-admin.
	 *
	 * WooCommerce rewrites `menu_order` of every product with direct SQL queries, then fires this action once, with the
	 * moved product and the new order of all products (the second argument, not used here). Queues the catalog list
	 * only: `menu_order` is not part of a single product's public data, it orders catalogs. Not counted against the
	 * fan-out cap, as no per-product tag is queued.
	 *
	 * @param mixed $id ID of the product that was moved.
	 *
	 * @return void
	 */
	public function on_products_reordered( mixed $id ): void {
		try {
			$id = self::id_of( $id );
			if ( $id < 1 ) {
				return;
			}
			$this->invalidator->queue( $this->tags( Woo_Tags::LIST_PRODUCTS ), self::EVENT_PRODUCTS_REORDERED, array( 'post_id' => $id ) );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, self::EVENT_PRODUCTS_REORDERED );
		}
	}

	/**
	 * `wc_after_products_starting_sales` (a list of IDs) and `wc_product_start_scheduled_sale` (one ID): sales started.
	 *
	 * @param mixed $ids Product or variation ID, or a list of them.
	 *
	 * @return void
	 */
	public function on_sale_started( mixed $ids ): void {
		$this->handle( self::ids_of( $ids ), self::EVENT_SALE_STARTED );
	}

	/**
	 * `wc_after_products_ending_sales` (a list of IDs) and `wc_product_end_scheduled_sale` (one ID): sales ended.
	 *
	 * @param mixed $ids Product or variation ID, or a list of them.
	 *
	 * @return void
	 */
	public function on_sale_ended( mixed $ids ): void {
		$this->handle( self::ids_of( $ids ), self::EVENT_SALE_ENDED );
	}

	/**
	 * Queue the tags of products that changed, guarding the hook against any failure.
	 *
	 * @param int[]  $ids       Product and variation IDs; those below 1 are skipped.
	 * @param string $event     Event name.
	 * @param mixed  $subject   The object the hook passed for the single ID, when it did (saves looking up the parent).
	 * @param bool   $with_list Whether to queue the catalog list too; false for a stock quantity change alone.
	 *
	 * @return void
	 */
	private function handle( array $ids, string $event, mixed $subject = null, bool $with_list = true ): void {
		try {
			$this->queue_products( array_values( array_unique( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) ), $event, $subject, $with_list );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, $event );
		}
	}

	/**
	 * Mark a product as stock-only on the current site, so its next save queues no list.
	 *
	 * @param int $id Product or variation ID; those below 1 are skipped.
	 *
	 * @return void
	 */
	private function mark_stock_only( int $id ): void {
		if ( $id > 0 ) {
			$this->stock_only[ (int) get_current_blog_id() ][ $id ] = true;
		}
	}

	/**
	 * Whether a product is marked as stock-only on the current site.
	 *
	 * @param int $id Product or variation ID.
	 *
	 * @return bool
	 */
	private function is_stock_only( int $id ): bool {
		return isset( $this->stock_only[ (int) get_current_blog_id() ][ $id ] );
	}

	/**
	 * Remove the stock-only mark of a product on the current site.
	 *
	 * @param int $id Product or variation ID.
	 *
	 * @return bool Whether the product was marked.
	 */
	private function take_stock_only( int $id ): bool {
		if ( ! $this->is_stock_only( $id ) ) {
			return false;
		}
		unset( $this->stock_only[ (int) get_current_blog_id() ][ $id ] );
		return true;
	}

	/**
	 * Queue the core tag set of each product (its own tag, its parent's tag and, unless left out, the catalog list), up to
	 * the fan-out cap.
	 *
	 * Past the cap the catalog list and the price tag are queued whatever `$with_list` says: the flush that covers the
	 * catalog makes the single tags redundant.
	 *
	 * @param int[]  $ids       Distinct product and variation IDs.
	 * @param string $event     Event name.
	 * @param mixed  $subject   The object the hook passed for the single ID, when it did.
	 * @param bool   $with_list Whether to queue the catalog list too.
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @return void
	 */
	private function queue_products( array $ids, string $event, mixed $subject, bool $with_list = true ): void {
		if ( array() === $ids ) {
			return;
		}

		$blog_id = (int) get_current_blog_id();
		$queued  = array();
		$tags    = array();
		foreach ( $ids as $id ) {
			if ( isset( $this->saturated[ $blog_id ] ) ) {
				break;
			}
			$parent = self::parent_of( $id, $subject );
			$seen   = $this->seen[ $blog_id ] ?? array();
			$fresh  = array();
			foreach ( array( $id, $parent ) as $product_id ) {
				if ( $product_id > 0 && ! isset( $seen[ $product_id ] ) ) {
					$fresh[ $product_id ] = true;
				}
			}
			if ( count( $seen ) + count( $fresh ) > $this->max_fanout ) {
				$this->saturated[ $blog_id ] = true;
				break;
			}
			$this->seen[ $blog_id ] = $seen + $fresh;
			$queued[]               = $id;
			array_push( $tags, ...$this->tags( Tags::post( $id ), Tags::post( $parent ) ) );
		}

		if ( array() !== $queued ) {
			$this->invalidator->queue( $with_list ? array_merge( $tags, $this->tags( Woo_Tags::LIST_PRODUCTS ) ) : $tags, $event, self::context( $queued ) );
		}
		if ( isset( $this->saturated[ $blog_id ] ) ) {
			$this->invalidator->queue(
				$this->tags( Woo_Tags::LIST_PRODUCTS, Store_Routes::PRICE_TAG ),
				self::EVENT_PRODUCTS_SATURATED,
				array_merge( self::context( $ids ), array( 'max_fanout' => $this->max_fanout ) )
			);
		}
	}

	/**
	 * The subject of a change, for the `rfrac/invalidation_tags` filter: `post_id` for one product, `post_ids` for several.
	 *
	 * @param int[] $ids Product and variation IDs.
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @return array<string,mixed>
	 */
	private static function context( array $ids ): array {
		return 1 === count( $ids ) ? array( 'post_id' => $ids[0] ) : array( 'post_ids' => $ids );
	}

	/**
	 * The parent ID of a product or variation: 0 when it has none.
	 *
	 * @param int   $id      Product or variation ID.
	 * @param mixed $subject The object the hook passed, when it did; used only when it is that product.
	 *
	 * @return int
	 */
	private static function parent_of( int $id, mixed $subject ): int {
		if ( $subject instanceof \WC_Product && $subject->get_id() === $id ) {
			return $subject->get_parent_id();
		}
		if ( $subject instanceof \WP_Post && $subject->ID === $id ) {
			return $subject->post_parent;
		}
		return (int) wp_get_post_parent_id( $id );
	}

	/**
	 * The ID a hook argument stands for: a product, a post or an ID.
	 *
	 * @param mixed $value Hook argument.
	 *
	 * @return int 0 when it is none of them.
	 */
	private static function id_of( mixed $value ): int {
		if ( $value instanceof \WC_Product ) {
			return $value->get_id();
		}
		if ( $value instanceof \WP_Post ) {
			return $value->ID;
		}
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * The IDs a hook argument stands for: one ID or a list of them.
	 *
	 * @param mixed $value Hook argument.
	 *
	 * @return array<int,int>
	 */
	private static function ids_of( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array( self::id_of( $value ) );
		}
		return array_map( static fn( mixed $id ): int => self::id_of( $id ), array_values( $value ) );
	}
}
