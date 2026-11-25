<?php
/**
 * Tests for the WooCommerce Store API route lists.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use PHPUnit\Framework\TestCase;
use Rockfire\Rest_Api_Cache\Config\Deny_List;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes;
use Rockfire\Rest_Api_Cache\Invalidation\Tags;

/**
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes
 */
class Test_Woocommerce_Store_Routes extends TestCase {

	/**
	 * The route suffixes WooCommerce registers under each Store API namespace.
	 *
	 * @var list<string>
	 */
	private const SUFFIXES = array(
		'/products',
		'/products/(?P<id>[\d]+)',
		'/products/(?P<slug>[\S]+)',
		'/products/attributes',
		'/products/attributes/(?P<id>[\d]+)',
		'/products/attributes/(?P<attribute_id>[\d]+)/terms',
		'/products/categories',
		'/products/categories/(?P<id>[\d]+)',
		'/products/brands',
		'/products/brands/(?P<identifier>[\w-]+)',
		'/products/tags',
		'/products/reviews',
		'/products/collection-data',
	);

	/**
	 * Both Store API namespaces.
	 *
	 * @var list<string>
	 */
	private const NAMESPACES = array( '/wc/store', '/wc/store/v1' );

	public function test_catalog_holds_every_suffix_under_both_namespaces(): void {
		$this->assertCount( 26, Store_Routes::CATALOG );
		$this->assertCount( 26, array_unique( Store_Routes::CATALOG ), 'No duplicate.' );

		foreach ( self::NAMESPACES as $index => $namespace ) {
			$this->assertSame(
				array_map( static fn( string $suffix ): string => $namespace . $suffix, self::SUFFIXES ),
				array_slice( Store_Routes::CATALOG, $index * 13, 13 ),
				"The 13 patterns under $namespace"
			);
		}
	}

	public function test_price_bearing_routes_are_the_product_routes_that_carry_prices(): void {
		$this->assertCount( 8, Store_Routes::PRICE_BEARING );
		$this->assertCount( 8, array_unique( Store_Routes::PRICE_BEARING ) );
		$this->assertSame( array(), array_diff( Store_Routes::PRICE_BEARING, Store_Routes::CATALOG ), 'Every price-bearing route is a catalog route.' );

		$expected = array();
		foreach ( self::NAMESPACES as $namespace ) {
			foreach ( array( '/products', '/products/(?P<id>[\d]+)', '/products/(?P<slug>[\S]+)', '/products/collection-data' ) as $suffix ) {
				$expected[] = $namespace . $suffix;
			}
		}
		$this->assertEqualsCanonicalizing( $expected, Store_Routes::PRICE_BEARING );
	}

	public function test_no_catalog_route_is_denied(): void {
		foreach ( Store_Routes::CATALOG as $pattern ) {
			$this->assertFalse( Deny_List::is_denied( $pattern ), "$pattern is denied" );
		}
	}

	public function test_the_price_tag_is_a_valid_tag(): void {
		$this->assertSame( 'wc-price:all', Store_Routes::PRICE_TAG );
		$this->assertSame( 'wc-price:all', Tags::normalize( Store_Routes::PRICE_TAG ) );
	}

	public function test_the_helpers_match_patterns_strictly(): void {
		foreach ( Store_Routes::CATALOG as $pattern ) {
			$this->assertTrue( Store_Routes::is_catalog( $pattern ), $pattern );
		}
		foreach ( Store_Routes::PRICE_BEARING as $pattern ) {
			$this->assertTrue( Store_Routes::is_price_bearing( $pattern ), $pattern );
		}
		$this->assertTrue( Store_Routes::is_catalog( '/wc/store/v1/products/tags' ) );
		$this->assertFalse( Store_Routes::is_price_bearing( '/wc/store/v1/products/tags' ), 'A catalog route without prices.' );
		$this->assertFalse( Store_Routes::is_price_bearing( '/wc/store/v1/products/reviews' ) );
	}

	/**
	 * Routes that are not catalog routes.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function other_routes(): array {
		return array(
			'cart'                 => array( '/wc/store/v1/cart' ),
			'checkout'             => array( '/wc/store/v1/checkout' ),
			'unversioned cart'     => array( '/wc/store/cart' ),
			'a concrete product'   => array( '/wc/store/v1/products/12' ),
			'other namespace'      => array( '/wc/store/v2/products' ),
			'trailing slash'       => array( '/wc/store/v1/products/' ),
			'upper case'           => array( '/WC/STORE/V1/PRODUCTS' ),
			'wp posts'             => array( '/wp/v2/posts' ),
			'empty'                => array( '' ),
		);
	}

	/**
	 * @dataProvider other_routes
	 *
	 * @param string $pattern Pattern.
	 */
	public function test_other_routes_are_neither_catalog_nor_price_bearing( string $pattern ): void {
		$this->assertFalse( Store_Routes::is_catalog( $pattern ) );
		$this->assertFalse( Store_Routes::is_price_bearing( $pattern ) );
	}
}
