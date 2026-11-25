<?php
/**
 * Store keeping payloads in the entries table.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Storage;

use Rockfire\Rest_Api_Cache\Failure_Recorder;

defined( 'ABSPATH' ) || exit;

/**
 * Default store: entries, tags and payloads live in the plugin tables.
 *
 * A hit costs one SELECT; hit counting is sampled (1 in N, then N hits are added) so that most
 * hits write nothing. Never fires hooks, never uses transients or the object cache.
 *
 * @since 1.0.0
 */
final class Database_Store implements Store_Interface {

	/**
	 * Entries repository.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Sampling rate N: one hit in N is written (as N hits). 0 disables hit counting.
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
		return 'database';
	}

	/**
	 * The active, unexpired response of a cache key (one SELECT).
	 *
	 * A row without payload or with undecodable headers is a miss.
	 *
	 * @param string $cache_key Cache key.
	 *
	 * @return Cached_Response|null
	 */
	public function get( string $cache_key ): ?Cached_Response {
		$row = $this->repository->find_active( $cache_key, gmdate( 'Y-m-d H:i:s' ) );
		if ( null === $row || ! isset( $row['payload'] ) || ! is_string( $row['payload'] ) ) {
			return null;
		}

		$headers = json_decode( (string) ( $row['headers'] ?? '' ), true );
		if ( ! is_array( $headers ) ) {
			return null;
		}
		$clean = [];
		foreach ( $headers as $name => $value ) {
			if ( is_string( $name ) && ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) ) {
				$clean[ $name ] = (string) $value;
			}
		}

		return new Cached_Response(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['cache_key'] ?? $cache_key ),
			$row['payload'],
			$clean,
			(int) strtotime( (string) ( $row['created_at'] ?? '' ) . ' UTC' ),
			(int) strtotime( (string) ( $row['expires_at'] ?? '' ) . ' UTC' )
		);
	}

	/**
	 * Count a hit with 1-in-N sampling.
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
		$this->repository->record_hit( $hit->entry_id, $this->hit_sampling, gmdate( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Upsert an entry and replace its tags.
	 *
	 * @param Entry_Data $entry Entry.
	 *
	 * @return int|null Entry id; null when not stored, or stored but its tags could not be written (it is then flushed and never served).
	 */
	public function set( Entry_Data $entry ): ?int {
		$now = time();
		$id  = $this->repository->upsert_entry(
			[
				'cache_key'  => $entry->cache_key,
				'url_hash'   => $entry->url_hash,
				'route'      => $entry->route,
				'rule_key'   => $entry->rule_key,
				'request'    => (string) wp_json_encode( $entry->request ),
				'method'     => $entry->method,
				'payload'    => $entry->payload,
				'headers'    => (string) wp_json_encode( $entry->headers ),
				'size'       => strlen( $entry->payload ),
				'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $now + max( 0, $entry->ttl ) ),
			]
		);
		if ( $id < 1 ) {
			return null;
		}
		if ( ! $this->repository->replace_tags( $id, $entry->tags ) ) {
			// Without its tags the entry could never be invalidated: make sure it is not served.
			$this->repository->flush_by_ids( [ $id ], gmdate( 'Y-m-d H:i:s', $now ) );
			return null;
		}
		return $id;
	}

	/**
	 * Flush by tags.
	 *
	 * @param string[] $tags Tags.
	 *
	 * @phpstan-param list<string> $tags
	 *
	 * @return int
	 */
	public function flush_by_tags( array $tags ): int {
		return $this->flushed( $this->repository->flush_by_tags( $tags, gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/**
	 * Flush by ids.
	 *
	 * @param int[] $ids Ids.
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @return int
	 */
	public function flush_by_ids( array $ids ): int {
		return $this->flushed( $this->repository->flush_by_ids( $ids, gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/**
	 * Flush by cache keys.
	 *
	 * @param string[] $keys Keys.
	 *
	 * @phpstan-param list<string> $keys
	 *
	 * @return int
	 */
	public function flush_by_keys( array $keys ): int {
		return $this->flushed( $this->repository->flush_by_keys( $keys, gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/**
	 * Flush by URL hash.
	 *
	 * @param string $hash URL hash.
	 *
	 * @return int
	 */
	public function flush_by_url_hash( string $hash ): int {
		return $this->flushed( $this->repository->flush_by_url_hash( $hash, gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/**
	 * Flush a route.
	 *
	 * @param string $route Route or rule key.
	 *
	 * @return int
	 */
	public function flush_route( string $route ): int {
		return $this->flushed( $this->repository->flush_route( $route, gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/**
	 * Flush everything.
	 *
	 * @return int
	 */
	public function flush_all(): int {
		return $this->flushed( $this->repository->flush_all( gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/**
	 * The count of a repository flush; a failed flush statement is recorded (context `flush`) and counts as 0.
	 *
	 * @param int|null $flushed Entries flushed, or null when a statement failed.
	 *
	 * @return int
	 */
	private function flushed( ?int $flushed ): int {
		if ( null === $flushed ) {
			Failure_Recorder::record( 'flush', 'A flush statement failed; some entries may still be served until they expire.' );
			return 0;
		}
		return $flushed;
	}

	/**
	 * Delete entries with their tags.
	 *
	 * @param int[] $entry_ids Ids.
	 *
	 * @phpstan-param list<int> $entry_ids
	 *
	 * @return int
	 */
	public function delete( array $entry_ids ): int {
		return $this->repository->delete_entries( $entry_ids );
	}

	/**
	 * Delete up to a batch of flushed or expired entries.
	 *
	 * @param int $batch_size Batch size.
	 *
	 * @return int
	 */
	public function prune( int $batch_size ): int {
		if ( $batch_size < 1 ) {
			return 0;
		}
		return $this->repository->delete_entries( $this->repository->prunable_ids( gmdate( 'Y-m-d H:i:s' ), $batch_size ) );
	}

	/**
	 * Delete the least recently hit active entries above the cap.
	 *
	 * @param int $max_entries Cap.
	 * @param int $batch_size  Maximum deletions in this call.
	 *
	 * @return int
	 */
	public function enforce_cap( int $max_entries, int $batch_size ): int {
		$excess = $this->repository->count_active() - max( 0, $max_entries );
		if ( $excess < 1 || $batch_size < 1 ) {
			return 0;
		}
		return $this->repository->delete_entries( $this->repository->lru_ids( min( $excess, $batch_size ) ) );
	}

	/**
	 * Stored payload of an entry.
	 *
	 * @param int $entry_id Entry id.
	 *
	 * @return string|null
	 */
	public function payload_for( int $entry_id ): ?string {
		return $this->repository->payload_for( $entry_id );
	}
}
