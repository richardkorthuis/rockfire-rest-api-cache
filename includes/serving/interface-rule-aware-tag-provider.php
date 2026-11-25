<?php
/**
 * Optional extension of the tag provider: receives the route rule the policy applied.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Serving;

defined( 'ABSPATH' ) || exit;

/**
 * A {@see Tag_Provider_Interface} that wants to know which route rule the cache policy chose.
 *
 * Kept separate from the base interface so existing implementations stay valid. {@see Capture} calls
 * `use_rule_key()` right before `collect()` when the provider implements this interface.
 *
 * @since 1.0.0
 */
interface Rule_Aware_Tag_Provider_Interface extends Tag_Provider_Interface {

	/**
	 * Set the key of the route rule that applies to the response about to be collected.
	 *
	 * The key is forgotten by `collect()` and `reset()`.
	 *
	 * @param string|null $key Rule key (a registered route pattern or a wildcard key), null for none.
	 *
	 * @return void
	 */
	public function use_rule_key( ?string $key ): void;
}
