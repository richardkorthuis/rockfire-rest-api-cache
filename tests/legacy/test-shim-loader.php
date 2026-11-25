<?php
/**
 * Tests for the conditional loader of the shims.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Legacy\Shim_Loader;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Shim_Loader
 */
class Test_Shim_Loader extends WP_UnitTestCase {

	const OWN      = 'rockfire-rest-api-cache/rockfire-rest-api-cache.php';
	const ORIGINAL = 'wp-rest-cache/wp-rest-cache.php';

	const SHIMS = 'includes/legacy/shims/';

	public function set_up() {
		parent::set_up();
		$this->activate( array( self::OWN ) );
	}

	public function tear_down() {
		Coexistence_Guard::reset();
		parent::tear_down();
	}

	/**
	 * Make the given plugins the active ones and forget the guard's answer.
	 *
	 * @param string[] $plugins Plugin files.
	 */
	private function activate( array $plugins ): void {
		update_option( 'active_plugins', $plugins );
		Coexistence_Guard::reset();
	}

	/**
	 * The allow-list as specified: class name => file below the shims directory.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function allow_listed(): array {
		return array(
			'Caching'                  => array( 'WP_Rest_Cache_Plugin\Includes\Caching\Caching', 'caching/class-caching.php' ),
			'Endpoint_Api'             => array( 'WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api', 'api/class-endpoint-api.php' ),
			'Post_Controller'          => array( 'WP_Rest_Cache_Plugin\Includes\Controller\Post_Controller', 'controller/class-post-controller.php' ),
			'Term_Controller'          => array( 'WP_Rest_Cache_Plugin\Includes\Controller\Term_Controller', 'controller/class-term-controller.php' ),
			'Attachment_Controller'    => array( 'WP_Rest_Cache_Plugin\Includes\Controller\Attachment_Controller', 'controller/class-attachment-controller.php' ),
			'Controller_Trait'         => array( 'WP_Rest_Cache_Plugin\Includes\Controller\Controller_Trait', 'controller/trait-controller.php' ),
			'WP_Rest_Cache_Endpoint_Api' => array( 'WP_Rest_Cache_Endpoint_Api', 'class-wp-rest-cache-endpoint-api.php' ),
		);
	}

	/**
	 * Names the loader must never resolve.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function never_shimmed(): array {
		return array(
			'Plugin'                   => array( 'WP_Rest_Cache_Plugin\Includes\Plugin' ),
			'Activator'                => array( 'WP_Rest_Cache_Plugin\Includes\Activator' ),
			'Deactivator'              => array( 'WP_Rest_Cache_Plugin\Includes\Deactivator' ),
			'Autoloader'               => array( 'WP_Rest_Cache_Plugin\Includes\Autoloader' ),
			'Util'                     => array( 'WP_Rest_Cache_Plugin\Includes\Util' ),
			'Item_Api'                 => array( 'WP_Rest_Cache_Plugin\Includes\API\Item_Api' ),
			'Oembed_Api'               => array( 'WP_Rest_Cache_Plugin\Includes\API\Oembed_Api' ),
			'Flush_Command'            => array( 'WP_Rest_Cache_Plugin\Includes\CLI\Flush_Command' ),
			'Caching_X'                => array( 'WP_Rest_Cache_Plugin\Includes\Caching\Caching_X' ),
			'Rockfire Plugin'          => array( 'Rockfire\Rest_Api_Cache\Plugin' ),
			'Namespace root'           => array( 'WP_Rest_Cache_Plugin' ),
			'Empty'                    => array( '' ),
		);
	}

	public function test_register_adds_the_loader_exactly_once() {
		Shim_Loader::register();
		Shim_Loader::register();

		$callback = array( Shim_Loader::class, 'autoload' );
		$found    = array_keys( array_filter( spl_autoload_functions(), static fn( $function ) => $function === $callback ) );

		$this->assertCount( 1, $found );
	}

	public function test_the_loader_is_appended_after_the_plugin_autoloader() {
		$functions = spl_autoload_functions();
		$own       = array_search( array( \Rockfire\Rest_Api_Cache\Autoloader::class, 'autoload' ), $functions, true );
		$shim      = array_search( array( Shim_Loader::class, 'autoload' ), $functions, true );

		$this->assertIsInt( $own );
		$this->assertIsInt( $shim );
		$this->assertGreaterThan( $own, $shim );
	}

	public function test_the_allow_list_has_exactly_the_seven_names() {
		$expected = array();
		foreach ( self::allow_listed() as $row ) {
			$expected[ strtolower( $row[0] ) ] = $row[1];
		}

		$this->assertCount( 7, Shim_Loader::classes() );
		$this->assertSame( $expected, Shim_Loader::classes() );
	}

	/**
	 * @dataProvider allow_listed
	 */
	public function test_an_allow_listed_name_resolves_to_its_shim_file( string $name, string $file ) {
		$expected = RFRAC_DIR . self::SHIMS . $file;

		$this->assertSame( $expected, Shim_Loader::resolve( $name ), 'As written.' );
		$this->assertSame( $expected, Shim_Loader::resolve( strtolower( $name ) ), 'Lower-cased.' );
		$this->assertSame( $expected, Shim_Loader::resolve( '\\' . $name ), 'With a leading backslash.' );
	}

	/**
	 * @dataProvider never_shimmed
	 */
	public function test_a_name_outside_the_allow_list_resolves_to_nothing( string $name ) {
		$this->assertNull( Shim_Loader::resolve( $name ) );
		$this->assertNull( Shim_Loader::resolve( '\\' . $name ) );
	}

	public function test_a_miss_does_not_consult_the_guard() {
		Coexistence_Guard::reset();
		$blocked = new ReflectionProperty( Coexistence_Guard::class, 'blocked' );
		$blocked->setAccessible( true );
		$this->assertNull( $blocked->getValue(), 'Precondition: the guard has not answered.' );

		Shim_Loader::resolve( 'WP_Rest_Cache_Plugin\Includes\Plugin' );
		Shim_Loader::autoload( 'Rockfire\Rest_Api_Cache\Not_A_Class' );

		$this->assertNull( $blocked->getValue() );
	}

	/**
	 * @dataProvider allow_listed
	 */
	public function test_an_active_original_resolves_nothing( string $name ) {
		$this->activate( array( self::ORIGINAL, self::OWN ) );

		$this->assertNull( Shim_Loader::resolve( $name ) );
		$this->assertNull( Shim_Loader::resolve( strtolower( $name ) ) );
		$this->assertNull( Shim_Loader::resolve( '\\' . $name ) );
	}

	public function test_an_active_original_in_a_renamed_folder_resolves_nothing() {
		$this->activate( array( 'wp-rest-cache-old/wp-rest-cache.php' ) );

		foreach ( self::allow_listed() as $row ) {
			$this->assertNull( Shim_Loader::resolve( $row[0] ), $row[0] );
		}
	}

	public function test_autoload_includes_nothing_for_a_name_outside_the_allow_list() {
		$before = get_included_files();

		Shim_Loader::autoload( 'WP_Rest_Cache_Plugin\Includes\Plugin' );
		Shim_Loader::autoload( 'Not_A_Class_Of_Rockfire' );

		$this->assertSame( $before, get_included_files() );
		$this->assertFalse( class_exists( 'WP_Rest_Cache_Plugin\Includes\Plugin' ) );
	}

	public function test_autoload_includes_nothing_while_the_original_is_active() {
		$this->activate( array( self::ORIGINAL ) );
		$before = get_included_files();

		foreach ( self::allow_listed() as $row ) {
			Shim_Loader::autoload( $row[0] );
		}

		$this->assertSame( $before, get_included_files() );
	}

	/**
	 * The original's `Plugin` class gets declared after the guard answered "not blocked".
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_declared_original_class_keeps_the_shims_out_of_the_request() {
		$this->assertFalse( Coexistence_Guard::is_blocked(), 'Precondition: only Rockfire is active.' );

		require_once dirname( __DIR__ ) . '/support/fixture-original-plugin-class.php';

		$this->assertFalse( class_exists( 'WP_Rest_Cache_Plugin\Includes\Caching\Caching' ) );
		$this->assertFalse( class_exists( 'WP_Rest_Cache_Endpoint_Api' ) );

		$shims = wp_normalize_path( RFRAC_DIR . self::SHIMS );
		foreach ( get_included_files() as $file ) {
			$this->assertStringStartsNotWith( $shims, wp_normalize_path( $file ), 'No shim file is included.' );
		}
	}

	/**
	 * The memoised guard answer is stale once the original's class appears; the live check catches it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_resolve_checks_the_original_class_live_after_the_guard_was_memoised() {
		$this->assertFalse( Coexistence_Guard::is_blocked(), 'Precondition: the answer is memoised as not blocked.' );
		foreach ( self::allow_listed() as $row ) {
			$this->assertNotNull( Shim_Loader::resolve( $row[0] ), 'Precondition: ' . $row[0] );
		}

		require_once dirname( __DIR__ ) . '/support/fixture-original-plugin-class.php';

		$this->assertFalse( Coexistence_Guard::is_blocked(), 'The memoised answer is unchanged.' );
		foreach ( self::allow_listed() as $row ) {
			$this->assertNull( Shim_Loader::resolve( $row[0] ), $row[0] );
		}
	}

	/**
	 * The original's autoloader alone, without its `Plugin` class, is enough to hold the shims back.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_declared_original_autoloader_keeps_the_shims_out() {
		$this->assertFalse( Coexistence_Guard::is_blocked(), 'Precondition: only Rockfire is active.' );

		eval( 'namespace WP_Rest_Cache_Plugin\Includes; class Autoloader {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares the original's class for the test.

		foreach ( self::allow_listed() as $row ) {
			$this->assertNull( Shim_Loader::resolve( $row[0] ), $row[0] );
		}
	}
}
