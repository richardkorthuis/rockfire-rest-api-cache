<?php
/**
 * Multisite tests for the admin foundation: site administrators vs super admins.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Admin;
use Rockfire\Rest_Api_Cache\Admin\Admin_Bar;
use Rockfire\Rest_Api_Cache\Admin\Capability;
use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Admin\Pages\Page_Interface;
use Rockfire\Rest_Api_Cache\Admin\Pages\Tools_Page;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Install\Mu_Installer;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Schema\Schema;
use Rockfire\Rest_Api_Cache\Services\Status_Service;

/**
 * @covers \Rockfire\Rest_Api_Cache\Admin\Admin
 * @covers \Rockfire\Rest_Api_Cache\Admin\Admin_Bar
 * @group multisite
 */
class Test_Admin_Multisite extends Rfrac_Test_Case {

	use Rfrac_Admin_Test_Helpers;

	/**
	 * Temporary must-use directory.
	 *
	 * @var string
	 */
	private string $mu_dir;

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run via composer test-multisite.' );
		}
		parent::set_up();
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$this->mu_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/rfrac-admin-ms-' . bin2hex( random_bytes( 4 ) );
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
		delete_option( Mu_Installer::OPTION_NOTICE );
		unset( $GLOBALS['current_screen'], $GLOBALS['hook_suffix'] );
		parent::tear_down();
	}

	private function admin( Page_Interface $page ): Admin {
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

	private function page(): Page_Interface {
		return new class() implements Page_Interface {
			public int $loaded = 0;
			public function slug(): string {
				return 'rfrac-ms';
			}
			public function page_title(): string {
				return 'MS';
			}
			public function menu_title(): string {
				return 'MS';
			}
			public function position(): int {
				return 10;
			}
			public function load(): void {
				++$this->loaded;
			}
			public function render(): void {
			}
			public function actions(): array {
				return array();
			}
		};
	}

	public function test_a_site_admin_loads_the_page_without_any_must_use_write(): void {
		$this->as_role( 'administrator' );
		$this->assertFalse( is_super_admin() );
		$page  = $this->page();
		$fired = did_action( Hooks::ADMIN_PAGE_LOAD );

		$this->admin( $page )->load( $page );

		$this->assertSame( 1, $page->loaded );
		$this->assertSame( $fired + 1, did_action( Hooks::ADMIN_PAGE_LOAD ) );
		$this->assertFileDoesNotExist( $this->mu_dir . '/rfrac-early-cache.php', 'Only the install button of a network admin writes the must-use file.' );
	}

	public function test_a_super_admin_page_load_writes_no_must_use_file_either(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );
		$page = $this->page();

		$this->admin( $page )->load( $page );

		$this->assertSame( 1, $page->loaded );
		$this->assertFileDoesNotExist( $this->mu_dir . '/rfrac-early-cache.php', 'A page load never writes the file, whoever opens it.' );
	}

	public function test_a_site_admin_sees_the_admin_bar_flush_and_can_flush_the_site(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry();
		$this->as_role( 'administrator' );

		$bar = new WP_Admin_Bar();
		( new Admin_Bar( new Capability() ) )->add_node( $bar );
		$node = $bar->get_node( Admin_Bar::NODE );
		$this->assertNotNull( $node );

		$this->admin( $this->page() )->register();
		parse_str( (string) wp_parse_url( $node->href, PHP_URL_QUERY ), $query );
		$this->set_request( $query );
		$this->intercept_redirect();
		try {
			do_action( 'admin_post_rfrac_flush_all' );
			$this->fail( 'Expected a redirect.' );
		} catch ( Redirect_Intercepted $redirect ) {
			$this->assertSame( 303, $redirect->status );
		}
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
	}

	/**
	 * A Tools page wired to the temporary must-use directory, registered on an Admin.
	 */
	private function tools_page(): Tools_Page {
		$plugin = Plugin::instance();
		$status = new Status_Service(
			Config::instance(),
			static fn() => $plugin->store(),
			new Schema(),
			new Mu_Installer( $plugin->basename(), RFRAC_DIR . 'mu-plugin/rfrac-early-cache.php', RFRAC_VERSION, $this->mu_dir ),
			$plugin->cache_service()
		);
		$page = new Tools_Page( new Notices(), $plugin->cache_service(), $status, $plugin->route_service() );
		( new Admin( new Capability(), new Notices(), $status, $plugin->cache_service(), array( $page ) ) )->register();
		return $page;
	}

	public function test_a_site_admin_cannot_install_the_network_wide_mu_file_from_the_tools_page(): void {
		$this->as_role( 'administrator' );
		$this->assertFalse( is_super_admin() );
		$this->assertTrue( current_user_can( 'manage_options' ), 'The site admin has the plugin capability.' );
		$page = $this->tools_page();

		$this->set_request(
			array(),
			array(
				'action'   => 'rfrac_install_mu',
				'_wpnonce' => wp_create_nonce( 'rfrac_install_mu' ),
			)
		);
		$this->intercept_redirect();
		try {
			do_action( 'admin_post_rfrac_install_mu' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assertFileDoesNotExist( $this->mu_dir . '/rfrac-early-cache.php' );
		$this->assertFalse( get_option( Mu_Installer::OPTION_NOTICE ), 'No install attempt was made.' );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'Install must-use file', $html );
		$this->assertStringContainsString( 'To install the must-use file manually', $html );
	}

	public function test_a_network_admin_can_install_the_mu_file_from_the_tools_page(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );
		$page = $this->tools_page();

		ob_start();
		$page->render();
		$this->assertStringContainsString( 'Install must-use file', (string) ob_get_clean() );

		$this->set_request(
			array(),
			array(
				'action'   => 'rfrac_install_mu',
				'_wpnonce' => wp_create_nonce( 'rfrac_install_mu' ),
			)
		);
		$this->intercept_redirect();
		try {
			do_action( 'admin_post_rfrac_install_mu' );
			$this->fail( 'Expected a redirect.' );
		} catch ( Redirect_Intercepted $redirect ) {
			$this->assertSame( 303, $redirect->status );
		}
		$this->assertFileExists( $this->mu_dir . '/rfrac-early-cache.php' );
	}

	/**
	 * The must-use notice as an admin prints it.
	 */
	private function mu_notice( Admin $admin ): string {
		ob_start();
		try {
			$admin->render_mu_notice();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * Make a screen the current one.
	 */
	private function on_screen( string $screen_id ): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		set_current_screen( $screen_id );
	}

	public function test_a_site_admin_sees_no_must_use_notice_and_a_network_admin_sees_it_on_the_plugins_screens(): void {
		wp_mkdir_p( $this->mu_dir );
		$admin = $this->admin( $this->page() );

		$this->as_role( 'administrator' );
		$this->assertFalse( is_super_admin() );
		$this->on_screen( 'plugins' );
		$this->assertSame( '', $this->mu_notice( $admin ), 'A site admin on the site Plugins screen.' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );

		foreach ( array( 'plugins-network', 'plugins' ) as $screen_id ) {
			$this->on_screen( $screen_id );
			$html = $this->mu_notice( $admin );
			$this->assertStringContainsString( 'runs in fallback mode', $html, $screen_id );
			$this->assertStringContainsString( 'name="action" value="rfrac_install_mu"', $html, $screen_id );
		}

		$this->on_screen( 'dashboard' );
		$this->assertSame( '', $this->mu_notice( $admin ), 'Not on the dashboard.' );
		$this->on_screen( 'dashboard-network' );
		$this->assertSame( '', $this->mu_notice( $admin ), 'Not on the network dashboard.' );
	}

	public function test_the_must_use_notice_is_hooked_on_the_site_and_the_network_notices(): void {
		$admin = $this->admin( $this->page() );
		$admin->register();

		$this->assertSame( 10, has_action( 'admin_notices', array( $admin, 'render_mu_notice' ) ) );
		$this->assertSame( 10, has_action( 'network_admin_notices', array( $admin, 'render_mu_notice' ) ) );
	}
}
