<?php
/**
 * Tests of the WooCommerce test harness: the two base test cases, the fixtures and the per-test state reset.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * The end-to-end base: both serving paths, fixtures and the state reset.
 *
 * @coversNothing
 */
class Test_Wc_Harness extends Rfrac_Wc_E2e_Test_Case {

	public function tear_down() {
		unset( $GLOBALS['wp_rest_server'] );

		parent::tear_down();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_store_api_response_is_served_live_then_from_the_cache( string $path ): void {
		$product = $this->wc_product( array( 'regular_price' => '12' ) );
		$route   = $this->store_route( 'products' );
		$this->configure( array(), array( Rfrac_Serving_Harness::route( $route ) ) );
		$h = $this->harness( $path );

		$h->request( '/wp-json' . $route );
		$body = $h->miss( $route );
		$this->assertNotNull( $body, 'The first request is a miss.' );
		$this->assertStringContainsString( '"id":' . $product->get_id(), $body, 'The live body lists the product.' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'The miss stored the response.' );

		$h->request( '/wp-json' . $route );
		$this->assertTrue( $h->lookup(), 'The second request is a hit.' );
		$this->assertSame( $body, $h->output );
		$this->assertNoFailures();
	}

	public function test_the_store_route_is_the_route_key_below_the_namespace(): void {
		$this->assertSame( '/wc/store/v1/products', $this->store_route( 'products' ) );
		$this->assertSame( '/wc/store/v1/products/categories', $this->store_route( '/products/categories' ) );
		$this->assertSame( '/wc/store/products', $this->store_route( 'products', 'wc/store' ) );
	}

	public function test_the_product_fixture_is_a_published_visible_in_stock_simple_product(): void {
		$product = wc_get_product( $this->wc_product()->get_id() );

		$this->assertInstanceOf( WC_Product_Simple::class, $product );
		$this->assertSame( 'Fixture product', $product->get_name() );
		$this->assertSame( '10', $product->get_regular_price() );
		$this->assertSame( 'publish', $product->get_status() );
		$this->assertSame( 'visible', $product->get_catalog_visibility() );
		$this->assertSame( 'instock', $product->get_stock_status() );
	}

	public function test_the_product_fixture_applies_the_properties_over_its_defaults(): void {
		$product = wc_get_product(
			$this->wc_product(
				array(
					'name'          => 'Hat',
					'regular_price' => '25',
					'sale_price'    => '20',
				)
			)->get_id()
		);

		$this->assertSame( 'Hat', $product->get_name() );
		$this->assertSame( '20', $product->get_price() );
		$this->assertTrue( $product->is_on_sale() );
	}

	public function test_the_variable_product_fixture_has_a_variation_per_term_and_a_synced_price_range(): void {
		$product = wc_get_product(
			$this->wc_variable_product(
				array(
					'small' => '10',
					'large' => '20',
				)
			)->get_id()
		);

		$this->assertInstanceOf( WC_Product_Variable::class, $product );
		$this->assertCount( 2, $product->get_children() );
		// WooCommerce formats the variation prices to the shop's price decimals ('10.00'), so they only equal the amounts.
		$this->assertSame( 10.0, (float) $product->get_variation_price( 'min' ) );
		$this->assertSame( 20.0, (float) $product->get_variation_price( 'max' ) );
		$this->assertSame( array( '10', '20' ), get_post_meta( $product->get_id(), '_price', false ), 'The parent holds the synced prices.' );
		$this->assertSame( '10', $product->get_price() );
		$this->assertSame( 'publish', $product->get_status() );
	}

	public function test_the_attribute_fixture_registers_the_taxonomy_and_its_terms(): void {
		$attribute = $this->wc_attribute( 'colour', array( 'red', 'blue' ) );

		$this->assertSame( 'pa_colour', $attribute['taxonomy'] );
		$this->assertTrue( taxonomy_exists( 'pa_colour' ) );
		$this->assertSame( array( 'red', 'blue' ), array_keys( $attribute['term_ids'] ) );
		$this->assertSame( $attribute['term_ids']['red'], get_term_by( 'name', 'red', 'pa_colour' )->term_id );
		$this->assertSame( $attribute['id'], wc_attribute_taxonomy_id_by_name( 'colour' ) );
		$this->assertTrue( taxonomy_is_product_attribute( 'pa_colour' ) );
	}

	/**
	 * @return string
	 */
	public function test_a_test_can_create_an_attribute(): string {
		$this->wc_attribute( 'size', array( 'small', 'large' ) );

		$this->assertTrue( taxonomy_exists( 'pa_size' ) );
		return 'pa_size';
	}

	/**
	 * @depends test_a_test_can_create_an_attribute
	 *
	 * @param string $taxonomy Taxonomy the previous test registered.
	 */
	public function test_the_next_test_starts_without_the_attribute( string $taxonomy ): void {
		$this->assertFalse( taxonomy_exists( $taxonomy ) );
		$this->assertArrayNotHasKey( $taxonomy, (array) $GLOBALS['wc_product_attributes'] );
		$this->assertNotContains( 'size', wp_list_pluck( wc_get_attribute_taxonomies(), 'attribute_name' ) );
	}

	/**
	 * @return string
	 */
	public function test_a_test_can_leave_a_deferred_product_sync(): string {
		$GLOBALS['wc_deferred_product_sync'] = array( 123 );

		$this->assertNotEmpty( $GLOBALS['wc_deferred_product_sync'] );
		return 'left';
	}

	/**
	 * @depends test_a_test_can_leave_a_deferred_product_sync
	 */
	public function test_the_next_test_starts_without_a_deferred_product_sync(): void {
		$this->assertEmpty( $GLOBALS['wc_deferred_product_sync'] );
	}

	public function test_the_reset_clears_the_cart_session_customer_and_deferred_sync(): void {
		$GLOBALS['wc_deferred_product_sync'] = array( 123 );
		WC()->cart                           = new stdClass();
		WC()->session                        = new stdClass();
		WC()->customer                       = new stdClass();

		$this->reset_wc_state();

		$this->assertNull( WC()->cart );
		$this->assertNull( WC()->session );
		$this->assertNull( WC()->customer );
		$this->assertEmpty( $GLOBALS['wc_deferred_product_sync'] );
	}

	/**
	 * @return string
	 */
	public function test_fake_active_plugins_makes_woocommerce_and_the_plugin_active(): string {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->assertFalse( is_plugin_active( 'woocommerce/woocommerce.php' ) );

		$this->fake_active_plugins();

		$this->assertTrue( is_plugin_active( 'woocommerce/woocommerce.php' ) );
		$this->assertTrue( is_plugin_active( Rockfire\Rest_Api_Cache\Plugin::instance()->basename() ) );
		return 'faked';
	}

	/**
	 * @depends test_fake_active_plugins_makes_woocommerce_and_the_plugin_active
	 */
	public function test_the_next_test_does_not_filter_the_active_plugins(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->assertFalse( has_filter( 'pre_option_active_plugins' ) );
		$this->assertFalse( is_plugin_active( 'woocommerce/woocommerce.php' ) );
	}

	public function test_the_reset_removes_the_fake_active_plugins(): void {
		$this->fake_active_plugins();
		$this->fake_active_plugins();

		$this->reset_wc_state();

		$this->assertFalse( has_filter( 'pre_option_active_plugins' ) );
	}

	public function test_hpos_follows_the_ci_leg(): void {
		$this->assertSame( OrderUtil::custom_orders_table_usage_is_enabled(), $this->hpos_enabled() );
	}

	public function test_the_term_fixtures_create_categories_tags_and_brands(): void {
		$parent = $this->wc_category( 'Clothing' );
		$child  = $this->wc_category( 'Hats', $parent );
		$tag    = $this->wc_tag( 'Summer' );
		$brand  = $this->wc_brand( 'Acme' );

		$this->assertSame( 0, get_term( $parent, 'product_cat' )->parent );
		$this->assertSame( $parent, get_term( $child, 'product_cat' )->parent );
		$this->assertSame( 'Summer', get_term( $tag, 'product_tag' )->name );
		$this->assertSame( 'Acme', get_term( $brand, 'product_brand' )->name );
	}

	public function test_the_review_fixture_rates_the_product_when_approved_only(): void {
		$product = $this->wc_product();

		$pending = $this->wc_review( $product->get_id(), 1, 0 );
		$this->assertSame( 0, wc_get_product( $product->get_id() )->get_review_count() );

		$approved = $this->wc_review( $product->get_id(), 4 );
		$fresh    = wc_get_product( $product->get_id() );

		$this->assertNotSame( $pending, $approved );
		$this->assertSame( 4, (int) get_comment_meta( $approved, 'rating', true ) );
		$this->assertSame( 1, $fresh->get_review_count() );
		$this->assertSame( '4.00', $fresh->get_average_rating() );
	}

	public function test_the_tax_rate_fixture_creates_a_rate_with_overrides(): void {
		$id = $this->wc_tax_rate(
			array(
				'tax_rate'      => '21.0000',
				'tax_rate_name' => 'VAT',
			)
		);

		$rate = WC_Tax::_get_tax_rate( $id );
		$this->assertSame( '21.0000', $rate['tax_rate'] );
		$this->assertSame( 'VAT', $rate['tax_rate_name'] );
	}

	public function test_the_tax_rate_fixture_throws_with_the_database_error_when_the_insert_fails(): void {
		global $wpdb;
		// A successful insert leaves its id in the connection, which the failed one must not return.
		$this->wc_tax_rate( array( 'tax_rate_name' => 'First' ) );
		$this->assertNotEmpty( $wpdb->insert_id );

		$table    = $wpdb->prefix . 'woocommerce_tax_rates';
		$suppress = $wpdb->suppress_errors( true );
		$fail     = static fn( $query ) => is_string( $query ) && false !== stripos( $query, 'INSERT INTO `' . $table . '`' ) ? 'RFRAC INVALID SQL' : $query;
		add_filter( 'query', $fail );
		$id    = null;
		$error = null;
		$last  = '';
		try {
			$id = $this->wc_tax_rate( array( 'tax_rate_name' => 'Second' ) );
		} catch ( RuntimeException $e ) {
			$error = $e;
			$last  = $wpdb->last_error;
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertNull( $id, 'No id is returned.' );
		$this->assertInstanceOf( RuntimeException::class, $error, 'The fixture throws.' );
		$this->assertNotSame( '', $last, 'The database reported an error.' );
		$this->assertStringContainsString( 'The tax rate fixture could not be created: ' . $last, $error->getMessage() );
	}
}

/**
 * The invalidation matrix base: the engine hooks armed, a Store API route served and stored.
 *
 * @coversNothing
 */
class Test_Wc_Harness_Matrix extends Rfrac_Wc_Matrix_Test_Case {

	public function test_a_served_store_api_route_is_stored_as_one_entry(): void {
		$this->wc_product();
		$this->arm();
		$this->enable_route( '/wc/store/v1/products' );

		$this->serve( '/wc/store/v1/products' );

		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
	}
}
