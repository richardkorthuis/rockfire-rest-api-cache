<?php
/**
 * Tests for the Site Health test and the debug section of the legacy layer.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Legacy\Constant_Fallback;
use Rockfire\Rest_Api_Cache\Legacy\Detector;
use Rockfire\Rest_Api_Cache\Legacy\Finding;
use Rockfire\Rest_Api_Cache\Legacy\Findings;
use Rockfire\Rest_Api_Cache\Legacy\Health;
use Rockfire\Rest_Api_Cache\Legacy\Importer;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Health
 * @covers \Rockfire\Rest_Api_Cache\Plugin
 */
class Test_Legacy_Health extends Rfrac_Test_Case {

	const OWN      = 'rockfire-rest-api-cache/rockfire-rest-api-cache.php';
	const ORIGINAL = 'wp-rest-cache/wp-rest-cache.php';

	public function set_up() {
		parent::set_up();
		$this->clean();
		update_option( 'active_plugins', array( self::OWN ) );
		Coexistence_Guard::reset();
		Config::set_fallback_constants( new Constant_Fallback() );
	}

	public function tear_down() {
		$this->clean();
		Coexistence_Guard::reset();
		Config::set_fallback_constants( new Constant_Fallback() );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Remove everything the tests store.
	 */
	private function clean(): void {
		delete_option( 'wp_rest_cache_database_version' );
		delete_option( 'wp_rest_cache_timeout' );
		delete_option( 'wp_rest_cache_timeout_interval' );
		delete_option( 'wp_rest_cache_global_cacheable_request_headers' );
		delete_option( Names::OPTION_LEGACY_FINDINGS );
	}

	/**
	 * The health integration over the real stores.
	 *
	 * @return Health
	 */
	private function health(): Health {
		return new Health( new Findings(), new Constant_Fallback(), new Detector() );
	}

	/**
	 * A finding.
	 *
	 * @param string $status  Status.
	 * @param string $subject Subject.
	 * @param string $message Message.
	 * @param string $code    Code.
	 *
	 * @return Finding
	 */
	private function finding( string $status, string $subject = 'a_subject', string $message = 'A message.', string $code = 'a_code' ): Finding {
		return new Finding( $status, $code, $subject, $message );
	}

	/**
	 * The description of the test.
	 *
	 * @return string
	 */
	private function description(): string {
		return $this->health()->test_legacy()['description'];
	}

	public function test_a_site_without_a_trace_shows_no_test(): void {
		$tests = $this->health()->register_tests( array() );

		$this->assertArrayNotHasKey( Health::TEST, $tests['direct'] );
		$this->assertSame( 'rfrac_legacy', Health::TEST );
	}

	public function test_other_tests_are_kept_and_a_site_without_a_trace_adds_nothing(): void {
		$tests = $this->health()->register_tests( array( 'direct' => array( 'other' => array( 'label' => 'Other' ) ) ) );

		$this->assertSame( array( 'other' ), array_keys( $tests['direct'] ) );
		$this->assertSame( array( 'direct' ), array_keys( $this->health()->register_tests( 'nope' ) ) );
	}

	public function test_leftovers_register_the_test(): void {
		update_option( 'wp_rest_cache_database_version', '2026.1.0' );

		$tests = $this->health()->register_tests( array( 'direct' => array( 'other' => array() ) ) );

		$this->assertArrayHasKey( 'other', $tests['direct'] );
		$this->assertArrayHasKey( 'rfrac_legacy', $tests['direct'] );
		$this->assertSame( 'rfrac_legacy', $tests['direct']['rfrac_legacy']['test'][0]->test_legacy()['test'] );
		$this->assertIsString( $tests['direct']['rfrac_legacy']['label'] );
		$this->assertIsCallable( $tests['direct']['rfrac_legacy']['test'] );
	}

	public function test_an_active_original_registers_the_test(): void {
		update_option( 'active_plugins', array( self::ORIGINAL, self::OWN ) );
		Coexistence_Guard::reset();

		$this->assertArrayHasKey( 'rfrac_legacy', $this->health()->register_tests( array() )['direct'] );
	}

	public function test_any_stored_group_registers_the_test(): void {
		$this->assertTrue( ( new Findings() )->replace( Findings::GROUP_NOTICE, array() ) );

		$this->assertFalse( Detector::present() );
		$this->assertArrayHasKey( 'rfrac_legacy', $this->health()->register_tests( array() )['direct'] );
	}

	public function test_a_result_has_the_shape_of_the_site_health_results_and_links_to_the_import(): void {
		$result = $this->health()->test_legacy();

		foreach ( array( 'label', 'status', 'badge', 'description', 'actions', 'test' ) as $key ) {
			$this->assertArrayHasKey( $key, $result );
		}
		$this->assertSame(
			array(
				'label' => 'Performance',
				'color' => 'blue',
			),
			$result['badge']
		);
		$this->assertSame( 'rfrac_legacy', $result['test'] );
		$this->assertStringContainsString( esc_url( Admin_Url::page( 'rfrac-import' ) ), $result['actions'] );
		$this->assertStringContainsString( 'page=rfrac-import', $result['actions'] );
	}

	public function test_without_constants_and_problems_the_test_is_good(): void {
		$result = $this->health()->test_legacy();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringNotContainsString( '<ul>', $result['description'] );
	}

	public function test_leftovers_with_only_imported_and_kept_findings_are_good(): void {
		update_option( 'wp_rest_cache_database_version', '2026.1.0' );
		$findings = new Findings();
		$findings->replace(
			Findings::GROUP_IMPORT,
			array(
				$this->finding( Finding::IMPORTED, 'imported_subject' ),
				$this->finding( Finding::KEPT, 'kept_subject', 'Kept.', 'kept_code' ),
			)
		);

		$this->assertSame( 'good', $this->health()->test_legacy()['status'] );
	}

	/**
	 * The standing note that every import stores, shaped the way the importer reports it.
	 *
	 * @return Finding
	 */
	private function stricter_requests(): Finding {
		return new Finding( Finding::ADJUSTED, Importer::CODE_STRICTER_REQUESTS, Importer::PLUGIN_SUBJECT, 'Some requests bypass the cache now.' );
	}

	public function test_an_import_whose_only_problem_is_the_standing_stricter_requests_note_is_good(): void {
		update_option( 'wp_rest_cache_database_version', '2026.1.0' );
		( new Findings() )->replace(
			Findings::GROUP_IMPORT,
			array(
				$this->finding( Finding::IMPORTED, 'imported_subject' ),
				$this->stricter_requests(),
			),
			array(
				'imported' => 1,
				'adjusted' => 1,
				'result'   => 'compiled',
			)
		);

		$result = $this->health()->test_legacy();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringNotContainsString( '<ul>', $result['description'] );
		$this->assertStringNotContainsString( Importer::PLUGIN_SUBJECT, $result['description'] );
		$this->assertStringContainsString( 'Import: 2 (0 need attention)', $this->health()->debug_information( array() )[ Health::DEBUG_SECTION ]['fields']['findings']['value'] );
	}

	public function test_the_standing_stricter_requests_note_is_not_listed_next_to_another_problem(): void {
		( new Findings() )->replace(
			Findings::GROUP_IMPORT,
			array(
				$this->stricter_requests(),
				$this->finding( Finding::SKIPPED, 'skipped_subject', 'Skipped.', 'skipped_code' ),
			)
		);

		$result = $this->health()->test_legacy();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'skipped_subject', $result['description'] );
		$this->assertStringNotContainsString( Importer::PLUGIN_SUBJECT, $result['description'] );
		$this->assertSame( 1, substr_count( $result['description'], '<li>' ) );
		$this->assertStringContainsString( 'Import: 2 (1 need attention)', $this->health()->debug_information( array() )[ Health::DEBUG_SECTION ]['fields']['findings']['value'] );
	}

	public function test_a_problem_finding_is_listed_and_the_others_are_not(): void {
		$findings = new Findings();
		$findings->replace( Findings::GROUP_HOOKS, array( $this->finding( Finding::UNSUPPORTED, 'a_hook_name', 'Has no counterpart.', 'not_bridged' ) ) );
		$findings->replace(
			Findings::GROUP_IMPORT,
			array(
				$this->finding( Finding::IMPORTED, 'imported_subject' ),
				$this->finding( Finding::KEPT, 'kept_subject', 'Kept.', 'kept_code' ),
			)
		);

		$result = $this->health()->test_legacy();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'a_hook_name', $result['description'] );
		$this->assertStringContainsString( 'Has no counterpart.', $result['description'] );
		$this->assertStringContainsString( 'unsupported', $result['description'] );
		$this->assertStringNotContainsString( 'imported_subject', $result['description'] );
		$this->assertStringNotContainsString( 'kept_subject', $result['description'] );
		$this->assertSame( 1, substr_count( $result['description'], '<li>' ) );
	}

	public function test_adjusted_and_skipped_findings_are_problems_too(): void {
		( new Findings() )->replace(
			Findings::GROUP_IMPORT,
			array(
				$this->finding( Finding::ADJUSTED, 'adjusted_subject', 'Adjusted.', 'adjusted_code' ),
				$this->finding( Finding::SKIPPED, 'skipped_subject', 'Skipped.', 'skipped_code' ),
			)
		);

		$description = $this->description();

		$this->assertStringContainsString( 'adjusted_subject', $description );
		$this->assertStringContainsString( 'skipped_subject', $description );
		$this->assertSame( 2, substr_count( $description, '<li>' ) );
	}

	public function test_the_output_of_a_finding_is_escaped(): void {
		( new Findings() )->replace(
			Findings::GROUP_HOOKS,
			array( $this->finding( Finding::UNSUPPORTED, '<b>subject</b>', '<script>alert(1)</script>', 'a_code' ) )
		);

		$description = $this->description();

		$this->assertStringNotContainsString( '<script>', $description );
		$this->assertStringNotContainsString( '<b>', $description );
		$this->assertStringContainsString( '&lt;script&gt;', $description );
		$this->assertStringContainsString( '&lt;b&gt;subject&lt;/b&gt;', $description );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_timeout_constant_is_named_with_its_replacement(): void {
		define( 'WP_REST_CACHE_TIMEOUT', 2 );

		$result = $this->health()->test_legacy();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'WP_REST_CACHE_TIMEOUT', $result['description'] );
		$this->assertStringContainsString( 'RFRAC_DEFAULT_TTL', $result['description'] );
		$this->assertStringContainsString( 'raise Max TTL or <code>RFRAC_MAX_TTL</code> when it is clamped', $result['description'] );
		$this->assertStringNotContainsString( 'is ignored', $result['description'] );
		$this->assertArrayHasKey( 'rfrac_legacy', $this->health()->register_tests( array() )['direct'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_constant_that_the_plugin_overrides_is_ignored(): void {
		define( 'RFRAC_DEFAULT_TTL', 600 );
		define( 'WP_REST_CACHE_TIMEOUT', 2 );

		$result = $this->health()->test_legacy();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'WP_REST_CACHE_TIMEOUT', $result['description'] );
		$this->assertStringContainsString( 'RFRAC_DEFAULT_TTL', $result['description'] );
		$this->assertStringContainsString( 'is ignored because', $result['description'] );
		$this->assertStringNotContainsString( 'still works', $result['description'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_constant_with_an_invalid_value_says_so(): void {
		define( 'WP_REST_CACHE_TIMEOUT', 'abc' );

		$description = $this->description();

		$this->assertStringContainsString( 'WP_REST_CACHE_TIMEOUT', $description );
		$this->assertStringContainsString( 'has an invalid value', $description );
		$this->assertStringContainsString( 'RFRAC_DEFAULT_TTL', $description );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_headers_constant_points_to_the_vary_headers_constant(): void {
		define( 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS', 'X-Lang' );

		$description = $this->description();

		$this->assertStringContainsString( 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS', $description );
		$this->assertStringContainsString( 'RFRAC_GLOBAL_VARY_HEADERS', $description );
		$this->assertStringNotContainsString( 'RFRAC_MAX_TTL', $description );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_constant_without_a_counterpart_has_no_effect(): void {
		define( 'WP_REST_CACHE_MEMCACHE_USED', true );

		$result = $this->health()->test_legacy();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'WP_REST_CACHE_MEMCACHE_USED', $result['description'] );
		$this->assertStringContainsString( 'has no effect', $result['description'] );
		$this->assertStringNotContainsString( 'RFRAC_', $result['description'] );
	}

	public function test_the_debug_section_has_the_four_rows(): void {
		update_option( 'wp_rest_cache_database_version', '2026.1.0' );

		$info = $this->health()->debug_information( array( 'wp-core' => array( 'label' => 'WordPress' ) ) );

		$this->assertSame( 'rockfire-rest-api-cache-legacy', Health::DEBUG_SECTION );
		$this->assertArrayHasKey( 'wp-core', $info );
		$this->assertArrayHasKey( 'rockfire-rest-api-cache-legacy', $info );
		$section = $info['rockfire-rest-api-cache-legacy'];
		$this->assertIsString( $section['label'] );
		$this->assertSame( array( 'original', 'last_import', 'constants', 'findings' ), array_keys( $section['fields'] ) );
		foreach ( $section['fields'] as $field ) {
			$this->assertIsString( $field['label'] );
			$this->assertIsString( $field['value'] );
		}
		$this->assertSame( 'Inactive, with leftovers', $section['fields']['original']['value'] );
		$this->assertSame( 'Never', $section['fields']['last_import']['value'] );
		$this->assertSame( 'None', $section['fields']['constants']['value'] );
		$this->assertSame( 'None', $section['fields']['findings']['value'] );
	}

	public function test_the_debug_section_adds_to_whatever_it_is_given(): void {
		$this->assertSame( array( Health::DEBUG_SECTION ), array_keys( $this->health()->debug_information( null ) ) );
		$this->assertSame( array( Health::DEBUG_SECTION ), array_keys( $this->health()->debug_information( 'nope' ) ) );
	}

	public function test_the_original_row_tells_active_leftovers_and_not_found(): void {
		$this->assertSame( 'Not found', $this->health()->debug_information( array() )[ Health::DEBUG_SECTION ]['fields']['original']['value'] );

		update_option( 'wp_rest_cache_database_version', '2026.1.0' );
		$this->assertSame( 'Inactive, with leftovers', $this->health()->debug_information( array() )[ Health::DEBUG_SECTION ]['fields']['original']['value'] );

		update_option( 'active_plugins', array( self::ORIGINAL, self::OWN ) );
		Coexistence_Guard::reset();
		$this->assertSame( 'Active', $this->health()->debug_information( array() )[ Health::DEBUG_SECTION ]['fields']['original']['value'] );
	}

	public function test_the_import_row_shows_the_time_and_the_counts_of_the_import(): void {
		( new Findings() )->replace(
			Findings::GROUP_IMPORT,
			array( $this->finding( Finding::IMPORTED ) ),
			array(
				'imported'    => 4,
				'kept'        => 1,
				'adjusted'    => 2,
				'skipped'     => 0,
				'unsupported' => 3,
				'result'      => 'compiled',
			)
		);

		$value = $this->health()->debug_information( array() )[ Health::DEBUG_SECTION ]['fields']['last_import']['value'];

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC \(/', $value );
		$this->assertStringContainsString( 'imported 4, kept 1, adjusted 2, skipped 0, unsupported 3, result: compiled', $value );
	}

	public function test_the_import_row_without_counts_shows_the_time_only(): void {
		( new Findings() )->replace( Findings::GROUP_IMPORT, array( $this->finding( Finding::IMPORTED ) ) );

		$value = $this->health()->debug_information( array() )[ Health::DEBUG_SECTION ]['fields']['last_import']['value'];

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC$/', $value );
	}

	public function test_the_findings_row_counts_every_group(): void {
		$findings = new Findings();
		$findings->replace(
			Findings::GROUP_IMPORT,
			array(
				$this->finding( Finding::IMPORTED, 'one' ),
				$this->finding( Finding::ADJUSTED, 'two', 'Adjusted.', 'adjusted_code' ),
			)
		);
		$findings->replace( Findings::GROUP_HOOKS, array( $this->finding( Finding::UNSUPPORTED, 'a_hook_name' ) ) );

		$value = $this->health()->debug_information( array() )[ Health::DEBUG_SECTION ]['fields']['findings']['value'];

		$this->assertStringContainsString( 'Import: 2 (1 need attention)', $value );
		$this->assertStringContainsString( 'Hooks: 1 (1 need attention)', $value );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_constants_row_gives_every_constant_with_its_state(): void {
		define( 'RFRAC_DEFAULT_TTL', 600 );
		define( 'WP_REST_CACHE_TIMEOUT', 2 );
		define( 'WP_REST_CACHE_MEMCACHE_USED', true );
		define( 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS', 'X-Lang' );

		$value = $this->health()->debug_information( array() )[ Health::DEBUG_SECTION ]['fields']['constants']['value'];

		$this->assertStringContainsString( 'WP_REST_CACHE_TIMEOUT (shadowed, replaced by RFRAC_DEFAULT_TTL)', $value );
		$this->assertStringContainsString( 'WP_REST_CACHE_MEMCACHE_USED (no effect)', $value );
		$this->assertStringContainsString( 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS (honoured, replaced by RFRAC_GLOBAL_VARY_HEADERS)', $value );
	}

	public function test_the_container_chains_the_test_and_the_section_after_the_core_ones(): void {
		update_option( 'wp_rest_cache_database_version', '2026.1.0' );
		set_current_screen( 'dashboard' );
		$plugin = new Plugin();
		$plugin->register_engine_hooks();

		$tests = apply_filters( 'site_status_tests', array() );
		$info  = apply_filters( 'debug_information', array() );

		foreach ( array( 'rfrac_mu_mode', 'rfrac_tables', 'rfrac_cleanup_backlog', 'rfrac_object_cache', 'rfrac_legacy' ) as $key ) {
			$this->assertArrayHasKey( $key, $tests['direct'] );
		}
		$this->assertArrayHasKey( 'rockfire-rest-api-cache', $info );
		$this->assertArrayHasKey( 'rockfire-rest-api-cache-legacy', $info );
		$this->assertSame( 'rfrac_legacy', call_user_func( $tests['direct']['rfrac_legacy']['test'] )['test'] );
	}

	public function test_the_container_adds_no_test_to_a_site_without_a_trace_and_no_hook(): void {
		set_current_screen( 'dashboard' );
		$plugin = new Plugin();
		$plugin->register_engine_hooks();

		$hooks = array_column( $plugin->engine_hooks(), 'hook' );

		$this->assertSame( 1, count( array_keys( $hooks, 'site_status_tests', true ) ) );
		$this->assertSame( 1, count( array_keys( $hooks, 'debug_information', true ) ) );
		$this->assertArrayNotHasKey( 'rfrac_legacy', apply_filters( 'site_status_tests', array() )['direct'] );
	}

	public function test_the_container_builds_the_services_once(): void {
		$plugin = new Plugin();

		$this->assertSame( $plugin->legacy_findings(), $plugin->legacy_findings() );
		$this->assertSame( $plugin->legacy_health(), $plugin->legacy_health() );
		$this->assertInstanceOf( Health::class, $plugin->legacy_health() );
		$this->assertInstanceOf( Findings::class, $plugin->legacy_findings() );
	}
}
