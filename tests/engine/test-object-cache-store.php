<?php
/**
 * Tests for the object-cache store.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Storage\Entry_Data;
use Rockfire\Rest_Api_Cache\Storage\Object_Cache_Store;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Interface;

/**
 * @covers \Rockfire\Rest_Api_Cache\Storage\Object_Cache_Store
 */
class Test_Object_Cache_Store extends Rfrac_Test_Case {

	/**
	 * Repository.
	 *
	 * @var Repository
	 */
	private Repository $repo;

	/**
	 * Original external object cache flag.
	 *
	 * @var bool
	 */
	private bool $ext_cache;

	public function set_up() {
		parent::set_up();
		$this->repo      = new Repository();
		$this->ext_cache = (bool) wp_using_ext_object_cache();
		wp_using_ext_object_cache( true );
		$this->reset_group();
	}

	public function tear_down() {
		$this->reset_group();
		wp_using_ext_object_cache( $this->ext_cache );
		parent::tear_down();
	}

	private function reset_group(): void {
		wp_cache_delete( 'generation', 'rfrac' );
		wp_cache_delete( 'hit_buffer', 'rfrac' );
		wp_cache_flush_group( 'rfrac' );
	}

	private function store( int $sampling = 10, ?Closure $rng = null ): Object_Cache_Store {
		return new Object_Cache_Store( $this->repo, $sampling, $rng );
	}

	private function entry( string $key, array $tags = array(), int $ttl = 600, string $route = '/wp/v2/posts' ): Entry_Data {
		return new Entry_Data(
			$key,
			hash( 'sha256', 'u' . $key ),
			$route,
			$route,
			array( 'path' => '/wp/v2/posts' ),
			'GET',
			'{"a":1}',
			array( 'X-Test' => 'yes' ),
			$ttl,
			$tags
		);
	}

	private function column( int $id, string $column ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT %i FROM %i WHERE id = %d', $column, $wpdb->prefix . 'rfrac_entries', $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function row_exists( int $id ): bool {
		return null !== $this->column( $id, 'id' );
	}

	private function object_key( string $cache_key ): string {
		return wp_cache_get( 'generation', 'rfrac' ) . ':' . $cache_key;
	}

	/**
	 * Store an entry and return [ id, key ].
	 */
	private function put( Object_Cache_Store $store, string $key, array $tags = array(), int $ttl = 600, string $route = '/wp/v2/posts' ): array {
		$id = $store->set( $this->entry( $key, $tags, $ttl, $route ) );
		$this->assertNotNull( $id );
		return array( (int) $id, $key );
	}

	public function test_set_with_failing_tag_write_returns_null_and_writes_no_object(): void {
		$store = $this->store();
		$id    = $this->with_failing_tag_writes( fn() => $store->set( $this->entry( 'kfail', array( 'post:1' ) ) ) );

		$this->assertNull( $id );
		$this->assertNull( $store->get( 'kfail' ) );
		$this->assertFalse( wp_cache_get( $this->object_key( 'kfail' ), 'rfrac' ) );
		global $wpdb;
		$row = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE cache_key = %s', $wpdb->prefix . 'rfrac_entries', 'kfail' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $row ) );
	}

	public function test_refresh_with_failing_tag_write_stops_serving_the_previous_payload(): void {
		$store            = $this->store();
		list( $id, $key ) = $this->put( $store, 'krefresh', array( 'post:1' ) );
		$this->assertNotNull( $store->get( $key ) );

		$result = $this->with_failing_tag_writes( fn() => $store->set( $this->entry( $key, array( 'post:2' ) ) ) );

		$this->assertNull( $result );
		$this->assertNull( $store->get( $key ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
	}

	public function test_implements_interface_and_name(): void {
		$store = $this->store();
		$this->assertInstanceOf( Store_Interface::class, $store );
		$this->assertSame( 'object_cache', $store->name() );
	}

	public function test_set_stores_row_without_payload_and_caches_payload(): void {
		$store            = $this->store();
		list( $id, $key ) = $this->put( $store, 'k1', array( 'post:1' ) );

		$this->assertNull( $this->column( $id, 'payload' ) );
		$this->assertSame( '7', $this->column( $id, 'size' ) );
		$this->assertSame( array( 'post:1' ), $this->repo->tags_for( $id ) );

		$hit = $store->get( $key );
		$this->assertNotNull( $hit );
		$this->assertSame( $id, $hit->entry_id );
		$this->assertSame( $key, $hit->cache_key );
		$this->assertSame( '{"a":1}', $hit->payload );
		$this->assertSame( array( 'X-Test' => 'yes' ), $hit->headers );
		$this->assertGreaterThan( time(), $hit->expires_at );
		$this->assertLessThanOrEqual( time(), $hit->created_at );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $hit->expires_at ), $this->column( $id, 'expires_at' ) );
	}

	public function test_get_unknown_key_misses(): void {
		$this->assertNull( $this->store()->get( 'nope' ) );
	}

	public function test_key_uses_generation_in_rfrac_group(): void {
		$store = $this->store();
		$this->put( $store, 'k1' );
		$this->assertNotFalse( wp_cache_get( 'generation', 'rfrac' ) );
		$this->assertIsArray( wp_cache_get( $this->object_key( 'k1' ), 'rfrac' ) );
	}

	public function test_get_hit_runs_no_sql_and_buffers_sampled_hit(): void {
		$store            = $this->store( 10, static fn( int $min, int $max ): int => 1 );
		list( $id, $key ) = $this->put( $store, 'k1' );

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$hit = $store->get( $key );
		$this->assertNotNull( $hit );
		$store->record_hit( $hit );
		$recorder->stop();

		$this->assertCount( 0, $recorder->matching( '/./' ) );
		$buffer = wp_cache_get( 'hit_buffer', 'rfrac' );
		$this->assertSame( 10, $buffer[ $id ][0] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $buffer[ $id ][1] );
		$this->assertSame( '0', $this->column( $id, 'hits' ) );
	}

	public function test_unsampled_hit_and_disabled_sampling_buffer_nothing(): void {
		$store         = $this->store( 10, static fn( int $min, int $max ): int => 2 );
		list( , $key ) = $this->put( $store, 'k1' );
		$store->record_hit( $store->get( $key ) );
		$this->assertFalse( wp_cache_get( 'hit_buffer', 'rfrac' ) );

		$off = $this->store( 0, static fn( int $min, int $max ): int => 1 );
		$off->record_hit( $store->get( $key ) );
		$this->assertFalse( wp_cache_get( 'hit_buffer', 'rfrac' ) );
	}

	public function test_hit_buffer_accumulates(): void {
		$store            = $this->store( 5, static fn( int $min, int $max ): int => 1 );
		list( $id, $key ) = $this->put( $store, 'k1' );
		$store->record_hit( $store->get( $key ) );
		$store->record_hit( $store->get( $key ) );
		$this->assertSame( 10, wp_cache_get( 'hit_buffer', 'rfrac' )[ $id ][0] );
	}

	public function test_flush_by_tags_makes_get_miss_and_keeps_others(): void {
		$store       = $this->store();
		list( , $a ) = $this->put( $store, 'a', array( 'post:1' ) );
		list( , $b ) = $this->put( $store, 'b', array( 'post:2' ) );

		$this->assertSame( 1, $store->flush_by_tags( array( 'post:1' ) ) );
		$this->assertNull( $store->get( $a ) );
		$this->assertNotNull( $store->get( $b ) );
	}

	public function test_flush_by_ids_keys_url_hash_and_route(): void {
		$store          = $this->store();
		list( $ia, $a ) = $this->put( $store, 'a' );
		list( , $b )    = $this->put( $store, 'b' );
		list( , $c )    = $this->put( $store, 'c' );
		list( , $d )    = $this->put( $store, 'd' );

		$this->assertSame( 1, $store->flush_by_ids( array( $ia ) ) );
		$this->assertNull( $store->get( $a ) );

		$this->assertSame( 1, $store->flush_by_keys( array( $b ) ) );
		$this->assertNull( $store->get( $b ) );

		$this->assertSame( 1, $store->flush_by_url_hash( hash( 'sha256', 'uc' ) ) );
		$this->assertNull( $store->get( $c ) );

		$this->assertSame( 1, $store->flush_route( '/wp/v2/posts' ) );
		$this->assertNull( $store->get( $d ) );
	}

	public function test_flush_by_tags_runs_one_select_and_one_update(): void {
		$store = $this->store();
		$this->put( $store, 'a', array( 'post:1' ) );
		$this->put( $store, 'b', array( 'post:1' ) );

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$store->flush_by_tags( array( 'post:1' ) );
		$recorder->stop();
		$this->assertCount( 1, $recorder->matching( '/^\s*UPDATE/i' ) );
		$this->assertCount( 1, $recorder->matching( '/^\s*SELECT/i' ) );
	}

	public function test_flush_all_bumps_generation_and_misses_everything(): void {
		$store       = $this->store();
		list( , $a ) = $this->put( $store, 'a' );
		list( , $b ) = $this->put( $store, 'b' );
		$before      = (int) wp_cache_get( 'generation', 'rfrac' );

		$this->assertSame( 2, $store->flush_all() );
		$this->assertSame( $before + 1, (int) wp_cache_get( 'generation', 'rfrac' ) );
		$this->assertNull( $store->get( $a ) );
		$this->assertNull( $store->get( $b ) );
	}

	public function test_flush_all_with_missing_generation_still_invalidates(): void {
		$store       = $this->store();
		list( , $a ) = $this->put( $store, 'a' );
		$old         = wp_cache_get( 'generation', 'rfrac' );
		wp_cache_delete( 'generation', 'rfrac' );

		$store->flush_all();
		$new = wp_cache_get( 'generation', 'rfrac' );
		$this->assertNotFalse( $new );
		$this->assertNotSame( $old, $new );
		$this->assertNull( $store->get( $a ) );
	}

	public function test_missing_generation_is_a_miss_and_set_reinitialises(): void {
		$store       = $this->store();
		list( , $a ) = $this->put( $store, 'a' );
		wp_cache_delete( 'generation', 'rfrac' );
		$this->assertNull( $store->get( $a ) );

		list( , $b ) = $this->put( $store, 'b' );
		$this->assertNotFalse( wp_cache_get( 'generation', 'rfrac' ) );
		$this->assertNotNull( $store->get( $b ) );
	}

	public function test_set_after_flush_serves_again(): void {
		$store       = $this->store();
		list( , $a ) = $this->put( $store, 'a', array( 'post:1' ) );
		$store->flush_by_tags( array( 'post:1' ) );
		$this->assertNull( $store->get( $a ) );
		$this->put( $store, 'a', array( 'post:1' ) );
		$this->assertNotNull( $store->get( $a ) );
	}

	public function test_evicted_payload_is_a_miss(): void {
		$store         = $this->store();
		list( , $key ) = $this->put( $store, 'a' );
		wp_cache_delete( $this->object_key( $key ), 'rfrac' );
		$this->assertNull( $store->get( $key ) );
	}

	/**
	 * @dataProvider malformed_values
	 *
	 * @param mixed $value Cached value.
	 */
	public function test_malformed_cached_value_is_a_miss( $value ): void {
		$store         = $this->store();
		list( , $key ) = $this->put( $store, 'a' );
		wp_cache_set( $this->object_key( $key ), $value, 'rfrac' );
		$this->assertNull( $store->get( $key ) );
	}

	public static function malformed_values(): array {
		$t = time() + 100;
		return array(
			'string'      => array( 'oops' ),
			'int'         => array( 5 ),
			'short'       => array( array( 1, 'p' ) ),
			'bad id'      => array( array( 'x', 'p', array(), $t - 5, $t ) ),
			'zero id'     => array( array( 0, 'p', array(), $t - 5, $t ) ),
			'bad payload' => array( array( 1, null, array(), $t - 5, $t ) ),
			'bad headers' => array( array( 1, 'p', 'h', $t - 5, $t ) ),
			'bad created' => array( array( 1, 'p', array(), 'c', $t ) ),
			'bad expires' => array( array( 1, 'p', array(), $t - 5, 'e' ) ),
			'expired'     => array( array( 1, 'p', array(), $t - 500, time() - 1 ) ),
		);
	}

	public function test_non_string_header_values_are_cleaned(): void {
		$store            = $this->store();
		list( $id, $key ) = $this->put( $store, 'a' );
		$t                = time();
		wp_cache_set( $this->object_key( $key ), array( $id, 'p', array( 'A' => 1, 'B' => array( 1 ), 0 => 'x' ), $t - 1, $t + 50 ), 'rfrac' );
		$this->assertSame( array( 'A' => '1' ), $store->get( $key )->headers );
	}

	public function test_zero_ttl_is_clamped_to_one_second(): void {
		$store            = $this->store();
		list( $id, $key ) = $this->put( $store, 'a', array(), 0 );
		$hit              = $store->get( $key );
		$this->assertNotNull( $hit );
		$this->assertSame( $hit->created_at + 1, $hit->expires_at );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $hit->expires_at ), $this->column( $id, 'expires_at' ) );
	}

	public function test_delete_removes_rows_and_object_keys(): void {
		$store            = $this->store();
		list( $id, $key ) = $this->put( $store, 'a', array( 'post:1' ) );
		$object_key       = $this->object_key( $key );

		$this->assertSame( 1, $store->delete( array( $id ) ) );
		$this->assertFalse( $this->row_exists( $id ) );
		$this->assertFalse( wp_cache_get( $object_key, 'rfrac' ) );
		$this->assertNull( $store->get( $key ) );
	}

	public function test_prune_removes_object_keys_of_expired_active_entries(): void {
		global $wpdb;
		$store            = $this->store();
		list( $id, $key ) = $this->put( $store, 'a' );
		$object_key       = $this->object_key( $key );
		$wpdb->update( $wpdb->prefix . 'rfrac_entries', array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->assertSame( 1, $store->prune( 10 ) );
		$this->assertFalse( $this->row_exists( $id ) );
		$this->assertFalse( wp_cache_get( $object_key, 'rfrac' ) );
	}

	public function test_prune_of_flushed_entries_leaves_nothing_servable(): void {
		$store            = $this->store();
		list( $id, $key ) = $this->put( $store, 'a', array( 'post:1' ) );
		$object_key       = $this->object_key( $key );
		$store->flush_by_tags( array( 'post:1' ) );
		$this->assertTrue( $this->row_exists( $id ) );
		$this->assertFalse( wp_cache_get( $object_key, 'rfrac' ) );

		$this->assertSame( 0, $store->prune( 0 ) );
		$this->assertSame( 1, $store->prune( 10 ) );
		$this->assertFalse( $this->row_exists( $id ) );
		$this->assertNull( $store->get( $key ) );
	}

	public function test_enforce_cap_persists_hit_buffer_and_removes_lru_keys(): void {
		global $wpdb;
		$store        = $this->store( 5, static fn( int $min, int $max ): int => 1 );
		list( $a, $ka ) = $this->put( $store, 'a' );
		list( $b, $kb ) = $this->put( $store, 'b' );
		$object_key   = $this->object_key( $ka );
		$old          = gmdate( 'Y-m-d H:i:s', time() - 1000 );
		$wpdb->update( $wpdb->prefix . 'rfrac_entries', array( 'last_hit_at' => $old ), array( 'id' => $a ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->prefix . 'rfrac_entries', array( 'last_hit_at' => $old ), array( 'id' => $b ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// A hit on b (persisted by enforce_cap) makes a the least recently hit.
		$store->record_hit( $store->get( $kb ) );

		$this->assertSame( 1, $store->enforce_cap( 1, 10 ) );
		$this->assertFalse( wp_cache_get( 'hit_buffer', 'rfrac' ) );
		$this->assertSame( '5', $this->column( $b, 'hits' ) );
		$this->assertFalse( $this->row_exists( $a ) );
		$this->assertFalse( wp_cache_get( $object_key, 'rfrac' ) );
		$this->assertNull( $store->get( $ka ) );
		$this->assertNotNull( $store->get( $kb ) );
	}

	public function test_enforce_cap_under_cap_deletes_nothing_but_persists_hits(): void {
		$store            = $this->store( 3, static fn( int $min, int $max ): int => 1 );
		list( $id, $key ) = $this->put( $store, 'a' );
		$store->record_hit( $store->get( $key ) );
		$this->assertSame( 0, $store->enforce_cap( 10, 10 ) );
		$this->assertSame( '3', $this->column( $id, 'hits' ) );
		$this->assertFalse( wp_cache_get( 'hit_buffer', 'rfrac' ) );
	}

	public function test_payload_for_reads_object_cache(): void {
		$store            = $this->store();
		list( $id, $key ) = $this->put( $store, 'a' );
		$this->assertSame( '{"a":1}', $store->payload_for( $id ) );
		$this->assertNull( $store->payload_for( $id + 999 ) );

		wp_cache_delete( $this->object_key( $key ), 'rfrac' );
		$this->assertNull( $store->payload_for( $id ) );
	}

	// ---------- Concurrency with flushes, failed key lookups ----------

	/**
	 * Run a callback once, just before the first query matching a pattern executes.
	 *
	 * Simulates another process acting at that point of the statement sequence.
	 */
	private function before_query( string $pattern, callable $callback ): void {
		$fired  = false;
		$filter = function ( $query ) use ( $pattern, $callback, &$fired, &$filter ) {
			if ( ! $fired && is_string( $query ) && 1 === preg_match( $pattern, $query ) ) {
				$fired = true;
				remove_filter( 'query', $filter );
				$callback();
			}
			return $query;
		};
		add_filter( 'query', $filter );
	}

	/**
	 * Run a callback while every query matching a pattern fails.
	 */
	private function with_failing_queries( string $pattern, callable $callback ) {
		global $wpdb;
		$filter   = static function ( $query ) use ( $pattern ) {
			return is_string( $query ) && 1 === preg_match( $pattern, $query ) ? 'RFRAC INVALID SQL' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			return $callback();
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppress );
		}
	}

	public function test_flush_landing_before_the_object_write_leaves_nothing_servable(): void {
		$store            = $this->store();
		list( $id, $key ) = $this->put( $store, 'race', array( 'post:1' ) );

		// Another process flushes the entry while this one is between its row and its object write.
		$this->before_query(
			'/^\s*INSERT IGNORE INTO \S*rfrac_tags/i',
			function () use ( $id ) {
				$this->assertSame( 1, $this->store()->flush_by_ids( array( $id ) ) );
			}
		);
		$result = $store->set( $this->entry( $key, array( 'post:1' ) ) );

		$this->assertSame( 0, $result, 'A concurrent flush is not a storage failure, but nothing was stored.' );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
		$this->assertNull( $store->get( $key ), 'The flushed row left no servable object.' );
		$this->assertFalse( wp_cache_get( $this->object_key( $key ), 'rfrac' ) );
	}

	public function test_store_landing_between_flush_lookup_and_update_is_still_removed(): void {
		$store = $this->store();
		$this->put( $store, 'other', array( 'post:1' ) );

		// The flush's UPDATE runs after another process stored a new entry with the flushed tag.
		$writer = $this->store();
		$this->before_query(
			'/^\s*UPDATE .*rfrac_tags/is',
			function () use ( $writer ) {
				$this->assertNotNull( $writer->set( $this->entry( 'late', array( 'post:1' ) ) ) );
			}
		);
		$this->assertSame( 2, $store->flush_by_tags( array( 'post:1' ) ) );

		$this->assertNull( $store->get( 'late' ), 'The entry flagged by the UPDATE has no servable object.' );
		$this->assertNull( $store->get( 'other' ) );
	}

	public function test_delete_racing_a_restore_leaves_nothing_servable(): void {
		$store            = $this->store();
		list( $id, $key ) = $this->put( $store, 'gone', array( 'post:1' ) );
		$store->flush_by_ids( array( $id ) );

		// Another process re-stores the flushed entry right before the delete removes its row.
		$this->before_query(
			'/^\s*DELETE FROM \S*rfrac_tags/i',
			function () use ( $key ) {
				$this->assertNotNull( $this->store()->set( $this->entry( $key, array( 'post:1' ) ) ) );
			}
		);
		$this->assertSame( 1, $store->delete( array( $id ) ) );

		$this->assertFalse( $this->row_exists( $id ) );
		$this->assertNull( $store->get( $key ), 'The deleted row left no servable object.' );
	}

	public function test_set_with_unknown_row_state_reports_failure_and_serves_nothing(): void {
		$store  = $this->store();
		$result = $this->with_failing_queries( '/^\s*SELECT state FROM/i', fn() => $store->set( $this->entry( 'unknown', array( 'post:1' ) ) ) );

		$this->assertNull( $result );
		$this->assertNull( $store->get( 'unknown' ) );
	}

	/**
	 * @dataProvider failed_lookup_flushes
	 */
	public function test_failed_key_lookup_after_a_flush_orphans_every_object_key( string $method ): void {
		$store            = $this->store();
		list( $id, $key ) = $this->put( $store, 'a', array( 'post:1' ) );
		list( , $other )  = $this->put( $store, 'b', array( 'post:2' ), 600, '/wp/v2/pages' );
		$before           = (string) wp_cache_get( 'generation', 'rfrac' );

		$args    = array(
			'flush_by_tags'     => array( array( 'post:1' ) ),
			'flush_by_ids'      => array( array( $id ) ),
			'flush_by_url_hash' => array( hash( 'sha256', 'u' . $key ) ),
			'flush_route'       => array( '/wp/v2/posts' ),
			'delete'            => array( array( $id ) ),
		)[ $method ];
		$flushed = $this->with_failing_queries( '/^\s*SELECT (DISTINCT e\.)?cache_key FROM/i', fn() => $store->$method( ...$args ) );

		$this->assertSame( 1, $flushed );
		$this->assertNotSame( $before, (string) wp_cache_get( 'generation', 'rfrac' ), 'The generation was bumped.' );
		$this->assertNull( $store->get( $key ) );
		$this->assertNull( $store->get( $other ), 'Every object key is orphaned, also of active rows (a plain miss).' );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $this->id_of( $other ) ), 'The other row stays active.' );
	}

	private function id_of( string $cache_key ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE cache_key = %s', $wpdb->prefix . 'rfrac_entries', $cache_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function failed_lookup_flushes(): array {
		return array(
			'tags'     => array( 'flush_by_tags' ),
			'ids'      => array( 'flush_by_ids' ),
			'url hash' => array( 'flush_by_url_hash' ),
			'route'    => array( 'flush_route' ),
			'delete'   => array( 'delete' ),
		);
	}

	public function test_failed_key_lookup_in_prune_and_cap_enforcement_orphans_every_object_key(): void {
		$store           = $this->store();
		list( $id )      = $this->put( $store, 'a', array( 'post:1' ) );
		list( , $other ) = $this->put( $store, 'b', array( 'post:2' ) );
		$this->repo->flush_by_ids( array( $id ), gmdate( 'Y-m-d H:i:s' ) );

		$before = (string) wp_cache_get( 'generation', 'rfrac' );
		$this->assertSame( 1, $this->with_failing_queries( '/^\s*SELECT cache_key FROM/i', fn() => $store->prune( 10 ) ) );
		$this->assertNotSame( $before, (string) wp_cache_get( 'generation', 'rfrac' ), 'prune bumped the generation.' );
		$this->assertNull( $store->get( $other ) );

		list( , $c ) = $this->put( $store, 'c', array( 'post:3' ) );
		$before      = (string) wp_cache_get( 'generation', 'rfrac' );
		$this->assertSame( 1, $this->with_failing_queries( '/^\s*SELECT cache_key FROM/i', fn() => $store->enforce_cap( 1, 10 ) ) );
		$this->assertNotSame( $before, (string) wp_cache_get( 'generation', 'rfrac' ), 'enforce_cap bumped the generation.' );
		$this->assertNull( $store->get( $c ) );
	}

	/**
	 * @dataProvider failed_flushes
	 */
	public function test_failed_flush_update_orphans_every_object_key_and_is_recorded( string $method ): void {
		delete_option( Failure_Recorder::OPTION );
		$store            = $this->store();
		list( $id, $key ) = $this->put( $store, 'a', array( 'post:1' ) );
		list( , $other )  = $this->put( $store, 'b', array( 'post:2' ) );
		$before           = (string) wp_cache_get( 'generation', 'rfrac' );

		$args    = array(
			'flush_by_tags'     => array( array( 'post:1' ) ),
			'flush_by_ids'      => array( array( $id ) ),
			'flush_by_url_hash' => array( hash( 'sha256', 'u' . $key ) ),
			'flush_route'       => array( '/wp/v2/posts' ),
			'flush_all'         => array(),
		)[ $method ];
		$flushed = $this->with_failing_queries( '/^\s*UPDATE/i', fn() => $store->$method( ...$args ) );

		$this->assertSame( 0, $flushed );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ), 'Precondition: the row is still active.' );
		$this->assertNotSame( $before, (string) wp_cache_get( 'generation', 'rfrac' ), 'The generation was bumped.' );
		$this->assertNull( $store->get( $key ), 'The stale response is not served.' );
		$this->assertNull( $store->get( $other ) );
		$this->assertSame( 'flush', Failure_Recorder::last()['context'] ?? null );
		delete_option( Failure_Recorder::OPTION );
	}

	public static function failed_flushes(): array {
		return array(
			'tags'     => array( 'flush_by_tags' ),
			'ids'      => array( 'flush_by_ids' ),
			'url hash' => array( 'flush_by_url_hash' ),
			'route'    => array( 'flush_route' ),
			'all'      => array( 'flush_all' ),
		);
	}

	public function test_failed_flush_by_keys_still_removes_the_objects_and_is_recorded(): void {
		delete_option( Failure_Recorder::OPTION );
		$store            = $this->store();
		list( $id, $key ) = $this->put( $store, 'a', array( 'post:1' ) );

		$this->assertSame( 0, $this->with_failing_queries( '/^\s*UPDATE/i', fn() => $store->flush_by_keys( array( $key ) ) ) );

		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ) );
		$this->assertNull( $store->get( $key ) );
		$this->assertSame( 'flush', Failure_Recorder::last()['context'] ?? null );
		delete_option( Failure_Recorder::OPTION );
	}

	public function test_re_store_dying_after_reactivating_a_flushed_row_leaves_no_stale_object(): void {
		$store            = $this->store();
		list( $id, $key ) = $this->put( $store, 'stale', array( 'post:1' ) );
		// A flush flagged the row; its key lookup has not run yet.
		$this->repo->flush_by_ids( array( $id ), gmdate( 'Y-m-d H:i:s' ) );
		$this->assertNotFalse( wp_cache_get( $this->object_key( $key ), 'rfrac' ), 'Precondition: the pre-flush object is still there.' );

		// Another request re-stores the entry (re-activating the row) and dies before writing its object.
		$die = static function ( $query ) {
			if ( is_string( $query ) && 1 === preg_match( '/^\s*DELETE FROM \S*rfrac_tags/i', $query ) ) {
				throw new RuntimeException( 'request died' );
			}
			return $query;
		};
		add_filter( 'query', $die );
		try {
			$store->set( $this->entry( $key, array( 'post:1' ) ) );
			$this->fail( 'Expected the simulated death.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'request died', $e->getMessage() );
		} finally {
			remove_filter( 'query', $die );
		}

		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ), 'The row was re-activated.' );
		$this->assertNull( $store->get( $key ), 'The pre-flush payload is not served.' );
	}

	public function test_flush_matching_nothing_runs_no_key_lookup(): void {
		$store = $this->store();
		$this->put( $store, 'a', array( 'post:1' ) );

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$this->assertSame( 0, $store->flush_by_tags( array( 'post:404' ) ) );
		$recorder->stop();
		$this->assertCount( 1, $recorder->matching( '/^\s*UPDATE/i' ) );
		$this->assertCount( 0, $recorder->matching( '/^\s*SELECT/i' ) );
	}

	public function test_deleting_nothing_runs_no_query(): void {
		$store    = $this->store();
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$this->assertSame( 0, $store->delete( array() ) );
		$this->assertSame( 0, $store->prune( 10 ) );
		$recorder->stop();
		$this->assertCount( 1, $recorder->matching( '/./' ), 'Only the SELECT of prunable ids.' );
	}
}
