<?php
/**
 * Tests for the WooCommerce product, variation, stock and sale invalidation listener.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Woo_Product_Events;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Woo_Product_Events
 */
class Test_Woo_Product_Events extends Rfrac_Test_Case {

	use Rfrac_Wc_Test_Helpers;

	/**
	 * Invalidator under test.
	 *
	 * @var Invalidator
	 */
	private Invalidator $inv;

	/**
	 * Spy store.
	 *
	 * @var Rfrac_Spy_Store
	 */
	private Rfrac_Spy_Store $spy;

	/**
	 * Temp file the PHP error log is written to while failures are captured.
	 *
	 * @var string|null
	 */
	private ?string $error_log_file = null;

	/**
	 * Error log setting to restore.
	 *
	 * @var string
	 */
	private string $old_error_log = '';

	public function set_up() {
		parent::set_up();
		$this->reset_wc_state();
		$this->spy = new Rfrac_Spy_Store();
		$this->inv = new Invalidator( $this->spy, static fn( string $url ): ?string => null );
	}

	public function tear_down() {
		if ( null !== $this->error_log_file ) {
			ini_set( 'error_log', $this->old_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			unlink( $this->error_log_file );
			$this->error_log_file = null;
		}
		$this->reset_wc_state();
		parent::tear_down();
	}

	/**
	 * Hook the listener to WordPress. Build the fixtures first, so building them queues nothing.
	 *
	 * @param int $max_fanout Fan-out cap of the listener.
	 *
	 * @return Woo_Product_Events
	 */
	private function arm( int $max_fanout = 200 ): Woo_Product_Events {
		$listener = new Woo_Product_Events( $this->inv, $max_fanout );
		$listener->register();
		$this->assertSame( array(), $this->pending(), 'Nothing is pending before the change under test.' );
		return $listener;
	}

	/**
	 * Pending tags of the current site.
	 *
	 * @return string[]
	 */
	private function pending(): array {
		return $this->inv->pending()[ get_current_blog_id() ] ?? array();
	}

	/**
	 * Assert the pending tags are exactly the given ones.
	 *
	 * @param string[] $expected Tags.
	 * @param string   $message  Failure message.
	 *
	 * @return void
	 */
	private function assert_pending( array $expected, string $message = '' ): void {
		$this->assertEqualsCanonicalizing( $expected, $this->pending(), $message );
	}

	/**
	 * A product that manages its stock.
	 *
	 * @param int $quantity Stock quantity.
	 *
	 * @return WC_Product_Simple
	 */
	private function stocked_product( int $quantity = 10 ): WC_Product_Simple {
		return $this->wc_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => $quantity,
			)
		);
	}

	/**
	 * A variable product with one variation that manages its own stock.
	 *
	 * @param int $quantity Stock quantity of the variation.
	 *
	 * @return array{0:WC_Product_Variable,1:int} The variable product and the ID of the variation.
	 */
	private function stocked_variation( int $quantity = 5 ): array {
		$variable  = $this->wc_variable_product( array( 'small' => '10' ) );
		$variation = wc_get_product( $variable->get_children()[0] );
		$this->assertInstanceOf( WC_Product_Variation::class, $variation );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( $quantity );
		$variation->save();

		return array( $variable, $variation->get_id() );
	}

	/**
	 * Save a change of the regular price of a product.
	 *
	 * @param int    $id    Product or variation ID.
	 * @param string $price New regular price.
	 *
	 * @return void
	 */
	private function change_price( int $id, string $price ): void {
		$product = wc_get_product( $id );
		$this->assertInstanceOf( WC_Product::class, $product );
		$product->set_regular_price( $price );
		$product->save();
	}

	// ---------- Product and variation saves ----------

	public function test_event_names_are_constants_prefixed_wc(): void {
		$events = array();
		foreach ( ( new ReflectionClass( Woo_Product_Events::class ) )->getConstants() as $name => $value ) {
			if ( 0 === strpos( $name, 'EVENT_' ) ) {
				$events[] = $value;
			}
		}

		$this->assertGreaterThanOrEqual( 11, count( $events ) );
		foreach ( $events as $event ) {
			$this->assertIsString( $event );
			$this->assertStringStartsWith( 'wc_', $event );
		}
		$this->assertSame( 'wc_products_saturated', Woo_Product_Events::EVENT_PRODUCTS_SATURATED );
	}

	public function test_every_declared_hook_is_registered(): void {
		$listener = $this->arm();

		foreach ( Woo_Product_Events::hooks() as $hook ) {
			$this->assertSame( $hook[2], has_filter( $hook[0], array( $listener, $hook[1] ) ), $hook[0] );
		}
	}

	public function test_a_new_product_queues_its_tag_and_the_list(): void {
		$this->arm();

		$product = $this->wc_product();

		$this->assert_pending( array( 'post:' . $product->get_id(), 'list:product' ) );
	}

	public function test_meta_only_product_save(): void {
		$product = $this->wc_product();
		$this->arm();

		// A price change writes meta only: it never reaches wp_update_post(), so no core post hook fires.
		$this->change_price( $product->get_id(), '15' );

		$this->assert_pending( array( 'post:' . $product->get_id(), 'list:product' ) );
	}

	public function test_saving_a_variation_queues_it_and_its_parent(): void {
		$variable  = $this->wc_variable_product();
		$variation = $variable->get_children()[0];
		$this->arm();

		$this->change_price( $variation, '12' );

		$this->assert_pending( array( 'post:' . $variation, 'post:' . $variable->get_id(), 'list:product' ) );
	}

	public function test_a_new_variation_queues_it_and_its_parent(): void {
		$variable = $this->wc_variable_product();
		$term     = get_terms(
			array(
				'taxonomy'   => 'pa_size',
				'hide_empty' => false,
				'fields'     => 'slugs',
			)
		);
		$this->assertIsArray( $term );
		$this->arm();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $variable->get_id() );
		$variation->set_attributes( array( 'pa_size' => (string) $term[0] ) );
		$variation->set_regular_price( '30' );
		$variation->save();

		$this->assertContains( 'post:' . $variation->get_id(), $this->pending() );
		$this->assertContains( 'post:' . $variable->get_id(), $this->pending() );
		$this->assertContains( 'list:product', $this->pending() );
	}

	public function test_trashing_a_variation_queues_it_and_its_parent(): void {
		$variable  = $this->wc_variable_product();
		$id        = $variable->get_children()[0];
		$variation = wc_get_product( $id );
		$this->assertInstanceOf( WC_Product_Variation::class, $variation );
		$this->arm();

		$variation->delete();

		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->assert_pending( array( 'post:' . $id, 'post:' . $variable->get_id(), 'list:product' ) );
	}

	public function test_deleting_a_variation_queues_it_and_its_parent(): void {
		$variable  = $this->wc_variable_product();
		$variation = $variable->get_children()[0];
		$this->arm();

		wp_delete_post( $variation, true );

		$this->assert_pending( array( 'post:' . $variation, 'post:' . $variable->get_id(), 'list:product' ) );
	}

	public function test_deleting_another_kind_of_post_queues_nothing(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->arm();

		wp_delete_post( $page, true );

		$this->assert_pending( array() );
	}

	// ---------- Stock ----------

	public function test_a_stock_decrease_queues_only_the_product(): void {
		$product = $this->stocked_product();
		$this->arm();

		wc_update_product_stock( $product, 1, 'decrease' );

		$this->assert_pending( array( 'post:' . $product->get_id() ) );
	}

	public function test_a_stock_update_of_a_variation_queues_it_and_its_parent(): void {
		list( $variable, $variation ) = $this->stocked_variation();
		$this->arm();

		wc_update_product_stock( $variation, 1, 'decrease' );
		$this->assert_pending( array( 'post:' . $variation, 'post:' . $variable->get_id() ), 'With the product saved.' );

		$this->inv->commit();
		wc_update_product_stock( $variation, 1, 'decrease', true );
		$this->assert_pending( array( 'post:' . $variation, 'post:' . $variable->get_id() ), 'With the update left to the caller.' );
	}

	public function test_the_deferred_sync_of_the_parent_queues_no_list_after_a_stock_decrease(): void {
		list( $variable, $variation ) = $this->stocked_variation();
		$this->arm();

		wc_update_product_stock( $variation, 1, 'decrease' );
		WC_Post_Data::do_deferred_product_sync();

		$this->assert_pending( array( 'post:' . $variation, 'post:' . $variable->get_id() ) );
	}

	public function test_the_direct_sql_stock_update_queues_the_product(): void {
		$product = $this->stocked_product();
		$this->arm();

		wc_update_product_stock( $product, 2, 'decrease', true );

		$this->assert_pending( array( 'post:' . $product->get_id() ) );
	}

	public function test_the_stock_updated_action_of_a_variation_looks_up_the_parent(): void {
		list( $variable, $variation ) = $this->stocked_variation();
		$this->arm();

		do_action( 'woocommerce_updated_product_stock', $variation );

		$this->assert_pending( array( 'post:' . $variation, 'post:' . $variable->get_id() ) );
	}

	public function test_a_stock_decrease_to_zero_queues_the_list(): void {
		$product = $this->stocked_product( 1 );
		$this->arm();

		wc_update_product_stock( $product, 1, 'decrease' );

		$decreased = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $decreased );
		$this->assertSame( 'outofstock', $decreased->get_stock_status() );
		$this->assert_pending( array( 'post:' . $product->get_id(), 'list:product' ) );
	}

	public function test_a_variation_selling_out_through_a_stock_decrease_queues_the_list(): void {
		list( $variable, $variation ) = $this->stocked_variation( 1 );
		$this->arm();

		wc_update_product_stock( $variation, 1, 'decrease' );
		WC_Post_Data::do_deferred_product_sync();

		$sold_out = wc_get_product( $variation );
		$this->assertInstanceOf( WC_Product_Variation::class, $sold_out );
		$this->assertSame( 'outofstock', $sold_out->get_stock_status() );
		$this->assert_pending( array( 'post:' . $variation, 'post:' . $variable->get_id(), 'list:product' ) );
	}

	public function test_a_stock_change_saved_with_another_change_queues_the_list(): void {
		$product = $this->stocked_product();
		$this->arm();

		$changed = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $changed );
		$changed->set_stock_quantity( 3 );
		$changed->set_name( 'Renamed' );
		$changed->save();

		$this->assert_pending( array( 'post:' . $product->get_id(), 'list:product' ) );
	}

	public function test_a_price_change_after_a_stock_decrease_queues_the_list(): void {
		$product = $this->stocked_product();
		$this->arm();

		wc_update_product_stock( $product, 1, 'decrease' );
		$this->inv->commit();
		$this->change_price( $product->get_id(), '15' );

		$this->assert_pending( array( 'post:' . $product->get_id(), 'list:product' ) );
	}

	public function test_going_out_of_stock_queues_the_product(): void {
		$product = $this->wc_product();
		$this->arm();

		$changed = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $changed );
		$changed->set_stock_status( 'outofstock' );
		$changed->save();

		$this->assert_pending( array( 'post:' . $product->get_id(), 'list:product' ) );
	}

	public function test_the_stock_status_of_a_variation_queues_it_and_its_parent(): void {
		$variable  = $this->wc_variable_product();
		$variation = $variable->get_children()[0];
		$this->arm();

		$changed = wc_get_product( $variation );
		$this->assertInstanceOf( WC_Product_Variation::class, $changed );
		$changed->set_stock_status( 'outofstock' );
		$changed->save();

		$this->assert_pending( array( 'post:' . $variation, 'post:' . $variable->get_id(), 'list:product' ) );
	}

	public function test_a_stock_reduction_of_an_order_flushes_while_it_runs(): void {
		$products = array( $this->stocked_product(), $this->stocked_product(), $this->stocked_product() );
		$order    = wc_create_order();
		foreach ( $products as $product ) {
			$order->add_product( $product, 1 );
		}
		$order->save();
		add_filter( Hooks::AUTO_COMMIT_THRESHOLD, static fn() => 2 );
		$this->arm();

		wc_reduce_stock_levels( $order->get_id() );

		$flushes = $this->spy->calls_of( 'flush_by_tags' );
		$this->assertNotEmpty( $flushes, 'The store was flushed before shutdown.' );
		$tags = $this->pending();
		foreach ( $flushes as $flush ) {
			$this->assertIsArray( $flush );
			$tags = array_merge( $tags, $flush );
		}
		foreach ( $products as $product ) {
			$this->assertContains( 'post:' . $product->get_id(), $tags );
		}
		$this->assertNotContains( 'list:product', $tags, 'A stock reduction keeps the product lists warm.' );
	}

	// ---------- Sales, visibility, price ----------

	public function test_a_sales_count_update_queues_only_the_popularity_list(): void {
		$product = $this->wc_product();
		$this->arm();

		WC_Data_Store::load( 'product' )->update_product_sales( $product->get_id(), 2, 'increase' );

		$this->assertSame( array( 'list:wc-popularity' ), $this->pending() );
	}

	public function test_reordering_products_queues_only_the_list(): void {
		$a = $this->wc_product();
		$b = $this->wc_product();
		$this->arm();

		// The arguments of WC_AJAX::product_ordering(): the moved product and the new menu order of every product, by ID.
		do_action(
			'woocommerce_after_product_ordering',
			$b->get_id(),
			array(
				$b->get_id() => 1,
				$a->get_id() => 2,
			)
		);

		$this->assertSame( array( 'list:product' ), $this->pending() );
	}

	public function test_the_reorder_event_carries_its_own_name_and_the_moved_product(): void {
		$product = $this->wc_product();
		$seen    = array();
		add_filter(
			Hooks::INVALIDATION_TAGS,
			static function ( $tags, $event, $context ) use ( &$seen ) {
				$seen[] = array( $event, $context );
				return $tags;
			},
			10,
			3
		);
		$this->arm();

		do_action( 'woocommerce_after_product_ordering', $product->get_id(), array( $product->get_id() => 0 ) );

		$this->assertSame( array( array( Woo_Product_Events::EVENT_PRODUCTS_REORDERED, array( 'post_id' => $product->get_id() ) ) ), $seen );
	}

	public function test_a_hidden_catalog_visibility_queues_the_product(): void {
		$product = $this->wc_product();
		$this->arm();

		$changed = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $changed );
		$changed->set_catalog_visibility( 'hidden' );
		$changed->save();

		$this->assert_pending( array( 'post:' . $product->get_id(), 'list:product' ) );
	}

	public function test_the_price_sync_of_a_grouped_product_queues_it(): void {
		$child   = $this->wc_product();
		$grouped = new WC_Product_Grouped();
		$grouped->set_props(
			array(
				'name'   => 'Fixture group',
				'status' => 'publish',
			)
		);
		$grouped->set_children( array( $child->get_id() ) );
		$grouped->save();
		$this->arm();

		WC_Product_Grouped::sync( $grouped, false );

		$this->assert_pending( array( 'post:' . $grouped->get_id(), 'list:product' ) );
	}

	public function test_a_scheduled_sale_of_a_variation_queues_it_and_its_parent(): void {
		$variable  = $this->wc_variable_product();
		$variation = $variable->get_children()[0];
		$this->arm();

		do_action( 'wc_product_start_scheduled_sale', $variation );
		$this->assert_pending( array( 'post:' . $variation, 'post:' . $variable->get_id(), 'list:product' ), 'Start.' );

		$this->inv->commit();
		do_action( 'wc_product_end_scheduled_sale', $variation );
		$this->assert_pending( array( 'post:' . $variation, 'post:' . $variable->get_id(), 'list:product' ), 'End.' );
	}

	public function test_the_sale_actions_of_a_batch_queue_every_product(): void {
		$a = $this->wc_product();
		$b = $this->wc_product();
		$this->arm();

		do_action( 'wc_after_products_ending_sales', array( $a->get_id(), $b->get_id() ) );
		$this->assert_pending( array( 'post:' . $a->get_id(), 'post:' . $b->get_id(), 'list:product' ), 'Ending.' );

		$this->inv->commit();
		do_action( 'wc_after_products_starting_sales', array( $a->get_id(), $b->get_id() ) );
		$this->assert_pending( array( 'post:' . $a->get_id(), 'post:' . $b->get_id(), 'list:product' ), 'Starting.' );
	}

	public function test_the_sale_actions_run_after_the_woocommerce_handlers(): void {
		$listener = $this->arm();

		$this->assertGreaterThan( 10, (int) has_action( 'wc_product_start_scheduled_sale', array( $listener, 'on_sale_started' ) ) );
		$this->assertGreaterThan( 10, (int) has_action( 'wc_product_end_scheduled_sale', array( $listener, 'on_sale_ended' ) ) );
	}

	// ---------- Fan-out cap ----------

	public function test_cap_falls_back_to_catalog_tags(): void {
		$products = array( $this->wc_product(), $this->wc_product(), $this->wc_product(), $this->wc_product() );
		$this->arm( 2 );

		$this->change_price( $products[0]->get_id(), '11' );
		$this->change_price( $products[1]->get_id(), '12' );
		$this->assert_pending( array( 'post:' . $products[0]->get_id(), 'post:' . $products[1]->get_id(), 'list:product' ), 'Within the cap.' );

		$this->change_price( $products[2]->get_id(), '13' );
		$this->assert_pending(
			array( 'post:' . $products[0]->get_id(), 'post:' . $products[1]->get_id(), 'list:product', Store_Routes::PRICE_TAG ),
			'The third product exceeds the cap.'
		);

		$this->inv->commit_filter( null );
		$this->assert_pending( array() );

		$this->change_price( $products[3]->get_id(), '14' );
		$this->assert_pending( array( 'list:product', Store_Routes::PRICE_TAG ), 'Every later product event falls back.' );
	}

	public function test_a_tracked_product_falls_back_too_once_the_cap_is_exceeded(): void {
		$products = array( $this->wc_product(), $this->wc_product(), $this->wc_product() );
		$this->arm( 2 );
		foreach ( $products as $index => $product ) {
			$this->change_price( $product->get_id(), (string) ( 11 + $index ) );
		}
		$this->inv->commit();

		$this->change_price( $products[0]->get_id(), '99' );

		$this->assert_pending( array( 'list:product', Store_Routes::PRICE_TAG ) );
	}

	public function test_a_variation_counts_with_its_parent_towards_the_cap(): void {
		$variable  = $this->wc_variable_product( array( 'small' => '10' ) );
		$variation = $variable->get_children()[0];
		$other     = $this->wc_product();
		$this->arm( 2 );

		$this->change_price( $variation, '12' );
		$this->assert_pending( array( 'post:' . $variation, 'post:' . $variable->get_id(), 'list:product' ), 'The variation and its parent fill the cap.' );

		$this->inv->commit();
		$this->change_price( $other->get_id(), '13' );
		$this->assert_pending( array( 'list:product', Store_Routes::PRICE_TAG ) );
	}

	public function test_a_stock_decrease_past_the_cap_falls_back_to_the_catalog_tags(): void {
		$a = $this->stocked_product();
		$b = $this->stocked_product();
		$this->arm( 1 );

		wc_update_product_stock( $a, 1, 'decrease' );
		$this->assert_pending( array( 'post:' . $a->get_id() ), 'Within the cap.' );

		wc_update_product_stock( $b, 1, 'decrease' );
		$this->assertContains( 'list:product', $this->pending(), 'The second product exceeds the cap.' );
		$this->assertContains( Store_Routes::PRICE_TAG, $this->pending() );
		$this->assertNotContains( 'post:' . $b->get_id(), $this->pending() );
	}

	public function test_the_cap_event_carries_its_own_name(): void {
		$seen = array();
		add_filter(
			Hooks::INVALIDATION_TAGS,
			static function ( $tags, $event ) use ( &$seen ) {
				$seen[] = $event;
				return $tags;
			},
			10,
			2
		);
		$a = $this->wc_product();
		$b = $this->wc_product();
		$this->arm( 1 );

		$this->change_price( $a->get_id(), '11' );
		$this->change_price( $b->get_id(), '12' );

		$this->assertSame( array( Woo_Product_Events::EVENT_PRODUCT_SAVED, Woo_Product_Events::EVENT_PRODUCTS_SATURATED ), array_values( array_unique( $seen ) ) );
	}

	public function test_a_batch_that_crosses_the_cap_queues_what_fits_then_the_catalog_tags(): void {
		$a = $this->wc_product();
		$b = $this->wc_product();
		$c = $this->wc_product();
		$this->arm( 2 );

		do_action( 'wc_after_products_ending_sales', array( $a->get_id(), $b->get_id(), $c->get_id() ) );

		$this->assert_pending( array( 'post:' . $a->get_id(), 'post:' . $b->get_id(), 'list:product', Store_Routes::PRICE_TAG ) );
	}

	public function test_a_sales_count_update_is_not_counted_against_the_cap(): void {
		$a = $this->wc_product();
		$b = $this->wc_product();
		$this->arm( 1 );

		WC_Data_Store::load( 'product' )->update_product_sales( $a->get_id(), 1, 'increase' );
		WC_Data_Store::load( 'product' )->update_product_sales( $b->get_id(), 1, 'increase' );

		$this->assertSame( array( 'list:wc-popularity' ), $this->pending() );
	}

	public function test_reordering_products_is_not_counted_against_the_cap(): void {
		$a = $this->wc_product();
		$b = $this->wc_product();
		$this->arm( 1 );

		do_action( 'woocommerce_after_product_ordering', $a->get_id(), array( $a->get_id() => 1 ) );
		do_action( 'woocommerce_after_product_ordering', $b->get_id(), array( $b->get_id() => 1 ) );
		$this->assertSame( array( 'list:product' ), $this->pending() );

		$this->inv->commit();
		$this->change_price( $a->get_id(), '11' );
		$this->assert_pending( array( 'post:' . $a->get_id(), 'list:product' ), 'The cap is still free for product events.' );
	}

	// ---------- Failure handling ----------

	public function test_a_throwing_tags_listener_is_reported_and_the_save_completes(): void {
		$product = $this->wc_product();
		$failed  = new ArrayObject();
		add_action(
			Hooks::INVALIDATION_FAILED,
			static function ( $error, $context ) use ( $failed ) {
				$failed[] = array( $error, $context );
			},
			10,
			2
		);
		add_filter(
			Hooks::INVALIDATION_TAGS,
			static function () {
				throw new RuntimeException( 'tags listener failed' );
			}
		);
		$this->error_log_file = (string) wp_tempnam( 'rfrac-error-log' );
		$this->old_error_log  = (string) ini_set( 'error_log', $this->error_log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$this->arm();

		$changed = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $changed );
		$changed->set_regular_price( '15' );
		$changed->save();

		$this->assertGreaterThanOrEqual( 1, count( $failed ), 'rfrac/invalidation_failed fired.' );
		$this->assertInstanceOf( RuntimeException::class, $failed[0][0] );
		$this->assertSame( Woo_Product_Events::EVENT_PRODUCT_SAVED, $failed[0][1]['event'] );
		$saved = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $saved );
		$this->assertSame( '15', $saved->get_regular_price(), 'The save completed.' );
	}

	public function test_a_hook_with_an_unusable_argument_queues_nothing(): void {
		$listener = $this->arm();

		$listener->on_stock_updated( 'not-an-id' );
		$listener->on_stock_set( new stdClass() );
		$listener->on_before_stock_set( new stdClass() );
		$listener->on_visibility_set( 0 );
		$listener->on_sale_ended( array() );
		$listener->on_sales_count_updated( null );
		$listener->on_products_reordered( 0 );

		$this->assert_pending( array() );
	}

	// ---------- Wiring ----------

	public function test_the_module_subscribes_the_listener(): void {
		$plugin = new Plugin();
		$plugin->register_integrations();

		$this->assertNotNull( $plugin->woocommerce(), 'WooCommerce is loaded in this suite.' );
		$registered = array_column( $plugin->engine_hooks(), 'hook' );
		foreach ( Woo_Product_Events::hooks() as $hook ) {
			$this->assertContains( $hook[0], $registered, $hook[0] );
		}
	}
}
