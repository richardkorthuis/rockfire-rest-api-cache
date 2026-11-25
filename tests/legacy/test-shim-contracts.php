<?php
/**
 * Tests for the contracts of the WP REST Cache class shims that the legacy classes reference on rockfire.nl documents.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Legacy\Shim_Loader;

/**
 * The readme only summarises the shims and links to https://rockfire.nl/docs/migration/legacy-classes/, which lists every
 * method. These tests pin, in the source, what that reference states: which methods work through which replacement, which
 * ones are recorded no-ops, and that the shims keep no data of their own.
 *
 * The shim files are read as text, not loaded: whether a shim class can be loaded depends on the original plugin being
 * absent, and the contracts have to hold either way.
 */
class Test_Shim_Contracts extends WP_UnitTestCase {

	/**
	 * Heading of the readme answer about the classes.
	 *
	 * @var string
	 */
	private const HEADING = '= Does code that calls WP REST Cache classes still work? =';

	/**
	 * The reference the readme answer links to.
	 *
	 * @var string
	 */
	private const REFERENCE = 'https://rockfire.nl/docs/migration/legacy-classes/';

	/**
	 * The replacement a working method may name that is not a function: the setting `get_timeout()` reads.
	 *
	 * @var string
	 */
	private const SETTING_REPLACEMENT = 'the default_ttl setting of the plugin';

	/**
	 * The root of the plugin.
	 *
	 * @return string
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * The source of a file of the plugin.
	 *
	 * @param string $relative The path below the plugin root.
	 *
	 * @return string
	 */
	private function source( string $relative ): string {
		$source = file_get_contents( self::root() . '/' . $relative ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertIsString( $source );

		return $source;
	}

	/**
	 * The public methods of a shim file with their bodies, read from the source.
	 *
	 * @param string $relative The file below `includes/legacy/shims/`.
	 *
	 * @return array<string,string> Method name => body.
	 */
	private function public_methods( string $relative ): array {
		preg_match_all( '/^\tpublic (?:static )?function (\w+)\([^{]*\{[^\n]*\n(.*?)\n\t\}\n/ms', $this->source( 'includes/legacy/shims/' . $relative ), $matches, PREG_SET_ORDER );

		$methods = array();
		foreach ( $matches as $match ) {
			$methods[ $match[1] ] = $match[2];
		}
		unset( $methods['get_instance'] );

		$this->assertNotSame( array(), $methods, "No public method found in $relative." );

		return $methods;
	}

	/**
	 * The readme answer about the classes, from its heading to the next heading.
	 *
	 * @return string
	 */
	private function readme_answer(): string {
		$lines = file( self::root() . '/readme.txt', FILE_IGNORE_NEW_LINES );
		$this->assertIsArray( $lines );

		$found = array();
		$in    = false;
		foreach ( $lines as $line ) {
			if ( self::HEADING === $line ) {
				$in = true;
				continue;
			}
			if ( $in && 1 === preg_match( '/^==?[^=]/', $line ) ) {
				break;
			}
			if ( $in ) {
				$found[] = $line;
			}
		}

		$this->assertNotSame( array(), $found, 'The readme answer about the classes is missing or empty.' );

		return implode( "\n", $found );
	}

	public function test_the_readme_answer_links_to_the_legacy_classes_reference(): void {
		$this->assertStringContainsString( self::REFERENCE, $this->readme_answer() );
	}

	public function test_the_readme_answer_adds_no_section_to_the_readme(): void {
		foreach ( explode( "\n", $this->readme_answer() ) as $line ) {
			$this->assertStringStartsNotWith( '==', $line, 'The answer about the classes must not open a section.' );
		}
	}

	public function test_the_loader_shims_the_classes_and_controllers_the_reference_names(): void {
		$classes = array_keys( Shim_Loader::classes() );

		$this->assertCount( 7, $classes, 'The reference speaks of seven shimmed classes.' );
		foreach ( array(
			'wp_rest_cache_plugin\includes\caching\caching',
			'wp_rest_cache_plugin\includes\api\endpoint_api',
			'wp_rest_cache_endpoint_api',
			'wp_rest_cache_plugin\includes\controller\post_controller',
			'wp_rest_cache_plugin\includes\controller\term_controller',
			'wp_rest_cache_plugin\includes\controller\attachment_controller',
			'wp_rest_cache_plugin\includes\controller\controller_trait',
		) as $class ) {
			$this->assertContains( $class, $classes, "$class is not shimmed." );
		}
	}

	/**
	 * Every public method of `Caching` is one of the two kinds the reference lists: a call that works (a deprecation notice
	 * naming its replacement) or a recorded no-op (a not-supported notice, also through `event()`). A method may be both, when
	 * only some of its arguments work.
	 */
	public function test_every_public_caching_method_works_through_a_replacement_or_is_a_recorded_no_op(): void {
		$checked = 0;
		foreach ( $this->public_methods( 'caching/class-caching.php' ) as $method => $body ) {
			$works = str_contains( $body, '$this->deprecated(' );
			$noop  = str_contains( $body, '$this->unsupported(' ) || str_contains( $body, '$this->event(' );
			$this->assertTrue( $works || $noop, "Caching::$method() neither names a replacement nor records a not-supported call." );
			++$checked;
		}

		$this->assertGreaterThan( 20, $checked );
	}

	public function test_every_working_caching_method_names_a_replacement_that_exists(): void {
		$source = $this->source( 'includes/legacy/shims/caching/class-caching.php' );
		preg_match_all( "/\\\$this->deprecated\(\s*__FUNCTION__,\s*'([^']+)'\s*\)/", $source, $matches );
		$this->assertNotSame( array(), $matches[1], 'No working method found.' );

		foreach ( array_unique( $matches[1] ) as $replacement ) {
			if ( self::SETTING_REPLACEMENT === $replacement ) {
				continue;
			}
			$this->assertMatchesRegularExpression( '/^rfrac_\w+\(\)$/', $replacement, "$replacement is not a public function of the plugin." );
			$this->assertTrue( function_exists( substr( $replacement, 0, -2 ) ), "$replacement does not exist." );
		}

		// The replacements the reference lists for the flush and tag methods.
		foreach ( array( 'rfrac_flush_url()', 'rfrac_flush_route()', 'rfrac_flush_tag()', 'rfrac_flush_all()', 'rfrac_tag_response()' ) as $function ) {
			$this->assertContains( $function, $matches[1], "No working method is replaced by $function." );
		}
	}

	public function test_the_flush_strictness_maps_onto_the_url_and_route_flush(): void {
		$body = $this->public_methods( 'caching/class-caching.php' )['delete_cache_by_endpoint'];

		// FLUSH_STRICT flushes one URL; FLUSH_PARAMS and FLUSH_LOOSE flush the route and everything below it.
		$this->assertMatchesRegularExpression( "/if \( self::FLUSH_STRICT === \\\$strictness \) \{\s*\\\$this->deprecated\( __FUNCTION__, 'rfrac_flush_url\(\)' \);\s*return rfrac_flush_url\(/", $body );
		$this->assertMatchesRegularExpression( "/\\\$this->deprecated\( __FUNCTION__, 'rfrac_flush_route\(\)' \);\s*return rfrac_flush_route\( \\\$route \)/", $body );
		foreach ( array( 'FLUSH_STRICT', 'FLUSH_PARAMS', 'FLUSH_LOOSE' ) as $constant ) {
			$this->assertStringContainsString( 'self::' . $constant, $body, "delete_cache_by_endpoint() does not accept $constant." );
		}
	}

	public function test_get_timeout_interval_returns_one_and_get_timeout_the_default_ttl(): void {
		$methods = $this->public_methods( 'caching/class-caching.php' );

		$this->assertMatchesRegularExpression( '/return 1;/', $methods['get_timeout_interval'], 'The shim returns 1 whatever the settings say.' );
		$this->assertStringNotContainsString( 'Config::', $methods['get_timeout_interval'], 'The shim does not read the settings.' );
		$this->assertStringContainsString( "['default_ttl']", $methods['get_timeout'] );
	}

	public function test_the_source_records_every_unsupported_call_as_a_finding(): void {
		// The notice helper stores every unsupported call as a finding of the classes group.
		$notice = $this->source( 'includes/legacy/class-shim-notice.php' );
		$this->assertMatchesRegularExpression( '/function unsupported\([^{]*\{.*?self::record\(/s', $notice, 'Shim_Notice::unsupported() no longer records a finding.' );
		$this->assertMatchesRegularExpression( '/function record\([^{]*\{.*?->add\(\s*Findings::GROUP_CLASSES\b/s', $notice, 'Shim_Notice::record() no longer adds a finding of the classes group.' );

		// Caching routes its not-supported calls through Shim_Notice too.
		$this->assertMatchesRegularExpression( '/function unsupported\([^{]*\{[^}]*Shim_Notice::unsupported\(/s', $this->source( 'includes/legacy/shims/caching/class-caching.php' ), 'Caching no longer routes unsupported calls through Shim_Notice.' );

		// Every method of Endpoint_Api is a recorded no-op.
		foreach ( $this->public_methods( 'api/class-endpoint-api.php' ) as $method => $body ) {
			$this->assertStringContainsString( 'Shim_Notice::unsupported(', $body, "Endpoint_Api::$method() no longer records a finding." );
		}
	}

	/**
	 * The reference says the shims fire no hook and keep no other data: their only writes are the findings Shim_Notice records.
	 */
	public function test_the_shims_fire_no_hook_and_write_no_option_of_their_own(): void {
		foreach ( array( 'caching/class-caching.php', 'api/class-endpoint-api.php', 'class-wp-rest-cache-endpoint-api.php' ) as $file ) {
			$source = $this->source( 'includes/legacy/shims/' . $file );
			$this->assertDoesNotMatchRegularExpression( '/\b(do_action|apply_filters|update_option|add_option|set_transient|update_site_option)\s*\(/', $source, "$file fires a hook or writes data of its own." );
		}
	}

	public function test_the_findings_are_shown_by_the_import_page_and_site_health(): void {
		// The reference says REST API Cache → Import and Site Health list the classes group.
		foreach ( array( 'includes/legacy/class-import-page.php', 'includes/legacy/class-health.php' ) as $file ) {
			$this->assertMatchesRegularExpression( "/Findings::GROUP_CLASSES\s*=>\s*__\(\s*'Classes'/", $this->source( $file ), "$file no longer labels the classes group." );
		}
	}
}
