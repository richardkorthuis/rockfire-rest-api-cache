<?php
/**
 * Tests for the lookup chain.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Cache_Policy;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Hit_Filters_Interface;
use Rockfire\Rest_Api_Cache\Serving\Lookup;
use Rockfire\Rest_Api_Cache\Serving\Request_State;
use Rockfire\Rest_Api_Cache\Storage\Cached_Response;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Interface;

/**
 * @covers \Rockfire\Rest_Api_Cache\Serving\Lookup
 * @covers \Rockfire\Rest_Api_Cache\Serving\Request_State
 */
class Test_Lookup extends Rfrac_Serving_Test_Case {

	/**
	 * A lookup with the capturing writer and an optional store.
	 *
	 * @param Store_Interface|null       $store   Store returned by the store factory (default: the real one).
	 * @param Hit_Filters_Interface|null $filters Hit filter set (default: none).
	 *
	 * @return Lookup
	 */
	private function lookup( ?Store_Interface $store = null, ?Hit_Filters_Interface $filters = null ): Lookup {
		return new Lookup(
			$this->policy(),
			new Repository(),
			$this->capturing_writer(),
			null,
			null === $store ? null : static fn() => $store,
			$filters
		);
	}

	private function config_object(): Compiled_Config {
		$config = Compiled_Config::load();
		$this->assertNotNull( $config );
		return $config;
	}

	private function context(): Request_Context {
		$config = $this->config_object();
		return Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() );
	}

	public function test_hit_is_served_and_ends_the_request(): void {
		$this->simulate( '/wp-json/wp/v2/posts?page=2' );
		$this->prime( '{"hit":1}' );

		try {
			$this->lookup()->run( $this->context(), $this->config_object() );
			$this->fail( 'The request was not terminated.' );
		} catch ( Rfrac_Test_Exit $e ) {
			$this->assertSame( '{"hit":1}', $this->output );
			$this->assertContains( 'X-Rfrac-Cache: HIT', $this->headers );
		}
		$this->assertFalse( Request_State::lookup_done(), 'Nothing runs after a hit.' );
	}

	public function test_hit_is_counted_before_it_is_sent(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$response = new Cached_Response( 7, 'k', '{}', array(), 1, 2 );
		$calls    = array();
		$store    = $this->createMock( Store_Interface::class );
		$store->method( 'get' )->willReturn( $response );
		$store->expects( $this->once() )->method( 'record_hit' )->willReturnCallback(
			function () use ( &$calls ) {
				$calls[] = 'count:' . $this->output;
			}
		);

		try {
			$this->lookup( $store )->run( $this->context(), $this->config_object() );
			$this->fail( 'The request was not terminated.' );
		} catch ( Rfrac_Test_Exit $e ) {
			$this->assertSame( array( 'count:' ), $calls );
			$this->assertSame( '{}', $this->output );
		}
	}

	public function test_failing_hit_counter_still_serves_the_hit(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$store = $this->createMock( Store_Interface::class );
		$store->method( 'get' )->willReturn( new Cached_Response( 7, 'k', '{"x":1}', array(), 1, 2 ) );
		$store->method( 'record_hit' )->willThrowException( new RuntimeException( 'db down' ) );

		try {
			$this->lookup( $store )->run( $this->context(), $this->config_object() );
			$this->fail( 'The request was not terminated.' );
		} catch ( Rfrac_Test_Exit $e ) {
			$this->assertSame( '{"x":1}', $this->output );
		}
		$last = \Rockfire\Rest_Api_Cache\Failure_Recorder::last();
		$this->assertNotNull( $last, 'The failing hit counter must be recorded.' );
		$this->assertSame( 'hit', $last['context'] );
		$this->assertStringContainsString( 'db down', $last['message'] );
	}

	public function test_miss_records_the_decision_and_key(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$context = $this->context();

		$this->lookup()->run( $context, $this->config_object() );

		$this->assertTrue( Request_State::lookup_done() );
		$this->assertSame( $context, Request_State::context() );
		$this->assertTrue( Request_State::decision()->cacheable );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) Request_State::cache_key() );
		$this->assertSame( '', $this->output );
	}

	public function test_uncacheable_request_records_a_bypass_without_key_or_store(): void {
		$this->simulate( '/wp-json/wp/v2/posts', array( 'REQUEST_METHOD' => 'POST' ) );
		$store = $this->createMock( Store_Interface::class );
		$store->expects( $this->never() )->method( 'get' );

		$this->lookup( $store )->run( $this->context(), $this->config_object() );

		$this->assertTrue( Request_State::lookup_done() );
		$this->assertFalse( Request_State::decision()->cacheable );
		$this->assertSame( Bypass_Reason::METHOD, Request_State::decision()->reason );
		$this->assertNull( Request_State::cache_key() );
	}

	public function test_authorization_never_hits_even_with_a_primed_entry(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$this->prime();
		$this->simulate( '/wp-json/wp/v2/posts', array( 'HTTP_AUTHORIZATION' => 'Bearer x' ) );

		$this->lookup()->run( $this->context(), $this->config_object() );

		$this->assertSame( '', $this->output );
		$this->assertSame( Bypass_Reason::CREDENTIALS, Request_State::decision()->reason );
	}

	public function test_coexistence_guard_blocks_a_hit(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$this->prime();
		$policy = new Cache_Policy( static fn(): bool => true );
		$lookup = new Lookup( $policy, new Repository(), $this->capturing_writer() );

		$lookup->run( $this->context(), $this->config_object() );

		$this->assertSame( '', $this->output );
		$this->assertSame( Bypass_Reason::COEXISTENCE, Request_State::decision()->reason );
	}

	public function test_exception_in_the_chain_is_recorded_as_bypass_and_swallowed(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$store = $this->createMock( Store_Interface::class );
		$store->method( 'get' )->willThrowException( new RuntimeException( 'boom' ) );

		$this->lookup( $store )->run( $this->context(), $this->config_object() );

		$this->assertTrue( Request_State::lookup_done() );
		$this->assertFalse( Request_State::decision()->cacheable );
		$this->assertNull( Request_State::cache_key() );
		$this->assertSame( Bypass_Reason::ERROR, Request_State::decision()->reason );
		$this->assertSame( '', $this->output );
	}

	public function test_error_in_key_function_is_swallowed(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$lookup = new Lookup(
			$this->policy(),
			new Repository(),
			$this->capturing_writer(),
			static function (): string {
				throw new Error( 'key' );
			}
		);

		$lookup->run( $this->context(), $this->config_object() );

		$this->assertFalse( Request_State::decision()->cacheable );
	}

	public function test_termination_of_the_writer_is_not_swallowed_by_the_catch_all(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$store = $this->createMock( Store_Interface::class );
		$store->method( 'get' )->willReturn( new Cached_Response( 7, 'k', '{}', array(), 1, 2 ) );

		$this->expectException( Rfrac_Test_Exit::class );
		$this->lookup( $store )->run( $this->context(), $this->config_object() );
	}

	public function test_the_hit_filter_set_runs_in_order_and_the_hit_is_counted_between_response_and_cors(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$fake  = new Rfrac_Fake_Hit_Filters();
		$store = $this->createMock( Store_Interface::class );
		$store->method( 'get' )->willReturn( new Cached_Response( 7, 'k', '{"x":1}', array(), 1, 2 ) );
		$store->expects( $this->once() )->method( 'record_hit' )->willReturnCallback(
			static function () use ( $fake ): void {
				$fake->calls[] = 'count';
			}
		);

		try {
			$this->lookup( $store, $fake )->run( $this->context(), $this->config_object() );
			$this->fail( 'The request was not terminated.' );
		} catch ( Rfrac_Test_Exit $e ) {
			$this->assertSame( array( 'decide', 'key', 'response', 'count', 'cors' ), $fake->calls );
			$this->assertSame( '{"x":1}', $this->output );
		}
	}

	public function test_a_response_that_is_not_served_is_not_counted_and_records_a_filtered_bypass(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$fake              = new Rfrac_Fake_Hit_Filters();
		$fake->on_response = static fn(): ?Cached_Response => null;
		$store             = $this->createMock( Store_Interface::class );
		$store->method( 'get' )->willReturn( new Cached_Response( 7, 'k', '{"x":1}', array(), 1, 2 ) );
		$store->expects( $this->never() )->method( 'record_hit' );

		$this->lookup( $store, $fake )->run( $this->context(), $this->config_object() );

		$this->assertSame( '', $this->output );
		$this->assertSame( Bypass_Reason::FILTERED, Request_State::decision()->reason );
		$this->assertFalse( Request_State::decision()->cacheable );
		$this->assertSame( array( 'decide', 'key', 'response' ), $fake->calls );
	}

	public function test_a_miss_does_not_ask_the_hit_filter_set_for_a_response_or_cors(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$fake = new Rfrac_Fake_Hit_Filters();

		$this->lookup( null, $fake )->run( $this->context(), $this->config_object() );

		$this->assertSame( array( 'decide', 'key' ), $fake->calls );
		$this->assertTrue( Request_State::decision()->cacheable );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) Request_State::cache_key() );
	}

	public function test_a_replaced_key_is_the_key_of_the_lookup_and_of_the_recorded_state(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$fake         = new Rfrac_Fake_Hit_Filters();
		$fake->on_key = static fn(): string => 'other';
		$store        = $this->createMock( Store_Interface::class );
		$store->expects( $this->once() )->method( 'get' )->with( 'other' )->willReturn( null );

		$this->lookup( $store, $fake )->run( $this->context(), $this->config_object() );

		$this->assertSame( 'other', Request_State::cache_key() );
	}

	public function test_a_decision_the_policy_refused_is_never_shown_to_the_hit_filter_set(): void {
		$this->simulate( '/wp-json/wp/v2/posts', array( 'REQUEST_METHOD' => 'POST' ) );
		$fake = new Rfrac_Fake_Hit_Filters();

		$this->lookup( null, $fake )->run( $this->context(), $this->config_object() );

		$this->assertSame( array(), $fake->calls );
		$this->assertSame( Bypass_Reason::METHOD, Request_State::decision()->reason );
	}

	public function test_a_throwing_hit_filter_set_is_recorded_as_a_bypass_and_never_serves(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$this->prime();
		$fake              = new Rfrac_Fake_Hit_Filters();
		$fake->on_response = static function (): ?Cached_Response {
			throw new RuntimeException( 'filters' );
		};

		$this->lookup( null, $fake )->run( $this->context(), $this->config_object() );

		$this->assertSame( '', $this->output );
		$this->assertSame( Bypass_Reason::ERROR, Request_State::decision()->reason );
		$this->assertSame( 'lookup', \Rockfire\Rest_Api_Cache\Failure_Recorder::last()['context'] ?? null );
	}

	/**
	 * Flush time for the epoch tests.
	 */
	private function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public function test_a_cacheable_miss_records_the_flush_epoch_it_saw(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$repo = new Repository();
		$repo->flush_by_tags( array( 'post:1' ), $this->now() );
		$repo->flush_by_tags( array( 'post:1' ), $this->now() );
		$epoch = $repo->flush_epoch();
		$this->assertNotNull( $epoch );
		$this->assertGreaterThanOrEqual( 2, $epoch );

		$this->lookup()->run( $this->context(), $this->config_object() );

		$this->assertTrue( Request_State::decision()->cacheable );
		$this->assertSame( $epoch, Request_State::flush_epoch() );
	}

	public function test_a_miss_on_a_site_that_never_flushed_records_epoch_zero(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'rfrac_flush_epoch' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->simulate( '/wp-json/wp/v2/posts' );

		$this->lookup()->run( $this->context(), $this->config_object() );

		$this->assertSame( 0, Request_State::flush_epoch() );
	}

	public function test_a_hit_reads_no_flush_epoch(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$this->prime( '{"hit":1}' );
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();

		try {
			$this->lookup()->run( $this->context(), $this->config_object() );
			$this->fail( 'The request was not terminated.' );
		} catch ( Rfrac_Test_Exit $e ) {
			$recorder->stop();
		}

		$this->assertNotEmpty( $recorder->matching( '/rfrac_entries/' ), 'The hit read the entry.' );
		$this->assertSame( array(), $recorder->matching( '/rfrac_flush_epoch/' ) );
		$this->assertNull( Request_State::flush_epoch() );
	}

	public function test_a_request_with_credentials_reads_no_flush_epoch(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$this->prime();
		$this->simulate( '/wp-json/wp/v2/posts', array( 'HTTP_AUTHORIZATION' => 'Bearer x' ) );
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();

		$this->lookup()->run( $this->context(), $this->config_object() );
		$recorder->stop();

		$this->assertSame( Bypass_Reason::CREDENTIALS, Request_State::decision()->reason );
		$this->assertNull( Request_State::flush_epoch() );
		$this->assertSame( array(), $recorder->matching( '/rfrac_flush_epoch/' ) );
	}

	public function test_a_request_the_policy_refused_reads_no_flush_epoch(): void {
		$this->simulate( '/wp-json/wp/v2/posts', array( 'REQUEST_METHOD' => 'POST' ) );
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();

		$this->lookup()->run( $this->context(), $this->config_object() );
		$recorder->stop();

		$this->assertFalse( Request_State::decision()->cacheable );
		$this->assertNull( Request_State::flush_epoch() );
		$this->assertSame( array(), $recorder->matching( '/rfrac_flush_epoch/' ) );
	}

	public function test_a_lookup_that_throws_records_no_flush_epoch(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$store = $this->createMock( Store_Interface::class );
		$store->method( 'get' )->willThrowException( new RuntimeException( 'boom' ) );
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();

		$this->lookup( $store )->run( $this->context(), $this->config_object() );
		$recorder->stop();

		$this->assertSame( Bypass_Reason::ERROR, Request_State::decision()->reason );
		$this->assertNull( Request_State::flush_epoch() );
		$this->assertSame( array(), $recorder->matching( '/rfrac_flush_epoch/' ) );
	}

	public function test_a_cacheable_miss_whose_epoch_cannot_be_read_is_a_recorded_lookup_error(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$context = $this->context();
		$config  = $this->config_object();
		$lookup  = $this->lookup();

		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', array( $this, 'break_epoch_read' ) );
		try {
			$lookup->run( $context, $config );
		} finally {
			remove_filter( 'query', array( $this, 'break_epoch_read' ) );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertTrue( Request_State::lookup_done() );
		$this->assertSame( Bypass_Reason::ERROR, Request_State::decision()->reason, 'Capture has no baseline to compare with.' );
		$this->assertNull( Request_State::flush_epoch() );
		$this->assertNull( Request_State::cache_key() );
		$this->assertSame( 'lookup', \Rockfire\Rest_Api_Cache\Failure_Recorder::last()['context'] ?? null );
		$this->assertSame( '', $this->output );
	}

	/**
	 * The `query` filter that breaks the reads of the flush epoch.
	 *
	 * @param mixed $query SQL.
	 *
	 * @return mixed
	 */
	public function break_epoch_read( $query ) {
		return is_string( $query ) && 1 === preg_match( '/^\s*SELECT\b.*rfrac_flush_epoch/s', $query ) ? 'RFRAC INVALID SQL' : $query;
	}

	public function test_a_miss_the_hit_filter_set_turned_into_a_bypass_reads_no_flush_epoch(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$fake         = new Rfrac_Fake_Hit_Filters();
		$fake->on_key = static fn(): ?string => null;
		$recorder     = new Rfrac_Query_Recorder();
		$recorder->start();

		$this->lookup( null, $fake )->run( $this->context(), $this->config_object() );
		$recorder->stop();

		$this->assertSame( Bypass_Reason::FILTERED, Request_State::decision()->reason );
		$this->assertNull( Request_State::cache_key() );
		$this->assertNull( Request_State::flush_epoch() );
		$this->assertSame( array(), $recorder->matching( '/rfrac_flush_epoch/' ) );
	}

	public function test_a_hit_the_hit_filter_set_refused_reads_no_flush_epoch(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$this->prime();
		$fake              = new Rfrac_Fake_Hit_Filters();
		$fake->on_response = static fn(): ?Cached_Response => null;
		$recorder          = new Rfrac_Query_Recorder();
		$recorder->start();

		$this->lookup( null, $fake )->run( $this->context(), $this->config_object() );
		$recorder->stop();

		$this->assertSame( Bypass_Reason::FILTERED, Request_State::decision()->reason );
		$this->assertNull( Request_State::flush_epoch() );
		$this->assertSame( array(), $recorder->matching( '/rfrac_flush_epoch/' ) );
	}

	public function test_a_single_site_does_not_look_at_current_blog(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site test.' );
		}
		require_once ABSPATH . WPINC . '/class-wp-site.php'; // Core loads it on multisite only.
		$had  = array_key_exists( 'current_blog', $GLOBALS );
		$prev = $GLOBALS['current_blog'] ?? null;
		$this->simulate( '/wp-json/wp/v2/posts' );
		$this->prime( '{"hit":1}' );
		$GLOBALS['current_blog'] = new WP_Site( (object) array( 'blog_id' => '1', 'archived' => '1', 'spam' => '1', 'deleted' => '1' ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

		try {
			$this->lookup()->run( $this->context(), $this->config_object() );
			$this->fail( 'The request was not terminated.' );
		} catch ( Rfrac_Test_Exit $e ) {
			$this->assertSame( '{"hit":1}', $this->output );
		} finally {
			if ( $had ) {
				$GLOBALS['current_blog'] = $prev;
			} else {
				unset( $GLOBALS['current_blog'] );
			}
		}
	}

	/**
	 * Data provider: the snapshot a lookup runs on, and whether it counts as outdated.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool}>
	 */
	public static function snapshots_by_age(): array {
		return array(
			'stale (empty fingerprint)'          => array( array( 'fingerprint' => '' ), true ),
			'pending discovery'                  => array( array( 'fingerprint' => 'current', 'pending_discovery' => true ), true ),
			'pending and stale'                  => array( array( 'fingerprint' => '', 'pending_discovery' => true ), true ),
			'current (fingerprint, not pending)' => array( array( 'fingerprint' => 'current' ), false ),
		);
	}

	/**
	 * @dataProvider snapshots_by_age
	 *
	 * @param array<string,mixed> $overrides Snapshot fields.
	 * @param bool                $outdated  Whether the snapshot is pending or stale.
	 */
	public function test_a_miss_records_whether_it_ran_on_a_pending_or_stale_snapshot( array $overrides, bool $outdated ): void {
		$this->set_compiled_config( array_merge( $this->snapshot(), $overrides ) );
		$this->simulate( '/wp-json/wp/v2/posts' );

		$this->lookup()->run( $this->context(), $this->config_object() );

		$this->assertTrue( Request_State::lookup_done() );
		$this->assertSame( $outdated, Request_State::outdated_snapshot() );
		$this->assertFalse( Request_State::outdated_snapshot_rebuilt(), 'Only the recompile of the request says so.' );
	}

	public function test_a_request_the_policy_refused_on_a_stale_snapshot_still_records_the_observation(): void {
		$this->simulate( '/wp-json/wp/v2/posts', array( 'REQUEST_METHOD' => 'POST' ) );

		$this->lookup()->run( $this->context(), $this->config_object() );

		$this->assertSame( Bypass_Reason::METHOD, Request_State::decision()->reason );
		$this->assertTrue( Request_State::outdated_snapshot() );
	}

	public function test_a_hit_on_a_stale_snapshot_records_no_observation(): void {
		$this->assertSame( '', $this->config_object()->fingerprint(), 'Precondition: the snapshot is stale.' );
		$this->simulate( '/wp-json/wp/v2/posts' );
		$this->prime( '{"hit":1}' );

		try {
			$this->lookup()->run( $this->context(), $this->config_object() );
			$this->fail( 'The request was not terminated.' );
		} catch ( Rfrac_Test_Exit $e ) {
			$this->assertSame( '{"hit":1}', $this->output );
		}

		$this->assertFalse( Request_State::outdated_snapshot() );
		$this->assertFalse( Request_State::outdated_snapshot_rebuilt() );
	}

	public function test_a_hit_on_a_pending_snapshot_records_no_observation(): void {
		$this->set_compiled_config( array_merge( $this->snapshot(), array( 'fingerprint' => 'current', 'pending_discovery' => true ) ) );
		$this->simulate( '/wp-json/wp/v2/posts' );
		$this->prime( '{"hit":1}' );

		try {
			$this->lookup()->run( $this->context(), $this->config_object() );
			$this->fail( 'The request was not terminated.' );
		} catch ( Rfrac_Test_Exit $e ) {
			$this->assertSame( '{"hit":1}', $this->output );
		}

		$this->assertFalse( Request_State::outdated_snapshot() );
	}

	public function test_a_lookup_that_threw_on_a_stale_snapshot_records_no_observation(): void {
		$this->simulate( '/wp-json/wp/v2/posts' );
		$store = $this->createMock( Store_Interface::class );
		$store->method( 'get' )->willThrowException( new RuntimeException( 'boom' ) );

		$this->lookup( $store )->run( $this->context(), $this->config_object() );

		$this->assertSame( Bypass_Reason::ERROR, Request_State::decision()->reason );
		$this->assertFalse( Request_State::outdated_snapshot() );
	}

	public function test_the_recompile_counts_as_a_rebuild_only_after_an_outdated_observation(): void {
		Request_State::record_snapshot_rebuilt();
		$this->assertFalse( Request_State::outdated_snapshot_rebuilt(), 'A rebuild of a snapshot the lookup did not see outdated exempts nothing.' );

		Request_State::record_outdated_snapshot();
		$this->assertFalse( Request_State::outdated_snapshot_rebuilt(), 'The observation alone is no rebuild.' );

		Request_State::record_snapshot_rebuilt();
		$this->assertTrue( Request_State::outdated_snapshot() );
		$this->assertTrue( Request_State::outdated_snapshot_rebuilt() );

		Request_State::reset();
		$this->assertFalse( Request_State::outdated_snapshot() );
		$this->assertFalse( Request_State::outdated_snapshot_rebuilt() );
	}

	public function test_request_state_reset_and_boot_record(): void {
		Request_State::record_boot( 3, false );
		$this->assertFalse( Request_State::early_booted() );
		$this->assertSame( 3, Request_State::mu_template_version() );
		Request_State::record_boot( 1, true );
		$this->assertTrue( Request_State::early_booted() );

		$this->simulate( '/wp-json/wp/v2/posts' );
		$this->lookup()->run( $this->context(), $this->config_object() );
		Request_State::reset();

		$this->assertFalse( Request_State::early_booted() );
		$this->assertNull( Request_State::mu_template_version() );
		$this->assertFalse( Request_State::lookup_done() );
		$this->assertNull( Request_State::context() );
		$this->assertNull( Request_State::decision() );
		$this->assertNull( Request_State::cache_key() );
	}
}
