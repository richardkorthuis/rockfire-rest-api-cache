<?php
/**
 * An entry to be stored.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable input of {@see Store_Interface::set()}.
 *
 * @since 1.0.0
 */
final class Entry_Data {

	/**
	 * Constructor.
	 *
	 * @param string               $cache_key Cache key.
	 * @param string               $url_hash  URL hash.
	 * @param string               $route     Matched route.
	 * @param string               $rule_key  Matched rule key.
	 * @param array<string,mixed>  $request   Request description (stored as JSON).
	 * @param string               $method    HTTP method.
	 * @param string               $payload   JSON payload.
	 * @param array<string,string> $headers   Response headers to replay.
	 * @param int                  $ttl       Time to live in seconds.
	 * @param string[]             $tags      Normalised tags.
	 *
	 * @phpstan-param list<string> $tags
	 */
	public function __construct(
		public readonly string $cache_key,
		public readonly string $url_hash,
		public readonly string $route,
		public readonly string $rule_key,
		public readonly array $request,
		public readonly string $method,
		public readonly string $payload,
		public readonly array $headers,
		public readonly int $ttl,
		public readonly array $tags
	) {
	}
}
