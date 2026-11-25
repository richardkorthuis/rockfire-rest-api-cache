<?php
/**
 * Tests of the test library path resolution of the WooCommerce bootstrap. Needs no WordPress.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/functions-tests-dir.php';

/**
 * Where the bootstrap looks for the WooCommerce test library.
 *
 * @covers ::rfrac_wc_tests_resolve_tests_dir
 */
class Test_Wc_Tests_Dir extends TestCase {

	/**
	 * Resolve with a library that exists in exactly the given directories.
	 *
	 * @param string|false $env_dir   The WP_TESTS_DIR value.
	 * @param string       $temp_dir  The PHP temp directory.
	 * @param string[]     $installed Directories that hold the library.
	 *
	 * @return string
	 */
	private function resolve( $env_dir, string $temp_dir, array $installed ): string {
		return rfrac_wc_tests_resolve_tests_dir(
			$env_dir,
			$temp_dir,
			static fn( $dir ) => in_array( $dir, $installed, true )
		);
	}

	public function test_a_tmp_path_that_holds_the_library_is_used_as_given_when_the_temp_dir_is_elsewhere(): void {
		$this->assertSame(
			'/tmp/wordpress-tests-lib-wc',
			$this->resolve( '/tmp/wordpress-tests-lib-wc', '/var/folders/ab/T/', array( '/tmp/wordpress-tests-lib-wc' ) )
		);
	}

	public function test_a_tmp_path_without_the_library_moves_below_the_temp_dir_keeping_the_rest_of_the_path(): void {
		$temp = 'C:\\Users\\dev\\AppData\\Local\\Temp';

		$this->assertSame( $temp . '/wordpress-tests-lib-wc', $this->resolve( '/tmp/wordpress-tests-lib-wc', $temp, array() ) );
		$this->assertSame( $temp . '/custom/lib', $this->resolve( '/tmp/custom/lib', $temp . '\\', array() ) );
	}

	public function test_any_other_path_is_used_as_given(): void {
		$this->assertSame( 'C:/libs/wc', $this->resolve( 'C:/libs/wc', '/var/tmp', array() ) );
		$this->assertSame( '/tmpfoo/lib', $this->resolve( '/tmpfoo/lib', '/var/tmp', array() ) );
		$this->assertSame( '/opt/wc-lib', $this->resolve( '/opt/wc-lib', '/var/tmp', array( '/opt/wc-lib' ) ) );
	}

	public function test_an_unset_value_defaults_below_the_temp_dir(): void {
		$this->assertSame( '/var/tmp/wordpress-tests-lib-wc', $this->resolve( false, '/var/tmp/', array() ) );
		$this->assertSame( '/var/tmp/wordpress-tests-lib-wc', $this->resolve( '', '/var/tmp', array() ) );
	}
}
