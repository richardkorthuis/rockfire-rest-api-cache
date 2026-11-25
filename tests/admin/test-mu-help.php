<?php
/**
 * Tests for the shared must-use texts: the manual install instructions.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Mu_Help;

/**
 * @covers \Rockfire\Rest_Api_Cache\Admin\Mu_Help
 */
class Test_Mu_Help extends Rfrac_Test_Case {

	private const SENTENCE = 'The paths are relative to the wp-content directory.';

	/**
	 * The part of the manual install instructions that names the paths, as printed (not the placeholder paragraph after it).
	 *
	 * @param string $template Path of the template.
	 * @param string $target   Path of the must-use file.
	 */
	private function instructions( string $template, string $target ): string {
		ob_start();
		try {
			Mu_Help::render_manual_instructions( $template, $target );
		} finally {
			$output = (string) ob_get_clean();
		}
		$this->assertStringContainsString( 'In the copy, replace', $output );
		return (string) strstr( $output, '<p>In the copy, replace', true );
	}

	/**
	 * A path as printed in the instructions.
	 *
	 * @param string $shown The path as it should be shown, not yet escaped.
	 */
	private function code( string $shown ): string {
		return '<code class="rfrac-path">' . esc_html( $shown ) . '</code>';
	}

	public function test_the_path_helpers_live_in_content_path_and_the_class_needs_no_escape_output_ignore(): void {
		$lines = file( dirname( __DIR__, 2 ) . '/admin/class-mu-help.php' );
		$this->assertIsArray( $lines );
		$code = implode( '', $lines );

		$this->assertStringNotContainsString( 'EscapeOutput', $code );
		$this->assertStringNotContainsString( 'phpcs:ignore', $code );
		$this->assertStringNotContainsString( 'function relative_path', $code );
		$this->assertStringNotContainsString( 'function shown_path', $code );
		$this->assertFalse( method_exists( Mu_Help::class, 'relative_path' ) );
		$this->assertFalse( method_exists( Mu_Help::class, 'shown_path' ) );
	}

	public function test_instructions_for_paths_inside_wp_content_are_relative_and_say_so(): void {
		$html = $this->instructions(
			WP_CONTENT_DIR . '/plugins/rockfire-rest-api-cache/mu-plugin/rfrac-early-cache.php',
			WP_CONTENT_DIR . '/mu-plugins/rfrac-early-cache.php'
		);

		$this->assertStringContainsString( $this->code( 'plugins/rockfire-rest-api-cache/mu-plugin/rfrac-early-cache.php' ), $html );
		$this->assertStringContainsString( $this->code( 'mu-plugins/rfrac-early-cache.php' ), $html );
		$this->assertStringContainsString( self::SENTENCE, $html );
		$this->assertStringNotContainsString( wp_normalize_path( WP_CONTENT_DIR ), $html );
	}

	public function test_instructions_for_a_template_outside_wp_content_print_no_absolute_path_and_no_relative_sentence(): void {
		$html = $this->instructions(
			'/srv/deploy/releases/42/rockfire-rest-api-cache/mu-plugin/rfrac-early-cache.php',
			WP_CONTENT_DIR . '/mu-plugins/rfrac-early-cache.php'
		);

		$this->assertStringContainsString( $this->code( "\u{2026}/mu-plugin/rfrac-early-cache.php" ), $html );
		$this->assertStringContainsString( $this->code( 'wp-content/mu-plugins/rfrac-early-cache.php' ), $html, 'A path inside wp-content next to one outside it says what it is relative to.' );
		$this->assertStringNotContainsString( '/srv', $html );
		$this->assertStringNotContainsString( 'releases', $html );
		$this->assertStringNotContainsString( wp_normalize_path( WP_CONTENT_DIR ), $html );
		$this->assertStringNotContainsString( self::SENTENCE, $html );
	}

	public function test_instructions_for_a_target_outside_wp_content_print_no_absolute_path_and_no_relative_sentence(): void {
		$html = $this->instructions(
			WP_CONTENT_DIR . '/plugins/rockfire-rest-api-cache/mu-plugin/rfrac-early-cache.php',
			'/srv/shared/mu/rfrac-early-cache.php'
		);

		$this->assertStringContainsString( $this->code( 'wp-content/plugins/rockfire-rest-api-cache/mu-plugin/rfrac-early-cache.php' ), $html );
		$this->assertStringContainsString( $this->code( "\u{2026}/mu/rfrac-early-cache.php" ), $html );
		$this->assertStringNotContainsString( '/srv', $html );
		$this->assertStringNotContainsString( 'shared', $html );
		$this->assertStringNotContainsString( self::SENTENCE, $html );
	}

	public function test_instructions_for_paths_all_outside_wp_content_print_no_absolute_path(): void {
		$html = $this->instructions(
			'C:\\srv\\deploy\\rockfire-rest-api-cache\\mu-plugin\\rfrac-early-cache.php',
			WP_CONTENT_DIR . '-old/mu/rfrac-early-cache.php'
		);

		$this->assertStringContainsString( $this->code( "\u{2026}/mu-plugin/rfrac-early-cache.php" ), $html );
		$this->assertStringContainsString( $this->code( "\u{2026}/mu/rfrac-early-cache.php" ), $html );
		$this->assertStringNotContainsString( 'srv', $html );
		$this->assertStringNotContainsString( 'deploy', $html );
		$this->assertStringNotContainsString( '-old', $html );
		$this->assertStringNotContainsString( self::SENTENCE, $html );
	}

	public function test_an_outside_path_without_a_directory_is_shown_as_its_file_name(): void {
		$html = $this->instructions( 'C:\\rfrac-early-cache.php', 'rfrac-early-cache.php' );

		$this->assertSame( 2, substr_count( $html, $this->code( "\u{2026}/rfrac-early-cache.php" ) ) );
		$this->assertStringNotContainsString( 'C:', $html );
	}

	public function test_the_shown_directory_is_escaped(): void {
		$html = $this->instructions( '/srv/<i>/rfrac-early-cache.php', WP_CONTENT_DIR . '/mu-plugins/rfrac-early-cache.php' );

		$this->assertStringContainsString( '&lt;i&gt;/rfrac-early-cache.php', $html );
		$this->assertStringNotContainsString( '<i>', $html );
	}
}
