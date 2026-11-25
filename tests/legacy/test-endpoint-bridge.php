<?php
/**
 * Tests for the bridge of the legacy endpoint filters onto the route rules.
 *
 * The site is compiled for real from a REST server (`Config_Compiler::compile()`) with the bridge of a fresh
 * container wired; the booted container's legacy hooks are detached for every test, so its per-request memo never
 * carries a result from one test into another. Every test with a `$path` argument runs for the early server and for
 * the fallback server.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Compiled_Route;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Config\Effective_Rule;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Legacy\Endpoint_Bridge;
use Rockfire\Rest_Api_Cache\Legacy\Finding;
use Rockfire\Rest_Api_Cache\Legacy\Findings;
use Rockfire\Rest_Api_Cache\Legacy\Translation_Note;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Endpoint_Bridge
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Module
 */
class Test_Endpoint_Bridge extends Rfrac_E2e_Test_Case {

	private const ITEMS = '/tst/v1/items';
	private const ITEM  = '/tst/v1/items/(?P<id>\d+)';
	private const POSTS = '/wp/v2/posts';
	private const POST  = '/wp/v2/posts/(?P<id>[\d]+)';
	private const USERS = '/wp/v2/users';
	private const USER  = '/wp/v2/users/(?P<id>[\d]+)';
	private const PAGES = '/wp/v2/pages';
	private const PAGE  = '/wp/v2/pages/(?P<id>[\d]+)';

	/**
	 * The arguments of every `deprecated_hook_run` of the test.
	 *
	 * @var list<array{hook:string,replacement:string}>
	 */
	private array $deprecated = array();

	/**
	 * Containers wired by the test, detached again in tear_down().
	 *
	 * @var list<Plugin>
	 */
	private array $wired = array();

	public function set_up() {
		parent::set_up();
		// A settings save must not compile on its own: every test compiles the site itself.
		remove_all_actions( 'add_option_' . Names::OPTION_SETTINGS );
		remove_all_actions( 'update_option_' . Names::OPTION_SETTINGS );
		Coexistence_Guard::reset();
		$this->unwire( Plugin::instance() );
		delete_option( Names::OPTION_LEGACY_FINDINGS );

		$this->deprecated = array();
		$this->wired      = array();
		add_action(
			'deprecated_hook_run',
			function ( $hook, $replacement ): void {
				$this->deprecated[] = array(
					'hook'        => (string) $hook,
					'replacement' => (string) $replacement,
				);
			},
			10,
			2
		);
	}

	public function tear_down() {
		foreach ( $this->wired as $plugin ) {
			$this->unwire( $plugin );
		}
		delete_option( Names::OPTION_LEGACY_FINDINGS );
		Coexistence_Guard::reset();
		parent::tear_down();
	}

	/**
	 * A callback that throws.
	 *
	 * @return never
	 *
	 * @throws RuntimeException Always.
	 */
	public static function throwing(): never {
		throw new RuntimeException( 'legacy callback failed' );
	}

	/**
	 * Register the test routes: `/tst/v1/items` and `/tst/v1/items/(?P<id>\d+)`.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'tst/v1',
			'/items',
			array(
				'methods'             => 'GET',
				'callback'            => static fn() => array( array( 'id' => 1 ) ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'tst/v1',
			'/items/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => static fn( WP_REST_Request $request ) => array( 'id' => (int) $request['id'] ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Wire the legacy module of a fresh container, as the booted plugin does.
	 *
	 * @return Plugin
	 */
	private function wire(): Plugin {
		$plugin = new Plugin();
		$plugin->register_legacy_hooks();
		$this->assertNotNull( $plugin->legacy(), 'The legacy module is wired.' );
		$this->wired[] = $plugin;
		return $plugin;
	}

	/**
	 * Remove the legacy hooks of a container.
	 *
	 * @param Plugin $plugin Container.
	 *
	 * @return void
	 */
	private function unwire( Plugin $plugin ): void {
		foreach ( $plugin->legacy_hooks() as $hook ) {
			remove_filter( $hook['hook'], $hook['callback'], $hook['priority'] );
		}
	}

	/**
	 * A REST server with the core and the test routes.
	 *
	 * @return WP_REST_Server
	 */
	private function server(): WP_REST_Server {
		return $this->new_rest_server( array( $this, 'register_routes' ) );
	}

	/**
	 * Compile the site from a REST server with the test routes, with or without the legacy module wired.
	 *
	 * @param bool $wired Whether the legacy module of a fresh container is wired while the site compiles.
	 *
	 * @return Compiled_Config
	 */
	private function compile_site( bool $wired = true ): Compiled_Config {
		$server = $this->server();
		$plugin = $wired ? $this->wire() : null;
		try {
			return ( new Config_Compiler() )->compile( $server );
		} finally {
			if ( null !== $plugin ) {
				$this->unwire( $plugin );
			}
		}
	}

	/**
	 * The effective route rules, built with the legacy module of a fresh container wired.
	 *
	 * @return array<string,Effective_Rule>
	 */
	private function effective_rules(): array {
		$server = $this->server();
		$plugin = $this->wire();
		try {
			return ( new Config() )->route_rules( $server );
		} finally {
			$this->unwire( $plugin );
		}
	}

	/**
	 * The compiled route of a key.
	 *
	 * @param Compiled_Config $config Compiled configuration.
	 * @param string          $key    Route key.
	 *
	 * @return Compiled_Route
	 */
	private function compiled( Compiled_Config $config, string $key ): Compiled_Route {
		foreach ( $config->routes() as $route ) {
			if ( $key === $route->key ) {
				return $route;
			}
		}
		$this->fail( 'The route ' . $key . ' is not compiled.' );
	}

	/**
	 * The findings of the endpoints group.
	 *
	 * @return list<Finding>
	 */
	private function endpoint_findings(): array {
		return ( new Findings() )->get( Findings::GROUP_ENDPOINTS )['findings'] ?? array();
	}

	/**
	 * The one finding of the endpoints group with a status and a subject.
	 *
	 * @param string $status  Finding status.
	 * @param string $subject Finding subject.
	 *
	 * @return Finding
	 */
	private function one_finding( string $status, string $subject ): Finding {
		$found = array_values(
			array_filter(
				$this->endpoint_findings(),
				static fn( Finding $finding ): bool => $status === $finding->status && $subject === $finding->subject
			)
		);
		$this->assertCount( 1, $found, 'One ' . $status . ' finding for ' . $subject . '.' );
		return $found[0];
	}

	/**
	 * Record the rules `rfrac/route_rules` hands on before (priority 0) and after (priority 2) the bridge.
	 *
	 * @param array<string,mixed>|null $before Receives the rules before the bridge.
	 * @param array<string,mixed>|null $after  Receives the rules after the bridge.
	 *
	 * @return void
	 */
	private function around_the_bridge( ?array &$before, ?array &$after ): void {
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( $rules ) use ( &$before ) {
				$before = $rules;
				return $rules;
			},
			0
		);
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( $rules ) use ( &$after ) {
				$after = $rules;
				return $rules;
			},
			2
		);
	}

	// ---------- Wiring ----------

	public function test_the_module_subscribes_the_bridge_to_its_three_hooks(): void {
		$this->assertSame(
			array(
				array( Hooks::ROUTE_RULES, 'route_rules', 1, 3 ),
				array( Hooks::GLOBAL_IGNORE_PARAMS, 'uncached_parameters', 1, 1 ),
				array( Hooks::COMPILED, 'record_report', 10, 1 ),
			),
			Endpoint_Bridge::hooks()
		);

		$plugin = $this->wire();

		$hooks = array();
		foreach ( $plugin->legacy_hooks() as $hook ) {
			$hooks[ $hook['hook'] ] = array( $hook['priority'], $hook['args'] );
		}
		$this->assertSame( array( 1, 3 ), $hooks[ Hooks::ROUTE_RULES ] ?? null, 'Priority 1, so the site callbacks of rfrac/route_rules override it.' );
		$this->assertSame( array( 1, 1 ), $hooks[ Hooks::GLOBAL_IGNORE_PARAMS ] ?? null );
		$this->assertSame( array( 10, 1 ), $hooks[ Hooks::COMPILED ] ?? null );
		$this->assertSame( array(), $plugin->engine_hooks(), 'The bridge is a legacy hook, never an engine hook.' );
	}

	// ---------- Allowed endpoints ----------

	/**
	 * AC-1.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_an_endpoint_a_legacy_callback_allows_is_cached_on_both_paths( string $path ): void {
		$this->setExpectedDeprecated( Endpoint_Bridge::ALLOWED );
		add_filter( Endpoint_Bridge::ALLOWED, static fn( $endpoints ) => $endpoints + array( 'tst/v1' => array( 'items' ) ) );

		$config = $this->compile_site();

		$this->assertTrue( $this->compiled( $config, self::ITEMS )->enabled, 'The collection compiles enabled.' );
		$this->assertTrue( $this->compiled( $config, self::ITEM )->enabled, 'The item route compiles enabled.' );

		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/tst/v1/items/5' );
		$body = $h->miss( '/tst/v1/items/5', array( $this, 'register_routes' ) );
		$this->assertNotNull( $body, 'The first request is a miss.' );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );

		$h->request( '/wp-json/tst/v1/items/5' );
		$this->assertTrue( $h->lookup(), 'The second request is a hit.' );
		$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( $body, $h->output );
		$this->assertNull( ( new Findings() )->get( Findings::GROUP_ENDPOINTS ), 'Everything was translated: no finding.' );
	}

	/**
	 * AC-2.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_an_endpoint_a_legacy_callback_removes_is_not_cached_on_both_paths( string $path ): void {
		$this->setExpectedDeprecated( Endpoint_Bridge::ALLOWED );
		$seen = null;
		add_filter(
			Endpoint_Bridge::ALLOWED,
			static function ( $endpoints ) use ( &$seen ) {
				$seen                = $endpoints;
				$endpoints['wp/v2'] = array_values( array_diff( $endpoints['wp/v2'], array( 'posts' ) ) );
				return $endpoints;
			}
		);
		$post_id = self::factory()->post->create();

		$config = $this->compile_site();

		$this->assertContains( 'posts', $seen['wp/v2'] ?? array(), 'The callback sees the routes cached by default as allowed endpoints.' );
		$this->assertFalse( $this->compiled( $config, self::POSTS )->enabled );
		$this->assertFalse( $this->compiled( $config, self::POST )->enabled );
		$this->assertTrue( $this->compiled( $config, self::PAGES )->enabled, 'The other endpoints stay cached.' );

		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/wp/v2/posts' );
		$this->assertFalse( $h->lookup() );
		$this->assertSame( Bypass_Reason::ROUTE_NOT_ENABLED, Request_State::decision()->reason );

		$h->request( '/wp-json/wp/v2/posts/' . $post_id );
		$this->assertFalse( $h->lookup() );
		$this->assertSame( Bypass_Reason::ROUTE_NOT_ENABLED, Request_State::decision()->reason );
	}

	public function test_a_site_callback_of_rfrac_route_rules_overrides_the_bridge(): void {
		$this->setExpectedDeprecated( Endpoint_Bridge::ALLOWED );
		add_filter( Endpoint_Bridge::ALLOWED, static fn( $endpoints ) => $endpoints + array( 'tst/v1' => array( 'items' ) ) );
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( $rules ) {
				$rules[ self::ITEMS ] = array( 'status' => 'excluded' );
				return $rules;
			}
		);

		$rules = $this->effective_rules();

		$this->assertSame( 'excluded', $rules[ self::ITEMS ]->rule->status );
		$this->assertSame( 'enabled', $rules[ self::ITEM ]->rule->status );
	}

	// ---------- Disallowed endpoints ----------

	/**
	 * AC-3.
	 */
	public function test_a_disallowed_endpoint_excludes_its_routes(): void {
		$this->setExpectedDeprecated( Endpoint_Bridge::DISALLOWED );
		add_filter( Endpoint_Bridge::DISALLOWED, static fn( $endpoints ) => array_merge( $endpoints, array( 'wp/v2' => array( 'users' ) ) ) );

		$rules = $this->effective_rules();

		$this->assertSame( 'excluded', $rules[ self::USERS ]->rule->status );
		$this->assertSame( 'excluded', $rules[ self::USER ]->rule->status );
		$this->assertSame( Effective_Rule::SOURCE_FILTER, $rules[ self::USERS ]->source );
		$this->assertSame( 'enabled', $rules[ self::POSTS ]->rule->status, 'The other endpoints stay cached.' );

		$config = $this->compile_site();
		$this->assertFalse( $this->compiled( $config, self::USERS )->enabled );
		$this->assertFalse( $this->compiled( $config, self::USER )->enabled );
	}

	public function test_a_disallowed_endpoint_wins_over_an_endpoint_a_legacy_callback_allows(): void {
		$this->setExpectedDeprecated( Endpoint_Bridge::ALLOWED );
		$this->setExpectedDeprecated( Endpoint_Bridge::DISALLOWED );
		add_filter( Endpoint_Bridge::ALLOWED, static fn( $endpoints ) => $endpoints + array( 'tst/v1' => array( 'items' ) ) );
		add_filter( Endpoint_Bridge::DISALLOWED, static fn( $endpoints ) => $endpoints + array( 'tst/v1' => array( 'items' ) ) );

		$rules = $this->effective_rules();

		$this->assertArrayNotHasKey( self::ITEMS, $rules );
		$this->assertArrayNotHasKey( self::ITEM, $rules );
	}

	// ---------- Rockfire wins ----------

	/**
	 * AC-4, a stored rule.
	 */
	public function test_a_stored_rule_is_never_changed_and_is_reported_as_kept(): void {
		$this->setExpectedDeprecated( Endpoint_Bridge::ALLOWED );
		$this->assertTrue(
			( new Config() )->save_route_rules(
				array(
					self::POSTS => array(
						'status' => 'enabled',
						'ttl'    => 120,
					),
				)
			)->is_valid()
		);
		add_filter(
			Endpoint_Bridge::ALLOWED,
			static function ( $endpoints ) {
				$endpoints['wp/v2'] = array_values( array_diff( $endpoints['wp/v2'], array( 'posts' ) ) );
				return $endpoints;
			}
		);

		$config = $this->compile_site();

		$rules = $this->effective_rules();
		$this->assertSame( Effective_Rule::SOURCE_SETTINGS, $rules[ self::POSTS ]->source, 'The stored rule is unchanged.' );
		$this->assertSame( 'enabled', $rules[ self::POSTS ]->rule->status );
		$this->assertSame( 120, $rules[ self::POSTS ]->rule->ttl );
		$this->assertTrue( $this->compiled( $config, self::POSTS )->enabled );
		$this->assertSame( 'excluded', $rules[ self::POST ]->rule->status, 'A route the stored rule does not cover follows the legacy filter.' );

		$kept = $this->one_finding( Finding::KEPT, 'wp/v2/posts' );
		$this->assertSame( Translation_Note::EXCLUDED, $kept->code );
		$this->assertStringContainsString( self::POSTS, $kept->message );
		$this->assertStringContainsString( 'set in this plugin', $kept->message );
		$this->assertFalse( $kept->is_problem() );
	}

	/**
	 * The legacy endpoint filters.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function endpoint_filters(): array {
		return array(
			'allowed'         => array( Endpoint_Bridge::ALLOWED ),
			'disallowed'      => array( Endpoint_Bridge::DISALLOWED ),
			'request headers' => array( Endpoint_Bridge::HEADERS ),
		);
	}

	/**
	 * A route a stored rule excludes is read back as its whole rest base; that must not exclude the routes beside it.
	 *
	 * @dataProvider endpoint_filters
	 *
	 * @param string $filter A legacy endpoint filter that returns its input.
	 */
	public function test_a_stored_exclusion_of_one_route_leaves_the_routes_beside_it_alone( string $filter ): void {
		$this->setExpectedDeprecated( $filter );
		$this->assertTrue( ( new Config() )->save_route_rules( array( self::POST => array( 'status' => 'excluded' ) ) )->is_valid() );
		add_filter( $filter, static fn( $input ) => $input );

		$rules = $this->effective_rules();

		$this->assertSame( Effective_Rule::SOURCE_SETTINGS, $rules[ self::POST ]->source );
		$this->assertSame( 'excluded', $rules[ self::POST ]->rule->status );
		$this->assertSame( 'enabled', $rules[ self::POSTS ]->rule->status, 'The collection keeps its default rule.' );

		$siblings = 0;
		foreach ( $rules as $key => $effective ) {
			$key = (string) $key;
			if ( self::POST === $key || ! str_starts_with( $key, self::POSTS ) ) {
				continue;
			}
			++$siblings;
			$this->assertSame( Effective_Rule::SOURCE_DEFAULT, $effective->source, $key . ' keeps its default rule.' );
			$this->assertSame( 'enabled', $effective->rule->status, $key . ' stays cached.' );
		}
		$this->assertGreaterThan( 1, $siblings, 'The collection and at least one more route sit beside the excluded one.' );
		$this->assertSame( array(), $this->endpoint_findings(), 'Nothing the callbacks changed, nothing to report.' );
	}

	public function test_a_disallowed_endpoint_still_excludes_its_routes_beside_a_stored_exclusion(): void {
		$this->setExpectedDeprecated( Endpoint_Bridge::DISALLOWED );
		$this->assertTrue( ( new Config() )->save_route_rules( array( self::POST => array( 'status' => 'excluded' ) ) )->is_valid() );
		add_filter( Endpoint_Bridge::DISALLOWED, static fn( $endpoints ) => array_merge_recursive( $endpoints, array( 'wp/v2' => array( 'users' ) ) ) );

		$rules = $this->effective_rules();

		$this->assertSame( 'excluded', $rules[ self::USERS ]->rule->status );
		$this->assertSame( 'excluded', $rules[ self::USER ]->rule->status );
		$this->assertSame( Effective_Rule::SOURCE_FILTER, $rules[ self::USERS ]->source );
		$this->assertSame( 'excluded', $rules[ self::POST ]->rule->status );
		$this->assertSame( Effective_Rule::SOURCE_SETTINGS, $rules[ self::POST ]->source );
		$this->assertSame( 'enabled', $rules[ self::POSTS ]->rule->status, 'The collection keeps its default rule.' );
		$this->assertSame( Effective_Rule::SOURCE_DEFAULT, $rules[ self::POSTS ]->source );
	}

	/**
	 * AC-4, a rule of `RFRAC_ROUTES`.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_rule_of_the_routes_constant_is_never_changed_and_is_reported_as_kept(): void {
		define(
			'RFRAC_ROUTES',
			array(
				self::PAGES => array(
					'status' => 'enabled',
					'ttl'    => 120,
				),
			)
		);
		$this->setExpectedDeprecated( Endpoint_Bridge::DISALLOWED );
		add_filter(
			Endpoint_Bridge::DISALLOWED,
			static function ( $endpoints ) {
				$endpoints['wp/v2'][] = 'pages';
				return $endpoints;
			}
		);

		$config = $this->compile_site();

		$rules = $this->effective_rules();
		$this->assertSame( Effective_Rule::SOURCE_CONSTANT, $rules[ self::PAGES ]->source, 'The constant rule is unchanged.' );
		$this->assertSame( 'enabled', $rules[ self::PAGES ]->rule->status );
		$this->assertSame( 120, $rules[ self::PAGES ]->rule->ttl );
		$this->assertTrue( $this->compiled( $config, self::PAGES )->enabled );
		$this->assertSame( 'excluded', $rules[ self::PAGE ]->rule->status, 'A route the constant does not define follows the legacy filter.' );

		$kept = $this->one_finding( Finding::KEPT, 'wp/v2/pages' );
		$this->assertSame( Translation_Note::EXCLUDED, $kept->code );
		$this->assertStringContainsString( self::PAGES, $kept->message );
		$this->assertStringContainsString( 'RFRAC_ROUTES', $kept->message );
	}

	public function test_a_wildcard_rule_is_never_changed(): void {
		$this->setExpectedDeprecated( Endpoint_Bridge::ALLOWED );
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( $rules ) {
				$rules[ self::ITEMS ] = array(
					'status' => 'enabled',
					'match'  => 'wildcard',
				);
				return $rules;
			},
			0
		);
		add_filter( Endpoint_Bridge::ALLOWED, static fn( $endpoints ) => $endpoints + array( 'tst/v1' => array( 'items' ) ) );
		$before = null;
		$after  = null;
		$this->around_the_bridge( $before, $after );

		$this->effective_rules();

		$this->assertSame( $before[ self::ITEMS ] ?? null, $after[ self::ITEMS ] ?? null, 'The wildcard rule under the key of a registered route is left alone.' );
		$this->assertSame( 'registered', $after[ self::ITEM ]['match'] ?? null );
		$this->assertSame( 'enabled', $after[ self::ITEM ]['status'] ?? null );
	}

	// ---------- Request headers ----------

	/**
	 * AC-5.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_cacheable_request_headers_vary_the_routes_without_the_credential_headers( string $path ): void {
		$this->setExpectedDeprecated( Endpoint_Bridge::HEADERS );
		add_filter( Endpoint_Bridge::HEADERS, static fn() => array( 'wp/v2/posts' => 'X-Lang, Authorization' ) );
		self::factory()->post->create_many( 2 );

		$config = $this->compile_site();

		$this->assertSame( array( 'x-lang' ), $this->compiled( $config, self::POSTS )->vary_headers );
		$this->assertTrue( $this->compiled( $config, self::POSTS )->enabled );
		$forbidden = $this->one_finding( Finding::SKIPPED, 'wp/v2/posts' );
		$this->assertSame( Translation_Note::VARY_FORBIDDEN, $forbidden->code );
		$this->assertStringContainsString( 'authorization', $forbidden->message );

		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_X_LANG' => 'en' ) );
		$this->assertNotNull( $h->miss( self::POSTS ), 'The first language is a miss.' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_X_LANG' => 'nl' ) );
		$this->assertNotNull( $h->miss( self::POSTS ), 'Another language is another entry.' );
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count() );

		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_X_LANG' => 'en' ) );
		$this->assertTrue( $h->lookup(), 'A repeat of the first language is a hit.' );
	}

	public function test_request_headers_never_enable_a_route_a_disallowed_endpoint_excludes(): void {
		$this->setExpectedDeprecated( Endpoint_Bridge::DISALLOWED );
		$this->setExpectedDeprecated( Endpoint_Bridge::HEADERS );
		add_filter( Endpoint_Bridge::DISALLOWED, static fn( $endpoints ) => $endpoints + array( 'wp/v2' => array( 'users' ) ) );
		add_filter( Endpoint_Bridge::HEADERS, static fn( $headers ) => $headers + array( 'wp/v2/users' => 'X-Lang' ) );

		$rules = $this->effective_rules();

		$this->assertSame( 'excluded', $rules[ self::USERS ]->rule->status );
		$this->assertSame( array(), $rules[ self::USERS ]->rule->vary_headers );
	}

	// ---------- Once per request ----------

	/**
	 * AC-6.
	 */
	public function test_the_legacy_callbacks_run_once_per_request_for_the_same_rules(): void {
		$this->setExpectedDeprecated( Endpoint_Bridge::ALLOWED );
		$calls = 0;
		add_filter(
			Endpoint_Bridge::ALLOWED,
			static function ( $endpoints ) use ( &$calls ) {
				++$calls;
				return $endpoints;
			}
		);
		$server = $this->server();
		$this->wire();
		$config = new Config();

		$first = $config->route_rules( $server );
		$this->assertEquals( $first, $config->route_rules( $server ), 'A repeat returns the same rules.' );
		$config->route_rules( $server );

		$this->assertSame( 1, $calls, 'The callback runs once.' );
		$runs = array_values( array_filter( $this->deprecated, static fn( array $run ): bool => Endpoint_Bridge::ALLOWED === $run['hook'] ) );
		$this->assertCount( 1, $runs, 'One deprecation notice.' );
		$this->assertSame( 'rfrac/route_rules', $runs[0]['replacement'] );
	}

	public function test_another_legacy_callback_makes_the_callbacks_run_again(): void {
		$this->setExpectedDeprecated( Endpoint_Bridge::ALLOWED );
		$first  = 0;
		$second = 0;
		add_filter(
			Endpoint_Bridge::ALLOWED,
			static function ( $endpoints ) use ( &$first ) {
				++$first;
				return $endpoints;
			}
		);
		$server = $this->server();
		$this->wire();
		( new Config() )->route_rules( $server );

		add_filter(
			Endpoint_Bridge::ALLOWED,
			static function ( $endpoints ) use ( &$second ) {
				++$second;
				return $endpoints;
			},
			20
		);
		( new Config() )->route_rules( $server );

		$this->assertSame( 2, $first );
		$this->assertSame( 1, $second );
	}

	// ---------- No legacy callbacks ----------

	/**
	 * AC-7.
	 */
	public function test_without_legacy_callbacks_the_compiled_configuration_is_byte_identical(): void {
		$this->compile_site( false );
		$without = get_option( Names::OPTION_CONFIG );
		$this->assertIsArray( $without );
		$this->assertNotSame( '', $without['fingerprint'] );

		delete_option( Names::OPTION_CONFIG );
		$this->compile_site();
		$with = get_option( Names::OPTION_CONFIG );

		$this->assertSame( serialize( $without ), serialize( $with ) );
		$this->assertSame( $without['fingerprint'], $with['fingerprint'] );
		$this->assertSame( array(), ( new Findings() )->all(), 'A site without legacy callbacks keeps no finding.' );
		$this->assertSame( array(), $this->deprecated, 'No legacy filter fired.' );
	}

	// ---------- Failures ----------

	/**
	 * AC-8.
	 */
	public function test_a_throwing_allowed_endpoints_callback_leaves_the_rules_unchanged(): void {
		$this->setExpectedDeprecated( Endpoint_Bridge::ALLOWED );
		add_filter( Endpoint_Bridge::ALLOWED, array( self::class, 'throwing' ) );
		$before = null;
		$after  = null;
		$this->around_the_bridge( $before, $after );

		$this->effective_rules();

		$this->assertIsArray( $before );
		$this->assertSame( $before, $after );
		$this->assertSame( 'hook:' . Endpoint_Bridge::ALLOWED, Failure_Recorder::last()['context'] ?? null );
	}

	public function test_a_result_that_is_not_an_array_counts_as_unchanged_and_is_recorded(): void {
		$this->setExpectedDeprecated( Endpoint_Bridge::DISALLOWED );
		add_filter( Endpoint_Bridge::DISALLOWED, static fn() => 'users' );
		$before = null;
		$after  = null;
		$this->around_the_bridge( $before, $after );

		$this->effective_rules();

		$this->assertIsArray( $before );
		$this->assertSame( $before, $after );
		$last = Failure_Recorder::last();
		$this->assertSame( 'hook:' . Endpoint_Bridge::DISALLOWED, $last['context'] ?? null );
		$this->assertStringContainsString( 'string', $last['message'] ?? '' );
	}

	// ---------- Uncached parameters ----------

	/**
	 * AC-9.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_the_uncached_parameters_are_left_out_of_the_cache_key_on_both_paths( string $path ): void {
		$this->setExpectedDeprecated( Endpoint_Bridge::UNCACHED );
		add_filter( Endpoint_Bridge::UNCACHED, static fn() => array( 'utm_source' ) );
		self::factory()->post->create_many( 2 );

		$config = $this->compile_site();
		$this->assertContains( 'utm_source', $this->compiled( $config, self::POSTS )->ignore_params );

		$h = $this->harness( $path )->with_legacy();
		$h->request( '/wp-json/wp/v2/posts' );
		$body = $h->miss( self::POSTS );
		$this->assertNotNull( $body, 'The first request is a miss.' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$h->request( '/wp-json/wp/v2/posts?utm_source=x' );
		$this->assertTrue( $h->lookup(), 'The uncached parameter hits the same entry.' );
		$this->assertSame( $body, $h->output );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
	}

	public function test_uncached_parameters_keeps_its_input_for_a_result_that_is_not_a_list_of_strings(): void {
		$this->setExpectedDeprecated( Endpoint_Bridge::UNCACHED );
		$bridge = new Endpoint_Bridge( new Config(), new Findings() );
		$result = array( 'utm_source', 5 );
		add_filter(
			Endpoint_Bridge::UNCACHED,
			static function () use ( &$result ) {
				return $result;
			}
		);

		$this->assertSame( array( 'fbclid' ), $bridge->uncached_parameters( array( 'fbclid' ) ) );

		$result = 'utm_source';
		$this->assertSame( array( 'fbclid' ), $bridge->uncached_parameters( array( 'fbclid' ) ) );

		$result = array( 'fbclid', 'utm_source' );
		$this->assertSame( array( 'fbclid', 'utm_source' ), $bridge->uncached_parameters( array( 'fbclid' ) ) );
	}

	// ---------- Findings ----------

	/**
	 * AC-10.
	 */
	public function test_an_entry_that_can_not_be_translated_is_a_finding_until_its_callback_is_removed(): void {
		$this->setExpectedDeprecated( Endpoint_Bridge::ALLOWED );
		$callback = static function ( $endpoints ) {
			$endpoints['wp/v2'][] = 'posts/(?P<id>\d+)';
			return $endpoints;
		};
		add_filter( Endpoint_Bridge::ALLOWED, $callback );

		$plugin = $this->wire();
		( new Config_Compiler() )->compile( null );
		$this->assertNull( ( new Findings() )->get( Findings::GROUP_ENDPOINTS ), 'A compile without a server builds no rules and records nothing.' );
		$this->unwire( $plugin );

		$this->compile_site();

		$pattern = $this->one_finding( Finding::SKIPPED, 'wp/v2/posts/(?P<id>\d+)' );
		$this->assertSame( Translation_Note::PATTERN, $pattern->code );
		$this->assertStringContainsString( Endpoint_Bridge::ALLOWED, $pattern->message );
		$this->assertTrue( $pattern->is_problem() );

		remove_filter( Endpoint_Bridge::ALLOWED, $callback );
		$this->compile_site();

		$this->assertSame( array(), $this->endpoint_findings(), 'The finding is gone once the callback is removed.' );
		$this->assertNull( ( new Findings() )->get( Findings::GROUP_ENDPOINTS ), 'No empty group is left behind.' );
	}
}
