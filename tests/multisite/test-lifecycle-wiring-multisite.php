<?php
/**
 * Multisite tests for the activation and site lifecycle wiring of the plugin container (#26).
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Install\Activator;
use Rockfire\Rest_Api_Cache\Install\Deactivator;
use Rockfire\Rest_Api_Cache\Install\Mu_Status;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Plugin
 * @group multisite
 */
class Test_Lifecycle_Wiring_Multisite extends Rfrac_Test_Case {

	/**
	 * Fresh container whose engine hooks are registered for this test.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * The current site plus two created sites.
	 *
	 * @var int[]
	 */
	private array $sites = array();

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run via composer test-multisite.' );
		}
		parent::set_up();
		// Sites are created before the container listens, so they start without tables.
		$this->sites   = array( get_current_blog_id() );
		$this->sites[] = (int) self::factory()->blog->create();
		$this->sites[] = (int) self::factory()->blog->create();
		$this->plugin  = new Plugin();
		$this->plugin->register_engine_hooks();
	}

	public function tear_down() {
		$mu = $this->plugin->mu_installer()->target_path();
		if ( file_exists( $mu ) ) {
			unlink( $mu );
		}
		parent::tear_down();
	}

	/**
	 * Whether a table (real or temporary) is queryable.
	 *
	 * @param string $table Full table name.
	 *
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$result   = $wpdb->query( "SELECT 1 FROM `{$table}` LIMIT 0" ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( $suppress );
		return false !== $result;
	}

	/**
	 * Whether a site has both plugin tables.
	 *
	 * @param int $site_id Site ID.
	 *
	 * @return bool
	 */
	private function has_table_pair( int $site_id ): bool {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( $site_id );
		return $this->table_exists( $prefix . Names::TABLE_ENTRIES ) && $this->table_exists( $prefix . Names::TABLE_TAGS );
	}

	/**
	 * Run a callback on a site.
	 *
	 * @param int      $site_id  Site ID.
	 * @param callable $callback Callback.
	 *
	 * @return mixed
	 */
	private function on_site( int $site_id, callable $callback ) {
		switch_to_blog( $site_id );
		try {
			return $callback();
		} finally {
			restore_current_blog();
		}
	}

	private function config_of( int $site_id ): ?Compiled_Config {
		return $this->on_site( $site_id, static fn() => Compiled_Config::load() );
	}

	private function db_version_of( int $site_id ): int {
		return (int) $this->on_site( $site_id, static fn() => get_option( Names::OPTION_DB_VERSION, 0 ) );
	}

	public function test_created_sites_start_without_tables(): void {
		$this->assertFalse( $this->has_table_pair( $this->sites[1] ) );
		$this->assertFalse( $this->has_table_pair( $this->sites[2] ) );
	}

	public function test_network_activation_creates_a_table_pair_per_site(): void {
		Activator::activate( true );

		foreach ( $this->sites as $site_id ) {
			$this->assertTrue( $this->has_table_pair( $site_id ), "Site $site_id has its tables." );
			$this->assertSame( 1, $this->db_version_of( $site_id ) );
			$config = $this->config_of( $site_id );
			$this->assertNotNull( $config, "Site $site_id has a compiled config." );
			$this->assertTrue( $config->pending_discovery() );
		}
	}

	public function test_network_activation_sets_up_only_the_first_batch_eagerly_and_the_rest_on_init(): void {
		add_filter( Hooks::NETWORK_ACTIVATION_BATCH, static fn() => 1 );
		$first = (int) get_sites(
			array(
				'fields' => 'ids',
				'number' => 1,
			)
		)[0];
		$lazy  = array_values( array_diff( $this->sites, array( $first ) ) );

		Activator::activate( true );

		foreach ( $lazy as $site_id ) {
			$this->assertSame( 0, $this->db_version_of( $site_id ), "Site $site_id is left for init." );
			$this->assertFalse( $this->has_table_pair( $site_id ) );
		}

		$init = array_values( array_filter( $this->plugin->engine_hooks(), static fn( array $h ): bool => 'init' === $h['hook'] ) );
		$this->assertCount( 1, $init );
		foreach ( $lazy as $site_id ) {
			$this->on_site( $site_id, $init[0]['callback'] );
			$this->assertTrue( $this->has_table_pair( $site_id ), "Site $site_id migrated on init." );
			$this->assertSame( 1, $this->db_version_of( $site_id ) );
			$this->assertTrue( $this->config_of( $site_id )->pending_discovery() );
		}
	}

	public function test_site_created_after_network_activation_gets_tables_and_config(): void {
		update_site_option( 'active_sitewide_plugins', array( $this->plugin->basename() => time() ) );

		$site_id = (int) self::factory()->blog->create();

		$this->assertTrue( $this->has_table_pair( $site_id ) );
		$this->assertSame( 1, $this->db_version_of( $site_id ) );
		$this->assertTrue( $this->config_of( $site_id )->pending_discovery() );
	}

	public function test_site_created_while_not_network_active_gets_no_tables(): void {
		update_site_option( 'active_sitewide_plugins', array() );

		$site_id = (int) self::factory()->blog->create();

		$this->assertFalse( $this->has_table_pair( $site_id ) );
	}

	/**
	 * Log in as a new super admin (the only user who may manage network plugins).
	 *
	 * @return int User ID.
	 */
	private function as_super_admin(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	public function test_activation_by_a_site_administrator_writes_no_must_use_file(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Activator::activate( false );

		$this->assertFileDoesNotExist( $this->plugin->mu_installer()->target_path() );
		$this->assertTrue( $this->has_table_pair( get_current_blog_id() ), 'The site is still set up.' );
	}

	public function test_activation_by_a_site_administrator_leaves_an_existing_legacy_loader_untouched(): void {
		$target = $this->plugin->mu_installer()->target_path();
		$legacy = "<?php\n/**\n * Plugin Name: REST API Cache - Must-Use Plugin\n */\n";
		wp_mkdir_p( dirname( $target ) );
		file_put_contents( $target, $legacy );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Activator::activate( false );

		$this->assertFileExists( $target, 'The network-shared file is not deleted by a site administrator.' );
		$this->assertSame( $legacy, file_get_contents( $target ), 'Nor overwritten.' );
		$this->assertSame( Mu_Status::OUTDATED, $this->plugin->mu_installer()->status() );
		$this->assertTrue( $this->has_table_pair( get_current_blog_id() ), 'The site is still set up.' );

		$this->as_super_admin();
		$this->plugin->status_service()->install_mu();

		$this->assertNotSame( $legacy, file_get_contents( $target ), 'The install button of a network administrator replaces the legacy loader.' );
		$this->assertSame( Mu_Status::CURRENT, $this->plugin->mu_installer()->status() );
	}

	public function test_network_activation_without_a_user_leaves_the_must_use_file_to_the_install_button(): void {
		wp_set_current_user( 0 );

		Activator::activate( true );

		$this->assertFileDoesNotExist( $this->plugin->mu_installer()->target_path() );

		$this->as_super_admin();
		$this->plugin->status_service()->install_mu();

		$this->assertFileExists( $this->plugin->mu_installer()->target_path() );
		$this->assertSame( Mu_Status::CURRENT, $this->plugin->mu_installer()->status() );
	}

	public function test_the_activated_hook_writes_a_missing_file_only_for_a_super_admin(): void {
		$installer = $this->plugin->mu_installer();
		$this->assertSame( Mu_Status::MISSING, $installer->status() );

		wp_set_current_user( 0 );
		do_action( Hooks::ACTIVATED );
		$this->assertSame( Mu_Status::MISSING, $installer->status(), 'User 0 (WP-CLI) leaves it missing.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( Hooks::ACTIVATED );
		$this->assertSame( Mu_Status::MISSING, $installer->status(), 'A site administrator leaves it missing.' );

		$this->as_super_admin();
		do_action( Hooks::ACTIVATED );
		$this->assertSame( Mu_Status::CURRENT, $installer->status() );
	}

	public function test_network_activation_by_a_super_admin_writes_the_must_use_file(): void {
		$this->as_super_admin();

		Activator::activate( true );

		$this->assertFileExists( $this->plugin->mu_installer()->target_path() );
	}

	public function test_site_activation_by_a_super_admin_writes_the_must_use_file(): void {
		$this->as_super_admin();

		Activator::activate( false );

		$this->assertFileExists( $this->plugin->mu_installer()->target_path() );
	}

	public function test_network_deactivation_flushes_every_site_and_removes_the_must_use_file(): void {
		$this->as_super_admin();
		Activator::activate( true );
		$this->assertFileExists( $this->plugin->mu_installer()->target_path() );
		$ids = array();
		foreach ( $this->sites as $site_id ) {
			$ids[ $site_id ] = (int) $this->on_site( $site_id, static fn() => Rfrac_Entry_Fixtures::insert_entry() );
		}
		update_site_option( 'active_sitewide_plugins', array( $this->plugin->basename() => time() ) );

		Deactivator::deactivate( true );

		foreach ( $ids as $site_id => $id ) {
			$this->assertSame( '2', $this->on_site( $site_id, static fn() => Rfrac_Entry_Fixtures::get_state( $id ) ), "Site $site_id flushed." );
		}
		$this->assertFileDoesNotExist( $this->plugin->mu_installer()->target_path(), 'The network-active entry is ignored on a network-wide deactivation.' );
	}

	public function test_site_deactivation_keeps_the_must_use_file_while_active_elsewhere(): void {
		$this->as_super_admin();
		Activator::activate( false );
		$mu = $this->plugin->mu_installer()->target_path();
		$this->assertFileExists( $mu );
		$this->on_site( $this->sites[1], static fn() => update_option( 'active_plugins', array( Plugin::instance()->basename() ) ) );

		Deactivator::deactivate( false );

		$this->assertFileExists( $mu );
	}

	public function test_store_is_built_per_site(): void {
		$this->set_compiled_config( array( 'store_mode' => 'database' ) );
		$this->on_site( $this->sites[1], fn() => $this->set_compiled_config( array( 'store_mode' => 'object_cache' ) ) );

		$main  = $this->plugin->store();
		$other = $this->on_site( $this->sites[1], fn() => $this->plugin->store() );

		$this->assertInstanceOf( \Rockfire\Rest_Api_Cache\Storage\Database_Store::class, $main );
		$this->assertInstanceOf( \Rockfire\Rest_Api_Cache\Storage\Object_Cache_Store::class, $other, 'switch_to_blog() gets the store of that site.' );
		$this->assertSame( $main, $this->plugin->store(), 'Memoised per site.' );
		$this->assertSame( $other, $this->on_site( $this->sites[1], fn() => $this->plugin->store() ) );
	}
}
