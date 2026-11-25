<?php
/**
 * Store test double that records the flush calls.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Storage\Cached_Response;
use Rockfire\Rest_Api_Cache\Storage\Entry_Data;
use Rockfire\Rest_Api_Cache\Storage\Store_Interface;

/**
 * Records every flush call as `array( method, argument, blog_id )` in `$calls`; every flush returns `$return`.
 */
final class Rfrac_Spy_Store implements Store_Interface {

	/**
	 * Recorded flush calls: method name, argument (null for flush_all) and the blog ID current at call time.
	 *
	 * @var list<array{0:string,1:mixed,2:int}>
	 */
	public array $calls = array();

	/**
	 * Value every flush method returns.
	 *
	 * @var int
	 */
	public int $return = 1;

	/**
	 * Called with ( method, argument ) before a flush call is recorded; may throw to simulate a failing store.
	 *
	 * @var Closure|null
	 */
	public ?Closure $before_flush = null;

	/**
	 * Arguments of the calls of one method.
	 *
	 * @param string $method Method name, e.g. `flush_by_tags`.
	 *
	 * @return list<mixed>
	 */
	public function calls_of( string $method ): array {
		$out = array();
		foreach ( $this->calls as $call ) {
			if ( $call[0] === $method ) {
				$out[] = $call[1];
			}
		}
		return $out;
	}

	/**
	 * Record a call.
	 *
	 * @param string $method   Method.
	 * @param mixed  $argument Argument.
	 *
	 * @return int
	 */
	private function record( string $method, mixed $argument ): int {
		if ( null !== $this->before_flush ) {
			( $this->before_flush )( $method, $argument );
		}
		$this->calls[] = array( $method, $argument, (int) get_current_blog_id() );
		return $this->return;
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'spy';
	}

	/**
	 * Always a miss.
	 *
	 * @param string $cache_key Key.
	 *
	 * @return Cached_Response|null
	 */
	public function get( string $cache_key ): ?Cached_Response {
		return null;
	}

	/**
	 * Ignored.
	 *
	 * @param Cached_Response $hit Hit.
	 */
	public function record_hit( Cached_Response $hit ): void {
	}

	/**
	 * Never stores.
	 *
	 * @param Entry_Data $entry Entry.
	 *
	 * @return int|null
	 */
	public function set( Entry_Data $entry ): ?int {
		return null;
	}

	/**
	 * Recorded.
	 *
	 * @param string[] $tags Tags.
	 *
	 * @return int
	 */
	public function flush_by_tags( array $tags ): int {
		return $this->record( 'flush_by_tags', $tags );
	}

	/**
	 * Recorded.
	 *
	 * @param int[] $ids Ids.
	 *
	 * @return int
	 */
	public function flush_by_ids( array $ids ): int {
		return $this->record( 'flush_by_ids', $ids );
	}

	/**
	 * Recorded.
	 *
	 * @param string[] $keys Keys.
	 *
	 * @return int
	 */
	public function flush_by_keys( array $keys ): int {
		return $this->record( 'flush_by_keys', $keys );
	}

	/**
	 * Recorded.
	 *
	 * @param string $hash Hash.
	 *
	 * @return int
	 */
	public function flush_by_url_hash( string $hash ): int {
		return $this->record( 'flush_by_url_hash', $hash );
	}

	/**
	 * Recorded.
	 *
	 * @param string $route Route.
	 *
	 * @return int
	 */
	public function flush_route( string $route ): int {
		return $this->record( 'flush_route', $route );
	}

	/**
	 * Recorded.
	 *
	 * @return int
	 */
	public function flush_all(): int {
		return $this->record( 'flush_all', null );
	}

	/**
	 * Nothing deleted.
	 *
	 * @param int[] $entry_ids Ids.
	 *
	 * @return int
	 */
	public function delete( array $entry_ids ): int {
		return 0;
	}

	/**
	 * Nothing pruned.
	 *
	 * @param int $batch_size Batch.
	 *
	 * @return int
	 */
	public function prune( int $batch_size ): int {
		return 0;
	}

	/**
	 * Nothing deleted.
	 *
	 * @param int $max_entries Cap.
	 * @param int $batch_size  Batch.
	 *
	 * @return int
	 */
	public function enforce_cap( int $max_entries, int $batch_size ): int {
		return 0;
	}

	/**
	 * No payload.
	 *
	 * @param int $entry_id Id.
	 *
	 * @return string|null
	 */
	public function payload_for( int $entry_id ): ?string {
		return null;
	}
}
