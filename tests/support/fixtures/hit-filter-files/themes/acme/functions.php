<?php
/**
 * Fixture: the functions file of a theme.
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
function rfrac_fixture_theme_callback( $value ) {
	return $value;
}
