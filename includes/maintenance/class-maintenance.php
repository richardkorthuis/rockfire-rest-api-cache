<?php
/**
 * Bounded maintenance of the cache tables.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Maintenance;

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes flushed and expired entries in batches and enforces the entry cap by LRU.
 *
 * A run is bounded by a batch size, a time budget and a batch limit, which depend on the
 * context it runs in. It never runs in the visitor's own request: WP-Cron may be spawned by a
 * front-end hit, but it executes in a separate loopback request.
 *
 * @since 1.0.0
 */
class Maintenance {

	/**
	 * Option holding the result of the last run.
	 *
	 * @var string
	 */
	public const LAST_RUN_OPTION = 'rfrac_maintenance_last_run';

	/**
	 * Seconds the lock stays valid.
	 *
	 * @var int
	 */
	private const LOCK_TTL = 300;

	/**
	 * Seconds until the follow-up event of an incomplete cron run.
	 *
	 * @var int
	 */
	private const CONTINUE_DELAY = 60;

	/**
	 * Default limits per context: batch size, time budget in seconds (0 unlimited), batch limit (0 unlimited).
	 *
	 * @var array<string,array{batch_size:int,time_budget:float,max_batches:int}>
	 */
	private const DEFAULTS = array(
		'cron'  => array(
			'batch_size'  => 500,
			'time_budget' => 20.0,
			'max_batches' => 20,
		),
		'admin' => array(
			'batch_size'  => 250,
			'time_budget' => 2.0,
			'max_batches' => 4,
		),
		'tools' => array(
			'batch_size'  => 500,
			'time_budget' => 10.0,
			'max_batches' => 10,
		),
		'cli'   => array(
			'batch_size'  => 500,
			'time_budget' => 0.0,
			'max_batches' => 0,
		),
	);

	/**
	 * Repository (counts only; deletions go through the store).
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Resolves the store of the current site (called per run step, so it follows `switch_to_blog()`).
	 *
	 * @var \Closure
	 * @phpstan-var \Closure(): Store_Interface
	 */
	private \Closure $store;

	/**
	 * Config.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Lock.
	 *
	 * @var Lock
	 */
	private Lock $lock;

	/**
	 * Constructor.
	 *
	 * @param Repository               $repository Repository.
	 * @param Store_Interface|\Closure $store      Store, or `fn(): Store_Interface` resolving the store of the current site.
	 * @param Config                   $config     Config.
	 * @param Lock                     $lock       Lock.
	 */
	public function __construct( Repository $repository, Store_Interface|\Closure $store, Config $config, Lock $lock ) {
		$this->repository = $repository;
		$this->store      = $store instanceof Store_Interface ? static fn(): Store_Interface => $store : $store;
		$this->config     = $config;
		$this->lock       = $lock;
	}

	/**
	 * Run the maintenance of the current site.
	 *
	 * @param array<string,mixed> $args    Overrides: `batch_size` (clamped 50..5000), `time_budget` (seconds, 0 unlimited), `max_batches` (0 unlimited).
	 * @param string              $context `cron`, `admin`, `tools` or `cli`.
	 *
	 * @return Maintenance_Result
	 */
	public function run( array $args = array(), string $context = 'cron' ): Maintenance_Result {
		$started  = microtime( true );
		$defaults = self::DEFAULTS[ $context ] ?? self::DEFAULTS['cron'];
		$filtered = apply_filters( Hooks::MAINTENANCE_ARGS, array_merge( $defaults, $args ), $context ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		$merged   = array_merge( $defaults, is_array( $filtered ) ? $filtered : array() );

		$batch       = max( 50, min( 5000, (int) $merged['batch_size'] ) );
		$budget      = max( 0.0, (float) $merged['time_budget'] );
		$max_batches = max( 0, (int) $merged['max_batches'] );

		if ( ! $this->lock->acquire( self::LOCK_TTL ) ) {
			return new Maintenance_Result( $context, true, 0, 0, 0, 0, false, 0, time() );
		}

		try {
			$batches = 0;
			$limited = static function ( int $batches ) use ( $started, $budget, $max_batches ): bool {
				return ( $max_batches > 0 && $batches >= $max_batches )
					|| ( $budget > 0.0 && ( microtime( true ) - $started ) >= $budget );
			};

			// Stale phase: at least one batch always runs so every run makes progress.
			$deleted   = 0;
			$stale_cut = false;
			do {
				$count    = ( $this->store )()->prune( $batch );
				$deleted += $count;
				++$batches;
				if ( $count < $batch ) {
					break;
				}
				$stale_cut = $limited( $batches );
			} while ( ! $stale_cut );

			// Cap phase.
			$cap     = (int) $this->config->settings()['max_entries'];
			$cap_cut = false;
			$evicted = 0;
			$active  = $this->repository->count_active();
			while ( $active > $cap ) {
				if ( $limited( $batches ) ) {
					$cap_cut = true;
					break;
				}
				$count = ( $this->store )()->enforce_cap( $cap, $batch );
				++$batches;
				if ( $count < 1 ) {
					break;
				}
				$evicted += $count;
				$active   = $this->repository->count_active();
			}

			$backlog = $this->repository->count_stale( gmdate( 'Y-m-d H:i:s' ) );

			// A limit only counts as a cut when work is left: a last full batch that cleared the backlog is not one.
			$limit_hit = ( $stale_cut && $backlog > 0 ) || $cap_cut;
			$complete  = $active <= $cap && ( ! $stale_cut || 0 === $backlog );
			$progress  = ( $deleted + $evicted ) > 0;
			$stalled   = ! $complete && ! $progress && ! $limit_hit;
			$result    = new Maintenance_Result(
				$context,
				false,
				$deleted,
				$evicted,
				$backlog,
				$active,
				$complete,
				(int) round( ( microtime( true ) - $started ) * 1000 ),
				time(),
				$stalled
			);

			update_option( self::LAST_RUN_OPTION, $result->to_array(), false );

			// Schedule the follow-up before listeners run, so a throwing listener cannot prevent it.
			// A run that deleted nothing and was not limited (e.g. persistent delete failures) must not chain.
			if ( ! $complete && ( $progress || $limit_hit ) && 'cron' === $context && ! wp_next_scheduled( Scheduler::CONTINUE_HOOK ) ) {
				wp_schedule_single_event( time() + self::CONTINUE_DELAY, Scheduler::CONTINUE_HOOK );
			}

			do_action( Hooks::MAINTENANCE_COMPLETED, $result ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.

			return $result;
		} finally {
			$this->lock->release();
		}
	}
}
