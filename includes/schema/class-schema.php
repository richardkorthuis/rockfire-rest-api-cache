<?php
/**
 * Database schema definition.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Schema;

use Rockfire\Rest_Api_Cache\Names;

defined( 'ABSPATH' ) || exit;

/**
 * The per-site tables of the cache engine, as dbDelta statements.
 *
 * @since 1.0.0
 */
final class Schema {

	/**
	 * Current schema version.
	 *
	 * @var int
	 */
	public const VERSION = 1;

	/**
	 * The CREATE TABLE statements (dbDelta format) for the current schema version.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix  Table prefix of the site (`$wpdb->prefix`).
	 * @param string $collate Charset/collation clause (`$wpdb->get_charset_collate()`).
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function create_sql( string $prefix, string $collate ): array {
		$entries = $prefix . Names::TABLE_ENTRIES;
		$tags    = $prefix . Names::TABLE_TAGS;

		return [
			"CREATE TABLE {$entries} (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  cache_key char(64) NOT NULL,
  url_hash char(64) NOT NULL,
  route varchar(191) NOT NULL,
  rule_key varchar(191) NOT NULL,
  request text NOT NULL,
  method varchar(10) NOT NULL DEFAULT 'GET',
  state tinyint unsigned NOT NULL DEFAULT 1,
  payload longtext NULL,
  headers text NOT NULL,
  size int unsigned NOT NULL DEFAULT 0,
  hits bigint unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  last_hit_at datetime NOT NULL,
  flushed_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY cache_key (cache_key),
  KEY state_expires (state,expires_at),
  KEY last_hit (last_hit_at),
  KEY route (route),
  KEY rule_key (rule_key),
  KEY url_hash (url_hash)
) {$collate};",
			"CREATE TABLE {$tags} (
  tag varchar(191) NOT NULL,
  entry_id bigint unsigned NOT NULL,
  PRIMARY KEY  (tag,entry_id),
  KEY entry_id (entry_id)
) {$collate};",
		];
	}

	/**
	 * Whether both tables of the current site exist (`SHOW TABLES LIKE`).
	 *
	 * Only for the status page and Site Health: never call it on a request path. `SHOW TABLES` does not
	 * list temporary tables.
	 *
	 * @return bool
	 */
	public function tables_exist(): bool {
		global $wpdb;
		foreach ( Names::tables() as $table ) {
			$name = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Probes whether the plugin's own table exists; must hit the database.
			if ( strtolower( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) ) !== strtolower( $name ) ) {
				return false;
			}
		}
		return true;
	}
}
