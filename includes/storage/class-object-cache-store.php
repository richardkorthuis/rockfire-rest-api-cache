<?php
/**
 * Store serving payloads from the object cache.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Storage;

use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Names;

defined( 'ABSPATH' ) || exit;

/**
 * Payloads and headers live in the `rfrac` object-cache group, metadata and tags in the database.
 *
 * A hit costs two object-cache reads (generation, entry) and no SQL. Correctness relies on every flush,
 * delete and prune path removing the object keys (or bumping the generation for a full flush); `get()`
 * additionally validates the cached value and its expiry. Uses only `wp_cache_*`, never fires hooks and
 * never uses transients. The group is not global, so on multisite every site has its own keys.
 *
 * @since 1.0.0
 */
final class Object_Cache_Store implements Store_Interface {

	/**
	 * Object-cache key of the generation counter.
	 *
	 * @var string
	 */
	private const GENERATION_KEY = 'generation';

	/**
	 * Object-cache key of the pending hit counts.
	 *
	 * @var string
	 */
	private const HIT_BUFFER_KEY = 'hit_buffer';

	/**
	 * Entries repository.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Sampling rate N: one hit in N is buffered (as N hits). 0 disables hit counting.
	 *
	 * @var int
	 */
	private int $hit_sampling;

	/**
	 * Random integer source, called as `( 1, N )`.
	 *
	 * @var \Closure
	 */
	private \Closure $rng;

	/**
	 * Constructor.
	 *
	 * @param Repository    $repository   Entries repository.
	 * @param int           $hit_sampling Sampling rate N (0 = never count).
	 * @param \Closure|null $rng          Random integer source `fn( int $min, int $max ): int`; defaults to `wp_rand`, or `random_int` in the must-use phase, before the pluggable functions are loaded.
	 */
	public function __construct( Repository $repository, int $hit_sampling, ?\Closure $rng = null ) {
		$this->repository   = $repository;
		$this->hit_sampling = max( 0, $hit_sampling );
		$this->rng          = $rng ?? static fn( int $min, int $max ): int => function_exists( 'wp_rand' ) ? wp_rand( $min, $max ) : random_int( $min, $max );
	}

	/**
	 * Store name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'object_cache';
	}

	/**
	 * The current generation, or null when it is not in the cache.
	 *
	 * @return string|null
	 */
	private function generation(): ?string {
		$gen = wp_cache_get( self::GENERATION_KEY, Names::CACHE_GROUP );
		return ( is_int( $gen ) || is_string( $gen ) ) && '' !== (string) $gen ? (string) $gen : null;
	}

	/**
	 * The current generation, initialising a missing one.
	 *
	 * @return string
	 */
	private function ensure_generation(): string {
		$gen = $this->generation();
		if ( null !== $gen ) {
			return $gen;
		}
		wp_cache_add( self::GENERATION_KEY, time(), Names::CACHE_GROUP );
		// Another process may have won the add; read back, and force a value if the cache refuses.
		$gen = $this->generation();
		if ( null === $gen ) {
			$gen = $this->fresh_generation();
		}
		return $gen;
	}

	/**
	 * Force a new generation value that cannot equal an earlier one.
	 *
	 * @return string
	 */
	private function fresh_generation(): string {
		$value = (int) floor( microtime( true ) * 1000000 );
		wp_cache_set( self::GENERATION_KEY, $value, Names::CACHE_GROUP );
		return (string) $value;
	}

	/**
	 * The object-cache key of an entry.
	 *
	 * @param string $generation Generation.
	 * @param string $cache_key  Cache key.
	 *
	 * @return string
	 */
	private function object_key( string $generation, string $cache_key ): string {
		return $generation . ':' . $cache_key;
	}

	/**
	 * Remove the object-cache entries of cache keys under the current generation.
	 *
	 * @param string[] $cache_keys Cache keys.
	 *
	 * @phpstan-param list<string> $cache_keys
	 *
	 * @return void
	 */
	private function forget( array $cache_keys ): void {
		$gen = $this->generation();
		if ( null === $gen || array() === $cache_keys ) {
			return;
		}
		$keys = [];
		foreach ( $cache_keys as $cache_key ) {
			$keys[] = $this->object_key( $gen, $cache_key );
		}
		wp_cache_delete_multiple( $keys, Names::CACHE_GROUP );
	}

	/**
	 * The active, unexpired response of a cache key: two object-cache reads, no SQL.
	 *
	 * A missing, evicted or malformed object, or one past its expiry, is a miss.
	 *
	 * @param string $cache_key Cache key.
	 *
	 * @return Cached_Response|null
	 */
	public function get( string $cache_key ): ?Cached_Response {
		$gen = $this->generation();
		if ( null === $gen ) {
			return null;
		}
		$value = wp_cache_get( $this->object_key( $gen, $cache_key ), Names::CACHE_GROUP );
		if ( ! is_array( $value ) || count( $value ) < 5 ) {
			return null;
		}
		$value = array_values( $value );
		[ $id, $payload, $headers, $created, $expires ] = $value;
		if ( ! is_int( $id ) || ! is_string( $payload ) || ! is_array( $headers ) || ! is_int( $created ) || ! is_int( $expires ) ) {
			return null;
		}
		if ( $id < 1 || $expires <= time() ) {
			return null;
		}
		$clean = [];
		foreach ( $headers as $name => $header ) {
			if ( is_string( $name ) && ( is_string( $header ) || is_int( $header ) || is_float( $header ) ) ) {
				$clean[ $name ] = (string) $header;
			}
		}
		return new Cached_Response( $id, $cache_key, $payload, $clean, $created, $expires );
	}

	/**
	 * Count a hit with 1-in-N sampling, in the object-cache `hit_buffer` (approximate by design).
	 *
	 * @param Cached_Response $hit Served response.
	 *
	 * @return void
	 */
	public function record_hit( Cached_Response $hit ): void {
		if ( $this->hit_sampling < 1 ) {
			return;
		}
		if ( 1 !== (int) ( $this->rng )( 1, $this->hit_sampling ) ) {
			return;
		}
		$buffer = $this->read_buffer();
		$count  = isset( $buffer[ $hit->entry_id ] ) ? $buffer[ $hit->entry_id ][0] : 0;

		$buffer[ $hit->entry_id ] = [ $count + $this->hit_sampling, gmdate( 'Y-m-d H:i:s' ) ];
		wp_cache_set( self::HIT_BUFFER_KEY, $buffer, Names::CACHE_GROUP );
	}

	/**
	 * The buffered hits, dropping malformed items.
	 *
	 * @return array<int,array{0:int,1:string}> Entry id => [ hits, last hit time ].
	 */
	private function read_buffer(): array {
		$raw = wp_cache_get( self::HIT_BUFFER_KEY, Names::CACHE_GROUP );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$buffer = [];
		foreach ( $raw as $id => $item ) {
			if ( is_int( $id ) && is_array( $item ) && isset( $item[0], $item[1] ) && is_int( $item[0] ) && is_string( $item[1] ) ) {
				$buffer[ $id ] = [ $item[0], $item[1] ];
			}
		}
		return $buffer;
	}

	/**
	 * Persist the buffered hits to the database and clear the buffer.
	 *
	 * @return void
	 */
	private function persist_hits(): void {
		$buffer = $this->read_buffer();
		wp_cache_delete( self::HIT_BUFFER_KEY, Names::CACHE_GROUP );
		if ( array() !== $buffer ) {
			$this->repository->record_hits( $buffer );
		}
	}

	/**
	 * Upsert the entry row (no payload), replace its tags, cache the payload and re-check the row.
	 *
	 * The object is written after the row, so a flush (or delete) that lands before the object write
	 * is seen by the re-check, which then removes the object; one that lands after it removes the
	 * object itself (it looks the keys up after its UPDATE). A flushed or deleted row therefore never
	 * leaves a servable object behind. Any previous object of the key is removed right after the
	 * upsert: when a flush flagged the row and this upsert re-activated it before the flush looked
	 * its keys up, the pre-flush object would otherwise stay servable should this request die before
	 * writing its own object.
	 *
	 * @param Entry_Data $entry Entry.
	 *
	 * @return int|null Entry id; 0 when the entry was flushed or deleted while it was being stored (so
	 *                  it is not served, but nothing failed); null when not stored, or stored but its
	 *                  tags could not be written (it is then flushed and never served).
	 */
	public function set( Entry_Data $entry ): ?int {
		$now     = time();
		$ttl     = max( 1, $entry->ttl );
		$expires = $now + $ttl;
		$id      = $this->repository->upsert_entry(
			[
				'cache_key'  => $entry->cache_key,
				'url_hash'   => $entry->url_hash,
				'route'      => $entry->route,
				'rule_key'   => $entry->rule_key,
				'request'    => (string) wp_json_encode( $entry->request ),
				'method'     => $entry->method,
				'payload'    => null,
				'headers'    => (string) wp_json_encode( $entry->headers ),
				'size'       => strlen( $entry->payload ),
				'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $expires ),
			]
		);
		if ( $id < 1 ) {
			return null;
		}
		$this->forget( [ $entry->cache_key ] );

		if ( ! $this->repository->replace_tags( $id, $entry->tags ) ) {
			// Without its tags the entry could never be invalidated: flag it (its object is already gone).
			$this->repository->flush_by_ids( [ $id ], gmdate( 'Y-m-d H:i:s', $now ) );
			return null;
		}

		wp_cache_set(
			$this->object_key( $this->ensure_generation(), $entry->cache_key ),
			[ $id, $entry->payload, $entry->headers, $now, $expires ],
			Names::CACHE_GROUP,
			$ttl
		);

		$state = $this->repository->entry_state( $id );
		if ( Repository::STATE_ACTIVE !== $state ) {
			// Flushed or deleted while it was being stored, or the state is unknown: never serve it.
			$this->forget( [ $entry->cache_key ] );
			return null === $state ? null : 0;
		}
		return $id;
	}

	/**
	 * Flush by tags; the keys are looked up after the UPDATE (see {@see self::set()}).
	 *
	 * @param string[] $tags Tags.
	 *
	 * @phpstan-param list<string> $tags
	 *
	 * @return int
	 */
	public function flush_by_tags( array $tags ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		return $this->after_flush( $this->repository->flush_by_tags( $tags, $now ), fn() => $this->repository->keys_for_tags( $tags, $now ) );
	}

	/**
	 * Flush by ids; the keys are looked up after the UPDATE.
	 *
	 * @param int[] $ids Ids.
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @return int
	 */
	public function flush_by_ids( array $ids ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		return $this->after_flush( $this->repository->flush_by_ids( $ids, $now ), fn() => $this->repository->keys_for_ids( $ids, $now ) );
	}

	/**
	 * Flush by cache keys (the keys are the object keys, no lookup needed).
	 *
	 * @param string[] $keys Keys.
	 *
	 * @phpstan-param list<string> $keys
	 *
	 * @return int
	 */
	public function flush_by_keys( array $keys ): int {
		$flushed = $this->repository->flush_by_keys( $keys, gmdate( 'Y-m-d H:i:s' ) );
		// The object keys are known: remove them whatever the UPDATE did.
		$this->forget( $keys );
		if ( null === $flushed ) {
			Failure_Recorder::record( 'flush', 'A flush statement failed; the flushed responses are no longer served from the object cache.' );
		}
		return $flushed ?? 0;
	}

	/**
	 * Flush by URL hash; the keys are looked up after the UPDATE.
	 *
	 * @param string $hash URL hash.
	 *
	 * @return int
	 */
	public function flush_by_url_hash( string $hash ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		return $this->after_flush( $this->repository->flush_by_url_hash( $hash, $now ), fn() => $this->repository->keys_for_url_hash( $hash, $now ) );
	}

	/**
	 * Flush a route; the keys are looked up after the UPDATE.
	 *
	 * @param string $route Route or rule key.
	 *
	 * @return int
	 */
	public function flush_route( string $route ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		return $this->after_flush( $this->repository->flush_route( $route, $now ), fn() => $this->repository->keys_for_route( $route, $now ) );
	}

	/**
	 * Flush everything: flag the rows and bump the generation, which orphans every object key.
	 *
	 * @return int
	 */
	public function flush_all(): int {
		$flushed = $this->repository->flush_all( gmdate( 'Y-m-d H:i:s' ) );
		$this->bump_generation();
		if ( null === $flushed ) {
			Failure_Recorder::record( 'flush', 'A flush statement failed; the object cache was invalidated as a whole.' );
		}
		return $flushed ?? 0;
	}

	/**
	 * Remove the object keys of the entries a flush flagged.
	 *
	 * Nothing to do when nothing was flagged. When the UPDATE failed, the rows it should have flagged
	 * may still be active, so every object key is orphaned (bumping the generation) and the failure
	 * is recorded (context `flush`); the active rows then miss until they are stored again.
	 *
	 * @param int|null $flushed Entries flushed, or null when a statement failed.
	 * @param \Closure $keys    Returns the cache keys of the flushed entries, or null when the lookup failed.
	 *
	 * @phpstan-param \Closure(): (list<string>|null) $keys
	 *
	 * @return int Entries flushed (0 when the flush failed).
	 */
	private function after_flush( ?int $flushed, \Closure $keys ): int {
		if ( null === $flushed ) {
			$this->bump_generation();
			Failure_Recorder::record( 'flush', 'A flush statement failed; the object cache was invalidated as a whole.' );
			return 0;
		}
		if ( $flushed > 0 ) {
			$this->forget_or_orphan( $keys() );
		}
		return $flushed;
	}

	/**
	 * Delete entries with their tags and object keys.
	 *
	 * The keys are looked up (whatever the state) before the rows go and removed after, so an entry
	 * re-stored in between is either removed here or caught by the re-check in {@see self::set()}.
	 *
	 * @param int[] $entry_ids Ids.
	 *
	 * @phpstan-param list<int> $entry_ids
	 *
	 * @return int
	 */
	public function delete( array $entry_ids ): int {
		if ( array() === $entry_ids ) {
			return 0;
		}
		$keys    = $this->repository->all_keys_for_ids( $entry_ids );
		$deleted = $this->repository->delete_entries( $entry_ids );
		$this->forget_or_orphan( $keys );
		return $deleted;
	}

	/**
	 * Delete up to a batch of flushed or expired entries, with their object keys.
	 *
	 * @param int $batch_size Batch size.
	 *
	 * @return int
	 */
	public function prune( int $batch_size ): int {
		if ( $batch_size < 1 ) {
			return 0;
		}
		return $this->delete( $this->repository->prunable_ids( gmdate( 'Y-m-d H:i:s' ), $batch_size ) );
	}

	/**
	 * Persist buffered hits, then delete the least recently hit active entries above the cap.
	 *
	 * @param int $max_entries Cap.
	 * @param int $batch_size  Maximum deletions in this call.
	 *
	 * @return int
	 */
	public function enforce_cap( int $max_entries, int $batch_size ): int {
		$this->persist_hits();
		$excess = $this->repository->count_active() - max( 0, $max_entries );
		if ( $excess < 1 || $batch_size < 1 ) {
			return 0;
		}
		return $this->delete( $this->repository->lru_ids( min( $excess, $batch_size ) ) );
	}

	/**
	 * Cached payload of an entry (one SELECT to find its cache key, then the object cache).
	 *
	 * @param int $entry_id Entry id.
	 *
	 * @return string|null Null when the entry is not active or its payload was evicted.
	 */
	public function payload_for( int $entry_id ): ?string {
		$keys = $this->repository->keys_for_ids( [ $entry_id ] ) ?? [];
		if ( array() === $keys ) {
			return null;
		}
		$hit = $this->get( $keys[0] );
		return null !== $hit && $hit->entry_id === $entry_id ? $hit->payload : null;
	}

	/**
	 * Remove the object keys of a lookup. When the lookup failed (null) the rows were flagged or
	 * deleted regardless, so every object key is orphaned by bumping the generation instead.
	 *
	 * @param string[]|null $cache_keys Cache keys, or null when they could not be looked up.
	 *
	 * @phpstan-param list<string>|null $cache_keys
	 *
	 * @return void
	 */
	private function forget_or_orphan( ?array $cache_keys ): void {
		if ( null === $cache_keys ) {
			$this->bump_generation();
			return;
		}
		$this->forget( $cache_keys );
	}

	/**
	 * Move to a new generation, which orphans every object key.
	 *
	 * @return void
	 */
	private function bump_generation(): void {
		if ( null === $this->generation() || false === wp_cache_incr( self::GENERATION_KEY, 1, Names::CACHE_GROUP ) ) {
			// Missing generation: re-initialise with a value no earlier generation can have had.
			$this->fresh_generation();
		}
	}
}
