<?php
/**
 * Tags the public WooCommerce Store API catalog responses with everything they show.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Integrations\WooCommerce;

use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Invalidation\Rest_Tag_Collector;
use Rockfire\Rest_Api_Cache\Invalidation\Tags;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the item, list and shared price tags of a Store API catalog response to the tag collector.
 *
 * It runs on `rest_request_after_callbacks`: after the route has built its response and before `_fields`
 * filters it, so every cached catalog response carries the tags of everything it shows whatever `_fields`
 * asks for. A product, term, attribute or review change then reaches the responses that show it without
 * any fan-out over the cached routes.
 *
 * The route is identified by the route object of the handler (`$handler['callback'][0]`), which covers both
 * Store API namespaces without matching a pattern. {@see Store_Routes} owns which route classes are catalog
 * routes, their kind and whether they carry prices; the tagger only looks that up. Only a 2xx response of one
 * of the thirteen public catalog routes is read; an error response, any other route and any request outside a
 * main dispatch are left alone. The response is always returned untouched, and the hook never runs a query:
 * it reads the response data and, for attribute terms, one cached WooCommerce lookup. When anything goes
 * wrong, the collection is marked incomplete with {@see Rest_Tag_Collector::fail_collection()}, so a response
 * is never stored without the tags that would invalidate it.
 *
 * Terms are shown by name, so they get `term:{id}` and never `term-ref:{id}`.
 *
 * @since 1.0.0
 */
final class Store_Api_Tagger {

	use Hook_Subscriber_Trait;

	/**
	 * The tag collector.
	 *
	 * @var Rest_Tag_Collector
	 */
	private Rest_Tag_Collector $collector;

	/**
	 * Create the tagger.
	 *
	 * @param Rest_Tag_Collector $collector The tag collector the tags are added to.
	 */
	public function __construct( Rest_Tag_Collector $collector ) {
		$this->collector = $collector;
	}

	/**
	 * The hooks: `rest_request_after_callbacks` (10, 3).
	 *
	 * @return array<int,array<int,mixed>>
	 *
	 * @phpstan-return list<array{0:string,1:'on_after_callbacks',2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		return array(
			array( 'rest_request_after_callbacks', 'on_after_callbacks', 10, 3 ),
		);
	}

	/**
	 * Filter `rest_request_after_callbacks`: add the tags of a catalog response to the collector.
	 *
	 * @param mixed $response Response.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  Request.
	 *
	 * @return mixed The response, untouched.
	 */
	public function on_after_callbacks( mixed $response, mixed $handler = null, mixed $request = null ): mixed {
		if ( ! $this->collector->is_dispatching() ) {
			return $response;
		}

		try {
			$route = self::route_of( $handler );
			$kind  = Store_Routes::kind_of( $route );
			if ( null === $kind || ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request ) {
				return $response;
			}
			$status = $response->get_status();
			if ( $status < 200 || $status >= 300 ) {
				return $response;
			}

			$tags = self::tags( $kind, $response->get_data(), $request );
			if ( Store_Routes::is_price_bearing_route( $route ) ) {
				$tags[] = Store_Routes::PRICE_TAG;
			}
			$this->collector->add_tags( $tags );
		} catch ( \Throwable $e ) {
			$this->collector->fail_collection( $e );
		}

		return $response;
	}

	/**
	 * The route object of a REST route handler.
	 *
	 * @param mixed $handler Route handler.
	 *
	 * @return object|null Null when the handler's callback is not a method of an object.
	 */
	private static function route_of( mixed $handler ): ?object {
		if ( ! is_array( $handler ) ) {
			return null;
		}
		$callback = $handler['callback'] ?? null;
		if ( ! is_array( $callback ) || ! isset( $callback[0] ) || ! is_object( $callback[0] ) ) {
			return null;
		}
		return $callback[0];
	}

	/**
	 * The item and list tags of a catalog response; the shared price tag is added by the caller.
	 *
	 * @param string           $kind    Route kind, one of the `Store_Routes::KIND_*` constants.
	 * @param mixed            $data    Response data.
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array<int,string>
	 *
	 * @phpstan-return list<string>
	 */
	private static function tags( string $kind, mixed $data, \WP_REST_Request $request ): array {
		$set = array();

		switch ( $kind ) {
			case Store_Routes::KIND_PRODUCTS:
				self::add( $set, Woo_Tags::LIST_PRODUCTS );
				if ( 'popularity' === $request->get_param( 'orderby' ) ) {
					self::add( $set, Woo_Tags::LIST_POPULAR );
				}
				foreach ( self::items( $data ) as $item ) {
					self::add_product( $set, $item );
				}
				break;

			case Store_Routes::KIND_PRODUCT:
				self::add_product( $set, $data );
				break;

			case Store_Routes::KIND_COLLECTION_DATA:
				self::add( $set, Woo_Tags::LIST_PRODUCTS );
				break;

			case Store_Routes::KIND_CATEGORIES:
				self::add( $set, Tags::list_for( 'product_cat' ) );
				foreach ( self::items( $data ) as $item ) {
					self::add_term( $set, $item, true );
				}
				break;

			case Store_Routes::KIND_CATEGORY:
				self::add( $set, Tags::list_for( 'product_cat' ) );
				self::add_term( $set, $data, true );
				break;

			case Store_Routes::KIND_TAGS:
				self::add( $set, Tags::list_for( 'product_tag' ) );
				foreach ( self::items( $data ) as $item ) {
					self::add_term( $set, $item, false );
				}
				break;

			case Store_Routes::KIND_BRANDS:
				self::add( $set, Tags::list_for( 'product_brand' ) );
				foreach ( self::items( $data ) as $item ) {
					self::add_term( $set, $item, true );
				}
				break;

			case Store_Routes::KIND_BRAND:
				self::add( $set, Tags::list_for( 'product_brand' ) );
				self::add_term( $set, $data, true );
				break;

			case Store_Routes::KIND_ATTRIBUTES:
				self::add( $set, Woo_Tags::LIST_ATTRIBUTES );
				self::add_each( $set, self::ids( $data ), Woo_Tags::attribute( ... ) );
				break;

			case Store_Routes::KIND_ATTRIBUTE:
				self::add( $set, Woo_Tags::attribute( self::int_of( self::field( $data, 'id' ) ) ) );
				break;

			case Store_Routes::KIND_ATTRIBUTE_TERMS:
				$attribute_id = self::int_of( $request->get_param( 'attribute_id' ) );
				self::add( $set, Woo_Tags::attribute( $attribute_id ) );
				self::add( $set, Tags::list_for( wc_attribute_taxonomy_name_by_id( $attribute_id ) ) );
				self::add_each( $set, self::ids( $data ), Tags::term( ... ) );
				break;

			case Store_Routes::KIND_REVIEWS:
				self::add( $set, Woo_Tags::LIST_REVIEWS );
				if ( ! empty( $request->get_param( 'category_id' ) ) || ! empty( $request->get_param( 'product_id' ) ) ) {
					self::add( $set, Woo_Tags::LIST_PRODUCTS );
				}
				foreach ( self::items( $data ) as $item ) {
					self::add( $set, Tags::comment( self::int_of( self::field( $item, 'id' ) ) ) );
					self::add( $set, Tags::post( self::int_of( self::field( $item, 'product_id' ) ) ) );
					self::add( $set, Tags::post( self::int_of( self::field( self::field( $item, 'product_image' ), 'id' ) ) ) );
				}
				break;
		}

		return array_keys( $set );
	}

	/**
	 * Add the tags of one product (or variation) item.
	 *
	 * The product itself, its parent, its variations and grouped children, the terms it shows by name
	 * (categories, tags, brands and attribute terms), its attributes and its images.
	 *
	 * @param array<string,true> $set  Tag set.
	 * @param mixed              $item Product item of the response data: an array, or an object.
	 *
	 * @return void
	 */
	private static function add_product( array &$set, mixed $item ): void {
		self::add( $set, Tags::post( self::int_of( self::field( $item, 'id' ) ) ) );
		self::add( $set, Tags::post( self::int_of( self::field( $item, 'parent' ) ) ) );
		self::add_each( $set, self::ids( self::field( $item, 'variations' ) ), Tags::post( ... ) );
		self::add_each( $set, self::ids( self::field( $item, 'grouped_products' ), null ), Tags::post( ... ) );
		self::add_each( $set, self::ids( self::field( $item, 'categories' ) ), Tags::term( ... ) );
		self::add_each( $set, self::ids( self::field( $item, 'tags' ) ), Tags::term( ... ) );
		self::add_each( $set, self::ids( self::field( $item, 'brands' ) ), Tags::term( ... ) );
		self::add_each( $set, self::ids( self::field( $item, 'images' ) ), Tags::post( ... ) );

		$attributes = self::field( $item, 'attributes' );
		self::add_each( $set, self::ids( $attributes ), Woo_Tags::attribute( ... ) );
		foreach ( self::items( $attributes ) as $attribute ) {
			self::add_each( $set, self::ids( self::field( $attribute, 'terms' ) ), Tags::term( ... ) );
		}
	}

	/**
	 * Add the tags of one term item of a category, tag or brand route.
	 *
	 * @param array<string,true> $set   Tag set.
	 * @param mixed              $item  Term item of the response data: an array, or an object.
	 * @param bool               $image Whether the term shows an image (categories and brands do).
	 *
	 * @return void
	 */
	private static function add_term( array &$set, mixed $item, bool $image ): void {
		self::add( $set, Tags::term( self::int_of( self::field( $item, 'id' ) ) ) );
		if ( $image ) {
			self::add( $set, Tags::post( self::int_of( self::field( self::field( $item, 'image' ), 'id' ) ) ) );
		}
	}

	/**
	 * Add one tag to the set; a null tag (an invalid id or name) is skipped.
	 *
	 * @param array<string,true> $set Tag set.
	 * @param string|null        $tag Tag.
	 *
	 * @return void
	 */
	private static function add( array &$set, ?string $tag ): void {
		if ( null !== $tag ) {
			$set[ $tag ] = true;
		}
	}

	/**
	 * Add the tags built from a list of ids.
	 *
	 * @param array<string,true> $set Tag set.
	 * @param array<int,int>     $ids Ids.
	 * @param \Closure           $tag Builds the tag of an id; null for an id that has no tag.
	 *
	 * @phpstan-param list<int>              $ids
	 * @phpstan-param \Closure(int): ?string $tag
	 *
	 * @return void
	 */
	private static function add_each( array &$set, array $ids, \Closure $tag ): void {
		foreach ( $ids as $id ) {
			self::add( $set, $tag( $id ) );
		}
	}

	/**
	 * The ids of a list of entries.
	 *
	 * @param mixed       $values List of entries, each an array or an object holding the id under `$key`, or the id itself when `$key` is null.
	 * @param string|null $key   Key of the id in an entry; null when each entry is the id.
	 *
	 * @return array<int,int> Ids above zero.
	 *
	 * @phpstan-return list<int>
	 */
	private static function ids( mixed $values, ?string $key = 'id' ): array {
		$ids = array();
		foreach ( self::items( $values ) as $entry ) {
			$id = self::int_of( null === $key ? $entry : self::field( $entry, $key ) );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * The entries of a list.
	 *
	 * @param mixed $values List.
	 *
	 * @return array<int,mixed> The entries; empty when the value is not an array.
	 *
	 * @phpstan-return list<mixed>
	 */
	private static function items( mixed $values ): array {
		return is_array( $values ) ? array_values( $values ) : array();
	}

	/**
	 * One field of an array or object item; Store API schemas return both, often nested.
	 *
	 * @param mixed  $item Array or object.
	 * @param string $key  Key or public property name.
	 *
	 * @return mixed The value; null when the item has no such field.
	 */
	private static function field( mixed $item, string $key ): mixed {
		if ( is_array( $item ) ) {
			return $item[ $key ] ?? null;
		}
		if ( is_object( $item ) ) {
			return get_object_vars( $item )[ $key ] ?? null;
		}
		return null;
	}

	/**
	 * A numeric value as an integer.
	 *
	 * @param mixed $value Value.
	 *
	 * @return int The integer; 0 when the value is not numeric.
	 */
	private static function int_of( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
