<?php
/**
 * Tests for the importer of the settings of the original plugin.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Capability;
use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Route_Discovery;
use Rockfire\Rest_Api_Cache\Config\Route_Info;
use Rockfire\Rest_Api_Cache\Config\Route_Rule;
use Rockfire\Rest_Api_Cache\Config\Settings_Schema;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Legacy\Constant_Fallback;
use Rockfire\Rest_Api_Cache\Legacy\Finding;
use Rockfire\Rest_Api_Cache\Legacy\Findings;
use Rockfire\Rest_Api_Cache\Legacy\Hook_Registry;
use Rockfire\Rest_Api_Cache\Legacy\Import_Notice;
use Rockfire\Rest_Api_Cache\Legacy\Import_Report;
use Rockfire\Rest_Api_Cache\Legacy\Importer;
use Rockfire\Rest_Api_Cache\Legacy\Original_Settings;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Importer
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Import_Report
 * @covers \Rockfire\Rest_Api_Cache\Plugin::legacy_importer
 */
class Test_Legacy_Importer extends Rfrac_Test_Case {

	use Rfrac_Fails_Option_Writes;

	const TTL_CONSTANTS_LABEL = 'WP_REST_CACHE_TIMEOUT * WP_REST_CACHE_TIMEOUT_INTERVAL';

	const ACME_ITEMS = 'wp_rest_cache_allowed_endpoints[acme/v1][items]';

	/**
	 * The codes of the findings that change, or would change, a setting or a route rule.
	 *
	 * @var list<string>
	 */
	const CHANGING_CODES = array(
		Importer::CODE_DEFAULT_TTL,
		Importer::CODE_MAX_TTL_RAISED,
		Importer::CODE_GLOBAL_VARY_HEADERS,
		Importer::CODE_GLOBAL_IGNORE,
		Importer::CODE_LOCKED,
		Importer::CODE_STORED_VALUE,
		Importer::CODE_STORED_RULE,
		'enabled',
		'excluded',
		'vary',
	);

	/**
	 * The REST server of the test, with the `acme/v1` routes.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * How often `update_option_rfrac_settings` fired since the counter started.
	 *
	 * @var int
	 */
	private int $settings_updates = 0;

	/**
	 * How often `rfrac_config` was written since the counter started.
	 *
	 * @var int
	 */
	private int $config_writes = 0;

	public function set_up() {
		parent::set_up();
		Rfrac_Legacy_Fixture::clear();
		delete_option( Names::OPTION_LEGACY_FINDINGS );
		delete_option( Failure_Recorder::OPTION );
		Config::set_fallback_constants( new Constant_Fallback() );
		// Rockfire's settings were saved once, so the import updates the option instead of adding it.
		update_option( Names::OPTION_SETTINGS, array_merge( Settings_Schema::defaults(), array( 'routes' => array() ) ), false );
		$this->server = $this->new_rest_server( array( 'Rfrac_Legacy_Fixture', 'register_routes' ) );
		$this->attach_settings_compile();
	}

	public function tear_down() {
		Config::set_fallback_constants( new Constant_Fallback() );
		delete_option( Failure_Recorder::OPTION );
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
	 * Count the settings updates and the writes of the compiled configuration from now on.
	 */
	private function count_writes(): void {
		$this->settings_updates = 0;
		$this->config_writes    = 0;
		add_action(
			'update_option_' . Names::OPTION_SETTINGS,
			function (): void {
				++$this->settings_updates;
			}
		);
		add_filter(
			'pre_update_option_' . Names::OPTION_CONFIG,
			function ( $value ) {
				++$this->config_writes;
				return $value;
			}
		);
	}

	/**
	 * A fresh importer over the shared settings.
	 *
	 * @return Importer
	 */
	private function importer(): Importer {
		return new Importer( Config::instance(), new Findings(), new Original_Settings() );
	}

	/**
	 * The findings of a report with a code, optionally about one subject.
	 *
	 * @param Import_Report $report  The report.
	 * @param string        $code    Finding code.
	 * @param string|null   $subject Subject, or null for any.
	 *
	 * @return list<Finding>
	 */
	private function findings_with( Import_Report $report, string $code, ?string $subject = null ): array {
		return array_values(
			array_filter(
				$report->findings,
				static fn( Finding $finding ): bool => $finding->code === $code && ( null === $subject || $finding->subject === $subject )
			)
		);
	}

	/**
	 * The one finding of a report with a code, optionally about one subject.
	 *
	 * @param Import_Report $report  The report.
	 * @param string        $code    Finding code.
	 * @param string|null   $subject Subject, or null for any.
	 *
	 * @return Finding
	 */
	private function one( Import_Report $report, string $code, ?string $subject = null ): Finding {
		$found = $this->findings_with( $report, $code, $subject );
		$this->assertCount( 1, $found, sprintf( 'Exactly one "%s" finding%s.', $code, null === $subject ? '' : ' about ' . $subject ) );
		return $found[0];
	}

	/**
	 * Code, subject and status of every finding of a report.
	 *
	 * @param Import_Report $report The report.
	 *
	 * @return list<array{0:string,1:string,2:string}>
	 */
	private function triples( Import_Report $report ): array {
		return array_map(
			static fn( Finding $finding ): array => array( $finding->code, $finding->subject, $finding->status ),
			$report->findings
		);
	}

	/**
	 * Every finding as its stored array.
	 *
	 * @param Finding[] $findings Findings.
	 *
	 * @return list<array<string,string>>
	 */
	private function arrays( array $findings ): array {
		return array_values( array_map( static fn( Finding $finding ): array => $finding->to_array(), $findings ) );
	}

	/**
	 * Every route rule as its stored array.
	 *
	 * @param array<string,Route_Rule> $rules Rules by route key.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function arrays_of_rules( array $rules ): array {
		return array_map( static fn( Route_Rule $rule ): array => $rule->to_array(), $rules );
	}

	/**
	 * The options an import may write.
	 *
	 * @return array<string,mixed>
	 */
	private function written_options(): array {
		return array(
			Names::OPTION_SETTINGS         => get_option( Names::OPTION_SETTINGS ),
			Names::OPTION_CONFIG           => get_option( Names::OPTION_CONFIG ),
			Names::OPTION_LEGACY_FINDINGS  => get_option( Names::OPTION_LEGACY_FINDINGS ),
		);
	}

	/**
	 * Store Rockfire settings over the defaults.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private function store_settings( array $settings ): void {
		update_option( Names::OPTION_SETTINGS, array_merge( Settings_Schema::defaults(), $settings, array( 'routes' => array() ) ), false );
	}

	/**
	 * Leave a site whose only trace of the original is one option, so that the import has nothing else to change.
	 *
	 * @param string $suffix Option of the original, without the `wp_rest_cache_` prefix.
	 * @param mixed  $value  Its value.
	 */
	private function install_only( string $suffix, mixed $value ): void {
		Rfrac_Legacy_Fixture::clear();
		add_option( 'wp_rest_cache_' . $suffix, $value, '', false );
	}

	/**
	 * Assert that an import saved nothing: no settings write, and the report says so.
	 *
	 * @param Import_Report $report The report of the run, made after {@see self::count_writes()}.
	 */
	private function assert_nothing_written( Import_Report $report ): void {
		$this->assertSame( Import_Report::RESULT_UNCHANGED, $report->result );
		$this->assertSame( 0, $this->settings_updates, 'No settings write.' );
	}

	// -----------------------------------------------------------------------------------------
	// A stock site.
	// -----------------------------------------------------------------------------------------

	/**
	 * AC-1: one settings write, one compile, the one-year lifetime, rules for the plugin routes only.
	 */
	public function test_a_stock_site_imports_with_one_settings_write_and_one_compile(): void {
		Rfrac_Legacy_Fixture::install_stock();
		$this->count_writes();

		$report = $this->importer()->run();

		$settings = Config::instance()->settings();
		$this->assertSame( 31536000, $settings['max_ttl'] );
		$this->assertSame( 31536000, $settings['default_ttl'] );
		$this->assertSame( Finding::ADJUSTED, $this->one( $report, Importer::CODE_MAX_TTL_RAISED, Rfrac_Legacy_Fixture::TTL_OPTIONS_LABEL )->status );
		$this->assertSame( Finding::IMPORTED, $this->one( $report, Importer::CODE_DEFAULT_TTL, Rfrac_Legacy_Fixture::TTL_OPTIONS_LABEL )->status );

		$stored = Config::instance()->stored_route_rules();
		$this->assertSame( Rfrac_Legacy_Fixture::ACME_ROUTES, array_keys( $stored ), 'Only the plugin routes get a rule; no wp/v2 route does.' );
		foreach ( $stored as $rule ) {
			$this->assertSame( Config::default_route_rule()->to_array(), $rule->to_array() );
		}
		$items = $this->one( $report, 'enabled', self::ACME_ITEMS );
		$this->assertSame( Finding::IMPORTED, $items->status );
		$this->assertStringContainsString( 'items in acme/v1: 2 routes cached, 0 already cached by default', $items->message );
		$posts = $this->one( $report, 'already_default', 'wp_rest_cache_allowed_endpoints[wp/v2][posts]' );
		$this->assertSame( Finding::IMPORTED, $posts->status );

		$this->assertSame( 1, $this->settings_updates, 'update_option_rfrac_settings fires once.' );
		$this->assertSame( 1, $this->config_writes, 'rfrac_config is written once.' );
		$this->assertSame( Import_Report::RESULT_COMPILED, $report->result );
		$this->assertFalse( $report->dry_run );
		$this->assertNotNull( $report->validation );
		$this->assertTrue( $report->validation->is_valid() );
		$this->assertSame( Finding::ADJUSTED, $this->one( $report, Importer::CODE_STRICTER_REQUESTS, Importer::PLUGIN_SUBJECT )->status );

		$group = ( new Findings() )->get( Findings::GROUP_IMPORT );
		$this->assertNotNull( $group );
		$this->assertSame( $this->arrays( $report->findings ), $this->arrays( $group['findings'] ), 'The import group holds the returned findings.' );
		$this->assertSame( $report->counts + array( 'result' => Import_Report::RESULT_COMPILED ), $group['meta'] );
		$this->assertSame( count( $report->findings ), array_sum( $report->counts ) );
	}

	/**
	 * AC-2: a dry run reports what the run does and writes nothing.
	 */
	public function test_a_dry_run_reports_the_findings_of_the_run_and_writes_nothing(): void {
		Rfrac_Legacy_Fixture::install_stock();
		$before = $this->written_options();

		$dry = $this->importer()->run( true );

		$this->assertSame( $before, $this->written_options(), 'The dry run left rfrac_settings, rfrac_config and rfrac_legacy_findings unchanged.' );
		$this->assertSame( Import_Report::RESULT_DRY_RUN, $dry->result );
		$this->assertTrue( $dry->dry_run );
		$this->assertNull( $dry->validation );

		$run = $this->importer()->run();

		$this->assertNotEmpty( $dry->findings );
		$this->assertSame( $this->triples( $run ), $this->triples( $dry ) );
		$this->assertSame( $run->counts, $dry->counts );
	}

	/**
	 * AC-3: a second run writes nothing and keeps every imported value.
	 */
	public function test_a_second_run_changes_nothing(): void {
		Rfrac_Legacy_Fixture::install_stock();
		$this->importer()->run();
		$this->count_writes();

		$report = $this->importer()->run();

		$this->assertSame( 0, $this->settings_updates, 'No settings write.' );
		$this->assertSame( Import_Report::RESULT_UNCHANGED, $report->result );
		foreach ( $report->findings as $finding ) {
			$this->assertNotContains( $finding->code, self::CHANGING_CODES, 'Every setting and rule finding is already imported: ' . $finding->message );
			if ( Importer::CODE_ALREADY_IMPORTED === $finding->code ) {
				$this->assertSame( Finding::KEPT, $finding->status );
			}
		}
		$this->assertSame( Finding::KEPT, $this->one( $report, Importer::CODE_ALREADY_IMPORTED, Rfrac_Legacy_Fixture::TTL_OPTIONS_LABEL )->status );
		$this->assertSame( Finding::KEPT, $this->one( $report, Importer::CODE_ALREADY_IMPORTED, self::ACME_ITEMS )->status );
	}

	// -----------------------------------------------------------------------------------------
	// A save that fails.
	// -----------------------------------------------------------------------------------------

	/**
	 * A failed first import stores no report, so the notice still offers the import and the reasons reach the caller.
	 */
	public function test_a_failed_first_import_stores_no_report_and_the_notice_still_offers_it(): void {
		Rfrac_Legacy_Fixture::install_stock();
		$this->fail_option_writes( Names::OPTION_SETTINGS );

		$report = $this->importer()->run();

		$this->assertSame( Import_Report::RESULT_FAILED, $report->result );
		$this->assertNotNull( $report->validation );
		$this->assertFalse( $report->validation->is_valid() );
		$this->assertTrue( $report->validation->has_code( Config::SAVE_FAILED ), 'The report carries why the save failed.' );
		$this->assertNull( ( new Findings() )->get( Findings::GROUP_IMPORT ), 'The planned findings are not stored as imported: none of their values was written.' );
		$this->assertSame( 2592000, Config::instance()->settings()['max_ttl'], 'Nothing was written.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// The notice prints only on the Plugins screen and the pages of the plugin, so it renders on the Plugins screen.
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		set_current_screen( 'plugins' );
		ob_start();
		try {
			( new Import_Notice( new Capability(), new Findings() ) )->render();
		} finally {
			$html = (string) ob_get_clean();
			unset( $GLOBALS['current_screen'] );
		}
		$this->assertStringContainsString( 'Import now', $html, 'Without a stored report the notice offers the import again.' );
		wp_set_current_user( 0 );
	}

	/**
	 * A failed repeated import leaves the report of the import that was saved.
	 */
	public function test_a_failed_import_keeps_the_report_of_the_earlier_import(): void {
		Rfrac_Legacy_Fixture::install_stock();
		$first = $this->importer()->run();
		$this->assertSame( Import_Report::RESULT_COMPILED, $first->result, 'Precondition: a saved import.' );
		$stored = ( new Findings() )->get( Findings::GROUP_IMPORT );
		$this->assertNotNull( $stored );

		// The settings go back to their defaults, so that the second import has values to write again.
		$this->store_settings( array() );
		$this->fail_option_writes( Names::OPTION_SETTINGS );

		$report = $this->importer()->run();

		$this->assertSame( Import_Report::RESULT_FAILED, $report->result );
		$this->assertTrue( $report->validation->has_code( Config::SAVE_FAILED ) );
		$this->assertEquals( $stored, ( new Findings() )->get( Findings::GROUP_IMPORT ), 'The group still holds the report of the saved import.' );
		$this->assertSame( Import_Report::RESULT_COMPILED, ( new Findings() )->get( Findings::GROUP_IMPORT )['meta']['result'] );
	}

	// -----------------------------------------------------------------------------------------
	// Rockfire wins.
	// -----------------------------------------------------------------------------------------

	/**
	 * AC-4: a stored default_ttl is kept, and max_ttl is then not raised.
	 */
	public function test_a_stored_default_ttl_is_kept_and_max_ttl_is_not_raised(): void {
		Rfrac_Legacy_Fixture::install_stock();
		$this->store_settings( array( 'default_ttl' => 3600 ) );

		$report = $this->importer()->run();

		$settings = Config::instance()->settings();
		$this->assertSame( 3600, $settings['default_ttl'] );
		$this->assertSame( 2592000, $settings['max_ttl'] );
		$this->assertSame( Finding::KEPT, $this->one( $report, Importer::CODE_STORED_VALUE, Rfrac_Legacy_Fixture::TTL_OPTIONS_LABEL )->status );
		$this->assertSame( array(), $this->findings_with( $report, Importer::CODE_MAX_TTL_RAISED ) );
	}

	/**
	 * AC-4: a stored max_ttl stays, and the default_ttl is clamped to it.
	 */
	public function test_a_stored_max_ttl_stays_and_the_default_ttl_fits_it(): void {
		Rfrac_Legacy_Fixture::install_stock();
		$this->store_settings( array( 'max_ttl' => 604800 ) );

		$report = $this->importer()->run();

		$this->assertSame( 604800, Config::instance()->raw_settings()['default_ttl'] );
		$this->assertSame( 604800, Config::instance()->settings()['max_ttl'] );
		$this->assertSame( Finding::ADJUSTED, $this->one( $report, Importer::CODE_DEFAULT_TTL, Rfrac_Legacy_Fixture::TTL_OPTIONS_LABEL )->status );
		$this->assertSame( array(), $this->findings_with( $report, Importer::CODE_MAX_TTL_RAISED ) );
	}

	/**
	 * AC-4: a max_ttl locked by its constant is untouched; the default_ttl is adjusted to it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_locked_max_ttl_is_untouched_and_the_default_ttl_fits_it(): void {
		define( 'RFRAC_MAX_TTL', 86400 );
		Rfrac_Legacy_Fixture::install_stock();

		$report = $this->importer()->run();

		$settings = Config::instance()->settings();
		$this->assertSame( 86400, $settings['max_ttl'] );
		$this->assertSame( 86400, $settings['default_ttl'] );
		$this->assertSame( 2592000, Config::instance()->raw_settings()['max_ttl'], 'The stored max_ttl is untouched.' );
		$this->assertSame( Finding::ADJUSTED, $this->one( $report, Importer::CODE_DEFAULT_TTL, Rfrac_Legacy_Fixture::TTL_OPTIONS_LABEL )->status );
		$this->assertSame( array(), $this->findings_with( $report, Importer::CODE_MAX_TTL_RAISED ) );
	}

	/**
	 * AC-5: a stored rule wins over an endpoint the original allowed.
	 */
	public function test_a_stored_rule_wins_over_an_allowed_endpoint(): void {
		Rfrac_Legacy_Fixture::install_stock();
		Config::instance()->save_route_rule( '/wp/v2/comments', array( 'status' => 'excluded' ) );
		$before = Config::instance()->stored_route_rules()['/wp/v2/comments']->to_array();

		$report = $this->importer()->run();

		$stored = Config::instance()->stored_route_rules();
		$this->assertSame( $before, $stored['/wp/v2/comments']->to_array(), 'The stored rule is unchanged.' );
		$this->assertArrayNotHasKey( '/wp/v2/comments/(?P<id>[\d]+)', $stored, 'The item route is cached by default and needs no rule.' );
		$kept = $this->one( $report, Importer::CODE_STORED_RULE, 'wp_rest_cache_allowed_endpoints[wp/v2][comments]' );
		$this->assertSame( Finding::KEPT, $kept->status );
	}

	/**
	 * AC-5: an invalid stored rule is kept as it is, and only the other route of the entry gets a rule.
	 */
	public function test_an_invalid_stored_rule_wins_over_an_allowed_endpoint(): void {
		Rfrac_Legacy_Fixture::install_stock();
		$invalid = array( 'status' => 'bogus' );
		update_option( Names::OPTION_SETTINGS, array_merge( Settings_Schema::defaults(), array( 'routes' => array( '/acme/v1/items' => $invalid ) ) ), false );
		$this->assertSame( array( '/acme/v1/items' ), array_keys( Config::instance()->invalid_stored_route_rules() ), 'Precondition: an invalid stored rule.' );

		$report = $this->importer()->run();

		$this->assertTrue( $report->validation->is_valid() );
		$this->assertSame( $invalid, get_option( Names::OPTION_SETTINGS )['routes']['/acme/v1/items'], 'The invalid rule is stored as it was.' );
		$this->assertSame( array( '/acme/v1/items/(?P<id>\d+)' ), array_keys( Config::instance()->stored_route_rules() ), 'Only the other route of the entry gets a rule.' );
		$this->assertSame( array( '/acme/v1/items' ), array_keys( Config::instance()->invalid_stored_route_rules() ) );

		$kept = $this->one( $report, Importer::CODE_STORED_RULE, self::ACME_ITEMS );
		$this->assertSame( Finding::KEPT, $kept->status );
		$this->assertStringContainsString( '1 route keeps the rule saved in this plugin', $kept->message );
		$imported = $this->one( $report, 'enabled', self::ACME_ITEMS );
		$this->assertSame( Finding::IMPORTED, $imported->status );
		$this->assertStringContainsString( '1 routes cached', $imported->message );
	}

	/**
	 * AC-5: a route defined by RFRAC_ROUTES keeps its rule; the other routes of the entry are imported.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_route_locked_by_the_routes_constant_is_kept(): void {
		define( 'RFRAC_ROUTES', array( '/acme/v1/items' => array( 'status' => 'excluded' ) ) );
		Rfrac_Legacy_Fixture::install_stock();

		$report = $this->importer()->run();

		$this->assertTrue( $report->validation->is_valid() );
		$stored = Config::instance()->stored_route_rules();
		$this->assertArrayNotHasKey( '/acme/v1/items', $stored, 'The locked route gets no stored rule.' );
		$this->assertArrayHasKey( '/acme/v1/items/(?P<id>\d+)', $stored );
		$this->assertSame( 'excluded', Config::instance()->constant_route_rules()['/acme/v1/items']->status );
		$kept = $this->one( $report, Importer::CODE_LOCKED, self::ACME_ITEMS );
		$this->assertSame( Finding::KEPT, $kept->status );
		$this->assertStringContainsString( Config::ROUTES_CONSTANT, $kept->message );
		$this->assertSame( Finding::IMPORTED, $this->one( $report, 'enabled', self::ACME_ITEMS )->status );
	}

	/**
	 * AC-6: the TTL constants of the original lock default_ttl under their label and still raise max_ttl.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_ttl_constants_lock_the_default_ttl_and_raise_max_ttl(): void {
		define( 'WP_REST_CACHE_TIMEOUT', 2 );
		define( 'WP_REST_CACHE_TIMEOUT_INTERVAL', 31536000 );

		$report = $this->importer()->run();

		$kept = $this->one( $report, Importer::CODE_LOCKED, self::TTL_CONSTANTS_LABEL );
		$this->assertSame( Finding::KEPT, $kept->status );
		$this->assertStringContainsString( self::TTL_CONSTANTS_LABEL, $kept->message, 'The message names the fallback label.' );
		$this->assertSame( Finding::ADJUSTED, $this->one( $report, Importer::CODE_MAX_TTL_RAISED, self::TTL_CONSTANTS_LABEL )->status );
		$settings = Config::instance()->settings();
		$this->assertSame( 31536000, $settings['max_ttl'] );
		$this->assertSame( 31536000, $settings['default_ttl'] );
		$this->assertSame( self::TTL_CONSTANTS_LABEL, Config::instance()->locked()['default_ttl'] );
	}

	/**
	 * The import never excludes a route the original did not allow.
	 */
	public function test_a_disallowed_route_is_excluded_only_when_the_original_allowed_it(): void {
		Rfrac_Legacy_Fixture::install_stock();
		update_option( 'wp_rest_cache_disallowed_endpoints', array( 'wp/v2' => array( 'comments', 'search' ) ), false );

		$report = $this->importer()->run();

		$stored = Config::instance()->stored_route_rules();
		$this->assertSame( 'excluded', $stored['/wp/v2/comments']->status );
		$this->assertSame( 'excluded', $stored['/wp/v2/comments/(?P<id>[\d]+)']->status );
		$this->assertArrayNotHasKey( '/wp/v2/search', $stored );
		$this->assertSame( Finding::IMPORTED, $this->one( $report, 'excluded', 'wp_rest_cache_disallowed_endpoints[wp/v2][comments]' )->status );
		$this->assertSame( Finding::SKIPPED, $this->one( $report, Importer::CODE_NOT_ALLOWED, 'wp_rest_cache_disallowed_endpoints[wp/v2][search]' )->status );
	}

	// -----------------------------------------------------------------------------------------
	// Global lists, values without an equivalent, hooks.
	// -----------------------------------------------------------------------------------------

	/**
	 * AC-7: invalid parameter names and forbidden header names are skipped one by one.
	 */
	public function test_the_global_lists_skip_invalid_and_forbidden_names(): void {
		Rfrac_Legacy_Fixture::install_stock();
		update_option( 'wp_rest_cache_uncached_parameters', array( 'utm_source', 'bad name' ), false );
		add_option( 'wp_rest_cache_global_cacheable_request_headers', 'X-Lang,Authorization' );

		$report = $this->importer()->run();

		$settings = Config::instance()->settings();
		$this->assertSame( array( 'utm_source' ), $settings['global_ignore_params'] );
		$this->assertSame( array( 'x-lang' ), $settings['global_vary_headers'] );

		$params = 'wp_rest_cache_uncached_parameters';
		$this->assertSame( Finding::ADJUSTED, $this->one( $report, Importer::CODE_GLOBAL_IGNORE, $params )->status );
		$bad = $this->one( $report, Importer::CODE_INVALID_PARAM, $params );
		$this->assertSame( Finding::SKIPPED, $bad->status );
		$this->assertStringContainsString( 'bad name', $bad->message );

		$headers   = 'wp_rest_cache_global_cacheable_request_headers';
		$forbidden = $this->one( $report, 'vary_forbidden', $headers );
		$this->assertSame( Finding::SKIPPED, $forbidden->status );
		$this->assertStringContainsString( 'authorization', $forbidden->message );
		$this->assertSame( Finding::ADJUSTED, $this->one( $report, Importer::CODE_GLOBAL_VARY_HEADERS, $headers )->status );
	}

	/**
	 * AC-4: a stored, non-default list of ignored parameters stays, whatever the original had.
	 */
	public function test_a_stored_global_ignore_list_is_kept(): void {
		$this->install_only( 'uncached_parameters', array( 'utm_source' ) );
		$this->store_settings( array( 'global_ignore_params' => array( 'fbclid' ) ) );
		$this->count_writes();

		$report = $this->importer()->run();

		$this->assertSame( array( 'fbclid' ), Config::instance()->settings()['global_ignore_params'] );
		$kept = $this->one( $report, Importer::CODE_STORED_VALUE, 'wp_rest_cache_uncached_parameters' );
		$this->assertSame( Finding::KEPT, $kept->status );
		$this->assertStringContainsString( 'global_ignore_params', $kept->message );
		$this->assertSame( array(), $this->findings_with( $report, Importer::CODE_GLOBAL_IGNORE ) );
		$this->assert_nothing_written( $report );
	}

	/**
	 * AC-3: the list of ignored parameters the original has is the one stored, so it is already imported.
	 */
	public function test_the_same_stored_global_ignore_list_is_already_imported(): void {
		$this->install_only( 'uncached_parameters', array( 'utm_source', 'fbclid' ) );
		$this->store_settings( array( 'global_ignore_params' => array( 'utm_source', 'fbclid' ) ) );
		$this->count_writes();

		$report = $this->importer()->run();

		$this->assertSame( array( 'utm_source', 'fbclid' ), Config::instance()->settings()['global_ignore_params'] );
		$this->assertSame( Finding::KEPT, $this->one( $report, Importer::CODE_ALREADY_IMPORTED, 'wp_rest_cache_uncached_parameters' )->status );
		$this->assertSame( array(), $this->findings_with( $report, Importer::CODE_STORED_VALUE ) );
		$this->assert_nothing_written( $report );
	}

	/**
	 * AC-4: a list of ignored parameters defined by its constant is untouched.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_locked_global_ignore_list_is_untouched(): void {
		define( 'RFRAC_GLOBAL_IGNORE_PARAMS', array( 'gclid' ) );
		$this->install_only( 'uncached_parameters', array( 'utm_source' ) );
		$this->count_writes();

		$report = $this->importer()->run();

		$this->assertSame( array( 'gclid' ), Config::instance()->settings()['global_ignore_params'] );
		$this->assertSame( array(), Config::instance()->raw_settings()['global_ignore_params'], 'The stored list is untouched.' );
		$kept = $this->one( $report, Importer::CODE_LOCKED, 'wp_rest_cache_uncached_parameters' );
		$this->assertSame( Finding::KEPT, $kept->status );
		$this->assertStringContainsString( 'RFRAC_GLOBAL_IGNORE_PARAMS', $kept->message );
		$this->assert_nothing_written( $report );
	}

	/**
	 * AC-4: a stored, non-default list of global vary headers stays, whatever the original had.
	 */
	public function test_a_stored_global_vary_list_is_kept(): void {
		$this->install_only( 'global_cacheable_request_headers', 'X-Region' );
		$this->store_settings( array( 'global_vary_headers' => array( 'x-lang' ) ) );
		$this->count_writes();

		$report = $this->importer()->run();

		$this->assertSame( array( 'x-lang' ), Config::instance()->settings()['global_vary_headers'] );
		$kept = $this->one( $report, Importer::CODE_STORED_VALUE, 'wp_rest_cache_global_cacheable_request_headers' );
		$this->assertSame( Finding::KEPT, $kept->status );
		$this->assertStringContainsString( 'global_vary_headers', $kept->message );
		$this->assertSame( array(), $this->findings_with( $report, Importer::CODE_GLOBAL_VARY_HEADERS ) );
		$this->assert_nothing_written( $report );
	}

	/**
	 * AC-3: the global vary headers the original has, once normalised, are the ones stored, so they are already imported.
	 */
	public function test_the_same_stored_global_vary_list_is_already_imported(): void {
		$this->install_only( 'global_cacheable_request_headers', 'X_Lang, X-Region' );
		$this->store_settings( array( 'global_vary_headers' => array( 'x-lang', 'x-region' ) ) );
		$this->count_writes();

		$report = $this->importer()->run();

		$this->assertSame( array( 'x-lang', 'x-region' ), Config::instance()->settings()['global_vary_headers'] );
		$this->assertSame( Finding::KEPT, $this->one( $report, Importer::CODE_ALREADY_IMPORTED, 'wp_rest_cache_global_cacheable_request_headers' )->status );
		$this->assertSame( array(), $this->findings_with( $report, Importer::CODE_STORED_VALUE ) );
		$this->assert_nothing_written( $report );
	}

	/**
	 * AC-4: global vary headers defined by their constant are untouched.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_locked_global_vary_headers_are_untouched(): void {
		define( 'RFRAC_GLOBAL_VARY_HEADERS', array( 'Accept-Language' ) );
		$this->install_only( 'global_cacheable_request_headers', 'X-Region' );
		$this->count_writes();

		$report = $this->importer()->run();

		$this->assertSame( array( 'accept-language' ), Config::instance()->settings()['global_vary_headers'] );
		$this->assertSame( array(), Config::instance()->raw_settings()['global_vary_headers'], 'The stored list is untouched.' );
		$kept = $this->one( $report, Importer::CODE_LOCKED, 'wp_rest_cache_global_cacheable_request_headers' );
		$this->assertSame( Finding::KEPT, $kept->status );
		$this->assertStringContainsString( 'RFRAC_GLOBAL_VARY_HEADERS', $kept->message );
		$this->assert_nothing_written( $report );
	}

	// -----------------------------------------------------------------------------------------
	// The vary limit.
	// -----------------------------------------------------------------------------------------

	/**
	 * AC-7: the global headers use up the vary budget of every imported rule; the headers over it are named.
	 */
	public function test_the_global_vary_headers_reduce_the_vary_budget_of_the_imported_rules(): void {
		Rfrac_Legacy_Fixture::install_stock();
		add_option( 'wp_rest_cache_global_cacheable_request_headers', 'X-Country,X-Tenant,X-Currency' );
		update_option( 'wp_rest_cache_cacheable_request_headers', array( 'acme/v1/items' => 'X-Lang,X-Region,X-Device,X-Theme' ), false );

		$report = $this->importer()->run();

		$this->assertSame( Import_Report::RESULT_COMPILED, $report->result );
		$this->assertTrue( $report->validation->is_valid() );
		$global = Config::instance()->settings()['global_vary_headers'];
		$this->assertSame( array( 'x-country', 'x-tenant', 'x-currency' ), $global );

		$stored = Config::instance()->stored_route_rules();
		$this->assertSame( Rfrac_Legacy_Fixture::ACME_ROUTES, array_keys( $stored ) );
		foreach ( $stored as $key => $rule ) {
			$this->assertSame( array( 'x-lang', 'x-region' ), $rule->vary_headers, $key . ' varies on the two headers that fit.' );
		}
		$this->assertTrue( Config::instance()->vary_fit( $global )->is_valid(), 'No rule varies on more than ' . Route_Rule::MAX_VARY_HEADERS . ' headers with the global ones.' );

		$subject = 'wp_rest_cache_cacheable_request_headers[acme/v1/items]';
		$limit   = $this->one( $report, 'vary_limit', $subject );
		$this->assertSame( Finding::ADJUSTED, $limit->status );
		$this->assertStringContainsString( 'x-device, x-theme left out on 2 routes', $limit->message );
		$this->assertStringContainsString( 'at most 2 request headers', $limit->message );
		$this->assertSame( Finding::IMPORTED, $this->one( $report, 'vary', $subject )->status );
		$this->assertSame( Finding::IMPORTED, $this->one( $report, Importer::CODE_GLOBAL_VARY_HEADERS, 'wp_rest_cache_global_cacheable_request_headers' )->status );
	}

	/**
	 * AC-7: global headers that would make a stored rule vary on too many headers are not imported.
	 */
	public function test_global_vary_headers_that_do_not_fit_a_stored_rule_are_skipped(): void {
		Rfrac_Legacy_Fixture::install_stock();
		$saved = Config::instance()->save_route_rule(
			'/acme/v1/items',
			array(
				'status'       => 'enabled',
				'vary_headers' => array( 'x-lang', 'x-region', 'x-device', 'x-theme' ),
			)
		);
		$this->assertTrue( $saved->is_valid(), 'Precondition: a stored rule varying on four headers.' );
		$before = Config::instance()->stored_route_rules()['/acme/v1/items']->to_array();
		add_option( 'wp_rest_cache_global_cacheable_request_headers', 'X-Country,X-Tenant' );

		$report = $this->importer()->run();

		$this->assertTrue( $report->validation->is_valid() );
		$skipped = $this->one( $report, Importer::CODE_TOO_MANY_VARY, 'wp_rest_cache_global_cacheable_request_headers' );
		$this->assertSame( Finding::SKIPPED, $skipped->status );
		$this->assertStringContainsString( '/acme/v1/items', $skipped->message );
		$this->assertSame( array(), $this->findings_with( $report, Importer::CODE_GLOBAL_VARY_HEADERS ) );
		$this->assertSame( Settings_Schema::defaults()['global_vary_headers'], Config::instance()->raw_settings()['global_vary_headers'], 'The global vary headers stay at their default.' );
		$this->assertSame( $before, Config::instance()->stored_route_rules()['/acme/v1/items']->to_array(), 'The stored rule is unchanged.' );
		$this->assertTrue( Config::instance()->vary_fit( Config::instance()->settings()['global_vary_headers'] )->is_valid() );
	}

	/**
	 * AC-8: request methods other than GET are skipped in one finding.
	 */
	public function test_other_request_methods_are_skipped(): void {
		Rfrac_Legacy_Fixture::install_stock();
		update_option( 'wp_rest_cache_allowed_request_methods', array( 'GET', 'POST' ), false );

		$finding = $this->one( $this->importer()->run( true ), Importer::CODE_REQUEST_METHODS );

		$this->assertSame( Finding::SKIPPED, $finding->status );
		$this->assertSame( 'wp_rest_cache_allowed_request_methods', $finding->subject );
		$this->assertStringContainsString( 'POST', $finding->message );
	}

	/**
	 * AC-8: GET only is imported.
	 */
	public function test_get_only_is_imported(): void {
		Rfrac_Legacy_Fixture::install_stock();

		$this->assertSame( Finding::IMPORTED, $this->one( $this->importer()->run( true ), Importer::CODE_REQUEST_METHODS )->status );
	}

	/**
	 * AC-1: POST of the original is cached on each imported route that registers it; DELETE is skipped.
	 */
	public function test_allowed_request_methods_are_imported_onto_the_routes_that_register_them(): void {
		$this->server = $this->new_rest_server( array( 'Rfrac_Legacy_Fixture', 'register_method_routes' ) );
		Rfrac_Legacy_Fixture::install_methods( array( 'GET', 'POST', 'DELETE' ) );

		$report = $this->importer()->run();

		$this->assertTrue( $report->validation->is_valid() );
		$stored = Config::instance()->stored_route_rules();
		$this->assertSame( array( 'GET', 'POST' ), $stored['/acme/v1/read-write']->methods );
		$this->assertSame( array( 'POST' ), $stored['/acme/v1/write-only']->methods );
		$this->assertSame( array( 'GET' ), $stored['/acme/v1/read-only']->methods, 'A route without POST keeps GET only.' );
		$this->assertArrayNotHasKey( 'methods', $stored['/acme/v1/read-only']->to_array() );

		$methods = $this->one( $report, Importer::CODE_REQUEST_METHODS );
		$this->assertSame( Finding::ADJUSTED, $methods->status );
		$this->assertSame( 'wp_rest_cache_allowed_request_methods', $methods->subject );
		$this->assertStringContainsString( 'POST', $methods->message );
		$this->assertStringNotContainsString( 'DELETE', $methods->message );
		$this->assertStringContainsString( '2 imported routes', $methods->message );

		$unsupported = $this->one( $report, Importer::CODE_REQUEST_METHODS_UNSUPPORTED );
		$this->assertSame( Finding::SKIPPED, $unsupported->status );
		$this->assertSame( 'wp_rest_cache_allowed_request_methods', $unsupported->subject );
		$this->assertStringContainsString( 'DELETE', $unsupported->message );
		$this->assertStringNotContainsString( 'POST', $unsupported->message );
	}

	/**
	 * AC-1: a method no imported route registers is not named as cached, and is skipped while another method is applied.
	 */
	public function test_a_method_no_route_registers_is_not_named_as_cached(): void {
		$this->server = $this->new_rest_server( array( 'Rfrac_Legacy_Fixture', 'register_method_routes' ) );
		Rfrac_Legacy_Fixture::install_methods( array( 'GET', 'POST', 'PUT' ) );
		// Only the acme/v1 routes are imported: they register POST, and none registers PUT.
		update_option( 'wp_rest_cache_allowed_endpoints', array( 'acme/v1' => Rfrac_Legacy_Fixture::METHOD_ENDPOINTS ), false );

		$report = $this->importer()->run( true );

		$methods = $this->one( $report, Importer::CODE_REQUEST_METHODS );
		$this->assertSame( Finding::ADJUSTED, $methods->status );
		$this->assertStringContainsString( 'POST', $methods->message );
		$this->assertStringNotContainsString( 'PUT', $methods->message );
		$this->assertSame( array(), $this->findings_with( $report, Importer::CODE_REQUEST_METHODS_UNSUPPORTED ) );

		$unregistered = $this->one( $report, Importer::CODE_REQUEST_METHODS_NO_ROUTE );
		$this->assertSame( Finding::SKIPPED, $unregistered->status );
		$this->assertSame( 'wp_rest_cache_allowed_request_methods', $unregistered->subject );
		$this->assertStringContainsString( 'PUT requests are not cached, because no imported route takes them', $unregistered->message );
		$this->assertStringNotContainsString( 'POST', $unregistered->message );
		$this->assertSame( array(), $this->findings_with( $report, Importer::CODE_REQUEST_METHODS_NOT_APPLIED ) );
	}

	/**
	 * AC-1: a method that only a route keeping without it registers is reported as not applied, not twice.
	 */
	public function test_a_method_only_a_kept_route_registers_is_not_reported_as_unregistered(): void {
		$this->server = $this->new_rest_server( array( 'Rfrac_Legacy_Fixture', 'register_method_routes' ) );
		Rfrac_Legacy_Fixture::install_methods( array( 'GET', 'POST', 'PUT' ) );

		$report = $this->importer()->run( true );

		$this->assertSame( Finding::ADJUSTED, $this->one( $report, Importer::CODE_REQUEST_METHODS )->status );
		$this->assertStringContainsString( 'PUT', $this->one( $report, Importer::CODE_REQUEST_METHODS_NOT_APPLIED )->message, 'A core route registers PUT but keeps without it.' );
		$this->assertSame( array(), $this->findings_with( $report, Importer::CODE_REQUEST_METHODS_NO_ROUTE ) );
	}

	/**
	 * AC-4: an option with only unsupported methods does not claim that it allows no request method.
	 */
	public function test_an_option_with_only_unsupported_methods_does_not_claim_to_allow_no_method(): void {
		Rfrac_Legacy_Fixture::install_stock();
		update_option( 'wp_rest_cache_allowed_request_methods', array( 'DELETE' ), false );

		$report = $this->importer()->run( true );

		$methods = $this->one( $report, Importer::CODE_REQUEST_METHODS );
		$this->assertSame( Finding::SKIPPED, $methods->status );
		$this->assertSame( 'wp_rest_cache_allowed_request_methods', $methods->subject );
		$this->assertStringNotContainsString( 'allows no request method', $methods->message );
		$this->assertStringContainsString( 'GET requests are cached', $methods->message );
		$unsupported = $this->one( $report, Importer::CODE_REQUEST_METHODS_UNSUPPORTED );
		$this->assertStringContainsString( 'DELETE', $unsupported->message );
	}

	/**
	 * AC-4: an option without any method allows no request method.
	 */
	public function test_an_option_without_methods_allows_no_request_method(): void {
		Rfrac_Legacy_Fixture::install_stock();
		update_option( 'wp_rest_cache_allowed_request_methods', array(), false );

		$report = $this->importer()->run( true );

		$methods = $this->one( $report, Importer::CODE_REQUEST_METHODS );
		$this->assertSame( Finding::SKIPPED, $methods->status );
		$this->assertStringContainsString( 'allows no request method', $methods->message );
		$this->assertSame( array(), $this->findings_with( $report, Importer::CODE_REQUEST_METHODS_UNSUPPORTED ) );
	}

	/**
	 * AC-3: a stored rule that already caches POST does not count as a route that keeps without it.
	 */
	public function test_a_stored_rule_that_caches_post_is_not_reported_as_not_applied(): void {
		$this->server = $this->new_rest_server( array( 'Rfrac_Legacy_Fixture', 'register_method_routes' ) );
		Rfrac_Legacy_Fixture::install_methods( array( 'GET', 'POST' ) );
		update_option( 'wp_rest_cache_allowed_endpoints', array( 'acme/v1' => Rfrac_Legacy_Fixture::METHOD_ENDPOINTS ), false );
		$saved = Config::instance()->save_route_rule( '/acme/v1/read-write', array( 'status' => 'enabled', 'ttl' => 120, 'methods' => array( 'GET', 'POST' ) ) );
		$this->assertTrue( $saved->is_valid(), 'Precondition: a stored rule that caches POST.' );
		$before = Config::instance()->stored_route_rules()['/acme/v1/read-write']->to_array();

		$report = $this->importer()->run();

		$this->assertTrue( $report->validation->is_valid() );
		$this->assertSame( $before, Config::instance()->stored_route_rules()['/acme/v1/read-write']->to_array(), 'The stored rule is unchanged.' );
		$this->assertSame( Finding::KEPT, $this->one( $report, Importer::CODE_STORED_RULE, 'wp_rest_cache_allowed_endpoints[acme/v1][read-write]' )->status );
		$this->assertSame( array(), $this->findings_with( $report, Importer::CODE_REQUEST_METHODS_NOT_APPLIED ), 'The stored rule caches POST on the route.' );
		$methods = $this->one( $report, Importer::CODE_REQUEST_METHODS );
		$this->assertSame( Finding::ADJUSTED, $methods->status );
		$this->assertStringContainsString( '2 imported routes', $methods->message, 'The route with the stored rule caches POST as the imported write-only route does.' );
	}

	/**
	 * AC-3: a rule defined in RFRAC_ROUTES that already caches POST does not count as a route that keeps without it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_locked_rule_that_caches_post_is_not_reported_as_not_applied(): void {
		define( 'RFRAC_ROUTES', array( '/acme/v1/read-write' => array( 'status' => 'enabled', 'methods' => array( 'GET', 'POST' ) ) ) );
		$this->server = $this->new_rest_server( array( 'Rfrac_Legacy_Fixture', 'register_method_routes' ) );
		Rfrac_Legacy_Fixture::install_methods( array( 'GET', 'POST' ) );
		update_option( 'wp_rest_cache_allowed_endpoints', array( 'acme/v1' => Rfrac_Legacy_Fixture::METHOD_ENDPOINTS ), false );
		$this->assertSame( array( 'GET', 'POST' ), Config::instance()->constant_route_rules()['/acme/v1/read-write']->methods, 'Precondition: a constant rule that caches POST.' );

		$report = $this->importer()->run();

		$this->assertTrue( $report->validation->is_valid() );
		$this->assertArrayNotHasKey( '/acme/v1/read-write', Config::instance()->stored_route_rules(), 'The locked route gets no stored rule.' );
		$this->assertSame( array(), $this->findings_with( $report, Importer::CODE_REQUEST_METHODS_NOT_APPLIED ), 'The constant rule caches POST on the route.' );
		$methods = $this->one( $report, Importer::CODE_REQUEST_METHODS );
		$this->assertSame( Finding::ADJUSTED, $methods->status );
		$this->assertStringContainsString( '2 imported routes', $methods->message );
	}

	/**
	 * AC-2: a second import reports the routes as already imported and changes nothing.
	 */
	public function test_a_second_import_of_the_request_methods_changes_nothing(): void {
		$this->server = $this->new_rest_server( array( 'Rfrac_Legacy_Fixture', 'register_method_routes' ) );
		Rfrac_Legacy_Fixture::install_methods( array( 'GET', 'POST', 'DELETE' ) );
		$first = $this->importer()->run();
		$this->assertSame( Import_Report::RESULT_COMPILED, $first->result, 'Precondition: a saved import.' );
		$rules = $this->arrays_of_rules( Config::instance()->stored_route_rules() );
		$this->count_writes();

		$second = $this->importer()->run();

		$this->assert_nothing_written( $second );
		$this->assertSame( $rules, $this->arrays_of_rules( Config::instance()->stored_route_rules() ) );
		foreach ( Rfrac_Legacy_Fixture::METHOD_ENDPOINTS as $endpoint ) {
			$kept = $this->one( $second, Importer::CODE_ALREADY_IMPORTED, 'wp_rest_cache_allowed_endpoints[acme/v1][' . $endpoint . ']' );
			$this->assertSame( Finding::KEPT, $kept->status );
		}
		$methods = $this->one( $second, Importer::CODE_REQUEST_METHODS );
		$this->assertSame( Finding::ADJUSTED, $methods->status );
		$this->assertStringContainsString( '2 imported routes', $methods->message );
		$this->assertEquals( $this->one( $first, Importer::CODE_REQUEST_METHODS ), $methods, 'The methods finding does not change either.' );
	}

	/**
	 * AC-3: a wp/v2 route, a route with a stored rule and a route locked by RFRAC_ROUTES get no methods.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_v2_stored_and_locked_routes_do_not_get_methods(): void {
		define( 'RFRAC_ROUTES', array( '/acme/v1/write-only' => array( 'status' => 'excluded' ) ) );
		$this->server = $this->new_rest_server( array( 'Rfrac_Legacy_Fixture', 'register_method_routes' ) );
		Rfrac_Legacy_Fixture::install_methods( array( 'GET', 'POST' ) );
		$this->assertTrue( Config::instance()->save_route_rule( '/acme/v1/read-write', array( 'status' => 'enabled', 'ttl' => 120 ) )->is_valid() );
		$before = Config::instance()->stored_route_rules()['/acme/v1/read-write']->to_array();

		$report = $this->importer()->run();

		$this->assertTrue( $report->validation->is_valid() );
		$stored = Config::instance()->stored_route_rules();
		$this->assertSame( $before, $stored['/acme/v1/read-write']->to_array(), 'The stored rule is unchanged.' );
		$this->assertArrayNotHasKey( '/acme/v1/write-only', $stored, 'The locked route gets no stored rule.' );
		$this->assertSame( 'excluded', Config::instance()->constant_route_rules()['/acme/v1/write-only']->status );
		foreach ( $stored as $key => $rule ) {
			$this->assertSame( array( 'GET' ), $rule->methods, $key . ' caches GET only.' );
		}
		$this->assertEqualsCanonicalizing( array( '/acme/v1/read-write', '/acme/v1/read-only' ), array_keys( $stored ) );

		$infos = ( new Route_Discovery() )->discover( $this->server );
		$core  = count(
			array_filter(
				$infos,
				static fn( Route_Info $info ): bool => str_starts_with( $info->route, '/wp/v2/posts' ) && $info->registers( 'POST' )
			)
		);
		$this->assertGreaterThan( 0, $core, 'Precondition: a wp/v2 route registers POST.' );

		$not_applied = $this->one( $report, Importer::CODE_REQUEST_METHODS_NOT_APPLIED );
		$this->assertSame( Finding::SKIPPED, $not_applied->status );
		$this->assertStringContainsString( sprintf( 'POST requests are not cached on %d routes', $core + 2 ), $not_applied->message );
		$this->assertStringContainsString( Config::ROUTES_CONSTANT, $not_applied->message );

		$methods = $this->one( $report, Importer::CODE_REQUEST_METHODS );
		$this->assertSame( Finding::SKIPPED, $methods->status, 'Only the read-only route is imported, and it takes no method.' );
		$this->assertStringContainsString( 'POST', $methods->message );
	}

	/**
	 * AC-4: POST on a site where no imported route registers it is skipped; the other methods stay.
	 */
	public function test_post_without_a_route_taking_it_is_skipped(): void {
		Rfrac_Legacy_Fixture::install_stock();
		update_option( 'wp_rest_cache_allowed_request_methods', array( 'GET', 'POST' ), false );

		$report = $this->importer()->run();

		$methods = $this->one( $report, Importer::CODE_REQUEST_METHODS );
		$this->assertSame( Finding::SKIPPED, $methods->status );
		$this->assertStringContainsString( 'POST', $methods->message );
		foreach ( Config::instance()->stored_route_rules() as $rule ) {
			$this->assertSame( array( 'GET' ), $rule->methods );
		}
		$this->assertSame( Finding::SKIPPED, $this->one( $report, Importer::CODE_REQUEST_METHODS_NOT_APPLIED )->status, 'The wp/v2 routes that register POST are counted.' );
		$this->assertSame( array(), $this->findings_with( $report, Importer::CODE_REQUEST_METHODS_UNSUPPORTED ) );
	}

	/**
	 * AC-4: GET only imports no method and reports no skipped method.
	 */
	public function test_get_only_reports_no_other_method(): void {
		Rfrac_Legacy_Fixture::install_stock();

		$report = $this->importer()->run( true );

		$this->assertSame( array(), $this->findings_with( $report, Importer::CODE_REQUEST_METHODS_UNSUPPORTED ) );
		$this->assertSame( array(), $this->findings_with( $report, Importer::CODE_REQUEST_METHODS_NOT_APPLIED ) );
	}

	/**
	 * AC-8: an enabled regeneration is skipped in one finding.
	 */
	public function test_regeneration_is_skipped(): void {
		Rfrac_Legacy_Fixture::install_stock();
		add_option( 'wp_rest_cache_regenerate', '1' );

		$finding = $this->one( $this->importer()->run( true ), Importer::CODE_REGENERATE );

		$this->assertSame( Finding::SKIPPED, $finding->status );
		$this->assertSame( 'wp_rest_cache_regenerate', $finding->subject );
	}

	/**
	 * AC-9: a hook without a bridge is unsupported, a bridged one keeps working and names its replacement.
	 */
	public function test_hooks_are_reported_as_bridged_or_unsupported(): void {
		Rfrac_Legacy_Fixture::install_stock();
		add_filter( 'wp_rest_cache/settings_panels', '__return_empty_array' );
		add_filter( 'wp_rest_cache/flush_on_set_terms', '__return_true' );
		add_filter( 'wp_rest_cache/skip_caching', '__return_false' );

		$report = $this->importer()->run( true );

		$unsupported = $this->one( $report, Hook_Registry::REASON_ADMIN_UI, 'wp_rest_cache/settings_panels' );
		$this->assertSame( Finding::UNSUPPORTED, $unsupported->status );
		$bridged = $this->one( $report, Importer::CODE_BRIDGED, 'wp_rest_cache/flush_on_set_terms' );
		$this->assertSame( Finding::IMPORTED, $bridged->status );
		$this->assertStringContainsString( Hooks::FLUSH_ON_SET_TERMS, $bridged->message );
		$hit = $this->one( $report, Importer::CODE_HIT_FILTER, 'wp_rest_cache/skip_caching' );
		$this->assertSame( Finding::IMPORTED, $hit->status );
		$this->assertStringContainsString( Hook_Registry::HIT_FILTERS_CONSTANT, $hit->message );
	}

	/**
	 * AC-10: without any option or constant of the original nothing is found and nothing is written.
	 */
	public function test_nothing_is_found_and_nothing_is_written_without_the_original(): void {
		$before = $this->written_options();
		$this->count_writes();

		$report = $this->importer()->run();

		$this->assertSame( Import_Report::RESULT_NOTHING_FOUND, $report->result );
		$this->assertSame( array(), $report->findings );
		$this->assertNull( $report->validation );
		$this->assertSame( 0, $this->settings_updates );
		$this->assertSame( $before, $this->written_options() );
		$this->assertSame( Import_Report::RESULT_NOTHING_FOUND, $this->importer()->run( true )->result );
	}

	/**
	 * AC-10: a partial server leaves the snapshot serving, so the import is deferred.
	 */
	public function test_a_partial_server_defers_the_compile(): void {
		Rfrac_Legacy_Fixture::install_stock();
		Plugin::instance()->compiler()->compile( $this->server );
		$this->assertNotSame( '', Compiled_Config::load()->fingerprint(), 'Precondition: a current snapshot.' );
		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );

		$report = $this->importer()->run();

		$this->assertSame( Import_Report::RESULT_DEFERRED, $report->result );
		$this->assertSame( 31536000, Config::instance()->settings()['max_ttl'], 'The settings were written.' );
		$this->assertSame( Import_Report::RESULT_DEFERRED, ( new Findings() )->get( Findings::GROUP_IMPORT )['meta']['result'] );
	}

	// -----------------------------------------------------------------------------------------
	// The report and the container.
	// -----------------------------------------------------------------------------------------

	public function test_the_report_as_an_array(): void {
		$findings = array(
			new Finding( Finding::IMPORTED, 'default_ttl', 'subject_one', 'One.' ),
			new Finding( Finding::SKIPPED, 'regenerate', 'subject_two', 'Two.' ),
			new Finding( Finding::SKIPPED, 'memcache_used', 'subject_three', 'Three.' ),
		);

		$report = new Import_Report( $findings, Import_Report::RESULT_DRY_RUN, null, true );

		$this->assertSame(
			array(
				'dry_run'  => true,
				'result'   => Import_Report::RESULT_DRY_RUN,
				'counts'   => array(
					Finding::IMPORTED    => 1,
					Finding::KEPT        => 0,
					Finding::ADJUSTED    => 0,
					Finding::SKIPPED     => 2,
					Finding::UNSUPPORTED => 0,
				),
				'findings' => array(
					array(
						'status'  => Finding::IMPORTED,
						'code'    => 'default_ttl',
						'subject' => 'subject_one',
						'message' => 'One.',
					),
					array(
						'status'  => Finding::SKIPPED,
						'code'    => 'regenerate',
						'subject' => 'subject_two',
						'message' => 'Two.',
					),
					array(
						'status'  => Finding::SKIPPED,
						'code'    => 'memcache_used',
						'subject' => 'subject_three',
						'message' => 'Three.',
					),
				),
			),
			$report->to_array()
		);
	}

	public function test_an_unknown_result_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new Import_Report( array(), 'imported' );
	}

	public function test_the_container_builds_one_importer_on_first_use(): void {
		$plugin   = new Plugin();
		$property = new ReflectionProperty( Plugin::class, 'legacy_importer' );
		$this->assertNull( $property->getValue( $plugin ), 'Nothing is built before the first use.' );

		$importer = $plugin->legacy_importer();

		$this->assertInstanceOf( Importer::class, $importer );
		$this->assertSame( $importer, $plugin->legacy_importer() );
	}
}
