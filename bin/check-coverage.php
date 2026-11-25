<?php
/**
 * Fails when the line coverage of the engine directories, in total or of any single directory, is below a threshold.
 *
 * Files no test loads count as fully uncovered: PHPUnit's default `includeUncoveredFiles` adds them to the report by
 * static analysis. Do not set `processUncoveredFiles="true"` in the PHPUnit config: `phpcov merge` would then
 * `include` every uncovered plugin file outside WordPress, hit `defined( 'ABSPATH' ) || exit;` and end the merge silently.
 * A directory without any lines fails.
 *
 * Reads a Clover report (`phpcov merge --clover=…` or `phpunit --coverage-clover=…`) and sums the
 * statement lines of every file below the given directories of `includes/`.
 *
 * Usage: php bin/check-coverage.php <clover.xml> [threshold] [dir ...]
 *   threshold  Minimum percentage, default 80.
 *   dir        Directories relative to the plugin root, default the engine directories.
 *
 * @package Rockfire_Rest_Api_Cache
 */

$root       = str_replace( chr( 92 ), '/', dirname( __DIR__ ) ) . '/';
$clover     = $argv[1] ?? 'coverage/clover.xml';
$threshold  = isset( $argv[2] ) ? (float) $argv[2] : 80.0;
$directions = array_slice( $argv, 3 );
if ( array() === $directions ) {
	$directions = array( 'includes/config', 'includes/request', 'includes/serving', 'includes/storage', 'includes/schema', 'includes/install' );
}

if ( ! is_file( $clover ) ) {
	fwrite( STDERR, "Clover report not found: {$clover}\n" );
	exit( 2 );
}

$xml = simplexml_load_file( $clover );
if ( false === $xml ) {
	fwrite( STDERR, "Cannot parse {$clover}\n" );
	exit( 2 );
}

$totals = array();
foreach ( $directions as $dir ) {
	$totals[ $dir ] = array(
		'lines'   => 0,
		'covered' => 0,
	);
}

foreach ( $xml->xpath( '//file' ) as $file ) {
	$name = str_replace( chr( 92 ), '/', (string) $file['name'] );
	if ( str_starts_with( $name, $root ) ) {
		$name = substr( $name, strlen( $root ) );
	}
	foreach ( $directions as $dir ) {
		if ( ! str_starts_with( $name, rtrim( $dir, '/' ) . '/' ) ) {
			continue;
		}
		foreach ( $file->line as $line ) {
			if ( 'stmt' !== (string) $line['type'] ) {
				continue;
			}
			++$totals[ $dir ]['lines'];
			if ( (int) $line['count'] > 0 ) {
				++$totals[ $dir ]['covered'];
			}
		}
	}
}

$all_lines   = 0;
$all_covered = 0;
$failed      = array();
foreach ( $totals as $dir => $total ) {
	$all_lines   += $total['lines'];
	$all_covered += $total['covered'];
	$percent      = $total['lines'] > 0 ? 100 * $total['covered'] / $total['lines'] : 0.0;
	printf( "%-20s %6.2f%%  (%d/%d lines)\n", $dir, $percent, $total['covered'], $total['lines'] );
	if ( 0 === $total['lines'] ) {
		$failed[] = "{$dir} has no lines in the report (wrong directory, or its files are not in the coverage include list)";
	} elseif ( $percent < $threshold ) {
		$failed[] = sprintf( '%s is at %.2f%%, below %.2f%%', $dir, $percent, $threshold );
	}
}

if ( 0 === $all_lines ) {
	fwrite( STDERR, "No covered files found for the given directories; is the report empty?\n" );
	exit( 2 );
}

$percent = 100 * $all_covered / $all_lines;
printf( "%-20s %6.2f%%  (%d/%d lines), required %.2f%% in total and per directory\n", 'total', $percent, $all_covered, $all_lines, $threshold );
if ( $percent < $threshold ) {
	$failed[] = sprintf( 'the total is at %.2f%%, below %.2f%%', $percent, $threshold );
}

if ( array() !== $failed ) {
	foreach ( $failed as $message ) {
		fwrite( STDERR, "Coverage check failed: {$message}\n" );
	}
	exit( 1 );
}
