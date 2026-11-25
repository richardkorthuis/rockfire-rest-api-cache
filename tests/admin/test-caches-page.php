<?php
/**
 * Tests for the Caches admin page: bulk actions, row actions, details, screen option.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Admin;
use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Admin\Capability;
use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Admin\Pages\Caches_Page;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Install\Mu_Installer;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Schema\Schema;
use Rockfire\Rest_Api_Cache\Services\Status_Service;

/**
 * @covers \Rockfire\Rest_Api_Cache\Admin\Pages\Caches_Page
 */
class Test_Caches_Page extends Rfrac_Test_Case {

	use Rfrac_Admin_Test_Helpers;

	/**
	 * Temporary must-use directory.
	 *
	 * @var string
	 */
	private string $mu_dir;

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$this->mu_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/rfrac-caches-mu-' . bin2hex( random_bytes( 4 ) );
		$GLOBALS['hook_suffix'] = 'toplevel_page_rfrac-caches';
		set_current_screen( 'toplevel_page_rfrac-caches' );
		$this->reset_admin_request();
	}

	public function tear_down() {
		$this->reset_admin_request();
		foreach ( (array) glob( $this->mu_dir . '/*' ) as $file ) {
			if ( is_string( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		if ( is_dir( $this->mu_dir ) ) {
			rmdir( $this->mu_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		unset( $GLOBALS['current_screen'], $GLOBALS['hook_suffix'] );
		parent::tear_down();
	}

	private function page(): Caches_Page {
		$plugin = Plugin::instance();
		return new Caches_Page( new Notices(), $plugin->cache_service(), $plugin->route_service(), $plugin->settings_service() );
	}

	private function admin( Caches_Page $page ): Admin {
		$plugin = Plugin::instance();
		$status = new Status_Service(
			Config::instance(),
			static fn() => $plugin->store(),
			new Schema(),
			new Mu_Installer( $plugin->basename(), RFRAC_DIR . 'mu-plugin/rfrac-early-cache.php', RFRAC_VERSION, $this->mu_dir ),
			$plugin->cache_service()
		);
		return new Admin( new Capability(), new Notices(), $status, $plugin->cache_service(), array( $page ) );
	}

	/**
	 * Run the page load and return the redirect.
	 */
	private function load_redirect( Caches_Page $page ): Redirect_Intercepted {
		$this->intercept_redirect();
		try {
			$this->admin( $page )->load( $page );
		} catch ( Redirect_Intercepted $redirect ) {
			return $redirect;
		}
		$this->fail( 'Expected a redirect.' );
	}

	/**
	 * Run an admin-post action and return the redirect.
	 */
	private function run_action( Caches_Page $page, string $action ): Redirect_Intercepted {
		$admin = $this->admin( $page );
		$admin->register();
		$this->intercept_redirect();
		try {
			do_action( 'admin_post_rfrac_' . $action );
		} catch ( Redirect_Intercepted $redirect ) {
			return $redirect;
		}
		$this->fail( 'Expected a redirect.' );
	}

	private function render( Caches_Page $page ): string {
		ob_start();
		$page->render();
		return (string) ob_get_clean();
	}

	private function nonce(): string {
		return wp_create_nonce( 'bulk-rfrac_entries' );
	}

	// ---------- Registration ----------

	public function test_page_identity_and_actions(): void {
		$page = $this->page();
		$this->assertSame( 'rfrac-caches', $page->slug() );
		$this->assertSame( 20, $page->position() );
		$this->assertSame( array( 'flush_entry', 'delete_entry' ), array_keys( $page->actions() ) );
	}

	public function test_the_plugin_registers_the_page(): void {
		$slugs = array_map( static fn( $page ) => $page->slug(), Plugin::instance()->admin_pages() );
		$this->assertContains( 'rfrac-caches', $slugs );
	}

	// ---------- Bulk actions ----------

	public function test_bulk_flush_flushes_exactly_the_selected_entries_and_redirects_with_303(): void {
		$this->as_admin();
		$a = Rfrac_Entry_Fixtures::insert_entry();
		$b = Rfrac_Entry_Fixtures::insert_entry();
		$c = Rfrac_Entry_Fixtures::insert_entry();
		Rfrac_Entry_Fixtures::insert_entry();
		update_user_option( get_current_user_id(), 'rfrac_entries_per_page', 1 );
		$this->set_request(
			array(
				'action'   => 'flush',
				'entry'    => array( (string) $a, (string) $c ),
				'_wpnonce' => $this->nonce(),
				's'        => 'posts<b>',
				'view'     => 'active',
				'route_id' => 'abc123',
				'orderby'  => 'size',
				'order'    => 'asc',
				'paged'    => '2',
			)
		);

		$redirect = $this->load_redirect( $this->page() );

		$this->assertSame( 303, $redirect->status );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $a ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $b ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $c ) );
		parse_str( (string) wp_parse_url( $redirect->location, PHP_URL_QUERY ), $query );
		$this->assertSame( 'rfrac-caches', $query['page'] );
		$this->assertSame( 'posts', $query['s'] );
		$this->assertSame( 'active', $query['view'] );
		$this->assertSame( 'abc123', $query['route_id'] );
		$this->assertSame( 'size', $query['orderby'] );
		$this->assertSame( 'asc', $query['order'] );
		$this->assertSame( '2', $query['paged'], 'Two active entries remain at one per page.' );
		$this->assertArrayNotHasKey( '_wpnonce', $query );
		$this->assertSame( 'success', ( new Notices() )->queued()[0]['type'] );
	}

	public function test_a_bulk_delete_that_empties_the_last_page_returns_to_the_new_last_page(): void {
		$this->as_admin();
		update_user_option( get_current_user_id(), 'rfrac_entries_per_page', 2 );
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = Rfrac_Entry_Fixtures::insert_entry();
		}
		$this->set_request(
			array(
				'action'   => 'delete',
				'entry'    => array( (string) $ids[4] ),
				'_wpnonce' => $this->nonce(),
				'paged'    => '3',
			)
		);

		$redirect = $this->load_redirect( $this->page() );

		parse_str( (string) wp_parse_url( $redirect->location, PHP_URL_QUERY ), $query );
		$this->assertSame( '2', $query['paged'], 'Four entries left: page 3 no longer exists.' );

		$this->set_request( array( 'paged' => '3' ) );
		$html = $this->render( $this->page() );
		$this->assertStringNotContainsString( 'No cache entries found.', $html, 'A page past the end shows the last page.' );
	}

	public function test_bulk_with_bad_ids_changes_nothing(): void {
		$this->as_admin();
		$id = Rfrac_Entry_Fixtures::insert_entry();
		foreach ( array( array( '0', '-0', 'abc', '' ), 'abc', '0,,0' ) as $entry ) {
			delete_user_meta( get_current_user_id(), Notices::meta_key() );
			$this->set_request(
				array(
					'action'   => 'delete',
					'entry'    => $entry,
					'_wpnonce' => $this->nonce(),
				)
			);
			$this->load_redirect( $this->page() );
			$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ) );
			$this->assertSame( 'warning', ( new Notices() )->queued()[0]['type'] );
		}
	}

	public function test_bulk_entry_as_a_comma_separated_string_is_accepted(): void {
		$this->as_admin();
		$a = Rfrac_Entry_Fixtures::insert_entry();
		$b = Rfrac_Entry_Fixtures::insert_entry();
		$this->set_request(
			array(
				'action'   => 'flush',
				'entry'    => $a . ',' . $b,
				'_wpnonce' => $this->nonce(),
			)
		);
		$this->load_redirect( $this->page() );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $a ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $b ) );
	}

	public function test_bulk_delete_by_a_subscriber_dies_and_changes_nothing(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) );
		$this->as_subscriber();
		$this->set_request(
			array(
				'action'   => 'delete',
				'entry'    => array( (string) $id ),
				'_wpnonce' => $this->nonce(),
			)
		);
		try {
			$this->load_redirect( $this->page() );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count_tags() );
	}

	public function test_bulk_delete_removes_the_selected_entries(): void {
		$this->as_admin();
		$a = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) );
		$b = Rfrac_Entry_Fixtures::insert_entry();
		$this->set_request(
			array(
				'action'   => 'delete',
				'entry'    => array( (string) $a ),
				'_wpnonce' => $this->nonce(),
			)
		);
		$redirect = $this->load_redirect( $this->page() );
		$this->assertSame( 303, $redirect->status );
		$this->assertSame( '', Rfrac_Entry_Fixtures::get_state( $a ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $b ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count_tags() );
	}

	public function test_bulk_action_without_a_nonce_dies_and_changes_nothing(): void {
		$this->as_admin();
		$id = Rfrac_Entry_Fixtures::insert_entry();
		$this->set_request(
			array(
				'action' => 'flush',
				'entry'  => array( (string) $id ),
			)
		);
		try {
			$this->load_redirect( $this->page() );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ) );
		}
	}

	public function test_bulk_action_with_a_wrong_nonce_dies(): void {
		$this->as_admin();
		$id = Rfrac_Entry_Fixtures::insert_entry();
		$this->set_request(
			array(
				'action'   => 'delete',
				'entry'    => array( (string) $id ),
				'_wpnonce' => wp_create_nonce( 'something-else' ),
			)
		);
		$this->expectException( WPDieException::class );
		$this->load_redirect( $this->page() );
	}

	public function test_bulk_action_by_a_subscriber_dies_and_changes_nothing(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry();
		$this->as_subscriber();
		$this->set_request(
			array(
				'action'   => 'flush',
				'entry'    => array( (string) $id ),
				'_wpnonce' => $this->nonce(),
			)
		);
		try {
			$this->load_redirect( $this->page() );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ) );
		}
	}

	public function test_bulk_action_without_a_selection_warns_and_redirects(): void {
		$this->as_admin();
		$this->set_request(
			array(
				'action'   => 'flush',
				'_wpnonce' => $this->nonce(),
			)
		);
		$redirect = $this->load_redirect( $this->page() );
		$this->assertSame( 303, $redirect->status );
		$this->assertSame( 'warning', ( new Notices() )->queued()[0]['type'] );
	}

	public function test_loading_the_list_without_an_action_does_not_redirect_and_adds_the_screen_option(): void {
		$this->as_admin();
		$this->set_request( array( 'page' => 'rfrac-caches' ) );
		$page = $this->page();
		$this->admin( $page )->load( $page );
		$this->assertSame( 'rfrac_entries_per_page', get_current_screen()->get_option( 'per_page', 'option' ) );
		$this->assertSame( 20, get_current_screen()->get_option( 'per_page', 'default' ) );
	}

	// ---------- Screen option ----------

	public function test_the_per_page_screen_option_is_capped_at_100(): void {
		$this->page();
		$this->assertSame( 100, apply_filters( 'set_screen_option_rfrac_entries_per_page', false, 'rfrac_entries_per_page', '5000' ) );
		$this->assertSame( 35, apply_filters( 'set_screen_option_rfrac_entries_per_page', false, 'rfrac_entries_per_page', '35' ) );
		$this->assertSame( 0, apply_filters( 'set_screen_option_rfrac_entries_per_page', false, 'rfrac_entries_per_page', 'abc' ) );
	}

	// ---------- Row actions ----------

	public function test_flush_entry_flushes_with_a_valid_nonce_and_redirects_to_the_list(): void {
		$this->as_admin();
		$id = Rfrac_Entry_Fixtures::insert_entry();
		$this->set_url_request( Admin_Url::action( 'flush_entry', (string) $id ) );
		$redirect = $this->run_action( $this->page(), 'flush_entry' );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
		$this->assertStringContainsString( 'page=rfrac-caches', $redirect->location );
	}

	public function test_delete_entry_deletes_with_a_valid_nonce(): void {
		$this->as_admin();
		$id = Rfrac_Entry_Fixtures::insert_entry();
		$this->set_url_request( Admin_Url::action( 'delete_entry', (string) $id ) );
		$this->run_action( $this->page(), 'delete_entry' );
		$this->assertSame( '', Rfrac_Entry_Fixtures::get_state( $id ) );
	}

	public function test_a_row_action_on_a_missing_entry_is_an_error(): void {
		$this->as_admin();
		foreach ( array( 'flush_entry', 'delete_entry' ) as $action ) {
			delete_user_meta( get_current_user_id(), Notices::meta_key() );
			$this->set_url_request( Admin_Url::action( $action, '999999' ) );
			$this->run_action( $this->page(), $action );
			$this->assertSame( array( array( 'type' => 'error', 'message' => 'Cache entry not found.' ) ), ( new Notices() )->queued(), $action );
		}
	}

	public function test_a_row_action_returns_to_the_filtered_list_it_came_from(): void {
		$this->as_admin();
		$id = Rfrac_Entry_Fixtures::insert_entry();
		$this->set_url_request( Admin_Url::action( 'flush_entry', (string) $id ) );
		$_SERVER['HTTP_REFERER'] = Admin_Url::page(
			'rfrac-caches',
			array(
				's'       => 'posts',
				'view'    => 'active',
				'orderby' => 'hits',
				'order'   => 'asc',
				'bogus'   => '<script>',
			)
		);

		$redirect = $this->run_action( $this->page(), 'flush_entry' );

		$this->assertSame(
			Admin_Url::page(
				'rfrac-caches',
				array(
					's'       => 'posts',
					'view'    => 'active',
					'orderby' => 'hits',
					'order'   => 'asc',
				)
			),
			$redirect->location
		);
	}

	public function test_a_row_action_from_the_details_view_returns_to_the_list(): void {
		$this->as_admin();
		$id = Rfrac_Entry_Fixtures::insert_entry();
		$this->set_url_request( Admin_Url::action( 'delete_entry', (string) $id ) );
		$_SERVER['HTTP_REFERER'] = Admin_Url::page(
			'rfrac-caches',
			array(
				'action' => 'details',
				'entry'  => $id,
			)
		);

		$this->assertSame( Admin_Url::page( 'rfrac-caches' ), $this->run_action( $this->page(), 'delete_entry' )->location );
	}

	public function test_flush_entry_by_a_subscriber_dies_and_changes_nothing(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry();
		$this->as_admin();
		$url = Admin_Url::action( 'flush_entry', (string) $id );
		$this->as_subscriber();
		$this->set_url_request( $url );
		try {
			$this->run_action( $this->page(), 'flush_entry' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ) );
	}

	public function test_a_hostile_target_is_not_an_entry(): void {
		$this->as_admin();
		$id = Rfrac_Entry_Fixtures::insert_entry();
		foreach ( array( $id . ' OR 1=1', '-' . $id, array( (string) $id ) ) as $target ) {
			$key = is_array( $target ) ? '' : sanitize_key( $target );
			$this->set_request(
				array(
					'action'   => 'rfrac_delete_entry',
					'target'   => $target,
					'_wpnonce' => wp_create_nonce( 'rfrac_delete_entry' . ( '' !== $key ? '_' . $key : '' ) ),
				)
			);
			try {
				$this->run_action( $this->page(), 'delete_entry' );
			} catch ( WPDieException $e ) {
				$this->addToAssertionCount( 1 );
			}
			$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ) );
		}
	}

	public function test_the_details_view_acts_through_post_forms(): void {
		$this->as_admin();
		$id = Rfrac_Entry_Fixtures::insert_entry();
		$this->set_request(
			array(
				'action' => 'details',
				'entry'  => (string) $id,
			)
		);
		$html = $this->render( $this->page() );

		$this->assertSame( 2, substr_count( $html, '<form method="post"' ) );
		$this->assertStringNotContainsString( 'admin-post.php?action=', $html, 'No state-changing links.' );
		$this->assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( 'rfrac_delete_entry_' . $id ) . '"', $html );
	}

	public function test_row_actions_without_a_nonce_die_and_change_nothing(): void {
		$this->as_admin();
		$id = Rfrac_Entry_Fixtures::insert_entry();
		foreach ( array( 'flush_entry', 'delete_entry' ) as $action ) {
			$this->set_request( array( 'target' => (string) $id ) );
			try {
				$this->run_action( $this->page(), $action );
				$this->fail( 'Expected wp_die.' );
			} catch ( WPDieException $e ) {
				$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ) );
			}
		}
	}

	public function test_row_actions_by_a_subscriber_die_and_change_nothing(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry();
		$this->as_admin();
		$url = Admin_Url::action( 'delete_entry', (string) $id );
		$this->as_subscriber();
		$this->set_url_request( $url );
		try {
			$this->run_action( $this->page(), 'delete_entry' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ) );
		}
	}

	public function test_a_nonce_made_for_entry_5_is_rejected_for_entry_6(): void {
		$this->as_admin();
		$five = Rfrac_Entry_Fixtures::insert_entry();
		$six  = Rfrac_Entry_Fixtures::insert_entry();
		$url  = Admin_Url::action( 'flush_entry', (string) $five );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$query['target'] = (string) $six;
		$this->set_request( $query );
		try {
			$this->run_action( $this->page(), 'flush_entry' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $six ) );
			$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $five ) );
		}
	}

	/**
	 * Use the query of an action URL as the request.
	 */
	private function set_url_request( string $url ): void {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->set_request( $query );
	}

	// ---------- Details ----------

	public function test_details_escape_the_request_headers_tags_and_payload(): void {
		$this->as_admin();
		$id = Rfrac_Entry_Fixtures::insert_entry(
			array(
				'request' => '/wp/v2/posts?x=<script>alert(1)</script>',
				'payload' => '{"content":"</pre><script>alert(2)</script>","link":"https://example.com/a/b"}',
				'headers' => '{"X-Evil":"</pre><script>alert(3)</script>"}',
			),
			array( 'post:<b>1' )
		);
		$this->set_request(
			array(
				'action' => 'details',
				'entry'  => (string) $id,
			)
		);
		$html = $this->render( $this->page() );

		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringContainsString( '&lt;/pre&gt;&lt;script&gt;alert(2)', $html );
		$this->assertStringContainsString( '&lt;/pre&gt;&lt;script&gt;alert(3)', $html );
		$this->assertStringContainsString( 'post:&lt;b&gt;1', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'https://example.com/a/b', $html, 'Slashes stay unescaped in the pretty payload.' );
		$this->assertStringContainsString( '<pre class="rfrac-pre">', $html );
		$this->assertStringContainsString( 'rfrac_flush_entry', $html );
		$this->assertStringContainsString( 'rfrac_delete_entry', $html );
	}

	public function test_details_of_a_flushed_entry_have_no_flush_button(): void {
		$this->as_admin();
		$id = Rfrac_Entry_Fixtures::insert_entry( array( 'state' => 2 ) );
		$this->set_request(
			array(
				'action' => 'details',
				'entry'  => (string) $id,
			)
		);
		$html = $this->render( $this->page() );
		$this->assertStringNotContainsString( 'rfrac_flush_entry', $html );
		$this->assertStringContainsString( 'rfrac_delete_entry', $html );
	}

	public function test_a_missing_entry_says_so(): void {
		$this->as_admin();
		$this->set_request(
			array(
				'action' => 'details',
				'entry'  => '999999',
			)
		);
		$this->assertStringContainsString( 'Cache entry not found.', $this->render( $this->page() ) );
	}

	public function test_an_evicted_payload_is_reported(): void {
		$this->as_admin();
		$id = Rfrac_Entry_Fixtures::insert_entry( array( 'payload' => null ) );
		$this->set_request(
			array(
				'action' => 'details',
				'entry'  => (string) $id,
			)
		);
		$this->assertStringContainsString( 'Payload not available (evicted from object cache).', $this->render( $this->page() ) );
	}

	public function test_a_huge_payload_is_truncated_with_a_notice(): void {
		$this->as_admin();
		$id = Rfrac_Entry_Fixtures::insert_entry( array( 'payload' => str_repeat( 'a', Caches_Page::MAX_PAYLOAD_BYTES + 5000 ) ) );
		$this->set_request(
			array(
				'action' => 'details',
				'entry'  => (string) $id,
			)
		);
		$html = $this->render( $this->page() );
		$this->assertStringContainsString( 'only the first', $html );
		$this->assertLessThan( Caches_Page::MAX_PAYLOAD_BYTES + 20000, strlen( $html ) );
	}

	public function test_a_payload_that_is_not_json_is_shown_as_is_escaped(): void {
		$this->as_admin();
		$id = Rfrac_Entry_Fixtures::insert_entry( array( 'payload' => '<b>not json</b>' ) );
		$this->set_request(
			array(
				'action' => 'details',
				'entry'  => (string) $id,
			)
		);
		$html = $this->render( $this->page() );
		$this->assertStringContainsString( '&lt;b&gt;not json&lt;/b&gt;', $html );
	}

	public function test_the_list_renders_inside_a_get_form_with_the_page_field(): void {
		$this->as_admin();
		Rfrac_Entry_Fixtures::insert_entry();
		$this->set_request( array( 'view' => 'active' ) );
		$html = $this->render( $this->page() );
		$this->assertStringContainsString( '<form method="get"', $html );
		$this->assertStringContainsString( 'name="page" value="rfrac-caches"', $html );
		$this->assertStringContainsString( 'name="view" value="active"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
	}
}
