<?php
/**
 * The bridge of the store-phase hooks of the original plugin.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

defined( 'ABSPATH' ) || exit;

/**
 * Hands the store-phase decisions of a response to the callbacks of the original's `timeout`, `skip_empty_result_set`
 * and `skip_caching` filters.
 *
 * Each filter rides on the Rockfire hook of the same decision: the lifetime of the entry (`rfrac/entry_ttl`), whether
 * an empty result set is stored (`rfrac/cache_empty_results`) and whether the response is stored at all
 * (`rfrac/store_allowed`). The request is the one of {@see Request_State}: nothing is read from a superglobal.
 *
 * A legacy filter is only fired for a request whose lookup decision was cacheable. That decision already holds the
 * credential checks, so a request that carries credentials reaches no legacy callback. A callback that answers
 * nothing usable leaves the value of Rockfire alone, and `skip_caching` can only veto: it never overrides a refusal.
 *
 * One more veto is not tied to a lookup: without `RFRAC_LEGACY_HIT_FILTERS`, a hooked `cache_key` or
 * `filter_cache_output` makes nothing stored, because a cache hit cannot apply either. It fails closed, so
 * no lookup state lets such a response in. It runs last on `rfrac/store_allowed`, but a filter can be undone by a
 * callback at the same priority or removed outright, so {@see \Rockfire\Rest_Api_Cache\Serving\Capture} asks {@see self::refuses_store()} directly as
 * well, after the filter: no site callback lifts it. A refused store also records the finding that names the constant
 * ({@see Hook_Findings::record_store_refusal()}), because nothing else need have recorded it.
 *
 * @since 1.0.0
 */
final class Store_Bridge {

	use Hook_Subscriber_Trait;

	/**
	 * The hooks: the entry TTL (10, 3), the empty result sets (10, 2), the store veto (10, 4) and, last, the refusal
	 * to store while a hit filter is hooked that a cache hit cannot apply (PHP_INT_MAX, 1).
	 *
	 * @return array<int,array<int,mixed>>
	 *
	 * @phpstan-return list<array{0:string,1:'timeout'|'empty_results'|'store_allowed'|'refuse_unapplied_hit_filters',2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		return array(
			array( Hooks::ENTRY_TTL, 'timeout', 10, 3 ),
			array( Hooks::CACHE_EMPTY_RESULTS, 'empty_results', 10, 2 ),
			array( Hooks::STORE_ALLOWED, 'store_allowed', 10, 4 ),
			array( Hooks::STORE_ALLOWED, 'refuse_unapplied_hit_filters', PHP_INT_MAX, 1 ),
		);
	}

	/**
	 * Filter `rfrac/entry_ttl`: let the callbacks of `wp_rest_cache/timeout` set the lifetime of the entry.
	 *
	 * The options of the legacy filter have the URI of the request, an empty object type (it is not known yet) and no
	 * request headers; the method is the one of the request. A result that is not a number above zero leaves the TTL as it is.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $ttl      The TTL in seconds.
	 * @param mixed $route    Request route (unused).
	 * @param mixed $rule_key Key of the matched route rule (unused).
	 *
	 * @return mixed The TTL of a legacy callback as an integer, else the TTL given.
	 */
	public function timeout( mixed $ttl, mixed $route = null, mixed $rule_key = null ): mixed {
		unset( $route, $rule_key );
		$context = self::cacheable_context();
		if ( null === $context ) {
			return $ttl;
		}

		$options = array(
			'uri'             => Request_Uri::of( $context, rest_get_url_prefix() ),
			'object_type'     => '',
			'request_headers' => array(),
			'request_method'  => $context->method,
		);
		$legacy  = Bridge::filter( 'wp_rest_cache/timeout', array( $ttl, $options ) );

		return is_numeric( $legacy ) && $legacy > 0 ? (int) $legacy : $ttl;
	}

	/**
	 * Filter `rfrac/cache_empty_results`: let the callbacks of `wp_rest_cache/skip_empty_result_set` decide.
	 *
	 * The legacy filter says whether to skip an empty result set, so it is handed the inverse of the answer and its
	 * result is inverted back. Without a callback the answer is unchanged. The request handed to the callbacks carries
	 * the method and the query of the request, without a nonce; the body is not passed.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $cache    Whether empty result sets are stored.
	 * @param mixed $rule_key Key of the matched route rule (unused).
	 *
	 * @return mixed Whether empty result sets are stored.
	 */
	public function empty_results( mixed $cache, mixed $rule_key = null ): mixed {
		unset( $rule_key );
		$context = self::cacheable_context();
		if ( null === $context ) {
			return $cache;
		}

		$request = new \WP_REST_Request( $context->method, (string) $context->route );
		$request->set_query_params( Request_Uri::params( $context ) );
		$uri  = Request_Uri::of( $context, rest_get_url_prefix() );
		$skip = Bridge::filter( 'wp_rest_cache/skip_empty_result_set', array( ! $cache, $request, $uri ) );

		return ! $skip;
	}

	/**
	 * Filter `rfrac/store_allowed`: let the callbacks of `wp_rest_cache/skip_caching` veto storing.
	 *
	 * A veto only: a truthy answer of the legacy filter refuses the response, any other answer leaves the verdict
	 * of Rockfire alone. It does not depend on `RFRAC_LEGACY_HIT_FILTERS`.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $allowed  Whether the response is stored.
	 * @param mixed $route    Request route (unused).
	 * @param mixed $rule_key Key of the matched route rule (unused).
	 * @param mixed $size     Payload size in bytes (unused).
	 *
	 * @return mixed False when a legacy callback skips caching, else the verdict given.
	 */
	public function store_allowed( mixed $allowed, mixed $route = null, mixed $rule_key = null, mixed $size = null ): mixed {
		unset( $route, $rule_key, $size );
		if ( null === self::cacheable_context() ) {
			return $allowed;
		}

		$name = 'wp_rest_cache/skip_caching';
		if ( false === has_filter( $name ) ) {
			return $allowed;
		}

		// A callback that throws is recorded by the Bridge, which hands back the default (no veto). Store-side that is
		// the wrong way round, so a marker after the last callback tells a chain that ran from one that was cut short.
		$completed = false;
		$marker    = static function ( mixed $skip ) use ( &$completed ): mixed {
			$completed = true;
			return $skip;
		};
		add_filter( $name, $marker, PHP_INT_MAX );
		try {
			$skip = Bridge::filter( $name, array( false ) );
		} finally {
			remove_filter( $name, $marker, PHP_INT_MAX );
		}

		return $skip || ! $completed ? false : $allowed;
	}

	/**
	 * Filter `rfrac/store_allowed`, last: refuse to store while a hit filter is hooked that a cache hit cannot apply.
	 *
	 * The verdict of {@see self::refuses_store()} on the filter: false while it refuses, else the verdict given. A later
	 * callback at the same priority can still turn the answer of this filter back into `true`, which is why
	 * {@see \Rockfire\Rest_Api_Cache\Serving\Capture} asks {@see self::refuses_store()} itself and does not rely on this.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $allowed Whether the response is stored.
	 *
	 * @return mixed False while such a callback is hooked and the constant is not defined, else the verdict given.
	 */
	public function refuse_unapplied_hit_filters( mixed $allowed ): mixed {
		return self::refuses_store() ? false : $allowed;
	}

	/**
	 * Whether a response is not stored because a hit filter is hooked that a cache hit cannot apply.
	 *
	 * Without `RFRAC_LEGACY_HIT_FILTERS` a hit applies neither `wp_rest_cache/cache_key` nor
	 * `wp_rest_cache/filter_cache_output`, so a stored response could be served to a visitor it was not meant for. With
	 * a callback on either, nothing is stored, whatever the state of the lookup: the answer reads no request and fires
	 * no legacy hook. It is not a filter, so no callback of a site can change it.
	 *
	 * When it refuses, the finding that names the hook and the constant is recorded if the legacy findings do not hold
	 * it yet ({@see Hook_Findings::record_store_refusal()}): the callback may have been hooked without a compile, so
	 * the refusal never runs without a finding to explain it. That costs a read of the findings option, and a write
	 * only the first time. A failure to record it never lifts the refusal.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True while such a callback is hooked and the constant is not defined.
	 */
	public static function refuses_store(): bool {
		if ( Hook_Registry::hit_filters_enabled() ) {
			return false;
		}

		if ( false === has_filter( 'wp_rest_cache/cache_key' ) && false === has_filter( 'wp_rest_cache/filter_cache_output' ) ) {
			return false;
		}

		try {
			Hook_Findings::record_store_refusal( new Findings() );
		} catch ( \Throwable $e ) {
			unset( $e ); // The store stays refused; the next compile records the finding.
		}

		return true;
	}

	/**
	 * The request of the lookup, when its decision was cacheable.
	 *
	 * @return Request_Context|null Null without a lookup, for a request that is not a REST request and for a decision
	 *                              that was a bypass (credentials included).
	 */
	private static function cacheable_context(): ?Request_Context {
		$context  = Request_State::context();
		$decision = Request_State::decision();
		if ( null === $context || null === $context->route || null === $decision || ! $decision->cacheable ) {
			return null;
		}
		return $context;
	}
}
