<?php
/**
 * Tests for the shim of the original plugin's `Caching` class.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Invalidation\Api;
use Rockfire\Rest_Api_Cache\Legacy\Shim_Notice;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching
 */
class Test_Shim_Caching extends Rfrac_E2e_Test_Case {

	/**
	 * Marks a parameter without a default in the signature table.
	 */
	private const REQUIRED = '__required__';

	/**
	 * The Api registered by the plugin boot.
	 *
	 * @var Api
	 */
	private Api $booted;

	/**
	 * Arguments of every `deprecated_function_run` since set_up().
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $deprecated = array();

	/**
	 * Arguments of every `doing_it_wrong_run` since set_up().
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $incorrect = array();

	/**
	 * `rfrac/flushed` events as `[ type, targets, affected ]`.
	 *
	 * @var array<int,array{0:string,1:array<int,string>,2:int}>
	 */
	private array $flushed = array();

	/**
	 * Tags of every `rfrac/stored` event since set_up().
	 *
	 * @var array<int,array<int,string>>
	 */
	private array $stored = array();

	public function set_up() {
		parent::set_up();
		$booted = Api::get();
		$this->assertInstanceOf( Api::class, $booted, 'The plugin registers the Api while booting.' );
		$this->booted     = $booted;
		$this->deprecated = array();
		$this->incorrect  = array();
		$this->flushed    = array();
		$this->stored     = array();
		Shim_Notice::reset();
		( new ReflectionProperty( Plugin::class, 'stores' ) )->setValue( Plugin::instance(), array() );
		$this->configure( array( 'store_mode' => 'database' ) );
		// A response with posts is stored; an empty result set never is.
		self::factory()->post->create_many( 3 );

		add_action(
			'deprecated_function_run',
			function ( ...$args ): void {
				$this->deprecated[] = $args;
			},
			10,
			3
		);
		add_action(
			'doing_it_wrong_run',
			function ( ...$args ): void {
				$this->incorrect[] = $args;
			},
			10,
			3
		);
		add_action(
			Hooks::FLUSHED,
			function ( $type, $targets, $affected ): void {
				$this->flushed[] = array( $type, $targets, $affected );
			},
			10,
			3
		);
		add_action(
			Hooks::STORED,
			function ( $key, $route, $tags ): void {
				$this->stored[] = $tags;
			},
			10,
			3
		);
	}

	public function tear_down() {
		Api::register( $this->booted );
		Shim_Notice::reset();
		( new ReflectionProperty( Plugin::class, 'stores' ) )->setValue( Plugin::instance(), array() );
		parent::tear_down();
	}

	/**
	 * The expected public signatures of the original 2026.3.1: method => parameter name => default.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function signatures(): array {
		$r = self::REQUIRED;
		return array(
			'get_instance'                         => array( array() ),
			'get_cache'                            => array( array( 'cache_key' => $r ) ),
			'set_cache'                            => array(
				array(
					'cache_key'       => $r,
					'value'           => $r,
					'type'            => $r,
					'uri'             => '',
					'object_type'     => '',
					'request_headers' => array(),
					'request_method'  => 'GET',
				),
			),
			'delete_cache'                         => array(
				array(
					'cache_key' => $r,
					'force'     => false,
				),
			),
			'delete_cache_by_endpoint'             => array(
				array(
					'endpoint'   => $r,
					'strictness' => 'strict',
					'force'      => false,
				),
			),
			'clear_caches'                         => array( array( 'force' => false ) ),
			'save_post'                            => array(
				array(
					'post_id' => $r,
					'post'    => $r,
					'update'  => $r,
				),
			),
			'delete_post'                          => array( array( 'post_id' => $r ) ),
			'transition_post_status'               => array(
				array(
					'new_status' => $r,
					'old_status' => $r,
					'post'       => $r,
				),
			),
			'updated_post_meta'                    => array(
				array(
					'meta_id'     => $r,
					'object_id'   => $r,
					'meta_key'    => $r,
					'_meta_value' => $r,
				),
			),
			'add_attachment'                       => array( array() ),
			'edit_attachment'                      => array( array( 'attachment_id' => $r ) ),
			'created_term'                         => array(
				array(
					'term_id'  => $r,
					'tt_id'    => $r,
					'taxonomy' => $r,
				),
			),
			'edited_term'                          => array(
				array(
					'term_id'  => $r,
					'tt_id'    => $r,
					'taxonomy' => $r,
				),
			),
			'delete_term'                          => array(
				array(
					'term'     => $r,
					'tt_id'    => $r,
					'taxonomy' => $r,
				),
			),
			'updated_term_meta'                    => array(
				array(
					'meta_id'     => $r,
					'object_id'   => $r,
					'meta_key'    => $r,
					'_meta_value' => $r,
				),
			),
			'set_object_terms'                     => array(
				array(
					'object_id'  => $r,
					'terms'      => $r,
					'tt_ids'     => $r,
					'taxonomy'   => $r,
					'append'     => $r,
					'old_tt_ids' => $r,
				),
			),
			'profile_update'                       => array( array( 'user_id' => $r ) ),
			'user_register'                        => array( array() ),
			'deleted_user'                         => array( array( 'user_id' => $r ) ),
			'updated_user_meta'                    => array(
				array(
					'meta_id'     => $r,
					'object_id'   => $r,
					'meta_key'    => $r,
					'_meta_value' => $r,
				),
			),
			'delete_comment_related_caches'        => array( array( 'comment_id' => $r ) ),
			'delete_comment_type_related_caches'   => array( array() ),
			'updated_comment_meta'                 => array(
				array(
					'meta_id'     => $r,
					'object_id'   => $r,
					'meta_key'    => $r,
					'_meta_value' => $r,
				),
			),
			'delete_all_caches'                    => array(
				array(
					'delete'       => $r,
					'cache_filter' => false,
				),
			),
			'delete_related_caches'                => array(
				array(
					'id'                  => $r,
					'object_type'         => $r,
					'force_single_delete' => false,
					'delete'              => false,
				),
			),
			'delete_object_type_caches'            => array(
				array(
					'object_type' => $r,
					'delete'      => false,
				),
			),
			'insert_cache_relation'                => array(
				array(
					'cache_id'    => $r,
					'object_id'   => $r,
					'object_type' => $r,
				),
			),
			'get_api_data'                         => array(
				array(
					'api_type'    => $r,
					'per_page'    => $r,
					'page_number' => $r,
				),
			),
			'get_record_count'                     => array( array( 'api_type' => $r ) ),
			'get_cache_data'                       => array( array( 'cache_key' => $r ) ),
			'get_timeout'                          => array(
				array(
					'calculated' => true,
					'options'    => array(),
				),
			),
			'get_timeout_interval'                 => array( array() ),
			'should_regenerate'                    => array( array() ),
			'get_regenerate_interval'              => array( array() ),
			'get_regenerate_number'                => array( array() ),
			'regenerate_expired_caches'            => array( array() ),
			'get_memcache_used'                    => array( array() ),
			'get_global_cacheable_request_headers' => array( array() ),
			'transient_key'                        => array( array( 'id' => $r ) ),
			'cleanup_deleted_caches'               => array( array() ),
			'update_database_structure'            => array( array() ),
		);
	}

	/**
	 * Every method that is neutral: method, arguments, the value it returns.
	 *
	 * @return array<string,array{0:string,1:array<int,mixed>,2:mixed}>
	 */
	public static function no_ops(): array {
		$meta = array( 1, 2, 'key', 'value' );
		return array(
			'get_cache'                            => array( 'get_cache', array( 'key' ), false ),
			'set_cache'                            => array( 'set_cache', array( 'key', array( 'a' => 1 ), 'endpoint' ), null ),
			'delete_cache'                         => array( 'delete_cache', array( 'key' ), null ),
			'get_api_data'                         => array( 'get_api_data', array( 'endpoint', 10, 1 ), array() ),
			'get_record_count'                     => array( 'get_record_count', array( 'endpoint' ), 0 ),
			'get_cache_data'                       => array( 'get_cache_data', array( 'key' ), null ),
			'should_regenerate'                    => array( 'should_regenerate', array(), false ),
			'get_regenerate_interval'              => array( 'get_regenerate_interval', array(), 'twicedaily' ),
			'get_regenerate_number'                => array( 'get_regenerate_number', array(), 10 ),
			'regenerate_expired_caches'            => array( 'regenerate_expired_caches', array(), null ),
			'cleanup_deleted_caches'               => array( 'cleanup_deleted_caches', array(), null ),
			'update_database_structure'            => array( 'update_database_structure', array(), null ),
			'get_memcache_used'                    => array( 'get_memcache_used', array(), false ),
			'get_global_cacheable_request_headers' => array( 'get_global_cacheable_request_headers', array(), '' ),
			'transient_key'                        => array( 'transient_key', array( 'abc' ), 'wp_rest_cache_abc' ),
			'save_post'                            => array( 'save_post', array( 1, null, true ), null ),
			'delete_post'                          => array( 'delete_post', array( 1 ), null ),
			'transition_post_status'               => array( 'transition_post_status', array( 'publish', 'draft', null ), null ),
			'updated_post_meta'                    => array( 'updated_post_meta', $meta, null ),
			'add_attachment'                       => array( 'add_attachment', array(), null ),
			'edit_attachment'                      => array( 'edit_attachment', array( 1 ), null ),
			'created_term'                         => array( 'created_term', array( 1, 1, 'category' ), null ),
			'edited_term'                          => array( 'edited_term', array( 1, 1, 'category' ), null ),
			'delete_term'                          => array( 'delete_term', array( 1, 1, 'category' ), null ),
			'updated_term_meta'                    => array( 'updated_term_meta', $meta, null ),
			'set_object_terms'                     => array( 'set_object_terms', array( 1, array( 2 ), array( 2 ), 'category', false, array() ), null ),
			'profile_update'                       => array( 'profile_update', array( 1 ), null ),
			'user_register'                        => array( 'user_register', array(), null ),
			'deleted_user'                         => array( 'deleted_user', array( 1 ), null ),
			'updated_user_meta'                    => array( 'updated_user_meta', $meta, null ),
			'delete_comment_related_caches'        => array( 'delete_comment_related_caches', array( 1 ), null ),
			'delete_comment_type_related_caches'   => array( 'delete_comment_type_related_caches', array(), null ),
			'updated_comment_meta'                 => array( 'updated_comment_meta', $meta, null ),
		);
	}

	/**
	 * The methods that map onto the public API instead of being neutral.
	 *
	 * @return string[]
	 */
	private static function working_methods(): array {
		return array(
			'get_instance',
			'delete_cache_by_endpoint',
			'clear_caches',
			'delete_all_caches',
			'delete_related_caches',
			'delete_object_type_caches',
			'insert_cache_relation',
			'get_timeout',
			'get_timeout_interval',
		);
	}

	/**
	 * Serve the posts list with two page sizes, so there are two entries of the same route.
	 *
	 * @param Rfrac_Serving_Harness $h Harness.
	 *
	 * @return void
	 */
	private function store_pages( Rfrac_Serving_Harness $h ): void {
		foreach ( array( 5, 10 ) as $per_page ) {
			$h->request( '/wp-json/wp/v2/posts?per_page=' . $per_page );
			$this->assertNotNull( $h->miss( '/wp/v2/posts' ), 'Stored: per_page=' . $per_page );
		}
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count() );
	}

	/**
	 * Whether a request for a URL is served from the cache.
	 *
	 * @param Rfrac_Serving_Harness $h   Harness.
	 * @param string                $uri Request URI.
	 *
	 * @return bool
	 */
	private function is_hit( Rfrac_Serving_Harness $h, string $uri ): bool {
		$h->request( $uri );
		return $h->lookup();
	}

	/**
	 * Insert an active entry of the posts route.
	 *
	 * @return int Entry id.
	 */
	private function route_entry(): int {
		return Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/posts' ) );
	}

	/**
	 * Make the registered Api use the container the serving harness built for its live request.
	 *
	 * The harness wires a container of its own, so its tag collector is not the one of the booted plugin.
	 *
	 * @param Rfrac_Serving_Harness $h Harness.
	 *
	 * @return void
	 */
	private function bind_api_to( Rfrac_Serving_Harness $h ): void {
		$plugin = ( new ReflectionProperty( Rfrac_Serving_Harness::class, 'plugin' ) )->getValue( $h );
		$this->assertInstanceOf( Plugin::class, $plugin );
		Api::register( new Api( $plugin->invalidator(), $plugin->tag_provider() ) );
	}

	/**
	 * A route registrar whose callback calls `insert_cache_relation()` with the given arguments.
	 *
	 * @param Rfrac_Serving_Harness $h           Harness.
	 * @param mixed                 $object_id   Object ID.
	 * @param mixed                 $object_type Object type.
	 *
	 * @return Closure
	 */
	private function relation_route( Rfrac_Serving_Harness $h, $object_id, $object_type ): Closure {
		return function () use ( $h, $object_id, $object_type ): void {
			$this->bind_api_to( $h );
			register_rest_route(
				'tst/v1',
				'/relation',
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => static function () use ( $object_id, $object_type ) {
						Caching::get_instance()->insert_cache_relation( 0, $object_id, $object_type );
						return array( 'a' => 1 );
					},
				)
			);
		};
	}

	public function test_get_instance_returns_one_shim_instance_with_the_constants_of_the_original(): void {
		$this->assertFalse( Coexistence_Guard::is_blocked(), 'Precondition: the guard does not block.' );

		$first  = Caching::get_instance();
		$second = Caching::get_instance();

		$this->assertInstanceOf( Caching::class, $first );
		$this->assertSame( $first, $second );
		$file = wp_normalize_path( (string) ( new ReflectionClass( $first ) )->getFileName() );
		$this->assertStringStartsWith( wp_normalize_path( RFRAC_DIR . 'includes/legacy/shims/' ), $file );
		$this->assertSame( '2026.1.0', Caching::DB_VERSION );
		$this->assertSame( 'wrc_caches', Caching::TABLE_CACHES );
		$this->assertSame( 'wrc_relations', Caching::TABLE_RELATIONS );
		$this->assertSame( 'strict', Caching::FLUSH_STRICT );
		$this->assertSame( 'params', Caching::FLUSH_PARAMS );
		$this->assertSame( 'loose', Caching::FLUSH_LOOSE );
		$this->assertSame(
			array( 'DB_VERSION', 'TABLE_CACHES', 'TABLE_RELATIONS', 'FLUSH_STRICT', 'FLUSH_PARAMS', 'FLUSH_LOOSE' ),
			array_keys( ( new ReflectionClass( Caching::class ) )->getConstants() )
		);
	}

	public function test_the_constructor_is_private(): void {
		$constructor = ( new ReflectionClass( Caching::class ) )->getConstructor();

		$this->assertNotNull( $constructor );
		$this->assertTrue( $constructor->isPrivate() );
	}

	public function test_every_public_method_has_the_signature_of_the_original(): void {
		$expected = array();
		foreach ( self::signatures() as $name => $row ) {
			$expected[ $name ] = $row[0];
		}

		$actual = array();
		foreach ( ( new ReflectionClass( Caching::class ) )->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			$name = $method->getName();
			$this->assertSame( 'get_instance' === $name, $method->isStatic(), $name . ' static-ness' );
			$this->assertFalse( $method->hasReturnType(), $name . ' has no native return type' );
			$parameters = array();
			foreach ( $method->getParameters() as $parameter ) {
				$this->assertFalse( $parameter->hasType(), $name . '::$' . $parameter->getName() . ' has no native type' );
				$parameters[ $parameter->getName() ] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : self::REQUIRED;
			}
			$actual[ $name ] = $parameters;
		}

		$expected_names = array_keys( $expected );
		$actual_names   = array_keys( $actual );
		sort( $expected_names );
		sort( $actual_names );
		$this->assertSame( $expected_names, $actual_names, 'The same public methods, none extra.' );
		foreach ( $expected as $name => $parameters ) {
			$this->assertSame( $parameters, $actual[ $name ], $name );
		}
	}

	public function test_the_neutral_and_the_working_methods_cover_the_whole_public_surface(): void {
		$names = array_merge( array_keys( self::no_ops() ), self::working_methods() );
		$all   = array_keys( self::signatures() );
		sort( $names );
		sort( $all );

		$this->assertSame( $all, $names );
	}

	public function test_delete_related_caches_flushes_the_entries_of_the_object_tag(): void {
		$entry_12 = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:12' ) );
		$entry_13 = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:13' ) );
		$this->setExpectedDeprecated( 'Caching::delete_related_caches' );

		$this->assertSame( 1, Caching::get_instance()->delete_related_caches( 12, 'post' ) );

		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $entry_12 ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $entry_13 ) );
		$this->assertSame( array( array( 'tags', array( 'post:12' ), 1 ) ), $this->flushed );
		$this->assertCount( 1, $this->deprecated );
		$this->assertSame( array( 'Caching::delete_related_caches', 'rfrac_flush_tag()', '1.0.0' ), $this->deprecated[0] );
		$this->assertSame( array(), $this->incorrect );
	}

	public function test_delete_related_caches_maps_other_object_types_onto_their_tags(): void {
		$term = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'term:4' ) );
		$user = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'user:2' ) );
		$this->setExpectedDeprecated( 'Caching::delete_related_caches' );

		$this->assertSame( 1, Caching::get_instance()->delete_related_caches( '4', 'category' ) );
		$this->assertSame( 1, Caching::get_instance()->delete_related_caches( 2, 'users' ) );

		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $term ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $user ) );
		$this->assertSame(
			array(
				array( 'tags', array( 'term:4' ), 1 ),
				array( 'tags', array( 'user:2' ), 1 ),
			),
			$this->flushed
		);
	}

	/**
	 * Pairs that name no tag: object type, ID.
	 *
	 * @return array<string,array{0:string,1:mixed}>
	 */
	public static function pairs_without_a_tag(): array {
		return array(
			'the list family'     => array( 'list', 5 ),
			'a non-numeric post'  => array( 'post', 'abc' ),
			'an invalid type'     => array( 'Bad Type!', 1 ),
			'an array ID'         => array( 'post', array( 1 ) ),
			'a float ID'          => array( 'post', 1.5 ),
			'a null object type'  => array( null, 1 ),
		);
	}

	/**
	 * @dataProvider pairs_without_a_tag
	 *
	 * @param mixed $object_type Object type.
	 * @param mixed $id          Object ID.
	 */
	public function test_delete_related_caches_without_a_tag_returns_zero_with_one_notice( $object_type, $id ): void {
		$entry = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:5' ) );
		$this->setExpectedIncorrectUsage( 'Caching::delete_related_caches' );

		$this->assertSame( 0, Caching::get_instance()->delete_related_caches( $id, $object_type ) );
		$this->assertSame( 0, Caching::get_instance()->delete_related_caches( $id, $object_type ) );

		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $entry ) );
		$this->assertSame( array(), $this->flushed, 'Nothing was flushed.' );
		$this->assertCount( 1, $this->incorrect );
		$this->assertSame( 'Caching::delete_related_caches', $this->incorrect[0][0] );
		$this->assertSame( array(), $this->deprecated );
	}

	/**
	 * Object types of lists: object type, kind of target, the tag or route flushed.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function lists(): array {
		return array(
			'a post type' => array( 'post', 'tags', 'list:post' ),
			'users'       => array( 'users', 'tags', 'list:users' ),
			'comments'    => array( 'comments', 'tags', 'list:comments' ),
			'types'       => array( 'types', 'route', '/wp/v2/types' ),
		);
	}

	/**
	 * @dataProvider lists
	 *
	 * @param string $object_type Object type.
	 * @param string $kind        `tags` or `route`.
	 * @param string $target      The tag or route that is flushed.
	 */
	public function test_delete_object_type_caches_flushes_the_list_of_the_type( string $object_type, string $kind, string $target ): void {
		if ( 'route' === $kind ) {
			$entry = Rfrac_Entry_Fixtures::insert_entry(
				array(
					'route'    => $target,
					'rule_key' => $target,
				)
			);
		} else {
			$entry = Rfrac_Entry_Fixtures::insert_entry( array(), array( $target ) );
		}
		$other = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:99' ) );
		$this->setExpectedDeprecated( 'Caching::delete_object_type_caches' );

		$this->assertSame( 1, Caching::get_instance()->delete_object_type_caches( $object_type ) );

		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $entry ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $other ) );
		$this->assertSame( array( array( $kind, array( $target ), 1 ) ), $this->flushed );
		$this->assertCount( 1, $this->deprecated );
		$this->assertSame( array(), $this->incorrect );
	}

	public function test_delete_object_type_caches_with_an_invalid_name_returns_zero_with_one_notice(): void {
		$entry = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:5' ) );
		$this->setExpectedIncorrectUsage( 'Caching::delete_object_type_caches' );

		$this->assertSame( 0, Caching::get_instance()->delete_object_type_caches( 'Bad Name!' ) );

		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $entry ) );
		$this->assertSame( array(), $this->flushed );
		$this->assertCount( 1, $this->incorrect );
		$this->assertSame( 'Caching::delete_object_type_caches', $this->incorrect[0][0] );
		$this->assertSame( array(), $this->deprecated );
	}

	/**
	 * Forms of the endpoint of the 5 per page list.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function endpoint_forms(): array {
		return array(
			'a root-relative path' => array( '/wp-json/wp/v2/posts?per_page=5' ),
			'an absolute URL'      => array( '{home}/wp-json/wp/v2/posts?per_page=5' ),
			'a rest_route form'    => array( '/?rest_route=/wp/v2/posts&per_page=5' ),
			'a bare path'          => array( 'wp-json/wp/v2/posts?per_page=5' ),
			'a padded path'        => array( "  /wp-json/wp/v2/posts?per_page=5 \n" ),
		);
	}

	/**
	 * @dataProvider endpoint_forms
	 *
	 * @param string $endpoint Endpoint of the original.
	 */
	public function test_strict_flushes_only_the_entry_of_the_url( string $endpoint ): void {
		$h = $this->harness( Rfrac_Serving_Harness::EARLY );
		$this->store_pages( $h );
		$this->setExpectedDeprecated( 'Caching::delete_cache_by_endpoint' );

		$result = Caching::get_instance()->delete_cache_by_endpoint( str_replace( '{home}', untrailingslashit( home_url() ), $endpoint ) );

		$this->assertTrue( $result );
		$this->assertFalse( $this->is_hit( $h, '/wp-json/wp/v2/posts?per_page=5' ), 'The entry of the URL is flushed.' );
		$this->assertTrue( $this->is_hit( $h, '/wp-json/wp/v2/posts?per_page=10' ), 'The other entry is untouched.' );
		$this->assertCount( 1, $this->flushed );
		$this->assertSame( 'url', $this->flushed[0][0] );
		$this->assertSame( 1, $this->flushed[0][2] );
		$this->assertCount( 1, $this->deprecated );
		$this->assertSame( array( 'Caching::delete_cache_by_endpoint', 'rfrac_flush_url()', '1.0.0' ), $this->deprecated[0] );
		$this->assertNoFailures();
	}

	public function test_strict_is_the_default_and_a_url_without_an_entry_returns_false(): void {
		$h = $this->harness( Rfrac_Serving_Harness::EARLY );
		$this->store_pages( $h );
		$this->setExpectedDeprecated( 'Caching::delete_cache_by_endpoint' );

		$this->assertFalse( Caching::get_instance()->delete_cache_by_endpoint( '/wp-json/wp/v2/posts?per_page=7', Caching::FLUSH_STRICT ) );
		$this->assertFalse( Caching::get_instance()->delete_cache_by_endpoint( '/wp-json/wp/v2/posts' ), 'The URL without parameters is another entry.' );

		$this->assertTrue( $this->is_hit( $h, '/wp-json/wp/v2/posts?per_page=5' ) );
		$this->assertTrue( $this->is_hit( $h, '/wp-json/wp/v2/posts?per_page=10' ) );
	}

	/**
	 * The strictness levels that flush a route.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function route_levels(): array {
		return array(
			'params' => array( 'params' ),
			'loose'  => array( 'loose' ),
		);
	}

	/**
	 * @dataProvider route_levels
	 *
	 * @param string $strictness Strictness.
	 */
	public function test_params_and_loose_flush_the_route_with_every_query_string( string $strictness ): void {
		$h = $this->harness( Rfrac_Serving_Harness::EARLY );
		$this->store_pages( $h );
		$other = Rfrac_Entry_Fixtures::insert_entry(
			array(
				'route'    => '/wp/v2/pages',
				'rule_key' => '/wp/v2/pages',
			)
		);
		$this->setExpectedDeprecated( 'Caching::delete_cache_by_endpoint' );

		$this->assertTrue( Caching::get_instance()->delete_cache_by_endpoint( '/wp-json/wp/v2/posts', $strictness ) );

		$this->assertFalse( $this->is_hit( $h, '/wp-json/wp/v2/posts?per_page=5' ) );
		$this->assertFalse( $this->is_hit( $h, '/wp-json/wp/v2/posts?per_page=10' ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $other ), 'Another route is untouched.' );
		$this->assertSame( array( array( 'route', array( '/wp/v2/posts' ), 2 ) ), $this->flushed );
		$this->assertSame( array( 'Caching::delete_cache_by_endpoint', 'rfrac_flush_route()', '1.0.0' ), $this->deprecated[0] );
	}

	public function test_params_and_loose_return_false_when_the_route_has_no_entry(): void {
		$this->setExpectedDeprecated( 'Caching::delete_cache_by_endpoint' );

		$this->assertFalse( Caching::get_instance()->delete_cache_by_endpoint( '/wp-json/wp/v2/posts', Caching::FLUSH_PARAMS ) );
		$this->assertFalse( Caching::get_instance()->delete_cache_by_endpoint( '/wp-json/wp/v2/posts', Caching::FLUSH_LOOSE ) );
	}

	public function test_an_unknown_strictness_returns_an_error_without_a_notice_or_a_flush(): void {
		$entry = $this->route_entry();

		$result = Caching::get_instance()->delete_cache_by_endpoint( '/wp-json/wp/v2/posts', 'bogus' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_rest_cache_invalid_strictness', $result->get_error_code() );
		$this->assertSame( 'Invalid strictness.', $result->get_error_message() );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $entry ) );
		$this->assertSame( array(), $this->flushed );
		$this->assertSame( array(), $this->deprecated );
		$this->assertSame( array(), $this->incorrect );
	}

	public function test_the_strictness_is_compared_strictly(): void {
		$this->assertWPError( Caching::get_instance()->delete_cache_by_endpoint( '/wp-json/wp/v2/posts', 0 ) );
		$this->assertWPError( Caching::get_instance()->delete_cache_by_endpoint( '/wp-json/wp/v2/posts', true ) );
		$this->assertWPError( Caching::get_instance()->delete_cache_by_endpoint( '/wp-json/wp/v2/posts', 'STRICT' ) );
	}

	public function test_an_empty_endpoint_returns_false_without_a_notice(): void {
		$this->route_entry();

		$this->assertFalse( Caching::get_instance()->delete_cache_by_endpoint( '' ) );
		$this->assertFalse( Caching::get_instance()->delete_cache_by_endpoint( " \t\n" ) );
		$this->assertFalse( Caching::get_instance()->delete_cache_by_endpoint( null ) );

		$this->assertSame( array(), $this->flushed );
		$this->assertSame( array(), $this->deprecated );
		$this->assertSame( array(), $this->incorrect );
	}

	public function test_a_path_that_is_no_rest_route_returns_false_without_a_notice_or_a_flush(): void {
		$entry = $this->route_entry();

		foreach ( array( Caching::FLUSH_STRICT, Caching::FLUSH_PARAMS, Caching::FLUSH_LOOSE ) as $strictness ) {
			$this->assertFalse( Caching::get_instance()->delete_cache_by_endpoint( '/about', $strictness ), $strictness );
			$this->assertFalse( Caching::get_instance()->delete_cache_by_endpoint( home_url( '/about/' ), $strictness ), $strictness );
		}

		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $entry ) );
		$this->assertSame( array(), $this->flushed );
		$this->assertSame( array(), $this->deprecated );
		$this->assertSame( array(), $this->incorrect );
	}

	public function test_the_rest_prefix_of_the_site_decides_what_a_rest_path_is(): void {
		add_filter(
			'rest_url_prefix',
			static function (): string {
				return 'api';
			}
		);
		$entry = $this->route_entry();
		$this->setExpectedDeprecated( 'Caching::delete_cache_by_endpoint' );

		$this->assertFalse( Caching::get_instance()->delete_cache_by_endpoint( '/wp-json/wp/v2/posts', Caching::FLUSH_PARAMS ), 'The default prefix is not the prefix of this site.' );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $entry ) );

		$this->assertTrue( Caching::get_instance()->delete_cache_by_endpoint( '/api/wp/v2/posts', Caching::FLUSH_PARAMS ) );

		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $entry ) );
		$this->assertSame( array( array( 'route', array( '/wp/v2/posts' ), 1 ) ), $this->flushed );
	}

	public function test_a_rest_path_is_resolved_below_the_home_path_of_a_subdirectory_site(): void {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		update_option( 'home', 'http://' . $host . '/sub' );
		update_option( 'siteurl', 'http://' . $host . '/sub' );
		$this->setExpectedDeprecated( 'Caching::delete_cache_by_endpoint' );

		$flushing = array(
			'a path of the REST API'              => '/wp-json/wp/v2/posts',
			'a bare path of the REST API'         => 'wp-json/wp/v2/posts',
			'a rest_route form'                   => '/?rest_route=/wp/v2/posts',
			'a path that has the home path'       => '/sub/wp-json/wp/v2/posts',
			'a rest_route form with the home path' => '/sub/?rest_route=/wp/v2/posts',
			'an absolute URL'                     => 'http://' . $host . '/sub/wp-json/wp/v2/posts',
		);
		foreach ( $flushing as $label => $endpoint ) {
			$entry = $this->route_entry();
			$this->assertTrue( Caching::get_instance()->delete_cache_by_endpoint( $endpoint, Caching::FLUSH_PARAMS ), $label );
			$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $entry ), $label );
		}

		$entry = $this->route_entry();
		$this->assertFalse( Caching::get_instance()->delete_cache_by_endpoint( '/other/wp-json/wp/v2/posts', Caching::FLUSH_PARAMS ), 'A path outside the home path.' );
		$this->assertFalse( Caching::get_instance()->delete_cache_by_endpoint( '/about', Caching::FLUSH_PARAMS ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $entry ) );
	}

	public function test_clear_caches_flushes_everything_and_reports_whether_there_was_something(): void {
		$entries = array(
			Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) ),
			Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:2' ) ),
			Rfrac_Entry_Fixtures::insert_entry( array(), array( 'list:post' ) ),
		);
		$this->setExpectedDeprecated( 'Caching::clear_caches' );

		$this->assertTrue( Caching::get_instance()->clear_caches() );

		foreach ( $entries as $entry ) {
			$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $entry ) );
		}
		$this->assertSame( array( array( 'all', array(), 3 ) ), $this->flushed );
		$this->assertSame( array( 'Caching::clear_caches', 'rfrac_flush_all()', '1.0.0' ), $this->deprecated[0] );

		$this->empty_tables();
		$this->assertFalse( Caching::get_instance()->clear_caches( true ), 'An empty store has nothing to flush.' );
	}

	public function test_delete_all_caches_flushes_everything_and_returns_the_count(): void {
		Rfrac_Entry_Fixtures::insert_entry();
		Rfrac_Entry_Fixtures::insert_entry();
		$this->setExpectedDeprecated( 'Caching::delete_all_caches' );

		$this->assertSame( 2, Caching::get_instance()->delete_all_caches( true ) );

		$this->assertSame( array( array( 'all', array(), 2 ) ), $this->flushed );
		$this->assertSame( array( 'Caching::delete_all_caches', 'rfrac_flush_all()', '1.0.0' ), $this->deprecated[0] );
		$this->assertSame( array(), $this->incorrect );
	}

	public function test_a_filtered_delete_all_caches_flushes_nothing_with_one_notice(): void {
		$entry = Rfrac_Entry_Fixtures::insert_entry();
		$this->setExpectedIncorrectUsage( 'Caching::delete_all_caches' );

		$this->assertSame( 0, Caching::get_instance()->delete_all_caches( true, 'x' ) );
		$this->assertSame( 0, Caching::get_instance()->delete_all_caches( false, 'x' ) );

		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $entry ) );
		$this->assertSame( array(), $this->flushed );
		$this->assertCount( 1, $this->incorrect );
		$this->assertSame( 'Caching::delete_all_caches', $this->incorrect[0][0] );
		$this->assertSame( array(), $this->deprecated );
	}

	/**
	 * Both serving paths.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_relation_inserted_while_a_request_is_dispatched_tags_the_stored_entry( string $path ): void {
		$h = $this->harness( $path );
		$this->setExpectedDeprecated( 'Caching::insert_cache_relation' );

		$h->request( '/wp-json/tst/v1/relation' );
		$body = $h->miss( '/tst/v1/relation', $this->relation_route( $h, 7, 'post' ) );

		$this->assertSame( '{"a":1}', $body );
		$this->assertCount( 1, $this->stored, 'The response is stored once.' );
		$this->assertContains( 'post:7', $this->stored[0] );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$this->assertSame( array( array( 'Caching::insert_cache_relation', 'rfrac_tag_response()', '1.0.0' ) ), $this->deprecated );
		$this->assertSame( array(), $this->incorrect );
		$this->assertNoFailures();
	}

	public function test_a_relation_without_a_tag_inserted_while_dispatching_leaves_the_response_untagged(): void {
		$h = $this->harness( Rfrac_Serving_Harness::EARLY );
		$this->setExpectedIncorrectUsage( 'Caching::insert_cache_relation' );

		$h->request( '/wp-json/tst/v1/relation' );
		$h->miss( '/tst/v1/relation', $this->relation_route( $h, 'abc', 'post' ) );

		$this->assertCount( 1, $this->stored );
		$this->assertNotContains( 'post:abc', $this->stored[0] );
		$this->assertCount( 1, $this->incorrect );
		$this->assertSame( 'Caching::insert_cache_relation', $this->incorrect[0][0] );
		$this->assertSame( array(), $this->deprecated );
	}

	public function test_a_relation_inserted_outside_a_dispatch_tags_nothing_with_one_notice(): void {
		$this->setExpectedIncorrectUsage( 'Caching::insert_cache_relation' );

		Caching::get_instance()->insert_cache_relation( 0, 7, 'post' );
		Caching::get_instance()->insert_cache_relation( 0, 8, 'post' );

		$this->assertCount( 1, $this->incorrect, 'The notice names the shim method and rfrac_tag_response() was not called.' );
		$this->assertSame( 'Caching::insert_cache_relation', $this->incorrect[0][0] );
		$this->assertSame( array(), $this->deprecated );
		$this->assertSame( array(), $this->stored );
	}

	public function test_a_relation_with_an_array_argument_is_ignored_silently(): void {
		Caching::get_instance()->insert_cache_relation( 0, array( 7 ), 'post' );
		Caching::get_instance()->insert_cache_relation( 0, 7, array( 'post' ) );

		$this->assertSame( array(), $this->incorrect );
		$this->assertSame( array(), $this->deprecated );
	}

	public function test_get_timeout_returns_the_default_ttl_of_the_settings(): void {
		update_option( Names::OPTION_SETTINGS, array( 'default_ttl' => 600 ), false );
		$this->setExpectedDeprecated( 'Caching::get_timeout' );

		$this->assertSame( 600, Caching::get_instance()->get_timeout() );
		$this->assertSame( 600, Caching::get_instance()->get_timeout( false ) );
		$this->assertSame( 600, Caching::get_instance()->get_timeout( true, array( 'uri' => '/wp-json/wp/v2/posts' ) ) );

		$this->assertCount( 1, $this->deprecated );
		$this->assertSame( 'Caching::get_timeout', $this->deprecated[0][0] );
	}

	public function test_get_timeout_interval_is_one(): void {
		$this->setExpectedDeprecated( 'Caching::get_timeout_interval' );

		$this->assertSame( 1, Caching::get_instance()->get_timeout_interval() );

		$this->assertCount( 1, $this->deprecated );
		$this->assertSame( array(), $this->incorrect );
	}

	/**
	 * @dataProvider no_ops
	 *
	 * @param string            $method   Method.
	 * @param array<int,mixed>  $args     Arguments.
	 * @param mixed             $expected The neutral value it returns.
	 */
	public function test_a_neutral_method_returns_its_value_and_touches_nothing_but_the_findings( string $method, array $args, $expected ): void {
		$this->setExpectedIncorrectUsage( 'Caching::' . $method );
		$hooks   = array();
		$options = array();
		add_action(
			'all',
			static function ( $hook ) use ( &$hooks ): void {
				$hooks[] = (string) $hook;
			}
		);
		foreach ( array( 'add_option', 'update_option', 'delete_option' ) as $write ) {
			add_action(
				$write,
				static function ( $option ) use ( &$options ): void {
					$options[] = (string) $option;
				}
			);
		}
		$queries = new Rfrac_Query_Recorder();
		$caching = Caching::get_instance();

		$queries->start();
		$first  = $caching->$method( ...$args );
		$second = $caching->$method( ...$args );
		$queries->stop();

		$this->assertSame( $expected, $first );
		$this->assertSame( $expected, $second );
		$this->assertCount( 1, $this->incorrect, 'One notice for two calls.' );
		$this->assertSame( 'Caching::' . $method, $this->incorrect[0][0] );
		$this->assertSame( array(), $this->deprecated );
		// The one thing a neutral method touches is the findings store: one read and one write per method.
		$other_queries = array_values(
			array_filter(
				$queries->matching( '/./' ),
				static fn( string $query ): bool => false === strpos( $query, Names::OPTION_LEGACY_FINDINGS )
			)
		);
		$this->assertSame( array(), $other_queries, 'No query runs but those of the findings store.' );
		$this->assertSame( array( Names::OPTION_LEGACY_FINDINGS ), $options, 'Only the findings store is written, once.' );
		$this->assertSame( array(), array_values( array_filter( $hooks, static fn( string $hook ): bool => false !== strpos( $hook, 'wp_rest_cache' ) ) ), 'No hook or option of the original is touched.' );
		$this->assertSame( array(), $this->flushed );
	}

	public function test_the_working_methods_fire_no_hook_of_the_original(): void {
		Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:12' ) );
		foreach ( array( 'delete_related_caches', 'delete_object_type_caches', 'delete_cache_by_endpoint', 'clear_caches', 'delete_all_caches', 'get_timeout', 'get_timeout_interval' ) as $method ) {
			$this->setExpectedDeprecated( 'Caching::' . $method );
		}
		$hooks = array();
		add_action(
			'all',
			static function ( $hook ) use ( &$hooks ): void {
				$hooks[] = (string) $hook;
			}
		);
		$caching = Caching::get_instance();

		$caching->delete_related_caches( 12, 'post' );
		$caching->delete_object_type_caches( 'post' );
		$caching->delete_cache_by_endpoint( '/wp-json/wp/v2/posts', Caching::FLUSH_PARAMS );
		$caching->clear_caches();
		$caching->delete_all_caches( false );
		$caching->get_timeout();
		$caching->get_timeout_interval();

		$this->assertSame( array(), array_values( array_filter( $hooks, static fn( string $hook ): bool => false !== strpos( $hook, 'wp_rest_cache' ) ) ) );
	}
}
