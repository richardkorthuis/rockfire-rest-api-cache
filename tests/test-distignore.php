<?php
/**
 * Tests for the git-based file listing of bin/lib/distignore.php.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/bin/lib/distignore.php';

/**
 * Covers `rfrac_git_files()`, `rfrac_forbidden_paths()` and `rfrac_shipped_files()` in bin/lib/distignore.php.
 *
 * Plain PHPUnit, without WordPress: every test builds a throwaway git repository under the system temp directory.
 */
class Test_Distignore extends TestCase {

	/**
	 * Throwaway directory that holds every fixture of one test.
	 *
	 * @var string
	 */
	private string $base = '';

	/**
	 * Previous value of GIT_CEILING_DIRECTORIES, restored after the test.
	 *
	 * @var string|false
	 */
	private $ceiling = false;

	/**
	 * Skips the test without git and creates the fixture directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! self::git_is_available() ) {
			$this->markTestSkipped( 'git is not on the PATH.' );
		}

		$this->base = rtrim( str_replace( '\\', '/', sys_get_temp_dir() ), '/' ) . '/rfrac-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->base, 0777, true );

		// Git must not discover a repository above the fixtures.
		$this->ceiling = getenv( 'GIT_CEILING_DIRECTORIES' );
		putenv( 'GIT_CEILING_DIRECTORIES=' . $this->base );
	}

	/**
	 * Removes the fixture directory and restores the environment.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( false === $this->ceiling ) {
			putenv( 'GIT_CEILING_DIRECTORIES' );
		} else {
			putenv( 'GIT_CEILING_DIRECTORIES=' . $this->ceiling );
		}
		if ( '' !== $this->base && is_dir( $this->base ) ) {
			$this->remove_tree( $this->base );
		}

		parent::tearDown();
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
	 * Writes fixture files, creating their directories.
	 *
	 * @param string   $dir   Directory.
	 * @param string[] $paths Relative paths.
	 * @return void
	 */
	private function write_files( string $dir, array $paths ): void {
		foreach ( $paths as $path ) {
			if ( ! is_dir( dirname( $dir . '/' . $path ) ) ) {
				mkdir( dirname( $dir . '/' . $path ), 0777, true );
			}
			file_put_contents( $dir . '/' . $path, "<?php\n" );
		}
	}

	/**
	 * Creates a git repository that tracks some of its files.
	 *
	 * @param string   $name      Directory name under the fixture directory.
	 * @param string[] $tracked   Relative paths to write and track.
	 * @param string[] $untracked Relative paths to write and leave untracked.
	 * @return string The repository root.
	 */
	private function make_repo( string $name, array $tracked, array $untracked = array() ): string {
		$dir = $this->base . '/' . $name;
		mkdir( $dir, 0777, true );
		$this->write_files( $dir, array_merge( $tracked, $untracked ) );

		$this->assertSame( 0, $this->run_process( array( 'git', 'init', '-q' ), $dir )['status'] );
		$this->assertSame( 0, $this->run_process( array_merge( array( 'git', 'add', '--' ), $tracked ), $dir )['status'] );

		return $dir;
	}

	/**
	 * Runs rfrac_shipped_files() in a child process, because it exits on failure.
	 *
	 * @param string $root Plugin root.
	 * @return array{status:int,stdout:string,stderr:string}
	 */
	private function shipped_in_child( string $root ): array {
		$runner = $this->base . '/run.php';
		file_put_contents(
			$runner,
			"<?php\nrequire \$argv[1];\necho implode( \"\\n\", rfrac_shipped_files( \$argv[2] ) ), \"\\n\";\n"
		);

		return $this->run_process( array( PHP_BINARY, $runner, dirname( __DIR__ ) . '/bin/lib/distignore.php', $root ) );
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

	/**
	 * Reads the meaningful lines of a file at the repository root: trimmed, without blank lines and comments.
	 *
	 * @param string $name File name at the repository root.
	 * @return string[]
	 */
	private function repository_lines( string $name ): array {
		$lines = file( dirname( __DIR__ ) . '/' . $name, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$this->assertIsArray( $lines, "{$name} cannot be read." );

		$kept = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line && '#' !== $line[0] ) {
				$kept[] = $line;
			}
		}

		return $kept;
	}

	public function test_git_files_returns_the_tracked_paths_only(): void {
		$root = $this->make_repo( 'repo', array( 'a.php', '.hidden/b.php', 'sub/c.md' ), array( 'c.php' ) );

		$files = rfrac_git_files( $root );

		$this->assertIsArray( $files );
		sort( $files, SORT_STRING );
		$this->assertSame( array( '.hidden/b.php', 'a.php', 'sub/c.md' ), $files );
	}

	public function test_forbidden_paths_flags_the_hidden_paths(): void {
		$root = $this->make_repo( 'repo', array( 'a.php', '.hidden/b.php', 'sub/.c/d.php' ), array( 'c.php' ) );

		$forbidden = rfrac_forbidden_paths( (array) rfrac_git_files( $root ) );

		ksort( $forbidden );
		$this->assertSame(
			array(
				'.hidden/b.php' => 'hidden path',
				'sub/.c/d.php'  => 'hidden path',
			),
			$forbidden
		);
	}

	public function test_git_files_returns_null_outside_a_git_checkout(): void {
		$dir = $this->base . '/plain';
		mkdir( $dir, 0777, true );

		$this->assertNull( rfrac_git_files( $dir ) );
	}

	/**
	 * Paths and the reason each is forbidden, or an empty reason when it may ship.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function paths(): array {
		return array(
			'a plain file'              => array( 'a.php', '' ),
			'a nested plain file'       => array( 'includes/class-a.php', '' ),
			'a dot inside a segment'    => array( 'my.dir/file.min.js', '' ),
			'a hidden root file'        => array( '.env', 'hidden path' ),
			'a hidden root directory'   => array( '.local/settings.json', 'hidden path' ),
			'a nested hidden directory' => array( 'includes/.cache/a.php', 'hidden path' ),
			'a nested hidden file'      => array( 'languages/.gitkeep', 'hidden path' ),
		);
	}

	/**
	 * @dataProvider paths
	 */
	public function test_forbidden_paths_reasons( string $path, string $reason ): void {
		$expected = '' === $reason ? array() : array( $path => $reason );

		$this->assertSame( $expected, rfrac_forbidden_paths( array( $path ) ) );
	}

	public function test_shipped_files_are_the_tracked_files_without_the_ignored_ones_sorted(): void {
		$root = $this->make_repo( 'repo', array( 'z.php', 'a.php', 'B.php', 'ignored/x.php', 'sub/ignored/y.php' ), array( 'u.php' ) );
		file_put_contents( $root . '/.distignore', "# Not shipped\n/ignored\n" );

		$result = $this->shipped_in_child( $root );

		$this->assertSame( 0, $result['status'], $result['stderr'] );
		$this->assertSame( "B.php\na.php\nsub/ignored/y.php\nz.php\n", $result['stdout'] );
	}

	public function test_shipped_files_skip_hidden_top_level_paths_without_a_distignore_line(): void {
		$root = $this->make_repo( 'repo', array( 'a.php', '.notes/b.md', '.env', 'sub/.c/d.php' ) );
		file_put_contents( $root . '/.distignore', "/ignored\n" );

		$result = $this->shipped_in_child( $root );

		$this->assertSame( 0, $result['status'], $result['stderr'] );
		$this->assertSame( "a.php\nsub/.c/d.php\n", $result['stdout'] );
	}

	public function test_shipped_files_fail_when_a_tracked_path_is_missing_on_disk(): void {
		$root = $this->make_repo( 'repo', array( 'a.php', 'gone.php', 'ignored/gone.php' ) );
		file_put_contents( $root . '/.distignore', "/ignored\n" );
		unlink( $root . '/gone.php' );
		unlink( $root . '/ignored/gone.php' );

		$result = $this->shipped_in_child( $root );

		$this->assertSame( 1, $result['status'] );
		$this->assertStringContainsString( "gone.php: tracked by git but missing on disk\n", $result['stderr'] );
		$this->assertStringNotContainsString( 'ignored/gone.php', $result['stderr'] );
	}

	public function test_distignore_and_gitattributes_exclude_the_same_paths(): void {
		// /.git never needs an export-ignore line: git archive never exports it.
		$distignore = array_values( array_diff( $this->repository_lines( '.distignore' ), array( '/.git' ) ) );

		$exported = array();
		foreach ( $this->repository_lines( '.gitattributes' ) as $line ) {
			if ( 1 === preg_match( '#^(\S+)\s+export-ignore$#', $line, $match ) ) {
				$exported[] = $match[1];
			}
		}

		$this->assertNotSame( array(), $distignore );
		$this->assertSame( array(), array_values( array_diff( $distignore, $exported ) ), '.distignore paths without an export-ignore line in .gitattributes.' );
		$this->assertSame( array(), array_values( array_diff( $exported, $distignore ) ), 'export-ignore paths in .gitattributes missing from .distignore.' );
	}

	public function test_the_real_distignore_keeps_the_store_assets_and_node_modules_out_of_the_zip(): void {
		$root = $this->make_repo( 'repo', array( '.wordpress-org/screenshot-1.png', 'node_modules/x/index.js', 'readme.txt' ) );
		copy( dirname( __DIR__ ) . '/.distignore', $root . '/.distignore' );

		$result = $this->shipped_in_child( $root );

		$this->assertSame( 0, $result['status'], $result['stderr'] );
		$this->assertSame( "readme.txt\n", $result['stdout'] );
		$shipped = array_values( array_filter( explode( "\n", $result['stdout'] ), 'strlen' ) );
		$this->assertSame( array(), rfrac_forbidden_paths( $shipped ) );
	}

	public function test_shipped_files_fail_outside_a_git_checkout(): void {
		$root = $this->base . '/plain';
		mkdir( $root, 0777, true );
		$this->write_files( $root, array( 'a.php' ) );
		file_put_contents( $root . '/.distignore', "/ignored\n" );

		$result = $this->shipped_in_child( $root );

		$this->assertSame( 1, $result['status'] );
		$this->assertSame( "The build needs a git checkout: git ls-files failed in {$root}.\n", $result['stderr'] );
		$this->assertSame( '', $result['stdout'] );
	}
}
