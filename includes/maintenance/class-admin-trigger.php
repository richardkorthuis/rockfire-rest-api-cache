<?php
/**
 * Maintenance triggered from the plugin's own admin pages.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Maintenance;

use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the database bounded when WP-Cron is disabled or broken: on a plugin admin page load
 * it runs a small, synchronous maintenance pass when the last run is too old.
 *
 * Hooks only {@see Hooks::ADMIN_PAGE_LOAD}, never `admin_init`.
 *
 * @since 1.0.0
 */
class Admin_Trigger {

	use Hook_Subscriber_Trait;

	/**
	 * Option holding the time of the last attempt (a failing or lock-skipped run does not update the last-run option).
	 *
	 * @var string
	 */
	public const LAST_ATTEMPT_OPTION = 'rfrac_maintenance_last_attempt';

	/**
	 * Maintenance runner.
	 *
	 * @var Maintenance
	 */
	private Maintenance $maintenance;

	/**
	 * Whether WP-Cron is disabled.
	 *
	 * @var bool
	 */
	private bool $cron_disabled;

	/**
	 * Constructor.
	 *
	 * @param Maintenance $maintenance   Maintenance runner.
	 * @param bool|null   $cron_disabled Whether WP-Cron is disabled; null reads `DISABLE_WP_CRON`.
	 */
	public function __construct( Maintenance $maintenance, ?bool $cron_disabled = null ) {
		$this->maintenance   = $maintenance;
		$this->cron_disabled = $cron_disabled ?? ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	}

	/**
	 * The hooks: the admin page load action (10, 1).
	 *
	 * @return array<int,array<int,mixed>>
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		return array( array( Hooks::ADMIN_PAGE_LOAD, 'on_admin_page_load', 10, 1 ) );
	}

	/**
	 * Action callback: run maintenance when due, discarding the result.
	 *
	 * @return void
	 */
	public function on_admin_page_load(): void {
		$this->maybe_run();
	}

	/**
	 * Make sure the event exists and run maintenance when the last run is too old.
	 *
	 * Runs when WP-Cron is disabled and the last run is over an hour old, or when it is over six hours old
	 * (cron is not keeping up).
	 *
	 * @return Maintenance_Result|null Null when no run was due.
	 */
	public function maybe_run(): ?Maintenance_Result {
		Scheduler::ensure_scheduled();

		$last = get_option( Maintenance::LAST_RUN_OPTION );
		$done = is_array( $last ) && isset( $last['finished_at'] ) ? (int) $last['finished_at'] : 0;
		// Retries after a failed or skipped run follow the same windows as successful ones.
		$ref = max( $done, (int) get_option( self::LAST_ATTEMPT_OPTION, 0 ) );
		$age = $ref > 0 ? time() - $ref : PHP_INT_MAX;

		if ( ( $this->cron_disabled && $age > HOUR_IN_SECONDS ) || $age > 6 * HOUR_IN_SECONDS ) {
			update_option( self::LAST_ATTEMPT_OPTION, time(), false );
			return $this->maintenance->run( array(), 'admin' );
		}
		return null;
	}
}
