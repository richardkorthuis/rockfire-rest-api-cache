<?php
/**
 * Tests for the dev-only screenshot script scripts/screenshots.mjs.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/bin/lib/distignore.php';

/**
 * Reads scripts/screenshots.mjs, the git file list and the CI workflow, and syntax-checks the script with node.
 *
 * Plain PHPUnit, without WordPress and without running the script: it needs a local site and a browser.
 */
class Test_Screenshot_Script extends TestCase {

	/**
	 * Absolute path to the plugin root.
	 *
	 * @var string
	 */
	private string $root = '';

	/**
	 * Contents of scripts/screenshots.mjs.
	 *
	 * @var string
	 */
	private string $script = '';

	/**
	 * Loads the script.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->root = rtrim( str_replace( '\\', '/', dirname( __DIR__ ) ), '/' );
		$path       = $this->root . '/scripts/screenshots.mjs';
		$this->assertFileExists( $path );
		$this->script = (string) file_get_contents( $path );
		$this->assertNotSame( '', $this->script );
	}

	/**
	 * AC-1: the admin pages the script visits, in order, are caches, routes, routes with edit, settings, tools.
	 *
	 * @return void
	 */
	public function test_pages_are_captured_in_caption_order(): void {
		$count = preg_match_all( '~admin\.php\?page=(rfrac-[a-z]+)(&action=edit)?~', $this->script, $matches, PREG_SET_ORDER );
		$this->assertSame( 5, $count );

		$found = array();
		foreach ( $matches as $match ) {
			$found[] = $match[1] . ( isset( $match[2] ) ? $match[2] : '' );
		}

		$this->assertSame(
			array(
				'rfrac-caches',
				'rfrac-routes',
				'rfrac-routes&action=edit',
				'rfrac-settings',
				'rfrac-tools',
			),
			$found
		);
	}

	/**
	 * AC-2: the site address and the credentials come from the environment, never from the script.
	 *
	 * @return void
	 */
	public function test_site_and_credentials_come_from_the_environment(): void {
		$this->assertStringContainsString( 'process.env.RFRAC_SHOT_URL', $this->script );
		$this->assertStringContainsString( 'process.env.RFRAC_SHOT_USER', $this->script );
		$this->assertStringContainsString( 'process.env.RFRAC_SHOT_PASSWORD', $this->script );
		$this->assertSame( 0, preg_match( '~https?://~', $this->script ), 'The script holds a URL literal.' );
		$this->assertStringNotContainsStringIgnoringCase( 'password:', $this->script );
	}

	/**
	 * AC-3: the script writes to the folder kept out of the zip, derives the route id like the plugin and pins Playwright.
	 *
	 * @return void
	 */
	public function test_script_holds_the_fixed_names(): void {
		$this->assertStringContainsString( '.wordpress-org', $this->script );
		$this->assertStringContainsString( 'screenshot-', $this->script );
		$this->assertStringContainsString( "createHash('md5')", $this->script );
		$this->assertStringContainsString( '.slice(0, 12)', $this->script );
		$this->assertStringContainsString( 'playwright@1.55.0', $this->script );
		$this->assertStringContainsString( '/wp/v2/posts', $this->script );
	}

	/**
	 * AC-4: no Node package manifest, lockfile or node_modules is tracked.
	 *
	 * @return void
	 */
	public function test_no_node_tooling_is_tracked(): void {
		$files = rfrac_git_files( $this->root );
		if ( null === $files ) {
			$this->markTestSkipped( 'The plugin root is not a git checkout or git is not on the PATH.' );
		}

		$tooling = array();
		foreach ( $files as $file ) {
			foreach ( explode( '/', $file ) as $segment ) {
				if ( in_array( $segment, array( 'package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'node_modules' ), true ) ) {
					$tooling[] = $file;
					break;
				}
			}
		}

		$this->assertSame( array(), $tooling );
	}

	/**
	 * AC-5: CI neither runs the script nor installs Playwright.
	 *
	 * @return void
	 */
	public function test_ci_workflow_does_not_run_the_script(): void {
		$workflow = $this->root . '/.github/workflows/ci.yml';
		if ( ! is_file( $workflow ) ) {
			$this->markTestSkipped( 'The CI workflow is not part of this checkout.' );
		}

		$contents = (string) file_get_contents( $workflow );
		$this->assertNotSame( '', $contents );
		$this->assertStringNotContainsString( 'screenshots.mjs', $contents );
		$this->assertStringNotContainsStringIgnoringCase( 'playwright', $contents );
	}

	/**
	 * AC-6: the script is valid ES module syntax for node.
	 *
	 * @return void
	 */
	public function test_script_passes_node_check(): void {
		if ( ! self::node_is_available() ) {
			$this->markTestSkipped( 'node is not on the PATH.' );
		}

		$process = proc_open(
			array( 'node', '--check', $this->root . '/scripts/screenshots.mjs' ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $process );

		fclose( $pipes[0] );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$this->assertSame( 0, proc_close( $process ), $stdout . $stderr );
	}

	/**
	 * AC-7: the admin page is checked right after it loads, before the screenshot is written.
	 *
	 * @return void
	 */
	public function test_admin_page_is_checked_before_the_screenshot(): void {
		$loop = strpos( $this->script, 'SHOTS.entries()' );
		$this->assertNotFalse( $loop );

		$body  = substr( $this->script, (int) $loop );
		$check = strpos( $body, 'assertAdminPage(' );
		$shot  = strpos( $body, 'page.screenshot(' );

		$this->assertNotFalse( $check, 'The capture loop does not check the admin page.' );
		$this->assertNotFalse( $shot );
		$this->assertLessThan( $shot, $check );
	}

	/**
	 * AC-7: an error page, a non-2xx status, no response or a redirect (to login or to another admin page) fails with
	 * exit code 1 naming the URL, and a loaded admin page passes.
	 *
	 * @return void
	 */
	public function test_admin_page_check_rejects_error_and_login_pages(): void {
		if ( ! self::node_is_available() ) {
			$this->markTestSkipped( 'node is not on the PATH.' );
		}

		$requested = 'https://example.test/wp-admin/admin.php?page=rfrac-routes&action=edit&route_id=abc123';
		$loaded    = array(
			'requested'       => $requested,
			'finalUrl'        => $requested,
			'status'          => 200,
			'hasAdminContent' => true,
			'isErrorPage'     => false,
		);

		$cases = array(
			'loaded'         => $loaded,
			'extra query'    => array_merge( $loaded, array( 'finalUrl' => $requested . '&_wpnonce=1' ) ),
			'not found'      => array_merge( $loaded, array( 'status' => 404 ) ),
			'forbidden'      => array_merge(
				$loaded,
				array(
					'status'      => 403,
					'isErrorPage' => true,
				)
			),
			'no response'    => array_merge( $loaded, array( 'status' => null ) ),
			'login redirect' => array_merge( $loaded, array( 'finalUrl' => 'https://example.test/wp-login.php?redirect_to=x&reauth=1' ) ),
			'other page'     => array_merge( $loaded, array( 'finalUrl' => 'https://example.test/wp-admin/admin.php?page=rfrac-routes' ) ),
			'die page'       => array_merge( $loaded, array( 'isErrorPage' => true ) ),
			'no admin area'  => array_merge( $loaded, array( 'hasAdminContent' => false ) ),
		);

		$results = $this->run_admin_page_checks( $cases );

		$this->assertSame( array_keys( $cases ), array_keys( $results ) );
		$this->assertTrue( $results['loaded']['ok'], 'A loaded admin page was rejected.' );
		$this->assertTrue( $results['extra query']['ok'], 'A page with extra query arguments was rejected.' );

		foreach ( array_diff( array_keys( $cases ), array( 'loaded', 'extra query' ) ) as $name ) {
			$this->assertFalse( $results[ $name ]['ok'], $name . ' was accepted.' );
			$this->assertSame( 1, $results[ $name ]['exitCode'], $name );
			$this->assertStringContainsString( $requested, $results[ $name ]['message'], $name );
		}
	}

	/**
	 * Running the script directly still starts it: without its environment it ends with exit code 2 and says why.
	 *
	 * @return void
	 */
	public function test_script_still_runs_when_executed_directly(): void {
		if ( ! self::node_is_available() ) {
			$this->markTestSkipped( 'node is not on the PATH.' );
		}

		$environment = getenv();
		foreach ( array_keys( $environment ) as $name ) {
			if ( 0 === strpos( (string) $name, 'RFRAC_SHOT_' ) ) {
				unset( $environment[ $name ] );
			}
		}

		$process = proc_open(
			array( 'node', $this->root . '/scripts/screenshots.mjs' ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			$environment
		);
		$this->assertIsResource( $process );

		fclose( $pipes[0] );
		stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$this->assertSame( 2, proc_close( $process ), $stderr );
		$this->assertStringContainsString( 'missing environment variable RFRAC_SHOT_URL', $stderr );
	}

	/**
	 * Calls assertAdminPage() of the script, imported by a node child process, once per case.
	 *
	 * @param array<string, array<string, mixed>> $cases The arguments of assertAdminPage(), by case name.
	 * @return array<string, array{ok: bool, exitCode: int|null, message: string}> The outcome per case.
	 */
	private function run_admin_page_checks( array $cases ): array {
		$runner = tempnam( sys_get_temp_dir(), 'rfrac-shot-' );
		$this->assertIsString( $runner );
		$runner_file = $runner . '.mjs';
		rename( $runner, $runner_file );

		file_put_contents(
			$runner_file,
			<<<'JS'
import { pathToFileURL } from 'node:url';

const mod = await import(pathToFileURL(process.argv[2]).href);
let input = '';
for await (const chunk of process.stdin) {
	input += chunk;
}

const results = {};
for (const [name, args] of Object.entries(JSON.parse(input))) {
	try {
		mod.assertAdminPage(args);
		results[name] = { ok: true, exitCode: null, message: '' };
	} catch (error) {
		results[name] = { ok: false, exitCode: error.exitCode ?? null, message: String(error.message) };
	}
}
process.stdout.write(JSON.stringify(results));
JS
		);

		$process = proc_open(
			array( 'node', $runner_file, $this->root . '/scripts/screenshots.mjs' ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $process );

		fwrite( $pipes[0], (string) wp_json_encode( $cases ) );
		fclose( $pipes[0] );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $process );
		unlink( $runner_file );

		$this->assertSame( 0, $exit, $stdout . $stderr );
		$results = json_decode( $stdout, true );
		$this->assertIsArray( $results, $stdout . $stderr );

		return $results;
	}

	/**
	 * Whether the node binary runs.
	 *
	 * @return bool
	 */
	private static function node_is_available(): bool {
		$process = @proc_open( array( 'node', '--version' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $process ) ) {
			return false;
		}

		stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return 0 === proc_close( $process );
	}
}
