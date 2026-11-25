<?php
/**
 * Tests for the flush epoch: advanced by every committed flush, read uncached, handed off through Request_State.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Request_State;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Storage\Repository
 * @covers \Rockfire\Rest_Api_Cache\Serving\Request_State
 * @covers \Rockfire\Rest_Api_Cache\Names
 */
class Test_Flush_Epoch extends Rfrac_Test_Case {

	/**
	 * Repository under test.
	 *
	 * @var Repository
	 */
	private Repository $repo;

	/**
	 * Whether the test switched the external object cache flag.
	 *
	 * @var bool|null
	 */
	private ?bool $ext_object_cache = null;

	public function set_up() {
		parent::set_up();
		$this->repo = new Repository();
		$this->delete_epoch_rows();
	}

	public function tear_down() {
		if ( null !== $this->ext_object_cache ) {
			wp_using_ext_object_cache( $this->ext_object_cache );
			$this->ext_object_cache = null;
		}
		wp_cache_delete( Names::OPTION_FLUSH_EPOCH, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		parent::tear_down();
	}

	private function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Remove both epoch rows of the current site, so a test starts from "never written".
	 */
	private function delete_epoch_rows(): void {
		global $wpdb;
		foreach ( array( Names::OPTION_FLUSH_EPOCH, Names::OPTION_FLUSH_ALL_EPOCH ) as $option ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Run a callback while the queries matching a pattern fail.
	 *
	 * @param string   $pattern  PCRE pattern of the queries to break.
	 * @param callable $callback Callback; its return value is returned.
	 *
	 * @return mixed
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

	/**
	 * The epoch of the current site, as an int (a failed read fails the test).
	 */
	private function epoch(): int {
		$epoch = $this->repo->flush_epoch();
		$this->assertNotNull( $epoch );
		return (int) $epoch;
	}

	/**
	 * The full-flush epoch of the current site, as an int (a failed read fails the test).
	 */
	private function all_epoch(): int {
		$epoch = $this->repo->flush_all_epoch();
		$this->assertNotNull( $epoch );
		return (int) $epoch;
	}

	public function test_the_option_names_are_prefixed_and_distinct(): void {
		$this->assertSame( 'rfrac_flush_epoch', Names::OPTION_FLUSH_EPOCH );
		$this->assertSame( 'rfrac_flush_all_epoch', Names::OPTION_FLUSH_ALL_EPOCH );
	}

	public function test_every_flush_method_advances_the_epoch_by_exactly_one(): void {
		$calls = array(
			'flush_by_tags'     => fn() => $this->repo->flush_by_tags( array( 'post:9' ), $this->now() ),
			'flush_by_ids'      => fn() => $this->repo->flush_by_ids( array( 999 ), $this->now() ),
			'flush_by_keys'     => fn() => $this->repo->flush_by_keys( array( 'x' ), $this->now() ),
			'flush_by_url_hash' => fn() => $this->repo->flush_by_url_hash( 'h', $this->now() ),
			'flush_route'       => fn() => $this->repo->flush_route( '/x', $this->now() ),
			'flush_all'         => fn() => $this->repo->flush_all( $this->now() ),
		);

		$expected = 0;
		$this->assertSame( 0, $this->epoch(), 'An empty cache, a never written counter.' );
		foreach ( $calls as $name => $call ) {
			$this->assertSame( 0, $call(), $name . ' matches nothing.' );
			++$expected;
			$this->assertSame( $expected, $this->epoch(), $name );
		}
	}

	public function test_a_flush_that_matches_entries_advances_the_epoch_once_too(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) );
		}

		$this->assertSame( 5, $this->repo->flush_by_tags( array( 'post:1' ), $this->now() ) );
		$this->assertSame( 1, $this->epoch() );
	}

	public function test_a_chunked_flush_advances_the_epoch_once(): void {
		$tags = array();
		for ( $i = 0; $i <= Repository::IN_CHUNK; $i++ ) {
			$tags[] = 'tag-' . $i;
		}

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$this->assertSame( 0, $this->repo->flush_by_tags( $tags, $this->now() ) );
		$recorder->stop();

		$this->assertCount( 2, $recorder->matching( '/^\s*UPDATE\b/i' ), 'Two chunked statements.' );
		$this->assertSame( 1, $this->epoch() );
	}

	public function test_an_empty_target_list_runs_nothing_and_leaves_the_epoch(): void {
		$this->repo->flush_by_tags( array( 'post:1' ), $this->now() );
		$before = $this->epoch();

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$this->assertSame( 0, $this->repo->flush_by_tags( array(), $this->now() ) );
		$this->assertSame( 0, $this->repo->flush_by_tags( array( '' ), $this->now() ) );
		$this->assertSame( 0, $this->repo->flush_by_ids( array(), $this->now() ) );
		$this->assertSame( 0, $this->repo->flush_by_ids( array( 0, -3 ), $this->now() ) );
		$this->assertSame( 0, $this->repo->flush_by_keys( array(), $this->now() ) );
		$recorder->stop();

		$this->assertSame( array(), $recorder->matching( '/./' ), 'No query ran.' );
		$this->assertSame( $before, $this->epoch() );
	}

	public function test_the_epoch_is_written_before_the_first_entries_update(): void {
		Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) );
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$this->repo->flush_by_tags( array( 'post:1' ), $this->now() );
		$recorder->stop();

		$all    = $recorder->matching( '/./' );
		$writes = array_keys( preg_grep( '/^\s*INSERT\b.*rfrac_flush_epoch/is', $all ) );
		$update = array_keys( preg_grep( '/^\s*UPDATE\b.*rfrac_entries/is', $all ) );
		$this->assertCount( 1, $writes );
		$this->assertCount( 1, $update );
		$this->assertLessThan( $update[0], $writes[0] );
	}

	public function test_every_flush_kind_writes_the_epoch_first(): void {
		$calls = array(
			fn() => $this->repo->flush_by_ids( array( 1 ), $this->now() ),
			fn() => $this->repo->flush_by_keys( array( 'x' ), $this->now() ),
			fn() => $this->repo->flush_by_url_hash( 'h', $this->now() ),
			fn() => $this->repo->flush_route( '/x', $this->now() ),
			fn() => $this->repo->flush_all( $this->now() ),
		);
		foreach ( $calls as $index => $call ) {
			$recorder = new Rfrac_Query_Recorder();
			$recorder->start();
			$call();
			$recorder->stop();

			$all = $recorder->matching( '/./' );
			$this->assertMatchesRegularExpression( '/^\s*INSERT\b.*rfrac_flush_epoch/is', $all[0], 'Call ' . $index );
			$this->assertMatchesRegularExpression( '/^\s*UPDATE\b.*rfrac_entries/is', $all[1], 'Call ' . $index );
		}
	}

	public function test_a_failed_entries_update_still_advances_the_epoch_but_not_the_full_flush_epoch(): void {
		Rfrac_Entry_Fixtures::insert_entry();
		$epoch = $this->epoch();
		$all   = $this->all_epoch();

		$result = $this->failing( '/^\s*UPDATE\b.*rfrac_entries/is', fn() => $this->repo->flush_all( $this->now() ) );

		$this->assertNull( $result );
		$this->assertSame( $epoch + 1, $this->epoch(), 'The flush epoch moved before the statement failed.' );
		$this->assertSame( $all, $this->all_epoch(), 'The full-flush epoch only moves for a flush that succeeded.' );
	}

	public function test_a_successful_flush_all_advances_the_full_flush_epoch_and_no_other_flush_does(): void {
		Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) );
		$this->assertSame( 0, $this->all_epoch() );

		$this->repo->flush_by_tags( array( 'post:1' ), $this->now() );
		$this->repo->flush_by_ids( array( 1 ), $this->now() );
		$this->repo->flush_by_keys( array( 'x' ), $this->now() );
		$this->repo->flush_by_url_hash( 'h', $this->now() );
		$this->repo->flush_route( '/x', $this->now() );
		$this->assertSame( 0, $this->all_epoch(), 'Targeted flushes never move the full-flush epoch.' );

		$epoch = $this->epoch();
		$this->assertIsInt( $this->repo->flush_all( $this->now() ) );
		$this->assertSame( 1, $this->all_epoch() );
		$this->assertSame( $epoch + 1, $this->epoch() );

		$this->assertIsInt( $this->repo->flush_all( $this->now() ) );
		$this->assertSame( 2, $this->all_epoch() );

		$this->repo->flush_by_tags( array( 'post:1' ), $this->now() );
		$this->assertSame( 2, $this->all_epoch() );
	}

	public function test_a_failed_full_flush_epoch_write_fails_the_flush_but_not_the_entries(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry();

		$result = $this->failing( '/^\s*INSERT\b.*rfrac_flush_all_epoch/is', fn() => $this->repo->flush_all( $this->now() ) );

		$this->assertNull( $result );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
		$this->assertSame( 1, $this->epoch() );
		$this->assertSame( 0, $this->all_epoch() );
	}

	public function test_a_failed_epoch_write_fails_the_flush_but_still_runs_the_statements(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) );

		$result = $this->failing( '/^\s*INSERT\b.*rfrac_flush_epoch/is', fn() => $this->repo->flush_by_tags( array( 'post:1' ), $this->now() ) );

		$this->assertNull( $result );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ), 'The entry was flushed anyway.' );
		$this->assertSame( 0, $this->epoch(), 'The epoch did not move.' );
	}

	public function test_a_failed_epoch_write_fails_every_flush_kind(): void {
		$calls = array(
			fn() => $this->repo->flush_by_ids( array( 1 ), $this->now() ),
			fn() => $this->repo->flush_by_keys( array( 'x' ), $this->now() ),
			fn() => $this->repo->flush_by_url_hash( 'h', $this->now() ),
			fn() => $this->repo->flush_route( '/x', $this->now() ),
			fn() => $this->repo->flush_all( $this->now() ),
		);
		foreach ( $calls as $index => $call ) {
			$this->assertNull( $this->failing( '/^\s*INSERT\b.*rfrac_flush_epoch/is', $call ), 'Call ' . $index );
		}
	}

	public function test_the_epoch_is_read_from_the_database_not_from_any_cache_or_filter(): void {
		global $wpdb;
		$this->repo->flush_by_tags( array( 'post:1' ), $this->now() );
		$this->repo->flush_by_tags( array( 'post:1' ), $this->now() );
		$this->repo->flush_all( $this->now() );
		$this->assertSame( 3, $this->epoch() );
		$this->assertSame( 1, $this->all_epoch() );

		$this->ext_object_cache = wp_using_ext_object_cache( true );
		wp_cache_set( Names::OPTION_FLUSH_EPOCH, '999', 'options' );
		wp_cache_set( Names::OPTION_FLUSH_ALL_EPOCH, '998', 'options' );
		$alloptions                                  = wp_load_alloptions();
		$alloptions[ Names::OPTION_FLUSH_EPOCH ]     = '777';
		$alloptions[ Names::OPTION_FLUSH_ALL_EPOCH ] = '776';
		wp_cache_set( 'alloptions', $alloptions, 'options' );
		add_filter( 'pre_option_' . Names::OPTION_FLUSH_EPOCH, static fn() => 12345 );
		add_filter( 'option_' . Names::OPTION_FLUSH_EPOCH, static fn() => 54321 );
		add_filter( 'pre_option_' . Names::OPTION_FLUSH_ALL_EPOCH, static fn() => 12346 );

		$this->assertSame( 12345, (int) get_option( Names::OPTION_FLUSH_EPOCH ), 'Sanity: WordPress would hand out the filtered value.' );
		$this->assertSame( 3, $this->epoch() );
		$this->assertSame( 1, $this->all_epoch() );

		$this->repo->flush_by_tags( array( 'post:1' ), $this->now() );
		$this->assertSame( 4, $this->epoch(), 'The write is not served from the polluted caches either.' );
		$this->assertSame(
			'4',
			$wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, Names::OPTION_FLUSH_EPOCH ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);
	}

	public function test_a_never_written_epoch_reads_zero(): void {
		$this->assertSame( 0, $this->repo->flush_epoch() );
		$this->assertSame( 0, $this->repo->flush_all_epoch() );
	}

	public function test_a_failed_select_reads_null_not_zero(): void {
		$this->repo->flush_all( $this->now() );

		$this->failing(
			'/^\s*SELECT\b.*rfrac_flush_/is',
			function (): void {
				$this->assertNull( $this->repo->flush_epoch() );
				$this->assertNull( $this->repo->flush_all_epoch() );
			}
		);
		$this->assertSame( 1, $this->epoch(), 'The counter itself is intact.' );
	}

	public function test_the_epoch_row_is_not_autoloaded(): void {
		global $wpdb;
		$this->repo->flush_all( $this->now() );
		$this->repo->flush_by_tags( array( 'post:1' ), $this->now() );

		foreach ( array( Names::OPTION_FLUSH_EPOCH, Names::OPTION_FLUSH_ALL_EPOCH ) as $option ) {
			$autoload = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->assertSame( 'off', $autoload, $option );
		}
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayNotHasKey( Names::OPTION_FLUSH_EPOCH, wp_load_alloptions( true ) );
		$this->assertArrayNotHasKey( Names::OPTION_FLUSH_ALL_EPOCH, wp_load_alloptions( true ) );
	}

	public function test_the_epoch_counts_up_from_a_stored_value_in_one_statement(): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->options,
			array(
				'option_name'  => Names::OPTION_FLUSH_EPOCH,
				'option_value' => '41',
				'autoload'     => 'off',
			)
		);

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$this->repo->flush_by_tags( array( 'post:1' ), $this->now() );
		$recorder->stop();

		$this->assertSame( 42, $this->epoch() );
		$this->assertCount( 1, $recorder->matching( '/rfrac_flush_epoch/' ), 'One statement, no read-modify-write.' );
		$this->assertCount( 0, $recorder->matching( '/^\s*SELECT\b/i' ) );
	}

	public function test_the_store_change_event_advances_both_epochs(): void {
		$plugin = new Plugin();
		$plugin->register_engine_hooks();
		$epoch = $this->epoch();
		$all   = $this->all_epoch();

		do_action( Hooks::CONFIG_STORE_CHANGED, 'database', 'object_cache' );

		$this->assertGreaterThan( $epoch, $this->epoch() );
		$this->assertGreaterThan( $all, $this->all_epoch() );
	}

	public function test_a_committed_tag_batch_advances_the_epoch(): void {
		$plugin = new Plugin();
		$plugin->register_engine_hooks();
		$id = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) );
		$plugin->invalidator()->queue( array( 'post:1' ), 'custom' );
		$epoch = $this->epoch();
		$all   = $this->all_epoch();

		$this->assertSame( 1, $plugin->invalidator()->commit() );

		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
		$this->assertSame( $epoch + 1, $this->epoch() );
		$this->assertSame( $all, $this->all_epoch(), 'A tag flush is not a full flush.' );
	}

	public function test_the_public_flush_all_function_advances_both_epochs(): void {
		$epoch = $this->epoch();
		$all   = $this->all_epoch();

		rfrac_flush_all();

		$this->assertSame( $epoch + 1, $this->epoch() );
		$this->assertSame( $all + 1, $this->all_epoch() );
	}

	public function test_request_state_hands_the_epoch_off_and_forgets_it(): void {
		$context  = Request_Context::from_globals( 'wp-json' );
		$decision = Policy_Decision::bypass( Bypass_Reason::ERROR );

		$this->assertNull( Request_State::flush_epoch() );

		Request_State::record_lookup( $context, $decision, 'key', 7 );
		$this->assertSame( 7, Request_State::flush_epoch() );
		$this->assertSame( 'key', Request_State::cache_key() );

		Request_State::record_lookup( $context, $decision, 'key' );
		$this->assertNull( Request_State::flush_epoch(), 'A three-argument call records no epoch.' );

		Request_State::record_lookup( $context, $decision, null, 0 );
		$this->assertSame( 0, Request_State::flush_epoch(), 'Epoch 0 is a value, not "unknown".' );

		Request_State::reset();
		$this->assertNull( Request_State::flush_epoch() );
	}
}
