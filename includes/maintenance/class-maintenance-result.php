<?php
/**
 * Outcome of a maintenance run.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Maintenance;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result of {@see Maintenance::run()}.
 *
 * @since 1.0.0
 */
final class Maintenance_Result {

	/**
	 * Constructor.
	 *
	 * @param string $context           Run context (`cron`, `admin`, `tools` or `cli`).
	 * @param bool   $skipped           Whether the run was skipped because another run holds the lock.
	 * @param int    $deleted_stale     Flushed and expired entries deleted.
	 * @param int    $evicted           Active entries evicted to enforce the cap.
	 * @param int    $backlog_remaining Flushed and expired entries left after the run (informational).
	 * @param int    $entries_after     Active entries after the run.
	 * @param bool   $complete          Whether the run finished its work: no budget or batch limit cut it short while work remained, and the active entries are within the cap.
	 * @param int    $duration_ms       Duration in milliseconds.
	 * @param int    $finished_at       Unix timestamp the run finished.
	 * @param bool   $stalled           Whether the run is incomplete, deleted nothing and was not cut by a limit (e.g. deletes keep failing); no continuation is scheduled then.
	 */
	public function __construct(
		public readonly string $context,
		public readonly bool $skipped,
		public readonly int $deleted_stale,
		public readonly int $evicted,
		public readonly int $backlog_remaining,
		public readonly int $entries_after,
		public readonly bool $complete,
		public readonly int $duration_ms,
		public readonly int $finished_at,
		public readonly bool $stalled = false
	) {
	}

	/**
	 * The result as a plain array (the value stored in the last-run option).
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'context'           => $this->context,
			'skipped'           => $this->skipped,
			'deleted_stale'     => $this->deleted_stale,
			'evicted'           => $this->evicted,
			'backlog_remaining' => $this->backlog_remaining,
			'entries_after'     => $this->entries_after,
			'complete'          => $this->complete,
			'duration_ms'       => $this->duration_ms,
			'finished_at'       => $this->finished_at,
			'stalled'           => $this->stalled,
		);
	}
}
