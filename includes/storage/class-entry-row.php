<?php
/**
 * A cache entry as listed in the admin and CLI.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable listing row of an entry (without payload and headers).
 *
 * @since 1.0.0
 */
final class Entry_Row {

	/**
	 * State value of an active entry.
	 *
	 * @var int
	 */
	public const STATE_ACTIVE = 1;

	/**
	 * State value of a flushed entry.
	 *
	 * @var int
	 */
	public const STATE_FLUSHED = 2;

	/**
	 * Constructor.
	 *
	 * @param int         $id          Entry id.
	 * @param string      $cache_key   Cache key (sha256 hex).
	 * @param string      $url_hash    URL hash.
	 * @param string      $route       Route.
	 * @param string      $rule_key    Rule key of the matching route rule.
	 * @param string      $request     Request description.
	 * @param string      $method      HTTP method.
	 * @param int         $state       1 active, 2 flushed.
	 * @param int         $size        Payload size in bytes.
	 * @param int         $hits        Hit counter.
	 * @param string      $created_at  Creation time (UTC, `Y-m-d H:i:s`).
	 * @param string      $expires_at  Expiry time (UTC).
	 * @param string      $last_hit_at Last hit time (UTC).
	 * @param string|null $flushed_at  Flush time (UTC), null when never flushed.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $cache_key,
		public readonly string $url_hash,
		public readonly string $route,
		public readonly string $rule_key,
		public readonly string $request,
		public readonly string $method,
		public readonly int $state,
		public readonly int $size,
		public readonly int $hits,
		public readonly string $created_at,
		public readonly string $expires_at,
		public readonly string $last_hit_at,
		public readonly ?string $flushed_at
	) {
	}

	/**
	 * Build a row from a database result row.
	 *
	 * @param array<string,mixed> $row Associative row (column => value).
	 *
	 * @return self
	 */
	public static function from_db( array $row ): self {
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['cache_key'] ?? '' ),
			(string) ( $row['url_hash'] ?? '' ),
			(string) ( $row['route'] ?? '' ),
			(string) ( $row['rule_key'] ?? '' ),
			(string) ( $row['request'] ?? '' ),
			(string) ( $row['method'] ?? 'GET' ),
			(int) ( $row['state'] ?? 1 ),
			(int) ( $row['size'] ?? 0 ),
			(int) ( $row['hits'] ?? 0 ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['expires_at'] ?? '' ),
			(string) ( $row['last_hit_at'] ?? '' ),
			isset( $row['flushed_at'] ) ? (string) $row['flushed_at'] : null
		);
	}
}
