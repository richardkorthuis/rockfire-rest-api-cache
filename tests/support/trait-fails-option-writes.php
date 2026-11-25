<?php
/**
 * Makes database writes of one option fail, for testing write-failure reporting.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites the `UPDATE` of an option into one that matches no row, so `update_option()` returns false
 * without changing the database or the object cache, exactly like a failed write.
 */
trait Rfrac_Fails_Option_Writes {

	/**
	 * Make every `UPDATE` of an option fail for the rest of the test.
	 *
	 * @param string $option Option name.
	 */
	protected function fail_option_writes( string $option ): void {
		add_filter(
			'query',
			static function ( string $query ) use ( $option ): string {
				global $wpdb;
				if ( str_starts_with( ltrim( $query ), 'UPDATE' ) && str_contains( $query, "'" . $option . "'" ) ) {
					return "UPDATE {$wpdb->options} SET option_value = option_value WHERE 1 = 0";
				}
				return $query;
			}
		);
	}
}
