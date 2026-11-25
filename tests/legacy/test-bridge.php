<?php
/**
 * Tests for the helper that fires the hooks of the original plugin as deprecated hooks.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Legacy\Bridge;
use Rockfire\Rest_Api_Cache\Legacy\Hook_Registry;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Bridge
 */
class Test_Bridge extends WP_UnitTestCase {

	/**
	 * The arguments of every `deprecated_hook_run` of the test.
	 *
	 * @var list<array{hook:string,replacement:string,version:string,message:string}>
	 */
	private array $deprecated = array();

	public function set_up() {
		parent::set_up();
		delete_option( Failure_Recorder::OPTION );
		$this->deprecated = array();
		add_action(
			'deprecated_hook_run',
			function ( $hook, $replacement, $version, $message ): void {
				$this->deprecated[] = array(
					'hook'        => (string) $hook,
					'replacement' => (string) $replacement,
					'version'     => (string) $version,
					'message'     => (string) $message,
				);
			},
			10,
			4
		);
	}

	public function tear_down() {
		delete_option( Failure_Recorder::OPTION );
		parent::tear_down();
	}

	/**
	 * A callback that throws.
	 *
	 * @return never
	 *
	 * @throws RuntimeException Always.
	 */
	public static function throwing(): never {
		throw new RuntimeException( 'legacy callback failed' );
	}

	// ---------- filter() ----------

	public function test_filter_hands_the_arguments_to_the_callbacks_and_returns_the_filtered_value(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/timeout' );
		$seen = array();
		add_filter(
			'wp_rest_cache/timeout',
			static function ( $timeout, $options ) use ( &$seen ) {
				$seen = array( $timeout, $options );
				return $timeout * 2;
			},
			10,
			2
		);

		$this->assertSame( 600, Bridge::filter( 'wp_rest_cache/timeout', array( 300, array( 'a' => 1 ) ) ) );
		$this->assertSame( array( 300, array( 'a' => 1 ) ), $seen );
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_filter_without_a_callback_returns_the_first_argument_and_raises_no_notice(): void {
		$this->assertSame( 300, Bridge::filter( 'wp_rest_cache/timeout', array( 300, array() ) ) );
		$this->assertSame( array(), $this->deprecated, 'The deprecation notice only fires for a hooked callback.' );
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_a_throwing_filter_callback_leaves_the_value_unchanged_and_is_recorded_under_its_own_name(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/timeout' );
		add_filter( 'wp_rest_cache/timeout', array( self::class, 'throwing' ) );

		$this->assertSame( 300, Bridge::filter( 'wp_rest_cache/timeout', array( 300, array() ) ) );

		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'hook:wp_rest_cache/timeout', $last['context'] );
		$this->assertStringContainsString( 'legacy callback failed', $last['message'] );
	}

	public function test_a_throwing_filter_callback_leaves_an_array_value_unchanged(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/allowed_endpoints' );
		add_filter( 'wp_rest_cache/allowed_endpoints', array( self::class, 'throwing' ) );

		$value = array( 'wp/v2' => array( 'posts' ) );

		$this->assertSame( $value, Bridge::filter( 'wp_rest_cache/allowed_endpoints', array( $value ) ) );
	}

	public function test_the_deprecation_names_the_replacement_and_the_version(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/timeout' );
		add_filter( 'wp_rest_cache/timeout', '__return_zero' );

		Bridge::filter( 'wp_rest_cache/timeout', array( 300, array() ) );

		$this->assertCount( 1, $this->deprecated );
		$this->assertSame( 'wp_rest_cache/timeout', $this->deprecated[0]['hook'] );
		$this->assertSame( Hooks::ENTRY_TTL, $this->deprecated[0]['replacement'] );
		$this->assertSame( 'rfrac/entry_ttl', $this->deprecated[0]['replacement'] );
		$this->assertSame( '1.0.0', $this->deprecated[0]['version'] );
		$this->assertSame( Hook_Registry::DEPRECATED_SINCE, $this->deprecated[0]['version'] );
	}

	public function test_the_deprecation_message_points_to_the_readme_without_the_identifiers_of_the_original(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/timeout' );
		add_filter( 'wp_rest_cache/timeout', '__return_zero' );

		Bridge::filter( 'wp_rest_cache/timeout', array( 300, array() ) );

		$message = $this->deprecated[0]['message'];
		$this->assertStringContainsString( 'migration section', $message );
		$this->assertStringNotContainsStringIgnoringCase( 'wp_rest_cache', $message );
		$this->assertStringNotContainsStringIgnoringCase( 'wp rest cache', $message );
	}

	public function test_a_hook_bridged_without_a_replacement_reports_an_empty_one(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/cache_key' );
		add_filter( 'wp_rest_cache/cache_key', '__return_empty_string' );

		$this->assertSame( '', Bridge::filter( 'wp_rest_cache/cache_key', array( 'abc' ) ) );

		$this->assertCount( 1, $this->deprecated );
		$this->assertSame( '', $this->deprecated[0]['replacement'] );
	}

	public function test_a_concrete_name_of_a_dynamic_hook_resolves_its_replacement(): void {
		$name = 'wp_rest_cache/flush_on_meta_update/post/_price';
		$this->setExpectedDeprecated( $name );
		add_filter( $name, '__return_false' );

		$this->assertFalse( Bridge::filter( $name, array( true, 0, 12, '_price' ) ) );

		$this->assertCount( 1, $this->deprecated );
		$this->assertSame( Hooks::FLUSH_ON_META_UPDATE_KEY, $this->deprecated[0]['replacement'] );
	}

	public function test_filter_rejects_a_hook_that_is_not_bridged(): void {
		$this->expectException( InvalidArgumentException::class );

		Bridge::filter( 'wp_rest_cache/settings_panels', array( array() ) );
	}

	public function test_filter_rejects_a_hook_that_is_not_bridged_also_when_a_callback_is_attached(): void {
		add_filter( 'wp_rest_cache/settings_panels', '__return_empty_array' );

		try {
			Bridge::filter( 'wp_rest_cache/settings_panels', array( array( 'x' ) ) );
			$this->fail( 'A hook that is not bridged must be rejected.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'wp_rest_cache/settings_panels', $e->getMessage() );
		}

		$this->assertSame( array(), $this->deprecated );
	}

	/**
	 * Names that are not a bridged hook of the original.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function names_that_are_not_bridged(): array {
		return array(
			'a hook of the replacement namespace' => array( Hooks::ENTRY_TTL ),
			'an empty name'                       => array( '' ),
			'an unknown hook of the original'     => array( 'wp_rest_cache/unknown' ),
			'a dynamic name missing its key'      => array( 'wp_rest_cache/flush_on_meta_update/post' ),
		);
	}

	/**
	 * @dataProvider names_that_are_not_bridged
	 *
	 * @param string $name The hook name.
	 */
	public function test_filter_and_action_reject_a_name_that_is_not_bridged( string $name ): void {
		foreach ( array( 'filter', 'action' ) as $method ) {
			try {
				Bridge::$method( $name, array( 1 ) );
				$this->fail( "Bridge::$method() must reject '$name'." );
			} catch ( InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'is not bridged', $e->getMessage() );
			}
		}
	}

	// ---------- action() ----------

	public function test_action_runs_the_callbacks_with_the_arguments(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/pre_delete_related_caches' );
		$seen = array();
		add_action(
			'wp_rest_cache/pre_delete_related_caches',
			static function ( $a, $b ) use ( &$seen ): void {
				$seen = array( $a, $b );
			},
			10,
			2
		);

		Bridge::action( 'wp_rest_cache/pre_delete_related_caches', array( 'one', 'two' ) );

		$this->assertSame( array( 'one', 'two' ), $seen );
		$this->assertCount( 1, $this->deprecated );
		$this->assertSame( Hooks::INVALIDATION_TAGS, $this->deprecated[0]['replacement'] );
		$this->assertSame( '1.0.0', $this->deprecated[0]['version'] );
	}

	public function test_action_without_a_callback_raises_no_notice(): void {
		Bridge::action( 'wp_rest_cache/pre_delete_related_caches', array( 'one' ) );

		$this->assertSame( array(), $this->deprecated );
	}

	public function test_a_throwing_action_callback_is_recorded_under_its_own_name_and_does_not_escape(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/pre_delete_related_caches' );
		add_action( 'wp_rest_cache/pre_delete_related_caches', array( self::class, 'throwing' ) );

		Bridge::action( 'wp_rest_cache/pre_delete_related_caches', array( 'one' ) );

		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'hook:wp_rest_cache/pre_delete_related_caches', $last['context'] );
	}

	public function test_action_rejects_a_hook_that_is_not_bridged(): void {
		$this->expectException( InvalidArgumentException::class );

		Bridge::action( 'wp_rest_cache/deleted_caches', array() );
	}
}
