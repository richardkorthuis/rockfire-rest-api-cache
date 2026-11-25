<?php
/**
 * Enforces that no shipped suppression is blanket or reasonless.
 *
 * Every `phpcs:ignore` and `phpcs:disable` of the shipped PHP (admin/, includes/, the main plugin file and
 * uninstall.php) must name at least one sniff and give a specific reason after ` -- `.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers the suppression comments of the shipped PHP.
 */
class Test_Suppression_Reasons extends TestCase {

	/**
	 * The reason that says nothing about why a query is safe.
	 *
	 * @var string
	 */
	private const EMPTY_REASON = 'Prepared above.';

	/**
	 * Plugin root.
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Why a suppression comment is not acceptable, or null when it is.
	 *
	 * Comments that are not a `phpcs:ignore`, `phpcs:disable` or `phpcs:ignoreFile` directive are acceptable.
	 *
	 * @param string $comment One line of a comment, markers included.
	 */
	private static function violation( string $comment ): ?string {
		$text = rtrim( ltrim( $comment, " \t/*#" ), " */\t\r\n" );

		if ( preg_match( '/^@codingStandardsIgnore(?:Start|End|Line|File)\b/i', $text ) ) {
			return 'a legacy blanket annotation names no sniff and gives no reason';
		}

		if ( ! preg_match( '/^@?phpcs:(ignoreFile|ignore|disable)(?![A-Za-z])(.*)$/is', $text, $m ) ) {
			return null;
		}

		if ( 0 === strcasecmp( 'ignoreFile', $m[1] ) ) {
			return 'phpcs:ignoreFile silences a whole file';
		}

		$codes  = $m[2];
		$reason = '';
		if ( preg_match( '/^(.*?)\s--(?:\s+(.*))?$/s', $m[2], $parts ) ) {
			$codes  = $parts[1];
			$reason = trim( $parts[2] ?? '' );
		}

		$names = array_values( array_filter( array_map( 'trim', explode( ',', $codes ) ), 'strlen' ) );
		if ( array() === $names ) {
			return 'it names no sniff and so silences everything';
		}
		foreach ( $names as $name ) {
			if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9]*(?:\.[A-Za-z0-9_]+){2,}$/', $name ) ) {
				return "'{$name}' is not a sniff name (Standard.Category.Sniff)";
			}
		}

		if ( '' === $reason || ! preg_match( '/[A-Za-z]/', $reason ) ) {
			return "it has no reason after ' -- '";
		}
		if ( 0 === strcasecmp( self::EMPTY_REASON, rtrim( $reason, '.' ) . '.' ) ) {
			return "'" . self::EMPTY_REASON . "' is not a specific reason";
		}

		return null;
	}

	/**
	 * The comment lines of a PHP source, with their line numbers.
	 *
	 * Only real comments count: the same text inside a string is not a directive.
	 *
	 * @param string $code PHP source.
	 *
	 * @return list<array{0:int,1:string}>
	 */
	private static function comment_lines( string $code ): array {
		$lines = array();
		foreach ( token_get_all( $code ) as $token ) {
			if ( ! is_array( $token ) || ( T_COMMENT !== $token[0] && T_DOC_COMMENT !== $token[0] ) ) {
				continue;
			}
			foreach ( explode( "\n", $token[1] ) as $i => $part ) {
				$lines[] = array( $token[2] + $i, $part );
			}
		}
		return $lines;
	}

	/**
	 * Every acceptable-or-not directive of a PHP source, as `line: problem` entries.
	 *
	 * @param string $code PHP source.
	 *
	 * @return list<string>
	 */
	private static function violations_in( string $code ): array {
		$found = array();
		foreach ( self::comment_lines( $code ) as list( $line, $part ) ) {
			$why = self::violation( $part );
			if ( null !== $why ) {
				$found[] = $line . ': ' . trim( $part ) . ' -> ' . $why;
			}
		}
		return $found;
	}

	/**
	 * Absolute paths of the shipped PHP files, keyed by their path relative to the plugin root.
	 *
	 * @return array<string,string>
	 */
	private static function shipped_files(): array {
		$root  = self::root();
		$files = array();
		foreach ( array( 'admin', 'includes' ) as $dir ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( $file instanceof SplFileInfo && 'php' === $file->getExtension() ) {
					$path           = str_replace( '\\', '/', $file->getPathname() );
					$files[ ltrim( substr( $path, strlen( str_replace( '\\', '/', $root ) ) ), '/' ) ] = $file->getPathname();
				}
			}
		}
		foreach ( array( 'rockfire-rest-api-cache.php', 'uninstall.php' ) as $name ) {
			$files[ $name ] = $root . '/' . $name;
		}
		ksort( $files );
		return $files;
	}

	/**
	 * Comments that must be rejected.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function rejected(): array {
		return array(
			'a sniff without a reason'            => array( '// phpcs:ignore WordPress.DB.DirectDatabaseQuery' ),
			'a code without a reason'             => array( '// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery' ),
			'codes without a reason'              => array( '// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching' ),
			'a blanket ignore'                    => array( '// phpcs:ignore' ),
			'a blanket ignore with a reason'      => array( '// phpcs:ignore -- Because.' ),
			'a blanket disable'                   => array( '// phpcs:disable' ),
			'a blanket block disable'             => array( '/* phpcs:disable */' ),
			'a disable without a reason'          => array( '// phpcs:disable WordPress.Security.NonceVerification' ),
			'an empty reason'                     => array( '// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery --' ),
			'a blank reason'                      => array( '// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- ' ),
			'a reason without words'              => array( '// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- ...' ),
			'the empty reason'                    => array( '// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.' ),
			'the empty reason in lower case'      => array( '// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above' ),
			'a category instead of a sniff'       => array( '// phpcs:ignore WordPress.DB -- Reads its own table.' ),
			'a standard instead of a sniff'       => array( '// phpcs:ignore WordPress -- Reads its own table.' ),
			'a bad code among good ones'          => array( '// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, nonsense -- Reads its own table.' ),
			'codes separated by spaces'           => array( '// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads its own table.' ),
			'a file wide ignore'                  => array( '// phpcs:ignoreFile' ),
			'a file wide ignore with a reason'    => array( '// phpcs:ignoreFile -- Generated.' ),
			'a docblock line without a reason'    => array( ' * phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery' ),
			'a hash comment without a reason'     => array( '# phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery' ),
			'the at form without a reason'        => array( '// @phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery' ),
			'the legacy line annotation'          => array( '// @codingStandardsIgnoreLine' ),
			'the legacy start annotation'         => array( '// @codingStandardsIgnoreStart' ),
		);
	}

	/**
	 * Comments that must be accepted.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function accepted(): array {
		return array(
			'a sniff and a reason'               => array( '// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Probes the plugin table.' ),
			'a code and a reason'                => array( '// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Probes the plugin table.' ),
			'codes and a reason'                 => array( '// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Probes the plugin table.' ),
			'codes without spaces'               => array( '// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Probes the plugin table.' ),
			'a disable with a reason'            => array( '// phpcs:disable WordPress.Security.NonceVerification -- Callers verify the nonce first.' ),
			'a block disable with a reason'      => array( '/* phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Signature of the original. */' ),
			'a docblock line with a reason'      => array( ' * phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Hook signature.' ),
			'a vendor standard code'             => array( '// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Bulk action name.' ),
			'a reason holding a dash pair'       => array( '// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off -- never cached.' ),
			'a re-enable'                        => array( '// phpcs:enable WordPress.Security.NonceVerification' ),
			'a comment that only mentions it'    => array( '// Never write phpcs:ignore without a reason.' ),
			'an unrelated comment'               => array( '// Keep this in sync with the schema.' ),
			'an empty comment'                   => array( '//' ),
		);
	}

	/**
	 * @dataProvider rejected
	 */
	public function test_matcher_rejects( string $comment ): void {
		$this->assertNotNull( self::violation( $comment ), $comment );
	}

	/**
	 * @dataProvider accepted
	 */
	public function test_matcher_accepts( string $comment ): void {
		$this->assertNull( self::violation( $comment ), $comment );
	}

	public function test_the_empty_reason_is_rejected_in_any_source(): void {
		$code = "<?php\n"
			. "\$wpdb->query( \$sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.\n"
			. "\$wpdb->query( \$sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The statement is built with prepare().\n";

		$found = self::violations_in( $code );

		$this->assertCount( 1, $found );
		$this->assertStringStartsWith( '2: ', $found[0] );
		$this->assertStringContainsString( self::EMPTY_REASON, $found[0] );
	}

	public function test_only_real_comments_are_read(): void {
		$code = "<?php\n"
			. "\$a = '// phpcs:ignore WordPress.DB.DirectDatabaseQuery';\n"
			. "\$b = 1; // phpcs:ignore WordPress.DB.DirectDatabaseQuery\n"
			. "/**\n * phpcs:disable\n */\n"
			. "\$c = 2; // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fine.\n";

		$found = self::violations_in( $code );

		$this->assertCount( 2, $found );
		$this->assertStringStartsWith( '3: ', $found[0] );
		$this->assertStringStartsWith( '5: ', $found[1] );
	}

	public function test_the_scan_covers_the_shipped_tree(): void {
		$files = self::shipped_files();

		$this->assertArrayHasKey( 'rockfire-rest-api-cache.php', $files );
		$this->assertArrayHasKey( 'uninstall.php', $files );
		$this->assertArrayHasKey( 'includes/install/class-uninstaller.php', $files );
		$this->assertArrayHasKey( 'admin/class-request.php', $files );
		$this->assertArrayHasKey( 'admin/views/route-edit.php', $files );
		$this->assertArrayHasKey( 'includes/storage/class-repository.php', $files );

		$directives = 0;
		foreach ( $files as $path ) {
			foreach ( self::comment_lines( (string) file_get_contents( $path ) ) as list( , $part ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( preg_match( '/^[\s\/*#@]*phpcs:(?:ignore|disable)/i', $part ) ) {
					++$directives;
				}
			}
		}
		$this->assertGreaterThan( 50, $directives, 'The scan finds the suppressions of the shipped tree.' );
	}

	public function test_every_shipped_suppression_names_a_sniff_and_a_specific_reason(): void {
		$violations = array();
		foreach ( self::shipped_files() as $relative => $path ) {
			$found = self::violations_in( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			foreach ( $found as $entry ) {
				$violations[] = $relative . ':' . $entry;
			}
		}

		$this->assertSame( array(), $violations, "Every suppression needs a sniff name and a reason: '// phpcs:ignore Standard.Category.Sniff -- Why it is safe.'" );
	}
}
