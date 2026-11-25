<?php
/**
 * Autoload all plugin classes.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache;

defined( 'ABSPATH' ) || exit;

/**
 * PSR-4-like autoloader for the `Rockfire\Rest_Api_Cache` namespace.
 *
 * Convention (after the root namespace):
 * - a first segment `Admin` maps to the base directory `admin/`, anything else to `includes/`;
 * - every remaining namespace segment becomes a lowercase directory with `_` replaced by `-`;
 * - the class name `Foo_Bar` maps to `class-foo-bar.php`, `Foo_Interface` to `interface-foo.php`
 *   and `Foo_Trait` to `trait-foo.php` (enums use `class-*.php`).
 *
 * Uses no WordPress functions, so it works from the must-use phase and from `uninstall.php`.
 * A class whose file does not exist is silently ignored.
 *
 * @since 1.0.0
 */
class Autoloader {

	/**
	 * The root namespace handled by this autoloader (with trailing separator).
	 *
	 * @var string
	 */
	private const ROOT = 'Rockfire\\Rest_Api_Cache\\';

	/**
	 * Whether the autoloader has been registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Register the autoloader with SPL. Safe to call more than once.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		spl_autoload_register( [ self::class, 'autoload' ] );
		self::$registered = true;
	}

	/**
	 * Load the file for a class in the plugin namespace, if it exists.
	 *
	 * @param string $class_name The fully qualified class name.
	 *
	 * @return void
	 */
	public static function autoload( string $class_name ): void {
		$file = self::path_for( $class_name );
		if ( null !== $file && is_file( $file ) ) {
			include_once $file;
		}
	}

	/**
	 * Resolve the file a class, interface or trait of the plugin namespace lives in, without loading it.
	 *
	 * @param string $class_name The fully qualified class name.
	 *
	 * @return string|null The path (which may not exist), null for a name outside the plugin namespace.
	 */
	public static function path_for( string $class_name ): ?string {
		$class_name = ltrim( $class_name, '\\' );
		if ( 0 !== strncmp( $class_name, self::ROOT, strlen( self::ROOT ) ) ) {
			return null;
		}

		$relative = substr( $class_name, strlen( self::ROOT ) );
		// Only ASCII letters, digits and `_` per segment, no empty segment: a name that `class_exists()` passed on
		// with `/`, `.`, a NUL byte or an empty part could otherwise walk out of the plugin directories.
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+(?:\\\\[A-Za-z0-9_]+)*$/D', $relative ) ) {
			return null;
		}

		$segments   = explode( '\\', $relative );
		$short_name = array_pop( $segments );

		$base = 'includes';
		if ( isset( $segments[0] ) && 'Admin' === $segments[0] ) {
			$base = 'admin';
			array_shift( $segments );
		}

		$path = dirname( __DIR__ ) . '/' . $base . '/';
		foreach ( $segments as $segment ) {
			$path .= self::slug( $segment ) . '/';
		}

		return $path . self::file_name( $short_name );
	}

	/**
	 * Convert a namespace segment or class name to its lowercase, dash-separated form.
	 *
	 * @param string $name The segment or class name.
	 *
	 * @return string
	 */
	private static function slug( string $name ): string {
		return str_replace( '_', '-', strtolower( $name ) );
	}

	/**
	 * Build the file name for a class, interface or trait.
	 *
	 * @param string $short_name The short class name.
	 *
	 * @return string
	 */
	private static function file_name( string $short_name ): string {
		$types = [
			'Interface' => 'interface',
			'Trait'     => 'trait',
		];
		foreach ( $types as $suffix => $prefix ) {
			$ending = '_' . $suffix;
			if ( strlen( $short_name ) > strlen( $ending ) && substr( $short_name, -strlen( $ending ) ) === $ending ) {
				return $prefix . '-' . self::slug( substr( $short_name, 0, -strlen( $ending ) ) ) . '.php';
			}
		}

		return 'class-' . self::slug( $short_name ) . '.php';
	}
}
