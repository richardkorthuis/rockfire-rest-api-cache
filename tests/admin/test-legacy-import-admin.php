<?php
/**
 * Tests for the admin side of the legacy import: the notice, the Import page and its actions.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Action_Handler;
use Rockfire\Rest_Api_Cache\Admin\Admin;
use Rockfire\Rest_Api_Cache\Admin\Capability;
use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Admin\Pages\Page_Interface;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Settings_Schema;
use Rockfire\Rest_Api_Cache\Config\Validation_Result;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Legacy\Constant_Fallback;
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
 * @covers \Rockfire\Rest_Api_Cache\Plugin
 */
class Test_Legacy_Import_Admin extends Rfrac_Test_Case {

	use Rfrac_Admin_Test_Helpers;
	use Rfrac_Fails_Option_Writes;

	const OWN      = 'rockfire-rest-api-cache/rockfire-rest-api-cache.php';
	const ORIGINAL = 'wp-rest-cache/wp-rest-cache.php';

	/**
	 * The REST server of the test, with the `acme/v1` routes.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	public function set_up() {
		parent::set_up();
		Rfrac_Legacy_Fixture::clear();
		delete_option( Names::OPTION_LEGACY_FINDINGS );
		update_option( 'active_plugins', array( self::OWN ) );
		Coexistence_Guard::reset();
		Config::set_fallback_constants( new Constant_Fallback() );
		// Rockfire's settings were saved once, so the import updates the option instead of adding it.
		update_option( Names::OPTION_SETTINGS, array_merge( Settings_Schema::defaults(), array( 'routes' => array() ) ), false );
		$this->server = $this->new_rest_server( array( 'Rfrac_Legacy_Fixture', 'register_routes' ) );
		$this->attach_settings_compile();
		$this->as_admin();
	}

	public function tear_down() {
		$this->reset_admin_request();
		foreach ( array( 'import_legacy', 'dismiss_import_notice', 'dismiss_import_summary', 'flush_all', 'dismiss_error' ) as $action ) {
			remove_all_actions( 'admin_post_rfrac_' . $action );
		}
		Rfrac_Legacy_Fixture::clear();
		delete_option( Names::OPTION_LEGACY_FINDINGS );
		Coexistence_Guard::reset();
		Config::set_fallback_constants( new Constant_Fallback() );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Attach the settings-save compile of the booted container again (the base class detaches the engine hooks),
	 * so a settings write compiles the configuration as it does on a live site.
	 */
	private function attach_settings_compile(): void {
		$hooks = array( 'add_option_' . Names::OPTION_SETTINGS, 'update_option_' . Names::OPTION_SETTINGS );
		foreach ( Plugin::instance()->engine_hooks() as $hook ) {
			if ( in_array( $hook['hook'], $hooks, true ) ) {
				add_filter( $hook['hook'], $hook['callback'], $hook['priority'], $hook['args'] );
			}
		}
	}

	/**
	 * An importer over the shared settings and the test routes.
	 */
	private function importer(): Importer {
		return new Importer( Config::instance(), new Findings(), new Original_Settings(), fn(): WP_REST_Server => $this->server );
	}

	/**
	 * The Import page over the real stores.
	 */
	private function page(): Import_Page {
		return new Import_Page( new Notices(), $this->importer(), new Findings() );
	}

	/**
	 * The import notice over the real stores.
	 */
	private function notice(): Import_Notice {
		return new Import_Notice( new Capability(), new Findings() );
	}

	/**
	 * Register the actions of the Import page the way the admin does.
	 */
	private function register_actions(): void {
		$plugin = Plugin::instance();
		( new Admin( new Capability(), new Notices(), $plugin->status_service(), $plugin->cache_service(), array( $this->page() ) ) )->register();
	}

	/**
	 * Leave the database version option of the original behind.
	 */
	private function leftovers(): void {
		update_option( 'wp_rest_cache_database_version', '2024.1.0' );
	}

	/**
	 * Make the original active on this site.
	 */
	private function original_active(): void {
		update_option( 'active_plugins', array( self::ORIGINAL, self::OWN ) );
		Coexistence_Guard::reset();
	}

	/**
	 * Store an import with one finding of each of three statuses.
	 *
	 * @param string $result Result of the import.
	 */
	private function store_import( string $result = Import_Report::RESULT_COMPILED ): void {
		( new Findings() )->replace(
			Findings::GROUP_IMPORT,
			array(
				new Finding( Finding::ADJUSTED, 'max_ttl_raised', 'wp_rest_cache_timeout', 'The maximum lifetime was raised.' ),
				new Finding( Finding::IMPORTED, 'default_ttl', 'wp_rest_cache_timeout', 'The lifetime was imported.' ),
				new Finding( Finding::KEPT, 'stored_value', 'wp_rest_cache_hit_recording', 'The saved value was kept.' ),
			),
			array(
				'imported'    => 1,
				'kept'        => 1,
				'adjusted'    => 1,
				'skipped'     => 0,
				'unsupported' => 0,
				'result'      => $result,
			)
		);
	}

	/**
	 * A user with the settings capability that may not manage plugins.
	 *
	 * The capability is granted before the user becomes the current user: {@see wp_set_current_user()} returns the
	 * object it already holds when the ID is the current one, so granting it after `as_role()` would never be seen.
	 */
	private function as_settings_manager_without_plugins(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $user_id )->add_cap( 'manage_options' );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Make a screen the current one.
	 *
	 * @param string $screen_id Screen ID.
	 */
	private function on_screen( string $screen_id ): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		set_current_screen( $screen_id );
	}

	/**
	 * Move the stored import to another time, as a new import does.
	 *
	 * @param int $time Unix timestamp.
	 */
	private function restamp_import( int $time ): void {
		$option = get_option( Names::OPTION_LEGACY_FINDINGS );
		$this->assertIsArray( $option );
		$option['groups'][ Findings::GROUP_IMPORT ]['time'] = $time;
		update_option( Names::OPTION_LEGACY_FINDINGS, $option, false );
	}

	/**
	 * Parse the query of the deactivate links of an HTML output.
	 *
	 * @param string $html HTML.
	 *
	 * @return list<array<string,string>> One entry per link.
	 */
	private function deactivate_links( string $html ): array {
		$links = array();
		if ( preg_match_all( '/href="([^"]*action=deactivate[^"]*)"/', $html, $matches ) ) {
			foreach ( $matches[1] as $href ) {
				$query = array();
				parse_str( (string) wp_parse_url( html_entity_decode( $href ), PHP_URL_QUERY ), $query );
				$query['path'] = (string) wp_parse_url( html_entity_decode( $href ), PHP_URL_PATH );
				$links[]       = $query;
			}
		}
		return $links;
	}

	/**
	 * Output of the notice.
	 *
	 * @param string $screen_id Screen ID the notice prints on.
	 */
	private function notice_html( string $screen_id = 'plugins' ): string {
		$this->on_screen( $screen_id );
		ob_start();
		try {
			$this->notice()->render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * Output of the page.
	 */
	private function page_html(): string {
		ob_start();
		try {
			$this->page()->render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
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
	 * Submit an action as a POST; returns the redirect, or dies like a failed check.
	 *
	 * @param string $action Action name.
	 * @param bool   $nonce  Whether to send a valid nonce.
	 */
	private function submit( string $action, bool $nonce = true ): Redirect_Intercepted {
		$this->set_request(
			array(),
			array(
				'action'   => 'rfrac_' . $action,
				'_wpnonce' => $nonce ? wp_create_nonce( Action_Handler::nonce_action( $action ) ) : 'bad',
			)
		);
		$this->intercept_redirect();
		try {
			do_action( 'admin_post_rfrac_' . $action );
		} catch ( Redirect_Intercepted $redirect ) {
			return $redirect;
		}
		$this->fail( 'Expected a redirect.' );
	}

	/**
	 * Whether the import notice is hooked on `admin_notices`.
	 */
	private function notice_is_hooked(): bool {
		global $wp_filter;
		if ( ! isset( $wp_filter['admin_notices'] ) ) {
			return false;
		}
		foreach ( $wp_filter['admin_notices']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && $function[0] instanceof Import_Notice ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Slugs of the pages of the container.
	 *
	 * @return list<string>
	 */
	private function page_slugs(): array {
		return array_values( array_map( static fn( Page_Interface $page ): string => $page->slug(), Plugin::instance()->admin_pages() ) );
	}

	// ---------- The page: identity and presence ----------

	public function test_page_identity_and_actions(): void {
		$page = $this->page();
		$this->assertInstanceOf( Page_Interface::class, $page );
		$this->assertSame( 'rfrac-import', $page->slug() );
		$this->assertSame( 50, $page->position() );
		$this->assertSame( 'Import', $page->menu_title() );

		$actions = $page->actions();
		$this->assertSame( array( 'import_legacy', 'dismiss_import_notice', 'dismiss_import_summary' ), array_keys( $actions ) );
		foreach ( $actions as $name => $definition ) {
			$this->assertIsArray( $definition, $name );
			$this->assertFalse( $definition['per_target_nonce'], $name );
			$this->assertIsCallable( $definition['callback'], $name );
		}
	}

	public function test_the_plugin_has_no_import_page_without_leftovers_and_findings(): void {
		$this->assertNotContains( 'rfrac-import', $this->page_slugs() );
		$this->assertFalse( $this->page()->available() );
	}

	public function test_the_plugin_adds_the_import_page_for_leftovers(): void {
		$this->leftovers();
		$this->assertContains( 'rfrac-import', $this->page_slugs() );
	}

	public function test_the_plugin_adds_the_import_page_for_an_active_original(): void {
		$this->original_active();
		$this->assertContains( 'rfrac-import', $this->page_slugs() );
	}

	public function test_the_plugin_adds_the_import_page_for_a_stored_finding(): void {
		( new Findings() )->add( Findings::GROUP_HOOKS, new Finding( Finding::UNSUPPORTED, 'unknown_hook', 'some/hook', 'It never runs.' ) );
		$this->assertContains( 'rfrac-import', $this->page_slugs() );
	}

	// ---------- The notice ----------

	public function test_the_notice_offers_the_import_to_an_administrator(): void {
		$this->leftovers();

		$html = $this->notice_html();

		$this->assertStringContainsString( 'value="rfrac_import_legacy"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( admin_url( 'admin-post.php' ), $html );
		$this->assertStringContainsString( 'Import now', $html );
		$this->assertStringContainsString( 'page=rfrac-import', $html, 'The review link.' );
		$this->assertStringContainsString( 'Review on the Import page', $html );
		$this->assertStringContainsString( 'value="rfrac_dismiss_import_notice"', $html );
	}

	public function test_the_container_hooks_the_notice_on_admin_notices_of_the_site_admin(): void {
		$this->on_screen( 'plugins' );
		$this->leftovers();
		$plugin = new Plugin();
		$plugin->register_admin_hooks();
		$this->assertTrue( $this->notice_is_hooked() );

		ob_start();
		try {
			do_action( 'admin_notices' );
		} finally {
			$html = (string) ob_get_clean();
		}

		$this->assertStringContainsString( 'value="rfrac_import_legacy"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
	}

	public function test_the_container_does_not_hook_the_notice_during_ajax(): void {
		$this->on_screen( 'dashboard' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$plugin = new Plugin();
		$plugin->register_admin_hooks();

		$this->assertFalse( $this->notice_is_hooked() );
	}

	public function test_the_notice_is_silent_for_an_editor(): void {
		$this->leftovers();
		$this->as_role( 'editor' );

		$this->assertSame( '', $this->notice_html() );
	}

	public function test_the_notice_is_silent_once_dismissed(): void {
		$this->leftovers();
		( new Findings() )->replace( Findings::GROUP_NOTICE, array(), array( 'dismissed' => true ) );

		$this->assertSame( '', $this->notice_html() );
	}

	public function test_the_notice_is_silent_after_an_import_while_the_original_is_inactive(): void {
		$this->leftovers();
		$this->store_import();

		$this->assertSame( '', $this->notice_html() );
	}

	public function test_the_notice_is_silent_without_anything_of_the_original(): void {
		$this->assertSame( '', $this->notice_html() );
	}

	public function test_the_notice_is_never_shown_on_the_import_page(): void {
		$this->leftovers();
		$this->set_request( array( 'page' => 'rfrac-import' ) );

		$this->assertSame( '', $this->notice_html( 'rest-api-cache_page_rfrac-import' ) );

		$this->original_active();
		$this->store_import();
		$this->assertSame( '', $this->notice_html( 'rest-api-cache_page_rfrac-import' ) );
	}

	public function test_the_offer_shows_on_the_plugins_screen_and_the_pages_of_the_plugin_only(): void {
		$this->leftovers();

		$this->assertStringContainsString( 'value="rfrac_import_legacy"', $this->notice_html( 'plugins' ) );
		$this->assertStringContainsString( 'value="rfrac_import_legacy"', $this->notice_html( 'toplevel_page_rfrac-caches' ) );
		$this->assertStringContainsString( 'value="rfrac_import_legacy"', $this->notice_html( 'rest-api-cache_page_rfrac-tools' ) );

		$this->assertSame( '', $this->notice_html( 'dashboard' ) );
		$this->assertSame( '', $this->notice_html( 'edit-post' ) );
		$this->assertSame( '', $this->notice_html( 'toplevel_page_other-plugin' ) );
	}

	public function test_the_notice_is_silent_without_a_screen(): void {
		$this->leftovers();
		$this->on_screen( 'plugins' );
		unset( $GLOBALS['current_screen'] );

		ob_start();
		try {
			$this->notice()->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		$this->assertSame( '', $html );
	}

	public function test_the_summary_shows_on_the_plugins_screen_only_with_the_report_link_the_deactivate_link_and_a_dismiss_form(): void {
		$this->original_active();
		$this->store_import();

		$html = $this->notice_html( 'plugins' );

		$this->assertStringContainsString( 'Imported the settings of WP REST Cache: 1 imported, 1 kept, 1 adjusted, 0 skipped, 0 unsupported.', $html );
		$this->assertStringContainsString( 'notice-warning', $html, 'An adjusted value needs attention.' );
		$this->assertStringContainsString( 'View report', $html );
		$this->assertStringContainsString( 'page=rfrac-import', $html );
		$this->assertStringContainsString( 'Deactivate WP REST Cache', $html );
		$this->assertStringContainsString( 'action=deactivate', $html );
		$this->assertStringContainsString( 'value="rfrac_dismiss_import_summary"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringNotContainsString( 'rfrac_import_legacy', $html );
		$this->assertStringNotContainsString( 'rfrac_deactivate_original', $html );

		$this->assertSame( '', $this->notice_html( 'dashboard' ) );
	}

	public function test_dismissing_the_summary_hides_it_for_that_user_until_a_new_import(): void {
		$this->original_active();
		$this->store_import();
		$this->register_actions();
		$user_a = $this->as_admin();
		$this->assertStringContainsString( 'rfrac_dismiss_import_summary', $this->notice_html() );

		$redirect = $this->submit( 'dismiss_import_summary' );

		$this->assertStringStartsWith( admin_url(), $redirect->location );
		$this->assertSame( '', $this->notice_html(), 'Admin A no longer sees the summary.' );
		$this->assertNull( ( new Findings() )->get( Findings::GROUP_NOTICE ), 'The offer is not touched.' );

		$user_b = $this->as_admin();
		$this->assertNotSame( $user_a, $user_b );
		$this->assertStringContainsString( 'rfrac_dismiss_import_summary', $this->notice_html(), 'Admin B still sees it.' );

		wp_set_current_user( $user_a );
		$this->assertSame( '', $this->notice_html() );

		$this->restamp_import( time() + 60 );
		$this->assertStringContainsString( 'rfrac_dismiss_import_summary', $this->notice_html(), 'A new import shows it to admin A again.' );
	}

	public function test_dismissing_the_summary_with_a_bad_nonce_or_without_the_capability_dies_and_stores_nothing(): void {
		$this->original_active();
		$this->store_import();
		$this->register_actions();

		try {
			$this->submit( 'dismiss_import_summary', false );
			$this->fail( 'A bad nonce should die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		$this->as_subscriber();
		try {
			$this->submit( 'dismiss_import_summary' );
			$this->fail( 'A user without the capability should die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		$this->as_admin();
		$this->assertStringContainsString( 'rfrac_dismiss_import_summary', $this->notice_html() );
	}

	public function test_dismissing_the_summary_without_a_stored_import_changes_nothing(): void {
		$this->original_active();
		$this->register_actions();

		$redirect = $this->submit( 'dismiss_import_summary' );

		$this->assertStringStartsWith( admin_url(), $redirect->location );
		$this->assertNull( ( new Findings() )->get( Findings::GROUP_IMPORT ) );
		$this->assertSame( array(), $this->queued() );
	}

	public function test_the_deactivate_link_of_the_notice_is_the_nonced_link_of_the_plugins_screen(): void {
		$this->original_active();
		$this->store_import();

		$links = $this->deactivate_links( $this->notice_html() );

		$this->assertCount( 1, $links );
		$this->assertStringEndsWith( 'wp-admin/plugins.php', $links[0]['path'] );
		$this->assertSame( 'deactivate', $links[0]['action'] );
		$this->assertSame( self::ORIGINAL, $links[0]['plugin'] );
		$this->assertSame( 1, wp_verify_nonce( $links[0]['_wpnonce'], 'deactivate-plugin_' . self::ORIGINAL ) );
	}

	public function test_the_deactivate_link_encodes_the_plugin_and_signs_the_basename(): void {
		$url = Import_Page::deactivate_url( self::ORIGINAL );

		$this->assertStringStartsWith( admin_url( 'plugins.php' ) . '?', $url );
		$this->assertStringContainsString( 'plugin=wp-rest-cache%2Fwp-rest-cache.php', $url );
		$this->assertStringContainsString( 'action=deactivate', $url );
		$this->assertStringNotContainsString( 'rfrac_deactivate_original', $url );

		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 1, wp_verify_nonce( $query['_wpnonce'], 'deactivate-plugin_' . self::ORIGINAL ) );
		$this->assertFalse( wp_verify_nonce( $query['_wpnonce'], 'deactivate-plugin_other/other.php' ) );
	}

	public function test_the_notice_after_an_import_without_attention_is_a_success(): void {
		$this->original_active();
		( new Findings() )->replace(
			Findings::GROUP_IMPORT,
			array(
				new Finding( Finding::IMPORTED, 'default_ttl', 'wp_rest_cache_timeout', 'The lifetime was imported.' ),
				new Finding( Finding::ADJUSTED, Importer::CODE_STRICTER_REQUESTS, Importer::PLUGIN_SUBJECT, 'Some requests bypass the cache now.' ),
			),
			array(
				'imported'    => 1,
				'kept'        => 0,
				'adjusted'    => 1,
				'skipped'     => 0,
				'unsupported' => 0,
				'result'      => Import_Report::RESULT_COMPILED,
			)
		);

		$html = $this->notice_html();

		$this->assertStringContainsString( 'notice-success', $html, 'The standing note about stricter requests is no reason to warn.' );
		$this->assertStringNotContainsString( 'need your attention', $html );
	}

	public function test_the_notice_after_a_failed_import_is_an_error(): void {
		$this->original_active();
		$this->store_import( Import_Report::RESULT_FAILED );

		$html = $this->notice_html();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'could not be imported', $html );
	}

	public function test_the_notice_offers_no_deactivation_to_a_user_who_may_not_deactivate_plugins(): void {
		$this->original_active();
		$this->store_import();
		$this->as_settings_manager_without_plugins();

		$html = $this->notice_html();

		$this->assertStringContainsString( 'Imported the settings of WP REST Cache', $html );
		$this->assertStringContainsString( 'View report', $html );
		$this->assertStringNotContainsString( 'action=deactivate', $html );
		$this->assertStringNotContainsString( 'Deactivate WP REST Cache', $html );
	}

	// ---------- The import action ----------

	public function test_a_valid_import_imports_redirects_to_the_report_and_queues_one_success_notice(): void {
		update_option( 'wp_rest_cache_timeout', 1 );
		update_option( 'wp_rest_cache_timeout_interval', 7200 );
		$this->register_actions();

		$redirect = $this->submit( 'import_legacy' );

		$this->assertSame( 303, $redirect->status );
		$this->assertStringContainsString( 'page=rfrac-import', $redirect->location );
		$this->assertSame( 7200, Config::instance()->settings()['default_ttl'] );
		$this->assertNotNull( ( new Findings() )->get( Findings::GROUP_IMPORT ) );

		$queued = $this->queued();
		$this->assertCount( 1, $queued );
		$this->assertSame( 'success', $queued[0]['type'] );
		$this->assertStringContainsString( 'Imported the settings of WP REST Cache', $queued[0]['message'] );
	}

	public function test_an_import_with_values_that_need_attention_queues_a_warning(): void {
		Rfrac_Legacy_Fixture::install_stock();
		$this->register_actions();

		$redirect = $this->submit( 'import_legacy' );

		$this->assertStringContainsString( 'page=rfrac-import', $redirect->location );
		$queued = $this->queued();
		$this->assertCount( 1, $queued );
		$this->assertSame( 'warning', $queued[0]['type'], 'The maximum lifetime was raised.' );
		$this->assertStringContainsString( 'need your attention', $queued[0]['message'] );
	}

	public function test_an_import_without_anything_to_import_queues_an_info_notice_and_stores_nothing(): void {
		$this->register_actions();

		$this->submit( 'import_legacy' );

		$queued = $this->queued();
		$this->assertCount( 1, $queued );
		$this->assertSame( 'info', $queued[0]['type'] );
		$this->assertNull( ( new Findings() )->get( Findings::GROUP_IMPORT ) );
	}

	public function test_a_failed_import_queues_an_error_that_names_why_the_save_failed(): void {
		Rfrac_Legacy_Fixture::install_stock();
		$this->register_actions();
		$this->fail_option_writes( Names::OPTION_SETTINGS );

		$redirect = $this->submit( 'import_legacy' );

		$this->assertStringContainsString( 'page=rfrac-import', $redirect->location );
		$queued = $this->queued();
		$this->assertCount( 1, $queued );
		$this->assertSame( 'error', $queued[0]['type'] );
		$this->assertStringContainsString( 'could not be imported', $queued[0]['message'] );
		$this->assertStringContainsString( 'Details: The settings and route rules could not be saved to the database.', $queued[0]['message'], 'The reason of the validation, as the CLI reports it.' );
	}

	public function test_the_reasons_of_a_failed_import_name_the_rejected_keys_and_routes_and_are_escaped_when_printed(): void {
		$summary    = Import_Page::summary( array(), Import_Report::RESULT_FAILED, 0 );
		$validation = new Validation_Result();
		$validation->add_error( 'locked_setting', 'The setting "max_ttl" is defined by RFRAC_MAX_TTL.', 'max_ttl' );
		$validation->add_error( 'locked_route', 'The route "/acme/<b>v1</b>" is defined by RFRAC_ROUTES.', '/acme/<b>v1</b>' );

		$message = Import_Page::with_failure_reasons( $summary, $validation );

		$this->assertStringStartsWith( $summary, $message );
		$this->assertStringContainsString( 'The setting "max_ttl" is defined by RFRAC_MAX_TTL. The route "/acme/<b>v1</b>" is defined by RFRAC_ROUTES.', $message, 'Every reason, in order.' );

		ob_start();
		try {
			Notices::output( 'error', $message );
		} finally {
			$html = (string) ob_get_clean();
		}
		$this->assertStringContainsString( 'The route &quot;/acme/&lt;b&gt;v1&lt;/b&gt;&quot; is defined by RFRAC_ROUTES.', $html );
		$this->assertStringNotContainsString( '<b>', $html );

		$this->assertSame( $summary, Import_Page::with_failure_reasons( $summary, null ) );
		$this->assertSame( $summary, Import_Page::with_failure_reasons( $summary, new Validation_Result() ) );
	}

	public function test_an_import_with_a_bad_nonce_or_without_the_capability_dies_and_imports_nothing(): void {
		update_option( 'wp_rest_cache_timeout', 1 );
		update_option( 'wp_rest_cache_timeout_interval', 7200 );
		$this->register_actions();
		$default_ttl = Config::instance()->settings()['default_ttl'];

		try {
			$this->submit( 'import_legacy', false );
			$this->fail( 'A bad nonce should die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		$this->as_subscriber();
		try {
			$this->submit( 'import_legacy' );
			$this->fail( 'A user without the capability should die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		$this->assertSame( $default_ttl, Config::instance()->settings()['default_ttl'] );
		$this->assertNull( ( new Findings() )->get( Findings::GROUP_IMPORT ) );
		$this->assertSame( array(), $this->queued() );
	}

	// ---------- The dismiss action ----------

	public function test_dismissing_the_notice_stores_it_and_silences_the_notice(): void {
		$this->leftovers();
		$this->register_actions();
		$this->assertNotSame( '', $this->notice_html() );

		$redirect = $this->submit( 'dismiss_import_notice' );

		$this->assertStringStartsWith( admin_url(), $redirect->location );
		$group = ( new Findings() )->get( Findings::GROUP_NOTICE );
		$this->assertNotNull( $group );
		$this->assertSame( array( 'dismissed' => true ), $group['meta'] );
		$this->assertSame( '', $this->notice_html() );
	}

	public function test_dismissing_with_a_bad_nonce_dies_and_stores_nothing(): void {
		$this->leftovers();
		$this->register_actions();

		try {
			$this->submit( 'dismiss_import_notice', false );
			$this->fail( 'A bad nonce should die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		$this->assertNull( ( new Findings() )->get( Findings::GROUP_NOTICE ) );
	}

	public function test_dismissing_the_offer_returns_to_the_referring_admin_screen_and_hides_it_for_every_user(): void {
		$this->leftovers();
		$this->register_actions();
		$_SERVER['HTTP_REFERER'] = admin_url( 'plugins.php?plugin_status=active' );

		$redirect = $this->submit( 'dismiss_import_notice' );

		$this->assertSame( admin_url( 'plugins.php?plugin_status=active' ), $redirect->location );

		$this->as_admin();
		$this->assertSame( '', $this->notice_html(), 'Another administrator does not see the offer either.' );
	}

	// ---------- The deactivate link ----------

	public function test_rendering_the_offer_never_deactivates_the_original(): void {
		$this->original_active();
		$this->store_import();

		$this->assertArrayNotHasKey( 'deactivate_original', $this->page()->actions() );
		$this->notice_html();
		$this->page_html();

		$this->assertContains( self::ORIGINAL, (array) get_option( 'active_plugins' ) );
		$this->assertTrue( Coexistence_Guard::is_blocked(), 'The original stays active until its own Plugins screen deactivates it.' );
	}

	// ---------- The page ----------

	public function test_the_page_previews_the_import_without_changing_the_settings(): void {
		Rfrac_Legacy_Fixture::install_stock();
		$before = get_option( Names::OPTION_SETTINGS );

		$html = $this->page_html();

		$this->assertStringContainsString( 'Preview', $html );
		$this->assertStringContainsString( 'rfrac-import-table', $html );
		$this->assertStringContainsString( Rfrac_Legacy_Fixture::TTL_OPTIONS_LABEL, $html, 'The dry-run findings are listed.' );
		$this->assertStringContainsString( '<td>Adjusted</td>', $html );
		$this->assertStringContainsString( 'value="rfrac_import_legacy"', $html );
		$this->assertStringContainsString( 'Import settings', $html );
		$this->assertStringContainsString( 'already changed in this plugin are kept', $html );
		$this->assertStringNotContainsString( 'Run the import again', $html );
		$this->assertSame( $before, get_option( Names::OPTION_SETTINGS ), 'rfrac_settings is unchanged.' );
		$this->assertNull( ( new Findings() )->get( Findings::GROUP_IMPORT ), 'Nothing is stored.' );
	}

	public function test_the_page_says_so_when_there_is_nothing_to_import(): void {
		$html = $this->page_html();

		$this->assertStringContainsString( 'nothing to import', $html );
		$this->assertStringNotContainsString( 'rfrac_import_legacy', $html );
	}

	public function test_the_page_shows_the_stored_report_and_the_other_findings(): void {
		Rfrac_Legacy_Fixture::install_stock();
		$this->importer()->run();
		( new Findings() )->add( Findings::GROUP_HOOKS, new Finding( Finding::UNSUPPORTED, 'unknown_hook', 'some/unknown_hook', 'The hook some/unknown_hook never runs.' ) );
		( new Findings() )->add( 'zz_custom', new Finding( Finding::KEPT, 'a_code', 'a_subject', 'A custom finding.' ) );

		$html = $this->page_html();

		$this->assertStringContainsString( 'Import report', $html );
		$this->assertStringContainsString( Rfrac_Legacy_Fixture::TTL_OPTIONS_LABEL, $html );
		$this->assertStringContainsString( 'Result:', $html );
		$this->assertStringContainsString( 'Run the import again', $html );
		$this->assertStringContainsString( 'value="rfrac_import_legacy"', $html );
		$this->assertStringNotContainsString( 'Import settings', $html );
		$this->assertStringNotContainsString( '<h2>Preview</h2>', $html );

		$this->assertStringContainsString( '<h2>Hooks</h2>', $html );
		$this->assertStringContainsString( 'The hook some/unknown_hook never runs.', $html );
		$this->assertStringContainsString( '<h2>Other findings</h2>', $html );
		$this->assertStringContainsString( 'A custom finding.', $html );

		$this->assertLessThan(
			strpos( $html, '<td>Imported</td>' ),
			(int) strrpos( $html, '<td>Adjusted</td>' ),
			'What needs attention comes before what was imported.'
		);
	}

	public function test_the_page_offers_the_deactivation_in_both_states(): void {
		$this->leftovers();
		$this->original_active();

		$before = $this->deactivate_links( $this->page_html() );
		$this->assertCount( 1, $before, 'Before the import.' );
		$this->assertSame( 'deactivate', $before[0]['action'] );
		$this->assertSame( self::ORIGINAL, $before[0]['plugin'] );
		$this->assertSame( 1, wp_verify_nonce( $before[0]['_wpnonce'], 'deactivate-plugin_' . self::ORIGINAL ) );
		$this->assertStringEndsWith( 'wp-admin/plugins.php', $before[0]['path'] );

		$this->store_import();

		$after = $this->deactivate_links( $this->page_html() );
		$this->assertCount( 1, $after, 'After the import.' );
		$this->assertSame( self::ORIGINAL, $after[0]['plugin'] );
		$this->assertStringNotContainsString( 'rfrac_deactivate_original', $this->page_html() );
	}

	public function test_the_page_offers_no_deactivation_without_an_active_original_or_the_capability(): void {
		$this->leftovers();
		$this->assertSame( array(), $this->deactivate_links( $this->page_html() ), 'The original is not active.' );

		$this->original_active();
		$this->as_settings_manager_without_plugins();
		$this->assertSame( array(), $this->deactivate_links( $this->page_html() ), 'The user may not deactivate plugins.' );
		$this->assertStringNotContainsString( 'Deactivate WP REST Cache', $this->page_html() );
	}

	// ---------- Helpers of the page ----------

	public function test_the_findings_are_ordered_by_what_needs_attention(): void {
		$findings = array(
			new Finding( Finding::IMPORTED, 'a', 's1', 'm' ),
			new Finding( Finding::KEPT, 'a', 's2', 'm' ),
			new Finding( Finding::UNSUPPORTED, 'a', 's3', 'm' ),
			new Finding( Finding::ADJUSTED, 'a', 's4', 'm' ),
			new Finding( Finding::SKIPPED, 'a', 's5', 'm' ),
			new Finding( Finding::ADJUSTED, 'a', 's6', 'm' ),
		);

		$ordered = array_map( static fn( Finding $finding ): string => $finding->subject, Import_Page::ordered( $findings ) );

		$this->assertSame( array( 's4', 's6', 's5', 's3', 's2', 's1' ), $ordered );
	}

	public function test_the_attention_count_ignores_the_standing_note(): void {
		$findings = array(
			new Finding( Finding::ADJUSTED, Importer::CODE_STRICTER_REQUESTS, Importer::PLUGIN_SUBJECT, 'm' ),
			new Finding( Finding::SKIPPED, 'regenerate', 's', 'm' ),
			new Finding( Finding::IMPORTED, 'default_ttl', 's', 'm' ),
			new Finding( Finding::UNSUPPORTED, 'unknown_hook', 's', 'm' ),
		);

		$this->assertSame( 2, Import_Page::attention( $findings ) );
	}

	public function test_the_summary_follows_the_result(): void {
		$counts = array(
			'imported'    => 2,
			'kept'        => 1,
			'adjusted'    => 0,
			'skipped'     => 0,
			'unsupported' => 0,
		);

		$compiled = Import_Page::summary( $counts, Import_Report::RESULT_COMPILED, 0 );
		$this->assertSame( 'Imported the settings of WP REST Cache: 2 imported, 1 kept, 0 adjusted, 0 skipped, 0 unsupported.', $compiled );
		$this->assertStringContainsString( 'rebuilt on a later request', Import_Page::summary( $counts, Import_Report::RESULT_DEFERRED, 0 ) );
		$this->assertStringContainsString( 'need your attention', Import_Page::summary( $counts, Import_Report::RESULT_COMPILED, 1 ) );
		$this->assertStringContainsString( 'could not be imported', Import_Page::summary( $counts, Import_Report::RESULT_FAILED, 0 ) );
		$this->assertStringContainsString( 'no settings of WP REST Cache to import', Import_Page::summary( $counts, Import_Report::RESULT_NOTHING_FOUND, 0 ) );
	}

	public function test_a_group_without_a_title_is_called_other_findings(): void {
		$this->assertSame( 'Hooks', Import_Page::group_title( Findings::GROUP_HOOKS ) );
		$this->assertSame( 'Other findings', Import_Page::group_title( 'zz_custom' ) );
	}
}
