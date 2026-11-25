<?php
/**
 * WooCommerce PHPUnit bootstrap. Wraps the standard bootstrap, never forks it: it points the run at the
 * WooCommerce-only test library and core, loads a real WooCommerce before the plugin does, installs it
 * once and fixes the HPOS (custom orders table) mode from the environment.
 *
 * WooCommerce 11.2 needs WordPress 7.0, so this suite has its own WordPress core (`WP_CORE_DIR`), its
 * own test library (`WP_TESTS_DIR`) and its own database. Install them with
 * `composer install-wp-tests-woocommerce -- <db> <user> <pass> <host> latest false <wc-version>`.
 * `RFRAC_WC_HPOS` selects the mode: empty or `0` runs with HPOS off, `1` with HPOS on.
 * See phpunit-woocommerce.xml.dist.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
use Rockfire\Rest_Api_Cache\Plugin;

if ( ! defined( 'RFRAC_WC_TESTS' ) ) {
	define( 'RFRAC_WC_TESTS', true );
}

require_once __DIR__ . '/woocommerce/support/functions-tests-dir.php';

// composer's `@putenv` sets WP_TESTS_DIR to a bash-style `/tmp/...` path. Where PHP can't find the library there
// (PHP on Windows reads it as <drive>:\tmp\...) it is moved below sys_get_temp_dir(), where bash put it
// (typically %TEMP%); a `/tmp/...` path that holds the library (Linux, macOS, CI) is used as given.
$rfrac_wc_tests_dir = rfrac_wc_tests_resolve_tests_dir(
	getenv( 'WP_TESTS_DIR' ),
	sys_get_temp_dir(),
	static fn( $dir ) => file_exists( "{$dir}/includes/functions.php" )
);
putenv( 'WP_TESTS_DIR=' . $rfrac_wc_tests_dir );

// HPOS mode: '' and '0' are off, '1' is on, anything else is a typo that would silently test the wrong mode.
$rfrac_wc_hpos = getenv( 'RFRAC_WC_HPOS' );
if ( false === $rfrac_wc_hpos || '' === $rfrac_wc_hpos ) {
	$rfrac_wc_hpos = '0';
}
if ( ! in_array( $rfrac_wc_hpos, array( '0', '1' ), true ) ) {
	echo "RFRAC_WC_HPOS must be empty, 0 (HPOS off) or 1 (HPOS on), got '{$rfrac_wc_hpos}'." . PHP_EOL;
	exit( 1 );
}
if ( ! defined( 'RFRAC_WC_HPOS' ) ) {
	define( 'RFRAC_WC_HPOS', '1' === $rfrac_wc_hpos );
}

if ( ! file_exists( "{$rfrac_wc_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$rfrac_wc_tests_dir}/includes/functions.php, have you run composer install-wp-tests-woocommerce ?" . PHP_EOL;
	exit( 1 );
}

// Give access to tests_add_filter() function (tests/bootstrap.php requires it again, harmlessly).
require_once "{$rfrac_wc_tests_dir}/includes/functions.php";

/**
 * Loads WooCommerce from the WooCommerce-only core. It is loaded by hand and never listed in
 * `active_plugins`: the plugin loader would include it a second time and fatal.
 */
function rfrac_wc_tests_load_woocommerce() {
	$main_file = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';

	if ( ! file_exists( $main_file ) ) {
		echo "Could not find {$main_file}, run install-wp-tests.sh with a WooCommerce version (composer install-wp-tests-woocommerce)." . PHP_EOL;
		exit( 1 );
	}

	// '7.0-alpha' sorts below every 7.0 pre-release, so only WordPress releases before 7.0 are rejected.
	if ( version_compare( $GLOBALS['wp_version'], '7.0-alpha', '<' ) ) {
		echo "WooCommerce 11.2 needs WordPress 7.0 or later, found {$GLOBALS['wp_version']}. Install the WooCommerce suite on the latest WordPress." . PHP_EOL;
		exit( 1 );
	}

	// WP_UnitTestCase wraps every test in a transaction; WooCommerce must not open nested ones.
	if ( ! defined( 'WC_USE_TRANSACTIONS' ) ) {
		define( 'WC_USE_TRANSACTIONS', false );
	}

	require $main_file;
}

/**
 * Installs WooCommerce into the test database, once, before any test transaction starts, and sets the HPOS mode.
 *
 * On a real site WooCommerce was installed by an earlier request, so the request the tests run in must not carry what
 * the plugin did while watching the install: the booted plugin's engine hooks are detached while it runs. Watching it,
 * the plugin turns the install's term inserts into invalidations, and these into route discoveries. In a wp-admin
 * request the WooCommerce module answers a discovery by initialising the Store API, which WooCommerce never does
 * there; its `rest_api_init` callbacks would then be in the hooks WP_UnitTestCase restores for every test, and every
 * REST server the admin-context suite builds would list the Store API routes.
 */
function rfrac_wc_tests_install_woocommerce() {
	static $installed = false;

	if ( $installed ) {
		return;
	}
	$installed = true;

	echo 'Installing WooCommerce ' . WC()->version . ' (HPOS ' . ( RFRAC_WC_HPOS ? 'on' : 'off' ) . ')...' . PHP_EOL;

	$engine_hooks = Plugin::instance()->engine_hooks();
	foreach ( $engine_hooks as $hook ) {
		remove_filter( $hook['hook'], $hook['callback'], $hook['priority'] );
	}

	try {
		WC_Install::install();

		// The install creates the HPOS tables only once WooCommerce has fired woocommerce_init, which is after setup_theme.
		wc_get_container()->get( DataSynchronizer::class )->create_database_tables();

		// Write the mode explicitly, so a shop-is-new heuristic never decides it, and keep the tables unsynchronised.
		update_option( CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, RFRAC_WC_HPOS ? 'yes' : 'no' );
		update_option( DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION, 'no' );
	} finally {
		foreach ( $engine_hooks as $hook ) {
			add_filter( $hook['hook'], $hook['callback'], $hook['priority'], $hook['args'] );
		}
	}

	// Initialize the WP_Roles object so the roles and capabilities WooCommerce added are known.
	$GLOBALS['wp_roles'] = null;
	wp_roles();
}

/*
 * The marker class WooCommerce's own test bootstrap defines. wc_get_product_visibility_term_ids() keeps the ids of the
 * product_visibility terms in a static variable and reads them again only when this class exists. The WordPress test
 * library deletes those terms after every test class and the test cases restore them with new ids (see
 * Rfrac_Wc_Test_Helpers::set_up_before_class()), so a remembered id would point at a deleted term.
 */
if ( ! class_exists( 'WC_Unit_Tests_Bootstrap', false ) ) {
	class_alias( stdClass::class, 'WC_Unit_Tests_Bootstrap' );
}

/**
 * Blocks outgoing HTTP requests: the suite must not depend on, or talk to, remote services.
 *
 * @return WP_Error
 */
function rfrac_wc_tests_block_http() {
	return new WP_Error( 'rfrac_wc_tests_http_blocked', 'Outgoing HTTP requests are blocked in the WooCommerce test suite.' );
}

/**
 * Drops the shutdown save of the customer WooCommerce creates while it boots.
 *
 * The WooCommerce test cases null `WC()->session` for every test, and saving a customer without a session raises a
 * "doing it wrong" notice when PHP shuts down at the end of the run. It runs before the first test, so the hook is
 * not among the hooks WP_UnitTestCase restores after every test.
 */
function rfrac_wc_tests_drop_customer_shutdown_save() {
	global $wp_filter;

	if ( ! isset( $wp_filter['shutdown'] ) ) {
		return;
	}
	foreach ( $wp_filter['shutdown']->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$function = $callback['function'];
			if ( is_array( $function ) && $function[0] instanceof WC_Customer ) {
				remove_action( 'shutdown', $function, $priority );
			}
		}
	}
}

tests_add_filter( 'muplugins_loaded', 'rfrac_wc_tests_load_woocommerce', 5 );
tests_add_filter( 'setup_theme', 'rfrac_wc_tests_install_woocommerce' );
tests_add_filter( 'pre_http_request', 'rfrac_wc_tests_block_http' );

require __DIR__ . '/bootstrap.php';

rfrac_wc_tests_drop_customer_shutdown_save();

// The WooCommerce test support (excluded from the testsuite; loaded after the engine support it extends).
require_once __DIR__ . '/woocommerce/support/trait-wc-test-helpers.php';
require_once __DIR__ . '/woocommerce/support/class-wc-e2e-test-case.php';
require_once __DIR__ . '/woocommerce/support/class-wc-matrix-test-case.php';
