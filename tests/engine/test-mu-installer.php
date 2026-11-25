<?php
/**
 * Tests for the must-use installer and its template.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Install\Mu_Installer;
use Rockfire\Rest_Api_Cache\Install\Mu_Status;
use Rockfire\Rest_Api_Cache\Names;

/**
 * @covers \Rockfire\Rest_Api_Cache\Install\Mu_Installer
 * @covers \Rockfire\Rest_Api_Cache\Install\Mu_Status
 */
class Test_Mu_Installer extends Rfrac_Test_Case {

	private const BASENAME = 'rockfire-rest-api-cache/rockfire-rest-api-cache.php';

	/**
	 * Temp directories created by the test.
	 *
	 * @var string[]
	 */
	private array $dirs = array();

	public function tear_down() {
		foreach ( $this->dirs as $dir ) {
			$this->remove_dir( $dir );
		}
		$this->dirs = array();
		delete_option( Mu_Installer::OPTION_NOTICE );
		delete_option( 'active_plugins' );
		parent::tear_down();
	}

	private function template(): string {
		return dirname( __DIR__, 2 ) . '/mu-plugin/' . Names::MU_FILE;
	}

	private function temp_dir( bool $create = true ): string {
		$dir          = rtrim( sys_get_temp_dir(), '/\\' ) . '/rfrac-mu-' . bin2hex( random_bytes( 4 ) );
		$this->dirs[] = $dir;
		if ( $create ) {
			mkdir( $dir, 0755, true );
		}
		return str_replace( '\\', '/', $dir );
	}

	private function installer( string $dir, ?string $template = null ): Mu_Installer {
		return new Mu_Installer( self::BASENAME, $template ?? $this->template(), '9.8.7', $dir );
	}

	private function remove_dir( string $dir ): void {
		if ( ! file_exists( $dir ) ) {
			return;
		}
		if ( is_dir( $dir ) ) {
			@chmod( $dir, 0755 );
			foreach ( (array) scandir( $dir ) as $entry ) {
				if ( '.' !== $entry && '..' !== $entry ) {
					$this->remove_dir( $dir . '/' . $entry );
				}
			}
			rmdir( $dir );
			return;
		}
		unlink( $dir );
	}

	private function leftovers( string $dir ): array {
		return glob( $dir . '/*.tmp-*' ) ?: array();
	}

	// ---------- Template ----------

	public function test_template_version_equals_template_header(): void {
		$this->assertSame( 1, Mu_Installer::TEMPLATE_VERSION );
		$this->assertSame( 1, preg_match( '/^\s*\*\s*RFRAC Template:\s*(\d+)\s*$/m', (string) file_get_contents( $this->template() ), $m ) );
		$this->assertSame( Mu_Installer::TEMPLATE_VERSION, (int) $m[1] );
		$this->assertStringContainsString( 'Early_Server::boot( ' . Mu_Installer::TEMPLATE_VERSION . ' )', (string) file_get_contents( $this->template() ) );
	}

	public function test_template_does_not_reference_main_file_constants(): void {
		$source = (string) file_get_contents( $this->template() );
		$this->assertDoesNotMatchRegularExpression( '/RFRAC_(VERSION|FILE|PATH|DIR|URL|BASENAME)\b/', $source );
		$this->assertStringContainsString( 'Plugin Name:       Rockfire – REST API Cache (early cache)', $source );
	}

	public function test_desired_content_replaces_placeholders_and_is_valid_php(): void {
		$installer = $this->installer( $this->temp_dir() );
		$content   = $installer->desired_content();

		$this->assertIsString( $content );
		$this->assertStringNotContainsString( '{{', $content );
		$this->assertStringContainsString( 'Version:           9.8.7', $content );
		$this->assertStringContainsString( "\$rfrac_basename = '" . self::BASENAME . "';", $content );
		$this->assertIsArray( token_get_all( $content, TOKEN_PARSE ) );
	}

	public function test_basename_is_escaped_via_var_export(): void {
		$installer = new Mu_Installer( "evil/it's.php", $this->template(), "1.0\n*/ evil", $this->temp_dir() );
		$content   = (string) $installer->desired_content();

		$this->assertStringContainsString( "\$rfrac_basename = 'evil/it\\'s.php';", $content );
		$this->assertStringContainsString( 'Version:           1.0  evil', $content );
		$this->assertIsArray( token_get_all( $content, TOKEN_PARSE ) );
	}

	public function test_desired_content_is_null_when_template_is_missing(): void {
		$dir       = $this->temp_dir();
		$installer = $this->installer( $dir, $dir . '/nope.php' );

		$this->assertNull( $installer->desired_content() );
		$this->assertSame( Mu_Status::MISSING, $installer->install() );
		$this->assertSame( 'template_unreadable', $installer->last_result()['code'] );
	}

	public function test_paths(): void {
		$dir       = $this->temp_dir();
		$installer = $this->installer( $dir );

		$this->assertSame( $dir . '/rfrac-early-cache.php', $installer->target_path() );
		$this->assertSame( $this->template(), $installer->template_path() );
	}

	// ---------- Install ----------

	public function test_install_writes_the_file_atomically(): void {
		$dir       = $this->temp_dir();
		$installer = $this->installer( $dir );
		$this->assertSame( Mu_Status::MISSING, $installer->status() );
		$this->assertSame( 'fallback', $installer->mode() );

		$status = $installer->install();

		$this->assertSame( Mu_Status::CURRENT, $status );
		$this->assertSame( Mu_Status::CURRENT, $installer->status() );
		$this->assertSame( 'early', $installer->mode() );
		$this->assertFileExists( $installer->target_path() );
		$this->assertSame( $installer->desired_content(), file_get_contents( $installer->target_path() ) );
		$this->assertSame( array(), $this->leftovers( $dir ) );
		$this->assertSame( array( 'rfrac-early-cache.php' ), array_values( array_diff( scandir( $dir ), array( '.', '..' ) ) ) );
	}

	public function test_install_creates_a_missing_directory(): void {
		$dir       = $this->temp_dir( false ) . '/nested/mu-plugins';
		$installer = $this->installer( $dir );

		$this->assertSame( Mu_Status::CURRENT, $installer->install() );
	}

	// ---------- The install blocker ----------

	public function test_install_blocker_is_null_where_an_install_can_go_ahead_and_changes_nothing(): void {
		$dir       = $this->temp_dir();
		$installer = $this->installer( $dir );
		$this->assertNull( $installer->install_blocker() );
		$this->assertSame( array(), array_values( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) ), 'Nothing is written.' );
		$this->assertNull( get_option( Mu_Installer::OPTION_NOTICE, null ), 'Nothing is recorded.' );

		$missing = $this->installer( $this->temp_dir( false ) . '/nested/mu-plugins' );
		$this->assertNull( $missing->install_blocker(), 'A directory that can be created.' );
		$this->assertDirectoryDoesNotExist( dirname( $missing->target_path() ), 'The probe creates no directory.' );
		$this->assertNull( get_option( Mu_Installer::OPTION_NOTICE, null ) );
	}

	/**
	 * @dataProvider install_blocker_causes
	 */
	public function test_install_blocker_names_the_precondition_that_refuses_and_install_refuses_with_it( string $code ): void {
		$base      = $this->temp_dir();
		$installer = $this->installer( $base );
		switch ( $code ) {
			case 'file_mods_disallowed':
				add_filter( 'file_mod_allowed', '__return_false' );
				break;
			case 'template_unreadable':
				$installer = $this->installer( $base, $base . '/nope.php' );
				break;
			case 'not_writable':
				file_put_contents( $base . '/blocker', 'x' );
				$installer = $this->installer( $base . '/blocker/mu-plugins' );
				break;
			case 'not_direct':
				add_filter( 'filesystem_method', static fn() => 'ftpext' );
				break;
		}

		$this->assertSame( $code, $installer->install_blocker() );

		$installer->install();
		$this->assertSame( $code, $installer->last_result()['code'] );
		$this->assertFileDoesNotExist( $installer->target_path() );
		$this->assertSame( array(), $this->leftovers( dirname( $installer->target_path() ) ) );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function install_blocker_causes(): array {
		return array(
			'file changes disallowed' => array( 'file_mods_disallowed' ),
			'template unreadable'     => array( 'template_unreadable' ),
			'not writable'            => array( 'not_writable' ),
			'not direct'              => array( 'not_direct' ),
		);
	}

	public function test_install_blocker_checks_in_the_order_install_refuses(): void {
		$base      = $this->temp_dir();
		$installer = $this->installer( $base . '/nope', $base . '/nope.php' );
		add_filter( 'file_mod_allowed', '__return_false' );
		add_filter( 'filesystem_method', static fn() => 'ftpext' );
		$this->assertSame( 'file_mods_disallowed', $installer->install_blocker() );

		remove_all_filters( 'file_mod_allowed' );
		$this->assertSame( 'template_unreadable', $installer->install_blocker() );

		$installer = $this->installer( $base );
		$this->assertSame( 'not_direct', $installer->install_blocker() );
	}

	public function test_install_records_a_non_autoloaded_notice(): void {
		$installer = $this->installer( $this->temp_dir() );
		$installer->install();

		$notice = get_option( Mu_Installer::OPTION_NOTICE );
		$this->assertSame( 'current', $notice['status'] );
		$this->assertSame( 'installed', $notice['code'] );
		$this->assertIsInt( $notice['time'] );
		$this->assertSame( $notice, $installer->last_result() );
		$this->assertArrayNotHasKey( Mu_Installer::OPTION_NOTICE, wp_load_alloptions( true ) );
		$this->assertSame( $notice, $this->installer( $this->temp_dir() )->last_result(), 'A new instance reads the stored result.' );
	}

	public function test_install_overwrites_outdated_content_and_never_outputs(): void {
		$dir       = $this->temp_dir();
		$installer = $this->installer( $dir );
		file_put_contents( $installer->target_path(), "<?php // old\n" );
		$this->assertSame( Mu_Status::OUTDATED, $installer->status() );
		$this->assertSame( 'fallback', $installer->mode() );

		ob_start();
		$status = $installer->install();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertSame( Mu_Status::CURRENT, $status );
		$this->assertSame( array(), $this->leftovers( $dir ) );
	}

	public function test_install_in_an_unwritable_location_reports_not_writable(): void {
		$base = $this->temp_dir();
		file_put_contents( $base . '/blocker', 'x' );
		$installer = $this->installer( $base . '/blocker/mu-plugins' );

		ob_start();
		$status = $installer->install();
		$this->assertSame( '', ob_get_clean() );

		$this->assertSame( Mu_Status::MISSING, $status );
		$this->assertSame( 'not_writable', $installer->last_result()['code'] );
		$this->assertSame( array(), $this->leftovers( $base ) );
	}

	public function test_install_in_a_read_only_directory_reports_not_writable(): void {
		if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			$this->markTestSkipped( 'Directory permissions are not enforced the same way on Windows.' );
		}
		$dir = $this->temp_dir();
		chmod( $dir, 0555 );
		if ( is_writable( $dir ) ) {
			chmod( $dir, 0755 );
			$this->markTestSkipped( 'Running with privileges that ignore directory permissions.' );
		}
		$installer = $this->installer( $dir );

		$status = $installer->install();

		chmod( $dir, 0755 );
		$this->assertSame( Mu_Status::MISSING, $status );
		$this->assertSame( 'not_writable', $installer->last_result()['code'] );
		$this->assertSame( array(), $this->leftovers( $dir ) );
		$this->assertFileDoesNotExist( $installer->target_path() );
	}

	public function test_failed_rename_removes_the_temp_file(): void {
		$dir       = $this->temp_dir();
		$installer = $this->installer( $dir );
		mkdir( $installer->target_path() ); // A directory in the way: rename() cannot replace it.

		$status = $installer->install();

		$this->assertSame( Mu_Status::MISSING, $status );
		$this->assertSame( 'rename_failed', $installer->last_result()['code'] );
		$this->assertSame( array(), $this->leftovers( $dir ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disabled_constant_blocks_install(): void {
		define( 'RFRAC_DISABLE_MU_INSTALL', true );
		$dir       = $this->temp_dir();
		$installer = $this->installer( $dir );

		$this->assertSame( Mu_Status::DISABLED, $installer->status() );
		$this->assertSame( 'disabled', $installer->install_blocker() );
		$this->assertSame( Mu_Status::DISABLED, $installer->install() );
		$this->assertSame( 'disabled', $installer->last_result()['code'] );
		$this->assertFileDoesNotExist( $installer->target_path() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( Mu_Status::DISABLED, $installer->ensure_installed() );
		$this->assertFileDoesNotExist( $installer->target_path() );
		$this->assertSame( 'fallback', $installer->mode() );
		$this->remove_dir( $dir );
	}

	// ---------- Refresh after an update ----------

	/**
	 * Write an outdated copy built from the template: the desired content with another plugin version.
	 *
	 * @param Mu_Installer $installer Installer.
	 *
	 * @return string The written content.
	 */
	private function write_outdated_template_copy( Mu_Installer $installer ): string {
		$old = str_replace( 'Version:           9.8.7', 'Version:           0.0.1', (string) $installer->desired_content() );
		file_put_contents( $installer->target_path(), $old );
		clearstatcache( true, $installer->target_path() );
		return $old;
	}

	public function test_after_upgrade_leaves_a_missing_file_missing(): void {
		$installer = $this->installer( $this->temp_dir() );

		$installer->maybe_repair_after_upgrade( null, array( 'type' => 'plugin', 'plugins' => array( self::BASENAME ) ) );
		$this->assertSame( Mu_Status::MISSING, $installer->status() );
		$this->assertFileDoesNotExist( $installer->target_path() );

		$installer->maybe_repair_after_upgrade( null, array( 'type' => 'plugin', 'action' => 'update', 'plugin' => self::BASENAME ) );
		$this->assertSame( Mu_Status::MISSING, $installer->status() );
		$this->assertNull( get_option( Mu_Installer::OPTION_NOTICE, null ), 'No install was attempted.' );
	}

	public function test_after_upgrade_refreshes_an_outdated_template_copy(): void {
		$installer = $this->installer( $this->temp_dir() );
		$this->write_outdated_template_copy( $installer );
		$this->assertSame( Mu_Status::OUTDATED, $installer->status() );

		$installer->maybe_repair_after_upgrade( null, array( 'type' => 'plugin', 'plugins' => array( 'other/other.php', self::BASENAME ) ) );

		$this->assertSame( Mu_Status::CURRENT, $installer->status() );
		$this->assertSame( $installer->desired_content(), file_get_contents( $installer->target_path() ) );
	}

	public function test_after_upgrade_accepts_the_single_plugin_shape_of_automatic_updates(): void {
		$installer = $this->installer( $this->temp_dir() );
		$old       = $this->write_outdated_template_copy( $installer );

		$installer->maybe_repair_after_upgrade( null, array( 'type' => 'plugin', 'plugin' => 'other/other.php' ) );
		$this->assertSame( $old, file_get_contents( $installer->target_path() ) );

		$installer->maybe_repair_after_upgrade( null, array( 'type' => 'plugin', 'action' => 'update', 'plugin' => self::BASENAME ) );
		$this->assertSame( Mu_Status::CURRENT, $installer->status() );
	}

	public function test_after_upgrade_does_nothing_for_another_plugin_or_a_theme(): void {
		$installer = $this->installer( $this->temp_dir() );
		$old       = $this->write_outdated_template_copy( $installer );

		$installer->maybe_repair_after_upgrade( null, array( 'type' => 'plugin', 'plugins' => array( 'other/other.php' ) ) );
		$installer->maybe_repair_after_upgrade( null, array( 'type' => 'theme', 'plugins' => array( self::BASENAME ) ) );
		$installer->maybe_repair_after_upgrade( null, array( 'type' => 'theme', 'themes' => array( 'twentytwentyfive' ) ) );
		$installer->maybe_repair_after_upgrade( null, array( 'type' => 'plugin' ) );

		$this->assertSame( Mu_Status::OUTDATED, $installer->status() );
		$this->assertSame( $old, file_get_contents( $installer->target_path() ) );
	}

	public function test_after_upgrade_leaves_an_outdated_file_without_a_template_header_alone(): void {
		$installer = $this->installer( $this->temp_dir() );
		file_put_contents( $installer->target_path(), "<?php // changed\n" );

		$installer->maybe_repair_after_upgrade( null, array( 'type' => 'plugin', 'plugins' => array( self::BASENAME ) ) );

		$this->assertSame( Mu_Status::OUTDATED, $installer->status() );
		$this->assertSame( "<?php // changed\n", file_get_contents( $installer->target_path() ) );
		$this->assertNull( get_option( Mu_Installer::OPTION_NOTICE, null ), 'No install was attempted.' );
	}

	public function test_after_upgrade_keeps_the_content_and_mtime_of_a_current_file(): void {
		$installer = $this->installer( $this->temp_dir() );
		$installer->install();
		delete_option( Mu_Installer::OPTION_NOTICE );
		$past = time() - 3600;
		touch( $installer->target_path(), $past );
		clearstatcache( true, $installer->target_path() );
		$content = file_get_contents( $installer->target_path() );

		$installer->maybe_repair_after_upgrade( null, array( 'type' => 'plugin', 'plugins' => array( self::BASENAME ) ) );

		clearstatcache( true, $installer->target_path() );
		$this->assertSame( Mu_Status::CURRENT, $installer->status() );
		$this->assertSame( $content, file_get_contents( $installer->target_path() ) );
		$this->assertSame( $past, filemtime( $installer->target_path() ) );
		$this->assertNull( get_option( Mu_Installer::OPTION_NOTICE, null ), 'No install was attempted.' );
	}

	public function test_install_without_the_direct_filesystem_method_reports_not_direct(): void {
		$installer = $this->installer( $this->temp_dir() );
		add_filter( 'filesystem_method', static fn() => 'ftpext' );

		ob_start();
		$status = $installer->install();
		$this->assertSame( '', ob_get_clean(), 'No credentials form.' );

		$this->assertSame( Mu_Status::MISSING, $status );
		$this->assertSame( 'not_direct', $installer->last_result()['code'] );
		$this->assertFileDoesNotExist( $installer->target_path() );
	}

	public function test_ensure_installed_with_an_unreadable_template_keeps_a_template_file(): void {
		$dir    = $this->temp_dir();
		$target = $this->installer( $dir )->target_path();
		file_put_contents( $target, "<?php\n/**\n * RFRAC Template: 0\n */\n" );
		$installer = $this->installer( $dir, $dir . '/nope.php' );

		$this->assertSame( Mu_Status::OUTDATED, $installer->ensure_installed() );
		$this->assertSame( 'template_unreadable', $installer->last_result()['code'] );
		$this->assertFileExists( $target );
	}

	/**
	 * A legacy loader (no template header) in a directory the installer cannot write to.
	 *
	 * @return Mu_Installer
	 */
	private function legacy_loader_that_cannot_be_replaced(): Mu_Installer {
		$installer = $this->installer( $this->temp_dir() );
		file_put_contents( $installer->target_path(), "<?php\n/**\n * Plugin Name: REST API Cache - Must-Use Plugin\n */\n" );
		add_filter( 'filesystem_method', static fn() => 'ftpext' );
		return $installer;
	}

	public function test_ensure_installed_deletes_a_legacy_loader_it_cannot_replace(): void {
		$installer = $this->legacy_loader_that_cannot_be_replaced();

		$this->assertSame( Mu_Status::MISSING, $installer->ensure_installed() );
		$this->assertFileDoesNotExist( $installer->target_path() );
	}

	public function test_after_upgrade_leaves_a_legacy_loader_alone(): void {
		$installer = $this->installer( $this->temp_dir() );
		$legacy    = "<?php\n/**\n * Plugin Name: REST API Cache - Must-Use Plugin\n */\n";
		file_put_contents( $installer->target_path(), $legacy );

		$installer->maybe_repair_after_upgrade( null, array( 'type' => 'plugin', 'plugin' => self::BASENAME ) );

		$this->assertSame( $legacy, file_get_contents( $installer->target_path() ), 'Only the install button replaces or deletes a legacy loader.' );
	}

	// ---------- Remove ----------

	public function test_remove_deletes_the_file(): void {
		$installer = $this->installer( $this->temp_dir() );
		$installer->install();

		$this->assertTrue( $installer->remove() );
		$this->assertFileDoesNotExist( $installer->target_path() );
		$this->assertTrue( $installer->remove(), 'Removing an absent file succeeds.' );
		$this->assertTrue( $installer->remove( true ) );
	}

	// ---------- The generated file ----------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_generated_file_returns_quietly_when_the_plugin_dir_does_not_exist(): void {
		$dir       = $this->temp_dir();
		$installer = new Mu_Installer( 'rfrac-nonexistent/rfrac-nonexistent.php', $this->template(), '1.0.0', $dir );
		$installer->install();
		update_option( 'active_plugins', array( 'rfrac-nonexistent/rfrac-nonexistent.php' ) );

		ob_start();
		$result = include $installer->target_path();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertNotFalse( $result );
		$this->assertTrue( defined( 'RFRAC_EARLY_LOADED' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_generated_file_ignores_an_inactive_plugin_and_a_second_include(): void {
		$dir       = $this->temp_dir();
		$installer = new Mu_Installer( 'rfrac-nonexistent/rfrac-nonexistent.php', $this->template(), '1.0.0', $dir );
		$installer->install();

		ob_start();
		include $installer->target_path();
		include $installer->target_path(); // Guarded by RFRAC_EARLY_LOADED.
		$this->assertSame( '', ob_get_clean() );
		$this->assertSame( 1, RFRAC_EARLY_LOADED );
	}
}
