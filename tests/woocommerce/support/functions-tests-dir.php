<?php
/**
 * Resolves the directory of the WooCommerce test library. Loaded by the bootstrap before WordPress, so it
 * depends on nothing but PHP.
 *
 * @package Rockfire_Rest_Api_Cache
 */

/**
 * Resolves the WooCommerce test library directory from the `WP_TESTS_DIR` value.
 *
 * Composer's `@putenv` hands over a bash-style `/tmp/...` path. PHP on Windows reads that as `<drive>:\tmp\...`,
 * while bash put the library below the temp directory, so such a path is moved below the temp directory, keeping
 * the rest of it, only when the library is not found at the given path. Where `/tmp` is real (Linux, macOS, the
 * CI runner) the given path is kept. Any other path is kept as given.
 *
 * @param string|false $env_dir        The `WP_TESTS_DIR` value; false or empty when it is not set.
 * @param string       $temp_dir       The PHP temp directory, see sys_get_temp_dir().
 * @param callable     $library_exists Receives a directory, returns whether it holds the test library.
 *
 * @return string The directory to load the test library from.
 */
function rfrac_wc_tests_resolve_tests_dir( $env_dir, string $temp_dir, callable $library_exists ): string {
	$temp_dir = rtrim( $temp_dir, '/\\' );

	if ( ! $env_dir ) {
		return $temp_dir . '/wordpress-tests-lib-wc';
	}

	if ( 0 === strpos( $env_dir, '/tmp/' ) && ! $library_exists( $env_dir ) ) {
		return $temp_dir . '/' . substr( $env_dir, strlen( '/tmp/' ) );
	}

	return $env_dir;
}
