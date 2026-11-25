<?php
/**
 * Contract of the collector that tags a response being stored.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Serving;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies the invalidation tags of the main REST response for {@see Capture}.
 *
 * Implemented by the tag collector of the invalidation layer; {@see Null_Tag_Provider} is the inert default.
 *
 * Contract with {@see Capture}: `collect()` is called at most once per main response, and Capture
 * normalises and deduplicates the returned tags itself (`Tags::normalize_many()`). Capture calls
 * `reset()` on every `rest_pre_echo_response`, also when nothing is stored (bypass, non-main
 * response, failure), so collected state never leaks into the next request of a long-running
 * process (WP-CLI). An implementation may still reset itself at the start of a request.
 *
 * @since 1.0.0
 */
interface Tag_Provider_Interface {

	/**
	 * Tags of the main request, draining the collected state.
	 *
	 * @param \WP_REST_Request $request The main request.
	 * @param mixed            $data    The response data about to be echoed (embedded data included).
	 *
	 * @return string[]|null Sorted unique tags, or null to signal that the response must not be stored.
	 * @phpstan-return list<string>|null
	 */
	public function collect( \WP_REST_Request $request, mixed $data ): ?array;

	/**
	 * Forget everything collected so far.
	 *
	 * @return void
	 */
	public function reset(): void;
}
