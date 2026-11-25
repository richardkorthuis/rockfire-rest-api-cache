<?php
/**
 * Tests for the "Flush REST cache" admin-bar node.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Admin;
use Rockfire\Rest_Api_Cache\Admin\Admin_Bar;
use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Admin\Capability;
use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Admin\Admin_Bar
 */
class Test_Admin_Bar extends Rfrac_Test_Case {

	use Rfrac_Admin_Test_Helpers;

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$this->reset_style();
	}

	public function tear_down() {
		$this->reset_admin_request();
		$this->reset_style();
		parent::tear_down();
	}

	private function reset_style(): void {
		if ( isset( wp_styles()->registered['admin-bar'] ) ) {
			unset( wp_styles()->registered['admin-bar']->extra['after'] );
		}
	}

	private function icon_css(): array {
		$after = wp_styles()->get_data( 'admin-bar', 'after' );
		return array_values( array_filter( is_array( $after ) ? $after : array(), static fn( $css ) => str_contains( (string) $css, 'wp-admin-bar-rfrac-flush' ) ) );
	}

	/**
	 * Build an admin bar with the node added the way WordPress does.
	 */
	private function bar(): WP_Admin_Bar {
		$bar = new WP_Admin_Bar();
		( new Admin_Bar( new Capability() ) )->add_node( $bar );
		return $bar;
	}

	public function test_register_hooks_the_node_at_priority_100_and_the_style_on_admin_bar_init(): void {
		$admin_bar = new Admin_Bar( new Capability() );
		$admin_bar->register();
		$this->assertSame( 100, has_action( 'admin_bar_menu', array( $admin_bar, 'add_node' ) ) );
		$this->assertSame( 10, has_action( 'admin_bar_init', array( $admin_bar, 'add_style' ) ) );
	}

	public function test_node_is_present_for_an_admin_with_a_nonced_flush_all_link(): void {
		$this->as_admin();
		$node = $this->bar()->get_node( Admin_Bar::NODE );

		$this->assertNotNull( $node );
		$this->assertStringContainsString( 'Flush REST cache', $node->title );
		$this->assertStringStartsWith( admin_url( 'admin-post.php' ), $node->href );
		parse_str( (string) wp_parse_url( $node->href, PHP_URL_QUERY ), $query );
		$this->assertSame( 'rfrac_flush_all', $query['action'] );
		$this->assertSame( 1, wp_verify_nonce( $query['_wpnonce'], 'rfrac_flush_all' ) );
		$this->assertCount( 1, $this->icon_css(), 'Icon CSS added once with the node.' );
	}

	public function test_node_is_absent_for_a_subscriber(): void {
		$this->as_subscriber();
		$this->assertNull( $this->bar()->get_node( Admin_Bar::NODE ) );
		( new Admin_Bar( new Capability() ) )->add_style();
		$this->assertSame( array(), $this->icon_css() );
	}

	public function test_node_is_absent_for_anonymous_visitors(): void {
		wp_set_current_user( 0 );
		$this->assertNull( $this->bar()->get_node( Admin_Bar::NODE ) );
	}

	public function test_node_is_absent_when_the_filter_returns_false_or_a_non_boolean(): void {
		$this->as_admin();
		add_filter( 'rfrac/show_admin_bar_flush', '__return_false' );
		$this->assertNull( $this->bar()->get_node( Admin_Bar::NODE ) );
		$this->assertSame( array(), $this->icon_css() );

		remove_all_filters( 'rfrac/show_admin_bar_flush' );
		add_filter( 'rfrac/show_admin_bar_flush', static fn() => 'yes' );
		$this->assertNull( $this->bar()->get_node( Admin_Bar::NODE ) );
	}

	public function test_node_follows_the_filtered_capability(): void {
		$this->as_role( 'editor' );
		$this->assertNull( $this->bar()->get_node( Admin_Bar::NODE ) );
		add_filter( 'rfrac/settings_capability', static fn() => 'edit_posts' );
		$this->assertNotNull( $this->bar()->get_node( Admin_Bar::NODE ) );
	}

	public function test_style_is_added_once_on_admin_bar_init(): void {
		$this->as_admin();
		$admin_bar = new Admin_Bar( new Capability() );
		$admin_bar->add_style();
		$admin_bar->add_style();
		$this->assertCount( 1, $this->icon_css() );
	}

	public function test_clicking_the_node_flushes_everything_and_shows_the_notice_once(): void {
		$ids     = array( Rfrac_Entry_Fixtures::insert_entry(), Rfrac_Entry_Fixtures::insert_entry(), Rfrac_Entry_Fixtures::insert_entry() );
		$user_id = $this->as_admin();
		$plugin  = Plugin::instance();
		( new Admin( new Capability(), new Notices(), $plugin->status_service(), $plugin->cache_service(), array() ) )->register();

		parse_str( (string) wp_parse_url( $this->bar()->get_node( Admin_Bar::NODE )->href, PHP_URL_QUERY ), $query );
		$this->set_request( $query );
		$_SERVER['HTTP_REFERER'] = home_url( '/sample-page/' );
		$this->intercept_redirect();
		try {
			do_action( 'admin_post_rfrac_flush_all' );
			$this->fail( 'Expected a redirect.' );
		} catch ( Redirect_Intercepted $redirect ) {
			$this->assertSame( 303, $redirect->status );
			$this->assertSame( Admin_Url::page( Admin::TOOLS_SLUG ), $redirect->location, 'From the front end to the Tools page, where the notice shows right away.' );
		}

		foreach ( $ids as $id ) {
			$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
		}
		ob_start();
		( new Notices() )->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Flushed 3 cached responses.', $html );
		$this->assertSame( '', get_user_meta( $user_id, Notices::meta_key(), true ) );
	}
}
