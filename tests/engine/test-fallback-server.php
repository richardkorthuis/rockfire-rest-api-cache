<?php
/**
 * Tests for the fallback server.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Cache_Policy;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Early_Server;
use Rockfire\Rest_Api_Cache\Serving\Fallback_Server;
use Rockfire\Rest_Api_Cache\Serving\Lookup;
use Rockfire\Rest_Api_Cache\Serving\Request_State;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Serving\Fallback_Server
 */
class Test_Fallback_Server extends Rfrac_Serving_Test_Case {

	public function set_up() {
		parent::set_up();
		$this->simulate( '/wp-json/wp/v2/posts' );
		Early_Server::use_writer( $this->capturing_writer() );
	}

	/**
	 * A fallback server with the capturing writer.
	 *
	 * @param bool $blocked Whether the coexistence guard blocks.
	 *
	 * @return Fallback_Server
	 */
	private function fallback( bool $blocked = false ): Fallback_Server {
		return new Fallback_Server(
			new Lookup( new Cache_Policy( static fn(): bool => $blocked ), new Repository(), $this->capturing_writer() ),
			static fn(): ?Compiled_Config => Compiled_Config::load()
		);
	}

	/**
	 * Serve and report whether the request was terminated.
	 *
	 * @param Fallback_Server|null $server Server.
	 *
	 * @return bool
	 */
	private function serve( ?Fallback_Server $server = null ): bool {
		try {
			( $server ?? $this->fallback() )->maybe_serve();
		} catch ( Rfrac_Test_Exit $e ) {
			return true;
		}
		return false;
	}

	/**
	 * Boot the early server and report termination.
	 *
	 * @param int $version Template version.
	 *
	 * @return bool
	 */
	private function boot_early( int $version = 1 ): bool {
		try {
			Early_Server::boot( $version );
		} catch ( Rfrac_Test_Exit $e ) {
			return true;
		}
		return false;
	}

	public function test_outdated_mu_file_falls_back_to_serving_the_hit(): void {
		$this->prime( '{"fallback":1}' );
		$this->assertFalse( $this->boot_early( 0 ) );
		$this->assertSame( '', $this->output );

		$this->assertTrue( $this->serve() );

		$this->assertSame( '{"fallback":1}', $this->output );
		$this->assertContains( 'X-Rfrac-Cache: HIT', $this->headers );
	}

	public function test_missing_mu_file_serves_the_hit(): void {
		$this->prime( '{"nomu":1}' );

		$this->assertTrue( $this->serve() );

		$this->assertSame( '{"nomu":1}', $this->output );
	}

	public function test_does_nothing_after_the_early_server_booted_and_missed(): void {
		$this->assertFalse( $this->boot_early() );
		$decision = Request_State::decision();
		$this->prime();

		$this->assertFalse( $this->serve() );

		$this->assertSame( '', $this->output );
		$this->assertTrue( Request_State::early_booted() );
		$this->assertTrue( $decision->cacheable );
		$this->assertSame( $decision, Request_State::decision() );
	}

	public function test_does_nothing_when_a_lookup_is_already_done(): void {
		$this->prime();
		Request_State::record_lookup( Request_Context::from_globals( 'wp-json' ), Policy_Decision::bypass( Bypass_Reason::METHOD ), null );

		$this->assertFalse( $this->serve() );
		$this->assertSame( '', $this->output );
	}

	public function test_authorization_never_hits(): void {
		$this->prime();
		$this->simulate( '/wp-json/wp/v2/posts', array( 'HTTP_AUTHORIZATION' => 'Bearer x' ) );

		$this->assertFalse( $this->serve() );

		$this->assertSame( '', $this->output );
		$this->assertSame( Bypass_Reason::CREDENTIALS, Request_State::decision()->reason );
	}

	public function test_coexistence_guard_blocks_the_hit(): void {
		$this->prime();

		$this->assertFalse( $this->serve( $this->fallback( true ) ) );

		$this->assertSame( '', $this->output );
		$this->assertSame( Bypass_Reason::COEXISTENCE, Request_State::decision()->reason );
	}

	public function test_wrong_schema_or_missing_config_does_nothing(): void {
		$this->prime();
		$this->set_compiled_config( array_merge( $this->snapshot(), array( 'schema_version' => 999 ) ) );
		$this->assertFalse( $this->serve() );
		$this->assertFalse( Request_State::lookup_done() );

		delete_option( Names::OPTION_CONFIG );
		$this->assertFalse( $this->serve() );
		$this->assertSame( '', $this->output );
		$this->assertFalse( Request_State::lookup_done() );
	}

	public function test_failing_config_loader_does_nothing(): void {
		$server = new Fallback_Server(
			new Lookup( $this->policy(), new Repository(), $this->capturing_writer() ),
			static function (): ?Compiled_Config {
				throw new RuntimeException( 'x' );
			}
		);

		$this->assertFalse( $this->serve( $server ) );
		$this->assertFalse( Request_State::lookup_done() );
	}

	public function test_exception_in_the_chain_lets_the_request_continue(): void {
		$this->prime();
		$lookup = new Lookup(
			$this->policy(),
			new Repository(),
			$this->capturing_writer(),
			static function (): string {
				throw new RuntimeException( 'key' );
			}
		);
		$server = new Fallback_Server( $lookup, static fn(): ?Compiled_Config => Compiled_Config::load() );

		$this->assertFalse( $this->serve( $server ) );
		$this->assertTrue( Request_State::lookup_done() );
		$this->assertSame( '', $this->output );
	}

	/**
	 * Warm every cache the hit path reads so the hook spy only sees the hooks of the reads themselves.
	 */
	private function warm_caches(): void {
		get_option( Names::OPTION_CONFIG );
		get_option( 'permalink_structure' );
		get_option( 'active_plugins' );
		get_site_option( 'active_sitewide_plugins' );
		Coexistence_Guard::is_blocked();
		Coexistence_Guard::reset();
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
			( new Plugin( $this->capturing_writer() ) )->fallback_server()->maybe_serve();
			$this->fail( 'The primed entry was not served.' );
		} catch ( Rfrac_Test_Exit $e ) {
			$this->assertContains( 'X-Rfrac-Cache: HIT', $this->headers );
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

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_kill_switch_serves_nothing_without_a_recompile(): void {
		$this->prime( '{"fallback":1}' );
		define( 'RFRAC_DISABLE', true );
		$this->assertTrue( Compiled_Config::load()->enabled(), 'The compiled config is not rebuilt.' );

		$this->assertFalse( $this->serve() );

		$this->assertSame( '', $this->output );
		$this->assertFalse( Request_State::lookup_done() );
	}
}
