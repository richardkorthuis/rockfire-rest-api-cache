<?php
/**
 * Tests for the buffered invalidator.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Storage\Database_Store;
use Rockfire\Rest_Api_Cache\Storage\Object_Cache_Store;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\Invalidator
 */
class Test_Invalidator extends Rfrac_Test_Case {

	/**
	 * Spy store.
	 *
	 * @var Rfrac_Spy_Store
	 */
	private Rfrac_Spy_Store $spy;

	/**
	 * Original external object cache flag.
	 *
	 * @var bool
	 */
	private bool $ext_cache;

	public function set_up() {
		parent::set_up();
		$this->spy       = new Rfrac_Spy_Store();
		$this->ext_cache = (bool) wp_using_ext_object_cache();
	}

	public function tear_down() {
		$this->restore_error_log();
		wp_using_ext_object_cache( $this->ext_cache );
		wp_cache_flush_group( 'rfrac' );
		parent::tear_down();
	}

	private function invalidator( ?Closure $hasher = null ): Invalidator {
		return new Invalidator( $this->spy, $hasher ?? static fn( string $url ): ?string => 'hash-of-' . $url );
	}

	/**
	 * Count `rfrac/flushed` actions as `[ type, targets, affected ]`.
	 *
	 * @return ArrayObject<int,array{0:string,1:array<int,string>,2:int}>
	 */
	private function capture_flushed(): ArrayObject {
		$log = new ArrayObject();
		add_action(
			Hooks::FLUSHED,
			static function ( $type, $targets, $affected ) use ( $log ) {
				$log[] = array( $type, $targets, $affected );
			},
			10,
			3
		);
		return $log;
	}

	public function test_queued_tags_are_deduplicated_and_flushed_in_one_call(): void {
		$inv = $this->invalidator();
		$inv->queue( array( 'post:1', 'list:post' ), 'e1' );
		$inv->queue( array( 'post:1', 'term:3' ), 'e2' );

		$this->assertSame( array( get_current_blog_id() => array( 'list:post', 'post:1', 'term:3' ) ), $inv->pending() );
		$this->assertSame( 1, $inv->commit() );
		$this->assertSame( array( array( 'list:post', 'post:1', 'term:3' ) ), $this->spy->calls_of( 'flush_by_tags' ) );
		$this->assertSame( array(), $inv->pending() );
	}

	public function test_queue_normalises_and_drops_invalid_tags(): void {
		$inv = $this->invalidator();
		$inv->queue( array( ' POST:2 ', 'bogus', 'post:0' ), 'e' );
		$this->assertSame( array( 'post:2' ), $inv->pending()[ get_current_blog_id() ] );
	}

	public function test_commit_with_empty_buffer_does_nothing(): void {
		$log      = $this->capture_flushed();
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$this->assertSame( 0, $this->invalidator()->commit() );
		$recorder->stop();
		$this->assertSame( array(), $recorder->matching( '/./' ) );
		$this->assertSame( array(), $this->spy->calls );
		$this->assertCount( 0, $log );
	}

	public function test_queue_runs_no_queries(): void {
		$inv      = $this->invalidator();
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$inv->queue( array( 'post:1', 'list:post' ), 'e' );
		$recorder->stop();
		$this->assertSame( array(), $recorder->matching( '/./' ) );
	}

	public function test_commit_chunks_at_1000_tags(): void {
		add_filter( Hooks::AUTO_COMMIT_THRESHOLD, static fn() => 10000 );
		$log  = $this->capture_flushed();
		$inv  = $this->invalidator();
		$tags = array();
		for ( $i = 1; $i <= 1500; $i++ ) {
			$tags[] = 'post:' . $i;
		}
		$inv->queue( $tags, 'bulk' );
		$this->assertSame( array(), $this->spy->calls );

		$this->assertSame( 2, $inv->commit() );
		$calls = $this->spy->calls_of( 'flush_by_tags' );
		$this->assertCount( 2, $calls );
		$this->assertCount( 1000, $calls[0] );
		$this->assertCount( 500, $calls[1] );
		$this->assertCount( 2, $log );
		$this->assertSame( 'tags', $log[0][0] );
		$this->assertSame( $calls[1], $log[1][1] );
		$this->assertSame( 1, $log[1][2] );
	}

	public function test_default_threshold_commits_at_the_500th_tag(): void {
		$inv = $this->invalidator();
		for ( $i = 1; $i <= 499; $i++ ) {
			$inv->queue( array( 'post:' . $i ), 'e' );
		}
		$this->assertSame( array(), $this->spy->calls );
		$this->assertCount( 499, $inv->pending()[ get_current_blog_id() ] );

		$inv->queue( array( 'post:500' ), 'e' );
		$this->assertCount( 1, $this->spy->calls_of( 'flush_by_tags' ) );
		$this->assertCount( 500, $this->spy->calls_of( 'flush_by_tags' )[0] );
		$this->assertSame( array(), $inv->pending() );
	}

	public function test_duplicates_do_not_count_towards_the_threshold(): void {
		add_filter( Hooks::AUTO_COMMIT_THRESHOLD, static fn() => 2 );
		$inv = $this->invalidator();
		$inv->queue( array( 'post:1' ), 'e' );
		$inv->queue( array( 'post:1' ), 'e' );
		$this->assertSame( array(), $this->spy->calls );
		$inv->queue( array( 'post:2' ), 'e' );
		$this->assertCount( 1, $this->spy->calls );
	}

	public function test_invalidation_tags_filter_can_empty_the_queue(): void {
		add_filter( Hooks::INVALIDATION_TAGS, static fn() => array() );
		$inv = $this->invalidator();
		$inv->queue( array( 'post:1' ), 'e' );
		$this->assertSame( array(), $inv->pending() );
		$this->assertSame( 0, $inv->commit() );
	}

	public function test_invalidation_tags_filter_receives_event_and_context_and_output_is_normalised(): void {
		$seen = array();
		add_filter(
			Hooks::INVALIDATION_TAGS,
			static function ( $tags, $event, $context ) use ( &$seen ) {
				$seen   = array( $tags, $event, $context );
				$tags[] = 'USER:5';
				$tags[] = 'junk';
				return $tags;
			},
			10,
			3
		);
		$inv = $this->invalidator();
		$inv->queue( array( 'post:1' ), 'my-event', array( 'id' => 1 ) );
		$this->assertSame( array( array( 'post:1' ), 'my-event', array( 'id' => 1 ) ), $seen );
		$this->assertSame( array( 'post:1', 'user:5' ), $inv->pending()[ get_current_blog_id() ] );
	}

	public function test_flush_all_drops_pending_and_flushes_once(): void {
		$log = $this->capture_flushed();
		$inv = $this->invalidator();
		$inv->queue( array( 'post:1' ), 'e' );
		$this->assertSame( 1, $inv->flush_all() );
		$this->assertSame( array(), $inv->pending() );
		$this->assertSame( array( array( 'flush_all', null, get_current_blog_id() ) ), $this->spy->calls );
		$this->assertSame( array( array( 'all', array(), 1 ) ), $log->getArrayCopy() );
		$this->assertSame( 0, $inv->commit() );
	}

	public function test_flush_tags_is_immediate_and_fires_the_action(): void {
		$log = $this->capture_flushed();
		$inv = $this->invalidator();
		$this->assertSame( 1, $inv->flush_tags( array( 'post:9', 'post:9', 'x' ) ) );
		$this->assertSame( array( array( 'post:9' ) ), $this->spy->calls_of( 'flush_by_tags' ) );
		$this->assertSame( array( array( 'tags', array( 'post:9' ), 1 ) ), $log->getArrayCopy() );
		$this->assertSame( 0, $inv->flush_tags( array( 'invalid' ) ) );
		$this->assertCount( 1, $this->spy->calls );
	}

	public function test_flush_url_hashes_the_url(): void {
		$log = $this->capture_flushed();
		$this->assertSame( 1, $this->invalidator()->flush_url( '/wp-json/wp/v2/posts' ) );
		$this->assertSame( array( 'hash-of-/wp-json/wp/v2/posts' ), $this->spy->calls_of( 'flush_by_url_hash' ) );
		$this->assertSame( array( array( 'url', array( '/wp-json/wp/v2/posts' ), 1 ) ), $log->getArrayCopy() );
	}

	public function test_flush_url_without_hash_does_nothing(): void {
		$log = $this->capture_flushed();
		$this->assertSame( 0, $this->invalidator( static fn( string $url ): ?string => null )->flush_url( '/nope' ) );
		$this->assertSame( array(), $this->spy->calls );
		$this->assertCount( 0, $log );
	}

	public function test_flush_route_is_immediate(): void {
		$log = $this->capture_flushed();
		$this->assertSame( 1, $this->invalidator()->flush_route( '/wp/v2/posts' ) );
		$this->assertSame( array( '/wp/v2/posts' ), $this->spy->calls_of( 'flush_route' ) );
		$this->assertSame( array( array( 'route', array( '/wp/v2/posts' ), 1 ) ), $log->getArrayCopy() );
	}

	public function test_commit_fires_flushed_with_the_store_result(): void {
		$this->spy->return = 7;
		$log               = $this->capture_flushed();
		$inv               = $this->invalidator();
		$inv->queue( array( 'post:1' ), 'e' );
		$this->assertSame( 7, $inv->commit() );
		$this->assertSame( array( array( 'tags', array( 'post:1' ), 7 ) ), $log->getArrayCopy() );
	}

	public function test_listener_that_queues_does_not_loop(): void {
		add_filter( Hooks::AUTO_COMMIT_THRESHOLD, static fn() => 1 );
		$inv   = $this->invalidator();
		$count = 0;
		add_action(
			Hooks::FLUSHED,
			static function () use ( $inv, &$count ) {
				++$count;
				$inv->queue( array( 'post:99' ), 'from-listener' );
			}
		);
		$inv->queue( array( 'post:1' ), 'e' );
		$this->assertSame( 1, $count );
		$this->assertCount( 1, $this->spy->calls );
		$this->assertSame( array( 'post:99' ), $inv->pending()[ get_current_blog_id() ] );
	}

	public function test_listener_that_queues_during_explicit_commit_waits_for_next_commit(): void {
		$inv = $this->invalidator();
		add_action(
			Hooks::FLUSHED,
			static function () use ( $inv ) {
				$inv->queue( array( 'post:99' ), 'from-listener' );
			}
		);
		$inv->queue( array( 'post:1' ), 'e' );
		$inv->commit();
		$this->assertSame( array( array( 'post:1' ) ), $this->spy->calls_of( 'flush_by_tags' ) );
		$this->assertSame( array( 'post:99' ), $inv->pending()[ get_current_blog_id() ] );
	}

	public function test_register_hooks_commit_at_shutdown_and_before_rest_echo(): void {
		$inv = $this->invalidator();
		$inv->register();
		$this->assertSame( 0, has_action( 'shutdown', array( $inv, 'commit_action' ) ) );
		$this->assertSame( PHP_INT_MAX, has_filter( 'rest_pre_echo_response', array( $inv, 'commit_filter' ) ) );

		$inv->queue( array( 'post:1' ), 'e' );
		$response = array( 'a' => 1 );
		$this->assertSame( $response, apply_filters( 'rest_pre_echo_response', $response ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		$this->assertCount( 1, $this->spy->calls );
		$this->assertSame( array(), $inv->pending() );
	}

	public function test_real_database_store_flushes_only_matching_entries_with_one_update(): void {
		$e1 = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) );
		$e2 = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:2' ) );

		$inv = new Invalidator( new Database_Store( new Repository(), 0 ), static fn( string $url ): ?string => null );
		$inv->queue( array( 'post:1' ), 'e' );

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$this->assertSame( 1, $inv->commit() );
		$recorder->stop();

		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $e1 ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $e2 ) );
		$this->assertCount( 1, $recorder->matching( '/^\s*UPDATE.*rfrac_entries/is' ) );
	}

	public function test_real_object_cache_store_uses_one_update_and_at_most_one_select(): void {
		wp_using_ext_object_cache( true );
		wp_cache_flush_group( 'rfrac' );
		$e1 = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) );
		$e2 = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:2' ) );

		$inv = new Invalidator( new Object_Cache_Store( new Repository(), 0 ), static fn( string $url ): ?string => null );
		$inv->queue( array( 'post:1' ), 'e' );

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$this->assertSame( 1, $inv->commit() );
		$recorder->stop();

		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $e1 ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $e2 ) );
		$this->assertCount( 1, $recorder->matching( '/^\s*UPDATE.*rfrac_entries/is' ) );
		$this->assertLessThanOrEqual( 1, count( $recorder->matching( '/^\s*SELECT/i' ) ) );
	}

	public function test_shutdown_drains_tags_queued_by_a_flushed_listener(): void {
		$inv    = $this->invalidator();
		$queued = false;
		add_action(
			Hooks::FLUSHED,
			function () use ( $inv, &$queued ) {
				if ( ! $queued ) {
					$queued = true;
					$inv->queue( array( 'post:99' ), 'listener' );
				}
			}
		);
		$inv->queue( array( 'post:1' ), 'e' );
		$inv->commit_action();

		$this->assertSame( array( array( 'post:1' ), array( 'post:99' ) ), $this->spy->calls_of( 'flush_by_tags' ) );
		$this->assertSame( array(), $inv->pending() );
	}

	public function test_shutdown_drain_is_bounded_when_a_listener_always_requeues(): void {
		$this->capture_failures();
		$inv = $this->invalidator();
		$n   = 0;
		add_action(
			Hooks::FLUSHED,
			function () use ( $inv, &$n ) {
				++$n;
				$inv->queue( array( 'post:' . ( 100 + $n ) ), 'loop' );
			}
		);
		$inv->queue( array( 'post:1' ), 'e' );
		$inv->commit_action();

		$this->assertCount( 3, $this->spy->calls_of( 'flush_by_tags' ) );
		$this->assertSame( 3, $n );
	}

	/**
	 * Record `rfrac/invalidation_failed` as `[ error, context ]` and send the PHP error log to a temp file.
	 *
	 * @return ArrayObject<int,array{0:Throwable,1:array<string,mixed>}>
	 */
	private function capture_failures(): ArrayObject {
		$log = new ArrayObject();
		add_action(
			Hooks::INVALIDATION_FAILED,
			static function ( $error, $context ) use ( $log ) {
				$log[] = array( $error, $context );
			},
			10,
			2
		);
		$this->error_log_file = (string) wp_tempnam( 'rfrac-error-log' );
		$this->old_error_log  = (string) ini_set( 'error_log', $this->error_log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		return $log;
	}

	/**
	 * Temp file the PHP error log is written to while failures are captured.
	 *
	 * @var string|null
	 */
	private ?string $error_log_file = null;

	/**
	 * Error log setting to restore.
	 *
	 * @var string
	 */
	private string $old_error_log = '';

	/**
	 * Contents of the captured error log.
	 *
	 * @return string
	 */
	private function error_log_contents(): string {
		return null === $this->error_log_file ? '' : (string) file_get_contents( $this->error_log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Restore the error log after a capture.
	 */
	private function restore_error_log(): void {
		if ( null !== $this->error_log_file ) {
			ini_set( 'error_log', $this->old_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			@unlink( $this->error_log_file ); // phpcs:ignore
			$this->error_log_file = null;
		}
	}

	/**
	 * 1,500 sequential post tags.
	 *
	 * @return string[]
	 */
	private function many_tags(): array {
		$tags = array();
		for ( $i = 1; $i <= 1500; $i++ ) {
			$tags[] = 'post:' . $i;
		}
		return $tags;
	}

	public function test_store_failure_mid_commit_requeues_the_rest_once_then_drops_it(): void {
		add_filter( Hooks::AUTO_COMMIT_THRESHOLD, static fn() => 100000 );
		$failures = $this->capture_failures();
		$calls    = 0;
		$this->spy->before_flush = static function () use ( &$calls ) {
			++$calls;
			if ( $calls >= 2 ) {
				throw new RuntimeException( 'store down' );
			}
		};
		$inv = $this->invalidator();
		$inv->queue( $this->many_tags(), 'bulk' );

		$this->assertSame( 1, $inv->commit() );
		$this->assertCount( 1, $this->spy->calls_of( 'flush_by_tags' ) );
		$this->assertCount( 500, $inv->pending()[ get_current_blog_id() ] );
		$this->assertCount( 1, $failures );
		$this->assertSame( 'store down', $failures[0][0]->getMessage() );
		$this->assertCount( 500, $failures[0][1]['requeued'] );
		$this->assertSame( array(), $failures[0][1]['dropped'] );

		// Second failure of the same tags: dropped, not re-queued again.
		$this->assertSame( 0, $inv->commit() );
		$this->assertSame( array(), $inv->pending() );
		$this->assertCount( 2, $failures );
		$this->assertCount( 500, $failures[1][1]['dropped'] );

		$log = $this->error_log_contents();
		$this->restore_error_log();
		$this->assertStringContainsString( 'RuntimeException: store down in ' . __FILE__ . ':', $log );
	}

	public function test_store_failure_on_one_site_does_not_stop_the_other_sites(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Uses an unknown blog ID without switching.' );
		}
		$failures = $this->capture_failures();
		$this->spy->before_flush = static function ( $method, $tags ) {
			if ( in_array( 'post:1', (array) $tags, true ) ) {
				throw new RuntimeException( 'first site fails' );
			}
		};
		$inv = $this->invalidator();
		$inv->queue( array( 'post:1' ), 'e', array(), 1 );
		$inv->queue( array( 'post:2' ), 'e', array(), 2 );
		$this->assertSame( 1, $inv->commit() );
		$this->restore_error_log();

		$this->assertSame( array( array( 'post:2' ) ), $this->spy->calls_of( 'flush_by_tags' ) );
		$this->assertSame( array( 1 => array( 'post:1' ) ), $inv->pending() );
		$this->assertCount( 1, $failures );
		$this->assertSame( 1, $failures[0][1]['blog_id'] );
	}

	public function test_a_retried_tag_that_succeeds_gets_a_fresh_retry_next_time(): void {
		$failures = $this->capture_failures();
		$fail     = true;
		$this->spy->before_flush = static function () use ( &$fail ) {
			if ( $fail ) {
				throw new RuntimeException( 'flaky' );
			}
		};
		$inv = $this->invalidator();
		$inv->queue( array( 'post:1' ), 'e' );
		$inv->commit();
		$fail = false;
		$inv->commit();
		$this->assertSame( array( array( 'post:1' ) ), $this->spy->calls_of( 'flush_by_tags' ) );

		$fail = true;
		$inv->queue( array( 'post:1' ), 'e' );
		$inv->commit();
		$this->restore_error_log();
		$this->assertSame( array( 'post:1' ), $inv->pending()[ get_current_blog_id() ] );
		$this->assertSame( array( 'post:1' ), $failures[1][1]['requeued'] );
	}

	public function test_commit_filter_returns_the_response_when_the_store_throws(): void {
		$failures                = $this->capture_failures();
		$this->spy->before_flush = static function () {
			throw new RuntimeException( 'boom' );
		};
		$inv = $this->invalidator();
		$inv->queue( array( 'post:1' ), 'e' );
		$response = array( 'ok' => true );
		$this->assertSame( $response, $inv->commit_filter( $response ) );
		$this->restore_error_log();
		$this->assertCount( 1, $failures );
	}

	public function test_auto_commit_failure_never_escapes_queue(): void {
		add_filter( Hooks::AUTO_COMMIT_THRESHOLD, static fn() => 1 );
		$failures                = $this->capture_failures();
		$this->spy->before_flush = static function () {
			throw new RuntimeException( 'boom' );
		};
		$inv = $this->invalidator();
		$inv->queue( array( 'post:1' ), 'e' );
		$this->restore_error_log();
		$this->assertCount( 1, $failures );
		$this->assertSame( array( 'post:1' ), $inv->pending()[ get_current_blog_id() ] );
	}

	public function test_flushed_listener_exception_does_not_stop_the_flush(): void {
		add_filter( Hooks::AUTO_COMMIT_THRESHOLD, static fn() => 100000 );
		$failures = $this->capture_failures();
		add_action(
			Hooks::FLUSHED,
			static function () {
				throw new LogicException( 'listener broke' );
			}
		);
		$inv = $this->invalidator();
		$inv->queue( $this->many_tags(), 'bulk' );
		$this->assertSame( 2, $inv->commit() );
		$this->restore_error_log();

		$this->assertCount( 2, $this->spy->calls_of( 'flush_by_tags' ) );
		$this->assertSame( array(), $inv->pending() );
		$this->assertCount( 2, $failures );
		$this->assertSame( Hooks::FLUSHED, $failures[0][1]['listener'] );
	}

	public function test_flush_all_keeps_pending_tags_when_the_store_throws(): void {
		$this->spy->before_flush = static function () {
			throw new RuntimeException( 'boom' );
		};
		$inv = $this->invalidator();
		$inv->queue( array( 'post:1' ), 'e' );
		try {
			$inv->flush_all();
			$this->fail( 'Expected the store exception.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertSame( array( 'post:1' ), $inv->pending()[ get_current_blog_id() ] );
	}

	public function test_tags_queued_after_the_shutdown_commit_are_committed_immediately(): void {
		$inv = $this->invalidator();
		$inv->queue( array( 'post:1' ), 'e' );
		$inv->commit_action();
		$this->assertSame( array( array( 'post:1' ) ), $this->spy->calls_of( 'flush_by_tags' ) );

		// A later shutdown callback (e.g. a deferred product sync) saves content.
		$inv->queue( array( 'post:2' ), 'late' );
		$this->assertSame( array( array( 'post:1' ), array( 'post:2' ) ), $this->spy->calls_of( 'flush_by_tags' ) );
		$this->assertSame( array(), $inv->pending() );
	}

	public function test_queue_before_shutdown_does_not_commit_below_the_threshold(): void {
		$inv = $this->invalidator();
		$inv->queue( array( 'post:2' ), 'e' );
		$this->assertSame( array(), $this->spy->calls );
	}

	public function test_tags_left_after_the_shutdown_drain_are_reported(): void {
		$failures = $this->capture_failures();
		$inv      = $this->invalidator();
		$n        = 0;
		add_action(
			Hooks::FLUSHED,
			function () use ( $inv, &$n ) {
				++$n;
				$inv->queue( array( 'post:' . ( 100 + $n ) ), 'loop' );
			}
		);
		$inv->queue( array( 'post:1' ), 'e' );
		$inv->commit_action();
		$this->restore_error_log();

		$this->assertCount( 1, $failures );
		$this->assertSame( array( get_current_blog_id() => array( 'post:103' ) ), $failures[0][1]['pending'] );
	}

	public function test_swallow_logs_class_location_hook_and_event_with_wp_debug(): void {
		$failures = $this->capture_failures();
		$inv      = $this->invalidator();
		$events   = new Rockfire\Rest_Api_Cache\Invalidation\User_Events( $inv );
		add_filter(
			Hooks::INVALIDATION_TAGS,
			static function () {
				throw new DomainException( 'filter failed' );
			}
		);
		add_action( 'rfrac_test_hook', array( $events, 'on_profile_update' ) );
		do_action( 'rfrac_test_hook', 1 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		$log = $this->error_log_contents();
		$this->restore_error_log();

		$this->assertCount( 1, $failures );
		// The exception left the filter without core popping it, so the innermost hook is reported.
		$this->assertSame( Hooks::INVALIDATION_TAGS, $failures[0][1]['hook'] );
		$this->assertSame( Invalidator::EVENT_USER_UPDATED, $failures[0][1]['event'] );
		$this->assertStringContainsString( 'hook: rfrac/invalidation_tags, event: user_updated', $log );
		$this->assertStringContainsString( 'DomainException: filter failed in ' . __FILE__ . ':', $log );
	}

	public function test_a_throwing_failure_listener_is_ignored(): void {
		$failures = $this->capture_failures();
		add_action(
			Hooks::INVALIDATION_FAILED,
			static function () {
				throw new RuntimeException( 'observer broke' );
			},
			5
		);
		$this->spy->before_flush = static function () {
			throw new RuntimeException( 'boom' );
		};
		$inv = $this->invalidator();
		$inv->queue( array( 'post:1' ), 'e' );
		$this->assertSame( 0, $inv->commit() );
		$this->restore_error_log();
		$this->assertCount( 0, $failures ); // The listener at priority 5 threw before ours ran; nothing escaped.
	}

	public function test_deferred_change_is_resolved_and_flushed_at_commit(): void {
		$inv   = $this->invalidator();
		$calls = 0;
		$inv->defer(
			'k',
			static function () use ( &$calls ): array {
				++$calls;
				return array( array( 'post:1', 'list:post' ), 'deferred', array( 'post_id' => 1 ) );
			}
		);
		$this->assertSame( array(), $this->spy->calls, 'Deferring runs nothing.' );
		$this->assertSame( 0, $calls );

		$inv->commit();
		$this->assertSame( 1, $calls );
		$this->assertSame( array( array( 'list:post', 'post:1' ) ), $this->spy->calls_of( 'flush_by_tags' ) );

		$inv->commit();
		$this->assertSame( 1, $calls, 'A resolver runs once.' );
	}

	public function test_deferring_again_under_the_same_key_replaces_the_resolver(): void {
		$inv = $this->invalidator();
		$inv->defer( 'k', static fn(): array => array( array( 'post:1' ), 'first' ) );
		$inv->defer( 'k', static fn(): array => array( array( 'post:2' ), 'second' ) );
		$inv->defer( 'other', static fn(): array => array( array( 'post:3' ), 'other' ) );
		$this->assertSame( array( get_current_blog_id() => array( 'post:2', 'post:3' ) ), $inv->pending() );
	}

	public function test_a_resolver_returning_null_queues_nothing(): void {
		$inv = $this->invalidator();
		$inv->defer( 'k', static fn(): ?array => null );
		$this->assertSame( 0, $inv->commit() );
		$this->assertSame( array(), $this->spy->calls );
	}

	public function test_deferred_tags_pass_the_invalidation_filter_with_their_event_and_context(): void {
		$seen = array();
		add_filter(
			Hooks::INVALIDATION_TAGS,
			static function ( $tags, $event, $context ) use ( &$seen ) {
				$seen[] = array( $tags, $event, $context );
				return $tags;
			},
			10,
			3
		);
		$inv = $this->invalidator();
		$inv->defer( 'k', static fn(): array => array( array( 'post:1' ), 'deferred', array( 'post_id' => 1 ) ) );
		$inv->commit();
		$this->assertSame( array( array( array( 'post:1' ), 'deferred', array( 'post_id' => 1 ) ) ), $seen );
	}

	public function test_a_throwing_resolver_is_reported_and_the_others_still_flush(): void {
		$failures = $this->capture_failures();
		$inv      = $this->invalidator();
		$inv->defer(
			'bad',
			static function (): array {
				throw new RuntimeException( 'resolver failed' );
			}
		);
		$inv->defer( 'good', static fn(): array => array( array( 'post:1' ), 'e' ) );
		$inv->commit();
		$this->restore_error_log();

		$this->assertCount( 1, $failures );
		$this->assertSame( array( array( 'post:1' ) ), $this->spy->calls_of( 'flush_by_tags' ) );
	}

	public function test_a_change_deferred_after_the_shutdown_commit_is_committed_immediately(): void {
		$inv = $this->invalidator();
		$inv->commit_action();
		$inv->defer( 'k', static fn(): array => array( array( 'post:2' ), 'late' ) );
		$this->assertSame( array( array( 'post:2' ) ), $this->spy->calls_of( 'flush_by_tags' ) );
	}

	public function test_the_shutdown_commit_resolves_deferred_changes(): void {
		$inv = $this->invalidator();
		$inv->defer( 'k', static fn(): array => array( array( 'post:4' ), 'e' ) );
		$inv->commit_action();
		$this->assertSame( array( array( 'post:4' ) ), $this->spy->calls_of( 'flush_by_tags' ) );
	}

	public function test_flush_all_drops_the_deferred_changes_of_the_site(): void {
		$inv = $this->invalidator();
		$inv->defer( 'k', static fn(): array => array( array( 'post:1' ), 'e' ) );
		$inv->flush_all();
		$this->assertSame( array(), $inv->pending() );
	}

	public function test_deferred_changes_count_toward_the_auto_commit_threshold(): void {
		add_filter( Hooks::AUTO_COMMIT_THRESHOLD, static fn() => 3 );
		$inv = $this->invalidator();
		$inv->queue( array( 'post:1' ), 'e' );
		$inv->defer( 'a', static fn(): array => array( array( 'post:2' ), 'e' ) );
		$this->assertSame( array(), $this->spy->calls, 'Below the threshold.' );
		$inv->defer( 'b', static fn(): array => array( array( 'post:3' ), 'e' ) );
		$this->assertSame( array( array( 'post:1', 'post:2', 'post:3' ) ), $this->spy->calls_of( 'flush_by_tags' ) );
	}

	public function test_a_change_deferred_while_committing_after_shutdown_is_drained(): void {
		$inv      = $this->invalidator();
		$deferred = false;
		add_action(
			Hooks::FLUSHED,
			static function () use ( $inv, &$deferred ) {
				if ( ! $deferred ) {
					$deferred = true;
					$inv->defer( 'late', static fn(): array => array( array( 'post:9' ), 'e' ) );
				}
			}
		);
		$inv->commit_action();
		$inv->queue( array( 'post:1' ), 'e' );
		$this->assertSame( array( array( 'post:1' ), array( 'post:9' ) ), $this->spy->calls_of( 'flush_by_tags' ) );
		$this->assertSame( array(), $inv->pending() );
	}

	public function test_deferred_changes_left_after_the_shutdown_drain_are_reported(): void {
		$failures = $this->capture_failures();
		$inv      = $this->invalidator();
		$n        = 0;
		add_action(
			Hooks::FLUSHED,
			static function () use ( $inv, &$n ) {
				++$n;
				$inv->defer( 'loop', static fn(): array => array( array( 'post:' . ( 200 + $n ) ), 'loop' ) );
			}
		);
		$inv->queue( array( 'post:1' ), 'e' );
		$inv->commit_action();
		$this->restore_error_log();

		$this->assertCount( 1, $failures );
		$this->assertSame( array( 'loop' ), $failures[0][1]['deferred'] );
	}
}
