<?php
/**
 * Tests for the Site Health tests and debug information (#47).
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Admin;
use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Health\Site_Health;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Install\Mu_Installer;
use Rockfire\Rest_Api_Cache\Install\Mu_Status;
use Rockfire\Rest_Api_Cache\Maintenance\Scheduler;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Schema\Migrator;
use Rockfire\Rest_Api_Cache\Schema\Schema;
use Rockfire\Rest_Api_Cache\Admin\Pages\Routes_Page;
use Rockfire\Rest_Api_Cache\Services\Cache_Service;
use Rockfire\Rest_Api_Cache\Services\Route_Service;
use Rockfire\Rest_Api_Cache\Services\Route_View;
use Rockfire\Rest_Api_Cache\Services\Status;
use Rockfire\Rest_Api_Cache\Services\Status_Service;
use Rockfire\Rest_Api_Cache\Storage\Database_Store;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Health\Site_Health
 */
class Test_Site_Health extends Rfrac_Test_Case {

	private const KEYS = array( 'rfrac_mu_mode', 'rfrac_tables', 'rfrac_cleanup_backlog', 'rfrac_object_cache' );

	public function tear_down() {
		remove_all_filters( Hooks::HEALTH_BACKLOG_THRESHOLD );
		delete_option( Failure_Recorder::OPTION );
		delete_option( Migrator::ERROR_OPTION );
		wp_clear_scheduled_hook( Scheduler::HOOK );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * A Site_Health over a stubbed snapshot.
	 *
	 * @param array<string,mixed> $override Status fields to override.
	 *
	 * @return Site_Health
	 */
	private function health( array $override = array() ): Site_Health {
		$base   = array(
			'plugin_version'        => '1.0.0',
			'db_version'            => 1,
			'db_version_expected'   => 1,
			'tables_present'        => true,
			'mu_status'             => Mu_Status::CURRENT,
			'mu_mode'               => 'early',
			'mu_last_result'        => null,
			'mu_target_path'        => '/wp-content/mu-plugins/rfrac.php',
			'mu_template_path'      => '/plugin/mu-plugin/rfrac.php',
			'mu_dir_writable'       => true,
			'store_type'            => 'database',
			'object_cache_detected' => false,
			'counts'                => array(
				'active'  => 3,
				'flushed' => 1,
				'expired' => 2,
				'total'   => 6,
				'size'    => 2048,
			),
			'total_size'            => 2048,
			'max_entries'           => 500,
			'backlog'               => 3,
			'last_maintenance'      => null,
			'next_maintenance'      => time() + 600,
			'cron_disabled'         => false,
			'coexistence_blocked'   => false,
			'locked_settings'       => array(),
			'last_error'            => null,
			'migration_error'       => null,
		);
		$status = new Status( ...array_merge( $base, $override ) );
		$stub   = new class( $status ) extends Status_Service {
			/**
			 * Snapshot.
			 *
			 * @var Status
			 */
			private Status $status;

			public function __construct( Status $status ) {
				$this->status = $status;
			}

			public function get( bool $check_tables = false ): Status {
				return $this->status;
			}
		};
		return new Site_Health( $stub );
	}

	private function real_health(): Site_Health {
		$repository = new Repository();
		$store      = new Database_Store( $repository, 1 );
		$cache      = new Cache_Service( $repository, $store, Plugin::instance()->maintenance() );
		$dir        = str_replace( '\\', '/', sys_get_temp_dir() ) . '/rfrac-health-' . uniqid();
		$installer  = new Mu_Installer( 'rockfire-rest-api-cache/rockfire-rest-api-cache.php', dirname( __DIR__, 2 ) . '/mu-plugin/' . Names::MU_FILE, '9.8.7', $dir );
		return new Site_Health( new Status_Service( new Config(), $store, new Schema(), $installer, $cache ) );
	}

	/**
	 * Assert the shape of a test result.
	 *
	 * @param array<string,mixed> $result Result.
	 */
	private function assert_valid( array $result ): void {
		foreach ( array( 'label', 'status', 'badge', 'description', 'actions', 'test' ) as $key ) {
			$this->assertArrayHasKey( $key, $result );
		}
		$this->assertContains( $result['status'], array( 'good', 'recommended', 'critical' ) );
		$this->assertSame(
			array(
				'label' => 'Performance',
				'color' => 'blue',
			),
			$result['badge']
		);
	}

	public function test_registers_four_direct_tests(): void {
		$tests = $this->health()->register_tests( array( 'direct' => array( 'other' => array() ) ) );
		foreach ( self::KEYS as $key ) {
			$this->assertArrayHasKey( $key, $tests['direct'] );
			$this->assert_valid( call_user_func( $tests['direct'][ $key ]['test'] ) );
		}
		$this->assertArrayHasKey( 'other', $tests['direct'] );
		$this->assertSame( array( 'direct' ), array_keys( $this->health()->register_tests( array() ) ) );
	}

	public function test_container_hooks_the_tests_and_debug_info_in_admin(): void {
		set_current_screen( 'dashboard' );
		$plugin = new Plugin();
		$plugin->register_engine_hooks();
		$hooks = array_column( $plugin->engine_hooks(), 'hook' );
		$this->assertContains( 'site_status_tests', $hooks );
		$this->assertContains( 'debug_information', $hooks );

		$tests = apply_filters( 'site_status_tests', array() );
		foreach ( self::KEYS as $key ) {
			$this->assertArrayHasKey( $key, $tests['direct'] );
			$this->assert_valid( call_user_func( $tests['direct'][ $key ]['test'] ) );
		}
		$this->assertArrayHasKey( 'rockfire-rest-api-cache', apply_filters( 'debug_information', array() ) );
	}

	public function test_cron_hooks_the_tests_but_not_the_debug_information(): void {
		set_current_screen( 'front' );
		add_filter( 'wp_doing_cron', '__return_true' );
		try {
			$plugin = new Plugin();
			$plugin->register_engine_hooks();
		} finally {
			remove_filter( 'wp_doing_cron', '__return_true' );
		}
		$hooks = array_column( $plugin->engine_hooks(), 'hook' );
		$this->assertContains( 'site_status_tests', $hooks );
		$this->assertNotContains( 'debug_information', $hooks );
	}

	public function test_not_hooked_outside_admin(): void {
		set_current_screen( 'front' );
		$plugin = new Plugin();
		$plugin->register_engine_hooks();
		$this->assertNotContains( 'site_status_tests', array_column( $plugin->engine_hooks(), 'hook' ) );
		$this->assertNotContains( 'debug_information', array_column( $plugin->engine_hooks(), 'hook' ) );
	}

	public function test_early_mode_is_good(): void {
		$result = $this->health()->test_mu_mode();
		$this->assert_valid( $result );
		$this->assertSame( 'good', $result['status'] );
	}

	public function test_fallback_mode_is_recommended_with_escaped_paths_and_failure(): void {
		$result = $this->health(
			array(
				'mu_mode'         => 'fallback',
				'mu_status'       => Mu_Status::MISSING,
				'mu_target_path'  => '/mu/<b>target</b>.php',
				'mu_dir_writable' => false,
				'last_error'      => array(
					'context' => 'hook:rest_post_dispatch',
					'message' => '<script>x</script>',
					'time'    => 1,
					'count'   => 1,
				),
			)
		)->test_mu_mode();
		$this->assert_valid( $result );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'Early serving is not active; responses are served after plugins load.', $result['description'] );
		$this->assertStringContainsString( '<code>' . esc_html( "\u{2026}/<b>target</b>.php" ) . '</code>', $result['description'] );
		$this->assertStringNotContainsString( '<b>target', $result['description'] );
		$this->assertStringContainsString( "<code>\u{2026}/mu-plugin/rfrac.php</code>", $result['description'] );
		$this->assertStringNotContainsString( '/plugin/', $result['description'] );
		$this->assertStringNotContainsString( '/mu/', $result['description'] );
		$this->assertStringContainsString( 'not writable', $result['description'] );
		$this->assertStringContainsString( 'hook:rest_post_dispatch', $result['description'] );
		$this->assertStringNotContainsString( '<script>', $result['description'] );
		$this->assertStringContainsString( '<a href=', $result['actions'] );
		$this->assertStringContainsString( esc_url( Admin_Url::page( Admin::TOOLS_SLUG ) ), $result['actions'] );
		$this->assertStringContainsString( 'page=rfrac-tools', $result['actions'] );
	}

	public function test_fallback_mode_shows_paths_inside_wp_content_with_the_wp_content_prefix(): void {
		$result = $this->health(
			array(
				'mu_mode'          => 'fallback',
				'mu_status'        => Mu_Status::MISSING,
				'mu_target_path'   => WP_CONTENT_DIR . '/mu-plugins/rfrac-early-cache.php',
				'mu_template_path' => WP_CONTENT_DIR . '/plugins/rockfire-rest-api-cache/mu-plugin/rfrac-early-cache.php',
			)
		)->test_mu_mode();
		$this->assert_valid( $result );
		$this->assertStringContainsString( '<code>wp-content/mu-plugins/rfrac-early-cache.php</code>', $result['description'] );
		$this->assertStringContainsString( '<code>wp-content/plugins/rockfire-rest-api-cache/mu-plugin/rfrac-early-cache.php</code>', $result['description'] );
		$this->assertStringNotContainsString( wp_normalize_path( WP_CONTENT_DIR ), $result['description'] );
		$this->assertStringNotContainsString( WP_CONTENT_DIR, $result['description'] );
	}

	public function test_fallback_mode_shows_paths_outside_wp_content_as_their_tails_only(): void {
		$result = $this->health(
			array(
				'mu_mode'          => 'fallback',
				'mu_status'        => Mu_Status::MISSING,
				'mu_target_path'   => '/srv/shared/mu/rfrac-early-cache.php',
				'mu_template_path' => 'C:\\srv\\deploy\\rockfire-rest-api-cache\\mu-plugin\\rfrac-early-cache.php',
			)
		)->test_mu_mode();
		$this->assert_valid( $result );
		$this->assertStringContainsString( "<code>\u{2026}/mu/rfrac-early-cache.php</code>", $result['description'] );
		$this->assertStringContainsString( "<code>\u{2026}/mu-plugin/rfrac-early-cache.php</code>", $result['description'] );
		foreach ( array( 'srv', 'shared', 'deploy', 'C:' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $result['description'] );
		}
	}

	public function test_outdated_mu_is_recommended(): void {
		$result = $this->health(
			array(
				'mu_mode'   => 'fallback',
				'mu_status' => Mu_Status::OUTDATED,
			)
		)->test_mu_mode();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'outdated', $result['description'] );
	}

	public function test_tables_present_is_good_and_missing_is_critical(): void {
		$this->assertSame( 'good', $this->health()->test_tables()['status'] );

		$result = $this->health(
			array(
				'tables_present'  => false,
				'migration_error' => 'Boom <i>',
				'last_error'      => array(
					'context' => 'hook:<x>',
					'message' => 'Fail <b>',
					'time'    => 1,
					'count'   => 1,
				),
			)
		)->test_tables();
		$this->assert_valid( $result );
		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'Deactivate and reactivate', $result['description'] );
		$this->assertStringContainsString( 'Boom &lt;i&gt;', $result['description'] );
		$this->assertStringContainsString( 'Fail &lt;b&gt;', $result['description'] );
		$this->assertStringNotContainsString( '<i>', $result['description'] );
	}

	public function test_failed_or_outdated_migration_with_tables_is_recommended_and_shows_the_error(): void {
		$failed = $this->health(
			array(
				'migration_error' => 'Boom <i>',
				'last_error'      => array(
					'context' => 'hook:<x>',
					'message' => 'Fail <b>',
					'time'    => 1,
					'count'   => 1,
				),
			)
		)->test_tables();
		$this->assert_valid( $failed );
		$this->assertSame( 'recommended', $failed['status'] );
		$this->assertStringContainsString( 'Boom &lt;i&gt;', $failed['description'] );
		$this->assertStringContainsString( 'Fail &lt;b&gt;', $failed['description'] );
		$this->assertStringNotContainsString( '<i>', $failed['description'] );

		$behind = $this->health(
			array(
				'db_version'          => 1,
				'db_version_expected' => 2,
			)
		)->test_tables();
		$this->assertSame( 'recommended', $behind['status'] );
		$this->assertStringContainsString( 'database version 1, expected 2', $behind['description'] );
		$this->assertStringNotContainsString( 'Last migration error', $behind['description'] );

		$this->assertSame(
			'good',
			$this->health(
				array(
					'db_version'          => 2,
					'db_version_expected' => 2,
				)
			)->test_tables()['status']
		);
	}

	public function test_dropped_entries_table_is_critical(): void {
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'rfrac_entries' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$suppressed = $wpdb->suppress_errors( true ); // Stats of a missing table fail noisily.
			try {
				$this->assertSame( 'critical', $this->real_health()->test_tables()['status'] );
			} finally {
				$wpdb->suppress_errors( $suppressed );
			}
		} finally {
			$this->restore_schema_state();
		}
		$this->assertSame( 'good', $this->real_health()->test_tables()['status'] );
	}

	public function test_backlog_over_threshold_is_recommended(): void {
		$result = $this->health( array( 'backlog' => 1001 ) )->test_cleanup_backlog();
		$this->assert_valid( $result );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'good', $this->health( array( 'backlog' => 1000 ) )->test_cleanup_backlog()['status'] );

		add_filter( Hooks::HEALTH_BACKLOG_THRESHOLD, static fn() => 5 );
		$this->assertSame( 'recommended', $this->health( array( 'backlog' => 10 ) )->test_cleanup_backlog()['status'] );
	}

	public function test_small_backlog_with_scheduled_event_is_good(): void {
		wp_clear_scheduled_hook( Scheduler::HOOK );
		Scheduler::schedule();
		$next = (int) wp_next_scheduled( Scheduler::HOOK );
		$this->assertSame( 'good', $this->health( array( 'backlog' => 10, 'next_maintenance' => $next ) )->test_cleanup_backlog()['status'] );
		$this->assertSame( 'good', $this->real_health()->test_cleanup_backlog()['status'] );
	}

	public function test_missing_or_overdue_maintenance_is_recommended_unless_cron_is_disabled(): void {
		$this->assertSame( 'recommended', $this->health( array( 'next_maintenance' => null ) )->test_cleanup_backlog()['status'] );
		$this->assertSame( 'recommended', $this->health( array( 'next_maintenance' => time() - 2 * HOUR_IN_SECONDS ) )->test_cleanup_backlog()['status'] );
		$this->assertSame( 'good', $this->health( array( 'next_maintenance' => time() - 600 ) )->test_cleanup_backlog()['status'] );
		$this->assertSame(
			'good',
			$this->health(
				array(
					'next_maintenance' => null,
					'cron_disabled'    => true,
				)
			)->test_cleanup_backlog()['status']
		);
	}

	/**
	 * Data provider: store type, persistent object cache, expected status, expected text.
	 *
	 * @return array<string,array{0:string,1:bool,2:string,3:string}>
	 */
	public static function store_cases(): array {
		return array(
			'object cache store with persistent cache' => array( 'object_cache', true, 'good', 'payloads are stored in the object cache' ),
			'object cache store without persistence'   => array( 'object_cache', false, 'recommended', 'do not survive between requests' ),
			'database store without persistent cache'  => array( 'database', false, 'good', 'payloads are stored in the database (one indexed query per hit)' ),
			'database store with persistent cache'     => array( 'database', true, 'good', 'Payloads are stored in the database' ),
		);
	}

	/**
	 * @dataProvider store_cases
	 *
	 * @param string $store    Store type.
	 * @param bool   $detected Persistent object cache present.
	 * @param string $status   Expected status.
	 * @param string $text     Expected description text.
	 */
	public function test_object_cache_follows_the_store_in_use( string $store, bool $detected, string $status, string $text ): void {
		$result = $this->health(
			array(
				'store_type'            => $store,
				'object_cache_detected' => $detected,
			)
		)->test_object_cache();
		$this->assert_valid( $result );
		$this->assertSame( $status, $result['status'] );
		$this->assertStringContainsString( $text, $result['description'] );
		if ( 'database' === $store ) {
			$this->assertStringNotContainsString( 'stored in the object cache', $result['description'] );
		}
	}

	public function test_mu_reason_branches(): void {
		$cases = array(
			array( array( 'mu_status' => Mu_Status::DISABLED ), 'RFRAC_DISABLE_MU_INSTALL' ),
			array( array( 'mu_dir_writable' => false ), 'not writable' ),
			array( array( 'mu_status' => Mu_Status::OUTDATED ), 'outdated' ),
			array(
				array(
					'mu_status'      => Mu_Status::MISSING,
					'mu_last_result' => array(
						'status' => Mu_Status::MISSING->value,
						'code'   => 'copy_failed',
						'time'   => 1,
					),
				),
				'The last install attempt failed (copy_failed)',
			),
			array( array( 'mu_status' => Mu_Status::MISSING ), 'not installed' ),
		);
		foreach ( $cases as $case ) {
			$result = $this->health( array_merge( array( 'mu_mode' => 'fallback' ), $case[0] ) )->test_mu_mode();
			$this->assertSame( 'recommended', $result['status'] );
			$this->assertStringContainsString( $case[1], $result['description'] );
		}
	}

	public function test_debug_information_fields(): void {
		$info = $this->health( array( 'locked_settings' => array( 'max_entries' => 'RFRAC_MAX' ) ) )->debug_information( array( 'wp-core' => array() ) );
		$this->assertArrayHasKey( 'wp-core', $info );
		$section = $info['rockfire-rest-api-cache'];
		$this->assertSame( 'REST API Cache', $section['label'] );
		foreach ( array( 'version', 'db_version', 'mu_mode', 'store_type', 'entries_active', 'entries_flushed', 'entries_expired', 'total_size', 'max_entries', 'next_maintenance', 'locked_settings' ) as $field ) {
			$this->assertArrayHasKey( $field, $section['fields'] );
			$this->assertArrayHasKey( 'label', $section['fields'][ $field ] );
			$this->assertArrayHasKey( 'value', $section['fields'][ $field ] );
		}
		$this->assertSame( 3, $section['fields']['entries_active']['value'] );
		$this->assertSame( 2048, $section['fields']['total_size']['debug'] );
		$this->assertStringContainsString( 'RFRAC_MAX', $section['fields']['locked_settings']['value'] );
	}

	public function test_debug_information_formats_empty_values(): void {
		$fields = $this->health( array( 'next_maintenance' => null ) )->debug_information( array() )['rockfire-rest-api-cache']['fields'];
		$this->assertSame( 'Not scheduled', $fields['next_maintenance']['value'] );
		$this->assertSame( 'None', $fields['locked_settings']['value'] );
		$this->assertSame( '1 / 1', $fields['db_version']['value'] );
		$this->assertSame( size_format( 2048 ), $fields['total_size']['value'] );
	}

	public function test_debug_total_size_equals_cache_service_stats_size(): void {
		Rfrac_Entry_Fixtures::insert_entry( array( 'payload' => str_repeat( 'a', 100 ) ) );
		$stats = Plugin::instance()->cache_service()->stats();
		$info  = $this->real_health()->debug_information( array() );
		$this->assertGreaterThan( 0, $stats['size'] );
		$fields = $info['rockfire-rest-api-cache']['fields'];
		$this->assertSame( $stats['size'], $fields['total_size']['debug'] );
		$this->assertSame( size_format( $stats['size'] ), $fields['total_size']['value'] );
		$this->assertSame( $stats['active'], $fields['entries_active']['value'] );
	}

	public function test_show_tables_runs_once_for_all_four_tests_and_debug_info(): void {
		$health   = $this->real_health();
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		try {
			$health->test_mu_mode();
			$health->test_tables();
			$health->test_cleanup_backlog();
			$health->test_object_cache();
			$health->debug_information( array() );
		} finally {
			$recorder->stop();
		}
		// One SHOW TABLES per table, from a single tables_exist() run.
		$this->assertCount( 2, $recorder->matching( '/^\s*SHOW TABLES/i' ) );
	}

	/**
	 * A Site_Health whose route service lists the given cached routes with a custom check, or throws.
	 *
	 * @param Route_View[]|Throwable $routes Routes, or the error to throw.
	 *
	 * @return Site_Health
	 */
	private function routes_health( array|Throwable $routes ): Site_Health {
		$service = new class( $routes ) extends Route_Service {
			/**
			 * Routes or error.
			 *
			 * @var Route_View[]|Throwable
			 */
			private array|Throwable $routes;

			public function __construct( array|Throwable $routes ) {
				$this->routes = $routes;
			}

			public function cached_custom_access(): array {
				if ( $this->routes instanceof Throwable ) {
					throw $this->routes;
				}
				return $this->routes;
			}
		};
		$status = $this->health();
		$ref    = new ReflectionProperty( Site_Health::class, 'status_service' );
		$ref->setAccessible( true );
		return new Site_Health( $ref->getValue( $status ), $service );
	}

	/**
	 * A cached route view with custom-checked methods.
	 *
	 * @param string   $route  Route.
	 * @param string[] $cached Cached custom-checked methods.
	 *
	 * @return Route_View
	 */
	private function custom_view( string $route, array $cached = array( 'GET' ) ): Route_View {
		return new Route_View( Route_Service::id_for( $route ), $route, 'partner/v1', array(), true, false, false, null, 'settings', false, true, 3600, null, array( 'GET', 'POST' ), array(), array( 'GET', 'POST' ), $cached );
	}

	public function test_the_custom_access_test_is_added_only_with_a_route_service(): void {
		$this->assertArrayNotHasKey( 'rfrac_custom_access_routes', $this->health()->register_tests( array() )['direct'] );

		$tests = $this->routes_health( array() )->register_tests( array() );
		$this->assertArrayHasKey( 'rfrac_custom_access_routes', $tests['direct'] );
		foreach ( self::KEYS as $key ) {
			$this->assertArrayHasKey( $key, $tests['direct'] );
		}
	}

	public function test_the_container_adds_the_custom_access_test(): void {
		set_current_screen( 'dashboard' );
		$plugin = new Plugin();
		$plugin->register_engine_hooks();

		$this->assertArrayHasKey( 'rfrac_custom_access_routes', apply_filters( 'site_status_tests', array() )['direct'] );
	}

	public function test_no_cached_custom_access_route_is_good_with_the_security_badge(): void {
		$result = $this->routes_health( array() )->test_custom_access_routes();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'rfrac_custom_access_routes', $result['test'] );
		$this->assertSame(
			array(
				'label' => 'Security',
				'color' => 'blue',
			),
			$result['badge']
		);
	}

	public function test_cached_custom_access_routes_are_recommended_and_listed_escaped_with_their_methods(): void {
		$result = $this->routes_health( array( $this->custom_view( '/partner/v1/feed/(?P<id><b>)' ), $this->custom_view( '/partner/v1/list', array( 'GET', 'POST' ) ) ) )->test_custom_access_routes();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( '2 cached REST routes check access with their own permission callback', $result['label'] );
		$this->assertStringContainsString( '<code>/partner/v1/feed/(?P&lt;id&gt;&lt;b&gt;)</code></a> (GET)', $result['description'] );
		$this->assertStringContainsString( '<code>/partner/v1/list</code></a> (GET, POST)', $result['description'] );
		$this->assertStringNotContainsString( '<b>', $result['description'] );
		$this->assertStringContainsString( esc_url( Admin_Url::page( Routes_Page::SLUG, array( 'action' => 'edit', 'route_id' => Route_Service::id_for( '/partner/v1/list' ) ) ) ), $result['description'] );
		$this->assertStringContainsString( '<code>RFRAC_BYPASS_HEADERS</code>', $result['description'] );
		$this->assertStringContainsString( esc_url( Admin_Url::page( Routes_Page::SLUG ) ), $result['actions'] );
		$this->assertSame( 'Security', $result['badge']['label'] );
	}

	public function test_more_than_ten_routes_are_counted_instead_of_listed(): void {
		$views = array();
		for ( $i = 1; $i <= 13; $i++ ) {
			$views[] = $this->custom_view( '/partner/v1/r' . $i );
		}
		$result = $this->routes_health( $views )->test_custom_access_routes();

		$this->assertSame( 11, substr_count( $result['description'], '<li>' ) );
		$this->assertStringContainsString( '<code>/partner/v1/r10</code>', $result['description'] );
		$this->assertStringNotContainsString( '<code>/partner/v1/r11</code>', $result['description'] );
		$this->assertStringContainsString( '<li>and 3 more routes</li>', $result['description'] );
	}

	public function test_a_failing_route_read_is_recommended_and_named_in_the_result_without_being_recorded(): void {
		$result = $this->routes_health( new RuntimeException( 'no server' ) )->test_custom_access_routes();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'REST API Cache could not check the access of its cached routes', $result['label'] );
		$this->assertStringContainsString( 'Reading the registered REST routes failed: RuntimeException: no server', $result['description'] );
		$this->assertFalse( get_option( Failure_Recorder::OPTION ) );
	}

	public function test_a_failing_route_read_leaves_an_earlier_non_clearable_failure_in_place(): void {
		$stored = array(
			'context'   => 'routes',
			'message'   => 'RuntimeException: routes broke',
			'time'      => time() - 2 * MINUTE_IN_SECONDS,
			'count'     => 3,
			'subject'   => '',
			'clearable' => false,
			'cleared'   => false,
		);
		update_option( Failure_Recorder::OPTION, $stored, false );

		$result = $this->routes_health( new RuntimeException( 'no server' ) )->test_custom_access_routes();

		$this->assertSame( $stored, get_option( Failure_Recorder::OPTION ) );
		$this->assertSame( 'routes', Failure_Recorder::last()['context'] ?? null );
		$this->assertStringContainsString( 'RuntimeException: no server', $result['description'] );
	}

	public function test_a_failing_route_read_shows_a_cut_escaped_one_line_error(): void {
		$result = $this->routes_health( new RuntimeException( "<b>bad</b>\r\n" . str_repeat( 'x', 400 ) ) )->test_custom_access_routes();

		$this->assertStringContainsString( 'RuntimeException: &lt;b&gt;bad&lt;/b&gt;  xxx', $result['description'] );
		$this->assertStringNotContainsString( '<b>', $result['description'] );
		$this->assertStringContainsString( 'x...</p>', $result['description'] );
		$this->assertStringNotContainsString( str_repeat( 'x', 300 ), $result['description'] );
	}

	public function test_a_route_read_that_succeeds_clears_a_recorded_site_health_failure(): void {
		Failure_Recorder::record( 'site_health', 'RuntimeException: no server' );
		$this->assertSame( 'site_health', Failure_Recorder::last()['context'] ?? null );

		$this->routes_health( array() )->test_custom_access_routes();

		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_a_route_read_that_succeeds_keeps_a_failure_of_the_engine(): void {
		Failure_Recorder::record( 'routes', 'RuntimeException: routes broke', '', false );

		$this->routes_health( array( $this->custom_view( '/partner/v1/list' ) ) )->test_custom_access_routes();

		$this->assertSame( 'routes', Failure_Recorder::last()['context'] ?? null );
	}
}
