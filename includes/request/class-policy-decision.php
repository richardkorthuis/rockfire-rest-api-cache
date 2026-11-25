<?php
/**
 * Outcome of the request-phase cache policy.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Request;

use Rockfire\Rest_Api_Cache\Config\Compiled_Route;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result of {@see Cache_Policy::evaluate()}.
 *
 * A cacheable decision always has a rule and no reason; a bypass decision always has a reason. The
 * raw vary header values live in memory only: they are hashed before they reach a key or storage.
 *
 * @since 1.0.0
 */
final class Policy_Decision {

	/**
	 * Use {@see self::bypass()} or {@see self::cache()}.
	 *
	 * @param bool                                             $cacheable  Whether the request may be served from / stored in the cache.
	 * @param Bypass_Reason|null                               $reason     Why not (null when cacheable).
	 * @param Compiled_Route|null                              $rule       The matched route rule, when known.
	 * @param array<int|string,string|array<int|string,mixed>> $key_params Parameters that make up the cache key.
	 * @param array<string,string>                             $vary       Vary entry (header name, or the reserved `cookie:<name>` of a vary cookie) => raw value (missing = empty string).
	 * @param string|null                                      $body_class Content-Type class of a non-GET request body (never the body); null for a GET.
	 * @param string|null                                      $body_hash  Sha256 hex digest of the canonical body of a non-GET request; null for a GET. Never a raw body.
	 * @param string|null                                      $host       The request host when it differs from the site's home host (it then keys the entry); null otherwise and for a bypass.
	 */
	private function __construct(
		public readonly bool $cacheable,
		public readonly ?Bypass_Reason $reason,
		public readonly ?Compiled_Route $rule,
		public readonly array $key_params,
		public readonly array $vary,
		public readonly ?string $body_class = null,
		public readonly ?string $body_hash = null,
		public readonly ?string $host = null
	) {
	}

	/**
	 * A bypass decision.
	 *
	 * @param Bypass_Reason       $reason Reason.
	 * @param Compiled_Route|null $rule   The matched rule, when matching already happened.
	 *
	 * @return self
	 */
	public static function bypass( Bypass_Reason $reason, ?Compiled_Route $rule = null ): self {
		return new self( false, $reason, $rule, [], [] );
	}

	/**
	 * A cacheable decision.
	 *
	 * @param Compiled_Route                                   $rule       Matched rule.
	 * @param array<int|string,string|array<int|string,mixed>> $key_params Parameters that make up the cache key.
	 * @param array<string,string>                             $vary       Vary entry (header name or `cookie:<name>`) => raw value.
	 * @param string|null                                      $body_class Content-Type class of a non-GET request body; null for a GET.
	 * @param string|null                                      $body_hash  Sha256 hex digest of the canonical body of a non-GET request; null for a GET. Never a raw body.
	 * @param string|null                                      $host       The request host when it differs from the site's home host; null otherwise.
	 *
	 * @return self
	 */
	public static function cache( Compiled_Route $rule, array $key_params, array $vary, ?string $body_class = null, ?string $body_hash = null, ?string $host = null ): self {
		return new self( true, null, $rule, $key_params, $vary, $body_class, $body_hash, $host );
	}
}
