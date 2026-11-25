<?php
/**
 * Tests for the screenshot check that bin/check-readme.php runs on readme.txt.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/bin/lib/readme.php';

/**
 * Covers `rfrac_readme_screenshot_failures()` in bin/lib/readme.php and its wiring in bin/check-readme.php.
 *
 * Plain PHPUnit, without WordPress: the readme is a list of lines and the files are a list of paths, both
 * built in the test. The header takes lines 1 to 5, so the first section heading is line 6. The script tests
 * run bin/check-readme.php as a child process in a throwaway git checkout of a tiny plugin.
 */
class Test_Readme_Screenshots extends TestCase {

	/**
	 * Throwaway directory that holds the fixture of one script test, or empty before it is made.
	 *
	 * @var string
	 */
	private string $base = '';

	/**
	 * Removes the fixture directory of a script test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( '' !== $this->base && is_dir( $this->base ) ) {
			$this->remove_tree( $this->base );
		}

		parent::tearDown();
	}

	/**
	 * Builds a readme: the header, then each section as a heading line and the given body lines.
	 *
	 * @param array<string,string[]> $sections Section name => body lines.
	 * @return string[]
	 */
	private static function readme( array $sections ): array {
		$lines = array( '=== Example ===', 'Contributors: someone', '', 'A short description of the example.', '' );
		foreach ( $sections as $name => $body ) {
			$lines[] = "== {$name} ==";
			array_push( $lines, ...$body );
			$lines[] = '';
		}

		return $lines;
	}

	/**
	 * A readme whose only section is Screenshots (heading at line 6, first body line at line 7).
	 *
	 * @param string[] $body The lines of the Screenshots section.
	 * @return string[]
	 */
	private static function screenshots( array $body ): array {
		return self::readme( array( 'Screenshots' => $body ) );
	}

	/**
	 * A failure as the helper returns it.
	 *
	 * @param int    $line   Line number.
	 * @param string $reason Failure reason.
	 * @return array{line:int,reason:string}
	 */
	private static function failure( int $line, string $reason ): array {
		return array(
			'line'   => $line,
			'reason' => $reason,
		);
	}

	/**
	 * No Screenshots section and no screenshot file is nothing to check.
	 *
	 * @return void
	 */
	public function test_no_section_and_no_files_passes(): void {
		$this->assertSame( array(), rfrac_readme_screenshot_failures( self::readme( array( 'Description' => array( 'Text.' ) ) ), array() ) );
		$this->assertSame( array(), rfrac_readme_screenshot_failures( array(), array() ) );
	}

	/**
	 * Matching captions and files pass; the other assets of the folder and files elsewhere are ignored.
	 *
	 * @return void
	 */
	public function test_matching_captions_and_files_pass(): void {
		$files = array(
			'readme.txt',
			'.wordpress-org/icon-256x256.png',
			'.wordpress-org/banner-772x250.png',
			'.wordpress-org/screenshot-1.png',
			'.wordpress-org/screenshot-2.png',
			'assets/screenshot-3.png',
			'tests/screenshot-01.jpg',
		);

		$this->assertSame( array(), rfrac_readme_screenshot_failures( self::screenshots( array( '1. A', '2. B' ) ), $files ) );
	}

	/**
	 * Blank lines are no captions, and the section ends at the next heading: a numbered list elsewhere is not read.
	 *
	 * @return void
	 */
	public function test_only_the_screenshots_section_is_read(): void {
		$lines = self::readme(
			array(
				'Installation' => array( '1. Upload the plugin.', '2. Activate it.' ),
				'Screenshots'  => array( '', '1. A', '   ', '2. B', '' ),
				'Changelog'    => array( '3. Not a caption.' ),
			)
		);
		$files = array( '.wordpress-org/screenshot-1.png', '.wordpress-org/screenshot-2.png' );

		$this->assertSame( array(), rfrac_readme_screenshot_failures( $lines, $files ) );
	}

	/**
	 * A caption without its file fails at the caption and names the file.
	 *
	 * @return void
	 */
	public function test_caption_without_file_fails_at_the_caption(): void {
		$this->assertSame(
			array( self::failure( 8, 'Screenshot caption 2 has no .wordpress-org/screenshot-2.png' ) ),
			rfrac_readme_screenshot_failures( self::screenshots( array( '1. A', '2. B' ) ), array( '.wordpress-org/screenshot-1.png' ) )
		);
	}

	/**
	 * A file without its caption fails at the Screenshots heading.
	 *
	 * @return void
	 */
	public function test_file_without_caption_fails_at_the_heading(): void {
		$this->assertSame(
			array( self::failure( 6, '.wordpress-org/screenshot-2.png has no caption in == Screenshots ==' ) ),
			rfrac_readme_screenshot_failures( self::screenshots( array( '1. A' ) ), array( '.wordpress-org/screenshot-1.png', '.wordpress-org/screenshot-2.png' ) )
		);
	}

	/**
	 * A file without a Screenshots section fails at line 1.
	 *
	 * @return void
	 */
	public function test_file_without_section_fails_at_line_one(): void {
		$this->assertSame(
			array( self::failure( 1, '.wordpress-org/screenshot-1.png has no caption in == Screenshots ==' ) ),
			rfrac_readme_screenshot_failures( self::readme( array( 'Description' => array( 'Text.' ) ) ), array( '.wordpress-org/screenshot-1.png' ) )
		);
	}

	/**
	 * Captions must run 1, 2, 3...: a gap fails at the caption that follows it.
	 *
	 * @return void
	 */
	public function test_captions_out_of_order_fail_at_the_caption(): void {
		$this->assertSame(
			array( self::failure( 8, 'Screenshot caption 3 is out of order (expected 2)' ) ),
			rfrac_readme_screenshot_failures(
				self::screenshots( array( '1. A', '3. B' ) ),
				array( '.wordpress-org/screenshot-1.png', '.wordpress-org/screenshot-3.png' )
			)
		);
	}

	/**
	 * Every caption is judged on its position in the list: after a repeated number each later caption is off by one.
	 *
	 * @return void
	 */
	public function test_every_misplaced_caption_fails(): void {
		$failures = rfrac_readme_screenshot_failures(
			self::screenshots( array( '1. A', '1. B', '2. C', '3. D' ) ),
			array( '.wordpress-org/screenshot-1.png', '.wordpress-org/screenshot-2.png', '.wordpress-org/screenshot-3.png' )
		);

		$this->assertSame(
			array(
				self::failure( 8, 'Screenshot caption 1 is out of order (expected 2)' ),
				self::failure( 9, 'Screenshot caption 2 is out of order (expected 3)' ),
				self::failure( 10, 'Screenshot caption 3 is out of order (expected 4)' ),
			),
			$failures
		);
	}

	/**
	 * A file that is not named screenshot-<n>.png (n without a leading zero, a png) fails at line 1.
	 *
	 * @return void
	 */
	public function test_misnamed_file_fails_at_line_one(): void {
		foreach ( array( 'screenshot-01.png', 'screenshot-1.jpg', 'screenshot-0.png', 'screenshot-.png', 'screenshot-1.png.bak', 'screenshot-1.PNG', 'sub/screenshot-1.png' ) as $name ) {
			$this->assertSame(
				array( self::failure( 1, ".wordpress-org/{$name} is not named screenshot-<n>.png" ) ),
				rfrac_readme_screenshot_failures( self::readme( array( 'Description' => array( 'Text.' ) ) ), array( ".wordpress-org/{$name}" ) ),
				$name
			);
		}
	}

	/**
	 * A misnamed file does not stand in for a caption's file, and the captions are still checked.
	 *
	 * @return void
	 */
	public function test_misnamed_file_does_not_satisfy_a_caption(): void {
		$this->assertSame(
			array(
				self::failure( 7, 'Screenshot caption 1 has no .wordpress-org/screenshot-1.png' ),
				self::failure( 1, '.wordpress-org/screenshot-01.png is not named screenshot-<n>.png' ),
			),
			rfrac_readme_screenshot_failures( self::screenshots( array( '1. A' ) ), array( '.wordpress-org/screenshot-01.png' ) )
		);
	}

	/**
	 * A body line that is not "<n>. <text>" fails at that line; with no real caption the section fails at its heading.
	 *
	 * @return void
	 */
	public function test_line_that_is_not_a_caption_fails_at_the_line(): void {
		$this->assertSame(
			array(
				self::failure( 6, "Section 'Screenshots' has no captions" ),
				self::failure( 7, 'Screenshots line is not a numbered caption "<n>. <text>"' ),
			),
			rfrac_readme_screenshot_failures( self::screenshots( array( '* A' ) ), array() )
		);
	}

	/**
	 * Only "<n>. <text>" is a caption: no leading zero, a dot and a space, and a text.
	 *
	 * @return void
	 */
	public function test_caption_syntax_is_strict(): void {
		foreach ( array( '1 A', '1.A', '1) A', '01. A', '0. A', '1. ', ' 1. A', '- 1. A', 'A', '= 1. A =' ) as $body ) {
			$this->assertSame(
				array( self::failure( 7, 'Screenshots line is not a numbered caption "<n>. <text>"' ) ),
				rfrac_readme_screenshot_failures( self::screenshots( array( $body, '1. B' ) ), array( '.wordpress-org/screenshot-1.png' ) ),
				$body
			);
		}
	}

	/**
	 * A line that is not a caption does not take a place: the captions around it still run 1, 2.
	 *
	 * @return void
	 */
	public function test_a_line_that_is_not_a_caption_takes_no_number(): void {
		$files = array( '.wordpress-org/screenshot-1.png', '.wordpress-org/screenshot-2.png' );

		$this->assertSame(
			array( self::failure( 8, 'Screenshots line is not a numbered caption "<n>. <text>"' ) ),
			rfrac_readme_screenshot_failures( self::screenshots( array( '1. A', '* B', '2. C' ) ), $files )
		);
	}

	/**
	 * A Screenshots section without any line fails at its heading, with or without files.
	 *
	 * @return void
	 */
	public function test_empty_section_fails_at_the_heading(): void {
		$no_captions = self::failure( 6, "Section 'Screenshots' has no captions" );

		$this->assertSame( array( $no_captions ), rfrac_readme_screenshot_failures( self::screenshots( array() ), array() ) );
		$this->assertSame( array( $no_captions ), rfrac_readme_screenshot_failures( self::screenshots( array( '', '  ' ) ), array() ) );
		$this->assertSame(
			array(
				$no_captions,
				self::failure( 6, '.wordpress-org/screenshot-1.png has no caption in == Screenshots ==' ),
			),
			rfrac_readme_screenshot_failures( self::screenshots( array() ), array( '.wordpress-org/screenshot-1.png' ) )
		);
	}

	/**
	 * The failures come in readme order, then in file order, each list under the first.
	 *
	 * @return void
	 */
	public function test_failures_come_in_readme_order_then_file_order(): void {
		$lines = self::readme(
			array(
				'Description' => array( 'Text.' ),
				'Screenshots' => array( '* A', '2. B', '3. C' ),
			)
		);
		$files = array(
			'.wordpress-org/screenshot-9.png',
			'.wordpress-org/screenshot-x.png',
			'.wordpress-org/screenshot-3.png',
			'.wordpress-org/screenshot-1.png',
		);

		$this->assertSame(
			array(
				self::failure( 10, 'Screenshots line is not a numbered caption "<n>. <text>"' ),
				self::failure( 11, 'Screenshot caption 2 is out of order (expected 1)' ),
				self::failure( 11, 'Screenshot caption 2 has no .wordpress-org/screenshot-2.png' ),
				self::failure( 12, 'Screenshot caption 3 is out of order (expected 2)' ),
				self::failure( 9, '.wordpress-org/screenshot-9.png has no caption in == Screenshots ==' ),
				self::failure( 1, '.wordpress-org/screenshot-x.png is not named screenshot-<n>.png' ),
				self::failure( 9, '.wordpress-org/screenshot-1.png has no caption in == Screenshots ==' ),
			),
			rfrac_readme_screenshot_failures( $lines, $files )
		);
	}

	/**
	 * The file picker keeps the screenshot files in file order, numbered, and null when misnamed.
	 *
	 * @return void
	 */
	public function test_screenshot_files_are_numbered_in_file_order(): void {
		$files = array(
			'readme.txt',
			'.wordpress-org/screenshot-2.png',
			'.wordpress-org/icon-256x256.png',
			'.wordpress-org/screenshot-01.png',
			'assets/screenshot-3.png',
			'.wordpress-org/screenshot-10.png',
		);

		$this->assertSame(
			array(
				'screenshot-2.png'  => 2,
				'screenshot-01.png' => null,
				'screenshot-10.png' => 10,
			),
			rfrac_readme_screenshot_files( $files )
		);
		$this->assertSame( array(), rfrac_readme_screenshot_files( array() ) );
	}

	/**
	 * Captions and files that disagree: the script exits 1 and names each disagreement at the readme line it
	 * belongs to.
	 *
	 * @return array<string,array{0:string[],1:string[],2:string,3:string}> Captions, files below
	 *         .wordpress-org/, the readme line the failure is reported at, and the reason.
	 */
	public static function disagreements(): array {
		return array(
			'a caption without its file' => array(
				array( '1. First', '2. Second' ),
				array( 'screenshot-1.png' ),
				'2. Second',
				'Screenshot caption 2 has no .wordpress-org/screenshot-2.png',
			),
			'a file without its caption' => array(
				array( '1. First' ),
				array( 'screenshot-1.png', 'screenshot-2.png' ),
				'== Screenshots ==',
				'.wordpress-org/screenshot-2.png has no caption in == Screenshots ==',
			),
			'a file that is misnamed'    => array(
				array( '1. First' ),
				array( 'screenshot-1.png', 'screenshot-01.png' ),
				'=== Fixture Plugin ===',
				'.wordpress-org/screenshot-01.png is not named screenshot-<n>.png',
			),
		);
	}

	/**
	 * @dataProvider disagreements
	 *
	 * @param string[] $captions Captions of the Screenshots section.
	 * @param string[] $files    Tracked files below .wordpress-org/.
	 * @param string   $at       The readme line the failure is reported at.
	 * @param string   $reason   The failure reason.
	 * @return void
	 */
	public function test_script_fails_when_captions_and_files_disagree( array $captions, array $files, string $at, string $reason ): void {
		$root = $this->make_plugin( $captions, $files );

		$result = $this->run_process( array( PHP_BINARY, $root . '/bin/check-readme.php' ), $root );

		$this->assertSame( 1, $result['status'], $result['stdout'] . $result['stderr'] );
		$this->assertSame( 'readme.txt:' . $this->line_of( $root, $at ) . ' ' . $reason . "\n", $result['stderr'] );
		$this->assertSame( '', $result['stdout'] );
	}

	/**
	 * A screenshot file that git does not track is no file of the plugin: the script does not read the folder.
	 *
	 * @return void
	 */
	public function test_script_ignores_a_screenshot_file_that_git_does_not_track(): void {
		$root = $this->make_plugin( array( '1. First', '2. Second' ), array( 'screenshot-1.png' ) );
		file_put_contents( $root . '/.wordpress-org/screenshot-2.png', "PNG\n" );

		$result = $this->run_process( array( PHP_BINARY, $root . '/bin/check-readme.php' ), $root );

		$this->assertSame( 1, $result['status'], $result['stdout'] . $result['stderr'] );
		$this->assertSame(
			'readme.txt:' . $this->line_of( $root, '2. Second' ) . " Screenshot caption 2 has no .wordpress-org/screenshot-2.png\n",
			$result['stderr']
		);
	}

	/**
	 * When git lists the shipped files but then cannot list .wordpress-org/, the script fails with that message
	 * instead of passing on an empty listing.
	 *
	 * The first listing of rfrac_shipped_files() exits on a git that does not work at all, so the fixture's copy of
	 * rfrac_git_files() returns null from its second call on, as a git that stops working half way through would.
	 *
	 * @return void
	 */
	public function test_script_fails_when_git_cannot_list_the_screenshot_files(): void {
		$root = $this->make_plugin( array( '1. First', '2. Second' ), array( 'screenshot-1.png', 'screenshot-2.png' ) );

		$lib      = $root . '/bin/lib/distignore.php';
		$head     = 'function rfrac_git_files( $root ) {';
		$original = (string) file_get_contents( $lib );
		$this->assertSame( 1, substr_count( $original, $head ) );
		file_put_contents( $lib, str_replace( $head, $head . "\n\tstatic \$calls = 0;\n\tif ( ++\$calls > 1 ) {\n\t\treturn null;\n\t}\n", $original ) );

		$result = $this->run_process( array( PHP_BINARY, $root . '/bin/check-readme.php' ), $root );

		$this->assertSame( 1, $result['status'], $result['stdout'] . $result['stderr'] );
		$this->assertStringContainsString( "readme.txt:1 Cannot list .wordpress-org/ (git ls-files failed)\n", $result['stderr'] );
		$this->assertSame( '', $result['stdout'] );
	}

	/**
	 * Captions and files that agree: the script exits 0 and prints the counts, which are those of the screenshot
	 * files only, not of the other assets of the folder.
	 *
	 * @return void
	 */
	public function test_script_passes_and_prints_the_counts_when_captions_and_files_agree(): void {
		$root = $this->make_plugin(
			array( '1. First', '2. Second' ),
			array( 'screenshot-1.png', 'screenshot-2.png', 'icon-256x256.png', 'banner-772x250.png' )
		);

		$result = $this->run_process( array( PHP_BINARY, $root . '/bin/check-readme.php' ), $root );

		$this->assertSame( 0, $result['status'], $result['stdout'] . $result['stderr'] );
		$this->assertSame( '', $result['stderr'] );
		$this->assertStringContainsString( "Screenshots: 2 captions, 2 files\n", $result['stdout'] );
		$this->assertStringEndsWith( "Readme checks passed.\n", $result['stdout'] );
	}

	/**
	 * The readme of the fixture plugin: every field and section the other checks of bin/check-readme.php want,
	 * and the given captions in the Screenshots section.
	 *
	 * @param string[] $captions Caption lines, e.g. "1. First".
	 * @return string
	 */
	private static function plugin_readme( array $captions ): string {
		$lines = array_merge(
			array(
				'=== Fixture Plugin ===',
				'Contributors: someone',
				'Tags: cache',
				'Requires at least: 6.6',
				'Tested up to: 6.8',
				'Requires PHP: 8.1',
				'Stable tag: 1.0.0',
				'License: GPLv2 or later',
				'License URI: https://www.gnu.org/licenses/gpl-2.0.html',
				'',
				'A short description of the fixture plugin.',
				'',
				'== Description ==',
				'',
				'Text.',
				'',
				'== Installation ==',
				'',
				'1. Upload the plugin.',
				'',
				'== Frequently Asked Questions ==',
				'',
				'= Is it a question? =',
				'',
				'Yes.',
				'',
				'== Screenshots ==',
				'',
			),
			$captions,
			array(
				'',
				'== Changelog ==',
				'',
				'= 1.0.0 =',
				'* First release.',
				'',
				'== Upgrade Notice ==',
				'',
				'= 1.0.0 =',
				'First release.',
				'',
			)
		);

		return implode( "\n", $lines );
	}

	/**
	 * Builds a throwaway git checkout of a tiny plugin that passes every check of bin/check-readme.php but the
	 * screenshot check, with its own copy of the script, which takes the plugin root from its own location.
	 *
	 * @param string[] $captions Caption lines of the Screenshots section, e.g. "1. First".
	 * @param string[] $files    File names below .wordpress-org/ to write and track.
	 * @return string The plugin root.
	 */
	private function make_plugin( array $captions, array $files ): string {
		if ( ! self::git_is_available() ) {
			$this->markTestSkipped( 'git is not on the PATH.' );
		}

		$this->base = rtrim( str_replace( '\\', '/', sys_get_temp_dir() ), '/' ) . '/rfrac-' . bin2hex( random_bytes( 4 ) );
		$root       = $this->base . '/plugin';
		foreach ( array( 'bin/lib', 'includes/cli', 'includes/request', 'mu-plugin', '.wordpress-org' ) as $dir ) {
			mkdir( $root . '/' . $dir, 0777, true );
		}

		file_put_contents( $root . '/rockfire-rest-api-cache.php', "<?php\n/**\n * Plugin Name: Fixture Plugin\n * Version: 1.0.0\n */\n" );
		file_put_contents( $root . '/readme.txt', self::plugin_readme( $captions ) );
		file_put_contents( $root . '/.distignore', "/bin\n/.wordpress-org\n" );
		// Files the script reads whatever the readme says; the fixture readme names no hook, command or placeholder.
		foreach ( array( 'includes/class-hooks.php', 'includes/cli/class-command.php', 'includes/cli/class-config-command.php', 'includes/request/class-bypass-reason.php', 'mu-plugin/rfrac-early-cache.php' ) as $stub ) {
			file_put_contents( $root . '/' . $stub, "<?php\n" );
		}
		foreach ( array( 'check-readme.php', 'lib/distignore.php', 'lib/readme.php' ) as $script ) {
			copy( dirname( __DIR__ ) . '/bin/' . $script, $root . '/bin/' . $script );
		}
		foreach ( $files as $file ) {
			file_put_contents( $root . '/.wordpress-org/' . $file, "PNG\n" );
		}

		$this->assertSame( 0, $this->run_process( array( 'git', 'init', '-q' ), $root )['status'] );
		$this->assertSame( 0, $this->run_process( array( 'git', 'add', '--', '.' ), $root )['status'] );

		return $root;
	}

	/**
	 * The line number of a line of the fixture readme.
	 *
	 * @param string $root Plugin root.
	 * @param string $text The whole line.
	 * @return int
	 */
	private function line_of( string $root, string $text ): int {
		$lines = file( $root . '/readme.txt', FILE_IGNORE_NEW_LINES );
		$this->assertIsArray( $lines );

		$index = array_search( $text, $lines, true );
		$this->assertIsInt( $index, "{$text} is not a line of the fixture readme." );

		return $index + 1;
	}

	/**
	 * Whether the git binary runs.
	 *
	 * @return bool
	 */
	private static function git_is_available(): bool {
		$process = @proc_open( array( 'git', '--version' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $process ) ) {
			return false;
		}

		stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return 0 === proc_close( $process );
	}

	/**
	 * Runs a command without a shell.
	 *
	 * @param string[] $command Program and arguments.
	 * @param string   $cwd     Working directory, or the current one when empty.
	 * @return array{status:int,stdout:string,stderr:string}
	 */
	private function run_process( array $command, string $cwd = '' ): array {
		$process = proc_open(
			$command,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			'' === $cwd ? null : $cwd
		);
		if ( ! is_resource( $process ) ) {
			return array(
				'status' => -1,
				'stdout' => '',
				'stderr' => '',
			);
		}

		fclose( $pipes[0] );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array(
			'status' => proc_close( $process ),
			'stdout' => $stdout,
			'stderr' => $stderr,
		);
	}

	/**
	 * Deletes a directory tree, read-only files included.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function remove_tree( string $dir ): void {
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() );
			} else {
				chmod( $item->getPathname(), 0666 );
				unlink( $item->getPathname() );
			}
		}
		rmdir( $dir );
	}
}
