<?php
/**
 * Tests for the admin foundation: menu, capability, page load order, actions, notices, assets and input.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Action_Handler;
use Rockfire\Rest_Api_Cache\Admin\Admin;
use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Admin\Capability;
use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Admin\Pages\Page_Interface;
use Rockfire\Rest_Api_Cache\Admin\Request;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Install\Mu_Installer;
use Rockfire\Rest_Api_Cache\Install\Mu_Status;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Schema\Migrator;
use Rockfire\Rest_Api_Cache\Schema\Schema;
use Rockfire\Rest_Api_Cache\Services\Status_Service;

/**
 * A configurable admin page for the foundation tests.
 */
class Rfrac_Test_Fake_Page implements Page_Interface {

	/**
	 * Calls to load().
	 *
	 * @var int
	 */
	public int $loaded = 0;

	/**
	 * Targets the actions received.
	 *
	 * @var list<string>
	 */
	public array $handled = array();

	/**
	 * Constructor.
	 *
	 * @param string $slug     Slug.
	 * @param int    $position Position.
	 * @param string $title    Title.
	 */
	public function __construct( private string $slug, private int $position, private string $title = 'Fake page' ) {
	}

	public function slug(): string {
		return $this->slug;
	}

	public function page_title(): string {
		return $this->title;
	}

	public function menu_title(): string {
		return $this->title;
	}

	public function position(): int {
		return $this->position;
	}

	public function load(): void {
		++$this->loaded;
	}

	public function render(): void {
		echo '<p>body of ' . esc_html( $this->slug ) . '</p>';
	}

	public function actions(): array {
		$prefix = str_replace( '-', '_', $this->slug );
		return array(
			$prefix . '_do'   => function ( string $target ): string {
				$this->handled[] = $target;
				return Admin_Url::page( $this->slug, array( 'done' => $target ) );
			},
			$prefix . '_pick' => array(
				'callback'         => function ( string $target ): string {
					$this->handled[] = $target;
					return Admin_Url::page( $this->slug );
				},
				'per_target_nonce' => false,
			),
		);
	}
}

/**
 * @covers \Rockfire\Rest_Api_Cache\Admin\Admin
 * @covers \Rockfire\Rest_Api_Cache\Admin\Action_Handler
 * @covers \Rockfire\Rest_Api_Cache\Admin\Admin_Url
 * @covers \Rockfire\Rest_Api_Cache\Admin\Capability
 * @covers \Rockfire\Rest_Api_Cache\Admin\Notices
 * @covers \Rockfire\Rest_Api_Cache\Admin\Request
 * @covers \Rockfire\Rest_Api_Cache\Admin\Redirect
 */
class Test_Admin_Foundation extends Rfrac_Test_Case {

	use Rfrac_Admin_Test_Helpers;

	/**
	 * Temporary must-use directory.
	 *
	 * @var string
	 */
	private string $mu_dir;

	/**
	 * Path of the must-use template the installer under test is given.
	 *
	 * @var string
	 */
	private string $template_path;

	/**
	 * Pages of the admin under test.
	 *
	 * @var list<Rfrac_Test_Fake_Page>
	 */
	private array $pages;

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->mu_dir        = rtrim( WP_CONTENT_DIR, '/\\' ) . '/rfrac-admin-mu-' . bin2hex( random_bytes( 4 ) );
		$this->template_path = RFRAC_DIR . 'mu-plugin/rfrac-early-cache.php';
		$this->pages         = array( new Rfrac_Test_Fake_Page( 'rfrac-fake-b', 20, 'Page <b>B</b>' ), new Rfrac_Test_Fake_Page( 'rfrac-fake-a', 10, 'Page A' ) );
		$this->reset_menu_globals();
		delete_option( Mu_Installer::OPTION_NOTICE );
		delete_option( Failure_Recorder::OPTION );
		delete_option( Migrator::ERROR_OPTION );
	}

	public function tear_down() {
		$this->reset_admin_request();
		$this->reset_menu_globals();
		foreach ( (array) glob( $this->mu_dir . '/*' ) as $file ) {
			if ( is_string( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		if ( is_dir( $this->mu_dir ) ) {
			rmdir( $this->mu_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		wp_dequeue_style( 'rfrac-admin' );
		wp_dequeue_script( 'rfrac-admin' );
		unset( $GLOBALS['current_screen'] );
		delete_option( Mu_Installer::OPTION_NOTICE );
		delete_option( Failure_Recorder::OPTION );
		delete_option( Migrator::ERROR_OPTION );
		parent::tear_down();
	}

	private function reset_menu_globals(): void {
		foreach ( array( 'menu', 'submenu', '_wp_submenu_nopriv', '_registered_pages', '_parent_pages', 'admin_page_hooks', '_wp_real_parent_file' ) as $global ) {
			$GLOBALS[ $global ] = array();
		}
	}

	private function status_service(): Status_Service {
		$plugin = Plugin::instance();
		return new Status_Service(
			Config::instance(),
			static fn() => $plugin->store(),
			new Schema(),
			new Mu_Installer( $plugin->basename(), $this->template_path, RFRAC_VERSION, $this->mu_dir ),
			$plugin->cache_service()
		);
	}

	private function admin( ?array $pages = null ): Admin {
		return new Admin( new Capability(), new Notices(), $this->status_service(), Plugin::instance()->cache_service(), $pages ?? $this->pages );
	}

	private function mu_file(): string {
		return $this->mu_dir . '/rfrac-early-cache.php';
	}

	/**
	 * Make a screen the current one (the screen id decides where the persistent notices print).
	 */
	private function on_screen( string $screen_id ): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		set_current_screen( $screen_id );
	}

	/**
	 * The must-use notice as the admin under test prints it.
	 */
	private function mu_notice( ?Admin $admin = null ): string {
		$admin ??= $this->admin();
		return $this->output( fn() => $admin->render_mu_notice() );
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

	/**
	 * The top-level menu entry of a slug, or null.
	 */
	private function menu_entry( string $slug ): ?array {
		foreach ( (array) $GLOBALS['menu'] as $item ) {
			if ( isset( $item[2] ) && $slug === $item[2] ) {
				return $item;
			}
		}
		return null;
	}

	// ---------- Registration ----------

	public function test_register_hooks_nothing_during_ajax(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$admin = $this->admin();
		$admin->register();

		$this->assertFalse( has_action( 'admin_menu', array( $admin, 'register_menu' ) ) );
		$this->assertFalse( has_action( 'admin_notices', array( $admin, 'render_notices' ) ) );
		$this->assertFalse( has_action( 'admin_notices', array( $admin, 'render_mu_notice' ) ) );
		$this->assertFalse( has_action( 'network_admin_notices', array( $admin, 'render_mu_notice' ) ) );
		$this->assertFalse( has_action( 'admin_post_rfrac_flush_all' ) );
	}

	public function test_register_hooks_menu_assets_notices_links_and_actions(): void {
		$admin = $this->admin();
		$admin->register();

		$this->assertSame( 10, has_action( 'admin_menu', array( $admin, 'register_menu' ) ) );
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_assets' ) ) );
		$this->assertSame( 10, has_action( 'admin_notices', array( $admin, 'render_notices' ) ) );
		$this->assertSame( 10, has_action( 'admin_notices', array( $admin, 'render_mu_notice' ) ) );
		$this->assertSame( 10, has_action( 'network_admin_notices', array( $admin, 'render_mu_notice' ) ) );
		$this->assertSame( 10, has_filter( 'plugin_action_links_' . plugin_basename( RFRAC_FILE ), array( $admin, 'plugin_action_links' ) ) );
		$this->assertSame( 10, has_action( 'admin_notices', array( $admin, 'render_plugin_change_notice' ) ) );
		$this->assertFalse( has_action( 'network_admin_notices', array( $admin, 'render_plugin_change_notice' ) ) );
		foreach ( array( 'flush_all', 'dismiss_plugin_change', 'rfrac_fake_a_do', 'rfrac_fake_a_pick', 'rfrac_fake_b_do', 'rfrac_fake_b_pick' ) as $action ) {
			$this->assertNotFalse( has_action( 'admin_post_rfrac_' . $action ), $action );
			$this->assertFalse( has_action( 'admin_post_nopriv_rfrac_' . $action ), $action );
		}
		$this->assertFalse( has_action( 'wp_ajax_rfrac_flush_caches' ) );
	}

	public function test_admin_registers_nothing_on_admin_init(): void {
		global $wp_filter;
		$admin = $this->admin();
		$admin->register();
		foreach ( isset( $wp_filter['admin_init'] ) ? $wp_filter['admin_init']->callbacks : array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				$this->assertFalse( is_array( $function ) && $function[0] instanceof Admin );
			}
		}
		foreach ( Plugin::instance()->engine_hooks() as $hook ) {
			$this->assertNotSame( 'admin_init', $hook['hook'] );
		}
	}

	public function test_duplicate_action_names_are_rejected(): void {
		$this->expectException( LogicException::class );
		$this->admin( array( new Rfrac_Test_Fake_Page( 'rfrac-fake-a', 10 ), new Rfrac_Test_Fake_Page( 'rfrac-fake-a', 20 ) ) )->action_handlers();
	}

	// ---------- Menu and capability ----------

	public function test_menu_is_registered_with_manage_options_and_pages_by_position(): void {
		$this->as_admin();
		$admin = $this->admin();
		$admin->register_menu();

		$entry = $this->menu_entry( 'rfrac-fake-a' );
		$this->assertNotNull( $entry, 'The lowest position is the top-level page.' );
		$this->assertSame( 'manage_options', $entry[1] );
		$this->assertSame( 'REST API Cache', $entry[0] );
		$this->assertSame( 'dashicons-performance', $entry[6] );

		$this->assertSame( array( 'rfrac-fake-a', 'rfrac-fake-b' ), array_column( $GLOBALS['submenu']['rfrac-fake-a'], 2 ) );
		$this->assertSame( array( 'manage_options', 'manage_options' ), array_column( $GLOBALS['submenu']['rfrac-fake-a'], 1 ) );

		$suffixes = $admin->hook_suffixes();
		$this->assertCount( 2, $suffixes );
		foreach ( $suffixes as $suffix => $page ) {
			$this->assertNotFalse( has_action( 'load-' . $suffix ), $suffix );
			$this->assertNotFalse( has_action( $suffix ), $suffix );
		}
		$this->assertSame( array( 'rfrac-fake-a', 'rfrac-fake-b' ), array_map( static fn( $page ) => $page->slug(), array_values( $suffixes ) ) );
	}

	public function test_filtered_capability_lets_an_editor_see_the_menu(): void {
		$this->as_role( 'editor' );
		$this->admin()->register_menu();
		$this->assertNull( $this->menu_entry( 'rfrac-fake-a' ), 'An editor has no manage_options.' );

		$this->reset_menu_globals();
		add_filter( 'rfrac/settings_capability', static fn() => 'edit_posts' );
		$this->admin()->register_menu();
		$entry = $this->menu_entry( 'rfrac-fake-a' );
		$this->assertNotNull( $entry );
		$this->assertSame( 'edit_posts', $entry[1] );
	}

	public function test_subscriber_gets_no_menu(): void {
		$this->as_subscriber();
		$admin = $this->admin();
		$admin->register_menu();
		$this->assertNull( $this->menu_entry( 'rfrac-fake-a' ) );
		$this->assertSame( array(), $admin->hook_suffixes() );
	}

	public function test_capability_falls_back_to_manage_options_for_an_empty_filter_result(): void {
		add_filter( 'rfrac/settings_capability', '__return_empty_string' );
		$this->assertSame( 'manage_options', ( new Capability() )->get() );
	}

	// ---------- Page load and render ----------

	public function test_load_checks_capability_then_fires_page_load_then_loads_the_page(): void {
		$this->as_admin();
		$admin  = $this->admin();
		$page   = $this->pages[1];
		$events = array();
		add_action(
			Hooks::ADMIN_PAGE_LOAD,
			function ( $slug ) use ( &$events, $page ) {
				$events[] = array( $slug, $page->loaded );
			}
		);

		$admin->load( $page );

		$this->assertSame( array( array( 'rfrac-fake-a', 0 ) ), $events, 'The action fired before the page loaded.' );
		$this->assertSame( 1, $page->loaded );
	}

	public function test_load_dies_for_a_user_without_the_capability_before_any_work(): void {
		$this->as_subscriber();
		$fired = did_action( Hooks::ADMIN_PAGE_LOAD );
		try {
			$this->admin()->load( $this->pages[0] );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		}
		$this->assertSame( $fired, did_action( Hooks::ADMIN_PAGE_LOAD ) );
		$this->assertSame( 0, $this->pages[0]->loaded );
		$this->assertFileDoesNotExist( $this->mu_file() );
	}

	public function test_render_rechecks_the_capability(): void {
		$this->as_subscriber();
		$this->expectException( WPDieException::class );
		$this->output( fn() => $this->admin()->render( $this->pages[0] ) );
	}

	public function test_render_wraps_the_page_with_an_escaped_title(): void {
		$this->as_admin();
		$html = $this->output( fn() => $this->admin()->render( $this->pages[0] ) );
		$this->assertStringContainsString( '<div class="wrap rfrac-wrap">', $html );
		$this->assertStringContainsString( 'Page &lt;b&gt;B&lt;/b&gt;', $html );
		$this->assertStringContainsString( '<p>body of rfrac-fake-b</p>', $html );
	}

	// ---------- Actions ----------

	public function test_page_action_requires_the_capability(): void {
		$this->as_subscriber();
		$this->admin()->register();
		$this->set_request( array( 'target' => '5', '_wpnonce' => wp_create_nonce( 'rfrac_rfrac_fake_a_do_5' ) ) );
		try {
			do_action( 'admin_post_rfrac_rfrac_fake_a_do' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		}
		$this->assertSame( array(), $this->pages[1]->handled );
	}

	public function test_page_action_rejects_a_bad_nonce_and_a_nonce_of_another_target(): void {
		$this->as_admin();
		$this->admin()->register();
		foreach ( array( 'bad' => 'nope', 'other target' => wp_create_nonce( 'rfrac_rfrac_fake_a_do_5' ) ) as $case => $nonce ) {
			$this->set_request( array( 'target' => '6', '_wpnonce' => $nonce ) );
			try {
				do_action( 'admin_post_rfrac_rfrac_fake_a_do' );
				$this->fail( "Expected wp_die ($case)." );
			} catch ( WPDieException $e ) {
				$this->addToAssertionCount( 1 );
			}
		}
		$this->assertSame( array(), $this->pages[1]->handled );
	}

	public function test_page_action_with_a_valid_nonce_runs_and_redirects_303(): void {
		$this->as_admin();
		$this->admin()->register();
		$url = Admin_Url::action( 'rfrac_fake_a_do', '5' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'rfrac_rfrac_fake_a_do', $query['action'] );
		$this->set_request( $query );

		$redirect = $this->run_action( 'rfrac_fake_a_do' );

		$this->assertSame( array( '5' ), $this->pages[1]->handled );
		$this->assertSame( 303, $redirect->status );
		$this->assertSame( Admin_Url::page( 'rfrac-fake-a', array( 'done' => '5' ) ), $redirect->location );
	}

	public function test_action_without_per_target_nonce_accepts_any_target(): void {
		$this->as_admin();
		$this->admin()->register();
		$this->set_request( array(), array( 'target' => 'abc', '_wpnonce' => wp_create_nonce( 'rfrac_rfrac_fake_a_pick' ) ) );

		$redirect = $this->run_action( 'rfrac_fake_a_pick' );

		$this->assertSame( array( 'abc' ), $this->pages[1]->handled );
		$this->assertSame( 303, $redirect->status );
	}

	public function test_flush_all_requires_the_capability_and_flushes_nothing(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry();
		$this->as_subscriber();
		$this->admin()->register();
		$this->set_request( array( '_wpnonce' => wp_create_nonce( 'rfrac_flush_all' ) ) );
		try {
			do_action( 'admin_post_rfrac_flush_all' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		}
		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ) );
	}

	public function test_flush_all_rejects_a_bad_nonce(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry();
		$this->as_admin();
		$this->admin()->register();
		$this->set_request( array( '_wpnonce' => 'nope' ) );
		$this->expectException( WPDieException::class );
		try {
			do_action( 'admin_post_rfrac_flush_all' );
		} finally {
			$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ) );
		}
	}

	public function test_flush_all_flushes_queues_a_notice_and_redirects_303_same_site(): void {
		$ids     = array( Rfrac_Entry_Fixtures::insert_entry(), Rfrac_Entry_Fixtures::insert_entry() );
		$user_id = $this->as_admin();
		$this->admin()->register();
		$this->set_request( array( '_wpnonce' => wp_create_nonce( 'rfrac_flush_all' ) ) );

		$redirect = $this->run_action( 'flush_all' );

		foreach ( $ids as $id ) {
			$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
		}
		$this->assertSame( 303, $redirect->status );
		$this->assertSame( $redirect->location, wp_validate_redirect( $redirect->location, '' ), 'Same-site location.' );
		$this->assertSame( Admin_Url::page( Admin::TOOLS_SLUG ), $redirect->location );
		$this->assertSame( array( array( 'type' => 'success', 'message' => 'Flushed 2 cached responses.' ) ), ( new Notices() )->queued( $user_id ) );
	}

	public function test_flush_all_returns_to_a_same_site_referer_only(): void {
		$this->as_admin();
		$this->admin()->register();
		$this->set_request( array( '_wpnonce' => wp_create_nonce( 'rfrac_flush_all' ) ) );

		$_SERVER['HTTP_REFERER'] = admin_url( 'edit.php' );
		$this->assertSame( admin_url( 'edit.php' ), $this->run_action( 'flush_all' )->location );

		$_SERVER['HTTP_REFERER'] = 'https://evil.example/';
		$this->assertSame( Admin_Url::page( Admin::TOOLS_SLUG ), $this->run_action( 'flush_all' )->location );
	}

	// ---------- Notices ----------

	public function test_a_request_without_queued_notices_writes_no_user_meta(): void {
		$this->as_admin();
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$html = $this->output( fn() => $this->admin()->render_notices() );
		$recorder->stop();

		$this->assertSame( '', $html );
		$this->assertSame( array(), $recorder->matching( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b.*usermeta/is' ) );
	}

	public function test_a_queued_notice_is_stored_under_the_blog_prefixed_key_only(): void {
		global $wpdb;
		$user_id = $this->as_admin();
		( new Notices() )->info( 'Stored per site.' );

		$this->assertSame( $wpdb->get_blog_prefix() . 'rfrac_notices', Notices::meta_key() );
		$this->assertCount( 1, get_user_meta( $user_id, $wpdb->get_blog_prefix() . 'rfrac_notices', true ) );
		$this->assertSame( '', get_user_meta( $user_id, 'rfrac_notices', true ) );
	}

	public function test_a_rendered_notice_is_escaped_and_deleted(): void {
		$user_id = $this->as_admin();
		( new Notices() )->error( '<img src=x onerror=alert(1)>' );
		$this->assertNotSame( '', get_user_meta( $user_id, Notices::meta_key(), true ) );

		$html = $this->output( fn() => $this->admin()->render_notices() );

		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertSame( '', get_user_meta( $user_id, Notices::meta_key(), true ) );
		$this->assertSame( '', $this->output( fn() => $this->admin()->render_notices() ), 'Shown once.' );
	}

	public function test_notices_are_deduplicated_and_capped(): void {
		$user_id = $this->as_admin();
		$notices = new Notices();
		$notices->success( 'same' );
		$notices->success( 'same' );
		$this->assertCount( 1, $notices->queued() );

		for ( $i = 1; $i <= 12; $i++ ) {
			$notices->info( "n$i" );
		}
		$queued = $notices->queued( $user_id );
		$this->assertCount( Notices::MAX, $queued );
		$this->assertSame( 'n12', $queued[9]['message'] );
		$this->assertSame( 'n3', $queued[0]['message'] );
	}

	public function test_notices_need_a_user(): void {
		wp_set_current_user( 0 );
		( new Notices() )->success( 'x' );
		$this->assertSame( array(), ( new Notices() )->queued() );
	}

	public function test_plugin_activation_and_deactivation_queue_no_flash_notice(): void {
		global $wp_filter;
		$user_id = $this->as_admin();
		$admin   = $this->admin();
		$admin->register();

		do_action( 'activated_plugin', 'other/other.php', false );
		do_action( 'deactivated_plugin', 'other/other.php', false );

		$this->assertSame( array(), ( new Notices() )->queued( $user_id ), 'The plugin-change notice replaced the one-shot flash.' );
		foreach ( array( 'activated_plugin', 'deactivated_plugin' ) as $hook ) {
			foreach ( isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array() as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					$this->assertFalse( is_array( $function ) && $function[0] instanceof Admin, "The admin hooks nothing on $hook." );
				}
			}
		}
	}

	public function test_status_notices_show_only_on_plugin_pages_and_are_escaped(): void {
		$this->as_admin();
		update_option( Migrator::ERROR_OPTION, 'Table <b>x</b> missing', false );
		Failure_Recorder::record( 'hook:init', '<script>alert(1)</script>' );

		$admin = $this->admin();
		$this->assertSame( '', $this->output( fn() => $admin->render_notices() ), 'Not on other admin screens.' );

		$admin->load( $this->pages[0] );
		$html = $this->output( fn() => $admin->render_notices() );
		$this->assertStringContainsString( 'Table &lt;b&gt;x&lt;/b&gt; missing', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringContainsString( 'hook:init', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_old_recorded_failures_are_not_shown(): void {
		$this->as_admin();
		update_option(
			Failure_Recorder::OPTION,
			array(
				'context' => 'compile',
				'message' => 'old failure',
				'time'    => time() - 2 * DAY_IN_SECONDS,
				'count'   => 1,
			),
			false
		);
		$html = $this->output( fn() => $this->admin()->render_status_notices() );
		$this->assertStringNotContainsString( 'old failure', $html );
	}

	// ---------- Dismissing the recorded failure ----------

	public function test_recorded_failure_notice_links_to_a_nonced_dismiss_action(): void {
		$this->as_admin();
		Failure_Recorder::record( 'capture', 'boom' );

		$html = $this->output( fn() => $this->admin()->render_status_notices() );
		$this->assertStringContainsString( 'boom', $html );
		$this->assertSame( 1, preg_match( '/<a href="([^"]+)">Dismiss this error<\/a>/', $html, $m ) );
		parse_str( (string) wp_parse_url( html_entity_decode( $m[1] ), PHP_URL_QUERY ), $query );
		$this->assertStringStartsWith( admin_url( 'admin-post.php' ), html_entity_decode( $m[1] ) );
		$this->assertSame( 'rfrac_dismiss_error', $query['action'] );
		$this->assertSame( 1, wp_verify_nonce( $query['_wpnonce'], 'rfrac_dismiss_error' ) );
		$this->assertStringNotContainsString( 'is-dismissible', $html, 'Only the server-side dismissal hides it.' );
	}

	public function test_dismiss_error_clears_the_failure_and_redirects_same_site(): void {
		$this->as_admin();
		Failure_Recorder::record( 'capture', 'boom' );
		$admin = $this->admin();
		$admin->register();
		$this->assertNotFalse( has_action( 'admin_post_rfrac_dismiss_error' ) );
		$this->assertFalse( has_action( 'admin_post_nopriv_rfrac_dismiss_error' ) );

		$this->set_request( array( '_wpnonce' => wp_create_nonce( 'rfrac_dismiss_error' ) ) );
		$_SERVER['HTTP_REFERER'] = Admin_Url::page( 'rfrac-fake-a' );
		$redirect                = $this->run_action( 'dismiss_error' );

		$this->assertNull( Failure_Recorder::last() );
		$this->assertSame( 303, $redirect->status );
		$this->assertSame( Admin_Url::page( 'rfrac-fake-a' ), $redirect->location );
		$this->assertStringNotContainsString( 'boom', $this->output( fn() => $admin->render_status_notices() ) );

		Failure_Recorder::record( 'capture', 'boom' );
		$_SERVER['HTTP_REFERER'] = 'https://evil.example/';
		$this->assertSame( Admin_Url::page( Admin::TOOLS_SLUG ), $this->run_action( 'dismiss_error' )->location );
	}

	public function test_dismiss_error_requires_the_capability(): void {
		Failure_Recorder::record( 'capture', 'boom' );
		$this->as_subscriber();
		$this->admin()->register();
		$this->set_request( array( '_wpnonce' => wp_create_nonce( 'rfrac_dismiss_error' ) ) );
		try {
			do_action( 'admin_post_rfrac_dismiss_error' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		}
		$this->assertNotNull( Failure_Recorder::last(), 'Nothing was cleared.' );
	}

	/**
	 * @dataProvider bad_dismiss_nonces
	 */
	public function test_dismiss_error_rejects_a_missing_or_foreign_nonce( ?string $nonce_action ): void {
		Failure_Recorder::record( 'capture', 'boom' );
		$this->as_admin();
		$this->admin()->register();
		$this->set_request( null === $nonce_action ? array() : array( '_wpnonce' => wp_create_nonce( $nonce_action ) ) );
		try {
			do_action( 'admin_post_rfrac_dismiss_error' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		}
		$this->assertNotNull( Failure_Recorder::last(), 'Nothing was cleared.' );
	}

	public static function bad_dismiss_nonces(): array {
		return array(
			'missing'           => array( null ),
			'another action'    => array( 'rfrac_flush_all' ),
			'unprefixed action' => array( 'dismiss_error' ),
		);
	}

	public function test_mu_notice_gives_manual_copy_instructions_after_a_failed_attempt_that_needs_them(): void {
		$this->as_admin();
		$this->on_screen( 'plugins' );
		// A readable copy of the template inside wp-content: the installer refuses an unreadable one, and the notice only names it.
		wp_mkdir_p( $this->mu_dir );
		$this->template_path = $this->mu_dir . '/template-source.php';
		copy( RFRAC_DIR . 'mu-plugin/rfrac-early-cache.php', $this->template_path );
		foreach ( array( 'not_writable', 'not_direct' ) as $code ) {
			update_option(
				Mu_Installer::OPTION_NOTICE,
				array(
					'status' => 'missing',
					'code'   => $code,
					'time'   => time(),
				),
				false
			);
			$html = $this->mu_notice();
			$this->assertStringContainsString( 'fallback mode', $html, $code );
			$this->assertStringContainsString( 'The last install attempt failed:', $html, $code );
			$this->assertStringNotContainsString( 'install it automatically', $html, $code );
			$this->assertStringContainsString( 'To install the must-use file manually, copy', $html, $code );
			$this->assertStringContainsString( '<code class="rfrac-path">' . esc_html( basename( $this->mu_dir ) . '/template-source.php' ) . '</code>', $html, $code );
			$this->assertStringContainsString( '<code class="rfrac-path">' . esc_html( basename( $this->mu_dir ) . '/rfrac-early-cache.php' ) . '</code>', $html, $code );
			$this->assertStringContainsString( 'The paths are relative to the wp-content directory.', $html, $code );
			$this->assertStringNotContainsString( esc_html( wp_normalize_path( $this->mu_file() ) ), $html, $code );
			$this->assertStringNotContainsString( esc_html( trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) ), $html, $code );
			$this->assertStringContainsString( esc_html( plugin_basename( RFRAC_FILE ) ), $html, $code );
		}

		update_option(
			Mu_Installer::OPTION_NOTICE,
			array(
				'status' => 'missing',
				'code'   => 'write_failed',
				'time'   => time(),
			),
			false
		);
		$html = $this->mu_notice();
		$this->assertStringContainsString( 'fallback mode', $html );
		$this->assertStringNotContainsString( 'To install the must-use file manually', $html );
	}

	/**
	 * @dataProvider mu_paths_outside_wp_content
	 */
	public function test_mu_notice_prints_no_absolute_path_for_a_path_outside_wp_content( bool $template_outside, bool $target_outside ): void {
		$elsewhere = dirname( rtrim( WP_CONTENT_DIR, '/\\' ) ) . '/rfrac-elsewhere';
		if ( $template_outside ) {
			$this->template_path = $elsewhere . '/linked/rockfire-rest-api-cache/mu-plugin/rfrac-early-cache.php';
		} else {
			$this->template_path = rtrim( WP_CONTENT_DIR, '/\\' ) . '/plugins/rockfire-rest-api-cache/mu-plugin/rfrac-early-cache.php';
		}
		if ( $target_outside ) {
			$this->mu_dir = $elsewhere . '/mu';
		}
		$this->as_admin();
		$this->on_screen( 'plugins' );
		update_option(
			Mu_Installer::OPTION_NOTICE,
			array(
				'status' => 'missing',
				'code'   => 'not_writable',
				'time'   => time(),
			),
			false
		);

		$html = $this->mu_notice();

		$this->assertStringContainsString( 'To install the must-use file manually, copy', $html );
		$this->assertStringContainsString( '<code class="rfrac-path">' . ( $template_outside ? "\u{2026}/mu-plugin/" : 'wp-content/plugins/rockfire-rest-api-cache/mu-plugin/' ) . 'rfrac-early-cache.php</code>', $html );
		$this->assertStringContainsString( '<code class="rfrac-path">' . ( $target_outside ? "\u{2026}/mu/" : 'wp-content/' . basename( $this->mu_dir ) . '/' ) . 'rfrac-early-cache.php</code>', $html );
		$this->assertStringNotContainsString( 'rfrac-elsewhere', $html );
		$this->assertStringNotContainsString( esc_html( wp_normalize_path( $this->template_path ) ), $html );
		$this->assertStringNotContainsString( esc_html( wp_normalize_path( $this->mu_file() ) ), $html );
		$this->assertStringNotContainsString( 'The paths are relative to the wp-content directory.', $html );
	}

	/**
	 * @return array<string,array{bool,bool}>
	 */
	public static function mu_paths_outside_wp_content(): array {
		return array(
			'template outside (symlinked plugin)' => array( true, false ),
			'target outside (WPMU_PLUGIN_DIR)'    => array( false, true ),
			'both outside'                        => array( true, true ),
		);
	}

	public function test_no_mu_notice_once_the_file_is_installed(): void {
		$this->as_admin();
		$this->on_screen( 'plugins' );
		$this->status_service()->install_mu();
		$admin = $this->admin();
		$this->assertFileExists( $this->mu_file() );
		$this->assertSame( '', $this->mu_notice( $admin ) );
		$this->assertStringNotContainsString( 'fallback mode', $this->output( fn() => $admin->render_status_notices() ) );
	}

	public function test_the_mu_notice_prints_an_install_form_on_the_plugins_screen_and_the_plugin_pages_but_not_elsewhere(): void {
		$this->as_admin();
		wp_mkdir_p( $this->mu_dir );
		$admin = $this->admin();

		$this->on_screen( 'plugins' );
		$html = $this->output(
			function () use ( $admin ): void {
				$admin->render_notices();
				$admin->render_mu_notice();
			}
		);
		$this->assertSame( 1, substr_count( $html, 'runs in fallback mode' ) );
		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $html );
		$this->assertStringContainsString( 'method="post"', $html );
		$this->assertStringContainsString( 'name="action" value="rfrac_install_mu"', $html );
		$this->assertSame( 1, preg_match( '/name="_wpnonce" value="([^"]+)"/', $html, $m ) );
		$this->assertSame( 1, wp_verify_nonce( $m[1], 'rfrac_install_mu' ) );
		$this->assertStringContainsString( 'button button-primary', $html );
		$this->assertStringContainsString( '>Install must-use file</button>', $html );
		$this->assertStringNotContainsString( 'To install the must-use file manually', $html );
		$this->assertStringNotContainsString( 'install it automatically', $html );

		// A plugin page loads (so the status notices print too) and shows the fallback text once.
		$this->on_screen( 'toplevel_page_rfrac-fake-a' );
		$admin->load( $this->pages[0] );
		$html = $this->output(
			function () use ( $admin ): void {
				$admin->render_notices();
				$admin->render_mu_notice();
			}
		);
		$this->assertSame( 1, substr_count( $html, 'runs in fallback mode' ) );
		$this->assertStringContainsString( 'name="action" value="rfrac_install_mu"', $html );

		$this->on_screen( 'dashboard' );
		$this->assertSame( '', $this->mu_notice( $admin ) );
		$this->on_screen( 'edit-post' );
		$this->assertSame( '', $this->mu_notice( $admin ) );
		unset( $GLOBALS['current_screen'] );
		$this->assertSame( '', $this->mu_notice( $admin ), 'No screen, no notice.' );
	}

	public function test_the_mu_notice_also_shows_for_an_outdated_file_and_on_the_network_plugins_screen(): void {
		$this->as_admin();
		wp_mkdir_p( $this->mu_dir );
		file_put_contents( $this->mu_file(), '<?php // old' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents

		$this->on_screen( 'plugins-network' );

		$html = $this->mu_notice();
		$this->assertStringContainsString( 'runs in fallback mode', $html );
		$this->assertStringContainsString( 'name="action" value="rfrac_install_mu"', $html );
	}

	public function test_the_mu_notice_prints_nothing_and_counts_no_entries_for_a_current_or_disabled_file(): void {
		$this->as_admin();
		$this->on_screen( 'plugins' );
		$this->status_service()->install_mu();
		$this->assertFileExists( $this->mu_file() );

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$html = $this->mu_notice();
		$recorder->stop();
		$this->assertSame( '', $html, 'A current file.' );
		$this->assertSame( array(), $recorder->matching( '/rfrac_entries/' ), 'No stats query.' );

		$service = $this->getMockBuilder( Status_Service::class )->disableOriginalConstructor()->onlyMethods( array( 'mu_status', 'get' ) )->getMock();
		$service->method( 'mu_status' )->willReturn( Mu_Status::DISABLED );
		$service->expects( $this->never() )->method( 'get' );
		$admin = new Admin( new Capability(), new Notices(), $service, Plugin::instance()->cache_service(), $this->pages );
		$this->assertSame( '', $this->mu_notice( $admin ), 'A disabled install.' );
	}

	public function test_the_mu_notice_is_for_a_user_who_may_install_and_use_the_plugin(): void {
		wp_mkdir_p( $this->mu_dir );
		$this->on_screen( 'plugins' );

		$this->as_role( 'editor' );
		$this->assertSame( '', $this->mu_notice(), 'An editor.' );

		// The filter gives an editor the plugin capability; installing a must-use plugin still needs activate_plugins.
		add_filter( 'rfrac/settings_capability', static fn() => 'edit_others_posts' );
		$this->assertSame( '', $this->mu_notice(), 'An editor with the plugin capability.' );
		remove_all_filters( 'rfrac/settings_capability' );

		$this->as_subscriber();
		$this->assertSame( '', $this->mu_notice(), 'A subscriber.' );

		wp_set_current_user( 0 );
		$this->assertSame( '', $this->mu_notice(), 'A visitor.' );

		$this->as_admin();
		$this->assertStringContainsString( 'runs in fallback mode', $this->mu_notice(), 'An administrator.' );

		// On a single site the plugin capability is required too.
		add_filter( 'rfrac/settings_capability', static fn() => 'do_not_allow' );
		$this->assertSame( '', $this->mu_notice(), 'An administrator without the plugin capability.' );
	}

	public function test_the_mu_notice_has_no_button_when_file_changes_are_disallowed(): void {
		$this->as_admin();
		$this->on_screen( 'plugins' );
		wp_mkdir_p( $this->mu_dir );
		add_filter( 'file_mod_allowed', '__return_false' );

		$html = $this->mu_notice();

		$this->assertStringContainsString( 'runs in fallback mode', $html );
		$this->assertStringContainsString( 'DISALLOW_FILE_MODS', $html );
		$this->assertStringContainsString( 'To install the must-use file manually, copy', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( '<button', $html );
		$this->assertStringNotContainsString( 'rfrac_install_mu', $html );
		$this->assertStringNotContainsString( 'install it automatically', $html );
		$this->assertStringNotContainsString( 'make the directory writable', $html );
	}

	public function test_the_mu_notice_has_no_button_for_a_directory_that_is_not_writable(): void {
		$this->as_admin();
		$this->on_screen( 'plugins' );
		$in_the_way = rtrim( WP_CONTENT_DIR, '/\\' ) . '/rfrac-admin-mu-file-' . bin2hex( random_bytes( 4 ) );
		file_put_contents( $in_the_way, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
		$this->mu_dir = $in_the_way . '/mu'; // A file stands where the directory above it should be: it cannot be created.

		try {
			$html = $this->mu_notice();
		} finally {
			unlink( $in_the_way ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		$this->assertStringContainsString( 'runs in fallback mode', $html );
		$this->assertStringContainsString( 'The must-use plugins directory is not writable.', $html );
		$this->assertStringContainsString( 'To install the must-use file manually, copy', $html );
		$this->assertStringContainsString( 'Or make the directory writable, reload this page and click Install must-use file.', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( '<button', $html );
		$this->assertStringNotContainsString( 'rfrac_install_mu', $html );
		$this->assertStringNotContainsString( 'install it automatically', $html );
	}

	public function test_the_mu_notice_has_no_button_when_the_filesystem_method_is_not_direct(): void {
		$this->as_admin();
		$this->on_screen( 'plugins' );
		wp_mkdir_p( $this->mu_dir );
		$this->assertTrue( wp_is_writable( $this->mu_dir ) );
		add_filter( 'filesystem_method', static fn() => 'ftpext' );

		$html = $this->mu_notice();

		$this->assertStringContainsString( 'runs in fallback mode', $html );
		$this->assertStringContainsString( 'FS_METHOD is not &quot;direct&quot;', $html );
		$this->assertStringContainsString( 'To install the must-use file manually, copy', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( '<button', $html );
		$this->assertStringNotContainsString( 'rfrac_install_mu', $html );
		$this->assertStringNotContainsString( 'Install must-use file', $html );
		$this->assertStringNotContainsString( 'The last install attempt failed', $html );
		$this->assertStringNotContainsString( 'make the directory writable', $html );
	}

	public function test_the_mu_notice_offers_the_install_exactly_where_the_installer_has_no_blocker(): void {
		$this->as_admin();
		$this->on_screen( 'plugins' );
		wp_mkdir_p( $this->mu_dir );

		$this->assertNull( $this->status_service()->mu_install_blocker() );
		$this->assertStringContainsString( 'name="action" value="rfrac_install_mu"', $this->mu_notice() );

		add_filter( 'filesystem_method', static fn() => 'ftpext' );
		$this->assertSame( 'not_direct', $this->status_service()->mu_install_blocker() );
		$this->assertStringNotContainsString( 'rfrac_install_mu', $this->mu_notice() );
	}

	public function test_the_mu_notice_writes_nothing(): void {
		$this->as_admin();
		$this->on_screen( 'plugins' );
		wp_mkdir_p( $this->mu_dir );
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();

		$this->mu_notice();

		$recorder->stop();
		$this->assertSame( array(), $recorder->matching( '/^\s*(INSERT|UPDATE|REPLACE|DELETE)\b/i' ) );
		$this->assertFileDoesNotExist( $this->mu_file() );
	}

	public function test_a_flush_all_from_the_plugins_screen_returns_to_it_and_a_front_end_referer_to_the_tools_page(): void {
		$this->as_admin();
		$this->admin()->register();
		$this->set_request( array( '_wpnonce' => wp_create_nonce( 'rfrac_flush_all' ) ) );

		$_SERVER['HTTP_REFERER'] = admin_url( 'plugins.php' );
		$this->assertSame( admin_url( 'plugins.php' ), $this->run_action( 'flush_all' )->location );

		$_SERVER['HTTP_REFERER'] = home_url( '/sample-page/' );
		$this->assertSame( Admin_Url::page( Admin::TOOLS_SLUG ), $this->run_action( 'flush_all' )->location );
	}

	// ---------- Assets and links ----------

	public function test_assets_load_only_on_the_plugin_pages(): void {
		$this->as_admin();
		$admin = $this->admin();
		$admin->register_menu();

		$admin->enqueue_assets( 'index.php' );
		$this->assertFalse( wp_style_is( 'rfrac-admin' ) );
		$this->assertFalse( wp_script_is( 'rfrac-admin' ) );

		$admin->enqueue_assets( (string) array_key_first( $admin->hook_suffixes() ) );
		$this->assertTrue( wp_style_is( 'rfrac-admin' ) );
		$this->assertTrue( wp_script_is( 'rfrac-admin' ) );
		$this->assertSame( RFRAC_VERSION, wp_scripts()->registered['rfrac-admin']->ver );
		$this->assertSame( array(), wp_scripts()->registered['rfrac-admin']->deps );
		$this->assertSame( RFRAC_VERSION, wp_styles()->registered['rfrac-admin']->ver );
	}

	public function test_plugin_action_link_points_to_the_landing_page_for_capable_users(): void {
		$this->as_admin();
		$links = $this->admin()->plugin_action_links( array( 'deactivate' => 'x' ) );
		$this->assertCount( 2, $links );
		$this->assertStringContainsString( esc_url( Admin_Url::page( 'rfrac-fake-a' ) ), $links[0] );

		$this->as_subscriber();
		$this->assertSame( array( 'deactivate' => 'x' ), $this->admin()->plugin_action_links( array( 'deactivate' => 'x' ) ) );
	}

	// ---------- URLs and input ----------

	public function test_admin_url_builds_nonced_action_urls_and_form_fields(): void {
		$this->as_admin();
		$url = Admin_Url::action( 'flush_entry', '5' );
		$this->assertStringStartsWith( admin_url( 'admin-post.php' ), $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'rfrac_flush_entry', $query['action'] );
		$this->assertSame( '5', $query['target'] );
		$this->assertSame( 1, wp_verify_nonce( $query['_wpnonce'], 'rfrac_flush_entry_5' ) );
		$this->assertFalse( wp_verify_nonce( $query['_wpnonce'], 'rfrac_flush_entry_6' ) );
		$this->assertSame( 'rfrac_flush_entry_5', Action_Handler::nonce_action( 'flush_entry', '5' ) );
		$this->assertSame( 'rfrac_flush_all', Action_Handler::nonce_action( 'flush_all' ) );

		$html = $this->output( static fn() => Admin_Url::form_fields( 'save_route', 'abc' ) );
		$this->assertStringContainsString( 'name="action" value="rfrac_save_route"', $html );
		$this->assertStringContainsString( 'name="target" value="abc"', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( 'rfrac_save_route_abc' ) . '"', $html );

		$this->assertSame( admin_url( 'admin.php?page=rfrac-caches&s=a%20b%26c' ), Admin_Url::page( 'rfrac-caches', array( 's' => 'a b&c' ) ) );
	}

	public function test_request_sanitises_every_type(): void {
		$this->set_request(
			array(
				'key'   => 'Abc-1<script>',
				'text'  => "  hi <b>there</b>\n",
				'int'   => '-7',
				'bool'  => 'on',
				'url'   => 'javascript:alert(1)',
				'post'  => 'from get',
			),
			array(
				'keys'  => array( 'Post', 'page', 'post', '<x>' ),
				'texts' => "utm_source\nfoo, bar\n\n<b>baz</b>",
				'ids'   => '3, 0, 7, 3',
				'goto'  => 'https://example.org/x?y=1',
				'post'  => 'from post',
				'quote' => "it's",
			)
		);

		$this->assertTrue( Request::has( 'key' ) );
		$this->assertFalse( Request::has( 'missing' ) );
		$this->assertSame( 'abc-1script', Request::key( 'key' ) );
		$this->assertSame( 'dflt', Request::key( 'missing', 'dflt' ) );
		$this->assertSame( 'hi there', Request::text( 'text' ) );
		$this->assertSame( "it's", Request::text( 'quote' ), 'Unslashed.' );
		$this->assertSame( 7, Request::int( 'int' ) );
		$this->assertSame( 9, Request::int( 'missing', 9 ) );
		$this->assertTrue( Request::bool( 'bool' ) );
		$this->assertFalse( Request::bool( 'text' ) );
		$this->assertSame( array( 'post', 'page' ), Request::key_list( 'keys' ), 'A tag is stripped, not turned into a key.' );
		$this->assertSame( array( 'utm_source', 'foo', 'bar', 'baz' ), Request::text_list( 'texts' ) );
		$this->assertSame( array( 3, 7 ), Request::int_list( 'ids' ) );
		$this->assertSame( '', Request::url( 'url' ) );
		$this->assertSame( 'https://example.org/x?y=1', Request::url( 'goto' ) );
		$this->assertSame( 'from post', Request::text( 'post' ), 'POST wins over GET.' );
		$this->assertSame( array(), Request::key_list( 'missing' ) );
	}

	// ---------- Anonymous AJAX (M4) ----------

	public function test_anonymous_ajax_admin_init_does_no_plugin_work(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );
		add_filter( 'wp_doing_ajax', '__return_true' );
		wp_set_current_user( 0 );
		$mu_notice = get_option( Mu_Installer::OPTION_NOTICE, null );
		$fired     = did_action( Hooks::ADMIN_PAGE_LOAD );

		// Core callbacks that send headers cannot run under PHPUnit; they are not the plugin's.
		remove_action( 'admin_init', 'wp_admin_headers' );
		remove_action( 'admin_init', 'send_frame_options_header' );

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$plugin = new Plugin();
		$plugin->register_admin_hooks();
		do_action( 'admin_init' );
		$recorder->stop();

		$this->assertSame( array(), $recorder->matching( '/^\s*(INSERT|UPDATE|REPLACE|DELETE)\b.*rfrac/is' ) );
		$this->assertSame( $mu_notice, get_option( Mu_Installer::OPTION_NOTICE, null ), 'No must-use write.' );
		$this->assertSame( $fired, did_action( Hooks::ADMIN_PAGE_LOAD ) );
		$this->assertFalse( has_action( 'admin_menu', array( $plugin->admin(), 'register_menu' ) ) );
	}

	public function test_wp_admin_request_registers_the_admin_through_the_container(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		set_current_screen( 'dashboard' );
		$plugin = new Plugin();
		$plugin->register_admin_hooks();
		$this->assertSame( 10, has_action( 'admin_menu', array( $plugin->admin(), 'register_menu' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_rfrac_flush_all' ) );
	}
}
