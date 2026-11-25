<?php
/**
 * Tests for the schema definition and the installed tables.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Schema\Schema;

/**
 * @covers \Rockfire\Rest_Api_Cache\Schema\Schema
 */
class Test_Schema extends Rfrac_Test_Case {

	public function test_version_is_one(): void {
		$this->assertSame( 1, Schema::VERSION );
	}

	public function test_create_sql_returns_entries_and_tags_statements(): void {
		$sql = Schema::create_sql( 'wp_x_', 'DEFAULT CHARACTER SET utf8mb4' );
		$this->assertCount( 2, $sql );
		$this->assertStringStartsWith( 'CREATE TABLE wp_x_rfrac_entries (', $sql[0] );
		$this->assertStringStartsWith( 'CREATE TABLE wp_x_rfrac_tags (', $sql[1] );
		$this->assertStringContainsString( ') DEFAULT CHARACTER SET utf8mb4;', $sql[0] );
		foreach ( array( 'UNIQUE KEY cache_key (cache_key)', 'KEY state_expires (state,expires_at)', 'KEY last_hit (last_hit_at)', 'KEY route (route)', 'KEY rule_key (rule_key)', 'KEY url_hash (url_hash)', 'PRIMARY KEY  (id)' ) as $fragment ) {
			$this->assertStringContainsString( $fragment, $sql[0] );
		}
		$this->assertStringContainsString( 'PRIMARY KEY  (tag,entry_id)', $sql[1] );
		$this->assertStringContainsString( 'KEY entry_id (entry_id)', $sql[1] );
	}

	public function test_table_names_match_names(): void {
		$sql = Schema::create_sql( 'p_', '' );
		$this->assertStringContainsString( 'p_' . Names::TABLE_ENTRIES . ' (', $sql[0] );
		$this->assertStringContainsString( 'p_' . Names::TABLE_TAGS . ' (', $sql[1] );
	}

	/**
	 * Core 6.6 to 6.8 compares the stored column type with the declared one case-sensitively, so a declaration
	 * that differs only in case would be rewritten by every install.
	 */
	public function test_dbdelta_is_a_no_op_on_the_installed_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$changes = dbDelta( Schema::create_sql( $wpdb->prefix, $wpdb->get_charset_collate() ), false );

		$this->assertSame( array(), $changes, 'dbDelta would change the installed tables: ' . implode( '; ', $changes ) );
	}

	public function test_installed_tables_have_the_specified_columns_and_indexes(): void {
		global $wpdb;
		$entries = $wpdb->prefix . Names::TABLE_ENTRIES;
		$tags    = $wpdb->prefix . Names::TABLE_TAGS;

		$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$entries}`", OBJECT_K ); // phpcs:ignore WordPress.DB
		$this->assertSame(
			array( 'id', 'cache_key', 'url_hash', 'route', 'rule_key', 'request', 'method', 'state', 'payload', 'headers', 'size', 'hits', 'created_at', 'expires_at', 'last_hit_at', 'flushed_at' ),
			array_keys( $columns )
		);
		$this->assertSame( 'char(64)', strtolower( (string) $columns['cache_key']->Type ) );
		$this->assertSame( 'GET', $columns['method']->Default );
		$this->assertSame( '1', $columns['state']->Default );
		$this->assertSame( 'YES', $columns['payload']->Null );
		$this->assertSame( 'YES', $columns['flushed_at']->Null );
		$this->assertSame( 'NO', $columns['expires_at']->Null );

		$indexes = array_unique( wp_list_pluck( $wpdb->get_results( "SHOW INDEX FROM `{$entries}`" ), 'Key_name' ) ); // phpcs:ignore WordPress.DB
		$this->assertEqualsCanonicalizing( array( 'PRIMARY', 'cache_key', 'state_expires', 'last_hit', 'route', 'rule_key', 'url_hash' ), $indexes );

		$unique = $wpdb->get_row( "SHOW INDEX FROM `{$entries}` WHERE Key_name = 'cache_key'" ); // phpcs:ignore WordPress.DB
		$this->assertSame( '0', (string) $unique->Non_unique );

		$tag_columns = array_keys( $wpdb->get_results( "SHOW COLUMNS FROM `{$tags}`", OBJECT_K ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( array( 'tag', 'entry_id' ), $tag_columns );
		$tag_pk = wp_list_pluck( $wpdb->get_results( "SHOW INDEX FROM `{$tags}` WHERE Key_name = 'PRIMARY'" ), 'Column_name' ); // phpcs:ignore WordPress.DB
		$this->assertSame( array( 'tag', 'entry_id' ), array_values( $tag_pk ) );
	}
}
