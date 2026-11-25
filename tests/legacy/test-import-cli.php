<?php
/**
 * Tests for `wp rfrac import-legacy` on one site: the import, the dry run and the refusals.
 *
 * The command runs in-process against WP-CLI's execution logger; `WP_CLI::error()` throws instead of exiting.
 * The network mode is tested in tests/multisite/test-cli-import-legacy-network.php.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Cli\Command;
use Rockfire\Rest_Api_Cache\Cli\Network_Iterator;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Settings_Schema;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Legacy\Constant_Fallback;
use Rockfire\Rest_Api_Cache\Legacy\Findings;
use Rockfire\Rest_Api_Cache\Legacy\Import_Cli;
use Rockfire\Rest_Api_Cache\Legacy\Import_Report;
use Rockfire\Rest_Api_Cache\Legacy\Importer;
use Rockfire\Rest_Api_Cache\Legacy\Original_Settings;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;

require_once dirname( __DIR__, 2 ) . '/vendor/wp-cli/wp-cli/php/utils.php';

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Import_Cli
 * @covers \Rockfire\Rest_Api_Cache\Cli\Command::import_legacy
 */
class Test_Import_Cli extends Rfrac_Test_Case {

	use Rfrac_Fails_Option_Writes;

	/**
	 * The captured WP-CLI output.
	 *
	 * @var \WP_CLI\Loggers\Execution
	 */
	private $logger;

	public function set_up() {
		parent::set_up();
		Rfrac_Legacy_Fixture::clear();
		delete_option( Names::OPTION_LEGACY_FINDINGS );
		delete_option( Failure_Recorder::OPTION );
		Config::set_fallback_constants( new Constant_Fallback() );
		// Rockfire's settings were saved once, so the import updates the option instead of adding it.
		update_option( Names::OPTION_SETTINGS, array_merge( Settings_Schema::defaults(), array( 'routes' => array() ) ), false );
		$this->new_rest_server( array( 'Rfrac_Legacy_Fixture', 'register_routes' ) );

		$this->logger = new \WP_CLI\Loggers\Execution();
		\WP_CLI::set_logger( $this->logger );
		$this->set_capture_exit( true );
	}

	public function tear_down() {
		$this->set_capture_exit( false );
		Config::set_fallback_constants( new Constant_Fallback() );
		delete_option( Failure_Recorder::OPTION );
		parent::tear_down();
	}

	/**
	 * WP_CLI::$capture_exit is private; toggle it so `WP_CLI::error()` throws instead of exiting.
	 *
	 * @param bool $value New value.
	 */
	private function set_capture_exit( bool $value ): void {
		( new ReflectionClass( 'WP_CLI' ) )->getProperty( 'capture_exit' )->setValue( null, $value );
	}

	/**
	 * A fresh importer over the shared settings.
	 *
	 * @return Importer
	 */
	private function importer(): Importer {
		return new Importer( Config::instance(), new Findings(), new Original_Settings() );
	}

	/**
	 * The import command on the real importer; the launcher fails the test when a process is launched.
	 *
	 * @return Import_Cli
	 */
	private function import_cli(): Import_Cli {
		return new Import_Cli(
			$this->importer(),
			new Network_Iterator( Plugin::instance()->basename() ),
			function ( string $command ) {
				$this->fail( 'A single-site import launches no process: ' . $command );
			}
		);
	}

	/**
	 * Run a callback and capture its output.
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
	 * The options an import may write.
	 *
	 * @return array<string,mixed>
	 */
	private function written_options(): array {
		return array(
			Names::OPTION_SETTINGS         => get_option( Names::OPTION_SETTINGS ),
			Names::OPTION_CONFIG           => get_option( Names::OPTION_CONFIG ),
			Names::OPTION_LEGACY_FINDINGS => get_option( Names::OPTION_LEGACY_FINDINGS ),
		);
	}

	/**
	 * AC-1: a dry run prints the report as JSON with the counts of the importer and saves nothing.
	 */
	public function test_a_dry_run_prints_the_report_and_saves_nothing(): void {
		Rfrac_Legacy_Fixture::install_stock();
		$before   = $this->written_options();
		$expected = $this->importer()->run( true );

		$out = $this->run_cli( fn() => $this->import_cli()->run( array( 'dry-run' => true, 'format' => 'json' ) ) );

		$this->assertNull( $out['error'], $out['stderr'] );
		$data = json_decode( $out['stdout'], true );
		$this->assertIsArray( $data, $out['stdout'] );
		$this->assertSame( Import_Report::RESULT_DRY_RUN, $data['result'] );
		$this->assertTrue( $data['dry_run'] );
		$this->assertSame( $expected->counts, $data['counts'] );
		$this->assertSame( $expected->to_array()['findings'], $data['findings'] );
		$this->assertNotSame( array(), $data['findings'] );
		$this->assertMatchesRegularExpression( '#^[^/\s]+/#', $data['site'], 'The site is its domain and path.' );
		$this->assertSame( $before, $this->written_options(), 'rfrac_settings, rfrac_config and rfrac_legacy_findings are unchanged.' );
	}

	/**
	 * A dry run in a table ends with the "would" summary.
	 */
	public function test_a_dry_run_table_ends_with_the_summary_of_what_would_happen(): void {
		Rfrac_Legacy_Fixture::install_stock();
		$counts = $this->importer()->run( true )->counts;

		$out = $this->run_cli( fn() => $this->import_cli()->run( array( 'dry-run' => true ) ) );

		$this->assertNull( $out['error'], $out['stderr'] );
		$this->assertStringContainsString( Rfrac_Legacy_Fixture::TTL_OPTIONS_LABEL, $out['stdout'] );
		$this->assertStringContainsString( 'status', $out['stdout'] );
		$this->assertStringContainsString(
			sprintf( 'Success: Dry run: nothing was saved. Would import %d, keep %d, adjust %d, skip %d; %d unsupported.', $counts['imported'], $counts['kept'], $counts['adjusted'], $counts['skipped'], $counts['unsupported'] ),
			$out['stdout']
		);
	}

	/**
	 * AC-2: an import succeeds with the summary and stores the settings.
	 */
	public function test_an_import_succeeds_with_the_summary_and_stores_the_settings(): void {
		Rfrac_Legacy_Fixture::install_stock();

		$out = $this->run_cli( fn() => $this->import_cli()->run( array() ) );

		$this->assertNull( $out['error'], $out['stderr'] );
		$this->assertMatchesRegularExpression( '/Success: Imported \d+, kept \d+, adjusted \d+, skipped \d+; \d+ unsupported\./', $out['stdout'] );
		$this->assertStringNotContainsString( 'Dry run', $out['stdout'] );
		$this->assertSame( 31536000, Config::instance()->settings()['default_ttl'] );
		$this->assertSame( Rfrac_Legacy_Fixture::ACME_ROUTES, array_keys( Config::instance()->stored_route_rules() ) );
		$group = ( new Findings() )->get( Findings::GROUP_IMPORT );
		$this->assertNotNull( $group, 'The findings of the import are stored.' );
	}

	/**
	 * The report prints as YAML and as JSON; the JSON carries the report of the importer and the site.
	 *
	 * The formatter writes a csv to the STDOUT of the process, which the in-process logger does not capture.
	 */
	public function test_the_report_prints_as_yaml_and_json(): void {
		Rfrac_Legacy_Fixture::install_stock();

		$yaml = $this->run_cli( fn() => $this->import_cli()->run( array( 'dry-run' => true, 'format' => 'yaml' ) ) );
		$this->assertStringContainsString( 'result: dry_run', $yaml['stdout'] );
		$this->assertStringNotContainsString( 'Success:', $yaml['stdout'] );

		$json = $this->run_cli( fn() => $this->import_cli()->run( array( 'format' => 'json' ) ) );
		$data = json_decode( $json['stdout'], true );
		$this->assertIsArray( $data, $json['stdout'] );
		$this->assertSame( array( 'dry_run', 'result', 'counts', 'findings', 'site' ), array_keys( $data ) );
		$this->assertContains( $data['result'], array( Import_Report::RESULT_COMPILED, Import_Report::RESULT_DEFERRED ) );
	}

	/**
	 * AC-3: without any option or constant of the original a warning is printed and the exit code is 0.
	 */
	public function test_without_legacy_data_it_warns_and_exits_with_zero(): void {
		$before = $this->written_options();

		$out = $this->run_cli( fn() => $this->import_cli()->run( array() ) );

		$this->assertNull( $out['error'], 'No exit: the exit code is 0.' );
		$this->assertStringContainsString( 'Warning: No settings of WP REST Cache were found', $out['stderr'] );
		$this->assertStringNotContainsString( 'Success:', $out['stdout'] );
		$this->assertSame( $before, $this->written_options() );
	}

	/**
	 * A site with nothing to import still prints a JSON report, so a network run can read it.
	 */
	public function test_without_legacy_data_the_json_is_still_a_report(): void {
		$out  = $this->run_cli( fn() => $this->import_cli()->run( array( 'format' => 'json' ) ) );
		$data = json_decode( $out['stdout'], true );

		$this->assertNull( $out['error'] );
		$this->assertIsArray( $data, $out['stdout'] );
		$this->assertSame( Import_Report::RESULT_NOTHING_FOUND, $data['result'] );
		$this->assertSame( array(), $data['findings'] );
	}

	/**
	 * A settings save that fails is an error that carries the reason.
	 */
	public function test_a_failed_save_is_an_error_with_the_validation_messages(): void {
		Rfrac_Legacy_Fixture::install_stock();
		$this->fail_option_writes( Names::OPTION_SETTINGS );

		$out = $this->run_cli( fn() => $this->import_cli()->run( array() ) );

		$this->assertSame( 'exit', $out['error'] );
		$this->assertStringContainsString( 'Error: The settings could not be saved:', $out['stderr'] );
		$this->assertStringContainsString( 'could not be saved to the database', $out['stderr'] );
		$this->assertStringNotContainsString( 'Success:', $out['stdout'] );
	}

	/**
	 * An unknown format is refused before anything runs.
	 */
	public function test_an_unknown_format_is_an_error(): void {
		Rfrac_Legacy_Fixture::install_stock();
		$before = $this->written_options();

		$out = $this->run_cli( fn() => $this->import_cli()->run( array( 'format' => 'xml' ) ) );

		$this->assertSame( 'exit', $out['error'] );
		$this->assertStringContainsString( 'Invalid format. Use one of: table, csv, json, yaml.', $out['stderr'] );
		$this->assertSame( $before, $this->written_options() );
	}

	/**
	 * `--network` needs multisite, and nothing is launched without it.
	 */
	public function test_network_requires_multisite(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site test.' );
		}

		$out = $this->run_cli( fn() => $this->import_cli()->run( array( 'network' => true ) ) );

		$this->assertSame( 'exit', $out['error'] );
		$this->assertStringContainsString( '--network requires multisite.', $out['stderr'] );
	}

	/**
	 * AC-4: without an import wired in, the command is an error.
	 */
	public function test_the_command_without_an_import_is_an_error(): void {
		$plugin  = Plugin::instance();
		$command = new Command( $plugin->cache_service(), $plugin->route_service(), $plugin->status_service(), new Network_Iterator( $plugin->basename() ), null );

		$out = $this->run_cli( fn() => $command->import_legacy( array(), array() ) );

		$this->assertSame( 'exit', $out['error'] );
		$this->assertStringContainsString( 'Error:', $out['stderr'] );
		$this->assertSame( '', $out['stdout'] );
	}

	/**
	 * The command hands its arguments to the import.
	 */
	public function test_the_command_delegates_to_the_import(): void {
		Rfrac_Legacy_Fixture::install_stock();
		$plugin  = Plugin::instance();
		$command = new Command( $plugin->cache_service(), $plugin->route_service(), $plugin->status_service(), new Network_Iterator( $plugin->basename() ), $this->import_cli() );

		$out = $this->run_cli( fn() => $command->import_legacy( array(), array( 'dry-run' => true, 'format' => 'json' ) ) );

		$data = json_decode( $out['stdout'], true );
		$this->assertIsArray( $data, $out['stdout'] );
		$this->assertSame( Import_Report::RESULT_DRY_RUN, $data['result'] );
	}
}
