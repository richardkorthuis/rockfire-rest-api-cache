<?php
/**
 * The report of one import of the original plugin's settings.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Config\Validation_Result;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable outcome of an {@see Importer} run: what became of every value of the original, how many findings
 * have each status, and what the run did with the configuration.
 *
 * Every finding's subject is the identifier of the original it is about (an option, a constant, a hook, or an
 * entry of an option such as `option[namespace][endpoint]`).
 *
 * @since 1.0.0
 *
 * @phpstan-import-type FindingData from Finding
 */
final class Import_Report {

	/**
	 * Result: the settings were written and the configuration compiled.
	 *
	 * @var string
	 */
	public const RESULT_COMPILED = 'compiled';

	/**
	 * Result: the settings were written; the configuration is rebuilt by a later request.
	 *
	 * @var string
	 */
	public const RESULT_DEFERRED = 'deferred';

	/**
	 * Result: nothing needed to be written.
	 *
	 * @var string
	 */
	public const RESULT_UNCHANGED = 'unchanged';

	/**
	 * Result: a dry run, which writes nothing.
	 *
	 * @var string
	 */
	public const RESULT_DRY_RUN = 'dry_run';

	/**
	 * Result: the site has no option or constant of the original.
	 *
	 * @var string
	 */
	public const RESULT_NOTHING_FOUND = 'nothing_found';

	/**
	 * Result: the settings could not be saved.
	 *
	 * @var string
	 */
	public const RESULT_FAILED = 'failed';

	/**
	 * Every result.
	 *
	 * @var list<string>
	 */
	public const RESULTS = array(
		self::RESULT_COMPILED,
		self::RESULT_DEFERRED,
		self::RESULT_UNCHANGED,
		self::RESULT_DRY_RUN,
		self::RESULT_NOTHING_FOUND,
		self::RESULT_FAILED,
	);

	/**
	 * The findings, in the order the import made them.
	 *
	 * @var list<Finding>
	 */
	public readonly array $findings;

	/**
	 * The number of findings per status, for each of {@see Finding::STATUSES}.
	 *
	 * @var array<string,int>
	 */
	public readonly array $counts;

	/**
	 * One of {@see self::RESULTS}.
	 *
	 * @var string
	 */
	public readonly string $result;

	/**
	 * The result of the settings save; null when nothing was saved (a dry run, or nothing found).
	 *
	 * @var Validation_Result|null
	 */
	public readonly ?Validation_Result $validation;

	/**
	 * Whether the run was a dry run.
	 *
	 * @var bool
	 */
	public readonly bool $dry_run;

	/**
	 * Create the report.
	 *
	 * @param Finding[]              $findings   The findings.
	 * @phpstan-param list<Finding>  $findings
	 * @param string                 $result     One of {@see self::RESULTS}.
	 * @param Validation_Result|null $validation The result of the settings save, null when nothing was saved.
	 * @param bool                   $dry_run    Whether the run was a dry run.
	 *
	 * @throws \InvalidArgumentException For an unknown result.
	 */
	public function __construct( array $findings, string $result, ?Validation_Result $validation = null, bool $dry_run = false ) {
		if ( ! in_array( $result, self::RESULTS, true ) ) {
			throw new \InvalidArgumentException( 'Unknown import result.' );
		}

		$counts = array();
		foreach ( Finding::STATUSES as $status ) {
			$counts[ $status ] = 0;
		}
		foreach ( $findings as $finding ) {
			$counts[ $finding->status ] = ( $counts[ $finding->status ] ?? 0 ) + 1;
		}

		$this->findings   = $findings;
		$this->counts     = $counts;
		$this->result     = $result;
		$this->validation = $validation;
		$this->dry_run    = $dry_run;
	}

	/**
	 * The report as a plain array.
	 *
	 * @return array{dry_run:bool,result:string,counts:array<string,int>,findings:list<FindingData>}
	 */
	public function to_array(): array {
		$findings = array();
		foreach ( $this->findings as $finding ) {
			$findings[] = $finding->to_array();
		}

		return array(
			'dry_run'  => $this->dry_run,
			'result'   => $this->result,
			'counts'   => $this->counts,
			'findings' => $findings,
		);
	}
}
