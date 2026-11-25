<?php
/**
 * Stand-in for the main class of the original plugin.
 *
 * A test requires this file, in a process of its own, to make the original look loaded. It is deliberately not
 * required by tests/bootstrap.php: declaring the class there would turn every test into one that runs beside
 * the original.
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace WP_Rest_Cache_Plugin\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * The declaration is all the detection looks at.
 */
class Plugin {
}
