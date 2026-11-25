<?php
/**
 * End-to-end invalidation matrix of the WooCommerce product side: real cached Store API responses, real product
 * changes, real flushes.
 *
 * Every row serves anonymous catalog requests through the REST server so the production capture stores entries with
 * the tags the Store API tagger collects, changes products through WooCommerce APIs, commits the invalidator and
 * asserts which entries were flushed and that every other entry is still active. Each row runs against the database
 * store and the object-cache store, in both HPOS legs of the suite.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes;

/**
 * @coversNothing
 */
class Test_Woo_Product_Matrix extends Rfrac_Wc_Matrix_Test_Case {

	private const PRODUCTS   = '/wc/store/v1/products';
	private const COLLECTION = '/wc/store/v1/products/collection-data';
	private const CATEGORIES = '/wc/store/v1/products/categories';
	private const POPULAR    = '/wc/store/v1/products?orderby=popularity';

	/**
	 * Data provider: every row of the matrix on every store.
	 *
	 * @return array<string,array{0:string,1:string}> Case name => row method suffix, store.
	 */
	public static function product_matrix(): array {
		$rows  = array(
			'P1 CRUD name edit'                => 'p1_name_edit',
			'P2 meta-only price change'        => 'p2_meta_only_price_change',
			'P3 variation price change'        => 'p3_variation_price_change',
			'P4 variation created'             => 'p4_variation_created',
			'P4 variation trashed'             => 'p4_variation_trashed',
			'P4 variation deleted'             => 'p4_variation_deleted',
			'P5 stock decrease'                => 'p5_stock_decrease',
			'P5 stock decrease while updating' => 'p5_stock_decrease_while_updating',
			'P6 out of stock'                  => 'p6_out_of_stock',
			'P7 order stock reduction'         => 'p7_order_stock_reduction',
			'P8 sales count increase'          => 'p8_sales_count_increase',
			'P9 hidden catalog visibility'     => 'p9_hidden_catalog_visibility',
			'P10 grouped child price change'   => 'p10_grouped_child_price_change',
			'P11 scheduled sale started'       => 'p11_scheduled_sale_started',
			'P12 scheduled sale ended'         => 'p12_scheduled_sale_ended',
			'P13 variable product sync'        => 'p13_variable_product_sync',
			'P14 save leaves other products'   => 'p14_save_leaves_other_products',
			'P15 gallery image alt text'       => 'p15_gallery_image_alt_text',
		);
		$cases = array();
		foreach ( $rows as $name => $row ) {
			foreach ( self::stores() as $store_name => $arguments ) {
				$cases[ $name . ' / ' . $store_name ] = array( $row, $arguments[0] );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider product_matrix
	 *
	 * @param string $row   Row method suffix.
	 * @param string $store Store of the run.
	 */
	public function test_product_matrix( string $row, string $store ): void {
		$method = 'row_' . $row;
		$this->assertTrue( method_exists( $this, $method ), 'The row is implemented: ' . $method );
		$this->$method( $store );
	}

	// ---------- Helpers ----------

	/**
	 * Enable the public Store API catalog routes of the current Store API namespace, like the catalog preset does.
	 *
	 * @return void
	 */
	private function enable_catalog_routes(): void {
		foreach ( Store_Routes::CATALOG as $route ) {
			if ( str_starts_with( $route, '/wc/store/v1/' ) ) {
				$this->enable_route( $route );
			}
		}
	}

	/**
	 * The Store API URI of a product by id.
	 *
	 * @param int $id Product or variation id.
	 *
	 * @return string
	 */
	private function by_id( int $id ): string {
		return self::PRODUCTS . '/' . $id;
	}

	/**
	 * The Store API URI of the product list that holds one product, which only a change of that product or a change of
	 * which products a list holds can flush.
	 *
	 * @param int $id Product id.
	 *
	 * @return string
	 */
	private function including( int $id ): string {
		return self::PRODUCTS . '?include=' . $id;
	}

	/**
	 * The Store API URI of a product by slug.
	 *
	 * @param int $id Product id.
	 *
	 * @return string
	 */
	private function by_slug( int $id ): string {
		$slug = (string) get_post_field( 'post_name', $id );
		$this->assertNotSame( '', $slug, 'The product has a slug.' );
		return self::PRODUCTS . '/' . $slug;
	}

	/**
	 * Load a product or variation by id for a change.
	 *
	 * @param int $id Product or variation id.
	 *
	 * @return WC_Product
	 */
	private function product( int $id ): WC_Product {
		$product = wc_get_product( $id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}

	/**
	 * A variable product with two variations and the id of its first variation.
	 *
	 * @return array{0:int,1:int} The variable product id and the variation id.
	 */
	private function variable_product_and_variation(): array {
		$variable = $this->wc_variable_product();
		$children = $variable->get_children();
		$this->assertCount( 2, $children, 'The variable product has two variations.' );
		return array( $variable->get_id(), (int) $children[0] );
	}

	/**
	 * A simple product that manages its stock.
	 *
	 * @param string $name Product name.
	 *
	 * @return WC_Product_Simple
	 */
	private function stocked_product( string $name = 'Stocked' ): WC_Product_Simple {
		return $this->wc_product(
			array(
				'name'           => $name,
				'manage_stock'   => true,
				'stock_quantity' => 10,
			)
		);
	}

	/**
	 * Serve the responses, apply the change and assert exactly the given ones are flushed.
	 *
	 * The fixtures are built by the caller before this runs, so building them queued nothing. The responses are
	 * served after the engine hooks are armed, so each is stored with the tags the Store API tagger collects.
	 *
	 * @param string   $store   Store of the run.
	 * @param string[] $flushed URIs expected to be flushed.
	 * @param string[] $kept    URIs served as well that must stay active.
	 * @param callable $change  The change, using WooCommerce APIs.
	 * @param string   $tag     The tag expected to flush them, for the failure message.
	 *
	 * @return void
	 */
	private function assert_change_flushes( string $store, array $flushed, array $kept, callable $change, string $tag ): void {
		$this->enable_catalog_routes();
		$this->arm( $store );

		$uris = array_merge( $flushed, $kept );
		foreach ( $uris as $uri ) {
			$this->serve( $uri );
		}
		$this->assert_active( $uris );

		$this->change_and_commit( $change );

		$this->assert_only_flushed( $flushed, $tag );
	}

	/**
	 * A change of a product flushes the list, the product by id and by slug and the collection data of the shop, and
	 * not the category list that shows the product's category.
	 *
	 * @param string   $store  Store of the run.
	 * @param callable $change Changes the product: `fn( WC_Product ): void`; the product is saved afterwards.
	 *
	 * @return void
	 */
	private function assert_catalog_change_flushes_the_product_responses( string $store, callable $change ): void {
		$category = $this->wc_category( 'Clothing' );
		$id       = $this->wc_product(
			array(
				'name'         => 'Before',
				'category_ids' => array( $category ),
			)
		)->get_id();

		$this->assert_change_flushes(
			$store,
			array( self::PRODUCTS, $this->by_id( $id ), $this->by_slug( $id ), self::COLLECTION ),
			array( self::CATEGORIES ),
			function () use ( $id, $change ): void {
				$product = $this->product( $id );
				$change( $product );
				$product->save();
			},
			'post:' . $id
		);
	}

	// ---------- Rows ----------

	/**
	 * P1: a CRUD name edit of a product.
	 *
	 * @param string $store Store of the run.
	 *
	 * @return void
	 */
	private function row_p1_name_edit( string $store ): void {
		$this->assert_catalog_change_flushes_the_product_responses(
			$store,
			static function ( WC_Product $product ): void {
				$product->set_name( 'After' );
			}
		);
	}

	/**
	 * P2: a price change that writes meta only.
	 *
	 * @param string $store Store of the run.
	 *
	 * @return void
	 */
	private function row_p2_meta_only_price_change( string $store ): void {
		$this->assert_catalog_change_flushes_the_product_responses(
			$store,
			static function ( WC_Product $product ): void {
				$product->set_regular_price( '15' );
			}
		);
	}

	/**
	 * P3: a price change of a variation flushes the variation, its parent and the list.
	 *
	 * @param string $store Store of the run.
	 *
	 * @return void
	 */
	private function row_p3_variation_price_change( string $store ): void {
		list( $variable, $variation ) = $this->variable_product_and_variation();
		$other                        = $this->wc_product( array( 'name' => 'Other' ) )->get_id();

		$this->assert_change_flushes(
			$store,
			array( $this->by_id( $variable ), $this->by_id( $variation ), self::PRODUCTS ),
			array( $this->by_id( $other ) ),
			function () use ( $variation ): void {
				$changed = $this->product( $variation );
				$changed->set_regular_price( '12' );
				$changed->save();
			},
			'post:' . $variation
		);
	}

	/**
	 * P4: a variation is created.
	 *
	 * @param string $store Store of the run.
	 *
	 * @return void
	 */
	private function row_p4_variation_created( string $store ): void {
		list( $variable ) = $this->variable_product_and_variation();
		$attribute        = $this->wc_attribute( 'size', array( 'medium' ) );
		$other            = $this->wc_product( array( 'name' => 'Other' ) )->get_id();

		$this->assert_change_flushes(
			$store,
			array( $this->by_id( $variable ), self::PRODUCTS ),
			array( $this->by_id( $other ) ),
			static function () use ( $variable, $attribute ): void {
				$created = new WC_Product_Variation();
				$created->set_parent_id( $variable );
				$created->set_attributes( array( $attribute['taxonomy'] => 'medium' ) );
				$created->set_regular_price( '30' );
				$created->set_status( 'publish' );
				$created->save();
			},
			'post:' . $variable
		);
	}

	/**
	 * P4: a variation is trashed.
	 *
	 * @param string $store Store of the run.
	 *
	 * @return void
	 */
	private function row_p4_variation_trashed( string $store ): void {
		list( $variable, $variation ) = $this->variable_product_and_variation();
		$other                        = $this->wc_product( array( 'name' => 'Other' ) )->get_id();

		$this->assert_change_flushes(
			$store,
			array( $this->by_id( $variable ), self::PRODUCTS ),
			array( $this->by_id( $other ) ),
			function () use ( $variation ): void {
				$this->product( $variation )->delete();
			},
			'post:' . $variable
		);
		$this->assertSame( 'trash', get_post_status( $variation ), 'The variation is in the trash.' );
	}

	/**
	 * P4: a variation is deleted.
	 *
	 * @param string $store Store of the run.
	 *
	 * @return void
	 */
	private function row_p4_variation_deleted( string $store ): void {
		list( $variable, $variation ) = $this->variable_product_and_variation();
		$other                        = $this->wc_product( array( 'name' => 'Other' ) )->get_id();

		$this->assert_change_flushes(
			$store,
			array( $this->by_id( $variable ), self::PRODUCTS ),
			array( $this->by_id( $other ) ),
			function () use ( $variation ): void {
				$this->product( $variation )->delete( true );
			},
			'post:' . $variable
		);
		$this->assertNull( get_post( $variation ), 'The variation is gone.' );
	}

	/**
	 * P5: the stock of a variation decreases, which flushes the variation, its parent and the product list that holds the
	 * parent, and no other product's responses.
	 *
	 * @param string $store    Store of the run.
	 * @param bool   $updating Whether the caller is in the middle of an update, which leaves the save to it.
	 *
	 * @return void
	 */
	private function assert_variation_stock_decrease_flushes( string $store, bool $updating ): void {
		list( $variable, $variation ) = $this->variable_product_and_variation();
		$stocked                      = $this->product( $variation );
		$stocked->set_manage_stock( true );
		$stocked->set_stock_quantity( 5 );
		$stocked->save();
		$other = $this->wc_product( array( 'name' => 'Other' ) )->get_id();

		$this->assert_change_flushes(
			$store,
			array( $this->by_id( $variation ), $this->by_id( $variable ), $this->including( $variable ) ),
			array( $this->by_id( $other ), $this->including( $other ) ),
			function () use ( $variation, $updating ): void {
				$this->assertNotFalse( wc_update_product_stock( $variation, 1, 'decrease', $updating ), 'The stock was decreased.' );
			},
			'post:' . $variation
		);
	}

	/**
	 * P5: `wc_update_product_stock()` decreases the stock of a variation.
	 *
	 * @param string $store Store of the run.
	 *
	 * @return void
	 */
	private function row_p5_stock_decrease( string $store ): void {
		$this->assert_variation_stock_decrease_flushes( $store, false );
	}

	/**
	 * P5: `wc_update_product_stock()` decreases the stock of a variation with `$updating` set.
	 *
	 * @param string $store Store of the run.
	 *
	 * @return void
	 */
	private function row_p5_stock_decrease_while_updating( string $store ): void {
		$this->assert_variation_stock_decrease_flushes( $store, true );
	}

	/**
	 * P6: a product becomes out of stock.
	 *
	 * @param string $store Store of the run.
	 *
	 * @return void
	 */
	private function row_p6_out_of_stock( string $store ): void {
		$id    = $this->wc_product()->get_id();
		$other = $this->wc_product( array( 'name' => 'Other' ) )->get_id();

		$this->assert_change_flushes(
			$store,
			array( $this->by_id( $id ), self::PRODUCTS ),
			array( $this->by_id( $other ) ),
			function () use ( $id ): void {
				$product = $this->product( $id );
				$product->set_stock_status( 'outofstock' );
				$product->save();
			},
			'post:' . $id
		);
	}

	/**
	 * P7: the stock levels of an order with three lines are reduced, which flushes each line's product and the product
	 * list that holds it, and no other product's responses.
	 *
	 * @param string $store Store of the run.
	 *
	 * @return void
	 */
	private function row_p7_order_stock_reduction( string $store ): void {
		$ids   = array(
			$this->stocked_product( 'First' )->get_id(),
			$this->stocked_product( 'Second' )->get_id(),
			$this->stocked_product( 'Third' )->get_id(),
		);
		$order = wc_create_order();
		foreach ( $ids as $id ) {
			$order->add_product( $this->product( $id ), 1 );
		}
		$order->save();
		$this->assertCount( 3, $order->get_items(), 'The order has three lines.' );
		$other = $this->stocked_product( 'Other' )->get_id();

		$this->assert_change_flushes(
			$store,
			array_merge(
				array_map( fn( int $id ): string => $this->by_id( $id ), $ids ),
				array_map( fn( int $id ): string => $this->including( $id ), $ids )
			),
			array( $this->by_id( $other ), $this->including( $other ) ),
			static function () use ( $order ): void {
				wc_reduce_stock_levels( $order->get_id() );
			},
			'post:' . $ids[0]
		);
		foreach ( $ids as $id ) {
			$this->assertSame( 9, $this->product( $id )->get_stock_quantity(), 'The stock of every line was reduced.' );
		}
	}

	/**
	 * P8: the sales count of a product increases; only the list sorted by popularity goes stale.
	 *
	 * @param string $store Store of the run.
	 *
	 * @return void
	 */
	private function row_p8_sales_count_increase( string $store ): void {
		$id    = $this->wc_product()->get_id();
		$other = $this->wc_product( array( 'name' => 'Other' ) )->get_id();

		$this->assert_change_flushes(
			$store,
			array( self::POPULAR ),
			array( self::PRODUCTS, $this->by_id( $other ) ),
			static function () use ( $id ): void {
				WC_Data_Store::load( 'product' )->update_product_sales( $id, 2, 'increase' );
			},
			'list:wc-popularity'
		);
	}

	/**
	 * P9: the catalog visibility of a product is set to hidden.
	 *
	 * @param string $store Store of the run.
	 *
	 * @return void
	 */
	private function row_p9_hidden_catalog_visibility( string $store ): void {
		$id    = $this->wc_product()->get_id();
		$other = $this->wc_product( array( 'name' => 'Other' ) )->get_id();

		$this->assert_change_flushes(
			$store,
			array( $this->by_id( $id ), self::PRODUCTS ),
			array( $this->by_id( $other ) ),
			function () use ( $id ): void {
				$product = $this->product( $id );
				$product->set_catalog_visibility( 'hidden' );
				$product->save();
			},
			'post:' . $id
		);
	}

	/**
	 * P10: the price of a child of a grouped product changes.
	 *
	 * @param string $store Store of the run.
	 *
	 * @return void
	 */
	private function row_p10_grouped_child_price_change( string $store ): void {
		$child_a = $this->wc_product( array( 'name' => 'Child A' ) )->get_id();
		$child_b = $this->wc_product( array( 'name' => 'Child B' ) )->get_id();
		$grouped = new WC_Product_Grouped();
		$grouped->set_props(
			array(
				'name'               => 'Group',
				'status'             => 'publish',
				'catalog_visibility' => 'visible',
			)
		);
		$grouped->set_children( array( $child_a, $child_b ) );
		$grouped->save();
		$other = $this->wc_product( array( 'name' => 'Other' ) )->get_id();

		$this->assert_change_flushes(
			$store,
			array( $this->by_id( $grouped->get_id() ) ),
			array( $this->by_id( $other ) ),
			function () use ( $child_a ): void {
				$child = $this->product( $child_a );
				$child->set_regular_price( '12' );
				$child->save();
			},
			'post:' . $child_a
		);
	}

	/**
	 * P11: a scheduled sale starts on a variation.
	 *
	 * @param string $store Store of the run.
	 *
	 * @return void
	 */
	private function row_p11_scheduled_sale_started( string $store ): void {
		list( $variable, $variation ) = $this->variable_product_and_variation();
		$other                        = $this->wc_product( array( 'name' => 'Other' ) )->get_id();

		$this->assert_change_flushes(
			$store,
			array( $this->by_id( $variation ), $this->by_id( $variable ), self::PRODUCTS ),
			array( $this->by_id( $other ) ),
			static function () use ( $variation ): void {
				do_action( 'wc_product_start_scheduled_sale', $variation );
			},
			'post:' . $variation
		);
	}

	/**
	 * P12: `wc_scheduled_sales()` ends the sale of a product.
	 *
	 * @param string $store Store of the run.
	 *
	 * @return void
	 */
	private function row_p12_scheduled_sale_ended( string $store ): void {
		$id = $this->wc_product(
			array(
				'regular_price'     => '10',
				'sale_price'        => '5',
				'date_on_sale_from' => time() - DAY_IN_SECONDS,
				'date_on_sale_to'   => time() + DAY_IN_SECONDS,
			)
		)->get_id();
		// Let the sale window run out, so the scheduled job finds the product with its sale price still in place.
		update_post_meta( $id, '_sale_price_dates_to', (string) ( time() - HOUR_IN_SECONDS ) );
		$other = $this->wc_product( array( 'name' => 'Other' ) )->get_id();

		$this->assert_change_flushes(
			$store,
			array( $this->by_id( $id ), self::PRODUCTS ),
			array( $this->by_id( $other ) ),
			static function (): void {
				wc_scheduled_sales();
			},
			'post:' . $id
		);
		$this->assertSame( '10', $this->product( $id )->get_price(), 'The sale of the product has ended.' );
	}

	/**
	 * P13: `WC_Product_Variable::sync()` syncs a variable product.
	 *
	 * @param string $store Store of the run.
	 *
	 * @return void
	 */
	private function row_p13_variable_product_sync( string $store ): void {
		list( $variable ) = $this->variable_product_and_variation();
		$other            = $this->wc_product( array( 'name' => 'Other' ) )->get_id();

		$this->assert_change_flushes(
			$store,
			array( $this->by_id( $variable ) ),
			array( $this->by_id( $other ) ),
			static function () use ( $variable ): void {
				WC_Product_Variable::sync( $variable );
			},
			'post:' . $variable
		);
	}

	/**
	 * P14: a save of a product leaves the responses of another product active.
	 *
	 * @param string $store Store of the run.
	 *
	 * @return void
	 */
	private function row_p14_save_leaves_other_products( string $store ): void {
		$category = $this->wc_category( 'Clothing' );
		$id       = $this->wc_product(
			array(
				'name'         => 'Saved',
				'category_ids' => array( $category ),
			)
		)->get_id();
		$other    = $this->wc_product(
			array(
				'name'         => 'Other',
				'category_ids' => array( $category ),
			)
		)->get_id();

		$this->assert_change_flushes(
			$store,
			array( $this->by_id( $id ) ),
			array( $this->by_id( $other ) ),
			function () use ( $id ): void {
				$product = $this->product( $id );
				$product->set_name( 'Saved again' );
				$product->save();
			},
			'post:' . $id
		);
	}

	/**
	 * P15: the alt text of a gallery image of a product is edited.
	 *
	 * @param string $store Store of the run.
	 *
	 * @return void
	 */
	private function row_p15_gallery_image_alt_text( string $store ): void {
		$image = (int) $this->factory()->attachment->create_object(
			array(
				'file'           => 'gallery.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Gallery image',
			)
		);
		$id    = $this->wc_product( array( 'gallery_image_ids' => array( $image ) ) )->get_id();
		$other = $this->wc_product( array( 'name' => 'Other' ) )->get_id();

		$this->assert_change_flushes(
			$store,
			array( $this->by_id( $id ) ),
			array( $this->by_id( $other ) ),
			static function () use ( $image ): void {
				update_post_meta( $image, '_wp_attachment_image_alt', 'A description' );
			},
			'post:' . $image
		);
	}
}
