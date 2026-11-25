<?php
/**
 * Fixture: a regular plugin file with callbacks of every shape.
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
function rfrac_fixture_plugin_callback( $value ) {
	return $value;
}

/**
 * A class with a static method, an instance method and an invokable instance.
 */
class Rfrac_Fixture_Acme_Callbacks {

	/**
	 * A static method.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	public static function filter_static( $value ) {
		return $value;
	}

	/**
	 * An instance method.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	public function filter_instance( $value ) {
		return $value;
	}

	/**
	 * The invokable form.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	public function __invoke( $value ) {
		return $value;
	}
}

/**
 * A closure declared in this file.
 *
 * @return Closure
 */
function rfrac_fixture_plugin_closure(): Closure {
	return static fn( $value ) => $value;
}
