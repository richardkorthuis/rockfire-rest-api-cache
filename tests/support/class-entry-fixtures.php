<?php
/**
 * Test fixtures for rows of the entries and tags tables.
 *
 * The only test file that knows the column names of the cache tables.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Names;

/**
 * Insert and inspect cache entries directly, bypassing the Repository.
 */
final class Rfrac_Entry_Fixtures {

	/**
	 * Counter making default cache keys unique.
	 *
	 * @var int
	 */
	private static int $sequence = 0;

	/**
	 * Insert an entry (and its tags) into the current site's tables.
	 *
	 * Defaults: an active GET entry for `/wp/v2/posts` created now, expiring in an hour, with a unique cache key.
	 * Overrides are column names (`cache_key`, `url_hash`, `route`, `rule_key`, `request`, `method`, `state`,
	 * `payload`, `headers`, `size`, `hits`, `created_at`, `expires_at`, `last_hit_at`, `flushed_at`).
	 *
	 * @param array<string,mixed> $overrides Column overrides.
	 * @param string[]            $tags      Tags to attach.
	 *
	 * @return int Entry id.
	 */
	public static function insert_entry( array $overrides = array(), array $tags = array() ): int {
		global $wpdb;
		++self::$sequence;
		$now = time();
		$row = array_merge(
			array(
				'cache_key'   => hash( 'sha256', 'key-' . self::$sequence ),
				'url_hash'    => hash( 'sha256', 'url-' . self::$sequence ),
				'route'       => '/wp/v2/posts',
				'rule_key'    => '/wp/v2/posts',
				'request'     => '/wp/v2/posts?page=' . self::$sequence,
				'method'      => 'GET',
				'state'       => 1,
				'payload'     => '{"ok":true}',
				'headers'     => '[]',
				'size'        => 11,
				'hits'        => 0,
				'created_at'  => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'  => gmdate( 'Y-m-d H:i:s', $now + HOUR_IN_SECONDS ),
				'last_hit_at' => gmdate( 'Y-m-d H:i:s', $now ),
				'flushed_at'  => null,
			),
			$overrides
		);

		$wpdb->insert( $wpdb->prefix . Names::TABLE_ENTRIES, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id;

		foreach ( $tags as $tag ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . Names::TABLE_TAGS,
				array(
					'tag'      => $tag,
					'entry_id' => $id,
				)
			);
		}
		return $id;
	}

	/**
	 * The `state` column of an entry as the database returns it ('1' active, '2' flushed), '' when missing.
	 *
	 * @param int $id Entry id.
	 *
	 * @return string
	 */
	public static function get_state( int $id ): string {
		global $wpdb;
		$table = $wpdb->prefix . Names::TABLE_ENTRIES;
		$state = $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM %i WHERE id = %d', $table, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return null === $state ? '' : (string) $state;
	}

	/**
	 * Number of rows in the current site's entries table.
	 *
	 * @return int
	 */
	public static function count(): int {
		global $wpdb;
		$table = $wpdb->prefix . Names::TABLE_ENTRIES;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Number of rows in the current site's tags table.
	 *
	 * @return int
	 */
	public static function count_tags(): int {
		global $wpdb;
		$table = $wpdb->prefix . Names::TABLE_TAGS;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
