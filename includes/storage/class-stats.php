<?php
/**
 * Aggregate numbers about the cache.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable cache statistics of the current site.
 *
 * @since 1.0.0
 */
final class Stats {

	/**
	 * Constructor.
	 *
	 * @param int $active      Entries that are active and not expired.
	 * @param int $flushed     Entries in state flushed.
	 * @param int $expired     Entries that are active but past their expiry.
	 * @param int $total       All entries.
	 * @param int $total_bytes Sum of the payload sizes.
	 * @param int $tag_rows    Rows in the tags table.
	 */
	public function __construct(
		public readonly int $active,
		public readonly int $flushed,
		public readonly int $expired,
		public readonly int $total,
		public readonly int $total_bytes,
		public readonly int $tag_rows
	) {
	}
}
