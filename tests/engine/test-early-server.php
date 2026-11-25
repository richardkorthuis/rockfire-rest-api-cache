<?php
/**
 * Tests for the early (must-use phase) server.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Install\Mu_Installer;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Serving\Early_Server;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * @covers \Rockfire\Rest_Api_Cache\Serving\Early_Server
 */
class Test_Early_Server extends Rfrac_Serving_Test_Case {

	public function set_up() {
		parent::set_up();
		$this->simulate( '/wp-json/wp/v2/posts' );
		Early_Server::use_writer( $this->capturing_writer() );
	}

	/**
	 * Boot and report whether the request was terminated.
	 *
	 * @param int $version Template version.
	 *
	 * @return bool
	 */
	private function boot( int $version = 1 ): bool {
		try {
			Early_Server::boot( $version );
		} catch ( Rfrac_Test_Exit $e ) {
			return true;
		}
		return false;
	}

	/**
	 * Warm every cache the hit path reads so the query log only holds the store's own queries.
	 */
	private function warm_caches(): void {
		get_option( Names::OPTION_CONFIG );
		get_option( 'permalink_structure' );
		get_option( 'active_plugins' );
		get_site_option( 'active_sitewide_plugins' );
		Coexistence_Guard::is_blocked();
		Coexistence_Guard::reset();
	}

	/**
	 * PHP source without comments and docblocks.
	 *
	 * @param string $source PHP source.
	 *
	 * @return string
	 */
	private function code_without_comments( string $source ): string {
		$code = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) ) {
				if ( T_COMMENT !== $token[0] && T_DOC_COMMENT !== $token[0] ) {
					$code .= $token[1];
				}
			} else {
				$code .= $token;
			}
		}
		return $code;
	}

	public function test_expected_template_version(): void {
		$this->assertSame( 1, Mu_Installer::TEMPLATE_VERSION );
	}

	public function test_hit_outputs_the_payload_with_one_select(): void {
		$this->prime( '{"primed":"payload"}' );
		$this->warm_caches();

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		try {
			$terminated = $this->boot();
		} finally {
			$recorder->stop();
		}

		$this->assertTrue( $terminated );
		$this->assertSame( '{"primed":"payload"}', $this->output );
		$this->assertContains( 'X-Rfrac-Cache: HIT', $this->headers );
		$this->assertCount( 1, $recorder->matching( '/^\s*SELECT/i' ) );
		$this->assertSame( array(), $recorder->matching( '/SHOW\s+TABLES/i' ) );
		$this->assertTrue( Request_State::early_booted() );
		$this->assertSame( 1, Request_State::mu_template_version() );
	}

	public function test_outdated_template_does_nothing(): void {
		$this->prime();

		$this->assertFalse( $this->boot( 0 ) );

		$this->assertSame( '', $this->output );
		$this->assertFalse( Request_State::early_booted() );
		$this->assertSame( 0, Request_State::mu_template_version() );
		$this->assertFalse( Request_State::lookup_done() );
	}

	public function test_miss_leaves_a_cacheable_decision_for_the_container(): void {
		$this->assertFalse( $this->boot() );

		$this->assertSame( '', $this->output );
		$this->assertTrue( Request_State::early_booted() );
		$this->assertTrue( Request_State::lookup_done() );
		$this->assertTrue( Request_State::decision()->cacheable );
		$this->assertNotNull( Request_State::cache_key() );
	}

	public function test_credentials_bypass_even_with_a_primed_entry(): void {
		$this->prime();
		$this->simulate( '/wp-json/wp/v2/posts', array( 'HTTP_AUTHORIZATION' => 'Bearer x' ) );

		$this->assertFalse( $this->boot() );

		$this->assertSame( '', $this->output );
		$this->assertSame( Bypass_Reason::CREDENTIALS, Request_State::decision()->reason );
	}

	public function test_coexistence_guard_blocks_the_hit(): void {
		$this->prime();
		$this->block_by_coexistence();

		$this->assertFalse( $this->boot() );

		$this->assertSame( '', $this->output );
		$this->assertSame( Bypass_Reason::COEXISTENCE, Request_State::decision()->reason );
	}

	public function test_no_compiled_config_means_no_output_and_no_query(): void {
		$this->prime();
		delete_option( Names::OPTION_CONFIG );

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		try {
			$terminated = $this->boot();
		} finally {
			$recorder->stop();
		}

		$this->assertFalse( $terminated );
		$this->assertSame( '', $this->output );
		$this->assertSame( array(), $recorder->matching( '/rfrac_entries/i' ) );
		$this->assertFalse( Request_State::lookup_done() );
	}

	public function test_other_schema_version_is_ignored(): void {
		$this->prime();
		$this->set_compiled_config( array_merge( $this->snapshot(), array( 'schema_version' => 999 ) ) );

		$this->assertFalse( $this->boot() );

		$this->assertSame( '', $this->output );
		$this->assertFalse( Request_State::lookup_done() );
	}

	public function test_exception_in_the_chain_lets_the_request_continue(): void {
		$this->prime();
		$boom = static function ( $query ) {
			if ( is_string( $query ) && false !== stripos( $query, 'cache_key =' ) ) {
				throw new RuntimeException( 'db' );
			}
			return $query;
		};
		add_filter( 'query', $boom );
		try {
			$terminated = $this->boot();
		} finally {
			remove_filter( 'query', $boom );
		}

		$this->assertFalse( $terminated );
		$this->assertSame( '', $this->output );
		$this->assertTrue( Request_State::lookup_done() );
		$this->assertFalse( Request_State::decision()->cacheable );
		$this->assertSame( 'lookup', Failure_Recorder::last()['context'] ?? null, 'The failure is recorded.' );
		delete_option( Failure_Recorder::OPTION );
	}

	public function test_failed_preparation_is_recorded_and_leaves_the_request_to_the_fallback(): void {
		$this->prime();
		$boom = static function () {
			throw new RuntimeException( 'config unavailable' );
		};
		add_filter( 'pre_option_' . Names::OPTION_CONFIG, $boom );
		try {
			$terminated = $this->boot();
		} finally {
			remove_filter( 'pre_option_' . Names::OPTION_CONFIG, $boom );
		}

		$this->assertFalse( $terminated );
		$this->assertFalse( Request_State::early_booted(), 'The fallback server may retry.' );
		$this->assertSame( 1, Request_State::mu_template_version() );
		$this->assertSame( 'early_server', Failure_Recorder::last()['context'] ?? null );
		delete_option( Failure_Recorder::OPTION );
	}

	public function test_hit_path_fires_no_plugin_hooks(): void {
		$this->prime();
		$this->warm_caches();
		$hooks = array();
		$spy   = static function () use ( &$hooks ) {
			$hooks[] = current_filter();
			return func_num_args() > 0 ? func_get_arg( 0 ) : null;
		};
		add_filter( 'all', $spy );
		try {
			$this->boot();
		} finally {
			remove_filter( 'all', $spy );
		}

		// Core fires option, home_url and query filters while reading options and running SQL; nothing else.
		$allowed = '/^(pre_option$|pre_wp_load_alloptions$|alloptions$|set_url_scheme$|pre_option_|option_|default_option_|pre_site_option_|site_option_|default_site_option_|home_url$|query$)/';
		foreach ( $hooks as $hook ) {
			$this->assertStringStartsNotWith( 'rfrac', (string) $hook );
			$this->assertMatchesRegularExpression( $allowed, (string) $hook, 'Unexpected hook on the hit path.' );
		}
		$this->assertNotEmpty( $hooks );
	}

	public function test_serving_code_loads_nothing_outside_the_plugin_includes(): void {
		$this->prime();
		$before = get_included_files();
		$this->boot();
		$new = array_diff( get_included_files(), $before );

		$includes = wp_normalize_path( dirname( __DIR__, 2 ) . '/includes/' );
		foreach ( $new as $file ) {
			$this->assertStringStartsWith( $includes, wp_normalize_path( $file ) );
		}

		// The included-files delta is empty once earlier tests autoloaded the classes, so also scan the source.
		$root  = dirname( __DIR__, 2 ) . '/includes/';
		// Capture runs on the miss path after the request was not served from the cache; it legitimately fires filters.
		$serving = array_diff( glob( $root . 'serving/*.php' ), array( $root . 'serving/class-capture.php' ) );
		$files   = array_merge( $serving, array( $root . 'request/class-request-context.php', $root . 'config/class-compiled-config.php' ) );
		foreach ( $files as $file ) {
			$source = $this->code_without_comments( (string) file_get_contents( $file ) );
			$this->assertDoesNotMatchRegularExpression( '/wp-admin|apply_filters\s*\(|do_action\s*\(|wp_remote_|SHOW\s+TABLES|\b(require|include)(_once)?\s*[\'"(]/', $source, basename( $file ) );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_kill_switch_serves_nothing_without_a_recompile(): void {
		$this->prime( '{"primed":"payload"}' );
		define( 'RFRAC_DISABLE', true );
		$this->assertTrue( Compiled_Config::load()->enabled(), 'The compiled config is not rebuilt.' );

		$this->assertFalse( $this->boot() );

		$this->assertSame( '', $this->output );
		$this->assertFalse( Request_State::early_booted(), 'The fallback server gets its turn (and checks the switch itself).' );
		$this->assertFalse( Request_State::lookup_done() );
	}
}
