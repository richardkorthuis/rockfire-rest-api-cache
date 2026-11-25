<?php
/**
 * Tests for the public PHP API (rfrac_flush_*() and rfrac_tag_response()).
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Api;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Capture;
use Rockfire\Rest_Api_Cache\Serving\Request_State;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\Api
 */
class Test_Public_Api extends Rfrac_Serving_Test_Case {

	/**
	 * The Api registered by the plugin boot.
	 *
	 * @var Api
	 */
	private Api $booted;

	/**
	 * `rfrac/flushed` events as `[ type, targets, affected ]`.
	 *
	 * @var list<array{0:string,1:array<int,string>,2:int}>
	 */
	private array $flushed = array();

	/**
	 * `rfrac/stored` events as `[ key, route, tags, size ]`.
	 *
	 * @var list<array{0:string,1:string,2:string[],3:int}>
	 */
	private array $stored = array();

	/**
	 * Query recorder.
	 *
	 * @var Rfrac_Query_Recorder
	 */
	private Rfrac_Query_Recorder $queries;

	public function set_up() {
		parent::set_up();
		$booted = Api::get();
		$this->assertInstanceOf( Api::class, $booted, 'The plugin registers the Api while booting.' );
		$this->booted  = $booted;
		$this->flushed = array();
		$this->stored  = array();
		$this->queries = new Rfrac_Query_Recorder();
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
			function ( $key, $route, $tags, $size ): void {
				$this->stored[] = array( $key, $route, $tags, $size );
			},
			10,
			4
		);
	}

	public function tear_down() {
		$this->queries->stop();
		Api::register( $this->booted );
		wp_cache_delete( 'count', 'rfrac' );
		parent::tear_down();
	}

	/**
	 * Replace the registered Api with one flushing into a spy store.
	 *
	 * @return Rfrac_Spy_Store
	 */
	private function use_spy(): Rfrac_Spy_Store {
		$spy = new Rfrac_Spy_Store();
		Api::register( new Api( new Invalidator( $spy, $this->url_hasher() ), $this->booted->collector() ) );
		return $spy;
	}

	/**
	 * The URL hasher the plugin's invalidator uses.
	 *
	 * @return Closure
	 */
	private function url_hasher(): Closure {
		return static function ( string $url ): ?string {
			$context = Request_Context::from_url( $url, rest_get_url_prefix() );
			return null === $context->route ? null : Cache_Key::url_hash( $context, $context->params );
		};
	}

	/**
	 * Write queries on the entries and tags tables recorded since start() (the flush epoch write is not one).
	 *
	 * @return list<string>
	 */
	private function writes(): array {
		return $this->queries->matching( '/^\s*(UPDATE|INSERT|DELETE|REPLACE)\b.*rfrac_(entries|tags)/is' );
	}

	/**
	 * Serve a route of the test namespace through the real server, with Capture hooked like the container does.
	 *
	 * @param string              $route Route below `/tst/v1`.
	 * @param string              $query Query string of the request.
	 * @param array<string,mixed> $rule  Overrides of the compiled rule.
	 *
	 * @return string Echoed output.
	 */
	private function serve_miss( string $route, string $query = '', array $rule = array() ): string {
		$this->set_compiled_config(
			array_merge(
				$this->snapshot(),
				array( 'routes' => array( $this->wildcard( '/tst/v1/*', $rule ) ) )
			)
		);
		$capture = new Capture(
			$this->policy(),
			new Repository(),
			$this->booted->collector(),
			Compiled_Config::load( ... ),
			static fn( Compiled_Config $config ): Request_Context => Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() ),
			null,
			null,
			static fn(): array => array()
		);
		add_filter( 'rest_post_dispatch', array( $capture, 'on_post_dispatch' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_pre_echo_response', array( $capture, 'on_pre_echo' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_authentication_errors', array( $this->booted->collector(), 'on_main_dispatch' ), 0, 1 );

		$this->simulate( '/wp-json/tst/v1' . $route . ( '' === $query ? '' : '?' . $query ) );
		// Capture stores only for a request whose lookup recorded a flush epoch: this request's is a miss at the current epoch.
		Request_State::reset();
		Rfrac_Serving_Harness::record_lookup_at_current_epoch();
		$server                    = new Spy_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;
		$register                  = static function (): void {
			register_rest_route(
				'tst/v1',
				'/tagged',
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => static function () {
						rfrac_tag_response( 'acf-options:header' );
						return array( 'a' => 1 );
					},
				)
			);
		};
		add_action( 'rest_api_init', $register, 99 );
		do_action( 'rest_api_init', $server );
		remove_action( 'rest_api_init', $register, 99 );

		set_error_handler(
			static fn( int $errno, string $errstr ): bool => false !== strpos( $errstr, 'headers already sent' ),
			E_WARNING
		);
		try {
			$server->serve_request( '/tst/v1' . $route );
		} finally {
			restore_error_handler();
		}
		return $server->sent_body;
	}

	public function test_api_holds_its_services_and_can_be_reset(): void {
		$invalidator = $this->booted->invalidator();
		$this->assertSame( Plugin::instance()->invalidator(), $invalidator );
		$this->assertSame( Plugin::instance()->tag_provider(), $this->booted->collector() );

		Api::reset();
		$this->assertNull( Api::get() );
		Api::register( $this->booted );
		$this->assertSame( $this->booted, Api::get() );
	}

	public function test_flush_tag_normalises_flushes_and_reports_invalid_tags_once(): void {
		$e1 = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) );
		$e2 = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:2' ) );
		$this->setExpectedIncorrectUsage( 'rfrac_flush_tag' );

		$this->queries->start();
		$result = rfrac_flush_tag( array( 'post:1', 'POST:1', 'bad tag' ) );
		$this->queries->stop();

		$this->assertSame( 1, $result );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $e1 ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $e2 ) );
		$this->assertCount( 1, $this->queries->matching( '/^\s*UPDATE/i' ) );
		$this->assertCount( 1, $this->writes() );
		$this->assertSame( array( array( 'tags', array( 'post:1' ), 1 ) ), $this->flushed );
	}

	public function test_flush_tag_accepts_a_string_of_tags(): void {
		$spy = $this->use_spy();

		$this->assertSame( 1, rfrac_flush_tag( 'post:2, term:3 post:2' ) );
		$this->assertSame( array( array( 'post:2', 'term:3' ) ), $spy->calls_of( 'flush_by_tags' ) );
	}

	public function test_flush_tag_without_a_valid_tag_returns_zero_without_a_query(): void {
		$this->setExpectedIncorrectUsage( 'rfrac_flush_tag' );

		$this->queries->start();
		$this->assertSame( 0, rfrac_flush_tag( 'bad tag' ) );
		$this->queries->stop();

		$this->assertSame( array(), $this->queries->matching( '/./' ) );
		$this->assertSame( array(), $this->flushed );
	}

	public function test_flush_tag_with_an_empty_list_is_not_incorrect_usage(): void {
		$this->queries->start();
		$this->assertSame( 0, rfrac_flush_tag( '' ) );
		$this->assertSame( 0, rfrac_flush_tag( '  , ' ) );
		$this->assertSame( 0, rfrac_flush_tag( array() ) );
		$this->queries->stop();

		$this->assertSame( array(), $this->queries->matching( '/./' ) );
		$this->assertSame( array(), $this->flushed );
	}

	public function test_flush_tag_of_fifty_valid_tags_is_one_update(): void {
		$entry = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:7' ) );
		$tags  = array();
		for ( $i = 1; $i <= 50; $i++ ) {
			$tags[] = 'post:' . ( $i + 100 );
		}
		$tags[] = 'post:7';

		$this->queries->start();
		$result = rfrac_flush_tag( $tags );
		$this->queries->stop();

		$this->assertSame( 1, $result );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $entry ) );
		$this->assertCount( 1, $this->writes() );
		$this->assertCount( 1, $this->queries->matching( '/^\s*UPDATE/i' ) );
	}

	public function test_flush_route_normalises_the_route(): void {
		$spy = $this->use_spy();

		$this->assertSame( 1, rfrac_flush_route( 'wp/v2/posts/' ) );

		$this->assertSame( array( '/wp/v2/posts' ), $spy->calls_of( 'flush_route' ) );
		$this->assertSame( array( array( 'route', array( '/wp/v2/posts' ), 1 ) ), $this->flushed );
	}

	public function test_flush_route_accepts_a_registered_pattern(): void {
		$spy = $this->use_spy();

		rfrac_flush_route( ' /wp/v2/posts/(?P<id>[\d]+) ' );

		$this->assertSame( array( '/wp/v2/posts/(?P<id>[\d]+)' ), $spy->calls_of( 'flush_route' ) );
	}

	public function test_flush_route_of_nothing_flushes_nothing_without_a_notice(): void {
		$spy = $this->use_spy();

		$this->assertSame( 0, rfrac_flush_route( '' ) );
		$this->assertSame( 0, rfrac_flush_route( "  \t\n " ) );
		$this->assertSame( array(), $spy->calls );
		$this->assertSame( array(), $this->flushed );
	}

	public function test_flush_route_of_the_root_flushes_the_index_route(): void {
		$spy = $this->use_spy();

		$this->assertSame( 1, rfrac_flush_route( '/' ) );
		$this->assertSame( 1, rfrac_flush_route( ' // ' ) );

		$this->assertSame( array( '/', '/' ), $spy->calls_of( 'flush_route' ) );
	}

	public function test_flush_route_matches_literally_and_includes_the_subtree(): void {
		$posts = Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/posts' ) );
		$item  = Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/posts/12', 'rule_key' => '/wp/v2/posts/(?P<id>[\d]+)' ) );
		$like  = Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/postsx', 'rule_key' => '/wp/v2/postsx' ) );

		$this->assertSame( 2, rfrac_flush_route( 'wp/v2/posts' ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $posts ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $item ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $like ) );
		$this->assertSame( 0, rfrac_flush_route( 'wp/v2/post%' ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $like ) );
	}

	public function test_flush_url_flushes_by_the_url_hash(): void {
		$spy = $this->use_spy();
		$url = '/wp-json/wp/v2/posts?per_page=5';

		$this->assertSame( 1, rfrac_flush_url( '  ' . $url . ' ' ) );

		$hash = ( $this->url_hasher() )( $url );
		$this->assertNotEmpty( $hash );
		$this->assertSame( array( $hash ), $spy->calls_of( 'flush_by_url_hash' ) );
		$this->assertSame( array( array( 'url', array( $url ), 1 ) ), $this->flushed );
	}

	public function test_flush_url_rejects_an_empty_or_overlong_url(): void {
		$spy = $this->use_spy();
		$this->setExpectedIncorrectUsage( 'rfrac_flush_url' );

		$this->assertSame( 0, rfrac_flush_url( '   ' ) );
		$this->assertSame( 0, rfrac_flush_url( '/wp-json/wp/v2/posts?s=' . str_repeat( 'a', 2048 ) ) );
		$this->assertSame( array(), $spy->calls );
	}

	public function test_flush_url_reports_an_empty_url_with_an_escaped_message(): void {
		$this->use_spy();
		$this->setExpectedIncorrectUsage( 'rfrac_flush_url' );
		$notices = array();
		add_action(
			'doing_it_wrong_run',
			static function ( $function_name, $message ) use ( &$notices ): void {
				$notices[] = array( $function_name, $message );
			},
			10,
			2
		);

		$this->assertSame( 0, rfrac_flush_url( '   ' ) );

		$this->assertSame(
			array( array( 'rfrac_flush_url', esc_html( 'The URL must not be empty or longer than 2048 characters.' ) ) ),
			$notices
		);
	}

	public function test_flush_all_flushes_everything(): void {
		$spy = $this->use_spy();
		$spy->return = 7;

		$this->assertSame( 7, rfrac_flush_all() );
		$this->assertSame( array( array( 'all', array(), 7 ) ), $this->flushed );
	}

	public function test_each_flush_function_fires_flushed_once_and_issues_one_write(): void {
		Rfrac_Entry_Fixtures::insert_entry( array( 'url_hash' => ( $this->url_hasher() )( '/wp-json/wp/v2/posts' ) ), array( 'post:1' ) );
		$calls = array(
			'tags'  => static fn() => rfrac_flush_tag( 'post:1' ),
			'url'   => static fn() => rfrac_flush_url( '/wp-json/wp/v2/posts' ),
			'route' => static fn() => rfrac_flush_route( '/wp/v2/posts' ),
			'all'   => static fn() => rfrac_flush_all(),
		);

		$repo = new Repository();
		foreach ( $calls as $type => $call ) {
			$this->flushed = array();
			$epoch         = (int) $repo->flush_epoch();
			$this->queries->start();
			$call();
			$this->queries->stop();

			$this->assertCount( 1, $this->flushed, $type );
			$this->assertSame( $type, $this->flushed[0][0] );
			$this->assertCount( 1, $this->writes(), $type );
			$this->assertSame( $epoch + 1, $repo->flush_epoch(), $type );
		}
	}

	public function test_flush_url_ignores_the_parameters_the_rule_ignores(): void {
		$this->serve_miss( '/tagged', 'a=1&utm_source=z', array( 'param_mode' => 'allow_all', 'ignore_params' => array( 'utm_source' ) ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$this->assertSame( 0, rfrac_flush_url( '/wp-json/tst/v1/tagged?a=2' ), 'Another value is another entry.' );
		$this->assertSame( 1, rfrac_flush_url( '/wp-json/tst/v1/tagged?a=1&utm_source=other' ), 'The ignored parameter does not count.' );
		$this->assertSame( 0, rfrac_flush_url( '/wp-json/tst/v1/tagged?a=1' ), 'Already flushed.' );
	}

	public function test_flush_url_without_the_ignored_parameter_flushes_the_entry(): void {
		$this->serve_miss( '/tagged', 'a=1&utm_source=z', array( 'param_mode' => 'allow_all', 'ignore_params' => array( 'utm_source' ) ) );

		$this->assertSame( 1, rfrac_flush_url( '/wp-json/tst/v1/tagged?a=1' ) );
	}

	public function test_a_route_callback_tags_the_stored_entry_and_the_tag_flushes_it(): void {
		$output = $this->serve_miss( '/tagged' );

		$this->assertSame( '{"a":1}', $output );
		$this->assertCount( 1, $this->stored, 'One miss fires rfrac/stored once.' );
		list( $key, $route, $tags, $size ) = $this->stored[0];
		$this->assertSame( 64, strlen( $key ) );
		$this->assertSame( '/tst/v1/tagged', $route );
		$this->assertContains( 'acf-options:header', $tags );
		$this->assertContains( 'route:/tst/v1/tagged', $tags );
		$this->assertGreaterThan( 0, $size );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$this->assertSame( 1, rfrac_flush_tag( 'acf-options:header' ) );
		$this->assertSame( array( array( 'tags', array( 'acf-options:header' ), 1 ) ), $this->flushed );
	}

	public function test_tag_response_before_rest_api_init_is_incorrect_usage(): void {
		global $wp_actions;
		$saved = $wp_actions['rest_api_init'] ?? null;
		unset( $wp_actions['rest_api_init'] );
		$this->setExpectedIncorrectUsage( 'rfrac_tag_response' );

		try {
			$this->assertFalse( rfrac_tag_response( 'acf-options:header' ) );
		} finally {
			if ( null !== $saved ) {
				$wp_actions['rest_api_init'] = $saved;
			}
		}
	}

	public function test_tag_response_on_init_is_incorrect_usage(): void {
		global $wp_actions;
		$saved = $wp_actions['rest_api_init'] ?? null;
		unset( $wp_actions['rest_api_init'] );
		$this->setExpectedIncorrectUsage( 'rfrac_tag_response' );
		$result = null;
		add_action(
			'init',
			static function () use ( &$result ): void {
				$result = rfrac_tag_response( 'acf-options:header' );
			}
		);

		try {
			do_action( 'init' );
		} finally {
			if ( null !== $saved ) {
				$wp_actions['rest_api_init'] = $saved;
			}
		}

		$this->assertFalse( $result );
	}

	public function test_tag_response_in_a_rest_api_init_handler_is_incorrect_usage(): void {
		$this->setExpectedIncorrectUsage( 'rfrac_tag_response' );
		$result = null;
		$hook   = static function () use ( &$result ): void {
			$result = rfrac_tag_response( 'acf-options:header' );
		};
		add_action( 'rest_api_init', $hook, 98 );

		do_action( 'rest_api_init', new WP_REST_Server() );
		remove_action( 'rest_api_init', $hook, 98 );

		$this->assertFalse( $result );
		$this->assertSame( 0, $this->booted->collector()->item_tag_count() );
	}

	public function test_tag_response_after_the_response_was_collected_is_incorrect_usage(): void {
		$this->booted->collector()->on_main_dispatch();
		$this->booted->collector()->collect( new WP_REST_Request( 'GET', '/tst/v1/x' ), null );
		$this->setExpectedIncorrectUsage( 'rfrac_tag_response' );

		$this->assertFalse( rfrac_tag_response( 'acf-options:header' ) );
	}

	public function test_tag_response_returns_false_when_the_collector_overflowed(): void {
		add_filter( Hooks::MAX_TAGS_PER_ENTRY, static fn() => 1 );
		$this->booted->collector()->on_main_dispatch();

		$this->assertFalse( rfrac_tag_response( array( 'custom:a', 'custom:b' ) ) );

		$this->booted->collector()->reset();
		delete_option( Failure_Recorder::OPTION );
	}

	public function test_tag_response_from_rest_post_dispatch_tags_the_stored_entry(): void {
		$result = null;
		$hook   = static function ( $response ) use ( &$result ) {
			$result = rfrac_tag_response( 'late:tag' );
			return $response;
		};
		add_filter( 'rest_post_dispatch', $hook, 10 );

		$this->serve_miss( '/tagged' );
		remove_filter( 'rest_post_dispatch', $hook, 10 );

		$this->assertTrue( $result );
		$this->assertContains( 'late:tag', $this->stored[0][2] );
	}

	public function test_tag_response_without_a_valid_tag_returns_false(): void {
		$this->booted->collector()->on_main_dispatch();
		$this->setExpectedIncorrectUsage( 'rfrac_tag_response' );

		$this->assertFalse( rfrac_tag_response( array( 'bad tag' ) ) );
		$this->booted->collector()->reset();
	}

	public function test_tag_response_with_an_empty_list_returns_false_without_a_notice(): void {
		$this->booted->collector()->on_main_dispatch();

		$this->assertFalse( rfrac_tag_response( array() ) );
		$this->booted->collector()->reset();
	}

	public function test_tag_response_adds_the_tags_to_the_collector(): void {
		$this->booted->collector()->on_main_dispatch();
		$before = $this->booted->collector()->item_tag_count();

		$this->assertTrue( rfrac_tag_response( array( 'acf-options:header', 'ACF-Options:Footer' ) ) );
		$this->assertSame( $before + 2, $this->booted->collector()->item_tag_count() );
		$this->booted->collector()->reset();
	}

	public function test_functions_before_boot_are_incorrect_usage(): void {
		Api::reset();
		$this->setExpectedIncorrectUsage( 'rfrac_flush_all' );
		$this->setExpectedIncorrectUsage( 'rfrac_flush_tag' );
		$this->setExpectedIncorrectUsage( 'rfrac_flush_url' );
		$this->setExpectedIncorrectUsage( 'rfrac_flush_route' );
		$this->setExpectedIncorrectUsage( 'rfrac_tag_response' );

		$this->queries->start();
		$this->assertSame( 0, rfrac_flush_all() );
		$this->assertSame( 0, rfrac_flush_tag( 'post:1' ) );
		$this->assertSame( 0, rfrac_flush_url( '/wp-json/wp/v2/posts' ) );
		$this->assertSame( 0, rfrac_flush_route( '/wp/v2/posts' ) );
		$this->assertFalse( rfrac_tag_response( 'post:1' ) );
		$this->queries->stop();

		$this->assertSame( array(), $this->queries->matching( '/./' ) );
	}

	public function test_flush_url_finds_the_entry_of_an_encoded_request_from_a_decoded_or_lower_case_url(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$request = Request_Context::from_parts(
			array(
				'REQUEST_METHOD' => 'GET',
				'REQUEST_URI'    => '/wp-json/tst/v1/caf%C3%A9',
			),
			array(),
			array(),
			array(),
			rest_get_url_prefix(),
			(string) wp_parse_url( home_url(), PHP_URL_PATH ),
			true,
			get_current_blog_id(),
			false
		);
		$this->assertSame( '/tst/v1/caf%C3%A9', $request->route );

		foreach ( array( '/wp-json/tst/v1/café', '/wp-json/tst/v1/caf%c3%a9', '/wp-json/tst/v1/caf%C3%A9' ) as $url ) {
			$id = Rfrac_Entry_Fixtures::insert_entry( array( 'url_hash' => Cache_Key::url_hash( $request, array() ) ) );
			$this->assertSame( 1, rfrac_flush_url( $url ), $url );
			$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ), $url );
		}
	}

	public function test_flush_url_reaches_entries_stored_as_sent_and_in_the_path_info_form(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$stored = function ( array $server ): int {
			$context = Request_Context::from_parts(
				array( 'REQUEST_METHOD' => 'GET' ) + $server,
				array(),
				array(),
				array(),
				rest_get_url_prefix(),
				(string) wp_parse_url( home_url(), PHP_URL_PATH ),
				true,
				get_current_blog_id(),
				false
			);
			$this->assertNotNull( $context->route );
			return Rfrac_Entry_Fixtures::insert_entry( array( 'url_hash' => Cache_Key::url_hash( $context, array() ) ) );
		};

		$lower = $stored( array( 'REQUEST_URI' => '/wp-json/tst/v1/caf%c3%a9' ) );
		$this->assertSame( 1, rfrac_flush_url( '/wp-json/tst/v1/caf%c3%a9' ), 'Lower-case escapes, stored as sent.' );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $lower ) );

		$raw = $stored( array( 'REQUEST_URI' => '/wp-json/tst/v1/a|b[0]' ) );
		$this->assertSame( 1, rfrac_flush_url( '/wp-json/tst/v1/a|b[0]' ), 'Characters a browser sends unescaped.' );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $raw ) );

		$info = $stored(
			array(
				'REQUEST_URI' => '/index.php/wp-json/tst/v1/caf%C3%A9',
				'PATH_INFO'   => '/wp-json/tst/v1/café',
			)
		);
		$this->assertSame( 1, rfrac_flush_url( '/index.php/wp-json/tst/v1/caf%C3%A9' ), 'The PATH_INFO form, stored with decoded bytes.' );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $info ) );
	}
}
