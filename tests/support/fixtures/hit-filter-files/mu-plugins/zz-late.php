<?php
/**
 * Fixture: a must-use file whose name sorts after the early-cache file, so it is loaded after it.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

/**
 * A callback of a hit filter.
 *
 * @param mixed $value The value.
 *
 * @return mixed
 */
function rfrac_fixture_zz_late( $value ) {
	return $value;
}
