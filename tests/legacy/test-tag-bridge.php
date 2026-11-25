<?php
/**
 * Tests for the bridge of the tagging hooks of the original plugin: the object type, the single item flag, the
 * cache relations and the relations of the collected tags.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Api;
use Rockfire\Rest_Api_Cache\Invalidation\Rest_Tag_Collector;
use Rockfire\Rest_Api_Cache\Legacy\Tag_Bridge;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Request_State;
use Rockfire\Rest_Api_Cache\Storage\Cached_Response;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Factory;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Tag_Bridge
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Module
 */
class Test_Tag_Bridge extends Rfrac_E2e_Test_Case {

	/**
	 * The Api registered by the plugin boot, put back after the test.
	 *
	 * @var Api
	 */
	private Api $booted;

	/**
	 * The `[ hook, replacement ]` pairs of every `deprecated_hook_run` of the test.
	 *
	 * @var list<array{0:string,1:string}>
	 */
	private array $deprecated = array();

	public function set_up() {
		parent::set_up();
		$booted = Api::get();
		$this->assertInstanceOf( Api::class, $booted, 'The plugin registers the Api while booting.' );
		$this->booted = $booted;
		// The booted container wired its legacy hooks; the live request of the harness wires its own.
		foreach ( Plugin::instance()->legacy_hooks() as $hook ) {
			remove_filter( $hook['hook'], $hook['callback'], $hook['priority'] );
		}
		$this->deprecated = array();
		add_action(
			'deprecated_hook_run',
			function ( $hook, $replacement ): void {
				$this->deprecated[] = array( (string) $hook, (string) $replacement );
			},
			10,
			2
		);
		$this->configure( array( 'store_mode' => 'database' ) );
	}

	public function tear_down() {
		Api::register( $this->booted );
		parent::tear_down();
	}

	/**
	 * Register the routes of the tests (runs on `rest_api_init`).
	 *
	 * The public API of the plugin belongs to the booted container, whose collector sees no dispatch of the harness;
	 * it is pointed at the container of the live request, as the booted container does for its own requests.
	 *
	 * @return void
	 */
	public function register_bridge_routes(): void {
		$plugin = null !== $this->h ? ( new ReflectionProperty( Rfrac_Serving_Harness::class, 'plugin' ) )->getValue( $this->h ) : null;
		if ( $plugin instanceof Plugin ) {
			Api::register( new Api( $plugin->invalidator(), $plugin->tag_provider() ) );
		}

		$route = static function ( string $path, mixed $data ): void {
			register_rest_route(
				'tst/v1',
				$path,
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => static fn() => $data,
				)
			);
		};

		$route( '/item', array( 'id' => 7 ) );
		$route( '/items', array( array( 'id' => 7 ), array( 'id' => 9 ) ) );
		$route( '/users', array( array( 'id' => 3 ), array( 'id' => 4 ) ) );
	}

	/**
	 * The entry stored for the current request, null when there is none.
	 *
	 * @return Cached_Response|null
	 */
	private function stored(): ?Cached_Response {
		$config = Compiled_Config::load();
		$this->assertNotNull( $config );
		$context  = Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() );
		$decision = $this->policy()->evaluate( $context, $config );
		$this->assertCacheable( $decision );
		return Store_Factory::for_mode( 'database', new Repository(), 0 )->get( Cache_Key::key( $context, $decision ) );
	}

	/**
	 * The tags of the entry stored for the current request.
	 *
	 * @return list<string>
	 */
	private function stored_tags(): array {
		$entry = $this->stored();
		$this->assertInstanceOf( Cached_Response::class, $entry, 'The response was stored.' );
		return ( new Repository() )->tags_for( $entry->entry_id );
	}

	/**
	 * Run the lookup of a request that is not served from the cache, so its decision is recorded.
	 *
	 * @param string $uri Request URI.
	 *
	 * @return void
	 */
	private function lookup_of( string $uri ): void {
		$h = $this->harness( Rfrac_Serving_Harness::EARLY );
		$h->request( $uri );
		$this->assertFalse( $h->lookup() );
	}

	/**
	 * Run the lookup of a cacheable request and record it again as a request of another method.
	 *
	 * The policy of the engine decides the lookup, so the decision is the cacheable one of the GET request; only the
	 * context carries the method, as it does for a route that opted in to it.
	 *
	 * @param string $uri    Request URI.
	 * @param string $method Upper-case method of the recorded context.
	 *
	 * @return void
	 */
	private function lookup_of_method( string $uri, string $method ): void {
		$this->lookup_of( $uri );
		$decision = Request_State::decision();
		$this->assertNotNull( $decision );
		$this->assertTrue( $decision->cacheable, 'Precondition: the lookup was cacheable.' );

		$context = Request_Context::from_parts(
			array(
				'REQUEST_METHOD' => $method,
				'REQUEST_URI'    => $uri,
			),
			array(),
			array(),
			array(),
			rest_get_url_prefix(),
			'',
			true,
			1,
			false
		);
		Request_State::record_lookup( $context, $decision, Request_State::cache_key() );
	}

	/**
	 * A bridge over the collector of a main dispatch that has started.
	 *
	 * @return array{0:Tag_Bridge,1:Rest_Tag_Collector}
	 */
	private function dispatching_bridge(): array {
		$collector = ( new Plugin() )->tag_provider();
		$collector->on_main_dispatch();
		return array( new Tag_Bridge( $collector ), $collector );
	}

	/**
	 * Hook a spy that records the arguments of every run of a legacy hook of the tagging phase, and passes the value on.
	 *
	 * @param string                    $name Hook name.
	 * @param list<list<mixed>>         $runs The arguments of each run, filled as they happen.
	 * @param callable(mixed): mixed    $answer What a filter answers, given the value it got; default the value.
	 *
	 * @return void
	 */
	private function spy_on( string $name, array &$runs, ?callable $answer = null ): void {
		add_filter(
			$name,
			static function ( ...$args ) use ( &$runs, $answer ) {
				$runs[] = $args;
				return null === $answer ? ( $args[0] ?? null ) : $answer( $args[0] ?? null );
			},
			10,
			4
		);
	}

	// ---------- Registration ----------

	public function test_the_hooks_of_the_bridge_are_the_after_callbacks_and_the_collected_tags(): void {
		$this->assertSame(
			array(
				array( 'rest_request_after_callbacks', 'after_callbacks', 10, 3 ),
				array( Hooks::COLLECTED_TAGS, 'collected_tags', 10, 3 ),
			),
			Tag_Bridge::hooks()
		);
	}

	public function test_the_module_registers_the_bridge_among_the_legacy_hooks_only(): void {
		$plugin = new Plugin();

		$plugin->register_legacy_hooks();

		$legacy = array();
		foreach ( $plugin->legacy_hooks() as $hook ) {
			$legacy[ $hook['hook'] ][] = array( $hook['priority'], $hook['args'] );
		}
		$this->assertSame( array( array( 10, 3 ) ), $legacy['rest_request_after_callbacks'] );
		$this->assertSame( array( array( 10, 3 ) ), $legacy[ Hooks::COLLECTED_TAGS ] );
		$this->assertSame( array(), $plugin->engine_hooks(), 'No engine hook was registered.' );
	}

	// ---------- determine_object_type, is_single_item ----------

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_list_gets_the_list_tag_and_the_tag_of_every_item_and_a_flush_of_one_item_reaches_it( string $path ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/determine_object_type' );
		$this->setExpectedDeprecated( 'wp_rest_cache/is_single_item' );
		$types = array();
		$this->spy_on( 'wp_rest_cache/determine_object_type', $types, static fn() => 'post' );
		$singles = array();
		$this->spy_on( 'wp_rest_cache/is_single_item', $singles );
		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/tst/v1/items' );

		$this->assertNotNull( $h->miss( '/tst/v1/items', array( $this, 'register_bridge_routes' ) ) );

		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$tags = $this->stored_tags();
		$this->assertContains( 'list:post', $tags );
		$this->assertContains( 'post:7', $tags );
		$this->assertContains( 'post:9', $tags );
		$this->assertContains( array( 'wp_rest_cache/determine_object_type', Hooks::COLLECTED_TAGS ), $this->deprecated, 'The replacement hook is named.' );

		$entry = $this->stored();
		$this->assertInstanceOf( Cached_Response::class, $entry );
		$this->assertSame( 1, rfrac_flush_tag( 'post:7' ), 'The flush of one item reaches the entry.' );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $entry->entry_id ), 'The entry is flushed.' );

		$this->assertCount( 1, $types );
		$this->assertSame( 'unknown', $types[0][0] );
		$this->assertSame( '', $types[0][1], 'There is no cache key yet.' );
		$this->assertSame( array(), $types[0][2]['headers'] );
		$this->assertEquals( array( (object) array( 'id' => 7 ), (object) array( 'id' => 9 ) ), $types[0][2]['data'], 'The data is the decoded JSON of the response.' );
		$this->assertSame( '/wp-json/tst/v1/items', $types[0][3] );
		$this->assertCount( 1, $singles );
		$this->assertFalse( $singles[0][0], 'A list has no ID of its own.' );
		$this->assertSame( $types[0][2], $singles[0][1] );
		$this->assertSame( '/wp-json/tst/v1/items', $singles[0][2] );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_single_item_gets_the_list_tag_and_its_own_tag_only( string $path ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/determine_object_type' );
		$this->setExpectedDeprecated( 'wp_rest_cache/is_single_item' );
		add_filter( 'wp_rest_cache/determine_object_type', static fn() => 'post' );
		$singles = array();
		$this->spy_on( 'wp_rest_cache/is_single_item', $singles );
		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/tst/v1/item' );

		$this->assertNotNull( $h->miss( '/tst/v1/item', array( $this, 'register_bridge_routes' ) ) );

		$tags = $this->stored_tags();
		$this->assertContains( 'list:post', $tags );
		$this->assertContains( 'post:7', $tags );
		$this->assertNotContains( 'post:9', $tags );
		$this->assertCount( 1, $singles );
		$this->assertTrue( $singles[0][0], 'The data has an ID of its own.' );
		$this->assertEquals( (object) array( 'id' => 7 ), $singles[0][1]['data'] );
		$this->assertSame( '/wp-json/tst/v1/item', $singles[0][2] );
	}

	public function test_a_callback_of_is_single_item_decides_whether_the_top_level_id_or_the_items_are_tagged(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/determine_object_type' );
		$this->setExpectedDeprecated( 'wp_rest_cache/is_single_item' );
		add_filter( 'wp_rest_cache/determine_object_type', static fn() => 'post' );
		add_filter( 'wp_rest_cache/is_single_item', '__return_false' );
		$this->lookup_of( '/wp-json/tst/v1/item' );
		list( $bridge, $collector ) = $this->dispatching_bridge();

		$bridge->after_callbacks( new WP_REST_Response( array( 'id' => 7 ) ), array(), new WP_REST_Request( 'GET', '/tst/v1/item' ) );

		$this->assertSame( 0, $collector->item_tag_count(), 'Not a single item: an object has no list items to tag.' );
		$this->lookup_of( '/wp-json/tst/v1/items' );
		list( $bridge, $collector ) = $this->dispatching_bridge();

		$bridge->after_callbacks( new WP_REST_Response( array( array( 'id' => 7 ), array( 'id' => 9 ) ) ), array(), new WP_REST_Request( 'GET', '/tst/v1/items' ) );

		$this->assertSame( 2, $collector->item_tag_count(), 'A list: the ID of every item is tagged.' );
	}

	public function test_without_a_type_callback_the_type_is_unknown_and_only_the_relations_run(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/process_cache_relations' );
		$relations = array();
		$this->spy_on( 'wp_rest_cache/process_cache_relations', $relations );
		$this->lookup_of( '/wp-json/tst/v1/item' );
		list( $bridge, $collector ) = $this->dispatching_bridge();

		$bridge->after_callbacks( new WP_REST_Response( array( 'id' => 7 ) ), array(), new WP_REST_Request( 'GET', '/tst/v1/item' ) );

		$this->assertSame( 0, $collector->item_tag_count() );
		$this->assertCount( 1, $relations );
		$this->assertSame( 'unknown', $relations[0][2] );
		$this->assertNotContains( 'wp_rest_cache/determine_object_type', array_column( $this->deprecated, 0 ), 'Nothing hooked it.' );
	}

	public function test_a_type_that_is_not_a_string_counts_as_unknown(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/determine_object_type' );
		$this->setExpectedDeprecated( 'wp_rest_cache/process_cache_relations' );
		add_filter( 'wp_rest_cache/determine_object_type', static fn() => 42 );
		$relations = array();
		$this->spy_on( 'wp_rest_cache/process_cache_relations', $relations );
		$this->lookup_of( '/wp-json/tst/v1/item' );
		list( $bridge, $collector ) = $this->dispatching_bridge();

		$bridge->after_callbacks( new WP_REST_Response( array( 'id' => 7 ) ), array(), new WP_REST_Request( 'GET', '/tst/v1/item' ) );

		$this->assertSame( 0, $collector->item_tag_count() );
		$this->assertSame( 'unknown', $relations[0][2] );
	}

	public function test_the_type_maps_onto_the_tags_of_its_objects(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/determine_object_type' );
		$type = 'users';
		add_filter(
			'wp_rest_cache/determine_object_type',
			static function () use ( &$type ) {
				return $type;
			}
		);
		$this->lookup_of( '/wp-json/tst/v1/users' );

		list( $bridge, $collector ) = $this->dispatching_bridge();
		$bridge->after_callbacks( new WP_REST_Response( array( array( 'id' => 3 ), array( 'id' => 4 ) ) ), array(), new WP_REST_Request( 'GET', '/tst/v1/users' ) );
		$tags = $collector->collect( new WP_REST_Request( 'GET', '/tst/v1/users' ), array() );
		$this->assertContains( 'list:users', (array) $tags );
		$this->assertContains( 'user:3', (array) $tags );
		$this->assertContains( 'user:4', (array) $tags );

		$type = 'acf-options';
		list( $bridge, $collector ) = $this->dispatching_bridge();
		$bridge->after_callbacks( new WP_REST_Response( array( 'id' => 'header' ) ), array(), new WP_REST_Request( 'GET', '/tst/v1/users' ) );
		$tags = $collector->collect( new WP_REST_Request( 'GET', '/tst/v1/users' ), array() );
		$this->assertContains( 'acf-options:header', (array) $tags, 'A custom relation is a custom tag.' );
	}

	// ---------- process_cache_relations, insert_cache_relation ----------

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_relations_callback_can_tag_the_response_and_every_relation_is_reported_with_the_cache_id_zero( string $path ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/determine_object_type' );
		$this->setExpectedDeprecated( 'wp_rest_cache/process_cache_relations' );
		$this->setExpectedDeprecated( 'wp_rest_cache/insert_cache_relation' );
		add_filter( 'wp_rest_cache/determine_object_type', static fn() => 'post' );
		$received = array();
		$tagged   = null;
		add_action(
			'wp_rest_cache/process_cache_relations',
			static function ( $cache_id, $data, $type, $uri ) use ( &$received, &$tagged ): void {
				$received = array( $cache_id, $data, $type, $uri );
				$tagged   = rfrac_tag_response( 'custom:1' );
			},
			10,
			4
		);
		$relations = array();
		add_action(
			'wp_rest_cache/insert_cache_relation',
			static function ( $cache_id, $object_id, $object_type ) use ( &$relations ): void {
				$relations[] = array( $cache_id, $object_id, $object_type );
			},
			10,
			3
		);
		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/tst/v1/item' );

		$this->assertNotNull( $h->miss( '/tst/v1/item', array( $this, 'register_bridge_routes' ) ) );

		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertTrue( $tagged, 'The response could still be tagged.' );
		$tags = $this->stored_tags();
		$this->assertContains( 'custom:1', $tags );
		$this->assertContains( 'post:7', $tags );
		$this->assertContains( 'list:post', $tags );
		$this->assertSame( 0, $received[0], 'There is no cache row yet.' );
		$this->assertEquals( (object) array( 'id' => 7 ), $received[1]['data'] );
		$this->assertSame( array(), $received[1]['headers'] );
		$this->assertSame( 'post', $received[2] );
		$this->assertSame( '/wp-json/tst/v1/item', $received[3] );
		$this->assertSame( array( array( 0, 7, 'post' ) ), $relations, 'Only the object tags are relations.' );
		$this->assertNoFailures();
	}

	public function test_collected_tags_reports_every_item_tag_and_returns_the_tags_unchanged(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/insert_cache_relation' );
		$this->lookup_of( '/wp-json/tst/v1/item' );
		$calls = array();
		add_action(
			'wp_rest_cache/insert_cache_relation',
			static function ( $cache_id, $object_id, $object_type ) use ( &$calls ): void {
				$calls[] = array( $cache_id, $object_id, $object_type );
			},
			10,
			3
		);
		$tags = array( 'comment:5', 'custom:1', 'list:post', 'post:7', 'route:/tst/v1/item', 'term-ref:3', 'term:3', 'user:2' );
		list( $bridge ) = $this->dispatching_bridge();

		$returned = $bridge->collected_tags( $tags, new WP_REST_Request( 'GET', '/tst/v1/item' ), array() );

		$this->assertSame( $tags, $returned );
		$this->assertEqualsCanonicalizing(
			array( array( 0, 5, 'comment' ), array( 0, 7, 'post' ), array( 0, 3, 'term' ), array( 0, 2, 'user' ) ),
			$calls,
			'The post, term, user and comment tags are relations; list, route, term-ref and custom tags are not.'
		);
	}

	public function test_collected_tags_accepts_a_string_of_tags_and_leaves_any_other_value_alone(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/insert_cache_relation' );
		$this->lookup_of( '/wp-json/tst/v1/item' );
		$calls = array();
		$this->spy_on( 'wp_rest_cache/insert_cache_relation', $calls );
		list( $bridge ) = $this->dispatching_bridge();

		$this->assertSame( 'post:7, term:3', $bridge->collected_tags( 'post:7, term:3' ) );
		$this->assertSame( array( array( 0, 7, 'post' ), array( 0, 3, 'term' ) ), array_map( static fn( array $call ): array => array_slice( $call, 0, 3 ), $calls ) );

		$calls = array();
		$this->assertNull( $bridge->collected_tags( null ) );
		$this->assertSame( 12, $bridge->collected_tags( 12 ) );
		$this->assertSame( array(), $calls );
	}

	public function test_collected_tags_without_a_callback_fires_nothing(): void {
		$this->lookup_of( '/wp-json/tst/v1/item' );
		list( $bridge ) = $this->dispatching_bridge();

		$this->assertSame( array( 'post:7' ), $bridge->collected_tags( array( 'post:7' ) ) );

		$this->assertSame( array(), $this->deprecated );
	}

	// ---------- Only the main GET request of a cacheable lookup is bridged ----------

	/**
	 * Data provider: situations in which the bridge does nothing.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function untouched_requests(): array {
		return array(
			'outside a main dispatch'   => array( 'not_dispatching' ),
			'a POST request'            => array( 'post_request' ),
			'another route'             => array( 'other_route' ),
			'a 404 response'            => array( 'not_found' ),
			'a 500 response'            => array( 'server_error' ),
			'a 304 response'            => array( 'not_modified' ),
			'an error object'           => array( 'wp_error' ),
			'a lookup with credentials' => array( 'credentials' ),
			'no lookup'                 => array( 'no_lookup' ),
		);
	}

	/**
	 * @dataProvider untouched_requests
	 *
	 * @param string $case The situation.
	 */
	public function test_nothing_is_asked_nor_tagged_unless_the_main_get_dispatch_of_a_cacheable_lookup_succeeded( string $case ): void {
		$h = $this->harness( Rfrac_Serving_Harness::EARLY );
		$h->request( '/wp-json/tst/v1/item', 'credentials' === $case ? array( 'HTTP_AUTHORIZATION' => 'Bearer abc' ) : array() );
		$this->assertFalse( $h->lookup() );
		if ( 'no_lookup' === $case ) {
			Request_State::reset();
		}
		list( $bridge, $collector ) = $this->dispatching_bridge();
		if ( 'not_dispatching' === $case ) {
			$collector->reset();
		}
		$asked = array();
		foreach ( array( 'wp_rest_cache/determine_object_type', 'wp_rest_cache/is_single_item', 'wp_rest_cache/process_cache_relations' ) as $name ) {
			$this->spy_on( $name, $asked, static fn() => 'post' );
		}
		$status = array(
			'not_found'    => 404,
			'server_error' => 500,
			'not_modified' => 304,
		);
		$request  = new WP_REST_Request( 'post_request' === $case ? 'POST' : 'GET', 'other_route' === $case ? '/tst/v1/other' : '/tst/v1/item' );
		$response = 'wp_error' === $case ? new WP_Error( 'tst_error' ) : new WP_REST_Response( array( 'id' => 7 ), $status[ $case ] ?? 200 );

		$this->assertSame( $response, $bridge->after_callbacks( $response, array(), $request ), 'The response is returned as it is.' );

		$this->assertSame( array(), $asked, 'No legacy hook ran.' );
		$this->assertSame( array(), $this->deprecated );
		$this->assertSame( 0, $collector->item_tag_count() );
	}

	/**
	 * Data provider: the non-GET methods a request context can carry.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function non_get_methods(): array {
		return array(
			'POST'  => array( 'POST' ),
			'PUT'   => array( 'PUT' ),
			'PATCH' => array( 'PATCH' ),
		);
	}

	/**
	 * @dataProvider non_get_methods
	 *
	 * @param string $method Method of the recorded context and of the dispatch.
	 */
	public function test_the_relation_callbacks_run_when_the_dispatched_method_is_the_one_of_the_context( string $method ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/process_cache_relations' );
		$relations = array();
		$this->spy_on( 'wp_rest_cache/process_cache_relations', $relations );
		$this->lookup_of_method( '/wp-json/tst/v1/item', $method );
		list( $bridge ) = $this->dispatching_bridge();

		$bridge->after_callbacks( new WP_REST_Response( array( 'id' => 7 ) ), array(), new WP_REST_Request( $method, '/tst/v1/item' ) );

		$this->assertCount( 1, $relations );
		$this->assertSame( 'unknown', $relations[0][2] );
	}

	/**
	 * @dataProvider non_get_methods
	 *
	 * @param string $method Method of the recorded context.
	 */
	public function test_the_relation_callbacks_do_not_run_when_another_method_is_dispatched( string $method ): void {
		$relations = array();
		$this->spy_on( 'wp_rest_cache/process_cache_relations', $relations );
		$this->lookup_of_method( '/wp-json/tst/v1/item', $method );
		list( $bridge, $collector ) = $this->dispatching_bridge();

		$bridge->after_callbacks( new WP_REST_Response( array( 'id' => 7 ) ), array(), new WP_REST_Request( 'GET', '/tst/v1/item' ) );

		$this->assertSame( array(), $relations );
		$this->assertSame( array(), $this->deprecated );
		$this->assertSame( 0, $collector->item_tag_count() );
	}

	public function test_the_main_get_dispatch_of_a_cacheable_lookup_is_bridged(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/determine_object_type' );
		add_filter( 'wp_rest_cache/determine_object_type', static fn() => 'post' );
		$this->lookup_of( '/wp-json/tst/v1/item' );
		list( $bridge, $collector ) = $this->dispatching_bridge();
		$response                   = new WP_REST_Response( array( 'id' => 7 ), 201 );

		$this->assertSame( $response, $bridge->after_callbacks( $response, array(), new WP_REST_Request( 'GET', '/tst/v1/item' ) ) );

		$this->assertSame( 1, $collector->item_tag_count(), 'Any 2xx response counts.' );
	}

	public function test_the_data_a_route_callback_returns_without_a_response_object_is_tagged_too(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/determine_object_type' );
		$types = array();
		$this->spy_on( 'wp_rest_cache/determine_object_type', $types, static fn() => 'post' );
		$this->lookup_of( '/wp-json/tst/v1/item' );
		list( $bridge, $collector ) = $this->dispatching_bridge();
		$request                    = new WP_REST_Request( 'GET', '/tst/v1/item' );

		$this->assertNull( $bridge->after_callbacks( null, array(), $request ), 'Nothing to tag.' );
		$this->assertCount( 0, $types );
		$response = new WP_HTTP_Response( array( 'id' => 7 ) );
		$this->assertSame( $response, $bridge->after_callbacks( $response, array(), $request ), 'Not a REST response.' );
		$this->assertCount( 0, $types );

		$this->assertSame( array( 'id' => 7 ), $bridge->after_callbacks( array( 'id' => 7 ), array(), $request ), 'The data is returned as it is.' );

		$this->assertCount( 1, $types );
		$this->assertEquals( (object) array( 'id' => 7 ), $types[0][2]['data'] );
		$this->assertSame( 1, $collector->item_tag_count() );
	}

	// ---------- Failures ----------

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_throwing_type_callback_is_recorded_and_the_response_is_not_stored( string $path ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/determine_object_type' );
		add_filter(
			'wp_rest_cache/determine_object_type',
			static function () {
				throw new RuntimeException( 'type failed' );
			}
		);
		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/tst/v1/item' );

		$this->assertNotNull( $h->miss( '/tst/v1/item', array( $this, 'register_bridge_routes' ) ) );

		$this->assert_refused_and_recorded( $h, 'hook:wp_rest_cache/determine_object_type', 'type failed' );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_throwing_relations_callback_is_recorded_and_the_response_is_not_stored( string $path ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/determine_object_type' );
		$this->setExpectedDeprecated( 'wp_rest_cache/process_cache_relations' );
		add_filter( 'wp_rest_cache/determine_object_type', static fn() => 'post' );
		add_action(
			'wp_rest_cache/process_cache_relations',
			static function (): void {
				throw new RuntimeException( 'relations failed' );
			}
		);
		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/tst/v1/item' );

		$this->assertNotNull( $h->miss( '/tst/v1/item', array( $this, 'register_bridge_routes' ) ) );

		$this->assert_refused_and_recorded( $h, 'hook:wp_rest_cache/process_cache_relations', 'relations failed' );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_throwing_single_item_callback_is_recorded_and_the_response_is_not_stored( string $path ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/determine_object_type' );
		$this->setExpectedDeprecated( 'wp_rest_cache/is_single_item' );
		add_filter( 'wp_rest_cache/determine_object_type', static fn() => 'post' );
		add_filter(
			'wp_rest_cache/is_single_item',
			static function () {
				throw new RuntimeException( 'single failed' );
			}
		);
		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/tst/v1/item' );

		$this->assertNotNull( $h->miss( '/tst/v1/item', array( $this, 'register_bridge_routes' ) ) );

		$this->assert_refused_and_recorded( $h, 'hook:wp_rest_cache/is_single_item', 'single failed' );
	}

	public function test_a_throwing_callback_marks_the_collection_incomplete_even_when_another_callback_returned_before_it(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/determine_object_type' );
		add_filter( 'wp_rest_cache/determine_object_type', static fn() => 'post', 5 );
		add_filter(
			'wp_rest_cache/determine_object_type',
			static function () {
				throw new RuntimeException( 'type failed' );
			},
			10
		);
		$this->lookup_of( '/wp-json/tst/v1/item' );
		list( $bridge, $collector ) = $this->dispatching_bridge();

		$bridge->after_callbacks( new WP_REST_Response( array( 'id' => 7 ) ), array(), new WP_REST_Request( 'GET', '/tst/v1/item' ) );

		$this->assertNull( $collector->collect( new WP_REST_Request( 'GET', '/tst/v1/item' ), array() ), 'The tags are incomplete: nothing is stored.' );
		$this->assertSame( Bypass_Reason::ERROR, $collector->refusal() );
	}

	public function test_callbacks_that_return_leave_the_collection_complete_and_no_marker_hooked(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/determine_object_type' );
		$this->setExpectedDeprecated( 'wp_rest_cache/process_cache_relations' );
		add_filter( 'wp_rest_cache/determine_object_type', static fn() => 'post' );
		add_action( 'wp_rest_cache/process_cache_relations', '__return_null' );
		$this->lookup_of( '/wp-json/tst/v1/item' );
		list( $bridge, $collector ) = $this->dispatching_bridge();

		$bridge->after_callbacks( new WP_REST_Response( array( 'id' => 7 ) ), array(), new WP_REST_Request( 'GET', '/tst/v1/item' ) );

		$tags = $collector->collect( new WP_REST_Request( 'GET', '/tst/v1/item' ), array() );
		$this->assertContains( 'post:7', (array) $tags );
		$this->assertNull( $collector->refusal() );
		foreach ( array( 'wp_rest_cache/determine_object_type', 'wp_rest_cache/process_cache_relations' ) as $name ) {
			$this->assertSame( array( 10 ), array_keys( $GLOBALS['wp_filter'][ $name ]->callbacks ), 'Only the callback of the site is left hooked.' );
		}
		$this->assertNoFailures();
	}

	/**
	 * A missed request whose tags could not be established: the response is served, but nothing is stored.
	 *
	 * @param Rfrac_Serving_Harness $h       The harness of the request.
	 * @param string                $context The context the failure is recorded under.
	 * @param string                $message What the message of the failure holds.
	 *
	 * @return void
	 */
	private function assert_refused_and_recorded( Rfrac_Serving_Harness $h, string $context, string $message ): void {
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'A response is never stored without its tags.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->assertSame( Bypass_Reason::ERROR->value, $h->live_header( 'X-Rfrac-Cache-Reason' ) );
		}
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( $context, $last['context'], 'The failure is recorded under the legacy hook.' );
		$this->assertStringContainsString( $message, $last['message'] );
	}

	public function test_a_failure_of_the_bridge_itself_marks_the_collection_incomplete_so_nothing_is_stored(): void {
		// A callback is hooked, so the bridge works; it fails before it asks it.
		add_filter( 'wp_rest_cache/determine_object_type', static fn() => 'post' );
		$armed = false;
		add_filter(
			'rest_request_after_callbacks',
			static function ( $response ) use ( &$armed ) {
				$armed = true;
				return $response;
			},
			5
		);
		add_filter(
			'rest_request_after_callbacks',
			static function ( $response ) use ( &$armed ) {
				$armed = false;
				return $response;
			},
			20
		);
		add_filter(
			'rest_url_prefix',
			static function ( $prefix ) use ( &$armed ) {
				if ( $armed ) {
					throw new RuntimeException( 'prefix failed' );
				}
				return $prefix;
			}
		);
		$h = $this->harness( Rfrac_Serving_Harness::EARLY )->with_legacy();
		$h->request( '/wp-json/tst/v1/item' );

		$this->assertNotNull( $h->miss( '/tst/v1/item', array( $this, 'register_bridge_routes' ) ) );

		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'A response is never stored without its tags.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->assertSame( Bypass_Reason::ERROR->value, $h->live_header( 'X-Rfrac-Cache-Reason' ) );
		}
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'tags', $last['context'] );
		$this->assertStringContainsString( 'prefix failed', $last['message'] );
	}
}
