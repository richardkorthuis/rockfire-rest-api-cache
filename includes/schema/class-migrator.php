<?php
/**
 * Database migrator.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Schema;

use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Names;

defined( 'ABSPATH' ) || exit;

/**
 * Brings the current site's tables to the current schema version.
 *
 * The per-request check only compares the autoloaded version option: no query is run when the
 * schema is current.
 *
 * @since 1.0.0
 */
class Migrator {

	/**
	 * Option holding the migration lock (a Unix timestamp). Not autoloaded.
	 *
	 * @var string
	 */
	public const LOCK_OPTION = 'rfrac_migrating';

	/**
	 * Seconds after which a lock is considered stale.
	 *
	 * @var int
	 */
	public const LOCK_TTL = 60;

	/**
	 * Option holding the message of the last failed migration (not autoloaded; deleted on success).
	 *
	 * @var string
	 */
	public const ERROR_OPTION = 'rfrac_migration_error';

	/**
	 * Migrate when the stored schema version is behind.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when migrations ran and the tables exist; false when the schema is current, another
	 *              process holds the lock, or the migration failed (the version is then not stored, so it is retried).
	 */
	public function maybe_migrate(): bool {
		if ( $this->installed_version() >= Schema::VERSION ) {
			return false;
		}
		return $this->run();
	}

	/**
	 * Create or repair the tables of the current site regardless of the stored version.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the tables were created or repaired; false when another process holds the
	 *              lock or the migration failed.
	 */
	public function install_site(): bool {
		return $this->run();
	}

	/**
	 * Drop both tables of the current site.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function drop_tables(): void {
		global $wpdb;
		foreach ( array_reverse( Names::tables() ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Drops the plugin's own tables.
		}
	}

	/**
	 * The stored schema version (0 when nothing is installed).
	 *
	 * @return int
	 */
	private function installed_version(): int {
		return (int) get_option( Names::OPTION_DB_VERSION, 0 );
	}

	/**
	 * Take the lock, run all migrations, verify the tables, store the version, release the lock and fire the hook.
	 *
	 * Every migration is idempotent (dbDelta), so a forced run simply repeats them all. The version is only
	 * stored (and the hook only fired) when both tables exist afterwards, so a failed CREATE TABLE is retried.
	 *
	 * @return bool False when the lock is held by another process or the migration failed.
	 */
	private function run(): bool {
		if ( ! $this->acquire_lock() ) {
			return false;
		}

		$from = $this->installed_version();
		try {
			foreach ( $this->migrations() as $migration ) {
				$migration();
			}
			$missing = $this->missing_tables();
			if ( array() !== $missing ) {
				$message = 'Missing tables after migration: ' . implode( ', ', $missing );
				update_option( self::ERROR_OPTION, $message, false );
				Failure_Recorder::record( 'migration', $message );
				return false;
			}
			update_option( Names::OPTION_DB_VERSION, Schema::VERSION, true );
			delete_option( self::ERROR_OPTION );
		} finally {
			delete_option( self::LOCK_OPTION );
		}

		/**
		 * Fires after the database schema of the current site was migrated.
		 *
		 * @since 1.0.0
		 *
		 * @param int $from Schema version before the migration.
		 * @param int $to   Schema version after the migration.
		 */
		do_action( Hooks::MIGRATED, $from, Schema::VERSION ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.

		return true;
	}

	/**
	 * Full names of the plugin tables of the current site that cannot be queried.
	 *
	 * Uses a query that also works for temporary tables (SHOW TABLES does not list them).
	 *
	 * @return string[]
	 */
	private function missing_tables(): array {
		global $wpdb;
		$missing  = array();
		$suppress = $wpdb->suppress_errors( true );
		foreach ( Names::tables() as $table ) {
			$name = $wpdb->prefix . $table;
			if ( false === $wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 0', $name ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Probes whether the plugin's own table exists; must hit the database.
				$missing[] = $name;
			}
		}
		$wpdb->suppress_errors( $suppress );
		return $missing;
	}

	/**
	 * Migration steps in order, keyed by the schema version they produce.
	 *
	 * @return array<int,callable>
	 */
	private function migrations(): array {
		return [
			1 => [ $this, 'migrate_to_1' ],
		];
	}

	/**
	 * Version 1: create the entries and tags tables.
	 *
	 * @return void
	 */
	private function migrate_to_1(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( Schema::create_sql( $wpdb->prefix, $wpdb->get_charset_collate() ) );
	}

	/**
	 * Take the migration lock; a lock older than LOCK_TTL seconds is stale and replaced.
	 *
	 * @return bool Whether this process now holds the lock.
	 */
	private function acquire_lock(): bool {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}

		// Read the stored value past the object cache: another process may have changed it.
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$locked_at = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $locked_at > 0 && ( time() - $locked_at ) < self::LOCK_TTL ) {
			return false;
		}

		delete_option( self::LOCK_OPTION );
		return add_option( self::LOCK_OPTION, time(), '', false );
	}
}
