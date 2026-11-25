<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Rockfire_Rest_Api_Cache
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/rockfire-rest-api-cache.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";

// Shared engine test support (excluded from the testsuites; loaded after the WP test lib so WP_UnitTestCase exists).
require_once __DIR__ . '/support/trait-drops-site-tables.php';
require_once __DIR__ . '/support/class-legacy-fixture.php';
require_once __DIR__ . '/support/trait-fails-option-writes.php';
require_once __DIR__ . '/support/class-rfrac-test-case.php';
require_once __DIR__ . '/support/class-query-recorder.php';
require_once __DIR__ . '/support/class-entry-fixtures.php';
require_once __DIR__ . '/support/class-policy-test-case.php';
require_once __DIR__ . '/support/class-test-exit.php';
require_once __DIR__ . '/support/class-serving-test-case.php';
require_once __DIR__ . '/support/class-spy-store.php';
require_once __DIR__ . '/support/class-fake-hit-filters.php';
require_once __DIR__ . '/support/class-serving-harness.php';
require_once __DIR__ . '/support/class-e2e-test-case.php';
require_once __DIR__ . '/support/class-matrix-test-case.php';
require_once __DIR__ . '/support/class-redirect-intercepted.php';
require_once __DIR__ . '/support/trait-admin-test-helpers.php';

// Create the engine tables once, before any test transaction starts (DDL commits implicitly).
( new \Rockfire\Rest_Api_Cache\Schema\Migrator() )->install_site();
