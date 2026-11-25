<?php
/**
 * WooCommerce cache tag vocabulary.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The only place WooCommerce-specific invalidation tags are built.
 *
 * Pure static helpers: no WordPress or WooCommerce calls. Products, product terms and product attribute terms reuse
 * the core vocabulary (`Tags::post()`, `Tags::term()` and `Tags::list_for()`: `post:{ID}`, `term:{id}`,
 * `list:product`, `list:product_cat`, `list:product_tag`, `list:product_brand`, `list:pa_{name}`). The WooCommerce
 * list tags defined here are `list:wc-attribute`, `list:wc-review` and `list:wc-popularity` (product lists sorted by
 * popularity, which a sales count change reorders). The price tag `wc-price:all` is `Store_Routes::PRICE_TAG` and is
 * deliberately not defined here.
 *
 * Every tag built here is a valid `{prefix}:{value}` tag and is returned unchanged by `Tags::normalize()`.
 *
 * @since 1.0.0
 */
final class Woo_Tags {

	/**
	 * Tag for "any product attribute list changed".
	 *
	 * @var string
	 */
	public const LIST_ATTRIBUTES = 'list:wc-attribute';

	/**
	 * Tag for "any product review list changed".
	 *
	 * @var string
	 */
	public const LIST_REVIEWS = 'list:wc-review';

	/**
	 * Tag for "any product list changed".
	 *
	 * @var string
	 */
	public const LIST_PRODUCTS = 'list:product';

	/**
	 * Tag for "product lists sorted by popularity (sales count) changed".
	 *
	 * @var string
	 */
	public const LIST_POPULAR = 'list:wc-popularity';

	/**
	 * Largest number of objects a change may fan out to before the broader list tag is flushed instead.
	 *
	 * @var int
	 */
	public const MAX_FANOUT = 200;

	/**
	 * Tag for a product attribute (the attribute itself, not one of its terms).
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Attribute ID.
	 * @return string|null Tag, or null when the ID is below 1.
	 */
	public static function attribute( int $id ): ?string {
		return $id < 1 ? null : 'wc-attribute:' . $id;
	}
}
