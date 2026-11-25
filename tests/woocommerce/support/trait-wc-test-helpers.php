<?php
/**
 * Helpers of the WooCommerce tests: the HPOS mode, fixtures built through WooCommerce CRUD and the per-test state reset.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Plugin;

/**
 * WooCommerce test helpers. Use in a WP_UnitTestCase; the WooCommerce base test cases call
 * {@see self::reset_wc_state()} in set_up() and tear_down().
 *
 * Fixtures go through WooCommerce CRUD and core APIs, never raw SQL. They run inside the test's database
 * transaction, so the rows they create are rolled back with it; only the in-memory state needs the reset.
 */
trait Rfrac_Wc_Test_Helpers {

	/**
	 * The `pre_option_active_plugins` filter of {@see self::fake_active_plugins()}, while it is added.
	 *
	 * @var Closure|null
	 */
	private ?Closure $wc_active_plugins_filter = null;

	/**
	 * Attribute taxonomies registered by {@see self::wc_attribute()} in this test.
	 *
	 * @var string[]
	 */
	private array $wc_fixture_taxonomies = array();

	/**
	 * Restore the default WooCommerce terms before the first test of the class.
	 *
	 * The WordPress test library deletes every term except the one with id 1 after each test class, and WooCommerce's
	 * default `product_cat` term, its `product_type` terms and its `product_visibility` terms go with them. Without
	 * them a product has no default category and no visibility term, so what depends on either (the category of a
	 * review, the recount of a category when a product goes out of stock) depends on the class that ran before.
	 * WooCommerce reads the ids of the visibility terms through a static memo that the marker class of the bootstrap
	 * switches off.
	 *
	 * The terms are created with the engine hooks of the booted container held off, as every test runs with them: a
	 * term fixture must not queue tags nor make a listener memoise state. They are committed, so they outlive the
	 * transaction of each test.
	 *
	 * @return void
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		$held = array();
		foreach ( Plugin::instance()->engine_hooks() as $hook ) {
			if ( ! isset( $held[ $hook['hook'] ] ) && isset( $GLOBALS['wp_filter'][ $hook['hook'] ] ) ) {
				$held[ $hook['hook'] ] = clone $GLOBALS['wp_filter'][ $hook['hook'] ];
			}
			remove_filter( $hook['hook'], $hook['callback'], $hook['priority'] );
		}
		try {
			WC_Install::create_terms();
		} finally {
			foreach ( $held as $name => $wp_hook ) {
				$GLOBALS['wp_filter'][ $name ] = $wp_hook;
			}
		}

		self::commit_transaction();
	}

	/**
	 * Whether this run uses the HPOS (custom orders table) mode, as selected by `RFRAC_WC_HPOS`.
	 *
	 * @return bool
	 */
	public function hpos_enabled(): bool {
		return '1' === getenv( 'RFRAC_WC_HPOS' );
	}

	/**
	 * Report WooCommerce and this plugin as active plugins.
	 *
	 * WooCommerce is loaded by the bootstrap and never listed in `active_plugins`, so code that asks
	 * `is_plugin_active()` would otherwise see neither. The filter is removed again by
	 * {@see self::reset_wc_state()}.
	 *
	 * @return void
	 */
	public function fake_active_plugins(): void {
		$this->remove_fake_active_plugins();

		$plugins                        = array( 'woocommerce/woocommerce.php', Plugin::instance()->basename() );
		$this->wc_active_plugins_filter = static fn(): array => $plugins;
		add_filter( 'pre_option_active_plugins', $this->wc_active_plugins_filter );
	}

	/**
	 * Start from clean WooCommerce state: no cart, session or customer, no deferred product sync and no
	 * attribute taxonomy of an earlier test.
	 *
	 * @return void
	 */
	public function reset_wc_state(): void {
		$woocommerce           = WC();
		$woocommerce->cart     = null;
		$woocommerce->session  = null;
		$woocommerce->customer = null;

		// Variation saves queue their parent for a sync at shutdown; the sync never runs in tests.
		$GLOBALS['wc_deferred_product_sync'] = array();

		$registered = isset( $GLOBALS['wc_product_attributes'] ) && is_array( $GLOBALS['wc_product_attributes'] ) ? array_keys( $GLOBALS['wc_product_attributes'] ) : array();
		foreach ( array_unique( array_merge( $this->wc_fixture_taxonomies, $registered ) ) as $taxonomy ) {
			$taxonomy = (string) $taxonomy;
			if ( taxonomy_exists( $taxonomy ) ) {
				unregister_taxonomy( $taxonomy );
			}
			unset( $GLOBALS['wc_product_attributes'][ $taxonomy ] );
		}
		$this->wc_fixture_taxonomies = array();
		delete_transient( 'wc_attribute_taxonomies' );

		$this->remove_fake_active_plugins();
	}

	/**
	 * Remove the filter of {@see self::fake_active_plugins()}.
	 *
	 * @return void
	 */
	private function remove_fake_active_plugins(): void {
		if ( null !== $this->wc_active_plugins_filter ) {
			remove_filter( 'pre_option_active_plugins', $this->wc_active_plugins_filter );
			$this->wc_active_plugins_filter = null;
		}
	}

	/**
	 * Create a published, visible, in-stock simple product.
	 *
	 * @param array<string,mixed> $props Product properties, applied with `set_props()` over the defaults.
	 *
	 * @return WC_Product_Simple
	 */
	protected function wc_product( array $props = array() ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_props(
			array_merge(
				array(
					'name'               => 'Fixture product',
					'regular_price'      => '10',
					'status'             => 'publish',
					'catalog_visibility' => 'visible',
					'stock_status'       => 'instock',
				),
				$props
			)
		);
		$product->save();

		return $product;
	}

	/**
	 * Create a global product attribute (`pa_<name>`), register its taxonomy and create its terms.
	 *
	 * @param string   $name  Attribute name, used as label and, sanitized, as slug.
	 * @param string[] $terms Term names.
	 *
	 * @return array{id:int,taxonomy:string,term_ids:array<string,int>} The attribute id, its taxonomy and the term ids by term name.
	 */
	protected function wc_attribute( string $name, array $terms ): array {
		global $wc_product_attributes;

		$slug     = wc_sanitize_taxonomy_name( $name );
		$taxonomy = wc_attribute_taxonomy_name( $slug );

		// Make sure caches are clean before the attribute is looked up.
		delete_transient( 'wc_attribute_taxonomies' );

		$id = wc_attribute_taxonomy_id_by_name( $slug );
		if ( ! $id ) {
			$id = wc_create_attribute(
				array(
					'name'         => $name,
					'slug'         => $slug,
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => 0,
				)
			);
			if ( is_wp_error( $id ) ) {
				throw new RuntimeException( 'The attribute fixture could not be created: ' . $id->get_error_message() );
			}

			// WooCommerce registers attribute taxonomies on `init`, so a new one is registered by hand.
			unregister_taxonomy( $taxonomy );
			register_taxonomy(
				$taxonomy,
				apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy, array( 'product' ) ),
				apply_filters(
					'woocommerce_taxonomy_args_' . $taxonomy,
					array(
						'labels'       => array( 'name' => $name ),
						'hierarchical' => false,
						'show_ui'      => false,
						'query_var'    => true,
						'rewrite'      => false,
					)
				)
			);
			$this->wc_fixture_taxonomies[] = $taxonomy;

			// The registry of WooCommerce attribute taxonomies, rebuilt from the table like `init` does.
			delete_transient( 'wc_attribute_taxonomies' );
			$wc_product_attributes = array();
			foreach ( wc_get_attribute_taxonomies() as $attribute ) {
				$wc_product_attributes[ wc_attribute_taxonomy_name( $attribute->attribute_name ) ] = $attribute;
			}
		}

		$term_ids = array();
		foreach ( $terms as $term ) {
			$term = (string) $term;
			$row  = term_exists( $term, $taxonomy );
			if ( ! $row ) {
				$row = wp_insert_term( $term, $taxonomy );
			}
			if ( is_wp_error( $row ) ) {
				throw new RuntimeException( 'The attribute term fixture could not be created: ' . $row->get_error_message() );
			}
			$term_ids[ $term ] = (int) ( is_array( $row ) ? $row['term_id'] : $row );
		}

		return array(
			'id'       => (int) $id,
			'taxonomy' => $taxonomy,
			'term_ids' => $term_ids,
		);
	}

	/**
	 * Create a published, visible variable product with one variation per attribute term and sync the parent.
	 *
	 * @param array<string,string> $prices_by_term Regular price by attribute term name; one variation per entry.
	 * @param string               $attribute      Global attribute name the variations vary by.
	 * @param array<string,mixed>  $props          Parent product properties, applied with `set_props()` over the defaults.
	 *
	 * @return WC_Product_Variable
	 */
	protected function wc_variable_product( array $prices_by_term = array( 'small' => '10', 'large' => '20' ), string $attribute = 'size', array $props = array() ): WC_Product_Variable {
		$attr = $this->wc_attribute( $attribute, array_map( 'strval', array_keys( $prices_by_term ) ) );

		$product_attribute = new WC_Product_Attribute();
		$product_attribute->set_id( $attr['id'] );
		$product_attribute->set_name( $attr['taxonomy'] );
		$product_attribute->set_options( array_values( $attr['term_ids'] ) );
		$product_attribute->set_position( 0 );
		$product_attribute->set_visible( true );
		$product_attribute->set_variation( true );

		$product = new WC_Product_Variable();
		$product->set_props(
			array_merge(
				array(
					'name'               => 'Fixture variable product',
					'status'             => 'publish',
					'catalog_visibility' => 'visible',
				),
				$props
			)
		);
		$product->set_attributes( array( $product_attribute ) );
		$product->save();

		foreach ( $prices_by_term as $term => $price ) {
			$term_object = get_term( $attr['term_ids'][ (string) $term ], $attr['taxonomy'] );
			if ( ! $term_object instanceof WP_Term ) {
				throw new RuntimeException( 'The attribute term fixture does not exist: ' . $term );
			}

			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( $attr['taxonomy'] => $term_object->slug ) );
			$variation->set_regular_price( (string) $price );
			$variation->set_status( 'publish' );
			$variation->save();
		}

		// By id, so the sync reads the variations just saved instead of the children the parent cached before them.
		WC_Product_Variable::sync( $product->get_id() );

		$synced = wc_get_product( $product->get_id() );
		if ( ! $synced instanceof WC_Product_Variable ) {
			throw new RuntimeException( 'The variable product fixture could not be read back.' );
		}

		return $synced;
	}

	/**
	 * Create a product category.
	 *
	 * @param string $name   Category name.
	 * @param int    $parent Parent category term id, 0 for a top-level category.
	 *
	 * @return int Term id.
	 */
	protected function wc_category( string $name, int $parent = 0 ): int {
		return $this->wc_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
	}

	/**
	 * Create a product tag.
	 *
	 * @param string $name Tag name.
	 *
	 * @return int Term id.
	 */
	protected function wc_tag( string $name ): int {
		return $this->wc_insert_term( $name, 'product_tag' );
	}

	/**
	 * Create a product brand.
	 *
	 * @param string $name Brand name.
	 *
	 * @return int Term id.
	 */
	protected function wc_brand( string $name ): int {
		return $this->wc_insert_term( $name, 'product_brand' );
	}

	/**
	 * Create a product review with a star rating.
	 *
	 * WordPress updates the comment count before it stores the comment meta, so an approved review recounts
	 * the product once more with the rating in place, like a review posted through the shop does.
	 *
	 * @param int $product_id Product id.
	 * @param int $rating     Star rating, 1 to 5.
	 * @param int $approved   1 for an approved review, 0 for a pending one.
	 *
	 * @return int Comment id.
	 */
	protected function wc_review( int $product_id, int $rating, int $approved = 1 ): int {
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_type'         => 'review',
				'comment_content'      => 'Fixture review',
				'comment_author'       => 'Fixture reviewer',
				'comment_author_email' => 'reviewer@example.org',
				'comment_approved'     => $approved,
				'comment_meta'         => array( 'rating' => $rating ),
			)
		);
		if ( ! $comment_id ) {
			throw new RuntimeException( 'The review fixture could not be created.' );
		}
		if ( 1 === $approved ) {
			wp_update_comment_count( $product_id );
		}

		return (int) $comment_id;
	}

	/**
	 * Create a tax rate, failing the test setup on an error.
	 *
	 * WooCommerce returns the insert id of the connection, which a failed insert leaves at the id of the last
	 * successful insert, so the id is cleared first to tell a failed insert from a stale one.
	 *
	 * @param array<string,mixed> $rate Tax rate columns (`tax_rate`, `tax_rate_name`, `tax_rate_class`, ...) over the defaults.
	 *
	 * @return int Tax rate id.
	 */
	protected function wc_tax_rate( array $rate ): int {
		global $wpdb;

		$wpdb->insert_id = 0;
		$id              = (int) WC_Tax::_insert_tax_rate(
			array_merge(
				array(
					'tax_rate_country'  => '',
					'tax_rate_state'    => '',
					'tax_rate'          => '10.0000',
					'tax_rate_name'     => 'Fixture tax',
					'tax_rate_priority' => 1,
					'tax_rate_compound' => 0,
					'tax_rate_shipping' => 1,
					'tax_rate_order'    => 0,
					'tax_rate_class'    => '',
				),
				$rate
			)
		);
		if ( ! $id ) {
			throw new RuntimeException( 'The tax rate fixture could not be created: ' . $wpdb->last_error );
		}

		return $id;
	}

	/**
	 * The route key of a Store API route, e.g. `/wc/store/v1/products`.
	 *
	 * @param string $path      Path below the namespace, e.g. `products` or `/products/categories`.
	 * @param string $namespace REST namespace.
	 *
	 * @return string
	 */
	protected function store_route( string $path, string $namespace = 'wc/store/v1' ): string {
		return untrailingslashit( '/' . trim( $namespace, '/' ) . '/' . ltrim( $path, '/' ) );
	}

	/**
	 * Create a term, failing the test setup on an error.
	 *
	 * @param string              $name     Term name.
	 * @param string              $taxonomy Taxonomy.
	 * @param array<string,mixed> $args     Arguments of `wp_insert_term()`.
	 *
	 * @return int Term id.
	 */
	private function wc_insert_term( string $name, string $taxonomy, array $args = array() ): int {
		$term = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $term ) ) {
			throw new RuntimeException( 'The ' . $taxonomy . ' fixture could not be created: ' . $term->get_error_message() );
		}

		return (int) $term['term_id'];
	}
}
