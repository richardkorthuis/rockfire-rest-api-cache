<?php
/**
 * Status snapshot service.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Services;

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Install\Mu_Installer;
use Rockfire\Rest_Api_Cache\Install\Mu_Status;
use Rockfire\Rest_Api_Cache\Maintenance\Maintenance;
use Rockfire\Rest_Api_Cache\Maintenance\Scheduler;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Schema\Migrator;
use Rockfire\Rest_Api_Cache\Schema\Schema;
use Rockfire\Rest_Api_Cache\Storage\Store_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the {@see Status} snapshot and exposes the must-use file actions of the Tools page.
 *
 * The snapshot is memoised per request, per blog and per `$check_tables` flag, and forgotten when a flush
 * (`rfrac/flushed`) or a maintenance run (`rfrac/maintenance_completed`) changed the cache, so a status shown
 * after such a change in the same request is current. The table check (`SHOW TABLES`) only runs when asked for.
 *
 * @since 1.0.0
 */
class Status_Service {

	/**
	 * Settings.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Store, or a resolver of the store of the current site.
	 *
	 * @var Store_Interface|\Closure
	 * @phpstan-var Store_Interface|\Closure(): Store_Interface
	 */
	private Store_Interface|\Closure $store;

	/**
	 * Schema.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Must-use installer.
	 *
	 * @var Mu_Installer
	 */
	private Mu_Installer $mu_installer;

	/**
	 * Cache service.
	 *
	 * @var Cache_Service
	 */
	private Cache_Service $cache_service;

	/**
	 * Memoised snapshots.
	 *
	 * @var array<string,Status>
	 */
	private array $memo = array();

	/**
	 * Whether the listeners that forget the memo are registered.
	 *
	 * @var bool
	 */
	private bool $listening = false;

	/**
	 * Constructor.
	 *
	 * @param Config                   $config        Settings.
	 * @param Store_Interface|\Closure $store         Store, or a closure returning the store of the current site.
	 * @phpstan-param Store_Interface|\Closure(): Store_Interface $store
	 * @param Schema                   $schema        Schema.
	 * @param Mu_Installer             $mu_installer  Must-use installer.
	 * @param Cache_Service            $cache_service Cache service.
	 */
	public function __construct( Config $config, Store_Interface|\Closure $store, Schema $schema, Mu_Installer $mu_installer, Cache_Service $cache_service ) {
		$this->config        = $config;
		$this->store         = $store;
		$this->schema        = $schema;
		$this->mu_installer  = $mu_installer;
		$this->cache_service = $cache_service;
	}

	/**
	 * The status snapshot of the current site.
	 *
	 * @param bool $check_tables Whether to check that the tables exist (`SHOW TABLES`); `tables_present` is null otherwise.
	 *
	 * @return Status
	 */
	public function get( bool $check_tables = false ): Status {
		$this->listen();
		$key = get_current_blog_id() . ':' . ( $check_tables ? '1' : '0' );
		if ( ! isset( $this->memo[ $key ] ) ) {
			$this->memo[ $key ] = $this->build( $check_tables );
		}
		return $this->memo[ $key ];
	}

	/**
	 * Install the must-use file; a file that is not built from the template and could not be replaced
	 * (the legacy loader) is deleted ({@see Mu_Installer::ensure_installed()}).
	 *
	 * @return Mu_Status The status after the attempt; the reason is in `Mu_Installer::last_result()`.
	 */
	public function install_mu(): Mu_Status {
		$status = $this->mu_installer->ensure_installed();
		$this->forget();
		return $status;
	}

	/**
	 * The state of the must-use file, without building the full snapshot (which counts the cache entries).
	 *
	 * @return Mu_Status
	 */
	public function mu_status(): Mu_Status {
		return $this->mu_installer->status();
	}

	/**
	 * Why the must-use file cannot be installed from here, without changing anything ({@see Mu_Installer::install_blocker()}).
	 *
	 * @return string|null The code of the first refusing precondition (the codes of `Mu_Installer::last_result()`); null when an install can go ahead.
	 */
	public function mu_install_blocker(): ?string {
		return $this->mu_installer->install_blocker();
	}

	/**
	 * Drop the memoised snapshots: the next {@see self::get()} builds a fresh one.
	 *
	 * Called when the must-use file, the cache contents or the maintenance state changed.
	 *
	 * @return void
	 */
	public function forget(): void {
		$this->memo = array();
	}

	/**
	 * Forget the memo whenever entries are flushed or a maintenance pass finished.
	 *
	 * @return void
	 */
	private function listen(): void {
		if ( $this->listening ) {
			return;
		}
		$this->listening = true;
		add_action( Hooks::FLUSHED, array( $this, 'forget' ), 10, 0 );
		add_action( Hooks::MAINTENANCE_COMPLETED, array( $this, 'forget' ), 10, 0 );
	}

	/**
	 * Build a snapshot.
	 *
	 * @param bool $check_tables Whether to check the tables.
	 *
	 * @return Status
	 */
	private function build( bool $check_tables ): Status {
		$settings = $this->config->settings();
		$store    = $this->store instanceof \Closure ? ( $this->store )() : $this->store;
		$tables   = $check_tables ? $this->schema->tables_exist() : null;
		// Without tables the stats query would raise a database error notice; report zero counts instead.
		$stats = false === $tables
			? array(
				'active'  => 0,
				'flushed' => 0,
				'expired' => 0,
				'total'   => 0,
				'size'    => 0,
			)
			: $this->cache_service->stats();

		$last_run = get_option( Maintenance::LAST_RUN_OPTION, null );
		$next     = wp_next_scheduled( Scheduler::HOOK );
		$error    = get_option( Migrator::ERROR_OPTION, null );

		return new Status(
			RFRAC_VERSION,
			(int) get_option( Names::OPTION_DB_VERSION, 0 ),
			Schema::VERSION,
			$tables,
			$this->mu_installer->status(),
			$this->mu_installer->mode(),
			$this->mu_installer->last_result(),
			$this->mu_installer->target_path(),
			$this->mu_installer->template_path(),
			$this->mu_dir_writable(),
			$store->name(),
			(bool) wp_using_ext_object_cache(),
			$stats,
			$stats['size'],
			(int) $settings['max_entries'],
			$stats['flushed'] + $stats['expired'],
			is_array( $last_run ) ? $last_run : null,
			false === $next ? null : (int) $next,
			defined( 'DISABLE_WP_CRON' ) && (bool) constant( 'DISABLE_WP_CRON' ),
			Coexistence_Guard::is_blocked(),
			$this->config->locked(),
			Failure_Recorder::last(),
			is_string( $error ) && '' !== $error ? $error : null
		);
	}

	/**
	 * Whether the must-use directory can be written (or created, when it does not exist yet).
	 *
	 * @return bool
	 */
	private function mu_dir_writable(): bool {
		$dir = dirname( $this->mu_installer->target_path() );
		return wp_is_writable( is_dir( $dir ) ? $dir : dirname( $dir ) );
	}
}
