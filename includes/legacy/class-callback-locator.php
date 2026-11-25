<?php
/**
 * Finds the file of a callback and tells whether a cache hit has loaded it.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Content_Path;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the file a hooked callback is declared in and classifies it by where that file lives.
 *
 * A cache hit served by the must-use file runs before the plugins load, so a callback of a regular plugin, of the
 * theme or of a must-use file that loads after the early-cache file is not there yet. The classification answers,
 * per callback, which of these holds:
 *
 * - {@see self::EARLY}: the callback is there on every path (core, an earlier must-use file, or any file when the
 *   must-use file is not installed, because then every hit is served after the plugins have loaded);
 * - {@see self::LOAD_ORDER}: a must-use file that loads after the early-cache file;
 * - {@see self::FALLBACK_ONLY}: a regular plugin, which only the fallback path has loaded;
 * - {@see self::NOT_ON_HITS}: a theme, or a file that cannot be found; no hit loads it.
 *
 * The directories are injectable, so a test can point them at fixtures.
 *
 * @since 1.0.0
 */
final class Callback_Locator {

	/**
	 * Class: the callback is there on every path.
	 *
	 * @var string
	 */
	public const EARLY = 'early';

	/**
	 * Class: a must-use file that loads after the early-cache file.
	 *
	 * @var string
	 */
	public const LOAD_ORDER = 'load_order';

	/**
	 * Class: a plugin file, which only the fallback path has loaded.
	 *
	 * @var string
	 */
	public const FALLBACK_ONLY = 'fallback_only';

	/**
	 * Class: a theme or an unknown file, which no cache hit has loaded.
	 *
	 * @var string
	 */
	public const NOT_ON_HITS = 'not_on_hits';

	/**
	 * The directory of the core includes, normalised, without a trailing slash.
	 *
	 * @var string
	 */
	private string $core_dir;

	/**
	 * The directory of the must-use plugins, normalised, without a trailing slash.
	 *
	 * @var string
	 */
	private string $mu_dir;

	/**
	 * The directory of the plugins, normalised, without a trailing slash.
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * The content directory the messages are relative to, normalised, without a trailing slash.
	 *
	 * @var string
	 */
	private string $content_dir;

	/**
	 * Create the locator.
	 *
	 * @param string|null $core_dir    Directory of the core includes; defaults to `ABSPATH . WPINC`.
	 * @param string|null $mu_dir      Directory of the must-use plugins; defaults to `WPMU_PLUGIN_DIR`.
	 * @param string|null $plugin_dir  Directory of the plugins; defaults to `WP_PLUGIN_DIR`.
	 * @param string|null $content_dir Directory the displayed paths are relative to; defaults to `WP_CONTENT_DIR`.
	 */
	public function __construct( ?string $core_dir = null, ?string $mu_dir = null, ?string $plugin_dir = null, ?string $content_dir = null ) {
		$this->core_dir    = self::normalize( $core_dir ?? ABSPATH . ( defined( 'WPINC' ) && is_string( WPINC ) ? WPINC : 'wp-includes' ) );
		$this->mu_dir      = self::normalize( $mu_dir ?? WPMU_PLUGIN_DIR );
		$this->plugin_dir  = self::normalize( $plugin_dir ?? WP_PLUGIN_DIR );
		$this->content_dir = self::normalize( $content_dir ?? WP_CONTENT_DIR );
	}

	/**
	 * The callbacks hooked to a hook, in the order they run.
	 *
	 * @param string $hook Hook name.
	 *
	 * @return list<mixed>
	 */
	public static function callbacks( string $hook ): array {
		$filters = $GLOBALS['wp_filter'] ?? null;
		$wp_hook = is_array( $filters ) ? ( $filters[ $hook ] ?? null ) : null;
		if ( ! $wp_hook instanceof \WP_Hook ) {
			return array();
		}

		$callbacks = array();
		foreach ( $wp_hook->callbacks as $by_priority ) {
			if ( ! is_array( $by_priority ) ) {
				continue;
			}
			foreach ( $by_priority as $entry ) {
				if ( is_array( $entry ) && array_key_exists( 'function', $entry ) ) {
					$callbacks[] = $entry['function'];
				}
			}
		}
		return $callbacks;
	}

	/**
	 * The file a callback is declared in.
	 *
	 * Handles closures, function names, `Class::method` strings, `[ object|class, method ]` arrays and invokable
	 * objects.
	 *
	 * @param mixed $callback The callback.
	 *
	 * @return string|null The normalised path (forward slashes); null for an internal function or a callback that
	 *                     cannot be resolved.
	 */
	public function file( mixed $callback ): ?string {
		try {
			$file = $this->reflect( $callback )?->getFileName();
		} catch ( \ReflectionException $e ) {
			unset( $e );
			return null;
		}

		return is_string( $file ) && '' !== $file ? wp_normalize_path( $file ) : null;
	}

	/**
	 * Which of the classes a callback belongs to.
	 *
	 * @param mixed       $callback  The callback.
	 * @param string|null $mu_target Path of the installed must-use early-cache file, null when it is not installed.
	 *
	 * @return string One of {@see self::EARLY}, {@see self::LOAD_ORDER}, {@see self::FALLBACK_ONLY} or {@see self::NOT_ON_HITS}.
	 */
	public function classify( mixed $callback, ?string $mu_target ): string {
		$file = $this->file( $callback );
		if ( null === $file ) {
			return self::NOT_ON_HITS;
		}

		if ( null !== self::relative_to( $file, $this->core_dir ) ) {
			return self::EARLY;
		}

		$in_mu = self::relative_to( $file, $this->mu_dir );
		if ( null !== $in_mu ) {
			if ( null === $mu_target ) {
				return self::EARLY;
			}
			// WordPress loads the must-use files in byte-wise order of their names; a directory counts under its own name.
			$first = explode( '/', $in_mu, 2 )[0];
			return strcmp( $first, basename( $mu_target ) ) < 0 ? self::EARLY : self::LOAD_ORDER;
		}

		if ( null !== self::relative_to( $file, $this->plugin_dir ) ) {
			return null === $mu_target ? self::EARLY : self::FALLBACK_ONLY;
		}

		return self::NOT_ON_HITS;
	}

	/**
	 * A file as shown in a message: relative to the content directory when it lives there, else its last directory and
	 * file name behind an ellipsis (never an absolute path).
	 *
	 * @param string $file Normalised path.
	 *
	 * @return string
	 */
	public function display( string $file ): string {
		return Content_Path::shown( $file, false, $this->content_dir );
	}

	/**
	 * The reflection of a callback.
	 *
	 * @param mixed $callback The callback.
	 *
	 * @return \ReflectionFunctionAbstract|null Null when the value is not something a callback can be made of.
	 *
	 * @throws \ReflectionException When the function, class or method does not exist.
	 */
	private function reflect( mixed $callback ): ?\ReflectionFunctionAbstract {
		if ( $callback instanceof \Closure ) {
			return new \ReflectionFunction( $callback );
		}

		if ( is_string( $callback ) ) {
			if ( str_contains( $callback, '::' ) ) {
				[ $class, $method ] = explode( '::', $callback, 2 );
				return new \ReflectionMethod( $class, $method );
			}
			return new \ReflectionFunction( $callback );
		}

		if ( is_array( $callback ) ) {
			$target = $callback[0] ?? null;
			$method = $callback[1] ?? null;
			if ( ( is_object( $target ) || is_string( $target ) ) && is_string( $method ) ) {
				return new \ReflectionMethod( $target, $method );
			}
			return null;
		}

		if ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
			return new \ReflectionMethod( $callback, '__invoke' );
		}

		return null;
	}

	/**
	 * The path of a file relative to a directory.
	 *
	 * The directory is also tried under its real path, because a symlinked plugin directory reports the files it
	 * holds by their real path.
	 *
	 * @param string $file Normalised path of the file.
	 * @param string $dir  Normalised directory without a trailing slash.
	 *
	 * @return string|null The relative path, null when the file is not under the directory.
	 */
	private static function relative_to( string $file, string $dir ): ?string {
		if ( '' === $dir ) {
			return null;
		}

		$bases = array( $dir );
		$real  = realpath( $dir );
		if ( false !== $real ) {
			$bases[] = self::normalize( $real );
		}

		foreach ( $bases as $base ) {
			if ( str_starts_with( $file, $base . '/' ) ) {
				return substr( $file, strlen( $base ) + 1 );
			}
		}
		return null;
	}

	/**
	 * A directory with forward slashes and without a trailing one.
	 *
	 * @param string $dir The directory.
	 *
	 * @return string
	 */
	private static function normalize( string $dir ): string {
		return untrailingslashit( wp_normalize_path( $dir ) );
	}
}
