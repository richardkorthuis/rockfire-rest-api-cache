<?php
/**
 * Tests for settings access, constant overrides and route rule persistence.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Route_Rule;
use Rockfire\Rest_Api_Cache\Config\Settings_Schema;
use Rockfire\Rest_Api_Cache\Names;

/**
 * @covers \Rockfire\Rest_Api_Cache\Config\Config
 */
class Test_Config extends Rfrac_Test_Case {

	use Rfrac_Fails_Option_Writes;

	/**
	 * Fresh config instance.
	 *
	 * @return Config
	 */
	private function config(): Config {
		return new Config();
	}

	/**
	 * Count update_option calls for the settings option while running a callback.
	 *
	 * @param callable $callback Work to run.
	 *
	 * @return int
	 */
	private function count_updates( callable $callback ): int {
		$calls  = 0;
		$filter = static function ( $value ) use ( &$calls ) {
			++$calls;
			return $value;
		};
		add_filter( 'pre_update_option_' . Names::OPTION_SETTINGS, $filter );
		$callback();
		remove_filter( 'pre_update_option_' . Names::OPTION_SETTINGS, $filter );
		return $calls;
	}

	public function test_defaults_without_stored_option(): void {
		$expected           = Settings_Schema::defaults();
		$expected['routes'] = array();

		$this->assertSame( $expected, $this->config()->settings() );
		$this->assertSame( $expected, $this->config()->raw_settings() );
		$this->assertSame( array(), $this->config()->locked() );
		$this->assertSame( array(), $this->config()->constant_errors() );
	}

	public function test_the_global_request_lists_default_to_empty_lists(): void {
		$settings = $this->config()->settings();

		$this->assertSame( array(), $settings['global_ignore_params'] );
		$this->assertSame( array(), $settings['global_vary_headers'] );
		$this->assertSame( array(), $this->config()->raw_settings()['global_ignore_params'] );
		$this->assertSame( array(), $this->config()->raw_settings()['global_vary_headers'] );
	}

	public function test_save_settings_persists_a_normalised_list(): void {
		$result = $this->config()->save_settings(
			array(
				'global_ignore_params' => 'utm_source fbclid',
				'global_vary_headers'  => array( 'Accept_Language', 'accept-language' ),
			)
		);

		$this->assertTrue( $result->is_valid() );
		$stored = get_option( Names::OPTION_SETTINGS );
		$this->assertSame( array( 'utm_source', 'fbclid' ), $stored['global_ignore_params'] );
		$this->assertSame( array( 'accept-language' ), $stored['global_vary_headers'] );
		$this->assertSame( array( 'utm_source', 'fbclid' ), $this->config()->settings()['global_ignore_params'] );
	}

	public function test_save_settings_keeps_the_old_list_when_the_new_one_is_invalid(): void {
		$this->config()->save_settings( array( 'global_ignore_params' => array( 'utm_source' ) ) );

		$result = $this->config()->save_settings(
			array(
				'global_ignore_params' => array( 'bad name' ),
				'max_params'           => 7,
			)
		);

		$this->assertFalse( $result->is_valid() );
		$this->assertTrue( $result->has_code( 'invalid_value' ) );
		$this->assertSame( 'global_ignore_params', $result->errors()[0]['field'] );
		$this->assertSame( array( 'utm_source' ), $this->config()->settings()['global_ignore_params'] );
		$this->assertSame( 7, $this->config()->settings()['max_params'], 'The valid keys are saved.' );
	}

	public function test_save_settings_can_empty_a_list(): void {
		$this->config()->save_settings( array( 'global_vary_headers' => 'accept-language' ) );

		$this->assertTrue( $this->config()->save_settings( array( 'global_vary_headers' => '' ) )->is_valid() );
		$this->assertSame( array(), $this->config()->settings()['global_vary_headers'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_list_constant_sets_the_value_and_locks_the_key(): void {
		define( 'RFRAC_GLOBAL_VARY_HEADERS', 'Accept-Language' );

		$this->assertSame( array( 'accept-language' ), $this->config()->settings()['global_vary_headers'] );
		$this->assertSame( 'RFRAC_GLOBAL_VARY_HEADERS', $this->config()->locked()['global_vary_headers'] );
		$this->assertTrue( $this->config()->is_locked( 'global_vary_headers' ) );
		$this->assertFalse( $this->config()->is_locked( 'global_ignore_params' ) );
		$this->assertSame( array(), $this->config()->constant_errors() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_list_constant_given_as_an_array_locks_the_key(): void {
		define( 'RFRAC_GLOBAL_IGNORE_PARAMS', array( 'utm_source', 'fbclid', 'utm_source' ) );

		$this->assertSame( array( 'utm_source', 'fbclid' ), $this->config()->settings()['global_ignore_params'] );
		$this->assertSame( 'RFRAC_GLOBAL_IGNORE_PARAMS', $this->config()->locked()['global_ignore_params'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_empty_list_constant_locks_the_key_to_an_empty_list(): void {
		define( 'RFRAC_GLOBAL_IGNORE_PARAMS', array() );
		define( 'RFRAC_GLOBAL_VARY_HEADERS', '' );
		update_option(
			Names::OPTION_SETTINGS,
			array(
				'global_ignore_params' => array( 'utm_source' ),
				'global_vary_headers'  => array( 'accept-language' ),
			),
			false
		);

		$this->assertSame( array(), $this->config()->settings()['global_ignore_params'] );
		$this->assertSame( array(), $this->config()->settings()['global_vary_headers'] );
		$this->assertSame( 'RFRAC_GLOBAL_IGNORE_PARAMS', $this->config()->locked()['global_ignore_params'] );
		$this->assertSame( 'RFRAC_GLOBAL_VARY_HEADERS', $this->config()->locked()['global_vary_headers'] );
		$this->assertSame( array(), $this->config()->constant_errors() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_invalid_list_constant_is_reported_and_does_not_lock(): void {
		define( 'RFRAC_GLOBAL_VARY_HEADERS', array( 'cookie' ) );
		define( 'RFRAC_GLOBAL_IGNORE_PARAMS', array( 'good', 'bad name' ) );
		update_option(
			Names::OPTION_SETTINGS,
			array(
				'global_ignore_params' => array( 'utm_source' ),
				'global_vary_headers'  => array( 'accept-language' ),
			),
			false
		);

		$errors = $this->config()->constant_errors();
		$this->assertArrayHasKey( 'RFRAC_GLOBAL_VARY_HEADERS', $errors );
		$this->assertStringContainsString( 'Expected a list of request header names.', $errors['RFRAC_GLOBAL_VARY_HEADERS'] );
		$this->assertStringContainsString( 'The header "cookie" can not be used', $errors['RFRAC_GLOBAL_VARY_HEADERS'] );
		$this->assertArrayHasKey( 'RFRAC_GLOBAL_IGNORE_PARAMS', $errors );
		$this->assertStringContainsString( 'Expected a list of query parameter names.', $errors['RFRAC_GLOBAL_IGNORE_PARAMS'] );
		$this->assertStringContainsString( 'Invalid parameter name "bad name"', $errors['RFRAC_GLOBAL_IGNORE_PARAMS'] );
		$this->assertFalse( $this->config()->is_locked( 'global_vary_headers' ) );
		$this->assertFalse( $this->config()->is_locked( 'global_ignore_params' ) );
		$this->assertSame( array( 'accept-language' ), $this->config()->settings()['global_vary_headers'], 'The stored value applies.' );
		$this->assertSame( array( 'utm_source' ), $this->config()->settings()['global_ignore_params'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_locked_list_is_not_persisted(): void {
		define( 'RFRAC_GLOBAL_IGNORE_PARAMS', 'utm_source' );
		$this->config()->save_settings( array( 'global_ignore_params' => 'fbclid' ) );

		$this->assertSame( array( 'utm_source' ), $this->config()->settings()['global_ignore_params'] );
		$this->assertSame( array(), $this->config()->raw_settings()['global_ignore_params'], 'Locked keys are never persisted.' );
	}

	public function test_option_is_not_autoloaded(): void {
		global $wpdb;
		$this->config()->save_settings( array( 'max_params' => 5 ) );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Names::OPTION_SETTINGS ) );
		$this->assertContains( $autoload, array( 'off', 'no' ) );
	}

	public function test_save_settings_clamps_ranges(): void {
		$result = $this->config()->save_settings( array( 'max_params' => 500, 'max_entries' => 5, 'hit_sampling' => 0 ) );

		$this->assertTrue( $result->is_valid() );
		$settings = $this->config()->settings();
		$this->assertSame( 100, $settings['max_params'] );
		$this->assertSame( 100, $settings['max_entries'] );
		$this->assertSame( 0, $settings['hit_sampling'] );
		$this->assertSame( 100, get_option( Names::OPTION_SETTINGS )['max_params'] );
	}

	public function test_save_settings_wrong_type_keeps_old_value_and_saves_the_rest(): void {
		$this->config()->save_settings( array( 'store' => 'database', 'max_params' => 7 ) );
		$result = $this->config()->save_settings( array( 'store' => 'redis', 'max_params' => 'abc', 'max_entries' => 500 ) );

		$this->assertFalse( $result->is_valid() );
		$this->assertTrue( $result->has_code( 'invalid_value' ) );
		$settings = $this->config()->settings();
		$this->assertSame( 'database', $settings['store'] );
		$this->assertSame( 7, $settings['max_params'] );
		$this->assertSame( 500, $settings['max_entries'] );
	}

	public function test_save_settings_preserves_routes(): void {
		$this->config()->save_route_rule( '/wp/v2/posts', array( 'status' => 'enabled' ) );
		$this->config()->save_settings( array( 'max_params' => 9, 'routes' => array() ) );

		$this->assertArrayHasKey( '/wp/v2/posts', $this->config()->stored_route_rules() );
	}

	public function test_save_route_rule_clamps_ttl(): void {
		$this->assertTrue( $this->config()->save_route_rule( '/wp/v2/posts', array( 'ttl' => 10 ) )->is_valid() );
		$this->assertSame( 60, $this->config()->stored_route_rules()['/wp/v2/posts']->ttl );

		$this->config()->save_settings( array( 'max_ttl' => 3600 ) );
		$this->config()->save_route_rule( '/wp/v2/pages', array( 'ttl' => 99999 ) );
		$this->assertSame( 3600, $this->config()->stored_route_rules()['/wp/v2/pages']->ttl );
	}

	public function test_forbidden_vary_header_saves_nothing(): void {
		$updates = $this->count_updates(
			function () use ( &$result ) {
				$result = $this->config()->save_route_rule( '/wp/v2/posts', array( 'vary_headers' => array( 'Cookie' ) ) );
			}
		);

		$this->assertFalse( $result->is_valid() );
		$this->assertTrue( $result->has_code( 'forbidden_vary_header' ) );
		$this->assertSame( 0, $updates );
		$this->assertFalse( get_option( Names::OPTION_SETTINGS ) );
		$this->assertSame( array(), $this->config()->stored_route_rules() );
	}

	public function test_invalid_route_key_is_rejected(): void {
		$result = $this->config()->save_route_rule( 'no-slash', array() );
		$this->assertTrue( $result->has_code( 'invalid_route_key' ) );

		$result = $this->config()->save_route_rule( '/wp/(?P<id>\d)', array( 'match' => 'wildcard' ) );
		$this->assertTrue( $result->has_code( 'invalid_route_key' ) );
	}

	public function test_bulk_save_performs_one_update_option(): void {
		$this->config()->save_route_rules( array( '/a' => array( 'status' => 'enabled' ), '/b' => array( 'status' => 'enabled' ) ) );

		$updates = $this->count_updates(
			function () {
				$result = $this->config()->save_route_rules(
					array(
						'/a' => array( 'status' => 'excluded' ),
						'/b' => null,
						'/c' => array( 'ttl' => 300 ),
					)
				);
				$this->assertTrue( $result->is_valid() );
			}
		);

		$this->assertSame( 1, $updates );
		$rules = $this->config()->stored_route_rules();
		$this->assertSame( array( '/a', '/c' ), array_keys( $rules ) );
		$this->assertSame( 'excluded', $rules['/a']->status );
	}

	public function test_bulk_save_is_atomic(): void {
		$updates = $this->count_updates(
			function () use ( &$result ) {
				$result = $this->config()->save_route_rules(
					array(
						'/ok'  => array( 'status' => 'enabled' ),
						'/bad' => array( 'status' => 'nonsense' ),
					)
				);
			}
		);

		$this->assertTrue( $result->has_code( 'invalid_status' ) );
		$this->assertSame( 0, $updates );
		$this->assertSame( array(), $this->config()->stored_route_rules() );
	}

	public function test_delete_route_rule(): void {
		$this->config()->save_route_rule( '/a', array( 'status' => 'enabled' ) );
		$this->assertTrue( $this->config()->delete_route_rule( '/a' )->is_valid() );
		$this->assertSame( array(), $this->config()->stored_route_rules() );
		$this->assertSame( array(), get_option( Names::OPTION_SETTINGS )['routes'] );
	}

	public function test_a_route_rule_that_overflows_the_vary_limit_with_the_global_headers_saves_nothing(): void {
		$this->config()->save_settings( array( 'global_vary_headers' => 'a b' ) );

		$updates = $this->count_updates(
			function () use ( &$result ) {
				$result = $this->config()->save_route_rule( '/wp/v2/posts', array( 'vary_headers' => array( 'c', 'd', 'e', 'f' ) ) );
			}
		);

		$this->assertFalse( $result->is_valid() );
		$this->assertSame( array( 'too_many_vary_headers' ), $result->codes() );
		$this->assertSame( 'vary_headers', $result->errors()[0]['field'] );
		$this->assertStringContainsString( 'the route "/wp/v2/posts" would vary on 6 request headers; at most 5 are allowed.', $result->errors()[0]['message'] );
		$this->assertSame( 0, $updates );
		$this->assertSame( array(), $this->config()->stored_route_rules() );

		$this->assertTrue( $this->config()->save_route_rule( '/wp/v2/posts', array( 'vary_headers' => array( 'c', 'd', 'e' ) ) )->is_valid() );
		$this->assertSame( array( 'c', 'd', 'e' ), $this->config()->stored_route_rules()['/wp/v2/posts']->vary_headers );
	}

	public function test_a_route_rule_header_that_is_also_global_counts_once(): void {
		$this->config()->save_settings( array( 'global_vary_headers' => 'a b' ) );

		$this->assertTrue( $this->config()->save_route_rule( '/wp/v2/posts', array( 'vary_headers' => array( 'a', 'c', 'd', 'e' ) ) )->is_valid() );
	}

	public function test_a_batch_with_one_route_that_overflows_saves_none(): void {
		$this->config()->save_settings( array( 'global_vary_headers' => 'a b' ) );

		$result = $this->config()->save_route_rules(
			array(
				'/ok'  => array( 'status' => 'enabled' ),
				'/bad' => array( 'vary_headers' => array( 'c', 'd', 'e', 'f' ) ),
			)
		);

		$this->assertSame( array( 'too_many_vary_headers' ), $result->codes() );
		$this->assertSame( array(), $this->config()->stored_route_rules() );
	}

	public function test_deleting_a_route_rule_is_not_held_to_the_vary_limit(): void {
		$this->config()->save_route_rule( '/a', array( 'vary_headers' => array( 'c', 'd', 'e', 'f', 'g' ) ) );
		update_option( Names::OPTION_SETTINGS, array_merge( get_option( Names::OPTION_SETTINGS ), array( 'global_vary_headers' => array( 'a' ) ) ), false );

		$this->assertTrue( $this->config()->delete_route_rule( '/a' )->is_valid() );
		$this->assertSame( array(), $this->config()->stored_route_rules() );
	}

	public function test_global_vary_headers_that_overflow_a_stored_rule_are_refused(): void {
		$this->config()->save_settings( array( 'global_vary_headers' => 'a' ) );
		$this->config()->save_route_rule( '/wp/v2/posts', array( 'vary_headers' => array( 'a', 'b', 'c', 'd' ) ) );

		$result = $this->config()->save_settings( array( 'global_vary_headers' => 'x y', 'max_params' => 7 ) );

		$this->assertFalse( $result->is_valid() );
		$this->assertSame( array( 'too_many_vary_headers' ), $result->codes() );
		$this->assertSame( 'global_vary_headers', $result->errors()[0]['field'] );
		$this->assertStringContainsString( '"/wp/v2/posts"', $result->errors()[0]['message'] );
		$this->assertSame( array( 'a' ), $this->config()->settings()['global_vary_headers'], 'The old global list is kept.' );
		$this->assertSame( 7, $this->config()->settings()['max_params'], 'The valid keys are saved.' );

		$this->assertTrue( $this->config()->save_settings( array( 'global_vary_headers' => 'a x' ) )->is_valid(), 'Five headers after deduplication fit.' );
		$this->assertSame( array( 'a', 'x' ), $this->config()->settings()['global_vary_headers'] );
	}

	public function test_a_refused_global_vary_list_with_nothing_else_changed_writes_nothing(): void {
		$this->config()->save_route_rule( '/wp/v2/posts', array( 'vary_headers' => array( 'a', 'b', 'c', 'd' ) ) );

		$updates = $this->count_updates(
			function () use ( &$result ) {
				$result = $this->config()->save_settings( array( 'global_vary_headers' => 'x y' ) );
			}
		);

		$this->assertSame( array( 'too_many_vary_headers' ), $result->codes() );
		$this->assertSame( 0, $updates );
	}

	public function test_sanitize_settings_keeps_the_stored_global_vary_headers_when_they_overflow_a_rule(): void {
		$this->config()->save_route_rule( '/wp/v2/posts', array( 'vary_headers' => array( 'a', 'b', 'c', 'd' ) ) );

		$clean = $this->config()->sanitize_settings( array( 'global_vary_headers' => 'x y', 'max_params' => 9 ) );

		$this->assertSame( array(), $clean['global_vary_headers'] );
		$this->assertSame( 9, $clean['max_params'] );
		$this->assertArrayHasKey( '/wp/v2/posts', $clean['routes'] );
		$this->assertSame( array( 'a', 'x' ), $this->config()->sanitize_settings( array( 'global_vary_headers' => 'a x' ) )['global_vary_headers'] );
	}

	public function test_vary_fit_reports_one_error_per_rule_on_the_route_key(): void {
		$result = $this->config()->vary_fit(
			array( 'x' ),
			array(
				'/k'  => array( 'vary_headers' => array( 'a', 'b', 'c', 'd', 'e' ) ),
				'/ok' => array( 'vary_headers' => array( 'a', 'b', 'c', 'd' ) ),
				'/in' => array( 'vary_headers' => array( 'a', 'b', 'c', 'd', 'x' ) ),
			)
		);

		$this->assertCount( 1, $result->errors() );
		$this->assertSame( 'too_many_vary_headers', $result->errors()[0]['code'] );
		$this->assertSame( '/k', $result->errors()[0]['field'] );
		$this->assertSame( 'With the global vary headers, the route "/k" would vary on 6 request headers; at most 5 are allowed.', $result->errors()[0]['message'] );
	}

	public function test_vary_fit_without_rules_checks_the_stored_rules_of_any_status(): void {
		$this->config()->save_route_rule( '/off', array( 'status' => 'excluded', 'vary_headers' => array( 'a', 'b', 'c', 'd' ) ) );
		$this->config()->save_route_rule( '/fine', array( 'vary_headers' => array( 'a' ) ) );

		$result = $this->config()->vary_fit( array( 'x', 'y' ) );

		$this->assertSame( array( '/off' ), array_column( $result->errors(), 'field' ) );
		$this->assertTrue( $this->config()->vary_fit( array( 'x' ) )->is_valid() );
		$this->assertTrue( $this->config()->vary_fit( array() )->is_valid() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_vary_fit_without_rules_also_checks_the_routes_constant(): void {
		define(
			'RFRAC_ROUTES',
			array(
				'/const'   => array( 'vary_headers' => array( 'a', 'b', 'c', 'd' ) ),
				'/invalid' => array( 'vary_headers' => array( 'cookie', 'b', 'c', 'd' ) ),
			)
		);

		$result = $this->config()->vary_fit( array( 'x', 'y' ) );

		$this->assertSame( array( '/const' ), array_column( $result->errors(), 'field' ), 'Valid constant rules count, invalid ones are ignored.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_route_save_is_checked_against_the_global_vary_constant(): void {
		define( 'RFRAC_GLOBAL_VARY_HEADERS', 'a b' );
		update_option( Names::OPTION_SETTINGS, array( 'global_vary_headers' => array() ), false );

		$result = $this->config()->save_route_rule( '/wp/v2/posts', array( 'vary_headers' => array( 'c', 'd', 'e', 'f' ) ) );

		$this->assertSame( array( 'too_many_vary_headers' ), $result->codes() );
		$this->assertTrue( $this->config()->save_route_rule( '/wp/v2/posts', array( 'vary_headers' => array( 'c', 'd', 'e' ) ) )->is_valid() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_settings_save_skips_the_locked_global_vary_headers(): void {
		define( 'RFRAC_GLOBAL_VARY_HEADERS', 'a' );
		$this->config()->save_route_rule( '/wp/v2/posts', array( 'vary_headers' => array( 'b', 'c', 'd', 'e' ) ) );

		$result = $this->config()->save_settings( array( 'global_vary_headers' => 'x y', 'max_params' => 7 ) );

		$this->assertTrue( $result->is_valid(), 'The locked key is skipped, not checked.' );
		$this->assertSame( array( 'a' ), $this->config()->settings()['global_vary_headers'] );
		$this->assertSame( array(), $this->config()->raw_settings()['global_vary_headers'] );
		$this->assertSame( 7, $this->config()->settings()['max_params'] );
	}

	/**
	 * The settings option exactly as the database holds it.
	 *
	 * @return string|null Null when the option row does not exist.
	 */
	private function stored_bytes(): ?string {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Names::OPTION_SETTINGS ) );
	}

	/**
	 * Save a setting and a rule, so the option exists and later saves go through update_option().
	 *
	 * @return void
	 */
	private function seed_settings_and_rules(): void {
		$result = $this->config()->save_settings_and_rules( array( 'max_params' => 7 ), array( '/seed' => array( 'status' => 'enabled' ) ) );
		$this->assertTrue( $result->is_valid(), 'Precondition: the seed saves.' );
	}

	public function test_save_settings_and_rules_writes_both_with_one_update_option(): void {
		$this->seed_settings_and_rules();
		$fired = 0;
		$count = static function () use ( &$fired ): void {
			++$fired;
		};
		add_action( 'update_option_' . Names::OPTION_SETTINGS, $count );

		$result  = null;
		$updates = $this->count_updates(
			function () use ( &$result ) {
				$result = $this->config()->save_settings_and_rules(
					array( 'default_ttl' => 600, 'max_ttl' => 7200 ),
					array(
						'/a' => array( 'status' => 'enabled', 'ttl' => 300 ),
						'/b' => array( 'status' => 'excluded' ),
					)
				);
			}
		);
		remove_action( 'update_option_' . Names::OPTION_SETTINGS, $count );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( 1, $updates );
		$this->assertSame( 1, $fired, 'update_option_rfrac_settings fires exactly once.' );
		$stored = get_option( Names::OPTION_SETTINGS );
		$this->assertSame( 600, $stored['default_ttl'] );
		$this->assertSame( 7200, $stored['max_ttl'] );
		$this->assertSame( 7, $stored['max_params'], 'Settings that are not in the input keep their value.' );
		$this->assertSame( array( '/seed', '/a', '/b' ), array_keys( $stored['routes'] ) );
		$this->assertSame( 300, $stored['routes']['/a']['ttl'] );
		$this->assertSame( 'excluded', $stored['routes']['/b']['status'] );
	}

	public function test_save_settings_and_rules_deletes_a_rule_with_null(): void {
		$this->seed_settings_and_rules();

		$result = $this->config()->save_settings_and_rules( array(), array( '/seed' => null, '/never-stored' => null ) );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( array(), get_option( Names::OPTION_SETTINGS )['routes'] );
		$this->assertSame( 7, get_option( Names::OPTION_SETTINGS )['max_params'] );
	}

	public function test_save_settings_and_rules_ignores_the_routes_key_of_the_settings(): void {
		$this->seed_settings_and_rules();

		$result = $this->config()->save_settings_and_rules(
			array( 'max_params' => 9, 'routes' => array( '/forged' => array( 'status' => 'enabled' ) ) ),
			array( '/a' => array( 'status' => 'enabled' ) )
		);

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( array( '/seed', '/a' ), array_keys( $this->config()->stored_route_rules() ) );
		$this->assertSame( 9, $this->config()->settings()['max_params'] );
	}

	public function test_save_settings_and_rules_with_an_invalid_rule_saves_nothing(): void {
		$this->seed_settings_and_rules();
		$before = $this->stored_bytes();

		$updates = $this->count_updates(
			function () use ( &$result ) {
				$result = $this->config()->save_settings_and_rules(
					array( 'max_params' => 9 ),
					array(
						'/ok'  => array( 'status' => 'enabled' ),
						'/bad' => array( 'status' => 'nonsense' ),
					)
				);
			}
		);

		$this->assertTrue( $result->has_code( 'invalid_status' ) );
		$this->assertSame( 0, $updates );
		$this->assertSame( $before, $this->stored_bytes(), 'The stored option is byte-identical.' );
	}

	public function test_save_settings_and_rules_with_an_invalid_setting_saves_nothing(): void {
		$this->seed_settings_and_rules();
		$before = $this->stored_bytes();

		$updates = $this->count_updates(
			function () use ( &$result ) {
				$result = $this->config()->save_settings_and_rules(
					array( 'store' => 'redis', 'max_params' => 9 ),
					array( '/ok' => array( 'status' => 'enabled' ) )
				);
			}
		);

		$this->assertTrue( $result->has_code( 'invalid_value' ) );
		$this->assertSame( 0, $updates );
		$this->assertSame( $before, $this->stored_bytes(), 'The valid parts are not saved either.' );
	}

	public function test_save_settings_and_rules_rejects_a_rule_that_is_not_an_array(): void {
		$this->seed_settings_and_rules();
		$before = $this->stored_bytes();

		$result = $this->config()->save_settings_and_rules( array( 'max_params' => 9 ), array( '/a' => 'enabled' ) );

		$this->assertTrue( $result->has_code( 'invalid_type' ) );
		$this->assertSame( $before, $this->stored_bytes() );
	}

	public function test_save_settings_and_rules_clamps_rule_ttls_to_the_new_max_ttl(): void {
		$this->assertSame( 2592000, $this->config()->settings()['max_ttl'], 'Precondition: the default max_ttl.' );
		$this->config()->save_route_rule( '/before', array( 'ttl' => 31536000 ) );
		$this->assertSame( 2592000, $this->config()->stored_route_rules()['/before']->ttl, 'A rule saved alone is clamped to the stored max_ttl.' );

		$result = $this->config()->save_settings_and_rules( array( 'max_ttl' => 31536000 ), array( '/a' => array( 'ttl' => 31536000 ) ) );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( 31536000, $this->config()->stored_route_rules()['/a']->ttl, 'The rule is clamped to the new max_ttl, not the old one.' );

		$this->config()->save_settings_and_rules( array( 'max_ttl' => 3600 ), array( '/b' => array( 'ttl' => 99999 ), '/c' => array( 'ttl' => 10 ) ) );
		$this->assertSame( 3600, $this->config()->stored_route_rules()['/b']->ttl );
		$this->assertSame( 60, $this->config()->stored_route_rules()['/c']->ttl );
	}

	public function test_save_settings_and_rules_with_nothing_changed_writes_nothing(): void {
		$this->seed_settings_and_rules();
		$fired = 0;
		$count = static function () use ( &$fired ): void {
			++$fired;
		};
		add_action( 'update_option_' . Names::OPTION_SETTINGS, $count );
		$before = $this->stored_bytes();

		$updates = $this->count_updates(
			function () {
				$result = $this->config()->save_settings_and_rules(
					array( 'max_params' => 7, 'routes' => array() ),
					array( '/seed' => array( 'status' => 'enabled' ), '/never-stored' => null )
				);
				$this->assertTrue( $result->is_valid() );
				$this->assertTrue( $this->config()->save_settings_and_rules( array(), array() )->is_valid() );
			}
		);
		remove_action( 'update_option_' . Names::OPTION_SETTINGS, $count );

		$this->assertSame( 0, $updates );
		$this->assertSame( 0, $fired );
		$this->assertSame( $before, $this->stored_bytes() );
	}

	public function test_save_settings_and_rules_without_a_stored_option_and_without_changes_stores_nothing(): void {
		$this->assertTrue( $this->config()->save_settings_and_rules( array(), array() )->is_valid() );
		$this->assertNull( $this->stored_bytes() );
	}

	public function test_save_settings_and_rules_reports_a_failed_write(): void {
		$this->seed_settings_and_rules();
		$this->fail_option_writes( Names::OPTION_SETTINGS );

		$result = $this->config()->save_settings_and_rules( array( 'max_params' => 9 ), array( '/a' => array( 'status' => 'enabled' ) ) );

		$this->assertFalse( $result->is_valid() );
		$this->assertTrue( $result->has_code( Config::SAVE_FAILED ) );
	}

	public function test_save_route_rules_still_clamps_to_the_stored_max_ttl_and_saves_none_of_an_invalid_batch(): void {
		$this->config()->save_route_rules( array( '/a' => array( 'ttl' => 99999999 ), '/b' => array( 'status' => 'nonsense' ) ) );
		$this->assertSame( array(), $this->config()->stored_route_rules() );

		$this->config()->save_route_rules( array( '/a' => array( 'ttl' => 99999999 ) ) );
		$this->assertSame( 2592000, $this->config()->stored_route_rules()['/a']->ttl );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_save_settings_and_rules_with_a_constant_locked_setting_saves_nothing(): void {
		$this->seed_settings_and_rules();
		$before = $this->stored_bytes();
		define( 'RFRAC_MAX_TTL', 7200 );

		$updates = $this->count_updates(
			function () use ( &$result ) {
				$result = $this->config()->save_settings_and_rules(
					array( 'max_ttl' => 9999, 'max_params' => 5 ),
					array( '/a' => array( 'status' => 'enabled' ) )
				);
			}
		);

		$this->assertFalse( $result->is_valid() );
		$this->assertTrue( $result->has_code( 'locked_setting' ) );
		$this->assertSame( 'max_ttl', $result->errors()[0]['field'] );
		$this->assertSame( 0, $updates );
		$this->assertSame( $before, $this->stored_bytes(), 'The stored option is byte-identical.' );

		$this->assertTrue( $this->config()->save_settings_and_rules( array( 'max_params' => 5 ), array() )->is_valid(), 'A locked key that is not in the input is no error.' );
		$this->assertSame( 5, $this->config()->settings()['max_params'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_save_settings_and_rules_clamps_rule_ttls_to_a_constant_max_ttl(): void {
		define( 'RFRAC_MAX_TTL', 7200 );

		$result = $this->config()->save_settings_and_rules( array( 'max_params' => 5 ), array( '/a' => array( 'ttl' => 99999 ) ) );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( 7200, $this->config()->stored_route_rules()['/a']->ttl, 'RFRAC_MAX_TTL still wins.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_save_settings_and_rules_with_a_constant_locked_route_saves_nothing(): void {
		$this->seed_settings_and_rules();
		$before = $this->stored_bytes();
		define( 'RFRAC_ROUTES', array( '/wp/v2/posts' => array( 'status' => 'excluded' ) ) );

		$updates = $this->count_updates(
			function () use ( &$result ) {
				$result = $this->config()->save_settings_and_rules(
					array( 'max_params' => 5 ),
					array(
						'/ok'          => array( 'status' => 'enabled' ),
						'/wp/v2/posts' => array( 'status' => 'enabled' ),
					)
				);
			}
		);

		$this->assertTrue( $result->has_code( 'locked_route' ) );
		$this->assertFalse( $result->has_code( 'locked_setting' ) );
		$this->assertSame( 0, $updates );
		$this->assertSame( $before, $this->stored_bytes() );

		$this->assertTrue( $this->config()->save_settings_and_rules( array( 'routes' => array(), 'max_params' => 5 ), array() )->is_valid(), 'The routes key is ignored, so RFRAC_ROUTES locks nothing in the settings.' );
	}

	public function test_save_settings_and_rules_refuses_a_rule_that_overflows_the_vary_limit_with_the_new_global_headers(): void {
		$this->seed_settings_and_rules();
		$before = $this->stored_bytes();

		$updates = $this->count_updates(
			function () use ( &$result ) {
				$result = $this->config()->save_settings_and_rules(
					array( 'global_vary_headers' => 'a b', 'max_params' => 9 ),
					array( '/wp/v2/posts' => array( 'vary_headers' => array( 'c', 'd', 'e', 'f' ) ) )
				);
			}
		);

		$this->assertFalse( $result->is_valid() );
		$this->assertSame( array( 'too_many_vary_headers' ), $result->codes() );
		$this->assertSame( 'vary_headers', $result->errors()[0]['field'] );
		$this->assertStringContainsString( 'the route "/wp/v2/posts" would vary on 6 request headers; at most 5 are allowed.', $result->errors()[0]['message'] );
		$this->assertSame( 0, $updates );
		$this->assertSame( $before, $this->stored_bytes(), 'Nothing is saved, the valid setting neither.' );

		$result = $this->config()->save_settings_and_rules(
			array( 'global_vary_headers' => 'a b', 'max_params' => 9 ),
			array( '/wp/v2/posts' => array( 'vary_headers' => array( 'a', 'c', 'd', 'e' ) ) )
		);
		$this->assertTrue( $result->is_valid(), 'A header that is also global counts once.' );
		$this->assertSame( array( 'a', 'b' ), $this->config()->settings()['global_vary_headers'] );
		$this->assertSame( array( 'a', 'c', 'd', 'e' ), $this->config()->stored_route_rules()['/wp/v2/posts']->vary_headers );
	}

	public function test_save_settings_and_rules_refuses_global_vary_headers_that_overflow_a_rule_it_leaves_alone(): void {
		$this->config()->save_route_rule( '/wp/v2/posts', array( 'vary_headers' => array( 'a', 'b', 'c', 'd' ) ) );
		$before = $this->stored_bytes();

		$updates = $this->count_updates(
			function () use ( &$result ) {
				$result = $this->config()->save_settings_and_rules(
					array( 'global_vary_headers' => 'x y', 'max_params' => 9 ),
					array( '/other' => array( 'status' => 'enabled' ) )
				);
			}
		);

		$this->assertFalse( $result->is_valid() );
		$this->assertSame( array( 'too_many_vary_headers' ), $result->codes() );
		$this->assertSame( 'global_vary_headers', $result->errors()[0]['field'] );
		$this->assertStringContainsString( '"/wp/v2/posts"', $result->errors()[0]['message'] );
		$this->assertSame( 0, $updates );
		$this->assertSame( $before, $this->stored_bytes(), 'Nothing is saved, the valid setting and rule neither.' );

		$this->assertTrue( $this->config()->save_settings_and_rules( array( 'global_vary_headers' => 'a x' ), array() )->is_valid(), 'Five headers after deduplication fit.' );
		$this->assertSame( array( 'a', 'x' ), $this->config()->settings()['global_vary_headers'] );
	}

	public function test_save_settings_and_rules_checks_the_vary_limit_against_the_rules_as_saved(): void {
		$this->config()->save_route_rule( '/wp/v2/posts', array( 'vary_headers' => array( 'a', 'b', 'c', 'd' ) ) );
		$this->config()->save_route_rule( '/wp/v2/pages', array( 'vary_headers' => array( 'a', 'b', 'c', 'd' ) ) );

		$result = $this->config()->save_settings_and_rules(
			array( 'global_vary_headers' => 'x y' ),
			array(
				'/wp/v2/posts' => array( 'vary_headers' => array( 'a', 'b', 'c' ) ),
				'/wp/v2/pages' => null,
			)
		);

		$this->assertTrue( $result->is_valid(), 'The rules that overflowed are shortened or deleted by the same save.' );
		$this->assertSame( array( 'x', 'y' ), $this->config()->settings()['global_vary_headers'] );
		$this->assertSame( array( '/wp/v2/posts' ), array_keys( $this->config()->stored_route_rules() ) );
		$this->assertSame( array( 'a', 'b', 'c' ), $this->config()->stored_route_rules()['/wp/v2/posts']->vary_headers );
	}

	public function test_save_settings_and_rules_does_not_hold_an_unchanged_global_list_to_the_vary_limit(): void {
		$this->config()->save_route_rule( '/wp/v2/posts', array( 'vary_headers' => array( 'a', 'b', 'c', 'd', 'e' ) ) );
		update_option( Names::OPTION_SETTINGS, array_merge( get_option( Names::OPTION_SETTINGS ), array( 'global_vary_headers' => array( 'x' ) ) ), false );

		$result = $this->config()->save_settings_and_rules( array( 'max_params' => 9 ), array( '/other' => array( 'status' => 'enabled' ) ) );

		$this->assertTrue( $result->is_valid(), 'Like save_settings(), only a changed global list is checked against the rules that are left alone.' );
		$this->assertSame( 9, $this->config()->settings()['max_params'] );
		$this->assertArrayHasKey( '/other', $this->config()->stored_route_rules() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_save_settings_and_rules_checks_a_rule_against_the_global_vary_constant(): void {
		define( 'RFRAC_GLOBAL_VARY_HEADERS', 'a b' );

		$result = $this->config()->save_settings_and_rules( array( 'max_params' => 7 ), array( '/wp/v2/posts' => array( 'vary_headers' => array( 'c', 'd', 'e', 'f' ) ) ) );

		$this->assertSame( array( 'too_many_vary_headers' ), $result->codes() );
		$this->assertSame( 'vary_headers', $result->errors()[0]['field'] );
		$this->assertNull( $this->stored_bytes() );
		$this->assertTrue( $this->config()->save_settings_and_rules( array( 'max_params' => 7 ), array( '/wp/v2/posts' => array( 'vary_headers' => array( 'c', 'd', 'e' ) ) ) )->is_valid() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_save_settings_and_rules_checks_new_global_vary_headers_against_the_routes_constant(): void {
		define( 'RFRAC_ROUTES', array( '/const' => array( 'vary_headers' => array( 'a', 'b', 'c', 'd' ) ) ) );

		$result = $this->config()->save_settings_and_rules( array( 'global_vary_headers' => 'x y', 'max_params' => 7 ), array() );

		$this->assertSame( array( 'too_many_vary_headers' ), $result->codes() );
		$this->assertSame( 'global_vary_headers', $result->errors()[0]['field'] );
		$this->assertStringContainsString( '"/const"', $result->errors()[0]['message'] );
		$this->assertNull( $this->stored_bytes() );
		$this->assertTrue( $this->config()->save_settings_and_rules( array( 'global_vary_headers' => 'a x' ), array() )->is_valid() );
	}

	public function test_settings_routes_are_arrays_keyed_by_route(): void {
		$this->config()->save_route_rule( '/a', array( 'status' => 'enabled' ) );
		$routes = $this->config()->settings()['routes'];

		$this->assertSame( Route_Rule::from_array( array( 'status' => 'enabled' ) )->to_array(), $routes['/a'] );
	}

	public function test_sanitize_settings_is_idempotent(): void {
		$config = $this->config();
		$config->save_route_rule( '/a', array( 'status' => 'enabled' ) );
		$inputs = array(
			array( 'max_params' => 500, 'store' => 'nope', 'default_ttl' => '120', 'enabled' => '0', 'extra' => 1 ),
			array( 'max_ttl' => 100, 'default_ttl' => 99999 ),
			array(),
			'garbage',
		);

		foreach ( $inputs as $input ) {
			$once = $config->sanitize_settings( $input );
			$this->assertSame( $once, $config->sanitize_settings( $once ) );
			$this->assertArrayHasKey( '/a', $once['routes'] );
		}
		$this->assertSame( 100, $config->sanitize_settings( $inputs[0] )['max_params'] );
		$this->assertSame( 'auto', $config->sanitize_settings( $inputs[0] )['store'] );
		$this->assertFalse( $config->sanitize_settings( $inputs[0] )['enabled'] );
	}

	public function test_sanitize_settings_works_as_settings_api_callback(): void {
		register_setting( 'rfrac_test_group', Names::OPTION_SETTINGS, array( 'sanitize_callback' => array( $this->config(), 'sanitize_settings' ) ) );
		update_option( Names::OPTION_SETTINGS, array( 'max_params' => 500 ), false );

		$this->assertSame( 100, get_option( Names::OPTION_SETTINGS )['max_params'] );
		unregister_setting( 'rfrac_test_group', Names::OPTION_SETTINGS );
	}

	public function test_route_rule_saves_survive_settings_api_callback(): void {
		register_setting( 'rfrac_test_group', Names::OPTION_SETTINGS, array( 'sanitize_callback' => array( $this->config(), 'sanitize_settings' ) ) );
		try {
			$saver = $this->config();

			$this->assertTrue( $saver->save_route_rule( '/wp/v2/posts', array( 'status' => 'enabled' ) )->is_valid() );
			$this->assertArrayHasKey( '/wp/v2/posts', $this->config()->stored_route_rules() );
			$this->assertArrayHasKey( '/wp/v2/posts', get_option( Names::OPTION_SETTINGS )['routes'] );

			$saver->save_route_rules( array( '/a' => array( 'status' => 'enabled' ), '/b' => array( 'status' => 'enabled' ) ) );
			$this->assertEqualsCanonicalizing( array( '/wp/v2/posts', '/a', '/b' ), array_keys( $this->config()->stored_route_rules() ) );

			$saver->delete_route_rule( '/a' );
			$saver->delete_route_rule( '/wp/v2/posts' );
			$this->assertSame( array( '/b' ), array_keys( $this->config()->stored_route_rules() ) );
			$this->assertSame( array( '/b' ), array_keys( get_option( Names::OPTION_SETTINGS )['routes'] ) );

			$saver->save_settings( array( 'max_params' => 7 ) );
			$this->assertSame( 7, $this->config()->settings()['max_params'] );
			$this->assertSame( array( '/b' ), array_keys( $this->config()->stored_route_rules() ) );

			// Form-style write outside persist() still ignores submitted routes.
			update_option(
				Names::OPTION_SETTINGS,
				array(
					'max_params' => 9,
					'routes'     => array( '/forged' => array( 'status' => 'enabled' ) ),
				),
				false
			);
			$this->assertSame( 9, $this->config()->settings()['max_params'] );
			$this->assertSame( array( '/b' ), array_keys( $this->config()->stored_route_rules() ) );
		} finally {
			unregister_setting( 'rfrac_test_group', Names::OPTION_SETTINGS );
		}
	}

	public function test_stored_garbage_is_ignored(): void {
		update_option(
			Names::OPTION_SETTINGS,
			array(
				'max_params' => 'abc',
				'routes'     => array(
					'/ok'  => array( 'status' => 'enabled' ),
					'/bad' => array( 'vary_headers' => array( 'cookie' ) ),
					'nope' => array(),
					'/str' => 'x',
				),
			),
			false
		);

		$this->assertSame( 20, $this->config()->settings()['max_params'] );
		$this->assertSame( array( '/ok' ), array_keys( $this->config()->stored_route_rules() ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_overrides_default_ttl(): void {
		define( 'RFRAC_DEFAULT_TTL', 300 );
		$this->config()->save_settings( array( 'default_ttl' => 900 ) );

		$this->assertSame( 300, $this->config()->settings()['default_ttl'] );
		$this->assertTrue( $this->config()->is_locked( 'default_ttl' ) );
		$this->assertFalse( $this->config()->is_locked( 'max_ttl' ) );
		$this->assertSame( array( 'default_ttl' => 'RFRAC_DEFAULT_TTL' ), $this->config()->locked() );
		$this->assertSame( 86400, $this->config()->raw_settings()['default_ttl'], 'Locked keys are never persisted.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_invalid_constant_is_ignored_and_reported(): void {
		define( 'RFRAC_DEFAULT_TTL', 'abc' );

		$this->assertSame( 86400, $this->config()->settings()['default_ttl'] );
		$this->assertFalse( $this->config()->is_locked( 'default_ttl' ) );
		$this->assertArrayHasKey( 'RFRAC_DEFAULT_TTL', $this->config()->constant_errors() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_all_setting_constants(): void {
		define( 'RFRAC_DISABLE', true );
		define( 'RFRAC_STORE', 'object_cache' );
		define( 'RFRAC_MAX_TTL', 7200 );
		define( 'RFRAC_DEFAULT_TTL', 99999 );
		define( 'RFRAC_MAX_ENTRIES', 250 );
		define( 'RFRAC_MAX_PAYLOAD_KB', 64 );
		define( 'RFRAC_MAX_PARAMS', 3 );
		define( 'RFRAC_HIT_SAMPLING', 0 );

		$settings = $this->config()->settings();
		$this->assertFalse( $settings['enabled'] );
		$this->assertSame( 'object_cache', $settings['store'] );
		$this->assertSame( 7200, $settings['max_ttl'] );
		$this->assertSame( 7200, $settings['default_ttl'], 'default_ttl is clamped to max_ttl.' );
		$this->assertSame( 250, $settings['max_entries'] );
		$this->assertSame( 64, $settings['max_payload_kb'] );
		$this->assertSame( 3, $settings['max_params'] );
		$this->assertSame( 0, $settings['hit_sampling'] );
		$this->assertEquals(
			array(
				'enabled'        => 'RFRAC_DISABLE',
				'store'          => 'RFRAC_STORE',
				'default_ttl'    => 'RFRAC_DEFAULT_TTL',
				'max_ttl'        => 'RFRAC_MAX_TTL',
				'max_entries'    => 'RFRAC_MAX_ENTRIES',
				'max_payload_kb' => 'RFRAC_MAX_PAYLOAD_KB',
				'max_params'     => 'RFRAC_MAX_PARAMS',
				'hit_sampling'   => 'RFRAC_HIT_SAMPLING',
			),
			array_intersect_key( $this->config()->locked(), Settings_Schema::defaults() )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rfrac_disable_false_does_not_lock(): void {
		define( 'RFRAC_DISABLE', false );

		$this->assertTrue( $this->config()->settings()['enabled'] );
		$this->assertFalse( $this->config()->is_locked( 'enabled' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_invalid_store_constant(): void {
		define( 'RFRAC_STORE', 'memcached' );

		$this->assertSame( 'auto', $this->config()->settings()['store'] );
		$this->assertArrayHasKey( 'RFRAC_STORE', $this->config()->constant_errors() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_routes_constant_merges_over_stored_and_is_locked(): void {
		$this->config()->save_route_rule( '/wp/v2/posts', array( 'status' => 'enabled', 'ttl' => 100 ) );
		$this->config()->save_route_rule( '/wp/v2/pages', array( 'status' => 'enabled' ) );

		define(
			'RFRAC_ROUTES',
			array(
				'/wp/v2/posts' => array( 'status' => 'excluded' ),
				'/wp/v2/*'     => array( 'match' => 'wildcard', 'ttl' => 5 ),
				'/bad'         => array( 'vary_headers' => array( 'cookie' ) ),
				'/notarray'    => 'x',
			)
		);

		$routes = $this->config()->settings()['routes'];
		$this->assertSame( 'excluded', $routes['/wp/v2/posts']['status'] );
		$this->assertSame( 'enabled', $routes['/wp/v2/pages']['status'] );
		$this->assertSame( 60, $routes['/wp/v2/*']['ttl'] );
		$this->assertArrayNotHasKey( '/bad', $routes );
		$this->assertArrayNotHasKey( '/notarray', $routes );

		$this->assertTrue( $this->config()->is_route_locked( '/wp/v2/posts' ) );
		$this->assertFalse( $this->config()->is_route_locked( '/wp/v2/pages' ) );
		$this->assertSame( 'RFRAC_ROUTES', $this->config()->locked()['routes'] );
		$this->assertArrayHasKey( 'RFRAC_ROUTES[/bad]', $this->config()->constant_errors() );
		$this->assertArrayHasKey( 'RFRAC_ROUTES[/notarray]', $this->config()->constant_errors() );

		$this->assertTrue( $this->config()->save_route_rule( '/wp/v2/posts', array( 'status' => 'enabled' ) )->has_code( 'locked_route' ) );
		$this->assertTrue( $this->config()->delete_route_rule( '/wp/v2/posts' )->has_code( 'locked_route' ) );
		$this->assertSame( 'enabled', $this->config()->stored_route_rules()['/wp/v2/posts']->status );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_routes_constant_not_an_array(): void {
		define( 'RFRAC_ROUTES', 'nope' );

		$this->assertArrayHasKey( 'RFRAC_ROUTES', $this->config()->constant_errors() );
		$this->assertSame( array(), $this->config()->settings()['routes'] );
		$this->assertFalse( $this->config()->is_locked( 'routes' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_compile_only_constants(): void {
		define( 'RFRAC_ALLOW_SKIP_CACHE', true );
		define( 'RFRAC_BYPASS_COOKIES', array( 'woocommerce_', 'wp_' ) );
		define( 'RFRAC_BYPASS_HEADERS', array( ' X-Shop-Token ', 'x-shop-token' ) );
		define( 'RFRAC_CORS_WILDCARD', true );
		define( 'RFRAC_DISABLE_MU_INSTALL', true );

		$this->assertSame(
			array(
				'allow_skip_cache' => true,
				'bypass_cookies'   => array( 'woocommerce_', 'wp_' ),
				'bypass_headers'   => array( 'x-shop-token' ),
				'cors_wildcard'    => true,
			),
			$this->config()->compile_overrides()
		);
		$this->assertTrue( $this->config()->mu_install_disabled() );
		$this->assertSame( array(), $this->config()->locked() );
		$this->assertSame( array(), $this->config()->constant_errors() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_invalid_compile_only_constants_are_reported(): void {
		define( 'RFRAC_ALLOW_SKIP_CACHE', 'maybe' );
		define( 'RFRAC_BYPASS_COOKIES', 'not-an-array' );
		define( 'RFRAC_BYPASS_HEADERS', array( 'x-ok', '' ) );

		$this->assertSame( array( 'allow_skip_cache' => null, 'bypass_cookies' => array(), 'bypass_headers' => array( 'x-ok' ), 'cors_wildcard' => null ), $this->config()->compile_overrides() );
		$this->assertFalse( $this->config()->mu_install_disabled() );
		$this->assertSame( array( 'RFRAC_ALLOW_SKIP_CACHE', 'RFRAC_BYPASS_COOKIES', 'RFRAC_BYPASS_HEADERS' ), array_keys( $this->config()->constant_errors() ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bypass_constants_are_validated_like_the_filters(): void {
		define( 'RFRAC_BYPASS_COOKIES', array( 'my.app_', "shop'cart_" ) );
		define( 'RFRAC_BYPASS_HEADERS', array( 'X-Shop-Key' ) );

		$overrides = $this->config()->compile_overrides();
		$this->assertSame( array( 'my_app_', "shop'cart_" ), $overrides['bypass_cookies'] );
		$this->assertSame( array( 'x-shop-key' ), $overrides['bypass_headers'] );
		$this->assertSame( array(), $this->config()->constant_errors() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bypass_constants_keep_the_valid_entries_and_report_the_invalid_ones(): void {
		define( 'RFRAC_BYPASS_COOKIES', array( 'good_', 'bad;name', 'x%y_', str_repeat( 'a', 129 ), '   ', 7 ) );
		define( 'RFRAC_BYPASS_HEADERS', array( 'X-Api-Key', 'X_Partner_Token', 'bad header', 'X.Dot' ) );

		$overrides = $this->config()->compile_overrides();
		$this->assertSame( array( 'good_' ), $overrides['bypass_cookies'], 'An invalid entry never removes a valid bypass cookie.' );
		$this->assertSame( array( 'x-api-key', 'x-partner-token' ), $overrides['bypass_headers'], 'An invalid entry never removes a valid bypass header; `_` counts as `-`.' );

		$errors = $this->config()->constant_errors();
		$this->assertSame( array( 'RFRAC_BYPASS_COOKIES', 'RFRAC_BYPASS_HEADERS' ), array_keys( $errors ) );
		$this->assertStringContainsString( '"bad;name"', $errors['RFRAC_BYPASS_COOKIES'] );
		$this->assertStringContainsString( '"x%y_"', $errors['RFRAC_BYPASS_COOKIES'] );
		$this->assertStringNotContainsString( 'good_', $errors['RFRAC_BYPASS_COOKIES'] );
		$this->assertStringContainsString( '"bad header"', $errors['RFRAC_BYPASS_HEADERS'] );
		$this->assertStringContainsString( '"X.Dot"', $errors['RFRAC_BYPASS_HEADERS'] );
		$this->assertStringNotContainsString( 'X_Partner_Token', $errors['RFRAC_BYPASS_HEADERS'] );
		$this->assertStringNotContainsString( 'X-Api-Key', $errors['RFRAC_BYPASS_HEADERS'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_valid_bypass_constant_entries_reach_the_compiled_config_next_to_invalid_ones(): void {
		define( 'RFRAC_BYPASS_COOKIES', array( 'shop_cart_', 'bad;name' ) );
		define( 'RFRAC_BYPASS_HEADERS', array( 'X-Shop-Key', 'X Bad' ) );

		$compiled = ( new Rockfire\Rest_Api_Cache\Config\Config_Compiler() )->compile( rest_get_server() );

		$this->assertContains( 'shop_cart_', $compiled->bypass_cookie_prefixes() );
		$this->assertContains( 'wordpress_logged_in_', $compiled->bypass_cookie_prefixes() );
		$this->assertContains( 'x-shop-key', $compiled->bypass_headers() );
		$this->assertContains( 'x-api-key', $compiled->bypass_headers() );

		$last = Rockfire\Rest_Api_Cache\Failure_Recorder::last();
		$this->assertNotNull( $last, 'The invalid constant entries are reported as the last error.' );
		$this->assertSame( 'bypass', $last['context'] );
		$this->assertStringContainsString( 'RFRAC_BYPASS_COOKIES', $last['message'] );
		$this->assertStringContainsString( '(and 1 more)', $last['message'] );
	}

	/**
	 * The values of the `RFRAC_NONCE_CACHING` constant that read as true.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function true_constants(): array {
		return array(
			'true'   => array( true ),
			'yes'    => array( 'yes' ),
			'one'    => array( 1 ),
			'on'     => array( 'on' ),
		);
	}

	/**
	 * @dataProvider true_constants
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param mixed $value Constant value.
	 */
	public function test_a_nonce_caching_constant_turns_it_on_and_locks_the_key( $value ): void {
		define( 'RFRAC_NONCE_CACHING', $value );

		$this->assertTrue( $this->config()->settings()['nonce_caching'] );
		$this->assertSame( 'RFRAC_NONCE_CACHING', $this->config()->locked()['nonce_caching'] );
		$this->assertTrue( $this->config()->is_locked( 'nonce_caching' ) );
		$this->assertSame( array(), $this->config()->constant_errors() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_false_nonce_caching_constant_wins_over_a_stored_true(): void {
		update_option( Names::OPTION_SETTINGS, array( 'nonce_caching' => true ), false );
		define( 'RFRAC_NONCE_CACHING', 'no' );

		$this->assertFalse( $this->config()->settings()['nonce_caching'] );
		$this->assertTrue( $this->config()->raw_settings()['nonce_caching'] );
		$this->assertSame( 'RFRAC_NONCE_CACHING', $this->config()->locked()['nonce_caching'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_invalid_nonce_caching_constant_is_reported_and_does_not_lock(): void {
		update_option( Names::OPTION_SETTINGS, array( 'nonce_caching' => true ), false );
		define( 'RFRAC_NONCE_CACHING', 'maybe' );

		$this->assertSame( array( 'RFRAC_NONCE_CACHING' => 'Expected a boolean.' ), $this->config()->constant_errors() );
		$this->assertFalse( $this->config()->is_locked( 'nonce_caching' ) );
		$this->assertTrue( $this->config()->settings()['nonce_caching'], 'The stored setting applies.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_locked_nonce_caching_is_never_written(): void {
		define( 'RFRAC_NONCE_CACHING', true );

		$this->assertTrue( $this->config()->save_settings( array( 'nonce_caching' => '0', 'max_params' => 5 ) )->is_valid() );
		$this->assertTrue( $this->config()->save_route_rule( '/a', array( 'status' => 'enabled' ) )->is_valid() );
		$this->assertArrayNotHasKey( 'nonce_caching', get_option( Names::OPTION_SETTINGS ) );

		$result = $this->config()->save_settings_and_rules( array( 'nonce_caching' => false ), array() );
		$this->assertTrue( $result->has_code( 'locked_setting' ) );
		$this->assertArrayNotHasKey( 'nonce_caching', get_option( Names::OPTION_SETTINGS ) );

		$clean = $this->config()->sanitize_settings( array( 'nonce_caching' => '0', 'max_params' => 7 ) );
		$this->assertArrayNotHasKey( 'nonce_caching', $clean );
		$this->assertSame( 7, $clean['max_params'] );

		$this->assertTrue( $this->config()->settings()['nonce_caching'] );
	}

	public function test_nothing_is_stored_for_nonce_caching_until_a_save_carries_it(): void {
		update_option( Names::OPTION_SETTINGS, array( 'max_ttl' => 7200 ), false );

		$this->assertTrue( $this->config()->save_settings( array( 'max_params' => 5 ) )->is_valid() );
		$this->assertArrayNotHasKey( 'nonce_caching', get_option( Names::OPTION_SETTINGS ) );

		$this->assertTrue( $this->config()->save_route_rule( '/a', array( 'status' => 'enabled' ) )->is_valid() );
		$this->assertArrayNotHasKey( 'nonce_caching', get_option( Names::OPTION_SETTINGS ) );

		$this->assertTrue( $this->config()->save_route_rules( array( '/b' => array( 'status' => 'enabled' ), '/a' => null ) )->is_valid() );
		$this->assertArrayNotHasKey( 'nonce_caching', get_option( Names::OPTION_SETTINGS ) );

		$this->assertTrue( $this->config()->save_settings_and_rules( array( 'max_params' => 9 ), array( '/c' => array( 'status' => 'enabled' ) ) )->is_valid() );
		$this->assertArrayNotHasKey( 'nonce_caching', get_option( Names::OPTION_SETTINGS ) );
		$this->assertSame( 9, get_option( Names::OPTION_SETTINGS )['max_params'] );
		$this->assertFalse( $this->config()->is_stored( 'nonce_caching' ) );
		$this->assertFalse( $this->config()->settings()['nonce_caching'] );
	}

	public function test_an_explicit_nonce_caching_choice_is_stored_even_when_it_is_the_default(): void {
		$this->assertTrue( $this->config()->save_settings( array( 'nonce_caching' => '0' ) )->is_valid() );

		$this->assertFalse( get_option( Names::OPTION_SETTINGS )['nonce_caching'] );
		$this->assertTrue( $this->config()->is_stored( 'nonce_caching' ) );

		// Later saves that omit the key keep it, whichever path they take.
		$this->config()->save_settings( array( 'max_params' => 5 ) );
		$this->config()->save_route_rule( '/a', array( 'status' => 'enabled' ) );
		$this->config()->save_settings_and_rules( array( 'max_params' => 6 ), array() );
		$this->assertFalse( get_option( Names::OPTION_SETTINGS )['nonce_caching'] );

		$this->config()->save_settings( array( 'nonce_caching' => 'yes' ) );
		$this->assertTrue( get_option( Names::OPTION_SETTINGS )['nonce_caching'] );
		$this->config()->save_settings( array( 'max_params' => 7 ) );
		$this->assertTrue( get_option( Names::OPTION_SETTINGS )['nonce_caching'] );
		$this->assertTrue( $this->config()->settings()['nonce_caching'] );
	}

	public function test_an_explicit_nonce_caching_choice_is_stored_by_save_settings_and_rules(): void {
		$this->assertTrue( $this->config()->save_settings_and_rules( array( 'nonce_caching' => false ), array() )->is_valid() );
		$this->assertFalse( get_option( Names::OPTION_SETTINGS )['nonce_caching'] );

		$this->assertTrue( $this->config()->save_settings_and_rules( array( 'nonce_caching' => true ), array( '/a' => array( 'status' => 'enabled' ) ) )->is_valid() );
		$this->assertTrue( get_option( Names::OPTION_SETTINGS )['nonce_caching'] );
	}

	public function test_an_invalid_nonce_caching_value_is_not_a_decision(): void {
		$result = $this->config()->save_settings( array( 'nonce_caching' => 'garbage', 'max_params' => 5 ) );
		$this->assertTrue( $result->has_code( 'invalid_value' ) );
		$this->assertArrayNotHasKey( 'nonce_caching', get_option( Names::OPTION_SETTINGS ) );
		$this->assertSame( 5, get_option( Names::OPTION_SETTINGS )['max_params'] );

		$this->config()->save_settings( array( 'nonce_caching' => true ) );
		$this->config()->save_settings( array( 'nonce_caching' => 'garbage' ) );
		$this->assertTrue( get_option( Names::OPTION_SETTINGS )['nonce_caching'], 'The stored choice stays.' );
	}

	public function test_the_settings_api_callback_stores_nonce_caching_only_on_a_decision(): void {
		register_setting( 'rfrac_test_group', Names::OPTION_SETTINGS, array( 'sanitize_callback' => array( $this->config(), 'sanitize_settings' ) ) );
		try {
			update_option( Names::OPTION_SETTINGS, array( 'max_params' => 9 ), false );
			$this->assertArrayNotHasKey( 'nonce_caching', get_option( Names::OPTION_SETTINGS ) );

			update_option( Names::OPTION_SETTINGS, array( 'max_params' => 9, 'nonce_caching' => '0' ), false );
			$this->assertFalse( get_option( Names::OPTION_SETTINGS )['nonce_caching'] );

			$this->config()->save_route_rule( '/a', array( 'status' => 'enabled' ) );
			update_option( Names::OPTION_SETTINGS, array( 'max_params' => 11 ), false );
			$this->assertFalse( get_option( Names::OPTION_SETTINGS )['nonce_caching'], 'A later save that omits the key keeps it.' );

			$once = $this->config()->sanitize_settings( array( 'nonce_caching' => '1' ) );
			$this->assertSame( $once, $this->config()->sanitize_settings( $once ), 'Idempotent.' );
			$this->assertTrue( $once['nonce_caching'] );
		} finally {
			unregister_setting( 'rfrac_test_group', Names::OPTION_SETTINGS );
		}
	}

	public function test_a_stored_nonce_caching_that_is_not_a_boolean_counts_as_nothing_stored(): void {
		update_option( Names::OPTION_SETTINGS, array( 'nonce_caching' => 'maybe' ), false );

		$this->assertFalse( $this->config()->is_stored( 'nonce_caching' ) );
		$this->assertFalse( $this->config()->settings()['nonce_caching'] );
	}

	public function test_a_rule_reads_its_methods_uppercased_deduplicated_and_in_canonical_order(): void {
		$rule = Route_Rule::from_array( array( 'methods' => array( 'post', 'PATCH', 'POST' ) ) );
		$this->assertSame( array( 'POST', 'PATCH' ), $rule->methods );
		$this->assertTrue( $rule->validate()->is_valid() );

		$this->assertSame( array( 'GET', 'POST', 'PUT' ), Route_Rule::from_array( array( 'methods' => array( ' put ', 'Post', 'get' ) ) )->methods );
	}

	public function test_a_rule_without_methods_reads_get(): void {
		$this->assertSame( array( 'GET' ), Route_Rule::from_array( array() )->methods );
		$this->assertSame( array( 'GET' ), Route_Rule::from_array( array( 'methods' => array() ) )->methods );
		$this->assertSame( array( 'GET' ), Route_Rule::from_array( array( 'methods' => null ) )->methods );
		$this->assertSame( array( 'GET' ), Route_Rule::defaults()->methods );
		$this->assertSame( Route_Rule::DEFAULT_METHODS, ( new Route_Rule( Route_Rule::STATUS_ENABLED ) )->methods );
	}

	/**
	 * @dataProvider provide_invalid_methods
	 *
	 * @param array<string,mixed> $data Rule data.
	 */
	public function test_a_rule_with_unsupported_methods_fails_validation( array $data ): void {
		$result = Route_Rule::from_array( $data )->validate();

		$this->assertFalse( $result->is_valid() );
		$this->assertTrue( $result->has_code( 'invalid_method' ) );
		$this->assertSame( 'methods', $result->errors()[0]['field'] );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function provide_invalid_methods(): array {
		return array(
			'delete'                 => array( array( 'methods' => array( 'DELETE' ) ) ),
			'head'                   => array( array( 'methods' => array( 'HEAD' ) ) ),
			'a supported and another' => array( array( 'methods' => array( 'POST', 'OPTIONS' ) ) ),
			'a string'               => array( array( 'methods' => 'POST' ) ),
			'a map'                  => array( array( 'methods' => array( 'a' => 'POST' ) ) ),
			'a non-string entry'     => array( array( 'methods' => array( 'POST', 5 ) ) ),
			'a wildcard with post'   => array( array( 'match' => 'wildcard', 'methods' => array( 'GET', 'POST' ) ) ),
			'a wildcard with put'    => array( array( 'match' => 'wildcard', 'methods' => array( 'PUT' ) ) ),
		);
	}

	public function test_a_wildcard_rule_can_cache_get(): void {
		$this->assertTrue( Route_Rule::from_array( array( 'match' => 'wildcard', 'methods' => array( 'GET' ) ) )->validate()->is_valid() );
		$this->assertTrue( Route_Rule::from_array( array( 'match' => 'wildcard' ) )->validate()->is_valid() );
	}

	public function test_a_get_only_rule_has_no_methods_key_and_other_rules_round_trip(): void {
		$this->assertArrayNotHasKey( 'methods', Route_Rule::from_array( array( 'status' => 'enabled' ) )->to_array() );
		$this->assertArrayNotHasKey( 'methods', Route_Rule::from_array( array( 'methods' => array( 'get' ) ) )->to_array() );
		$this->assertSame( Route_Rule::defaults()->to_array(), Route_Rule::from_array( array() )->to_array() );

		$rule = Route_Rule::from_array( array( 'status' => 'enabled', 'methods' => array( 'GET', 'POST' ) ) );
		$this->assertSame( array( 'GET', 'POST' ), $rule->to_array()['methods'] );
		$this->assertEquals( $rule, Route_Rule::from_array( $rule->to_array() ) );
	}

	public function test_with_ttl_and_with_methods_carry_the_methods(): void {
		$rule = Route_Rule::from_array( array( 'status' => 'enabled', 'methods' => array( 'POST' ) ) );
		$this->assertSame( array( 'POST' ), $rule->with_ttl( 120 )->methods );
		$this->assertSame( 120, $rule->with_ttl( 120 )->ttl );

		$changed = $rule->with_methods( array( 'patch', 'get', 'PATCH' ) );
		$this->assertSame( array( 'GET', 'PATCH' ), $changed->methods );
		$this->assertSame( array( 'POST' ), $rule->methods, 'The rule is immutable.' );
		$this->assertSame( 'enabled', $changed->status );
		$this->assertSame( array( 'GET' ), $rule->with_methods( array() )->methods );
		$this->assertTrue( $rule->with_methods( array( 'DELETE' ) )->validate()->has_code( 'invalid_method' ) );
	}

	public function test_a_rule_with_methods_is_saved_and_layered_with_its_methods(): void {
		$this->assertTrue( $this->config()->save_route_rule( '/rfrac-test/v1/things', array( 'status' => 'enabled', 'methods' => array( 'post', 'GET' ) ) )->is_valid() );

		$this->assertSame( array( 'GET', 'POST' ), $this->config()->stored_route_rules()['/rfrac-test/v1/things']->methods );
		$this->assertSame( array( 'GET', 'POST' ), $this->config()->settings()['routes']['/rfrac-test/v1/things']['methods'] );

		$invalid = $this->config()->save_route_rule( '/rfrac-test/v1/other', array( 'methods' => array( 'DELETE' ) ) );
		$this->assertTrue( $invalid->has_code( 'invalid_method' ) );
		$this->assertArrayNotHasKey( '/rfrac-test/v1/other', $this->config()->stored_route_rules() );
	}

	public function test_instance_is_shared(): void {
		$this->assertSame( Config::instance(), Config::instance() );
	}
}
