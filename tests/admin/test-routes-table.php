<?php
/**
 * Tests for the routes list table.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Tables\Routes_Table;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Route_Discovery;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Services\Route_Service;
use Rockfire\Rest_Api_Cache\Services\Settings_Service;

/**
 * @covers \Rockfire\Rest_Api_Cache\Admin\Tables\Routes_Table
 */
class Test_Routes_Table extends Rfrac_Test_Case {

	use Rfrac_Admin_Test_Helpers;

	/**
	 * Route service.
	 *
	 * @var Route_Service
	 */
	private Route_Service $routes;

	public function set_up() {
		parent::set_up();
		$this->new_rest_server(
			static function () {
				register_rest_route(
					'myplugin/v1',
					'/items',
					array(
						'methods'             => 'GET',
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
						'args'                => array( 'page' => array() ),
					)
				);
				register_rest_route(
					'myplugin/v1',
					'/lookup',
					array(
						'methods'             => 'POST',
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
					)
				);
				register_rest_route(
					'myplugin/v1',
					'/remove',
					array(
						'methods'             => 'DELETE',
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
					)
				);
			}
		);
		update_option( Names::OPTION_SETTINGS, array(), false );
		$config       = new Config();
		$this->routes = new Route_Service( $config, new Route_Discovery() );
		$this->as_admin();
		set_current_screen( 'toplevel_page_rfrac-routes' );
	}

	public function tear_down() {
		$this->reset_admin_request();
		parent::tear_down();
	}

	private function table(): Routes_Table {
		return new Routes_Table( $this->routes, new Settings_Service( new Config() ) );
	}

	private function html( array $get = array() ): string {
		$this->set_request( $get + array( 'page' => 'rfrac-routes' ) );
		$table = $this->table();
		$table->prepare_items();
		ob_start();
		$table->display();
		return (string) ob_get_clean();
	}

	public function test_uses_the_documented_singular_and_plural(): void {
		$this->assertSame( 'rfrac_route', $this->table()->_args['singular'] );
		$this->assertSame( 'rfrac_routes', $this->table()->_args['plural'] );
	}

	public function test_the_columns_and_bulk_nonce(): void {
		$html = $this->html();
		foreach ( array( 'Route', 'Namespace', 'Status', 'TTL', 'Parameters', 'Vary', 'Invalidation' ) as $heading ) {
			$this->assertStringContainsString( '>' . $heading, $html, $heading );
		}
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'value="enable"', $html );
		$this->assertStringContainsString( 'value="exclude"', $html );
		$this->assertStringContainsString( 'value="reset"', $html );
	}

	public function test_denied_route_is_denied_without_a_checkbox(): void {
		$html = $this->html( array( 's' => 'users/me' ) );
		$this->assertStringContainsString( '>Denied<', $html );
		$this->assertStringNotContainsString( '>Locked<', $html, 'Locked is reserved for the routes of the Locked view (a constant).' );
		$this->assertStringNotContainsString( 'name="route[]"', $html );
	}

	public function test_a_default_route_has_a_checkbox_and_the_default_marker(): void {
		$html = $this->html( array( 's' => 'myplugin/v1/items' ) );
		$this->assertStringContainsString( 'name="route[]"', $html );
		$this->assertStringContainsString( 'value="' . Route_Service::id_for( '/myplugin/v1/items' ) . '"', $html );
		$this->assertStringContainsString( '(site default)', $html );
		$this->assertStringContainsString( 'Registered args', $html );
		$this->assertStringContainsString( 'Disabled', $html );
	}

	public function test_namespace_and_search_filter_the_rows(): void {
		$html = $this->html( array( 'namespace' => 'wp/v2', 's' => 'posts' ) );
		$this->assertStringContainsString( '/wp/v2/posts', $html );
		$this->assertStringNotContainsString( "/myplugin/v1/items", $html );
		$this->assertStringNotContainsString( '/wp/v2/users', $html );
	}

	public function test_row_actions_flush_only_for_enabled_and_reset_only_for_overrides(): void {
		$html = $this->html( array( 's' => 'myplugin/v1/items' ) );
		$this->assertStringContainsString( 'View entries', $html );
		$this->assertStringContainsString( 'page=rfrac-caches&#038;route_id=', $html );
		$this->assertStringNotContainsString( '>Flush<', $html );
		$this->assertStringNotContainsString( '>Reset<', $html );

		$this->routes->save( Route_Service::id_for( '/myplugin/v1/items' ), array( 'status' => 'enabled' ) );
		$html = $this->html( array( 's' => 'myplugin/v1/items' ) );
		$this->assertStringContainsString( 'action=rfrac_flush_route_cache', $html );
		$this->assertStringContainsString( '>Reset<', $html );
	}

	public function test_a_post_only_route_is_listed_and_its_methods_are_shown_once_chosen(): void {
		$lookup = Route_Service::id_for( '/myplugin/v1/lookup' );

		$html = $this->html( array( 's' => 'myplugin/v1/lookup' ) );
		$this->assertStringContainsString( '/myplugin/v1/lookup', $html );
		$this->assertStringContainsString( 'name="route[]"', $html );
		$this->assertStringNotContainsString( 'Methods:', $html, 'A GET-only rule shows no methods.' );

		$this->assertNotWPError( $this->routes->save( $lookup, array( 'status' => 'enabled', 'methods' => array( 'POST' ) ) ) );
		$html = $this->html( array( 's' => 'myplugin/v1/lookup' ) );
		$this->assertStringContainsString( 'Methods: POST', $html );
		$this->assertStringContainsString( '>Enabled<', $html );
	}

	public function test_a_rule_with_get_and_post_shows_both_methods(): void {
		$this->assertTrue( ( new Config() )->save_route_rules( array( '/myplugin/v1/lookup' => array( 'status' => 'enabled', 'methods' => array( 'GET', 'POST' ) ) ) )->is_valid() );
		$this->routes->flush();

		$html = $this->html( array( 's' => 'myplugin/v1/lookup' ) );
		$this->assertStringContainsString( '/myplugin/v1/lookup', $html );
		$this->assertStringContainsString( 'Methods: GET, POST', $html );
	}

	public function test_a_delete_only_route_is_not_listed(): void {
		$this->assertStringNotContainsString( '/myplugin/v1/remove', $this->html( array( 's' => 'myplugin/v1/remove' ) ) );
	}

	public function test_views_have_counts_and_the_current_one_is_marked(): void {
		$this->set_request( array( 'page' => 'rfrac-routes', 'view' => 'locked' ) );
		ob_start();
		$this->table()->views();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'class="current"', $html );
		$this->assertStringContainsString( 'Not registered', $html );
	}

	public function test_hidden_columns_of_the_screen_are_respected(): void {
		$GLOBALS['hook_suffix'] = 'toplevel_page_rfrac-routes'; // As on the real page: the table uses the current screen.
		update_user_option( get_current_user_id(), 'manage' . get_current_screen()->id . 'columnshidden', array( 'vary', 'invalidation' ) );
		try {
			$html = $this->html();
		} finally {
			unset( $GLOBALS['hook_suffix'] );
		}
		$this->assertMatchesRegularExpression( '/<th[^>]*id=.vary.[^>]*class=.[^"\']*hidden/', $html );
		$this->assertMatchesRegularExpression( '/<th[^>]*id=.invalidation.[^>]*class=.[^"\']*hidden/', $html );
		$this->assertDoesNotMatchRegularExpression( '/<th[^>]*id=.ttl.[^>]*class=.[^"\']*hidden/', $html );
	}

	public function test_page_size_is_a_clamped_screen_option(): void {
		$table = $this->table();
		$this->assertSame( 50, $table->per_page() );
		update_user_option( get_current_user_id(), Routes_Table::PER_PAGE_OPTION, 9999 );
		$this->assertSame( 200, $table->per_page() );
		update_user_option( get_current_user_id(), Routes_Table::PER_PAGE_OPTION, 5 );
		$this->assertSame( 5, $table->per_page() );
		$table->prepare_items();
		$this->assertLessThanOrEqual( 5, count( $table->items ) );
	}
}
