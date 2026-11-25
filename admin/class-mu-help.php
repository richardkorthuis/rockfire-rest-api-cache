<?php
/**
 * Shared texts about the must-use file: failure reasons and the manual install instructions.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Admin;

use Rockfire\Rest_Api_Cache\Content_Path;

defined( 'ABSPATH' ) || exit;

/**
 * The one place for the must-use failure reasons (by the code of
 * {@see \Rockfire\Rest_Api_Cache\Install\Mu_Installer::last_result()}) and the manual copy
 * instructions, used by the admin notice and the Tools page.
 *
 * @since 1.0.0
 */
final class Mu_Help {

	/**
	 * The translated, full-sentence reason of a failed install attempt.
	 *
	 * @param string $code Outcome code of the last install attempt.
	 *
	 * @return string The reason, or '' for an unknown code (or a success).
	 */
	public static function reason( string $code ): string {
		switch ( $code ) {
			case 'disabled':
				return __( 'Installing the must-use file is disabled by the RFRAC_DISABLE_MU_INSTALL constant.', 'rockfire-rest-api-cache' );
			case 'file_mods_disallowed':
				return __( 'WordPress does not allow file changes on this site (DISALLOW_FILE_MODS).', 'rockfire-rest-api-cache' );
			case 'not_writable':
				return __( 'The must-use plugins directory is not writable.', 'rockfire-rest-api-cache' );
			case 'not_direct':
				return __( 'WordPress cannot write files directly on this server (FS_METHOD is not "direct").', 'rockfire-rest-api-cache' );
			case 'filesystem_unavailable':
				return __( 'The WordPress filesystem is not available.', 'rockfire-rest-api-cache' );
			case 'template_unreadable':
				return __( 'The must-use template could not be read.', 'rockfire-rest-api-cache' );
			case 'write_failed':
				return __( 'The file could not be written.', 'rockfire-rest-api-cache' );
			case 'verify_failed':
				return __( 'The written file did not match the template.', 'rockfire-rest-api-cache' );
			case 'rename_failed':
				return __( 'The file could not be moved into place.', 'rockfire-rest-api-cache' );
		}
		return '';
	}

	/**
	 * Whether the failure of a code can only be solved by copying the file manually (or by an administrator of the server).
	 *
	 * @param string $code Outcome code of the last install attempt.
	 *
	 * @return bool
	 */
	public static function needs_manual_install( string $code ): bool {
		return in_array( $code, array( 'not_writable', 'not_direct', 'file_mods_disallowed' ), true );
	}

	/**
	 * Print the manual install instructions: copy the template and replace its two placeholders.
	 *
	 * No absolute server path is printed: see {@see Content_Path::shown()}. The sentence that the paths are relative to
	 * `wp-content` is printed only when both of them are.
	 *
	 * @param string $template Absolute path of the must-use template.
	 * @param string $target   Absolute path the must-use file is installed to.
	 *
	 * @return void
	 */
	public static function render_manual_instructions( string $template, string $target ): void {
		$relative = null !== Content_Path::relative( $template ) && null !== Content_Path::relative( $target );
		printf(
			'<p>%s</p>',
			sprintf(
				/* translators: 1: path of the must-use template, 2: path of the must-use file. */
				esc_html__( 'To install the must-use file manually, copy %1$s to %2$s.', 'rockfire-rest-api-cache' ),
				'<code class="rfrac-path">' . esc_html( Content_Path::shown( $template, ! $relative ) ) . '</code>',
				'<code class="rfrac-path">' . esc_html( Content_Path::shown( $target, ! $relative ) ) . '</code>'
			)
		);
		if ( $relative ) {
			printf( '<p class="rfrac-help">%s</p>', esc_html__( 'The paths are relative to the wp-content directory.', 'rockfire-rest-api-cache' ) );
		}
		printf(
			'<p>%s</p>',
			sprintf(
				/* translators: 1: version placeholder, 2: plugin version, 3: basename placeholder, 4: plugin basename. */
				esc_html__( 'In the copy, replace %1$s with %2$s and %3$s with %4$s.', 'rockfire-rest-api-cache' ),
				'<code>{{RFRAC_PLUGIN_VERSION}}</code>',
				'<code>' . esc_html( RFRAC_VERSION ) . '</code>',
				'<code>{{RFRAC_PLUGIN_BASENAME}}</code>',
				'<code>' . esc_html( plugin_basename( RFRAC_FILE ) ) . '</code>'
			)
		);
	}
}
