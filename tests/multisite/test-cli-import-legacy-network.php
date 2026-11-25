<?php
/**
 * Multisite tests for `wp rfrac import-legacy --network`: one launched process per site where the plugin is active.
 *
 * The launcher is a fake that records the command lines and answers with the report each process would print, so
 * no process is started and no site is imported in this process.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Cli\Network_Iterator;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Legacy\Import_Cli;
use Rockfire\Rest_Api_Cache\Legacy\Import_Report;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;

require_once dirname( __DIR__, 2 ) . '/vendor/wp-cli/wp-cli/php/utils.php';

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Import_Cli
 * @covers \Rockfire\Rest_Api_Cache\Cli\Network_Iterator
 * @group multisite
 */
class Test_Cli_Import_Legacy_Network extends Rfrac_Test_Case {

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
	 * Second site, where the plugin stays inactive.
	 *
	 * @var int
	 */
	private int $blog_2 = 0;

	/**
	 * Third site.
	 *
	 * @var int
	 */
	private int $blog_3 = 0;

	/**
	 * The command lines the fake launcher received.
	 *
	 * @var string[]
	 */
	private array $launched = array();

	/**
	 * Answers the fake launcher gives, by site (domain and path); a site without one gets a dry-run report.
	 *
	 * @var array<string,mixed>
	 */
	private array $answers = array();

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run via composer test-multisite.' );
		}
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->sitewide       = get_site_option( 'active_sitewide_plugins', array() );
		$this->active_plugins = get_option( 'active_plugins', array() );
		$this->blog_2         = (int) self::factory()->blog->create();
		$this->blog_3         = (int) self::factory()->blog->create();
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

		$this->launched = array();
		$this->answers  = array();
		// The plugin is active on the main site and on the third site; the second site is left out.
		$this->activate( array( 1, $this->blog_3 ) );
		// Every site runs the original, so an import in this process would change what the sites hold.
		foreach ( array( 1, $this->blog_2, $this->blog_3 ) as $blog_id ) {
			$this->on_site( $blog_id, static fn() => Rfrac_Legacy_Fixture::install_stock() );
		}
	}

	public function tear_down() {
		( new ReflectionClass( 'WP_CLI' ) )->getProperty( 'capture_exit' )->setValue( null, false );
		global $wpdb;
		update_site_option( 'active_sitewide_plugins', $this->sitewide );

		// The main site: remove what the test created and restore its active plugins.
		switch_to_blog( 1 );
		Rfrac_Legacy_Fixture::clear();
		delete_option( Names::OPTION_LEGACY_FINDINGS );
		delete_option( Failure_Recorder::OPTION );
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
	 * Make the plugin active on given sites only.
	 *
	 * @param int[] $sites Sites with the plugin in their own `active_plugins`.
	 */
	private function activate( array $sites ): void {
		$basename = plugin_basename( RFRAC_FILE );
		update_site_option( 'active_sitewide_plugins', array() );
		foreach ( $sites as $blog_id ) {
			switch_to_blog( $blog_id );
			update_option( 'active_plugins', array( $basename ) );
			restore_current_blog();
		}
	}

	/**
	 * Run a callback on a site.
	 *
	 * @param int      $blog_id  Site.
	 * @param callable $callback Callback.
	 */
	private function on_site( int $blog_id, callable $callback ): void {
		switch_to_blog( $blog_id );
		$callback();
		restore_current_blog();
	}

	/**
	 * The site of a blog ID as domain and path, the way the import names it.
	 *
	 * @param int $blog_id Site.
	 *
	 * @return string
	 */
	private function label( int $blog_id ): string {
		$site = get_site( $blog_id );
		$this->assertInstanceOf( WP_Site::class, $site );
		return $site->domain . $site->path;
	}

	/**
	 * What every site holds that an import changes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function snapshot(): array {
		$snapshot = array();
		foreach ( array( 1, $this->blog_2, $this->blog_3 ) as $blog_id ) {
			switch_to_blog( $blog_id );
			$snapshot[ $blog_id ] = array(
				Names::OPTION_SETTINGS        => get_option( Names::OPTION_SETTINGS ),
				Names::OPTION_CONFIG          => get_option( Names::OPTION_CONFIG ),
				Names::OPTION_LEGACY_FINDINGS => get_option( Names::OPTION_LEGACY_FINDINGS ),
			);
			restore_current_blog();
		}
		return $snapshot;
	}

	/**
	 * The report a process of a site prints.
	 *
	 * @param string $site    Site, as domain and path.
	 * @param bool   $dry_run Whether the process runs with `--dry-run`.
	 * @param string $result  The result of the import.
	 *
	 * @return array<string,mixed>
	 */
	private function report( string $site, bool $dry_run, string $result = Import_Report::RESULT_DRY_RUN ): array {
		$nothing = Import_Report::RESULT_NOTHING_FOUND === $result;
		return array(
			'dry_run'  => $dry_run,
			'result'   => $result,
			'counts'   => array(
				'imported'    => $nothing ? 0 : 1,
				'kept'        => 0,
				'adjusted'    => 0,
				'skipped'     => 0,
				'unsupported' => $nothing ? 0 : 2,
			),
			'findings' => $nothing ? array() : array(
				array(
					'status'  => 'imported',
					'code'    => 'default_ttl',
					'subject' => 'subject-of-' . $site,
					'message' => 'Message of ' . $site,
				),
			),
			'site'     => $site,
		);
	}

	/**
	 * The process result the fake launcher gives for a command.
	 *
	 * @param string $command The command line.
	 *
	 * @return object
	 */
	private function launch( string $command ): object {
		$this->launched[] = $command;
		$this->assertSame( 1, preg_match( '#--url=(\S+)#', $command, $match ), 'The command names the site: ' . $command );
		$site = $match[1];
		if ( isset( $this->answers[ $site ] ) ) {
			return (object) $this->answers[ $site ];
		}
		return (object) array(
			'stdout'      => (string) wp_json_encode( $this->report( $site, str_contains( $command, '--dry-run' ) ) ),
			'stderr'      => '',
			'return_code' => 0,
		);
	}

	/**
	 * The import command over the fake launcher.
	 *
	 * @return Import_Cli
	 */
	private function import_cli(): Import_Cli {
		$plugin = Plugin::instance();
		return new Import_Cli( $plugin->legacy_importer(), new Network_Iterator( $plugin->basename() ), fn( string $command ) => $this->launch( $command ) );
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
	 * AC-5: a dry run over the network launches one process per site with the plugin active, and imports nothing here.
	 */
	public function test_a_dry_run_launches_one_process_per_active_site_and_changes_nothing(): void {
		$before = $this->snapshot();

		$out = $this->run_cli( fn() => $this->import_cli()->run( array( 'network' => true, 'dry-run' => true ) ) );

		$this->assertFalse( $out['error'], $out['stderr'] );
		$this->assertCount( 2, $this->launched, 'Sites 1 and 3 are launched; site 2 does not run the plugin.' );
		foreach ( array( 1, $this->blog_3 ) as $index => $blog_id ) {
			$command = $this->launched[ $index ];
			$this->assertStringStartsWith( 'rfrac import-legacy --url=' . $this->label( $blog_id ) . ' --path=', $command );
			$this->assertStringContainsString( '--path=', $command );
			$this->assertStringContainsString( '--format=json --dry-run', $command );
			$this->assertStringNotContainsString( '--network', $command );
		}
		$this->assertSame( $before, $this->snapshot(), 'No site rfrac_settings, rfrac_config or rfrac_legacy_findings changed.' );
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertFalse( ms_is_switched() );
	}

	/**
	 * The result of every process is printed with its site, and added up in a total.
	 */
	public function test_the_results_are_printed_per_site_and_in_total(): void {
		$out = $this->run_cli( fn() => $this->import_cli()->run( array( 'network' => true, 'dry-run' => true ) ) );

		$one   = $this->label( 1 );
		$two   = $this->label( $this->blog_2 );
		$three = $this->label( $this->blog_3 );
		$this->assertStringContainsString( 'subject-of-' . $one, $out['stdout'] );
		$this->assertStringContainsString( 'subject-of-' . $three, $out['stdout'] );
		$this->assertStringNotContainsString( 'subject-of-' . $two, $out['stdout'] );
		$this->assertStringContainsString( 'site', $out['stdout'], 'The table starts with a site column.' );
		$summary = 'Dry run: nothing was saved. Would import 1, keep 0, adjust 0, skip 0; 2 unsupported.';
		$this->assertStringContainsString( '[' . $one . '] ' . $summary, $out['stdout'] );
		$this->assertStringContainsString( '[' . $three . '] ' . $summary, $out['stdout'] );
		$this->assertStringContainsString( 'Success: Dry run: nothing was saved. Would import 2, keep 0, adjust 0, skip 0; 4 unsupported.', $out['stdout'] );
	}

	/**
	 * Without `--dry-run` the processes import, and the command lines say so.
	 */
	public function test_an_import_launches_the_processes_without_the_dry_run_flag(): void {
		$out = $this->run_cli( fn() => $this->import_cli()->run( array( 'network' => true ) ) );

		$this->assertFalse( $out['error'], $out['stderr'] );
		$this->assertCount( 2, $this->launched );
		foreach ( $this->launched as $command ) {
			$this->assertStringEndsWith( '--format=json', $command );
			$this->assertStringNotContainsString( '--dry-run', $command );
			$this->assertStringNotContainsString( '--network', $command );
		}
		$this->assertStringContainsString( 'Success: Imported 2, kept 0, adjusted 0, skipped 0; 4 unsupported.', $out['stdout'] );
	}

	/**
	 * AC-6: a process that fails is reported by site, the other sites are still printed, and the run ends in an error.
	 */
	public function test_a_failed_site_is_named_in_the_error_and_the_others_are_printed(): void {
		$three                    = $this->label( $this->blog_3 );
		$this->answers[ $three ] = array(
			'stdout'      => '',
			'stderr'      => 'Error: The settings could not be saved.',
			'return_code' => 1,
		);

		$out = $this->run_cli( fn() => $this->import_cli()->run( array( 'network' => true, 'dry-run' => true ) ) );

		$this->assertTrue( $out['error'] );
		$this->assertCount( 2, $this->launched, 'A failing site does not stop the other sites.' );
		$this->assertStringContainsString( 'subject-of-' . $this->label( 1 ), $out['stdout'], 'Site 1 was printed.' );
		$this->assertStringContainsString( 'Warning: [' . $three . '] The import ended with exit code 1. Error: The settings could not be saved.', $out['stderr'] );
		$this->assertStringContainsString( 'Error: The import failed on 1 site(s): ' . $three . '.', $out['stderr'] );
		$this->assertStringNotContainsString( 'Success:', $out['stdout'] );
	}

	/**
	 * Output that is not the JSON report of a process counts as a failure of its site.
	 */
	public function test_output_that_is_not_a_report_is_a_failure_of_its_site(): void {
		$one                    = $this->label( 1 );
		$this->answers[ $one ] = array(
			'stdout'      => 'Deprecated: something printed before the report',
			'stderr'      => '',
			'return_code' => 0,
		);

		$out = $this->run_cli( fn() => $this->import_cli()->run( array( 'network' => true ) ) );

		$this->assertTrue( $out['error'] );
		$this->assertStringContainsString( 'Warning: [' . $one . '] The output of the import could not be read.', $out['stderr'] );
		$this->assertStringContainsString( 'Error: The import failed on 1 site(s): ' . $one . '.', $out['stderr'] );
		$this->assertStringContainsString( 'subject-of-' . $this->label( $this->blog_3 ), $out['stdout'] );
	}

	/**
	 * A site without the original is reported as such and is not a failure.
	 */
	public function test_a_site_without_the_original_is_not_a_failure(): void {
		$one                    = $this->label( 1 );
		$this->answers[ $one ] = array(
			'stdout'      => (string) wp_json_encode( $this->report( $one, false, Import_Report::RESULT_NOTHING_FOUND ) ),
			'stderr'      => 'Warning: No settings of WP REST Cache were found; there is nothing to import.',
			'return_code' => 0,
		);

		$out = $this->run_cli( fn() => $this->import_cli()->run( array( 'network' => true ) ) );

		$this->assertFalse( $out['error'], $out['stderr'] );
		$this->assertStringContainsString( '[' . $one . '] No settings of WP REST Cache were found; there is nothing to import.', $out['stdout'] );
		$this->assertStringContainsString( 'Success: Imported 1, kept 0, adjusted 0, skipped 0; 2 unsupported.', $out['stdout'] );
	}

	/**
	 * In JSON the output is the list of the reports of the sites.
	 */
	public function test_the_json_output_is_the_list_of_the_site_reports(): void {
		$out = $this->run_cli( fn() => $this->import_cli()->run( array( 'network' => true, 'dry-run' => true, 'format' => 'json' ) ) );

		$this->assertFalse( $out['error'], $out['stderr'] );
		$reports = json_decode( $out['stdout'], true );
		$this->assertIsArray( $reports, $out['stdout'] );
		$this->assertSame( array( $this->label( 1 ), $this->label( $this->blog_3 ) ), array_column( $reports, 'site' ) );
		foreach ( $reports as $report ) {
			$this->assertSame( Import_Report::RESULT_DRY_RUN, $report['result'] );
			$this->assertTrue( $report['dry_run'] );
			$this->assertCount( 1, $report['findings'] );
		}
		$this->assertStringNotContainsString( 'Success:', $out['stdout'], 'JSON holds the data only.' );
	}

	/**
	 * A network where the plugin is active on no site is an error, and nothing is launched.
	 */
	public function test_a_network_without_an_active_site_is_an_error(): void {
		foreach ( array( 1, $this->blog_3 ) as $blog_id ) {
			$this->on_site( $blog_id, static fn() => update_option( 'active_plugins', array() ) );
		}

		$out = $this->run_cli( fn() => $this->import_cli()->run( array( 'network' => true ) ) );

		$this->assertTrue( $out['error'] );
		$this->assertSame( array(), $this->launched );
		$this->assertStringContainsString( 'The plugin is not active on any site of the network.', $out['stderr'] );
	}

	/**
	 * Without `--network` only the current site is imported, in this process.
	 */
	public function test_without_network_nothing_is_launched(): void {
		$out = $this->run_cli( fn() => $this->import_cli()->run( array( 'dry-run' => true, 'format' => 'json' ) ) );

		$data = json_decode( $out['stdout'], true );
		$this->assertIsArray( $data, $out['stdout'] );
		$this->assertSame( array(), $this->launched );
		$this->assertSame( $this->label( 1 ), $data['site'] );
	}
}
