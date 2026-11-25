<?php
/**
 * Shared base of the end-to-end invalidation matrix tests.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Api;
use Rockfire\Rest_Api_Cache\Invalidation\Relevance;
use Rockfire\Rest_Api_Cache\Invalidation\Rest_Tag_Collector;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Serving\Capture;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * Serves real anonymous REST requests through the engine so entries are stored with real tags, then lets a test
 * change content through WordPress APIs and commit the invalidator.
 *
 * Fixtures are created before {@see self::arm()} (the engine hooks are detached by the base class), so building
 * them queues nothing. Entries are tracked by the URI that produced them.
 */
abstract class Rfrac_Matrix_Test_Case extends Rfrac_Serving_Test_Case {

	/**
	 * Entry ids by served URI.
	 *
	 * @var array<string,int>
	 */
	protected array $entries = array();

	/**
	 * Query recorder.
	 *
	 * @var Rfrac_Query_Recorder
	 */
	protected Rfrac_Query_Recorder $recorder;

	/**
	 * `rfrac/flushed` events as `[ type, targets, affected ]`.
	 *
	 * @var list<array{0:string,1:array<int,string>,2:int}>
	 */
	protected array $flushed = array();

	/**
	 * Cache keys reported by `rfrac/stored` for the last request.
	 *
	 * @var string[]
	 */
	private array $stored_keys = array();

	/**
	 * The spy server of the last {@see self::serve_after_lookup()} request.
	 *
	 * @var Spy_REST_Server|null
	 */
	private ?Spy_REST_Server $live_server = null;

	/**
	 * Original external object cache flag.
	 *
	 * @var bool
	 */
	private bool $ext_cache = false;

	/**
	 * The engine hooks the booted container had recorded when the test started.
	 *
	 * {@see self::arm()} registers the engine hooks on the booted container again, which appends them to its list.
	 * WP_UnitTestCase removes the hooks themselves after the test, but not the container's record of them, so the
	 * list is put back in tear_down(); otherwise every later test would see the booted container with the engine
	 * hooks registered once more for every armed test (and attach them that many times when it re-attaches them).
	 *
	 * @var list<array{hook:string,callback:\Closure,priority:int,args:int}>|null
	 */
	private ?array $booted_engine_hooks = null;

	public function set_up() {
		$this->booted_engine_hooks = Plugin::instance()->engine_hooks();
		parent::set_up();
		$this->ext_cache = (bool) wp_using_ext_object_cache();
		$this->entries   = array();
		$this->flushed   = array();
		$this->recorder  = new Rfrac_Query_Recorder();
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( 0 );
		wp_cache_flush_group( 'rfrac' );
		wp_cache_delete( 'generation', 'rfrac' );
	}

	public function tear_down() {
		$this->recorder->stop();
		wp_using_ext_object_cache( $this->ext_cache );
		wp_cache_flush_group( 'rfrac' );
		wp_cache_delete( 'generation', 'rfrac' );
		( new ReflectionProperty( Plugin::class, 'stores' ) )->setValue( Plugin::instance(), array() );
		if ( null !== $this->booted_engine_hooks ) {
			( new ReflectionProperty( Plugin::class, 'engine_hooks' ) )->setValue( Plugin::instance(), $this->booted_engine_hooks );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Data provider: the two stores of the matrix.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function stores(): array {
		return array(
			'database'     => array( 'database' ),
			'object cache' => array( 'object_cache' ),
		);
	}

	/**
	 * Register the live engine hooks, compile the config from the current server and select the store.
	 *
	 * Call after the fixtures, post types, route rules and custom routes are in place.
	 *
	 * @param string $store `database` or `object_cache`.
	 *
	 * @return void
	 */
	protected function arm( string $store = 'database' ): void {
		$plugin = Plugin::instance();
		$plugin->register_engine_hooks();
		add_action(
			Hooks::STORED,
			function ( $key ): void {
				$this->stored_keys[] = (string) $key;
			}
		);
		add_action(
			Hooks::FLUSHED,
			function ( $type, $targets, $affected ): void {
				$this->flushed[] = array( $type, $targets, $affected );
			},
			10,
			3
		);
		$this->configure_site( $store );
	}

	/**
	 * Compile the config of the current site from a fresh server and select the store (call once per site after arm()).
	 *
	 * @param string $store `database` or `object_cache`.
	 *
	 * @return void
	 */
	protected function configure_site( string $store ): void {
		$plugin = Plugin::instance();
		wp_using_ext_object_cache( 'object_cache' === $store );
		( new ReflectionProperty( Plugin::class, 'stores' ) )->setValue( $plugin, array() );

		$server                   = new Spy_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;
		do_action( 'rest_api_init', $server );
		$config = $plugin->compiler()->compile( $server )->to_array();
		update_option(
			Names::OPTION_CONFIG,
			array_merge(
				$config,
				array(
					'store_mode'   => $store,
					'hit_sampling' => 0,
				)
			),
			false
		);

		foreach ( array( 'rule_post_types', 'rule_taxonomies' ) as $memo ) {
			( new ReflectionProperty( Relevance::class, $memo ) )->setValue( $plugin->relevance(), null );
		}
		( new ReflectionProperty( Rest_Tag_Collector::class, 'rules' ) )->setValue( $plugin->tag_provider(), null );
		$this->reset_plugin_invalidators();
	}

	/**
	 * Enable a route through the settings, like the admin screen does.
	 *
	 * @param string              $key  Route key.
	 * @param array<string,mixed> $rule Rule fields.
	 *
	 * @return void
	 */
	protected function enable_route( string $key, array $rule = array() ): void {
		$result = Config::instance()->save_route_rule( $key, array_merge( array( 'status' => 'enabled' ), $rule ) );
		$this->assertTrue( $result->is_valid(), 'The route rule is valid: ' . $key );
	}

	/**
	 * Serve a real anonymous GET request through the REST server so the engine stores the response.
	 *
	 * @param string $uri Path below the REST prefix with an optional query string, e.g. `/wp/v2/posts?page=2`.
	 *
	 * @return string The echoed body.
	 */
	protected function serve( string $uri ): string {
		wp_set_current_user( 0 );
		Request_State::reset();
		( new ReflectionProperty( Capture::class, 'handled' ) )->setValue( Plugin::instance()->capture(), false );
		$this->stored_keys = array();
		$this->simulate( untrailingslashit( (string) wp_parse_url( rest_url(), PHP_URL_PATH ) ) . $uri );
		// Capture stores only for a request whose lookup recorded a flush epoch: this one's is a miss at the current epoch.
		Rfrac_Serving_Harness::record_lookup_at_current_epoch();

		$server                    = new Spy_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;
		do_action( 'rest_api_init', $server );

		// PHPUnit has already written output, so core's header() calls would raise warnings.
		set_error_handler(
			static fn( int $errno, string $errstr ): bool => false !== strpos( $errstr, 'headers already sent' ),
			E_WARNING
		);
		try {
			$server->serve_request( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		} finally {
			restore_error_handler();
		}

		$this->assertCount( 1, $this->stored_keys, 'The request is stored as exactly one entry: ' . $uri );
		$this->entries[ $uri ] = $this->entry_id( $this->stored_keys[0] );
		return $server->sent_body;
	}

	/**
	 * Serve a real anonymous GET request that something changes after its content was read.
	 *
	 * Runs the real fallback lookup (which misses and records the flush epoch it saw), then dispatches the request
	 * with `$during` hooked on `rest_post_dispatch` at priority 0: after the route callback has read the content,
	 * before Capture stashes the response. Unlike {@see self::serve()} it asserts no store, since the capture may
	 * refuse it; {@see self::live_header()} tells what became of the response.
	 *
	 * @param string   $uri    Path below the REST prefix with an optional query string, e.g. `/wp/v2/posts/5`.
	 * @param callable $during Runs during the dispatch, e.g. a content change followed by a commit of the invalidator.
	 *
	 * @return string The echoed body.
	 */
	protected function serve_after_lookup( string $uri, callable $during ): string {
		wp_set_current_user( 0 );
		( new ReflectionProperty( Capture::class, 'handled' ) )->setValue( Plugin::instance()->capture(), false );
		$this->stored_keys = array();
		$this->simulate( untrailingslashit( (string) wp_parse_url( rest_url(), PHP_URL_PATH ) ) . $uri );

		$this->assertFalse( ( new Rfrac_Serving_Harness( Rfrac_Serving_Harness::FALLBACK ) )->lookup(), 'The lookup misses: ' . $uri );

		$server                    = new Spy_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;
		$this->live_server         = $server;
		do_action( 'rest_api_init', $server );

		$hook = static function ( $result ) use ( $during ) {
			$during();
			return $result;
		};
		add_filter( 'rest_post_dispatch', $hook, 0 );

		// PHPUnit has already written output, so core's header() calls would raise warnings.
		set_error_handler(
			static fn( int $errno, string $errstr ): bool => false !== strpos( $errstr, 'headers already sent' ),
			E_WARNING
		);
		try {
			$server->serve_request( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		} finally {
			restore_error_handler();
			remove_filter( 'rest_post_dispatch', $hook, 0 );
		}

		return $server->sent_body;
	}

	/**
	 * A header of the last live response of {@see self::serve_after_lookup()} (case-insensitive name), null when not sent.
	 *
	 * @param string $name Header name.
	 *
	 * @return string|null
	 */
	protected function live_header( string $name ): ?string {
		foreach ( null !== $this->live_server ? $this->live_server->sent_headers : array() as $key => $value ) {
			if ( 0 === strcasecmp( (string) $key, $name ) ) {
				return (string) $value;
			}
		}
		return null;
	}

	/**
	 * Number of entries stored by the last request, as reported by `rfrac/stored`.
	 *
	 * @return int
	 */
	protected function stored_count(): int {
		return count( $this->stored_keys );
	}

	/**
	 * Id of the entry of a cache key.
	 *
	 * @param string $cache_key Cache key.
	 *
	 * @return int
	 */
	private function entry_id( string $cache_key ): int {
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE cache_key = %s', $wpdb->prefix . Names::TABLE_ENTRIES, $cache_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertNotNull( $id, 'The stored entry exists.' );
		return (int) $id;
	}

	/**
	 * The tags stored for a served URI.
	 *
	 * @param string $uri Served URI.
	 *
	 * @return string[]
	 */
	protected function tags_of( string $uri ): array {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT tag FROM %i WHERE entry_id = %d ORDER BY tag', $wpdb->prefix . Names::TABLE_TAGS, $this->entries[ $uri ] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'strval', (array) $rows );
	}

	/**
	 * Failure message: the expected tag and the tags actually stored for an entry.
	 *
	 * @param string $uri      Served URI.
	 * @param string $expected What the test expected to hit it, e.g. the tag.
	 *
	 * @return string
	 */
	private function describe( string $uri, string $expected ): string {
		return sprintf( '%s (expected tag: %s; stored tags: %s)', $uri, $expected, implode( ', ', $this->tags_of( $uri ) ) );
	}

	/**
	 * State of a served URI's entry: 1 active, 2 flushed.
	 *
	 * @param string $uri Served URI.
	 *
	 * @return string
	 */
	protected function state_of( string $uri ): string {
		return Rfrac_Entry_Fixtures::get_state( $this->entries[ $uri ] );
	}

	/**
	 * Assert the entries of the given URIs were flushed.
	 *
	 * @param string[] $uris     Served URIs.
	 * @param string   $expected The tag expected to flush them, for the failure message.
	 *
	 * @return void
	 */
	protected function assert_flushed( array $uris, string $expected = 'a tag of the change' ): void {
		foreach ( $uris as $uri ) {
			$this->assertSame( '2', $this->state_of( $uri ), 'Flushed: ' . $this->describe( $uri, $expected ) );
		}
	}

	/**
	 * Assert the entries of the given URIs are still active.
	 *
	 * @param string[] $uris Served URIs.
	 *
	 * @return void
	 */
	protected function assert_active( array $uris ): void {
		foreach ( $uris as $uri ) {
			$this->assertSame( '1', $this->state_of( $uri ), 'Active: ' . $this->describe( $uri, 'none' ) );
		}
	}

	/**
	 * Assert the listed entries are flushed and every other served entry is active.
	 *
	 * @param string[] $flushed  Served URIs expected to be flushed.
	 * @param string   $expected Tag expected to flush them.
	 *
	 * @return void
	 */
	protected function assert_only_flushed( array $flushed, string $expected = 'a tag of the change' ): void {
		$this->assert_flushed( $flushed, $expected );
		$this->assert_active( array_values( array_diff( array_keys( $this->entries ), $flushed ) ) );
	}

	/**
	 * Run a content change, commit the invalidator and record its queries.
	 *
	 * @param callable $change The change, using real WordPress APIs.
	 *
	 * @return void
	 */
	protected function change_and_commit( callable $change ): void {
		$this->flushed = array();
		$this->recorder->start();
		$change();
		Api::get()->invalidator()->commit();
		$this->recorder->stop();
	}

	/**
	 * UPDATE statements on the entries table since the recording started.
	 *
	 * @return string[]
	 */
	protected function entry_updates(): array {
		return $this->recorder->matching( '/^\s*UPDATE\s+`?' . preg_quote( $GLOBALS['wpdb']->prefix . Names::TABLE_ENTRIES, '/' ) . '`?\b/i' );
	}

	/**
	 * SELECT statements on the entries table since the recording started.
	 *
	 * @return string[]
	 */
	protected function entry_selects(): array {
		return $this->recorder->matching( '/^\s*SELECT\b.*\b' . preg_quote( $GLOBALS['wpdb']->prefix . Names::TABLE_ENTRIES, '/' ) . '\b/is' );
	}

	/**
	 * Assert the change cost exactly one UPDATE on the entries table (and at most one SELECT with the object cache).
	 *
	 * @param string $store Store mode of the run.
	 *
	 * @return void
	 */
	protected function assert_one_flush_query( string $store ): void {
		$this->assertCount( 1, $this->entry_updates(), 'Exactly one UPDATE on the entries table: ' . implode( ' | ', $this->entry_updates() ) );
		if ( 'object_cache' === $store ) {
			$this->assertLessThanOrEqual( 1, count( $this->entry_selects() ), 'At most one SELECT on the entries table.' );
		}
	}
}
