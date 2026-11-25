<?php
/**
 * Tests for the Settings admin page.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Admin;
use Rockfire\Rest_Api_Cache\Admin\Capability;
use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Admin\Pages\Settings_Page;
use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Services\Settings_Service;

/**
 * @covers \Rockfire\Rest_Api_Cache\Admin\Pages\Settings_Page
 * @covers \Rockfire\Rest_Api_Cache\Admin\Admin
 */
class Test_Settings_Page extends Rfrac_Test_Case {

	use Rfrac_Admin_Test_Helpers;
	use Rfrac_Fails_Option_Writes;

	public function tear_down() {
		$GLOBALS['wp_settings_errors'] = array();
		$this->reset_admin_request();
		parent::tear_down();
	}

	private function page(): Settings_Page {
		return new Settings_Page( new Settings_Service( new Config() ), Plugin::instance()->status_service(), new Capability() );
	}

	private function admin( Settings_Page $page ): Admin {
		return new Admin( new Capability(), new Notices(), Plugin::instance()->status_service(), Plugin::instance()->cache_service(), array( $page ) );
	}

	private function render( Settings_Page $page ): string {
		ob_start();
		$page->render();
		return (string) ob_get_clean();
	}

	/**
	 * What options.php does for a POST of the option group: capability, then update_option() of the unslashed value.
	 *
	 * @param array<string,mixed> $values Submitted values.
	 */
	private function post_options_php( array $values ): void {
		$capability = apply_filters( 'option_page_capability_rfrac_settings', 'manage_options' );
		if ( ! current_user_can( $capability ) ) {
			wp_die( 'Sorry, you are not allowed to manage these options.', 403 );
		}
		check_admin_referer( 'rfrac_settings-options' );
		update_option( Names::OPTION_SETTINGS, wp_unslash( wp_slash( $values ) ) );
	}

	public function test_slug_and_position(): void {
		$page = $this->page();
		$this->assertSame( 'rfrac-settings', $page->slug() );
		$this->assertSame( 30, $page->position() );
		$this->assertSame( array(), $page->actions() );
	}

	public function test_the_admin_registers_the_setting_on_admin_init_only_outside_ajax(): void {
		$page  = $this->page();
		$admin = $this->admin( $page );
		$admin->register();
		$this->assertSame( 10, has_action( 'admin_init', array( $page, 'register_settings' ) ) );

		unregister_setting( 'rfrac_settings', Names::OPTION_SETTINGS );
		remove_action( 'admin_init', array( $page, 'register_settings' ) );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->admin( $page )->register();
		$this->assertFalse( has_action( 'admin_init', array( $page, 'register_settings' ) ) );
		$this->assertArrayNotHasKey( Names::OPTION_SETTINGS, get_registered_settings() );
	}

	public function test_register_settings_registers_sanitizer_sections_and_fields(): void {
		global $wp_settings_fields, $wp_settings_sections;
		$page = $this->page();
		$page->register_settings();

		$registered = get_registered_settings()[ Names::OPTION_SETTINGS ];
		$this->assertSame( 'array', $registered['type'] );
		$this->assertFalse( $registered['show_in_rest'] );
		$this->assertInstanceOf( Settings_Service::class, $registered['sanitize_callback'][0] );
		$this->assertSame( 'sanitize', $registered['sanitize_callback'][1] );

		$this->assertSame( array( 'rfrac_defaults', 'rfrac_request', 'rfrac_limits', 'rfrac_performance' ), array_keys( $wp_settings_sections['rfrac-settings'] ) );
		$fields = array();
		foreach ( $wp_settings_fields['rfrac-settings'] as $section_fields ) {
			$fields = array_merge( $fields, array_keys( $section_fields ) );
		}
		$this->assertSame( 10, count( $fields ) );
		$this->assertContains( 'rfrac_max_payload_kb', $fields );
		$this->assertContains( 'rfrac_nonce_caching', array_keys( $wp_settings_fields['rfrac-settings']['rfrac_request'] ) );
	}

	public function test_rendered_fields_have_units_ranges_and_a_store_select(): void {
		$this->as_admin();
		$page = $this->page();
		$page->register_settings();
		$html = $this->render( $page );

		$this->assertStringContainsString( 'name="rfrac_settings[max_payload_kb]"', $html );
		$this->assertMatchesRegularExpression( '/name="rfrac_settings\[max_payload_kb\]"[^>]*min="1" max="8192"/', $html );
		$this->assertStringContainsString( 'KB', $html );
		$this->assertStringContainsString( '<select id="rfrac-store" name="rfrac_settings[store]"', $html );
		$this->assertStringContainsString( 'action="options.php"', $html );
		$this->assertStringContainsString( "name='option_page' value='rfrac_settings'", $html );
		$this->assertStringNotContainsString( 'disabled', $html );
	}

	public function test_the_request_rules_section_has_a_textarea_per_list_with_one_value_per_line(): void {
		$this->as_admin();
		update_option(
			Names::OPTION_SETTINGS,
			array(
				'global_ignore_params' => array( 'utm_source', 'fbclid' ),
				'global_vary_headers'  => array(),
			),
			false
		);
		$page = $this->page();
		$page->register_settings();
		$html = $this->render( $page );

		$this->assertStringContainsString( 'Request rules', $html );
		$this->assertLessThan( strpos( $html, 'Limits' ), strpos( $html, 'Request rules' ), 'The section comes before Limits.' );
		$this->assertGreaterThan( strpos( $html, 'Defaults' ), strpos( $html, 'Request rules' ), 'The section comes after Defaults.' );
		$this->assertStringContainsString( 'Ignored query parameters', $html );
		$this->assertStringContainsString( 'Vary request headers', $html );
		$this->assertMatchesRegularExpression( '/<textarea id="rfrac-global_ignore_params" name="rfrac_settings\[global_ignore_params\]" class="large-text code" rows="4"[^>]*>utm_source\nfbclid<\/textarea>/', $html );
		$this->assertMatchesRegularExpression( '/<textarea id="rfrac-global_vary_headers" name="rfrac_settings\[global_vary_headers\]" class="large-text code" rows="4"[^>]*><\/textarea>/', $html );
		$this->assertStringNotContainsString( 'disabled', $html );
		$this->assertStringContainsString( 'a route registers is never ignored on that route', $html );
	}

	public function test_a_list_value_is_escaped_in_its_textarea(): void {
		$this->as_admin();
		update_option( Names::OPTION_SETTINGS, array( 'global_ignore_params' => array( 'a[b]' ) ), false );
		$page = $this->page();
		$page->register_settings();

		$this->assertStringContainsString( '>a[b]</textarea>', $this->render( $page ) );
	}

	public function test_posting_an_invalid_header_list_keeps_the_stored_one_and_reports_it(): void {
		$this->as_admin();
		$this->page()->register_settings();
		update_option( Names::OPTION_SETTINGS, array( 'global_vary_headers' => array( 'accept-language' ) ), false );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'rfrac_settings-options' );

		$this->post_options_php(
			array(
				'global_vary_headers'  => "Accept-Language\r\nCookie",
				'global_ignore_params' => "utm_source\r\nfbclid",
			)
		);

		$stored = get_option( Names::OPTION_SETTINGS );
		$this->assertSame( array( 'accept-language' ), $stored['global_vary_headers'] );
		$this->assertSame( array( 'utm_source', 'fbclid' ), $stored['global_ignore_params'] );
		$this->assertContains( 'rfrac_invalid_global_vary_headers', array_column( get_settings_errors( 'rfrac_settings' ), 'code' ) );
	}

	public function test_posting_global_vary_headers_that_overflow_a_stored_rule_keeps_the_stored_ones_and_names_the_route(): void {
		$this->as_admin();
		// Seed through the plugin's own save API: the sanitizer takes the routes from storage, never from the input.
		$config = new Config();
		$this->assertTrue( $config->save_route_rule( '/wp/v2/posts', array( 'vary_headers' => array( 'a', 'b', 'c', 'd' ) ) )->is_valid() );
		$this->assertTrue( $config->save_settings( array( 'global_vary_headers' => 'a' ) )->is_valid() );
		$this->page()->register_settings();
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'rfrac_settings-options' );

		$this->post_options_php( array( 'global_vary_headers' => "x\r\ny" ) );

		$stored = get_option( Names::OPTION_SETTINGS );
		$this->assertSame( array( 'a' ), $stored['global_vary_headers'] );
		$this->assertArrayHasKey( '/wp/v2/posts', $stored['routes'] );
		$errors = array_column( get_settings_errors( 'rfrac_settings' ), 'message', 'code' );
		$this->assertArrayHasKey( 'rfrac_invalid_global_vary_headers', $errors );
		$this->assertStringContainsString( '/wp/v2/posts', $errors['rfrac_invalid_global_vary_headers'] );

		$GLOBALS['wp_settings_errors'] = array();
		$this->post_options_php( array( 'global_vary_headers' => "a\r\nx" ) );
		$this->assertSame( array( 'a', 'x' ), get_option( Names::OPTION_SETTINGS )['global_vary_headers'] );
		$this->assertNotContains( 'rfrac_invalid_global_vary_headers', array_column( get_settings_errors( 'rfrac_settings' ), 'code' ) );
	}

	public function test_posting_to_options_php_saves_clamped_values_and_recompiles(): void {
		$this->as_admin();
		$this->page()->register_settings();
		update_option( Names::OPTION_SETTINGS, array(), false );
		add_action(
			'update_option_' . Names::OPTION_SETTINGS,
			static function () {
				( new Config_Compiler() )->compile( rest_get_server() );
			}
		);
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'rfrac_settings-options' );

		$this->post_options_php(
			array(
				'default_ttl' => '7200',
				'max_entries' => '50',
			)
		);

		$stored = get_option( Names::OPTION_SETTINGS );
		$this->assertSame( 7200, $stored['default_ttl'] );
		$this->assertSame( 100, $stored['max_entries'], 'Clamped to the minimum.' );
		$this->assertSame( 7200, Compiled_Config::load()->default_ttl() );

		$messages = array_column( get_settings_errors( 'rfrac_settings' ), 'message' );
		$this->assertContains( 'Maximum entries was raised to the minimum of 100.', $messages );
	}

	/**
	 * The end of options.php: "Settings saved." when nothing else was reported, then the messages go to a transient.
	 *
	 * @return array<int,array<string,string>> The stored messages.
	 */
	private function finish_options_php(): array {
		if ( ! count( get_settings_errors() ) ) {
			add_settings_error( 'general', 'settings_updated', 'Settings saved.', 'success' );
		}
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		return (array) get_transient( 'settings_errors' );
	}

	public function test_a_failed_database_write_replaces_settings_saved_by_an_error(): void {
		$this->as_admin();
		$this->page()->register_settings();
		update_option( Names::OPTION_SETTINGS, array(), false );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'rfrac_settings-options' );
		$this->fail_option_writes( Names::OPTION_SETTINGS );

		$this->post_options_php( array( 'default_ttl' => '7200' ) );
		$errors = $this->finish_options_php();

		$this->assertSame( array( 'rfrac_save_failed' ), array_column( $errors, 'code' ) );
		$this->assertSame( 'error', $errors[0]['type'] );
		$this->assertSame( 'rfrac_settings', $errors[0]['setting'] );
		$this->assertSame( 'The settings could not be saved to the database.', $errors[0]['message'] );
	}

	public function test_a_successful_or_unchanged_save_keeps_settings_saved(): void {
		$this->as_admin();
		$page = $this->page();
		$page->register_settings();
		update_option( Names::OPTION_SETTINGS, array(), false );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'rfrac_settings-options' );

		$this->post_options_php( array( 'default_ttl' => '7200' ) );
		$this->assertSame( array( 'settings_updated' ), array_column( $this->finish_options_php(), 'code' ) );

		$GLOBALS['wp_settings_errors'] = array();
		$this->post_options_php( array( 'default_ttl' => '7200' ) );
		$this->assertSame( array( 'settings_updated' ), array_column( $this->finish_options_php(), 'code' ), 'An unchanged value is not a failure.' );
	}

	public function test_a_failed_write_keeps_the_validation_messages(): void {
		$this->as_admin();
		$this->page()->register_settings();
		update_option( Names::OPTION_SETTINGS, array(), false );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'rfrac_settings-options' );
		$this->fail_option_writes( Names::OPTION_SETTINGS );

		$this->post_options_php(
			array(
				'default_ttl' => '7200',
				'max_entries' => 'abc',
			)
		);

		$this->assertSame( array( 'rfrac_invalid_max_entries', 'rfrac_save_failed' ), array_column( $this->finish_options_php(), 'code' ) );
	}

	public function test_report_failed_write_leaves_other_values_and_writes_alone(): void {
		$page = $this->page();
		$this->assertSame( 'not an array', $page->report_failed_write( 'not an array' ), 'Nothing pending.' );

		$page->watch_write( 'blogname', 'a', 'b' );
		$this->assertSame( array(), $page->report_failed_write( array() ), 'Another option is not watched.' );

		update_option( Names::OPTION_SETTINGS, array( 'default_ttl' => 600 ), false );
		$page->watch_write( Names::OPTION_SETTINGS, array(), array( 'default_ttl' => 600 ) );
		$saved = array(
			array(
				'setting' => 'general',
				'code'    => 'settings_updated',
				'message' => 'Settings saved.',
				'type'    => 'success',
			),
		);
		$this->assertSame( $saved, $page->report_failed_write( $saved ), 'The database holds the value (a stale cache missed updated_option): no error.' );

		$page->watch_write( Names::OPTION_SETTINGS, array(), array( 'default_ttl' => 900 ) );
		$this->assertSame( array( 'rfrac_save_failed' ), array_column( $page->report_failed_write( $saved ), 'code' ) );
	}

	public function test_capability_filter_lets_an_editor_save_and_a_subscriber_dies(): void {
		$this->page()->register_settings();
		$this->assertSame( 'manage_options', apply_filters( 'option_page_capability_rfrac_settings', 'x' ) );
		add_filter( 'rfrac/settings_capability', static fn() => 'edit_posts' );
		$this->assertSame( 'edit_posts', apply_filters( 'option_page_capability_rfrac_settings', 'x' ) );

		$this->as_role( 'editor' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'rfrac_settings-options' );
		update_option( Names::OPTION_SETTINGS, array(), false );
		$this->post_options_php( array( 'max_params' => '7' ) );
		$this->assertSame( 7, get_option( Names::OPTION_SETTINGS )['max_params'] );

		$this->as_subscriber();
		$this->expectException( WPDieException::class );
		$this->post_options_php( array( 'max_params' => '9' ) );
	}

	public function test_render_dies_for_a_user_without_the_capability(): void {
		$this->as_role( 'editor' );
		$page  = $this->page();
		$admin = $this->admin( $page );
		$this->expectException( WPDieException::class );
		$admin->render( $page );
	}

	public function test_the_storage_table_shows_the_active_store_and_object_cache(): void {
		$this->as_admin();
		$html = $this->render( $this->page() );
		$this->assertStringContainsString( 'Storage', $html );
		$this->assertStringContainsString( 'Active store', $html );
		$this->assertStringContainsString( 'Persistent object cache', $html );
		$this->assertStringContainsString( 'Must-use mode', $html );
		$this->assertStringNotContainsString( 'Maintenance backlog', $html, 'The Settings page uses the storage mode of the status table.' );
		$this->assertStringContainsString( 'Database', $html );

		wp_using_ext_object_cache( true );
		update_option( Names::OPTION_CONFIG, array( 'store_mode' => 'object_cache' ) );
		try {
			$page = new Settings_Page( new Settings_Service( new Config() ), $this->fresh_status_service(), new Capability() );
			$html = $this->render( $page );
			$this->assertStringContainsString( 'Object cache', $html );
			$this->assertStringContainsString( 'payloads are stored in the object cache automatically', strtolower( $html ) );
		} finally {
			wp_using_ext_object_cache( false );
		}
	}

	/**
	 * A status service with an object-cache store.
	 */
	private function fresh_status_service(): \Rockfire\Rest_Api_Cache\Services\Status_Service {
		$plugin = Plugin::instance();
		$store  = $this->createStub( \Rockfire\Rest_Api_Cache\Storage\Store_Interface::class );
		$store->method( 'name' )->willReturn( 'object_cache' );
		return new \Rockfire\Rest_Api_Cache\Services\Status_Service(
			Config::instance(),
			$store,
			new \Rockfire\Rest_Api_Cache\Schema\Schema(),
			$plugin->mu_installer(),
			$plugin->cache_service()
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_locked_field_is_disabled_and_a_forged_value_does_not_change_the_stored_one(): void {
		define( 'RFRAC_DEFAULT_TTL', 300 );
		$this->as_admin();
		update_option( Names::OPTION_SETTINGS, array( 'default_ttl' => 600 ), false );
		$page = $this->page();
		$page->register_settings();
		$html = $this->render( $page );

		$this->assertMatchesRegularExpression( '/<input[^>]*name="rfrac_settings\[default_ttl\]"[^>]*value="300"[^>]*disabled="disabled"/', $html );
		$this->assertStringContainsString( 'Locked by constant <code>RFRAC_DEFAULT_TTL</code> in wp-config.php', $html );
		$this->assertDoesNotMatchRegularExpression( '/<input[^>]*name="rfrac_settings\[max_ttl\]"[^>]*disabled/', $html );

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'rfrac_settings-options' );
		$this->post_options_php( array( 'default_ttl' => '999' ) );
		$this->assertSame( 600, get_option( Names::OPTION_SETTINGS )['default_ttl'] );
	}

	/**
	 * What a browser submits for the nonce caching field of a rendered page, left alone: every non-disabled
	 * hidden input posts its value, a ticked checkbox posts its value, and the last one of the same name wins.
	 *
	 * @param string $html The rendered page.
	 *
	 * @return string|null The submitted value; null when the field posts nothing.
	 */
	private function untouched_nonce_caching_post( string $html ): ?string {
		preg_match_all( '/<input type="(hidden|checkbox)"[^>]*name="rfrac_settings\[nonce_caching\]"[^>]*>/', $html, $inputs, PREG_SET_ORDER );
		$posted = null;
		foreach ( $inputs as $input ) {
			if ( str_contains( $input[0], 'disabled' ) ) {
				continue;
			}
			if ( 'hidden' === $input[1] ) {
				$posted = '0';
			} elseif ( str_contains( $input[0], "checked='checked'" ) ) {
				$posted = '1';
			}
		}
		return $posted;
	}

	public function test_the_nonce_caching_checkbox_is_off_enabled_and_posts_nothing_while_nobody_stored_it(): void {
		$this->as_admin();
		$page = $this->page();
		$page->register_settings();
		$html = $this->render( $page );

		$this->assertSame( 1, substr_count( $html, 'name="rfrac_settings[nonce_caching]"' ), 'Only the checkbox: an opt-in nobody stored has no unticking to post.' );
		$this->assertStringNotContainsString( '<input type="hidden" name="rfrac_settings[nonce_caching]"', $html );
		$this->assertMatchesRegularExpression( '/<input type="checkbox"[^>]*name="rfrac_settings\[nonce_caching\]"[^>]*value="1"(?![^>]*(checked|disabled))[^>]*>/', $html, 'Off and enabled by default.' );
		$this->assertNull( $this->untouched_nonce_caching_post( $html ) );
		$this->assertStringContainsString( 'Cache requests with a REST nonce', $html );
		$this->assertStringContainsString( 'The nonce is never checked on a cache hit, and any login still bypasses the cache.', $html );
		$this->assertStringNotContainsString( 'Turned on by a filter', $html );
		$this->assertStringNotContainsString( 'Turned off by a filter', $html );
		$this->assertLessThan( strpos( $html, 'Limits' ), strpos( $html, 'rfrac-nonce_caching' ), 'The field is in the Request rules section.' );
	}

	public function test_a_stored_nonce_caching_checkbox_follows_a_hidden_zero_so_unticking_posts_a_choice(): void {
		$this->as_admin();
		$page = $this->page();
		$page->register_settings();
		$hidden = '<input type="hidden" name="rfrac_settings[nonce_caching]" value="0">';

		foreach ( array( true, false ) as $stored ) {
			update_option( Names::OPTION_SETTINGS, array( 'nonce_caching' => $stored ), false );
			$html     = $this->render( $page );
			$checkbox = strpos( $html, 'type="checkbox" id="rfrac-nonce_caching" name="rfrac_settings[nonce_caching]" value="1"' );

			$this->assertStringContainsString( $hidden, $html );
			$this->assertNotFalse( $checkbox );
			$this->assertLessThan( $checkbox, strpos( $html, $hidden ), 'The hidden 0 precedes the checkbox.' );
			$this->assertSame( 2, substr_count( $html, 'name="rfrac_settings[nonce_caching]"' ), 'One hidden input and one checkbox.' );
			$this->assertSame( $stored ? '1' : '0', $this->untouched_nonce_caching_post( $html ), 'A save that leaves the box alone keeps the stored choice.' );
		}
	}

	public function test_the_nonce_caching_checkbox_is_checked_by_a_stored_true_and_not_by_a_stored_false(): void {
		$this->as_admin();
		$page = $this->page();
		$page->register_settings();

		update_option( Names::OPTION_SETTINGS, array( 'nonce_caching' => true ), false );
		$html = $this->render( $page );
		$this->assertMatchesRegularExpression( '/<input type="checkbox" id="rfrac-nonce_caching"[^>]* checked=\'checked\'/', $html );
		$this->assertStringNotContainsString( 'Turned on by a filter', $html );

		update_option( Names::OPTION_SETTINGS, array( 'nonce_caching' => false ), false );
		$html = $this->render( $page );
		$this->assertDoesNotMatchRegularExpression( '/<input type="checkbox" id="rfrac-nonce_caching"[^>]*checked/', $html );
	}

	public function test_a_compiled_flag_that_a_filter_turned_on_renders_unchecked_with_a_note(): void {
		$this->as_admin();
		$page = $this->page();
		$page->register_settings();
		$this->set_compiled_config( array( 'nonce_caching' => true ) );

		$html = $this->render( $page );
		$this->assertDoesNotMatchRegularExpression( '/<input type="checkbox" id="rfrac-nonce_caching"[^>]*checked/', $html, 'The box shows the setting, not the filter.' );
		$this->assertStringContainsString( 'Turned on by a filter or a carried-over WP REST Cache callback, so requests with a nonce are cached whatever this box says.', $html );
		$this->assertStringNotContainsString( 'Turned off by a filter', $html );
	}

	public function test_a_save_that_leaves_a_filter_driven_nonce_opt_in_alone_stores_nothing_and_removing_the_filter_turns_it_off(): void {
		$this->as_admin();
		$page = $this->page();
		$page->register_settings();
		update_option( Names::OPTION_SETTINGS, array(), false );
		add_filter( Hooks::NONCE_CACHING, '__return_true' );
		( new Config_Compiler() )->compile( rest_get_server() );
		$this->assertTrue( Compiled_Config::load()->nonce_caching(), 'The filter turns nonce caching on.' );

		// An unrelated save of the page, as a browser posts it.
		$untouched = $this->untouched_nonce_caching_post( $this->render( $page ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'rfrac_settings-options' );
		$this->post_options_php( array( 'max_params' => '31' ) + ( null === $untouched ? array() : array( 'nonce_caching' => $untouched ) ) );

		$stored = get_option( Names::OPTION_SETTINGS );
		$this->assertSame( 31, $stored['max_params'] );
		$this->assertArrayNotHasKey( 'nonce_caching', $stored, 'A save that did not touch the box stores no choice.' );

		remove_all_filters( Hooks::NONCE_CACHING );
		( new Config_Compiler() )->compile( rest_get_server() );
		$this->assertFalse( Compiled_Config::load()->nonce_caching(), 'Without the filter nonce caching is off again.' );
	}

	public function test_ticking_the_box_of_a_filter_driven_nonce_opt_in_stores_the_choice(): void {
		$this->as_admin();
		$page = $this->page();
		$page->register_settings();
		update_option( Names::OPTION_SETTINGS, array(), false );
		$this->set_compiled_config( array( 'nonce_caching' => true ) );
		$this->assertNull( $this->untouched_nonce_caching_post( $this->render( $page ) ) );

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'rfrac_settings-options' );
		$this->post_options_php( array( 'nonce_caching' => '1' ) );

		$this->assertTrue( get_option( Names::OPTION_SETTINGS )['nonce_caching'] );
	}

	public function test_a_filter_that_turns_nonce_caching_on_over_a_stored_false_is_flagged(): void {
		$this->as_admin();
		$page = $this->page();
		$page->register_settings();
		update_option( Names::OPTION_SETTINGS, array( 'nonce_caching' => false ), false );
		add_filter( Hooks::NONCE_CACHING, '__return_true' );
		( new Config_Compiler() )->compile( rest_get_server() );
		$this->assertTrue( Compiled_Config::load()->nonce_caching() );

		$html = $this->render( $page );
		$this->assertDoesNotMatchRegularExpression( '/<input type="checkbox" id="rfrac-nonce_caching"[^>]*checked/', $html );
		$this->assertStringContainsString( 'Turned on by a filter or a carried-over WP REST Cache callback', $html );
		$this->assertSame( '0', $this->untouched_nonce_caching_post( $html ), 'The stored false is kept as it is.' );
	}

	public function test_a_filter_that_turns_nonce_caching_off_over_a_stored_true_is_flagged(): void {
		$this->as_admin();
		$page = $this->page();
		$page->register_settings();
		update_option( Names::OPTION_SETTINGS, array( 'nonce_caching' => true ), false );
		add_filter( Hooks::NONCE_CACHING, '__return_false' );
		( new Config_Compiler() )->compile( rest_get_server() );
		$this->assertFalse( Compiled_Config::load()->nonce_caching() );

		$html = $this->render( $page );
		$this->assertMatchesRegularExpression( '/<input type="checkbox" id="rfrac-nonce_caching"[^>]* checked=\'checked\'/', $html );
		$this->assertStringContainsString( 'Turned off by a filter, so requests with a nonce are not cached whatever this box says.', $html );
		$this->assertStringNotContainsString( 'Turned on by a filter', $html );
		$this->assertSame( '1', $this->untouched_nonce_caching_post( $html ), 'The stored true is kept as it is.' );
	}

	public function test_a_stored_nonce_choice_that_the_snapshot_agrees_with_has_no_note(): void {
		$this->as_admin();
		$page = $this->page();
		$page->register_settings();

		update_option( Names::OPTION_SETTINGS, array( 'nonce_caching' => true ), false );
		$this->set_compiled_config( array( 'nonce_caching' => true ) );
		$html = $this->render( $page );
		$this->assertStringNotContainsString( 'by a filter', $html );

		update_option( Names::OPTION_SETTINGS, array( 'nonce_caching' => false ), false );
		$this->set_compiled_config();
		$html = $this->render( $page );
		$this->assertStringNotContainsString( 'by a filter', $html );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_filter_that_turns_nonce_caching_on_over_a_locked_false_is_flagged(): void {
		define( 'RFRAC_NONCE_CACHING', false );
		$this->as_admin();
		$page = $this->page();
		$page->register_settings();
		add_filter( Hooks::NONCE_CACHING, '__return_true' );
		( new Config_Compiler() )->compile( rest_get_server() );
		$this->assertTrue( Compiled_Config::load()->nonce_caching(), 'The filter is applied on top of the constant.' );

		$html = $this->render( $page );
		$this->assertMatchesRegularExpression( '/<input type="checkbox" id="rfrac-nonce_caching" name="rfrac_settings\[nonce_caching\]" value="1"[^>]*disabled="disabled"/', $html );
		$this->assertDoesNotMatchRegularExpression( '/<input type="checkbox" id="rfrac-nonce_caching"[^>]*checked/', $html );
		$this->assertStringContainsString( 'Locked by constant <code>RFRAC_NONCE_CACHING</code> in wp-config.php', $html );
		$this->assertStringContainsString( 'Turned on by a filter or a carried-over WP REST Cache callback', $html );
	}

	public function test_posting_the_nonce_caching_box_unticked_stores_false_and_ticked_stores_true(): void {
		$this->as_admin();
		$this->page()->register_settings();
		update_option( Names::OPTION_SETTINGS, array(), false );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'rfrac_settings-options' );

		$this->post_options_php( array( 'max_params' => '30', 'nonce_caching' => '0' ) );
		$this->assertFalse( get_option( Names::OPTION_SETTINGS )['nonce_caching'] );

		$this->post_options_php( array( 'max_params' => '30', 'nonce_caching' => '1' ) );
		$this->assertTrue( get_option( Names::OPTION_SETTINGS )['nonce_caching'] );

		$this->post_options_php( array( 'max_params' => '31', 'nonce_caching' => '0' ) );
		$this->assertFalse( get_option( Names::OPTION_SETTINGS )['nonce_caching'] );
		$this->assertSame( array(), get_settings_errors( 'rfrac_settings' ) );
	}

	public function test_posting_a_form_without_the_nonce_caching_field_leaves_the_key_absent(): void {
		$this->as_admin();
		$this->page()->register_settings();
		update_option( Names::OPTION_SETTINGS, array(), false );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'rfrac_settings-options' );

		$this->post_options_php( array( 'max_params' => '30' ) );

		$this->assertSame( 30, get_option( Names::OPTION_SETTINGS )['max_params'] );
		$this->assertArrayNotHasKey( 'nonce_caching', get_option( Names::OPTION_SETTINGS ) );
	}

	public function test_posting_garbage_for_nonce_caching_keeps_the_old_value_and_reports_it(): void {
		$this->as_admin();
		$this->page()->register_settings();
		update_option( Names::OPTION_SETTINGS, array( 'nonce_caching' => true ), false );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'rfrac_settings-options' );

		$this->post_options_php( array( 'nonce_caching' => 'garbage' ) );

		$this->assertTrue( get_option( Names::OPTION_SETTINGS )['nonce_caching'] );
		$this->assertContains( 'rfrac_invalid_nonce_caching', array_column( get_settings_errors( 'rfrac_settings' ), 'code' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_locked_nonce_caching_checkbox_is_disabled_without_a_hidden_input_and_ignores_a_forged_value(): void {
		define( 'RFRAC_NONCE_CACHING', true );
		$this->as_admin();
		$page = $this->page();
		$page->register_settings();
		// A snapshot that agrees with the lock adds no filter note to a locked field.
		$this->set_compiled_config( array( 'nonce_caching' => true ) );
		$html = $this->render( $page );

		$this->assertMatchesRegularExpression( '/<input type="checkbox" id="rfrac-nonce_caching" name="rfrac_settings\[nonce_caching\]" value="1" checked=\'checked\'[^>]*disabled="disabled"/', $html );
		$this->assertStringNotContainsString( 'name="rfrac_settings[nonce_caching]" value="0"', $html );
		$this->assertSame( 1, substr_count( $html, 'name="rfrac_settings[nonce_caching]"' ), 'Only the disabled checkbox.' );
		$this->assertStringContainsString( 'Locked by constant <code>RFRAC_NONCE_CACHING</code> in wp-config.php', $html );
		$this->assertStringNotContainsString( 'Turned on by a filter', $html );

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'rfrac_settings-options' );
		$this->post_options_php( array( 'max_params' => '30', 'nonce_caching' => '0' ) );
		$this->assertSame( 30, get_option( Names::OPTION_SETTINGS )['max_params'] );
		$this->assertArrayNotHasKey( 'nonce_caching', get_option( Names::OPTION_SETTINGS ), 'A locked key is never written.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_locked_list_field_is_disabled_names_its_constant_and_ignores_a_forged_value(): void {
		define( 'RFRAC_GLOBAL_VARY_HEADERS', array( 'Accept-Language', 'X-Country' ) );
		$this->as_admin();
		update_option( Names::OPTION_SETTINGS, array( 'global_vary_headers' => array( 'x-stored' ) ), false );
		$page = $this->page();
		$page->register_settings();
		$html = $this->render( $page );

		$this->assertMatchesRegularExpression( '/<textarea[^>]*name="rfrac_settings\[global_vary_headers\]"[^>]*disabled="disabled"[^>]*>accept-language\nx-country<\/textarea>/', $html );
		$this->assertStringContainsString( 'Locked by constant <code>RFRAC_GLOBAL_VARY_HEADERS</code> in wp-config.php', $html );
		$this->assertDoesNotMatchRegularExpression( '/<textarea[^>]*name="rfrac_settings\[global_ignore_params\]"[^>]*disabled/', $html );

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'rfrac_settings-options' );
		$this->post_options_php( array( 'global_vary_headers' => 'x-forged' ) );
		$this->assertSame( array( 'x-stored' ), get_option( Names::OPTION_SETTINGS )['global_vary_headers'] );
	}
}
