<?php
/**
 * Paths as shown to the user: relative to the content directory, never absolute.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that turns a file path into text safe to print: nothing here reveals the layout of the server.
 *
 * Used by the manual install instructions, Site Health and the legacy callback findings. It has no admin dependency and
 * registers no hooks; the result is plain text, which the caller escapes at output.
 *
 * @since 1.0.0
 */
final class Content_Path {

	/**
	 * The prefix of a path inside the content directory when it is shown with one.
	 *
	 * @var string
	 */
	private const PREFIX = 'wp-content/';

	/**
	 * A path relative to the `wp-content` directory (or to another base).
	 *
	 * @param string      $path Path of a file.
	 * @param string|null $base Directory the path is relative to; defaults to `WP_CONTENT_DIR`.
	 *
	 * @return string|null The normalised path without the base, or null when the path does not lie inside it.
	 */
	public static function relative( string $path, ?string $base = null ): ?string {
		$base = trailingslashit( wp_normalize_path( $base ?? WP_CONTENT_DIR ) );
		$path = wp_normalize_path( $path );
		return 0 === strpos( $path, $base ) ? substr( $path, strlen( $base ) ) : null;
	}

	/**
	 * A path as shown to the user: no absolute server path, whatever the location of the file.
	 *
	 * A path inside the base is shown relative to it, prefixed with `wp-content/` when asked to (a page that shows other
	 * paths next to it does not say that they are relative). Any other path (a symlinked plugin, `WP_PLUGIN_DIR` or
	 * `WPMU_PLUGIN_DIR` outside `wp-content`) is shown as its last directory and its file name behind an ellipsis, which
	 * reveals nothing of the server layout.
	 *
	 * @param string      $path        Path of a file.
	 * @param bool        $with_prefix Whether to prefix a path inside the base with `wp-content/`.
	 * @param string|null $base        Directory the path is relative to; defaults to `WP_CONTENT_DIR`.
	 *
	 * @return string
	 */
	public static function shown( string $path, bool $with_prefix = false, ?string $base = null ): string {
		$relative = self::relative( $path, $base );
		if ( null !== $relative ) {
			return $with_prefix ? self::PREFIX . $relative : $relative;
		}

		// Split by hand: `dirname()` and `basename()` treat a Windows drive differently per platform.
		$segments  = explode( '/', wp_normalize_path( $path ) );
		$file      = (string) array_pop( $segments );
		$directory = (string) array_pop( $segments );
		if ( '' !== $directory && '.' !== $directory && ! str_ends_with( $directory, ':' ) ) {
			$file = $directory . '/' . $file;
		}
		return "\u{2026}/" . $file;
	}
}
