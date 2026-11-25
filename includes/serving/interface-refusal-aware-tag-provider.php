<?php
/**
 * Optional extension of the tag provider: tells why it refused a response.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Serving;

use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;

defined( 'ABSPATH' ) || exit;

/**
 * A {@see Tag_Provider_Interface} that says why its last `collect()` returned null.
 *
 * {@see Capture} reports the reason in `X-Rfrac-Cache-Reason`. A provider without this interface
 * that returns null is reported as `filtered`.
 *
 * @since 1.0.0
 */
interface Refusal_Aware_Tag_Provider_Interface extends Tag_Provider_Interface {

	/**
	 * Why the last `collect()` returned null: `too_many_tags` on a tag limit overflow, `error` when
	 * the collection was incomplete because a collector hook failed. Null after a `collect()` that
	 * returned tags.
	 *
	 * @return Bypass_Reason|null
	 */
	public function refusal(): ?Bypass_Reason;
}
