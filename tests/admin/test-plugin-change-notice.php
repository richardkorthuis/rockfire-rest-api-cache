<?php
/**
 * Tests for the plugin-change notice: Flush all and Dismiss, cleared by any full flush.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Admin;
use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Admin\Capability;
use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Services\Plugin_Change;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Admin\Admin
 * @covers \Rockfire\Rest_Api_Cache\Services\Plugin_Change
 */
class Test_Plugin_Change_Notice extends Rfrac_Test_Case {

	use Rfrac_Admin_Test_Helpers;

	/**
	 * Marker of the container.
	 *
	 * @var Plugin_Change
	 */
	private Plugin_Change $change;

	/**
	 * Container under test.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	public function set_up() {
		parent::set_up();
		global $wpdb;
		foreach ( array( Names::OPTION_FLUSH_EPOCH, Names::OPTION_FLUSH_ALL_EPOCH ) as $option ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		delete_option( Names::OPTION_PLUGIN_CHANGED );
		$this->plugin = new Plugin();
		$this->change = $this->plugin->plugin_change();
		$this->on_screen( 'plugins' );
	}

	public function tear_down() {
		$this->reset_admin_request();
		unset( $GLOBALS['current_screen'], $GLOBALS['hook_suffix'] );
		parent::tear_down();
	}

	/**
	 * Make a screen the current one (the screen id decides where the notice prints).
	 */
	private function on_screen( string $screen_id ): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		set_current_screen( $screen_id );
	}

	/**
	 * Run a callback and return its output.
	 */
	private function output( callable $callback ): string {
		ob_start();
		try {
			$callback();
		} finally {
			$output = (string) ob_get_clean();
		}
		return $output;
	}

	/**
	 * The notice as the admin of the container prints it.
	 */
	private function notice(): string {
		return $this->output( fn() => $this->plugin->admin()->render_plugin_change_notice() );
	}

	/**
	 * Run an admin-post action and return the intercepted redirect.
	 */
	private function run_action( string $action ): Redirect_Intercepted {
		$this->intercept_redirect();
		try {
			do_action( 'admin_post_rfrac_' . $action );
		} catch ( Redirect_Intercepted $redirect ) {
			return $redirect;
		}
		$this->fail( 'Expected a redirect.' );
	}

	public function test_a_pending_marker_prints_a_flush_all_form_and_a_dismiss_form_for_an_admin(): void {
		$this->as_admin();
		$this->change->mark();

		$html = $this->notice();

		$this->assertStringContainsString( 'notice notice-warning rfrac-notice rfrac-plugin-change-notice', $html );
		$this->assertStringContainsString( 'Cached responses may be outdated', $html );
		$forms = array_filter( explode( '</form>', $html ), static fn( string $part ): bool => str_contains( $part, '<form' ) );
		$this->assertCount( 2, $forms );
		$forms = array_values( $forms );

		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $forms[0] );
		$this->assertStringContainsString( 'method="post"', $forms[0] );
		$this->assertStringContainsString( 'name="action" value="rfrac_flush_all"', $forms[0] );
		$this->assertSame( 1, preg_match( '/name="_wpnonce" value="([^"]+)"/', $forms[0], $m ) );
		$this->assertSame( 1, wp_verify_nonce( $m[1], 'rfrac_flush_all' ) );
		$this->assertStringContainsString( 'button-primary', $forms[0] );
		$this->assertStringContainsString( '>Flush all</button>', $forms[0] );

		$this->assertStringContainsString( 'name="action" value="rfrac_dismiss_plugin_change"', $forms[1] );
		$this->assertSame( 1, preg_match( '/name="_wpnonce" value="([^"]+)"/', $forms[1], $m ) );
		$this->assertSame( 1, wp_verify_nonce( $m[1], 'rfrac_dismiss_plugin_change' ) );
		$this->assertStringContainsString( '>Dismiss</button>', $forms[1] );
	}

	public function test_the_notice_shows_on_the_plugins_screen_and_the_plugin_pages_only(): void {
		$this->as_admin();
		$this->change->mark();

		$this->on_screen( 'plugins' );
		$this->assertStringContainsString( 'rfrac-plugin-change-notice', $this->notice() );

		$this->on_screen( 'rest-api-cache_page_rfrac-tools' );
		$this->assertStringContainsString( 'rfrac-plugin-change-notice', $this->notice() );

		$this->on_screen( 'dashboard' );
		$this->assertSame( '', $this->notice() );

		$this->on_screen( 'edit-post' );
		$this->assertSame( '', $this->notice() );

		unset( $GLOBALS['current_screen'] );
		$this->assertSame( '', $this->notice(), 'No screen, no notice.' );

		$this->assertTrue( $this->change->pending(), 'The marker stays until a flush or a dismissal.' );
	}

	public function test_readme_describes_where_the_notice_shows(): void {
		$readme = (string) file_get_contents( RFRAC_DIR . 'readme.txt' );
		$faq    = (string) strstr( (string) strstr( $readme, '= Why does a notice appear after I switch a plugin on or off? =' ), '= Does it work with a persistent object cache? =', true );

		$this->assertNotSame( '', $faq, 'The readme has the plugin-change FAQ.' );
		$this->assertStringContainsString( 'on the Plugins screen and on the plugin\'s own admin pages', $faq );
		$this->assertStringNotContainsString( 'on the admin screens of the site', $faq );
	}

	public function test_the_notice_is_empty_for_a_subscriber_a_visitor_and_without_a_marker(): void {
		$this->as_admin();
		$this->assertSame( '', $this->notice(), 'No marker.' );

		$this->change->mark();
		$this->as_subscriber();
		$this->assertSame( '', $this->notice(), 'A subscriber.' );

		wp_set_current_user( 0 );
		$this->assertSame( '', $this->notice(), 'A visitor.' );
	}

	public function test_the_notice_follows_the_settings_capability(): void {
		$this->as_role( 'editor' );
		$this->change->mark();
		$this->assertSame( '', $this->notice() );

		add_filter( 'rfrac/settings_capability', static fn() => 'edit_pages' );
		$this->assertStringContainsString( 'rfrac-plugin-change-notice', $this->notice() );
	}

	public function test_rendering_the_notice_writes_nothing(): void {
		$this->as_admin();
		$this->change->mark();
		$admin    = $this->plugin->admin();
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();

		$this->output( fn() => $admin->render_plugin_change_notice() );

		$recorder->stop();
		$this->assertSame( array(), $recorder->matching( '/^\s*(INSERT|UPDATE|REPLACE|DELETE)\b/i' ) );
		$this->assertTrue( $this->change->pending(), 'Rendering does not clear the marker.' );
	}

	public function test_the_admin_defaults_to_the_marker_of_this_plugin(): void {
		$this->as_admin();
		$this->change->mark();
		$plugin = Plugin::instance();
		$admin  = new Admin( new Capability(), new Notices(), $plugin->status_service(), $plugin->cache_service(), array() );

		$this->assertStringContainsString( 'rfrac-plugin-change-notice', $this->output( fn() => $admin->render_plugin_change_notice() ) );
	}

	public function test_a_full_flush_removes_the_notice_even_on_an_empty_cache(): void {
		$this->as_admin();
		$this->change->mark();
		$this->assertNotSame( '', $this->notice() );

		rfrac_flush_all();

		$this->assertSame( '', $this->notice() );
	}

	public function test_a_flush_by_tag_or_a_post_save_commit_keeps_the_notice(): void {
		$this->as_admin();
		$this->plugin->register_engine_hooks();
		$this->change->mark();

		rfrac_flush_tag( 'post:1' );
		$this->assertNotSame( '', $this->notice() );

		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->plugin->invalidator()->commit();
		$this->assertNotSame( '', $this->notice() );
	}

	public function test_the_flush_all_action_of_the_admin_removes_the_notice(): void {
		$this->as_admin();
		$this->change->mark();
		$admin = $this->plugin->admin();
		$this->assertNotSame( '', $this->notice() );

		$admin->flush_all();

		$this->assertSame( '', $this->notice() );
	}

	public function test_the_dismiss_handler_deletes_the_marker_and_redirects_back(): void {
		$this->as_admin();
		$this->change->mark();
		$admin = $this->plugin->admin();
		$admin->register();
		$this->assertNotFalse( has_action( 'admin_post_rfrac_dismiss_plugin_change' ) );
		$this->assertFalse( has_action( 'admin_post_nopriv_rfrac_dismiss_plugin_change' ) );

		$this->set_request( array( '_wpnonce' => wp_create_nonce( 'rfrac_dismiss_plugin_change' ) ) );
		$_SERVER['HTTP_REFERER'] = admin_url( 'plugins.php' );
		$redirect                = $this->run_action( 'dismiss_plugin_change' );

		$this->assertSame( 303, $redirect->status );
		$this->assertSame( admin_url( 'plugins.php' ), $redirect->location );
		$this->assertFalse( get_option( Names::OPTION_PLUGIN_CHANGED ) );
		$this->assertSame( '', $this->notice() );
	}

	public function test_the_dismiss_handler_returns_to_the_tools_page_without_an_admin_referer(): void {
		$this->as_admin();
		$this->change->mark();
		$this->assertSame( Admin_Url::page( Admin::TOOLS_SLUG ), $this->plugin->admin()->dismiss_plugin_change() );
		$this->assertFalse( $this->change->pending() );

		$this->change->mark();
		$_SERVER['HTTP_REFERER'] = 'https://evil.example/';
		$this->assertSame( Admin_Url::page( Admin::TOOLS_SLUG ), $this->plugin->admin()->dismiss_plugin_change() );
	}

	public function test_the_dismiss_handler_does_not_return_to_a_same_host_front_end_referer(): void {
		$this->as_admin();
		$this->change->mark();
		// Same host, so core's referer validation accepts it: only the admin-prefix check keeps the redirect off the front end.
		$_SERVER['HTTP_REFERER'] = home_url( '/sample-page/' );

		$this->assertSame( Admin_Url::page( Admin::TOOLS_SLUG ), $this->plugin->admin()->dismiss_plugin_change() );
		$this->assertFalse( $this->change->pending() );
	}

	/**
	 * @dataProvider bad_dismiss_requests
	 */
	public function test_the_dismiss_handler_refuses_a_missing_or_foreign_nonce_and_a_subscriber( ?string $nonce_action, string $role ): void {
		$this->as_role( $role );
		$this->change->mark();
		$this->plugin->admin()->register();
		$this->set_request( null === $nonce_action ? array() : array( '_wpnonce' => wp_create_nonce( $nonce_action ) ) );

		try {
			do_action( 'admin_post_rfrac_dismiss_plugin_change' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		}
		$this->assertTrue( $this->change->pending(), 'Nothing was dismissed.' );
	}

	public static function bad_dismiss_requests(): array {
		return array(
			'missing nonce'    => array( null, 'administrator' ),
			'another action'   => array( 'rfrac_flush_all', 'administrator' ),
			'unprefixed'       => array( 'dismiss_plugin_change', 'administrator' ),
			'a subscriber'     => array( 'rfrac_dismiss_plugin_change', 'subscriber' ),
		);
	}

	public function test_the_admin_hooks_the_notice_on_admin_notices_only(): void {
		$admin = $this->plugin->admin();
		$admin->register();

		$this->assertSame( 10, has_action( 'admin_notices', array( $admin, 'render_plugin_change_notice' ) ) );
		$this->assertFalse( has_action( 'network_admin_notices', array( $admin, 'render_plugin_change_notice' ) ) );
		$handlers = $admin->action_handlers();
		$this->assertArrayHasKey( Admin::DISMISS_PLUGIN_CHANGE_ACTION, $handlers );
		$this->assertSame( 'dismiss_plugin_change', Admin::DISMISS_PLUGIN_CHANGE_ACTION );
	}

	public function test_an_activation_queues_no_flash_and_the_admin_has_no_activation_hook(): void {
		global $wp_filter;
		$user_id = $this->as_admin();
		$this->plugin->register_engine_hooks();
		$this->plugin->admin()->register();

		do_action( 'activated_plugin', 'other/other.php', false );
		do_action( 'deactivated_plugin', 'other/other.php', false );

		$this->assertSame( array(), ( new Notices() )->queued( $user_id ), 'No flash is queued.' );
		$this->assertTrue( $this->change->pending(), 'The marker is.' );
		foreach ( array( 'activated_plugin', 'deactivated_plugin' ) as $hook ) {
			foreach ( isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array() as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					$this->assertFalse( is_array( $function ) && $function[0] instanceof Admin, "The admin hooks nothing on $hook." );
				}
			}
		}
		$this->assertFalse( method_exists( Admin::class, 'on_plugin_activated' ) );
		$this->assertFalse( method_exists( Admin::class, 'on_plugin_deactivated' ) );
	}

	public function test_the_marker_survives_the_requests_and_shows_to_every_admin_until_a_flush(): void {
		$this->as_admin();
		$this->change->mark();
		$this->assertNotSame( '', $this->notice() );
		$this->assertNotSame( '', $this->notice(), 'Not shown once: it stays until a flush or a dismissal.' );

		$this->as_admin();
		$this->assertNotSame( '', $this->notice(), 'Every admin sees it.' );

		( new Repository() )->flush_all( gmdate( 'Y-m-d H:i:s' ) );
		$this->assertSame( '', $this->notice() );
	}
}
