<?php
/**
 * Multisite site lifecycle handling.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Schema;

use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Names;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the tables of a new site, registers them for removal when a site is deleted and flushes the cache of a
 * site that is archived, marked as spam or deactivated.
 *
 * @since 1.0.0
 */
final class Site_Lifecycle {

	/**
	 * Plugin basename (as in `active_sitewide_plugins`).
	 *
	 * @var string
	 */
	private string $plugin_basename;

	/**
	 * Migrator.
	 *
	 * @var Migrator
	 */
	private Migrator $migrator;

	/**
	 * Flushes the cache of the current site (runs after switching to the site that was taken down).
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $flush_site;

	/**
	 * Constructor.
	 *
	 * @param string        $plugin_basename Plugin basename.
	 * @param Migrator      $migrator        Migrator.
	 * @param \Closure|null $flush_site      Flushes the cache of the current site; none means a status change flushes nothing.
	 */
	public function __construct( string $plugin_basename, Migrator $migrator, ?\Closure $flush_site = null ) {
		$this->plugin_basename = $plugin_basename;
		$this->migrator        = $migrator;
		$this->flush_site      = $flush_site;
	}

	/**
	 * Create the tables for a new site when the plugin is network-active (`wp_initialize_site`, priority 100).
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Site $site The new site.
	 *
	 * @return void
	 */
	public function on_initialize_site( \WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( $this->plugin_basename ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		try {
			if ( $this->migrator->install_site() ) {
				/**
				 * Fires after the plugin was set up for a newly created site.
				 *
				 * Not fired when the tables could not be created.
				 *
				 * @since 1.0.0
				 *
				 * @param int $site_id ID of the new site.
				 */
				do_action( Hooks::SITE_INITIALIZED, (int) $site->blog_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
			}
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Flush the cache of a site that was archived, marked as spam or deactivated (`archive_blog`, `make_spam_blog`, `deactivate_blog`).
	 *
	 * @since 1.0.0
	 *
	 * @param int $site_id ID of the site.
	 *
	 * @return void
	 */
	public function on_site_suspended( int $site_id ): void {
		$this->flush_suspended_site( $site_id );
	}

	/**
	 * Flush the cache of a site whose `archived`, `spam` or `deleted` status was set (`wp_update_site`).
	 *
	 * Only a status going from `'0'` to another value counts; every other field, and a status that was already set, is ignored.
	 * Core's own handler of this hook fires `archive_blog` and `make_spam_blog` for the same change, so the site is
	 * flushed twice; a flush is idempotent and no state is kept to tell the two apart.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Site $new_site Site after the update.
	 * @param \WP_Site $old_site Site before the update.
	 *
	 * @return void
	 */
	public function on_site_updated( \WP_Site $new_site, \WP_Site $old_site ): void {
		foreach ( array( 'archived', 'spam', 'deleted' ) as $status ) {
			if ( '0' === (string) $old_site->$status && '0' !== (string) $new_site->$status ) {
				$this->flush_suspended_site( (int) $new_site->blog_id );
				return;
			}
		}
	}

	/**
	 * Add the plugin tables of a site to the tables dropped when it is deleted (`wpmu_drop_tables`).
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $tables  Tables to drop.
	 * @param int      $site_id ID of the site being deleted.
	 *
	 * @return string[]
	 */
	public function filter_drop_tables( array $tables, int $site_id ): array {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( $site_id );
		foreach ( Names::tables() as $table ) {
			$tables[] = $prefix . $table;
		}
		return $tables;
	}

	/**
	 * Flush the cache of a site, when this plugin runs on it.
	 *
	 * The plugin runs on the site when it is network-active or listed in the site's `active_plugins`; on any other
	 * site its tables may not exist, so nothing is queried there. Errors are suppressed for a site whose tables are gone.
	 *
	 * @param int $site_id ID of the site.
	 *
	 * @return void
	 */
	private function flush_suspended_site( int $site_id ): void {
		if ( null === $this->flush_site || $site_id < 1 ) {
			return;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		switch_to_blog( $site_id );
		$suppress = $this->suppress_errors( true );
		try {
			if ( is_plugin_active( $this->plugin_basename ) ) {
				( $this->flush_site )();
			}
		} finally {
			$this->suppress_errors( $suppress );
			restore_current_blog();
		}
	}

	/**
	 * Set whether database errors are suppressed.
	 *
	 * @param bool $suppress Whether to suppress errors.
	 *
	 * @return bool The previous setting.
	 */
	private function suppress_errors( bool $suppress ): bool {
		global $wpdb;
		return $wpdb instanceof \wpdb ? $wpdb->suppress_errors( $suppress ) : false;
	}
}
