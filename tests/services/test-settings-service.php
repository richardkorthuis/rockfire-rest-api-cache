<?php
/**
 * Tests for the settings service.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Settings_Schema;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Services\Settings_Service;

/**
 * @covers \Rockfire\Rest_Api_Cache\Services\Settings_Service
 */
class Test_Settings_Service extends Rfrac_Test_Case {

	public function tear_down() {
		$GLOBALS['wp_settings_errors'] = array();
		parent::tear_down();
	}

	private function service(): Settings_Service {
		return new Settings_Service( new Config() );
	}

	/**
	 * Codes of the settings errors of the group.
	 *
	 * @return string[]
	 */
	private function error_codes(): array {
		return array_column( get_settings_errors( Settings_Service::ERROR_SLUG ), 'code' );
	}

	public function test_schema_lists_the_editable_settings_with_ranges_and_sections(): void {
		$schema = $this->service()->schema();

		$this->assertSame( array( 'default_ttl', 'max_ttl', 'max_entries', 'max_payload_kb', 'max_params', 'hit_sampling', 'store', 'global_ignore_params', 'global_vary_headers', 'nonce_caching' ), array_keys( $schema ) );
		$this->assertSame( 'defaults', $schema['default_ttl']['section'] );
		$this->assertSame( 60, $schema['default_ttl']['min'] );
		$this->assertSame( 2592000, $schema['default_ttl']['max'] ); // The effective max_ttl.
		$this->assertSame( 31536000, $schema['max_ttl']['max'] );
		$this->assertSame( array( 100, 1000000, 'limits' ), array( $schema['max_entries']['min'], $schema['max_entries']['max'], $schema['max_entries']['section'] ) );
		$this->assertSame( array( 1, 8192, 'KB' ), array( $schema['max_payload_kb']['min'], $schema['max_payload_kb']['max'], $schema['max_payload_kb']['unit'] ) );
		$this->assertSame( array( 1, 100 ), array( $schema['max_params']['min'], $schema['max_params']['max'] ) );
		$this->assertSame( array( 0, 1000, 'performance' ), array( $schema['hit_sampling']['min'], $schema['hit_sampling']['max'], $schema['hit_sampling']['section'] ) );
		$this->assertSame( 'enum', $schema['store']['type'] );
		$this->assertSame( array( 'auto', 'database', 'object_cache' ), array_keys( $schema['store']['options'] ) );
		foreach ( $schema as $field ) {
			$this->assertNotSame( '', $field['label'] );
			$this->assertNotSame( '', $field['description'] );
		}
	}

	public function test_default_ttl_maximum_follows_the_effective_max_ttl(): void {
		update_option( Names::OPTION_SETTINGS, array( 'max_ttl' => 7200 ) );

		$this->assertSame( 7200, $this->service()->schema()['default_ttl']['max'] );
	}

	public function test_view_reports_values_and_no_locks_without_constants(): void {
		$view = $this->service()->view();

		$this->assertSame(
			array(
				'value'    => 86400,
				'locked'   => false,
				'constant' => null,
			),
			$view['default_ttl']
		);
		$this->assertSame( 'auto', $view['store']['value'] );
		$this->assertSame( array_keys( $this->service()->schema() ), array_keys( $view ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_constant_locks_the_setting_and_sanitize_keeps_the_stored_value(): void {
		define( 'RFRAC_DEFAULT_TTL', 120 );
		update_option( Names::OPTION_SETTINGS, array( 'default_ttl' => 600 ) );
		$service = $this->service();

		$clean = $service->sanitize( array( 'default_ttl' => 999 ) );

		$this->assertSame( 600, $clean['default_ttl'] );
		$this->assertSame( array(), $this->error_codes() );
		$this->assertSame(
			array(
				'value'    => 120,
				'locked'   => true,
				'constant' => 'RFRAC_DEFAULT_TTL',
			),
			$service->view()['default_ttl']
		);
		$this->assertSame( 120, $service->get( 'default_ttl' ) );
	}

	public function test_sanitize_clamps_and_adds_a_warning_per_clamped_value(): void {
		$clean = $this->service()->sanitize(
			array(
				'default_ttl' => '5',
				'max_params'  => 500,
				'max_entries' => 5000,
			)
		);

		$this->assertSame( 60, $clean['default_ttl'] );
		$this->assertSame( 100, $clean['max_params'] );
		$this->assertSame( 5000, $clean['max_entries'] );
		$errors = get_settings_errors( Settings_Service::ERROR_SLUG );
		$this->assertEqualsCanonicalizing( array( 'rfrac_clamped_default_ttl', 'rfrac_clamped_max_params' ), array_column( $errors, 'code' ) );
		$this->assertSame( 'warning', $errors[0]['type'] );
		$this->assertNotSame( '', $errors[0]['message'] );
	}

	public function test_sanitize_gives_no_message_for_values_in_range(): void {
		$clean = $this->service()->sanitize( array( 'default_ttl' => 600, 'max_params' => '30', 'store' => 'database' ) );

		$this->assertSame( 600, $clean['default_ttl'] );
		$this->assertSame( 30, $clean['max_params'] );
		$this->assertSame( 'database', $clean['store'] );
		$this->assertSame( array(), $this->error_codes() );
	}

	public function test_sanitize_reports_invalid_values_as_errors_and_keeps_the_stored_value(): void {
		$clean = $this->service()->sanitize(
			array(
				'default_ttl' => 600,
				'max_params'  => 'abc',
				'max_entries' => '1e3',
				'store'       => 'bogus',
				'hit_sampling' => array( 'x' ),
			)
		);

		$this->assertSame( 600, $clean['default_ttl'] );
		$this->assertSame( 20, $clean['max_params'] );
		$this->assertSame( 10000, $clean['max_entries'] );
		$this->assertSame( 'auto', $clean['store'] );
		$this->assertSame( 10, $clean['hit_sampling'] );
		$errors = get_settings_errors( Settings_Service::ERROR_SLUG );
		$this->assertEqualsCanonicalizing( array( 'rfrac_invalid_max_params', 'rfrac_invalid_max_entries', 'rfrac_invalid_store', 'rfrac_invalid_hit_sampling' ), array_column( $errors, 'code' ) );
		foreach ( $errors as $error ) {
			$this->assertSame( 'error', $error['type'] );
		}
	}

	public function test_sanitize_truncates_the_echoed_input(): void {
		$this->service()->sanitize( array( 'max_params' => str_repeat( 'x', 500 ) ) );

		$errors = get_settings_errors( Settings_Service::ERROR_SLUG );
		$this->assertCount( 1, $errors );
		$this->assertLessThan( 200, strlen( $errors[0]['message'] ) );
	}

	public function test_default_ttl_is_clamped_to_the_submitted_max_ttl_with_a_warning(): void {
		$clean = $this->service()->sanitize( array( 'max_ttl' => 3600, 'default_ttl' => 7200 ) );

		$this->assertSame( 3600, $clean['max_ttl'] );
		$this->assertSame( 3600, $clean['default_ttl'] );
		$this->assertSame( array( 'rfrac_clamped_default_ttl' ), $this->error_codes() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_default_ttl_is_clamped_to_a_max_ttl_locked_by_a_constant(): void {
		define( 'RFRAC_MAX_TTL', 3600 );
		update_option( Names::OPTION_SETTINGS, array( 'max_ttl' => 2592000 ) );

		$clean = $this->service()->sanitize( array( 'default_ttl' => 86400, 'max_ttl' => 999999 ) );

		$this->assertSame( 3600, $clean['default_ttl'] );
		$this->assertSame( 2592000, $clean['max_ttl'], 'The locked setting keeps its stored value.' );
		$this->assertSame( array( 'rfrac_clamped_default_ttl' ), $this->error_codes() );
		$this->assertSame( 3600, $this->service()->schema()['default_ttl']['max'] );
	}

	public function test_schema_bounds_and_options_come_from_the_settings_schema(): void {
		$schema = $this->service()->schema();

		foreach ( Settings_Schema::definitions() as $key => $definition ) {
			if ( 'enabled' === $key ) {
				$this->assertArrayNotHasKey( $key, $schema, 'The kill switch is the RFRAC_DISABLE constant only.' );
				continue;
			}
			$this->assertArrayHasKey( $key, $schema, $key . ' has a form field.' );
			if ( 'bool' === $definition['type'] ) {
				$this->assertSame( 'bool', $schema[ $key ]['type'], $key );
			} elseif ( 'int' === $definition['type'] ) {
				$this->assertSame( $definition['min'], $schema[ $key ]['min'], $key );
				if ( 'default_ttl' !== $key ) {
					$this->assertSame( $definition['max'], $schema[ $key ]['max'], $key );
				}
			} elseif ( 'list' === $definition['type'] ) {
				$this->assertSame( $definition['item'], $schema[ $key ]['item'], $key );
				$this->assertSame( $definition['max_items'], $schema[ $key ]['max_items'], $key );
			} else {
				$this->assertSame( $definition['values'], array_keys( $schema[ $key ]['options'] ), $key );
			}
		}
		$this->assertSame( Settings_Schema::STORE_MODES, array_keys( $schema['store']['options'] ) );
	}

	public function test_sanitize_drops_unknown_keys_preserves_routes_and_is_idempotent(): void {
		$config = new Config();
		$config->save_route_rule(
			'/wp/v2/posts',
			array(
				'status' => 'enabled',
				'ttl'    => 600,
			)
		);
		$stored_routes = $config->raw_settings()['routes'];
		$this->assertNotSame( array(), $stored_routes );
		$service = $this->service();

		$once  = $service->sanitize(
			array(
				'evil'        => 1,
				'routes'      => array( 'x' => 1 ),
				'default_ttl' => 5,
			)
		);
		$twice = $service->sanitize( $once );

		$this->assertArrayNotHasKey( 'evil', $once );
		$this->assertSame( $stored_routes, $once['routes'] );
		$this->assertSame( $once, $twice );
	}

	public function test_sanitize_of_a_non_array_leaves_the_stored_option_unchanged(): void {
		update_option( Names::OPTION_SETTINGS, array( 'default_ttl' => 600 ) );
		$before = get_option( Names::OPTION_SETTINGS );

		foreach ( array( null, 'garbage', 5, false ) as $input ) {
			$clean = $this->service()->sanitize( $input );
			$this->assertSame( 600, $clean['default_ttl'] );
		}
		$this->assertSame( $before, get_option( Names::OPTION_SETTINGS ) );
		$this->assertSame( array(), $this->error_codes() );
	}

	public function test_schema_describes_the_two_request_lists(): void {
		$schema = $this->service()->schema();

		$this->assertSame( 'list', $schema['global_ignore_params']['type'] );
		$this->assertSame( 'Ignored query parameters', $schema['global_ignore_params']['label'] );
		$this->assertSame( 'request', $schema['global_ignore_params']['section'] );
		$this->assertSame( 'param', $schema['global_ignore_params']['item'] );
		$this->assertSame( 20, $schema['global_ignore_params']['max_items'] );
		$this->assertStringContainsString( 'a route registers is never ignored on that route', $schema['global_ignore_params']['description'] );
		$this->assertSame( 'list', $schema['global_vary_headers']['type'] );
		$this->assertSame( 'Vary request headers', $schema['global_vary_headers']['label'] );
		$this->assertSame( 'request', $schema['global_vary_headers']['section'] );
		$this->assertSame( 'header', $schema['global_vary_headers']['item'] );
		$this->assertSame( 5, $schema['global_vary_headers']['max_items'] );
		$this->assertStringContainsString( 'At most 5 headers together with each route\'s own', $schema['global_vary_headers']['description'] );
	}

	public function test_view_reports_the_lists_as_arrays(): void {
		update_option( Names::OPTION_SETTINGS, array( 'global_ignore_params' => 'utm_source fbclid' ) );
		$view = $this->service()->view();

		$this->assertSame( array( 'utm_source', 'fbclid' ), $view['global_ignore_params']['value'] );
		$this->assertSame( array(), $view['global_vary_headers']['value'] );
		$this->assertFalse( $view['global_vary_headers']['locked'] );
	}

	public function test_sanitize_accepts_a_textarea_value_and_keeps_the_list_as_an_array(): void {
		$clean = $this->service()->sanitize(
			array(
				'global_ignore_params' => "utm_source\r\nfbclid\r\n",
				'global_vary_headers'  => "Accept-Language\r\nX-Country",
			)
		);

		$this->assertSame( array( 'utm_source', 'fbclid' ), $clean['global_ignore_params'] );
		$this->assertSame( array( 'accept-language', 'x-country' ), $clean['global_vary_headers'] );
		$this->assertSame( array(), $this->error_codes() );
	}

	public function test_sanitize_reports_an_invalid_list_with_the_reason_and_keeps_the_stored_one(): void {
		update_option(
			Names::OPTION_SETTINGS,
			array(
				'global_vary_headers'  => array( 'accept-language' ),
				'global_ignore_params' => array( 'utm_source' ),
			)
		);

		$clean = $this->service()->sanitize(
			array(
				'global_vary_headers'  => "Accept-Language\nCookie",
				'global_ignore_params' => 'fbclid',
			)
		);

		$this->assertSame( array( 'accept-language' ), $clean['global_vary_headers'] );
		$this->assertSame( array( 'fbclid' ), $clean['global_ignore_params'] );
		$errors = get_settings_errors( Settings_Service::ERROR_SLUG );
		$this->assertSame( array( 'rfrac_invalid_global_vary_headers' ), array_column( $errors, 'code' ) );
		$this->assertSame( 'error', $errors[0]['type'] );
		$this->assertStringContainsString( 'Vary request headers', $errors[0]['message'] );
		$this->assertStringContainsString( 'The header &quot;cookie&quot; can not be used to vary the cache.', $errors[0]['message'] );
		$this->assertStringContainsString( 'The previous value was kept.', $errors[0]['message'] );
	}

	public function test_sanitize_reports_too_many_entries_and_escapes_the_reason(): void {
		$this->service()->sanitize(
			array(
				'global_ignore_params' => '<script>alert(1)</script>',
				'global_vary_headers'  => 'a b c d e f',
			)
		);

		$errors = array_column( get_settings_errors( Settings_Service::ERROR_SLUG ), 'message', 'code' );
		$this->assertStringContainsString( 'At most 5 entries are allowed', $errors['rfrac_invalid_global_vary_headers'] );
		$this->assertStringNotContainsString( '<script>', $errors['rfrac_invalid_global_ignore_params'] );
	}

	public function test_sanitize_refuses_global_vary_headers_that_overflow_a_stored_rule_and_names_the_route(): void {
		( new Config() )->save_route_rule( '/wp/v2/posts', array( 'vary_headers' => array( 'a', 'b', 'c', 'd' ) ) );

		$clean = $this->service()->sanitize( array( 'global_vary_headers' => 'x y', 'max_params' => 9 ) );

		$this->assertSame( array(), $clean['global_vary_headers'], 'The stored list is kept.' );
		$this->assertSame( 9, $clean['max_params'] );
		$this->assertArrayHasKey( '/wp/v2/posts', $clean['routes'] );
		$errors = get_settings_errors( Settings_Service::ERROR_SLUG );
		$this->assertSame( array( 'rfrac_invalid_global_vary_headers' ), array_column( $errors, 'code' ) );
		$this->assertSame( 'error', $errors[0]['type'] );
		$this->assertStringContainsString( 'Vary request headers', $errors[0]['message'] );
		$this->assertStringContainsString( '/wp/v2/posts', $errors[0]['message'] );
		$this->assertStringContainsString( 'would vary on 6 request headers; at most 5 are allowed.', $errors[0]['message'] );
		$this->assertStringContainsString( 'The previous value was kept.', $errors[0]['message'] );
	}

	public function test_sanitize_accepts_global_vary_headers_that_fit_every_rule_after_deduplication(): void {
		( new Config() )->save_route_rule( '/wp/v2/posts', array( 'vary_headers' => array( 'a', 'b', 'c', 'd' ) ) );

		$clean = $this->service()->sanitize( array( 'global_vary_headers' => 'a x' ) );

		$this->assertSame( array( 'a', 'x' ), $clean['global_vary_headers'] );
		$this->assertSame( array(), $this->error_codes() );
	}

	public function test_a_route_rule_save_does_not_report_the_global_vary_headers_it_writes_back(): void {
		$config  = new Config();
		$service = $this->service();
		$this->assertTrue( $config->save_settings( array( 'global_vary_headers' => 'a b' ) )->is_valid() );
		// A rule that was stored before the global list, so it no longer fits.
		update_option(
			Names::OPTION_SETTINGS,
			array_merge( (array) get_option( Names::OPTION_SETTINGS ), array( 'routes' => array( '/r' => array( 'vary_headers' => array( 'c', 'd', 'e', 'f' ) ) ) ) ),
			false
		);
		add_filter( 'sanitize_option_' . Names::OPTION_SETTINGS, array( $service, 'sanitize' ) );

		$result = $config->save_route_rules( array( '/q' => array( 'status' => 'enabled' ) ) );

		$this->assertTrue( $result->is_valid() );
		$this->assertArrayHasKey( '/q', $config->stored_route_rules() );
		$this->assertSame( array(), $this->error_codes() );
	}

	public function test_sources_of_the_lists_are_default_or_stored(): void {
		$this->assertSame( 'default', $this->service()->sources()['global_ignore_params'] );

		update_option(
			Names::OPTION_SETTINGS,
			array(
				'global_ignore_params' => array( 'utm_source' ),
				'global_vary_headers'  => array( 'cookie' ),
			)
		);
		$sources = $this->service()->sources();

		$this->assertSame( 'stored', $sources['global_ignore_params'] );
		$this->assertSame( 'default', $sources['global_vary_headers'], 'An invalid stored list falls back to the default.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_list_constant_locks_the_field_and_names_the_source(): void {
		define( 'RFRAC_GLOBAL_VARY_HEADERS', 'Accept-Language' );
		update_option( Names::OPTION_SETTINGS, array( 'global_vary_headers' => array( 'x-country' ) ) );
		$service = $this->service();

		$this->assertSame( 'constant:RFRAC_GLOBAL_VARY_HEADERS', $service->sources()['global_vary_headers'] );
		$this->assertSame(
			array(
				'value'    => array( 'accept-language' ),
				'locked'   => true,
				'constant' => 'RFRAC_GLOBAL_VARY_HEADERS',
			),
			$service->view()['global_vary_headers']
		);

		$clean = $service->sanitize( array( 'global_vary_headers' => 'cookie' ) );
		$this->assertSame( array( 'x-country' ), $clean['global_vary_headers'], 'A locked list keeps its stored value.' );
		$this->assertSame( array(), $this->error_codes() );
	}

	public function test_the_nonce_caching_field_is_a_request_rules_checkbox_that_states_the_trade_off(): void {
		$field = $this->service()->schema()['nonce_caching'];

		$this->assertSame( 'bool', $field['type'] );
		$this->assertSame( 'request', $field['section'] );
		$this->assertSame( 'Cache requests with a REST nonce', $field['label'] );
		$this->assertStringContainsString( 'Logged-out requests that carry a REST nonce', $field['description'] );
		$this->assertStringContainsString( 'are cached', $field['description'] );
		$this->assertStringContainsString( 'never checked on a cache hit', $field['description'] );
		$this->assertStringContainsString( 'any login still bypasses', $field['description'] );
	}

	public function test_the_nonce_caching_source_follows_the_stored_option(): void {
		$this->assertSame( 'default', $this->service()->sources()['nonce_caching'] );
		$this->assertFalse( $this->service()->view()['nonce_caching']['value'] );

		update_option( Names::OPTION_SETTINGS, array( 'nonce_caching' => false ) );
		$this->assertSame( 'stored', $this->service()->sources()['nonce_caching'] );

		update_option( Names::OPTION_SETTINGS, array( 'nonce_caching' => true ) );
		$this->assertSame( 'stored', $this->service()->sources()['nonce_caching'] );
		$this->assertTrue( $this->service()->view()['nonce_caching']['value'] );

		update_option( Names::OPTION_SETTINGS, array( 'nonce_caching' => 'maybe' ) );
		$this->assertSame( 'default', $this->service()->sources()['nonce_caching'], 'A stored value that is not a boolean is the default.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_nonce_caching_constant_locks_the_field(): void {
		define( 'RFRAC_NONCE_CACHING', 'yes' );
		$service = $this->service();

		$this->assertSame( 'constant:RFRAC_NONCE_CACHING', $service->sources()['nonce_caching'] );
		$this->assertSame(
			array(
				'value'    => true,
				'locked'   => true,
				'constant' => 'RFRAC_NONCE_CACHING',
			),
			$service->view()['nonce_caching']
		);

		$clean = $service->sanitize( array( 'nonce_caching' => '0', 'max_params' => 5 ) );
		$this->assertArrayNotHasKey( 'nonce_caching', $clean );
		$this->assertSame( array(), $this->error_codes() );
	}

	public function test_sanitize_stores_nonce_caching_only_when_the_form_carries_it(): void {
		$this->assertArrayNotHasKey( 'nonce_caching', $this->service()->sanitize( array( 'max_params' => 5 ) ), 'A form that never had the box leaves the key absent.' );

		$this->assertFalse( $this->service()->sanitize( array( 'nonce_caching' => '0' ) )['nonce_caching'], 'Unticking stores an explicit false.' );
		$this->assertTrue( $this->service()->sanitize( array( 'nonce_caching' => '1' ) )['nonce_caching'] );
		$this->assertSame( array(), $this->error_codes() );
	}

	public function test_sanitize_reports_a_nonce_caching_value_that_is_not_a_boolean_and_keeps_the_stored_one(): void {
		update_option( Names::OPTION_SETTINGS, array( 'nonce_caching' => true ) );

		$clean = $this->service()->sanitize( array( 'nonce_caching' => 'garbage' ) );

		$this->assertTrue( $clean['nonce_caching'] );
		$this->assertSame( array( 'rfrac_invalid_nonce_caching' ), $this->error_codes() );
	}

	public function test_compiled_flag_is_true_only_for_a_flag_the_snapshot_has_on(): void {
		$service = $this->service();
		$this->assertFalse( $service->compiled_flag( 'nonce_caching' ), 'No snapshot.' );

		update_option( Names::OPTION_CONFIG, array( 'store_mode' => 'database' ) );
		$this->assertFalse( $service->compiled_flag( 'nonce_caching' ) );

		update_option( Names::OPTION_CONFIG, array( 'nonce_caching' => 'yes' ) );
		$this->assertFalse( $service->compiled_flag( 'nonce_caching' ), 'Only a strict true counts.' );

		update_option( Names::OPTION_CONFIG, array( 'nonce_caching' => true ) );
		$this->assertTrue( $service->compiled_flag( 'nonce_caching' ) );
	}

	public function test_get_all_effective_and_compiled(): void {
		update_option( Names::OPTION_SETTINGS, array( 'max_entries' => 500 ) );
		$service = $this->service();

		$this->assertSame( 500, $service->get( 'max_entries' ) );
		$this->assertNull( $service->get( 'nope' ) );
		$this->assertSame( ( new Config() )->settings(), $service->all_effective() );

		$this->assertSame( array(), $service->compiled() );
		update_option( Names::OPTION_CONFIG, array( 'store_mode' => 'database' ) );
		$this->assertSame( array( 'store_mode' => 'database' ), $service->compiled() );
	}
}
