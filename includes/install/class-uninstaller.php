<?php
/**
 * Full removal of plugin data on uninstall.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Install;

use Rockfire\Rest_Api_Cache\Names;

defined( 'ABSPATH' ) || exit;

/**
 * Removes every trace of the plugin: tables, options, transients, user meta, cron events,
 * the object-cache group and the must-use file, on every site of the network.
 *
 * Only depends on Names, Sites and Cleanup; the plugin itself is not booted during uninstall.
 * Every deletion is anchored on a prefix, never on an unanchored pattern.
 *
 * @since 1.0.0
 */
final class Uninstaller {

	/**
	 * Uninstall the plugin from the whole installation.
	 *
	 * @return void
	 */
	public static function run(): void {
		Sites::each( [ self::class, 'uninstall_site' ] );

		if ( is_multisite() ) {
			self::uninstall_network();
		}

		self::delete_user_meta_like( Names::PREFIX );

		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( Names::CACHE_GROUP );
		}

		$mu_file = WPMU_PLUGIN_DIR . '/' . Names::MU_FILE;
		if ( file_exists( $mu_file ) ) {
			wp_delete_file( $mu_file );
		}
	}

	/**
	 * Remove the plugin data of the current site.
	 *
	 * @return void
	 */
	public static function uninstall_site(): void {
		global $wpdb;

		foreach ( Names::tables() as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall drops the plugin's own tables; no WordPress API exists.
		}

		Cleanup::unschedule_events();

		$like_prefix    = $wpdb->esc_like( Names::PREFIX ) . '%';
		$like_transient = $wpdb->esc_like( '_transient_' . Names::PREFIX ) . '%';
		$like_timeout   = $wpdb->esc_like( '_transient_timeout_' . Names::PREFIX ) . '%';

		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off uninstall sweep of rfrac-prefixed rows; nothing to cache.
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$like_prefix,
				$like_transient,
				$like_timeout
			)
		);
		foreach ( (array) $names as $name ) {
			delete_option( (string) $name );
		}

		self::delete_user_meta_like( $wpdb->get_blog_prefix() . Names::PREFIX );
	}

	/**
	 * Remove the network-level data (sitemeta) of a multisite installation.
	 *
	 * @return void
	 */
	public static function uninstall_network(): void {
		global $wpdb;

		$network_id = get_current_network_id();

		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off uninstall sweep of rfrac-prefixed rows; nothing to cache.
			$wpdb->prepare(
				'SELECT meta_key FROM %i WHERE site_id = %d AND ( meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s )',
				$wpdb->sitemeta,
				$network_id,
				$wpdb->esc_like( Names::PREFIX ) . '%',
				$wpdb->esc_like( '_site_transient_' . Names::PREFIX ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' . Names::PREFIX ) . '%'
			)
		);
		foreach ( (array) $names as $name ) {
			delete_network_option( $network_id, (string) $name );
		}
	}

	/**
	 * Delete every user meta key that starts with the given prefix, for all users.
	 *
	 * @param string $prefix Meta key prefix (anchored at the start).
	 *
	 * @return void
	 */
	private static function delete_user_meta_like( string $prefix ): void {
		global $wpdb;

		$keys = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off uninstall sweep of rfrac-prefixed rows; nothing to cache.
			$wpdb->prepare(
				'SELECT DISTINCT meta_key FROM %i WHERE meta_key LIKE %s',
				$wpdb->usermeta,
				$wpdb->esc_like( $prefix ) . '%'
			)
		);
		foreach ( (array) $keys as $key ) {
			delete_metadata( 'user', 0, (string) $key, '', true );
		}
	}
}
