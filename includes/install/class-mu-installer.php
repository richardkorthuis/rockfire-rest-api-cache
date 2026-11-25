<?php
/**
 * Installs, refreshes and removes the must-use early-cache file.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Install;

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Names;

defined( 'ABSPATH' ) || exit;

/**
 * Atomic installer of `wp-content/mu-plugins/rfrac-early-cache.php`.
 *
 * Only called at defined moments (activation, the "Install must-use file" button, and a plugin upgrade
 * that finds an outdated copy built from the template), never from `admin_init`, an admin screen load,
 * AJAX or front-end hooks. It never asks for filesystem credentials and never produces output: when the
 * file cannot be written directly the plugin keeps working in fallback mode and the reason is stored
 * in the non-autoloaded `rfrac_mu_notice` option (`{status, code, time}`, displayed in the admin).
 *
 * Whether an install can go ahead at all is decided in one place, {@see self::install_blocker()}: it is what
 * {@see self::install()} refuses on, and what an admin screen asks (it changes nothing) before it offers the install.
 *
 * Every install path ({@see self::ensure_installed()}) deletes a file it could not replace when it
 * is not built from a template (the loader written by the legacy activator, which would keep
 * running the legacy serving code), so the plugin runs in fallback mode instead.
 *
 * @since 1.0.0
 */
final class Mu_Installer {

	/**
	 * Version of the must-use template this plugin expects (equals the `RFRAC Template:` header).
	 *
	 * @var int
	 */
	public const TEMPLATE_VERSION = 1;

	/**
	 * Non-autoloaded option holding the outcome of the last install attempt.
	 *
	 * @var string
	 */
	public const OPTION_NOTICE = 'rfrac_mu_notice';

	/**
	 * Placeholder of the plugin version in the template.
	 *
	 * @var string
	 */
	private const PLACEHOLDER_VERSION = '{{RFRAC_PLUGIN_VERSION}}';

	/**
	 * Placeholder (including its quotes) of the plugin basename in the template.
	 *
	 * @var string
	 */
	private const PLACEHOLDER_BASENAME = "'{{RFRAC_PLUGIN_BASENAME}}'";

	/**
	 * Plugin basename, e.g. `rockfire-rest-api-cache/rockfire-rest-api-cache.php`.
	 *
	 * @var string
	 */
	private string $plugin_basename;

	/**
	 * Absolute path of the template file.
	 *
	 * @var string
	 */
	private string $template_path;

	/**
	 * Plugin version stamped into the file header.
	 *
	 * @var string
	 */
	private string $plugin_version;

	/**
	 * Directory the file is installed into.
	 *
	 * @var string
	 */
	private string $mu_dir;

	/**
	 * Cached desired content.
	 *
	 * @var string|null
	 */
	private ?string $desired = null;

	/**
	 * Outcome of the last install attempt in this request.
	 *
	 * @var array{status:string,code:string,time:int}|null
	 */
	private ?array $last_result = null;

	/**
	 * Constructor.
	 *
	 * @param string      $plugin_basename Plugin basename.
	 * @param string      $template_path   Absolute path of the template file.
	 * @param string      $plugin_version  Plugin version.
	 * @param string|null $mu_dir          Install directory; defaults to `WPMU_PLUGIN_DIR` (a seam for tests).
	 */
	public function __construct( string $plugin_basename, string $template_path, string $plugin_version, ?string $mu_dir = null ) {
		$this->plugin_basename = $plugin_basename;
		$this->template_path   = $template_path;
		$this->plugin_version  = $plugin_version;
		$this->mu_dir          = rtrim( $mu_dir ?? WPMU_PLUGIN_DIR, '/\\' );
	}

	/**
	 * Absolute path of the installed file.
	 *
	 * @return string
	 */
	public function target_path(): string {
		return $this->mu_dir . '/' . Names::MU_FILE;
	}

	/**
	 * Absolute path of the template.
	 *
	 * @return string
	 */
	public function template_path(): string {
		return $this->template_path;
	}

	/**
	 * The content the installed file should have: the template with its placeholders replaced.
	 *
	 * @return string|null Null when the template cannot be read.
	 */
	public function desired_content(): ?string {
		if ( null !== $this->desired ) {
			return $this->desired;
		}

		if ( ! is_readable( $this->template_path ) ) {
			return null;
		}
		$template = file_get_contents( $this->template_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a plugin-owned template file.
		if ( false === $template ) {
			return null;
		}

		// The version lives in a header comment: keep it on one line and unable to close the comment.
		$version       = trim( str_replace( '*/', '', (string) preg_replace( '/[\r\n]+/', ' ', $this->plugin_version ) ) );
		$this->desired = str_replace(
			array( self::PLACEHOLDER_VERSION, self::PLACEHOLDER_BASENAME ),
			array( $version, var_export( $this->plugin_basename, true ) ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generating PHP source.
			$template
		);

		return $this->desired;
	}

	/**
	 * Current state of the installed file.
	 *
	 * @return Mu_Status
	 */
	public function status(): Mu_Status {
		if ( Config::instance()->mu_install_disabled() ) {
			return Mu_Status::DISABLED;
		}

		$target = $this->target_path();
		clearstatcache( true, $target );
		if ( ! is_file( $target ) ) {
			return Mu_Status::MISSING;
		}

		$desired = $this->desired_content();
		if ( null !== $desired && md5( $desired ) === md5_file( $target ) ) {
			return Mu_Status::CURRENT;
		}

		return Mu_Status::OUTDATED;
	}

	/**
	 * Serving mode: `early` only when the file is current, otherwise `fallback`.
	 *
	 * @return string `early` or `fallback`.
	 */
	public function mode(): string {
		return Mu_Status::CURRENT === $this->status() ? 'early' : 'fallback';
	}

	/**
	 * Outcome of the last install attempt (this request, else the stored one).
	 *
	 * @return array{status:string,code:string,time:int}|null
	 */
	public function last_result(): ?array {
		if ( null !== $this->last_result ) {
			return $this->last_result;
		}

		$stored = get_option( self::OPTION_NOTICE, null );
		if ( is_array( $stored ) && isset( $stored['status'], $stored['code'], $stored['time'] ) ) {
			return array(
				'status' => (string) $stored['status'],
				'code'   => (string) $stored['code'],
				'time'   => (int) $stored['time'],
			);
		}

		return null;
	}

	/**
	 * Why the file cannot be installed from here, or null when {@see self::install()} can go ahead.
	 *
	 * The one owner of the preconditions of an install, in the order {@see self::install()} refuses them (the codes of
	 * {@see self::last_result()}): `disabled` (`RFRAC_DISABLE_MU_INSTALL`), `file_mods_disallowed` (`DISALLOW_FILE_MODS` or
	 * the `file_mod_allowed` filter), `template_unreadable`, `not_writable` (neither the directory nor, when it does not
	 * exist yet, the nearest existing directory above it can be written), `not_direct` (the WordPress filesystem method is
	 * not `direct`) and `filesystem_unavailable`. Creates no directory and writes no file (but for the short-lived probe file
	 * WordPress itself writes to detect the filesystem method when `FS_METHOD` is not defined), records nothing and asks for
	 * no credentials, so an admin notice may call it to decide whether to offer the install.
	 *
	 * @return string|null The code of the first refusing precondition; null when none refuses.
	 */
	public function install_blocker(): ?string {
		if ( Config::instance()->mu_install_disabled() ) {
			return 'disabled';
		}
		if ( ! self::file_mods_allowed() ) {
			return 'file_mods_disallowed';
		}
		if ( null === $this->desired_content() ) {
			return 'template_unreadable';
		}

		// A directory that does not exist yet is created by the install: it needs a writable directory above it.
		$directory = $this->existing_directory();
		if ( null === $directory || ! wp_is_writable( $directory ) ) {
			return 'not_writable';
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( 'direct' !== get_filesystem_method( array(), $directory ) ) {
			return 'not_direct';
		}

		return null === $this->direct_filesystem( $directory ) ? 'filesystem_unavailable' : null;
	}

	/**
	 * Write the file atomically: temp file with a unique name, checksum verification, rename.
	 *
	 * Refused with the code of {@see self::install_blocker()} when that names a precondition that does not hold, for
	 * instance `disabled` when `RFRAC_DISABLE_MU_INSTALL` is set and `file_mods_disallowed` when WordPress disallows
	 * file modifications (`DISALLOW_FILE_MODS` or the `file_mod_allowed` filter).
	 *
	 * @return Mu_Status The status after the attempt; the reason is in {@see self::last_result()}.
	 */
	public function install(): Mu_Status {
		$blocker = $this->install_blocker();
		if ( null !== $blocker ) {
			return $this->finish( $blocker );
		}

		// Not null: the blocker refuses an unreadable template.
		$desired = (string) $this->desired_content();

		if ( ! is_dir( $this->mu_dir ) ) {
			wp_mkdir_p( $this->mu_dir );
		}
		if ( ! is_dir( $this->mu_dir ) || ! wp_is_writable( $this->mu_dir ) ) {
			return $this->finish( 'not_writable' );
		}

		// Credentials are never requested: with the `direct` method none are needed.
		$filesystem = $this->direct_filesystem( $this->mu_dir );
		if ( null === $filesystem ) {
			return $this->finish( 'filesystem_unavailable' );
		}

		$target = $this->target_path();
		$temp   = $target . '.tmp-' . bin2hex( random_bytes( 6 ) );
		$mode   = defined( 'FS_CHMOD_FILE' ) ? (int) constant( 'FS_CHMOD_FILE' ) : 0644;

		if ( ! $filesystem->put_contents( $temp, $desired, $mode ) ) {
			wp_delete_file( $temp );
			return $this->finish( 'write_failed' );
		}

		clearstatcache( true, $temp );
		if ( md5( $desired ) !== md5_file( $temp ) ) {
			wp_delete_file( $temp );
			return $this->finish( 'verify_failed' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem::move() is not atomic (it copies and deletes); a same-directory rename() replaces the target in one step, and its failure warning must never be output.
		if ( ! @rename( $temp, $target ) ) {
			wp_delete_file( $temp );
			return $this->finish( 'rename_failed' );
		}

		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $target, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- May be restricted by the host.
		}

		return $this->finish( 'installed' );
	}

	/**
	 * Install the file; when it could not be replaced and is not built from a template (the legacy
	 * loader, without an `RFRAC Template:` header), delete it.
	 *
	 * @return Mu_Status
	 */
	public function ensure_installed(): Mu_Status {
		$status = $this->install();
		if ( Mu_Status::OUTDATED !== $status || $this->is_template_file() ) {
			return $status;
		}
		$this->remove( true );
		return $this->status();
	}

	/**
	 * Whether the current user may write the must-use file from the admin.
	 *
	 * The file is shared by every site of a network, so on multisite only a network administrator
	 * (`manage_network_plugins`) may install it; on a single site `activate_plugins` is required.
	 *
	 * @return bool
	 */
	public static function current_user_can_install(): bool {
		return current_user_can( is_multisite() ? 'manage_network_plugins' : 'activate_plugins' );
	}

	/**
	 * Whether WordPress allows file modifications for the must-use install (`DISALLOW_FILE_MODS`, `file_mod_allowed`).
	 *
	 * @return bool
	 */
	public static function file_mods_allowed(): bool {
		return wp_is_file_mod_allowed( 'rfrac_mu_install' );
	}

	/**
	 * The install directory when it exists, else the nearest existing directory above it (the one that must be writable
	 * for {@see self::install()} to create the install directory).
	 *
	 * @return string|null Null when something that is not a directory stands in the way.
	 */
	private function existing_directory(): ?string {
		$directory = $this->mu_dir;
		while ( ! is_dir( $directory ) ) {
			$parent = dirname( $directory );
			if ( file_exists( $directory ) || $parent === $directory ) {
				return null;
			}
			$directory = $parent;
		}
		return $directory;
	}

	/**
	 * Set up the WordPress filesystem for a directory, without asking for credentials.
	 *
	 * @param string $directory Directory the filesystem is set up for.
	 *
	 * @return \WP_Filesystem_Base|null The `direct` filesystem; null when it cannot be set up.
	 */
	private function direct_filesystem( string $directory ): ?\WP_Filesystem_Base {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem( false, $directory ) ) {
			return null;
		}
		global $wp_filesystem;
		return $wp_filesystem instanceof \WP_Filesystem_Base && 'direct' === $wp_filesystem->method ? $wp_filesystem : null;
	}

	/**
	 * Whether the installed file carries an `RFRAC Template:` header.
	 *
	 * @return bool
	 */
	private function is_template_file(): bool {
		$header = get_file_data( $this->target_path(), array( 'template' => 'RFRAC Template' ) );
		return '' !== ( $header['template'] ?? '' );
	}

	/**
	 * Refresh an outdated copy after this plugin was updated (hook: `upgrader_process_complete`).
	 *
	 * Runs for any updater, including automatic updates without a current user, and acts only
	 * when this plugin is among the updated ones: bulk updates pass `plugins` (a list), single
	 * updates and automatic updates pass `plugin` (a basename). Only an outdated file built from a
	 * template (carrying `RFRAC Template:`) is rewritten; a missing file stays missing and any other
	 * file (a legacy loader, a current copy) is left untouched.
	 *
	 * @param mixed               $upgrader Upgrader instance (unused).
	 * @param array<string,mixed> $extra    Update details.
	 *
	 * @return void
	 */
	public function maybe_repair_after_upgrade( $upgrader, array $extra ): void {
		unset( $upgrader );

		if ( ( $extra['type'] ?? '' ) !== 'plugin' ) {
			return;
		}
		$plugins = isset( $extra['plugins'] ) && is_array( $extra['plugins'] ) ? $extra['plugins'] : array();
		if ( isset( $extra['plugin'] ) && is_string( $extra['plugin'] ) ) {
			$plugins[] = $extra['plugin'];
		}
		if ( ! in_array( $this->plugin_basename, $plugins, true ) ) {
			return;
		}

		if ( Mu_Status::OUTDATED === $this->status() && $this->is_template_file() ) {
			$this->install();
		}
	}

	/**
	 * Remove the file.
	 *
	 * Without `$force` on multisite the file is kept while the plugin is network-active or active
	 * on any site other than the current one (the site being deactivated).
	 *
	 * @param bool $force        Remove regardless of other sites.
	 * @param bool $network_wide Deactivation is network-wide: the network-active entry is ignored.
	 *
	 * @return bool True when the file is gone afterwards; false when it was kept or could not be deleted.
	 */
	public function remove( bool $force = false, bool $network_wide = false ): bool {
		$target = $this->target_path();
		clearstatcache( true, $target );
		if ( ! file_exists( $target ) ) {
			return true;
		}

		if ( ! $force && is_multisite() && $this->active_elsewhere( $network_wide ) ) {
			return false;
		}

		wp_delete_file( $target );
		clearstatcache( true, $target );

		return ! file_exists( $target );
	}

	/**
	 * Whether the plugin is network-active or active on a site other than the current one.
	 *
	 * @param bool $ignore_network Ignore the network-active entry.
	 *
	 * @return bool
	 */
	private function active_elsewhere( bool $ignore_network ): bool {
		if ( ! $ignore_network ) {
			$network = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network ) && isset( $network[ $this->plugin_basename ] ) ) {
				return true;
			}
		}

		$current = get_current_blog_id();
		$offset  = 0;
		$batch   = Sites::BATCH;
		do {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => Sites::BATCH,
					'offset' => $offset,
				)
			);
			foreach ( $site_ids as $site_id ) {
				if ( (int) $site_id === $current ) {
					continue;
				}
				$active = get_blog_option( (int) $site_id, 'active_plugins', array() );
				if ( is_array( $active ) && in_array( $this->plugin_basename, $active, true ) ) {
					return true;
				}
			}
			$offset += Sites::BATCH;
			$more    = count( $site_ids );
		} while ( $batch === $more );

		return false;
	}

	/**
	 * Record the outcome of an install attempt and return the resulting status.
	 *
	 * @param string $code Outcome code.
	 *
	 * @return Mu_Status
	 */
	private function finish( string $code ): Mu_Status {
		$status = $this->status();

		$this->last_result = array(
			'status' => $status->value,
			'code'   => $code,
			'time'   => time(),
		);
		update_option( self::OPTION_NOTICE, $this->last_result, false );

		return $status;
	}
}
