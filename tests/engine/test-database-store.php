<?php
/**
 * Tests for the database store.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Storage\Cached_Response;
use Rockfire\Rest_Api_Cache\Storage\Database_Store;
use Rockfire\Rest_Api_Cache\Storage\Entry_Data;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Interface;

/**
 * @covers \Rockfire\Rest_Api_Cache\Storage\Database_Store
 * @covers \Rockfire\Rest_Api_Cache\Storage\Cached_Response
 * @covers \Rockfire\Rest_Api_Cache\Storage\Entry_Data
 */
class Test_Database_Store extends Rfrac_Test_Case {

	/**
	 * Repository.
	 *
	 * @var Repository
	 */
	private Repository $repo;

	public function set_up() {
		parent::set_up();
		$this->repo = new Repository();
	}

	private function store( int $sampling = 10, ?Closure $rng = null ): Database_Store {
		return new Database_Store( $this->repo, $sampling, $rng );
	}

	private function entry( string $key, array $tags = array(), int $ttl = 600 ): Entry_Data {
		return new Entry_Data(
			$key,
			hash( 'sha256', 'u' . $key ),
			'/wp/v2/posts',
			'/wp/v2/posts',
			array( 'path' => '/wp/v2/posts' ),
			'GET',
			'{"a":1}',
			array( 'X-Test' => 'yes' ),
			$ttl,
			$tags
		);
	}

	private function hits( int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT hits FROM %i WHERE id = %d', $wpdb->prefix . 'rfrac_entries', $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function id_for_key( string $key ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE cache_key = %s', $wpdb->prefix . 'rfrac_entries', $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function test_set_with_failing_tag_write_returns_null_and_flushes_the_entry(): void {
		$store = $this->store();
		$id    = $this->with_failing_tag_writes( fn() => $store->set( $this->entry( 'kfail', array( 'post:1' ) ) ) );

		$this->assertNull( $id );
		$row_id = $this->id_for_key( 'kfail' );
		$this->assertGreaterThan( 0, $row_id );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $row_id ) );
		$this->assertNull( $store->get( 'kfail' ) );
	}

	public function test_implements_interface_and_name(): void {
		$store = $this->store();
		$this->assertInstanceOf( Store_Interface::class, $store );
		$this->assertSame( 'database', $store->name() );
	}

	public function test_get_hit_is_one_select_and_no_write_when_rng_says_no(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry( array( 'headers' => '{"X-A":"b"}' ) );
		$key = (string) $this->repo->get( $id )?->cache_key;

		$store    = $this->store( 10, static fn( int $min, int $max ): int => 2 );
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$hit = $store->get( $key );
		$this->assertNotNull( $hit );
		$store->record_hit( $hit );
		$recorder->stop();

		$this->assertCount( 1, $recorder->matching( '/^\s*SELECT/i' ) );
		$this->assertCount( 0, $recorder->matching( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)/i' ) );
		$this->assertSame( 0, $this->hits( $id ) );
		$this->assertSame( $id, $hit->entry_id );
		$this->assertSame( $key, $hit->cache_key );
		$this->assertSame( '{"ok":true}', $hit->payload );
		$this->assertSame( array( 'X-A' => 'b' ), $hit->headers );
		$this->assertGreaterThan( time(), $hit->expires_at );
		$this->assertLessThanOrEqual( time(), $hit->created_at );
	}

	public function test_sampled_hit_adds_n_hits_in_two_queries(): void {
		$id  = Rfrac_Entry_Fixtures::insert_entry();
		$key = (string) $this->repo->get( $id )?->cache_key;

		$store    = $this->store( 10, static fn( int $min, int $max ): int => 1 );
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$hit = $store->get( $key );
		$this->assertNotNull( $hit );
		$store->record_hit( $hit );
		$recorder->stop();

		$this->assertCount( 1, $recorder->matching( '/^\s*SELECT/i' ) );
		$this->assertCount( 1, $recorder->matching( '/^\s*UPDATE/i' ) );
		$this->assertSame( 10, $this->hits( $id ) );
	}

	public function test_rng_receives_one_and_n(): void {
		$id    = Rfrac_Entry_Fixtures::insert_entry();
		$seen  = array();
		$store = $this->store(
			7,
			static function ( int $min, int $max ) use ( &$seen ): int {
				$seen = array( $min, $max );
				return 2;
			}
		);
		$store->record_hit( new Cached_Response( $id, 'k', '{}', array(), 1, 2 ) );
		$this->assertSame( array( 1, 7 ), $seen );
	}

	public function test_zero_sampling_never_writes(): void {
		$id    = Rfrac_Entry_Fixtures::insert_entry();
		$called = false;
		$store = $this->store(
			0,
			static function () use ( &$called ): int {
				$called = true;
				return 1;
			}
		);
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$store->record_hit( new Cached_Response( $id, 'k', '{}', array(), 1, 2 ) );
		$recorder->stop();
		$this->assertSame( array(), $recorder->matching( '/./' ) );
		$this->assertFalse( $called );
		$this->assertSame( 0, $this->hits( $id ) );
	}

	public function test_default_rng_works(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry();
		$this->store( 1 )->record_hit( new Cached_Response( $id, 'k', '{}', array(), 1, 2 ) );
		$this->assertSame( 1, $this->hits( $id ) );
	}

	public function test_get_misses(): void {
		$store = $this->store();
		$this->assertNull( $store->get( 'nope' ) );

		$id = Rfrac_Entry_Fixtures::insert_entry( array( 'cache_key' => 'null-payload', 'payload' => null ) );
		$this->assertGreaterThan( 0, $id );
		$this->assertNull( $store->get( 'null-payload' ) );

		Rfrac_Entry_Fixtures::insert_entry( array( 'cache_key' => 'bad-headers', 'headers' => 'not json' ) );
		$this->assertNull( $store->get( 'bad-headers' ) );

		Rfrac_Entry_Fixtures::insert_entry( array( 'cache_key' => 'flushed', 'state' => 2 ) );
		$this->assertNull( $store->get( 'flushed' ) );

		Rfrac_Entry_Fixtures::insert_entry( array( 'cache_key' => 'old', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ) );
		$this->assertNull( $store->get( 'old' ) );
	}

	public function test_set_stores_entry_and_get_returns_it(): void {
		$store = $this->store();
		$id    = $store->set( $this->entry( 'k1', array( 'post:1', 'post:2' ), 600 ) );
		$this->assertIsInt( $id );

		$row = $this->repo->get( $id );
		$this->assertNotNull( $row );
		$this->assertSame( 7, $row->size );
		$this->assertSame( array( 'post:1', 'post:2' ), $this->repo->tags_for( $id ) );

		$hit = $store->get( 'k1' );
		$this->assertNotNull( $hit );
		$this->assertSame( array( 'X-Test' => 'yes' ), $hit->headers );
		$this->assertEqualsWithDelta( 600, $hit->expires_at - $hit->created_at, 1 );
		$this->assertSame( '{"path":"\/wp\/v2\/posts"}', $row->request );
		$this->assertSame( $row->created_at, $row->last_hit_at );
	}

	public function test_set_reactivates_flushed_key_and_replaces_tags(): void {
		$store = $this->store();
		$id    = $store->set( $this->entry( 'k1', array( 'a', 'b' ) ) );
		$store->flush_by_tags( array( 'a' ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( (int) $id ) );
		$this->assertNull( $store->get( 'k1' ) );

		$again = $store->set( $this->entry( 'k1', array( 'c' ) ) );
		$this->assertSame( $id, $again );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( (int) $id ) );
		$this->assertSame( array( 'c' ), $this->repo->tags_for( (int) $id ) );
		$this->assertNotNull( $store->get( 'k1' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
	}

	public function test_flush_methods_delegate(): void {
		$store = $this->store();
		$a     = (int) $store->set( $this->entry( 'a', array( 't1' ) ) );
		$b     = (int) $store->set( $this->entry( 'b' ) );
		$c     = (int) $store->set( $this->entry( 'c' ) );
		$d     = (int) $store->set( $this->entry( 'd' ) );

		$this->assertSame( 1, $store->flush_by_tags( array( 't1' ) ) );
		$this->assertSame( 1, $store->flush_by_ids( array( $b ) ) );
		$this->assertSame( 1, $store->flush_by_keys( array( 'c' ) ) );
		$this->assertSame( 1, $store->flush_by_url_hash( hash( 'sha256', 'ud' ) ) );
		foreach ( array( $a, $b, $c, $d ) as $id ) {
			$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
		}

		$store->set( $this->entry( 'e' ) );
		$store->set( $this->entry( 'f' ) );
		$this->assertSame( 2, $store->flush_route( '/wp/v2/posts' ) );
		$this->assertSame( 0, $store->flush_all() );
		$store->set( $this->entry( 'g' ) );
		$store->set( $this->entry( 'h' ) );
		$this->assertSame( 2, $store->flush_all() );
	}

	public function test_delete_removes_rows_and_tags(): void {
		$store = $this->store();
		$id    = (int) $store->set( $this->entry( 'a', array( 't1', 't2' ) ) );
		$this->assertSame( 1, $store->delete( array( $id ) ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count() );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count_tags() );
		$this->assertNull( $store->payload_for( $id ) );
	}

	public function test_payload_for(): void {
		$store = $this->store();
		$id    = (int) $store->set( $this->entry( 'a' ) );
		$this->assertSame( '{"a":1}', $store->payload_for( $id ) );
		$this->assertNull( $store->payload_for( $id + 999 ) );
	}

	public function test_expired_entry_is_a_miss_and_is_pruned(): void {
		$id    = Rfrac_Entry_Fixtures::insert_entry( array( 'cache_key' => 'old', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 't' ) );
		$keep  = Rfrac_Entry_Fixtures::insert_entry( array( 'cache_key' => 'keep' ) );
		$store = $this->store();

		$this->assertNull( $store->get( 'old' ) );
		$this->assertSame( 1, $store->prune( 100 ) );
		$this->assertSame( '', Rfrac_Entry_Fixtures::get_state( $id ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $keep ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count_tags() );
	}

	public function test_prune_removes_flushed_and_respects_batch(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			Rfrac_Entry_Fixtures::insert_entry( array( 'state' => 2 ) );
		}
		$active = Rfrac_Entry_Fixtures::insert_entry();
		$store  = $this->store();

		$this->assertSame( 3, $store->prune( 3 ) );
		$this->assertSame( 2, $store->prune( 100 ) );
		$this->assertSame( 0, $store->prune( 100 ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $active ) );
	}

	public function test_enforce_cap_deletes_least_recently_hit_with_tags(): void {
		$ids = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$ids[] = Rfrac_Entry_Fixtures::insert_entry(
				array( 'last_hit_at' => gmdate( 'Y-m-d H:i:s', time() - 1000 + $i * 10 ) ),
				array( 'tag-' . $i )
			);
		}
		$store = $this->store();

		$this->assertSame( 2, $store->enforce_cap( 10, 100 ) );
		$this->assertSame( 10, Rfrac_Entry_Fixtures::count() );
		$this->assertSame( 10, Rfrac_Entry_Fixtures::count_tags() );
		$this->assertSame( '', Rfrac_Entry_Fixtures::get_state( $ids[0] ) );
		$this->assertSame( '', Rfrac_Entry_Fixtures::get_state( $ids[1] ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $ids[2] ) );
		$this->assertSame( 0, $store->enforce_cap( 10, 100 ) );
	}

	public function test_enforce_cap_respects_batch_size(): void {
		for ( $i = 0; $i < 12; $i++ ) {
			Rfrac_Entry_Fixtures::insert_entry();
		}
		$this->assertSame( 1, $this->store()->enforce_cap( 5, 1 ) );
		$this->assertSame( 11, Rfrac_Entry_Fixtures::count() );
	}

	public function test_store_fires_no_plugin_hooks(): void {
		$seen = array();
		$spy  = static function ( $hook ) use ( &$seen ) {
			if ( is_string( $hook ) && str_starts_with( $hook, 'rfrac' ) ) {
				$seen[] = $hook;
			}
		};
		add_action( 'all', $spy );
		$store = $this->store( 1, static fn(): int => 1 );
		$id    = (int) $store->set( $this->entry( 'h' ) );
		$hit   = $store->get( 'h' );
		$this->assertNotNull( $hit );
		$store->record_hit( $hit );
		$store->flush_all();
		$store->prune( 10 );
		$store->delete( array( $id ) );
		remove_action( 'all', $spy );

		$this->assertSame( array(), $seen );
	}

	public function test_failed_flush_counts_as_zero_and_is_recorded(): void {
		global $wpdb;
		delete_option( Failure_Recorder::OPTION );
		$store = $this->store();
		$id    = $store->set( $this->entry( 'k', array( 'post:1' ) ) );

		$suppress = $wpdb->suppress_errors( true );
		$fail     = static fn( $query ) => is_string( $query ) && 1 === preg_match( '/^\s*UPDATE/i', $query ) ? 'RFRAC INVALID SQL' : $query;
		add_filter( 'query', $fail );
		try {
			$this->assertSame( 0, $store->flush_by_tags( array( 'post:1' ) ) );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( (int) $id ) );
		$this->assertSame( 'flush', Failure_Recorder::last()['context'] ?? null );
		delete_option( Failure_Recorder::OPTION );
	}
}
