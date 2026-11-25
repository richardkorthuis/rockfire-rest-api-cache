<?php
/**
 * Tests for the coexistence notice: where it prints, who sees it, and its per-user dismissal.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Action_Handler;
use Rockfire\Rest_Api_Cache\Admin\Capability;
use Rockfire\Rest_Api_Cache\Admin\Coexistence_Notice;
use Rockfire\Rest_Api_Cache\Admin\Dismissals;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Admin\Coexistence_Notice
 */
class Test_Coexistence_Notice extends Rfrac_Test_Case {

	use Rfrac_Admin_Test_Helpers;

	const OWN      = 'rockfire-rest-api-cache/rockfire-rest-api-cache.php';
	const ORIGINAL = 'wp-rest-cache/wp-rest-cache.php';
	const TEXT     = 'is paused because WP REST Cache is also active.';

	/**
	 * The notice under test, registered like the container does.
	 *
	 * @var Coexistence_Notice
	 */
	private Coexistence_Notice $notice;

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		$this->block( true );
		$this->notice = new Coexistence_Notice( new Capability() );
		$this->notice->register();
		$this->as_admin();
	}

	public function tear_down() {
		$this->reset_admin_request();
		Coexistence_Guard::reset();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Make the guard block (the original is active) or not.
	 *
	 * @param bool $blocked Whether the original plugin is active.
	 */
	private function block( bool $blocked ): void {
		update_option( 'active_plugins', $blocked ? array( self::ORIGINAL, self::OWN ) : array( self::OWN ) );
		Coexistence_Guard::reset();
	}

	/**
	 * What the notice prints on a screen.
	 *
	 * @param string $screen_id Screen ID.
	 */
	private function html( string $screen_id ): string {
		set_current_screen( $screen_id );
		ob_start();
		try {
			$this->notice->render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * Post the dismissal as the browser does and return the intercepted redirect.
	 *
	 * @param string      $target       Posted target.
	 * @param string|null $nonce_target Target the nonce is made for; default: the posted one.
	 */
	private function submit( string $target, ?string $nonce_target = null ): Redirect_Intercepted {
		$this->set_request(
			array(),
			array(
				'action'   => 'rfrac_' . Coexistence_Notice::ACTION,
				'target'   => $target,
				'_wpnonce' => wp_create_nonce( Action_Handler::nonce_action( Coexistence_Notice::ACTION, $nonce_target ?? $target ) ),
			)
		);
		$_SERVER['HTTP_REFERER'] = admin_url( 'plugins.php' );
		$this->intercept_redirect();
		try {
			do_action( 'admin_post_rfrac_' . Coexistence_Notice::ACTION );
		} catch ( Redirect_Intercepted $redirect ) {
			return $redirect;
		}
		$this->fail( 'Expected a redirect.' );
	}

	/**
	 * The nonce and the target of the Dismiss form of a printed notice.
	 *
	 * @param string $html The notice.
	 *
	 * @return array{nonce:string,target:string}
	 */
	private function form_of( string $html ): array {
		$this->assertSame( 1, preg_match( '/name="target" value="([^"]+)"/', $html, $target ) );
		$this->assertSame( 1, preg_match( '/name="_wpnonce" value="([^"]+)"/', $html, $nonce ) );

		return array(
			'nonce'  => $nonce[1],
			'target' => $target[1],
		);
	}

	// ---------- Where and what it prints ----------

	public function test_the_plugins_screen_prints_the_text_and_a_site_dismiss_form_without_the_link(): void {
		$html = $this->html( 'plugins' );

		$this->assertStringContainsString( 'notice notice-error', $html );
		$this->assertStringContainsString( self::TEXT, $html );
		$this->assertStringNotContainsString( 'is-dismissible', $html );
		$this->assertStringNotContainsString( 'Go to Plugins', $html, 'Already on the Plugins screen.' );
		$this->assertStringContainsString( 'method="post"', $html );
		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $html );
		$this->assertStringContainsString( 'name="action" value="rfrac_dismiss_coexistence"', $html );
		$this->assertStringContainsString( '>Dismiss</button>', $html );

		$form = $this->form_of( $html );
		$this->assertSame( 'site', $form['target'] );
		$this->assertSame( 1, wp_verify_nonce( $form['nonce'], 'rfrac_dismiss_coexistence_site' ) );
		$this->assertFalse( wp_verify_nonce( $form['nonce'], 'rfrac_dismiss_coexistence_network' ) );
	}

	public function test_a_page_of_the_plugin_adds_the_link_to_the_plugins_screen(): void {
		foreach ( array( 'toplevel_page_rfrac-caches', 'rest-api-cache_page_rfrac-tools' ) as $screen_id ) {
			$html = $this->html( $screen_id );

			$this->assertStringContainsString( self::TEXT, $html, $screen_id );
			$this->assertStringContainsString( 'href="' . esc_url( self_admin_url( 'plugins.php' ) ) . '"', $html, $screen_id );
			$this->assertStringContainsString( 'Go to Plugins', $html, $screen_id );
			$this->assertStringContainsString( 'name="action" value="rfrac_dismiss_coexistence"', $html, $screen_id );
			$this->assertSame( 'site', $this->form_of( $html )['target'] );
		}
	}

	public function test_other_screens_print_nothing(): void {
		foreach ( array( 'dashboard', 'edit-post', 'toplevel_page_other-plugin', 'front' ) as $screen_id ) {
			$this->assertSame( '', $this->html( $screen_id ), $screen_id );
		}
	}

	public function test_nothing_prints_while_the_guard_does_not_block(): void {
		$this->block( false );

		$this->assertSame( '', $this->html( 'plugins' ) );
		$this->assertSame( '', $this->html( 'toplevel_page_rfrac-caches' ) );
	}

	public function test_nothing_prints_for_a_user_who_may_not_manage_plugins(): void {
		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$this->as_role( $role );
			$this->assertSame( '', $this->html( 'plugins' ), $role );
		}

		wp_set_current_user( 0 );
		$this->assertSame( '', $this->html( 'plugins' ), 'A visitor.' );
	}

	public function test_the_settings_capability_decides_only_the_dismiss_form(): void {
		// Nobody has `do_not_allow`, not even a super admin of a network.
		add_filter( 'rfrac/settings_capability', static fn() => 'do_not_allow' );

		$html = $this->html( 'plugins' );

		$this->assertStringContainsString( self::TEXT, $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'rfrac_dismiss_coexistence', $html );
	}

	public function test_the_plugin_capability_alone_does_not_show_the_notice(): void {
		add_filter( 'rfrac/settings_capability', static fn() => 'edit_pages' );
		$this->as_role( 'editor' );

		$this->assertSame( '', $this->html( 'plugins' ), 'The notice needs activate_plugins.' );
	}

	public function test_rendering_writes_nothing(): void {
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();

		$this->html( 'plugins' );

		$recorder->stop();
		$this->assertSame( array(), $recorder->matching( '/^\s*(INSERT|UPDATE|REPLACE|DELETE)\b/i' ) );
	}

	public function test_the_network_admin_prints_a_network_dismiss_form_for_a_super_admin(): void {
		$html = $this->html( 'plugins-network' );

		$this->assertStringContainsString( self::TEXT, $html );
		$this->assertStringNotContainsString( 'Go to Plugins', $html );
		$form = $this->form_of( $html );
		$this->assertSame( 'network', $form['target'] );
		$this->assertSame( 1, wp_verify_nonce( $form['nonce'], 'rfrac_dismiss_coexistence_network' ) );
		$this->assertFalse( wp_verify_nonce( $form['nonce'], 'rfrac_dismiss_coexistence_site' ) );
	}

	// ---------- Dismissing ----------

	public function test_a_dismissal_hides_the_notice_for_that_user_only_and_redirects_back(): void {
		$admin_a = get_current_user_id();
		$admin_b = $this->as_admin();
		wp_set_current_user( $admin_a );
		$this->assertNotSame( '', $this->html( 'plugins' ) );

		$redirect = $this->submit( 'site' );

		$this->assertSame( 303, $redirect->status );
		$this->assertSame( admin_url( 'plugins.php' ), $redirect->location, 'Back to the referer.' );
		$this->assertTrue( Dismissals::is_dismissed( Dismissals::COEXISTENCE ) );
		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE_NETWORK ) );
		$this->assertSame( '', $this->html( 'plugins' ) );
		$this->assertSame( '', $this->html( 'toplevel_page_rfrac-tools' ), 'The dismissal holds on the pages of the plugin too.' );

		wp_set_current_user( $admin_b );
		$this->assertStringContainsString( self::TEXT, $this->html( 'plugins' ), 'The other admin still sees it.' );
		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE ) );
	}

	public function test_a_nonce_of_the_site_posted_with_the_network_target_is_refused(): void {
		try {
			$this->submit( 'network', 'site' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE_NETWORK ) );
		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE ) );
		$this->assertNotSame( '', $this->html( 'plugins' ) );
	}

	public function test_a_missing_or_foreign_nonce_is_refused(): void {
		$this->set_request(
			array(),
			array(
				'action' => 'rfrac_dismiss_coexistence',
				'target' => 'site',
			)
		);
		try {
			do_action( 'admin_post_rfrac_dismiss_coexistence' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		}

		try {
			$this->submit( 'site', 'flush_all' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		}
		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE ) );
	}

	public function test_a_user_without_the_plugin_capability_cannot_dismiss(): void {
		$this->as_subscriber();

		try {
			$this->submit( 'site' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE ) );
	}

	public function test_the_network_and_the_site_are_dismissed_separately(): void {
		$this->assertNotSame( '', $this->html( 'plugins-network' ) );
		$this->assertNotSame( '', $this->html( 'plugins' ) );

		$this->submit( 'network' );

		$this->assertSame( '', $this->html( 'plugins-network' ), 'Dismissed on the network Plugins screen.' );
		$this->assertStringContainsString( self::TEXT, $this->html( 'plugins' ), 'Still shown on the site until dismissed there.' );
		$this->assertTrue( Dismissals::is_dismissed( Dismissals::COEXISTENCE_NETWORK ) );
		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE ) );

		$this->submit( 'site' );

		$this->assertSame( '', $this->html( 'plugins' ) );
		$this->assertSame( '', $this->html( 'plugins-network' ) );
	}

	public function test_a_site_dismissal_leaves_the_network_notice_showing(): void {
		$this->submit( 'site' );

		$this->assertSame( '', $this->html( 'plugins' ) );
		$this->assertStringContainsString( self::TEXT, $this->html( 'plugins-network' ) );
	}

	public function test_dismiss_stores_the_scope_of_its_target_and_returns_where_to_go_back_to(): void {
		set_current_screen( 'front' );

		$this->assertSame( self_admin_url( 'plugins.php' ), $this->notice->dismiss( 'site' ), 'No referer.' );
		$this->assertTrue( Dismissals::is_dismissed( Dismissals::COEXISTENCE ) );
		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE_NETWORK ) );

		$_SERVER['HTTP_REFERER'] = 'https://evil.example/';
		$this->assertSame( self_admin_url( 'plugins.php' ), $this->notice->dismiss( 'network' ), 'A foreign referer.' );
		$this->assertTrue( Dismissals::is_dismissed( Dismissals::COEXISTENCE_NETWORK ) );

		$_SERVER['HTTP_REFERER'] = admin_url( 'admin.php?page=rfrac-tools' );
		$this->assertSame( admin_url( 'admin.php?page=rfrac-tools' ), $this->notice->dismiss( '' ), 'An admin referer.' );
	}

	public function test_an_unknown_target_dismisses_the_site_notice_only(): void {
		$this->notice->dismiss( 'everything' );

		$this->assertTrue( Dismissals::is_dismissed( Dismissals::COEXISTENCE ) );
		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE_NETWORK ) );
	}

	// ---------- Episodes ----------

	/**
	 * Hook the episode count on `activated_plugin` like the container does, in every context.
	 *
	 * @return void
	 */
	private function count_episodes(): void {
		add_action( 'activated_plugin', array( Coexistence_Notice::class, 'on_plugin_activated' ), 10, 2 );
	}

	public function test_a_dismissal_does_not_hide_the_notice_after_wp_rest_cache_is_deactivated_and_reactivated(): void {
		$this->count_episodes();
		$this->submit( 'site' );
		$this->assertSame( '', $this->html( 'plugins' ) );

		$this->block( false );
		do_action( 'deactivated_plugin', self::ORIGINAL, false );
		$this->assertSame( '', $this->html( 'plugins' ), 'Nothing is blocked meanwhile.' );
		$this->block( true );
		do_action( 'activated_plugin', self::ORIGINAL, false );

		$this->assertStringContainsString( self::TEXT, $this->html( 'plugins' ), 'A new episode shows the notice again.' );
		$this->assertStringContainsString( self::TEXT, $this->html( 'toplevel_page_rfrac-tools' ) );

		$this->submit( 'site' );
		$this->assertSame( '', $this->html( 'plugins' ), 'The new episode can be dismissed too.' );
		$this->assertSame( '', $this->html( 'toplevel_page_rfrac-tools' ) );
	}

	public function test_a_dismissal_of_the_network_notice_ends_with_the_episode_too(): void {
		$this->count_episodes();
		$this->submit( 'network' );
		$this->assertSame( '', $this->html( 'plugins-network' ) );

		do_action( 'activated_plugin', self::ORIGINAL, true );

		$this->assertStringContainsString( self::TEXT, $this->html( 'plugins-network' ) );

		$this->submit( 'network' );
		$this->assertSame( '', $this->html( 'plugins-network' ) );
	}

	public function test_a_network_wide_activation_starts_a_new_episode_on_the_sites_without_walking_them(): void {
		$this->count_episodes();
		$this->submit( 'site' );
		$this->assertSame( '', $this->html( 'plugins' ) );

		do_action( 'activated_plugin', self::ORIGINAL, true );

		$this->assertStringContainsString( self::TEXT, $this->html( 'plugins' ) );
		if ( is_multisite() ) {
			$this->assertFalse( get_option( Coexistence_Notice::OPTION_EPISODE ), 'Counted once for the network, not on the site.' );
			$this->assertSame( 1, (int) get_site_option( Coexistence_Notice::OPTION_EPISODE ) );
		}
	}

	public function test_activating_this_plugin_starts_a_new_episode_too(): void {
		$this->count_episodes();
		$this->submit( 'site' );

		do_action( 'activated_plugin', self::OWN, false );

		$this->assertStringContainsString( self::TEXT, $this->html( 'plugins' ) );
	}

	public function test_activating_any_other_plugin_keeps_the_dismissal(): void {
		$this->count_episodes();
		$this->submit( 'site' );

		foreach ( array( 'hello-dolly/hello.php', 'wp-rest-cache-helper/helper.php', 'akismet/akismet.php' ) as $plugin ) {
			do_action( 'activated_plugin', $plugin, false );
			do_action( 'activated_plugin', $plugin, true );
		}
		do_action( 'activated_plugin', null, false );
		do_action( 'activated_plugin', '', false );

		$this->assertSame( '', $this->html( 'plugins' ) );
		$this->assertFalse( get_option( Coexistence_Notice::OPTION_EPISODE ), 'Nothing is counted.' );
	}

	public function test_a_dismissal_made_before_episodes_were_counted_holds_until_the_first_one(): void {
		$this->count_episodes();
		Dismissals::dismiss( Dismissals::COEXISTENCE );
		$this->assertSame( '', $this->html( 'plugins' ) );

		do_action( 'activated_plugin', self::ORIGINAL, false );

		$this->assertStringContainsString( self::TEXT, $this->html( 'plugins' ) );
	}

	public function test_a_garbled_episode_count_reads_as_none(): void {
		$this->count_episodes();
		update_option( Coexistence_Notice::OPTION_EPISODE, array( 'x' ), false );
		$this->submit( 'site' );
		$this->assertSame( '', $this->html( 'plugins' ) );

		do_action( 'activated_plugin', self::ORIGINAL, false );

		$this->assertStringContainsString( self::TEXT, $this->html( 'plugins' ) );
		$this->assertSame( 1, (int) get_option( Coexistence_Notice::OPTION_EPISODE ) );
	}

	public function test_an_activation_outside_wp_admin_brings_a_dismissed_notice_back(): void {
		$this->count_episodes();
		$user_id = get_current_user_id();
		$this->submit( 'site' );
		$this->assertSame( '', $this->html( 'plugins' ) );

		// WP-CLI, cron or REST: no screen and no user, and the notice itself is not hooked.
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		do_action( 'activated_plugin', self::ORIGINAL, false );
		$this->assertSame( 1, (int) get_option( Coexistence_Notice::OPTION_EPISODE ), 'The activation counted without a screen or a user.' );

		wp_set_current_user( $user_id );
		$this->assertStringContainsString( self::TEXT, $this->html( 'plugins' ), 'The notice shows again for the user who dismissed it.' );
	}

	// ---------- Wiring ----------

	public function test_it_hooks_both_notice_actions_and_registers_its_action_for_logged_in_users_only(): void {
		foreach ( array( 'admin_notices', 'network_admin_notices' ) as $hook ) {
			$this->assertSame( 10, has_action( $hook, array( $this->notice, 'render' ) ), $hook );
		}
		$this->assertNotFalse( has_action( 'admin_post_rfrac_dismiss_coexistence' ) );
		$this->assertFalse( has_action( 'admin_post_nopriv_rfrac_dismiss_coexistence' ) );
	}

	public function test_it_no_longer_hooks_the_activation_as_the_container_does_in_every_context(): void {
		// The booted container hooks the static callback; leave only what `register()` itself adds.
		remove_action( 'activated_plugin', array( Coexistence_Notice::class, 'on_plugin_activated' ), 10 );

		( new Coexistence_Notice( new Capability() ) )->register();

		$this->assertFalse( has_action( 'activated_plugin', array( Coexistence_Notice::class, 'on_plugin_activated' ) ), 'register() leaves the episode count to the container.' );
		$this->assertFalse( has_action( 'activated_plugin', array( $this->notice, 'on_plugin_activated' ) ) );
		$this->assertTrue( ( new ReflectionMethod( Coexistence_Notice::class, 'on_plugin_activated' ) )->isStatic() );
	}

	public function test_its_action_is_none_of_the_admin_actions(): void {
		$this->assertSame( 'dismiss_coexistence', Coexistence_Notice::ACTION );
		$this->assertArrayNotHasKey( Coexistence_Notice::ACTION, Plugin::instance()->admin()->action_handlers() );
	}

	// ---------- Readme ----------

	public function test_readme_describes_the_notice(): void {
		$readme = (string) file_get_contents( RFRAC_DIR . 'readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$start  = strpos( $readme, '= Can I run it next to WP REST Cache? =' );
		$this->assertIsInt( $start );
		$end = strpos( $readme, '= How do I switch from WP REST Cache? =', $start );
		$this->assertIsInt( $end );
		$answer = substr( $readme, $start, $end - $start );

		$this->assertStringContainsString( 'pauses caching completely', $answer );
		$this->assertStringContainsString( 'on the Plugins screen and on the plugin\'s own pages', $answer );
		$this->assertStringContainsString( 'each user can dismiss it', $answer );
		$this->assertStringContainsString( 'REST API Cache → Tools keeps showing that caching is paused', $answer );
		$this->assertStringContainsString( 'never deactivates WP REST Cache and never changes its settings or data', $answer );
	}
}
