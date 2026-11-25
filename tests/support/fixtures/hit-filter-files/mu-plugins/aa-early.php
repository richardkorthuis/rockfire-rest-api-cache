<?php
/**
 * Fixture: a must-use file whose name sorts before the early-cache file, so it is loaded before it.
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
function rfrac_fixture_aa_early( $value ) {
	return $value;
}
