<?php
/**
 * Tests for the entries/tags repository.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Storage\Entry_Query;
use Rockfire\Rest_Api_Cache\Storage\Entry_Row;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Storage\Repository
 * @covers \Rockfire\Rest_Api_Cache\Storage\Entry_Query
 * @covers \Rockfire\Rest_Api_Cache\Storage\Entry_Row
 * @covers \Rockfire\Rest_Api_Cache\Storage\Stats
 */
class Test_Repository extends Rfrac_Test_Case {

	/**
	 * Repository under test.
	 *
	 * @var Repository
	 */
	private Repository $repo;

	public function set_up() {
		parent::set_up();
		$this->repo = new Repository();
	}

	private function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	private function past(): string {
		return gmdate( 'Y-m-d H:i:s', time() - 60 );
	}

	/**
	 * Entry data for upsert_entry().
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 *
	 * @return array<string,mixed>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'cache_key'  => hash( 'sha256', 'k' ),
				'url_hash'   => hash( 'sha256', 'u' ),
				'route'      => '/wp/v2/posts',
				'rule_key'   => '/wp/v2/posts',
				'request'    => '/wp/v2/posts?x=1',
				'method'     => 'GET',
				'payload'    => '{"a":1}',
				'headers'    => '[]',
				'size'       => 7,
				'created_at' => $this->past(),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
			),
			$overrides
		);
	}

	public function test_flush_by_tags_is_one_update(): void {
		$ids = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$ids[] = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1', 'other:' . $i ) );
		}
		$untouched = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:2' ) );

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$flushed = $this->repo->flush_by_tags( array( 'post:1' ), $this->now() );
		$recorder->stop();

		$this->assertSame( 50, $flushed );
		$this->assertCount( 2, $recorder->matching( '/./' ), 'The epoch write and the UPDATE.' );
		$this->assertCount( 1, $recorder->matching( '/rfrac_(entries|tags)/' ), 'Exactly one statement on the cache tables.' );
		$this->assertCount( 1, $recorder->matching( '/^\s*UPDATE\b/i' ) );
		foreach ( $ids as $id ) {
			$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
		}
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $untouched ) );
	}

	public function test_flush_by_tags_counts_entry_once_for_several_matching_tags(): void {
		Rfrac_Entry_Fixtures::insert_entry( array(), array( 'a', 'b' ) );
		$this->assertSame( 1, $this->repo->flush_by_tags( array( 'a', 'b' ), $this->now() ) );
		$this->assertSame( 0, $this->repo->flush_by_tags( array( 'a', 'b' ), $this->now() ), 'Already flushed.' );
		$this->assertSame( 0, $this->repo->flush_by_tags( array(), $this->now() ) );
	}

	public function test_flush_by_tags_chunks_over_1000_tags(): void {
		$id   = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'tag-1400' ) );
		$tags = array();
		for ( $i = 0; $i < 1500; $i++ ) {
			$tags[] = 'tag-' . $i;
		}

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$this->assertSame( 1, $this->repo->flush_by_tags( $tags, $this->now() ) );
		$recorder->stop();

		$this->assertCount( 2, $recorder->matching( '/^\s*UPDATE\b/i' ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
	}

	public function test_replace_tags_inserts_1200_tags_with_three_statements(): void {
		$id   = Rfrac_Entry_Fixtures::insert_entry();
		$tags = array();
		for ( $i = 0; $i < 1200; $i++ ) {
			$tags[] = 'tag:' . $i;
		}

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$this->repo->replace_tags( $id, $tags );
		$recorder->stop();

		$this->assertCount( 3, $recorder->matching( '/^\s*INSERT IGNORE\b/i' ) );
		$this->assertCount( 1, $recorder->matching( '/^\s*DELETE\b/i' ) );
		$this->assertCount( 1200, $this->repo->tags_for( $id ) );
	}

	public function test_replace_tags_replaces_and_deduplicates(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'old' ) );
		$this->repo->replace_tags( $id, array( 'b', 'a', 'b', '' ) );
		$this->assertSame( array( 'a', 'b' ), $this->repo->tags_for( $id ) );
		$this->repo->replace_tags( $id, array() );
		$this->assertSame( array(), $this->repo->tags_for( $id ) );
	}

	public function test_replace_tags_returns_true_on_success_including_empty_list(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry();
		$this->assertTrue( $this->repo->replace_tags( $id, array( 'a', 'b' ) ) );
		$this->assertTrue( $this->repo->replace_tags( $id, array( 'a', 'b' ) ) ); // INSERT IGNORE of duplicates affects 0 rows.
		$this->assertTrue( $this->repo->replace_tags( $id, array() ) );
	}

	public function test_replace_tags_returns_false_when_the_insert_fails(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry();
		$ok = $this->with_failing_tag_writes( fn() => $this->repo->replace_tags( $id, array( 'a' ) ) );
		$this->assertFalse( $ok );
	}

	public function test_replace_tags_returns_false_when_the_delete_fails(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry();
		$ok = $this->with_failing_tag_writes( fn() => $this->repo->replace_tags( $id, array() ), 'DELETE' );
		$this->assertFalse( $ok );
	}

	public function test_search_percent_matches_only_literal_percent(): void {
		$match = Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/100%25-off' ) );
		Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/posts' ) );
		Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/a_b' ) );

		$query = Entry_Query::from_array( array( 'search' => '%' ) );
		$rows  = $this->repo->query( $query );

		$this->assertSame( 1, $this->repo->count( $query ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( $match, $rows[0]->id );

		$this->assertSame( 1, $this->repo->count( Entry_Query::from_array( array( 'search' => '_' ) ) ), 'Underscore is literal too.' );
	}

	public function test_search_also_matches_request(): void {
		Rfrac_Entry_Fixtures::insert_entry( array( 'request' => '/wp/v2/posts?search=needle' ) );
		Rfrac_Entry_Fixtures::insert_entry();
		$this->assertSame( 1, $this->repo->count( Entry_Query::from_array( array( 'search' => 'needle' ) ) ) );
	}

	public function test_flush_route_does_not_flush_sibling_route_prefix(): void {
		$posts = Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/posts' ) );
		$post  = Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/post' ) );
		$sub   = Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/post/5' ) );

		$this->assertSame( 2, $this->repo->flush_route( '/wp/v2/post', $this->now() ) );

		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $posts ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $post ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $sub ) );
	}

	public function test_flush_route_matches_rule_key(): void {
		$rule  = '/wp/v2/posts/(?P<id>[\d]+)';
		$match = Rfrac_Entry_Fixtures::insert_entry(
			array(
				'route'    => '/wp/v2/posts/5',
				'rule_key' => $rule,
			)
		);
		$other = Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/posts/6' ) );

		$this->assertSame( 1, $this->repo->flush_route( $rule, $this->now() ) );

		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $match ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $other ) );
		$this->assertSame( array(), $this->repo->keys_for_route( $rule ), 'Flushed entries are no longer matched.' );
	}

	public function test_find_active_hit_expired_and_flushed(): void {
		$key = hash( 'sha256', 'k' );
		$id  = $this->repo->upsert_entry( $this->row() );

		$found = $this->repo->find_active( $key, $this->now() );
		$this->assertIsArray( $found );
		$this->assertSame( $id, (int) $found['id'] );
		$this->assertSame( '{"a":1}', $found['payload'] );

		$this->assertNull( $this->repo->find_active( $key, gmdate( 'Y-m-d H:i:s', time() + 7200 ) ), 'Expired.' );
		$this->assertNull( $this->repo->find_active( 'nope', $this->now() ) );

		$this->repo->flush_by_ids( array( $id ), $this->now() );
		$this->assertNull( $this->repo->find_active( $key, $this->now() ), 'Flushed.' );
	}

	public function test_find_active_on_expired_row_is_null(): void {
		$entry = Rfrac_Entry_Fixtures::insert_entry( array( 'expires_at' => $this->past() ) );
		$this->assertGreaterThan( 0, $entry );
		global $wpdb;
		$key = $wpdb->get_var( $wpdb->prepare( 'SELECT cache_key FROM %i WHERE id = %d', $wpdb->prefix . Names::TABLE_ENTRIES, $entry ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertNull( $this->repo->find_active( (string) $key, $this->now() ) );
	}

	public function test_upsert_inserts_then_refreshes_and_revives(): void {
		$first = $this->repo->upsert_entry( $this->row() );
		$this->assertGreaterThan( 0, $first );
		$entry = $this->repo->get( $first );
		$this->assertSame( 0, $entry->hits );
		$this->assertSame( $entry->created_at, $entry->last_hit_at );

		$this->repo->record_hit( $first, 3, $this->now() );
		$this->repo->flush_by_ids( array( $first ), $this->now() );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $first ) );

		$second = $this->repo->upsert_entry( $this->row( array( 'payload' => 'new', 'size' => 3 ) ) );

		$this->assertSame( $first, $second, 'Same row, same id.' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$entry = $this->repo->get( $first );
		$this->assertSame( Entry_Row::STATE_ACTIVE, $entry->state );
		$this->assertNull( $entry->flushed_at );
		$this->assertSame( 3, $entry->size );
		$this->assertSame( 3, $entry->hits, 'Hit counter survives a refresh.' );
		$this->assertSame( 'new', $this->repo->find_active( hash( 'sha256', 'k' ), $this->now() )['payload'] );
	}

	/**
	 * Whether the payload column of an entry is SQL NULL, read straight from the table (not through the Repository).
	 *
	 * @param int $id Entry id.
	 *
	 * @return bool
	 */
	private function payload_is_sql_null( int $id ): bool {
		global $wpdb;
		$is_null = $wpdb->get_var( $wpdb->prepare( 'SELECT payload IS NULL FROM %i WHERE id = %d', $wpdb->prefix . Names::TABLE_ENTRIES, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertNotNull( $is_null, "Entry {$id} exists." );
		return '1' === (string) $is_null;
	}

	public function test_upsert_stores_a_null_payload_as_sql_null_and_swaps_it_with_a_string_on_refresh(): void {
		$null_row = $this->row( array( 'payload' => null ) );

		$first = $this->repo->upsert_entry( $null_row );
		$this->assertGreaterThan( 0, $first );
		$this->assertTrue( $this->payload_is_sql_null( $first ), 'A null payload inserts SQL NULL, not an empty string.' );

		$second = $this->repo->upsert_entry( $this->row( array( 'payload' => 'text' ) ) );
		$this->assertSame( $first, $second, 'Same row, same id.' );
		$this->assertFalse( $this->payload_is_sql_null( $first ), 'Refreshing with a string stores the string.' );
		$this->assertSame( 'text', $this->repo->find_active( hash( 'sha256', 'k' ), $this->now() )['payload'] );

		$third = $this->repo->upsert_entry( $null_row );
		$this->assertSame( $first, $third, 'Same row, same id.' );
		$this->assertTrue( $this->payload_is_sql_null( $first ), 'Refreshing with null again stores SQL NULL.' );

		$fourth = $this->repo->upsert_entry( $this->row( array( 'payload' => '' ) ) );
		$this->assertSame( $first, $fourth, 'Same row, same id.' );
		$this->assertFalse( $this->payload_is_sql_null( $first ), 'An empty string is a payload, not NULL.' );
		$this->assertSame( '', $this->repo->find_active( hash( 'sha256', 'k' ), $this->now() )['payload'] );

		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
	}

	public function test_record_hit_and_record_hits(): void {
		$a = Rfrac_Entry_Fixtures::insert_entry();
		$b = Rfrac_Entry_Fixtures::insert_entry();
		$c = Rfrac_Entry_Fixtures::insert_entry();
		$t = '2030-01-02 03:04:05';

		$this->repo->record_hit( $a, 2, $t );
		$this->assertSame( 2, $this->repo->get( $a )->hits );
		$this->assertSame( $t, $this->repo->get( $a )->last_hit_at );

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$this->repo->record_hits(
			array(
				$a => array( 5, '2031-01-01 00:00:00' ),
				$b => array( 1, '2032-01-01 00:00:00' ),
			)
		);
		$recorder->stop();

		$this->assertCount( 1, $recorder->matching( '/^\s*UPDATE\b/i' ) );
		$this->assertSame( 7, $this->repo->get( $a )->hits );
		$this->assertSame( '2031-01-01 00:00:00', $this->repo->get( $a )->last_hit_at );
		$this->assertSame( 1, $this->repo->get( $b )->hits );
		$this->assertSame( 0, $this->repo->get( $c )->hits );
	}

	public function test_record_hits_chunks_of_500(): void {
		$hits = array();
		for ( $i = 1; $i <= 1001; $i++ ) {
			$hits[ $i ] = array( 1, $this->now() );
		}
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$this->repo->record_hits( $hits );
		$recorder->stop();
		$this->assertCount( 3, $recorder->matching( '/^\s*UPDATE\b/i' ) );
	}

	public function test_flush_by_ids_keys_url_hash_and_all(): void {
		$a = Rfrac_Entry_Fixtures::insert_entry( array( 'cache_key' => str_repeat( 'a', 64 ), 'url_hash' => str_repeat( 'u', 64 ) ) );
		$b = Rfrac_Entry_Fixtures::insert_entry( array( 'url_hash' => str_repeat( 'u', 64 ) ) );
		$c = Rfrac_Entry_Fixtures::insert_entry();
		$d = Rfrac_Entry_Fixtures::insert_entry();
		$e = Rfrac_Entry_Fixtures::insert_entry();

		$this->assertSame( 1, $this->repo->flush_by_keys( array( str_repeat( 'a', 64 ) ), $this->now() ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $a ) );
		$this->assertSame( 1, $this->repo->flush_by_url_hash( str_repeat( 'u', 64 ), $this->now() ), 'Only the still active one.' );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $b ) );
		$this->assertSame( 1, $this->repo->flush_by_ids( array( $c, 0 ), $this->now() ) );
		$this->assertSame( 0, $this->repo->flush_by_ids( array(), $this->now() ) );
		$this->assertSame( 2, $this->repo->flush_all( $this->now() ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $d ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $e ) );
	}

	public function test_keys_for_matchers_return_active_cache_keys(): void {
		$a = Rfrac_Entry_Fixtures::insert_entry( array( 'cache_key' => str_repeat( 'a', 64 ), 'url_hash' => str_repeat( 'h', 64 ), 'route' => '/x/y' ), array( 't1', 't2' ) );
		Rfrac_Entry_Fixtures::insert_entry( array( 'cache_key' => str_repeat( 'b', 64 ), 'state' => 2 ), array( 't1' ) );
		Rfrac_Entry_Fixtures::insert_entry( array( 'cache_key' => str_repeat( 'c', 64 ) ), array( 't9' ) );

		$this->assertSame( array( str_repeat( 'a', 64 ) ), $this->repo->keys_for_tags( array( 't1', 't2' ) ) );
		$this->assertSame( array( str_repeat( 'a', 64 ) ), $this->repo->keys_for_url_hash( str_repeat( 'h', 64 ) ) );
		$this->assertSame( array( str_repeat( 'a', 64 ) ), $this->repo->keys_for_route( '/x' ) );
		$this->assertSame( array( str_repeat( 'a', 64 ) ), $this->repo->keys_for_ids( array( $a ) ) );
		$this->assertSame( array(), $this->repo->keys_for_tags( array() ) );
	}

	public function test_delete_entries_removes_tags_and_chunks(): void {
		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'x', 'y' ) );
		}
		$keep = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'x' ) );

		$this->assertSame( 3, $this->repo->delete_entries( $ids ) );

		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count_tags() );
		$this->assertSame( array( 'x' ), $this->repo->tags_for( $keep ) );
		$this->assertSame( 0, $this->repo->delete_entries( array() ) );
	}

	public function test_prunable_lru_and_counts(): void {
		$now     = $this->now();
		$expired = Rfrac_Entry_Fixtures::insert_entry( array( 'expires_at' => $this->past() ) );
		$flushed = Rfrac_Entry_Fixtures::insert_entry( array( 'state' => 2 ) );
		$old     = Rfrac_Entry_Fixtures::insert_entry( array( 'last_hit_at' => '2020-01-01 00:00:00' ) );
		$older   = Rfrac_Entry_Fixtures::insert_entry( array( 'last_hit_at' => '2019-01-01 00:00:00' ) );
		$fresh   = Rfrac_Entry_Fixtures::insert_entry( array( 'last_hit_at' => '2030-01-01 00:00:00' ) );

		$this->assertSame( array( $expired, $flushed ), $this->repo->prunable_ids( $now, 10 ) );
		$this->assertSame( array( $expired ), $this->repo->prunable_ids( $now, 1 ) );
		$this->assertSame( 2, $this->repo->count_stale( $now ) );
		$this->assertSame( 4, $this->repo->count_active() );
		$this->assertSame( array( $older, $old ), array_slice( $this->repo->lru_ids( 2 ), 0, 2 ) );
		$this->assertNotContains( $flushed, $this->repo->lru_ids( 10 ) );
		$this->assertContains( $fresh, $this->repo->lru_ids( 10 ) );
	}

	public function test_query_filters_state_route_rule_tag(): void {
		$active  = Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/posts', 'rule_key' => 'r1' ), array( 'post:1' ) );
		$flushed = Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/posts/3', 'rule_key' => 'r2', 'state' => 2 ) );
		$expired = Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/pages', 'rule_key' => 'r3', 'expires_at' => $this->past() ) );

		$ids = function ( array $args ): array {
			$rows = $this->repo->query( Entry_Query::from_array( $args + array( 'orderby' => 'id', 'order' => 'asc' ) ) );
			return array_map( static fn( Entry_Row $row ) => $row->id, $rows );
		};

		$this->assertSame( array( $active, $flushed, $expired ), $ids( array() ) );
		$this->assertSame( array( $active ), $ids( array( 'state' => 'active' ) ) );
		$this->assertSame( array( $flushed ), $ids( array( 'state' => 'flushed' ) ) );
		$this->assertSame( array( $expired ), $ids( array( 'state' => 'expired' ) ) );
		$this->assertSame( array( $active, $flushed ), $ids( array( 'route_prefix' => '/wp/v2/posts' ) ) );
		$this->assertSame( array( $flushed ), $ids( array( 'rule_key' => 'r2' ) ) );
		$this->assertSame( array( $active ), $ids( array( 'tag' => 'post:1' ) ) );
		$this->assertSame( array( $expired, $flushed, $active ), $ids( array( 'order' => 'desc' ) ) );
		$this->assertSame( 1, $this->repo->count( Entry_Query::from_array( array( 'state' => 'expired' ) ) ) );
	}

	/**
	 * Entries for the listing filters: every filter has rows that match it and rows that only almost do.
	 *
	 * @return array<int,array<string,mixed>> The seeded rows keyed by entry id: their columns and their `tags`.
	 */
	private function seed_listing(): array {
		$future = gmdate( 'Y-m-d H:i:s', time() + 3600 );
		$past   = gmdate( 'Y-m-d H:i:s', time() - 3600 );
		// Values repeat in every column that can be sorted on, so the id tie-break is exercised too.
		$rows = array(
			array( 'route' => '/wp/v2/posts', 'request' => '/wp/v2/posts?x=alpha', 'rule_key' => 'r1', 'state' => 1, 'expires_at' => $future, 'method' => 'GET', 'size' => 30, 'hits' => 2, 'created_at' => '2024-01-03 00:00:00', 'last_hit_at' => '2024-02-01 00:00:00' ),
			array( 'route' => '/wp/v2/posts/3', 'request' => '/wp/v2/posts/3', 'rule_key' => 'r2', 'state' => 2, 'expires_at' => $future, 'method' => 'GET', 'size' => 10, 'hits' => 2, 'created_at' => '2024-01-01 00:00:00', 'last_hit_at' => '2024-02-03 00:00:00' ),
			array( 'route' => '/wp/v2/pages', 'request' => '/wp/v2/pages?q=alpha', 'rule_key' => 'r1', 'state' => 1, 'expires_at' => $past, 'method' => 'POST', 'size' => 20, 'hits' => 0, 'created_at' => '2024-01-02 00:00:00', 'last_hit_at' => '2024-02-02 00:00:00' ),
			array( 'route' => '/wp/v2/posts', 'request' => '/wp/v2/posts?x=beta', 'rule_key' => 'r3', 'state' => 1, 'expires_at' => $future, 'method' => 'POST', 'size' => 20, 'hits' => 9, 'created_at' => '2024-01-02 00:00:00', 'last_hit_at' => '2024-02-02 00:00:00' ),
			array( 'route' => '/wp/v2/postscript', 'request' => '/wp/v2/postscript', 'rule_key' => 'r1', 'state' => 2, 'expires_at' => $past, 'method' => 'HEAD', 'size' => 10, 'hits' => 0, 'created_at' => '2024-01-01 00:00:00', 'last_hit_at' => '2024-02-03 00:00:00' ),
			array( 'route' => '/wp/v2/pages/9', 'request' => '/wp/v2/pages/9?x=alpha', 'rule_key' => 'r2', 'state' => 1, 'expires_at' => $future, 'method' => 'GET', 'size' => 30, 'hits' => 9, 'created_at' => '2024-01-03 00:00:00', 'last_hit_at' => '2024-02-01 00:00:00' ),
		);
		$tags = array( array( 't1' ), array( 't1', 't2' ), array( 't2' ), array(), array( 't1' ), array( 't1' ) );

		$seeded = array();
		foreach ( $rows as $i => $row ) {
			$id            = Rfrac_Entry_Fixtures::insert_entry( $row, $tags[ $i ] );
			$seeded[ $id ] = $row + array( 'tags' => $tags[ $i ] );
		}
		return $seeded;
	}

	/**
	 * Ids of the seeded rows a listing query must return, worked out in PHP from the row values alone.
	 *
	 * @param array<int,array<string,mixed>> $seeded Rows from {@see self::seed_listing()}.
	 * @param array<string,string>           $args   Filters of the query.
	 *
	 * @return int[] Ids in ascending order.
	 */
	private function expected_ids( array $seeded, array $args ): array {
		$ids = array();
		foreach ( $seeded as $id => $row ) {
			$search = $args['search'] ?? '';
			$prefix = $args['route_prefix'] ?? '';
			$rule   = $args['rule_key'] ?? '';
			$tag    = $args['tag'] ?? '';
			$state  = $args['state'] ?? 'all';

			$expired = $row['expires_at'] <= gmdate( 'Y-m-d H:i:s' );
			$checks  = array(
				'' === $search || false !== stripos( $row['route'] . "\n" . $row['request'], $search ),
				'all' === $state
					|| ( 'active' === $state && 1 === $row['state'] && ! $expired )
					|| ( 'flushed' === $state && 2 === $row['state'] )
					|| ( 'expired' === $state && 1 === $row['state'] && $expired ),
				'' === $prefix || $row['route'] === $prefix || str_starts_with( $row['route'], $prefix . '/' ),
				'' === $rule || $row['rule_key'] === $rule,
				'' === $tag || in_array( $tag, $row['tags'], true ),
			);
			if ( ! in_array( false, $checks, true ) ) {
				$ids[] = $id;
			}
		}
		sort( $ids );
		return $ids;
	}

	public function test_listing_filters_alone_and_combined_match_a_reference_filter(): void {
		$seeded = $this->seed_listing();
		$none   = array(
			'search'       => '',
			'state'        => 'all',
			'route_prefix' => '',
			'rule_key'     => '',
			'tag'          => '',
		);
		$values = array(
			'search'       => array( 'alpha', 'pages' ),
			'state'        => array( 'active', 'flushed', 'expired' ),
			'route_prefix' => array( '/wp/v2/posts', '/wp/v2/pages' ),
			'rule_key'     => array( 'r1', 'r2' ),
			'tag'          => array( 't1', 't2' ),
		);

		// Every combination of "not set" and each value of each filter, so each filter alone and all of them together are covered.
		$combinations = array( $none );
		foreach ( $values as $filter => $options ) {
			$next = array();
			foreach ( $combinations as $combination ) {
				$next[] = $combination;
				foreach ( $options as $option ) {
					$next[] = array( $filter => $option ) + $combination;
				}
			}
			$combinations = $next;
		}
		$this->assertCount( 3 * 4 * 3 * 3 * 3, $combinations );

		$matching = 0;
		foreach ( $combinations as $args ) {
			$expected = $this->expected_ids( $seeded, $args );
			$matching += count( $expected );
			$label     = wp_json_encode( $args );

			foreach ( array( 'asc' => $expected, 'desc' => array_reverse( $expected ) ) as $order => $ids ) {
				$rows = $this->repo->query( Entry_Query::from_array( $args + array( 'orderby' => 'id', 'order' => $order ) ) );
				$this->assertSame( $ids, array_map( static fn( Entry_Row $row ) => $row->id, $rows ), "query() {$order} for {$label}" );
			}
			$this->assertSame( count( $expected ), $this->repo->count( Entry_Query::from_array( $args ) ), "count() for {$label}" );
		}
		$this->assertGreaterThan( 0, $matching, 'The reference filter matches rows, so the comparison is not vacuous.' );
	}

	public function test_listing_filters_are_literal_even_when_they_hold_sql_and_wildcards(): void {
		$seeded = $this->seed_listing();
		$args   = array(
			'search'       => "x' OR '1'='1",
			'route_prefix' => "/wp/v2/posts' OR '1'='1",
			'rule_key'     => "r1' OR '1'='1",
			'tag'          => "t1' OR '1'='1",
		);
		foreach ( $args as $filter => $value ) {
			$this->assertSame( 0, $this->repo->count( Entry_Query::from_array( array( $filter => $value ) ) ), "{$filter} is bound, not run." );
		}
		$this->assertSame( array(), $this->expected_ids( $seeded, $args ) );
		$this->assertSame( 0, $this->repo->count( Entry_Query::from_array( $args ) ) );
		$this->assertSame( array(), $this->repo->query( Entry_Query::from_array( $args ) ) );

		// LIKE wildcards stand for themselves: none of the seeded routes holds a "%" or "_", so none of these may match one.
		$wildcards = array(
			array( 'route_prefix' => '%' ),
			array( 'route_prefix' => '/wp/v2/%' ),
			array( 'route_prefix' => '/wp/v2/p_sts' ),
			array( 'rule_key' => '%' ),
			array( 'rule_key' => 'r_' ),
			array( 'tag' => '%' ),
			array( 'tag' => 't_' ),
		);
		foreach ( $wildcards as $filter ) {
			$label = wp_json_encode( $filter );
			$this->assertSame( array(), $this->expected_ids( $seeded, $filter ), "reference filter for {$label}" );
			$this->assertSame( 0, $this->repo->count( Entry_Query::from_array( $filter ) ), "count() for {$label}" );
			$this->assertSame( array(), $this->repo->query( Entry_Query::from_array( $filter ) ), "query() for {$label}" );
		}
		foreach ( array( '%', '/wp/v2/%', '/wp/v2/p%', '/wp/v2/p_sts' ) as $route ) {
			$this->assertSame( array(), $this->repo->keys_for_route( $route ), "keys_for_route() for {$route}" );
		}
		$this->assertCount( 2, $this->repo->keys_for_route( '/wp/v2/posts' ), 'The same lookup finds the active entries of a real route.' );

		$this->assertSame( 1, $this->repo->count( Entry_Query::from_array( array( 'route_prefix' => '/wp/v2/posts/3' ) ) ), 'A route prefix matches the route itself.' );
		$this->assertSame( 3, $this->repo->count( Entry_Query::from_array( array( 'route_prefix' => '/wp/v2/posts' ) ) ), 'A route prefix matches its subtree and not a sibling that starts with the same letters.' );
	}

	public function test_percent_and_underscore_in_a_route_or_filter_match_only_themselves(): void {
		// Each wildcard route has a child and a decoy that an unescaped "%" or "_" would also match.
		$routes = array(
			'percent'       => array( '/wp/v2/100%', '100%' ),
			'percent_child' => array( '/wp/v2/100%/1', 'x1' ),
			'percent_decoy' => array( '/wp/v2/10000/1', '10000' ),
			'under'         => array( '/wp/v2/p_sts', 'p_sts' ),
			'under_child'   => array( '/wp/v2/p_sts/1', 'x2' ),
			'under_decoy'   => array( '/wp/v2/posts/1', 'posts' ),
		);
		$ids    = array();
		$keys   = array();
		foreach ( $routes as $name => list( $route, $tag ) ) {
			$keys[ $name ] = hash( 'sha256', $route );
			$ids[ $name ]  = Rfrac_Entry_Fixtures::insert_entry(
				array(
					'route'     => $route,
					'rule_key'  => $route,
					'cache_key' => $keys[ $name ],
				),
				array( $tag )
			);
		}

		$listing = function ( array $args, array $names ) use ( $ids ): void {
			$expected = array_map( static fn( string $name ): int => $ids[ $name ], $names );
			$label    = wp_json_encode( $args );
			$rows     = $this->repo->query( Entry_Query::from_array( $args + array( 'orderby' => 'id', 'order' => 'asc' ) ) );
			$this->assertSame( $expected, array_map( static fn( Entry_Row $row ) => $row->id, $rows ), "query() for {$label}" );
			$this->assertSame( count( $expected ), $this->repo->count( Entry_Query::from_array( $args ) ), "count() for {$label}" );
		};
		$listing( array( 'route_prefix' => '/wp/v2/100%' ), array( 'percent', 'percent_child' ) );
		$listing( array( 'route_prefix' => '/wp/v2/p_sts' ), array( 'under', 'under_child' ) );
		$listing( array( 'route_prefix' => '%' ), array() );
		$listing( array( 'route_prefix' => '/wp/v2/p%' ), array() );
		$listing( array( 'rule_key' => '/wp/v2/100%' ), array( 'percent' ) );
		$listing( array( 'rule_key' => '/wp/v2/p_sts' ), array( 'under' ) );
		$listing( array( 'rule_key' => '%' ), array() );
		$listing( array( 'tag' => '100%' ), array( 'percent' ) );
		$listing( array( 'tag' => 'p_sts' ), array( 'under' ) );
		$listing( array( 'tag' => '%' ), array() );

		$lookup = function ( string $route, array $names ) use ( $keys ): void {
			$expected = array_map( static fn( string $name ): string => $keys[ $name ], $names );
			sort( $expected );
			$found = $this->repo->keys_for_route( $route );
			$this->assertIsArray( $found, "keys_for_route() for {$route}" );
			sort( $found );
			$this->assertSame( $expected, $found, "keys_for_route() for {$route}" );
		};
		$lookup( '/wp/v2/100%', array( 'percent', 'percent_child' ) );
		$lookup( '/wp/v2/p_sts', array( 'under', 'under_child' ) );
		$lookup( '%', array() );
		$lookup( '/wp/v2/p%', array() );
	}

	public function test_listing_orders_by_every_column_in_both_directions_with_the_id_as_tie_break(): void {
		$seeded = $this->seed_listing();

		foreach ( Entry_Query::ORDERBY as $orderby ) {
			foreach ( array( 'asc', 'desc' ) as $order ) {
				$rows     = $this->repo->query( Entry_Query::from_array( array( 'orderby' => $orderby, 'order' => $order ) ) );
				$expected = array_keys( $seeded );
				usort(
					$expected,
					static function ( int $a, int $b ) use ( $seeded, $orderby, $order ): int {
						$by_column = 'id' === $orderby ? 0 : strnatcasecmp( (string) $seeded[ $a ][ $orderby ], (string) $seeded[ $b ][ $orderby ] );
						$result    = 0 !== $by_column ? $by_column : $a <=> $b;
						return 'asc' === $order ? $result : -$result;
					}
				);
				$this->assertSame( $expected, array_map( static fn( Entry_Row $row ) => $row->id, $rows ), "orderby {$orderby} {$order}" );
			}
		}
	}

	public function test_query_paging(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			Rfrac_Entry_Fixtures::insert_entry();
		}
		$query = Entry_Query::from_array(
			array(
				'per_page' => 2,
				'page'     => 3,
				'orderby'  => 'id',
				'order'    => 'asc',
			)
		);
		$this->assertCount( 1, $this->repo->query( $query ) );
		$this->assertSame( 5, $this->repo->count( $query ), 'Count ignores paging.' );
	}

	public function test_query_invalid_orderby_falls_back_to_default(): void {
		Rfrac_Entry_Fixtures::insert_entry();
		$query = Entry_Query::from_array(
			array(
				'orderby' => 'id; DROP',
				'order'   => 'sideways; DROP',
			)
		);
		$this->assertSame( 'created_at', $query->orderby );
		$this->assertSame( 'desc', $query->order );
		$this->assertCount( 1, $this->repo->query( $query ) );
	}

	public function test_entry_query_clamps(): void {
		$query = Entry_Query::from_array(
			array(
				'search'   => '  ' . str_repeat( 'x', 300 ),
				'state'    => 'bogus',
				'per_page' => 9999,
				'page'     => -4,
				'order'    => 'ASC',
				'orderby'  => 'hits',
			)
		);
		$this->assertSame( 200, strlen( $query->search ) );
		$this->assertSame( 'all', $query->state );
		$this->assertSame( 500, $query->per_page );
		$this->assertSame( 1, $query->page );
		$this->assertSame( 'asc', $query->order );
		$this->assertSame( 'hits', $query->orderby );

		$defaults = Entry_Query::from_array( array( 'per_page' => 0 ) );
		$this->assertSame( 1, $defaults->per_page );
		$defaults = Entry_Query::from_array( array() );
		$this->assertSame( 20, $defaults->per_page );
		$this->assertSame( 0, $defaults->offset() );
	}

	/**
	 * Two entries on the same tag, URL hash and route prefix; the first one flushed at `$now`.
	 *
	 * @return int Id of the flushed entry.
	 */
	private function one_flushed_one_active( string $now ): int {
		$a = Rfrac_Entry_Fixtures::insert_entry( array( 'cache_key' => str_repeat( 'a', 64 ), 'url_hash' => str_repeat( 'h', 64 ), 'route' => '/x/y' ), array( 't1' ) );
		Rfrac_Entry_Fixtures::insert_entry( array( 'cache_key' => str_repeat( 'b', 64 ), 'url_hash' => str_repeat( 'h', 64 ), 'route' => '/x/z' ), array( 't1' ) );
		$this->assertSame( 1, $this->repo->flush_by_ids( array( $a ), $now ) );
		return $a;
	}

	public function test_key_lookups_by_flush_time_match_only_entries_flushed_then(): void {
		$now     = $this->now();
		$a       = $this->one_flushed_one_active( $now );
		$flushed = array( str_repeat( 'a', 64 ) );

		$this->assertSame( $flushed, $this->repo->keys_for_tags( array( 't1' ), $now ) );
		$this->assertSame( $flushed, $this->repo->keys_for_url_hash( str_repeat( 'h', 64 ), $now ) );
		$this->assertSame( $flushed, $this->repo->keys_for_route( '/x', $now ) );
		$this->assertSame( $flushed, $this->repo->keys_for_ids( array( $a ), $now ) );
		$this->assertSame( array( str_repeat( 'b', 64 ) ), $this->repo->keys_for_tags( array( 't1' ) ), 'Without a time: the active ones.' );
		$this->assertSame( $flushed, $this->repo->all_keys_for_ids( array( $a ) ) );
	}

	public function test_key_lookups_by_flush_time_exclude_entries_flushed_at_another_time(): void {
		$a = $this->one_flushed_one_active( $this->now() );

		$past = $this->past();
		$this->assertSame( array(), $this->repo->keys_for_tags( array( 't1' ), $past ) );
		$this->assertSame( array(), $this->repo->keys_for_url_hash( str_repeat( 'h', 64 ), $past ) );
		$this->assertSame( array(), $this->repo->keys_for_route( '/x', $past ) );
		$this->assertSame( array(), $this->repo->keys_for_ids( array( $a ), $past ) );
	}

	public function test_entry_state(): void {
		$a = $this->one_flushed_one_active( $this->now() );
		$this->assertSame( Repository::STATE_FLUSHED, $this->repo->entry_state( $a ) );
		$this->assertSame( Repository::STATE_ACTIVE, $this->repo->entry_state( $a + 1 ) );
		$this->assertSame( 0, $this->repo->entry_state( 999999 ) );
	}

	/**
	 * Run a callback while the queries matching a pattern fail.
	 */
	private function failing( string $pattern, callable $callback ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$fail     = static fn( $query ) => is_string( $query ) && 1 === preg_match( $pattern, $query ) ? 'RFRAC INVALID SQL' : $query;
		add_filter( 'query', $fail );
		try {
			return $callback();
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppress );
		}
	}

	public function test_failed_key_lookups_are_null_not_an_empty_list(): void {
		$a = $this->one_flushed_one_active( $this->now() );

		$this->failing(
			'/^\s*SELECT/i',
			function () use ( $a ): void {
				$this->assertNull( $this->repo->keys_for_tags( array( 't1' ) ) );
				$this->assertNull( $this->repo->keys_for_url_hash( str_repeat( 'h', 64 ) ) );
				$this->assertNull( $this->repo->keys_for_route( '/x' ) );
				$this->assertNull( $this->repo->keys_for_ids( array( $a ) ) );
				$this->assertNull( $this->repo->all_keys_for_ids( array( $a ) ) );
				$this->assertNull( $this->repo->entry_state( $a ) );
			}
		);
		$this->assertSame( array(), $this->repo->keys_for_tags( array( 'nope' ) ), 'An empty result is an empty list.' );
	}

	public function test_a_failure_in_a_later_chunk_fails_the_whole_lookup(): void {
		$tags   = array_map( static fn( int $i ): string => 't' . $i, range( 0, Repository::IN_CHUNK ) );
		$result = $this->failing( "/^\s*SELECT.*'t" . Repository::IN_CHUNK . "'/is", fn() => $this->repo->keys_for_tags( $tags ) );
		$this->assertNull( $result, 'The second chunk failed.' );
	}

	public function test_failed_flushes_are_null_not_zero(): void {
		$a = Rfrac_Entry_Fixtures::insert_entry( array( 'url_hash' => str_repeat( 'h', 64 ), 'route' => '/x/y' ), array( 't1' ) );
		$now = $this->now();

		$this->failing(
			'/^\s*UPDATE/i',
			function () use ( $a, $now ): void {
				$this->assertNull( $this->repo->flush_by_tags( array( 't1' ), $now ) );
				$this->assertNull( $this->repo->flush_by_ids( array( $a ), $now ) );
				$this->assertNull( $this->repo->flush_by_keys( array( 'k' ), $now ) );
				$this->assertNull( $this->repo->flush_by_url_hash( str_repeat( 'h', 64 ), $now ) );
				$this->assertNull( $this->repo->flush_route( '/x', $now ) );
				$this->assertNull( $this->repo->flush_all( $now ) );
			}
		);
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $a ) );
		$this->assertSame( 0, $this->repo->flush_by_tags( array( 'nope' ), $now ), 'Matching nothing is 0, not a failure.' );
	}

	public function test_a_failed_flush_chunk_fails_the_flush_but_the_other_chunks_still_run(): void {
		$a    = Rfrac_Entry_Fixtures::insert_entry( array(), array( 't0' ) );
		$tags = array_map( static fn( int $i ): string => 't' . $i, range( 0, Repository::IN_CHUNK ) );

		$result = $this->failing( "/^\s*UPDATE.*'t" . Repository::IN_CHUNK . "'/is", fn() => $this->repo->flush_by_tags( $tags, $this->now() ) );

		$this->assertNull( $result );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $a ), 'The first chunk flushed its entries.' );
	}

	public function test_huge_page_is_clamped_and_offset_stays_an_int(): void {
		foreach ( array( (string) PHP_INT_MAX, PHP_INT_MAX, '1e30', '99999999999999999999' ) as $raw ) {
			$query = Entry_Query::from_array(
				array(
					'page'     => $raw,
					'per_page' => 500,
				)
			);
			$this->assertLessThanOrEqual( Entry_Query::MAX_PAGE, $query->page );
			$this->assertGreaterThanOrEqual( 1, $query->page );
			$this->assertIsInt( $query->offset() );
		}

		$query = Entry_Query::from_array(
			array(
				'page'     => PHP_INT_MAX,
				'per_page' => 500,
			)
		);
		$this->assertSame( ( Entry_Query::MAX_PAGE - 1 ) * 500, $query->offset() );

		$direct = new Entry_Query( per_page: Entry_Query::MAX_PER_PAGE, page: PHP_INT_MAX );
		$this->assertSame( ( Entry_Query::MAX_PAGE - 1 ) * Entry_Query::MAX_PER_PAGE, $direct->offset(), 'A directly built query cannot overflow either.' );
		$this->assertSame( array(), $this->repo->query( $direct ), 'A page far past the end lists nothing instead of a fatal.' );
	}

	public function test_constructor_clamps_page_size_so_limit_and_offset_agree(): void {
		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = Rfrac_Entry_Fixtures::insert_entry( array( 'cache_key' => str_repeat( (string) $i, 64 ) ) );
		}

		$query = new Entry_Query( orderby: 'id', order: 'asc', per_page: 0, page: 2 );
		$this->assertSame( 1, $query->per_page );
		$this->assertSame( 1, $query->offset() );
		$rows = $this->repo->query( $query );
		$this->assertCount( 1, $rows, 'LIMIT uses the clamped page size.' );
		$this->assertSame( $ids[1], $rows[0]->id, 'And OFFSET the same one.' );

		$this->assertSame( Entry_Query::MAX_PER_PAGE, ( new Entry_Query( per_page: 100000 ) )->per_page );
		$this->assertSame( 1, ( new Entry_Query( page: -5 ) )->page );
	}

	public function test_get_tags_for_and_stats(): void {
		$a = Rfrac_Entry_Fixtures::insert_entry( array( 'size' => 100 ), array( 'b', 'a' ) );
		Rfrac_Entry_Fixtures::insert_entry( array( 'size' => 50, 'state' => 2 ) );
		Rfrac_Entry_Fixtures::insert_entry( array( 'size' => 25, 'expires_at' => $this->past() ), array( 'c' ) );

		$entry = $this->repo->get( $a );
		$this->assertInstanceOf( Entry_Row::class, $entry );
		$this->assertSame( 100, $entry->size );
		$this->assertSame( array( 'a', 'b' ), $this->repo->tags_for( $a ) );
		$this->assertNull( $this->repo->get( 999999 ) );

		$stats = $this->repo->stats();
		$this->assertSame( 1, $stats->active );
		$this->assertSame( 1, $stats->flushed );
		$this->assertSame( 1, $stats->expired );
		$this->assertSame( 3, $stats->total );
		$this->assertSame( 175, $stats->total_bytes );
		$this->assertSame( 3, $stats->tag_rows );
	}

	public function test_stats_on_empty_tables(): void {
		$stats = $this->repo->stats();
		$this->assertSame( 0, $stats->total );
		$this->assertSame( 0, $stats->total_bytes );
	}

	public function test_table_names_follow_the_prefix_at_call_time(): void {
		global $wpdb;
		$original      = $wpdb->prefix;
		$wpdb->prefix  = 'zz_';
		$entries_table = $this->repo->entries_table();
		$tags_table    = $this->repo->tags_table();
		$wpdb->prefix  = $original;

		$this->assertSame( 'zz_' . Names::TABLE_ENTRIES, $entries_table );
		$this->assertSame( 'zz_' . Names::TABLE_TAGS, $tags_table );
		$this->assertSame( $original . Names::TABLE_ENTRIES, $this->repo->entries_table() );
	}

	public function test_missing_table_is_a_miss_not_an_error(): void {
		global $wpdb;
		$original     = $wpdb->prefix;
		$wpdb->prefix = 'missing_';
		$errors       = $wpdb->show_errors( true );

		ob_start();
		$found = $this->repo->find_active( hash( 'sha256', 'k' ), $this->now() );
		$this->repo->record_hit( 1, 1, $this->now() );
		$this->repo->record_hits( array( 1 => array( 1, $this->now() ) ) );
		$output = ob_get_clean();

		$wpdb->show_errors( $errors );
		$wpdb->prefix = $original;

		$this->assertNull( $found );
		$this->assertSame( '', $output, 'No database error is printed.' );
	}
}
