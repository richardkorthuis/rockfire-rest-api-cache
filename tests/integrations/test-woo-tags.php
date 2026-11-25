<?php
/**
 * Tests for the WooCommerce cache tag vocabulary.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use PHPUnit\Framework\TestCase;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Woo_Tags;
use Rockfire\Rest_Api_Cache\Invalidation\Tags;

/**
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Woo_Tags
 */
class Test_Woo_Tags extends TestCase {

	/**
	 * An attribute ID of 1 or more gives a `wc-attribute:{id}` tag; anything below 1 gives null.
	 *
	 * @return void
	 */
	public function test_attribute_tag(): void {
		$this->assertSame( 'wc-attribute:12', Woo_Tags::attribute( 12 ) );
		$this->assertNull( Woo_Tags::attribute( 0 ) );
		$this->assertNull( Woo_Tags::attribute( -3 ) );
	}

	/**
	 * Every constant and the built attribute tag are valid tags that `Tags::normalize()` leaves as they are.
	 *
	 * @return void
	 */
	public function test_constants_survive_normalisation(): void {
		$tags = array(
			Woo_Tags::LIST_ATTRIBUTES,
			Woo_Tags::LIST_REVIEWS,
			Woo_Tags::LIST_PRODUCTS,
			Woo_Tags::LIST_POPULAR,
			Woo_Tags::attribute( 12 ),
		);

		foreach ( $tags as $tag ) {
			$this->assertNotNull( $tag );
			$this->assertSame( $tag, Tags::normalize( $tag ) );
		}
	}

	/**
	 * The list tag of an attribute taxonomy is the core list tag of the taxonomy name.
	 *
	 * @return void
	 */
	public function test_attribute_taxonomy_list_tag(): void {
		$this->assertSame( 'list:pa_color', Tags::list_for( 'pa_color' ) );
	}

	/**
	 * The popularity list tag is a list tag of its own, distinct from the product list tag.
	 *
	 * @return void
	 */
	public function test_popularity_list_tag(): void {
		$this->assertSame( 'list:wc-popularity', Woo_Tags::LIST_POPULAR );
		$this->assertSame( Tags::list_for( 'wc-popularity' ), Woo_Tags::LIST_POPULAR );
		$this->assertSame( Woo_Tags::LIST_POPULAR, Tags::normalize( Woo_Tags::LIST_POPULAR ) );
		$this->assertNotSame( Woo_Tags::LIST_PRODUCTS, Woo_Tags::LIST_POPULAR );
	}

	/**
	 * The product list tag is the core list tag of the `product` post type.
	 *
	 * @return void
	 */
	public function test_products_list_tag_is_the_core_list_tag(): void {
		$this->assertSame( Tags::list_for( 'product' ), Woo_Tags::LIST_PRODUCTS );
		$this->assertSame( 200, Woo_Tags::MAX_FANOUT );
	}
}
