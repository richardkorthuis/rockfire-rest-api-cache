<?php
/**
 * Contract of a source of constant values that apply below the `RFRAC_*` constants.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies setting values that {@see Config} treats as the value of a constant that is not defined.
 *
 * A provider is registered with {@see Config::set_fallback_constants()}. {@see Config} asks it on every read
 * of the constants, so an implementation must be cheap and must not write or filter anything. A value is only
 * used for a setting whose own `RFRAC_*` constant is not defined, and never for `enabled`.
 *
 * @since 1.0.0
 */
interface Fallback_Constants_Interface {

	/**
	 * The fallback values, by settings key.
	 *
	 * `value` has the shape the `RFRAC_*` constant of the key would hold and goes through the same
	 * normalisation. `label` names what supplies the value; it is what the key is locked by and what an
	 * invalid value is reported under.
	 *
	 * @return array<string,array{value:mixed,label:string}>
	 */
	public function values(): array;
}
