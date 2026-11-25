<?php
/**
 * Tests for the allow decision of bin/check-compliance.php.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/bin/lib/compliance.php';

/**
 * Covers `rfrac_upstream_allowed()` in bin/lib/compliance.php.
 */
class Test_Compliance_Allow_List extends TestCase {

	/**
	 * Files that may name the original plugin by its identifiers.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function allowed_files(): array {
		return array(
			'a file of the legacy directory'      => array( 'includes/legacy/class-x.php' ),
			'a nested file of the legacy folder'  => array( 'includes/legacy/compat/class-y.php' ),
			'the readme'                          => array( 'readme.txt' ),
			'the main plugin file'                => array( 'rockfire-rest-api-cache.php' ),
			'the coexistence guard'               => array( 'includes/install/class-coexistence-guard.php' ),
		);
	}

	/**
	 * Files that must stay free of the identifiers of the original plugin.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function refused_files(): array {
		return array(
			'a sibling directory with the same start' => array( 'includes/legacy-x/a.php' ),
			'the config class'                        => array( 'includes/config/class-config.php' ),
			'the POT'                                 => array( 'languages/rockfire-rest-api-cache.pot' ),
			'the legacy directory as a file'          => array( 'includes/legacy' ),
			'a legacy directory elsewhere'            => array( 'admin/includes/legacy/class-x.php' ),
			'a readme of another case'                => array( 'README.txt' ),
			'the uninstall file'                      => array( 'uninstall.php' ),
			'another install class'                   => array( 'includes/install/class-activator.php' ),
		);
	}

	/**
	 * @dataProvider allowed_files
	 */
	public function test_allows( string $file ): void {
		$this->assertTrue( rfrac_upstream_allowed( $file ) );
	}

	/**
	 * @dataProvider refused_files
	 */
	public function test_refuses( string $file ): void {
		$this->assertFalse( rfrac_upstream_allowed( $file ) );
	}

	public function test_the_script_uses_the_helper_and_keeps_its_pattern(): void {
		$script = (string) file_get_contents( dirname( __DIR__ ) . '/bin/check-compliance.php' );

		$this->assertStringContainsString( "require __DIR__ . '/lib/compliance.php';", $script );
		$this->assertStringContainsString( 'rfrac_upstream_allowed( $file )', $script );
		$this->assertStringContainsString( "'/wp_rest_cache|wp-rest-cache|wprc|wrc_|WP_Rest_Cache_Plugin|acato/i'", $script );
	}

	public function test_check_compliance_fails_the_run_on_a_forbidden_path(): void {
		$script = (string) file_get_contents( dirname( __DIR__ ) . '/bin/check-compliance.php' );

		$this->assertStringContainsString( "require __DIR__ . '/lib/distignore.php';", $script );
		$this->assertMatchesRegularExpression(
			'/foreach \( rfrac_forbidden_paths\( \$shipped \) as \$file => \$reason \) \{\s*rfrac_fail\( \$file, \d+, /',
			$script,
			'check-compliance.php must record a failure for every path rfrac_forbidden_paths( $shipped ) returns.'
		);
		$this->assertMatchesRegularExpression(
			'/if \( \$failures \) \{[^}]*exit\( 1 \);/',
			$script,
			'check-compliance.php must exit non-zero once a failure is recorded.'
		);
	}

	public function test_build_zip_fails_the_build_on_a_forbidden_path(): void {
		$script = (string) file_get_contents( dirname( __DIR__ ) . '/bin/build-zip.php' );

		$this->assertStringContainsString( "require __DIR__ . '/lib/distignore.php';", $script );
		$this->assertMatchesRegularExpression(
			'/foreach \( rfrac_forbidden_paths\( \$entries \) as \$name => \$reason \) \{\R(?:.*\R){0,3}?\s*\$failed = true;\R\}/',
			$script,
			'build-zip.php must mark the build as failed for every entry rfrac_forbidden_paths( $entries ) returns.'
		);
		$this->assertMatchesRegularExpression(
			'/if \( \$failed \) \{\s*exit\( 1 \);/',
			$script,
			'build-zip.php must exit non-zero once the build is marked as failed.'
		);
	}
}
