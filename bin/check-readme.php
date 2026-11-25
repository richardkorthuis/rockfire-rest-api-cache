<?php
/**
 * Checks that readme.txt only names things that exist in the shipped code.
 *
 * Every failure is printed as `readme.txt:line reason` and the script exits 1.
 *
 * Checks:
 *  (a) The readme name equals the plugin header `Plugin Name`; the short description is at most 150 characters.
 *  (b) Every `rfrac_*()` function is defined as a global function in a shipped PHP file.
 *  (c) Every `rfrac/...` hook is registered in includes/class-hooks.php, and the FAQ links to the hooks reference on
 *      rockfire.nl, which documents every registered hook.
 *  (d) Every `wp rfrac` subcommand is a `@subcommand` of the CLI classes, and every `--option` after it on
 *      the same line is an option of that subcommand.
 *  (e) Every `RFRAC_*` constant and every other `rfrac_*` name (tables, options, cron hooks, cache group)
 *      appears as a string literal in a shipped PHP file.
 *  (f) Every `rfrac-*.php` file name exists in mu-plugin/; every `X-Rfrac-*` header appears in shipped code.
 *  (g) WordPress.org readme format: required header fields, at most 5 tags, version fields, Stable tag equals the
 *      plugin Version, known section names, "= Heading =" subsections, upgrade notices of at most 300 characters
 *      per version, and the bypass reasons listed in the FAQ are values of Bypass_Reason.
 *  (h) No section is longer than WordPress.org shows: the FAQ and the changelog at most 5,000 words, every other
 *      section at most 2,500. On success the word count of each section and of each FAQ question is printed.
 *  (i) The `== Screenshots ==` captions and the tracked `.wordpress-org/screenshot-<n>.png` files agree: the
 *      captions are numbered 1, 2, 3..., every caption has its file and every file its caption. On success the
 *      number of captions and of files is printed.
 *
 * Usage: composer check-readme
 *
 * @package Rockfire_Rest_Api_Cache
 */

require __DIR__ . '/lib/distignore.php';

/**
 * The hooks reference the FAQ links to instead of listing every hook.
 */
const RFRAC_README_HOOKS_REFERENCE = 'https://rockfire.nl/docs/hooks/';
require __DIR__ . '/lib/readme.php';

$root     = dirname( __DIR__ );
$failures = array();
$readme   = file( $root . '/readme.txt', FILE_IGNORE_NEW_LINES );
if ( false === $readme ) {
	fwrite( STDERR, "Cannot read readme.txt\n" );
	exit( 1 );
}

// The shipped PHP code, concatenated.
$code = '';
foreach ( rfrac_shipped_files( $root ) as $file ) {
	if ( preg_match( '/\.php$/i', $file ) ) {
		$code .= file_get_contents( $root . '/' . $file ) . "\n";
	}
}

$hooks_file = (string) file_get_contents( $root . '/includes/class-hooks.php' );
preg_match_all( "/=\s*'(rfrac\/[^']+)'\s*;/", $hooks_file, $m );
$hooks = $m[1];

// Subcommand => its options, read from the docblock that carries the `@subcommand` tag.
$cli_options = array();
foreach ( array( 'class-command.php', 'class-config-command.php' ) as $cli_file ) {
	$cli = (string) file_get_contents( $root . '/includes/cli/' . $cli_file );
	preg_match_all( '#/\*\*(?:(?!\*/).)*?@subcommand\s+(\S+)(?:(?!\*/).)*\*/#s', $cli, $blocks, PREG_SET_ORDER );
	foreach ( $blocks as $block ) {
		preg_match_all( '/^\s*\*\s*\[--([a-z-]+)/m', $block[0], $o );
		$cli_options[ $block[1] ] = $o[1];
	}
}

$reasons_file = (string) file_get_contents( $root . '/includes/request/class-bypass-reason.php' );
preg_match_all( "/\bcase\s+[A-Z_]+\s*=\s*'([a-z_]+)'/", $reasons_file, $m );
$bypass_reasons = $m[1];

/**
 * Records a failure.
 *
 * @param int    $line   Line number (1-based).
 * @param string $reason Description.
 * @return void
 */
function rfrac_readme_fail( $line, $reason ) {
	global $failures;
	$failures[] = "readme.txt:{$line} {$reason}";
}

// (a) Header name and short description.
$plugin_file = (string) file_get_contents( $root . '/rockfire-rest-api-cache.php' );
if ( ! preg_match( '/^\s*\*\s*Plugin Name:\s*(.+?)\s*$/m', $plugin_file, $m ) ) {
	rfrac_readme_fail( 1, 'Plugin Name header not found in rockfire-rest-api-cache.php' );
} elseif ( '=== ' . $m[1] . ' ===' !== $readme[0] ) {
	rfrac_readme_fail( 1, "Readme name must be byte-identical to the plugin header name '{$m[1]}'" );
}
// The short description is the first non-empty line after the header fields (which end at the first empty line).
$short     = null;
$in_header = true;
foreach ( $readme as $index => $line ) {
	if ( 0 === $index ) {
		continue;
	}
	if ( $in_header ) {
		$in_header = '' !== trim( $line );
		continue;
	}
	if ( '' !== trim( $line ) ) {
		$short = array( $index + 1, $line );
		break;
	}
}
if ( null === $short || str_starts_with( $short[1], '==' ) ) {
	rfrac_readme_fail( 1, 'Short description not found' );
} elseif ( mb_strlen( $short[1], 'UTF-8' ) > 150 ) {
	rfrac_readme_fail( $short[0], 'Short description is longer than 150 characters' );
}

// (g) WordPress.org readme format: header fields, tags, section names, heading syntax, upgrade notices.
$fields = array();
foreach ( array_slice( $readme, 1 ) as $line ) {
	if ( ! preg_match( '/^([A-Za-z ]+):\s*(.+)$/', $line, $m ) ) {
		break;
	}
	$fields[ $m[1] ] = trim( $m[2] );
}
foreach ( array( 'Contributors', 'Tags', 'Requires at least', 'Tested up to', 'Requires PHP', 'Stable tag', 'License', 'License URI' ) as $field ) {
	if ( ! isset( $fields[ $field ] ) || '' === $fields[ $field ] ) {
		rfrac_readme_fail( 1, "Header field '{$field}' is missing" );
	}
}
if ( isset( $fields['Tags'] ) && count( array_filter( array_map( 'trim', explode( ',', $fields['Tags'] ) ) ) ) > 5 ) {
	rfrac_readme_fail( 1, 'More than 5 tags' );
}
foreach ( array( 'Requires at least', 'Tested up to' ) as $field ) {
	if ( isset( $fields[ $field ] ) && ! preg_match( '/^\d+\.\d+$/', $fields[ $field ] ) ) {
		rfrac_readme_fail( 1, "'{$field}' must be a major.minor WordPress version" );
	}
}
if ( ! preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $plugin_file, $m ) ) {
	rfrac_readme_fail( 1, 'Version header not found in rockfire-rest-api-cache.php' );
} elseif ( isset( $fields['Stable tag'] ) && $m[1] !== $fields['Stable tag'] ) {
	rfrac_readme_fail( 1, "Stable tag {$fields['Stable tag']} does not match the plugin Version {$m[1]}" );
}
$sections = array( 'Description', 'Installation', 'Frequently Asked Questions', 'Screenshots', 'Changelog', 'Upgrade Notice' );
$section  = '';
$subtitle = '';
$notices  = array(); // Upgrade notice version => [ first line, text ].
foreach ( $readme as $index => $line ) {
	if ( 0 !== $index && preg_match( '/^==\s*(.+?)\s*==$/', $line, $m ) ) {
		$section  = $m[1];
		$subtitle = '';
		if ( ! in_array( $section, $sections, true ) ) {
			rfrac_readme_fail( $index + 1, "Unknown section '{$section}'" );
		}
	} elseif ( preg_match( '/^= (.+) =$/', $line, $m ) ) {
		$subtitle = $m[1];
		if ( 'Upgrade Notice' === $section ) {
			$notices[ $subtitle ] = array( $index + 1, '' );
		}
	} elseif ( preg_match( '/^=[^=]/', $line ) ) {
		rfrac_readme_fail( $index + 1, 'Malformed subsection heading (use "= Heading =")' );
	} elseif ( 'Upgrade Notice' === $section && '' !== $subtitle ) {
		$notices[ $subtitle ][1] = trim( $notices[ $subtitle ][1] . ' ' . trim( $line ) );
	} elseif ( "Why isn't my endpoint cached?" === $subtitle && preg_match( '/^\* (`[a-z_]+`(?:(?: or |, )`[a-z_]+`)*) –/', $line, $m ) ) {
		// The documented bypass reasons must be values of Bypass_Reason.
		preg_match_all( '/`([a-z_]+)`/', $m[1], $r );
		foreach ( $r[1] as $reason ) {
			if ( ! in_array( $reason, $bypass_reasons, true ) ) {
				rfrac_readme_fail( $index + 1, "Bypass reason '{$reason}' is not a value of Bypass_Reason" );
			}
		}
	}
}
foreach ( $notices as $version => list( $no, $text ) ) {
	if ( mb_strlen( $text, 'UTF-8' ) > 300 ) {
		rfrac_readme_fail( $no, "Upgrade notice for {$version} is longer than 300 characters" );
	}
}
foreach ( array( 'Description', 'Installation', 'Frequently Asked Questions', 'Changelog', 'Upgrade Notice' ) as $required ) {
	if ( ! in_array( '== ' . $required . ' ==', $readme, true ) ) {
		rfrac_readme_fail( 1, "Section '{$required}' is missing" );
	}
}

foreach ( $readme as $index => $line ) {
	$no = $index + 1;

	// (b) Functions.
	preg_match_all( '/\b(rfrac_[a-z0-9_]+)\s*\(/', $line, $m );
	foreach ( $m[1] as $function ) {
		// Global functions only: a method of the same name (indented) does not count.
		if ( ! preg_match( '/^function\s+' . preg_quote( $function, '/' ) . '\s*\(/m', $code ) ) {
			rfrac_readme_fail( $no, "Function {$function}() is not defined in the shipped code" );
		}
	}

	// (c) Hooks.
	preg_match_all( '/\brfrac\/[a-z0-9_\/{}]+/', $line, $m );
	foreach ( $m[0] as $hook ) {
		if ( ! in_array( $hook, $hooks, true ) ) {
			rfrac_readme_fail( $no, "Hook {$hook} is not registered in includes/class-hooks.php" );
		}
	}

	// (d) WP-CLI subcommands and options.
	// Every option belongs to the nearest `wp rfrac` command before it on the same line.
	preg_match_all( '/\bwp rfrac ([a-z-]+)(?: ([a-z]+))?/', $line, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
	foreach ( $m as $i => $match ) {
		$name = $match[1][0];
		$sub  = 'config' === $name ? ( $match[2][0] ?? '' ) : $name;
		if ( ! isset( $cli_options[ $sub ] ) ) {
			rfrac_readme_fail( $no, "WP-CLI command 'wp rfrac {$name}" . ( 'config' === $name ? " {$sub}" : '' ) . "' does not exist" );
			continue;
		}
		$start = $match[0][1] + strlen( $match[0][0] );
		$end   = isset( $m[ $i + 1 ] ) ? $m[ $i + 1 ][0][1] : strlen( $line );
		preg_match_all( '/(?<![a-z-])--([a-z][a-z-]*)/', substr( $line, $start, $end - $start ), $o );
		foreach ( $o[1] as $option ) {
			// `--url` is named only to say that WP-CLI reserves it.
			if ( 'url' !== $option && ! in_array( $option, $cli_options[ $sub ], true ) ) {
				rfrac_readme_fail( $no, "WP-CLI option --{$option} does not exist for 'wp rfrac {$name}" . ( 'config' === $name ? " {$sub}" : '' ) . "'" );
			}
		}
	}

	// (e) Constants (and `{{RFRAC_*}}` placeholders of the must-use template) and other rfrac_ names.
	preg_match_all( '/\{\{RFRAC_[A-Z0-9_]+\}\}/', $line, $m );
	foreach ( array_unique( $m[0] ) as $placeholder ) {
		if ( ! str_contains( (string) file_get_contents( $root . '/mu-plugin/rfrac-early-cache.php' ), $placeholder ) ) {
			rfrac_readme_fail( $no, "Placeholder {$placeholder} is not in the must-use template" );
		}
	}
	preg_match_all( '/(?<!\{\{)\bRFRAC_[A-Z0-9_]+\b(?!\}\})/', $line, $m );
	foreach ( array_unique( $m[0] ) as $constant ) {
		if ( ! preg_match( "/'" . preg_quote( $constant, '/' ) . "'/", $code ) ) {
			rfrac_readme_fail( $no, "Constant {$constant} is not used in the shipped code" );
		}
	}
	preg_match_all( '/\b(rfrac_[a-z0-9_]*[a-z0-9])(?![a-z0-9_]*\s*\()/', $line, $m );
	foreach ( array_unique( $m[1] ) as $name ) {
		if ( ! preg_match( "/'" . preg_quote( $name, '/' ) . "'/", $code ) ) {
			rfrac_readme_fail( $no, "Name {$name} does not appear as a string in the shipped code" );
		}
	}

	// (f) Must-use file names and response headers.
	preg_match_all( '/\b(rfrac-[a-z0-9-]+\.php)\b/', $line, $m );
	foreach ( array_unique( $m[1] ) as $filename ) {
		if ( ! is_file( $root . '/mu-plugin/' . $filename ) ) {
			rfrac_readme_fail( $no, "File mu-plugin/{$filename} does not exist" );
		}
	}
	preg_match_all( '/\bX-Rfrac-[A-Za-z-]*[A-Za-z]/', $line, $m );
	foreach ( array_unique( $m[0] ) as $header ) {
		if ( ! str_contains( $code, "'" . $header . "'" ) ) {
			rfrac_readme_fail( $no, "Header {$header} is not sent by the shipped code" );
		}
	}
}

// (c) The FAQ points to the hooks reference, which documents every registered hook (generated from includes/class-hooks.php).
$text = implode( "\n", $readme );
if ( array() !== $hooks && ! str_contains( $text, RFRAC_README_HOOKS_REFERENCE ) ) {
	rfrac_readme_fail( 1, 'The hooks reference ' . RFRAC_README_HOOKS_REFERENCE . ' is not linked' );
}

// (h) Word limits of the sections.
foreach ( rfrac_readme_word_failures( $readme ) as $no => $reason ) {
	rfrac_readme_fail( $no, $reason );
}

// (i) Screenshot captions against the tracked screenshot files.
$tracked = rfrac_git_files( $root );
if ( null === $tracked ) {
	rfrac_readme_fail( 1, 'Cannot list .wordpress-org/ (git ls-files failed)' );
	$tracked = array();
}
foreach ( rfrac_readme_screenshot_failures( $readme, $tracked ) as $failure ) {
	rfrac_readme_fail( $failure['line'], $failure['reason'] );
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

$limits = rfrac_readme_limits();
foreach ( rfrac_readme_sections( $readme ) as $name => $section ) {
	echo "{$name}: {$section['words']} words (limit " . ( $limits[ $name ] ?? $limits['*'] ) . ")\n";
	if ( 'Frequently Asked Questions' === $name ) {
		foreach ( $section['subsections'] as $question => $words ) {
			echo "  {$question}: {$words} words\n";
		}
	}
}
// Nothing failed, so every screenshot file is named right and has exactly one caption, and every caption its
// file: the two counts are equal. The files are the ones the check above judged.
$screenshots = count( rfrac_readme_screenshot_files( $tracked ) );
echo "Screenshots: {$screenshots} captions, {$screenshots} files\n";
echo "Readme checks passed.\n";
