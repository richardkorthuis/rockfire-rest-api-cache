<?php
/**
 * Project compliance checks that PHPCS and PHPStan cannot express.
 *
 * Every failure is printed as `file:line reason` and the script exits 1.
 *
 * Checks:
 *  (a) Header `Version` equals the RFRAC_VERSION literal and the readme `Stable tag`.
 *  (b) No upstream (WP REST Cache) identifiers in shipped files, apart from the allow-list of rfrac_upstream_allowed()
 *      in bin/lib/compliance.php: the readme, the main plugin file, the coexistence guard and the files under includes/legacy/.
 *  (c) Every shipped PHP file has an ABSPATH guard (WP_UNINSTALL_PLUGIN for uninstall.php) in its first 40 lines.
 *  (d) No shipped path has a segment that starts with a dot.
 *
 * The shipped files are the files git tracks, minus those matched by `.distignore` and hidden top-level paths.
 *
 * Usage: composer compliance
 *
 * @package Rockfire_Rest_Api_Cache
 */

require __DIR__ . '/lib/distignore.php';
require __DIR__ . '/lib/compliance.php';

$root     = dirname( __DIR__ );
$failures = array();

/**
 * Records a failure.
 *
 * @param string $file   Relative file path.
 * @param int    $line   Line number.
 * @param string $reason Description.
 * @return void
 */
function rfrac_fail( $file, $line, $reason ) {
	global $failures;
	$failures[] = "{$file}:{$line} {$reason}";
}

/**
 * Finds the first line matching a regex in a file.
 *
 * @param string $path  Absolute path.
 * @param string $regex Regex with one capture group.
 * @return array{0:int,1:string}|null Line number and captured value.
 */
function rfrac_find_line( $path, $regex ) {
	$lines = file( $path, FILE_IGNORE_NEW_LINES );
	foreach ( (array) $lines as $index => $line ) {
		if ( preg_match( $regex, $line, $matches ) ) {
			return array( $index + 1, $matches[1] );
		}
	}

	return null;
}

// (a) Version consistency.
$header  = rfrac_find_line( $root . '/rockfire-rest-api-cache.php', '/^\s*\*\s*Version:\s*(\S+)/' );
$literal = rfrac_find_line( $root . '/rockfire-rest-api-cache.php', "/define\(\s*'RFRAC_VERSION'\s*,\s*'([^']+)'/" );
$stable  = rfrac_find_line( $root . '/readme.txt', '/^Stable tag:\s*(\S+)/i' );

if ( ! $header ) {
	rfrac_fail( 'rockfire-rest-api-cache.php', 1, 'Plugin header Version not found' );
}
if ( ! $literal ) {
	rfrac_fail( 'rockfire-rest-api-cache.php', 1, 'RFRAC_VERSION literal not found' );
}
if ( ! $stable ) {
	rfrac_fail( 'readme.txt', 1, 'Stable tag not found' );
}
if ( $header && $literal && $header[1] !== $literal[1] ) {
	rfrac_fail( 'rockfire-rest-api-cache.php', $literal[0], "Version mismatch: header Version is {$header[1]} but RFRAC_VERSION is {$literal[1]}" );
}
if ( $header && $stable && $header[1] !== $stable[1] ) {
	rfrac_fail( 'readme.txt', $stable[0], "Version mismatch: readme Stable tag is {$stable[1]} but header Version is {$header[1]}" );
}

// (b) No upstream identifiers in shipped files.
$shipped  = rfrac_shipped_files( $root );
$upstream = '/wp_rest_cache|wp-rest-cache|wprc|wrc_|WP_Rest_Cache_Plugin|acato/i';
foreach ( $shipped as $file ) {
	if ( rfrac_upstream_allowed( $file ) || preg_match( '/\.(png|jpe?g|gif|webp|ico|zip|mo|woff2?|ttf|eot)$/i', $file ) ) {
		continue;
	}
	$lines = file( $root . '/' . $file, FILE_IGNORE_NEW_LINES );
	foreach ( (array) $lines as $index => $line ) {
		if ( preg_match( $upstream, $line, $matches ) ) {
			rfrac_fail( $file, $index + 1, "Upstream identifier '{$matches[0]}' must not appear in shipped files" );
		}
	}
}

// (c) Direct-access guard in every shipped PHP file.
foreach ( $shipped as $file ) {
	if ( ! preg_match( '/\.php$/i', $file ) ) {
		continue;
	}
	$constant = 'uninstall.php' === $file ? 'WP_UNINSTALL_PLUGIN' : 'ABSPATH';
	$guard    = '/^\s*defined\(\s*\'' . $constant . '\'\s*\)\s*\|\|\s*(exit|die)\b/';
	$head     = array_slice( (array) file( $root . '/' . $file, FILE_IGNORE_NEW_LINES ), 0, 40 );
	if ( ! preg_grep( $guard, $head ) ) {
		rfrac_fail( $file, 1, "Missing direct-access guard \"defined( '{$constant}' ) || exit;\" within the first 40 lines" );
	}
}

// (d) No hidden path in the shipped list.
foreach ( rfrac_forbidden_paths( $shipped ) as $file => $reason ) {
	rfrac_fail( $file, 1, "{$reason} must not be shipped" );
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "Compliance checks passed.\n";
