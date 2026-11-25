<?php
/**
 * Tests for the constant fallback seam of Config, the reader of the original's settings and the constant fallback.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Route;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Config\Fallback_Constants_Interface;
use Rockfire\Rest_Api_Cache\Config\Settings_Schema;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Legacy\Constant_Fallback;
use Rockfire\Rest_Api_Cache\Legacy\Detector;
use Rockfire\Rest_Api_Cache\Legacy\Original_Settings;
use Rockfire\Rest_Api_Cache\Services\Settings_Service;

/**
 * @covers \Rockfire\Rest_Api_Cache\Config\Config
 * @covers \Rockfire\Rest_Api_Cache\Config\Config_Compiler
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Constant_Fallback
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Detector
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Original_Settings
 */
class Test_Legacy_Constant_Fallback extends Rfrac_Test_Case {

	const TTL_LABEL = 'WP_REST_CACHE_TIMEOUT * WP_REST_CACHE_TIMEOUT_INTERVAL';

	public function set_up() {
		parent::set_up();
		delete_option( 'wp_rest_cache_timeout' );
		delete_option( 'wp_rest_cache_timeout_interval' );
		delete_option( 'wp_rest_cache_global_cacheable_request_headers' );
		Config::set_fallback_constants( new Constant_Fallback() );
	}

	public function tear_down() {
		Config::set_fallback_constants( new Constant_Fallback() );
		parent::tear_down();
	}

	/**
	 * A provider that returns the given values.
	 *
	 * @param array<string,array{value:mixed,label:string}> $values Values by settings key.
	 *
	 * @return Fallback_Constants_Interface
	 */
	private function provider( array $values ): Fallback_Constants_Interface {
		return new class( $values ) implements Fallback_Constants_Interface {

			/**
			 * Values by settings key.
			 *
			 * @var array<string,array{value:mixed,label:string}>
			 */
			private array $values;

			/**
			 * Constructor.
			 *
			 * @param array<string,array{value:mixed,label:string}> $values Values by settings key.
			 */
			public function __construct( array $values ) {
				$this->values = $values;
			}

			/**
			 * The values.
			 *
			 * @return array<string,array{value:mixed,label:string}>
			 */
			public function values(): array {
				return $this->values;
			}
		};
	}

	// -----------------------------------------------------------------------------------------
	// The seam in Config.
	// -----------------------------------------------------------------------------------------

	public function test_a_fallback_value_is_normalised_locked_and_labelled(): void {
		Config::set_fallback_constants(
			$this->provider(
				array(
					'store'                => array(
						'value' => 'database',
						'label' => 'THE_LABEL',
					),
					'max_entries'          => array(
						'value' => 5,
						'label' => 'OTHER_LABEL',
					),
					'global_ignore_params' => array(
						'value' => 'utm_source, fbclid utm_source',
						'label' => 'LIST_LABEL',
					),
				)
			)
		);
		$config = new Config();

		$settings = $config->settings();
		$this->assertSame( 'database', $settings['store'] );
		$this->assertSame( 100, $settings['max_entries'], 'The value is clamped like a constant.' );
		$this->assertSame( array( 'utm_source', 'fbclid' ), $settings['global_ignore_params'], 'A list goes through the list branch.' );
		$this->assertSame(
			array(
				'store'                => 'THE_LABEL',
				'max_entries'          => 'OTHER_LABEL',
				'global_ignore_params' => 'LIST_LABEL',
			),
			$config->locked()
		);
		$this->assertTrue( $config->is_locked( 'store' ) );
		$this->assertSame( array(), $config->constant_errors() );
		$this->assertSame( 'auto', $config->raw_settings()['store'], 'A fallback value is never persisted.' );
	}

	public function test_a_locked_fallback_key_is_not_saved(): void {
		Config::set_fallback_constants(
			$this->provider(
				array(
					'store' => array(
						'value' => 'database',
						'label' => 'THE_LABEL',
					),
				)
			)
		);
		$config = new Config();
		$config->save_settings( array( 'store' => 'object_cache' ) );

		$this->assertSame( 'database', $config->settings()['store'] );
		$this->assertSame( 'auto', $config->raw_settings()['store'] );
	}

	public function test_an_invalid_fallback_value_is_reported_under_its_label_and_ignored(): void {
		Config::set_fallback_constants(
			$this->provider(
				array(
					'store'               => array(
						'value' => 'memcached',
						'label' => 'STORE_LABEL',
					),
					'max_params'          => array(
						'value' => 'many',
						'label' => 'PARAMS_LABEL',
					),
					'global_vary_headers' => array(
						'value' => array( 'cookie' ),
						'label' => 'HEADERS_LABEL',
					),
				)
			)
		);
		$config = new Config();

		$this->assertSame( array(), $config->locked() );
		$this->assertSame( 'auto', $config->settings()['store'] );
		$errors = $config->constant_errors();
		$this->assertSame( 'Expected one of: auto, database, object_cache.', $errors['STORE_LABEL'] );
		$this->assertSame( 'Expected an integer.', $errors['PARAMS_LABEL'] );
		$this->assertStringStartsWith( 'Expected a list of request header names.', $errors['HEADERS_LABEL'] );
		$this->assertStringContainsString( 'cookie', $errors['HEADERS_LABEL'] );
	}

	public function test_enabled_and_routes_never_take_a_fallback(): void {
		Config::set_fallback_constants(
			$this->provider(
				array(
					'enabled' => array(
						'value' => true,
						'label' => 'DISABLE_LABEL',
					),
					'routes'  => array(
						'value' => array( '/wp/v2/posts' => array( 'status' => 'excluded' ) ),
						'label' => 'ROUTES_LABEL',
					),
				)
			)
		);
		$config = new Config();

		$this->assertTrue( $config->settings()['enabled'] );
		$this->assertSame( array(), $config->locked() );
		$this->assertSame( array(), $config->constant_errors() );
		$this->assertSame( array(), $config->constant_route_rules() );
	}

	public function test_a_null_provider_removes_the_fallback(): void {
		$values = array(
			'store' => array(
				'value' => 'database',
				'label' => 'THE_LABEL',
			),
		);
		Config::set_fallback_constants( $this->provider( $values ) );
		$this->assertSame( array( 'store' => 'THE_LABEL' ), ( new Config() )->locked() );

		Config::set_fallback_constants( null );
		$this->assertSame( array(), ( new Config() )->locked() );
		$this->assertSame( 'auto', ( new Config() )->settings()['store'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_defined_constant_always_wins_over_the_fallback(): void {
		define( 'RFRAC_STORE', 'object_cache' );
		Config::set_fallback_constants(
			$this->provider(
				array(
					'store'       => array(
						'value' => 'database',
						'label' => 'THE_LABEL',
					),
					'max_entries' => array(
						'value' => 500,
						'label' => 'OTHER_LABEL',
					),
				)
			)
		);
		$config = new Config();

		$this->assertSame( 'object_cache', $config->settings()['store'] );
		$this->assertSame( 500, $config->settings()['max_entries'] );
		$this->assertSame(
			array(
				'store'       => 'RFRAC_STORE',
				'max_entries' => 'OTHER_LABEL',
			),
			$config->locked()
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_invalid_constant_does_not_fall_back(): void {
		define( 'RFRAC_STORE', 'memcached' );
		Config::set_fallback_constants(
			$this->provider(
				array(
					'store' => array(
						'value' => 'database',
						'label' => 'THE_LABEL',
					),
				)
			)
		);
		$config = new Config();

		$this->assertSame( 'auto', $config->settings()['store'] );
		$this->assertSame( array(), $config->locked() );
		$this->assertSame( array( 'RFRAC_STORE' ), array_keys( $config->constant_errors() ) );
	}

	// -----------------------------------------------------------------------------------------
	// The constants of the original through the fallback (AC-1 to AC-9).
	// -----------------------------------------------------------------------------------------

	public function test_nothing_is_supplied_without_constants_of_the_original(): void {
		update_option( 'wp_rest_cache_timeout', '5' );
		update_option( 'wp_rest_cache_global_cacheable_request_headers', 'X-Lang' );
		$fallback = new Constant_Fallback();

		$this->assertSame( array(), $fallback->values(), 'The options of the original are for the import, not the fallback.' );
		$this->assertSame( array(), $fallback->report() );
		$this->assertSame( array(), ( new Config() )->locked() );
		$this->assertSame( 86400, ( new Config() )->settings()['default_ttl'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_default_ttl_is_the_product_of_timeout_and_interval(): void {
		define( 'WP_REST_CACHE_TIMEOUT', 2 );
		define( 'WP_REST_CACHE_TIMEOUT_INTERVAL', 3600 );
		$config = new Config();

		$this->assertSame( 7200, $config->settings()['default_ttl'] );
		$this->assertSame( array( 'default_ttl' => self::TTL_LABEL ), $config->locked() );
		$this->assertSame( array(), $config->constant_errors() );
		$this->assertSame( 'constant:' . self::TTL_LABEL, ( new Settings_Service( $config ) )->sources()['default_ttl'] );
		$this->assertSame( 86400, $config->raw_settings()['default_ttl'], 'The product is never persisted.' );
		$this->assertSame(
			array(
				array(
					'constant'    => 'WP_REST_CACHE_TIMEOUT',
					'replacement' => 'RFRAC_DEFAULT_TTL',
					'state'       => 'honoured',
				),
				array(
					'constant'    => 'WP_REST_CACHE_TIMEOUT_INTERVAL',
					'replacement' => 'RFRAC_DEFAULT_TTL',
					'state'       => 'honoured',
				),
			),
			( new Constant_Fallback() )->report()
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_constant_timeout_takes_its_interval_from_the_option(): void {
		define( 'WP_REST_CACHE_TIMEOUT', 1 );
		update_option( 'wp_rest_cache_timeout_interval', '86400' );
		$config = new Config();

		$this->assertSame( 86400, $config->settings()['default_ttl'] );
		$this->assertSame( array( 'default_ttl' => 'WP_REST_CACHE_TIMEOUT' ), $config->locked() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_constant_timeout_takes_the_default_interval_and_is_clamped_to_max_ttl(): void {
		define( 'WP_REST_CACHE_TIMEOUT', 1 );
		$config = new Config();

		$this->assertSame( 2592000, $config->settings()['max_ttl'] );
		$this->assertSame( 2592000, $config->settings()['default_ttl'] );
		$this->assertSame( array( 'default_ttl' => 'WP_REST_CACHE_TIMEOUT' ), $config->locked() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_constant_interval_alone_uses_the_default_timeout(): void {
		define( 'WP_REST_CACHE_TIMEOUT_INTERVAL', 7200 );
		$config = new Config();

		$this->assertSame( 7200, $config->settings()['default_ttl'] );
		$this->assertSame( array( 'default_ttl' => 'WP_REST_CACHE_TIMEOUT_INTERVAL' ), $config->locked() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_constant_of_the_plugin_wins_and_shadows_the_original(): void {
		define( 'RFRAC_DEFAULT_TTL', 600 );
		define( 'WP_REST_CACHE_TIMEOUT', 5 );
		define( 'WP_REST_CACHE_TIMEOUT_INTERVAL', 60 );
		$config = new Config();

		$this->assertSame( 600, $config->settings()['default_ttl'] );
		$this->assertSame( array( 'default_ttl' => 'RFRAC_DEFAULT_TTL' ), $config->locked() );
		$this->assertSame( array(), $config->constant_errors() );
		$this->assertSame(
			array(
				array(
					'constant'    => 'WP_REST_CACHE_TIMEOUT',
					'replacement' => 'RFRAC_DEFAULT_TTL',
					'state'       => 'shadowed',
				),
				array(
					'constant'    => 'WP_REST_CACHE_TIMEOUT_INTERVAL',
					'replacement' => 'RFRAC_DEFAULT_TTL',
					'state'       => 'shadowed',
				),
			),
			( new Constant_Fallback() )->report()
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_timeout_that_is_not_a_number_is_reported_and_ignored(): void {
		define( 'WP_REST_CACHE_TIMEOUT', 'abc' );
		$config = new Config();

		$this->assertSame( 'Expected an integer.', $config->constant_errors()['WP_REST_CACHE_TIMEOUT'] );
		$this->assertFalse( $config->is_locked( 'default_ttl' ) );
		$this->assertSame( 86400, $config->settings()['default_ttl'] );
		$this->assertSame(
			array(
				array(
					'constant'    => 'WP_REST_CACHE_TIMEOUT',
					'replacement' => 'RFRAC_DEFAULT_TTL',
					'state'       => 'invalid',
				),
			),
			( new Constant_Fallback() )->report()
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_product_that_is_not_positive_is_reported_under_the_combined_label(): void {
		define( 'WP_REST_CACHE_TIMEOUT', 0 );
		define( 'WP_REST_CACHE_TIMEOUT_INTERVAL', 3600 );
		$config = new Config();

		$this->assertSame( array( self::TTL_LABEL ), array_keys( $config->constant_errors() ) );
		$this->assertSame( 'Expected an integer.', $config->constant_errors()[ self::TTL_LABEL ] );
		$this->assertFalse( $config->is_locked( 'default_ttl' ) );
		$this->assertSame( 'invalid', ( new Constant_Fallback() )->report()[0]['state'] );
		$this->assertSame( 'invalid', ( new Constant_Fallback() )->report()[1]['state'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_global_headers_constant_locks_the_vary_headers(): void {
		define( 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS', 'X-Lang, x_region' );
		$config = new Config();

		$this->assertSame( array( 'x-lang', 'x-region' ), $config->settings()['global_vary_headers'] );
		$this->assertSame( array( 'global_vary_headers' => 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS' ), $config->locked() );
		$this->assertSame(
			'constant:WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS',
			( new Settings_Service( $config ) )->sources()['global_vary_headers']
		);
		$this->assertSame(
			array(
				array(
					'constant'    => 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS',
					'replacement' => 'RFRAC_GLOBAL_VARY_HEADERS',
					'state'       => 'honoured',
				),
			),
			( new Constant_Fallback() )->report()
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_invalid_headers_constant_is_reported_under_its_own_name_and_under_the_one_it_stands_in_for(): void {
		define( 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS', 'Cookie' );
		$config = new Config();

		$errors = $config->constant_errors();
		$this->assertArrayHasKey( 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS', $errors );
		$this->assertArrayHasKey( 'RFRAC_GLOBAL_VARY_HEADERS', $errors, 'The compiler looks for the error of the global vary list here.' );
		$this->assertStringContainsString( 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS', $errors['RFRAC_GLOBAL_VARY_HEADERS'] );
		$this->assertStringContainsString( $errors['WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS'], $errors['RFRAC_GLOBAL_VARY_HEADERS'] );
		$this->assertFalse( $config->is_locked( 'global_vary_headers' ) );
		$this->assertSame( array(), $config->settings()['global_vary_headers'] );
		$this->assertSame( 'invalid', ( new Constant_Fallback() )->report()[0]['state'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_invalid_headers_constant_compiles_every_route_as_not_cached_like_the_plugin_constant(): void {
		define( 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS', 'X-Lang, Cookie' );
		delete_option( Failure_Recorder::OPTION );

		$compiled = ( new Config_Compiler( new Config() ) )->compile( $this->new_rest_server() );

		$enabled = array_values( array_filter( $compiled->routes(), static fn( Compiled_Route $route ): bool => $route->enabled ) );
		$this->assertNotEmpty( $enabled );
		foreach ( $enabled as $route ) {
			$this->assertTrue( $route->vary_invalid, $route->key );
			$this->assertTrue( $route->visitor_varies, $route->key );
		}
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last, 'A problem is recorded.' );
		$this->assertStringStartsWith( 'No route is cached, the global vary headers are invalid: ', $last['message'] );
		$this->assertStringContainsString( 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS', $last['message'] );
		$this->assertStringNotContainsString( 'more)', $last['message'], 'Exactly one problem.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_headers_constant_is_shadowed_by_its_replacement(): void {
		define( 'RFRAC_GLOBAL_VARY_HEADERS', 'Accept-Language' );
		define( 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS', 'X-Lang' );
		$config = new Config();

		$this->assertSame( array( 'accept-language' ), $config->settings()['global_vary_headers'] );
		$this->assertSame( array( 'global_vary_headers' => 'RFRAC_GLOBAL_VARY_HEADERS' ), $config->locked() );
		$this->assertSame( 'shadowed', ( new Constant_Fallback() )->report()[0]['state'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constants_without_a_counterpart_have_no_effect(): void {
		define( 'WP_REST_CACHE_REGENERATE', true );
		define( 'WP_REST_CACHE_REGENERATE_INTERVAL', 'hourly' );
		define( 'WP_REST_CACHE_REGENERATE_NUMBER', 5 );
		define( 'WP_REST_CACHE_MEMCACHE_USED', false );
		$config = new Config();

		$this->assertSame( array(), $config->locked() );
		$this->assertSame( array(), $config->constant_errors() );
		$this->assertSame( Settings_Schema::defaults() + array( 'routes' => array() ), $config->settings() );
		$this->assertSame(
			array(
				array(
					'constant'    => 'WP_REST_CACHE_REGENERATE',
					'replacement' => null,
					'state'       => 'no_effect',
				),
				array(
					'constant'    => 'WP_REST_CACHE_REGENERATE_INTERVAL',
					'replacement' => null,
					'state'       => 'no_effect',
				),
				array(
					'constant'    => 'WP_REST_CACHE_REGENERATE_NUMBER',
					'replacement' => null,
					'state'       => 'no_effect',
				),
				array(
					'constant'    => 'WP_REST_CACHE_MEMCACHE_USED',
					'replacement' => null,
					'state'       => 'no_effect',
				),
			),
			( new Constant_Fallback() )->report()
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_without_a_provider_the_constants_of_the_original_do_nothing(): void {
		define( 'WP_REST_CACHE_TIMEOUT', 2 );
		define( 'WP_REST_CACHE_TIMEOUT_INTERVAL', 3600 );
		define( 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS', 'X-Lang' );
		define( 'RFRAC_MAX_PARAMS', 7 );
		Config::set_fallback_constants( null );
		$config = new Config();

		$this->assertSame( array( 'max_params' => 'RFRAC_MAX_PARAMS' ), $config->locked() );
		$this->assertSame( array(), $config->constant_errors() );
		$this->assertSame( 86400, $config->settings()['default_ttl'] );
		$this->assertSame( array(), $config->settings()['global_vary_headers'] );
		$this->assertSame( 7, $config->settings()['max_params'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enabled_is_never_locked_by_the_fallback(): void {
		define( 'WP_REST_CACHE_TIMEOUT', 2 );
		define( 'WP_REST_CACHE_TIMEOUT_INTERVAL', 3600 );
		define( 'WP_REST_CACHE_REGENERATE', true );
		define( 'WP_REST_CACHE_REGENERATE_INTERVAL', 'hourly' );
		define( 'WP_REST_CACHE_REGENERATE_NUMBER', 5 );
		define( 'WP_REST_CACHE_MEMCACHE_USED', true );
		define( 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS', 'X-Lang' );
		$config = new Config();

		$this->assertFalse( defined( 'RFRAC_DISABLE' ) );
		$this->assertFalse( $config->is_locked( 'enabled' ) );
		$this->assertTrue( $config->settings()['enabled'] );
		$this->assertSame( array( 'default_ttl', 'global_vary_headers' ), array_keys( $config->locked() ) );
		$this->assertFalse( Settings_Schema::kill_switch() );
		$this->assertCount( 7, ( new Constant_Fallback() )->report() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_booted_plugin_installs_the_fallback(): void {
		// Remove the provider the boot installed: registering the hooks installs it again.
		Config::set_fallback_constants( null );
		Rockfire\Rest_Api_Cache\Plugin::instance()->register_hooks();
		define( 'WP_REST_CACHE_TIMEOUT', 2 );
		define( 'WP_REST_CACHE_TIMEOUT_INTERVAL', 3600 );

		$this->assertSame( 7200, ( new Config() )->settings()['default_ttl'] );
		$this->assertSame( array( 'default_ttl' => self::TTL_LABEL ), ( new Config() )->locked() );
	}

	// -----------------------------------------------------------------------------------------
	// Original_Settings and the detector.
	// -----------------------------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_defined_constants_are_listed_in_order_with_their_values(): void {
		$this->assertSame( array(), Original_Settings::defined_constants() );
		$this->assertFalse( Detector::present() );

		define( 'WP_REST_CACHE_MEMCACHE_USED', false );
		define( 'WP_REST_CACHE_TIMEOUT', 3 );

		$this->assertSame(
			array(
				'WP_REST_CACHE_TIMEOUT'       => 3,
				'WP_REST_CACHE_MEMCACHE_USED' => false,
			),
			Original_Settings::defined_constants()
		);
		$this->assertTrue( Detector::present() );
		$this->assertFalse( Detector::original_active() );
		$this->assertFalse( Detector::has_leftovers() );
	}

	public function test_the_constants_are_the_seven_of_the_original(): void {
		$this->assertCount( 7, Original_Settings::CONSTANTS );
		$this->assertSame( array_unique( Original_Settings::CONSTANTS ), Original_Settings::CONSTANTS );
		foreach ( Original_Settings::CONSTANTS as $constant ) {
			$this->assertStringStartsWith( 'WP_REST_CACHE_', $constant );
		}
	}

	public function test_options_are_read_with_their_default(): void {
		$this->assertNull( Original_Settings::option( 'regenerate_number' ) );
		$this->assertSame( 10, Original_Settings::option( 'regenerate_number', 10 ) );
		$this->assertFalse( Original_Settings::has_option( 'regenerate_number' ) );

		update_option( 'wp_rest_cache_regenerate_number', '25' );
		$this->assertSame( '25', Original_Settings::option( 'regenerate_number', 10 ) );
		$this->assertTrue( Original_Settings::has_option( 'regenerate_number' ) );

		update_option( 'wp_rest_cache_regenerate', '' );
		$this->assertTrue( Original_Settings::has_option( 'regenerate' ), 'An empty option still exists.' );
	}

	public function test_the_ttl_is_unknown_when_nothing_sets_it(): void {
		$this->assertNull( Original_Settings::ttl() );
	}

	public function test_the_ttl_is_read_from_the_options(): void {
		update_option( 'wp_rest_cache_timeout', '2' );
		update_option( 'wp_rest_cache_timeout_interval', '3600' );

		$this->assertSame(
			array(
				'seconds'       => 7200,
				'label'         => 'wp_rest_cache_timeout * wp_rest_cache_timeout_interval',
				'from_constant' => false,
			),
			Original_Settings::ttl()
		);
	}

	public function test_a_half_without_option_takes_the_default_of_the_original(): void {
		update_option( 'wp_rest_cache_timeout', '3' );
		$this->assertSame( 3 * 31536000, Original_Settings::ttl()['seconds'] );
		$this->assertSame( 'wp_rest_cache_timeout', Original_Settings::ttl()['label'] );

		delete_option( 'wp_rest_cache_timeout' );
		update_option( 'wp_rest_cache_timeout_interval', '60' );
		$this->assertSame( 60, Original_Settings::ttl()['seconds'] );
		$this->assertSame( 'wp_rest_cache_timeout_interval', Original_Settings::ttl()['label'] );
	}

	/**
	 * Values that are not a usable half of the cache lifetime.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function unusable_halves(): array {
		return array(
			'text'     => array( 'abc' ),
			'fraction' => array( '1.5' ),
			'zero'     => array( '0' ),
			'negative' => array( '-5' ),
			'empty'    => array( '' ),
			'an array' => array( array( 1 ) ),
			'true'     => array( true ),
		);
	}

	/**
	 * @dataProvider unusable_halves
	 *
	 * @param mixed $value The option value.
	 */
	public function test_the_seconds_are_unknown_when_a_half_is_unusable( $value ): void {
		update_option( 'wp_rest_cache_timeout', $value );
		update_option( 'wp_rest_cache_timeout_interval', '3600' );
		$this->assertNull( Original_Settings::ttl()['seconds'] );

		update_option( 'wp_rest_cache_timeout', '2' );
		update_option( 'wp_rest_cache_timeout_interval', $value );
		$this->assertNull( Original_Settings::ttl()['seconds'] );
	}

	public function test_a_product_beyond_the_integer_range_stays_an_integer(): void {
		update_option( 'wp_rest_cache_timeout', '999999999999999999' );
		update_option( 'wp_rest_cache_timeout_interval', '31536000' );

		$this->assertSame( PHP_INT_MAX, Original_Settings::ttl()['seconds'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_constant_ttl_is_labelled_by_the_constants_that_are_defined(): void {
		update_option( 'wp_rest_cache_timeout', '9' );
		define( 'WP_REST_CACHE_TIMEOUT_INTERVAL', 60 );

		$this->assertSame(
			array(
				'seconds'       => 540,
				'label'         => 'WP_REST_CACHE_TIMEOUT_INTERVAL',
				'from_constant' => true,
			),
			Original_Settings::ttl()
		);
	}

	public function test_the_global_headers_are_unknown_when_nothing_sets_them(): void {
		$this->assertNull( Original_Settings::global_headers() );
	}

	public function test_the_global_headers_are_split_trimmed_and_cleaned(): void {
		update_option( 'wp_rest_cache_global_cacheable_request_headers', ' X-Lang ,, x_region,  ' );

		$this->assertSame(
			array(
				'headers'       => array( 'X-Lang', 'x_region' ),
				'from_constant' => false,
				'label'         => 'wp_rest_cache_global_cacheable_request_headers',
			),
			Original_Settings::global_headers()
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_global_headers_constant_wins_over_the_option(): void {
		update_option( 'wp_rest_cache_global_cacheable_request_headers', 'X-Option' );
		define( 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS', 'X-Lang, X-Region' );

		$this->assertSame(
			array(
				'headers'       => array( 'X-Lang', 'X-Region' ),
				'from_constant' => true,
				'label'         => 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS',
			),
			Original_Settings::global_headers()
		);
	}
}
