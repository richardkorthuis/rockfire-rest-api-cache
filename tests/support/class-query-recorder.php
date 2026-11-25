<?php
/**
 * Records the SQL queries WordPress runs, via the `query` filter.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

/**
 * Query recorder for tests asserting which queries a code path runs.
 */
class Rfrac_Query_Recorder {

	/**
	 * Recorded queries.
	 *
	 * @var list<string>
	 */
	private array $queries = array();

	/**
	 * Whether recording is active.
	 *
	 * @var bool
	 */
	private bool $recording = false;

	/**
	 * Start recording (clears earlier recordings).
	 */
	public function start(): void {
		$this->queries = array();
		if ( ! $this->recording ) {
			add_filter( 'query', array( $this, 'record' ), PHP_INT_MAX );
			$this->recording = true;
		}
	}

	/**
	 * Stop recording; the recorded queries stay available.
	 */
	public function stop(): void {
		if ( $this->recording ) {
			remove_filter( 'query', array( $this, 'record' ), PHP_INT_MAX );
			$this->recording = false;
		}
	}

	/**
	 * `query` filter callback.
	 *
	 * @param string $query SQL query.
	 *
	 * @return string The unchanged query.
	 */
	public function record( $query ) {
		$this->queries[] = (string) $query;
		return $query;
	}

	/**
	 * Recorded queries matching a regular expression.
	 *
	 * @param string $regex Complete PCRE pattern.
	 *
	 * @return list<string>
	 */
	public function matching( string $regex ): array {
		return array_values(
			array_filter(
				$this->queries,
				static fn( string $query ) => 1 === preg_match( $regex, $query )
			)
		);
	}
}
