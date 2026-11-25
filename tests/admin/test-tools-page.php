<?php
/**
 * Tests for the Tools page.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Action_Handler;
use Rockfire\Rest_Api_Cache\Admin\Admin;
use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Admin\Capability;
use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Admin\Pages\Tools_Page;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Install\Mu_Installer;
use Rockfire\Rest_Api_Cache\Maintenance\Lock;
use Rockfire\Rest_Api_Cache\Maintenance\Maintenance;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Schema\Schema;
use Rockfire\Rest_Api_Cache\Services\Status_Service;

/**
 * @covers \Rockfire\Rest_Api_Cache\Admin\Pages\Tools_Page
 */
class Test_Tools_Page extends Rfrac_Test_Case {

	use Rfrac_Admin_Test_Helpers;

	/**
	 * Temporary must-use directory.
	 *
	 * @var string
	 */
	private string $mu_dir;

	/**
	 * The page.
	 *
	 * @var Tools_Page
	 */
	private Tools_Page $page;

	public function set_up() {
		parent::set_up();
		$this->mu_dir = rtrim( WP_CONTENT_DIR, '/\\' ) . '/rfrac-tools-mu-' . bin2hex( random_bytes( 4 ) );
		delete_option( Mu_Installer::OPTION_NOTICE );
		delete_option( Maintenance::LAST_RUN_OPTION );
		delete_option( Lock::OPTION );
		$plugin     = Plugin::instance();
		$status     = new Status_Service(
			Config::instance(),
			static fn() => $plugin->store(),
			new Schema(),
			new Mu_Installer( $plugin->basename(), RFRAC_DIR . 'mu-plugin/rfrac-early-cache.php', RFRAC_VERSION, $this->mu_dir ),
			$plugin->cache_service()
		);
		$this->page = new Tools_Page( new Notices(), $plugin->cache_service(), $status, $plugin->route_service() );
		( new Admin( new Capability(), new Notices(), $status, $plugin->cache_service(), array( $this->page ) ) )->register();
		$this->as_admin();
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
		delete_option( Lock::OPTION );
		delete_option( Maintenance::LAST_RUN_OPTION );
		remove_all_filters( 'file_mod_allowed' );
		remove_all_filters( 'rfrac/settings_capability' );
		delete_option( Mu_Installer::OPTION_NOTICE );
		foreach ( array( 'flush_tag', 'flush_url', 'flush_route', 'run_maintenance', 'install_mu', 'flush_all' ) as $action ) {
			remove_all_actions( 'admin_post_rfrac_' . $action );
		}
		parent::tear_down();
	}

	/**
	 * Submit an action as a POST with a valid nonce; returns the redirect.
	 *
	 * @param string              $action Action name.
	 * @param array<string,mixed> $post   Extra POST fields.
	 * @param bool                $nonce  Whether to send a valid nonce.
	 */
	private function submit( string $action, array $post = array(), bool $nonce = true ): Redirect_Intercepted {
		$post['action']   = 'rfrac_' . $action;
		$post['_wpnonce'] = $nonce ? wp_create_nonce( Action_Handler::nonce_action( $action ) ) : 'bad';
		$this->set_request( array(), $post );
		$this->intercept_redirect();
		try {
			do_action( 'admin_post_rfrac_' . $action );
		} catch ( Redirect_Intercepted $redirect ) {
			return $redirect;
		}
		$this->fail( 'Expected a redirect.' );
	}

	/**
	 * Valid POST fields per action.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function valid_posts(): array {
		return array(
			'flush_tag'       => array( 'rfrac_tag' => 'post:12' ),
			'flush_url'       => array( 'rfrac_url' => rest_url( 'wp/v2/posts' ) ),
			'flush_route'     => array( 'target' => Plugin::instance()->route_service()->resolve( '/wp/v2/posts' )->id ),
			'run_maintenance' => array(),
			'install_mu'      => array(),
		);
	}

	/**
	 * The notices queued for the current user.
	 *
	 * @return list<array{type:string,message:string}>
	 */
	private function queued(): array {
		return ( new Notices() )->queued();
	}

	/**
	 * Output of the page.
	 */
	private function rendered(): string {
		ob_start();
		try {
			$this->page->render();
		} finally {
			$output = (string) ob_get_clean();
		}
		return $output;
	}

	public function test_slug_and_position(): void {
		$this->assertSame( 'rfrac-tools', $this->page->slug() );
		$this->assertSame( 40, $this->page->position() );
		$this->assertSame( array( 'flush_tag', 'flush_url', 'flush_route', 'run_maintenance', 'install_mu' ), array_keys( $this->page->actions() ) );
	}

	public function test_page_is_registered_in_the_plugin(): void {
		$slugs = array_map( static fn( $page ): string => $page->slug(), Plugin::instance()->admin_pages() );
		$this->assertContains( 'rfrac-tools', $slugs );
	}

	/**
	 * Assert that a rejected action changed nothing: no flush, no maintenance run, no must-use file, no notice.
	 *
	 * @param int    $id      Entry that must still be active.
	 * @param string $message Assertion message.
	 */
	private function assert_no_effect( int $id, string $message ): void {
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ), $message );
		$this->assertFalse( get_option( Maintenance::LAST_RUN_OPTION ), $message . ': no maintenance run.' );
		$this->assertFileDoesNotExist( $this->mu_dir . '/rfrac-early-cache.php', $message . ': no must-use file.' );
		$this->assertFalse( get_option( Mu_Installer::OPTION_NOTICE ), $message . ': no install attempt.' );
		$this->assertSame( array(), $this->queued(), $message );
	}

	public function test_subscribers_cannot_run_any_action(): void {
		foreach ( $this->valid_posts() as $action => $post ) {
			$this->as_subscriber();
			$id = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:12' ) );
			try {
				$this->submit( $action, $post );
				$this->fail( $action . ' should die.' );
			} catch ( WPDieException $e ) {
				$this->assertSame( 403, $e->getCode(), $action );
			}
			$this->assert_no_effect( $id, $action );
			$this->as_admin();
		}
	}

	public function test_bad_nonce_dies_without_side_effects(): void {
		foreach ( $this->valid_posts() as $action => $post ) {
			$id = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:12' ) );
			try {
				$this->submit( $action, $post, false );
				$this->fail( $action . ' should die.' );
			} catch ( WPDieException $e ) {
				$this->assertSame( 403, $e->getCode(), $action );
			}
			$this->assert_no_effect( $id, $action );
		}
	}

	public function test_a_nonce_of_one_action_is_rejected_for_another(): void {
		$actions = array_keys( $this->valid_posts() );
		foreach ( $actions as $i => $action ) {
			$other = $actions[ ( $i + 1 ) % count( $actions ) ];
			$id    = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:12' ) );
			$post  = $this->valid_posts()[ $action ];

			$post['action']   = 'rfrac_' . $action;
			$post['_wpnonce'] = wp_create_nonce( Action_Handler::nonce_action( $other ) );
			$this->set_request( array(), $post );
			$this->intercept_redirect();
			try {
				do_action( 'admin_post_rfrac_' . $action );
				$this->fail( $action . ' accepted the nonce of ' . $other );
			} catch ( WPDieException $e ) {
				$this->assertSame( 403, $e->getCode(), $action );
			}
			$this->assert_no_effect( $id, $action . ' with the nonce of ' . $other );
		}
	}

	public function test_an_anonymous_visitor_cannot_run_any_action(): void {
		$this->assertFalse( has_action( 'admin_post_nopriv_rfrac_install_mu' ) );
		foreach ( $this->valid_posts() as $action => $post ) {
			$this->assertFalse( has_action( 'admin_post_nopriv_rfrac_' . $action ), $action );
		}
		wp_set_current_user( 0 );
		$id = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:12' ) );
		try {
			$this->submit( 'install_mu' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assert_no_effect( $id, 'anonymous' );
	}

	public function test_every_action_redirects_303_to_the_tools_page(): void {
		foreach ( $this->valid_posts() as $action => $post ) {
			$redirect = $this->submit( $action, $post );
			$this->assertSame( 303, $redirect->status, $action );
			$this->assertStringContainsString( 'page=rfrac-tools', $redirect->location, $action );
		}
	}

	public function test_flush_tag_flushes_only_the_tagged_entries(): void {
		$twelve   = array();
		$thirteen = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$twelve[] = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:12' ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$thirteen[] = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:13' ) );
		}

		$redirect = $this->submit( 'flush_tag', array( 'rfrac_tag' => 'post:12' ) );

		$this->assertStringContainsString( 'page=rfrac-tools', $redirect->location );
		$this->assertSame( array( array( 'type' => 'success', 'message' => 'Flushed 5 cached responses.' ) ), $this->queued() );
		foreach ( $twelve as $id ) {
			$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
		}
		foreach ( $thirteen as $id ) {
			$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ) );
		}
	}

	public function test_flush_tag_rejects_route_tags(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'route:/wp/v2/posts' ) );

		$this->submit( 'flush_tag', array( 'rfrac_tag' => 'route:/wp/v2/posts' ) );

		$notices = $this->queued();
		$this->assertSame( 'error', $notices[0]['type'] );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ) );
	}

	public function test_flush_url_rejects_a_foreign_host_and_an_empty_url(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:12' ) );

		$this->submit( 'flush_url', array( 'rfrac_url' => 'https://evil.example.org/wp-json/wp/v2/posts' ) );
		$this->assertSame( 'error', $this->queued()[0]['type'] );
		delete_user_meta( get_current_user_id(), Notices::meta_key() );
		$this->submit( 'flush_url', array( 'rfrac_url' => 'not a url' ) );
		$this->assertSame( 'error', $this->queued()[0]['type'] );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ) );
	}

	public function test_flush_url_flushes_the_entries_of_the_url(): void {
		$url    = rest_url( 'wp/v2/posts' ) . '?page=7';
		$hasher = ( new ReflectionProperty( Plugin::instance()->invalidator(), 'url_hasher' ) )->getValue( Plugin::instance()->invalidator() );
		$id     = Rfrac_Entry_Fixtures::insert_entry(
			array(
				'request'  => '/wp/v2/posts?page=7',
				'url_hash' => $hasher( $url )[0],
			)
		);
		$other  = Rfrac_Entry_Fixtures::insert_entry( array( 'request' => '/wp/v2/posts?page=8' ) );

		$this->submit( 'flush_url', array( 'rfrac_url' => $url ) );

		$this->assertSame( array( array( 'type' => 'success', 'message' => 'Flushed 1 cached response.' ) ), $this->queued() );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $other ), 'Another URL is untouched.' );
	}

	public function test_flush_route_flushes_the_route_and_rejects_unknown_ids(): void {
		$posts = Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/posts' ) );
		$other = Rfrac_Entry_Fixtures::insert_entry( array( 'route' => '/wp/v2/pages', 'rule_key' => '/wp/v2/pages' ) );
		$view  = Plugin::instance()->route_service()->resolve( '/wp/v2/posts' );

		$this->submit( 'flush_route', array( 'target' => 'ffffffffffff' ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $posts ) );
		$this->assertSame( 'error', $this->queued()[0]['type'] );

		$this->submit( 'flush_route', array( 'target' => $view->id ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $posts ) );
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $other ) );
	}

	public function test_run_maintenance_reports_the_result(): void {
		$this->submit( 'run_maintenance' );

		$notices = $this->queued();
		$this->assertSame( 'success', $notices[0]['type'] );
		$this->assertMatchesRegularExpression( '/^Maintenance deleted \d+ stale entries and evicted \d+ entries; \d+ stale entries remain\.$/', $notices[0]['message'] );
		$this->assertIsArray( get_option( Maintenance::LAST_RUN_OPTION ) );
	}

	public function test_run_maintenance_while_another_run_holds_the_lock_is_a_warning(): void {
		$stale = Rfrac_Entry_Fixtures::insert_entry( array( 'state' => 2 ) );
		( new Lock() )->acquire( 300 );

		$this->submit( 'run_maintenance' );

		$this->assertSame( 'warning', $this->queued()[0]['type'] );
		$this->assertStringStartsWith( 'Maintenance is already running', $this->queued()[0]['message'] );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $stale ), 'Nothing was deleted.' );
	}

	public function test_install_mu_installs_the_file(): void {
		$redirect = $this->submit( 'install_mu' );

		$this->assertFileExists( $this->mu_dir . '/rfrac-early-cache.php' );
		$this->assertSame( 'success', $this->queued()[0]['type'] );
		$this->assertSame( Admin_Url::page( 'rfrac-tools' ), $redirect->location, 'Without an admin referer: back to the Tools page.' );
	}

	public function test_install_mu_returns_to_the_admin_screen_it_was_clicked_on(): void {
		$file  = $this->mu_dir . '/rfrac-early-cache.php';
		$cases = array(
			'the Plugins screen'         => array( admin_url( 'plugins.php' ), admin_url( 'plugins.php' ) ),
			'the network Plugins screen' => array( network_admin_url( 'plugins.php' ), network_admin_url( 'plugins.php' ) ),
			'a front-end page'           => array( home_url( '/sample-page/' ), Admin_Url::page( 'rfrac-tools' ) ),
		);

		foreach ( $cases as $label => list( $referer, $expected ) ) {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				clearstatcache( true, $file );
			}
			delete_user_meta( get_current_user_id(), Notices::meta_key() );
			$_SERVER['HTTP_REFERER'] = $referer;

			$redirect = $this->submit( 'install_mu' );

			$this->assertSame( 303, $redirect->status, $label );
			$this->assertSame( $expected, $redirect->location, $label );
			$this->assertFileExists( $file, $label );
			$this->assertSame( 'success', $this->queued()[0]['type'], $label );
		}
	}

	public function test_install_mu_returns_to_the_screen_named_by_the_path_only_referer_the_forms_post(): void {
		// A notice form posts `_wp_http_referer` as the host-less REQUEST_URI of its screen (wp_nonce_field()).
		$redirect = $this->submit( 'install_mu', array( '_wp_http_referer' => '/wp-admin/plugins.php' ) );

		$this->assertSame( 303, $redirect->status );
		$this->assertSame( admin_url( 'plugins.php' ), $redirect->location, 'Back to the Plugins screen, not to the Tools page.' );
		$this->assertFileExists( $this->mu_dir . '/rfrac-early-cache.php' );
		$this->assertSame( 'success', $this->queued()[0]['type'] );
	}

	public function test_install_mu_ignores_a_path_only_referer_outside_the_admin(): void {
		$referers = array( '/sample-page/', '/wp-login.php', '/wp-admin-other/plugins.php', '/wp-admin/../wp-login.php' );

		foreach ( $referers as $referer ) {
			$redirect = $this->submit( 'install_mu', array( '_wp_http_referer' => $referer ) );

			$this->assertSame( Admin_Url::page( 'rfrac-tools' ), $redirect->location, $referer );
		}
	}

	public function test_a_failed_install_mu_returns_to_the_screen_it_was_clicked_on_as_well(): void {
		add_filter( 'file_mod_allowed', '__return_false' );
		$_SERVER['HTTP_REFERER'] = admin_url( 'plugins.php' );

		$redirect = $this->submit( 'install_mu' );

		$this->assertSame( admin_url( 'plugins.php' ), $redirect->location );
		$this->assertFileDoesNotExist( $this->mu_dir . '/rfrac-early-cache.php' );
		$this->assertSame( 'error', $this->queued()[0]['type'] );
	}

	public function test_install_mu_needs_the_plugin_management_capability_not_only_the_plugin_capability(): void {
		// An editor gets the plugin's admin through the filter, but may not write a must-use plugin.
		add_filter( 'rfrac/settings_capability', static fn() => 'edit_others_posts' );
		$this->as_role( 'editor' );
		$id = Rfrac_Entry_Fixtures::insert_entry();

		try {
			$this->submit( 'install_mu' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assert_no_effect( $id, 'editor' );

		$html = $this->rendered();
		$this->assertStringNotContainsString( 'Install must-use file', $html, 'No button for a user who may not install.' );
		$this->assertStringContainsString( 'To install the must-use file manually', $html );
		$this->assertStringContainsString( 'Only a user who can manage plugins', $html );
	}

	public function test_install_mu_is_refused_when_file_mods_are_disallowed(): void {
		add_filter( 'file_mod_allowed', '__return_false' );

		$this->submit( 'install_mu' );

		$this->assertFileDoesNotExist( $this->mu_dir . '/rfrac-early-cache.php' );
		$this->assertSame( 'file_mods_disallowed', get_option( Mu_Installer::OPTION_NOTICE )['code'] );
		$this->assertSame(
			array(
				array(
					'type'    => 'error',
					'message' => 'The must-use file could not be installed: WordPress does not allow file changes on this site (DISALLOW_FILE_MODS).',
				),
			),
			$this->queued()
		);

		$html = $this->rendered();
		$this->assertStringNotContainsString( 'Install must-use file', $html );
		$this->assertStringContainsString( 'To install the must-use file manually', $html );
		$this->assertStringContainsString( 'DISALLOW_FILE_MODS', $html );
	}

	public function test_install_mu_reports_the_failure_reason(): void {
		$this->mu_dir = (string) tempnam( sys_get_temp_dir(), 'rfrac' ); // A file, so the directory cannot be created.
		$plugin       = Plugin::instance();
		$status       = new Status_Service(
			Config::instance(),
			static fn() => $plugin->store(),
			new Schema(),
			new Mu_Installer( $plugin->basename(), RFRAC_DIR . 'mu-plugin/rfrac-early-cache.php', RFRAC_VERSION, $this->mu_dir . '/sub' ),
			$plugin->cache_service()
		);
		$page         = new Tools_Page( new Notices(), $plugin->cache_service(), $status, $plugin->route_service() );

		$page->install_mu();

		$notices = $this->queued();
		$this->assertSame( 'error', $notices[0]['type'] );
		$this->assertStringStartsWith( 'The must-use file could not be installed', $notices[0]['message'] );
		unlink( $this->mu_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->mu_dir .= '-gone';
	}

	public function test_render_in_fallback_mode_shows_the_badge_and_escaped_paths(): void {
		$html = $this->rendered();

		$this->assertStringContainsString( 'rfrac-badge--warning">Fallback<', $html );
		$this->assertStringContainsString( esc_html( 'rfrac-early-cache.php' ), $html );
		$this->assertStringContainsString( 'Install must-use file', $html );
		$this->assertStringContainsString( 'data-rfrac-confirm', $html );
		$this->assertStringContainsString( 'post:123', $html );
		$this->assertStringContainsString( 'name="rfrac_tag"', $html );
		$this->assertStringContainsString( 'name="rfrac_url"', $html );
		$this->assertStringContainsString( 'name="target"', $html );
		$this->assertStringContainsString( 'Run now', $html );
	}

	/**
	 * Render the page for an installer with another template and must-use directory.
	 *
	 * @param string $template Path of the template (never read: the page only names it).
	 * @param string $mu_dir   Must-use directory (never created).
	 */
	private function rendered_for( string $template, string $mu_dir ): string {
		$plugin     = Plugin::instance();
		$status     = new Status_Service(
			Config::instance(),
			static fn() => $plugin->store(),
			new Schema(),
			new Mu_Installer( $plugin->basename(), $template, RFRAC_VERSION, $mu_dir ),
			$plugin->cache_service()
		);
		$this->page = new Tools_Page( new Notices(), $plugin->cache_service(), $status, $plugin->route_service() );

		return $this->rendered();
	}

	public function test_render_in_fallback_mode_shows_paths_relative_to_wp_content_only(): void {
		$html = $this->rendered_for( rtrim( WP_CONTENT_DIR, '/\\' ) . '/plugins/rockfire-rest-api-cache/mu-plugin/rfrac-early-cache.php', $this->mu_dir );

		$this->assertStringContainsString( '<code class="rfrac-path">plugins/rockfire-rest-api-cache/mu-plugin/rfrac-early-cache.php</code>', $html );
		$this->assertStringContainsString( '<code class="rfrac-path">' . esc_html( basename( $this->mu_dir ) . '/rfrac-early-cache.php' ) . '</code>', $html );
		$this->assertStringContainsString( 'The paths are relative to the wp-content directory.', $html );
		$this->assertStringNotContainsString( esc_html( wp_normalize_path( $this->mu_dir . '/rfrac-early-cache.php' ) ), $html );
		$this->assertStringNotContainsString( esc_html( trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) ), $html );
	}

	public function test_render_in_fallback_mode_prints_no_absolute_path_for_a_template_outside_wp_content(): void {
		$elsewhere = dirname( rtrim( WP_CONTENT_DIR, '/\\' ) ) . '/rfrac-elsewhere';
		$template  = $elsewhere . '/linked/rockfire-rest-api-cache/mu-plugin/rfrac-early-cache.php';

		$html = $this->rendered_for( $template, $this->mu_dir );

		$this->assertStringContainsString( '<code class="rfrac-path">' . "\u{2026}/mu-plugin/rfrac-early-cache.php" . '</code>', $html );
		$this->assertStringContainsString( '<code class="rfrac-path">wp-content/' . esc_html( basename( $this->mu_dir ) . '/rfrac-early-cache.php' ) . '</code>', $html );
		$this->assertStringNotContainsString( 'rfrac-elsewhere', $html );
		$this->assertStringNotContainsString( esc_html( wp_normalize_path( $template ) ), $html );
		$this->assertStringNotContainsString( 'The paths are relative to the wp-content directory.', $html );
	}

	public function test_render_in_fallback_mode_prints_no_absolute_path_for_a_target_outside_wp_content(): void {
		$elsewhere = dirname( rtrim( WP_CONTENT_DIR, '/\\' ) ) . '/rfrac-elsewhere';
		$mu_dir    = $elsewhere . '/mu';

		$html = $this->rendered_for( rtrim( WP_CONTENT_DIR, '/\\' ) . '/plugins/rockfire-rest-api-cache/mu-plugin/rfrac-early-cache.php', $mu_dir );

		$this->assertStringContainsString( '<code class="rfrac-path">wp-content/plugins/rockfire-rest-api-cache/mu-plugin/rfrac-early-cache.php</code>', $html );
		$this->assertStringContainsString( '<code class="rfrac-path">' . "\u{2026}/mu/rfrac-early-cache.php" . '</code>', $html );
		$this->assertStringNotContainsString( 'rfrac-elsewhere', $html );
		$this->assertStringNotContainsString( esc_html( wp_normalize_path( $mu_dir . '/rfrac-early-cache.php' ) ), $html );
		$this->assertStringNotContainsString( 'The paths are relative to the wp-content directory.', $html );
	}

	public function test_render_in_early_mode_has_no_install_button_or_instructions(): void {
		$this->submit( 'install_mu' );

		$html = $this->rendered();

		$this->assertStringContainsString( 'rfrac-badge--success">Early<', $html );
		$this->assertStringNotContainsString( 'Install must-use file', $html );
		$this->assertStringNotContainsString( 'RFRAC_PLUGIN_VERSION', $html );
	}

	public function test_render_in_outdated_mode(): void {
		wp_mkdir_p( $this->mu_dir );
		file_put_contents( $this->mu_dir . '/rfrac-early-cache.php', '<?php // old' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents

		$this->assertStringContainsString( 'rfrac-badge--warning">Outdated<', $this->rendered() );
	}

	public function test_render_prints_status_figures(): void {
		$html = $this->rendered();

		$this->assertStringContainsString( 'Tables present', $html );
		$this->assertStringContainsString( 'Installed ', $html );
		$this->assertStringContainsString( 'active,', $html );
	}

	public function test_the_admin_render_dies_for_a_subscriber(): void {
		$this->as_subscriber();
		$plugin = Plugin::instance();
		$admin  = new Admin( new Capability(), new Notices(), $plugin->status_service(), $plugin->cache_service(), array( $this->page ) );

		$this->expectException( WPDieException::class );
		$admin->render( $this->page );
	}
}
