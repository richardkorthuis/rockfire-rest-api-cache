<?php
/**
 * Per-site iteration helper.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a callback for the current site, or for every site of a multisite network.
 *
 * @since 1.0.0
 */
final class Sites {

	/**
	 * Number of sites fetched per query when walking a network.
	 *
	 * @var int
	 */
	public const BATCH = 100;

	/**
	 * Call the callback once on a single site, or once per site (switched to) on multisite.
	 *
	 * The previous blog is always restored, even if the callback throws.
	 *
	 * @param callable $callback Callback; it receives no arguments (use get_current_blog_id()).
	 *
	 * @return void
	 */
	public static function each( callable $callback ): void {
		if ( ! is_multisite() ) {
			$callback();
			return;
		}

		$offset = 0;
		do {
			$site_ids = get_sites(
				[
					'fields' => 'ids',
					'number' => self::BATCH,
					'offset' => $offset,
				]
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					$callback();
				} finally {
					restore_current_blog();
				}
			}

			$offset += self::BATCH;
			$more    = self::BATCH === count( $site_ids );
		} while ( $more );
	}
}
