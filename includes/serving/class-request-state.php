<?php
/**
 * Request-scoped hand-off between the must-use phase and the plugin container.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Serving;

use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;

defined( 'ABSPATH' ) || exit;

/**
 * The one static of the engine that holds request state (besides the test seam of {@see Early_Server}).
 *
 * The must-use phase and the plugin container are separate objects within the same PHP request;
 * this holds what the early phase learned (which template version booted, the context, the policy
 * decision and the cache key) so the later phase does not repeat the work. It also carries whether the lookup ran on a
 * pending or stale snapshot (it did not hit) and whether this request's own recompile then rebuilt that snapshot, so
 * Capture can tell the refusal of such a request from a lookup that could not run. Tests call {@see self::reset()}.
 *
 * @since 1.0.0
 */
final class Request_State {

	/**
	 * Whether a current must-use file booted the early server and prepared its lookup.
	 *
	 * @var bool
	 */
	private static bool $early_booted = false;

	/**
	 * Template version reported by the must-use file.
	 *
	 * @var int|null
	 */
	private static ?int $mu_template_version = null;

	/**
	 * Whether a lookup ran.
	 *
	 * @var bool
	 */
	private static bool $lookup_done = false;

	/**
	 * Request context of the lookup.
	 *
	 * @var Request_Context|null
	 */
	private static ?Request_Context $context = null;

	/**
	 * Policy decision of the lookup.
	 *
	 * @var Policy_Decision|null
	 */
	private static ?Policy_Decision $decision = null;

	/**
	 * Cache key of the lookup (null when the request is not cacheable).
	 *
	 * @var string|null
	 */
	private static ?string $cache_key = null;

	/**
	 * Flush epoch the lookup saw (null when the lookup did not read it).
	 *
	 * @var int|null
	 */
	private static ?int $flush_epoch = null;

	/**
	 * Whether the lookup ran on a pending or stale snapshot and did not hit.
	 *
	 * @var bool
	 */
	private static bool $outdated_snapshot = false;

	/**
	 * Whether this request's `rest_api_init` recompile rebuilt the outdated snapshot the lookup ran on.
	 *
	 * @var bool
	 */
	private static bool $outdated_snapshot_rebuilt = false;

	/**
	 * Record that the lookup ran on a pending or stale snapshot and did not hit.
	 *
	 * @return void
	 */
	public static function record_outdated_snapshot(): void {
		self::$outdated_snapshot = true;
	}

	/**
	 * Record that the recompile of this request rebuilt its snapshot; counts only when the lookup observed it outdated.
	 *
	 * @return void
	 */
	public static function record_snapshot_rebuilt(): void {
		if ( self::$outdated_snapshot ) {
			self::$outdated_snapshot_rebuilt = true;
		}
	}

	/**
	 * Whether the lookup ran on a pending or stale snapshot and did not hit.
	 *
	 * @return bool
	 */
	public static function outdated_snapshot(): bool {
		return self::$outdated_snapshot;
	}

	/**
	 * Whether this request rebuilt the pending or stale snapshot its lookup ran on.
	 *
	 * @return bool
	 */
	public static function outdated_snapshot_rebuilt(): bool {
		return self::$outdated_snapshot_rebuilt;
	}

	/**
	 * Record that the must-use file booted.
	 *
	 * @param int  $template_version Template version of the must-use file.
	 * @param bool $matches          Whether the early server is in charge: the template matches and the lookup was prepared.
	 *
	 * @return void
	 */
	public static function record_boot( int $template_version, bool $matches ): void {
		self::$mu_template_version = $template_version;
		self::$early_booted        = $matches;
	}

	/**
	 * Whether a current must-use file booted and prepared its lookup (a mismatching template or a failed preparation does not count).
	 *
	 * @return bool
	 */
	public static function early_booted(): bool {
		return self::$early_booted;
	}

	/**
	 * Template version reported by the must-use file, null when it did not boot.
	 *
	 * @return int|null
	 */
	public static function mu_template_version(): ?int {
		return self::$mu_template_version;
	}

	/**
	 * Record the outcome of a lookup that did not end the request.
	 *
	 * @param Request_Context $context     Request context.
	 * @param Policy_Decision $decision    Policy decision.
	 * @param string|null     $key         Cache key, null when not cacheable.
	 * @param int|null        $flush_epoch Flush epoch the lookup saw, null when it did not read it.
	 *
	 * @return void
	 */
	public static function record_lookup( Request_Context $context, Policy_Decision $decision, ?string $key, ?int $flush_epoch = null ): void {
		self::$lookup_done = true;
		self::$context     = $context;
		self::$decision    = $decision;
		self::$cache_key   = $key;
		self::$flush_epoch = $flush_epoch;
	}

	/**
	 * Whether a lookup ran.
	 *
	 * @return bool
	 */
	public static function lookup_done(): bool {
		return self::$lookup_done;
	}

	/**
	 * Request context of the lookup.
	 *
	 * @return Request_Context|null
	 */
	public static function context(): ?Request_Context {
		return self::$context;
	}

	/**
	 * Policy decision of the lookup.
	 *
	 * @return Policy_Decision|null
	 */
	public static function decision(): ?Policy_Decision {
		return self::$decision;
	}

	/**
	 * Cache key of the lookup.
	 *
	 * @return string|null
	 */
	public static function cache_key(): ?string {
		return self::$cache_key;
	}

	/**
	 * Flush epoch the lookup saw.
	 *
	 * @return int|null
	 */
	public static function flush_epoch(): ?int {
		return self::$flush_epoch;
	}

	/**
	 * Forget everything (intended for tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$early_booted        = false;
		self::$mu_template_version = null;
		self::$lookup_done         = false;
		self::$context             = null;
		self::$decision            = null;
		self::$cache_key           = null;
		self::$flush_epoch         = null;

		self::$outdated_snapshot         = false;
		self::$outdated_snapshot_rebuilt = false;
	}
}
