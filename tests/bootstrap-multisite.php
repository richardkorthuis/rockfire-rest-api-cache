<?php
/**
 * Multisite PHPUnit bootstrap. Sets WP_TESTS_MULTISITE before delegating to the standard
 * bootstrap — WP's test lib reads that constant when bootstrapping and installs the network
 * (wp_blogs / wp_sitemeta) instead of a single-site WP.
 *
 * Pair this with a separate test DB and WP_TESTS_DIR so the single-site and multisite suites
 * don't fight over the same wp_options / wp_blogs state. See phpunit-multisite.xml.dist.
 *
 * @package Rockfire_Rest_Api_Cache
 */

if ( ! defined( 'WP_TESTS_MULTISITE' ) ) {
	define( 'WP_TESTS_MULTISITE', true );
}

// composer's `@putenv` sets WP_TESTS_DIR to a bash-style `/tmp/...` path that PHP on Windows
// can't open with file_exists/require. Rewrite it through sys_get_temp_dir() so it resolves
// to the same physical location bash mapped `/tmp/...` to (typically %TEMP%).
$wp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $wp_tests_dir || strpos( $wp_tests_dir, '/tmp/' ) === 0 ) {
	putenv( 'WP_TESTS_DIR=' . rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib-ms' );
}

require __DIR__ . '/bootstrap.php';

// Shared engine test support (bootstrap.php loads it too; require_once keeps this idempotent).
require_once __DIR__ . '/support/trait-drops-site-tables.php';
require_once __DIR__ . '/support/class-legacy-fixture.php';
require_once __DIR__ . '/support/class-rfrac-test-case.php';
require_once __DIR__ . '/support/class-query-recorder.php';
require_once __DIR__ . '/support/class-entry-fixtures.php';
require_once __DIR__ . '/support/class-policy-test-case.php';
require_once __DIR__ . '/support/class-test-exit.php';
require_once __DIR__ . '/support/class-serving-test-case.php';
require_once __DIR__ . '/support/class-spy-store.php';
require_once __DIR__ . '/support/class-serving-harness.php';
require_once __DIR__ . '/support/class-e2e-test-case.php';
require_once __DIR__ . '/support/class-matrix-test-case.php';
