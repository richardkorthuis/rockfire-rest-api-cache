<?php
/**
 * Shared identifiers used across the plugin.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Central registry of the plugin's persistent names (prefix, tables, options, files, groups).
 *
 * @since 1.0.0
 */
final class Names {

	/**
	 * Prefix for options, transients, hooks and other global identifiers.
	 *
	 * @var string
	 */
	public const PREFIX = 'rfrac_';

	/**
	 * Unprefixed name of the cache entries table.
	 *
	 * @var string
	 */
	public const TABLE_ENTRIES = 'rfrac_entries';

	/**
	 * Unprefixed name of the cache tags table.
	 *
	 * @var string
	 */
	public const TABLE_TAGS = 'rfrac_tags';

	/**
	 * Option holding the plugin settings.
	 *
	 * @var string
	 */
	public const OPTION_SETTINGS = 'rfrac_settings';

	/**
	 * Option holding the generated runtime configuration.
	 *
	 * @var string
	 */
	public const OPTION_CONFIG = 'rfrac_config';

	/**
	 * Option holding the installed database schema version.
	 *
	 * @var string
	 */
	public const OPTION_DB_VERSION = 'rfrac_db_version';

	/**
	 * Non-autoloaded option holding the findings of the legacy import and compatibility layer.
	 *
	 * @var string
	 */
	public const OPTION_LEGACY_FINDINGS = 'rfrac_legacy_findings';

	/**
	 * Non-autoloaded option counting the flushes committed on a site.
	 *
	 * Written and read only by `Repository` through `$wpdb`, never through `get_option()`: a read-modify-write
	 * is not atomic, and a cached read would hide a flush another request just committed.
	 *
	 * @var string
	 */
	public const OPTION_FLUSH_EPOCH = 'rfrac_flush_epoch';

	/**
	 * Non-autoloaded option counting the full flushes committed on a site.
	 *
	 * Written and read only by `Repository` through `$wpdb`, never through `get_option()`.
	 *
	 * @var string
	 */
	public const OPTION_FLUSH_ALL_EPOCH = 'rfrac_flush_all_epoch';

	/**
	 * Non-autoloaded option marking that another plugin was switched on or off since the last full flush.
	 *
	 * Holds the full-flush epoch of the site at that moment; the marker is pending while it equals the live epoch.
	 *
	 * @var string
	 */
	public const OPTION_PLUGIN_CHANGED = 'rfrac_plugin_changed';

	/**
	 * File name of the must-use early-cache loader in the mu-plugins directory.
	 *
	 * @var string
	 */
	public const MU_FILE = 'rfrac-early-cache.php';

	/**
	 * Object cache group.
	 *
	 * @var string
	 */
	public const CACHE_GROUP = 'rfrac';

	/**
	 * Unprefixed names of all plugin tables.
	 *
	 * @return array<int,string>
	 */
	public static function tables(): array {
		return [ self::TABLE_ENTRIES, self::TABLE_TAGS ];
	}
}
