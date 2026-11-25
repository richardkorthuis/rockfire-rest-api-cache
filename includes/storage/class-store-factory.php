<?php
/**
 * Chooses the store for a mode.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the {@see Store_Interface} for a configured store mode.
 *
 * @since 1.0.0
 */
final class Store_Factory {

	/**
	 * Resolve a configured mode to a concrete one.
	 *
	 * `auto` becomes `object_cache` with a persistent object cache and `database` otherwise;
	 * an unknown value is `database`.
	 *
	 * @param string $mode `auto`, `database` or `object_cache`.
	 *
	 * @return string `database` or `object_cache`.
	 */
	public static function resolve_mode( string $mode ): string {
		if ( 'auto' === $mode ) {
			return wp_using_ext_object_cache() ? 'object_cache' : 'database';
		}
		return 'object_cache' === $mode ? 'object_cache' : 'database';
	}

	/**
	 * The store for a mode.
	 *
	 * @param string     $mode       `auto`, `database` or `object_cache`.
	 * @param Repository $repository Entries repository.
	 * @param int        $sampling   Hit sampling N (0 disables hit counting).
	 *
	 * @return Store_Interface
	 */
	public static function for_mode( string $mode, Repository $repository, int $sampling ): Store_Interface {
		if ( 'object_cache' === self::resolve_mode( $mode ) ) {
			return new Object_Cache_Store( $repository, $sampling );
		}
		return new Database_Store( $repository, $sampling );
	}
}
