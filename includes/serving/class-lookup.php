<?php
/**
 * Runs the context, policy, key, store and writer chain for one request.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Serving;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Legacy\Hit_Filter_Pass;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Cache_Policy;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Storage\Cached_Response;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Factory;
use Rockfire\Rest_Api_Cache\Storage\Store_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Serves a cached hit and ends the request, or records the outcome in {@see Request_State}.
 *
 * Hit path: applies no filters and fires no actions of its own (reading options still runs core's
 * option filters), unless the site defines `RFRAC_LEGACY_HIT_FILTERS` and so opts into the legacy filters
 * of {@see Hit_Filter_Pass}. `Cache_Key` and `Store_Factory` are static classes, so they are injected as
 * closures (defaulting to `Cache_Key::key()` and `Store_Factory::for_mode()`). A failure of the
 * chain is recorded with {@see Failure_Recorder} (context `lookup`).
 *
 * An injected {@see Hit_Filters_Interface} takes part strictly after the policy passed the credential
 * checks: `decide()` on a cacheable decision, `key()` on the computed key, `response()` on a hit (before it
 * is counted) and `cors()` for the writer. Without one, the chain behaves and costs exactly as before.
 *
 * @since 1.0.0
 */
final class Lookup {

	/**
	 * Builds the cache key, `( Request_Context, Policy_Decision ): string`.
	 *
	 * @var \Closure
	 * @phpstan-var \Closure(Request_Context, Policy_Decision): string
	 */
	private \Closure $key_function;

	/**
	 * Builds the store, `( Compiled_Config ): Store_Interface`.
	 *
	 * @var \Closure
	 * @phpstan-var \Closure(Compiled_Config): Store_Interface
	 */
	private \Closure $store_factory;

	/**
	 * Constructor.
	 *
	 * @param Cache_Policy               $policy        Request-phase policy.
	 * @param Repository                 $repository    Repository handed to the default store factory.
	 * @param Response_Writer            $writer        Writes a hit.
	 * @param \Closure|null              $key_function  Optional key builder; default `Cache_Key::key()`.
	 * @param \Closure|null              $store_factory Optional `( Compiled_Config ): Store_Interface`; default builds the store from the config's mode and hit sampling.
	 * @param Hit_Filters_Interface|null $filters       Optional opt-in pass that decides, keys and transforms hits; default none.
	 *
	 * @phpstan-param (\Closure(Request_Context, Policy_Decision): string)|null $key_function
	 * @phpstan-param (\Closure(Compiled_Config): Store_Interface)|null $store_factory
	 */
	public function __construct(
		private readonly Cache_Policy $policy,
		private readonly Repository $repository,
		private readonly Response_Writer $writer,
		?\Closure $key_function = null,
		?\Closure $store_factory = null,
		private readonly ?Hit_Filters_Interface $filters = null
	) {
		$this->key_function  = $key_function ?? static fn( Request_Context $context, Policy_Decision $decision ): string => Cache_Key::key( $context, $decision );
		$this->store_factory = $store_factory ?? fn( Compiled_Config $config ): Store_Interface => Store_Factory::for_mode( $config->store_mode(), $this->repository, $config->hit_sampling() );
	}

	/**
	 * The hit filter set of this site; null unless the site opted in.
	 *
	 * Resolved by the code that builds a lookup ({@see Early_Server::boot()} and the container). A site opts in
	 * by defining `RFRAC_LEGACY_HIT_FILTERS` (anything but `false`), which brings the legacy filters of the
	 * original plugin onto hits. Without it the only work is the `defined()` check, so hits behave and cost exactly
	 * as before. The constant is spelled out here, not read from `Hook_Registry::HIT_FILTERS_CONSTANT`, so that a
	 * request without it loads nothing of the legacy module.
	 *
	 * @return Hit_Filters_Interface|null
	 */
	public static function hit_filters(): ?Hit_Filters_Interface {
		return defined( 'RFRAC_LEGACY_HIT_FILTERS' ) && false !== constant( 'RFRAC_LEGACY_HIT_FILTERS' ) ? new Hit_Filter_Pass() : null;
	}

	/**
	 * Evaluate the request; on a hit send it and end the request, otherwise record the outcome.
	 *
	 * Every `\Throwable` of the lookup itself is caught and recorded as a bypass. The writer runs
	 * outside the catch, so the termination in {@see Response_Writer::send_hit()} is never swallowed.
	 * The hit filter set, when injected, runs inside the catch and only for a decision the policy left
	 * cacheable, so never for a request with credentials.
	 *
	 * A site that is archived, spammed or deleted on multisite is served nothing and records a `disabled` bypass
	 * before the policy runs. A cacheable miss records the flush epoch it saw, and fails (an `error` bypass, recorded) when
	 * it cannot read it, as Capture would then have no baseline; a hit, a non-cacheable decision and a failed lookup
	 * read none. A lookup that ends without a hit on a pending or stale snapshot (pending discovery, or an empty
	 * fingerprint) records that in {@see Request_State}; a hit, a suspended site and a failed lookup record nothing of it.
	 *
	 * @param Request_Context $context Request context.
	 * @param Compiled_Config $config  Compiled config.
	 *
	 * @return void
	 */
	public function run( Request_Context $context, Compiled_Config $config ): void {
		$decision = null;
		$key      = null;
		$hit      = null;
		$cors     = null;
		$epoch    = null;

		try {
			if ( self::site_suspended() ) {
				Request_State::record_lookup( $context, Policy_Decision::bypass( Bypass_Reason::DISABLED ), null );
				return;
			}
			$decision = $this->policy->evaluate( $context, $config );
			if ( $decision->cacheable && null !== $this->filters ) {
				$decision = $this->filters->decide( $decision, $context, $config );
			}
			if ( $decision->cacheable ) {
				$key = ( $this->key_function )( $context, $decision );
				if ( null !== $this->filters ) {
					$key = $this->filters->key( $key, $context, $decision, $config );
				}
				if ( null === $key ) {
					$decision = Policy_Decision::bypass( Bypass_Reason::FILTERED, $decision->rule );
				} else {
					$store = ( $this->store_factory )( $config );
					$hit   = $store->get( $key );
					if ( null !== $hit && null !== $this->filters ) {
						$hit = $this->filters->response( $hit, $context, $config );
						if ( null === $hit ) {
							$decision = Policy_Decision::bypass( Bypass_Reason::FILTERED, $decision->rule );
						}
					}
					if ( null !== $hit ) {
						$this->record_hit( $store, $hit );
						$cors = $this->filters?->cors( $context, $config );
					} elseif ( $decision->cacheable ) {
						// A miss that stays cacheable: the epoch seen here is what Capture compares at store time.
						$epoch = $this->current_epoch();
					}
				}
			}
		} catch ( \Throwable $e ) {
			Failure_Recorder::record( 'lookup', $e, (string) $context->route );
			Request_State::record_lookup( $context, Policy_Decision::bypass( Bypass_Reason::ERROR ), null );
			return;
		}

		if ( null !== $hit ) {
			$this->writer->send_hit( $hit, $context, $config, $decision, $cors );
		}

		// Only a lookup that did not hit: this request's own recompile may rebuild the snapshot it ran on (see Capture).
		if ( $config->pending_discovery() || '' === $config->fingerprint() ) {
			Request_State::record_outdated_snapshot();
		}
		Request_State::record_lookup( $context, $decision, $key, $epoch );
	}

	/**
	 * Number of flushes committed on the current site, read from the database.
	 *
	 * Without it Capture has no baseline to compare with, so a failed read fails the lookup (recorded, `error`) and the
	 * response is not stored.
	 *
	 * @return int
	 *
	 * @throws \RuntimeException When the epoch could not be read.
	 */
	private function current_epoch(): int {
		$epoch = $this->repository->flush_epoch();
		if ( null === $epoch ) {
			throw new \RuntimeException( 'The flush epoch could not be read.' );
		}
		return $epoch;
	}

	/**
	 * Whether the current multisite site is archived, marked as spam or deleted (`'2'` included).
	 *
	 * Reads only `$GLOBALS['current_blog']`, which core has set before any must-use file loads: no `get_site()`,
	 * no option read and no filter, so a hit costs nothing more. Both serving paths run before core's
	 * `ms_site_check()`, which is what turns such a site away; without this check an entry primed before the
	 * site was suspended would still be served.
	 *
	 * @return bool
	 */
	private static function site_suspended(): bool {
		if ( ! is_multisite() ) {
			return false;
		}
		$site = $GLOBALS['current_blog'] ?? null;
		return $site instanceof \WP_Site && ( '0' !== $site->archived || '0' !== $site->spam || '0' !== $site->deleted );
	}

	/**
	 * Count a hit; a failure only loses the statistic (it is recorded, context `hit`), the hit is still served.
	 *
	 * @param Store_Interface $store Store.
	 * @param Cached_Response $hit   Hit.
	 *
	 * @return void
	 */
	private function record_hit( Store_Interface $store, Cached_Response $hit ): void {
		try {
			$store->record_hit( $hit );
		} catch ( \Throwable $e ) {
			Failure_Recorder::record( 'hit', $e ); // Hit statistics are best-effort; the failure is only recorded.
		}
	}
}
