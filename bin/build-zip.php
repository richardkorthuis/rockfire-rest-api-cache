<?php
/**
 * Builds the distributable plugin zip at build/rockfire-rest-api-cache.zip.
 *
 * Ships the files git tracks, minus those matched by `.distignore` and hidden top-level paths, so an untracked
 * file never reaches the zip. Wraps everything in a top-level `rockfire-rest-api-cache/` folder and asserts that no
 * development files or hidden paths leaked in.
 *
 * Usage: composer build
 *
 * @package Rockfire_Rest_Api_Cache
 */

require __DIR__ . '/lib/distignore.php';

$root   = dirname( __DIR__ );
$slug   = 'rockfire-rest-api-cache';
$build  = $root . '/build';
$target = $build . '/' . $slug . '.zip';

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The PHP zip extension is required.\n" );
	exit( 1 );
}

if ( ! is_dir( $build ) && ! mkdir( $build, 0755, true ) ) {
	fwrite( STDERR, "Cannot create {$build}\n" );
	exit( 1 );
}

if ( file_exists( $target ) ) {
	unlink( $target );
}

$files = rfrac_shipped_files( $root );

$zip = new ZipArchive();
if ( true !== $zip->open( $target, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Cannot create {$target}\n" );
	exit( 1 );
}

foreach ( $files as $file ) {
	if ( ! $zip->addFile( $root . '/' . $file, $slug . '/' . $file ) ) {
		fwrite( STDERR, "Cannot add {$file} to the zip\n" );
		exit( 1 );
	}
}
if ( ! $zip->close() ) {
	fwrite( STDERR, "Cannot write {$target}\n" );
	exit( 1 );
}

// Assert that no development files ended up in the zip.
$zip    = new ZipArchive();
$opened = $zip->open( $target );
if ( true !== $opened ) {
	fwrite( STDERR, "Cannot re-open {$target} (error {$opened})\n" );
	exit( 1 );
}
if ( count( $files ) !== $zip->numFiles ) {
	fwrite( STDERR, 'Expected ' . count( $files ) . " files in the zip, found {$zip->numFiles}\n" );
	exit( 1 );
}
$forbidden = array( 'tests/', 'bin/', 'vendor/', '.github/', 'composer.json' );
$failed    = false;
$entries   = array();
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$name      = $zip->getNameIndex( $i );
	$relative  = substr( $name, strlen( $slug ) + 1 );
	$entries[] = $name;
	foreach ( $forbidden as $entry ) {
		if ( $relative === $entry || ( '/' === substr( $entry, -1 ) && 0 === strpos( $relative, $entry ) ) ) {
			fwrite( STDERR, "{$name}: must not be shipped\n" );
			$failed = true;
		}
	}
}
// No entry may carry a hidden path segment, whatever git tracks.
foreach ( rfrac_forbidden_paths( $entries ) as $name => $reason ) {
	fwrite( STDERR, "{$name}: {$reason} must not be shipped\n" );
	$failed = true;
}
$count = $zip->numFiles;
if ( ! $zip->close() ) {
	fwrite( STDERR, "Cannot close {$target}\n" );
	exit( 1 );
}

if ( $failed ) {
	exit( 1 );
}

echo "Built build/{$slug}.zip with {$count} files.\n";
