<?php
/**
 * WooCommerce admin-context PHPUnit bootstrap. Defines `WP_ADMIN` before the WooCommerce bootstrap loads WordPress,
 * so every test process boots as a wp-admin request: `is_admin()` is true while WooCommerce and the plugin decide
 * their admin-only wiring, which both do once, at boot. It wraps `bootstrap-woocommerce.php` and never
 * forks it: the WooCommerce database, test library, core and HPOS mode are the ones of that suite.
 *
 * No screen is set: `is_admin()` is true from the constant alone, like on a wp-admin request before
 * `wp-admin/admin.php` sets a screen. The wp-admin includes are loaded because a wp-admin request has them.
 * See phpunit-woocommerce-admin.xml.dist.
 *
 * @package Rockfire_Rest_Api_Cache
 */

if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}

require __DIR__ . '/bootstrap-woocommerce.php';

// The functions of a wp-admin request that admin code calls without loading them itself (e.g. wp_terms_checklist()).
require_once ABSPATH . 'wp-admin/includes/admin.php';

// The install leaves the redirect to the WooCommerce setup wizard pending; no test must see it.
delete_transient( '_wc_activation_redirect' );

if ( ! is_admin() ) {
	echo 'The admin-context suite must boot with WP_ADMIN true; WP_ADMIN was defined false before its bootstrap.' . PHP_EOL;
	exit( 1 );
}

// The admin-context test support (excluded from the testsuite; loaded after the WooCommerce support it uses).
require_once __DIR__ . '/woocommerce/admin/support/class-wc-admin-test-case.php';
