<?php
/**
 * Boolean flag helper shared by the WP-CLI commands.
 *
 * @since 1.0.0
 *
 * @package    Rockfire_Rest_Api_Cache
 * @subpackage Rockfire_Rest_Api_Cache/Cli
 */

namespace Rockfire\Rest_Api_Cache\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a WP-CLI flag (`--name`, `--name=false`).
 *
 * @since 1.0.0
 */
trait Flag_Trait {

	/**
	 * Whether a flag was passed and not switched off (`--flag=false`, `0`, `no`, `off`).
	 *
	 * @param array<string,mixed> $assoc_args Associative arguments.
	 * @param string              $name       Flag name.
	 *
	 * @return bool
	 */
	private function flag( array $assoc_args, string $name ): bool {
		// WP-CLI reads the flag from an array of strings and booleans; any other value counts as set when truthy.
		$flags = array();
		foreach ( $assoc_args as $key => $arg ) {
			$flags[ $key ] = is_string( $arg ) ? $arg : (bool) $arg;
		}

		$value = \WP_CLI\Utils\get_flag_value( $flags, $name, false );
		return is_string( $value ) ? filter_var( $value, FILTER_VALIDATE_BOOLEAN ) : (bool) $value;
	}
}
