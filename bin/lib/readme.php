<?php
/**
 * Word counting of readme.txt sections and the screenshot check, in a file of its own so tests can load it.
 *
 * WordPress.org truncates a readme section after a number of words and links to the full text. These helpers
 * follow its parser: words are whitespace-separated, and a section body runs from the line after its
 * `== Name ==` heading to the next `== Name ==` heading. The screenshot check compares the captions of the
 * `== Screenshots ==` section with the `.wordpress-org/screenshot-<n>.png` files.
 *
 * @package Rockfire_Rest_Api_Cache
 */

/**
 * Counts the whitespace-separated words of a text.
 *
 * @param string $text Any text.
 * @return int
 */
function rfrac_readme_words( string $text ): int {
	$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );

	return false === $words ? 0 : count( $words );
}

/**
 * The word limits after which WordPress.org truncates a section, keyed by section name.
 *
 * The `*` entry is the limit of every section that is not named.
 *
 * @return array<string,int>
 */
function rfrac_readme_limits(): array {
	return array(
		'Frequently Asked Questions' => 5000,
		'Changelog'                  => 5000,
		'*'                          => 2500,
	);
}

/**
 * Splits the lines of a readme into sections and counts their words.
 *
 * The header (including the `=== Name ===` line) and the short description before the first `== Name ==`
 * heading belong to no section. A body includes its `= Heading =` lines; each of them also opens a subsection
 * (a FAQ question, a changelog version) that runs to the next `= Heading =` line and counts that line too.
 * A section named twice is counted as one.
 *
 * @param string[] $lines The lines of readme.txt, without line endings.
 * @return array<string,array{line:int,words:int,subsections:array<string,int>}> Section name => the 1-based
 *         number of its heading line, its word count and the word count of each subsection.
 */
function rfrac_readme_sections( array $lines ): array {
	$bodies = array();
	$starts = array();
	$name   = null;
	$title  = null;

	foreach ( $lines as $index => $line ) {
		if ( preg_match( '/^==\s*([^=].*?)\s*==$/', $line, $match ) ) {
			$name  = $match[1];
			$title = null;
			if ( ! isset( $bodies[ $name ] ) ) {
				$bodies[ $name ] = array(
					'text'        => '',
					'subsections' => array(),
				);
				$starts[ $name ] = $index + 1;
			}
			continue;
		}
		if ( null === $name ) {
			continue;
		}

		$bodies[ $name ]['text'] .= $line . "\n";
		if ( preg_match( '/^= (.+) =$/', $line, $match ) ) {
			$title = $match[1];
			if ( ! isset( $bodies[ $name ]['subsections'][ $title ] ) ) {
				$bodies[ $name ]['subsections'][ $title ] = '';
			}
		}
		if ( null !== $title ) {
			$bodies[ $name ]['subsections'][ $title ] .= $line . "\n";
		}
	}

	$sections = array();
	foreach ( $bodies as $name => $body ) {
		$sections[ $name ] = array(
			'line'        => $starts[ $name ],
			'words'       => rfrac_readme_words( $body['text'] ),
			'subsections' => array_map( 'rfrac_readme_words', $body['subsections'] ),
		);
	}

	return $sections;
}

/**
 * Lists the sections that are longer than WordPress.org shows.
 *
 * @param string[] $lines The lines of readme.txt, without line endings.
 * @return array<int,string> Line number of the section heading => failure reason, in readme order.
 */
function rfrac_readme_word_failures( array $lines ): array {
	$limits   = rfrac_readme_limits();
	$failures = array();

	foreach ( rfrac_readme_sections( $lines ) as $name => $section ) {
		$limit = $limits[ $name ] ?? $limits['*'];
		if ( $section['words'] > $limit ) {
			$failures[ $section['line'] ] = "Section '{$name}' has {$section['words']} words; WordPress.org truncates it after {$limit}";
		}
	}

	return $failures;
}

/**
 * Picks the screenshot files out of a file listing, and numbers them.
 *
 * The one naming rule of the screenshot check: WordPress.org reads `.wordpress-org/screenshot-<n>.png`, n
 * without a leading zero, as the file of caption n. Only the files `.wordpress-org/screenshot-*` count; the
 * other assets of that folder (icons, banners) and every file outside it are none of this check's business.
 *
 * @param string[] $files Repo-relative paths with forward slashes, e.g. from rfrac_git_files().
 * @return array<string,int|null> In file order: the name below `.wordpress-org/` => its number, or null when it
 *         starts with `screenshot-` but is not named `screenshot-<n>.png`.
 */
function rfrac_readme_screenshot_files( array $files ): array {
	$dir   = '.wordpress-org/';
	$shots = array();

	foreach ( $files as $file ) {
		if ( ! str_starts_with( $file, $dir ) ) {
			continue;
		}
		$name = substr( $file, strlen( $dir ) );
		if ( ! str_starts_with( basename( $name ), 'screenshot-' ) ) {
			continue;
		}
		$shots[ $name ] = preg_match( '/^screenshot-([1-9][0-9]*)\.png$/', $name, $match ) ? (int) $match[1] : null;
	}

	return $shots;
}

/**
 * Lists where the `== Screenshots ==` captions and the tracked screenshot files disagree.
 *
 * WordPress.org shows `.wordpress-org/screenshot-<n>.png` under the caption `<n>. <text>` of the Screenshots
 * section, so the captions must run 1, 2, 3... and every caption needs its file and every file its caption.
 * The files are the ones rfrac_readme_screenshot_files() picks. Pure: the caller lists the files.
 *
 * @param string[] $lines The lines of readme.txt, without line endings.
 * @param string[] $files Repo-relative paths with forward slashes, e.g. from rfrac_git_files().
 * @return list<array{line:int,reason:string}> Failures in readme order, then in file order. A failure about a
 *         file is reported at the Screenshots heading, or at line 1 when the readme has no such section.
 */
function rfrac_readme_screenshot_failures( array $lines, array $files ): array {
	$dir    = '.wordpress-org/';
	$shots  = rfrac_readme_screenshot_files( $files ); // File order.
	$failed = array();

	$heading  = null;
	$in       = false;
	$captions = 0;
	$numbers  = array(); // Caption number => true.
	foreach ( $lines as $index => $line ) {
		$no = $index + 1;
		if ( preg_match( '/^==\s*([^=].*?)\s*==$/', $line, $match ) ) {
			$in = 'Screenshots' === $match[1];
			if ( $in && null === $heading ) {
				$heading = $no;
			}
			continue;
		}
		if ( ! $in || '' === trim( $line ) ) {
			continue;
		}

		if ( ! preg_match( '/^([1-9][0-9]*)\. (\S.*)$/', $line, $match ) ) {
			$failed[] = array(
				'line'   => $no,
				'reason' => 'Screenshots line is not a numbered caption "<n>. <text>"',
			);
			continue;
		}

		$got      = (int) $match[1];
		$expected = $captions + 1;
		++$captions;
		$numbers[ $got ] = true;
		if ( $got !== $expected ) {
			$failed[] = array(
				'line'   => $no,
				'reason' => "Screenshot caption {$got} is out of order (expected {$expected})",
			);
		}
		if ( ! in_array( $got, $shots, true ) ) {
			$failed[] = array(
				'line'   => $no,
				'reason' => "Screenshot caption {$got} has no {$dir}screenshot-{$got}.png",
			);
		}
	}

	if ( null !== $heading && 0 === $captions ) {
		array_unshift(
			$failed,
			array(
				'line'   => $heading,
				'reason' => "Section 'Screenshots' has no captions",
			)
		);
	}

	foreach ( $shots as $name => $number ) {
		if ( null === $number ) {
			$failed[] = array(
				'line'   => 1,
				'reason' => "{$dir}{$name} is not named screenshot-<n>.png",
			);
		} elseif ( ! isset( $numbers[ $number ] ) ) {
			$failed[] = array(
				'line'   => $heading ?? 1,
				'reason' => "{$dir}{$name} has no caption in == Screenshots ==",
			);
		}
	}

	return $failed;
}
