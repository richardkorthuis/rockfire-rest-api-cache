<?php
/**
 * Helpers of bin/check-compliance.php, in a file of their own so tests can load them.
 *
 * @package Rockfire_Rest_Api_Cache
 */

/**
 * Whether a shipped file may name the original plugin (WP REST Cache) by its identifiers.
 *
 * Allowed are exactly three files (the readme, the main plugin file and the coexistence guard) and
 * the files under the legacy directory `includes/legacy/` (the prefix, with its trailing slash).
 * The POT and every other shipped file stay out of the list.
 *
 * @param string $file Path relative to the plugin root, forward slashes.
 * @return bool
 */
function rfrac_upstream_allowed( string $file ): bool {
	$files = array( 'readme.txt', 'rockfire-rest-api-cache.php', 'includes/install/class-coexistence-guard.php' );

	return in_array( $file, $files, true ) || str_starts_with( $file, 'includes/legacy/' );
}
