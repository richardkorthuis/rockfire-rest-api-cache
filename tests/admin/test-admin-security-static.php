<?php
/**
 * Static regression checks for the admin hardening (M4, L5).
 *
 * @package Rockfire_Rest_Api_Cache
 */

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class Test_Admin_Security_Static extends TestCase {

	/**
	 * Plugin root.
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Files under a plugin directory, relative to the root, with forward slashes.
	 *
	 * @param string       $dir        Directory relative to the root.
	 * @param list<string> $extensions File extensions.
	 * @return array<string,string> Relative path => contents.
	 */
	private static function files( string $dir, array $extensions = array( 'php' ) ): array {
		$out = array();
		$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( self::root() . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file instanceof SplFileInfo && in_array( $file->getExtension(), $extensions, true ) ) {
				$relative         = str_replace( '\\', '/', substr( $file->getPathname(), strlen( self::root() ) + 1 ) );
				$out[ $relative ] = self::read( $file->getPathname() );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * The contents of a file.
	 *
	 * @param string $path Absolute path.
	 */
	private static function read( string $path ): string {
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_only_the_redirect_class_redirects_and_it_exits_right_after(): void {
		$found = array();
		foreach ( self::files( 'admin' ) + self::files( 'includes' ) as $file => $code ) {
			if ( preg_match( '/\bwp_(safe_)?redirect\s*\(/', $code ) ) {
				$found[] = $file;
			}
		}
		$this->assertSame( array( 'admin/class-redirect.php' ), $found );

		$code = (string) file_get_contents( self::root() . '/admin/class-redirect.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( 1, preg_match_all( '/\bwp_safe_redirect\s*\([^;]*\);\s*exit;/', $code ), 'exit; directly after the redirect.' );
		$this->assertSame( 1, preg_match_all( '/\bwp_safe_redirect\s*\(/', $code ) );
		$this->assertStringContainsString( 'wp_safe_redirect( $url, 303 );', $code );
	}

	public function test_no_nopriv_handlers_or_ajax_actions_in_admin(): void {
		foreach ( self::files( 'admin' ) as $file => $code ) {
			$this->assertDoesNotMatchRegularExpression( '/[\'"](admin_post_nopriv_|wp_ajax_)/', $code, $file );
		}
	}

	public function test_only_the_action_handler_hooks_admin_post(): void {
		$found = array();
		foreach ( self::files( 'admin' ) + self::files( 'includes' ) as $file => $code ) {
			if ( preg_match( '/[\'"]admin_post_/', $code ) ) {
				$found[] = $file;
			}
		}
		$this->assertSame( array( 'admin/class-action-handler.php' ), $found, 'Every admin-post handler goes through Action_Handler (capability, nonce, safe redirect).' );
	}

	public function test_no_raw_location_headers(): void {
		foreach ( self::files( 'admin' ) + self::files( 'includes' ) as $file => $code ) {
			$this->assertDoesNotMatchRegularExpression( '/\bheader\s*\(\s*[\'"]\s*Location\s*:/i', $code, $file );
		}
	}

	public function test_only_the_request_class_reads_superglobals_in_admin(): void {
		foreach ( self::files( 'admin' ) as $file => $code ) {
			if ( 'admin/class-request.php' === $file ) {
				continue;
			}
			$this->assertDoesNotMatchRegularExpression( '/\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES|ENV)\b/', $code, $file );
			$this->assertDoesNotMatchRegularExpression( '/\$GLOBALS\s*\[|\bfilter_input(_array)?\s*\(|\bINPUT_(GET|POST|COOKIE|SERVER|ENV|REQUEST)\b|php:\/\/input|\bgetenv\s*\(/i', $code, $file );
		}
	}

	public function test_superglobals_are_read_only_by_the_two_readers(): void {
		$sources = self::files( 'admin' ) + self::files( 'includes' );
		foreach ( array( 'rockfire-rest-api-cache.php', 'uninstall.php' ) as $file ) {
			$sources[ $file ] = self::read( self::root() . '/' . $file );
		}

		$superglobals = array( '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SERVER', '$_FILES' );
		$found        = array();
		foreach ( $sources as $file => $code ) {
			// Tokens, not text: a superglobal named in a comment or a docblock is not a read.
			foreach ( token_get_all( $code ) as $token ) {
				if ( is_array( $token ) && T_VARIABLE === $token[0] && in_array( $token[1], $superglobals, true ) ) {
					$found[] = $file;
					break;
				}
			}
		}
		sort( $found );
		$this->assertSame(
			array( 'admin/class-request.php', 'includes/request/class-request-context.php' ),
			$found,
			'Only Admin\Request (the admin input) and Request_Context (the front-end request) read the superglobals.'
		);
	}

	public function test_no_deactivate_plugins_call_ships(): void {
		$skipped = array( 'vendor', 'tests', 'node_modules' );
		$filter  = new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( self::root(), FilesystemIterator::SKIP_DOTS ),
			static function ( SplFileInfo $file ) use ( $skipped ): bool {
				// Dot directories (.git, .github) hold no shipped code.
				return ! $file->isDir() || ( ! in_array( $file->getFilename(), $skipped, true ) && '.' !== substr( $file->getFilename(), 0, 1 ) );
			}
		);

		$scanned = 0;
		$found   = array();
		foreach ( new RecursiveIteratorIterator( $filter ) as $file ) {
			if ( $file instanceof SplFileInfo && 'php' === $file->getExtension() ) {
				++$scanned;
				if ( str_contains( self::read( $file->getPathname() ), 'deactivate_plugins(' ) ) {
					$found[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( self::root() ) + 1 ) );
				}
			}
		}

		$this->assertGreaterThan( 50, $scanned, 'The scan reaches the shipped code.' );
		$this->assertSame( array(), $found, 'The plugin never deactivates another plugin itself: the Plugins screen does, through its own nonce\'d link.' );
	}

	public function test_no_jquery_ui_inline_scripts_or_event_handlers_in_admin(): void {
		foreach ( self::files( 'admin', array( 'php', 'js', 'css' ) ) as $file => $code ) {
			$this->assertStringNotContainsStringIgnoringCase( 'jquery-ui', $code, $file );
			if ( 'php' === pathinfo( $file, PATHINFO_EXTENSION ) ) {
				$this->assertDoesNotMatchRegularExpression( '/<script\b|\bon(click|submit|change|load)\s*=/i', $code, $file );
			}
		}
		$js = (string) file_get_contents( self::root() . '/admin/assets/admin.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertDoesNotMatchRegularExpression( '/jQuery|\$\(/', $js, 'admin.js has no dependencies.' );
	}

	public function test_the_legacy_admin_is_gone(): void {
		foreach ( array( 'admin/includes', 'admin/partials', 'admin/css' ) as $dir ) {
			$this->assertDirectoryDoesNotExist( self::root() . '/' . $dir );
		}
		foreach ( self::files( 'admin' ) + self::files( 'includes' ) as $file => $code ) {
			$this->assertStringNotContainsString( 'options-general.php?page=rfrac', $code, $file );
			$this->assertStringNotContainsString( 'rfrac_flush_caches', $code, $file );
		}
	}

	public function test_every_admin_php_file_has_an_abspath_guard(): void {
		foreach ( self::files( 'admin' ) as $file => $code ) {
			$this->assertMatchesRegularExpression( "/^\s*defined\(\s*'ABSPATH'\s*\)\s*\|\|\s*exit;/m", $code, $file );
		}
	}
}
