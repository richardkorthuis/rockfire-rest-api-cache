<?php
/**
 * Tests for the entries list table of the Caches page.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Tables\Entries_Table;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Services\Route_Service;

/**
 * @covers \Rockfire\Rest_Api_Cache\Admin\Tables\Entries_Table
 */
class Test_Entries_Table extends Rfrac_Test_Case {

	use Rfrac_Admin_Test_Helpers;

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$this->as_admin();
		$GLOBALS['hook_suffix'] = 'toplevel_page_rfrac-caches';
		set_current_screen( 'toplevel_page_rfrac-caches' );
		$this->reset_admin_request();
	}

	public function tear_down() {
		$this->reset_admin_request();
		unset( $GLOBALS['current_screen'], $GLOBALS['hook_suffix'] );
		parent::tear_down();
	}

	private function table( int $sampling = 0 ): Entries_Table {
		$plugin = Plugin::instance();
		return new Entries_Table( $plugin->cache_service(), $plugin->route_service(), $sampling );
	}

	/**
	 * Load the items and return the rendered table.
	 */
	private function render( Entries_Table $table ): string {
		$table->prepare_items();
		ob_start();
		$table->display();
		return (string) ob_get_clean();
	}

	public function test_columns_and_sortable_columns(): void {
		$table = $this->table();
		$this->assertSame( array( 'cb', 'request', 'method', 'route', 'state', 'size', 'hits', 'created_at', 'expires_at', 'last_hit_at' ), array_keys( $table->get_columns() ) );
		$method = new ReflectionMethod( $table, 'get_sortable_columns' );
		$method->setAccessible( true );
		$this->assertSame( array( 'method', 'route', 'size', 'hits', 'created_at', 'expires_at', 'last_hit_at' ), array_keys( $method->invoke( $table ) ) );
	}

	public function test_each_row_prints_its_escaped_method_and_the_list_sorts_by_it(): void {
		$get  = Rfrac_Entry_Fixtures::insert_entry( array( 'method' => 'GET' ) );
		$post = Rfrac_Entry_Fixtures::insert_entry( array( 'method' => 'POST' ) );
		$this->set_request(
			array(
				'orderby' => 'method',
				'order'   => 'asc',
			)
		);
		$table = $this->table();
		$html  = $this->render( $table );
		$this->assertSame( array( $get, $post ), array_map( static fn( $row ) => $row->id, $table->items ) );
		$this->assertStringContainsString( '>GET</td>', $html );
		$this->assertStringContainsString( '>POST</td>', $html );

		$this->set_request(
			array(
				'orderby' => 'method',
				'order'   => 'desc',
			)
		);
		$table = $this->table();
		$this->render( $table );
		$this->assertSame( array( $post, $get ), array_map( static fn( $row ) => $row->id, $table->items ) );
	}

	public function test_the_method_is_escaped_in_the_list(): void {
		Rfrac_Entry_Fixtures::insert_entry( array( 'method' => '<b>X</b>' ) );
		$this->set_request( array() );
		$html = $this->render( $this->table() );
		$this->assertStringContainsString( '&lt;b&gt;X&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>X</b>', $html );
	}

	public function test_an_unknown_orderby_falls_back_to_the_newest_first_default(): void {
		$old = Rfrac_Entry_Fixtures::insert_entry( array( 'created_at' => '2020-01-01 00:00:00' ) );
		$new = Rfrac_Entry_Fixtures::insert_entry( array( 'created_at' => '2030-01-01 00:00:00' ) );
		$this->set_request( array( 'orderby' => 'methods' ) );
		$table = $this->table();
		$this->render( $table );
		$this->assertSame( array( $new, $old ), array_map( static fn( $row ) => $row->id, $table->items ) );
	}

	public function test_third_page_of_45_entries_has_five_rows_and_a_total_of_45(): void {
		for ( $i = 0; $i < 45; $i++ ) {
			Rfrac_Entry_Fixtures::insert_entry();
		}
		$this->set_request( array( 'paged' => '3' ) );
		$table = $this->table();
		$html  = $this->render( $table );
		$this->assertCount( 5, $table->items );
		$this->assertSame( 45, $table->get_pagination_arg( 'total_items' ) );
		$this->assertSame( 3, $table->get_pagination_arg( 'total_pages' ) );
		$this->assertStringContainsString( '45 items', $html );
	}

	public function test_flushed_view_lists_only_flushed_entries_without_a_flush_action(): void {
		Rfrac_Entry_Fixtures::insert_entry( array( 'request' => '/wp/v2/active-one' ) );
		Rfrac_Entry_Fixtures::insert_entry(
			array(
				'request'    => '/wp/v2/flushed-one',
				'state'      => 2,
				'flushed_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$this->set_request( array( 'view' => 'flushed' ) );
		$table = $this->table();
		$html  = $this->render( $table );
		$this->assertCount( 1, $table->items );
		$this->assertStringContainsString( 'flushed-one', $html );
		$this->assertStringNotContainsString( 'active-one', $html );
		$this->assertStringNotContainsString( 'rfrac_flush_entry', $html );
		$this->assertStringContainsString( 'rfrac_delete_entry', $html );
	}

	public function test_active_row_has_flush_details_and_confirmed_delete_actions(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry();
		$this->set_request( array() );
		$html = $this->render( $this->table() );
		$this->assertStringContainsString( 'rfrac_flush_entry', $html );
		$this->assertStringContainsString( 'target=' . $id, $html );
		$this->assertStringContainsString( 'action=details', $html );
		$this->assertStringContainsString( 'data-rfrac-confirm=', $html );
		$this->assertStringContainsString( 'name="entry[]"', $html );
	}

	public function test_views_show_counts_from_the_stats(): void {
		Rfrac_Entry_Fixtures::insert_entry();
		Rfrac_Entry_Fixtures::insert_entry( array( 'state' => 2 ) );
		Rfrac_Entry_Fixtures::insert_entry( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
		$this->set_request( array() );
		ob_start();
		$this->table()->views();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'All <span class="count">(3)</span>', $html );
		$this->assertStringContainsString( 'Flushed <span class="count">(1)</span>', $html );
		$this->assertStringContainsString( 'Expired <span class="count">(1)</span>', $html );
		$this->assertStringContainsString( 'Active <span class="count">(1)</span>', $html );
	}

	public function test_expired_view_and_badges(): void {
		Rfrac_Entry_Fixtures::insert_entry( array( 'request' => '/wp/v2/live' ) );
		Rfrac_Entry_Fixtures::insert_entry(
			array(
				'request'    => '/wp/v2/old',
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			)
		);
		$this->set_request( array( 'view' => 'expired' ) );
		$html = $this->render( $this->table() );
		$this->assertStringContainsString( '/wp/v2/old', $html );
		$this->assertStringNotContainsString( '/wp/v2/live', $html );
		$this->assertStringContainsString( 'rfrac-badge--warning', $html );
		$this->assertStringContainsString( 'rfrac-badge--warning">Expired</span>', $html );
		$this->assertStringNotContainsString( 'State: Expired', $html, 'No screen-reader text repeating the visible badge.' );
		$this->assertStringNotContainsString( 'rfrac_flush_entry', $html );
	}

	public function test_search_filters_by_request(): void {
		Rfrac_Entry_Fixtures::insert_entry( array( 'request' => '/wp/v2/posts?search=needle' ) );
		Rfrac_Entry_Fixtures::insert_entry( array( 'request' => '/wp/v2/pages' ) );
		$this->set_request( array( 's' => 'needle' ) );
		$table = $this->table();
		$this->render( $table );
		$this->assertCount( 1, $table->items );
	}

	public function test_request_is_escaped_in_the_list(): void {
		Rfrac_Entry_Fixtures::insert_entry( array( 'request' => '/wp/v2/posts?x=<script>alert(1)</script>' ) );
		$this->set_request( array() );
		$html = $this->render( $this->table() );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script>alert(1)', $html );
	}

	public function test_a_hostile_orderby_falls_back_to_the_default_without_a_database_error(): void {
		global $wpdb;
		Rfrac_Entry_Fixtures::insert_entry();
		Rfrac_Entry_Fixtures::insert_entry();
		$this->set_request(
			array(
				'orderby' => 'hits;DROP',
				'order'   => 'desc;DROP',
			)
		);
		$wpdb->last_error = '';
		$table            = $this->table();
		$this->render( $table );
		$this->assertCount( 2, $table->items );
		$this->assertSame( '', $wpdb->last_error );
	}

	public function test_sorting_by_size(): void {
		Rfrac_Entry_Fixtures::insert_entry( array( 'size' => 5 ) );
		$big = Rfrac_Entry_Fixtures::insert_entry( array( 'size' => 500 ) );
		$this->set_request(
			array(
				'orderby' => 'size',
				'order'   => 'desc',
			)
		);
		$table = $this->table();
		$this->render( $table );
		$this->assertSame( $big, $table->items[0]->id );
	}

	public function test_route_filter_shows_only_that_routes_entries(): void {
		Rfrac_Entry_Fixtures::insert_entry(
			array(
				'request'  => '/wp/v2/posts?a=1',
				'route'    => '/wp/v2/posts',
				'rule_key' => '/wp/v2/posts',
			)
		);
		Rfrac_Entry_Fixtures::insert_entry(
			array(
				'request'  => '/wp/v2/pages?a=1',
				'route'    => '/wp/v2/pages',
				'rule_key' => '/wp/v2/pages',
			)
		);
		$this->new_rest_server();
		Plugin::instance()->route_service()->flush();
		$this->set_request( array( 'route_id' => Route_Service::id_for( '/wp/v2/posts' ) ) );
		$table = $this->table();
		$html  = $this->render( $table );
		$this->assertCount( 1, $table->items );
		$this->assertSame( '/wp/v2/posts', $table->items[0]->route );
		$this->assertNotNull( $table->route_filter() );
		$this->assertStringContainsString( 'route_id=' . Route_Service::id_for( '/wp/v2/posts' ), $html );
	}

	public function test_an_unknown_route_id_is_ignored(): void {
		Rfrac_Entry_Fixtures::insert_entry();
		$this->set_request( array( 'route_id' => 'nosuchroute' ) );
		$table = $this->table();
		$this->render( $table );
		$this->assertCount( 1, $table->items );
		$this->assertTrue( $table->has_unknown_route() );
	}

	public function test_hits_are_approximate_only_when_sampling(): void {
		Rfrac_Entry_Fixtures::insert_entry( array( 'hits' => 7 ) );
		$this->set_request( array() );
		$this->assertStringContainsString( '≈7', $this->render( $this->table( 10 ) ) );
		$this->assertStringNotContainsString( '≈', $this->render( $this->table( 1 ) ) );
		$this->assertStringNotContainsString( '≈', $this->render( $this->table( 0 ) ) );
	}

	public function test_dates_are_formatted_in_the_site_timezone_and_empty_dates_show_a_dash(): void {
		$this->assertSame( '—', Entries_Table::format_date( '' ) );
		$this->assertSame( '—', Entries_Table::format_date( '0000-00-00 00:00:00' ) );
		$expected = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( '2026-01-02 03:04:05 UTC' ) );
		$this->assertSame( $expected, Entries_Table::format_date( '2026-01-02 03:04:05' ) );
	}

	public function test_empty_list_message(): void {
		$this->set_request( array() );
		$this->assertStringContainsString( 'No cache entries found.', $this->render( $this->table() ) );
	}
}
