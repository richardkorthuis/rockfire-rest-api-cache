<?php
/**
 * The marker that another plugin was switched on or off.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Services;

use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Install\Sites;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Storage\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers, per site, that another plugin was activated or deactivated, until the cache is flushed in full.
 *
 * The marker is the full-flush epoch of the site at the moment of the change, kept in a non-autoloaded option. It is
 * pending while it equals the live epoch, so any full flush (which advances the epoch) clears it without touching it;
 * a dismissal deletes it. Nothing is flushed here: the admin shows a notice that offers the flush.
 *
 * @since 1.0.0
 */
class Plugin_Change {

	/**
	 * Repository (the full-flush epoch).
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Basename of this plugin.
	 *
	 * @var string
	 */
	private string $own_basename;

	/**
	 * Constructor.
	 *
	 * @param Repository $repository   Repository.
	 * @param string     $own_basename Basename of this plugin, e.g. `rockfire-rest-api-cache/rockfire-rest-api-cache.php`.
	 */
	public function __construct( Repository $repository, string $own_basename ) {
		$this->repository   = $repository;
		$this->own_basename = $own_basename;
	}

	/**
	 * `activated_plugin` and `deactivated_plugin`: mark the affected sites.
	 *
	 * A network-wide change marks every site when this plugin is network-active (it runs on all of them), otherwise
	 * only the current site. This plugin's own (de)activation and anything that is not a plugin basename are ignored.
	 *
	 * A site of a network-wide change whose marker cannot be written does not stop the walk: the failure is recorded on
	 * that site (see {@see self::mark_recording_failure()}) and the remaining sites are still marked.
	 *
	 * @param mixed $plugin       Basename of the plugin that changed.
	 * @param mixed $network_wide Whether the change is network-wide.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the full-flush epoch of the current site cannot be read (not a network-wide walk).
	 */
	public function on_plugin_changed( mixed $plugin = '', mixed $network_wide = false ): void {
		if ( ! is_string( $plugin ) || '' === $plugin || $this->own_basename === $plugin ) {
			return;
		}
		if ( (bool) $network_wide && $this->is_network_active() ) {
			Sites::each( fn() => $this->mark_recording_failure() );
			return;
		}
		$this->mark();
	}

	/**
	 * Mark the current site: store the live full-flush epoch.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the full-flush epoch cannot be read.
	 */
	public function mark(): void {
		$epoch = $this->repository->flush_all_epoch();
		if ( null === $epoch ) {
			throw new \RuntimeException( 'The full-flush epoch could not be read.' );
		}
		update_option( Names::OPTION_PLUGIN_CHANGED, (string) $epoch, false );
	}

	/**
	 * Mark the current site, recording instead of throwing when that fails.
	 *
	 * Used for each site of a network-wide walk, so one site that cannot be marked does not leave the sites after it
	 * unmarked. The failure is recorded on the site itself (the recorder keeps its option per site), in a context that
	 * names the site.
	 *
	 * @return void
	 */
	private function mark_recording_failure(): void {
		try {
			$this->mark();
		} catch ( \Throwable $e ) {
			Failure_Recorder::record( 'plugin_change:site-' . get_current_blog_id(), $e );
		}
	}

	/**
	 * Whether a plugin change is waiting for a flush on the current site: the marker equals the live full-flush epoch.
	 *
	 * Reads only.
	 *
	 * @return bool
	 */
	public function pending(): bool {
		$stored = get_option( Names::OPTION_PLUGIN_CHANGED, null );
		if ( ! is_numeric( $stored ) ) {
			return false;
		}
		$epoch = $this->repository->flush_all_epoch();
		return null !== $epoch && (int) $stored === $epoch;
	}

	/**
	 * Forget the marker of the current site.
	 *
	 * @return void
	 */
	public function dismiss(): void {
		delete_option( Names::OPTION_PLUGIN_CHANGED );
	}

	/**
	 * Whether this plugin is network-active.
	 *
	 * @return bool
	 */
	private function is_network_active(): bool {
		if ( ! is_multisite() ) {
			return false;
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active_for_network( $this->own_basename );
	}
}
