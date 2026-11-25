<?php
/**
 * Tests for the WooCommerce catalog invalidation listener: attribute terms, attributes, reviews and term meta.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Woo_Catalog_Events;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Woo_Catalog_Events
 */
class Test_Woo_Catalog_Events extends Rfrac_Test_Case {

	use Rfrac_Wc_Test_Helpers;

	/**
	 * Invalidator under test.
	 *
	 * @var Invalidator
	 */
	private Invalidator $inv;

	public function set_up() {
		parent::set_up();
		$this->reset_wc_state();
		$this->inv = new Invalidator( new Rfrac_Spy_Store(), static fn( string $url ): ?string => null );
	}

	public function tear_down() {
		$this->reset_wc_state();
		parent::tear_down();
	}

	/**
	 * Subscribe the listener under test through the module, as the plugin does. Call it after the fixtures are
	 * built, so their events are not seen.
	 *
	 * @param int $max_fanout Fan-out limit; the default of the class when 0.
	 *
	 * @return void
	 */
	private function listen( int $max_fanout = 0 ): void {
		$events = 0 === $max_fanout ? new Woo_Catalog_Events( $this->inv ) : new Woo_Catalog_Events( $this->inv, $max_fanout );
		$module = new Module(
			Plugin::instance(),
			static function ( string $hook, Closure $callback, int $priority = 10, int $args = 1 ): void {
				add_filter( $hook, $callback, $priority, $args );
			}
		);
		$module->subscribe( $events );
		$this->inv->commit();
	}

	/**
	 * Pending tags of the current site, in ascending order.
	 *
	 * @return string[]
	 */
	private function pending(): array {
		return $this->inv->pending()[ get_current_blog_id() ] ?? array();
	}

	/**
	 * Assert the pending tags are exactly the expected ones.
	 *
	 * @param string[] $expected Expected tags, in any order.
	 * @param string   $message  Failure message.
	 *
	 * @return void
	 */
	private function assert_pending( array $expected, string $message = '' ): void {
		sort( $expected, SORT_STRING );
		$this->assertSame( $expected, $this->pending(), $message );
	}

	/**
	 * Forget what the last changes queued.
	 *
	 * @return void
	 */
	private function settle(): void {
		$this->inv->commit();
	}

	/**
	 * A product in category C2 (child of C1) with brand B.
	 *
	 * @return array{product:int,c1:int,c2:int,brand:int}
	 */
	private function categorised_product(): array {
		$c1      = $this->wc_category( 'Clothing' );
		$c2      = $this->wc_category( 'Shirts', $c1 );
		$brand   = $this->wc_brand( 'Acme' );
		$product = $this->wc_product()->get_id();
		wp_set_object_terms( $product, array( $c2 ), 'product_cat' );
		wp_set_object_terms( $product, array( $brand ), 'product_brand' );

		return array(
			'product' => $product,
			'c1'      => $c1,
			'c2'      => $c2,
			'brand'   => $brand,
		);
	}

	/**
	 * The tags an approved review of the product queues with the terms of the fixture.
	 *
	 * @param int                                        $comment_id Comment ID.
	 * @param array{product:int,c1:int,c2:int,brand:int} $fixture    Fixture of {@see self::categorised_product()}.
	 *
	 * @return string[]
	 */
	private function review_tags( int $comment_id, array $fixture ): array {
		return array(
			'comment:' . $comment_id,
			'list:wc-review',
			'post:' . $fixture['product'],
			'list:product',
			'term:' . $fixture['c2'],
			'term:' . $fixture['c1'],
			'term:' . $fixture['brand'],
		);
	}

	// ---------------------------------------------------------------------------------------
	// Hooks and wiring.
	// ---------------------------------------------------------------------------------------

	public function test_the_hooks_have_the_required_priorities_and_argument_counts(): void {
		$hooks = array();
		foreach ( Woo_Catalog_Events::hooks() as $hook ) {
			$hooks[ $hook[0] ] = array( $hook[2], $hook[3] );
		}

		$this->assertSame(
			array(
				'created_term'                  => array( 10, 3 ),
				'delete_term'                   => array( 10, 5 ),
				'edited_term'                   => array( 10, 3 ),
				'edited_term_taxonomy'          => array( 10, 2 ),
				'woocommerce_attribute_added'   => array( 10, 2 ),
				'woocommerce_attribute_updated' => array( 10, 3 ),
				'woocommerce_attribute_deleted' => array( 10, 3 ),
				'wp_insert_comment'             => array( 10, 2 ),
				'edit_comment'                  => array( 10, 2 ),
				'transition_comment_status'     => array( 10, 3 ),
				'added_term_meta'               => array( 10, 4 ),
				'updated_term_meta'             => array( 10, 4 ),
				'deleted_term_meta'             => array( 10, 4 ),
			),
			$hooks
		);
	}

	public function test_the_module_subscribes_the_listener_and_its_hooks_queue_tags(): void {
		$this->assertTrue( Module::is_active(), 'Precondition: WooCommerce has loaded.' );
		$plugin = new Plugin();
		$plugin->register_integrations();
		$this->assertInstanceOf( Module::class, $plugin->woocommerce() );

		$registered = array_column( $plugin->engine_hooks(), 'hook' );
		foreach ( Woo_Catalog_Events::hooks() as $hook ) {
			$this->assertContains( $hook[0], $registered, $hook[0] . ' is an engine hook of the module' );
		}

		do_action( 'woocommerce_attribute_added', 41, array() );

		$this->assertSame( array( 'list:wc-attribute' ), $plugin->invalidator()->pending()[ get_current_blog_id() ] ?? array() );
	}

	// ---------------------------------------------------------------------------------------
	// Attribute terms.
	// ---------------------------------------------------------------------------------------

	public function test_renaming_an_attribute_term_queues_the_term_and_its_list(): void {
		$attribute = $this->wc_attribute( 'color', array( 'Red' ) );
		$term_id   = $attribute['term_ids']['Red'];
		$this->listen();

		wp_update_term( $term_id, 'pa_color', array( 'name' => 'Crimson' ) );

		$this->assert_pending( array( 'term:' . $term_id, 'list:pa_color' ) );
	}

	public function test_a_recount_of_an_attribute_term_queues_the_term_and_its_list(): void {
		$attribute = $this->wc_attribute( 'color', array( 'Red' ) );
		$term_id   = $attribute['term_ids']['Red'];
		$term      = get_term( $term_id, 'pa_color' );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->listen();

		wp_update_term_count_now( array( (int) $term->term_taxonomy_id ), 'pa_color' );

		$this->assert_pending( array( 'term:' . $term_id, 'list:pa_color' ) );
	}

	public function test_creating_and_deleting_an_attribute_term_queue_the_term_list_and_attribute(): void {
		$attribute = $this->wc_attribute( 'color', array( 'Red' ) );
		$this->listen();

		$created = wp_insert_term( 'Blue', 'pa_color' );
		$this->assertIsArray( $created );
		$term_id = (int) $created['term_id'];
		$this->assert_pending( array( 'term:' . $term_id, 'list:pa_color', 'wc-attribute:' . $attribute['id'] ) );
		$this->settle();

		wp_delete_term( $term_id, 'pa_color' );

		$this->assert_pending( array( 'term:' . $term_id, 'list:pa_color', 'wc-attribute:' . $attribute['id'] ) );
	}

	public function test_terms_of_the_product_taxonomies_are_left_to_the_core_term_listener(): void {
		$category = $this->wc_category( 'Clothing' );
		$tag      = $this->wc_tag( 'Sale' );
		$brand    = $this->wc_brand( 'Acme' );
		$this->listen();

		foreach ( array(
			array( $category, 'product_cat' ),
			array( $tag, 'product_tag' ),
			array( $brand, 'product_brand' ),
		) as $row ) {
			wp_update_term( $row[0], $row[1], array( 'name' => 'Renamed ' . $row[1] ) );
			$created = wp_insert_term( 'Another ' . $row[1], $row[1] );
			$this->assertIsArray( $created );
			wp_delete_term( (int) $created['term_id'], $row[1] );
		}

		$this->assert_pending( array() );
	}

	public function test_terms_of_other_taxonomies_queue_nothing(): void {
		$this->listen();

		$created = wp_insert_term( 'Plain', 'category' );
		$this->assertIsArray( $created );
		wp_update_term( (int) $created['term_id'], 'category', array( 'name' => 'Renamed' ) );
		wp_delete_term( (int) $created['term_id'], 'category' );

		$this->assert_pending( array() );
	}

	// ---------------------------------------------------------------------------------------
	// Attributes.
	// ---------------------------------------------------------------------------------------

	public function test_renaming_an_attribute_queues_the_attribute_and_the_lists(): void {
		$attribute = $this->wc_attribute( 'color', array( 'Red' ) );
		$this->listen();

		$result = wc_update_attribute( $attribute['id'], array( 'name' => 'Colour' ) );

		$this->assertSame( $attribute['id'], $result );
		$this->assert_pending( array( 'wc-attribute:' . $attribute['id'], 'list:wc-attribute', 'list:pa_color' ) );
	}

	public function test_changing_the_slug_of_an_attribute_queues_both_taxonomy_lists(): void {
		$attribute = $this->wc_attribute( 'color', array( 'Red' ) );
		$this->listen();

		$result = wc_update_attribute( $attribute['id'], array( 'slug' => 'shade' ) );

		$this->assertSame( $attribute['id'], $result );
		$this->assert_pending( array( 'wc-attribute:' . $attribute['id'], 'list:wc-attribute', 'list:pa_color', 'list:pa_shade' ) );
	}

	public function test_creating_an_attribute_queues_the_attribute_list(): void {
		$this->listen();

		$id = wc_create_attribute(
			array(
				'name' => 'Size',
				'slug' => 'size',
			)
		);

		$this->assertIsInt( $id );
		$this->assert_pending( array( 'list:wc-attribute' ) );
	}

	public function test_deleting_an_attribute_queues_the_attribute_the_list_and_its_taxonomy(): void {
		$id = wc_create_attribute(
			array(
				'name' => 'Size',
				'slug' => 'size',
			)
		);
		$this->assertIsInt( $id );
		$this->listen();

		$this->assertTrue( wc_delete_attribute( $id ) );

		$this->assert_pending( array( 'wc-attribute:' . $id, 'list:wc-attribute', 'list:pa_size' ) );
	}

	public function test_deleting_an_attribute_with_terms_also_queues_each_term(): void {
		$attribute = $this->wc_attribute( 'color', array( 'Red', 'Blue' ) );
		$this->listen();

		$this->assertTrue( wc_delete_attribute( $attribute['id'] ) );

		$this->assert_pending(
			array(
				'wc-attribute:' . $attribute['id'],
				'list:wc-attribute',
				'list:pa_color',
				'term:' . $attribute['term_ids']['Red'],
				'term:' . $attribute['term_ids']['Blue'],
			)
		);
	}

	// ---------------------------------------------------------------------------------------
	// Reviews.
	// ---------------------------------------------------------------------------------------

	public function test_an_approved_review_queues_the_review_the_product_and_its_terms_with_ancestors(): void {
		$fixture = $this->categorised_product();
		$this->listen();

		$id = $this->wc_review( $fixture['product'], 5 );

		$this->assert_pending( $this->review_tags( $id, $fixture ) );
	}

	public function test_held_and_spam_reviews_queue_nothing_and_approving_a_held_one_queues_everything(): void {
		$fixture = $this->categorised_product();
		$this->listen();

		$spam = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $fixture['product'],
				'comment_type'     => 'review',
				'comment_approved' => 'spam',
			)
		);
		$held = $this->wc_review( $fixture['product'], 4, 0 );
		$this->assert_pending( array() );

		wp_set_comment_status( $held, 'approve' );

		$this->assert_pending( $this->review_tags( $held, $fixture ) );
		$this->assertNotContains( 'comment:' . $spam, $this->pending() );
	}

	public function test_leaving_the_approved_state_queues_the_review_again(): void {
		$fixture = $this->categorised_product();
		$id      = $this->wc_review( $fixture['product'], 5 );
		$this->listen();

		wp_set_comment_status( $id, 'hold' );
		$this->assert_pending( $this->review_tags( $id, $fixture ) );
		$this->settle();

		wp_set_comment_status( $id, 'approve' );
		$this->settle();
		wp_trash_comment( $id );
		$this->assert_pending( $this->review_tags( $id, $fixture ) );
	}

	public function test_editing_an_approved_review_queues_it_and_editing_a_held_one_does_not(): void {
		$fixture  = $this->categorised_product();
		$approved = $this->wc_review( $fixture['product'], 5 );
		$held     = $this->wc_review( $fixture['product'], 3, 0 );
		$this->listen();

		wp_update_comment(
			array(
				'comment_ID'      => $held,
				'comment_content' => 'Edited',
			)
		);
		$this->assert_pending( array() );

		wp_update_comment(
			array(
				'comment_ID'      => $approved,
				'comment_content' => 'Edited',
			)
		);
		$this->assert_pending( $this->review_tags( $approved, $fixture ) );
	}

	public function test_a_comment_on_a_regular_post_queues_nothing(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->listen();

		$id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => 1,
			)
		);
		wp_update_comment(
			array(
				'comment_ID'      => $id,
				'comment_content' => 'Edited',
			)
		);
		wp_set_comment_status( $id, 'hold' );
		wp_set_comment_status( $id, 'approve' );
		wp_delete_comment( $id, true );

		$this->assert_pending( array() );
	}

	public function test_a_review_above_the_fan_out_limit_queues_the_term_lists_instead_of_the_terms(): void {
		$fixture = $this->categorised_product();
		$this->listen( 2 );

		$id = $this->wc_review( $fixture['product'], 5 );

		$this->assert_pending(
			array(
				'comment:' . $id,
				'list:wc-review',
				'post:' . $fixture['product'],
				'list:product',
				'list:product_cat',
				'list:product_brand',
			)
		);
		foreach ( $this->pending() as $tag ) {
			$this->assertStringStartsNotWith( 'term:', $tag );
		}
	}

	public function test_a_review_at_the_fan_out_limit_still_queues_the_terms(): void {
		$fixture = $this->categorised_product();
		$this->listen( 3 );

		$id = $this->wc_review( $fixture['product'], 5 );

		$this->assert_pending( $this->review_tags( $id, $fixture ) );
	}

	public function test_a_review_of_a_product_in_the_default_category_without_a_brand_queues_only_that_term(): void {
		$default = (int) get_option( 'default_product_cat' );
		$this->assertGreaterThan( 0, $default, 'Precondition: WooCommerce created the default category.' );
		$product = $this->wc_product()->get_id();
		$this->listen();

		$id = $this->wc_review( $product, 5 );

		$this->assert_pending( array( 'comment:' . $id, 'list:wc-review', 'post:' . $product, 'list:product', 'term:' . $default ) );
	}

	// ---------------------------------------------------------------------------------------
	// Term meta.
	// ---------------------------------------------------------------------------------------

	public function test_the_recount_image_and_order_meta_of_a_term_queue_the_term_and_its_list(): void {
		$category = $this->wc_category( 'Clothing' );
		$this->listen();

		foreach ( array( 'product_count_product_cat', 'thumbnail_id', 'order' ) as $key ) {
			update_term_meta( $category, $key, 3 );
			$this->assert_pending( array( 'term:' . $category, 'list:product_cat' ), $key );
			$this->settle();
		}
	}

	public function test_added_updated_and_deleted_term_meta_all_queue(): void {
		$category = $this->wc_category( 'Clothing' );
		$this->listen();

		add_term_meta( $category, 'thumbnail_id', 7 );
		$this->assert_pending( array( 'term:' . $category, 'list:product_cat' ), 'added' );
		$this->settle();

		update_term_meta( $category, 'thumbnail_id', 8 );
		$this->assert_pending( array( 'term:' . $category, 'list:product_cat' ), 'updated' );
		$this->settle();

		delete_term_meta( $category, 'thumbnail_id' );
		$this->assert_pending( array( 'term:' . $category, 'list:product_cat' ), 'deleted' );
	}

	public function test_term_meta_of_tags_brands_and_attribute_terms_queues_with_their_own_list(): void {
		$tag       = $this->wc_tag( 'Sale' );
		$brand     = $this->wc_brand( 'Acme' );
		$attribute = $this->wc_attribute( 'color', array( 'Red' ) );
		$red       = $attribute['term_ids']['Red'];
		$this->listen();

		update_term_meta( $tag, 'product_count_product_tag', 2 );
		$this->assert_pending( array( 'term:' . $tag, 'list:product_tag' ), 'tag' );
		$this->settle();

		update_term_meta( $brand, 'product_count_product_brand', 2 );
		$this->assert_pending( array( 'term:' . $brand, 'list:product_brand' ), 'brand' );
		$this->settle();

		update_term_meta( $red, 'order', 2 );
		$this->assert_pending( array( 'term:' . $red, 'list:pa_color' ), 'attribute term' );
	}

	public function test_other_term_meta_keys_queue_nothing(): void {
		$category = $this->wc_category( 'Clothing' );
		$this->listen();

		update_term_meta( $category, 'foo', 1 );
		add_term_meta( $category, 'display_type', 'subcategories' );
		delete_term_meta( $category, 'foo' );

		$this->assert_pending( array() );
	}

	public function test_term_meta_of_other_taxonomies_queues_nothing(): void {
		$term = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$this->listen();

		update_term_meta( $term, 'thumbnail_id', 3 );
		update_term_meta( $term, 'order', 3 );

		$this->assert_pending( array() );
	}

	// ---------------------------------------------------------------------------------------
	// Failures.
	// ---------------------------------------------------------------------------------------

	public function test_a_failing_handler_is_reported_and_never_thrown(): void {
		$failures = array();
		add_action(
			Hooks::INVALIDATION_FAILED,
			static function ( \Throwable $error ) use ( &$failures ): void {
				$failures[] = $error->getMessage();
			}
		);
		add_filter(
			Hooks::INVALIDATION_TAGS,
			static function (): array {
				throw new RuntimeException( 'tag filter failed' );
			}
		);
		$attribute = $this->wc_attribute( 'color', array( 'Red' ) );
		$this->listen();

		// The failures are logged in debug mode: keep them out of the test output.
		$log = (string) wp_tempnam( 'rfrac-error-log' );
		$old = (string) ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		try {
			do_action( 'woocommerce_attribute_added', $attribute['id'], array() );
			wc_update_attribute( $attribute['id'], array( 'name' => 'Colour' ) );
		} finally {
			ini_set( 'error_log', $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
		$logged = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $log );

		$this->assertSame( array( 'tag filter failed', 'tag filter failed' ), $failures );
		$this->assertStringContainsString( 'event: ' . Woo_Catalog_Events::EVENT_ATTRIBUTE_ADDED, $logged );
		$this->assertStringContainsString( 'event: ' . Woo_Catalog_Events::EVENT_ATTRIBUTE_UPDATED, $logged );
		$this->assert_pending( array() );
	}
}
