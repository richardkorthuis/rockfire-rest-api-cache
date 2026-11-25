<?php
/**
 * Decides whether a request may use the cache and whether a response may be stored.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Request;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Compiled_Route;
use Rockfire\Rest_Api_Cache\Config\Deny_List;
use Rockfire\Rest_Api_Cache\Config\Route_Rule;
use Rockfire\Rest_Api_Cache\Config\Settings_Schema;
use Rockfire\Rest_Api_Cache\Config\Vary_Cookies;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * The privacy and security rules of the cache.
 *
 * {@see self::evaluate()} runs before serving: it is must-use-phase safe (no filters, no options,
 * no superglobals, no I/O) and the first failing rule wins. {@see self::store_verdict()} runs again
 * before storing a response.
 *
 * @since 1.0.0
 */
final class Cache_Policy {

	/**
	 * Whether a regex failure was recorded in this request.
	 *
	 * @var bool
	 */
	private static bool $regex_failure_recorded = false;

	/**
	 * Maximum route length in bytes.
	 *
	 * @var int
	 */
	public const MAX_ROUTE_LENGTH = 191;

	/**
	 * Maximum raw query string length in bytes.
	 *
	 * @var int
	 */
	public const MAX_QUERY_LENGTH = 2048;

	/**
	 * Maximum number of invalidation tags per entry.
	 *
	 * @var int
	 */
	public const MAX_TAGS = 5000;

	/**
	 * Allowed values of the `context` parameter.
	 *
	 * @var list<string>
	 */
	public const CACHEABLE_CONTEXTS = [ 'view', 'embed' ];

	/**
	 * Parameters that change the response format.
	 *
	 * @var list<string>
	 */
	public const FORMAT_PARAMS = [ '_jsonp', '_envelope', '_pretty', '_method' ];

	/**
	 * Cookie prefixes that always bypass, whatever the configured prefixes are.
	 *
	 * @var list<string>
	 */
	public const ALWAYS_BYPASS_COOKIE_PREFIXES = [ 'wordpress_logged_in_', 'wordpress_sec_', 'wp-postpass_' ];

	/**
	 * The name of the default auth cookie (`wordpress_` plus the 32 hexadecimal characters of `COOKIEHASH`), which
	 * always bypasses too. `wordpress_test_cookie` and other `wordpress_` names do not match.
	 *
	 * @var string
	 */
	public const AUTH_COOKIE_PATTERN = '/^wordpress_[0-9a-f]{32}$/D';

	/**
	 * Returns whether caching is blocked by the coexistence guard.
	 *
	 * @var \Closure(): bool
	 */
	private \Closure $is_blocked;

	/**
	 * Constructor.
	 *
	 * @param \Closure $is_blocked Returns true when caching is blocked, e.g. `Coexistence_Guard::is_blocked(...)`.
	 * @phpstan-param \Closure(): bool $is_blocked
	 */
	public function __construct( \Closure $is_blocked ) {
		$this->is_blocked = $is_blocked;
	}

	/**
	 * Evaluate the request-phase rules. The first failing rule wins.
	 *
	 * The order: `disabled`, `coexistence`, `not_rest`, `method_override`, `credentials`, `filtered`, `context` and
	 * `status_param` (query, then the top-level body parameters), `route_too_long`, `denied_route`, the route match
	 * (`route_not_enabled`), `visitor_varies`, `method` (the method is not one of the route's methods), `body` (a GET
	 * carrying any body, or a non-GET body that cannot be keyed), `format_param` (query only), `skip_cache` and
	 * `bypass_param` (query or top-level body keys), the parameter limits (query only) and `vary_cookie`. The
	 * deny-list and the credential checks run before the method check, so no route rule can opt a credentialed or
	 * denied request in.
	 *
	 * A header the snapshot names (a credential `bypass_headers()` entry, or a `vary_headers` entry of the matched rule)
	 * that the context did not read is unknown, never absent ({@see Request_Context::has_read_header()}): an unread
	 * credential header is `credentials`, an unread vary header is `error` (an unread vary cookie is `vary_cookie`).
	 *
	 * A cacheable non-GET decision carries the Content-Type class of the body and the sha256 of its canonical form
	 * without the rule's ignored parameters, `_wpnonce` while nonce caching is on and `skip_cache` while skipping is
	 * not allowed. A GET decision is keyed exactly as before. Either carries the request host when it differs from
	 * the compiled home host (the empty host for a request that names none; never when the snapshot has no home host),
	 * which enters the key and nothing else.
	 *
	 * @param Request_Context $context Request.
	 * @param Compiled_Config $config  Compiled configuration.
	 *
	 * @return Policy_Decision
	 */
	public function evaluate( Request_Context $context, Compiled_Config $config ): Policy_Decision {
		return $this->decide( $context, $config, true );
	}

	/**
	 * Evaluate the request-phase rules for a URL that is flushed: the rules of {@see self::evaluate()} without the
	 * method check, so the key parameters of the URL are those its entries were stored with, whatever their method.
	 *
	 * Only for hashing a URL to flush it: the decision is never used to serve or store a response.
	 *
	 * @param Request_Context $context Request for the URL (see {@see Request_Context::url_variants()}).
	 * @param Compiled_Config $config  Compiled configuration.
	 *
	 * @return Policy_Decision
	 */
	public function evaluate_for_url( Request_Context $context, Compiled_Config $config ): Policy_Decision {
		return $this->decide( $context, $config, false );
	}

	/**
	 * The request-phase rules of {@see self::evaluate()}. The first failing rule wins.
	 *
	 * @param Request_Context $context      Request.
	 * @param Compiled_Config $config       Compiled configuration.
	 * @param bool            $check_method Whether the method must be one of the matched route's methods.
	 *
	 * @return Policy_Decision
	 */
	private function decide( Request_Context $context, Compiled_Config $config, bool $check_method ): Policy_Decision {
		// The RFRAC_DISABLE kill switch acts before the next recompile.
		if ( ! $config->enabled() || Settings_Schema::kill_switch() ) {
			return Policy_Decision::bypass( Bypass_Reason::DISABLED );
		}
		if ( true === ( $this->is_blocked )() ) {
			return Policy_Decision::bypass( Bypass_Reason::COEXISTENCE );
		}
		$route = $context->route;
		if ( ! $context->is_rest || null === $route ) {
			return Policy_Decision::bypass( Bypass_Reason::NOT_REST );
		}
		// Core would dispatch another method than the one the request is keyed by: never serve or store it.
		if ( $context->method_override ) {
			return Policy_Decision::bypass( Bypass_Reason::METHOD_OVERRIDE );
		}
		if ( [] !== self::credential_signals( $context, $config )
			|| self::has_login_cookie( $context, $config )
			|| $context->has_cookie_with_prefix( ...$config->bypass_cookie_prefixes() )
			|| self::has_credential_header( $context, $config )
		) {
			return Policy_Decision::bypass( Bypass_Reason::CREDENTIALS );
		}
		// A compile-time filter bypassed every request; the credential checks above keep their own reason.
		if ( $config->bypass_all() ) {
			return Policy_Decision::bypass( Bypass_Reason::FILTERED );
		}

		$params = $context->params;
		// A nonce-only request is cached under the key of the same request without the nonce.
		if ( $config->nonce_caching() ) {
			unset( $params['_wpnonce'] );
		}
		// The top-level body keys the parameter rules look at; empty for a GET. The body nonce is left out like the query one.
		$body = $context->body_params;
		if ( $config->nonce_caching() ) {
			unset( $body['_wpnonce'] );
		}
		foreach ( [ $params, $body ] as $source ) {
			$reason = self::non_public_reason( $source );
			if ( null !== $reason ) {
				return Policy_Decision::bypass( $reason );
			}
		}
		if ( strlen( $route ) > self::MAX_ROUTE_LENGTH ) {
			return Policy_Decision::bypass( Bypass_Reason::ROUTE_TOO_LONG );
		}

		// The deny-list runs on the concrete route before matching, so no rule can make a denied route cacheable.
		if ( Deny_List::is_denied( $route ) ) {
			return Policy_Decision::bypass( Bypass_Reason::DENIED_ROUTE );
		}

		$rule = $this->match( $route, $config );
		if ( false === $rule ) {
			return Policy_Decision::bypass( Bypass_Reason::ERROR );
		}
		if ( null === $rule || ! $rule->enabled ) {
			return Policy_Decision::bypass( Bypass_Reason::ROUTE_NOT_ENABLED );
		}
		// The responses of this route differ per visitor in a way no cache key captures: never serve or store them.
		if ( $rule->visitor_varies ) {
			return Policy_Decision::bypass( Bypass_Reason::VISITOR_VARIES, $rule );
		}
		// Only the methods the route opted in to (GET by default); after the deny-list and the credential checks.
		if ( $check_method && ! in_array( $context->method, $rule->methods, true ) ) {
			return Policy_Decision::bypass( Bypass_Reason::METHOD, $rule );
		}
		$is_get = 'GET' === $context->method;
		// Core reads a body ahead of the query for every method, so a GET that carries one is never keyed by it; a
		// multipart, unsupported, invalid or too large non-GET body has no canonical form to key it by.
		if ( $is_get ? $context->has_body : null !== $context->body_error ) {
			return Policy_Decision::bypass( Bypass_Reason::BODY, $rule );
		}

		foreach ( self::FORMAT_PARAMS as $name ) {
			if ( array_key_exists( $name, $params ) ) {
				return Policy_Decision::bypass( Bypass_Reason::FORMAT_PARAM, $rule );
			}
		}

		if ( array_key_exists( 'skip_cache', $params ) || array_key_exists( 'skip_cache', $body ) ) {
			if ( $config->allow_skip_cache() ) {
				return Policy_Decision::bypass( Bypass_Reason::SKIP_CACHE, $rule );
			}
			unset( $params['skip_cache'], $body['skip_cache'] );
		}

		foreach ( $rule->bypass_params as $name ) {
			if ( array_key_exists( $name, $params ) || array_key_exists( $name, $body ) ) {
				return Policy_Decision::bypass( Bypass_Reason::BYPASS_PARAM, $rule );
			}
		}

		foreach ( $rule->ignore_params as $name ) {
			unset( $params[ $name ] );
		}

		if ( Route_Rule::PARAM_ALLOW_ALL !== $rule->param_mode ) {
			$allowed = array_flip( array_merge( $rule->allowed_params, $config->universal_params() ) );
			foreach ( array_keys( $params ) as $name ) {
				if ( ! isset( $allowed[ (string) $name ] ) ) {
					return Policy_Decision::bypass( Bypass_Reason::UNKNOWN_PARAM, $rule );
				}
			}
		}

		if ( count( $params ) > $config->max_params() ) {
			return Policy_Decision::bypass( Bypass_Reason::TOO_MANY_PARAMS, $rule );
		}
		if ( $context->query_length > self::MAX_QUERY_LENGTH ) {
			return Policy_Decision::bypass( Bypass_Reason::QUERY_TOO_LONG, $rule );
		}
		if ( ! self::is_utf8( $route ) || ! self::is_utf8_params( $params ) ) {
			return Policy_Decision::bypass( Bypass_Reason::INVALID_ENCODING, $rule );
		}

		$vary = [];
		foreach ( $rule->vary_headers as $name ) {
			// A header the context did not read (a snapshot recompiled after the lookup) is unknown, never absent: keying it
			// as `''` would store the response under the variant of a request that lacks the header.
			if ( ! $context->has_read_header( $name ) ) {
				return Policy_Decision::bypass( Bypass_Reason::ERROR, $rule );
			}
			$vary[ $name ] = $context->header( $name ) ?? '';
		}

		// Each declared cookie keys the entry under its reserved `cookie:<name>` entry. A cookie the context did
		// not read (a snapshot swapped between lookup and capture) or an unlisted value must never key as another one.
		foreach ( $rule->vary_cookies as $name => $allowed ) {
			$name = (string) $name;
			if ( ! array_key_exists( $name, $context->vary_cookies ) ) {
				return Policy_Decision::bypass( Bypass_Reason::VARY_COOKIE, $rule );
			}
			$value = $context->vary_cookies[ $name ];
			if ( null === $value || ( '' !== $value && ! in_array( $value, $allowed, true ) ) ) {
				return Policy_Decision::bypass( Bypass_Reason::VARY_COOKIE, $rule );
			}
			$vary[ Vary_Cookies::key( $name ) ] = $value;
		}

		// The request host keys the entry only when it is not the site's home host, so no key of a normally served site changes.
		// A request that names no host is not on the home host either, and a site that builds its home URL from the Host header
		// renders host-less links for it: it keys under the empty host, which no named host equals.
		$request_host = $context->host ?? '';
		$host         = ( '' !== $config->home_host() && $request_host !== $config->home_host() ) ? $request_host : null;

		if ( $is_get ) {
			return Policy_Decision::cache( $rule, $params, $vary, null, null, $host );
		}

		// The body is keyed by its canonical form without the keys that do not change the response, like the query.
		$drop = $rule->ignore_params;
		if ( $config->nonce_caching() ) {
			$drop[] = '_wpnonce';
		}
		if ( ! $config->allow_skip_cache() ) {
			$drop[] = 'skip_cache';
		}
		return Policy_Decision::cache( $rule, $params, $vary, $context->content_class, hash( 'sha256', $context->canonical_body( $drop ) ), $host );
	}

	/**
	 * Why a set of parameters asks for data that is not public: a `context` other than `view` or `embed`, or a
	 * `status` other than `publish`.
	 *
	 * @param array<int|string,mixed> $params Query parameters or top-level body parameters.
	 *
	 * @return Bypass_Reason|null Null when neither is present with another value.
	 */
	private static function non_public_reason( array $params ): ?Bypass_Reason {
		if ( array_key_exists( 'context', $params ) && ! in_array( $params['context'], self::CACHEABLE_CONTEXTS, true ) ) {
			return Bypass_Reason::CONTEXT;
		}
		if ( array_key_exists( 'status', $params ) && 'publish' !== $params['status'] ) {
			return Bypass_Reason::STATUS_PARAM;
		}
		return null;
	}

	/**
	 * Store-phase rules: whether the generated response may be stored.
	 *
	 * @param Policy_Decision                   $decision         The request-phase decision.
	 * @param int                               $user_id          Current user ID.
	 * @param int                               $status           Response status code.
	 * @param int                               $size             Payload size in bytes.
	 * @param array<int|string,string|string[]> $response_headers Response headers: name => value(s), or `headers_list()` style `Name: value` lines.
	 * @param bool                              $is_empty         Whether the result set is empty.
	 * @param int                               $tag_count        Number of invalidation tags.
	 * @param int                               $entry_estimate   Estimated number of stored entries.
	 * @param Compiled_Config                   $config           Compiled configuration.
	 *
	 * @return Bypass_Reason|null Null when the response may be stored.
	 */
	public function store_verdict(
		Policy_Decision $decision,
		int $user_id,
		int $status,
		int $size,
		array $response_headers,
		bool $is_empty,
		int $tag_count,
		int $entry_estimate,
		Compiled_Config $config
	): ?Bypass_Reason {
		if ( ! $decision->cacheable ) {
			return $decision->reason ?? Bypass_Reason::FILTERED;
		}
		if ( 0 !== $user_id ) {
			return Bypass_Reason::USER;
		}
		if ( 200 !== $status ) {
			return Bypass_Reason::STATUS;
		}
		if ( $size > $config->max_payload_bytes() ) {
			return Bypass_Reason::TOO_LARGE;
		}
		if ( $is_empty && ! $this->cache_empty_results( $decision ) ) {
			return Bypass_Reason::EMPTY;
		}

		$headers = self::normalise_headers( $response_headers );
		if ( isset( $headers['set-cookie'] ) ) {
			return Bypass_Reason::SET_COOKIE;
		}
		foreach ( $headers['cache-control'] ?? [] as $value ) {
			// `no-cache` and `max-age=0` too: before WordPress 6.8 `wp_get_nocache_headers()` sends
			// only `no-cache, must-revalidate, max-age=0`, and a shared cache must honour the intent.
			if ( 1 === preg_match( '/(^|[\s,])(private|no-store|no-cache)([\s,=]|$)|(^|[\s,])max-age\s*=\s*0+([\s,]|$)/i', $value ) ) {
				return Bypass_Reason::PRIVATE;
			}
		}

		if ( $tag_count > self::MAX_TAGS ) {
			return Bypass_Reason::TOO_MANY_TAGS;
		}
		if ( $entry_estimate >= self::entry_ceiling( $config ) ) {
			return Bypass_Reason::ENTRY_CEILING;
		}
		return null;
	}

	/**
	 * The hard entry ceiling: 1.5 × `max_entries`, rounded up (integer arithmetic).
	 *
	 * A response is not stored once the entry estimate reaches it.
	 *
	 * @param Compiled_Config $config Compiled configuration.
	 *
	 * @return int
	 */
	public static function entry_ceiling( Compiled_Config $config ): int {
		return intdiv( 3 * $config->max_entries() + 1, 2 );
	}

	/**
	 * The cached entry count from which the capture recounts the stored entries: 80% of the ceiling.
	 *
	 * @param Compiled_Config $config Compiled configuration.
	 *
	 * @return int
	 */
	public static function entry_recount_floor( Compiled_Config $config ): int {
		return intdiv( 4 * self::entry_ceiling( $config ), 5 );
	}

	/**
	 * The first compiled route whose regex matches the route path; it may be a negative entry
	 * (`enabled` false), which makes the route not cacheable.
	 *
	 * A regex that fails to run (for example a PCRE backtrack limit) stops matching: a later, broader
	 * rule must not decide for this route. The failure is recorded (context `policy`, once per request).
	 *
	 * @param string          $route  Concrete route (path only, never the query string).
	 * @param Compiled_Config $config Configuration.
	 *
	 * @return Compiled_Route|null|false The rule, null when none matches, false when a regex failed.
	 */
	private function match( string $route, Compiled_Config $config ): Compiled_Route|null|false {
		foreach ( $config->routes() as $rule ) {
			$matched = preg_match( $rule->regex, $route );
			if ( 1 === $matched ) {
				return $rule;
			}
			if ( false === $matched ) {
				// Once per request: the recorder reads an option, which must not happen on every evaluation.
				if ( ! self::$regex_failure_recorded ) {
					self::$regex_failure_recorded = true;
					Failure_Recorder::record( 'policy', sprintf( 'The pattern of route %s could not be matched: %s', $rule->key, preg_last_error_msg() ), $route, false );
				}
				return false;
			}
		}
		return null;
	}

	/**
	 * The credential signals of the request that bypass the cache: all of them, except the two nonce carriers
	 * (`X-WP-Nonce`, `_wpnonce`) while the compiled `nonce_caching` flag is on.
	 *
	 * @param Request_Context $context Request.
	 * @param Compiled_Config $config  Compiled configuration.
	 *
	 * @return list<string>
	 */
	public static function credential_signals( Request_Context $context, Compiled_Config $config ): array {
		if ( ! $config->nonce_caching() ) {
			return $context->signals;
		}
		return array_values(
			array_diff( $context->signals, [ Request_Context::SIGNAL_X_WP_NONCE, Request_Context::SIGNAL_WPNONCE_PARAM ] )
		);
	}

	/**
	 * Whether a cookie name is covered by a rule no filter can remove: the always-bypass prefixes or the default
	 * auth cookie name. The compiler uses it to leave such names out of the snapshot's `login_cookies`, so the two
	 * cannot drift.
	 *
	 * @param string $name Cookie name as it appears in `$_COOKIE`.
	 *
	 * @return bool
	 */
	public static function is_unfilterable_login_cookie( string $name ): bool {
		foreach ( self::ALWAYS_BYPASS_COOKIE_PREFIXES as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}
		return 1 === preg_match( self::AUTH_COOKIE_PATTERN, $name );
	}

	/**
	 * Whether the request carries a login cookie: one covered by an unfilterable rule, or one of the site's
	 * compiled `login_cookies`.
	 *
	 * @param Request_Context $context Request.
	 * @param Compiled_Config $config  Compiled configuration.
	 *
	 * @return bool
	 */
	private static function has_login_cookie( Request_Context $context, Compiled_Config $config ): bool {
		$login_cookies = $config->login_cookies();
		foreach ( $context->cookie_names as $name ) {
			if ( self::is_unfilterable_login_cookie( $name ) || in_array( $name, $login_cookies, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the request carries a non-empty credential header of the compiled list (e.g. `X-API-Key`), or may carry one:
	 * a listed header the context did not read (a snapshot recompiled after the lookup) is unknown, never absent.
	 *
	 * @param Request_Context $context Request.
	 * @param Compiled_Config $config  Configuration.
	 *
	 * @return bool
	 */
	private static function has_credential_header( Request_Context $context, Compiled_Config $config ): bool {
		foreach ( $config->bypass_headers() as $name ) {
			if ( ! $context->has_read_header( $name ) ) {
				return true;
			}
			$value = $context->header( $name );
			if ( null !== $value && '' !== $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether empty result sets of this route are stored.
	 *
	 * @param Policy_Decision $decision Decision.
	 *
	 * @return bool
	 */
	private function cache_empty_results( Policy_Decision $decision ): bool {
		/**
		 * Filters whether empty result sets are cached.
		 *
		 * @see Hooks::CACHE_EMPTY_RESULTS
		 */
		return true === apply_filters( Hooks::CACHE_EMPTY_RESULTS, false, null !== $decision->rule ? $decision->rule->key : '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
	}

	/**
	 * Normalise response headers to lower-case name => list of values.
	 *
	 * @param array<int|string,string|string[]> $headers Name => value(s), or `Name: value` lines.
	 *
	 * @return array<string,list<string>>
	 */
	private static function normalise_headers( array $headers ): array {
		$out = [];
		foreach ( $headers as $name => $value ) {
			if ( is_int( $name ) ) {
				if ( ! is_string( $value ) || ! str_contains( $value, ':' ) ) {
					continue;
				}
				[ $name, $value ] = explode( ':', $value, 2 );
			}
			$name = strtolower( trim( $name ) );
			foreach ( (array) $value as $item ) {
				$out[ $name ][] = trim( (string) $item );
			}
		}
		return $out;
	}

	/**
	 * Whether a string is valid UTF-8.
	 *
	 * @param string $value Value.
	 *
	 * @return bool
	 */
	private static function is_utf8( string $value ): bool {
		return 1 === preg_match( '//u', $value );
	}

	/**
	 * Whether every key and value of a (nested) parameter array is valid UTF-8.
	 *
	 * @param array<mixed> $params Parameters.
	 *
	 * @return bool
	 */
	private static function is_utf8_params( array $params ): bool {
		foreach ( $params as $key => $value ) {
			if ( ! self::is_utf8( (string) $key ) ) {
				return false;
			}
			if ( is_array( $value ) ) {
				if ( ! self::is_utf8_params( $value ) ) {
					return false;
				}
			} elseif ( ! is_string( $value ) || ! self::is_utf8( $value ) ) {
				return false;
			}
		}
		return true;
	}
}
