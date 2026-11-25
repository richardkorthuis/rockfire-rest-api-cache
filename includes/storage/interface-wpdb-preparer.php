<?php
/**
 * Static analysis view of the statements the repository prepares.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * A `wpdb` whose `prepare()` returns the prepared statement.
 *
 * `wpdb::prepare()` is documented as returning `string|void`, because it returns nothing when it is given no query.
 * The repository only ever prepares non-empty literal queries, so it always gets a string back, and `wpdb::query()`
 * accepts only a string. The repository states that in its `@var` tag as `\wpdb&Wpdb_Preparer_Interface`, which is
 * all this interface is for: nothing implements it and nothing loads it at run time.
 *
 * @since 1.0.0
 */
interface Wpdb_Preparer_Interface {

	/**
	 * Prepare a statement.
	 *
	 * @param string $query   Query with `sprintf()`-like placeholders.
	 * @param mixed  ...$args Values for the placeholders, or one array of them.
	 *
	 * @return string The prepared statement.
	 */
	public function prepare( $query, ...$args ): string;
}
