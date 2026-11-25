<?php
/**
 * Multisite tests for the settings-save recompile while another site is switched to.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Plugin
 * @group multisite
 */
class Test_Switched_Recompile extends Rfrac_Test_Case {

	/**
	 * Fresh container whose engine hooks are registered for this test.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * The created site that gets switched to.
	 *
	 * @var int
	 */
	private int $site = 0;

	/**
	 * The REST server of the current site.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run via composer test-multisite.' );
		}
		parent::set_up();
		$this->site   = (int) self::factory()->blog->create();
		$this->server = $this->new_rest_server();
		$this->plugin = new Plugin();
		$this->plugin->register_engine_hooks();
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

	/**
	 * The compiled config of a site.
	 *
	 * @param int $site_id Site ID.
	 *
	 * @return Compiled_Config|null
	 */
	private function config_of( int $site_id ): ?Compiled_Config {
		return $this->on_site( $site_id, static fn() => Compiled_Config::load() );
	}

	/**
	 * Compile the current server's routes into a site's `rfrac_config`.
	 *
	 * @param int $site_id Site ID.
	 *
	 * @return Compiled_Config The snapshot, current and with routes.
	 */
	private function compile_site( int $site_id ): Compiled_Config {
		$this->on_site( $site_id, fn() => $this->plugin->compiler()->compile( $this->server ) );
		$config = $this->config_of( $site_id );
		$this->assertNotNull( $config, 'Precondition: the site has a snapshot.' );
		$this->assertNotSame( '', $config->fingerprint(), 'Precondition: the snapshot is current.' );
		$this->assertNotEmpty( $config->routes(), 'Precondition: the snapshot has routes.' );
		return $config;
	}

	public function test_a_settings_write_while_switched_marks_that_site_stale(): void {
		$before = $this->compile_site( $this->site );
		$fired  = 0;
		$count  = static function () use ( &$fired ): void {
			++$fired;
		};
		add_action( Hooks::DISCOVERY_SERVER, $count );

		switch_to_blog( $this->site );
		try {
			$was_switched = ms_is_switched();
			$result       = Config::instance()->save_settings( array( 'default_ttl' => 600 ) );
		} finally {
			restore_current_blog();
		}

		$this->assertTrue( $was_switched, 'Precondition: the write happened while switched.' );
		$this->assertTrue( $result->is_valid() );
		$this->assertSame( 0, $fired, 'The origin site\'s REST server was not built for the switched site.' );

		$after = $this->config_of( $this->site );
		$this->assertNotNull( $after );
		$this->assertSame( '', $after->fingerprint(), 'The switched site is marked stale.' );
		$this->assertFalse( $after->pending_discovery(), 'It keeps serving its routes.' );
		$this->assertEquals( $before->routes(), $after->routes(), 'Its routes are unchanged.' );
		$this->assertSame( $before->default_ttl(), $after->default_ttl(), 'The old settings keep serving until its next request.' );
		$this->assertSame( 600, $this->on_site( $this->site, static fn() => Config::instance()->settings()['default_ttl'] ), 'The setting is saved on the switched site.' );
	}

	public function test_the_marked_site_is_rebuilt_with_the_saved_settings_by_its_next_request(): void {
		$this->compile_site( $this->site );
		$this->on_site( $this->site, static fn() => Config::instance()->save_settings( array( 'default_ttl' => 600 ) ) );

		$rebuilt = $this->on_site( $this->site, fn() => $this->plugin->compiler()->maybe_recompile_for_server( $this->server ) );

		$this->assertTrue( $rebuilt );
		$config = $this->config_of( $this->site );
		$this->assertNotNull( $config );
		$this->assertSame( 600, $config->default_ttl() );
		$this->assertNotSame( '', $config->fingerprint() );
	}

	public function test_a_settings_write_while_switched_does_not_touch_the_origin_site(): void {
		$this->compile_site( get_current_blog_id() );
		$origin = get_option( Names::OPTION_CONFIG );

		$this->on_site( $this->site, static fn() => Config::instance()->save_settings( array( 'default_ttl' => 600 ) ) );

		$this->assertSame( $origin, get_option( Names::OPTION_CONFIG ), 'The origin site\'s snapshot is untouched.' );
		$this->assertNotSame( 600, Config::instance()->settings()['default_ttl'], 'The origin site\'s settings are untouched.' );
	}

	public function test_a_switched_site_without_a_snapshot_stays_without_one(): void {
		$this->on_site( $this->site, static fn() => delete_option( Names::OPTION_CONFIG ) );

		$this->on_site( $this->site, static fn() => Config::instance()->save_settings( array( 'default_ttl' => 600 ) ) );

		$this->assertNull( $this->config_of( $this->site ) );
	}

	public function test_a_settings_write_on_the_current_site_still_compiles(): void {
		$result = Config::instance()->save_settings( array( 'default_ttl' => 1234 ) );

		$this->assertTrue( $result->is_valid() );
		$this->assertFalse( ms_is_switched() );
		$config = Compiled_Config::load();
		$this->assertNotNull( $config, 'The save compiled.' );
		$this->assertSame( 1234, $config->default_ttl() );
		$this->assertNotSame( '', $config->fingerprint() );

		switch_to_blog( $this->site );
		restore_current_blog();
		Config::instance()->save_settings( array( 'default_ttl' => 2345 ) );
		$this->assertSame( 2345, Compiled_Config::load()->default_ttl(), 'After restore_current_blog() a save compiles again.' );
	}
}
