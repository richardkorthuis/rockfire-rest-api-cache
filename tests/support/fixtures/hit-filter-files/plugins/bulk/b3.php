<?php
/**
 * Fixture: one of six plugin files that each hook a callback to the same filter.
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
function rfrac_fixture_bulk_3( $value ) {
	return $value;
}
