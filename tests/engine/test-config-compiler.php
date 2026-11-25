<?php
/**
 * Tests for route rules resolution and the config compiler.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Compiled_Route;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Config\Effective_Rule;
use Rockfire\Rest_Api_Cache\Config\Route_Discovery;
use Rockfire\Rest_Api_Cache\Config\Route_Info;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Invalidation\Api;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Cache_Policy;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Schema\Schema;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * @covers \Rockfire\Rest_Api_Cache\Config\Config_Compiler
 * @covers \Rockfire\Rest_Api_Cache\Config\Config::route_rules
 */
class Test_Config_Compiler extends Rfrac_Test_Case {

	/**
	 * Fresh global server; optionally with an extra route.
	 *
	 * @param bool $with_extra Register `/rfrac-test/v1/things`.
	 *
	 * @return WP_REST_Server
	 */
	private function server( bool $with_extra = false ): WP_REST_Server {
		return $this->new_rest_server(
			static function () use ( $with_extra ) {
				if ( ! $with_extra ) {
					return;
				}
				register_rest_route(
					'rfrac-test/v1',
					'/things',
					array(
						'methods'             => 'GET',
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
						'args'                => array( 'color' => array() ),
					)
				);
			}
		);
	}

	/**
	 * Compiled routes by key.
	 *
	 * @param Compiled_Config $config Config.
	 *
	 * @return array<string,Compiled_Route>
	 */
	private function by_key( Compiled_Config $config ): array {
		$out = array();
		foreach ( $config->routes() as $route ) {
			if ( $route->enabled ) {
				$out[ $route->key ] = $route;
			}
		}
		return $out;
	}

	/**
	 * Whether the request-phase policy bypasses a REST route as not enabled.
	 *
	 * @param Compiled_Config $config Config.
	 * @param string          $path   Route path, e.g. `/my/v1/private`.
	 *
	 * @return bool
	 */
	private function is_route_not_enabled( Compiled_Config $config, string $path ): bool {
		$uri      = '/wp-json' . $path;
		$context  = Request_Context::from_parts(
			array(
				'REQUEST_METHOD' => 'GET',
				'REQUEST_URI'    => $uri,
			),
			array(),
			array(),
			array(),
			'wp-json',
			'',
			true,
			1,
			false
		);
		$decision = ( new Cache_Policy( static fn(): bool => false ) )->evaluate( $context, $config );
		return ! $decision->cacheable && Bypass_Reason::ROUTE_NOT_ENABLED === $decision->reason;
	}

	/**
	 * Fresh server with `/rfrac-test/v1/things` and `/rfrac-test/v1/things/(?P<id>...)` registered.
	 *
	 * @return WP_REST_Server
	 */
	private function overlap_server(): WP_REST_Server {
		return $this->new_rest_server(
			static function () {
				foreach ( array( '/things', '/(?P<id>[\w\/-]+)' ) as $path ) {
					register_rest_route(
						'rfrac-test/v1',
						$path,
						array(
							'methods'             => 'GET',
							'callback'            => '__return_true',
							'permission_callback' => '__return_true',
						)
					);
				}
			}
		);
	}

	/**
	 * Count update_option attempts for the config option while running a callback.
	 *
	 * @param callable $callback Work.
	 *
	 * @return int
	 */
	private function count_writes( callable $callback ): int {
		$calls  = 0;
		$filter = static function ( $value ) use ( &$calls ) {
			++$calls;
			return $value;
		};
		add_filter( 'pre_update_option_' . Names::OPTION_CONFIG, $filter );
		$callback();
		remove_filter( 'pre_update_option_' . Names::OPTION_CONFIG, $filter );
		return $calls;
	}

	public function test_default_install_compiles_default_routes(): void {
		$config = ( new Config_Compiler() )->compile( $this->server() );
		$routes = $this->by_key( $config );

		$this->assertArrayHasKey( '/wp/v2/posts', $routes );
		$this->assertArrayHasKey( '/wp/v2/posts/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/oembed/1.0/embed', $routes );
		$this->assertArrayNotHasKey( '/wp/v2/users/me', $routes );
		$this->assertArrayNotHasKey( '/wp/v2', $routes );
		$this->assertArrayNotHasKey( '/', $routes );

		$posts = $routes['/wp/v2/posts'];
		$this->assertSame( '@^/wp/v2/posts$@i', $posts->regex );
		$this->assertSame( 1, preg_match( $posts->regex, '/wp/v2/posts' ) );
		$this->assertSame( 1, preg_match( $routes['/wp/v2/posts/(?P<id>[\d]+)']->regex, '/wp/v2/posts/12' ) );
		$this->assertSame( 86400, $posts->ttl );
		$this->assertSame( 'registered', $posts->param_mode );
		$this->assertSame( array( 'search' ), $posts->bypass_params );
		$this->assertContains( 'per_page', $posts->allowed_params );
		$this->assertContains( '_fields', $posts->allowed_params );
		$this->assertFalse( $config->pending_discovery() );
		$this->assertNotSame( '', $config->fingerprint() );
	}

	public function test_global_values_are_compiled(): void {
		$config = ( new Config_Compiler() )->compile( $this->server() );

		$this->assertTrue( $config->enabled() );
		$this->assertSame( 'database', $config->store_mode() );
		$this->assertSame( rest_get_url_prefix(), $config->rest_prefix() );
		$this->assertSame( get_bloginfo( 'charset' ), $config->charset() );
		$this->assertSame( 1048576, $config->max_payload_bytes() );
		$this->assertSame( array( '_fields', '_embed', '_locale', 'lang' ), $config->universal_params() );
		$this->assertSame( array( 'wordpress_logged_in_', 'wordpress_sec_', 'wp-postpass_', 'wp_woocommerce_session_' ), $config->bypass_cookie_prefixes() );
		$this->assertSame( array( 'x-wp-total', 'x-wp-totalpages', 'link', 'allow', 'content-language' ), $config->replay_headers() );
		$this->assertFalse( $config->allow_skip_cache() );
		$this->assertTrue( $config->cors_wildcard() );
		$this->assertSame( Schema::VERSION, $config->schema_version() );
		$this->assertSame( array_values( get_allowed_http_origins() ), $config->cors_origins() );
	}

	public function test_without_server_writes_pending_discovery(): void {
		$config = ( new Config_Compiler() )->compile();

		$this->assertSame( array(), $config->routes() );
		$this->assertTrue( $config->pending_discovery() );
		$this->assertSame( '', $config->fingerprint() );
		$this->assertTrue( Compiled_Config::load()->pending_discovery() );
	}

	public function test_config_option_is_autoloaded(): void {
		global $wpdb;
		( new Config_Compiler() )->compile( $this->server() );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Names::OPTION_CONFIG ) );
		$this->assertContains( $autoload, array( 'on', 'yes', 'auto-on', 'auto' ) );
	}

	public function test_denied_routes_never_compile_via_settings(): void {
		$config = new Config();
		$this->assertTrue( $config->save_route_rule( '/wp/v2/users/me', array( 'status' => 'enabled' ) )->is_valid() );
		$this->assertTrue( $config->save_route_rule( '/wc/store/v1/*', array( 'status' => 'enabled', 'match' => 'wildcard' ) )->is_valid() );

		$routes = $this->by_key( ( new Config_Compiler() )->compile( $this->server() ) );

		$this->assertArrayNotHasKey( '/wp/v2/users/me', $routes );
		$this->assertArrayNotHasKey( '/wc/store/v1/*', $routes );
	}

	public function test_denied_routes_never_compile_via_filter(): void {
		$add = static function ( array $rules ): array {
			$rules['/wp/v2/users/me'] = array( 'status' => 'enabled' );
			$rules['/batch/v1']       = array( 'status' => 'enabled', 'match' => 'wildcard' );
			return $rules;
		};
		add_filter( Hooks::ROUTE_RULES, $add );

		$routes = $this->by_key( ( new Config_Compiler() )->compile( $this->server() ) );

		$this->assertArrayNotHasKey( '/wp/v2/users/me', $routes );
		$this->assertArrayNotHasKey( '/batch/v1', $routes );
		$this->assertArrayHasKey( '/wp/v2/posts', $routes );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_denied_routes_never_compile_via_constant(): void {
		define(
			'RFRAC_ROUTES',
			array(
				'/wp/v2/users/me'     => array( 'status' => 'enabled' ),
				'/rfrac-test/v1/things' => array( 'status' => 'enabled', 'ttl' => 600 ),
			)
		);

		$config = ( new Config_Compiler() )->compile( $this->server( true ) );
		$routes = $this->by_key( $config );

		$this->assertArrayNotHasKey( '/wp/v2/users/me', $routes );
		$this->assertArrayHasKey( '/rfrac-test/v1/things', $routes );
		$this->assertSame( 600, $routes['/rfrac-test/v1/things']->ttl );
		$this->assertSame( array( 'color' ), array_values( array_intersect( array( 'color' ), $routes['/rfrac-test/v1/things']->allowed_params ) ) );
	}

	public function test_unknown_registered_rule_is_dropped(): void {
		( new Config() )->save_route_rule( '/nope/v1/missing', array( 'status' => 'enabled' ) );

		$routes = $this->by_key( ( new Config_Compiler() )->compile( $this->server() ) );

		$this->assertArrayNotHasKey( '/nope/v1/missing', $routes );
	}

	public function test_excluded_stored_rule_disables_a_default_route(): void {
		( new Config() )->save_route_rule( '/wp/v2/posts', array( 'status' => 'excluded' ) );

		$routes = $this->by_key( ( new Config_Compiler() )->compile( $this->server() ) );

		$this->assertArrayNotHasKey( '/wp/v2/posts', $routes );
		$this->assertArrayHasKey( '/wp/v2/pages', $routes );
	}

	public function test_excluded_registered_route_beats_a_wider_wildcard(): void {
		$config = new Config();
		$config->save_route_rule( '/rfrac-test/v1/*', array( 'status' => 'enabled', 'match' => 'wildcard' ) );
		$config->save_route_rule( '/rfrac-test/v1/things', array( 'status' => 'excluded' ) );

		$compiled = ( new Config_Compiler() )->compile( $this->server( true ) );

		$this->assertTrue( $this->is_route_not_enabled( $compiled, '/rfrac-test/v1/things' ) );
		$this->assertFalse( $this->is_route_not_enabled( $compiled, '/rfrac-test/v1/other' ) );
	}

	public function test_excluded_wildcard_is_a_negative_entry_before_enabled_wildcards(): void {
		$config = new Config();
		$config->save_route_rule( '/rfrac-test/v1/*', array( 'status' => 'enabled', 'match' => 'wildcard' ) );
		$config->save_route_rule( '/rfrac-test/v1/private/*', array( 'status' => 'excluded', 'match' => 'wildcard' ) );

		$compiled = ( new Config_Compiler() )->compile( $this->server() );

		$this->assertTrue( $this->is_route_not_enabled( $compiled, '/rfrac-test/v1/private/x' ) );
		$this->assertFalse( $this->is_route_not_enabled( $compiled, '/rfrac-test/v1/x' ) );
	}

	public function test_not_enabled_first_registered_route_beats_a_later_overlapping_enabled_route(): void {
		$config = new Config();
		$config->save_route_rule( '/rfrac-test/v1/(?P<id>[\w\/-]+)', array( 'status' => 'enabled' ) );

		$compiled = ( new Config_Compiler() )->compile( $this->overlap_server() );

		$this->assertTrue( $this->is_route_not_enabled( $compiled, '/rfrac-test/v1/things' ), 'The first registered route is not enabled.' );
		$this->assertFalse( $this->is_route_not_enabled( $compiled, '/rfrac-test/v1/things/x' ) );
		$this->assertArrayHasKey( '/rfrac-test/v1/(?P<id>[\w\/-]+)', $this->by_key( $compiled ) );
	}

	public function test_negative_entries_are_stored_compactly(): void {
		( new Config_Compiler() )->compile( $this->server() );

		$stored = get_option( Names::OPTION_CONFIG );
		$this->assertIsArray( $stored );
		$negatives = array_filter( $stored['routes'], static fn( array $r ): bool => isset( $r['enabled'] ) );
		$this->assertNotSame( array(), $negatives );
		foreach ( $negatives as $route ) {
			$this->assertSame( array( 'key', 'regex', 'enabled' ), array_keys( $route ) );
			$this->assertFalse( $route['enabled'] );
		}
	}

	public function test_stored_rule_enables_a_non_default_route_with_ttl_and_args(): void {
		( new Config() )->save_route_rule( '/rfrac-test/v1/things', array( 'status' => 'enabled', 'ttl' => 120 ) );

		$routes = $this->by_key( ( new Config_Compiler() )->compile( $this->server( true ) ) );

		$this->assertSame( 120, $routes['/rfrac-test/v1/things']->ttl );
		$this->assertContains( 'color', $routes['/rfrac-test/v1/things']->allowed_params );
		$this->assertContains( 'lang', $routes['/rfrac-test/v1/things']->allowed_params );
	}

	public function test_wildcard_rule_compiles_with_escaped_segments(): void {
		( new Config() )->save_route_rule( '/my.plugin/v1/items/*', array( 'status' => 'enabled', 'match' => 'wildcard' ) );

		$routes = $this->by_key( ( new Config_Compiler() )->compile( $this->server() ) );
		$regex  = $routes['/my.plugin/v1/items/*']->regex;

		$this->assertSame( 1, preg_match( $regex, '/my.plugin/v1/items/5' ) );
		$this->assertSame( 0, preg_match( $regex, '/myXplugin/v1/items/5' ) );
		$this->assertSame( 0, preg_match( $regex, '/my.plugin/v1/items/5/6' ) );
	}

	public function test_route_rules_layers_and_sources(): void {
		$server = $this->server( true );
		$config = new Config();
		$config->save_route_rule( '/wp/v2/pages', array( 'status' => 'excluded' ) );
		$config->save_route_rule( '/rfrac-test/v1/things', array( 'status' => 'enabled' ) );

		add_filter(
			Hooks::ROUTE_RULES,
			static function ( array $rules ): array {
				$rules['/wp/v2/tags']       = array( 'status' => 'enabled', 'ttl' => 300 );
				$rules['/wp/v2/categories'] = array( 'status' => 'bogus' );
				$rules['/wp/v2/media']      = array( 'status' => 'enabled', 'vary_headers' => array( 'cookie' ) );
				return $rules;
			}
		);

		delete_option( Failure_Recorder::OPTION );
		$rules = $config->route_rules( $server );
		$this->assertNull( Failure_Recorder::last(), 'route_rules() itself records nothing; the compile does.' );
		( new Config_Compiler( $config ) )->compile( $server );

		$this->assertContainsOnlyInstancesOf( Effective_Rule::class, $rules );
		$this->assertSame( 'default', $rules['/wp/v2/posts']->source );
		$this->assertSame( 'enabled', $rules['/wp/v2/posts']->rule->status );
		$this->assertSame( array( 'search' ), $rules['/wp/v2/posts']->rule->bypass_params );
		$this->assertNull( $rules['/wp/v2/posts']->rule->ttl );
		$this->assertSame( 'registered', $rules['/wp/v2/posts']->rule->param_mode );
		$this->assertSame( 'settings', $rules['/wp/v2/pages']->source );
		$this->assertSame( 'excluded', $rules['/wp/v2/pages']->rule->status );
		$this->assertSame( 'settings', $rules['/rfrac-test/v1/things']->source );
		$this->assertSame( 'filter', $rules['/wp/v2/tags']->source );
		$this->assertSame( 300, $rules['/wp/v2/tags']->rule->ttl );
		$this->assertSame( 'filter', $rules['/wp/v2/categories']->source, 'An invalid filtered rule fails closed.' );
		$this->assertSame( 'excluded', $rules['/wp/v2/categories']->rule->status, 'An invalid filtered rule does not leave the enabled default.' );
		$this->assertSame( 'excluded', $rules['/wp/v2/media']->rule->status, 'A forbidden vary header makes the filtered rule invalid.' );
		$this->assertSame( array(), $rules['/wp/v2/media']->rule->vary_headers );

		$last = Failure_Recorder::last();
		$this->assertNotNull( $last, 'Invalid filtered rules are recorded.' );
		$this->assertSame( 'route_rules', $last['context'] );
		$this->assertStringContainsString( 'rfrac/route_rules rule "/wp/v2/', $last['message'] );
		$this->assertStringContainsString( '(and 1 more)', $last['message'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_route_rules_filter_receives_the_sources_and_the_discovered_routes(): void {
		define( 'RFRAC_ROUTES', array( '/wp/v2/pages' => array( 'status' => 'enabled', 'ttl' => 300 ) ) );
		( new Config() )->save_route_rule( '/wp/v2/posts', array( 'status' => 'enabled', 'ttl' => 120 ) );
		$server = $this->server( true );

		$calls = array();
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( array $rules, $sources = null, $routes = null ) use ( &$calls ): array {
				$calls[] = array( $rules, $sources, $routes );
				return $rules;
			},
			10,
			3
		);

		$rules = ( new Config() )->route_rules( $server );

		$this->assertCount( 1, $calls, 'One filter run per rule build.' );
		list( $filtered, $sources, $routes ) = $calls[0];
		$this->assertSame( 'settings', $sources['/wp/v2/posts'] );
		$this->assertSame( 'constant', $sources['/wp/v2/pages'] );
		$this->assertSame( 'default', $sources['/wp/v2/tags'] );
		$this->assertSame( array_keys( $filtered ), array_keys( $sources ), 'Every rule has a source.' );
		$this->assertSame( array_keys( ( new Route_Discovery() )->discover( $server ) ), array_keys( $routes ), 'The routes are the discovery output, in registration order.' );
		$this->assertContainsOnlyInstancesOf( Route_Info::class, $routes );
		$this->assertInstanceOf( Route_Info::class, $routes['/wp/v2/tags'] );
		$this->assertArrayHasKey( '/rfrac-test/v1/things', $routes );
		$this->assertSame( 'constant', $rules['/wp/v2/pages']->source, 'An unchanged rule keeps its layer.' );
		$this->assertSame( 'settings', $rules['/wp/v2/posts']->source );
	}

	public function test_an_invalid_stored_rule_is_a_settings_source(): void {
		update_option( Names::OPTION_SETTINGS, array( 'routes' => array( '/wp/v2/posts' => array( 'status' => 'bogus' ) ) ), false );
		$sources = null;
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( array $rules, $given = null ) use ( &$sources ): array {
				$sources = $given;
				return $rules;
			},
			10,
			2
		);

		( new Config() )->route_rules( $this->server() );

		$this->assertSame( 'settings', $sources['/wp/v2/posts'] );
		$this->assertSame( 'default', $sources['/wp/v2/tags'] );
	}

	public function test_a_one_argument_route_rules_callback_is_applied_as_before(): void {
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( array $rules ): array {
				$rules['/wp/v2/tags'] = array( 'status' => 'enabled', 'ttl' => 300 );
				unset( $rules['/wp/v2/pages'] );
				return $rules;
			}
		);

		$rules = ( new Config() )->route_rules( $this->server() );

		$this->assertSame( 'filter', $rules['/wp/v2/tags']->source );
		$this->assertSame( 300, $rules['/wp/v2/tags']->rule->ttl );
		$this->assertArrayNotHasKey( '/wp/v2/pages', $rules );
		$this->assertSame( 'default', $rules['/wp/v2/posts']->source );
	}

	public function test_a_three_argument_callback_leaves_the_snapshot_and_fingerprint_alone(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$plain    = $compiler->fingerprint( $server );
		$compiler->compile( $server );
		$saved = get_option( Names::OPTION_CONFIG );

		add_filter(
			Hooks::ROUTE_RULES,
			static function ( array $rules, $sources = null, $routes = null ): array {
				return $rules;
			},
			10,
			3
		);

		$this->assertSame( $plain, $compiler->fingerprint( $server ) );
		$compiler->compile( $server );
		$this->assertSame( $saved, get_option( Names::OPTION_CONFIG ) );
	}

	/**
	 * Keep the configuration of every run of the compiled action.
	 *
	 * @param list<Compiled_Config> $seen Receives the configuration of every run.
	 */
	private function spy_compiled( array &$seen ): void {
		add_action(
			Hooks::COMPILED,
			static function ( $config ) use ( &$seen ): void {
				$seen[] = $config;
			}
		);
	}

	public function test_compiled_action_fires_once_with_the_saved_configuration(): void {
		$seen = array();
		$this->spy_compiled( $seen );

		$config = ( new Config_Compiler() )->compile( $this->server() );

		$this->assertCount( 1, $seen );
		$this->assertInstanceOf( Compiled_Config::class, $seen[0] );
		$this->assertNotSame( '', $seen[0]->fingerprint() );
		$this->assertSame( $config->fingerprint(), $seen[0]->fingerprint() );
		$this->assertSame( Compiled_Config::load()->fingerprint(), $seen[0]->fingerprint(), 'The configuration is the saved one.' );
	}

	public function test_compiled_action_fires_for_a_compile_without_a_server(): void {
		$seen = array();
		$this->spy_compiled( $seen );

		( new Config_Compiler() )->compile();

		$this->assertCount( 1, $seen );
		$this->assertTrue( $seen[0]->pending_discovery() );
	}

	public function test_compiled_action_fires_when_the_live_recompile_saves(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$seen     = array();
		$this->spy_compiled( $seen );

		$this->assertTrue( $compiler->maybe_recompile_for_server( $server ), 'No stored config yet.' );

		$this->assertCount( 1, $seen );
		$this->assertSame( Compiled_Config::load()->fingerprint(), $seen[0]->fingerprint() );

		$compiler->mark_stale();
		$this->assertTrue( $compiler->maybe_recompile_for_server( $server ) );
		$this->assertCount( 2, $seen, 'A stale snapshot that is rebuilt announces the new one.' );
	}

	public function test_compiled_action_does_not_fire_for_a_current_snapshot_or_an_unchanged_fingerprint(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$compiler->compile( $server );
		$seen = array();
		$this->spy_compiled( $seen );

		$this->assertFalse( $compiler->maybe_recompile_for_server( $server ), 'A current snapshot is left alone.' );
		delete_transient( Config_Compiler::CHECK_TRANSIENT );
		$this->assertFalse( $compiler->maybe_recompile_for_server( $server, true ), 'The periodic check finds the fingerprint unchanged.' );
		$compiler->fingerprint( $server );

		$this->assertSame( array(), $seen );
	}

	public function test_compiled_action_does_not_fire_when_the_server_is_partial(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$compiler->compile( $server );
		$compiler->mark_stale();
		$seen = array();
		$this->spy_compiled( $seen );
		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );

		$this->assertFalse( $compiler->maybe_recompile_for_server( $server ) );
		$this->assertFalse( $compiler->compile_unless_partial( $server ) );

		$this->assertSame( array(), $seen );

		remove_filter( Hooks::PARTIAL_SERVER, '__return_true' );

		$this->assertTrue( $compiler->compile_unless_partial( $server ) );
		$this->assertCount( 1, $seen, 'A compile that goes ahead announces itself once.' );
	}

	public function test_invalid_stored_rule_fails_closed_and_is_recorded(): void {
		$server = $this->server( true );
		update_option(
			Names::OPTION_SETTINGS,
			array(
				'routes' => array(
					'/wp/v2/posts'          => array( 'status' => 'bogus' ),
					'/rfrac-test/v1/things' => 'not an array',
				),
			),
			false
		);
		delete_option( Failure_Recorder::OPTION );

		$rules = ( new Config() )->route_rules( $server );
		$this->assertNull( Failure_Recorder::last(), 'Not recorded on every route_rules() call (every MISS).' );
		( new Config_Compiler() )->compile( $server );

		$this->assertSame( 'excluded', $rules['/wp/v2/posts']->rule->status, 'The enabled default does not stay under an invalid stored rule.' );
		$this->assertSame( 'settings', $rules['/wp/v2/posts']->source );
		$this->assertArrayNotHasKey( '/rfrac-test/v1/things', $rules );
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'route_rules', $last['context'] );
		$this->assertStringContainsString( 'stored rule "/wp/v2/posts"', $last['message'] );

		$compiled = $this->by_key( ( new Config_Compiler() )->compile( $server ) );
		$this->assertTrue( ! isset( $compiled['/wp/v2/posts'] ) || ! $compiled['/wp/v2/posts']->enabled, 'The route is not cached.' );

		$stored = get_option( Names::OPTION_SETTINGS );
		$this->assertArrayHasKey( '/wp/v2/posts', $stored['routes'], 'A compile does not delete the invalid rule.' );
	}

	public function test_non_array_route_rules_filter_is_recorded(): void {
		add_filter( Hooks::ROUTE_RULES, '__return_null' );
		delete_option( Failure_Recorder::OPTION );

		$rules = ( new Config() )->route_rules( $this->server() );
		( new Config_Compiler() )->compile( $this->server() );

		$this->assertSame( 'default', $rules['/wp/v2/posts']->source );
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertStringContainsString( 'instead of an array', $last['message'] );
	}

	public function test_stored_rule_with_default_status_follows_the_default(): void {
		$server = $this->server( true );
		$config = new Config();
		$config->save_route_rule( '/wp/v2/posts', array( 'status' => 'default', 'ttl' => 900 ) );
		$config->save_route_rule( '/rfrac-test/v1/things', array( 'status' => 'default' ) );

		$rules = $config->route_rules( $server );

		$this->assertSame( 'enabled', $rules['/wp/v2/posts']->rule->status );
		$this->assertSame( 900, $rules['/wp/v2/posts']->rule->ttl );
		$this->assertSame( 'default', $rules['/rfrac-test/v1/things']->rule->status );

		$routes = $this->by_key( ( new Config_Compiler() )->compile( $server ) );
		$this->assertArrayNotHasKey( '/rfrac-test/v1/things', $routes );
	}

	public function test_recompile_only_when_something_changed(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();

		$this->assertTrue( $compiler->maybe_recompile_for_server( $server ), 'No stored config yet.' );

		$writes = $this->count_writes(
			function () use ( $compiler, $server, &$second ) {
				$second = $compiler->maybe_recompile_for_server( $server );
			}
		);
		$this->assertFalse( $second );
		$this->assertSame( 0, $writes );
	}

	public function test_recompiling_an_unchanged_config_does_not_write(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$compiler->compile( $server );

		$writes = $this->count_writes( static fn() => $compiler->compile( $server ) );

		$this->assertSame( 0, $writes );
	}

	public function test_new_route_is_picked_up_once_the_config_is_marked_stale(): void {
		$compiler = new Config_Compiler();
		$before   = $compiler->compile( $this->server() )->fingerprint();

		$server = $this->server( true );
		$this->assertNotSame( $before, $compiler->fingerprint( $server ) );
		$this->assertFalse( $compiler->maybe_recompile_for_server( $server ), 'A current snapshot is not rebuilt on every request.' );

		// What a plugin activation does (activated_plugin marks the config stale).
		$compiler->mark_stale();
		$this->assertTrue( $compiler->maybe_recompile_for_server( $server ) );
		$this->assertSame( $compiler->fingerprint( $server ), Compiled_Config::load()->fingerprint() );
		$this->assertFalse( $compiler->maybe_recompile_for_server( $server ) );
	}

	public function test_current_snapshot_is_not_rebuilt_without_the_periodic_check(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$compiler->compile( $server );

		$built = 0;
		add_filter(
			Hooks::UNIVERSAL_PARAMS,
			static function ( $params ) use ( &$built ) {
				++$built;
				return $params;
			}
		);
		$writes = $this->count_writes( fn() => $this->assertFalse( $compiler->maybe_recompile_for_server( $server ) ) );

		$this->assertSame( 0, $built, 'Nothing is built for a current snapshot.' );
		$this->assertSame( 0, $writes );
	}

	public function test_periodic_check_is_rate_limited(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$compiler->compile( $server );
		delete_transient( Config_Compiler::CHECK_TRANSIENT );

		add_filter( Hooks::ALLOW_SKIP_CACHE, '__return_true' );
		$this->assertTrue( $compiler->maybe_recompile_for_server( $server, true ), 'The first periodic check compares the fingerprint.' );
		$this->assertTrue( Compiled_Config::load()->allow_skip_cache() );
		$this->assertNotFalse( get_transient( Config_Compiler::CHECK_TRANSIENT ) );

		add_filter( Hooks::UNIVERSAL_PARAMS, static fn( array $p ): array => array_merge( $p, array( 'custom_param' ) ) );
		$this->assertFalse( $compiler->maybe_recompile_for_server( $server, true ), 'No second check within the interval.' );
		$this->assertNotContains( 'custom_param', Compiled_Config::load()->universal_params() );

		delete_transient( Config_Compiler::CHECK_TRANSIENT );
		$this->assertTrue( $compiler->maybe_recompile_for_server( $server, true ) );
		$this->assertContains( 'custom_param', Compiled_Config::load()->universal_params() );
		delete_transient( Config_Compiler::CHECK_TRANSIENT );
	}

	/**
	 * Count the calls of a filter and record what it received.
	 *
	 * @param mixed                  $return Value the filter returns.
	 * @param int|null               $calls  Number of calls.
	 * @param list<array<int,mixed>> $args   Arguments of every call.
	 *
	 * @return Closure The filter.
	 */
	private function partial_server_spy( $return, ?int &$calls = null, ?array &$args = null ): Closure {
		$calls = 0;
		$args  = array();
		return static function ( $partial, ...$rest ) use ( $return, &$calls, &$args ) {
			++$calls;
			$args[] = array_merge( array( $partial ), $rest );
			return $return;
		};
	}

	public function test_partial_server_skips_the_recompile_of_a_pending_snapshot(): void {
		$compiler = new Config_Compiler();
		$compiler->compile();
		$stored = get_option( Names::OPTION_CONFIG );
		$this->assertTrue( Compiled_Config::load()->pending_discovery() );

		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );
		$writes = $this->count_writes( fn() => $this->assertFalse( $compiler->maybe_recompile_for_server( $this->server() ) ) );

		$this->assertSame( 0, $writes );
		$this->assertSame( $stored, get_option( Names::OPTION_CONFIG ), 'The stored snapshot is unchanged.' );
		$this->assertTrue( Compiled_Config::load()->pending_discovery() );
	}

	public function test_partial_server_skips_a_missing_snapshot_and_a_stale_one(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );

		$this->assertFalse( $compiler->maybe_recompile_for_server( $server ) );
		$this->assertFalse( get_option( Names::OPTION_CONFIG ), 'A missing snapshot stays missing.' );

		remove_filter( Hooks::PARTIAL_SERVER, '__return_true' );
		$compiler->compile( $server );
		$compiler->mark_stale();
		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );

		$this->assertFalse( $compiler->maybe_recompile_for_server( $server ) );
		$this->assertSame( '', Compiled_Config::load()->fingerprint(), 'A stale snapshot stays stale.' );

		remove_filter( Hooks::PARTIAL_SERVER, '__return_true' );
		$this->assertTrue( $compiler->maybe_recompile_for_server( $server ), 'The next full-server request recompiles as before.' );
		$this->assertNotSame( '', Compiled_Config::load()->fingerprint() );
	}

	public function test_partial_server_skips_the_periodic_check_without_consuming_it(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$compiler->compile( $server );
		delete_transient( Config_Compiler::CHECK_TRANSIENT );
		add_filter( Hooks::ALLOW_SKIP_CACHE, '__return_true' );
		$built = 0;
		add_filter(
			Hooks::UNIVERSAL_PARAMS,
			static function ( $params ) use ( &$built ) {
				++$built;
				return $params;
			}
		);
		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );

		$writes = $this->count_writes( fn() => $this->assertFalse( $compiler->maybe_recompile_for_server( $server, true ) ) );

		$this->assertSame( 0, $built, 'Nothing is rebuilt.' );
		$this->assertSame( 0, $writes );
		$this->assertFalse( Compiled_Config::load()->allow_skip_cache() );
		$this->assertFalse( get_transient( Config_Compiler::CHECK_TRANSIENT ), 'The check transient is still unset.' );

		remove_filter( Hooks::PARTIAL_SERVER, '__return_true' );
		$this->assertTrue( $compiler->maybe_recompile_for_server( $server, true ), 'The next full-server request still runs the periodic check.' );
		$this->assertTrue( Compiled_Config::load()->allow_skip_cache() );
		delete_transient( Config_Compiler::CHECK_TRANSIENT );
	}

	public function test_partial_server_skip_saves_and_records_nothing(): void {
		$compiler = new Config_Compiler();
		$compiler->compile();
		delete_option( Failure_Recorder::OPTION );
		add_filter( Hooks::BYPASS_HEADERS, static fn(): array => array( 'bad header', 'x-ok' ) ); // A recompile would record this.
		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );

		$this->assertFalse( $compiler->maybe_recompile_for_server( $this->server() ) );
		$this->assertNull( Failure_Recorder::last(), 'A skip records no failure.' );

		remove_filter( Hooks::PARTIAL_SERVER, '__return_true' );
		$this->assertTrue( $compiler->maybe_recompile_for_server( $this->server() ) );
		$this->assertSame( 'bypass', Failure_Recorder::last()['context'] ?? null, 'Precondition: a recompile does record it.' );
		delete_option( Failure_Recorder::OPTION );
	}

	/**
	 * @dataProvider data_values_that_are_not_strict_true
	 *
	 * @param mixed $value Filter result.
	 */
	public function test_only_a_strict_true_marks_the_server_as_partial( $value ): void {
		$compiler = new Config_Compiler();
		$compiler->compile();
		add_filter( Hooks::PARTIAL_SERVER, fn() => $value );

		$this->assertTrue( $compiler->maybe_recompile_for_server( $this->server() ), 'The recompile proceeds.' );
		$this->assertFalse( Compiled_Config::load()->pending_discovery() );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function data_values_that_are_not_strict_true(): array {
		return array(
			'int 1'       => array( 1 ),
			'string yes'  => array( 'yes' ),
			'string 1'    => array( '1' ),
			'string true' => array( 'true' ),
			'array'       => array( array( true ) ),
			'false'       => array( false ),
			'null'        => array( null ),
		);
	}

	public function test_partial_server_filter_receives_the_default_the_server_and_the_route(): void {
		$compiler = new Config_Compiler();
		$compiler->compile();
		$server = $this->server();
		add_filter( Hooks::PARTIAL_SERVER, $this->partial_server_spy( false, $calls, $args ), 10, 3 );

		$compiler->maybe_recompile_for_server( $server, false, '/wc/store/v1/products' );

		$this->assertSame( 1, $calls );
		$this->assertFalse( $args[0][0], 'The default is false.' );
		$this->assertSame( $server, $args[0][1] );
		$this->assertSame( '/wc/store/v1/products', $args[0][2] );

		$compiler->mark_stale();
		$compiler->maybe_recompile_for_server( $server );
		$this->assertSame( '', $args[1][2], 'The route is empty when unknown.' );
	}

	public function test_partial_server_filter_is_not_applied_to_a_current_snapshot_without_a_due_check(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$compiler->compile( $server );
		add_filter( Hooks::PARTIAL_SERVER, $this->partial_server_spy( true, $calls ), 10, 3 );

		$this->assertFalse( $compiler->maybe_recompile_for_server( $server, false ) );
		$this->assertSame( 0, $calls, 'Not applied without a periodic check.' );

		set_transient( Config_Compiler::CHECK_TRANSIENT, time(), HOUR_IN_SECONDS );
		$this->assertFalse( $compiler->maybe_recompile_for_server( $server, true ) );
		$this->assertSame( 0, $calls, 'Not applied inside the periodic check window.' );
		delete_transient( Config_Compiler::CHECK_TRANSIENT );
	}

	public function test_compile_never_applies_the_partial_server_filter(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		add_filter( Hooks::PARTIAL_SERVER, $this->partial_server_spy( true, $calls ), 10, 3 );

		$compiler->compile();
		$this->assertTrue( Compiled_Config::load()->pending_discovery(), 'compile() without a server ran.' );

		$config = $compiler->compile( $server );
		$this->assertFalse( $config->pending_discovery(), 'compile() with a server ran.' );
		$this->assertNotSame( array(), Compiled_Config::load()->routes() );
		$this->assertSame( 0, $calls );
	}

	public function test_compile_unless_partial_compiles_without_a_partial_server_callback(): void {
		$compiler = new Config_Compiler();

		$this->assertTrue( $compiler->compile_unless_partial( $this->server() ) );

		$config = Compiled_Config::load();
		$this->assertFalse( $config->pending_discovery() );
		$this->assertNotSame( '', $config->fingerprint() );
		$this->assertNotSame( array(), $config->routes() );
	}

	public function test_compile_unless_partial_leaves_a_current_snapshot_serving_and_marks_it_stale(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server( true );
		$compiler->compile( $server );
		$this->assertNotSame( '', Compiled_Config::load()->fingerprint(), 'Precondition: the snapshot is current.' );
		$expected                = get_option( Names::OPTION_CONFIG );
		$expected['fingerprint'] = '';
		delete_option( Failure_Recorder::OPTION );
		add_filter( Hooks::ALLOW_SKIP_CACHE, '__return_true' ); // A compile would pick this up.
		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );

		$this->assertFalse( $compiler->compile_unless_partial( $server ) );

		$this->assertSame( $expected, get_option( Names::OPTION_CONFIG ), 'Only the fingerprint is cleared.' );
		$this->assertFalse( Compiled_Config::load()->allow_skip_cache() );
		$this->assertFalse( Compiled_Config::load()->pending_discovery() );

		remove_filter( Hooks::PARTIAL_SERVER, '__return_true' );
		$this->assertTrue( $compiler->maybe_recompile_for_server( $server ), 'The next full-server request rebuilds the stale snapshot.' );
		$this->assertTrue( Compiled_Config::load()->allow_skip_cache() );
		$this->assertNotSame( '', Compiled_Config::load()->fingerprint() );
	}

	public function test_compile_unless_partial_records_a_clearable_compile_failure_for_a_current_snapshot(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server( true );
		$compiler->compile( $server );
		delete_option( Failure_Recorder::OPTION );
		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );

		$this->assertFalse( $compiler->compile_unless_partial( $server ) );

		$last = Failure_Recorder::last();
		$this->assertSame( 'compile', $last['context'] ?? null, 'The skipped settings compile is not silent.' );
		$this->assertStringContainsString( 'not compiled', $last['message'] ?? '' );
		$this->assertStringContainsString( 'partial', $last['message'] ?? '' );
		$stored = get_option( Failure_Recorder::OPTION );
		$this->assertTrue( $stored['clearable'] ?? null, 'The next successful rebuild clears it.' );
		$this->assertSame( '', $stored['subject'] ?? null, 'Not specific to a route, so any rebuild clears it.' );
	}

	public function test_compile_unless_partial_records_the_skip_for_a_stale_snapshot_too(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$compiler->compile( $server );
		$compiler->mark_stale();
		delete_option( Failure_Recorder::OPTION );
		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );

		$this->assertFalse( $compiler->compile_unless_partial( $server ) );

		$this->assertSame( 'compile', Failure_Recorder::last()['context'] ?? null, 'A stale snapshot keeps serving its old settings.' );
	}

	public function test_compile_unless_partial_keeps_a_missing_snapshot_missing(): void {
		$compiler = new Config_Compiler();
		delete_option( Names::OPTION_CONFIG );
		delete_option( Failure_Recorder::OPTION );
		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );

		$this->assertFalse( $compiler->compile_unless_partial( $this->server() ) );

		$this->assertFalse( get_option( Names::OPTION_CONFIG ) );
		$this->assertNull( Failure_Recorder::last(), 'A missing snapshot serves nothing, so there is nothing pending to report.' );
	}

	public function test_compile_unless_partial_records_nothing_for_a_snapshot_pending_discovery(): void {
		$compiler = new Config_Compiler();
		$compiler->compile();
		$this->assertTrue( Compiled_Config::load()->pending_discovery(), 'Precondition: no routes are compiled.' );
		delete_option( Failure_Recorder::OPTION );
		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );

		$this->assertFalse( $compiler->compile_unless_partial( $this->server() ) );

		$this->assertNull( Failure_Recorder::last(), 'A snapshot without routes serves nothing, so there is nothing pending to report.' );
	}

	public function test_compile_unless_partial_clears_a_recorded_compile_failure_when_it_compiles(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		Failure_Recorder::record( 'compile', 'The saved settings were not compiled.' );
		$this->assertSame( 'compile', Failure_Recorder::last()['context'] ?? null, 'Precondition: a compile failure is recorded.' );

		$this->assertTrue( $compiler->compile_unless_partial( $server ) );

		$this->assertNull( Failure_Recorder::last(), 'A successful compile proves the settings apply again.' );
	}

	public function test_compile_unless_partial_keeps_a_failure_of_another_context_when_it_compiles(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		Failure_Recorder::record( 'capture', 'boom', '/wp/v2/posts' );

		$this->assertTrue( $compiler->compile_unless_partial( $server ) );

		$this->assertSame( 'capture', Failure_Recorder::last()['context'] ?? null );
	}

	/**
	 * @dataProvider data_values_that_are_not_strict_true
	 *
	 * @param mixed $value Filter result.
	 */
	public function test_compile_unless_partial_compiles_unless_the_filter_returns_a_strict_true( $value ): void {
		$compiler = new Config_Compiler();
		$compiler->compile();
		add_filter( Hooks::PARTIAL_SERVER, fn() => $value );

		$this->assertTrue( $compiler->compile_unless_partial( $this->server() ) );

		$this->assertFalse( Compiled_Config::load()->pending_discovery() );
		$this->assertNotSame( '', Compiled_Config::load()->fingerprint() );
	}

	public function test_compile_unless_partial_passes_the_default_the_server_and_the_route_to_the_filter(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		add_filter( Hooks::PARTIAL_SERVER, $this->partial_server_spy( false, $calls, $args ), 10, 3 );

		$compiler->compile_unless_partial( $server, '/x' );

		$this->assertSame( 1, $calls );
		$this->assertSame( array( false, $server, '/x' ), $args[0] );

		$compiler->compile_unless_partial( $server );
		$this->assertSame( '', $args[1][2], 'The route is empty when unknown.' );
	}

	public function test_snapshot_of_an_older_format_is_rebuilt(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$stored   = $compiler->compile( $server )->to_array();
		$stored['format'] = 1;
		unset( $stored['bypass_headers'] );
		update_option( Names::OPTION_CONFIG, $stored );

		$this->assertTrue( $compiler->maybe_recompile_for_server( $server ) );
		$this->assertSame( Config_Compiler::BYPASS_HEADERS, Compiled_Config::load()->bypass_headers() );
	}

	public function test_mark_stale_forces_one_recompile_and_keeps_routes(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$compiler->compile( $server );
		$routes = Compiled_Config::load()->routes();

		$compiler->mark_stale();

		$stale = Compiled_Config::load();
		$this->assertSame( '', $stale->fingerprint() );
		$this->assertEquals( $routes, $stale->routes() );
		$this->assertTrue( $compiler->maybe_recompile_for_server( $server ) );
		$this->assertFalse( $compiler->maybe_recompile_for_server( $server ) );
	}

	public function test_pending_discovery_is_completed_by_a_server(): void {
		$compiler = new Config_Compiler();
		$compiler->compile();

		$this->assertTrue( $compiler->maybe_recompile_for_server( $this->server() ) );
		$this->assertFalse( Compiled_Config::load()->pending_discovery() );
		$this->assertNotSame( array(), Compiled_Config::load()->routes() );
	}

	public function test_fingerprint_changes_when_a_rule_is_enabled(): void {
		$compiler = new Config_Compiler();
		$one      = $compiler->fingerprint( $this->server( true ) );

		( new Config() )->save_route_rule( '/rfrac-test/v1/things', array( 'status' => 'enabled' ) );
		$two = $compiler->fingerprint( $this->server( true ) );

		$this->assertSame( 32, strlen( $one ) );
		$this->assertNotSame( $one, $two );
	}

	public function test_filter_change_recompiles_on_a_stale_config(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$compiler->compile( $server );
		$this->assertFalse( $compiler->maybe_recompile_for_server( $server ) );

		add_filter( Hooks::ALLOW_SKIP_CACHE, '__return_true' );
		$compiler->mark_stale();
		$this->assertTrue( $compiler->maybe_recompile_for_server( $server ) );
		$this->assertTrue( Compiled_Config::load()->allow_skip_cache() );

		add_filter( Hooks::UNIVERSAL_PARAMS, static fn( array $p ): array => array_merge( $p, array( 'custom_param' ) ) );
		$compiler->mark_stale();
		$this->assertTrue( $compiler->maybe_recompile_for_server( $server ) );
		$this->assertContains( 'custom_param', Compiled_Config::load()->universal_params() );

		$writes = $this->count_writes( fn() => $this->assertFalse( $compiler->maybe_recompile_for_server( $server ) ) );
		$this->assertSame( 0, $writes );
	}

	public function test_stored_setting_change_recompiles(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$compiler->compile( $server );
		$this->assertTrue( Compiled_Config::load()->enabled() );

		( new Config() )->save_settings( array( 'enabled' => false ) );
		$compiler->mark_stale(); // The settings save recompiles through the container hooks, detached here.

		$this->assertTrue( $compiler->maybe_recompile_for_server( $server ) );
		$this->assertFalse( Compiled_Config::load()->enabled() );
		$this->assertFalse( $compiler->maybe_recompile_for_server( $server ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disable_constant_recompiles(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$compiler->compile( $server );
		$this->assertTrue( Compiled_Config::load()->enabled() );

		define( 'RFRAC_DISABLE', true );
		delete_transient( Config_Compiler::CHECK_TRANSIENT );

		$this->assertFalse( $compiler->maybe_recompile_for_server( $server ), 'Only the periodic check sees a constant change.' );
		$this->assertTrue( $compiler->maybe_recompile_for_server( $server, true ) );
		$this->assertFalse( Compiled_Config::load()->enabled() );
		$this->assertFalse( $compiler->maybe_recompile_for_server( $server ) );
	}

	public function test_store_changed_action_fires_with_old_and_new_mode(): void {
		$compiler = new Config_Compiler();
		$compiler->compile( $this->server() );

		$calls = array();
		add_action(
			Hooks::CONFIG_STORE_CHANGED,
			static function ( $old, $new ) use ( &$calls ) {
				$calls[] = array( $old, $new );
			},
			10,
			2
		);

		( new Config() )->save_settings( array( 'store' => 'object_cache' ) );
		$compiler->compile( $this->server() );
		$compiler->compile( $this->server() );

		$this->assertSame( array( array( 'database', 'object_cache' ) ), $calls );
	}

	public function test_filters_shape_the_global_lists(): void {
		add_filter(
			Hooks::UNIVERSAL_PARAMS,
			static fn( array $p ): array => array_merge( $p, array( 'custom_param', 'bad name!' ) )
		);
		add_filter(
			Hooks::BYPASS_COOKIE_PREFIXES,
			static fn( array $p ): array => array_merge( $p, array( 'my_session_' ) )
		);
		add_filter(
			Hooks::REPLAY_HEADERS,
			static fn( array $h ): array => array_merge( $h, array( 'X-Custom', 'Set-Cookie', 'etag', 'vary' ) )
		);
		add_filter( Hooks::ALLOW_SKIP_CACHE, '__return_true' );

		$config = ( new Config_Compiler() )->compile( $this->server() );

		$this->assertContains( 'custom_param', $config->universal_params() );
		$this->assertNotContains( 'bad name!', $config->universal_params() );
		$this->assertContains( 'my_session_', $config->bypass_cookie_prefixes() );
		$this->assertContains( 'x-custom', $config->replay_headers() );
		foreach ( array( 'set-cookie', 'etag', 'vary' ) as $forbidden ) {
			$this->assertNotContains( $forbidden, $config->replay_headers() );
		}
		$this->assertTrue( $config->allow_skip_cache() );
	}

	public function test_a_compile_without_the_name_free_filters_writes_neither_flag_and_keeps_the_fingerprint(): void {
		$config = ( new Config_Compiler() )->compile( $this->server() );

		$this->assertTrue( $config->cors_on_hits() );
		$this->assertFalse( $config->bypass_all() );
		$this->assertArrayNotHasKey( 'cors_on_hits', $config->to_array() );
		$this->assertArrayNotHasKey( 'bypass_all', $config->to_array() );

		$stored = get_option( Names::OPTION_CONFIG );
		$this->assertIsArray( $stored );
		$this->assertArrayNotHasKey( 'cors_on_hits', $stored );
		$this->assertArrayNotHasKey( 'bypass_all', $stored );

		// The fingerprint hashes exactly the fields of the snapshot as it was before the flags existed.
		$fingerprint = $stored['fingerprint'];
		unset( $stored['fingerprint'] );
		$this->assertSame( md5( (string) wp_json_encode( $stored ) ), $fingerprint );
	}

	public function test_the_cors_on_hits_filter_is_compiled_only_for_a_strict_false(): void {
		add_filter( Hooks::CORS_ON_HITS, '__return_false' );
		$config = ( new Config_Compiler() )->compile( $this->server() );

		$this->assertFalse( $config->cors_on_hits() );
		$this->assertFalse( Compiled_Config::load()->cors_on_hits() );
		$this->assertFalse( $config->to_array()['cors_on_hits'] );
		$this->assertArrayNotHasKey( 'bypass_all', $config->to_array() );

		foreach ( array( 0, '', null, array(), 'no' ) as $loose ) {
			remove_all_filters( Hooks::CORS_ON_HITS );
			add_filter( Hooks::CORS_ON_HITS, static fn() => $loose );
			$config = ( new Config_Compiler() )->compile( $this->server() );

			$this->assertTrue( $config->cors_on_hits(), 'Only a strict false switches the headers off.' );
			$this->assertArrayNotHasKey( 'cors_on_hits', $config->to_array() );
		}

		remove_all_filters( Hooks::CORS_ON_HITS );
		add_filter( Hooks::CORS_ON_HITS, '__return_true' );
		$this->assertTrue( ( new Config_Compiler() )->compile( $this->server() )->cors_on_hits() );
	}

	public function test_the_bypass_all_filter_is_compiled_only_for_a_strict_true(): void {
		add_filter( Hooks::BYPASS_ALL, '__return_true' );
		$config = ( new Config_Compiler() )->compile( $this->server() );

		$this->assertTrue( $config->bypass_all() );
		$this->assertTrue( Compiled_Config::load()->bypass_all() );
		$this->assertTrue( $config->to_array()['bypass_all'] );
		$this->assertArrayNotHasKey( 'cors_on_hits', $config->to_array() );

		foreach ( array( 1, 'yes', array( 1 ), null, false ) as $loose ) {
			remove_all_filters( Hooks::BYPASS_ALL );
			add_filter( Hooks::BYPASS_ALL, static fn() => $loose );
			$config = ( new Config_Compiler() )->compile( $this->server() );

			$this->assertFalse( $config->bypass_all(), 'Only a strict true bypasses every request.' );
			$this->assertArrayNotHasKey( 'bypass_all', $config->to_array() );
		}
	}

	public function test_a_name_free_filter_changes_the_fingerprint_and_removing_it_restores_it(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$plain    = $compiler->fingerprint( $server );

		add_filter( Hooks::CORS_ON_HITS, '__return_false' );
		$cors = $compiler->fingerprint( $server );
		remove_all_filters( Hooks::CORS_ON_HITS );
		add_filter( Hooks::BYPASS_ALL, '__return_true' );
		$all = $compiler->fingerprint( $server );
		remove_all_filters( Hooks::BYPASS_ALL );

		$this->assertNotSame( $plain, $cors );
		$this->assertNotSame( $plain, $all );
		$this->assertNotSame( $cors, $all );
		$this->assertSame( $plain, $compiler->fingerprint( $server ) );
	}

	public function test_a_compile_without_a_nonce_caching_callback_writes_nothing_and_keeps_the_fingerprint(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$plain    = $compiler->fingerprint( $server );

		$config = $compiler->compile( $server );
		$this->assertFalse( $config->nonce_caching() );
		$this->assertSame( array(), $config->login_cookies() );
		$this->assertArrayNotHasKey( 'nonce_caching', $config->to_array() );
		$this->assertArrayNotHasKey( 'login_cookies', $config->to_array() );
		$stored = get_option( Names::OPTION_CONFIG );
		$this->assertIsArray( $stored );
		$this->assertArrayNotHasKey( 'nonce_caching', $stored );
		$this->assertArrayNotHasKey( 'login_cookies', $stored );
		$this->assertSame( $plain, $stored['fingerprint'] );

		add_filter( Hooks::NONCE_CACHING, '__return_false' );
		$this->assertSame( $plain, $compiler->fingerprint( $server ) );
	}

	public function test_the_nonce_caching_filter_is_compiled_only_for_a_strict_true(): void {
		$seen = array();
		add_filter(
			Hooks::NONCE_CACHING,
			static function ( $enabled, $source = null ) use ( &$seen ) {
				$seen[] = array( $enabled, $source );
				return true;
			},
			10,
			2
		);
		$compiler = new Config_Compiler();
		$config   = $compiler->compile( $this->server() );

		$this->assertSame( array( array( false, 'default' ) ), array_slice( $seen, 0, 1 ) );
		$this->assertTrue( $config->nonce_caching() );
		$this->assertTrue( Compiled_Config::load()->nonce_caching() );
		$this->assertTrue( $config->to_array()['nonce_caching'] );
		$this->assertArrayNotHasKey( 'login_cookies', $config->to_array() );

		foreach ( array( 1, '1', 'yes', array( 1 ), null, false ) as $loose ) {
			remove_all_filters( Hooks::NONCE_CACHING );
			add_filter( Hooks::NONCE_CACHING, static fn() => $loose );
			$config = $compiler->compile( $this->server() );

			$this->assertFalse( $config->nonce_caching(), 'Only a strict true switches nonce caching on.' );
			$this->assertArrayNotHasKey( 'nonce_caching', $config->to_array() );
		}
	}

	public function test_the_nonce_caching_filter_changes_the_fingerprint_and_removing_it_restores_it(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$plain    = $compiler->fingerprint( $server );

		add_filter( Hooks::NONCE_CACHING, '__return_true' );
		$on = $compiler->fingerprint( $server );
		remove_all_filters( Hooks::NONCE_CACHING );

		$this->assertNotSame( $plain, $on );
		$this->assertSame( $plain, $compiler->fingerprint( $server ) );
	}

	public function test_the_login_cookie_names_of_a_default_site_are_all_covered_by_the_unfilterable_rules(): void {
		$this->assertSame( array(), Config_Compiler::login_cookie_names( array( LOGGED_IN_COOKIE, SECURE_AUTH_COOKIE, AUTH_COOKIE ) ) );
		$this->assertSame( array(), ( new Config_Compiler() )->compile( $this->server() )->login_cookies() );
	}

	/**
	 * Both serving paths.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function serving_paths(): array {
		return array(
			'early'    => array( Rfrac_Serving_Harness::EARLY ),
			'fallback' => array( Rfrac_Serving_Harness::FALLBACK ),
		);
	}

	/**
	 * A compiler for a site whose login cookie constants hold the given names.
	 *
	 * WordPress fixes the constants at bootstrap, so the test stands in for {@see Config_Compiler::defined_login_cookies()};
	 * the real method is covered by {@see self::test_the_compiler_reads_the_login_cookie_constants_a_site_defines()}.
	 *
	 * @param list<string> $cookie_constants The values of `LOGGED_IN_COOKIE`, `SECURE_AUTH_COOKIE` and `AUTH_COOKIE`.
	 *
	 * @return Config_Compiler
	 */
	private function compiler_for_login_cookie_constants( array $cookie_constants ): Config_Compiler {
		return new class( $cookie_constants ) extends Config_Compiler {

			/**
			 * Constructor.
			 *
			 * @param list<string> $cookie_constants The constants' values.
			 */
			public function __construct( private readonly array $cookie_constants ) {
				parent::__construct();
			}

			/**
			 * The stand-in constants.
			 *
			 * @return list<string>
			 */
			protected function defined_login_cookies(): array {
				return $this->cookie_constants;
			}
		};
	}

	/**
	 * Look up a request on a serving path and assert the policy decision it got.
	 *
	 * @param Rfrac_Serving_Harness $h       Harness of the serving path.
	 * @param string                $uri     Request URI.
	 * @param array<string,mixed>   $server  Extra `$_SERVER` entries.
	 * @param array<string,mixed>   $cookie  Cookies, named as PHP exposes them.
	 * @param Bypass_Reason|null    $reason  Expected bypass reason; null for a cacheable request.
	 * @param string                $message Failure message.
	 *
	 * @return void
	 */
	private function assert_lookup_decision( Rfrac_Serving_Harness $h, string $uri, array $server, array $cookie, ?Bypass_Reason $reason, string $message ): void {
		$h->request( $uri, $server, $cookie );
		$this->assertFalse( $h->lookup(), $message . ': nothing is stored, so nothing is served.' );

		$decision = Request_State::decision();
		$this->assertNotNull( $decision, $message );
		$this->assertSame( $reason, $decision->reason, $message );
		$this->assertSame( null === $reason, $decision->cacheable, $message );
	}

	/**
	 * @dataProvider serving_paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_renamed_login_cookie_is_compiled_and_bypasses_with_or_without_a_nonce_on_both_serving_paths( string $path ): void {
		Coexistence_Guard::reset();
		wp_set_current_user( 0 );
		update_option( 'permalink_structure', '/%postname%/' );
		add_filter( Hooks::NONCE_CACHING, '__return_true' );

		$posts      = '/wp-json/wp/v2/posts';
		$with_nonce = $posts . '?_wpnonce=NONCE-7f3a9c';
		$nonce      = array( 'HTTP_X_WP_NONCE' => 'NONCE-7f3a9c' );
		$login      = array( 'my_login' => 'admin|1|x' );
		$h          = new Rfrac_Serving_Harness( $path );

		try {
			// Control: a site that keeps the default cookie names compiles no list, so its renamed cookie is no login cookie.
			$default = ( new Config_Compiler() )->compile( $this->server() );
			$this->assertSame( array(), $default->login_cookies() );
			$this->assert_lookup_decision( $h, $posts, array(), $login, null, 'Default compile, renamed cookie' );
			$this->assert_lookup_decision( $h, $with_nonce, $nonce, $login, null, 'Default compile, renamed cookie and nonce' );

			// LOGGED_IN_COOKIE is renamed to `my.login`; PHP exposes that cookie as `my_login`.
			$compiled = $this->compiler_for_login_cookie_constants( array( 'my.login', SECURE_AUTH_COOKIE, AUTH_COOKIE ) )->compile( $this->server() );
			$stored   = get_option( Names::OPTION_CONFIG );
			$this->assertIsArray( $stored );
			$this->assertSame( array( 'my_login' ), $stored['login_cookies'] );
			$this->assertTrue( $stored['nonce_caching'] );
			$this->assertSame( array( 'my_login' ), $compiled->login_cookies() );
			$this->assertSame( array( 'my_login' ), Compiled_Config::load()->login_cookies() );

			$this->assert_lookup_decision( $h, $posts, array(), $login, Bypass_Reason::CREDENTIALS, 'Renamed login cookie' );
			$this->assert_lookup_decision( $h, $with_nonce, $nonce, $login, Bypass_Reason::CREDENTIALS, 'Renamed login cookie next to a nonce' );
			$this->assert_lookup_decision( $h, $with_nonce, $nonce, array(), null, 'Control: the nonce alone is cacheable' );
			$this->assert_lookup_decision( $h, $posts, array(), array( 'my_login_other' => 'x' ), null, 'Another cookie is no login cookie' );
		} finally {
			$h->restore();
		}
	}

	/**
	 * The login cookie constants as a site defines them, run in a fresh PHP process so that they are defined before WordPress
	 * would define its defaults (core keeps any constant that is already defined).
	 *
	 * @param array<string,string> $constants Constant => value, for the constants the site defines.
	 *
	 * @return array{0:list<string>,1:list<string>} The names the compiler reads from the constants, and the names it compiles.
	 */
	private function login_cookie_names_of_a_site_defining( array $constants ): array {
		$autoloader = dirname( __DIR__, 2 ) . '/includes/class-autoloader.php';
		$defines    = '';
		foreach ( $constants as $constant => $value ) {
			$defines .= 'define( ' . var_export( $constant, true ) . ', ' . var_export( $value, true ) . ' );' . "\n";
		}
		$script = '<?php
define( "ABSPATH", __DIR__ . "/" );
' . $defines . 'require ' . var_export( $autoloader, true ) . ';
\Rockfire\Rest_Api_Cache\Autoloader::register();
$compiler = ( new ReflectionClass( \Rockfire\Rest_Api_Cache\Config\Config_Compiler::class ) )->newInstanceWithoutConstructor();
$names    = ( new ReflectionMethod( $compiler, "defined_login_cookies" ) )->invoke( $compiler );
echo json_encode( array( $names, \Rockfire\Rest_Api_Cache\Config\Config_Compiler::login_cookie_names( $names ) ) );
';
		$file   = tempnam( sys_get_temp_dir(), 'rfrac' );
		file_put_contents( $file, $script ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		try {
			$output = array();
			$status = 1;
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );
		} finally {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		$this->assertSame( 0, $status, implode( "\n", $output ) );

		$decoded = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $decoded, implode( "\n", $output ) );
		return $decoded;
	}

	public function test_the_compiler_reads_the_login_cookie_constants_a_site_defines(): void {
		$this->assertSame(
			array(
				array( 'my.login', 'secure auth', 'wp_auth[x]' ),
				array( 'my_login', 'secure_auth', 'wp_auth' ),
			),
			$this->login_cookie_names_of_a_site_defining(
				array(
					'LOGGED_IN_COOKIE'   => 'my.login',
					'SECURE_AUTH_COOKIE' => 'secure auth',
					'AUTH_COOKIE'        => 'wp_auth[x]',
				)
			)
		);
		$this->assertSame(
			array(
				array( 'my.login' ),
				array( 'my_login' ),
			),
			$this->login_cookie_names_of_a_site_defining( array( 'LOGGED_IN_COOKIE' => 'my.login' ) ),
			'A constant the site does not define is skipped.'
		);
	}

	public function test_the_login_cookie_names_are_normalised_as_php_names_them_and_sorted(): void {
		$this->assertSame(
			array( 'arr', 'auth_cookie', 'my_login' ),
			Config_Compiler::login_cookie_names( array( 'my.login', 'wordpress_sec_x', 'auth cookie', 'arr[a]' ) )
		);
		$this->assertSame(
			array( 'a', 'b' ),
			Config_Compiler::login_cookie_names( array( 'b', 'a', 'b', '', '[x]', 42, null, 'wordpress_logged_in_x', 'wp-postpass_x', 'wordpress_' . str_repeat( 'f', 32 ) ) )
		);
		$this->assertSame( array( 'wordpress_' . str_repeat( 'f', 31 ), 'wordpress_test_cookie' ), Config_Compiler::login_cookie_names( array( 'wordpress_test_cookie', 'wordpress_' . str_repeat( 'f', 31 ) ) ) );
	}

	public function test_an_invalid_filter_entry_never_removes_a_valid_bypass(): void {
		add_filter( Hooks::BYPASS_COOKIE_PREFIXES, static fn( array $p ): array => array_merge( $p, array( 'bad;name', 'my_session_' ) ) );
		add_filter( Hooks::BYPASS_HEADERS, static fn( array $h ): array => array_merge( $h, array( 'X Bad', 'X_Shop_Key' ) ) );
		delete_option( Failure_Recorder::OPTION );

		$config = ( new Config_Compiler() )->compile( $this->server() );

		$this->assertSame( array_merge( Config_Compiler::BYPASS_COOKIE_PREFIXES, array( 'my_session_' ) ), $config->bypass_cookie_prefixes() );
		$this->assertSame( array_merge( Config_Compiler::BYPASS_HEADERS, array( 'x-shop-key' ) ), $config->bypass_headers(), 'X_Shop_Key is x-shop-key, as PHP sees it.' );

		$last = Failure_Recorder::last();
		$this->assertNotNull( $last, 'Invalid filter entries are reported.' );
		$this->assertSame( 'bypass', $last['context'] );
		$this->assertStringContainsString( '"bad;name"', $last['message'] );
		$this->assertStringContainsString( '(and 1 more)', $last['message'] );
	}

	public function test_normalize_header_name(): void {
		$this->assertSame( 'x-tenant-token', Config_Compiler::normalize_header_name( ' X_Tenant_Token ' ) );
		foreach ( array( '', 'x tenant', 'x.tenant', 'x:y', str_repeat( 'a', 65 ), null, 5 ) as $invalid ) {
			$this->assertNull( Config_Compiler::normalize_header_name( $invalid ) );
		}
	}

	public function test_bypass_problems_are_recorded_once_per_compile_and_not_by_a_fingerprint_check(): void {
		add_filter( Hooks::BYPASS_HEADERS, static fn( array $h ): array => array_merge( $h, array( 'bad header' ) ) );
		delete_option( Failure_Recorder::OPTION );

		$compiler = new Config_Compiler();
		$compiler->fingerprint( $this->server() );
		$this->assertNull( Failure_Recorder::last(), 'A fingerprint check records nothing.' );

		$compiler->compile( $this->server() );
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'bypass', $last['context'] );
		$this->assertFalse( (bool) get_option( Failure_Recorder::OPTION )['clearable'], 'Not cleared by a later success.' );
	}

	public function test_bypass_filters_returning_only_invalid_entries_keep_the_defaults(): void {
		add_filter( Hooks::BYPASS_COOKIE_PREFIXES, static fn(): array => array( 'bad;name', '   ' ) );
		add_filter( Hooks::BYPASS_HEADERS, static fn(): array => array( 'X Bad', 'bad header' ) );

		$config = ( new Config_Compiler() )->compile( $this->server() );

		$this->assertSame( Config_Compiler::BYPASS_COOKIE_PREFIXES, $config->bypass_cookie_prefixes() );
		$this->assertSame( Config_Compiler::BYPASS_HEADERS, $config->bypass_headers() );
	}

	public function test_bypass_filters_returning_an_empty_list_clear_it(): void {
		add_filter( Hooks::BYPASS_COOKIE_PREFIXES, '__return_empty_array' );
		add_filter( Hooks::BYPASS_HEADERS, '__return_empty_array' );

		$config = ( new Config_Compiler() )->compile( $this->server() );

		$this->assertSame( array(), $config->bypass_cookie_prefixes() );
		$this->assertSame( array(), $config->bypass_headers() );
	}

	public function test_filter_rule_that_is_not_an_array_excludes_a_default_route(): void {
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( array $rules ): array {
				$rules['/wp/v2/posts']     = 'enabled';
				$rules['/rfrac-none/v1/x'] = array( 'status' => 'bogus' );
				return $rules;
			}
		);

		$rules = ( new Config() )->route_rules( $this->server() );

		$this->assertSame( 'excluded', $rules['/wp/v2/posts']->rule->status );
		$this->assertSame( 'filter', $rules['/wp/v2/posts']->source );
		$this->assertArrayNotHasKey( '/rfrac-none/v1/x', $rules, 'An invalid rule without a lower layer is ignored, not added.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_invalid_filter_rule_over_a_constant_rule_fails_closed(): void {
		define( 'RFRAC_ROUTES', array( '/rfrac-test/v1/things' => array( 'status' => 'enabled' ) ) );
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( array $rules ): array {
				$rules['/rfrac-test/v1/things'] = array( 'status' => 'bogus' );
				return $rules;
			}
		);

		$rules = ( new Config() )->route_rules( $this->server( true ) );

		$this->assertSame( 'excluded', $rules['/rfrac-test/v1/things']->rule->status );
	}

	public function test_non_array_bypass_filters_keep_the_defaults(): void {
		add_filter( Hooks::BYPASS_COOKIE_PREFIXES, '__return_null' );
		add_filter( Hooks::BYPASS_HEADERS, '__return_false' );

		$config = ( new Config_Compiler() )->compile( $this->server() );

		$this->assertSame( Config_Compiler::BYPASS_COOKIE_PREFIXES, $config->bypass_cookie_prefixes() );
		$this->assertSame( Config_Compiler::BYPASS_HEADERS, $config->bypass_headers() );
	}

	public function test_bypass_cookie_prefixes_accept_cookie_name_characters_as_php_names_them(): void {
		add_filter(
			Hooks::BYPASS_COOKIE_PREFIXES,
			static fn(): array => array( 'my.app_', "shop'cart~", 'a!b#c$', 'a+b_', 'bad name', 'bad;name', 'bad=name', 'bad%2Ename', '', '   ', 42, str_repeat( 'a', 129 ) )
		);

		$this->assertSame( array( 'my_app_', "shop'cart~", 'a!b#c$', 'a+b_' ), ( new Config_Compiler() )->compile( $this->server() )->bypass_cookie_prefixes() );
	}

	public function test_normalize_cookie_prefix(): void {
		$this->assertSame( 'my_app_', Config_Compiler::normalize_cookie_prefix( ' my.app_ ' ) );
		$this->assertSame( str_repeat( 'a', 128 ), Config_Compiler::normalize_cookie_prefix( str_repeat( 'a', 128 ) ) );
		foreach ( array( '', 'a b', 'a,b', 'a[b', "a\nb", 'a;b', 'a=b', null, array( 'x' ) ) as $invalid ) {
			$this->assertNull( Config_Compiler::normalize_cookie_prefix( $invalid ) );
		}
	}

	public function test_bypass_headers_default_and_filter(): void {
		$this->assertSame( array( 'x-api-key', 'x-auth-token', 'x-access-token' ), ( new Config_Compiler() )->compile( $this->server() )->bypass_headers() );

		add_filter(
			Hooks::BYPASS_HEADERS,
			static fn( array $h ): array => array_merge( array_diff( $h, array( 'x-auth-token' ) ), array( 'X-Shop-Key', 'bad header!' ) )
		);
		$headers = ( new Config_Compiler() )->compile( $this->server() )->bypass_headers();

		$this->assertSame( array( 'x-api-key', 'x-access-token', 'x-shop-key' ), $headers );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_compile_constants(): void {
		define( 'RFRAC_ALLOW_SKIP_CACHE', false );
		define( 'RFRAC_BYPASS_COOKIES', array( 'shop_cart_' ) );
		define( 'RFRAC_CORS_WILDCARD', false );
		add_filter( Hooks::ALLOW_SKIP_CACHE, '__return_true' );

		$config = ( new Config_Compiler() )->compile( $this->server() );

		$this->assertFalse( $config->allow_skip_cache(), 'The constant wins over the filter.' );
		$this->assertContains( 'shop_cart_', $config->bypass_cookie_prefixes() );
		$this->assertContains( 'wordpress_logged_in_', $config->bypass_cookie_prefixes() );
		$this->assertFalse( $config->cors_wildcard() );
	}

	public function test_settings_are_compiled(): void {
		( new Config() )->save_settings(
			array(
				'enabled'        => false,
				'default_ttl'    => 600,
				'max_payload_kb' => 2,
				'max_params'     => 5,
				'hit_sampling'   => 0,
			)
		);

		$config = ( new Config_Compiler() )->compile( $this->server() );

		$this->assertFalse( $config->enabled() );
		$this->assertSame( 600, $config->default_ttl() );
		$this->assertSame( 2048, $config->max_payload_bytes() );
		$this->assertSame( 5, $config->max_params() );
		$this->assertSame( 0, $config->hit_sampling() );
		$this->assertSame( 600, $this->by_key( $config )['/wp/v2/posts']->ttl );
	}

	/**
	 * The stored routes of the saved snapshot, by key.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function stored_routes(): array {
		$stored = get_option( Names::OPTION_CONFIG );
		$out    = array();
		foreach ( $stored['routes'] as $route ) {
			$out[ $route['key'] ] = $route;
		}
		return $out;
	}

	public function test_route_varies_per_visitor_is_compiled_for_the_route_only(): void {
		add_filter(
			Hooks::ROUTE_VARIES_PER_VISITOR,
			static fn( $varies, $route_key ) => '/wp/v2/posts' === $route_key ? true : $varies,
			10,
			2
		);

		( new Config_Compiler() )->compile( $this->server() );
		$stored = $this->stored_routes();

		$this->assertTrue( $stored['/wp/v2/posts']['visitor_varies'] );
		foreach ( $stored as $key => $route ) {
			if ( '/wp/v2/posts' !== $key ) {
				$this->assertArrayNotHasKey( 'visitor_varies', $route, $key );
			}
		}
		$this->assertTrue( $this->by_key( Compiled_Config::load() )['/wp/v2/posts']->visitor_varies );
		$this->assertArrayNotHasKey( 'vary_invalid', $stored['/wp/v2/posts'], 'A route that varies on purpose has a valid declaration.' );
		$this->assertFalse( $this->by_key( Compiled_Config::load() )['/wp/v2/posts']->vary_invalid );
	}

	public function test_route_vary_cookies_are_normalised_and_compiled(): void {
		add_filter(
			Hooks::ROUTE_VARY_COOKIES,
			static fn( $cookies, $route_key ) => '/wp/v2/posts' === $route_key ? array( 'my.cur' => array( 'USD', 'EUR', 'EUR' ) ) : $cookies,
			10,
			2
		);
		delete_option( Failure_Recorder::OPTION );

		( new Config_Compiler() )->compile( $this->server() );
		$stored = $this->stored_routes();

		$this->assertSame( array( 'my_cur' => array( 'EUR', 'USD' ) ), $stored['/wp/v2/posts']['vary_cookies'] );
		$this->assertArrayNotHasKey( 'visitor_varies', $stored['/wp/v2/posts'] );
		$this->assertArrayNotHasKey( 'vary_cookies', $stored['/wp/v2/pages'] );
		$this->assertNull( Failure_Recorder::last() );
		$this->assertSame( array( 'my_cur' ), Compiled_Config::load()->vary_cookie_names() );
	}

	public function test_seams_are_not_called_for_a_route_that_is_not_enabled(): void {
		$seen = array();
		add_filter(
			Hooks::ROUTE_VARIES_PER_VISITOR,
			static function ( $varies, $route_key ) use ( &$seen ) {
				$seen[] = $route_key;
				return $varies;
			},
			10,
			2
		);
		add_filter(
			Hooks::ROUTE_VARY_COOKIES,
			static function ( $cookies, $route_key ) use ( &$seen ) {
				$seen[] = $route_key;
				return $cookies;
			},
			10,
			2
		);

		( new Config_Compiler() )->compile( $this->server( true ) );
		$stored = $this->stored_routes();

		$this->assertNotContains( '/rfrac-test/v1/things', $seen );
		$this->assertContains( '/wp/v2/posts', $seen );
		$this->assertSame( array( 'key', 'regex', 'enabled' ), array_keys( $stored['/rfrac-test/v1/things'] ) );
		$this->assertFalse( $stored['/rfrac-test/v1/things']['enabled'] );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function invalid_vary_declarations(): array {
		return array(
			'null'            => array( null ),
			'invalid name'    => array( array( 'bad%name' => array( 'x' ) ) ),
			'no values'       => array( array( 'c' => array() ) ),
			'nine cookies'    => array( array_fill_keys( array( 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i' ), array( 'x' ) ) ),
		);
	}

	/**
	 * @dataProvider invalid_vary_declarations
	 *
	 * @param mixed $declared What the filter returns.
	 */
	public function test_invalid_vary_declaration_fails_closed_and_is_recorded( $declared ): void {
		add_filter(
			Hooks::ROUTE_VARY_COOKIES,
			static fn( $cookies, $route_key ) => '/wp/v2/posts' === $route_key ? $declared : $cookies,
			10,
			2
		);
		delete_option( Failure_Recorder::OPTION );

		( new Config_Compiler() )->compile( $this->server() );
		$stored = $this->stored_routes();

		$this->assertTrue( $stored['/wp/v2/posts']['visitor_varies'] );
		$this->assertTrue( $stored['/wp/v2/posts']['vary_invalid'] );
		$this->assertArrayNotHasKey( 'vary_cookies', $stored['/wp/v2/posts'] );
		$this->assertArrayNotHasKey( 'vary_invalid', $stored['/wp/v2/pages'] );
		$loaded = $this->by_key( Compiled_Config::load() )['/wp/v2/posts'];
		$this->assertTrue( $loaded->visitor_varies );
		$this->assertTrue( $loaded->vary_invalid, 'The reason survives the snapshot.' );
		$this->assertSame( Compiled_Route::VARY_COOKIES, $loaded->vary_cause, 'The cause is the cookie declaration.' );
		$this->assertArrayNotHasKey( 'vary_cause', $stored['/wp/v2/posts'], 'A bare `vary_invalid` stands for the cookie declaration.' );
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'route_rules', $last['context'] );
		$this->assertStringStartsWith( 'Invalid vary declaration, the route is not cached: ', $last['message'] );
		$this->assertStringContainsString( '/wp/v2/posts', $last['message'] );
		$this->assertStringNotContainsString( 'more)', $last['message'], 'Exactly one problem.' );
	}

	public function test_route_rule_problems_come_before_vary_problems(): void {
		add_filter( Hooks::ROUTE_RULES, static fn( array $rules ): array => array_merge( $rules, array( '/wp/v2/tags' => array( 'status' => 'bogus' ) ) ) );
		add_filter( Hooks::ROUTE_VARY_COOKIES, '__return_null' );
		delete_option( Failure_Recorder::OPTION );

		( new Config_Compiler() )->compile( $this->server() );

		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'route_rules', $last['context'] );
		$this->assertStringStartsWith( 'Invalid route rule ignored', $last['message'] );
		$this->assertStringContainsString( 'more)', $last['message'] );
	}

	public function test_vary_problems_come_before_bypass_problems(): void {
		add_filter( Hooks::BYPASS_HEADERS, static fn( array $h ): array => array_merge( $h, array( 'bad header' ) ) );
		add_filter( Hooks::ROUTE_VARY_COOKIES, '__return_null' );
		delete_option( Failure_Recorder::OPTION );

		( new Config_Compiler() )->compile( $this->server() );

		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'route_rules', $last['context'] );
		$this->assertStringStartsWith( 'Invalid vary declaration', $last['message'] );
	}

	public function test_snapshot_and_fingerprint_are_unchanged_without_seam_answers(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();

		$compiler->compile( $server );
		foreach ( $this->stored_routes() as $key => $route ) {
			$this->assertArrayNotHasKey( 'visitor_varies', $route, $key );
			$this->assertArrayNotHasKey( 'vary_cookies', $route, $key );
			$this->assertArrayNotHasKey( 'vary_invalid', $route, $key );
		}
		$plain =Compiled_Config::load()->fingerprint();

		add_filter( Hooks::ROUTE_VARIES_PER_VISITOR, '__return_false' );
		add_filter( Hooks::ROUTE_VARY_COOKIES, '__return_empty_array' );
		$this->assertSame( $plain, $compiler->fingerprint( $server ), 'Default seam answers leave the fingerprint alone.' );
	}

	public function test_a_seam_answer_changes_the_fingerprint(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();
		$plain    = $compiler->fingerprint( $server );

		add_filter( Hooks::ROUTE_VARIES_PER_VISITOR, '__return_true' );
		$varies = $compiler->fingerprint( $server );
		remove_filter( Hooks::ROUTE_VARIES_PER_VISITOR, '__return_true' );

		add_filter( Hooks::ROUTE_VARY_COOKIES, static fn(): array => array( 'cur' => array( 'EUR' ) ) );
		$cookies = $compiler->fingerprint( $server );

		$this->assertNotSame( $plain, $varies );
		$this->assertNotSame( $plain, $cookies );
		$this->assertNotSame( $varies, $cookies );
	}

	/**
	 * Fresh server with a read-write, a read-only and a write-only test route.
	 *
	 * @return WP_REST_Server
	 */
	private function methods_server(): WP_REST_Server {
		return $this->new_rest_server(
			static function () {
				register_rest_route(
					'rfrac-test/v1',
					'/rw',
					array(
						array(
							'methods'             => 'GET',
							'callback'            => '__return_true',
							'permission_callback' => '__return_true',
							'args'                => array( 'a' => array() ),
						),
						array(
							'methods'             => 'POST',
							'callback'            => '__return_true',
							'permission_callback' => '__return_true',
							'args'                => array( 'b' => array() ),
						),
					)
				);
				register_rest_route(
					'rfrac-test/v1',
					'/read-only',
					array(
						'methods'             => 'GET',
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
						'args'                => array( 'color' => array() ),
					)
				);
				register_rest_route(
					'rfrac-test/v1',
					'/write-only',
					array(
						'methods'             => 'POST',
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
						'args'                => array( 'body' => array() ),
					)
				);
			}
		);
	}

	public function test_a_rule_with_get_and_post_compiles_the_methods_and_the_union_of_their_arguments(): void {
		$this->assertTrue( ( new Config() )->save_route_rule( '/rfrac-test/v1/rw', array( 'status' => 'enabled', 'methods' => array( 'GET', 'POST' ) ) )->is_valid() );
		delete_option( Failure_Recorder::OPTION );

		$config = ( new Config_Compiler() )->compile( $this->methods_server() );
		$route  = $this->by_key( $config )['/rfrac-test/v1/rw'];

		$this->assertSame( array( 'GET', 'POST' ), $route->methods );
		$this->assertSame( array( '_embed', '_fields', '_locale', 'a', 'b', 'lang' ), $route->allowed_params );
		$this->assertSame( array( 'GET', 'POST' ), $this->stored_routes()['/rfrac-test/v1/rw']['methods'] );
		$this->assertSame( array( 'GET', 'POST' ), $config->routes()[ $this->route_index( $config, '/rfrac-test/v1/rw' ) ]->methods );
		$this->assertNull( Failure_Recorder::last(), 'Nothing was dropped.' );
	}

	public function test_a_post_only_rule_compiles_only_the_arguments_of_post(): void {
		( new Config() )->save_route_rule( '/rfrac-test/v1/rw', array( 'status' => 'enabled', 'methods' => array( 'POST' ) ) );

		$route = $this->by_key( ( new Config_Compiler() )->compile( $this->methods_server() ) )['/rfrac-test/v1/rw'];

		$this->assertSame( array( 'POST' ), $route->methods );
		$this->assertSame( array( '_embed', '_fields', '_locale', 'b', 'lang' ), $route->allowed_params );
	}

	public function test_a_post_rule_on_a_write_only_route_is_compiled(): void {
		( new Config() )->save_route_rule( '/rfrac-test/v1/write-only', array( 'status' => 'enabled', 'methods' => array( 'POST' ) ) );

		$route = $this->by_key( ( new Config_Compiler() )->compile( $this->methods_server() ) )['/rfrac-test/v1/write-only'];

		$this->assertSame( array( 'POST' ), $route->methods );
		$this->assertContains( 'body', $route->allowed_params );
	}

	public function test_a_method_the_route_does_not_register_is_dropped_and_recorded(): void {
		( new Config() )->save_route_rule( '/rfrac-test/v1/rw', array( 'status' => 'enabled', 'methods' => array( 'GET', 'POST', 'PUT' ) ) );
		delete_option( Failure_Recorder::OPTION );

		$route = $this->by_key( ( new Config_Compiler() )->compile( $this->methods_server() ) )['/rfrac-test/v1/rw'];

		$this->assertSame( array( 'GET', 'POST' ), $route->methods );
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'route_rules', $last['context'] );
		$this->assertStringContainsString( '/rfrac-test/v1/rw', $last['message'] );
		$this->assertStringContainsString( 'PUT', $last['message'] );
		$this->assertStringNotContainsString( 'more)', $last['message'], 'Exactly one problem.' );
	}

	public function test_a_route_with_no_method_left_is_a_negative_entry(): void {
		( new Config() )->save_route_rule( '/rfrac-test/v1/read-only', array( 'status' => 'enabled', 'methods' => array( 'PUT' ) ) );
		delete_option( Failure_Recorder::OPTION );

		$config = ( new Config_Compiler() )->compile( $this->methods_server() );

		$this->assertArrayNotHasKey( '/rfrac-test/v1/read-only', $this->by_key( $config ) );
		$this->assertSame( array( 'key', 'regex', 'enabled' ), array_keys( $this->stored_routes()['/rfrac-test/v1/read-only'] ) );
		$this->assertTrue( $this->is_route_not_enabled( $config, '/rfrac-test/v1/read-only' ) );
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertStringContainsString( '/rfrac-test/v1/read-only', $last['message'] );
		$this->assertStringContainsString( 'PUT', $last['message'] );
	}

	public function test_a_core_route_is_never_compiled_for_a_method_other_than_get(): void {
		$rules = array(
			'/wp/v2/posts' => array( 'status' => 'enabled', 'methods' => array( 'GET', 'POST' ) ),
			'/wp/v2/pages' => array( 'status' => 'enabled', 'methods' => array( 'POST' ) ),
		);
		( new Config() )->save_route_rules( $rules );
		delete_option( Failure_Recorder::OPTION );

		$config = ( new Config_Compiler() )->compile( $this->methods_server() );
		$routes = $this->by_key( $config );

		$this->assertSame( array( 'GET' ), $routes['/wp/v2/posts']->methods, 'POST is dropped, GET stays.' );
		$this->assertArrayNotHasKey( 'methods', $this->stored_routes()['/wp/v2/posts'] );
		$this->assertArrayNotHasKey( '/wp/v2/pages', $routes, 'With no method left the route is negative.' );
		$this->assertTrue( $this->is_route_not_enabled( $config, '/wp/v2/pages' ) );
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'route_rules', $last['context'] );
		$this->assertStringContainsString( '/wp/v2/', $last['message'] );
		$this->assertStringContainsString( 'POST', $last['message'] );
		$this->assertStringContainsString( '(and 1 more)', $last['message'], 'One problem per dropped method.' );
	}

	public function test_a_route_under_the_core_prefix_in_another_namespace_is_never_compiled_for_post(): void {
		$server = $this->new_rest_server(
			static function () {
				register_rest_route(
					'wp/v2',
					'/rfrac-forms',
					array(
						'methods'             => 'POST',
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
					)
				);
			}
		);
		( new Config() )->save_route_rule( '/wp/v2/rfrac-forms', array( 'status' => 'enabled', 'methods' => array( 'POST' ) ) );

		$config = ( new Config_Compiler() )->compile( $server );

		$this->assertArrayNotHasKey( '/wp/v2/rfrac-forms', $this->by_key( $config ) );
		$this->assertTrue( $this->is_route_not_enabled( $config, '/wp/v2/rfrac-forms' ) );
	}

	public function test_a_wildcard_rule_compiles_for_get_only(): void {
		( new Config() )->save_route_rule( '/rfrac-test/v1/*', array( 'status' => 'enabled', 'match' => 'wildcard' ) );

		$route = $this->by_key( ( new Config_Compiler() )->compile( $this->methods_server() ) )['/rfrac-test/v1/*'];

		$this->assertSame( array( 'GET' ), $route->methods );
		$this->assertArrayNotHasKey( 'methods', $this->stored_routes()['/rfrac-test/v1/*'] );
	}

	public function test_an_invalid_method_rule_from_the_filter_fails_closed(): void {
		add_filter(
			Hooks::ROUTE_RULES,
			static fn( array $rules ): array => array_merge( $rules, array( '/rfrac-test/v1/rw' => array( 'status' => 'enabled', 'methods' => array( 'DELETE' ) ) ) )
		);
		delete_option( Failure_Recorder::OPTION );

		$config = ( new Config_Compiler() )->compile( $this->methods_server() );

		$this->assertArrayNotHasKey( '/rfrac-test/v1/rw', $this->by_key( $config ) );
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertStringStartsWith( 'Invalid route rule ignored', $last['message'] );
	}

	public function test_get_only_rules_leave_the_snapshot_and_the_fingerprint_as_they_were(): void {
		$config = new Config();
		$config->save_route_rule( '/rfrac-test/v1/read-only', array( 'status' => 'enabled', 'methods' => array( 'GET' ) ) );
		$config->save_route_rule( '/rfrac-test/v1/rw', array( 'status' => 'enabled' ) );
		$compiler = new Config_Compiler();
		$server   = $this->methods_server();
		delete_option( Failure_Recorder::OPTION );

		$first       = $compiler->compile( $server );
		$stored      = get_option( Names::OPTION_CONFIG );
		$fingerprint = $first->fingerprint();
		$second      = $compiler->compile( $server );

		$this->assertSame( 2, Compiled_Config::FORMAT );
		$this->assertSame( Compiled_Config::FORMAT, $stored['format'] );
		foreach ( $stored['routes'] as $route ) {
			$this->assertArrayNotHasKey( 'methods', $route, $route['key'] );
		}
		foreach ( $second->routes() as $route ) {
			$this->assertSame( array( 'GET' ), $route->methods, $route->key );
		}
		$this->assertSame( $fingerprint, $second->fingerprint() );
		$this->assertSame( $fingerprint, $compiler->fingerprint( $server ) );
		$this->assertTrue( $this->is_route_not_enabled( $second, '/rfrac-test/v1/write-only' ), 'A write-only route without a rule is a negative entry.' );
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_opting_in_to_a_method_changes_the_fingerprint_and_removing_it_restores_it(): void {
		$config   = new Config();
		$compiler = new Config_Compiler();
		$server   = $this->methods_server();
		$config->save_route_rule( '/rfrac-test/v1/rw', array( 'status' => 'enabled' ) );
		$plain = $compiler->fingerprint( $server );

		$config->save_route_rule( '/rfrac-test/v1/rw', array( 'status' => 'enabled', 'methods' => array( 'GET', 'POST' ) ) );
		$this->assertNotSame( $plain, $compiler->fingerprint( $server ) );

		$config->save_route_rule( '/rfrac-test/v1/rw', array( 'status' => 'enabled', 'methods' => array( 'GET' ) ) );
		$this->assertSame( $plain, $compiler->fingerprint( $server ) );
	}

	/**
	 * Position of a route in the compiled list.
	 *
	 * @param Compiled_Config $config Config.
	 * @param string          $key    Route key.
	 *
	 * @return int
	 */
	private function route_index( Compiled_Config $config, string $key ): int {
		foreach ( $config->routes() as $index => $route ) {
			if ( $route->key === $key ) {
				return $index;
			}
		}
		$this->fail( 'Route not compiled: ' . $key );
	}

	/**
	 * Make `home_url()` answer with the given URL.
	 *
	 * @param string $url Home URL.
	 */
	private function home_is( string $url ): void {
		remove_all_filters( 'option_home' );
		add_filter( 'option_home', static fn() => $url );
	}

	/**
	 * A compiler whose site flush is counted instead of reaching the invalidator.
	 *
	 * @param int $flushes Receives the number of site flushes.
	 *
	 * @return Config_Compiler
	 */
	private function counting_compiler( int &$flushes ): Config_Compiler {
		return new Config_Compiler(
			null,
			static function () use ( &$flushes ): void {
				++$flushes;
			}
		);
	}

	public function test_the_home_host_is_compiled_from_home_url(): void {
		$compiler = new Config_Compiler();
		$expected = array(
			'http://example.org'              => 'example.org',
			'https://Shop.Example:8443/sub'   => 'shop.example:8443',
			'https://example.org:443'         => 'example.org',
			'http://example.org:80/'          => 'example.org',
			'http://example.org:8080'         => 'example.org:8080',
			'http://example.org:443'          => 'example.org:443',
			'https://example.org:80'          => 'example.org:80',
			'https://[::1]:8443'              => '[::1]:8443',
		);

		foreach ( $expected as $url => $host ) {
			$this->home_is( $url );
			$config = $compiler->compile( $this->server() );

			$this->assertSame( $host, $config->home_host(), $url );
			$this->assertSame( $host, Compiled_Config::load()->home_host(), $url );
			$this->assertSame( $host, get_option( Names::OPTION_CONFIG )['home_host'], $url );
		}
	}

	public function test_a_url_without_a_parsable_host_yields_no_home_host(): void {
		$method = new ReflectionMethod( Config_Compiler::class, 'home_host' );

		foreach ( array( '', '/just/a/path', 'not a url', 'http:///' ) as $url ) {
			$this->assertSame( '', $method->invoke( null, $url ), $url );
		}
		$this->assertSame( 'example.org', $method->invoke( null, 'HTTP://Example.ORG/' ) );
	}

	public function test_the_home_host_is_part_of_the_fingerprint(): void {
		$compiler = new Config_Compiler();
		$server   = $this->server();

		$this->home_is( 'http://example.org' );
		$first = $compiler->fingerprint( $server );
		$this->assertSame( $first, $compiler->fingerprint( $server ) );

		$this->home_is( 'http://other.example' );
		$this->assertNotSame( $first, $compiler->fingerprint( $server ) );
	}

	public function test_the_periodic_check_keeps_the_stored_home_host_when_home_follows_the_request_host(): void {
		$flushes  = 0;
		$compiler = $this->counting_compiler( $flushes );
		$server   = $this->server();
		$this->home_is( 'http://example.org' );
		$compiler->compile( $server );
		$stored = get_option( Names::OPTION_CONFIG );
		$this->assertSame( 'example.org', $stored['home_host'] );

		$before = $_SERVER['HTTP_HOST'] ?? null;
		try {
			$_SERVER['HTTP_HOST'] = 'evil.example';
			remove_all_filters( 'option_home' );
			add_filter(
				'option_home',
				static fn() => 'http://' . $_SERVER['HTTP_HOST']
			);
			$this->assertSame( 'http://evil.example', home_url(), 'The home URL now follows the request host.' );

			delete_transient( Config_Compiler::CHECK_TRANSIENT );
			$compiler->maybe_recompile_for_server( $server, true );
		} finally {
			if ( null === $before ) {
				unset( $_SERVER['HTTP_HOST'] );
			} else {
				$_SERVER['HTTP_HOST'] = $before;
			}
		}

		$this->assertSame( 'example.org', Compiled_Config::load()->home_host() );
		$this->assertSame( 0, $flushes );
		delete_transient( Config_Compiler::CHECK_TRANSIENT );
	}

	public function test_the_periodic_recompile_keeps_the_stored_home_host_while_saving_other_changes(): void {
		$flushes  = 0;
		$compiler = $this->counting_compiler( $flushes );
		$server   = $this->server();
		$this->home_is( 'http://example.org' );
		$compiler->compile( $server );
		$fingerprint = Compiled_Config::load()->fingerprint();

		$this->home_is( 'http://evil.example' );
		add_filter( Hooks::ALLOW_SKIP_CACHE, '__return_true' );
		delete_transient( Config_Compiler::CHECK_TRANSIENT );

		$this->assertTrue( $compiler->maybe_recompile_for_server( $server, true ), 'A real change is still picked up.' );

		$config = Compiled_Config::load();
		$this->assertTrue( $config->allow_skip_cache() );
		$this->assertSame( 'example.org', $config->home_host() );
		$this->assertNotSame( $fingerprint, $config->fingerprint() );
		$this->assertSame( 0, $flushes );

		// The kept host leaves the sealed fingerprint consistent: the next periodic check finds nothing to do.
		delete_transient( Config_Compiler::CHECK_TRANSIENT );
		$this->assertFalse( $compiler->maybe_recompile_for_server( $server, true ) );
		delete_transient( Config_Compiler::CHECK_TRANSIENT );
	}

	public function test_a_stale_rebuild_by_an_anonymous_request_keeps_the_stored_home_host_when_home_follows_the_request_host(): void {
		$flushes  = 0;
		$compiler = $this->counting_compiler( $flushes );
		$server   = $this->server();
		$this->set_compiled_config( array( 'home_host' => 'real.example', 'fingerprint' => '' ) );
		wp_set_current_user( 0 );

		$before = $_SERVER['HTTP_HOST'] ?? null;
		try {
			$_SERVER['HTTP_HOST'] = 'evil.example';
			remove_all_filters( 'option_home' );
			add_filter(
				'option_home',
				static fn() => 'http://' . $_SERVER['HTTP_HOST']
			);
			$this->assertSame( 'http://evil.example', home_url(), 'The home URL now follows the request host.' );

			$this->assertTrue( $compiler->maybe_recompile_for_server( $server ) );
		} finally {
			if ( null === $before ) {
				unset( $_SERVER['HTTP_HOST'] );
			} else {
				$_SERVER['HTTP_HOST'] = $before;
			}
		}

		$config = Compiled_Config::load();
		$this->assertSame( 'real.example', $config->home_host() );
		$this->assertNotSame( '', $config->fingerprint(), 'The snapshot is rebuilt all the same.' );
		$this->assertSame( 0, $flushes );
	}

	public function test_a_stale_rebuild_for_a_user_who_cannot_manage_options_keeps_the_stored_home_host(): void {
		$flushes  = 0;
		$compiler = $this->counting_compiler( $flushes );
		$server   = $this->server();
		$this->set_compiled_config( array( 'home_host' => 'real.example', 'fingerprint' => '' ) );
		$this->home_is( 'http://evil.example' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertTrue( $compiler->maybe_recompile_for_server( $server ) );

		$this->assertSame( 'real.example', Compiled_Config::load()->home_host() );
		$this->assertSame( 0, $flushes );
	}

	public function test_a_stale_rebuild_for_an_administrator_derives_the_home_host_fresh_and_flushes_the_site(): void {
		$flushes  = 0;
		$compiler = $this->counting_compiler( $flushes );
		$server   = $this->server();
		$this->set_compiled_config( array( 'home_host' => 'old.example', 'fingerprint' => '' ) );
		$this->home_is( 'http://new.example' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertTrue( $compiler->maybe_recompile_for_server( $server ) );

		$this->assertSame( 'new.example', Compiled_Config::load()->home_host() );
		$this->assertSame( 1, $flushes );
	}

	public function test_a_missing_snapshot_derives_the_home_host_without_a_flush(): void {
		$flushes  = 0;
		$compiler = $this->counting_compiler( $flushes );
		$this->home_is( 'http://new.example' );

		$this->assertTrue( $compiler->maybe_recompile_for_server( $this->server() ) );

		$this->assertSame( 'new.example', Compiled_Config::load()->home_host() );
		$this->assertSame( 0, $flushes );
	}

	public function test_a_snapshot_without_home_host_gets_one_without_a_flush(): void {
		$flushes  = 0;
		$compiler = $this->counting_compiler( $flushes );
		$server   = $this->server();
		$this->home_is( 'http://example.org' );
		$legacy = Compiled_Config::defaults();
		unset( $legacy['home_host'] );
		$legacy['fingerprint'] = 'v4';

		update_option( Names::OPTION_CONFIG, $legacy, true );
		$compiler->compile( $server );
		$this->assertSame( 'example.org', Compiled_Config::load()->home_host() );
		$this->assertSame( 0, $flushes, 'A compile of a snapshot that carried no host flushes nothing.' );

		update_option( Names::OPTION_CONFIG, $legacy, true );
		delete_transient( Config_Compiler::CHECK_TRANSIENT );
		$this->assertTrue( $compiler->maybe_recompile_for_server( $server, true ) );
		$this->assertSame( 'example.org', Compiled_Config::load()->home_host() );
		$this->assertSame( 0, $flushes, 'The periodic path derives the host fresh when none is stored.' );
		delete_transient( Config_Compiler::CHECK_TRANSIENT );
	}

	public function test_an_unchanged_home_host_never_flushes_the_site(): void {
		$flushes  = 0;
		$compiler = $this->counting_compiler( $flushes );
		$server   = $this->server();
		$this->home_is( 'http://example.org' );

		$compiler->compile( $server );
		$compiler->compile( $server );
		add_filter( Hooks::ALLOW_SKIP_CACHE, '__return_true' );
		$compiler->compile( $server );

		$this->assertSame( 0, $flushes );
	}

	public function test_a_compile_with_a_changed_home_host_flushes_the_site_once(): void {
		$flushes  = 0;
		$compiler = $this->counting_compiler( $flushes );
		$server   = $this->server();

		$this->home_is( 'http://example.org' );
		$compiler->compile( $server );
		$this->home_is( 'https://example.org' );
		$compiler->compile( $server );
		$this->assertSame( 0, $flushes, 'The scheme is not part of the host.' );

		$this->home_is( 'https://www.example.org' );
		$compiler->compile( $server );
		$this->assertSame( 1, $flushes );
		$this->assertSame( 'www.example.org', Compiled_Config::load()->home_host() );
	}

	public function test_the_default_flush_on_a_home_host_change_is_a_no_op_while_the_api_is_not_registered(): void {
		$registered = Api::get();
		Api::reset();
		$compiler = new Config_Compiler();
		$server   = $this->server();

		try {
			$this->home_is( 'http://example.org' );
			$compiler->compile( $server );
			$this->home_is( 'http://other.example' );
			$compiler->compile( $server );
		} finally {
			if ( null !== $registered ) {
				Api::register( $registered );
			}
		}

		$this->assertSame( 'other.example', Compiled_Config::load()->home_host() );
	}
}
