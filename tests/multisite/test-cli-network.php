<?php
/**
 * Multisite tests for the `--network` flag of the WP-CLI commands.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Cli\Command;
use Rockfire\Rest_Api_Cache\Cli\Network_Iterator;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Maintenance\Lock;
use Rockfire\Rest_Api_Cache\Maintenance\Maintenance;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Services\Cache_Service;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Interface;

require_once dirname( __DIR__, 2 ) . '/vendor/wp-cli/wp-cli/php/utils.php';

/**
 * @covers \Rockfire\Rest_Api_Cache\Cli\Network_Iterator
 * @covers \Rockfire\Rest_Api_Cache\Cli\Command
 * @group multisite
 */
class Test_Cli_Network extends Rfrac_Test_Case {

	/**
	 * Execution logger.
	 *
	 * @var \WP_CLI\Loggers\Execution
	 */
	private $logger;

	/**
	 * Original network-active plugins.
	 *
	 * @var mixed
	 */
	private $sitewide;

	/**
	 * Original `active_plugins` of the main site.
	 *
	 * @var mixed
	 */
	private $active_plugins;

	/**
	 * Second and third site.
	 *
	 * @var int
	 */
	private int $blog_2 = 0;

	/**
	 * Third site, where the plugin stays inactive.
	 *
	 * @var int
	 */
	private int $blog_3 = 0;

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run via composer test-multisite.' );
		}
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->sitewide = get_site_option( 'active_sitewide_plugins', array() );
		$this->active_plugins = get_option( 'active_plugins', array() );
		$this->blog_2   = (int) self::factory()->blog->create();
		$this->blog_3   = (int) self::factory()->blog->create();
		foreach ( array( $this->blog_2, $this->blog_3 ) as $blog_id ) {
			switch_to_blog( $blog_id );
			( new \Rockfire\Rest_Api_Cache\Schema\Migrator() )->install_site();
			restore_current_blog();
		}
		// Other tests leave sites behind (some with dropped tables); only the sites of this test count.
		$sites = array( 1, $this->blog_2, $this->blog_3 );
		add_filter(
			'sites_pre_query',
			static fn( $result, $query ) => ( 'ids' === ( $query->query_vars['fields'] ?? '' ) ) ? $sites : $result,
			10,
			2
		);
		$this->logger = new \WP_CLI\Loggers\Execution();
		\WP_CLI::set_logger( $this->logger );
		( new ReflectionClass( 'WP_CLI' ) )->getProperty( 'capture_exit' )->setValue( null, true );
	}

	public function tear_down() {
		( new ReflectionClass( 'WP_CLI' ) )->getProperty( 'capture_exit' )->setValue( null, false );
		global $wpdb;
		update_site_option( 'active_sitewide_plugins', $this->sitewide );
		remove_all_filters( Hooks::MAINTENANCE_ARGS );

		// The main site: remove the rows the test created and restore its active plugins.
		switch_to_blog( 1 );
		delete_option( Lock::OPTION );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'rfrac_tags' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'rfrac_entries' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		update_option( 'active_plugins', $this->active_plugins );
		restore_current_blog();

		// The created sites: wpmu_delete_blog() does not know the cache tables, so drop them explicitly.
		foreach ( array( $this->blog_2, $this->blog_3 ) as $blog_id ) {
			if ( $blog_id <= 1 ) {
				continue;
			}
			switch_to_blog( $blog_id );
			foreach ( array( Names::TABLE_TAGS, Names::TABLE_ENTRIES ) as $table ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			restore_current_blog();
			wpmu_delete_blog( $blog_id, true );
		}
		$this->restore_schema_state();
		parent::tear_down();
	}

	/**
	 * Make the plugin network-active, or active on given sites only.
	 *
	 * @param int[]|null $sites Sites with the plugin in their own `active_plugins`; null for network-active.
	 */
	private function activate( ?array $sites ): void {
		$basename = plugin_basename( RFRAC_FILE );
		if ( null === $sites ) {
			update_site_option( 'active_sitewide_plugins', array( $basename => time() ) );
			return;
		}
		update_site_option( 'active_sitewide_plugins', array() );
		foreach ( $sites as $blog_id ) {
			switch_to_blog( $blog_id );
			update_option( 'active_plugins', array( $basename ) );
			restore_current_blog();
		}
	}

	private function command(): Command {
		$plugin = Plugin::instance();
		return new Command( $plugin->cache_service(), $plugin->route_service(), $plugin->status_service(), new Network_Iterator( $plugin->basename() ) );
	}

	/**
	 * Run a callback and capture the output.
	 *
	 * @param callable $callback Callback.
	 *
	 * @return array{stdout:string,stderr:string,error:bool}
	 */
	private function run_cli( callable $callback ): array {
		$this->logger->stdout = '';
		$this->logger->stderr = '';
		$error                = false;
		$this->logger->ob_start();
		try {
			$callback();
		} catch ( \WP_CLI\ExitException $e ) {
			$error = true;
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
	 * State of an entry on a site.
	 *
	 * @param int $blog_id Site.
	 * @param int $id      Entry.
	 *
	 * @return string
	 */
	private function state_on( int $blog_id, int $id ): string {
		switch_to_blog( $blog_id );
		$state = Rfrac_Entry_Fixtures::get_state( $id );
		restore_current_blog();
		return $state;
	}

	private function entry_on( int $blog_id ): int {
		switch_to_blog( $blog_id );
		$id = Rfrac_Entry_Fixtures::insert_entry();
		restore_current_blog();
		return $id;
	}

	public function test_flush_all_network_flushes_every_site_and_restores_the_blog(): void {
		$this->activate( null );
		$one   = $this->entry_on( 1 );
		$two   = $this->entry_on( $this->blog_2 );
		$three = $this->entry_on( $this->blog_3 );

		$out = $this->run_cli( fn() => $this->command()->flush( array(), array( 'all' => true, 'network' => true ) ) );

		$this->assertFalse( $out['error'], $out['stderr'] );
		foreach ( array( 1 => $one, $this->blog_2 => $two, $this->blog_3 => $three ) as $blog_id => $id ) {
			$this->assertSame( '2', $this->state_on( $blog_id, $id ), "Site $blog_id flushed." );
			switch_to_blog( $blog_id );
			$this->assertStringContainsString( '[' . site_url() . '] Flushed 1 cached responses.', $out['stdout'] );
			restore_current_blog();
		}
		$this->assertStringContainsString( 'Flushed 3 cached responses in total.', $out['stdout'] );
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertFalse( ms_is_switched() );
	}

	public function test_network_skips_sites_where_the_plugin_is_inactive(): void {
		$this->activate( array( 1, $this->blog_2 ) );
		$one   = $this->entry_on( 1 );
		$two   = $this->entry_on( $this->blog_2 );
		$three = $this->entry_on( $this->blog_3 );

		$out = $this->run_cli( fn() => $this->command()->flush( array(), array( 'all' => true, 'network' => true ) ) );

		$this->assertSame( '2', $this->state_on( 1, $one ) );
		$this->assertSame( '2', $this->state_on( $this->blog_2, $two ) );
		$this->assertSame( '1', $this->state_on( $this->blog_3, $three ), 'Inactive site untouched.' );
		$this->assertStringContainsString( 'Flushed 2 cached responses in total.', $out['stdout'] );
	}

	public function test_without_network_only_the_current_site_is_flushed(): void {
		$this->activate( null );
		$one = $this->entry_on( 1 );
		$two = $this->entry_on( $this->blog_2 );

		$this->run_cli( fn() => $this->command()->flush( array(), array( 'all' => true ) ) );

		$this->assertSame( '2', $this->state_on( 1, $one ) );
		$this->assertSame( '1', $this->state_on( $this->blog_2, $two ) );
	}

	public function test_list_network_adds_the_blog_id(): void {
		$this->activate( null );
		$this->entry_on( 1 );
		$this->entry_on( $this->blog_2 );

		$out  = $this->run_cli( fn() => $this->command()->list_entries( array(), array( 'network' => true, 'format' => 'json' ) ) );
		$rows = json_decode( $out['stdout'], true );

		$this->assertCount( 2, $rows );
		$this->assertSame( array( 1, $this->blog_2 ), array_map( 'intval', array_column( $rows, 'blog_id' ) ) );
		$this->assertSame( 'blog_id', array_key_first( $rows[0] ) );

		$out = $this->run_cli( fn() => $this->command()->list_entries( array(), array( 'network' => true, 'format' => 'count' ) ) );
		$this->assertSame( '2', trim( $out['stdout'] ) );
	}

	public function test_prune_network_reports_per_site_and_in_total(): void {
		$this->activate( null );
		$old = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
		foreach ( array( 1, $this->blog_2 ) as $blog_id ) {
			switch_to_blog( $blog_id );
			Rfrac_Entry_Fixtures::insert_entry( array( 'state' => 2, 'flushed_at' => $old ) );
			restore_current_blog();
		}

		$out = $this->run_cli( fn() => $this->command()->prune( array(), array( 'network' => true ) ) );

		$this->assertFalse( $out['error'], $out['stderr'] );
		$this->assertStringContainsString( 'Deleted 2, evicted 0 in total.', $out['stdout'] );
		$sites = count( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) );
		$this->assertSame( $sites + 1, substr_count( $out['stdout'], 'Deleted ' ), 'One line per site and the total.' );
		$this->assertSame( 1, get_current_blog_id() );
	}

	public function test_the_blog_is_restored_when_the_callback_throws(): void {
		$this->activate( null );
		$iterator = new Network_Iterator( plugin_basename( RFRAC_FILE ) );

		try {
			$iterator->each(
				static function (): void {
					throw new \RuntimeException( 'boom' );
				}
			);
			$this->fail( 'Expected exception.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertSame( 1, get_current_blog_id() );
		$this->assertFalse( ms_is_switched() );
	}

	/**
	 * Run a callback on a site and return its result.
	 *
	 * @param int      $blog_id  Site.
	 * @param callable $callback Callback.
	 *
	 * @return mixed
	 */
	private function on_site( int $blog_id, callable $callback ): mixed {
		switch_to_blog( $blog_id );
		try {
			return $callback();
		} finally {
			restore_current_blog();
		}
	}

	public function test_each_returns_the_number_of_sites_visited(): void {
		$sites = count( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) );
		$this->activate( null );
		$this->assertSame( $sites, ( new Network_Iterator( plugin_basename( RFRAC_FILE ) ) )->each( static function (): void {} ) );

		$this->activate( array( $this->blog_2 ) );
		$this->on_site( 1, static fn() => update_option( 'active_plugins', array() ) );
		$this->assertSame( 1, ( new Network_Iterator( plugin_basename( RFRAC_FILE ) ) )->each( static function (): void {} ) );
	}

	public function test_network_flag_errors_when_the_plugin_is_active_on_no_site(): void {
		$this->activate( array() );
		$this->on_site( 1, static fn() => update_option( 'active_plugins', array() ) );

		foreach ( array( 'flush' => array( 'all' => true ), 'prune' => array(), 'list_entries' => array() ) as $method => $assoc ) {
			$assoc['network'] = true;
			$out              = $this->run_cli( fn() => $this->command()->$method( array(), $assoc ) );
			$this->assertTrue( $out['error'], $method );
			$this->assertStringContainsString( 'The plugin is not active on any site of the network.', $out['stderr'], $method );
		}
		$this->assertSame( 1, get_current_blog_id() );
	}

	public function test_flush_request_url_network_flushes_only_the_site_that_owns_the_url(): void {
		$this->activate( null );
		$url     = $this->on_site( $this->blog_2, static fn() => rest_url( 'wp/v2/posts' ) );
		$target = $this->on_site(
			$this->blog_2,
			static function () use ( $url ): int {
				$context = Request_Context::from_url( $url, rest_get_url_prefix() );
				return Rfrac_Entry_Fixtures::insert_entry( array( 'url_hash' => Cache_Key::url_hash( $context, $context->params ) ) );
			}
		);
		$other  = $this->on_site( $this->blog_3, fn() => Rfrac_Entry_Fixtures::insert_entry() );
		$main   = $this->entry_on( 1 );

		$out = $this->run_cli( fn() => $this->command()->flush( array(), array( 'request-url' => $url, 'network' => true ) ) );

		$this->assertFalse( $out['error'], $out['stderr'] );
		$this->assertSame( '', $out['stderr'], 'Sites that do not own the URL are skipped without a warning.' );
		$this->assertSame( '2', $this->state_on( $this->blog_2, $target ) );
		$this->assertSame( '1', $this->state_on( $this->blog_3, $other ) );
		$this->assertSame( '1', $this->state_on( 1, $main ) );
		$this->assertStringContainsString( 'Flushed 1 cached responses in total.', $out['stdout'] );
		$this->assertSame( 1, get_current_blog_id() );
	}

	public function test_flush_request_url_network_errors_when_no_site_accepts_the_url(): void {
		$this->activate( null );

		$out = $this->run_cli( fn() => $this->command()->flush( array(), array( 'request-url' => 'https://nowhere.invalid/wp-json/wp/v2/posts', 'network' => true ) ) );

		$this->assertTrue( $out['error'] );
		$this->assertStringContainsString( 'No site of the network accepts this URL', $out['stderr'] );
		$this->assertStringNotContainsString( 'Warning', $out['stderr'], 'No warning per site.' );
		$this->assertSame( 1, get_current_blog_id() );
	}

	public function test_flush_tag_network_validates_the_tag_once(): void {
		$this->activate( null );
		$one = $this->entry_on( 1 );

		$out = $this->run_cli( fn() => $this->command()->flush( array(), array( 'tag' => 'bad tag!', 'network' => true ) ) );

		$this->assertTrue( $out['error'] );
		$this->assertSame( 1, substr_count( $out['stderr'], 'is not a valid tag' ) );
		$this->assertSame( '1', $this->state_on( 1, $one ) );
	}

	public function test_flush_network_failure_warns_per_site_errors_and_restores_the_blog(): void {
		$this->activate( null );
		$sites = count( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) );

		$out = $this->run_cli( fn() => $this->command()->flush( array(), array( 'route' => '/nope/v1/missing', 'network' => true ) ) );

		$this->assertTrue( $out['error'] );
		$this->assertSame( $sites, substr_count( $out['stderr'], 'Unknown route: /nope/v1/missing' ) );
		$this->assertStringContainsString( 'The flush failed on ' . $sites . ' site(s).', $out['stderr'] );
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertFalse( ms_is_switched() );
	}

	public function test_prune_network_counts_sites_skipped_because_maintenance_is_running(): void {
		$this->activate( null );
		$old = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
		$this->on_site( 1, static fn() => Rfrac_Entry_Fixtures::insert_entry( array( 'state' => 2, 'flushed_at' => $old ) ) );
		$this->on_site( $this->blog_2, static fn() => add_option( Lock::OPTION, time() + 300, '', false ) );

		$out = $this->run_cli( fn() => $this->command()->prune( array(), array( 'network' => true ) ) );

		$this->assertFalse( $out['error'], $out['stderr'] );
		$this->assertStringContainsString( 'Deleted 1, evicted 0 in total.', $out['stdout'] );
		$this->assertStringContainsString( '1 site(s) skipped because maintenance was already running.', $out['stdout'] );
		$this->assertStringContainsString( 'Maintenance is already running; this site was skipped.', $out['stderr'] );
		$this->on_site( $this->blog_2, static fn() => delete_option( Lock::OPTION ) );
	}

	public function test_prune_network_with_every_site_skipped_is_an_error(): void {
		$this->activate( array( $this->blog_2 ) );
		$this->on_site( 1, static fn() => update_option( 'active_plugins', array() ) );
		$this->on_site( $this->blog_2, static fn() => add_option( Lock::OPTION, time() + 300, '', false ) );

		$out = $this->run_cli( fn() => $this->command()->prune( array(), array( 'network' => true ) ) );

		$this->assertTrue( $out['error'] );
		$this->assertStringContainsString( 'Maintenance is already running; nothing was done.', $out['stderr'] );
		$this->on_site( $this->blog_2, static fn() => delete_option( Lock::OPTION ) );
	}

	public function test_prune_network_that_stalls_is_an_error(): void {
		$this->activate( array( $this->blog_2 ) );
		$this->on_site( 1, static fn() => update_option( 'active_plugins', array() ) );
		$this->on_site(
			$this->blog_2,
			static function (): void {
				update_option( Names::OPTION_SETTINGS, array( 'max_entries' => 100 ) );
				for ( $i = 0; $i < 120; $i++ ) {
					Rfrac_Entry_Fixtures::insert_entry();
				}
			}
		);
		$store = $this->createMock( Store_Interface::class );
		$store->method( 'prune' )->willReturn( 0 );
		$store->method( 'enforce_cap' )->willReturn( 0 );
		$plugin      = Plugin::instance();
		$repository  = new Repository();
		$maintenance = new Maintenance( $repository, $store, Config::instance(), new Lock() );
		$command     = new Command( new Cache_Service( $repository, $store, $maintenance ), $plugin->route_service(), $plugin->status_service(), new Network_Iterator( $plugin->basename() ) );

		$out = $this->run_cli( fn() => $command->prune( array(), array( 'network' => true ) ) );

		$this->assertTrue( $out['error'] );
		$this->assertStringContainsString( 'Maintenance made no progress', $out['stderr'] );
		$this->assertStringNotContainsString( 'Success', $out['stdout'] );
		$this->assertSame( 1, get_current_blog_id() );
	}
}
