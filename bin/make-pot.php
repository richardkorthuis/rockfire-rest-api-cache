<?php
/**
 * Generates the translation template with WP-CLI (the `wp-cli/wp-cli-bundle` dev dependency), or checks that it is current.
 *
 * Runs `wp i18n make-pot` through the PHP binary that runs this script, without a shell, so the JSON
 * header argument is passed unchanged on Windows and Linux. The paths it skips are the patterns of `.distignore`,
 * so the template never names a file that stays out of the zip.
 *
 * Usage:
 *   composer make-pot                     Write languages/rockfire-rest-api-cache.pot.
 *   php bin/make-pot.php --check          Regenerate into a temporary file and compare the msgctxt, msgid and
 *                                         msgid_plural strings (continuation lines included) with the committed
 *                                         file; exits 1 and lists the differences when they differ.
 *
 * @package Rockfire_Rest_Api_Cache
 */

require __DIR__ . '/lib/distignore.php';

$root    = dirname( __DIR__ );
$pot     = $root . '/languages/rockfire-rest-api-cache.pot';
$check   = in_array( '--check', array_slice( $argv, 1 ), true );
$target  = $check ? tempnam( sys_get_temp_dir(), 'rfrac-pot' ) : $pot;
$wp_boot = $root . '/vendor/wp-cli/wp-cli/php/boot-fs.php';

if ( ! is_file( $wp_boot ) || ! is_dir( $root . '/vendor/wp-cli/i18n-command' ) ) {
	fwrite( STDERR, "WP-CLI with the i18n command is missing: run composer install.\n" );
	exit( 1 );
}

$command = array(
	PHP_BINARY,
	$wp_boot,
	'i18n',
	'make-pot',
	$root,
	$target,
	'--domain=rockfire-rest-api-cache',
	'--exclude=' . implode( ',', rfrac_distignore_patterns( $root . '/.distignore' ) ),
	'--headers={"Report-Msgid-Bugs-To":"https://wordpress.org/support/plugin/rockfire-rest-api-cache/"}',
);

$process = proc_open( $command, array( 1 => STDOUT, 2 => STDERR ), $pipes, $root );
$status  = is_resource( $process ) ? proc_close( $process ) : 1;
if ( 0 !== $status ) {
	fwrite( STDERR, "wp i18n make-pot failed.\n" );
	exit( 1 );
}
if ( ! $check ) {
	exit( 0 );
}

/**
 * The msgctxt, msgid and msgid_plural strings of a POT file, with their continuation lines joined.
 *
 * @param string $file POT file.
 * @return string[]
 */
function rfrac_pot_strings( $file ) {
	$out     = array();
	$current = null;
	foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
		if ( preg_match( '/^(msgctxt|msgid|msgid_plural) (".*")$/', $line, $m ) ) {
			if ( null !== $current ) {
				$out[] = $current;
			}
			$current = $m[1] . ' ' . $m[2];
		} elseif ( null !== $current && str_starts_with( $line, '"' ) ) {
			$current .= $line;
		} elseif ( null !== $current ) {
			$out[]   = $current;
			$current = null;
		}
	}
	if ( null !== $current ) {
		$out[] = $current;
	}
	return $out;
}

$committed = is_file( $pot ) ? rfrac_pot_strings( $pot ) : array();
$fresh     = rfrac_pot_strings( $target );
unlink( $target );

if ( $committed === $fresh ) {
	echo "The POT file is current.\n";
	exit( 0 );
}
foreach ( array_diff( $committed, $fresh ) as $string ) {
	fwrite( STDERR, "- {$string}\n" );
}
foreach ( array_diff( $fresh, $committed ) as $string ) {
	fwrite( STDERR, "+ {$string}\n" );
}
fwrite( STDERR, "languages/rockfire-rest-api-cache.pot is out of date: run composer make-pot and commit the result.\n" );
exit( 1 );
