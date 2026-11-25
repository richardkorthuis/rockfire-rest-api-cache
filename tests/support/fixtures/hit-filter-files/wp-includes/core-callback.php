<?php
/**
 * Fixture: a file under the core includes directory.
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
function rfrac_fixture_core_callback( $value ) {
	return $value;
}
