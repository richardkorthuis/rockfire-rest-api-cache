<?php
/**
 * Exception standing in for `exit` in tests.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by an injected terminate closure so a test can observe that the request ended.
 */
final class Rfrac_Test_Exit extends RuntimeException {
}
