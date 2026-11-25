<?php
/**
 * Multisite tests for the must-use installer.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Install\Mu_Installer;
use Rockfire\Rest_Api_Cache\Install\Mu_Status;
use Rockfire\Rest_Api_Cache\Names;

/**
 * @covers \Rockfire\Rest_Api_Cache\Install\Mu_Installer
 * @group multisite
 */
class Test_Mu_Installer_Multisite extends Rfrac_Test_Case {

	private const BASENAME = 'rockfire-rest-api-cache/rockfire-rest-api-cache.php';

	private string $dir = '';

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run via `composer test-multisite`.' );
		}
		parent::set_up();
		$this->dir = str_replace( '\\', '/', rtrim( sys_get_temp_dir(), '/\\' ) ) . '/rfrac-mu-ms-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir, 0755, true );
	}

	public function tear_down() {
		if ( '' !== $this->dir && is_dir( $this->dir ) ) {
			foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
				unlink( $file );
			}
			rmdir( $this->dir );
		}
		delete_site_option( 'active_sitewide_plugins' );
		delete_option( Mu_Installer::OPTION_NOTICE );
		parent::tear_down();
	}

	private function installer(): Mu_Installer {
		$installer = new Mu_Installer( self::BASENAME, dirname( __DIR__, 2 ) . '/mu-plugin/' . Names::MU_FILE, '1.0.0', $this->dir );
		$installer->install();
		return $installer;
	}

	private function activate_on( int $blog_id ): void {
		switch_to_blog( $blog_id );
		update_option( 'active_plugins', array( self::BASENAME ) );
		restore_current_blog();
	}

	public function test_remove_keeps_the_file_when_active_on_another_site(): void {
		$installer = $this->installer();
		$site2     = self::factory()->blog->create();
		$this->activate_on( $site2 );

		$this->assertSame( get_main_site_id(), get_current_blog_id() );
		$this->assertFalse( $installer->remove() );
		$this->assertFileExists( $installer->target_path() );
	}

	public function test_remove_deletes_the_file_when_no_site_has_it_active(): void {
		$installer = $this->installer();
		self::factory()->blog->create();

		$this->assertTrue( $installer->remove() );
		$this->assertFileDoesNotExist( $installer->target_path() );
	}

	public function test_remove_ignores_the_current_site(): void {
		$installer = $this->installer();
		self::factory()->blog->create();
		update_option( 'active_plugins', array( self::BASENAME ) );

		$this->assertTrue( $installer->remove() );
	}

	public function test_remove_keeps_the_file_when_network_active(): void {
		$installer = $this->installer();
		update_site_option( 'active_sitewide_plugins', array( self::BASENAME => time() ) );

		$this->assertFalse( $installer->remove() );
		$this->assertFileExists( $installer->target_path() );
		$this->assertTrue( $installer->remove( false, true ), 'A network-wide deactivation ignores the network entry.' );
	}

	public function test_force_removes_regardless_of_other_sites(): void {
		$installer = $this->installer();
		$this->activate_on( self::factory()->blog->create() );

		$this->assertTrue( $installer->remove( true ) );
		$this->assertFileDoesNotExist( $installer->target_path() );
	}

	public function test_installing_requires_network_plugin_capability(): void {
		// A site administrator may not manage network plugins.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertFalse( Mu_Installer::current_user_can_install() );

		wp_set_current_user( 0 );
		$this->assertFalse( Mu_Installer::current_user_can_install() );

		$super = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $super );
		wp_set_current_user( $super );
		$this->assertTrue( Mu_Installer::current_user_can_install() );
	}

	public function test_a_missing_file_stays_missing_after_an_update_of_the_plugin(): void {
		$installer = new Mu_Installer( self::BASENAME, dirname( __DIR__, 2 ) . '/mu-plugin/' . Names::MU_FILE, '1.0.0', $this->dir );
		$super     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $super );
		wp_set_current_user( $super );

		$installer->maybe_repair_after_upgrade( null, array( 'type' => 'plugin', 'plugins' => array( self::BASENAME ) ) );

		$this->assertSame( Mu_Status::MISSING, $installer->status() );
		$this->assertFileDoesNotExist( $installer->target_path() );
	}
}
