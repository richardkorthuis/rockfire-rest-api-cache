<?php
/**
 * Helpers for reading `.distignore` and listing the files that ship in the plugin zip.
 *
 * Shared by bin/build-zip.php, bin/check-compliance.php, bin/check-readme.php and bin/make-pot.php.
 *
 * @package Rockfire_Rest_Api_Cache
 */

/**
 * Reads the patterns from a `.distignore` file.
 *
 * @param string $file Absolute path to the .distignore file.
 * @return string[] Patterns with leading and trailing slashes removed.
 */
function rfrac_distignore_patterns( $file ) {
	$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( false === $lines ) {
		fwrite( STDERR, "Cannot read {$file}\n" );
		exit( 1 );
	}

	$patterns = array();
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( '' === $line || '#' === $line[0] ) {
			continue;
		}
		$patterns[] = trim( $line, '/' );
	}

	return $patterns;
}

/**
 * Checks whether a relative path is ignored by the patterns.
 *
 * A pattern matches the path itself or any parent directory of it. Patterns are anchored at the
 * plugin root, matching `.gitattributes` export-ignore semantics for the entries we use.
 *
 * @param string   $path     Path relative to the plugin root, forward slashes.
 * @param string[] $patterns Patterns from rfrac_distignore_patterns().
 * @return bool
 */
function rfrac_is_ignored( $path, array $patterns ) {
	foreach ( $patterns as $pattern ) {
		if ( $path === $pattern || 0 === strpos( $path, $pattern . '/' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Lists the files git tracks in a checkout.
 *
 * Runs `git ls-files -z` in the root through an argument vector, without a shell. Untracked and ignored
 * files never appear, so a local-only file can never reach the zip or the checks.
 *
 * @param string $root Absolute path to the plugin root.
 * @return string[]|null Relative paths, forward slashes, in git's order; null when git fails in the root
 *                       (no git on the PATH, or the root is not a git checkout).
 */
function rfrac_git_files( $root ) {
	$root    = rtrim( str_replace( '\\', '/', $root ), '/' );
	$null    = '\\' === DIRECTORY_SEPARATOR ? 'NUL' : '/dev/null';
	$process = proc_open(
		array( 'git', '-C', $root, 'ls-files', '-z' ),
		array(
			0 => array( 'file', $null, 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'file', $null, 'w' ),
		),
		$pipes
	);
	if ( ! is_resource( $process ) ) {
		return null;
	}

	$output = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	$status = proc_close( $process );
	if ( false === $output || 0 !== $status ) {
		return null;
	}

	return array_values( array_unique( array_filter( explode( "\0", $output ), 'strlen' ) ) );
}

/**
 * Finds the paths that must never ship: any path with a segment that starts with a dot.
 *
 * @param string[] $files Relative paths, forward slashes.
 * @return array<string,string> Offending path => reason, `hidden path`.
 */
function rfrac_forbidden_paths( array $files ) {
	$forbidden = array();
	foreach ( $files as $file ) {
		foreach ( explode( '/', $file ) as $segment ) {
			if ( '' !== $segment && '.' === $segment[0] ) {
				$forbidden[ $file ] = 'hidden path';
				break;
			}
		}
	}

	return $forbidden;
}

/**
 * Lists the files that ship in the plugin: the files git tracks, minus those matched by `.distignore` and
 * those under a hidden top-level path (a file or directory whose name starts with a dot), which never ship.
 *
 * Exits 1 when the root is not a git checkout (there is no filesystem fallback) and when a shipped path is
 * tracked by git but missing on disk.
 *
 * @param string $root Absolute path to the plugin root.
 * @return string[] Sorted relative paths, forward slashes.
 */
function rfrac_shipped_files( $root ) {
	$root     = rtrim( str_replace( '\\', '/', $root ), '/' );
	$patterns = rfrac_distignore_patterns( $root . '/.distignore' );

	$tracked = rfrac_git_files( $root );
	if ( null === $tracked ) {
		fwrite( STDERR, "The build needs a git checkout: git ls-files failed in {$root}.\n" );
		exit( 1 );
	}

	$files   = array();
	$missing = false;
	foreach ( $tracked as $path ) {
		if ( '.' === $path[0] || rfrac_is_ignored( $path, $patterns ) ) {
			continue;
		}
		if ( ! is_file( $root . '/' . $path ) ) {
			fwrite( STDERR, "{$path}: tracked by git but missing on disk\n" );
			$missing = true;
			continue;
		}
		$files[] = $path;
	}
	if ( $missing ) {
		exit( 1 );
	}
	sort( $files, SORT_STRING );

	return $files;
}
