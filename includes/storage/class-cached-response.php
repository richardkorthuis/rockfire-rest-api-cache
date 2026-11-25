<?php
/**
 * A cache hit.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable response returned by a store on a hit.
 *
 * @since 1.0.0
 */
final class Cached_Response {

	/**
	 * Constructor.
	 *
	 * @param int                  $entry_id   Entry id.
	 * @param string               $cache_key  Cache key.
	 * @param string               $payload    JSON payload.
	 * @param array<string,string> $headers    Response headers.
	 * @param int                  $created_at Creation time (Unix timestamp).
	 * @param int                  $expires_at Expiry time (Unix timestamp).
	 */
	public function __construct(
		public readonly int $entry_id,
		public readonly string $cache_key,
		public readonly string $payload,
		public readonly array $headers,
		public readonly int $created_at,
		public readonly int $expires_at
	) {
	}
}
