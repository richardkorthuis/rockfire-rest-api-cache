<?php
/**
 * Tests for the Store API tag provider.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Api_Tagger;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes;
use Rockfire\Rest_Api_Cache\Invalidation\Oembed_Tagger;
use Rockfire\Rest_Api_Cache\Invalidation\Rest_Tag_Collector;
use Rockfire\Rest_Api_Cache\Invalidation\Route_Tag_Map;
use Rockfire\Rest_Api_Cache\Invalidation\Shape_Parser;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;

/**
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Api_Tagger
 */
class Test_Store_Api_Tagger extends Rfrac_Test_Case {

	use Rfrac_Wc_Test_Helpers;

	/**
	 * Collector the tagger adds its tags to.
	 *
	 * @var Rest_Tag_Collector
	 */
	private Rest_Tag_Collector $collector;

	/**
	 * Tagger under test.
	 *
	 * @var Store_Api_Tagger
	 */
	private Store_Api_Tagger $tagger;

	public function set_up() {
		parent::set_up();
		$this->reset_wc_state();
		$this->collector = new Rest_Tag_Collector( Config::instance(), new Route_Tag_Map(), new Oembed_Tagger(), new Shape_Parser() );
		$this->collector->register();
		$this->tagger = new Store_Api_Tagger( $this->collector );
		$this->tagger->register();
		$this->new_rest_server();
	}

	public function tear_down() {
		$GLOBALS['wp_current_filter'] = array(); // A filter that threw left its name on core's stack of current filters.
		delete_option( Failure_Recorder::OPTION );
		$this->reset_wc_state();
		parent::tear_down();
	}

	/**
	 * Dispatch a GET request on the global server as a main dispatch, without collecting it.
	 *
	 * @param string              $route  Route.
	 * @param array<string,mixed> $params Query parameters.
	 *
	 * @return array{0:WP_REST_Request,1:WP_REST_Response}
	 */
	private function dispatch( string $route, array $params = array() ): array {
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params( $params );
		$this->collector->on_main_dispatch();
		$response = rest_do_request( $request );
		$response = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request ); // As `WP_REST_Server::serve_request()` does.
		return array( $request, $response );
	}

	/**
	 * Dispatch and collect.
	 *
	 * @param string              $route  Route.
	 * @param array<string,mixed> $params Query parameters.
	 *
	 * @return string[]|null
	 */
	private function collect( string $route, array $params = array() ): ?array {
		list( $request, $response ) = $this->dispatch( $route, $params );
		return $this->collector->collect( $request, $response->get_data() );
	}

	/**
	 * Dispatch, collect and drop the `route:` tag.
	 *
	 * @param string              $route  Route.
	 * @param array<string,mixed> $params Query parameters.
	 *
	 * @return string[]
	 */
	private function provider_tags( string $route, array $params = array() ): array {
		$tags = $this->collect( $route, $params );
		$this->assertIsArray( $tags );
		return array_values( array_filter( $tags, static fn( string $tag ): bool => ! str_starts_with( $tag, 'route:' ) ) );
	}

	/**
	 * Create an image attachment WooCommerce can render (it needs an attached file).
	 *
	 * @return int Attachment id.
	 */
	private function image(): int {
		return (int) self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/jpeg',
				'file'           => 'rfrac-' . wp_generate_password( 8, false ) . '.jpg',
			)
		);
	}

	/**
	 * Create a simple product in a category with a tag, a brand, a `pa_color` term and a gallery image.
	 *
	 * @return array{product:int,category:int,tag:int,brand:int,attribute:int,term:int,image:int}
	 */
	private function catalog_product(): array {
		$category = $this->wc_category( 'Shoes' );
		$tag      = $this->wc_tag( 'Summer' );
		$brand    = $this->wc_brand( 'Acme' );
		$color    = $this->wc_attribute( 'color', array( 'red' ) );
		$image    = $this->image();

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( $color['id'] );
		$attribute->set_name( $color['taxonomy'] );
		$attribute->set_options( array_values( $color['term_ids'] ) );
		$attribute->set_visible( true );
		$attribute->set_variation( false );

		$product = $this->wc_product(
			array(
				'slug'              => 'tagged-product',
				'category_ids'      => array( $category ),
				'tag_ids'           => array( $tag ),
				'gallery_image_ids' => array( $image ),
				'attributes'        => array( $attribute ),
			)
		);
		wp_set_object_terms( $product->get_id(), array( $brand ), 'product_brand' );
		clean_post_cache( $product->get_id() );

		return array(
			'product'   => $product->get_id(),
			'category'  => $category,
			'tag'       => $tag,
			'brand'     => $brand,
			'attribute' => $color['id'],
			'term'      => $color['term_ids']['red'],
			'image'     => $image,
		);
	}

	public function test_products_list_tags(): void {
		$f = $this->catalog_product();

		$tags = $this->provider_tags( '/wc/store/v1/products' );

		foreach ( array( 'list:product', 'post:' . $f['product'], 'term:' . $f['category'], 'term:' . $f['tag'], 'term:' . $f['brand'], 'term:' . $f['term'], 'wc-attribute:' . $f['attribute'], 'post:' . $f['image'], 'wc-price:all' ) as $expected ) {
			$this->assertContains( $expected, $tags );
		}
		$this->assertSame( array(), preg_grep( '/^term-ref:/', $tags ), 'Terms shown by name are never term-ref tags.' );
		$this->assertNull( $this->collector->refusal() );
	}

	public function test_a_popularity_sorted_products_list_carries_the_popularity_tag(): void {
		$f = $this->catalog_product();

		$tags = $this->provider_tags( '/wc/store/v1/products', array( 'orderby' => 'popularity' ) );

		foreach ( array( 'list:wc-popularity', 'list:product', 'post:' . $f['product'] ) as $expected ) {
			$this->assertContains( $expected, $tags );
		}
		$this->assertNull( $this->collector->refusal() );
	}

	public function test_a_products_list_in_another_order_carries_no_popularity_tag(): void {
		$this->catalog_product();

		$this->assertNotContains( 'list:wc-popularity', $this->provider_tags( '/wc/store/v1/products' ), 'The default order is not sorted by popularity.' );
		$this->assertNotContains( 'list:wc-popularity', $this->provider_tags( '/wc/store/v1/products', array( 'orderby' => 'price' ) ) );
		$this->assertContains( 'list:product', $this->provider_tags( '/wc/store/v1/products', array( 'orderby' => 'price' ) ) );
	}

	public function test_products_list_tags_in_the_unversioned_namespace(): void {
		$f = $this->catalog_product();

		$tags = $this->provider_tags( '/wc/store/products' );

		foreach ( array( 'list:product', 'post:' . $f['product'], 'term:' . $f['category'], 'wc-price:all' ) as $expected ) {
			$this->assertContains( $expected, $tags );
		}
	}

	public function test_fields_do_not_hide_store_api_tags(): void {
		$f = $this->catalog_product();

		list( $request, $response ) = $this->dispatch( '/wc/store/v1/products', array( '_fields' => 'id' ) );
		$data                       = $response->get_data();
		$this->assertContains( array( 'id' => $f['product'] ), $data, '_fields reduced the response to the id.' );

		$tags = $this->collector->collect( $request, $data );
		$this->assertIsArray( $tags );
		foreach ( array( 'list:product', 'post:' . $f['product'], 'term:' . $f['category'], 'term:' . $f['tag'], 'term:' . $f['brand'], 'term:' . $f['term'], 'wc-attribute:' . $f['attribute'], 'post:' . $f['image'], 'wc-price:all' ) as $expected ) {
			$this->assertContains( $expected, $tags );
		}
	}

	public function test_variable_product_and_variation_tags(): void {
		$variable = $this->wc_variable_product();
		list( $x, $y ) = $variable->get_children();
		$v             = $variable->get_id();

		$tags = $this->provider_tags( '/wc/store/products/' . $v );
		foreach ( array( 'post:' . $v, 'post:' . $x, 'post:' . $y, 'wc-price:all' ) as $expected ) {
			$this->assertContains( $expected, $tags );
		}
		$this->assertNotContains( 'list:product', $tags, 'A single product has no list tag.' );

		$tags = $this->provider_tags( '/wc/store/products/' . $x );
		$this->assertContains( 'post:' . $x, $tags );
		$this->assertContains( 'post:' . $v, $tags, 'A variation is tagged with its parent.' );
	}

	public function test_grouped_product_children_are_tagged(): void {
		$a = $this->wc_product( array( 'name' => 'Child A' ) )->get_id();
		$b = $this->wc_product( array( 'name' => 'Child B' ) )->get_id();

		$grouped = new WC_Product_Grouped();
		$grouped->set_props(
			array(
				'name'               => 'Fixture grouped product',
				'status'             => 'publish',
				'catalog_visibility' => 'visible',
			)
		);
		$grouped->set_children( array( $a, $b ) );
		$grouped->save();

		$tags = $this->provider_tags( '/wc/store/v1/products/' . $grouped->get_id() );

		$this->assertContains( 'post:' . $grouped->get_id(), $tags );
		$this->assertContains( 'post:' . $a, $tags );
		$this->assertContains( 'post:' . $b, $tags );
	}

	public function test_a_product_has_the_same_tags_by_slug_and_by_id(): void {
		$f = $this->catalog_product();

		$by_id   = $this->provider_tags( '/wc/store/v1/products/' . $f['product'] );
		$by_slug = $this->provider_tags( '/wc/store/v1/products/tagged-product' );

		$this->assertContains( 'post:' . $f['product'], $by_slug );
		$this->assertContains( 'wc-price:all', $by_slug );
		$this->assertSame( $by_id, $by_slug );
	}

	/**
	 * The term routes under both namespaces.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function term_routes(): array {
		$cases = array();
		foreach ( array( 'wc/store/v1', 'wc/store' ) as $namespace ) {
			foreach ( array( 'categories', 'category', 'tags', 'brands', 'brand', 'attributes', 'attribute', 'attribute terms' ) as $route ) {
				$cases[ $namespace . ' ' . $route ] = array( $route, $namespace );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider term_routes
	 *
	 * @param string $route     Route name.
	 * @param string $namespace REST namespace.
	 */
	public function test_term_route_tags( string $route, string $namespace ): void {
		$category       = $this->wc_category( 'Hats' );
		$category_image = $this->image();
		add_term_meta( $category, 'thumbnail_id', $category_image );
		$tag         = $this->wc_tag( 'Winter' );
		$brand       = $this->wc_brand( 'Globex' );
		$brand_image = $this->image();
		add_term_meta( $brand, 'thumbnail_id', $brand_image );
		$color = $this->wc_attribute( 'color', array( 'red' ) );
		$red   = $color['term_ids']['red'];

		$cases = array(
			'categories'      => array( 'products/categories', array( 'include' => array( $category ), 'hide_empty' => false ), array( 'list:product_cat', 'term:' . $category, 'post:' . $category_image ) ),
			'category'        => array( 'products/categories/' . $category, array(), array( 'term:' . $category, 'list:product_cat', 'post:' . $category_image ) ),
			'tags'            => array( 'products/tags', array( 'include' => array( $tag ), 'hide_empty' => false ), array( 'list:product_tag', 'term:' . $tag ) ),
			'brands'          => array( 'products/brands', array( 'include' => array( $brand ), 'hide_empty' => false ), array( 'list:product_brand', 'term:' . $brand, 'post:' . $brand_image ) ),
			'brand'           => array( 'products/brands/' . $brand, array(), array( 'term:' . $brand, 'list:product_brand', 'post:' . $brand_image ) ),
			'attributes'      => array( 'products/attributes', array(), array( 'list:wc-attribute', 'wc-attribute:' . $color['id'] ) ),
			'attribute'       => array( 'products/attributes/' . $color['id'], array(), array( 'wc-attribute:' . $color['id'] ) ),
			'attribute terms' => array( 'products/attributes/' . $color['id'] . '/terms', array( 'hide_empty' => false ), array( 'wc-attribute:' . $color['id'], 'list:pa_color', 'term:' . $red ) ),
		);
		list( $path, $params, $expected ) = $cases[ $route ];

		$tags = $this->provider_tags( $this->store_route( $path, $namespace ), $params );

		$this->assertEqualsCanonicalizing( $expected, $tags );
		$this->assertNull( $this->collector->refusal() );
	}

	public function test_review_tags(): void {
		$image   = $this->image();
		$product = $this->wc_product( array( 'image_id' => $image ) );
		$review  = $this->wc_review( $product->get_id(), 5 );
		$pending = $this->wc_review( $product->get_id(), 1, 0 );

		$tags = $this->provider_tags( '/wc/store/v1/products/reviews' );

		$this->assertEqualsCanonicalizing( array( 'list:wc-review', 'comment:' . $review, 'post:' . $product->get_id(), 'post:' . $image ), $tags );
		$this->assertNotContains( 'comment:' . $pending, $tags, 'A pending review is not shown.' );
		$this->assertNotContains( 'list:product', $tags );
		$this->assertNotContains( Store_Routes::PRICE_TAG, $tags );

		$category = $this->wc_category( 'Reviewed' );
		wp_set_object_terms( $product->get_id(), array( $category ), 'product_cat' );
		$this->assertContains( 'list:product', $this->provider_tags( '/wc/store/v1/products/reviews', array( 'category_id' => (string) $category ) ) );
		$this->assertContains( 'list:product', $this->provider_tags( '/wc/store/v1/products/reviews', array( 'product_id' => (string) $product->get_id() ) ) );
	}

	public function test_collection_data_tags(): void {
		$this->wc_product();

		$tags = $this->collect( '/wc/store/v1/products/collection-data', array( 'calculate_price_range' => 1 ) );

		$this->assertSame( array( 'list:product', 'route:/wc/store/v1/products/collection-data', 'wc-price:all' ), $tags, 'No tag comes from the shape parser.' );
	}

	public function test_a_categories_response_carries_no_price_tag(): void {
		$category = $this->wc_category( 'Cheap' );

		$tags = $this->provider_tags( '/wc/store/v1/products/categories', array( 'include' => array( $category ), 'hide_empty' => false ) );

		$this->assertContains( 'term:' . $category, $tags );
		$this->assertNotContains( Store_Routes::PRICE_TAG, $tags );
	}

	public function test_an_error_response_adds_no_tags(): void {
		list( $request, $response ) = $this->dispatch( '/wc/store/v1/products/999999' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 0, $this->collector->item_tag_count() );
		$tags = $this->collector->collect( $request, $response->get_data() );
		$this->assertIsArray( $tags );
		$this->assertNotContains( Store_Routes::PRICE_TAG, $tags );
		$this->assertNotContains( 'list:product', $tags );
	}

	public function test_a_throwing_filter_refuses_the_response(): void {
		$this->wc_product();
		// The tagger's first call into the collector resolves the tag limit; only that call, inside the provider's hook, fails.
		// Once: a filter that throws leaves its name on core's stack of current filters, so a second check would still see the hook.
		$thrown = false;
		add_filter(
			Hooks::MAX_TAGS_PER_ENTRY,
			static function ( $max ) use ( &$thrown ) {
				if ( ! $thrown && doing_filter( 'rest_request_after_callbacks' ) ) {
					$thrown = true;
					throw new RuntimeException( 'The provider fails.' );
				}
				return $max;
			}
		);

		$tags = $this->collect( '/wc/store/v1/products' );

		$this->assertNull( $tags );
		$this->assertSame( Bypass_Reason::ERROR, $this->collector->refusal() );
		$this->assertSame( 'tags', Failure_Recorder::last()['context'] ?? null );
	}

	public function test_the_response_is_returned_untouched(): void {
		$response = new WP_REST_Response( array( 'id' => 1 ), 200 );
		$request  = new WP_REST_Request( 'GET', '/wc/store/v1/products' );
		$handler  = array( 'callback' => array( new stdClass(), 'get_response' ) );

		$this->assertSame( $response, $this->tagger->on_after_callbacks( $response, $handler, $request ), 'Outside a dispatch.' );

		$this->collector->on_main_dispatch();
		$this->assertSame( $response, $this->tagger->on_after_callbacks( $response, $handler, $request ), 'Not a catalog route.' );
		$this->assertSame( $response, $this->tagger->on_after_callbacks( $response, null, null ), 'No handler.' );
		$error = new WP_Error( 'x' );
		$this->assertSame( $error, $this->tagger->on_after_callbacks( $error, $handler, $request ), 'An error.' );
		$this->assertSame( 0, $this->collector->item_tag_count() );
	}

	public function test_other_routes_are_left_alone(): void {
		self::factory()->post->create();
		$with_tagger = $this->collect( '/wp/v2/posts' );

		remove_filter( 'rest_request_after_callbacks', array( $this->tagger, 'on_after_callbacks' ), 10 );
		$without_tagger = $this->collect( '/wp/v2/posts' );

		$this->assertSame( $without_tagger, $with_tagger );
		$this->assertNotContains( Store_Routes::PRICE_TAG, $with_tagger );
	}

	public function test_nothing_is_added_outside_a_main_dispatch(): void {
		$this->catalog_product();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/products' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $this->collector->item_tag_count() );
		$this->assertSame( array( 'route:/x' ), $this->collector->collect( new WP_REST_Request( 'GET', '/x' ), null ) );
	}

	/**
	 * Dispatch a request and call the tagger again, with the arguments the filter was given, while queries are recorded.
	 *
	 * @param string              $route  Route.
	 * @param array<string,mixed> $params Query parameters.
	 *
	 * @return Rfrac_Query_Recorder
	 */
	private function record_provider_queries( string $route, array $params = array() ): Rfrac_Query_Recorder {
		$seen = array();
		add_filter(
			'rest_request_after_callbacks',
			static function ( $response, $handler = null, $request = null ) use ( &$seen ) {
				$seen = array( $response, $handler, $request );
				return $response;
			},
			1,
			3
		);
		$this->dispatch( $route, $params );
		$this->assertNotSame( array(), $seen, 'The route was dispatched.' );

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$this->tagger->on_after_callbacks( ...$seen );
		$recorder->stop();
		$this->collector->reset();

		return $recorder;
	}

	public function test_the_provider_runs_no_query_for_a_products_list(): void {
		$this->catalog_product();

		$this->assertSame( array(), $this->record_provider_queries( '/wc/store/v1/products' )->matching( '/./' ) );
	}

	public function test_the_provider_runs_at_most_one_cached_lookup_for_attribute_terms(): void {
		$color = $this->wc_attribute( 'color', array( 'red', 'blue' ) );

		$queries = $this->record_provider_queries( '/wc/store/v1/products/attributes/' . $color['id'] . '/terms' )->matching( '/./' );

		$this->assertLessThanOrEqual( 1, count( $queries ), implode( "\n", $queries ) );
	}

	public function test_price_bearing_handlers_are_exactly_the_price_bearing_routes(): void {
		$routes = rest_get_server()->get_routes();

		foreach ( Store_Routes::PRICE_BEARING as $pattern ) {
			$this->assertTrue( Store_Routes::is_price_bearing_route( $this->route_object( $routes, $pattern ) ), $pattern );
		}
		foreach ( Store_Routes::CATALOG as $pattern ) {
			$route = $this->route_object( $routes, $pattern );
			$this->assertTrue( Store_Routes::is_catalog_route( $route ), $pattern );
			$this->assertNotNull( Store_Routes::kind_of( $route ), $pattern );
			$this->assertSame( Store_Routes::is_price_bearing( $pattern ), Store_Routes::is_price_bearing_route( $route ), $pattern );
		}
		foreach ( array_keys( $routes ) as $pattern ) {
			if ( ! str_starts_with( $pattern, '/wc/store' ) || in_array( $pattern, Store_Routes::CATALOG, true ) ) {
				continue;
			}
			$route = $this->route_object( $routes, $pattern );
			$this->assertFalse( Store_Routes::is_catalog_route( $route ), $pattern . ' is not a catalog route.' );
			$this->assertNull( Store_Routes::kind_of( $route ), $pattern );
			$this->assertFalse( Store_Routes::is_price_bearing_route( $route ), $pattern );
		}
	}

	/**
	 * The tags the tagger adds for a 200 response of the handler of a route pattern, with no response data.
	 *
	 * @param array<string,mixed> $routes  Routes of the server.
	 * @param string              $pattern Route pattern.
	 *
	 * @return string[]
	 */
	private function tags_of_route_handler( array $routes, string $pattern ): array {
		$request = new WP_REST_Request( 'GET', '/wc/store/v1/products' ); // The tagger reads the handler, never the route of the request.
		$handler = array( 'callback' => array( $this->route_object( $routes, $pattern ), 'get_response' ) );

		$this->collector->on_main_dispatch();
		$this->tagger->on_after_callbacks( new WP_REST_Response( array(), 200 ), $handler, $request );
		$tags = $this->collector->collect( $request, null );

		$this->assertIsArray( $tags, $pattern );
		return $tags;
	}

	public function test_a_response_carries_the_price_tag_exactly_when_its_route_is_in_the_price_bearing_list(): void {
		$routes = rest_get_server()->get_routes();

		foreach ( Store_Routes::CATALOG as $pattern ) {
			$tags = $this->tags_of_route_handler( $routes, $pattern );

			if ( Store_Routes::is_price_bearing( $pattern ) ) {
				$this->assertContains( Store_Routes::PRICE_TAG, $tags, $pattern . ' is price-bearing.' );
			} else {
				$this->assertNotContains( Store_Routes::PRICE_TAG, $tags, $pattern . ' carries no price.' );
			}
		}
	}

	public function test_the_tagger_keeps_no_route_list_of_its_own(): void {
		$lists = array_filter( ( new ReflectionClass( Store_Api_Tagger::class ) )->getConstants(), 'is_array' );

		$this->assertSame( array(), $lists, 'The routes, their kinds and their price-bearing status live in Store_Routes.' );
		$this->assertFalse( method_exists( Store_Api_Tagger::class, 'is_price_bearing_route' ) );
		$this->assertFalse( method_exists( Store_Api_Tagger::class, 'is_catalog_route' ) );
	}

	/**
	 * The route object of the first handler of a route pattern.
	 *
	 * @param array<string,mixed> $routes  Routes of the server.
	 * @param string              $pattern Route pattern.
	 *
	 * @return mixed
	 */
	private function route_object( array $routes, string $pattern ) {
		$this->assertArrayHasKey( $pattern, $routes );
		$handlers = $routes[ $pattern ];
		$this->assertIsArray( $handlers );
		foreach ( $handlers as $handler ) {
			if ( is_array( $handler ) && isset( $handler['callback'] ) && is_array( $handler['callback'] ) ) {
				return $handler['callback'][0];
			}
		}
		return null;
	}

	public function test_the_module_subscribes_the_tagger_to_the_after_callbacks_filter(): void {
		$plugin = new Plugin();
		$plugin->register_integrations();

		$hooks = array_values( array_filter( $plugin->engine_hooks(), static fn( array $hook ): bool => 'rest_request_after_callbacks' === $hook['hook'] ) );

		$this->assertCount( 1, $hooks );
		$this->assertSame( 10, $hooks[0]['priority'] );
		$this->assertSame( 3, $hooks[0]['args'] );
		$this->assertSame( array( array( 'rest_request_after_callbacks', 'on_after_callbacks', 10, 3 ) ), Store_Api_Tagger::hooks() );
	}
}
