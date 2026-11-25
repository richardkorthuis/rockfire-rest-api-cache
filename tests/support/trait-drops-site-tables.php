<?php
/**
 * Cleanup of the sites a multisite test created.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creating a site runs real DDL for its cache tables, which the transaction rollback does not undo, and that
 * implicit commit may also keep the site's row. Use from `tear_down()` after `parent::tear_down()`.
 */
trait Rfrac_Drops_Site_Tables {

	/**
	 * Multisite: drop the cache tables of the sites a test created (`wptests_N_rfrac_*`) and delete every
	 * surviving site other than the main one, so later site iterations never meet a site without tables.
	 *
	 * Tables are found with `SHOW TABLES`, which never lists the suite's temporary tables, so a committed site
	 * row can outlive its tables: the sweep walks the site rows, not the tables.
	 *
	 * Runs after the parent tear down, so the implicit commit of the DROP no longer carries test data.
	 */
	protected function drop_created_site_tables(): void {
		global $wpdb;
		if ( ! is_multisite() ) {
			return;
		}
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$like    = $wpdb->esc_like( $wpdb->base_prefix ) . '%' . $wpdb->esc_like( 'rfrac_' ) . '%';
		$tables  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$pattern = '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '([1-9]\d*)_rfrac_/';
		foreach ( $tables as $table ) {
			if ( 1 === preg_match( $pattern, $table ) ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		$main = (int) get_main_site_id();
		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $blog_id ) {
			if ( (int) $blog_id !== $main ) {
				wpmu_delete_blog( (int) $blog_id, true );
			}
		}
	}
}
