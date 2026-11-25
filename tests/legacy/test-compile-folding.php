<?php
/**
 * Tests for the compile folding of the legacy hit filters that do not depend on the request.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Legacy\Compile_Folding;
use Rockfire\Rest_Api_Cache\Legacy\Findings;
use Rockfire\Rest_Api_Cache\Legacy\Module;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * The filters are applied by the legacy module of the booted container, so the compile tests go through its hooks.
 *
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Compile_Folding
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Module
 */
class Test_Compile_Folding extends Rfrac_E2e_Test_Case {

	private const URI = '/wp-json/wp/v2/posts';

	public function set_up() {
		parent::set_up();
		$this->assertInstanceOf( Module::class, Plugin::instance()->legacy(), 'Precondition: the booted container wires the legacy module.' );
		delete_option( Names::OPTION_LEGACY_FINDINGS );
	}

	public function tear_down() {
		delete_option( Names::OPTION_LEGACY_FINDINGS );
		parent::tear_down();
	}

	/**
	 * Compile the configuration from a fresh REST server, as a settings save does.
	 *
	 * @return Compiled_Config
	 */
	private function compile(): Compiled_Config {
		return ( new Config_Compiler() )->compile( $this->new_rest_server() );
	}

	/**
	 * The stored compiled configuration.
	 *
	 * @return Compiled_Config
	 */
	private function compiled(): Compiled_Config {
		$config = Compiled_Config::load();
		$this->assertNotNull( $config );
		return $config;
	}

	/**
	 * Every hook that fires while a callback runs.
	 *
	 * @param Closure $run Callback.
	 *
	 * @return list<string>
	 */
	private function hooks_during( Closure $run ): array {
		$hooks = array();
		$spy   = static function () use ( &$hooks ): void {
			$hooks[] = current_filter();
		};
		add_filter( 'all', $spy );
		try {
			$run();
		} finally {
			remove_filter( 'all', $spy );
		}
		return $hooks;
	}

	// ---------- The component ----------

	public function test_it_declares_the_compile_filters(): void {
		$this->assertSame(
			array(
				array( Hooks::ALLOW_SKIP_CACHE, 'allow_skip_cache', 10, 1 ),
				array( Hooks::CORS_ON_HITS, 'cors', 10, 1 ),
				array( Hooks::BYPASS_ALL, 'bypass_all', 10, 1 ),
				array( Hooks::NONCE_CACHING, 'nonce_caching', 10, 2 ),
			),
			Compile_Folding::hooks()
		);
	}

	public function test_the_module_subscribes_it_to_the_compile_filters(): void {
		$hooks = array_column( Plugin::instance()->legacy_hooks(), 'hook' );

		$this->assertContains( Hooks::ALLOW_SKIP_CACHE, $hooks );
		$this->assertContains( Hooks::CORS_ON_HITS, $hooks );
		$this->assertContains( Hooks::BYPASS_ALL, $hooks );
		$this->assertContains( Hooks::NONCE_CACHING, $hooks );
	}

	public function test_allow_skip_cache_seeds_the_callbacks_with_the_value_of_the_engine(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/allow_skip_cache' );
		$seen = array();
		add_filter(
			'wp_rest_cache/allow_skip_cache',
			static function ( $allow ) use ( &$seen ) {
				$seen[] = $allow;
				return $allow;
			}
		);
		$folding = new Compile_Folding();

		$this->assertFalse( $folding->allow_skip_cache( false ) );
		$this->assertTrue( $folding->allow_skip_cache( true ) );

		$this->assertSame( array( false, true ), $seen );
	}

	public function test_allow_skip_cache_is_what_the_callbacks_answer(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/allow_skip_cache' );
		$folding = new Compile_Folding();

		add_filter( 'wp_rest_cache/allow_skip_cache', '__return_true' );
		$this->assertTrue( $folding->allow_skip_cache( false ) );

		remove_filter( 'wp_rest_cache/allow_skip_cache', '__return_true' );
		add_filter( 'wp_rest_cache/allow_skip_cache', '__return_zero' );
		$this->assertFalse( $folding->allow_skip_cache( true ) );
	}

	public function test_without_a_callback_the_value_of_the_engine_is_kept_and_nothing_is_deprecated(): void {
		$folding = new Compile_Folding();

		$this->assertFalse( $folding->allow_skip_cache( false ) );
		$this->assertTrue( $folding->allow_skip_cache( true ) );
		$this->assertTrue( $folding->cors( true ) );
		$this->assertFalse( $folding->cors( false ) );
		$this->assertFalse( $folding->bypass_all( false ) );
		$this->assertTrue( $folding->bypass_all( true ) );
		$this->assertFalse( $folding->nonce_caching( false, 'default' ) );
		$this->assertTrue( $folding->nonce_caching( true, 'default' ) );
		$this->assertNull( Failure_Recorder::last() );
	}

	// ---------- skip_nonce_caching ----------

	/**
	 * The constant callbacks that answer "do not skip", which carry nonce caching over.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function callbacks_that_do_not_skip(): array {
		return array(
			'false'        => array( '__return_false' ),
			'null'         => array( '__return_null' ),
			'zero'         => array( '__return_zero' ),
			'empty string' => array( '__return_empty_string' ),
			'empty array'  => array( '__return_empty_array' ),
		);
	}

	/**
	 * AC-3: the fold only speaks while Rockfire has not decided.
	 */
	public function test_a_setting_or_a_constant_of_rockfire_decides_and_the_legacy_filter_is_not_asked(): void {
		add_filter( 'wp_rest_cache/skip_nonce_caching', '__return_false' );
		$folding = new Compile_Folding();

		$this->assertFalse( $folding->nonce_caching( false, 'setting' ) );
		$this->assertFalse( $folding->nonce_caching( false, 'constant' ) );
		$this->assertFalse( $folding->nonce_caching( false ), 'Without a source nothing is folded.' );
		$this->assertTrue( $folding->nonce_caching( true, 'setting' ) );
	}

	/**
	 * AC-3: from the default, a callback that does not skip switches nonce caching on and fires the deprecation notice.
	 *
	 * @dataProvider callbacks_that_do_not_skip
	 *
	 * @param string $callback A constant callback of core.
	 */
	public function test_from_the_default_a_constant_callback_that_does_not_skip_switches_nonce_caching_on( string $callback ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_nonce_caching' );
		add_filter( 'wp_rest_cache/skip_nonce_caching', $callback );
		$folding = new Compile_Folding();

		$this->assertTrue( $folding->nonce_caching( false, 'default' ) );
		$this->assertTrue( $folding->nonce_caching( true, 'default' ), 'The value of the engine is never turned off.' );
	}

	/**
	 * AC-2: a callback that skips keeps nonce caching off.
	 */
	public function test_a_constant_callback_that_skips_keeps_nonce_caching_off(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_nonce_caching' );
		add_filter( 'wp_rest_cache/skip_nonce_caching', '__return_true' );

		$this->assertFalse( ( new Compile_Folding() )->nonce_caching( false, 'default' ) );
	}

	/**
	 * AC-2: a closure is not constant, so it is neither asked nor reported as a deprecated call.
	 */
	public function test_a_closure_is_not_asked_and_keeps_nonce_caching_off(): void {
		$ran = 0;
		add_filter(
			'wp_rest_cache/skip_nonce_caching',
			static function () use ( &$ran ) {
				++$ran;
				return false;
			}
		);
		$folding = new Compile_Folding();

		$this->assertFalse( $folding->nonce_caching( false, 'default' ) );
		$this->assertSame( 0, $ran );

		add_filter( 'wp_rest_cache/skip_nonce_caching', '__return_false', 20 );
		$this->assertFalse( $folding->nonce_caching( false, 'default' ), 'One callback that is not constant is enough.' );
		$this->assertSame( 0, $ran );
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_a_throwing_callback_leaves_the_value_of_the_engine_and_is_recorded(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/allow_skip_cache' );
		delete_option( Failure_Recorder::OPTION );
		add_filter(
			'wp_rest_cache/allow_skip_cache',
			static function () {
				throw new RuntimeException( 'callback failed' );
			}
		);
		$folding = new Compile_Folding();

		$this->assertTrue( $folding->allow_skip_cache( true ) );
		$this->assertFalse( $folding->allow_skip_cache( false ) );

		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'hook:wp_rest_cache/allow_skip_cache', $last['context'] );
	}

	public function test_cors_starts_the_filter_of_the_original_from_the_opposite_of_the_engine_value(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/disable_cors_headers' );
		$seen = array();
		add_filter(
			'wp_rest_cache/disable_cors_headers',
			static function ( $disable ) use ( &$seen ) {
				$seen[] = $disable;
				return $disable;
			}
		);
		$folding = new Compile_Folding();

		$this->assertTrue( $folding->cors( true ) );
		$this->assertFalse( $folding->cors( false ), 'A value of the engine that already switches the headers off stays.' );

		$this->assertSame( array( false ), $seen, 'The filter is only asked while the headers are on.' );
	}

	public function test_only_the_strict_answer_true_switches_the_cors_headers_off(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/disable_cors_headers' );
		$folding = new Compile_Folding();

		add_filter( 'wp_rest_cache/disable_cors_headers', '__return_true' );
		$this->assertFalse( $folding->cors( true ) );

		remove_filter( 'wp_rest_cache/disable_cors_headers', '__return_true' );
		add_filter( 'wp_rest_cache/disable_cors_headers', '__return_false' );
		$this->assertTrue( $folding->cors( true ) );

		remove_filter( 'wp_rest_cache/disable_cors_headers', '__return_false' );
		add_filter( 'wp_rest_cache/disable_cors_headers', static fn() => 'yes' );
		$this->assertTrue( $folding->cors( true ), 'A truthy value that is not true does not count.' );
	}

	public function test_a_constant_skip_caching_bypasses_every_request(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_caching' );
		$folding = new Compile_Folding();

		add_filter( 'wp_rest_cache/skip_caching', '__return_true' );
		$this->assertTrue( $folding->bypass_all( false ) );

		remove_filter( 'wp_rest_cache/skip_caching', '__return_true' );
		add_filter( 'wp_rest_cache/skip_caching', '__return_false' );
		$this->assertFalse( $folding->bypass_all( false ) );
		$this->assertTrue( $folding->bypass_all( true ), 'The value of the engine is never turned off.' );
	}

	public function test_a_skip_caching_that_is_not_constant_is_not_folded_and_never_called(): void {
		$ran = 0;
		add_filter(
			'wp_rest_cache/skip_caching',
			static function () use ( &$ran ) {
				++$ran;
				return true;
			}
		);
		$folding = new Compile_Folding();

		$this->assertFalse( $folding->bypass_all( false ) );
		$this->assertSame( 'kept', $folding->bypass_all( 'kept' ), 'The value of the engine is handed on as it is.' );

		add_filter( 'wp_rest_cache/skip_caching', '__return_true', 20 );
		$this->assertFalse( $folding->bypass_all( false ), 'One callback that is not constant is enough.' );
		$this->assertSame( 0, $ran );
	}

	public function test_which_hooks_have_only_constant_callbacks(): void {
		$name = 'wp_rest_cache/skip_caching';
		$this->assertFalse( Compile_Folding::is_constant( $name ), 'Not hooked.' );

		foreach ( Compile_Folding::CONSTANT_CALLBACKS as $callback ) {
			add_filter( $name, $callback );
			$this->assertTrue( Compile_Folding::is_constant( $name ), $callback );
			remove_filter( $name, $callback );
		}
		$this->assertSame(
			array( '__return_true', '__return_false', '__return_null', '__return_zero', '__return_empty_string', '__return_empty_array' ),
			Compile_Folding::CONSTANT_CALLBACKS
		);

		add_filter( $name, '__return_true' );
		add_filter( $name, array( $this, 'test_which_hooks_have_only_constant_callbacks' ) );
		$this->assertFalse( Compile_Folding::is_constant( $name ), 'A method is not constant.' );
		remove_filter( $name, array( $this, 'test_which_hooks_have_only_constant_callbacks' ) );

		add_filter( $name, static fn() => true );
		$this->assertFalse( Compile_Folding::is_constant( $name ), 'A closure is not constant.' );
	}

	// ---------- Compiled into the snapshot ----------

	/**
	 * AC-1: a filtered allow_skip_cache is compiled and then applies on both paths, with no hook on the hit.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_compiled_allow_skip_cache_bypasses_skip_cache_on_both_paths_and_fires_no_legacy_hook_on_the_hit( string $path ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/allow_skip_cache' );
		$h = $this->harness( $path );
		$this->assertFalse( $this->compile()->allow_skip_cache(), 'Control: the engine does not honour skip_cache by default.' );
		$h->request( self::URI . '?skip_cache=1' );
		$this->prime( '{"stored":1}' );
		$this->assertTrue( $h->lookup(), 'Control: skip_cache is ignored, so the entry is a hit.' );

		$calls = 0;
		add_filter(
			'wp_rest_cache/allow_skip_cache',
			static function () use ( &$calls ) {
				++$calls;
				return true;
			}
		);

		$this->assertTrue( $this->compile()->allow_skip_cache() );
		$this->assertTrue( $this->compiled()->allow_skip_cache(), 'The stored snapshot has it.' );
		$this->assertGreaterThan( 0, $calls, 'The callback ran at the compile.' );
		$calls = 0;

		$hooks = $this->hooks_during(
			function () use ( $h ): void {
				$h->request( self::URI . '?skip_cache=1' );
				$this->assertFalse( $h->lookup() );
			}
		);

		$this->assertSame( Bypass_Reason::SKIP_CACHE, Request_State::decision()->reason );
		$this->assertSame( '', $h->output );
		$this->assertSame( 0, $calls, 'The callback did not run on the hit.' );
		foreach ( $hooks as $hook ) {
			$this->assertStringStartsNotWith( 'wp_rest_cache/', $hook, 'No legacy hook fired on the hit.' );
		}
		$this->assertNotContains( 'deprecated_hook_run', $hooks );
	}

	/**
	 * AC-2: a filtered disable_cors_headers is compiled and then applies on both paths.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_compiled_disable_cors_headers_sends_no_origin_on_either_path( string $path ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/disable_cors_headers' );
		$origin = home_url();
		$h      = $this->harness( $path );
		$this->assertTrue( $this->compile()->cors_on_hits(), 'Control: hits carry the CORS headers by default.' );
		$h->request( self::URI, array( 'HTTP_ORIGIN' => $origin ) );
		$this->prime( '{"stored":1}' );
		$this->assertTrue( $h->lookup() );
		$this->assertSame( $origin, $h->hit_header( 'Access-Control-Allow-Origin' ), 'Control: the origin is allowed.' );

		add_filter( 'wp_rest_cache/disable_cors_headers', '__return_true' );

		$this->assertFalse( $this->compile()->cors_on_hits() );
		$this->assertFalse( $this->compiled()->cors_on_hits(), 'The stored snapshot has it.' );
		$h->request( self::URI, array( 'HTTP_ORIGIN' => $origin ) );
		$this->assertTrue( $h->lookup(), 'The entry is still a hit.' );

		$this->assertNull( $h->hit_header( 'Access-Control-Allow-Origin' ) );
		$this->assertNull( $h->hit_header( 'Access-Control-Allow-Methods' ) );
		$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
	}

	/**
	 * AC-3: a constant skip_caching is compiled and bypasses on both paths.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_compiled_constant_skip_caching_bypasses_every_request_on_both_paths( string $path ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_caching' );
		$h = $this->harness( $path );
		$this->assertFalse( $this->compile()->bypass_all(), 'Control.' );
		$h->request( self::URI );
		$this->prime( '{"stored":1}' );
		$this->assertTrue( $h->lookup(), 'Control: a hit.' );

		add_filter( 'wp_rest_cache/skip_caching', '__return_true' );

		$this->assertTrue( $this->compile()->bypass_all() );
		$this->assertTrue( $this->compiled()->bypass_all() );
		$h->request( self::URI );
		$this->assertFalse( $h->lookup() );
		$this->assertSame( Bypass_Reason::FILTERED, Request_State::decision()->reason );
		$this->assertSame( '', $h->output );
	}

	/**
	 * AC-3: a constant skip_caching sets bypass_all; a closure leaves it absent and is reported.
	 */
	public function test_a_skip_caching_closure_is_not_compiled_and_is_reported_without_the_constant(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_caching' );
		add_filter( 'wp_rest_cache/skip_caching', '__return_true' );

		$this->assertTrue( $this->compile()->bypass_all() );
		$this->assertTrue( get_option( Names::OPTION_CONFIG )['bypass_all'] );
		$this->assertNull( ( new Findings() )->get( Findings::GROUP_HOOKS ), 'A constant skip_caching is applied, so nothing is reported.' );

		remove_filter( 'wp_rest_cache/skip_caching', '__return_true' );
		add_filter( 'wp_rest_cache/skip_caching', static fn() => true );

		$this->assertFalse( $this->compile()->bypass_all() );
		$this->assertArrayNotHasKey( 'bypass_all', get_option( Names::OPTION_CONFIG ) );
		$hooks = ( new Findings() )->get( Findings::GROUP_HOOKS );
		$this->assertNotNull( $hooks );
		$this->assertCount( 1, $hooks['findings'] );
		$this->assertSame( 'hit_filter_not_applied', $hooks['findings'][0]->code );
		$this->assertSame( 'wp_rest_cache/skip_caching', $hooks['findings'][0]->subject );
	}

	/**
	 * AC-1: a constant skip_nonce_caching that does not skip is compiled into the snapshot while nothing is stored.
	 *
	 * @dataProvider callbacks_that_do_not_skip
	 *
	 * @param string $callback A constant callback of core.
	 */
	public function test_a_compiled_constant_skip_nonce_caching_that_does_not_skip_switches_nonce_caching_on( string $callback ): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/skip_nonce_caching' );
		$this->assertFalse( $this->compile()->nonce_caching(), 'Control: nonce caching is off by default.' );
		$this->assertArrayNotHasKey( 'nonce_caching', get_option( Names::OPTION_CONFIG ) );

		add_filter( 'wp_rest_cache/skip_nonce_caching', $callback );

		$this->assertTrue( $this->compile()->nonce_caching() );
		$this->assertTrue( $this->compiled()->nonce_caching(), 'The stored snapshot has it.' );
		$this->assertTrue( get_option( Names::OPTION_CONFIG )['nonce_caching'] );
		$this->assertNull( ( new Findings() )->get( Findings::GROUP_HOOKS ), 'A constant callback is carried over, so nothing is reported.' );
	}

	/**
	 * AC-2: a callback that skips, no callback and a closure leave nonce caching off.
	 */
	public function test_a_compiled_skip_nonce_caching_that_skips_or_is_not_constant_leaves_nonce_caching_off(): void {
		$this->assertFalse( $this->compile()->nonce_caching(), 'No callback.' );
		$this->assertArrayNotHasKey( 'nonce_caching', get_option( Names::OPTION_CONFIG ) );

		$this->setExpectedDeprecated( 'wp_rest_cache/skip_nonce_caching' );
		add_filter( 'wp_rest_cache/skip_nonce_caching', '__return_true' );
		$this->assertFalse( $this->compile()->nonce_caching(), '__return_true.' );
		$this->assertArrayNotHasKey( 'nonce_caching', get_option( Names::OPTION_CONFIG ) );
		remove_filter( 'wp_rest_cache/skip_nonce_caching', '__return_true' );

		add_filter( 'wp_rest_cache/skip_nonce_caching', static fn() => false );
		$this->assertFalse( $this->compile()->nonce_caching(), 'A closure.' );
		$this->assertArrayNotHasKey( 'nonce_caching', get_option( Names::OPTION_CONFIG ) );
	}

	/**
	 * AC-4: a callback that fails leaves the flag off and is recorded under the name of the legacy hook.
	 */
	public function test_a_failing_skip_nonce_caching_leaves_nonce_caching_off_and_is_recorded(): void {
		delete_option( Failure_Recorder::OPTION );
		add_filter( 'wp_rest_cache/skip_nonce_caching', '__return_false' );
		add_action(
			'deprecated_hook_run',
			static function (): void {
				throw new RuntimeException( 'callback failed' );
			},
			1
		);

		$this->assertFalse( ( new Compile_Folding() )->nonce_caching( false, 'default' ) );
		$this->assertFalse( $this->compile()->nonce_caching() );
		$this->assertArrayNotHasKey( 'nonce_caching', get_option( Names::OPTION_CONFIG ) );

		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'hook:wp_rest_cache/skip_nonce_caching', $last['context'] );
	}

	/**
	 * The constant RFRAC_ALLOW_SKIP_CACHE wins: the legacy filter is never reached.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_constant_allow_skip_cache_wins_over_the_legacy_filter(): void {
		define( 'RFRAC_ALLOW_SKIP_CACHE', false );
		$calls = 0;
		add_filter(
			'wp_rest_cache/allow_skip_cache',
			static function () use ( &$calls ) {
				++$calls;
				return true;
			}
		);

		$config = $this->compile();

		$this->assertFalse( $config->allow_skip_cache() );
		$this->assertSame( 0, $calls );
	}

	/**
	 * AC-8: without legacy callbacks the snapshot is the one without the module.
	 */
	public function test_without_legacy_callbacks_the_snapshot_equals_the_one_without_the_module(): void {
		$hooks = Plugin::instance()->legacy_hooks();
		$this->assertNotEmpty( $hooks );
		foreach ( $hooks as $hook ) {
			remove_filter( $hook['hook'], $hook['callback'], $hook['priority'] );
		}
		$without = $this->compile();
		$stored  = get_option( Names::OPTION_CONFIG );
		$this->assertNull( ( new Findings() )->get( Findings::GROUP_HOOKS ), 'Precondition: without the module nothing was recorded.' );

		foreach ( $hooks as $hook ) {
			add_filter( $hook['hook'], $hook['callback'], $hook['priority'], $hook['args'] );
		}
		$with = $this->compile();

		$this->assertNotSame( '', $with->fingerprint() );
		$this->assertSame( $without->fingerprint(), $with->fingerprint() );
		$this->assertSame( $stored, get_option( Names::OPTION_CONFIG ) );
		$this->assertArrayNotHasKey( 'bypass_all', $stored );
		$this->assertArrayNotHasKey( 'cors_on_hits', $stored );
		$this->assertSame( array(), ( new Findings() )->all(), 'A site without legacy callbacks keeps no finding.' );
	}
}
