<?php
/**
 * Multisite tests for the admin side of the legacy import: an original that is active on the whole network.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Action_Handler;
use Rockfire\Rest_Api_Cache\Admin\Admin;
use Rockfire\Rest_Api_Cache\Admin\Capability;
use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Legacy\Finding;
use Rockfire\Rest_Api_Cache\Legacy\Findings;
use Rockfire\Rest_Api_Cache\Legacy\Import_Notice;
use Rockfire\Rest_Api_Cache\Legacy\Import_Page;
use Rockfire\Rest_Api_Cache\Legacy\Import_Report;
use Rockfire\Rest_Api_Cache\Legacy\Importer;
use Rockfire\Rest_Api_Cache\Legacy\Original_Settings;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Import_Page
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Import_Notice
 * @group multisite
 */
class Test_Legacy_Import_Admin_Multisite extends Rfrac_Test_Case {

	use Rfrac_Admin_Test_Helpers;

	const ORIGINAL = 'wp-rest-cache/wp-rest-cache.php';

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run via composer test-multisite.' );
		}
		parent::set_up();
		delete_option( Names::OPTION_LEGACY_FINDINGS );
		update_site_option( 'active_sitewide_plugins', array( self::ORIGINAL => time() ) );
		Coexistence_Guard::reset();
		( new Findings() )->replace(
			Findings::GROUP_IMPORT,
			array( new Finding( Finding::IMPORTED, 'default_ttl', 'wp_rest_cache_timeout', 'The lifetime was imported.' ) ),
			array(
				'imported'    => 1,
				'kept'        => 0,
				'adjusted'    => 0,
				'skipped'     => 0,
				'unsupported' => 0,
				'result'      => Import_Report::RESULT_COMPILED,
			)
		);
	}

	public function tear_down() {
		$this->reset_admin_request();
		remove_all_actions( 'admin_post_rfrac_dismiss_import_summary' );
		remove_all_actions( 'admin_post_rfrac_flush_all' );
		remove_all_actions( 'admin_post_rfrac_dismiss_error' );
		update_site_option( 'active_sitewide_plugins', array() );
		delete_option( Names::OPTION_LEGACY_FINDINGS );
		Coexistence_Guard::reset();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Output of the notice, printed on the Plugins screen.
	 */
	private function notice_html(): string {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		set_current_screen( 'plugins' );
		ob_start();
		try {
			( new Import_Notice( new Capability(), new Findings() ) )->render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	public function test_a_network_active_original_links_a_network_administrator_to_the_network_plugins_screen(): void {
		$this->as_admin();

		$html = $this->notice_html();

		$this->assertStringContainsString( 'Imported the settings of WP REST Cache', $html );
		$this->assertStringContainsString( esc_url( network_admin_url( 'plugins.php' ) ), $html );
		$this->assertStringNotContainsString( 'action=deactivate', $html, 'No site link: a site cannot deactivate a network plugin.' );
		$this->assertStringNotContainsString( 'rfrac_deactivate_original', $html );
	}

	public function test_a_network_active_original_offers_a_site_administrator_nothing_to_deactivate(): void {
		$this->as_role( 'administrator' );
		$this->assertFalse( current_user_can( 'manage_network_plugins' ) );

		$html = $this->notice_html();

		$this->assertStringContainsString( 'Imported the settings of WP REST Cache', $html );
		$this->assertStringContainsString( 'View report', $html );
		$this->assertStringNotContainsString( 'network/plugins.php', $html );
		$this->assertStringNotContainsString( 'action=deactivate', $html );
		$this->assertStringNotContainsString( 'rfrac_deactivate_original', $html );
	}

	public function test_the_summary_of_a_network_active_original_can_be_dismissed_per_user(): void {
		$user_a = $this->as_admin();
		$plugin = Plugin::instance();
		$page   = new Import_Page( new Notices(), new Importer( Config::instance(), new Findings(), new Original_Settings() ), new Findings() );
		( new Admin( new Capability(), new Notices(), $plugin->status_service(), $plugin->cache_service(), array( $page ) ) )->register();
		$this->assertArrayNotHasKey( 'deactivate_original', $page->actions() );

		$this->set_request(
			array(),
			array(
				'action'   => 'rfrac_dismiss_import_summary',
				'_wpnonce' => wp_create_nonce( Action_Handler::nonce_action( 'dismiss_import_summary' ) ),
			)
		);
		$this->intercept_redirect();
		try {
			do_action( 'admin_post_rfrac_dismiss_import_summary' );
			$this->fail( 'Expected a redirect.' );
		} catch ( Redirect_Intercepted $redirect ) {
			$this->assertStringStartsWith( admin_url(), $redirect->location );
		}

		$this->assertSame( '', $this->notice_html(), 'Admin A no longer sees the summary.' );
		$this->assertArrayHasKey( self::ORIGINAL, (array) get_site_option( 'active_sitewide_plugins' ), 'It stays network-active.' );

		$user_b = $this->as_admin();
		$this->assertNotSame( $user_a, $user_b );
		$this->assertStringContainsString( 'rfrac_dismiss_import_summary', $this->notice_html(), 'Admin B still sees it.' );
	}
}
