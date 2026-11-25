<?php
/**
 * Tests for the findings about the legacy hooks, recorded on every saved compile, and the locator of callbacks.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Legacy\Callback_Locator;
use Rockfire\Rest_Api_Cache\Legacy\Compile_Folding;
use Rockfire\Rest_Api_Cache\Legacy\Finding;
use Rockfire\Rest_Api_Cache\Legacy\Findings;
use Rockfire\Rest_Api_Cache\Legacy\Hook_Findings;
use Rockfire\Rest_Api_Cache\Legacy\Hook_Registry;
use Rockfire\Rest_Api_Cache\Legacy\Module;
use Rockfire\Rest_Api_Cache\Legacy\Store_Bridge;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Hook_Findings
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Callback_Locator
 */
class Test_Hook_Findings extends Rfrac_Test_Case {

	public function set_up() {
		parent::set_up();
		delete_option( Names::OPTION_LEGACY_FINDINGS );
		delete_option( Failure_Recorder::OPTION );
	}

	public function tear_down() {
		delete_option( Names::OPTION_LEGACY_FINDINGS );
		delete_option( Failure_Recorder::OPTION );
		parent::tear_down();
	}

	/**
	 * The directory of the fixture files, normalised.
	 *
	 * @return string
	 */
	private function fixtures(): string {
		return wp_normalize_path( dirname( __DIR__ ) . '/support/fixtures/hit-filter-files' );
	}

	/**
	 * Load the fixture files whose functions the tests hook.
	 *
	 * @return void
	 */
	private function load_fixtures(): void {
		foreach ( array( 'wp-includes/core-callback', 'mu-plugins/aa-early', 'mu-plugins/zz-late', 'plugins/acme/acme', 'themes/acme/functions', 'plugins/bulk/b1', 'plugins/bulk/b2', 'plugins/bulk/b3', 'plugins/bulk/b4', 'plugins/bulk/b5', 'plugins/bulk/b6' ) as $file ) {
			require_once $this->fixtures() . '/' . $file . '.php';
		}
	}

	/**
	 * A locator over the fixture directories.
	 *
	 * @return Callback_Locator
	 */
	private function locator(): Callback_Locator {
		$base = $this->fixtures();
		return new Callback_Locator( $base . '/wp-includes', $base . '/mu-plugins', $base . '/plugins', $base );
	}

	/**
	 * The path the early-cache file is at: present among the fixtures, or in a directory that does not exist.
	 *
	 * @param bool $installed Whether the file is there.
	 *
	 * @return string
	 */
	private function target( bool $installed = true ): string {
		return $this->fixtures() . ( $installed ? '/mu-plugins/rfrac-early-cache.php' : '/mu-plugins/not-installed/rfrac-early-cache.php' );
	}

	/**
	 * The component over the fixture directories and its own findings store.
	 *
	 * @param bool $installed Whether the early-cache file is there.
	 *
	 * @return Hook_Findings
	 */
	private function component( bool $installed = true ): Hook_Findings {
		$target = $this->target( $installed );
		return new Hook_Findings( new Findings(), $this->locator(), static fn(): string => $target );
	}

	/**
	 * The stored findings of a group.
	 *
	 * @param string $group Group id.
	 *
	 * @return list<Finding>
	 */
	private function stored( string $group ): array {
		$data = ( new Findings() )->get( $group );
		$this->assertNotNull( $data, 'The group ' . $group . ' is stored.' );
		return $data['findings'];
	}

	/**
	 * Assert that a group is not stored at all: an empty result for a group that was never stored writes nothing.
	 *
	 * @param string $group   Group id.
	 * @param string $message Failure message.
	 *
	 * @return void
	 */
	private function assert_not_stored( string $group, string $message = '' ): void {
		$this->assertNull( ( new Findings() )->get( $group ), '' === $message ? 'The group ' . $group . ' is not stored.' : $message );
	}

	/**
	 * `code subject` of every finding, sorted.
	 *
	 * @param list<Finding> $findings Findings.
	 *
	 * @return list<string>
	 */
	private function summary( array $findings ): array {
		$summary = array_map( static fn( Finding $finding ): string => $finding->code . ' ' . $finding->subject, $findings );
		sort( $summary );
		return $summary;
	}

	/**
	 * The one finding about a hook.
	 *
	 * @param list<Finding> $findings Findings.
	 * @param string        $subject  The hook.
	 *
	 * @return Finding
	 */
	private function about( array $findings, string $subject ): Finding {
		$found = array_values( array_filter( $findings, static fn( Finding $finding ): bool => $finding->subject === $subject ) );
		$this->assertCount( 1, $found, 'One finding about ' . $subject . '.' );
		return $found[0];
	}

	/**
	 * Compile the configuration from a fresh REST server through the booted container, as a settings save does.
	 *
	 * @return void
	 */
	private function compile(): void {
		( new Config_Compiler() )->compile( $this->new_rest_server() );
	}

	// ---------- Callback_Locator::callbacks() ----------

	public function test_callbacks_lists_what_is_hooked_in_the_order_it_runs(): void {
		add_filter( 'rfrac_test/hook', '__return_false', 20 );
		add_filter( 'rfrac_test/hook', '__return_true', 10 );

		$this->assertSame( array( '__return_true', '__return_false' ), Callback_Locator::callbacks( 'rfrac_test/hook' ) );
		$this->assertSame( array(), Callback_Locator::callbacks( 'rfrac_test/not_hooked' ) );
	}

	// ---------- Callback_Locator::file() ----------

	public function test_file_finds_the_file_of_every_kind_of_callback(): void {
		$this->load_fixtures();
		$locator  = $this->locator();
		$plugin   = $this->fixtures() . '/plugins/acme/acme.php';
		$instance = new Rfrac_Fixture_Acme_Callbacks();

		$this->assertSame( $plugin, $locator->file( 'rfrac_fixture_plugin_callback' ), 'A function name.' );
		$this->assertSame( $plugin, $locator->file( 'Rfrac_Fixture_Acme_Callbacks::filter_static' ), 'A Class::method string.' );
		$this->assertSame( $plugin, $locator->file( array( 'Rfrac_Fixture_Acme_Callbacks', 'filter_static' ) ), 'A static method.' );
		$this->assertSame( $plugin, $locator->file( array( $instance, 'filter_instance' ) ), 'An instance method.' );
		$this->assertSame( $plugin, $locator->file( $instance ), 'An invokable object.' );
		$this->assertSame( $plugin, $locator->file( rfrac_fixture_plugin_closure() ), 'A closure.' );
		$this->assertSame( wp_normalize_path( __FILE__ ), $locator->file( static fn() => true ), 'A closure of this file.' );
	}

	/**
	 * Values that name no file: an internal function, something that does not exist, something that is no callback.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function callbacks_without_a_file(): array {
		return array(
			'an internal function'          => array( 'strlen' ),
			'a function that does not exist' => array( 'rfrac_fixture_no_such_function' ),
			'a string of a missing class'    => array( 'Rfrac_Fixture_No_Such_Class::method' ),
			'an array of a missing class'    => array( array( 'Rfrac_Fixture_No_Such_Class', 'method' ) ),
			'a missing method'               => array( array( 'Test_Hook_Findings', 'no_such_method' ) ),
			'an object that is not invokable' => array( new stdClass() ),
			'a number'                       => array( 42 ),
			'an array of numbers'            => array( array( 1, 2 ) ),
			'an empty array'                 => array( array() ),
			'null'                           => array( null ),
		);
	}

	/**
	 * @dataProvider callbacks_without_a_file
	 *
	 * @param mixed $callback The value.
	 */
	public function test_file_is_null_for_a_value_that_names_no_file( $callback ): void {
		$this->assertNull( $this->locator()->file( $callback ) );
	}

	// ---------- Callback_Locator::classify() ----------

	public function test_classify_tells_where_a_callback_is_loaded_when_the_early_file_is_installed(): void {
		$this->load_fixtures();
		$locator = $this->locator();
		$target  = $this->target();

		$this->assertSame( Callback_Locator::EARLY, $locator->classify( 'rfrac_fixture_core_callback', $target ), 'Core.' );
		$this->assertSame( Callback_Locator::EARLY, $locator->classify( 'rfrac_fixture_aa_early', $target ), 'A must-use file that sorts before the early file.' );
		$this->assertSame( Callback_Locator::LOAD_ORDER, $locator->classify( 'rfrac_fixture_zz_late', $target ), 'A must-use file that sorts after it.' );
		$this->assertSame( Callback_Locator::FALLBACK_ONLY, $locator->classify( 'rfrac_fixture_plugin_callback', $target ), 'A plugin.' );
		$this->assertSame( Callback_Locator::FALLBACK_ONLY, $locator->classify( array( new Rfrac_Fixture_Acme_Callbacks(), 'filter_instance' ), $target ), 'A plugin method.' );
		$this->assertSame( Callback_Locator::NOT_ON_HITS, $locator->classify( 'rfrac_fixture_theme_callback', $target ), 'A theme.' );
		$this->assertSame( Callback_Locator::NOT_ON_HITS, $locator->classify( static fn() => true, $target ), 'A file outside every directory.' );
		$this->assertSame( Callback_Locator::NOT_ON_HITS, $locator->classify( 'strlen', $target ), 'A callback without a file.' );
	}

	public function test_without_the_installed_file_must_use_and_plugin_callbacks_count_as_applied(): void {
		$this->load_fixtures();
		$locator = $this->locator();

		$this->assertSame( Callback_Locator::EARLY, $locator->classify( 'rfrac_fixture_core_callback', null ) );
		$this->assertSame( Callback_Locator::EARLY, $locator->classify( 'rfrac_fixture_aa_early', null ) );
		$this->assertSame( Callback_Locator::EARLY, $locator->classify( 'rfrac_fixture_zz_late', null ) );
		$this->assertSame( Callback_Locator::EARLY, $locator->classify( 'rfrac_fixture_plugin_callback', null ) );
		$this->assertSame( Callback_Locator::NOT_ON_HITS, $locator->classify( 'rfrac_fixture_theme_callback', null ), 'Only the theme remains.' );
	}

	public function test_a_must_use_file_sorts_byte_wise_against_the_name_of_the_early_file(): void {
		$this->load_fixtures();
		$locator = $this->locator();

		$this->assertSame( Callback_Locator::EARLY, $locator->classify( 'rfrac_fixture_aa_early', '/mu/aa.php' ), '"-" sorts before ".".' );
		$this->assertSame( Callback_Locator::LOAD_ORDER, $locator->classify( 'rfrac_fixture_aa_early', '/mu/a.php' ), '"a" sorts before "-a".' );
		$this->assertSame( Callback_Locator::LOAD_ORDER, $locator->classify( 'rfrac_fixture_aa_early', '/mu/B.php' ), 'An upper case letter sorts before every lower case one.' );
		$this->assertSame( Callback_Locator::EARLY, $locator->classify( 'rfrac_fixture_zz_late', '/mu/zzz.php' ) );
	}

	public function test_display_shows_a_file_relative_to_the_content_directory(): void {
		$locator = $this->locator();

		$this->assertSame( 'plugins/acme/acme.php', $locator->display( $this->fixtures() . '/plugins/acme/acme.php' ) );
	}

	public function test_display_shows_a_file_outside_the_content_directory_as_its_tail_only(): void {
		$locator = $this->locator();

		$this->assertSame( "\u{2026}/somewhere/else.php", $locator->display( '/somewhere/else.php' ) );
		$this->assertSame( "\u{2026}/deploy/else.php", $locator->display( 'C:/srv/deploy/else.php' ) );
		$this->assertSame( "\u{2026}/else.php", $locator->display( 'C:/else.php' ) );
		$this->assertSame( "\u{2026}/" . basename( $this->fixtures() ) . '-old/else.php', $locator->display( $this->fixtures() . '-old/else.php' ) );
	}

	// ---------- Hook_Findings ----------

	public function test_it_records_on_the_compiled_action(): void {
		$this->assertSame( array( array( Hooks::COMPILED, 'record', 10, 1 ) ), Hook_Findings::hooks() );
		$this->assertContains( Hooks::COMPILED, array_column( Plugin::instance()->legacy_hooks(), 'hook' ) );
	}

	public function test_each_reason_of_the_registry_has_a_message_of_its_own(): void {
		$hooked = array(
			'wp_rest_cache/settings_panels'          => Hook_Registry::REASON_ADMIN_UI,
			'wp_rest_cache/cache_hit_recording'      => Hook_Registry::REASON_REMOVED,
			'wp_rest_cache/cache_headers'            => Hook_Registry::REASON_NO_SEAM,
			'wp_rest_cache/allowed_request_methods'  => Hook_Registry::REASON_NO_SEAM,
		);
		foreach ( array_keys( $hooked ) as $name ) {
			add_filter( $name, '__return_true' );
		}

		$this->component()->record();

		$findings = $this->stored( Findings::GROUP_HOOKS );
		$this->assertCount( 4, $findings );
		$messages = array();
		foreach ( $hooked as $name => $reason ) {
			$finding = $this->about( $findings, $name );
			$this->assertSame( Finding::UNSUPPORTED, $finding->status );
			$this->assertSame( $reason, $finding->code );
			$this->assertStringStartsWith( $name, $finding->message );
			$messages[] = $finding->message;
		}
		$this->assertCount( 4, array_unique( $messages ), 'Four messages.' );
		$this->assertNull( Failure_Recorder::last(), 'Findings are not failures.' );
	}

	public function test_a_bridged_hook_and_a_hook_without_callbacks_give_no_finding(): void {
		add_filter( 'wp_rest_cache/timeout', '__return_true' );
		add_filter( 'wp_rest_cache/allowed_endpoints', '__return_true' );

		$this->component()->record();

		$this->assert_not_stored( Findings::GROUP_HOOKS );
		$this->assert_not_stored( Findings::GROUP_LOAD_ORDER );
	}

	/**
	 * AC-7: a hooked cache_key means nothing is stored without the constant, and the finding names the constant.
	 */
	public function test_without_the_constant_a_hooked_cache_key_is_reported_as_refusing_to_store_with_the_constant_to_define(): void {
		$this->assertFalse( defined( Hook_Registry::HIT_FILTERS_CONSTANT ), 'Precondition.' );
		add_filter( 'wp_rest_cache/cache_key', static fn( $key ) => $key . '-x' );

		$this->component()->record();

		$findings = $this->stored( Findings::GROUP_HOOKS );
		$this->assertSame( array( 'hit_filter_store_refused wp_rest_cache/cache_key' ), $this->summary( $findings ) );
		$this->assertSame( Hook_Findings::CODE_STORE_REFUSED, $findings[0]->code );
		$this->assertSame( Finding::UNSUPPORTED, $findings[0]->status );
		$this->assertStringContainsString( 'RFRAC_LEGACY_HIT_FILTERS', $findings[0]->message );
		$this->assertSame( 'wp_rest_cache/cache_key is hooked, so responses are not stored: a cache hit cannot apply it. Define RFRAC_LEGACY_HIT_FILTERS to apply it on cache hits, or remove the callback, then flush the cache once.', $findings[0]->message );
		$this->assert_not_stored( Findings::GROUP_LOAD_ORDER );
	}

	/**
	 * AC-6: a hit filter that does not stop storing keeps the finding that says it is not applied.
	 */
	public function test_without_the_constant_pre_output_headers_alone_is_only_reported_as_not_applied(): void {
		add_filter( 'wp_rest_cache/pre_output_headers', '__return_empty_array' );

		$this->component()->record();

		$findings = $this->stored( Findings::GROUP_HOOKS );
		$this->assertSame( array( 'hit_filter_not_applied wp_rest_cache/pre_output_headers' ), $this->summary( $findings ) );
		$this->assertSame( Hook_Findings::CODE_HIT_FILTER_NOT_APPLIED, $findings[0]->code );
		$this->assertSame( 'wp_rest_cache/pre_output_headers is hooked but not applied on cache hits; define RFRAC_LEGACY_HIT_FILTERS to apply it.', $findings[0]->message );
	}

	public function test_without_the_constant_every_request_dependent_hit_filter_is_reported_and_the_folded_ones_are_not(): void {
		add_filter( 'wp_rest_cache/cache_key', '__return_empty_string' );
		add_filter( 'wp_rest_cache/filter_cache_output', '__return_empty_array' );
		add_filter( 'wp_rest_cache/pre_output_headers', '__return_empty_array' );
		add_filter( 'wp_rest_cache/skip_caching', static fn() => true );
		add_filter( 'wp_rest_cache/allow_skip_cache', static fn() => true );
		add_filter( 'wp_rest_cache/disable_cors_headers', static fn() => true );

		$this->component()->record();

		$this->assertSame(
			array(
				'hit_filter_not_applied wp_rest_cache/pre_output_headers',
				'hit_filter_not_applied wp_rest_cache/skip_caching',
				'hit_filter_store_refused wp_rest_cache/cache_key',
				'hit_filter_store_refused wp_rest_cache/filter_cache_output',
			),
			$this->summary( $this->stored( Findings::GROUP_HOOKS ) )
		);
	}

	/**
	 * AC-7: findings rebuilt on `rfrac/compiled` carry the refusal for both filters, each naming the hook and the constant.
	 */
	public function test_a_compile_reports_both_store_refusing_hit_filters_with_the_hook_and_the_constant(): void {
		$this->assertInstanceOf( Module::class, Plugin::instance()->legacy(), 'Precondition.' );
		$this->assertFalse( defined( Hook_Registry::HIT_FILTERS_CONSTANT ), 'Precondition.' );
		add_filter( 'wp_rest_cache/cache_key', static fn( $key ) => $key . '-x' );
		add_filter( 'wp_rest_cache/filter_cache_output', static fn( $output ) => $output );

		$this->compile();

		$findings = $this->stored( Findings::GROUP_HOOKS );
		$this->assertSame(
			array(
				'hit_filter_store_refused wp_rest_cache/cache_key',
				'hit_filter_store_refused wp_rest_cache/filter_cache_output',
			),
			$this->summary( $findings )
		);
		foreach ( $findings as $finding ) {
			$this->assertSame( Finding::UNSUPPORTED, $finding->status );
			$this->assertStringContainsString( 'RFRAC_LEGACY_HIT_FILTERS', $finding->message );
			$this->assertStringContainsString( $finding->subject, $finding->message );
		}
		$this->assertNull( Failure_Recorder::last(), 'Findings are not failures.' );
	}

	/**
	 * A callback added by a code edit or a manual update changes no fingerprint, so no compile records it: the first
	 * store it refuses does, naming the hook and the constant.
	 */
	public function test_the_first_refused_store_records_the_finding_without_a_compile(): void {
		$this->assertInstanceOf( Module::class, Plugin::instance()->legacy(), 'Precondition.' );
		$this->assertFalse( defined( Hook_Registry::HIT_FILTERS_CONSTANT ), 'Precondition.' );
		$compiled = 0;
		add_action(
			Hooks::COMPILED,
			static function () use ( &$compiled ): void {
				++$compiled;
			}
		);
		add_filter( 'wp_rest_cache/cache_key', static fn( $key ) => $key . '-x' );
		$this->assert_not_stored( Findings::GROUP_HOOKS, 'Precondition: nothing is recorded before the store.' );

		$this->assertFalse( apply_filters( Hooks::STORE_ALLOWED, true, '/wp/v2/posts', '/wp/v2/posts', 10 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.

		$this->assertSame( 0, $compiled, 'No compile ran.' );
		$findings = $this->stored( Findings::GROUP_HOOKS );
		$this->assertSame( array( 'hit_filter_store_refused wp_rest_cache/cache_key' ), $this->summary( $findings ) );
		$this->assertSame( Finding::UNSUPPORTED, $findings[0]->status );
		$this->assertSame( 'wp_rest_cache/cache_key is hooked, so responses are not stored: a cache hit cannot apply it. Define RFRAC_LEGACY_HIT_FILTERS to apply it on cache hits, or remove the callback, then flush the cache once.', $findings[0]->message );
		$this->assertNull( Failure_Recorder::last(), 'Findings are not failures.' );
	}

	public function test_a_refused_store_records_each_hooked_store_refusing_filter(): void {
		add_filter( 'wp_rest_cache/cache_key', '__return_empty_string' );
		add_filter( 'wp_rest_cache/filter_cache_output', '__return_empty_array' );
		add_filter( 'wp_rest_cache/pre_output_headers', '__return_empty_array' );

		$this->assertTrue( Store_Bridge::refuses_store() );

		$this->assertSame(
			array(
				'hit_filter_store_refused wp_rest_cache/cache_key',
				'hit_filter_store_refused wp_rest_cache/filter_cache_output',
			),
			$this->summary( $this->stored( Findings::GROUP_HOOKS ) ),
			'Only the filters that stop storing are reported by a store.'
		);
	}

	/**
	 * A refused store costs no write once its finding is recorded, and a store nothing refuses records nothing.
	 */
	public function test_a_refused_store_writes_the_finding_only_when_it_is_not_recorded_yet(): void {
		$writes = 0;
		add_filter(
			'pre_update_option_' . Names::OPTION_LEGACY_FINDINGS,
			static function ( $value ) use ( &$writes ) {
				++$writes;
				return $value;
			}
		);

		$this->assertFalse( Store_Bridge::refuses_store() );
		$this->assertSame( 0, $writes, 'A store that is not refused writes nothing.' );
		$this->assert_not_stored( Findings::GROUP_HOOKS );

		add_filter( 'wp_rest_cache/cache_key', '__return_empty_string' );
		$this->assertTrue( Store_Bridge::refuses_store() );
		$this->assertSame( 1, $writes, 'The first refused store writes the finding.' );

		$this->assertTrue( Store_Bridge::refuses_store() );
		$this->assertTrue( Store_Bridge::refuses_store() );
		$this->assertSame( 1, $writes, 'A later refused store writes nothing.' );
		$this->assertSame( array( 'hit_filter_store_refused wp_rest_cache/cache_key' ), $this->summary( $this->stored( Findings::GROUP_HOOKS ) ) );
	}

	public function test_a_finding_recorded_by_a_refused_store_goes_with_the_callback_at_the_next_compile(): void {
		add_filter( 'wp_rest_cache/cache_key', '__return_empty_string' );
		$this->assertTrue( Store_Bridge::refuses_store() );
		$this->assertCount( 1, $this->stored( Findings::GROUP_HOOKS ) );

		$this->compile();

		$this->assertSame( array( 'hit_filter_store_refused wp_rest_cache/cache_key' ), $this->summary( $this->stored( Findings::GROUP_HOOKS ) ), 'The compile rebuilds the same finding.' );

		remove_filter( 'wp_rest_cache/cache_key', '__return_empty_string' );
		$this->compile();

		$this->assertSame( array(), $this->stored( Findings::GROUP_HOOKS ) );
	}

	public function test_a_failing_write_of_the_finding_does_not_lift_the_refusal(): void {
		add_filter( 'wp_rest_cache/cache_key', '__return_empty_string' );
		add_filter(
			'pre_update_option_' . Names::OPTION_LEGACY_FINDINGS,
			static function (): never {
				throw new RuntimeException( 'The database is gone.' );
			}
		);

		$this->assertTrue( Store_Bridge::refuses_store() );
		$this->assertFalse( ( new Store_Bridge() )->refuse_unapplied_hit_filters( true ) );
	}

	/**
	 * With the constant the hit filters are applied, so a store is not refused and nothing is recorded.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_with_the_constant_nothing_is_refused_and_nothing_is_recorded(): void {
		define( Hook_Registry::HIT_FILTERS_CONSTANT, true );
		add_filter( 'wp_rest_cache/cache_key', '__return_empty_string' );

		$this->assertFalse( Store_Bridge::refuses_store() );
		Hook_Findings::record_store_refusal( new Findings() );

		$this->assert_not_stored( Findings::GROUP_HOOKS );
	}

	public function test_a_constant_skip_caching_is_folded_and_not_reported(): void {
		add_filter( 'wp_rest_cache/skip_caching', '__return_true' );

		$this->component()->record();

		$this->assert_not_stored( Findings::GROUP_HOOKS );
	}

	/**
	 * AC-6: a skip_nonce_caching callback that depends on the request is not carried over, and says where to go.
	 */
	public function test_a_skip_nonce_caching_closure_is_reported_as_not_carried_over(): void {
		$this->assertFalse( defined( Hook_Registry::HIT_FILTERS_CONSTANT ), 'Precondition.' );
		add_filter( 'wp_rest_cache/skip_nonce_caching', static fn() => false );

		$this->component()->record();

		$findings = $this->stored( Findings::GROUP_HOOKS );
		$this->assertSame( array( 'nonce_not_carried_over wp_rest_cache/skip_nonce_caching' ), $this->summary( $findings ) );
		$this->assertSame( Hook_Findings::CODE_NONCE_NOT_CARRIED_OVER, $findings[0]->code );
		$this->assertSame( Finding::UNSUPPORTED, $findings[0]->status );
		$this->assertStringStartsWith( 'wp_rest_cache/skip_nonce_caching', $findings[0]->message );
		$this->assertStringContainsString( 'Settings', $findings[0]->message, 'It points to the setting.' );
		$this->assert_not_stored( Findings::GROUP_LOAD_ORDER );
		$this->assertNull( Failure_Recorder::last(), 'Findings are not failures.' );
	}

	public function test_one_callback_that_is_not_constant_is_enough_to_report_skip_nonce_caching(): void {
		add_filter( 'wp_rest_cache/skip_nonce_caching', '__return_false' );
		add_filter( 'wp_rest_cache/skip_nonce_caching', static fn( $skip ) => $skip, 20 );

		$this->component()->record();

		$this->assertSame( array( 'nonce_not_carried_over wp_rest_cache/skip_nonce_caching' ), $this->summary( $this->stored( Findings::GROUP_HOOKS ) ) );
	}

	/**
	 * AC-6: a constant callback is carried over by the compile folding, so nothing is reported.
	 */
	public function test_a_constant_skip_nonce_caching_is_carried_over_and_not_reported(): void {
		foreach ( Compile_Folding::CONSTANT_CALLBACKS as $callback ) {
			add_filter( 'wp_rest_cache/skip_nonce_caching', $callback );
		}

		$this->component()->record();

		$this->assert_not_stored( Findings::GROUP_HOOKS );
	}

	/**
	 * AC-6: a saved compile reports the closure, whether or not the hit filters are applied.
	 */
	public function test_a_compile_reports_a_skip_nonce_caching_closure_and_forgets_it_when_it_is_removed(): void {
		$this->assertInstanceOf( Module::class, Plugin::instance()->legacy(), 'Precondition.' );
		$closure = static fn() => false;
		add_filter( 'wp_rest_cache/skip_nonce_caching', $closure );

		$this->compile();

		$this->assertSame( array( 'nonce_not_carried_over wp_rest_cache/skip_nonce_caching' ), $this->summary( $this->stored( Findings::GROUP_HOOKS ) ) );
		$this->assertCount( 1, ( new Findings() )->problems() );

		remove_filter( 'wp_rest_cache/skip_nonce_caching', $closure );
		$this->compile();

		$this->assertSame( array(), $this->stored( Findings::GROUP_HOOKS ) );
	}

	/**
	 * AC-6: with the constant the hit filters are applied, but a skip_nonce_caching closure is still not carried over.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_with_the_constant_a_skip_nonce_caching_closure_is_still_reported(): void {
		define( Hook_Registry::HIT_FILTERS_CONSTANT, true );
		add_filter( 'wp_rest_cache/skip_nonce_caching', static fn() => false );
		add_filter( 'wp_rest_cache/cache_key', '__return_empty_string' );

		$this->compile();

		$this->assertSame( array( 'nonce_not_carried_over wp_rest_cache/skip_nonce_caching' ), $this->summary( $this->stored( Findings::GROUP_HOOKS ) ) );
	}

	public function test_a_finding_goes_when_its_callback_is_removed_and_the_findings_are_recorded_again(): void {
		$component = $this->component();
		add_filter( 'wp_rest_cache/cache_key', '__return_empty_string' );
		add_filter( 'wp_rest_cache/cache_headers', '__return_true' );
		$component->record();
		$this->assertCount( 2, $this->stored( Findings::GROUP_HOOKS ) );

		remove_filter( 'wp_rest_cache/cache_key', '__return_empty_string' );
		$component->record();
		$this->assertSame( array( 'no_seam wp_rest_cache/cache_headers' ), $this->summary( $this->stored( Findings::GROUP_HOOKS ) ) );

		remove_filter( 'wp_rest_cache/cache_headers', '__return_true' );
		$component->record();
		$this->assertSame( array(), $this->stored( Findings::GROUP_HOOKS ) );
	}

	/**
	 * AC-4: through a compile of the booted container, both unsupported hooks appear and then disappear.
	 */
	public function test_a_compile_records_the_unsupported_hooks_and_the_next_one_forgets_a_removed_callback(): void {
		$this->assertInstanceOf( Module::class, Plugin::instance()->legacy(), 'Precondition.' );
		add_filter( 'wp_rest_cache/cache_headers', '__return_true' );
		add_filter( 'wp_rest_cache/allowed_request_methods', '__return_true' );

		$this->compile();

		$findings = $this->stored( Findings::GROUP_HOOKS );
		$this->assertSame( array( 'no_seam wp_rest_cache/allowed_request_methods', 'no_seam wp_rest_cache/cache_headers' ), $this->summary( $findings ) );
		foreach ( $findings as $finding ) {
			$this->assertSame( Finding::UNSUPPORTED, $finding->status );
		}
		$this->assertCount( 2, ( new Findings() )->problems() );
		$this->assertNull( Failure_Recorder::last() );

		remove_filter( 'wp_rest_cache/cache_headers', '__return_true' );
		remove_filter( 'wp_rest_cache/allowed_request_methods', '__return_true' );
		$this->compile();

		$this->assertSame( array(), $this->stored( Findings::GROUP_HOOKS ) );
		$this->assertSame( array(), ( new Findings() )->problems() );
	}

	/**
	 * AC-8: without legacy callbacks both groups are empty: neither is stored, so the site keeps no finding.
	 */
	public function test_a_compile_without_legacy_callbacks_stores_no_group(): void {
		$this->compile();

		$this->assert_not_stored( Findings::GROUP_HOOKS );
		$this->assert_not_stored( Findings::GROUP_LOAD_ORDER );
		$this->assertSame( array(), ( new Findings() )->all(), 'A site without legacy callbacks keeps no finding.' );
		$this->assertNull( Failure_Recorder::last() );
	}

	/**
	 * AC-7: a recompile that finds the fingerprint unchanged saves nothing, so the findings are not rewritten.
	 */
	public function test_a_recompile_with_an_unchanged_fingerprint_does_not_rewrite_the_findings(): void {
		$compiler = new Config_Compiler();
		$server   = $this->new_rest_server();
		$compiler->compile( $server );
		add_filter( 'wp_rest_cache/cache_headers', '__return_true' );
		$writes   = 0;
		$compiled = 0;
		add_filter(
			'pre_update_option_' . Names::OPTION_LEGACY_FINDINGS,
			static function ( $value ) use ( &$writes ) {
				++$writes;
				return $value;
			}
		);
		add_action(
			Hooks::COMPILED,
			static function () use ( &$compiled ): void {
				++$compiled;
			}
		);
		delete_transient( Config_Compiler::CHECK_TRANSIENT );

		$this->assertFalse( $compiler->maybe_recompile_for_server( $server, true ), 'The periodic check finds the fingerprint unchanged.' );

		$this->assertSame( 0, $compiled, 'No compile was saved.' );
		$this->assertSame( 0, $writes, 'The findings were not written.' );
		$this->assert_not_stored( Findings::GROUP_HOOKS, 'The callback hooked after the compile is not reported yet.' );

		$compiler->compile( $server );

		$this->assertSame( 1, $compiled );
		$this->assertSame( array( 'no_seam wp_rest_cache/cache_headers' ), $this->summary( $this->stored( Findings::GROUP_HOOKS ) ) );
	}

	/**
	 * The constant set to false does not apply the hit filters.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_constant_that_is_false_counts_as_not_defined(): void {
		define( Hook_Registry::HIT_FILTERS_CONSTANT, false );
		$this->load_fixtures();
		add_filter( 'wp_rest_cache/cache_key', 'rfrac_fixture_zz_late' );

		$this->component()->record();

		$this->assertSame( array( 'hit_filter_store_refused wp_rest_cache/cache_key' ), $this->summary( $this->stored( Findings::GROUP_HOOKS ) ) );
		$this->assert_not_stored( Findings::GROUP_LOAD_ORDER );
	}

	/**
	 * AC-6: with the constant, the callbacks a hit does not have loaded are reported with their files.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_with_the_constant_the_callbacks_a_hit_has_not_loaded_are_reported_with_their_files(): void {
		define( Hook_Registry::HIT_FILTERS_CONSTANT, true );
		$this->load_fixtures();
		add_filter( 'wp_rest_cache/cache_key', 'rfrac_fixture_zz_late' );
		add_filter( 'wp_rest_cache/filter_cache_output', 'rfrac_fixture_plugin_callback' );
		add_filter( 'wp_rest_cache/pre_output_headers', 'rfrac_fixture_theme_callback' );
		add_filter( 'wp_rest_cache/skip_caching', 'rfrac_fixture_aa_early' );
		add_filter( 'wp_rest_cache/disable_cors_headers', 'rfrac_fixture_core_callback' );
		add_filter( 'wp_rest_cache/allow_skip_cache', 'rfrac_fixture_aa_early' );

		$this->component()->record();

		$findings = $this->stored( Findings::GROUP_LOAD_ORDER );
		$this->assertSame(
			array(
				'fallback_only wp_rest_cache/filter_cache_output',
				'load_order wp_rest_cache/cache_key',
				'not_on_hits wp_rest_cache/pre_output_headers',
			),
			$this->summary( $findings )
		);
		foreach ( $findings as $finding ) {
			$this->assertSame( Finding::UNSUPPORTED, $finding->status );
		}
		$late = $this->about( $findings, 'wp_rest_cache/cache_key' )->message;
		$this->assertStringContainsString( 'mu-plugins/zz-late.php', $late );
		$this->assertStringContainsString( 'rfrac-early-cache.php', $late );
		$this->assertStringContainsString( 'plugins/acme/acme.php', $this->about( $findings, 'wp_rest_cache/filter_cache_output' )->message );
		$this->assertStringContainsString( 'themes/acme/functions.php', $this->about( $findings, 'wp_rest_cache/pre_output_headers' )->message );
		foreach ( $findings as $finding ) {
			$this->assertStringNotContainsString( 'aa-early', $finding->message, 'A file that loads earlier gives no finding.' );
			$this->assertStringNotContainsString( 'core-callback', $finding->message, 'A core file gives no finding.' );
		}
		$this->assert_not_stored( Findings::GROUP_HOOKS, 'The hit filters are applied, so none is reported as not applied.' );
		$this->assertNull( Failure_Recorder::last() );
	}

	/**
	 * A callback defined outside the content directory is reported by the tail of its path, never by its directory.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_callback_outside_the_content_directory_is_reported_without_its_absolute_directory(): void {
		define( Hook_Registry::HIT_FILTERS_CONSTANT, true );
		add_filter( 'wp_rest_cache/pre_output_headers', static fn() => true );

		$this->component()->record();

		$message = $this->about( $this->stored( Findings::GROUP_LOAD_ORDER ), 'wp_rest_cache/pre_output_headers' )->message;
		$this->assertStringContainsString( "\u{2026}/legacy/test-hook-findings.php", $message );
		$this->assertStringNotContainsString( wp_normalize_path( __DIR__ ), $message );
		$this->assertStringNotContainsString( wp_normalize_path( dirname( __DIR__, 2 ) ), $message );
	}

	/**
	 * Without the early-cache file every hit is served after the plugins have loaded.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_with_the_constant_but_no_early_file_only_the_theme_callback_is_reported(): void {
		define( Hook_Registry::HIT_FILTERS_CONSTANT, true );
		$this->load_fixtures();
		add_filter( 'wp_rest_cache/cache_key', 'rfrac_fixture_zz_late' );
		add_filter( 'wp_rest_cache/filter_cache_output', 'rfrac_fixture_plugin_callback' );
		add_filter( 'wp_rest_cache/pre_output_headers', 'rfrac_fixture_theme_callback' );

		$this->component( false )->record();

		$this->assertSame( array( 'not_on_hits wp_rest_cache/pre_output_headers' ), $this->summary( $this->stored( Findings::GROUP_LOAD_ORDER ) ) );
	}

	/**
	 * A message lists at most five files, each once.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_message_lists_at_most_five_files_and_each_file_once(): void {
		define( Hook_Registry::HIT_FILTERS_CONSTANT, true );
		$this->load_fixtures();
		foreach ( range( 1, 6 ) as $number ) {
			add_filter( 'wp_rest_cache/cache_key', 'rfrac_fixture_bulk_' . $number );
		}
		add_filter( 'wp_rest_cache/cache_key', 'rfrac_fixture_bulk_1', 20 );
		add_filter( 'wp_rest_cache/filter_cache_output', 'rfrac_fixture_plugin_callback' );
		add_filter( 'wp_rest_cache/filter_cache_output', array( new Rfrac_Fixture_Acme_Callbacks(), 'filter_instance' ) );

		$this->component()->record();

		$findings = $this->stored( Findings::GROUP_LOAD_ORDER );
		$many     = $this->about( $findings, 'wp_rest_cache/cache_key' )->message;
		foreach ( range( 1, 5 ) as $number ) {
			$this->assertStringContainsString( 'plugins/bulk/b' . $number . '.php', $many );
		}
		$this->assertStringNotContainsString( 'plugins/bulk/b6.php', $many, 'The sixth file is cut off.' );
		$this->assertSame( 1, substr_count( $many, 'plugins/bulk/b1.php' ), 'A file is listed once.' );
		$this->assertStringEndsWith( '…', $many );

		$one = $this->about( $findings, 'wp_rest_cache/filter_cache_output' )->message;
		$this->assertSame( 1, substr_count( $one, 'plugins/acme/acme.php' ) );
		$this->assertStringEndsNotWith( '…', $one );
	}

	/**
	 * Both groups are rebuilt on a compile, and a callback that is removed leaves no finding behind.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_with_the_constant_a_compile_rebuilds_the_load_order_group(): void {
		define( Hook_Registry::HIT_FILTERS_CONSTANT, true );
		$this->load_fixtures();
		add_filter( 'wp_rest_cache/pre_output_headers', 'rfrac_fixture_theme_callback' );

		$this->compile();

		$this->assertSame( array( 'not_on_hits wp_rest_cache/pre_output_headers' ), $this->summary( $this->stored( Findings::GROUP_LOAD_ORDER ) ) );

		remove_filter( 'wp_rest_cache/pre_output_headers', 'rfrac_fixture_theme_callback' );
		$this->compile();

		$this->assertSame( array(), $this->stored( Findings::GROUP_LOAD_ORDER ) );
	}
}
