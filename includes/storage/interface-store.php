<?php
/**
 * Contract of a cache entry store.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Where cached REST responses live. Implementations never fire actions or filters.
 *
 * @since 1.0.0
 */
interface Store_Interface {

	/**
	 * Store name: `database` or `object_cache`.
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * The active, unexpired response of a cache key.
	 *
	 * @param string $cache_key Cache key.
	 *
	 * @return Cached_Response|null Null on a miss.
	 */
	public function get( string $cache_key ): ?Cached_Response;

	/**
	 * Count a hit (sampled inside the store).
	 *
	 * @param Cached_Response $hit The response that was served.
	 *
	 * @return void
	 */
	public function record_hit( Cached_Response $hit ): void;

	/**
	 * Store an entry (insert or refresh) and replace its tags.
	 *
	 * @param Entry_Data $entry Entry.
	 *
	 * @return int|null Entry id. Null when the entry was not stored, or was stored but could not be made
	 *                  invalidatable (its tags were not written); it is then flagged flushed and never served.
	 *                  0 when a concurrent flush or delete hit the entry while it was being stored: it is
	 *                  not served, but nothing failed.
	 */
	public function set( Entry_Data $entry ): ?int;

	/**
	 * Flush the active entries carrying any of the tags.
	 *
	 * @param string[] $tags Normalised tags.
	 *
	 * @phpstan-param list<string> $tags
	 *
	 * @return int Entries flushed.
	 */
	public function flush_by_tags( array $tags ): int;

	/**
	 * Flush active entries by id.
	 *
	 * @param int[] $ids Entry ids.
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @return int Entries flushed.
	 */
	public function flush_by_ids( array $ids ): int;

	/**
	 * Flush active entries by cache key.
	 *
	 * @param string[] $keys Cache keys.
	 *
	 * @phpstan-param list<string> $keys
	 *
	 * @return int Entries flushed.
	 */
	public function flush_by_keys( array $keys ): int;

	/**
	 * Flush active entries by URL hash.
	 *
	 * @param string $hash URL hash.
	 *
	 * @return int Entries flushed.
	 */
	public function flush_by_url_hash( string $hash ): int;

	/**
	 * Flush the active entries of a route, its subtree or a rule key.
	 *
	 * @param string $route Route or rule key.
	 *
	 * @return int Entries flushed.
	 */
	public function flush_route( string $route ): int;

	/**
	 * Flush every active entry.
	 *
	 * @return int Entries flushed.
	 */
	public function flush_all(): int;

	/**
	 * Hard-delete entries with their tags and payload.
	 *
	 * @param int[] $entry_ids Entry ids.
	 *
	 * @phpstan-param list<int> $entry_ids
	 *
	 * @return int Entries deleted.
	 */
	public function delete( array $entry_ids ): int;

	/**
	 * Hard-delete up to a batch of flushed or expired entries.
	 *
	 * @param int $batch_size Maximum entries to delete.
	 *
	 * @return int Entries deleted.
	 */
	public function prune( int $batch_size ): int;

	/**
	 * Delete the least recently hit active entries above the cap.
	 *
	 * @param int $max_entries Maximum number of active entries.
	 * @param int $batch_size  Maximum entries to delete in this call.
	 *
	 * @return int Entries deleted.
	 */
	public function enforce_cap( int $max_entries, int $batch_size ): int;

	/**
	 * The stored payload of an entry (for the admin details view).
	 *
	 * @param int $entry_id Entry id.
	 *
	 * @return string|null Null when the entry or its payload is gone.
	 */
	public function payload_for( int $entry_id ): ?string;
}
