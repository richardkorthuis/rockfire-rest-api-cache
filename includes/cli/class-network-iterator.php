<?php
/**
 * Runs a WP-CLI action on every site of a network where the plugin is active.
 *
 * @since 1.0.0
 *
 * @package    Rockfire_Rest_Api_Cache
 * @subpackage Rockfire_Rest_Api_Cache/Cli
 */

namespace Rockfire\Rest_Api_Cache\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Iterates the sites of a multisite network for the `--network` flag.
 *
 * @since 1.0.0
 */
class Network_Iterator {

	/**
	 * Plugin basename, e.g. `rockfire-rest-api-cache/rockfire-rest-api-cache.php`.
	 *
	 * @var string
	 */
	private string $basename;

	/**
	 * Constructor.
	 *
	 * @param string $basename Plugin basename.
	 */
	public function __construct( string $basename ) {
		$this->basename = $basename;
	}

	/**
	 * Call the callback once per site where the plugin is active, switched to that site.
	 *
	 * The previous site is always restored, even when the callback throws.
	 *
	 * @param callable $callback Receives the blog ID.
	 * @phpstan-param callable(int): void $callback
	 *
	 * @return int Number of sites the callback ran on (0 when the plugin is active on no site).
	 */
	public function each( callable $callback ): int {
		if ( ! is_multisite() ) {
			\WP_CLI::error( __( '--network requires multisite.', 'rockfire-rest-api-cache' ) );
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$network_active = is_plugin_active_for_network( $this->basename );

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		$visited = 0;
		foreach ( $site_ids as $site_id ) {
			$site_id = (int) $site_id;
			switch_to_blog( $site_id );
			try {
				if ( $network_active || $this->is_active_on_current_site() ) {
					$callback( $site_id );
					++$visited;
				}
			} finally {
				restore_current_blog();
			}
		}
		return $visited;
	}

	/**
	 * Whether the plugin is in the `active_plugins` option of the current site.
	 *
	 * @return bool
	 */
	private function is_active_on_current_site(): bool {
		$active = get_option( 'active_plugins', array() );
		return is_array( $active ) && in_array( $this->basename, $active, true );
	}
}
