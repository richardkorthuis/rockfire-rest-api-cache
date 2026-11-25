<?php
/**
 * Tests for the cache service.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Api;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Maintenance\Maintenance_Result;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Services\Cache_Service;
use Rockfire\Rest_Api_Cache\Storage\Database_Store;
use Rockfire\Rest_Api_Cache\Storage\Entry_Data;
use Rockfire\Rest_Api_Cache\Storage\Entry_Query;
use Rockfire\Rest_Api_Cache\Storage\Object_Cache_Store;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Interface;

/**
 * @covers \Rockfire\Rest_Api_Cache\Services\Cache_Service
 * @covers \Rockfire\Rest_Api_Cache\Storage\Repository::headers_for
 */
class Test_Cache_Service extends Rfrac_Test_Case {

	/**
	 * The Api registered by the plugin boot.
	 *
	 * @var Api|null
	 */
	private ?Api $booted = null;

	/**
	 * `rfrac/flushed` events as `[ type, targets, affected ]`.
	 *
	 * @var list<array{0:string,1:mixed,2:int}>
	 */
	private array $flushed = array();

	/**
	 * Original external object cache flag.
	 *
	 * @var bool
	 */
	private bool $ext_cache;

	public function set_up() {
		parent::set_up();
		$this->booted    = Api::get();
		$this->flushed   = array();
		$this->ext_cache = (bool) wp_using_ext_object_cache();
		add_action(
			Hooks::FLUSHED,
			function ( $type, $targets, $affected ): void {
				$this->flushed[] = array( $type, $targets, $affected );
			},
			10,
			3
		);
	}

	public function tear_down() {
		if ( null !== $this->booted ) {
			Api::register( $this->booted );
		}
		wp_using_ext_object_cache( $this->ext_cache );
		wp_cache_flush_group( 'rfrac' );
		parent::tear_down();
	}

	/**
	 * Service on a database store.
	 *
	 * @param Store_Interface|null $store Store override.
	 *
	 * @return Cache_Service
	 */
	private function service( ?Store_Interface $store = null ): Cache_Service {
		$repository = new Repository();
		return new Cache_Service( $repository, $store ?? new Database_Store( $repository, 1 ), Plugin::instance()->maintenance() );
	}

	/**
	 * Route the public API to a spy store.
	 *
	 * @return Rfrac_Spy_Store
	 */
	private function use_spy(): Rfrac_Spy_Store {
		$spy = new Rfrac_Spy_Store();
		$this->assertNotNull( $this->booted );
		Api::register(
			new Api(
				new Invalidator(
					$spy,
					static function ( string $url ): ?string {
						$context = Request_Context::from_url( $url, rest_get_url_prefix() );
						return null === $context->route ? null : Cache_Key::url_hash( $context, $context->params );
					}
				),
				$this->booted->collector()
			)
		);
		return $spy;
	}

	public function test_query_returns_items_and_total_and_matches_literal_like_characters(): void {
		$match = Rfrac_Entry_Fixtures::insert_entry( array( 'request' => '/wp/v2/foo%bar' ) );
		Rfrac_Entry_Fixtures::insert_entry( array( 'request' => '/wp/v2/foobar' ) );

		$result = $this->service()->query( new Entry_Query( search: 'foo%' ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( $match, $result['items'][0]->id );

		$page = $this->service()->query( new Entry_Query( per_page: 1 ) );
		$this->assertSame( 2, $page['total'] );
		$this->assertCount( 1, $page['items'] );
	}

	public function test_query_falls_back_to_the_default_order_for_an_unknown_orderby(): void {
		global $wpdb;
		Rfrac_Entry_Fixtures::insert_entry();
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';

		$result = $this->service()->query( Entry_Query::from_array( array( 'orderby' => 'size; DROP' ) ) );

		$this->assertSame( '', $wpdb->last_error );
		$wpdb->suppress_errors( $suppress );
		$this->assertSame( 'created_at', Entry_Query::from_array( array( 'orderby' => 'size; DROP' ) )->orderby );
		$this->assertSame( 1, $result['total'] );
	}

	public function test_flush_flushes_the_given_entries_with_one_update_and_fires_the_hook(): void {
		$a     = Rfrac_Entry_Fixtures::insert_entry();
		$b     = Rfrac_Entry_Fixtures::insert_entry();
		$other = Rfrac_Entry_Fixtures::insert_entry();
		$rec   = new Rfrac_Query_Recorder();
		$rec->start();

		$n = $this->service()->flush( array( $a, (string) $b, $b, 0, 'x' ) );

		$rec->stop();
		$this->assertSame( 2, $n );
		$this->assertCount( 1, $rec->matching( '/^\s*UPDATE\b/i' ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $a ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $b ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $other ) );
		$this->assertSame( array( array( 'entries', array( (string) $a, (string) $b ), 2 ) ), $this->flushed );
	}

	public function test_flush_with_nothing_valid_runs_no_query_and_fires_no_action(): void {
		Rfrac_Entry_Fixtures::insert_entry();
		$service = $this->service();
		$rec     = new Rfrac_Query_Recorder();
		$rec->start();

		$this->assertSame( 0, $service->flush( array() ) );
		$this->assertSame( 0, $service->flush( array( 0, 'x' ) ) );

		$rec->stop();
		$this->assertSame( array(), $rec->matching( '/./' ) );
		$this->assertSame( array(), $this->flushed );
	}

	public function test_flush_and_delete_accept_only_positive_integers_and_digit_strings(): void {
		$a   = Rfrac_Entry_Fixtures::insert_entry();
		$b   = Rfrac_Entry_Fixtures::insert_entry();
		$c   = Rfrac_Entry_Fixtures::insert_entry();
		$bad = array( -$a, (float) $a, $a . 'abc', '1e3', '-' . $a, ' ' . $a, $a . "
", '+' . $a, true, null, array( $a ), '0', 0, '' );

		$this->assertSame( 0, $this->service()->flush( $bad ) );
		$this->assertSame( 0, $this->service()->delete( $bad ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $a ) );
		$this->assertSame( array(), $this->flushed );

		$this->assertSame( 2, $this->service()->flush( array_merge( $bad, array( (string) $b, $c ) ) ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $a ) );
		$this->assertSame( 1, $this->service()->delete( array_merge( $bad, array( (string) $b ) ) ) );
		$this->assertSame( '', Rfrac_Entry_Fixtures::get_state( $b ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $a ) );
	}

	public function test_a_throwing_flushed_listener_does_not_break_flush_and_is_reported(): void {
		$id       = Rfrac_Entry_Fixtures::insert_entry();
		$failures = array();
		add_action(
			Hooks::FLUSHED,
			static function (): void {
				throw new RuntimeException( 'listener boom' );
			}
		);
		add_action(
			Hooks::INVALIDATION_FAILED,
			static function ( $error, $context ) use ( &$failures ): void {
				$failures[] = array( $error->getMessage(), $context['listener'] ?? null );
			},
			10,
			2
		);

		$this->assertSame( 1, $this->service()->flush( array( $id ) ) );

		$this->assertSame( array( array( 'listener boom', Hooks::FLUSHED ) ), $failures );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
	}

	public function test_flush_does_not_fire_the_hook_when_nothing_was_flushed(): void {
		$this->assertSame( 0, $this->service()->flush( array( 999999 ) ) );
		$this->assertSame( array(), $this->flushed );
	}

	public function test_delete_removes_the_row_and_its_tags(): void {
		$keep   = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:2' ) );
		$remove = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1', 'list:post' ) );

		$this->assertSame( 1, $this->service()->delete( array( $remove ) ) );

		$this->assertSame( '', Rfrac_Entry_Fixtures::get_state( $remove ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $keep ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count_tags() );
		$this->assertSame( 0, $this->service()->delete( array( 0, 'x' ) ) );
	}

	public function test_flush_tag_delegates_to_the_public_api(): void {
		$spy = $this->use_spy();

		$result = $this->service()->flush_tag( 'post:12, List:Product' );

		$this->assertSame( 1, $result );
		$this->assertSame( array( array( 'list:product', 'post:12' ) ), $spy->calls_of( 'flush_by_tags' ) );
	}

	public function test_flush_tag_rejects_route_tags_and_invalid_tags(): void {
		$spy     = $this->use_spy();
		$service = $this->service();

		$route = $service->flush_tag( 'route:/x' );
		$this->assertWPError( $route );
		$this->assertSame( 'rfrac_use_route_flush', $route->get_error_code() );

		$mixed = $service->flush_tag( 'post:1,route:/x' );
		$this->assertSame( 'rfrac_use_route_flush', $mixed->get_error_code() );

		foreach ( array( '<script>', 'post:1,<script>', '', ' , ', 'nocolon' ) as $input ) {
			$error = $service->flush_tag( $input );
			$this->assertWPError( $error, $input );
			$this->assertSame( 'rfrac_invalid_tag', $error->get_error_code(), $input );
		}

		$too_many = $service->flush_tag( implode( ',', array_map( static fn( int $i ): string => 'post:' . $i, range( 1, 51 ) ) ) );
		$this->assertSame( 'rfrac_invalid_tag', $too_many->get_error_code() );
		$this->assertSame( array(), $spy->calls, 'Nothing was flushed.' );
	}

	public function test_flush_tag_accepts_exactly_fifty_tags(): void {
		$this->use_spy();
		$result = $this->service()->flush_tag( implode( ',', array_map( static fn( int $i ): string => 'post:' . $i, range( 1, 50 ) ) ) );
		$this->assertSame( 1, $result );
	}

	public function test_flush_url_rejects_other_hosts_and_schemes(): void {
		$spy     = $this->use_spy();
		$service = $this->service();

		foreach ( array( 'https://evil.example/wp-json/wp/v2/posts', 'ftp://' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/wp-json/wp/v2/posts', '/wp-json/wp/v2/posts', 'javascript:alert(1)', '' ) as $url ) {
			$error = $service->flush_url( $url );
			$this->assertWPError( $error, $url );
			$this->assertSame( 'rfrac_invalid_url', $error->get_error_code(), $url );
		}
		$this->assertSame( array(), $spy->calls );
	}

	public function test_flush_url_rejects_over_long_urls_and_urls_without_a_rest_route(): void {
		$spy     = $this->use_spy();
		$service = $this->service();

		$long = $service->flush_url( rest_url( 'wp/v2/posts' ) . '?s=' . str_repeat( 'a', Cache_Service::MAX_URL_LENGTH ) );
		$this->assertWPError( $long );
		$this->assertSame( 'rfrac_invalid_url', $long->get_error_code() );
		foreach ( array( home_url( '/' ), home_url( '/some-page/' ), home_url( '/wp-admin/' ) ) as $url ) {
			$error = $service->flush_url( $url );
			$this->assertWPError( $error, $url );
			$this->assertSame( 'rfrac_invalid_url', $error->get_error_code(), $url );
		}
		$this->assertSame( array(), $spy->calls );
	}

	public function test_flush_url_requires_the_home_path_of_a_subdirectory_site(): void {
		$spy = $this->use_spy();
		update_option( 'permalink_structure', '/%postname%/' );
		update_option( 'home', 'http://' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/sub' );
		update_option( 'siteurl', 'http://' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/sub' );
		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$service = $this->service();

		foreach ( array( 'http://' . $host . '/other/wp-json/wp/v2/posts', 'http://' . $host . '/?rest_route=/wp/v2/posts', 'http://' . $host . '/subsite/wp-json/wp/v2/posts' ) as $url ) {
			$error = $service->flush_url( $url );
			$this->assertWPError( $error, $url );
			$this->assertSame( 'rfrac_invalid_url', $error->get_error_code(), $url );
		}
		$this->assertSame( array(), $spy->calls );

		$this->assertSame( 1, $service->flush_url( 'http://' . $host . '/sub/wp-json/wp/v2/posts' ) );
		$this->assertSame( 1, $service->flush_url( 'http://' . $host . '/sub/?rest_route=/wp/v2/posts' ) );
		$this->assertCount( 2, $spy->calls_of( 'flush_by_url_hash' ) );
	}

	public function test_flush_url_delegates_for_the_same_host(): void {
		$spy = $this->use_spy();

		$result = $this->service()->flush_url( rest_url( 'wp/v2/posts' ) );

		$this->assertSame( 1, $result );
		$this->assertCount( 1, $spy->calls_of( 'flush_by_url_hash' ) );
	}

	public function test_flush_route_and_flush_all_delegate_to_the_public_api(): void {
		$spy     = $this->use_spy();
		$service = $this->service();

		$this->assertSame( 1, $service->flush_route( '/wp/v2/posts' ) );
		$this->assertSame( 1, $service->flush_all() );

		$this->assertSame( array( '/wp/v2/posts' ), $spy->calls_of( 'flush_route' ) );
		$this->assertCount( 1, $spy->calls_of( 'flush_all' ) );
	}

	public function test_flush_route_returns_an_error_for_an_empty_route_and_flushes_the_index(): void {
		$spy     = $this->use_spy();
		$service = $this->service();

		foreach ( array( '', '   ', "	
" ) as $route ) {
			$error = $service->flush_route( $route );
			$this->assertWPError( $error );
			$this->assertSame( 'rfrac_invalid_route', $error->get_error_code() );
		}
		$this->assertSame( array(), $spy->calls );

		$this->assertSame( 1, $service->flush_route( '/' ) );
		$this->assertSame( array( '/' ), $spy->calls_of( 'flush_route' ) );
	}

	public function test_flush_tag_accepts_whitespace_separated_tags_and_truncates_the_echoed_tag(): void {
		$spy     = $this->use_spy();
		$service = $this->service();

		$this->assertSame( 1, $service->flush_tag( "post:1 term:2
post:3" ) );
		$this->assertSame( array( array( 'post:1', 'post:3', 'term:2' ) ), $spy->calls_of( 'flush_by_tags' ) );

		$error = $service->flush_tag( 'nocolon' . str_repeat( 'x', 500 ) );
		$this->assertWPError( $error );
		$this->assertLessThan( 200, strlen( $error->get_error_message() ) );
	}

	public function test_stats_counts_the_states(): void {
		$now = time();
		for ( $i = 0; $i < 3; $i++ ) {
			Rfrac_Entry_Fixtures::insert_entry( array( 'size' => 100 ) );
		}
		Rfrac_Entry_Fixtures::insert_entry(
			array(
				'state'      => 2,
				'size'       => 100,
				'flushed_at' => gmdate( 'Y-m-d H:i:s', $now ),
			)
		);
		Rfrac_Entry_Fixtures::insert_entry(
			array(
				'size'       => 100,
				'expires_at' => gmdate( 'Y-m-d H:i:s', $now - 60 ),
			)
		);

		$stats = $this->service()->stats();

		$this->assertSame(
			array(
				'active'  => 3,
				'flushed' => 1,
				'expired' => 1,
				'total'   => 5,
				'size'    => 500,
			),
			$stats
		);
	}

	public function test_get_returns_null_for_a_missing_entry(): void {
		$this->assertNull( $this->service()->get( 999999 ) );
		$this->assertNull( $this->service()->get( 0 ) );
	}

	public function test_get_returns_row_headers_tags_payload_and_state_label(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry(
			array(
				'payload' => '{"a":1}',
				'headers' => '{"X-Test":"yes"}',
			),
			array( 'post:2', 'post:1' )
		);

		$entry = $this->service()->get( $id );

		$this->assertNotNull( $entry );
		$this->assertSame( $id, $entry['row']->id );
		$this->assertSame( array( 'X-Test' => 'yes' ), $entry['headers'] );
		$this->assertSame( array( 'post:1', 'post:2' ), $entry['tags'] );
		$this->assertSame( '{"a":1}', $entry['payload'] );
		$this->assertSame( 'active', $entry['state_label'] );
	}

	public function test_get_state_label_for_flushed_and_expired_entries(): void {
		$flushed = Rfrac_Entry_Fixtures::insert_entry( array( 'state' => 2 ) );
		$expired = Rfrac_Entry_Fixtures::insert_entry( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
		$bad     = Rfrac_Entry_Fixtures::insert_entry( array( 'headers' => 'not json' ) );

		$this->assertSame( 'flushed', $this->service()->get( $flushed )['state_label'] );
		$this->assertSame( 'expired', $this->service()->get( $expired )['state_label'] );
		$this->assertSame( array(), $this->service()->get( $bad )['headers'] );
	}

	public function test_get_reads_the_payload_of_an_object_cache_entry_through_the_store(): void {
		wp_using_ext_object_cache( true );
		$repository = new Repository();
		$store      = new Object_Cache_Store( $repository, 1 );
		$id         = $store->set(
			new Entry_Data(
				hash( 'sha256', 'oc' ),
				hash( 'sha256', 'ocu' ),
				'/wp/v2/posts',
				'/wp/v2/posts',
				array( 'path' => '/wp/v2/posts' ),
				'GET',
				'{"from":"object-cache"}',
				array( 'X-Test' => 'yes' ),
				600,
				array( 'post:1' )
			)
		);
		$this->assertIsInt( $id );

		$entry = $this->service( $store )->get( $id );

		$this->assertNotNull( $entry );
		$this->assertSame( '{"from":"object-cache"}', $entry['payload'] );
		$this->assertSame( array( 'X-Test' => 'yes' ), $entry['headers'] );
	}

	public function test_run_maintenance_passes_the_context(): void {
		$seen = null;
		add_filter(
			Hooks::MAINTENANCE_ARGS,
			static function ( $args, $context ) use ( &$seen ) {
				$seen = $context;
				return $args;
			},
			10,
			2
		);

		$result = $this->service()->run_maintenance( 'tools' );

		$this->assertInstanceOf( Maintenance_Result::class, $result );
		$this->assertSame( 'tools', $seen );
		$this->assertSame( 'tools', $result->context );
	}
}
