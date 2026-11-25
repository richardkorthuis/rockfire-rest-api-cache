<?php
/**
 * Tag provider that collects nothing.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Serving;

defined( 'ABSPATH' ) || exit;

/**
 * Returns no tags; every response is stored without tags.
 *
 * @since 1.0.0
 */
final class Null_Tag_Provider implements Tag_Provider_Interface {

	/**
	 * No tags.
	 *
	 * @param \WP_REST_Request $request The main request.
	 * @param mixed            $data    The response data.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function collect( \WP_REST_Request $request, mixed $data ): array {
		return array();
	}

	/**
	 * Nothing to forget.
	 *
	 * @return void
	 */
	public function reset(): void {
	}
}
