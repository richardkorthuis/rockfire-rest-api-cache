<?php
/**
 * Tests for the WP-CLI commands `wp rfrac` (flush, list, status, prune) and `wp rfrac config get`.
 *
 * The commands run in-process against WP-CLI's execution logger; `WP_CLI::error()` throws instead of exiting.
 * Registration with a real `wp` binary (`wp rfrac --help`, argument parsing) is not covered here.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Cli\Command;
use Rockfire\Rest_Api_Cache\Cli\Config_Command;
use Rockfire\Rest_Api_Cache\Cli\Network_Iterator;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Maintenance\Lock;
use Rockfire\Rest_Api_Cache\Maintenance\Maintenance;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Services\Cache_Service;
use Rockfire\Rest_Api_Cache\Services\Status;
use Rockfire\Rest_Api_Cache\Services\Status_Service;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Interface;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;

require_once dirname( __DIR__, 2 ) . '/vendor/wp-cli/wp-cli/php/utils.php';

/**
 * @covers \Rockfire\Rest_Api_Cache\Cli\Command
 * @covers \Rockfire\Rest_Api_Cache\Cli\Config_Command
 * @covers \Rockfire\Rest_Api_Cache\Cli\Network_Iterator
 */
class Test_Cli_Command extends Rfrac_Test_Case {

	/**
	 * The captured WP-CLI output.
	 *
	 * @var \WP_CLI\Loggers\Execution
	 */
	private $logger;

	/**
	 * Previous WP-CLI logger.
	 *
	 * @var mixed
	 */
	private $previous_logger;

	/**
	 * `rfrac/flushed` events: `[ type, targets, affected ]`.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $flushed = array();

	public function set_up() {
		parent::set_up();
		$this->logger = new \WP_CLI\Loggers\Execution();
		\WP_CLI::set_logger( $this->logger );
		$this->set_capture_exit( true );
		$this->flushed = array();
		add_action(
			Hooks::FLUSHED,
			function ( $type, $targets, $affected ): void {
				$this->flushed[] = array( $type, $targets, $affected );
			},
			10,
			3
		);
	}

	public function tear_down() {
		$this->set_capture_exit( false );
		delete_option( Lock::OPTION );
		delete_option( Names::OPTION_SETTINGS );
		remove_all_filters( Hooks::MAINTENANCE_ARGS );
		parent::tear_down();
	}

	/**
	 * The command under test, built on the services of the booted container.
	 *
	 * @return Command
	 */
	private function command(): Command {
		$plugin = Plugin::instance();
		return new Command( $plugin->cache_service(), $plugin->route_service(), $plugin->status_service(), new Network_Iterator( $plugin->basename() ) );
	}

	/**
	 * A command whose maintenance runs on the given store (to force a stalled run).
	 *
	 * @param Store_Interface $store Store used by the maintenance and the cache service.
	 *
	 * @return Command
	 */
	private function command_with_store( Store_Interface $store ): Command {
		$plugin      = Plugin::instance();
		$repository  = new Repository();
		$maintenance = new Maintenance( $repository, $store, Config::instance(), new Lock() );
		return new Command( new Cache_Service( $repository, $store, $maintenance ), $plugin->route_service(), $plugin->status_service(), new Network_Iterator( $plugin->basename() ) );
	}

	/**
	 * Run a command callback and capture its output.
	 *
	 * @param callable $callback Callback.
	 *
	 * @return array{stdout:string,stderr:string,error:?string}
	 */
	private function run_cli( callable $callback ): array {
		$this->logger->stdout = '';
		$this->logger->stderr = '';
		$error                = null;
		$this->logger->ob_start();
		try {
			$callback();
		} catch ( \WP_CLI\ExitException $e ) {
			$error = 'exit';
		} finally {
			$this->logger->ob_end();
		}
		return array(
			'stdout' => $this->logger->stdout,
			'stderr' => $this->logger->stderr,
			'error'  => $error,
		);
	}

	/**
	 * WP_CLI::$capture_exit is private; toggle it so `WP_CLI::error()` throws instead of exiting.
	 *
	 * @param bool $value New value.
	 */
	private function set_capture_exit( bool $value ): void {
		( new ReflectionClass( 'WP_CLI' ) )->getProperty( 'capture_exit' )->setValue( null, $value );
	}

	// ---------- flush ----------

	public function test_flush_all_flushes_every_entry(): void {
		$a = Rfrac_Entry_Fixtures::insert_entry();
		$b = Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/pages' ), array( 'post:2' ) );

		$out = $this->run_cli( fn() => $this->command()->flush( array(), array( 'all' => true ) ) );

		$this->assertNull( $out['error'] );
		$this->assertStringContainsString( 'Flushed 2 cached responses.', $out['stdout'] );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $a ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $b ) );
	}

	public function test_flush_tag_flushes_only_the_tagged_entries(): void {
		$tagged   = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) );
		$untagged = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:9' ) );

		$out = $this->run_cli( fn() => $this->command()->flush( array(), array( 'tag' => 'post:1' ) ) );

		$this->assertStringContainsString( 'Flushed 1 cached responses.', $out['stdout'] );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $tagged ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $untagged ) );
	}

	public function test_flush_tag_list_is_one_flush_call(): void {
		$one = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) );
		$two = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:2' ) );

		$out = $this->run_cli( fn() => $this->command()->flush( array(), array( 'tag' => 'post:1,post:2' ) ) );

		$this->assertStringContainsString( 'Flushed 2 cached responses.', $out['stdout'] );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $one ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $two ) );
		$this->assertCount( 1, $this->flushed, 'One flush call with both tags.' );
	}

	public function test_flush_tag_error_of_the_service_is_reported(): void {
		$out = $this->run_cli( fn() => $this->command()->flush( array(), array( 'tag' => 'route:/x' ) ) );

		$this->assertSame( 'exit', $out['error'] );
		$this->assertStringContainsString( 'Route tags cannot be flushed here', $out['stderr'] );
	}

	public function test_flush_route_resolves_a_path_to_its_pattern(): void {
		$pattern = '/wp/v2/posts/(?P<id>[\d]+)';
		$match   = Rfrac_Entry_Fixtures::insert_entry( array( 'route' => $pattern ) );
		$other   = Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/pages' ) );

		$out = $this->run_cli( fn() => $this->command()->flush( array(), array( 'route' => '/wp/v2/posts/5' ) ) );

		$this->assertNull( $out['error'], $out['stderr'] );
		$this->assertStringContainsString( 'Flushed 1 cached responses.', $out['stdout'] );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $match ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $other ) );
	}

	public function test_flush_unknown_route_is_an_error(): void {
		$out = $this->run_cli( fn() => $this->command()->flush( array(), array( 'route' => '/nope/v1/missing' ) ) );

		$this->assertSame( 'exit', $out['error'] );
		$this->assertStringContainsString( 'Unknown route: /nope/v1/missing', $out['stderr'] );
	}

	public function test_flush_url_of_another_host_is_an_error(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry();

		$out = $this->run_cli( fn() => $this->command()->flush( array(), array( 'request-url' => 'https://other.host/wp-json/wp/v2/posts' ) ) );

		$this->assertSame( 'exit', $out['error'] );
		$this->assertStringContainsString( 'Enter a full http(s) URL of a REST route on this site.', $out['stderr'] );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ) );
	}

	public function test_flush_url_of_this_site_flushes_exactly_the_target(): void {
		$url     = rest_url( 'wp/v2/posts' );
		$context = Request_Context::from_url( $url, rest_get_url_prefix() );
		$target  = Rfrac_Entry_Fixtures::insert_entry( array( 'url_hash' => Cache_Key::url_hash( $context, $context->params ) ) );
		$other   = Rfrac_Entry_Fixtures::insert_entry();

		$out = $this->run_cli( fn() => $this->command()->flush( array(), array( 'request-url' => $url ) ) );

		$this->assertNull( $out['error'], $out['stderr'] );
		$this->assertStringContainsString( 'Flushed 1 cached responses.', $out['stdout'] );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $target ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $other ) );
	}

	public function test_flush_url_given_decoded_flushes_the_entry_of_the_encoded_request(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$encoded = Request_Context::from_url( home_url( '/wp-json/wp/v2/caf%C3%A9' ), rest_get_url_prefix() );
		$this->assertSame( '/wp/v2/caf%C3%A9', $encoded->route );
		$target = Rfrac_Entry_Fixtures::insert_entry( array( 'url_hash' => Cache_Key::url_hash( $encoded, $encoded->params ) ) );

		$out = $this->run_cli( fn() => $this->command()->flush( array(), array( 'request-url' => home_url( '/wp-json/wp/v2/café' ) ) ) );

		$this->assertNull( $out['error'], $out['stderr'] );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $target ) );
	}

	public function test_flush_needs_exactly_one_selector(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) );

		foreach ( array( array(), array( 'all' => true, 'tag' => 'post:1' ), array( 'tag' => 'post:1', 'route' => '/wp/v2/posts' ) ) as $assoc ) {
			$out = $this->run_cli( fn() => $this->command()->flush( array(), $assoc ) );
			$this->assertSame( 'exit', $out['error'] );
			$this->assertStringContainsString( 'Specify exactly one of --all, --tag, --request-url, --route.', $out['stderr'] );
		}
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ) );
	}

	public function test_network_flag_on_a_single_site_is_an_error(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site only.' );
		}
		foreach ( array( 'flush' => array( 'all' => true ), 'prune' => array(), 'list_entries' => array() ) as $method => $assoc ) {
			$assoc['network'] = true;
			$out              = $this->run_cli( fn() => $this->command()->$method( array(), $assoc ) );
			$this->assertSame( 'exit', $out['error'], $method );
			$this->assertStringContainsString( '--network requires multisite.', $out['stderr'] );
		}
	}

	// ---------- list ----------

	public function test_list_json_has_the_default_fields(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry( array( 'hits' => 3 ) );

		$out  = $this->run_cli( fn() => $this->command()->list_entries( array(), array( 'format' => 'json' ) ) );
		$rows = json_decode( $out['stdout'], true );

		$this->assertIsArray( $rows );
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'id', 'route', 'request', 'state', 'size', 'hits', 'expires_at' ), array_keys( $rows[0] ) );
		$this->assertSame( $id, (int) $rows[0]['id'] );
		$this->assertSame( 'active', $rows[0]['state'] );
		$this->assertSame( 3, (int) $rows[0]['hits'] );
	}

	public function test_list_count_prints_the_total_integer(): void {
		Rfrac_Entry_Fixtures::insert_entry();
		Rfrac_Entry_Fixtures::insert_entry();
		Rfrac_Entry_Fixtures::insert_entry();

		$out = $this->run_cli( fn() => $this->command()->list_entries( array(), array( 'format' => 'count', 'per-page' => 1 ) ) );

		$this->assertSame( '3', trim( $out['stdout'] ) );
	}

	public function test_list_state_flushed_ids_prints_only_flushed_ids(): void {
		Rfrac_Entry_Fixtures::insert_entry();
		$flushed_a = Rfrac_Entry_Fixtures::insert_entry( array( 'state' => 2, 'flushed_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		$flushed_b = Rfrac_Entry_Fixtures::insert_entry( array( 'state' => 2, 'flushed_at' => gmdate( 'Y-m-d H:i:s' ) ) );

		$out = $this->run_cli( fn() => $this->command()->list_entries( array(), array( 'state' => 'flushed', 'format' => 'ids' ) ) );

		$ids = array_map( 'intval', explode( ' ', trim( $out['stdout'] ) ) );
		sort( $ids );
		$this->assertSame( array( $flushed_a, $flushed_b ), $ids );
	}

	public function test_list_filters_and_fields(): void {
		Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/posts', 'request' => '/wp/v2/posts?x=1' ) );
		Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/pages', 'request' => '/wp/v2/pages' ) );

		$out  = $this->run_cli( fn() => $this->command()->list_entries( array(), array( 'route' => '/wp/v2/pages', 'fields' => 'id,route', 'format' => 'json' ) ) );
		$rows = json_decode( $out['stdout'], true );
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'id', 'route' ), array_keys( $rows[0] ) );

		$out  = $this->run_cli( fn() => $this->command()->list_entries( array(), array( 'search' => 'x=1', 'format' => 'json' ) ) );
		$rows = json_decode( $out['stdout'], true );
		$this->assertCount( 1, $rows );
		$this->assertSame( '/wp/v2/posts', $rows[0]['route'] );
	}

	public function test_list_expired_state_and_invalid_arguments(): void {
		Rfrac_Entry_Fixtures::insert_entry();
		Rfrac_Entry_Fixtures::insert_entry( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );

		$out  = $this->run_cli( fn() => $this->command()->list_entries( array(), array( 'state' => 'expired', 'format' => 'json' ) ) );
		$rows = json_decode( $out['stdout'], true );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'expired', $rows[0]['state'] );

		$out = $this->run_cli( fn() => $this->command()->list_entries( array(), array( 'state' => 'bogus' ) ) );
		$this->assertSame( 'exit', $out['error'] );
		$out = $this->run_cli( fn() => $this->command()->list_entries( array(), array( 'format' => 'xml' ) ) );
		$this->assertSame( 'exit', $out['error'] );
	}

	public function test_list_pages_and_reports_the_total(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			Rfrac_Entry_Fixtures::insert_entry();
		}

		$out  = $this->run_cli( fn() => $this->command()->list_entries( array(), array( 'per-page' => 2, 'page' => 3, 'format' => 'json' ) ) );
		$rows = json_decode( $out['stdout'], true );
		$this->assertCount( 1, $rows, 'The last page holds the remaining entry.' );

		$out  = $this->run_cli( fn() => $this->command()->list_entries( array(), array( 'per-page' => 2, 'page' => 1, 'format' => 'json' ) ) );
		$rows = json_decode( $out['stdout'], true );
		$this->assertCount( 2, $rows );

		$out = $this->run_cli( fn() => $this->command()->list_entries( array(), array( 'per-page' => 2, 'page' => 2, 'format' => 'count' ) ) );
		$this->assertSame( '5', trim( $out['stdout'] ), 'The count is the total over all pages.' );

		$out  = $this->run_cli( fn() => $this->command()->list_entries( array(), array( 'per-page' => 100000, 'format' => 'json' ) ) );
		$rows = json_decode( $out['stdout'], true );
		$this->assertCount( 5, $rows, 'A page size above the maximum is clamped, not rejected.' );
	}

	public function test_list_route_filter_includes_the_subtree(): void {
		Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/posts' ) );
		Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/posts/(?P<id>[\d]+)' ) );
		Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/pages' ) );

		$out  = $this->run_cli( fn() => $this->command()->list_entries( array(), array( 'route' => '/wp/v2/posts', 'format' => 'json' ) ) );
		$rows = json_decode( $out['stdout'], true );

		$routes = array_column( $rows, 'route' );
		sort( $routes );
		$this->assertSame( array( '/wp/v2/posts', '/wp/v2/posts/(?P<id>[\d]+)' ), $routes );
	}

	// ---------- status ----------

	public function test_status_json_includes_mu_mode_and_store_type(): void {
		$out  = $this->run_cli( fn() => $this->command()->status( array(), array( 'format' => 'json' ) ) );
		$data = json_decode( $out['stdout'], true );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'mu_mode', $data );
		$this->assertArrayHasKey( 'store_type', $data );
		$this->assertTrue( $data['tables_present'] );
		$this->assertSame( 0, $data['entries_total'] );
		$this->assertSame( 'early' === $data['mu_mode'], false === strpos( $out['stderr'], 'must-use loader' ), 'A warning exactly when the loader is not early.' );
		$this->assertStringNotContainsString( 'tables are missing', $out['stderr'] );
	}

	public function test_status_table_has_key_value_rows(): void {
		$out = $this->run_cli( fn() => $this->command()->status( array(), array() ) );

		$this->assertNull( $out['error'] );
		$this->assertStringContainsString( 'mu_mode', $out['stdout'] );
		$this->assertStringContainsString( 'store_type', $out['stdout'] );
	}

	/**
	 * A command whose status snapshot has overridden fields.
	 *
	 * @param array<string,mixed> $override Status fields.
	 *
	 * @return Command
	 */
	private function command_with_status( array $override ): Command {
		$plugin = Plugin::instance();
		$real   = $plugin->status_service()->get( true );
		$status = new Status( ...array_merge( get_object_vars( $real ), $override ) );
		$stub   = new class( $status ) extends Status_Service {
			/**
			 * Snapshot.
			 *
			 * @var Status
			 */
			private Status $snapshot;

			public function __construct( Status $snapshot ) {
				$this->snapshot = $snapshot;
			}

			public function get( bool $check_tables = false ): Status {
				return $this->snapshot;
			}
		};
		return new Command( $plugin->cache_service(), $plugin->route_service(), $stub, new Network_Iterator( $plugin->basename() ) );
	}

	public function test_status_warns_when_the_tables_are_missing(): void {
		$out = $this->run_cli( fn() => $this->command_with_status( array( 'tables_present' => false ) )->status( array(), array( 'format' => 'json' ) ) );

		$this->assertNull( $out['error'] );
		$this->assertStringContainsString( 'The cache tables are missing. Deactivate and reactivate the plugin to create them.', $out['stderr'] );
		$this->assertFalse( json_decode( $out['stdout'], true )['tables_present'] );
	}

	public function test_status_warns_exactly_when_the_loader_is_not_early(): void {
		$out = $this->run_cli( fn() => $this->command_with_status( array( 'mu_mode' => 'fallback' ) )->status( array(), array( 'format' => 'json' ) ) );
		$this->assertStringContainsString( 'The must-use loader is not active (mode: fallback)', $out['stderr'] );

		$out = $this->run_cli( fn() => $this->command_with_status( array( 'mu_mode' => 'early' ) )->status( array(), array( 'format' => 'json' ) ) );
		$this->assertStringNotContainsString( 'must-use loader', $out['stderr'] );
	}

	// ---------- prune ----------

	public function test_prune_reports_counts_and_removes_flushed_entries(): void {
		global $wpdb;
		$old = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
		Rfrac_Entry_Fixtures::insert_entry( array( 'state' => 2, 'flushed_at' => $old ) );
		Rfrac_Entry_Fixtures::insert_entry( array( 'state' => 2, 'flushed_at' => $old ) );
		$keep = Rfrac_Entry_Fixtures::insert_entry();

		$out = $this->run_cli( fn() => $this->command()->prune( array(), array() ) );

		$this->assertNull( $out['error'], $out['stderr'] );
		$this->assertMatchesRegularExpression( '/Deleted 2, evicted 0\./', $out['stdout'] );
		$this->assertSame( 0, Plugin::instance()->cache_service()->stats()['flushed'] );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $keep ) );
	}

	public function test_prune_with_the_lock_held_is_an_error(): void {
		Rfrac_Entry_Fixtures::insert_entry( array( 'state' => 2, 'flushed_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ) );
		add_option( Lock::OPTION, time() + 300, '', false );

		$out = $this->run_cli( fn() => $this->command()->prune( array(), array() ) );

		$this->assertSame( 'exit', $out['error'] );
		$this->assertStringContainsString( 'Maintenance is already running; this site was skipped.', $out['stderr'] );
		$this->assertStringContainsString( 'Maintenance is already running; nothing was done.', $out['stderr'] );
		$this->assertSame( 1, Plugin::instance()->cache_service()->stats()['flushed'] );
	}

	public function test_prune_cut_in_the_stale_phase_names_the_backlog(): void {
		$old = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
		for ( $i = 0; $i < 60; $i++ ) {
			Rfrac_Entry_Fixtures::insert_entry( array( 'state' => 2, 'flushed_at' => $old ) );
		}
		add_filter(
			Hooks::MAINTENANCE_ARGS,
			static function ( $args ) {
				$args['batch_size']  = 50;
				$args['max_batches'] = 1;
				return $args;
			}
		);

		$out = $this->run_cli( fn() => $this->command()->prune( array(), array() ) );

		$this->assertNull( $out['error'], $out['stderr'] );
		$this->assertStringContainsString( 'Deleted 50, evicted 0.', $out['stdout'] );
		$this->assertStringNotContainsString( 'Success: Deleted', $out['stdout'], 'An incomplete run is not reported as a success.' );
		$this->assertStringContainsString( '10 stale entries remain; run the command again.', $out['stderr'] );
	}

	public function test_prune_cut_in_the_cap_phase_names_the_entry_limit(): void {
		update_option( Names::OPTION_SETTINGS, array( 'max_entries' => 100 ) );
		for ( $i = 0; $i < 120; $i++ ) {
			Rfrac_Entry_Fixtures::insert_entry();
		}
		add_filter(
			Hooks::MAINTENANCE_ARGS,
			static function ( $args ) {
				$args['max_batches'] = 1;
				return $args;
			}
		);

		$out = $this->run_cli( fn() => $this->command()->prune( array(), array() ) );

		$this->assertNull( $out['error'], $out['stderr'] );
		$this->assertStringNotContainsString( 'stale entries remain', $out['stderr'] );
		$this->assertStringContainsString( 'more active entries (120) than the entry limit allows', $out['stderr'] );
	}

	public function test_prune_that_stalls_is_an_error(): void {
		update_option( Names::OPTION_SETTINGS, array( 'max_entries' => 100 ) );
		for ( $i = 0; $i < 120; $i++ ) {
			Rfrac_Entry_Fixtures::insert_entry();
		}
		$store = $this->createMock( Store_Interface::class );
		$store->method( 'prune' )->willReturn( 0 );
		$store->method( 'enforce_cap' )->willReturn( 0 );

		$out = $this->run_cli( fn() => $this->command_with_store( $store )->prune( array(), array() ) );

		$this->assertSame( 'exit', $out['error'] );
		$this->assertStringContainsString( 'Maintenance made no progress', $out['stderr'] );
		$this->assertStringNotContainsString( 'Success', $out['stdout'] );
	}

	// ---------- config get ----------

	public function test_config_get_a_key_prints_the_value(): void {
		$out = $this->run_cli( fn() => ( new Config_Command( Plugin::instance()->settings_service() ) )->get( array( 'default_ttl' ), array() ) );

		$this->assertSame( '86400', trim( $out['stdout'] ) );
	}

	public function test_config_get_unknown_key_is_an_error(): void {
		$out = $this->run_cli( fn() => ( new Config_Command( Plugin::instance()->settings_service() ) )->get( array( 'nope' ), array() ) );

		$this->assertSame( 'exit', $out['error'] );
		$this->assertStringContainsString( 'Unknown setting: nope', $out['stderr'] );
	}

	public function test_config_get_lists_keys_with_their_source(): void {
		update_option( Names::OPTION_SETTINGS, array( 'max_params' => 12 ) );

		$out  = $this->run_cli( fn() => ( new Config_Command( Plugin::instance()->settings_service() ) )->get( array(), array( 'format' => 'json' ) ) );
		$rows = array_column( json_decode( $out['stdout'], true ), null, 'key' );

		$this->assertSame( 'stored', $rows['max_params']['source'] );
		$this->assertSame( 12, $rows['max_params']['value'] );
		$this->assertSame( 'default', $rows['default_ttl']['source'] );
		$this->assertSame( array( 'key', 'value', 'source' ), array_keys( $rows['default_ttl'] ) );
	}

	public function test_config_get_marks_a_constant_source(): void {
		$service = $this->createMock( \Rockfire\Rest_Api_Cache\Services\Settings_Service::class );
		$service->method( 'sources' )->willReturn( array( 'default_ttl' => 'constant:RFRAC_DEFAULT_TTL' ) );
		$service->method( 'view' )->willReturn(
			array(
				'default_ttl' => array(
					'value'    => 300,
					'locked'   => true,
					'constant' => 'RFRAC_DEFAULT_TTL',
				),
			)
		);

		$out  = $this->run_cli( fn() => ( new Config_Command( $service ) )->get( array(), array( 'format' => 'json' ) ) );
		$rows = json_decode( $out['stdout'], true );

		$this->assertSame( 'constant:RFRAC_DEFAULT_TTL', $rows[0]['source'] );
	}

	public function test_config_get_source_of_invalid_clamped_and_stored_values(): void {
		update_option(
			Names::OPTION_SETTINGS,
			array(
				'max_params'     => 500,
				'max_entries'    => 'lots',
				'hit_sampling'   => '12',
				'max_payload_kb' => 2,
			)
		);

		$out  = $this->run_cli( fn() => ( new Config_Command( Plugin::instance()->settings_service() ) )->get( array(), array( 'format' => 'json' ) ) );
		$rows = array_column( json_decode( $out['stdout'], true ), null, 'key' );

		$this->assertSame( 'clamped', $rows['max_params']['source'] );
		$this->assertSame( 100, $rows['max_params']['value'] );
		$this->assertSame( 'default', $rows['max_entries']['source'], 'An invalid stored value falls back to the default.' );
		$this->assertSame( 10000, $rows['max_entries']['value'] );
		$this->assertSame( 'stored', $rows['hit_sampling']['source'] );
		$this->assertSame( 'stored', $rows['max_payload_kb']['source'] );
		$this->assertSame( 'default', $rows['default_ttl']['source'] );
	}

	public function test_config_get_lists_the_request_lists_as_arrays_in_json(): void {
		update_option( Names::OPTION_SETTINGS, array( 'global_ignore_params' => array( 'utm_source', 'fbclid' ) ) );

		$out  = $this->run_cli( fn() => ( new Config_Command( Plugin::instance()->settings_service() ) )->get( array(), array( 'format' => 'json' ) ) );
		$rows = array_column( json_decode( $out['stdout'], true ), null, 'key' );

		$this->assertSame( array( 'utm_source', 'fbclid' ), $rows['global_ignore_params']['value'] );
		$this->assertSame( 'stored', $rows['global_ignore_params']['source'] );
		$this->assertSame( array(), $rows['global_vary_headers']['value'] );
		$this->assertSame( 'default', $rows['global_vary_headers']['source'] );
	}

	public function test_config_get_shows_a_list_as_comma_separated_text_in_a_table(): void {
		update_option( Names::OPTION_SETTINGS, array( 'global_ignore_params' => array( 'utm_source', 'fbclid' ) ) );
		$command = new Config_Command( Plugin::instance()->settings_service() );
		$method  = new ReflectionMethod( Config_Command::class, 'setting_rows' );

		$rows = array_column( $method->invoke( $command, 'table' ), null, 'key' );
		$this->assertSame( 'utm_source, fbclid', $rows['global_ignore_params']['value'] );
		$this->assertSame( 'stored', $rows['global_ignore_params']['source'] );
		$this->assertSame( '', $rows['global_vary_headers']['value'], 'An empty list is an empty cell.' );
		$this->assertSame( 86400, $rows['default_ttl']['value'] );

		$rows = array_column( $method->invoke( $command, 'json' ), null, 'key' );
		$this->assertSame( array( 'utm_source', 'fbclid' ), $rows['global_ignore_params']['value'] );
		$rows = array_column( $method->invoke( $command, 'yaml' ), null, 'key' );
		$this->assertSame( array(), $rows['global_vary_headers']['value'] );
	}

	public function test_config_get_a_list_key_prints_the_array(): void {
		update_option( Names::OPTION_SETTINGS, array( 'global_vary_headers' => 'Accept-Language' ) );

		$out = $this->run_cli( fn() => ( new Config_Command( Plugin::instance()->settings_service() ) )->get( array( 'global_vary_headers' ), array( 'format' => 'json' ) ) );

		$this->assertSame( array( 'accept-language' ), json_decode( $out['stdout'], true ) );
	}

	public function test_config_get_compiled_false_is_not_compiled(): void {
		$this->set_compiled_config();

		$out = $this->run_cli( fn() => ( new Config_Command( Plugin::instance()->settings_service() ) )->get( array( 'default_ttl' ), array( 'compiled' => 'false' ) ) );

		$this->assertSame( '86400', trim( $out['stdout'] ) );
	}

	public function test_config_get_compiled_json_is_valid(): void {
		$this->set_compiled_config();

		$out  = $this->run_cli( fn() => ( new Config_Command( Plugin::instance()->settings_service() ) )->get( array(), array( 'compiled' => true, 'format' => 'json' ) ) );
		$data = json_decode( $out['stdout'], true );

		$this->assertIsArray( $data );
		$this->assertNotSame( array(), $data );
		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
	}

	// ---------- registration ----------

	/**
	 * Options each command method documents (the synopsis WP-CLI registers).
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string[]}> Label => class, method, subcommand, option names.
	 */
	public static function documented_commands(): array {
		return array(
			'flush'         => array( Command::class, 'flush', 'flush', array( 'all', 'tag', 'request-url', 'route', 'network' ) ),
			'list'          => array( Command::class, 'list_entries', 'list', array( 'route', 'state', 'search', 'per-page', 'page', 'fields', 'format', 'network' ) ),
			'status'        => array( Command::class, 'status', 'status', array( 'format' ) ),
			'prune'         => array( Command::class, 'prune', 'prune', array( 'network' ) ),
			'import-legacy' => array( Command::class, 'import_legacy', 'import-legacy', array( 'dry-run', 'network', 'format' ) ),
			'config get'    => array( Config_Command::class, 'get', 'get', array( 'compiled', 'format' ) ),
		);
	}

	/**
	 * The docblock of every command parses into the synopsis WP-CLI would register, with the documented options.
	 *
	 * @dataProvider documented_commands
	 *
	 * @param string   $class      Command class.
	 * @param string   $method     Method.
	 * @param string   $subcommand Expected subcommand name.
	 * @param string[] $options    Option names the synopsis must declare.
	 */
	public function test_command_docblocks_parse_into_the_registered_synopsis( string $class, string $method, string $subcommand, array $options ): void {
		$doc = ( new ReflectionMethod( $class, $method ) )->getDocComment();
		$this->assertIsString( $doc );
		$parser = new \WP_CLI\DocParser( $doc );

		$this->assertSame( $subcommand, $parser->get_tag( 'subcommand' ) );
		$this->assertNotSame( '', $parser->get_shortdesc() );
		// WP-CLI builds the synopsis from the OPTIONS section of the long description.
		$synopsis = ( new ReflectionMethod( \WP_CLI\Dispatcher\Subcommand::class, 'extract_synopsis' ) )->invoke( null, $parser->get_longdesc() );
		$this->assertNotSame( '', $synopsis );
		$params = \WP_CLI\SynopsisParser::parse( $synopsis );
		$names  = array();
		foreach ( $params as $param ) {
			$names[] = $param['name'] ?? '';
			$this->assertNotSame( 'unknown', $param['type'], 'Every documented parameter is a valid synopsis token.' );
		}
		foreach ( $options as $option ) {
			$this->assertContains( $option, $names, "$method documents --$option." );
		}
		$this->assertNotContains( 'url', $names, 'WP-CLI reserves --url.' );
	}

	public function test_commands_are_only_registered_under_wp_cli(): void {
		$this->assertFalse( defined( 'WP_CLI' ) && WP_CLI, 'The test environment is not WP-CLI.' );
		Plugin::instance()->register_cli_commands();
		$this->assertTrue( true, 'A no-op without WP-CLI (no fatal error on the missing class).' );
	}
}
