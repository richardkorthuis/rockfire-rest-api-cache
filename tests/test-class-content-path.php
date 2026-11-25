<?php
/**
 * Tests for Content_Path, the helper that shows file paths relative to wp-content and never as absolute paths.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Content_Path;

/**
 * @covers \Rockfire\Rest_Api_Cache\Content_Path
 */
class Test_Content_Path extends WP_UnitTestCase {

	// ---------- relative() ----------

	public function test_a_path_inside_wp_content_loses_the_wp_content_prefix(): void {
		$this->assertSame( 'mu-plugins/rfrac-early-cache.php', Content_Path::relative( WP_CONTENT_DIR . '/mu-plugins/rfrac-early-cache.php' ) );
	}

	public function test_backslash_separators_are_normalised_first(): void {
		$path = str_replace( '/', '\\', WP_CONTENT_DIR . '/mu-plugins/rfrac-early-cache.php' );

		$this->assertSame( 'mu-plugins/rfrac-early-cache.php', Content_Path::relative( $path ) );
	}

	public function test_a_trailing_slash_on_the_base_makes_no_difference(): void {
		$this->assertSame( 'mu-plugins/rfrac-early-cache.php', Content_Path::relative( trailingslashit( WP_CONTENT_DIR ) . 'mu-plugins/rfrac-early-cache.php' ) );
		$this->assertSame( 'a/b.php', Content_Path::relative( '/srv/base/a/b.php', '/srv/base/' ) );
		$this->assertSame( 'a/b.php', Content_Path::relative( '/srv/base/a/b.php', '/srv/base' ) );
	}

	public function test_a_path_outside_wp_content_has_no_relative_form(): void {
		$this->assertNull( Content_Path::relative( '/srv/other/x.php' ) );
		$this->assertNull( Content_Path::relative( 'C:\\srv\\other\\x.php' ) );
	}

	public function test_a_sibling_directory_sharing_the_wp_content_name_is_not_inside_it(): void {
		$this->assertNull( Content_Path::relative( WP_CONTENT_DIR . '-old/x.php' ) );
	}

	public function test_wp_content_itself_has_no_relative_form(): void {
		$this->assertNull( Content_Path::relative( WP_CONTENT_DIR ) );
	}

	public function test_an_empty_path_has_no_relative_form(): void {
		$this->assertNull( Content_Path::relative( '' ) );
	}

	public function test_a_given_base_replaces_wp_content(): void {
		$this->assertSame( 'plugins/x.php', Content_Path::relative( '/srv/base/plugins/x.php', '/srv/base' ) );
		$this->assertNull( Content_Path::relative( WP_CONTENT_DIR . '/plugins/x.php', '/srv/base' ) );
	}

	// ---------- shown() ----------

	public function test_a_path_inside_wp_content_is_shown_relative_or_with_the_wp_content_prefix(): void {
		$path = WP_CONTENT_DIR . '/mu-plugins/rfrac-early-cache.php';

		$this->assertSame( 'mu-plugins/rfrac-early-cache.php', Content_Path::shown( $path ) );
		$this->assertSame( 'mu-plugins/rfrac-early-cache.php', Content_Path::shown( $path, false ) );
		$this->assertSame( 'wp-content/mu-plugins/rfrac-early-cache.php', Content_Path::shown( $path, true ) );
	}

	public function test_backslash_and_trailing_slash_variants_are_shown_the_same(): void {
		$backslashes = str_replace( '/', '\\', WP_CONTENT_DIR . '/mu-plugins/x.php' );
		$doubled     = trailingslashit( WP_CONTENT_DIR ) . 'mu-plugins/x.php';

		$this->assertSame( 'mu-plugins/x.php', Content_Path::shown( $backslashes ) );
		$this->assertSame( 'wp-content/mu-plugins/x.php', Content_Path::shown( $backslashes, true ) );
		$this->assertSame( 'mu-plugins/x.php', Content_Path::shown( $doubled ) );
		$this->assertSame( 'wp-content/mu-plugins/x.php', Content_Path::shown( $doubled, true ) );
	}

	public function test_an_outside_path_is_shown_as_its_last_directory_and_file_behind_an_ellipsis(): void {
		foreach ( array( false, true ) as $with_prefix ) {
			$this->assertSame( "\u{2026}/other/x.php", Content_Path::shown( '/srv/other/x.php', $with_prefix ) );
			$this->assertSame( "\u{2026}/other/x.php", Content_Path::shown( 'C:\\srv\\other\\x.php', $with_prefix ) );
			$this->assertSame( "\u{2026}/" . basename( WP_CONTENT_DIR ) . '-old/x.php', Content_Path::shown( WP_CONTENT_DIR . '-old/x.php', $with_prefix ) );
		}
	}

	public function test_an_outside_path_without_a_directory_is_shown_as_its_file_name(): void {
		$this->assertSame( "\u{2026}/x.php", Content_Path::shown( 'C:\\x.php' ) );
		$this->assertSame( "\u{2026}/x.php", Content_Path::shown( 'x.php' ) );
		$this->assertSame( "\u{2026}/x.php", Content_Path::shown( './x.php' ) );
		$this->assertSame( "\u{2026}/x.php", Content_Path::shown( '/x.php' ) );
	}

	public function test_no_outside_path_leaks_its_parent_directories(): void {
		$shown = Content_Path::shown( '/srv/deploy/releases/42/plugin/mu-plugin/x.php' );

		$this->assertSame( "\u{2026}/mu-plugin/x.php", $shown );
		$this->assertStringNotContainsString( 'srv', $shown );
		$this->assertStringNotContainsString( 'releases', $shown );
	}

	public function test_wp_content_itself_and_an_empty_path_are_not_inside_it(): void {
		$content = wp_normalize_path( WP_CONTENT_DIR );
		$parent  = basename( dirname( $content ) );

		$this->assertSame( "\u{2026}/" . $parent . '/' . basename( $content ), Content_Path::shown( WP_CONTENT_DIR ) );
		$this->assertSame( "\u{2026}/", Content_Path::shown( '' ) );
	}

	public function test_a_given_base_replaces_wp_content_for_shown(): void {
		$this->assertSame( 'plugins/x.php', Content_Path::shown( '/srv/base/plugins/x.php', false, '/srv/base' ) );
		$this->assertSame( 'wp-content/plugins/x.php', Content_Path::shown( '/srv/base/plugins/x.php', true, '/srv/base' ) );
		$this->assertSame( "\u{2026}/plugins/x.php", Content_Path::shown( WP_CONTENT_DIR . '/plugins/x.php', false, '/srv/base' ) );
	}
}
