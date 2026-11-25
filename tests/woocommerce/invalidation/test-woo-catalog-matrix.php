<?php
/**
 * End-to-end WooCommerce catalog invalidation matrix: real cached Store API responses, real catalog changes,
 * real flushes.
 *
 * Every row serves anonymous Store API requests through the REST server so the production capture stores
 * entries with the tags the Store API tagger collected, changes the catalog through WordPress and WooCommerce
 * APIs, commits the invalidator and asserts which entries were flushed by that one commit. A row flushes the
 * responses that show its change and leaves every other served response active. Each row runs against the
 * database store and the object-cache store, and the whole suite runs with HPOS off and on.
 *
 * Rows C1 to C9 are terms and attributes, C10 to C12 are reviews and C13 to C15 are the term meta behind
 * recounts, images and ordering.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes;

/**
 * @coversNothing
 */
class Test_Woo_Catalog_Matrix extends Rfrac_Wc_Matrix_Test_Case {

	/**
	 * The rows of the matrix: row id => what changes.
	 *
	 * @var array<string,string>
	 */
	private const CASES = array(
		'C1'  => 'product category renamed',
		'C2'  => 'product tag renamed',
		'C3'  => 'product brand renamed',
		'C4'  => 'attribute term renamed',
		'C5'  => 'attribute term created',
		'C6'  => 'attribute term deleted',
		'C7'  => 'attribute renamed',
		'C8'  => 'attribute deleted',
		'C9'  => 'attribute added',
		'C10' => 'approved review inserted',
		'C11' => 'held review inserted',
		'C12' => 'approved review edited',
		'C13' => 'product out of stock while out-of-stock items are hidden',
		'C14' => 'category thumbnail changed',
		'C15' => 'category order set',
	);

	/**
	 * Data provider: every row of the matrix on every store.
	 *
	 * @return array<string,array{0:string,1:string}> Store, row id.
	 */
	public static function catalog_matrix(): array {
		$rows = array();
		foreach ( self::CASES as $case => $label ) {
			foreach ( self::stores() as $store_label => $store ) {
				$rows[ $case . ' ' . $label . ', ' . $store_label ] = array( $store[0], $case );
			}
		}
		return $rows;
	}

	/**
	 * @dataProvider catalog_matrix
	 *
	 * @param string $store Store mode of the run.
	 * @param string $case  Row id.
	 */
	public function test_catalog_matrix( string $store, string $case ): void {
		$plan = match ( $case ) {
			'C1'    => $this->plan_category_renamed(),
			'C2'    => $this->plan_tag_renamed(),
			'C3'    => $this->plan_brand_renamed(),
			'C4'    => $this->plan_attribute_term_renamed(),
			'C5'    => $this->plan_attribute_term_created(),
			'C6'    => $this->plan_attribute_term_deleted(),
			'C7'    => $this->plan_attribute_renamed(),
			'C8'    => $this->plan_attribute_deleted(),
			'C9'    => $this->plan_attribute_added(),
			'C10'   => $this->plan_approved_review_inserted(),
			'C11'   => $this->plan_held_review_inserted(),
			'C12'   => $this->plan_approved_review_edited(),
			'C13'   => $this->plan_product_out_of_stock(),
			'C14'   => $this->plan_category_thumbnail_changed(),
			'C15'   => $this->plan_category_order_set(),
			default => throw new InvalidArgumentException( 'Unknown matrix row: ' . $case ),
		};

		foreach ( Store_Routes::CATALOG as $route ) {
			$this->enable_route( $route );
		}
		$this->arm( $store );

		foreach ( $plan['serve'] as $uri ) {
			$this->serve( $uri );
		}
		$this->assert_active( $plan['serve'] );

		$this->change_and_commit( $plan['change'] );

		$this->assert_only_flushed( $plan['flushed'], $plan['tag'] );
		if ( array() === $plan['flushed'] ) {
			$this->assertSame( array(), $this->entry_updates(), 'No UPDATE on the entries table.' );
			$this->assertSame( array(), $this->flushed, 'No rfrac/flushed event.' );
		} else {
			$this->assert_one_flush_query( $store );
		}
	}

	// ---------------------------------------------------------------------------------------
	// Terms and attributes: C1 to C9.
	// ---------------------------------------------------------------------------------------

	/**
	 * C1: a product category is renamed. Its list, its own response and the product in it are flushed; a product
	 * in another category is not.
	 *
	 * @return array{serve:string[],flushed:string[],tag:string,change:Closure}
	 */
	private function plan_category_renamed(): array {
		$shop = $this->shop();

		$categories = $this->store_route( 'products/categories' );
		$category   = $this->category_route( $shop['cat'] );
		$product    = $this->product_route( $shop['p'] );

		return array(
			'serve'   => array( $categories, $category, $product, $this->product_route( $shop['q'] ), $this->store_route( 'products/tags' ), $this->store_route( 'products/attributes' ) ),
			'flushed' => array( $categories, $category, $product ),
			'tag'     => 'term:' . $shop['cat'],
			'change'  => fn() => wp_update_term( $shop['cat'], 'product_cat', array( 'name' => 'T-shirts' ) ),
		);
	}

	/**
	 * C2: a product tag is renamed. The tag list and the product with the tag are flushed.
	 *
	 * @return array{serve:string[],flushed:string[],tag:string,change:Closure}
	 */
	private function plan_tag_renamed(): array {
		$shop = $this->shop();

		$tags    = $this->store_route( 'products/tags' );
		$product = $this->product_route( $shop['p'] );

		return array(
			'serve'   => array( $tags, $product, $this->product_route( $shop['q'] ), $this->store_route( 'products/categories' ), $this->store_route( 'products/brands' ) ),
			'flushed' => array( $tags, $product ),
			'tag'     => 'term:' . $shop['tag'],
			'change'  => fn() => wp_update_term( $shop['tag'], 'product_tag', array( 'name' => 'Winter' ) ),
		);
	}

	/**
	 * C3: a product brand is renamed. The brand list, the brand itself and the product with the brand are flushed.
	 *
	 * @return array{serve:string[],flushed:string[],tag:string,change:Closure}
	 */
	private function plan_brand_renamed(): array {
		$shop = $this->shop();

		$brands  = $this->store_route( 'products/brands' );
		$brand   = $this->brand_route( $shop['brand'] );
		$product = $this->product_route( $shop['p'] );

		return array(
			'serve'   => array( $brands, $brand, $product, $this->product_route( $shop['q'] ), $this->store_route( 'products/categories' ), $this->store_route( 'products/tags' ) ),
			'flushed' => array( $brands, $brand, $product ),
			'tag'     => 'term:' . $shop['brand'],
			'change'  => fn() => wp_update_term( $shop['brand'], 'product_brand', array( 'name' => 'Acme Outdoor' ) ),
		);
	}

	/**
	 * C4: a term of the `pa_color` attribute is renamed. The attribute's term list and the product that shows the
	 * term are flushed; the attribute itself and its list are not.
	 *
	 * @return array{serve:string[],flushed:string[],tag:string,change:Closure}
	 */
	private function plan_attribute_term_renamed(): array {
		$shop  = $this->shop();
		$color = $shop['color'];
		$red   = $color['term_ids']['Red'];

		$terms   = $this->attribute_terms_route( $color['id'] );
		$product = $this->product_route( $shop['p'] );

		return array(
			'serve'   => array( $terms, $product, $this->product_route( $shop['q'] ), $this->attribute_route( $color['id'] ), $this->store_route( 'products/attributes' ), $this->store_route( 'products/categories' ) ),
			'flushed' => array( $terms, $product ),
			'tag'     => 'term:' . $red,
			'change'  => fn() => wp_update_term( $red, $color['taxonomy'], array( 'name' => 'Crimson' ) ),
		);
	}

	/**
	 * C5: a term is created in the `pa_color` attribute. The attribute's term list, the attribute list and the
	 * attribute itself are flushed.
	 *
	 * @return array{serve:string[],flushed:string[],tag:string,change:Closure}
	 */
	private function plan_attribute_term_created(): array {
		$shop  = $this->shop();
		$color = $shop['color'];

		$attribute  = $this->attribute_route( $color['id'] );
		$attributes = $this->store_route( 'products/attributes' );
		$terms      = $this->attribute_terms_route( $color['id'] );

		return array(
			'serve'   => array( $terms, $attributes, $attribute, $this->product_route( $shop['q'] ), $this->store_route( 'products/categories' ), $this->store_route( 'products/tags' ) ),
			'flushed' => array( $terms, $attributes, $attribute ),
			'tag'     => 'wc-attribute:' . $color['id'],
			'change'  => fn() => wp_insert_term( 'Green', $color['taxonomy'] ),
		);
	}

	/**
	 * C6: a term of the `pa_color` attribute is deleted. The attribute's term list, the product that shows the term
	 * and the attribute itself are flushed.
	 *
	 * @return array{serve:string[],flushed:string[],tag:string,change:Closure}
	 */
	private function plan_attribute_term_deleted(): array {
		$shop  = $this->shop();
		$color = $shop['color'];
		$red   = $color['term_ids']['Red'];

		$terms     = $this->attribute_terms_route( $color['id'] );
		$product   = $this->product_route( $shop['p'] );
		$attribute = $this->attribute_route( $color['id'] );

		return array(
			'serve'   => array( $terms, $product, $attribute, $this->product_route( $shop['q'] ), $this->store_route( 'products/categories' ), $this->store_route( 'products/tags' ) ),
			'flushed' => array( $terms, $product, $attribute ),
			'tag'     => 'term:' . $red,
			'change'  => fn() => wp_delete_term( $red, $color['taxonomy'] ),
		);
	}

	/**
	 * C7: the `pa_color` attribute is renamed. The attribute list, the attribute itself, its term list and the
	 * product that shows it are flushed.
	 *
	 * @return array{serve:string[],flushed:string[],tag:string,change:Closure}
	 */
	private function plan_attribute_renamed(): array {
		$shop  = $this->shop();
		$color = $shop['color'];

		$attributes = $this->store_route( 'products/attributes' );
		$attribute  = $this->attribute_route( $color['id'] );
		$terms      = $this->attribute_terms_route( $color['id'] );
		$product    = $this->product_route( $shop['p'] );

		return array(
			'serve'   => array( $attributes, $attribute, $terms, $product, $this->product_route( $shop['q'] ), $this->store_route( 'products/categories' ), $this->store_route( 'products/tags' ) ),
			'flushed' => array( $attributes, $attribute, $terms, $product ),
			'tag'     => 'wc-attribute:' . $color['id'],
			'change'  => fn() => wc_update_attribute( $color['id'], array( 'name' => 'Colour' ) ),
		);
	}

	/**
	 * C8: the `pa_color` attribute is deleted. The attribute list, the attribute itself, its term list and the
	 * product that shows it are flushed.
	 *
	 * @return array{serve:string[],flushed:string[],tag:string,change:Closure}
	 */
	private function plan_attribute_deleted(): array {
		$shop  = $this->shop();
		$color = $shop['color'];

		$attributes = $this->store_route( 'products/attributes' );
		$attribute  = $this->attribute_route( $color['id'] );
		$terms      = $this->attribute_terms_route( $color['id'] );
		$product    = $this->product_route( $shop['p'] );

		return array(
			'serve'   => array( $attributes, $attribute, $terms, $product, $this->product_route( $shop['q'] ), $this->store_route( 'products/categories' ), $this->store_route( 'products/tags' ) ),
			'flushed' => array( $attributes, $attribute, $terms, $product ),
			'tag'     => 'list:wc-attribute',
			'change'  => fn() => wc_delete_attribute( $color['id'] ),
		);
	}

	/**
	 * C9: an attribute is added. The attribute list is flushed; the attributes that already exist, their terms and
	 * the products that show them are not.
	 *
	 * @return array{serve:string[],flushed:string[],tag:string,change:Closure}
	 */
	private function plan_attribute_added(): array {
		$shop  = $this->shop();
		$color = $shop['color'];

		$attributes = $this->store_route( 'products/attributes' );

		return array(
			'serve'   => array( $attributes, $this->attribute_route( $color['id'] ), $this->attribute_terms_route( $color['id'] ), $this->product_route( $shop['p'] ), $this->product_route( $shop['q'] ), $this->store_route( 'products/categories' ) ),
			'flushed' => array( $attributes ),
			'tag'     => 'list:wc-attribute',
			'change'  => fn() => wc_create_attribute(
				array(
					'name' => 'Size',
					'slug' => 'size',
				)
			),
		);
	}

	// ---------------------------------------------------------------------------------------
	// Reviews: C10 to C12.
	// ---------------------------------------------------------------------------------------

	/**
	 * C10: an approved review is posted on a product in a child category (parent category, brand). The review list,
	 * the product, its categories and its brand, every product list and the rating counts are flushed.
	 *
	 * @return array{serve:string[],flushed:string[],tag:string,change:Closure}
	 */
	private function plan_approved_review_inserted(): array {
		$shop = $this->shop( true );

		$flushed = array(
			$this->store_route( 'products/reviews' ),
			$this->product_route( $shop['p'] ),
			$this->category_route( $shop['cat'] ),
			$this->category_route( $shop['parent'] ),
			$this->brand_route( $shop['brand'] ),
			$this->store_route( 'products' ) . '?orderby=rating',
			$this->store_route( 'products/collection-data' ) . '?calculate_rating_counts=true',
		);

		return array(
			'serve'   => array_merge( $flushed, array( $this->product_route( $shop['q'] ), $this->store_route( 'products/tags' ), $this->store_route( 'products/attributes' ) ) ),
			'flushed' => $flushed,
			'tag'     => 'list:wc-review',
			'change'  => fn() => $this->wc_review( $shop['p'], 5 ),
		);
	}

	/**
	 * C11: a held review is inserted. Nothing is flushed.
	 *
	 * @return array{serve:string[],flushed:string[],tag:string,change:Closure}
	 */
	private function plan_held_review_inserted(): array {
		$shop = $this->shop( true );

		return array(
			'serve'   => array(
				$this->store_route( 'products/reviews' ),
				$this->product_route( $shop['p'] ),
				$this->product_route( $shop['q'] ),
				$this->category_route( $shop['cat'] ),
				$this->category_route( $shop['parent'] ),
				$this->brand_route( $shop['brand'] ),
				$this->store_route( 'products' ) . '?orderby=rating',
				$this->store_route( 'products/collection-data' ) . '?calculate_rating_counts=true',
			),
			'flushed' => array(),
			'tag'     => 'none',
			'change'  => fn() => $this->wc_review( $shop['p'], 3, 0 ),
		);
	}

	/**
	 * C12: an approved review is edited. The review list is flushed.
	 *
	 * @return array{serve:string[],flushed:string[],tag:string,change:Closure}
	 */
	private function plan_approved_review_edited(): array {
		$shop   = $this->shop( true );
		$review = $this->wc_review( $shop['p'], 5 );

		$reviews = $this->store_route( 'products/reviews' );

		return array(
			'serve'   => array( $reviews, $this->product_route( $shop['q'] ), $this->store_route( 'products/tags' ), $this->store_route( 'products/attributes' ) ),
			'flushed' => array( $reviews ),
			'tag'     => 'list:wc-review',
			'change'  => fn() => wp_update_comment(
				array(
					'comment_ID'      => $review,
					'comment_content' => 'Edited review',
				)
			),
		);
	}

	// ---------------------------------------------------------------------------------------
	// Term meta: C13 to C15.
	// ---------------------------------------------------------------------------------------

	/**
	 * C13: with out-of-stock items hidden, a product goes out of stock. The recount of its category flushes the
	 * category and the category list.
	 *
	 * @return array{serve:string[],flushed:string[],tag:string,change:Closure}
	 */
	private function plan_product_out_of_stock(): array {
		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		$shop = $this->shop();

		$categories = $this->store_route( 'products/categories' );
		$category   = $this->category_route( $shop['cat'] );

		return array(
			'serve'   => array( $categories, $category, $this->product_route( $shop['q'] ), $this->store_route( 'products/attributes' ), $this->store_route( 'products/reviews' ) ),
			'flushed' => array( $categories, $category ),
			'tag'     => 'term:' . $shop['cat'],
			'change'  => function () use ( $shop ): void {
				$product = wc_get_product( $shop['p'] );
				$this->assertInstanceOf( WC_Product::class, $product );
				$product->set_stock_status( 'outofstock' );
				$product->save();
			},
		);
	}

	/**
	 * C14: the thumbnail of a category is changed through term meta. The category is flushed.
	 *
	 * @return array{serve:string[],flushed:string[],tag:string,change:Closure}
	 */
	private function plan_category_thumbnail_changed(): array {
		$shop = $this->shop();

		$old = $this->attachment( 'before.jpg' );
		$new = $this->attachment( 'after.jpg' );
		update_term_meta( $shop['cat'], 'thumbnail_id', $old );

		$category = $this->category_route( $shop['cat'] );

		return array(
			'serve'   => array( $category, $this->product_route( $shop['q'] ), $this->store_route( 'products/tags' ), $this->store_route( 'products/brands' ), $this->store_route( 'products/attributes' ) ),
			'flushed' => array( $category ),
			'tag'     => 'term:' . $shop['cat'],
			'change'  => fn() => update_term_meta( $shop['cat'], 'thumbnail_id', $new ),
		);
	}

	/**
	 * C15: the order of a category is set with `wc_set_term_order()`. The category list is flushed.
	 *
	 * @return array{serve:string[],flushed:string[],tag:string,change:Closure}
	 */
	private function plan_category_order_set(): array {
		$shop = $this->shop();

		$categories = $this->store_route( 'products/categories' );

		return array(
			'serve'   => array( $categories, $this->product_route( $shop['q'] ), $this->store_route( 'products/tags' ), $this->store_route( 'products/brands' ), $this->store_route( 'products/attributes' ) ),
			'flushed' => array( $categories ),
			'tag'     => 'list:product_cat',
			'change'  => fn() => wc_set_term_order( $shop['cat'], 5, 'product_cat' ),
		);
	}

	// ---------------------------------------------------------------------------------------
	// Fixtures and routes.
	// ---------------------------------------------------------------------------------------

	/**
	 * A small shop: product P in category C (a child of a parent category when nested) with a tag, a brand and the
	 * `pa_color` attribute (term Red), and product Q in another category with a review of its own.
	 *
	 * Built before the engine hooks are armed, so building it queues nothing.
	 *
	 * @param bool $nested Whether C is a child of a parent category.
	 *
	 * @return array{parent:int,cat:int,other:int,tag:int,brand:int,color:array{id:int,taxonomy:string,term_ids:array<string,int>},p:int,q:int}
	 */
	private function shop( bool $nested = false ): array {
		$parent = $nested ? $this->wc_category( 'Clothing' ) : 0;
		$cat    = $this->wc_category( 'Shirts', $parent );
		$other  = $this->wc_category( 'Shoes' );
		$tag    = $this->wc_tag( 'Summer' );
		$brand  = $this->wc_brand( 'Acme' );
		$color  = $this->wc_attribute( 'color', array( 'Red', 'Blue' ) );

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( $color['id'] );
		$attribute->set_name( $color['taxonomy'] );
		$attribute->set_options( array( $color['term_ids']['Red'] ) );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( false );

		$p = $this->wc_product(
			array(
				'name'       => 'Polo shirt',
				'attributes' => array( $attribute ),
			)
		)->get_id();
		wp_set_object_terms( $p, array( $cat ), 'product_cat' );
		wp_set_object_terms( $p, array( $tag ), 'product_tag' );
		wp_set_object_terms( $p, array( $brand ), 'product_brand' );

		$q = $this->wc_product( array( 'name' => 'Sneakers' ) )->get_id();
		wp_set_object_terms( $q, array( $other ), 'product_cat' );
		$this->wc_review( $q, 4 );

		return array(
			'parent' => $parent,
			'cat'    => $cat,
			'other'  => $other,
			'tag'    => $tag,
			'brand'  => $brand,
			'color'  => $color,
			'p'      => $p,
			'q'      => $q,
		);
	}

	/**
	 * Create an image attachment.
	 *
	 * @param string $file File name.
	 *
	 * @return int Attachment ID.
	 */
	private function attachment( string $file ): int {
		return (int) $this->factory()->attachment->create_object(
			array(
				'file'           => $file,
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $file,
			)
		);
	}

	/**
	 * Route of a product.
	 *
	 * @param int $id Product ID.
	 *
	 * @return string
	 */
	private function product_route( int $id ): string {
		return $this->store_route( 'products/' . $id );
	}

	/**
	 * Route of a product category.
	 *
	 * @param int $id Term ID.
	 *
	 * @return string
	 */
	private function category_route( int $id ): string {
		return $this->store_route( 'products/categories/' . $id );
	}

	/**
	 * Route of a product brand.
	 *
	 * @param int $id Term ID.
	 *
	 * @return string
	 */
	private function brand_route( int $id ): string {
		return $this->store_route( 'products/brands/' . $id );
	}

	/**
	 * Route of a product attribute.
	 *
	 * @param int $id Attribute ID.
	 *
	 * @return string
	 */
	private function attribute_route( int $id ): string {
		return $this->store_route( 'products/attributes/' . $id );
	}

	/**
	 * Route of the terms of a product attribute.
	 *
	 * @param int $id Attribute ID.
	 *
	 * @return string
	 */
	private function attribute_terms_route( int $id ): string {
		return $this->store_route( 'products/attributes/' . $id . '/terms' );
	}
}
