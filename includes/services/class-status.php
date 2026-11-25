<?php
/**
 * Status snapshot of the plugin.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Services;

use Rockfire\Rest_Api_Cache\Install\Mu_Status;

defined( 'ABSPATH' ) || exit;

/**
 * One immutable snapshot of the state of the plugin, shared by the Tools page, `wp rfrac status` and
 * Site Health. Built by {@see Status_Service::get()}.
 *
 * Beyond the status fields it carries `last_error` (the last failure recorded by `Failure_Recorder`,
 * option `rfrac_last_error`) and `migration_error` (option `rfrac_migration_error`), so the consumers
 * need not read those options themselves.
 *
 * @since 1.0.0
 */
final class Status {

	/**
	 * Constructor.
	 *
	 * @param string      $plugin_version        Plugin version.
	 * @param int         $db_version            Stored schema version of the current site (0 when none).
	 * @param int         $db_version_expected   Schema version the code expects.
	 * @param bool|null   $tables_present        Whether both tables exist; null when not checked.
	 * @param Mu_Status   $mu_status             State of the must-use file.
	 * @param string      $mu_mode               Serving mode: `early` or `fallback`.
	 * @param array|null  $mu_last_result        Outcome of the last install attempt.
	 * @phpstan-param array{status:string,code:string,time:int}|null $mu_last_result
	 * @param string      $mu_target_path        Path of the installed must-use file.
	 * @param string      $mu_template_path      Path of the must-use template.
	 * @param bool        $mu_dir_writable       Whether the must-use directory (or the directory to create) is writable.
	 * @param string      $store_type            Name of the active store.
	 * @param bool        $object_cache_detected Whether a persistent object cache is in use.
	 * @param array       $counts                Entry counts and size ({@see Cache_Service::stats()}).
	 * @phpstan-param array{active:int,flushed:int,expired:int,total:int,size:int} $counts
	 * @param int         $total_size            Total payload size in bytes.
	 * @param int         $max_entries           Effective `max_entries` setting.
	 * @param int         $backlog               Flushed and expired entries waiting to be deleted.
	 * @param array|null  $last_maintenance      Last maintenance result (`Maintenance_Result::to_array()`), or null.
	 * @phpstan-param array<string,mixed>|null $last_maintenance
	 * @param int|null    $next_maintenance      Timestamp of the next scheduled maintenance, or null.
	 * @param bool        $cron_disabled         Whether `DISABLE_WP_CRON` is set.
	 * @param bool        $coexistence_blocked   Whether caching is paused for the original WP REST Cache.
	 * @param array       $locked_settings       Settings locked by a constant (key => constant name).
	 * @phpstan-param array<string,string> $locked_settings
	 * @param array|null  $last_error            Last recorded failure.
	 * @phpstan-param array{context:string,message:string,time:int,count:int}|null $last_error
	 * @param string|null $migration_error       Last migration error message, or null.
	 */
	public function __construct(
		public readonly string $plugin_version,
		public readonly int $db_version,
		public readonly int $db_version_expected,
		public readonly ?bool $tables_present,
		public readonly Mu_Status $mu_status,
		public readonly string $mu_mode,
		public readonly ?array $mu_last_result,
		public readonly string $mu_target_path,
		public readonly string $mu_template_path,
		public readonly bool $mu_dir_writable,
		public readonly string $store_type,
		public readonly bool $object_cache_detected,
		public readonly array $counts,
		public readonly int $total_size,
		public readonly int $max_entries,
		public readonly int $backlog,
		public readonly ?array $last_maintenance,
		public readonly ?int $next_maintenance,
		public readonly bool $cron_disabled,
		public readonly bool $coexistence_blocked,
		public readonly array $locked_settings,
		public readonly ?array $last_error = null,
		public readonly ?string $migration_error = null
	) {
	}
}
