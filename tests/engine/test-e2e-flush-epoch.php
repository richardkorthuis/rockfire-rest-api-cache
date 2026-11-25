<?php
/**
 * End-to-end tests of the flush epoch: a take-down committed while a capturing request runs leaves no stale entry.
 *
 * Every test runs for the early server and for the fallback server, against the database store and the object-cache
 * store. The lookup of the path misses and records the flush epoch it saw; the take-down (a public post made
 * private, then a commit of the invalidator) runs on `rest_post_dispatch` at priority 0, after the route callback
 * has read the content and before Capture stashes the response.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Serving\Request_State;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 * @covers \Rockfire\Rest_Api_Cache\Serving\Lookup
 * @covers \Rockfire\Rest_Api_Cache\Storage\Repository
 */
class Test_E2e_Flush_Epoch extends Rfrac_E2e_Test_Case {

	/**
	 * Original external object cache flag.
	 *
	 * @var bool
	 */
	private bool $ext_cache = false;

	/**
	 * The public post that is taken down.
	 *
	 * @var int
	 */
	private int $post_id = 0;

	public function set_up() {
		parent::set_up();
		$this->ext_cache = (bool) wp_using_ext_object_cache();
		$this->post_id   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
	}

	public function tear_down() {
		wp_using_ext_object_cache( $this->ext_cache );
		wp_cache_flush_group( 'rfrac' );
		wp_cache_delete( Names::OPTION_FLUSH_EPOCH, 'options' );
		parent::tear_down();
	}

	/**
	 * Data provider: the two serving paths and the two stores.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function paths_and_stores(): array {
		$cases = array();
		foreach ( self::paths() as $path_name => $path ) {
			foreach ( array( 'database', 'object_cache' ) as $store ) {
				$cases[ $path_name . ' / ' . $store ] = array( $path[0], $store );
			}
		}
		return $cases;
	}

	/**
	 * The REST route of the post.
	 *
	 * @return string
	 */
	private function post_route(): string {
		return '/wp/v2/posts/' . $this->post_id;
	}

	/**
	 * Select the store, compile a config that enables the route of a single post and point the harness at the request.
	 *
	 * @param string $path  Serving path.
	 * @param string $store `database` or `object_cache`.
	 *
	 * @return Rfrac_Serving_Harness
	 */
	private function prepare( string $path, string $store ): Rfrac_Serving_Harness {
		wp_using_ext_object_cache( 'object_cache' === $store );
		$this->configure( array( 'store_mode' => $store ), array( Rfrac_Serving_Harness::route( '/wp/v2/posts/(?P<id>[\d]+)' ) ) );

		$harness = $this->harness( $path );
		$harness->request( '/wp-json' . $this->post_route() );
		return $harness;
	}

	/**
	 * A complete request (the lookup of the path, then the live request) with a callback hooked on `rest_post_dispatch` at priority 0.
	 *
	 * @param Rfrac_Serving_Harness $harness The harness.
	 * @param callable|null         $during  Runs during the dispatch.
	 *
	 * @return void
	 */
	private function miss_during( Rfrac_Serving_Harness $harness, ?callable $during = null ): void {
		$hook = static function ( $result ) use ( $during ) {
			if ( null !== $during ) {
				$during();
			}
			return $result;
		};
		add_filter( 'rest_post_dispatch', $hook, 0 );
		try {
			$this->assertNotNull( $harness->miss( $this->post_route() ), 'The lookup misses.' );
		} finally {
			remove_filter( 'rest_post_dispatch', $hook, 0 );
		}
	}

	/**
	 * Make the post private and commit the invalidator of the live request's container (the one whose listeners queued the change).
	 *
	 * @return int Entries flushed.
	 */
	private function take_down(): int {
		wp_update_post( array( 'ID' => $this->post_id, 'post_status' => 'private' ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertNotNull( $this->h );
		$plugin = ( new ReflectionProperty( Rfrac_Serving_Harness::class, 'plugin' ) )->getValue( $this->h );
		$this->assertInstanceOf( Plugin::class, $plugin );
		return $plugin->invalidator()->commit();
	}

	/**
	 * The flush epoch of the current site, read from the database.
	 *
	 * @return int
	 */
	private function epoch(): int {
		$epoch = ( new Repository() )->flush_epoch();
		$this->assertNotNull( $epoch );
		return (int) $epoch;
	}

	/**
	 * Assert the live response was not stored: BYPASS `flushed`, no entry.
	 *
	 * @param Rfrac_Serving_Harness $harness The harness.
	 *
	 * @return void
	 */
	private function assert_refused( Rfrac_Serving_Harness $harness ): void {
		$this->assertSame( 'BYPASS', $harness->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 'flushed', $harness->live_header( 'X-Rfrac-Cache-Reason' ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'No entry was stored.' );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count_tags(), 'No tag was stored.' );
		$this->assertNoFailures();
	}

	/**
	 * Assert the next lookup of the path is not a hit.
	 *
	 * @param Rfrac_Serving_Harness $harness The harness.
	 * @param string                $path    Serving path.
	 *
	 * @return void
	 */
	private function assert_next_lookup_misses( Rfrac_Serving_Harness $harness, string $path ): void {
		$harness->request( '/wp-json' . $this->post_route() );
		$this->assertFalse( $harness->lookup(), 'The next lookup is not a hit on the ' . $path . ' path.' );
	}

	/**
	 * @dataProvider paths_and_stores
	 *
	 * @param string $path  Serving path.
	 * @param string $store Store.
	 */
	public function test_a_take_down_during_dispatch_stores_nothing_and_the_next_lookup_is_not_a_hit( string $path, string $store ): void {
		$h       = $this->prepare( $path, $store );
		$flushed = null;
		$before  = $this->epoch();

		$this->miss_during(
			$h,
			function () use ( &$flushed ): void {
				$flushed = $this->take_down();
			}
		);

		$this->assertSame( 0, $flushed, 'The commit flushed no entry: nothing was stored yet.' );
		$this->assertGreaterThan( $before, $this->epoch(), 'The commit advanced the flush epoch.' );
		$this->assert_refused( $h );
		$this->assert_next_lookup_misses( $h, $path );
	}

	/**
	 * @dataProvider paths_and_stores
	 *
	 * @param string $path  Serving path.
	 * @param string $store Store.
	 */
	public function test_a_stale_object_cache_copy_of_the_epoch_does_not_let_the_store_through( string $path, string $store ): void {
		$h = $this->prepare( $path, $store );

		$this->miss_during(
			$h,
			function (): void {
				$before = $this->epoch();
				$this->assertSame( 0, $this->take_down() );
				$this->assertGreaterThan( $before, $this->epoch() );

				// A copy of the epoch from before the flush, as a not yet invalidated object cache would still hand out.
				wp_cache_set( Names::OPTION_FLUSH_EPOCH, (string) $before, 'options' );
				$this->assertSame( (string) $before, wp_cache_get( Names::OPTION_FLUSH_EPOCH, 'options' ) );
			}
		);

		$this->assert_refused( $h );
		$this->assert_next_lookup_misses( $h, $path );
	}

	/**
	 * @dataProvider paths_and_stores
	 *
	 * @param string $path  Serving path.
	 * @param string $store Store.
	 */
	public function test_without_a_change_during_dispatch_the_response_is_stored_and_the_next_lookup_hits( string $path, string $store ): void {
		$h      = $this->prepare( $path, $store );
		$before = $this->epoch();

		$this->miss_during( $h );

		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'The response was stored.' );
		$this->assertSame( $before, $this->epoch(), 'Storing flushes nothing.' );

		$h->request( '/wp-json' . $this->post_route() );
		$this->assertTrue( $h->lookup(), 'The next lookup is a hit on the ' . $path . ' path.' );
		$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
		$this->assertNoFailures();
	}

	/**
	 * Data provider: the two serving paths, each with a configuration that no lookup could use.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function paths_and_unusable_configurations(): array {
		$cases = array();
		foreach ( self::paths() as $path_name => $path ) {
			foreach ( array( 'not compiled', 'schema mismatch' ) as $state ) {
				$cases[ $path_name . ' / ' . $state ] = array( $path[0], $state );
			}
		}
		return $cases;
	}

	/**
	 * Leave the site with a configuration that no lookup can use.
	 *
	 * @param string $state `not compiled` or `schema mismatch`.
	 *
	 * @return void
	 */
	private function make_the_configuration_unusable( string $state ): void {
		if ( 'not compiled' === $state ) {
			// The first request of a site: the live request compiles the configuration on `rest_api_init`, after no lookup could run.
			delete_option( Names::OPTION_CONFIG );
		} else {
			$this->configure( array( 'schema_version' => 999 ), array( Rfrac_Serving_Harness::route( '/wp/v2/posts/(?P<id>[\d]+)' ) ) );
		}
	}

	/**
	 * Assert that the refusal for a missing flush epoch was recorded as a `capture` failure of the post's route.
	 *
	 * @return void
	 */
	private function assert_no_lookup_failure_recorded(): void {
		$last = Failure_Recorder::last();
		$this->assertIsArray( $last, 'A cacheable response refused for lack of a flush epoch is an operator-visible failure.' );
		$this->assertSame( 'capture', $last['context'] );
		$this->assertStringContainsString( 'No lookup recorded a flush epoch', $last['message'] );
		$this->assertSame( $this->post_route(), get_option( Failure_Recorder::OPTION )['subject'] ?? null, 'Recorded for its route, so only a stored response of that route clears it.' );
	}

	/**
	 * @dataProvider paths_and_unusable_configurations
	 *
	 * @param string $path  Serving path.
	 * @param string $state `not compiled` or `schema mismatch`.
	 */
	public function test_a_request_whose_lookup_could_not_run_stores_nothing_as_no_lookup( string $path, string $state ): void {
		$this->make_the_configuration_unusable( $state );
		$h = $this->harness( $path );
		$h->request( '/wp-json' . $this->post_route() );
		$before = $this->epoch();

		$this->assertNotNull( $h->miss( $this->post_route() ), 'No lookup could serve a hit.' );

		$this->assertFalse( Request_State::lookup_done(), 'Precondition: no lookup ran on the ' . $path . ' path.' );
		$this->assertNull( Request_State::flush_epoch() );
		$this->assertNotNull( Compiled_Config::load(), 'Precondition: the capture sees a configuration.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( Bypass_Reason::NO_LOOKUP->value, $h->live_header( 'X-Rfrac-Cache-Reason' ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'No entry was stored.' );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count_tags(), 'No tag was stored.' );
		$this->assertSame( $before, $this->epoch(), 'Refusing flushes nothing.' );
		$this->assert_no_lookup_failure_recorded();
	}

	/**
	 * @dataProvider paths_and_unusable_configurations
	 *
	 * @param string $path  Serving path.
	 * @param string $state `not compiled` or `schema mismatch`.
	 */
	public function test_the_request_after_a_refused_no_lookup_request_records_an_epoch_stores_and_the_next_lookup_hits( string $path, string $state ): void {
		$this->make_the_configuration_unusable( $state );
		$h = $this->harness( $path );

		// Request 1: no lookup could run, so nothing is stored.
		$h->request( '/wp-json' . $this->post_route() );
		$this->assertNotNull( $h->miss( $this->post_route() ), 'No lookup could serve a hit.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ), 'Precondition: the first request was refused on the ' . $path . ' path.' );
		$this->assertSame( Bypass_Reason::NO_LOOKUP->value, $h->live_header( 'X-Rfrac-Cache-Reason' ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'Precondition: the first request stored nothing.' );
		$this->assert_no_lookup_failure_recorded();

		// Request 2: the configuration is compiled, so the lookup runs, misses and records the epoch, and the response is stored.
		$this->configure( array(), array( Rfrac_Serving_Harness::route( '/wp/v2/posts/(?P<id>[\d]+)' ) ) );
		$h->request( '/wp-json' . $this->post_route() );
		$this->assertNotNull( $h->miss( $this->post_route() ), 'The lookup of the second request misses on the ' . $path . ' path.' );

		$this->assertTrue( Request_State::lookup_done(), 'The lookup of the second request ran.' );
		$this->assertNotNull( Request_State::flush_epoch(), 'The lookup of the second request recorded the flush epoch.' );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'The second request stored the response.' );
		$this->assertNull( Failure_Recorder::last(), 'Storing the route cleared the failure the refusal recorded.' );

		// Request 3: the stored entry is served.
		$h->request( '/wp-json' . $this->post_route() );
		$this->assertTrue( $h->lookup(), 'The lookup of the third request is a hit on the ' . $path . ' path.' );
		$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
	}

	/**
	 * Data provider: the two serving paths, each with a pending or a stale snapshot whose schema the lookup can use.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function paths_and_outdated_configurations(): array {
		$cases = array();
		foreach ( self::paths() as $path_name => $path ) {
			foreach ( array( 'pending', 'stale' ) as $state ) {
				$cases[ $path_name . ' / ' . $state ] = array( $path[0], $state );
			}
		}
		return $cases;
	}

	/**
	 * Leave the site with a pending or a stale snapshot that enables no route.
	 *
	 * The snapshot carries the resolved store, as a real compile writes it ('database' or 'object_cache', never
	 * 'auto'): from 'auto' the rebuild would see a store change and flush both stores, which is not what an
	 * activation or a mark_stale() leaves behind.
	 *
	 * @param string $state `pending` or `stale`.
	 *
	 * @return void
	 */
	private function make_the_snapshot_outdated( string $state ): void {
		$store = wp_using_ext_object_cache() ? 'object_cache' : 'database';
		if ( 'pending' === $state ) {
			// As after an activation: compile() without a REST server, so route discovery is still pending.
			$this->configure( array( 'pending_discovery' => true, 'fingerprint' => '', 'store_mode' => $store ), array() );
		} else {
			// As after mark_stale(): the fingerprint is emptied, so the next request rebuilds it.
			$this->configure( array( 'fingerprint' => '', 'store_mode' => $store ), array() );
		}
	}

	/**
	 * @dataProvider paths_and_outdated_configurations
	 *
	 * @param string $path  Serving path.
	 * @param string $state `pending` or `stale`.
	 */
	public function test_the_request_that_rebuilt_its_outdated_snapshot_is_refused_no_lookup_without_a_failure( string $path, string $state ): void {
		$this->make_the_snapshot_outdated( $state );
		$h = $this->harness( $path );
		$h->request( '/wp-json' . $this->post_route() );
		$before = $this->epoch();

		$this->assertNotNull( $h->miss( $this->post_route() ), 'No lookup could serve a hit.' );

		$this->assertTrue( Request_State::lookup_done(), 'Precondition: the lookup ran on the ' . $path . ' path.' );
		$this->assertTrue( Request_State::outdated_snapshot(), 'The lookup saw the ' . $state . ' snapshot.' );
		$this->assertTrue( Request_State::outdated_snapshot_rebuilt(), 'The recompile of the request rebuilt it.' );
		$this->assertNull( Request_State::flush_epoch() );
		$config = Compiled_Config::load();
		$this->assertNotNull( $config );
		$this->assertNotSame( '', $config->fingerprint(), 'The snapshot was rebuilt.' );
		$this->assertFalse( $config->pending_discovery(), 'The snapshot is no longer pending.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( Bypass_Reason::NO_LOOKUP->value, $h->live_header( 'X-Rfrac-Cache-Reason' ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'No entry was stored.' );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count_tags(), 'No tag was stored.' );
		$this->assertSame( $before, $this->epoch(), 'Refusing flushes nothing.' );
		$this->assertNull( Failure_Recorder::last(), 'The refusal records no failure.' );
	}

	/**
	 * @dataProvider paths_and_outdated_configurations
	 *
	 * @param string $path  Serving path.
	 * @param string $state `pending` or `stale`.
	 */
	public function test_the_request_after_the_one_that_rebuilt_its_outdated_snapshot_stores_and_the_next_lookup_hits( string $path, string $state ): void {
		$this->make_the_snapshot_outdated( $state );
		$h = $this->harness( $path );

		// Request 1: refused, and the live request rebuilds the snapshot.
		$h->request( '/wp-json' . $this->post_route() );
		$this->assertNotNull( $h->miss( $this->post_route() ), 'No lookup could serve a hit.' );
		$this->assertSame( Bypass_Reason::NO_LOOKUP->value, $h->live_header( 'X-Rfrac-Cache-Reason' ), 'Precondition: the first request was refused on the ' . $path . ' path.' );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'Precondition: the first request stored nothing.' );

		// Request 2: the lookup runs on the rebuilt snapshot, misses and records the epoch, and the response is stored.
		$h->request( '/wp-json' . $this->post_route() );
		$this->assertNotNull( $h->miss( $this->post_route() ), 'The lookup of the second request misses on the ' . $path . ' path.' );

		$this->assertFalse( Request_State::outdated_snapshot(), 'The rebuilt snapshot is current.' );
		$this->assertNotNull( Request_State::flush_epoch(), 'The lookup of the second request recorded the flush epoch.' );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'The second request stored the response.' );
		$this->assertNull( Failure_Recorder::last(), 'No failure was recorded for either request.' );

		// Request 3: the stored entry is served.
		$h->request( '/wp-json' . $this->post_route() );
		$this->assertTrue( $h->lookup(), 'The lookup of the third request is a hit on the ' . $path . ' path.' );
		$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
		$this->assertFalse( Request_State::outdated_snapshot(), 'A hit records no observation.' );
	}
}
