<?php
/**
 * The legacy filters of a cache hit, applied per request.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Vary_Cookies;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Hit_Filters_Interface;
use Rockfire\Rest_Api_Cache\Storage\Cached_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the six hit-time filters of the original plugin (`skip_caching`, `allow_skip_cache`, `cache_key`,
 * `filter_cache_output`, `pre_output_headers` and `disable_cors_headers`) on both serving paths.
 *
 * The set exists only for a site that defines `RFRAC_LEGACY_HIT_FILTERS` ({@see \Rockfire\Rest_Api_Cache\Serving\Lookup::hit_filters()}):
 * it is the documented exception to the rule that a hit applies no plugin filters, and it makes hits slower. Every
 * method runs strictly after the credential checks of the policy, so a request with credentials never reaches a
 * callback. The filters are plain filters: a hit never fires a deprecation notice.
 *
 * Fail closed: a callback that throws, or whose answer cannot be used (a `cache_key` that is not a non-empty string,
 * an output that does not encode, headers that are not an array of scalars), is recorded with {@see Failure_Recorder}
 * (context `hook:wp_rest_cache/<name>`), and the request is then not served from the cache, nor stored (`filtered`),
 * so the live response is returned. A deliberate skip (`skip_caching`, `allow_skip_cache`) is no failure.
 * Which hook this class applies is bounded by {@see Hook_Registry::hit_filters()}.
 *
 * @since 1.0.0
 */
final class Hit_Filter_Pass implements Hit_Filters_Interface {

	/**
	 * Skip caching altogether, or only for a request with `skip_cache` (when it is not allowed by the configuration).
	 *
	 * A truthy `wp_rest_cache/skip_caching` bypasses with `filtered`; the `skip_cache` parameter bypasses with
	 * `skip_cache` when the filter `wp_rest_cache/allow_skip_cache` (default: the configuration) says so. A throwing
	 * callback bypasses with `filtered`.
	 *
	 * @since 1.0.0
	 *
	 * @param Policy_Decision $decision Cacheable decision of the policy.
	 * @param Request_Context $context  Request context.
	 * @param Compiled_Config $config   Compiled config.
	 *
	 * @return Policy_Decision The same decision, or a bypass decision.
	 */
	public function decide( Policy_Decision $decision, Request_Context $context, Compiled_Config $config ): Policy_Decision {
		$name = 'skip_caching';
		try {
			if ( apply_filters( 'wp_rest_cache/skip_caching', false ) ) {
				return Policy_Decision::bypass( Bypass_Reason::FILTERED, $decision->rule );
			}

			$name = 'allow_skip_cache';
			if ( array_key_exists( 'skip_cache', $context->params ) && apply_filters( 'wp_rest_cache/allow_skip_cache', $config->allow_skip_cache() ) ) {
				return Policy_Decision::bypass( Bypass_Reason::SKIP_CACHE, $decision->rule );
			}
		} catch ( \Throwable $e ) {
			self::record( $name, $e, $context );
			return Policy_Decision::bypass( Bypass_Reason::FILTERED, $decision->rule );
		}

		return $decision;
	}

	/**
	 * Filter the cache key.
	 *
	 * The callbacks get the key of the engine, the request URI of the original, the vary entries of the request
	 * (the vary cookies are not request headers, so they stay out of what the callback sees) and the method of the
	 * request (`GET`, or the opted-in `POST`, `PUT` or `PATCH`). A result identical to the key is kept byte for byte;
	 * another non-empty string is hashed into a key of the same shape together with every component the engine key
	 * keeps apart (blog, scheme, all vary entries including the vary cookies and, for a request that is not a GET, its
	 * method, body class and body hash, see {@see \Rockfire\Rest_Api_Cache\Request\Cache_Key::replaced()}), so a callback
	 * can not merge visitors the engine keeps apart; anything else, or a throwing callback, bypasses the cache and is
	 * recorded.
	 *
	 * @since 1.0.0
	 *
	 * @param string          $key      Cache key built by the engine.
	 * @param Request_Context $context  Request context.
	 * @param Policy_Decision $decision Cacheable decision.
	 * @param Compiled_Config $config   Compiled config.
	 *
	 * @return string|null The key to look up and to store under; null bypasses the cache (`filtered`).
	 */
	public function key( string $key, Request_Context $context, Policy_Decision $decision, Compiled_Config $config ): ?string {
		try {
			$headers = array();
			foreach ( $decision->vary as $name => $value ) {
				if ( ! str_starts_with( $name, Vary_Cookies::KEY_PREFIX ) ) {
					$headers[ $name ] = $value;
				}
			}

			$result = apply_filters( 'wp_rest_cache/cache_key', $key, Request_Uri::of( $context, $config->rest_prefix() ), $headers, $context->method );
		} catch ( \Throwable $e ) {
			self::record( 'cache_key', $e, $context );
			return null;
		}

		if ( $result === $key ) {
			return $key;
		}
		if ( is_string( $result ) && '' !== $result ) {
			return self::replacement( $result, $context, $decision );
		}

		self::record( 'cache_key', sprintf( 'The filter returned %s instead of a non-empty string; the request bypasses the cache.', '' === $result ? 'an empty string' : gettype( $result ) ), $context );
		return null;
	}

	/**
	 * The key a replacement string of the `cache_key` filter is stored under.
	 *
	 * The replacement is no longer the key by itself: {@see \Rockfire\Rest_Api_Cache\Request\Cache_Key::replaced()} hashes
	 * it together with the blog, the scheme and the whole vary map of the decision (cookie entries as well), and for a
	 * request that is not a GET with its method, the Content-Type class of its body and the hash of the canonical body
	 * (never the body itself). A callback that answers one string for two visitors the engine keeps apart, for two
	 * bodies, or for a GET and a POST of one URI, can therefore never make them share a key.
	 *
	 * @param string          $replacement Non-empty string the filter returned.
	 * @param Request_Context $context     Request context.
	 * @param Policy_Decision $decision    Cacheable decision (its vary map and, for a non-GET, body class and body hash are used).
	 *
	 * @return string Sha256 hex digest.
	 */
	private static function replacement( string $replacement, Request_Context $context, Policy_Decision $decision ): string {
		return Cache_Key::replaced( $replacement, $context, $decision );
	}

	/**
	 * Filter the output and the headers of a hit.
	 *
	 * The payload goes through `wp_rest_cache/filter_cache_output` only while a callback is hooked, as decoded JSON
	 * (objects) that is encoded again. The headers go through `wp_rest_cache/pre_output_headers` and must come back
	 * as an array of scalars. Either failing, a stored payload that does not decode, or a throwing callback, serves
	 * nothing (`filtered`) and is recorded. The writer still replays the headers through its allow-list and sanitiser.
	 *
	 * @since 1.0.0
	 *
	 * @param Cached_Response $hit     The stored response.
	 * @param Request_Context $context Request context.
	 * @param Compiled_Config $config  Compiled config.
	 *
	 * @return Cached_Response|null The response to serve; null does not serve the hit (`filtered`).
	 */
	public function response( Cached_Response $hit, Request_Context $context, Compiled_Config $config ): ?Cached_Response {
		$name = 'filter_cache_output';
		try {
			$payload = $hit->payload;
			if ( has_filter( 'wp_rest_cache/filter_cache_output' ) ) {
				$data = json_decode( $payload );
				if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
					self::record( $name, sprintf( 'The stored payload could not be decoded for the filter (%s); the hit was not served.', json_last_error_msg() ), $context );
					return null;
				}

				$payload = wp_json_encode( apply_filters( 'wp_rest_cache/filter_cache_output', $data ) );
				if ( ! is_string( $payload ) ) {
					self::record( $name, sprintf( 'The filtered output could not be encoded as JSON (%s); the hit was not served.', json_last_error_msg() ), $context );
					return null;
				}
			}

			$name    = 'pre_output_headers';
			$headers = apply_filters( 'wp_rest_cache/pre_output_headers', $hit->headers, Request_Uri::of( $context, $config->rest_prefix() ) );
			if ( ! is_array( $headers ) ) {
				self::record( $name, sprintf( 'The filter returned %s instead of an array; the hit was not served.', gettype( $headers ) ), $context );
				return null;
			}
			$replay = array();
			foreach ( $headers as $header => $value ) {
				if ( ! is_scalar( $value ) ) {
					self::record( $name, sprintf( 'The filter returned %s as a header value instead of a scalar; the hit was not served.', gettype( $value ) ), $context );
					return null;
				}
				$replay[ (string) $header ] = (string) $value;
			}
		} catch ( \Throwable $e ) {
			self::record( $name, $e, $context );
			return null;
		}

		return new Cached_Response( $hit->entry_id, $hit->cache_key, $payload, $replay, $hit->created_at, $hit->expires_at );
	}

	/**
	 * Whether a hit carries the `Access-Control-Allow-*` headers.
	 *
	 * The filter of the original turns the headers off, so it can only do that: it is asked only while the
	 * configuration sends the headers (it then starts from `false`, like its fold at compile time), and only the
	 * answer `true` disables them. A configuration without the headers keeps them off whatever a callback answers.
	 * A throwing callback turns them off as well.
	 *
	 * @since 1.0.0
	 *
	 * @param Request_Context $context Request context.
	 * @param Compiled_Config $config  Compiled config.
	 *
	 * @return bool
	 */
	public function cors( Request_Context $context, Compiled_Config $config ): bool {
		if ( ! $config->cors_on_hits() ) {
			return false;
		}

		try {
			return true !== apply_filters( 'wp_rest_cache/disable_cors_headers', false );
		} catch ( \Throwable $e ) {
			self::record( 'disable_cors_headers', $e, $context );
			return false;
		}
	}

	/**
	 * Record a callback that threw, or an answer that cannot be used, in the context `hook:wp_rest_cache/<name>`.
	 *
	 * @param string            $name    Name of the hook without its prefix.
	 * @param \Throwable|string $error   What the callback threw, or what was wrong with its answer.
	 * @param Request_Context   $context Request context; its route is the subject of the failure.
	 *
	 * @return void
	 */
	private static function record( string $name, \Throwable|string $error, Request_Context $context ): void {
		Failure_Recorder::record( 'hook:' . Hook_Registry::PREFIX . $name, $error, (string) $context->route );
	}
}
