<?php
/**
 * The WooCommerce Store API routes the plugin knows about.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Integrations\WooCommerce;

use Automattic\WooCommerce\StoreApi\Routes\V1\ProductAttributes;
use Automattic\WooCommerce\StoreApi\Routes\V1\ProductAttributesById;
use Automattic\WooCommerce\StoreApi\Routes\V1\ProductAttributeTerms;
use Automattic\WooCommerce\StoreApi\Routes\V1\ProductBrands;
use Automattic\WooCommerce\StoreApi\Routes\V1\ProductBrandsById;
use Automattic\WooCommerce\StoreApi\Routes\V1\ProductCategories;
use Automattic\WooCommerce\StoreApi\Routes\V1\ProductCategoriesById;
use Automattic\WooCommerce\StoreApi\Routes\V1\ProductCollectionData;
use Automattic\WooCommerce\StoreApi\Routes\V1\ProductReviews;
use Automattic\WooCommerce\StoreApi\Routes\V1\Products;
use Automattic\WooCommerce\StoreApi\Routes\V1\ProductsById;
use Automattic\WooCommerce\StoreApi\Routes\V1\ProductsBySlug;
use Automattic\WooCommerce\StoreApi\Routes\V1\ProductTags;

defined( 'ABSPATH' ) || exit;

/**
 * The single home of the WooCommerce Store API route lists, the route-class kinds and the shared price tag.
 *
 * The patterns are the route regexes WooCommerce registers (the `get_path_regex()` of its public catalog
 * routes) under both Store API namespaces: the unversioned `/wc/store` and `/wc/store/v1`. They are compared
 * as plain strings against a route pattern exactly as the REST server holds it, never compiled. Every
 * session, account and checkout route is absent on purpose: the hard deny-list denies those.
 *
 * A route object is matched by its class, for the callers that run before the matched route pattern is set
 * (`rest_request_after_callbacks`). Whether such a route carries prices is read from
 * {@see self::PRICE_BEARING}, through the route's own `get_path_regex()`, so no second list of price-bearing
 * routes exists.
 *
 * @since 1.0.0
 */
final class Store_Routes {

	/**
	 * Tag carried by every price-bearing response; flushed when a price, a sale, a tax setting or a currency changes.
	 *
	 * @var string
	 */
	public const PRICE_TAG = 'wc-price:all';

	/**
	 * Route kind: the product list.
	 *
	 * @var string
	 */
	public const KIND_PRODUCTS = 'products';

	/**
	 * Route kind: one product, by id or by slug.
	 *
	 * @var string
	 */
	public const KIND_PRODUCT = 'product';

	/**
	 * Route kind: the product collection data (price range and counts).
	 *
	 * @var string
	 */
	public const KIND_COLLECTION_DATA = 'collection-data';

	/**
	 * Route kind: the product category list.
	 *
	 * @var string
	 */
	public const KIND_CATEGORIES = 'categories';

	/**
	 * Route kind: one product category.
	 *
	 * @var string
	 */
	public const KIND_CATEGORY = 'category';

	/**
	 * Route kind: the product tag list.
	 *
	 * @var string
	 */
	public const KIND_TAGS = 'tags';

	/**
	 * Route kind: the product brand list.
	 *
	 * @var string
	 */
	public const KIND_BRANDS = 'brands';

	/**
	 * Route kind: one product brand.
	 *
	 * @var string
	 */
	public const KIND_BRAND = 'brand';

	/**
	 * Route kind: the product attribute list.
	 *
	 * @var string
	 */
	public const KIND_ATTRIBUTES = 'attributes';

	/**
	 * Route kind: one product attribute.
	 *
	 * @var string
	 */
	public const KIND_ATTRIBUTE = 'attribute';

	/**
	 * Route kind: the terms of one product attribute.
	 *
	 * @var string
	 */
	public const KIND_ATTRIBUTE_TERMS = 'attribute-terms';

	/**
	 * Route kind: the product review list.
	 *
	 * @var string
	 */
	public const KIND_REVIEWS = 'reviews';

	/**
	 * The route class of every public catalog route, and the kind of response it builds.
	 *
	 * The classes serve both Store API namespaces. Nothing else is listed here: whether a class carries
	 * prices comes from {@see self::PRICE_BEARING}.
	 *
	 * @var array<class-string,string>
	 */
	private const ROUTE_KINDS = [
		Products::class              => self::KIND_PRODUCTS,
		ProductsById::class          => self::KIND_PRODUCT,
		ProductsBySlug::class        => self::KIND_PRODUCT,
		ProductCollectionData::class => self::KIND_COLLECTION_DATA,
		ProductCategories::class     => self::KIND_CATEGORIES,
		ProductCategoriesById::class => self::KIND_CATEGORY,
		ProductTags::class           => self::KIND_TAGS,
		ProductBrands::class         => self::KIND_BRANDS,
		ProductBrandsById::class     => self::KIND_BRAND,
		ProductAttributes::class     => self::KIND_ATTRIBUTES,
		ProductAttributesById::class => self::KIND_ATTRIBUTE,
		ProductAttributeTerms::class => self::KIND_ATTRIBUTE_TERMS,
		ProductReviews::class        => self::KIND_REVIEWS,
	];

	/**
	 * The Store API namespaces, as a route pattern starts with them: the unversioned one and `v1`.
	 *
	 * @var list<string>
	 */
	private const NAMESPACES = [ '/wc/store', '/wc/store/v1' ];

	/**
	 * The public Store API catalog routes, under both namespaces.
	 *
	 * @var list<string>
	 */
	public const CATALOG = [
		'/wc/store/products',
		'/wc/store/products/(?P<id>[\d]+)',
		'/wc/store/products/(?P<slug>[\S]+)',
		'/wc/store/products/attributes',
		'/wc/store/products/attributes/(?P<id>[\d]+)',
		'/wc/store/products/attributes/(?P<attribute_id>[\d]+)/terms',
		'/wc/store/products/categories',
		'/wc/store/products/categories/(?P<id>[\d]+)',
		'/wc/store/products/brands',
		'/wc/store/products/brands/(?P<identifier>[\w-]+)',
		'/wc/store/products/tags',
		'/wc/store/products/reviews',
		'/wc/store/products/collection-data',
		'/wc/store/v1/products',
		'/wc/store/v1/products/(?P<id>[\d]+)',
		'/wc/store/v1/products/(?P<slug>[\S]+)',
		'/wc/store/v1/products/attributes',
		'/wc/store/v1/products/attributes/(?P<id>[\d]+)',
		'/wc/store/v1/products/attributes/(?P<attribute_id>[\d]+)/terms',
		'/wc/store/v1/products/categories',
		'/wc/store/v1/products/categories/(?P<id>[\d]+)',
		'/wc/store/v1/products/brands',
		'/wc/store/v1/products/brands/(?P<identifier>[\w-]+)',
		'/wc/store/v1/products/tags',
		'/wc/store/v1/products/reviews',
		'/wc/store/v1/products/collection-data',
	];

	/**
	 * The catalog routes whose responses carry prices; a subset of {@see self::CATALOG}.
	 *
	 * @var list<string>
	 */
	public const PRICE_BEARING = [
		'/wc/store/products',
		'/wc/store/products/(?P<id>[\d]+)',
		'/wc/store/products/(?P<slug>[\S]+)',
		'/wc/store/products/collection-data',
		'/wc/store/v1/products',
		'/wc/store/v1/products/(?P<id>[\d]+)',
		'/wc/store/v1/products/(?P<slug>[\S]+)',
		'/wc/store/v1/products/collection-data',
	];

	/**
	 * Whether a route pattern is a public Store API catalog route.
	 *
	 * @param string $pattern Route pattern, as registered with the REST server.
	 *
	 * @return bool
	 */
	public static function is_catalog( string $pattern ): bool {
		return in_array( $pattern, self::CATALOG, true );
	}

	/**
	 * Whether a route pattern is a catalog route whose responses carry prices.
	 *
	 * @param string $pattern Route pattern, as registered with the REST server.
	 *
	 * @return bool
	 */
	public static function is_price_bearing( string $pattern ): bool {
		return in_array( $pattern, self::PRICE_BEARING, true );
	}

	/**
	 * The kind of catalog route a route object handles.
	 *
	 * @param mixed $route Route object, i.e. `$handler['callback'][0]` of a REST route handler.
	 *
	 * @return string|null One of the `KIND_*` constants; null for anything but the thirteen catalog route classes.
	 */
	public static function kind_of( mixed $route ): ?string {
		if ( ! is_object( $route ) ) {
			return null;
		}
		foreach ( self::ROUTE_KINDS as $class => $kind ) {
			if ( $route instanceof $class ) {
				return $kind;
			}
		}
		return null;
	}

	/**
	 * Whether a route object is the handler of a public Store API catalog route.
	 *
	 * @param mixed $route Route object, i.e. `$handler['callback'][0]` of a REST route handler.
	 *
	 * @return bool
	 */
	public static function is_catalog_route( mixed $route ): bool {
		return null !== self::kind_of( $route );
	}

	/**
	 * Whether a route object is the handler of a catalog route whose responses carry prices.
	 *
	 * The route's own path regex is put under each Store API namespace and looked up in {@see self::PRICE_BEARING}.
	 *
	 * @param mixed $route Route object, i.e. `$handler['callback'][0]` of a REST route handler.
	 *
	 * @return bool
	 */
	public static function is_price_bearing_route( mixed $route ): bool {
		if ( ! is_object( $route ) ) {
			return false;
		}
		foreach ( array_keys( self::ROUTE_KINDS ) as $class ) {
			if ( ! $route instanceof $class ) {
				continue;
			}
			foreach ( self::NAMESPACES as $namespace ) {
				if ( self::is_price_bearing( $namespace . $class::get_path_regex() ) ) {
					return true;
				}
			}
			return false;
		}
		return false;
	}
}
