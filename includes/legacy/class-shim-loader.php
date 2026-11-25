<?php
/**
 * Conditional loader of the shims for the original plugin's public classes.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Autoloader that resolves exactly seven class names of the original WP REST Cache plugin to Rockfire shim
 * files, so that site code calling those classes keeps working once the original is removed.
 *
 * A name resolves only while the original is neither active nor loaded: the coexistence guard must not block
 * and neither its `Plugin` class nor its autoloader may be declared, also when that happens later in the same
 * request. The shims never replace a class of the original that is, or can still become, available.
 *
 * @since 1.0.0
 */
final class Shim_Loader {

	/**
	 * Autoloader class of the original plugin; its presence means the original is loading its own classes.
	 *
	 * @var string
	 */
	private const ORIGINAL_AUTOLOADER = 'WP_Rest_Cache_Plugin\Includes\Autoloader';

	/**
	 * The allow-list: lower-cased class name of the original => shim file below `includes/legacy/shims/`.
	 *
	 * @var array<string,string>
	 */
	private const MAP = array(
		'wp_rest_cache_plugin\includes\caching\caching'  => 'caching/class-caching.php',
		'wp_rest_cache_plugin\includes\api\endpoint_api' => 'api/class-endpoint-api.php',
		'wp_rest_cache_plugin\includes\controller\post_controller' => 'controller/class-post-controller.php',
		'wp_rest_cache_plugin\includes\controller\term_controller' => 'controller/class-term-controller.php',
		'wp_rest_cache_plugin\includes\controller\attachment_controller' => 'controller/class-attachment-controller.php',
		'wp_rest_cache_plugin\includes\controller\controller_trait' => 'controller/trait-controller.php',
		'wp_rest_cache_endpoint_api'                     => 'class-wp-rest-cache-endpoint-api.php',
	);

	/**
	 * Whether the loader has been registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Register the loader with SPL, after every autoloader that is already there. Safe to call more than once.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		spl_autoload_register( array( self::class, 'autoload' ), true, false );
		self::$registered = true;
	}

	/**
	 * Load the shim file of an allow-listed class, when the original is absent and the file exists.
	 *
	 * @param string $class_name The fully qualified class, interface or trait name.
	 *
	 * @return void
	 */
	public static function autoload( string $class_name ): void {
		$file = self::resolve( $class_name );
		if ( null !== $file && is_file( $file ) ) {
			include_once $file;
		}
	}

	/**
	 * The shim file for a class name, without loading it.
	 *
	 * The name is looked up in the allow-list first (case-insensitive, a leading backslash is ignored); a miss
	 * returns null without consulting the coexistence guard. A hit resolves only while the guard does not block
	 * and the original's `Plugin` class and autoloader are not declared. The file may not exist.
	 *
	 * @param string $class_name The fully qualified class, interface or trait name.
	 *
	 * @return string|null The absolute path of the shim file, null when the name must not be shimmed.
	 */
	public static function resolve( string $class_name ): ?string {
		$key = strtolower( ltrim( $class_name, '\\' ) );
		if ( ! isset( self::MAP[ $key ] ) ) {
			return null;
		}

		if ( Coexistence_Guard::is_blocked() ) {
			return null;
		}

		// The memoised guard cannot see a class that was declared after its first answer.
		if ( class_exists( Coexistence_Guard::ORIGINAL_CLASS, false ) || class_exists( self::ORIGINAL_AUTOLOADER, false ) ) {
			return null;
		}

		return RFRAC_DIR . 'includes/legacy/shims/' . self::MAP[ $key ];
	}

	/**
	 * The allow-list.
	 *
	 * @return array<string,string> Lower-cased class name => shim file relative to `includes/legacy/shims/`.
	 */
	public static function classes(): array {
		return self::MAP;
	}
}
