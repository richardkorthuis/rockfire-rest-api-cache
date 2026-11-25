<?php
/**
 * End-to-end WooCommerce price and option invalidation matrix: real cached Store API responses, real changes of
 * tax rates, settings, plugins and the theme, real flushes.
 *
 * Every row serves anonymous Store API requests through the REST server so the production capture stores the
 * entries with the tags the Store API tagger collected, makes one change, commits the invalidator and asserts which
 * entries were flushed: every price-bearing response, on both Store API namespaces, or exactly what the row says.
 * Each row runs against the database store and the object-cache store.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Location_Pricing;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Woo_Price_Events;

/**
 * @coversNothing
 */
class Test_Woo_Price_Matrix extends Rfrac_Wc_Matrix_Test_Case {

	/**
	 * Scope: the price-bearing responses are flushed and every other served response stays active.
	 *
	 * @var string
	 */
	private const SCOPE_PRICE = 'price';

	/**
	 * Scope: the price-bearing responses are flushed.
	 *
	 * @var string
	 */
	private const SCOPE_PRICE_FLUSHED = 'price-flushed';

	/**
	 * Scope: the price-bearing responses, the categories list, the category by id and the reviews list are flushed.
	 *
	 * @var string
	 */
	private const SCOPE_PERMALINKS = 'permalinks';

	/**
	 * Scope: only the reviews list is flushed.
	 *
	 * @var string
	 */
	private const SCOPE_REVIEWS = 'reviews';

	/**
	 * Scope: nothing is flushed.
	 *
	 * @var string
	 */
	private const SCOPE_NONE = 'none';

	/**
	 * Slug of the fixture product.
	 *
	 * @var string
	 */
	private const SLUG = 'matrix-hat';

	/**
	 * The Store API namespaces: the versioned one and the unversioned alias.
	 *
	 * @var list<string>
	 */
	private const NAMESPACES = array( 'wc/store/v1', 'wc/store' );

	/**
	 * Price and tax options of the invalidation map: updating one flushes the price-bearing responses.
	 *
	 * @var list<string>
	 */
	private const PRICE_AND_TAX_OPTIONS = array(
		'woocommerce_currency',
		'woocommerce_currency_pos',
		'woocommerce_price_thousand_sep',
		'woocommerce_price_decimal_sep',
		'woocommerce_price_num_decimals',
		'woocommerce_price_display_suffix',
		'woocommerce_calc_taxes',
		'woocommerce_prices_include_tax',
		'woocommerce_tax_display_shop',
		'woocommerce_tax_based_on',
		'woocommerce_default_country',
		'woocommerce_store_postcode',
		'woocommerce_store_city',
		'woocommerce_default_customer_address',
	);

	/**
	 * Product display options of the invalidation map: updating one flushes the price-bearing responses.
	 *
	 * @var list<string>
	 */
	private const PRODUCT_DISPLAY_OPTIONS = array(
		'woocommerce_weight_unit',
		'woocommerce_dimension_unit',
		'woocommerce_stock_format',
		'woocommerce_notify_low_stock_amount',
		'woocommerce_notify_no_stock_amount',
		'woocommerce_manage_stock',
		'woocommerce_hide_out_of_stock_items',
		'default_product_cat',
	);

	/**
	 * Actions a row fires by hand with synthetic arguments; no other listener (core, WooCommerce) may see them.
	 *
	 * @var list<string>
	 */
	private const FIRED_BY_HAND = array(
		'switch_theme',
		'activated_plugin',
		'deactivated_plugin',
		'woocommerce_update_options_tax',
		'wp_ajax_woocommerce_tax_rates_save_changes',
	);

	/**
	 * The Store API routes of the matrix besides the price-bearing ones: the categories list, the category by id and the reviews list.
	 *
	 * @var list<string>
	 */
	private const OTHER_ROUTES = array(
		'/wc/store/v1/products/categories',
		'/wc/store/v1/products/categories/(?P<id>[\d]+)',
		'/wc/store/v1/products/reviews',
		'/wc/store/products/categories',
		'/wc/store/products/categories/(?P<id>[\d]+)',
		'/wc/store/products/reviews',
	);

	/**
	 * Leave the hooks a row fires by hand to the engine and the module, then arm.
	 *
	 * Every other listener of those hooks is dropped first, before the base class wires the module and the engine
	 * hooks that listen to them.
	 *
	 * @param string $store `database` or `object_cache`.
	 *
	 * @return void
	 */
	protected function arm( string $store = 'database' ): void {
		foreach ( self::FIRED_BY_HAND as $hook ) {
			remove_all_actions( $hook );
		}

		parent::arm( $store );
	}

	/**
	 * Data provider: one case per row, over every store.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function price_matrix(): array {
		$cases = array();
		foreach ( array_keys( self::rows() ) as $row ) {
			foreach ( self::stores() as $store_label => $store ) {
				$cases[ $row . ' (' . $store_label . ')' ] = array( $row, $store[0] );
			}
		}
		return $cases;
	}

	/**
	 * The rows: what a row prepares before the engine is armed, the one change it makes, and what the change flushes.
	 *
	 * @return array<string,array{scope:string,setup:Closure|null,change:Closure}>
	 */
	private static function rows(): array {
		$rows = array();

		// R1: a tax rate added, updated or deleted.
		$rows['R1 tax rate added']   = array(
			'scope'  => self::SCOPE_PRICE,
			'setup'  => null,
			'change' => static function ( self $test ): void {
				$test->wc_tax_rate( array( 'tax_rate_name' => 'Added tax' ) );
			},
		);
		$rows['R1 tax rate updated'] = array(
			'scope'  => self::SCOPE_PRICE,
			'setup'  => static fn( self $test ): int => $test->wc_tax_rate( array( 'tax_rate_name' => 'Updated tax' ) ),
			'change' => static function ( self $test, $rate_id ): void {
				WC_Tax::_update_tax_rate( (int) $rate_id, array( 'tax_rate' => '12.0000' ) );
			},
		);
		$rows['R1 tax rate deleted'] = array(
			'scope'  => self::SCOPE_PRICE,
			'setup'  => static fn( self $test ): int => $test->wc_tax_rate( array( 'tax_rate_name' => 'Deleted tax' ) ),
			'change' => static function ( self $test, $rate_id ): void {
				WC_Tax::_delete_tax_rate( (int) $rate_id );
			},
		);

		// R2: the admin screen saves the tax-rate table over AJAX.
		$rows['R2 admin ajax tax rate save'] = array(
			'scope'  => self::SCOPE_PRICE,
			'setup'  => static fn( self $test ): int => (int) self::factory()->user->create( array( 'role' => 'shop_manager' ) ),
			'change' => static function ( self $test, $user_id ): void {
				wp_set_current_user( (int) $user_id );
				$test->assertTrue( current_user_can( 'manage_woocommerce' ) );
				do_action( 'wp_ajax_woocommerce_tax_rates_save_changes' );
				wp_set_current_user( 0 );
			},
		);

		// R3: the tax settings are saved.
		$rows['R3 tax settings saved'] = array(
			'scope'  => self::SCOPE_PRICE,
			'setup'  => null,
			'change' => static function (): void {
				do_action( 'woocommerce_update_options_tax' );
			},
		);

		// R4: each price and tax option. R5: each product display option.
		foreach ( self::PRICE_AND_TAX_OPTIONS as $option ) {
			$rows[ 'R4 option ' . $option ] = array(
				'scope'  => self::SCOPE_PRICE,
				'setup'  => null,
				'change' => static function () use ( $option ): void {
					update_option( $option, 'rfrac-matrix-' . $option );
				},
			);
		}
		foreach ( self::PRODUCT_DISPLAY_OPTIONS as $option ) {
			$rows[ 'R5 option ' . $option ] = array(
				'scope'  => self::SCOPE_PRICE_FLUSHED,
				'setup'  => null,
				'change' => static function () use ( $option ): void {
					update_option( $option, 'rfrac-matrix-' . $option );
				},
			);
		}

		// R6: the permalink bases change the links in product, category and review responses.
		$rows['R6 option woocommerce_permalinks'] = array(
			'scope'  => self::SCOPE_PERMALINKS,
			'setup'  => null,
			'change' => static function (): void {
				update_option( 'woocommerce_permalinks', array( 'product_base' => '/rfrac-matrix-shop' ) );
			},
		);

		// R7: avatars appear in review responses only.
		$rows['R7 option show_avatars'] = array(
			'scope'  => self::SCOPE_REVIEWS,
			'setup'  => static function (): void {
				update_option( 'show_avatars', '1' );
			},
			'change' => static function (): void {
				update_option( 'show_avatars', '0' );
			},
		);

		// R8: a plugin or the theme may filter prices.
		$rows['R8 activated_plugin']   = array(
			'scope'  => self::SCOPE_PRICE,
			'setup'  => null,
			'change' => static function (): void {
				do_action( 'activated_plugin', 'example/example.php', false );
			},
		);
		$rows['R8 deactivated_plugin'] = array(
			'scope'  => self::SCOPE_PRICE,
			'setup'  => null,
			'change' => static function (): void {
				do_action( 'deactivated_plugin', 'example/example.php', false );
			},
		);
		$rows['R8 switch_theme']       = array(
			'scope'  => self::SCOPE_PRICE,
			'setup'  => null,
			'change' => static function (): void {
				do_action( 'switch_theme', 'example-theme' );
			},
		);

		// R9: an option of WooCommerce the map does not know.
		$rows['R9 option woocommerce_email_from_name'] = array(
			'scope'  => self::SCOPE_NONE,
			'setup'  => static function (): void {
				update_option( 'woocommerce_email_from_name', 'Shop' );
			},
			'change' => static function (): void {
				update_option( 'woocommerce_email_from_name', 'Other shop' );
			},
		);

		// R10: deleting a mapped option invalidates what updating it does.
		$rows['R10 option woocommerce_price_display_suffix deleted'] = array(
			'scope'  => self::SCOPE_PRICE,
			'setup'  => static function (): void {
				update_option( 'woocommerce_price_display_suffix', ' incl. VAT' );
			},
			'change' => static function (): void {
				delete_option( 'woocommerce_price_display_suffix' );
			},
		);

		return $rows;
	}

	/**
	 * Enable the matrix routes and serve every request of the matrix, so each is stored with its tags.
	 *
	 * @param int $product_id  Fixture product id.
	 * @param int $category_id Fixture category id.
	 *
	 * @return array{price:list<string>,categories:list<string>,reviews:list<string>} The served URIs by kind.
	 */
	private function serve_matrix( int $product_id, int $category_id ): array {
		$this->assertFalse( Location_Pricing::detected(), 'No tax-location filter is hooked, so the price-bearing routes are cached.' );

		foreach ( array_merge( Store_Routes::PRICE_BEARING, self::OTHER_ROUTES ) as $route ) {
			$this->enable_route( $route );
		}

		$uris = array(
			'price'      => array(),
			'categories' => array(),
			'reviews'    => array(),
		);
		foreach ( self::NAMESPACES as $namespace ) {
			$uris['price'][]      = $this->store_route( 'products', $namespace );
			$uris['price'][]      = $this->store_route( 'products/' . $product_id, $namespace );
			$uris['price'][]      = $this->store_route( 'products/' . self::SLUG, $namespace );
			$uris['price'][]      = $this->store_route( 'products/collection-data', $namespace );
			$uris['categories'][] = $this->store_route( 'products/categories', $namespace );
			$uris['categories'][] = $this->store_route( 'products/categories/' . $category_id, $namespace );
			$uris['reviews'][]    = $this->store_route( 'products/reviews', $namespace );
		}

		foreach ( array_merge( $uris['price'], $uris['categories'], $uris['reviews'] ) as $uri ) {
			$this->serve( $uri );
		}

		// A response that does not carry its tag could never be flushed by it, so the premise is checked first.
		foreach ( $uris['price'] as $uri ) {
			$this->assertContains( Store_Routes::PRICE_TAG, $this->tags_of( $uri ), 'A price-bearing response carries the price tag: ' . $uri );
		}
		foreach ( $uris['categories'] as $uri ) {
			$this->assertContains( 'list:product_cat', $this->tags_of( $uri ), 'A category response carries the category list tag: ' . $uri );
			$this->assertNotContains( Store_Routes::PRICE_TAG, $this->tags_of( $uri ), 'A category response carries no price: ' . $uri );
		}
		foreach ( $uris['reviews'] as $uri ) {
			$this->assertContains( 'list:wc-review', $this->tags_of( $uri ), 'A review response carries the review list tag: ' . $uri );
			$this->assertNotContains( Store_Routes::PRICE_TAG, $this->tags_of( $uri ), 'A review response carries no price: ' . $uri );
		}

		return $uris;
	}

	/**
	 * @dataProvider price_matrix
	 *
	 * @param string $row   Row key.
	 * @param string $store Store mode: `database` or `object_cache`.
	 */
	public function test_price_matrix( string $row, string $store ): void {
		$definition = self::rows()[ $row ];

		// A product with a rating in a category: every route of the matrix has a non-empty response to store.
		$category_id = $this->wc_category( 'Matrix hats' );
		$product     = $this->wc_product(
			array(
				'name'         => 'Matrix hat',
				'slug'         => self::SLUG,
				'category_ids' => array( $category_id ),
			)
		);
		$this->wc_review( $product->get_id(), 4 );
		$state = null === $definition['setup'] ? null : ( $definition['setup'] )( $this );

		$this->arm( $store );
		$uris = $this->serve_matrix( $product->get_id(), $category_id );
		$this->assert_active( array_merge( $uris['price'], $uris['categories'], $uris['reviews'] ) );

		$this->change_and_commit( fn() => ( $definition['change'] )( $this, $state ) );

		$price_tag = Store_Routes::PRICE_TAG;
		switch ( $definition['scope'] ) {
			case self::SCOPE_PRICE:
				$this->assert_only_flushed( $uris['price'], $price_tag );
				break;

			case self::SCOPE_PRICE_FLUSHED:
				$this->assert_flushed( $uris['price'], $price_tag );
				break;

			case self::SCOPE_PERMALINKS:
				$this->assert_flushed( $uris['price'], $price_tag );
				$this->assert_flushed( $uris['categories'], 'list:product_cat' );
				$this->assert_flushed( $uris['reviews'], 'list:wc-review' );
				break;

			case self::SCOPE_REVIEWS:
				$this->assert_only_flushed( $uris['reviews'], 'list:wc-review' );
				break;

			default:
				$this->assert_only_flushed( array() );
				$this->assertSame( array(), $this->entry_updates(), 'No UPDATE on the entries table.' );
				$this->assertSame( array(), $this->flushed );
		}
	}

	public function test_the_matrix_has_a_row_for_every_option_of_the_map(): void {
		$mapped = ( new ReflectionClassConstant( Woo_Price_Events::class, 'PRICE_OPTIONS' ) )->getValue();
		$rows   = array_merge( self::PRICE_AND_TAX_OPTIONS, self::PRODUCT_DISPLAY_OPTIONS );
		sort( $mapped );
		sort( $rows );

		$this->assertSame( $mapped, $rows, 'The matrix covers every price and catalog option of the map, and no other.' );
	}
}
