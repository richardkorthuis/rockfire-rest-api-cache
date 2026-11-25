<?php
/**
 * Tests for the Autoloader — the spl_autoload_register callback that maps
 * `Rockfire\Rest_Api_Cache\…` class names to files under the plugin tree.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Autoloader;

/**
 * @covers \Rockfire\Rest_Api_Cache\Autoloader
 */
class Test_Autoloader extends WP_UnitTestCase {

	/**
	 * Call the private file-name mapper.
	 *
	 * @param string $short_name Short class name.
	 *
	 * @return string
	 */
	private function file_name( $short_name ) {
		$method = new ReflectionMethod( Autoloader::class, 'file_name' );
		$method->setAccessible( true );

		return $method->invoke( null, $short_name );
	}

	/**
	 * Assert that the class name resolves to exactly the expected, existing file.
	 *
	 * The classes are already loaded by the bootstrap, so the test checks the resolved path itself.
	 *
	 * @param string $class_name    Fully qualified class name.
	 * @param string $relative_path Expected file relative to the plugin root.
	 */
	private function assert_autoload_resolves_to( $class_name, $relative_path ) {
		$expected = realpath( dirname( __DIR__ ) . '/' . $relative_path );
		$this->assertNotFalse( $expected, "Fixture file {$relative_path} must exist" );

		$resolved = Autoloader::path_for( $class_name );
		$this->assertNotNull( $resolved );
		$this->assertSame( $expected, realpath( $resolved ) );
	}

	public function test_register_is_idempotent() {
		$count = static function () {
			return count(
				array_filter(
					spl_autoload_functions(),
					static fn( $loader ) => [ Autoloader::class, 'autoload' ] === $loader
				)
			);
		};

		$this->assertSame( 1, $count(), 'Plugin bootstrap must register the autoloader once' );

		Autoloader::register();
		Autoloader::register();

		$this->assertSame( 1, $count() );
	}

	public function test_autoload_is_a_noop_for_non_plugin_namespaced_classes() {
		$before = count( get_included_files() );

		Autoloader::autoload( 'Some\\Other\\Vendor\\Class_Name' );

		$this->assertSame( $before, count( get_included_files() ) );
	}

	public function test_autoload_resolves_interface_suffix_to_interface_file_on_disk() {
		$this->assert_autoload_resolves_to( 'Rockfire\\Rest_Api_Cache\\Storage\\Store_Interface', 'includes/storage/interface-store.php' );
	}

	public function test_autoload_resolves_top_level_class_to_includes() {
		$this->assert_autoload_resolves_to( 'Rockfire\\Rest_Api_Cache\\Names', 'includes/class-names.php' );

		$this->assertTrue( class_exists( '\Rockfire\\Rest_Api_Cache\\Names', false ) );
	}

	public function test_autoload_accepts_leading_backslash() {
		$this->assert_autoload_resolves_to( '\\Rockfire\\Rest_Api_Cache\\Plugin', 'includes/class-plugin.php' );
	}

	public function test_autoload_resolves_subnamespace_to_subdirectory() {
		$this->assert_autoload_resolves_to( 'Rockfire\\Rest_Api_Cache\\Storage\\Database_Store', 'includes/storage/class-database-store.php' );
		$this->assert_autoload_resolves_to( 'Rockfire\\Rest_Api_Cache\\Install\\Cleanup', 'includes/install/class-cleanup.php' );
		$this->assert_autoload_resolves_to( 'Rockfire\\Rest_Api_Cache\\Cli\\Command', 'includes/cli/class-command.php' );

		$this->assertTrue( class_exists( '\Rockfire\\Rest_Api_Cache\\Storage\\Database_Store', false ) );
	}

	public function test_autoload_maps_admin_namespace_to_admin_directory() {
		$this->assert_autoload_resolves_to( 'Rockfire\\Rest_Api_Cache\\Admin\\Admin', 'admin/class-admin.php' );
		$this->assert_autoload_resolves_to(
			'Rockfire\\Rest_Api_Cache\\Admin\\Pages\\Page_Interface',
			'admin/pages/interface-page.php'
		);
	}

	public function test_autoload_maps_trait_suffix_to_trait_file() {
		$this->assert_autoload_resolves_to(
			'Rockfire\\Rest_Api_Cache\\Invalidation\\Guarded_Trait',
			'includes/invalidation/trait-guarded.php'
		);

		$this->assertTrue( trait_exists( '\Rockfire\\Rest_Api_Cache\\Invalidation\\Guarded_Trait', false ) );
	}

	public function test_file_name_mapping_convention() {
		$this->assertSame( 'class-foo-bar.php', $this->file_name( 'Foo_Bar' ) );
		$this->assertSame( 'interface-foo.php', $this->file_name( 'Foo_Interface' ) );
		$this->assertSame( 'interface-foo-bar.php', $this->file_name( 'Foo_Bar_Interface' ) );
		$this->assertSame( 'trait-foo.php', $this->file_name( 'Foo_Trait' ) );
		$this->assertSame( 'class-api.php', $this->file_name( 'API' ) );
		// A bare `Trait` / `Interface` name has no stem, so it is treated as a class.
		$this->assertSame( 'class-trait.php', $this->file_name( 'Trait' ) );
	}

	public function test_path_for_returns_null_outside_the_plugin_namespace() {
		$this->assertNull( Autoloader::path_for( 'Some\\Other\\Class_Name' ) );
		$this->assertNull( Autoloader::path_for( 'Rockfire\\Rest_Api_Cache\\' ) );
		$this->assertStringEndsWith( '/includes/interface-nope.php', str_replace( '\\', '/', (string) Autoloader::path_for( 'Rockfire\\Rest_Api_Cache\\Nope_Interface' ) ) );
	}

	public function test_path_for_rejects_empty_and_dot_segments() {
		foreach ( array( 'Foo\\\\Bar', 'Foo\\.\\Bar', 'Foo\\..\\Bar', 'Foo\\..', 'Foo\\.', '..\\Foo' ) as $name ) {
			$this->assertNull( Autoloader::path_for( 'Rockfire\\Rest_Api_Cache\\' . $name ), $name );
		}
	}

	public function test_path_for_rejects_names_with_characters_outside_the_allowlist() {
		$names = array(
			'Config/../../../tmp\\Evil',
			'Config\\Evil/../Name',
			'Config\\..Evil',
			"Config\\Evil\0",
			'Config\\Evil Name',
			'Config\\Evil:Name',
			'Config\\Evil.php',
			'Config\\Evil' . "\n",
			'Config\\Évil',
			'Config\\',
		);
		foreach ( $names as $name ) {
			$this->assertNull( Autoloader::path_for( 'Rockfire\\Rest_Api_Cache\\' . $name ), json_encode( $name ) );
		}
	}

	public function test_path_for_still_accepts_names_made_of_letters_digits_and_underscores() {
		$this->assertStringEndsWith( '/includes/config/class-compiled-config.php', str_replace( '\\', '/', (string) Autoloader::path_for( 'Rockfire\\Rest_Api_Cache\\Config\\Compiled_Config' ) ) );
		$this->assertStringEndsWith( '/includes/v2/class-a1-b2.php', str_replace( '\\', '/', (string) Autoloader::path_for( 'Rockfire\\Rest_Api_Cache\\V2\\A1_B2' ) ) );
	}

	public function test_autoload_silently_skips_when_resolved_path_does_not_exist() {
		$before = count( get_included_files() );

		Autoloader::autoload( 'Rockfire\\Rest_Api_Cache\\Nope' );
		Autoloader::autoload( 'Rockfire\\Rest_Api_Cache\\Nope_Interface' );
		Autoloader::autoload( 'Rockfire\\Rest_Api_Cache\\Admin\\Nope\\Nope_Trait' );

		$this->assertSame( $before, count( get_included_files() ) );
		$this->assertFalse( class_exists( '\Rockfire\\Rest_Api_Cache\\Nope' ) );
	}

	public function test_requiring_main_file_twice_does_not_fatal() {
		$this->assertTrue( defined( 'RFRAC_VERSION' ) );

		$before = count( $GLOBALS['wp_filter']['init']->callbacks, COUNT_RECURSIVE );

		require dirname( __DIR__ ) . '/rockfire-rest-api-cache.php';

		// The double-load guard returns before a second Plugin instance registers its hooks.
		$this->assertSame( $before, count( $GLOBALS['wp_filter']['init']->callbacks, COUNT_RECURSIVE ) );
		$this->assertSame( '1.0.0', RFRAC_VERSION );
	}
}
