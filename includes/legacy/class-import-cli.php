<?php
/**
 * The WP-CLI side of the import of the original plugin's settings.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Cli\Flag_Trait;
use Rockfire\Rest_Api_Cache\Cli\Network_Iterator;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the {@see Importer} for `wp rfrac import-legacy`: on the current site, as a dry run, or over the network.
 *
 * Over the network the import never runs in this process. It collects the sites where the plugin is active and
 * launches one `wp rfrac import-legacy --format=json` process per site, so every site loads its own plugins and
 * the importer sees the routes and constants of that site. The JSON of every process is read back and printed as
 * one report. All output goes through the WP-CLI APIs.
 *
 * @since 1.0.0
 *
 * @phpstan-import-type FindingData from Finding
 * @phpstan-type SiteReport array{site:string, dry_run:bool, result:string, counts:array<string,int>, findings:list<FindingData>}
 */
final class Import_Cli {

	use Flag_Trait;

	/**
	 * The output formats.
	 *
	 * @var list<string>
	 */
	private const FORMATS = array( 'table', 'csv', 'json', 'yaml' );

	/**
	 * Importer of the current site.
	 *
	 * @var Importer
	 */
	private Importer $importer;

	/**
	 * Network iterator.
	 *
	 * @var Network_Iterator
	 */
	private Network_Iterator $network;

	/**
	 * Launches a WP-CLI command in a new process and returns what it printed and how it ended.
	 *
	 * @var \Closure
	 * @phpstan-var \Closure(string): mixed
	 */
	private \Closure $launch;

	/**
	 * Constructor.
	 *
	 * @param Importer         $importer Importer of the current site.
	 * @param Network_Iterator $network  Network iterator.
	 * @param \Closure|null    $launch   Launches a command and returns an object (or array) with `stdout`, `stderr` and
	 *                                   `return_code`; default {@see \WP_CLI::runcommand()} in a new process.
	 * @phpstan-param (\Closure(string): mixed)|null $launch
	 */
	public function __construct( Importer $importer, Network_Iterator $network, ?\Closure $launch = null ) {
		$this->importer = $importer;
		$this->network  = $network;
		$this->launch   = $launch ?? static fn( string $cmd ) => \WP_CLI::runcommand(
			$cmd,
			array(
				'launch'     => true,
				'return'     => 'all',
				'exit_error' => false,
			)
		);
	}

	/**
	 * Run the import as the command arguments ask.
	 *
	 * @param array<string,mixed> $assoc_args Associative arguments: `dry-run`, `network`, `format`.
	 *
	 * @return void
	 */
	public function run( array $assoc_args ): void {
		$format = isset( $assoc_args['format'] ) && is_string( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		if ( ! in_array( $format, self::FORMATS, true ) ) {
			/* translators: %s: the formats that can be used, separated by commas */
			\WP_CLI::error( sprintf( __( 'Invalid format. Use one of: %s.', 'rockfire-rest-api-cache' ), implode( ', ', self::FORMATS ) ) );
			return;
		}
		$dry_run = $this->flag( $assoc_args, 'dry-run' );

		if ( $this->flag( $assoc_args, 'network' ) ) {
			$this->run_network( $dry_run, $format );
			return;
		}
		$this->run_site( $dry_run, $format );
	}

	// ---------------------------------------------------------------------------------------
	// One site.
	// ---------------------------------------------------------------------------------------

	/**
	 * Import on the current site and print the findings.
	 *
	 * @param bool   $dry_run Whether to save nothing.
	 * @param string $format  Output format.
	 *
	 * @return void
	 */
	private function run_site( bool $dry_run, string $format ): void {
		$report = $this->importer->run( $dry_run );

		if ( Import_Report::RESULT_FAILED === $report->result ) {
			$messages = array();
			if ( null !== $report->validation ) {
				foreach ( $report->validation->errors() as $error ) {
					$messages[] = $error['message'];
				}
			}
			/* translators: %s: the reasons the settings could not be saved */
			\WP_CLI::error( sprintf( __( 'The settings could not be saved: %s', 'rockfire-rest-api-cache' ), implode( ' ', $messages ) ) );
			return;
		}

		$data = $report->to_array() + array( 'site' => $this->site_label() );
		if ( 'json' === $format || 'yaml' === $format ) {
			\WP_CLI::print_value( $data, array( 'format' => $format ) );
		} elseif ( array() !== $data['findings'] || 'csv' === $format ) {
			$this->print_rows( $data['findings'], array( 'status', 'subject', 'message' ), $format );
		}

		if ( Import_Report::RESULT_NOTHING_FOUND === $report->result ) {
			\WP_CLI::warning( $this->nothing_found_message() );
			return;
		}
		if ( 'table' === $format ) {
			\WP_CLI::success( $this->summary( $report->counts, $report->dry_run ) );
		}
	}

	// ---------------------------------------------------------------------------------------
	// The network.
	// ---------------------------------------------------------------------------------------

	/**
	 * Launch the import once per site where the plugin is active and print what the processes reported.
	 *
	 * @param bool   $dry_run Whether to save nothing.
	 * @param string $format  Output format.
	 *
	 * @return void
	 */
	private function run_network( bool $dry_run, string $format ): void {
		$sites = array();
		$this->network->each(
			static function ( int $blog_id ) use ( &$sites ): void {
				$site = get_site( $blog_id );
				if ( $site instanceof \WP_Site ) {
					$sites[] = $site->domain . $site->path;
				}
			}
		);
		if ( array() === $sites ) {
			\WP_CLI::error( __( 'The plugin is not active on any site of the network.', 'rockfire-rest-api-cache' ) );
			return;
		}

		$reports = array();
		$failed  = array();
		foreach ( $sites as $site ) {
			$report = $this->import_site( $site, $dry_run );
			if ( null === $report ) {
				$failed[] = $site;
				continue;
			}
			$reports[] = $report;
		}

		$this->print_network( $reports, $dry_run, $format );

		if ( array() !== $failed ) {
			/* translators: 1: number of sites, 2: the sites (domain and path), separated by commas */
			\WP_CLI::error( sprintf( __( 'The import failed on %1$d site(s): %2$s.', 'rockfire-rest-api-cache' ), count( $failed ), implode( ', ', $failed ) ) );
			return;
		}
		if ( 'table' === $format ) {
			\WP_CLI::success( $this->summary( $this->total( $reports ), $dry_run ) );
		}
	}

	/**
	 * Import one site in a new process.
	 *
	 * A site whose process ends with a non-zero exit code, or whose output is not the JSON report, gets a warning.
	 *
	 * @param string $site    The site, as domain and path.
	 * @param bool   $dry_run Whether to save nothing.
	 *
	 * @return array|null The report of the site; null when the import failed.
	 * @phpstan-return SiteReport|null
	 */
	private function import_site( string $site, bool $dry_run ): ?array {
		$run = ( $this->launch )( $this->command( $site, $dry_run ) );
		$run = is_object( $run ) || is_array( $run ) ? (array) $run : array();

		$code   = $run['return_code'] ?? null;
		$stdout = isset( $run['stdout'] ) && is_string( $run['stdout'] ) ? $run['stdout'] : '';
		$stderr = isset( $run['stderr'] ) && is_string( $run['stderr'] ) ? trim( $run['stderr'] ) : '';
		if ( 0 !== $code ) {
			/* translators: 1: the site (domain and path), 2: exit code of the process */
			$message = sprintf( __( '[%1$s] The import ended with exit code %2$d.', 'rockfire-rest-api-cache' ), $site, is_int( $code ) ? $code : -1 );
			\WP_CLI::warning( '' === $stderr ? $message : $message . ' ' . $stderr );
			return null;
		}

		$report = $this->parse( $site, $dry_run, $stdout );
		if ( null === $report ) {
			/* translators: %s: the site (domain and path) */
			\WP_CLI::warning( sprintf( __( '[%s] The output of the import could not be read.', 'rockfire-rest-api-cache' ), $site ) );
		}
		return $report;
	}

	/**
	 * The command that imports one site, without `--network`.
	 *
	 * @param string $site    The site, as domain and path.
	 * @param bool   $dry_run Whether to save nothing.
	 *
	 * @return string
	 */
	private function command( string $site, bool $dry_run ): string {
		return 'rfrac import-legacy ' . $this->option( 'url', $site ) . ' ' . $this->option( 'path', ABSPATH ) . ' --format=json' . ( $dry_run ? ' --dry-run' : '' );
	}

	/**
	 * A `--name=value` argument of a command line; the value is quoted when it needs to be.
	 *
	 * @param string $name  Option name.
	 * @param string $value Option value.
	 *
	 * @return string
	 */
	private function option( string $name, string $value ): string {
		if ( 1 === preg_match( '#^[A-Za-z0-9_.:/@%+,=-]+$#', $value ) ) {
			return '--' . $name . '=' . $value;
		}
		return '--' . $name . '=' . escapeshellarg( $value );
	}

	/**
	 * Read the JSON report a process printed.
	 *
	 * @param string $site    The site, as domain and path.
	 * @param bool   $dry_run Whether the run was a dry run.
	 * @param string $stdout  What the process printed.
	 *
	 * @return array|null The report; null when the output is not a report.
	 * @phpstan-return SiteReport|null
	 */
	private function parse( string $site, bool $dry_run, string $stdout ): ?array {
		$data = json_decode( trim( $stdout ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$result = $data['result'] ?? null;
		if ( ! is_string( $result ) || ! in_array( $result, Import_Report::RESULTS, true ) ) {
			return null;
		}
		$raw_counts   = $data['counts'] ?? null;
		$raw_findings = $data['findings'] ?? null;
		if ( ! is_array( $raw_counts ) || ! is_array( $raw_findings ) ) {
			return null;
		}

		$counts = array();
		foreach ( Finding::STATUSES as $status ) {
			$count             = $raw_counts[ $status ] ?? 0;
			$counts[ $status ] = is_int( $count ) ? max( 0, $count ) : 0;
		}
		$findings = array();
		foreach ( $raw_findings as $raw_finding ) {
			$finding = Finding::from_array( $raw_finding );
			if ( null === $finding ) {
				return null;
			}
			$findings[] = $finding->to_array();
		}

		return array(
			'site'     => $site,
			'dry_run'  => $dry_run,
			'result'   => $result,
			'counts'   => $counts,
			'findings' => $findings,
		);
	}

	/**
	 * Print the reports of the sites that were imported.
	 *
	 * @param array[] $reports The reports, in the order of the sites.
	 * @phpstan-param list<SiteReport> $reports
	 * @param bool    $dry_run Whether the run was a dry run.
	 * @param string  $format  Output format.
	 *
	 * @return void
	 */
	private function print_network( array $reports, bool $dry_run, string $format ): void {
		if ( 'json' === $format || 'yaml' === $format ) {
			\WP_CLI::print_value( $reports, array( 'format' => $format ) );
			return;
		}

		$rows = array();
		foreach ( $reports as $report ) {
			foreach ( $report['findings'] as $finding ) {
				$rows[] = array( 'site' => $report['site'] ) + $finding;
			}
		}
		if ( array() !== $rows || 'csv' === $format ) {
			$this->print_rows( $rows, array( 'site', 'status', 'subject', 'message' ), $format );
		}
		if ( 'table' !== $format ) {
			return;
		}
		foreach ( $reports as $report ) {
			$line = Import_Report::RESULT_NOTHING_FOUND === $report['result'] ? $this->nothing_found_message() : $this->summary( $report['counts'], $dry_run );
			\WP_CLI::log( '[' . $report['site'] . '] ' . $line );
		}
	}

	/**
	 * The counts of every report added up.
	 *
	 * @param array[] $reports The reports.
	 * @phpstan-param list<SiteReport> $reports
	 *
	 * @return array<string,int>
	 */
	private function total( array $reports ): array {
		$total = array();
		foreach ( Finding::STATUSES as $status ) {
			$total[ $status ] = 0;
		}
		foreach ( $reports as $report ) {
			foreach ( $report['counts'] as $status => $count ) {
				$total[ $status ] = ( $total[ $status ] ?? 0 ) + $count;
			}
		}
		return $total;
	}

	// ---------------------------------------------------------------------------------------
	// Output.
	// ---------------------------------------------------------------------------------------

	/**
	 * Print rows through the WP-CLI formatter.
	 *
	 * @param array<int,array<string,mixed>> $rows   Rows.
	 * @param string[]                       $fields Columns.
	 * @param string                         $format `table` or `csv`.
	 *
	 * @return void
	 */
	private function print_rows( array $rows, array $fields, string $format ): void {
		$args = array( 'format' => $format );
		( new \WP_CLI\Formatter( $args, $fields ) )->display_items( $rows );
	}

	/**
	 * The sentence that says what an import did, or in a dry run what it would do.
	 *
	 * @param array<string,int> $counts  Number of findings per status.
	 * @param bool              $dry_run Whether the run was a dry run.
	 *
	 * @return string
	 */
	private function summary( array $counts, bool $dry_run ): string {
		$imported    = $counts[ Finding::IMPORTED ] ?? 0;
		$kept        = $counts[ Finding::KEPT ] ?? 0;
		$adjusted    = $counts[ Finding::ADJUSTED ] ?? 0;
		$skipped     = $counts[ Finding::SKIPPED ] ?? 0;
		$unsupported = $counts[ Finding::UNSUPPORTED ] ?? 0;

		if ( $dry_run ) {
			/* translators: 1: number of values imported, 2: number kept, 3: number adjusted, 4: number skipped, 5: number unsupported */
			return sprintf( __( 'Dry run: nothing was saved. Would import %1$d, keep %2$d, adjust %3$d, skip %4$d; %5$d unsupported.', 'rockfire-rest-api-cache' ), $imported, $kept, $adjusted, $skipped, $unsupported );
		}
		/* translators: 1: number of values imported, 2: number kept, 3: number adjusted, 4: number skipped, 5: number unsupported */
		return sprintf( __( 'Imported %1$d, kept %2$d, adjusted %3$d, skipped %4$d; %5$d unsupported.', 'rockfire-rest-api-cache' ), $imported, $kept, $adjusted, $skipped, $unsupported );
	}

	/**
	 * The warning of a site without any option or constant of the original.
	 *
	 * @return string
	 */
	private function nothing_found_message(): string {
		return __( 'No settings of WP REST Cache were found; there is nothing to import.', 'rockfire-rest-api-cache' );
	}

	/**
	 * The current site as domain and path (`example.org/`, `example.org/blog/`).
	 *
	 * @return string
	 */
	private function site_label(): string {
		if ( is_multisite() ) {
			$site = get_site();
			if ( $site instanceof \WP_Site ) {
				return $site->domain . $site->path;
			}
		}

		$parts = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$host = isset( $parts['host'] ) ? $parts['host'] : '';
		if ( isset( $parts['port'] ) ) {
			$host .= ':' . $parts['port'];
		}
		return $host . trailingslashit( $parts['path'] ?? '' );
	}
}
