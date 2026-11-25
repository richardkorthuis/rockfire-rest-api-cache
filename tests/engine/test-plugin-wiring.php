<?php
/**
 * Tests for the plugin container and the engine hook wiring (#26).
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Admin;
use Rockfire\Rest_Api_Cache\Admin\Coexistence_Notice;
use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Config\Route_Discovery;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Install\Mu_Installer;
use Rockfire\Rest_Api_Cache\Install\Mu_Status;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module;
use Rockfire\Rest_Api_Cache\Invalidation\Rest_Tag_Collector;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Invalidation\Relevance;
use Rockfire\Rest_Api_Cache\Maintenance\Admin_Trigger;
use Rockfire\Rest_Api_Cache\Maintenance\Maintenance;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Cache_Policy;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Schema\Migrator;
use Rockfire\Rest_Api_Cache\Schema\Site_Lifecycle;
use Rockfire\Rest_Api_Cache\Serving\Capture;
use Rockfire\Rest_Api_Cache\Serving\Early_Server;
use Rockfire\Rest_Api_Cache\Serving\Fallback_Server;
use Rockfire\Rest_Api_Cache\Serving\Lookup;
use Rockfire\Rest_Api_Cache\Serving\Request_State;
use Rockfire\Rest_Api_Cache\Serving\Response_Writer;
use Rockfire\Rest_Api_Cache\Services\Cache_Service;
use Rockfire\Rest_Api_Cache\Services\Route_Service;
use Rockfire\Rest_Api_Cache\Services\Status_Service;
use Rockfire\Rest_Api_Cache\Storage\Database_Store;
use Rockfire\Rest_Api_Cache\Storage\Object_Cache_Store;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Plugin
 */
class Test_Plugin_Wiring extends Rfrac_Serving_Test_Case {

	/**
	 * Engine hooks of the table in #26 plus the invalidation (#59) and maintenance (#60) groups:
	 * hook => priority, or the list of priorities when several groups share a hook.
	 *
	 * @var array<string,int|list<int>>
	 */
	private const HOOK_TABLE = array(
		'plugins_loaded'                => array( 1, 10 ),
		'init'                          => 1,
		'rfrac/migrated'                => 10,
		'rfrac/site_initialized'        => 10,
		'rest_authentication_errors'    => 0,
		'rest_api_init'                 => PHP_INT_MAX,
		'registered_post_type'          => 10,
		'registered_taxonomy'           => 10,
		'rest_request_before_callbacks' => 10,
		'rest_exposed_cors_headers'     => PHP_INT_MAX,
		'rest_allowed_cors_headers'     => PHP_INT_MAX,
		'rest_post_dispatch'            => PHP_INT_MAX,
		'rest_pre_echo_response'        => PHP_INT_MAX,
		'add_option_rfrac_settings'     => 10,
		'update_option_rfrac_settings'  => 10,
		'activated_plugin'              => 10,
		'deactivated_plugin'            => 10,
		'switch_theme'                  => 10,
		'upgrader_process_complete'     => 10,
		'rfrac/admin_page_load'         => 10,
		'wp_initialize_site'            => array( 100, 1000 ),
		'wpmu_drop_tables'              => 10,
		'archive_blog'                  => 10,
		'make_spam_blog'                => 10,
		'deactivate_blog'               => 10,
		'wp_update_site'                => 10,
		'rfrac/config/store_changed'    => 10,
		'rfrac/activate_site'           => 10,
		'rfrac/activated'               => 10,
		'rfrac/deactivate_site'         => 10,
		'rfrac/deactivated'             => 10,
		// Invalidation.
		'shutdown'                      => 0,
		'wp_after_insert_post'          => 10,
		'transition_post_status'        => 10,
		'deleted_post'                  => 10,
		'add_attachment'                => 10,
		'edit_attachment'               => 10,
		'wp_media_attach_action'        => 10,
		'updated_option'                => 10,
		'added_option'                  => 10,
		'created_term'                  => 10,
		'edited_term'                   => 10,
		'delete_term'                   => 10,
		'added_term_relationship'       => 10,
		'set_object_terms'              => 10,
		'deleted_term_relationships'    => 10,
		'edit_terms'                    => 10,
		'edited_term_taxonomy'          => 10,
		'edited_term_taxonomies'        => 10,
		'profile_update'                => 10,
		'user_register'                 => 10,
		'deleted_user'                  => 10,
		'add_user_to_blog'              => 10,
		'remove_user_from_blog'         => 10,
		'wp_insert_comment'             => 10,
		'edit_comment'                  => 10,
		'transition_comment_status'     => 10,
		'added_post_meta'               => 10,
		'updated_post_meta'             => 10,
		'deleted_post_meta'             => 10,
		'added_term_meta'               => 10,
		'updated_term_meta'             => 10,
		'deleted_term_meta'             => 10,
		'added_user_meta'               => 10,
		'updated_user_meta'             => 10,
		'deleted_user_meta'             => 10,
		'added_comment_meta'            => 10,
		'updated_comment_meta'          => 10,
		'deleted_comment_meta'          => 10,
		// Maintenance.
		'rfrac_maintenance'             => 10,
		'rfrac_maintenance_continue'    => 10,
		// Site Health (tests: admin and cron requests; debug information: admin requests only).
		'site_status_tests'             => 10,
		'debug_information'             => 10,
	);

	/**
	 * Fresh container whose engine hooks are registered for this test.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	public function set_up() {
		parent::set_up();
		set_current_screen( 'dashboard' ); // The Site Health hooks are registered on admin requests (the tests also on cron).
		$this->plugin = new Plugin();
		$this->plugin->register_engine_hooks();
		wp_set_current_user( 0 );
		wp_cache_delete( 'count', 'rfrac' );
	}

	public function tear_down() {
		wp_set_current_user( 0 );
		wp_cache_delete( 'count', 'rfrac' );
		set_current_screen( 'front' );
		delete_option( Failure_Recorder::OPTION );
		delete_transient( Config_Compiler::CHECK_TRANSIENT );
		parent::tear_down();
	}

	/**
	 * Fire `plugins_loaded` through WordPress with only the callbacks of a fresh container on it.
	 *
	 * @param Plugin $plugin Container; its engine hooks are registered here.
	 *
	 * @return void
	 */
	private function do_plugins_loaded( Plugin $plugin ): void {
		global $wp_filter;
		$saved                       = $wp_filter['plugins_loaded'] ?? null;
		$wp_filter['plugins_loaded'] = new WP_Hook();
		try {
			$plugin->register_engine_hooks();
			do_action( 'plugins_loaded' );
		} finally {
			$wp_filter['plugins_loaded'] = $saved;
		}
	}

	/**
	 * A writer that records the hit and whether the request was ended.
	 *
	 * @param bool $terminated Set to true when terminate runs.
	 *
	 * @return Response_Writer
	 */
	private function recording_writer( bool &$terminated ): Response_Writer {
		return new Response_Writer(
			function ( string $line ): void {
				$this->headers[] = $line;
			},
			static function () use ( &$terminated ): never {
				$terminated = true;
				throw new Rfrac_Test_Exit( 'exit' );
			},
			function ( string $out ): void {
				$this->output .= $out;
			},
			static function ( int $code ): void {}
		);
	}

	/**
	 * Store an entry for `/wp/v2/posts` through the live wiring.
	 *
	 * @return string The stored body.
	 */
	private function store_posts(): string {
		self::factory()->post->create();
		$this->settle_before_request();
		$this->simulate( '/wp-json/wp/v2/posts' );
		$server = $this->serve( '/wp/v2/posts' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		Request_State::reset();
		return $server->sent_body;
	}

	/**
	 * Get the site ready for a request that is stored: commit what the test queued and compile the configuration with the routes of a live REST server.
	 *
	 * A request stores only when its lookup recorded a flush epoch, and no flush may commit after that. The configuration has
	 * to be compiled and enable the route for the lookup to record one (the first request of a site compiles it and stores
	 * nothing), and the invalidations queued by the content the test created (a published post) would otherwise commit
	 * while the request runs, on `rest_pre_echo_response`, which takes the store of the response with it.
	 *
	 * @return void
	 */
	private function settle_before_request(): void {
		$this->plugin->invalidator()->commit();
		$this->plugin->compiler()->compile( $this->new_rest_server() );
	}

	/**
	 * Start the next request of the same test: the request state is forgotten and Capture handles a main dispatch again, as in a new PHP process.
	 *
	 * @return void
	 */
	private function begin_next_request(): void {
		Request_State::reset();
		( new ReflectionProperty( Capture::class, 'handled' ) )->setValue( $this->plugin->capture(), false );
	}

	/**
	 * The callbacks this container registered on a hook.
	 *
	 * @param string $hook Hook name.
	 *
	 * @return list<array{hook:string,callback:\Closure,priority:int,args:int}>
	 */
	private function hooks_on( string $hook ): array {
		return array_values( array_filter( $this->plugin->engine_hooks(), static fn( array $h ): bool => $hook === $h['hook'] ) );
	}

	/**
	 * Run the callbacks this container registered on a hook, in priority order (like WordPress would).
	 *
	 * @param string $hook Hook name.
	 * @param mixed  ...$args Arguments.
	 *
	 * @return mixed The value returned by the last callback.
	 */
	private function fire( string $hook, ...$args ) {
		$hooks = $this->hooks_on( $hook );
		$this->assertNotEmpty( $hooks, "No engine callback on $hook" );
		usort( $hooks, static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );
		$result = null;
		foreach ( $hooks as $h ) {
			$result = ( $h['callback'] )( ...array_slice( $args, 0, $h['args'] ) );
		}
		return $result;
	}

	/**
	 * Serve a GET request through a spy REST server, like core does, with the engine hooks live.
	 *
	 * Capture stores only for a request whose lookup recorded a flush epoch, so a request that ran no lookup yet gets the
	 * one of a cacheable miss at the current epoch, as the fallback lookup would have recorded.
	 *
	 * @param string $route       Route.
	 * @param bool   $with_lookup Whether a request that ran no lookup yet gets the one of a cacheable miss; false serves it as it is.
	 *
	 * @return Spy_REST_Server
	 */
	private function serve( string $route, bool $with_lookup = true ): Spy_REST_Server {
		if ( $with_lookup ) {
			Rfrac_Serving_Harness::record_lookup_at_current_epoch();
		}
		$server                    = new Spy_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;
		do_action( 'rest_api_init', $server );
		// PHPUnit has already written output, so core's header() calls would raise warnings.
		set_error_handler(
			static fn( int $errno, string $errstr ): bool => false !== strpos( $errstr, 'headers already sent' ),
			E_WARNING
		);
		try {
			$server->serve_request( $route );
		} finally {
			restore_error_handler();
		}
		return $server;
	}

	// ---------- Container ----------

	public function test_getters_return_typed_services_built_once(): void {
		$p = $this->plugin;

		$this->assertSame( Config::instance(), $p->config() );
		$this->assertInstanceOf( Config_Compiler::class, $p->compiler() );
		$this->assertInstanceOf( Route_Discovery::class, $p->route_discovery() );
		$this->assertInstanceOf( Route_Service::class, $p->route_service() );
		$this->assertInstanceOf( Repository::class, $p->repository() );
		$this->assertInstanceOf( Cache_Policy::class, $p->policy() );
		$this->assertInstanceOf( Response_Writer::class, $p->writer() );
		$this->assertInstanceOf( Lookup::class, $p->lookup() );
		$this->assertInstanceOf( Fallback_Server::class, $p->fallback_server() );
		$this->assertInstanceOf( Capture::class, $p->capture() );
		$this->assertInstanceOf( Rest_Tag_Collector::class, $p->tag_provider() );
		$this->assertInstanceOf( Migrator::class, $p->migrator() );
		$this->assertInstanceOf( Site_Lifecycle::class, $p->site_lifecycle() );
		$this->assertInstanceOf( Mu_Installer::class, $p->mu_installer() );

		$this->assertInstanceOf( Invalidator::class, $p->invalidator() );
		$this->assertInstanceOf( Relevance::class, $p->relevance() );
		$this->assertInstanceOf( Maintenance::class, $p->maintenance() );
		$this->assertInstanceOf( Cache_Service::class, $p->cache_service() );

		foreach ( array( 'compiler', 'route_discovery', 'route_service', 'repository', 'store', 'policy', 'writer', 'lookup', 'fallback_server', 'capture', 'tag_provider', 'migrator', 'site_lifecycle', 'mu_installer', 'invalidator', 'relevance', 'maintenance', 'cache_service' ) as $getter ) {
			$this->assertSame( $p->$getter(), $p->$getter(), "$getter builds its service once" );
		}

		$other = new Plugin();
		$this->assertNotSame( $p->capture(), $other->capture(), 'No shared instances between containers.' );
	}

	public function test_hit_filters_are_none_by_default_and_resolved_once_per_container(): void {
		$plugin   = new Plugin();
		$resolved = new ReflectionProperty( Plugin::class, 'hit_filters_resolved' );

		$this->assertNull( Lookup::hit_filters() );
		$this->assertFalse( $resolved->getValue( $plugin ), 'Nothing is resolved before it is needed.' );
		$this->assertNull( $plugin->hit_filters() );
		$this->assertTrue( $resolved->getValue( $plugin ), 'A null answer is remembered too.' );
		$this->assertNull( $plugin->hit_filters() );
		$this->assertNull( ( new ReflectionProperty( Lookup::class, 'filters' ) )->getValue( $plugin->lookup() ) );
		$this->assertNull( ( new ReflectionProperty( Capture::class, 'filters' ) )->getValue( $plugin->capture() ) );
	}

	public function test_the_container_hands_its_hit_filter_set_to_the_lookup_and_the_capture(): void {
		$fake   = new Rfrac_Fake_Hit_Filters();
		$plugin = new Plugin();
		( new ReflectionProperty( Plugin::class, 'hit_filters' ) )->setValue( $plugin, $fake );
		( new ReflectionProperty( Plugin::class, 'hit_filters_resolved' ) )->setValue( $plugin, true );

		$this->assertSame( $fake, $plugin->hit_filters() );
		$this->assertSame( $fake, ( new ReflectionProperty( Lookup::class, 'filters' ) )->getValue( $plugin->lookup() ) );
		$this->assertSame( $fake, ( new ReflectionProperty( Capture::class, 'filters' ) )->getValue( $plugin->capture() ) );
	}

	public function test_mu_installer_targets_the_template_of_this_plugin(): void {
		$installer = $this->plugin->mu_installer();
		$this->assertSame( RFRAC_DIR . 'mu-plugin/' . Names::MU_FILE, $installer->template_path() );
		$this->assertSame( rtrim( WPMU_PLUGIN_DIR, '/\\' ) . '/' . Names::MU_FILE, $installer->target_path() );
		$this->assertSame( plugin_basename( RFRAC_FILE ), $this->plugin->basename() );
	}

	public function test_compiled_config_getter_is_a_loader_reading_the_option_on_every_call(): void {
		$loader = $this->plugin->compiled_config();
		delete_option( Names::OPTION_CONFIG );
		$this->assertNull( $loader() );

		$this->set_compiled_config( array( 'default_ttl' => 777 ) );
		$config = $loader();
		$this->assertInstanceOf( Compiled_Config::class, $config );
		$this->assertSame( 777, $config->default_ttl() );
	}

	public function test_cache_key_getter_builds_the_cache_key(): void {
		$context  = $this->request( '/wp-json/wp/v2/posts' );
		$decision = $this->policy()->evaluate( $context, $this->config() );

		$this->assertSame( Cache_Key::key( $context, $decision ), ( $this->plugin->cache_key() )( $context, $decision ) );
	}

	public function test_policy_consults_the_coexistence_guard(): void {
		$context = $this->request( '/wp-json/wp/v2/posts' );
		$this->assertCacheable( $this->plugin->policy()->evaluate( $context, $this->config() ) );

		$this->block_by_coexistence();
		$this->assertBypass( Bypass_Reason::COEXISTENCE->value, ( new Plugin() )->policy()->evaluate( $context, $this->config() ) );
	}

	public function test_store_follows_the_compiled_store_mode_and_falls_back_to_the_settings(): void {
		$this->set_compiled_config( array( 'store_mode' => 'object_cache' ) );
		$this->assertInstanceOf( Object_Cache_Store::class, ( new Plugin() )->store() );

		$this->set_compiled_config( array( 'store_mode' => 'database' ) );
		$this->assertInstanceOf( Database_Store::class, ( new Plugin() )->store() );

		delete_option( Names::OPTION_CONFIG );
		Config::instance()->save_settings( array( 'store' => 'object_cache' ) );
		delete_option( Names::OPTION_CONFIG ); // Saving compiled it; test the settings fallback.
		$this->assertInstanceOf( Object_Cache_Store::class, ( new Plugin() )->store() );
	}

	public function test_instance_returns_the_booted_container(): void {
		$this->assertInstanceOf( Plugin::class, Plugin::instance() );
		$this->assertSame( Plugin::instance(), Plugin::boot(), 'A second boot returns the same container.' );
		$this->assertNotEmpty( Plugin::instance()->engine_hooks() );
	}

	public function test_a_new_container_registers_no_hooks(): void {
		$plugin = new Plugin();
		$this->assertSame( array(), $plugin->engine_hooks() );
	}

	// ---------- Hook table ----------

	public function test_every_hook_of_the_table_is_registered_at_its_priority(): void {
		foreach ( self::HOOK_TABLE as $hook => $priority ) {
			$priorities = (array) $priority;
			$hooks      = $this->hooks_on( $hook );
			$this->assertNotEmpty( $hooks, "Nothing registered on $hook" );
			foreach ( $hooks as $h ) {
				$this->assertContains( $h['priority'], $priorities, "Priority of $hook" );
				$this->assertSame( $h['priority'], has_filter( $hook, $h['callback'] ), "$hook is registered with WordPress" );
			}
			$this->assertEqualsCanonicalizing( $priorities, array_values( array_unique( array_column( $hooks, 'priority' ) ) ), "Every priority of $hook is used" );
		}
		$this->assertCount( 2, $this->hooks_on( 'plugins_loaded' ), 'The fallback server and the integrations.' );
		$this->assertCount( 2, $this->hooks_on( 'upgrader_process_complete' ), 'mark_stale and the must-use refresh of an outdated copy.' );
		$this->assertCount( 2, $this->hooks_on( 'rest_pre_echo_response' ), 'The invalidation commit and Capture.' );
		$this->assertCount( 2, $this->hooks_on( 'rfrac/activate_site' ), 'Site setup and the maintenance schedule.' );
		$this->assertCount( 2, $this->hooks_on( 'rfrac/deactivate_site' ), 'Site flush and the maintenance unschedule.' );
		$this->assertCount( 1, $this->hooks_on( 'rfrac/admin_page_load' ), 'The maintenance trigger.' );

		$registered = array_unique( array_column( $this->plugin->engine_hooks(), 'hook' ) );
		sort( $registered );
		$expected = array_keys( self::HOOK_TABLE );
		sort( $expected );
		$this->assertSame( $expected, $registered, 'No engine hook outside the table.' );
	}

	public function test_booted_container_has_the_same_hooks_registered(): void {
		foreach ( Plugin::instance()->engine_hooks() as $h ) {
			$this->assertArrayHasKey( $h['hook'], self::HOOK_TABLE );
		}
		// The fresh container registered the two admin-only Site Health hooks that the front-end boot did not.
		$this->assertCount( count( $this->plugin->engine_hooks() ) - 2, Plugin::instance()->engine_hooks() );
	}

	public function test_no_engine_work_on_admin_init_or_ajax_and_no_mu_check(): void {
		global $wp_filter;
		foreach ( $this->plugin->engine_hooks() as $h ) {
			$this->assertNotSame( 'admin_init', $h['hook'] );
			$this->assertStringStartsNotWith( 'wp_ajax', $h['hook'] );
		}
		$this->assertFalse( method_exists( Admin::class, 'check_muplugin_existence' ) );

		foreach ( $wp_filter['admin_init']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];
				if ( is_array( $fn ) ) {
					$this->assertStringNotContainsStringIgnoringCase( 'mu', (string) $fn[1], 'No must-use check on admin_init.' );
				}
			}
		}
	}

	/**
	 * Whether a callback of the coexistence notice is hooked on an action.
	 *
	 * @param string $hook Action name.
	 */
	private function coexistence_notice_is_hooked( string $hook ): bool {
		global $wp_filter;
		foreach ( isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && $function[0] instanceof Coexistence_Notice ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Register the hooks of a fresh container as if nothing had registered the episode count before.
	 *
	 * The container booted for the tests hooks the static callback on `activated_plugin` already (in every context),
	 * so it is taken off first: what the fresh container registers is then all there is.
	 *
	 * @return void
	 */
	private function register_hooks_without_the_episode_count(): void {
		remove_action( 'activated_plugin', array( Coexistence_Notice::class, 'on_plugin_activated' ), 10 );
		$this->assertFalse( has_action( 'activated_plugin', array( Coexistence_Notice::class, 'on_plugin_activated' ) ), 'Precondition.' );

		( new Plugin() )->register_hooks();
	}

	public function test_the_coexistence_notice_is_hooked_on_an_admin_request(): void {
		( new Plugin() )->register_hooks();

		$this->assertTrue( $this->coexistence_notice_is_hooked( 'admin_notices' ) );
		$this->assertTrue( $this->coexistence_notice_is_hooked( 'network_admin_notices' ) );
		$this->assertNotFalse( has_action( 'admin_post_rfrac_dismiss_coexistence' ) );
		$this->assertFalse( has_action( 'admin_notices', array( Coexistence_Guard::class, 'render_notice' ) ), 'The guard prints nothing itself.' );
	}

	public function test_the_coexistence_notice_is_not_hooked_during_ajax(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );

		( new Plugin() )->register_hooks();

		$this->assertFalse( $this->coexistence_notice_is_hooked( 'admin_notices' ) );
		$this->assertFalse( $this->coexistence_notice_is_hooked( 'network_admin_notices' ) );
		$this->assertFalse( has_action( 'admin_post_rfrac_dismiss_coexistence' ) );
	}

	public function test_the_coexistence_notice_is_not_hooked_outside_the_admin(): void {
		set_current_screen( 'front' );

		( new Plugin() )->register_hooks();

		$this->assertFalse( $this->coexistence_notice_is_hooked( 'admin_notices' ) );
		$this->assertFalse( $this->coexistence_notice_is_hooked( 'network_admin_notices' ) );
		$this->assertFalse( has_action( 'admin_post_rfrac_dismiss_coexistence' ) );
	}

	public function test_the_episode_count_is_hooked_on_an_admin_request(): void {
		$this->register_hooks_without_the_episode_count();

		$this->assertSame( 10, has_action( 'activated_plugin', array( Coexistence_Notice::class, 'on_plugin_activated' ) ) );
		$this->assertTrue( $this->coexistence_notice_is_hooked( 'admin_notices' ), 'The notice is still hooked in wp-admin.' );
	}

	public function test_the_episode_count_is_hooked_during_ajax_without_the_notice(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->register_hooks_without_the_episode_count();

		$this->assertSame( 10, has_action( 'activated_plugin', array( Coexistence_Notice::class, 'on_plugin_activated' ) ) );
		$this->assertFalse( $this->coexistence_notice_is_hooked( 'admin_notices' ) );
		$this->assertFalse( $this->coexistence_notice_is_hooked( 'network_admin_notices' ) );
		$this->assertFalse( has_action( 'admin_post_rfrac_dismiss_coexistence' ) );
	}

	public function test_the_episode_count_is_hooked_outside_the_admin_without_the_notice(): void {
		set_current_screen( 'front' );

		$this->register_hooks_without_the_episode_count();

		$this->assertSame( 10, has_action( 'activated_plugin', array( Coexistence_Notice::class, 'on_plugin_activated' ) ) );
		$this->assertFalse( $this->coexistence_notice_is_hooked( 'admin_notices' ) );
		$this->assertFalse( $this->coexistence_notice_is_hooked( 'network_admin_notices' ) );
		$this->assertFalse( has_action( 'admin_post_rfrac_dismiss_coexistence' ) );
	}

	public function test_one_activation_counts_one_episode_on_an_admin_request(): void {
		$this->register_hooks_without_the_episode_count();
		$this->assertFalse( get_option( Coexistence_Notice::OPTION_EPISODE ), 'Precondition: no episode yet.' );

		do_action( 'activated_plugin', 'wp-rest-cache/wp-rest-cache.php', false );

		$this->assertSame( 1, (int) get_option( Coexistence_Notice::OPTION_EPISODE ), 'One activation, one episode: the callback is hooked once.' );
	}

	public function test_one_activation_counts_one_episode_outside_the_admin(): void {
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		$this->register_hooks_without_the_episode_count();
		$this->assertFalse( $this->coexistence_notice_is_hooked( 'admin_notices' ), 'Precondition: the notice is not hooked outside the admin.' );
		$this->assertFalse( get_option( Coexistence_Notice::OPTION_EPISODE ), 'Precondition: no episode yet.' );

		do_action( 'activated_plugin', 'wp-rest-cache/wp-rest-cache.php', false );

		$this->assertSame( 1, (int) get_option( Coexistence_Notice::OPTION_EPISODE ), 'An activation outside wp-admin counts through the hook the container registers.' );
	}

	public function test_one_activation_counts_one_episode_during_ajax(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->register_hooks_without_the_episode_count();
		$this->assertFalse( $this->coexistence_notice_is_hooked( 'admin_notices' ), 'Precondition: the notice is not hooked during AJAX.' );
		$this->assertFalse( get_option( Coexistence_Notice::OPTION_EPISODE ), 'Precondition: no episode yet.' );

		do_action( 'activated_plugin', 'wp-rest-cache/wp-rest-cache.php', false );

		$this->assertSame( 1, (int) get_option( Coexistence_Notice::OPTION_EPISODE ), 'An activation during AJAX counts through the hook the container registers.' );
	}

	public function test_no_legacy_engine_callback_is_hooked(): void {
		global $wp_filter;
		$legacy = '/^Rockfire\\\\Rest_Api_Cache\\\\(API|Caching|Controller)\\\\/';
		foreach ( $wp_filter as $hook => $wp_hook ) {
			foreach ( $wp_hook->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'];
					if ( is_array( $fn ) ) {
						$class = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
						$this->assertDoesNotMatchRegularExpression( $legacy, $class, "Legacy callback on $hook" );
					}
				}
			}
		}
		$this->assertFalse( has_filter( 'rfrac/skip_caching' ) );
		$this->assertFalse( has_action( 'rfrac_regenerate_cron' ) );
		$this->assertFalse( has_action( 'rfrac_cleanup_deleted_caches' ) );
	}

	// ---------- Integrations ----------

	/**
	 * The `plugins_loaded` callbacks of a container at a priority.
	 *
	 * @param Plugin $plugin   Container.
	 * @param int    $priority Priority.
	 *
	 * @return list<array{hook:string,callback:\Closure,priority:int,args:int}>
	 */
	private function plugins_loaded_hooks( Plugin $plugin, int $priority ): array {
		return array_values(
			array_filter(
				$plugin->engine_hooks(),
				static fn( array $h ): bool => 'plugins_loaded' === $h['hook'] && $priority === $h['priority']
			)
		);
	}

	public function test_without_woocommerce_the_only_new_engine_hook_is_plugins_loaded_at_10(): void {
		$this->assertSame( 0, did_action( 'woocommerce_loaded' ), 'Precondition: WooCommerce has not loaded.' );

		$this->assertNull( $this->plugin->woocommerce() );
		$hooks = $this->plugins_loaded_hooks( $this->plugin, 10 );
		$this->assertCount( 1, $hooks );
		$this->assertSame( 0, $hooks[0]['args'] );
		$this->assertSame( 10, has_filter( 'plugins_loaded', $hooks[0]['callback'] ) );
		$this->assertCount( 1, $this->plugins_loaded_hooks( $this->plugin, 1 ), 'The fallback server is untouched.' );

		$this->plugin->register_integrations();
		$this->assertNull( $this->plugin->woocommerce(), 'An inactive call wires nothing.' );
	}

	public function test_an_inactive_call_does_not_mark_the_integrations_registered(): void {
		$plugin = new Plugin();
		$plugin->register_integrations();
		$this->assertNull( $plugin->woocommerce() );

		do_action( 'woocommerce_loaded' );
		$plugin->register_integrations();

		$this->assertInstanceOf( Module::class, $plugin->woocommerce() );
	}

	public function test_the_woocommerce_module_is_wired_once_per_container_after_woocommerce_loaded(): void {
		do_action( 'woocommerce_loaded' );

		// Built after `plugins_loaded` has fired: wired right away.
		$plugin = new Plugin();
		$plugin->register_engine_hooks();
		$module = $plugin->woocommerce();
		$this->assertInstanceOf( Module::class, $module );
		$this->assertSame( $plugin, $module->plugin() );
		$hooks = count( $plugin->engine_hooks() );

		$plugin->register_integrations();

		$this->assertSame( $module, $plugin->woocommerce() );
		$this->assertCount( $hooks, $plugin->engine_hooks(), 'A second call adds no engine hook.' );

		$other = new Plugin();
		$other->register_integrations();
		$this->assertNotSame( $module, $other->woocommerce(), 'One module per container.' );
	}

	public function test_a_container_built_before_plugins_loaded_wires_the_integrations_when_it_fires(): void {
		global $wp_actions, $wp_filter;
		$saved_actions = $wp_actions['plugins_loaded'] ?? null;
		$saved_filters = $wp_filter['plugins_loaded'] ?? null;
		unset( $wp_actions['plugins_loaded'] );
		$wp_filter['plugins_loaded'] = new WP_Hook();
		try {
			do_action( 'woocommerce_loaded' ); // WooCommerce loads before `plugins_loaded` fires.
			$plugin = new Plugin();
			$plugin->register_engine_hooks();
			$this->assertNull( $plugin->woocommerce(), 'Nothing is wired before the action.' );

			$hooks = $this->plugins_loaded_hooks( $plugin, 10 );
			$this->assertCount( 1, $hooks );
			$this->assertSame( 10, has_filter( 'plugins_loaded', $hooks[0]['callback'] ) );

			remove_all_filters( 'plugins_loaded', 1 ); // Only the integration callback is of interest here.
			do_action( 'plugins_loaded' );

			$this->assertInstanceOf( Module::class, $plugin->woocommerce() );
		} finally {
			if ( null === $saved_actions ) {
				unset( $wp_actions['plugins_loaded'] );
			} else {
				$wp_actions['plugins_loaded'] = $saved_actions;
			}
			$wp_filter['plugins_loaded'] = $saved_filters;
		}
	}

	// ---------- Smoke: no PHP notices (#48) ----------

	/**
	 * Run a callback and collect every PHP error it raises (WP_DEBUG is on in the test suite).
	 *
	 * The "headers already sent" warnings are expected: PHPUnit has already written output.
	 *
	 * @param callable $run Callback.
	 *
	 * @return list<string> The errors, as "errno: message (file:line)".
	 */
	private function collect_errors( callable $run ): array {
		$errors = array();
		set_error_handler(
			static function ( int $errno, string $errstr, string $file = '', int $line = 0 ) use ( &$errors ): bool {
				if ( ! ( error_reporting() & $errno ) ) {
					return false;
				}
				if ( false === strpos( $errstr, 'headers already sent' ) ) {
					$errors[] = "$errno: $errstr ($file:$line)";
				}
				return true;
			}
		);
		try {
			$run();
		} finally {
			restore_error_handler();
		}
		return $errors;
	}

	/**
	 * Serve an anonymous GET request through the live engine wiring and collect the PHP errors of the
	 * whole request (bootstrap hooks, dispatch, capture and store).
	 *
	 * Unlike {@see self::serve()} no handler of its own is installed around `serve_request()`.
	 *
	 * @param string               $route  Route.
	 * @param Spy_REST_Server|null $server Set to the server.
	 *
	 * @return list<string>
	 */
	private function serve_collecting_errors( string $route, ?Spy_REST_Server &$server = null ): array {
		$this->simulate( '/wp-json' . $route );
		return $this->collect_errors(
			function () use ( $route, &$server ): void {
				$this->fire( 'plugins_loaded' );
				$this->fire( 'init' );
				$server                    = new Spy_REST_Server();
				$GLOBALS['wp_rest_server'] = $server;
				do_action( 'rest_api_init', $server );
				$server->serve_request( $route );
			}
		);
	}

	public function test_serving_a_rest_request_raises_no_php_notices(): void {
		$this->assertTrue( WP_DEBUG );
		self::factory()->post->create();
		$this->plugin->compiler()->compile();

		// Request 1: the configuration is compiled while the request runs, so no lookup recorded a flush epoch to store against.
		$server = null;
		$errors = $this->serve_collecting_errors( '/wp/v2/posts', $server );

		$this->assertSame( array(), $errors );
		$this->assertInstanceOf( Spy_REST_Server::class, $server );
		$this->assertSame( 'BYPASS', $server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::NO_LOOKUP->value, $server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count() );

		// Request 2: the lookup finds the compiled configuration, so the response is stored.
		$this->begin_next_request();
		$server = null;
		$errors = $this->serve_collecting_errors( '/wp/v2/posts', $server );

		$this->assertSame( array(), $errors );
		$this->assertInstanceOf( Spy_REST_Server::class, $server );
		$this->assertSame( 'MISS', $server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
	}

	public function test_the_rest_smoke_test_collects_a_notice_raised_during_dispatch(): void {
		self::factory()->post->create();
		$this->plugin->compiler()->compile();
		add_filter(
			'rest_post_dispatch',
			static function ( $response ) {
				trigger_error( 'rfrac smoke probe', E_USER_NOTICE ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
				return $response;
			}
		);

		$errors = $this->serve_collecting_errors( '/wp/v2/posts' );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'rfrac smoke probe', $errors[0] );
	}

	public function test_the_error_collector_ignores_suppressed_errors(): void {
		$errors = $this->collect_errors(
			static function (): void {
				$level = error_reporting( E_ALL & ~E_USER_NOTICE );
				try {
					trigger_error( 'suppressed', E_USER_NOTICE ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
				} finally {
					error_reporting( $level );
				}
			}
		);
		$this->assertSame( array(), $errors );
	}

	public function test_loading_and_rendering_every_admin_page_through_the_admin_raises_no_php_notices(): void {
		$mu_file    = WPMU_PLUGIN_DIR . '/rfrac-early-cache.php';
		$mu_existed = file_exists( $mu_file );

		// A real stored entry, for the Caches details view.
		$this->store_posts();
		global $wpdb;
		$entry_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', $wpdb->prefix . Names::TABLE_ENTRIES ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertGreaterThan( 0, $entry_id );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}
		wp_set_current_user( $user_id );

		$admin = $this->plugin->admin();
		$pages = array();
		foreach ( $admin->pages() as $page ) {
			$pages[ $page->slug() ] = $page;
		}
		$this->assertCount( 4, $pages );

		$views = array();
		foreach ( array_keys( $pages ) as $slug ) {
			$views[] = array( $slug, array() );
		}
		$views[] = array( 'rfrac-routes', array( 'action' => 'edit', 'route_id' => Route_Service::id_for( '/wp/v2/posts' ) ) );
		$views[] = array( 'rfrac-caches', array( 'action' => 'details', 'entry' => (string) $entry_id ) );

		try {
			$this->assertSame(
				array(),
				$this->collect_errors(
					static function () use ( $admin ): void {
						$admin->register();
						do_action( 'admin_init' );
					}
				),
				'admin_init'
			);

			foreach ( $views as list( $slug, $args ) ) {
				$this->assertArrayHasKey( $slug, $pages );
				$page     = $pages[ $slug ];
				$label    = $slug . ' ' . wp_json_encode( $args );
				$_GET     = array_merge( array( 'page' => $slug ), $args );
				$_REQUEST = $_GET;
				set_current_screen( 'toplevel_page_' . $slug );
				$html   = '';
				$errors = $this->collect_errors(
					static function () use ( $admin, $page, &$html ): void {
						$admin->load( $page );
						ob_start();
						try {
							do_action( 'admin_notices' );
							$admin->render( $page );
						} finally {
							$html = (string) ob_get_clean();
						}
					}
				);
				$this->assertSame( array(), $errors, $label );
				$this->assertStringContainsString( 'rfrac-wrap', $html, $label );
			}
		} finally {
			if ( ! $mu_existed && file_exists( $mu_file ) ) {
				unlink( $mu_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			$_GET     = array();
			$_REQUEST = array();
		}
	}

	// ---------- Serving ----------

	public function test_first_anonymous_request_recompiles_the_second_stores_and_the_third_is_a_hit(): void {
		self::factory()->post->create( array( 'post_title' => 'Wired' ) );
		$this->plugin->compiler()->compile();
		$this->assertTrue( Compiled_Config::load()->pending_discovery(), 'Precondition: activation state.' );
		$this->simulate( '/wp-json/wp/v2/posts' );

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		try {
			// Request 1: no must-use file, so the fallback server runs; the pending configuration enables no route, so it records no epoch.
			$this->fire( 'plugins_loaded' );
			$this->fire( 'init' );
			$this->assertTrue( Request_State::lookup_done() );
			$this->assertNull( Request_State::flush_epoch() );
			$server = $this->serve( '/wp/v2/posts' );

			$config = Compiled_Config::load();
			$this->assertFalse( $config->pending_discovery(), 'Recompiled on rest_api_init.' );
			$this->assertSame( 'BYPASS', $server->sent_headers['X-Rfrac-Cache'] ?? null );
			$this->assertSame( Bypass_Reason::NO_LOOKUP->value, $server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
			$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'No lookup recorded a flush epoch, so nothing is stored.' );
			$this->assertTrue( Request_State::outdated_snapshot(), 'The lookup ran on the pending snapshot.' );
			$this->assertTrue( Request_State::outdated_snapshot_rebuilt(), 'The recompile of the request rebuilt it.' );
			$this->assertNull( Failure_Recorder::last(), 'The first request after an activation is refused without a recorded failure.' );

			// Request 2: the fallback server finds the compiled configuration, misses and records the epoch, so the response is stored.
			$this->begin_next_request();
			$this->simulate( '/wp-json/wp/v2/posts' );
			$this->fire( 'plugins_loaded' );
			$this->fire( 'init' );
			$this->assertNotNull( Request_State::flush_epoch() );
			$server = $this->serve( '/wp/v2/posts' );
		} finally {
			$recorder->stop();
		}

		$this->assertSame( 'MISS', $server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'Stored in the same request.' );
		$this->assertNull( Failure_Recorder::last(), 'No failure was recorded for the first two requests.' );
		$this->assertSame( array(), $recorder->matching( '/SHOW\s+TABLES/i' ), 'No SHOW TABLES on an anonymous REST GET.' );
		$this->assertSame( array(), $recorder->matching( '/^\s*(CREATE|ALTER|DESCRIBE)\b/i' ), 'No schema work on an anonymous REST GET.' );
		$body = $server->sent_body;

		// Request 3: the early server (must-use phase) serves the stored entry.
		Request_State::reset();
		$this->simulate( '/wp-json/wp/v2/posts' );
		Early_Server::use_writer( $this->capturing_writer() );
		$recorder->start();
		try {
			Early_Server::boot( Mu_Installer::TEMPLATE_VERSION );
			$this->fail( 'Expected a hit.' );
		} catch ( Rfrac_Test_Exit $e ) {
			$this->assertContains( 'X-Rfrac-Cache: HIT', $this->headers );
			$this->assertSame( $body, $this->output );
		} finally {
			$recorder->stop();
		}
		$this->assertSame( array(), $recorder->matching( '/SHOW\s+TABLES/i' ) );
	}

	public function test_the_request_that_follows_a_schema_upgrade_is_refused_no_lookup_and_recorded(): void {
		self::factory()->post->create();
		$this->plugin->invalidator()->commit();
		$this->set_compiled_config( array_merge( $this->snapshot(), array( 'schema_version' => 999, 'fingerprint' => 'before-the-upgrade' ) ) );
		$this->simulate( '/wp-json/wp/v2/posts' );

		// The lookup is skipped for the stale schema version, then the migration compiles a pending snapshot.
		$this->fire( 'plugins_loaded' );
		$this->assertFalse( Request_State::lookup_done(), 'Precondition: the stale schema version skipped the lookup.' );
		$this->fire( Hooks::MIGRATED, 0, 1 );
		$this->assertTrue( Compiled_Config::load()->pending_discovery(), 'Precondition: the migration compiled a pending snapshot.' );

		$server = $this->serve( '/wp/v2/posts', false );

		$this->assertFalse( Compiled_Config::load()->pending_discovery(), 'The request rebuilt the snapshot on rest_api_init.' );
		$this->assertFalse( Request_State::outdated_snapshot(), 'No lookup saw the pending snapshot.' );
		$this->assertFalse( Request_State::outdated_snapshot_rebuilt() );
		$this->assertSame( 'BYPASS', $server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::NO_LOOKUP->value, $server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count() );
		$this->assertSame( 'capture', Failure_Recorder::last()['context'] ?? null, 'The upgrade request is a refusal worth reporting.' );
	}

	/**
	 * Data provider: the two outdated snapshots a lookup can run on.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function outdated_snapshot_states(): array {
		return array(
			'pending' => array( 'pending' ),
			'stale'   => array( 'stale' ),
		);
	}

	/**
	 * @dataProvider outdated_snapshot_states
	 *
	 * @param string $state `pending` or `stale`.
	 */
	public function test_a_rebuild_that_could_not_be_stored_leaves_the_no_lookup_refusal_recorded( string $state ): void {
		self::factory()->post->create();
		$this->plugin->invalidator()->commit();
		$this->set_compiled_config( array_merge( $this->snapshot(), array( 'fingerprint' => '', 'pending_discovery' => 'pending' === $state ) ) );
		$this->simulate( '/wp-json/wp/v2/posts' );

		// The lookup ran on the outdated snapshot and read no flush epoch; Capture finds the request cacheable.
		$config = Compiled_Config::load();
		$this->assertNotNull( $config );
		Request_State::record_outdated_snapshot();
		Request_State::record_lookup(
			Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() ),
			Policy_Decision::bypass( Bypass_Reason::ROUTE_NOT_ENABLED ),
			null,
			null
		);

		// The write of the rebuilt snapshot does not take effect (a failed write, a filter on the option).
		$keep_the_old_snapshot = static fn( $value, $old_value ) => $old_value;
		add_filter( 'pre_update_option_' . Names::OPTION_CONFIG, $keep_the_old_snapshot, 10, 2 );
		try {
			$server = $this->serve( '/wp/v2/posts', false );
		} finally {
			remove_filter( 'pre_update_option_' . Names::OPTION_CONFIG, $keep_the_old_snapshot, 10 );
		}

		$stored = Compiled_Config::load();
		$this->assertNotNull( $stored );
		$this->assertTrue( $stored->pending_discovery() || '' === $stored->fingerprint(), 'Precondition: the rebuilt snapshot was not stored.' );
		$this->assertFalse( Request_State::outdated_snapshot_rebuilt(), 'A rebuild that was not stored exempts nothing.' );
		$this->assertSame( Bypass_Reason::NO_LOOKUP->value, $server->sent_headers['X-Rfrac-Cache-Reason'] ?? null, 'Precondition: Capture refused the request for lack of a flush epoch.' );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count() );
		$this->assertSame( 'capture', Failure_Recorder::last()['context'] ?? null, 'Every request of a site that never stores the rebuilt snapshot is a refusal worth reporting.' );
		$this->assertSame( '/wp/v2/posts', get_option( Failure_Recorder::OPTION )['subject'] ?? null, 'Recorded for the route of the request.' );
	}

	public function test_a_periodic_rebuild_of_a_usable_snapshot_leaves_the_refusal_recorded(): void {
		self::factory()->post->create();
		$this->plugin->invalidator()->commit();
		$this->set_compiled_config( array_merge( $this->snapshot(), array( 'fingerprint' => 'periodic', 'routes' => array() ) ) );
		delete_transient( Config_Compiler::CHECK_TRANSIENT );
		$this->simulate( '/wp-json/wp/v2/posts' );

		$this->fire( 'plugins_loaded' );
		$this->assertTrue( Request_State::lookup_done(), 'Precondition: the lookup ran on the usable snapshot.' );
		$this->assertNull( Request_State::flush_epoch(), 'Precondition: no route is cached, so it recorded no epoch.' );
		$server = $this->serve( '/wp/v2/posts', false );

		$this->assertNotSame( 'periodic', Compiled_Config::load()->fingerprint(), 'The periodic check rebuilt the snapshot.' );
		$this->assertFalse( Request_State::outdated_snapshot(), 'The snapshot was neither pending nor stale.' );
		$this->assertFalse( Request_State::outdated_snapshot_rebuilt() );
		$this->assertSame( 'BYPASS', $server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( Bypass_Reason::NO_LOOKUP->value, $server->sent_headers['X-Rfrac-Cache-Reason'] ?? null );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count() );
		$this->assertSame( 'capture', Failure_Recorder::last()['context'] ?? null );
	}

	public function test_fallback_server_serves_a_hit_when_the_must_use_file_did_not_boot(): void {
		self::factory()->post->create();
		$this->settle_before_request();
		$this->simulate( '/wp-json/wp/v2/posts' );
		$this->serve( '/wp/v2/posts' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		Request_State::reset();
		$lookup = new Lookup( $this->plugin->policy(), $this->plugin->repository(), $this->capturing_writer(), $this->plugin->cache_key() );
		try {
			( new Fallback_Server( $lookup, $this->plugin->compiled_config() ) )->maybe_serve();
			$this->fail( 'Expected a hit.' );
		} catch ( Rfrac_Test_Exit $e ) {
			$this->assertContains( 'X-Rfrac-Cache: HIT', $this->headers );
		}
	}

	public function test_fallback_hit_through_the_plugins_loaded_hook_and_the_container(): void {
		$body = $this->store_posts();

		$terminated = false;
		$plugin     = new Plugin( $this->recording_writer( $terminated ) );
		$this->simulate( '/wp-json/wp/v2/posts' );
		$this->do_plugins_loaded( $plugin );

		$this->assertTrue( $terminated, 'The hit ended the request.' );
		$this->assertContains( 'X-Rfrac-Cache: HIT', $this->headers );
		$this->assertSame( $body, $this->output );
	}

	/**
	 * Credentials that must never get a hit.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>}>
	 */
	public static function credentials(): array {
		return array(
			'authorization'    => array( array( 'HTTP_AUTHORIZATION' => 'Bearer abc' ), array() ),
			'logged-in cookie' => array( array(), array( 'wordpress_logged_in_0123abcd' => 'admin|1|x' ) ),
			'api key header'   => array( array( 'HTTP_X_API_KEY' => 'k' ), array() ),
		);
	}

	/**
	 * @dataProvider credentials
	 *
	 * @param array<string,mixed> $server Server entries.
	 * @param array<string,mixed> $cookie Cookies.
	 */
	public function test_fallback_never_serves_a_credentialed_request_through_the_container( array $server, array $cookie ): void {
		$this->store_posts();

		$terminated = false;
		$plugin     = new Plugin( $this->recording_writer( $terminated ) );
		$this->simulate( '/wp-json/wp/v2/posts', $server, $cookie );
		$this->do_plugins_loaded( $plugin );

		$this->assertFalse( $terminated );
		$this->assertSame( '', $this->output );
		$this->assertSame( array(), $this->headers );
		$this->assertSame( Bypass_Reason::CREDENTIALS, Request_State::decision()->reason );
	}

	public function test_fallback_server_does_nothing_after_the_early_server_booted(): void {
		Request_State::record_boot( Mu_Installer::TEMPLATE_VERSION, true );
		$this->simulate( '/wp-json/wp/v2/posts' );

		$this->fire( 'plugins_loaded' );

		$this->assertFalse( Request_State::lookup_done() );
	}

	public function test_capture_is_not_built_before_the_hit_decision(): void {
		$plugin = new Plugin();
		$plugin->register_engine_hooks();
		$capture = new ReflectionProperty( Plugin::class, 'capture' );
		$capture->setAccessible( true );

		$this->simulate( '/wp-json/wp/v2/posts' );
		$plugin->fallback_server()->maybe_serve();

		$this->assertNull( $capture->getValue( $plugin ) );
	}

	// ---------- Config ----------

	public function test_settings_saves_recompile_with_the_server_on_add_and_update(): void {
		delete_option( Names::OPTION_SETTINGS );
		delete_option( Names::OPTION_CONFIG );
		$GLOBALS['wp_rest_server'] = null;

		Config::instance()->save_settings( array( 'default_ttl' => 1234 ) );
		$config = Compiled_Config::load();
		$this->assertNotNull( $config, 'The first save (add_option) compiles.' );
		$this->assertSame( 1234, $config->default_ttl() );
		$this->assertFalse( $config->pending_discovery(), 'Compiled with the REST server.' );

		Config::instance()->save_settings( array( 'default_ttl' => 2345 ) );
		$this->assertSame( 2345, Compiled_Config::load()->default_ttl(), 'A later save (update_option) compiles.' );
	}

	/**
	 * Count the writes of the settings option and of the compiled config while running a callback.
	 *
	 * @param callable $callback Work to run.
	 *
	 * @return array{settings:int,config:int}
	 */
	private function count_settings_and_config_writes( callable $callback ): array {
		$counts   = array(
			'settings' => 0,
			'config'   => 0,
		);
		$settings = static function ( $value ) use ( &$counts ) {
			++$counts['settings'];
			return $value;
		};
		$config   = static function ( $value ) use ( &$counts ) {
			++$counts['config'];
			return $value;
		};
		add_filter( 'pre_update_option_' . Names::OPTION_SETTINGS, $settings );
		add_filter( 'pre_update_option_' . Names::OPTION_CONFIG, $config );
		try {
			$callback();
		} finally {
			remove_filter( 'pre_update_option_' . Names::OPTION_SETTINGS, $settings );
			remove_filter( 'pre_update_option_' . Names::OPTION_CONFIG, $config );
		}
		return $counts;
	}

	public function test_settings_and_rules_saved_together_write_once_and_compile_once(): void {
		$this->compile_a_current_snapshot_caching_posts();
		$fired = 0;
		$count = static function () use ( &$fired ): void {
			++$fired;
		};
		add_action( 'update_option_' . Names::OPTION_SETTINGS, $count );

		$result = null;
		$writes = $this->count_settings_and_config_writes(
			function () use ( &$result ): void {
				$result = Config::instance()->save_settings_and_rules(
					array(
						'default_ttl' => 2345,
						'max_ttl'     => 7200,
					),
					array(
						'/wp/v2/posts' => array( 'status' => 'excluded' ),
						'/wp/v2/pages' => array( 'ttl' => 600 ),
					)
				);
			}
		);

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( 1, $fired, 'update_option_rfrac_settings fires exactly once.' );
		$this->assertSame( 1, $writes['settings'] );
		$this->assertSame( 1, $writes['config'], 'rfrac_config is rewritten once.' );
		$compiled = Compiled_Config::load();
		$this->assertSame( 2345, $compiled->default_ttl() );
		$this->assertFalse( $this->snapshot_caches_route( $compiled, '/wp/v2/posts' ), 'The compile saw the new rules.' );
		$this->assertTrue( $this->snapshot_caches_route( $compiled, '/wp/v2/pages' ) );
		$this->assertNotSame( '', $compiled->fingerprint() );
	}

	public function test_a_rejected_or_unchanged_settings_and_rules_save_compiles_nothing(): void {
		$this->compile_a_current_snapshot_caching_posts();
		Config::instance()->save_settings_and_rules( array( 'default_ttl' => 2345 ), array( '/wp/v2/pages' => array( 'ttl' => 600 ) ) );
		$stored = get_option( Names::OPTION_SETTINGS );
		$config = get_option( Names::OPTION_CONFIG );

		$results = array();
		$writes  = $this->count_settings_and_config_writes(
			function () use ( &$results ): void {
				$results[] = Config::instance()->save_settings_and_rules( array( 'default_ttl' => 3456 ), array( '/wp/v2/pages' => array( 'status' => 'nonsense' ) ) );
				$results[] = Config::instance()->save_settings_and_rules( array( 'default_ttl' => 2345 ), array( '/wp/v2/pages' => array( 'ttl' => 600 ) ) );
			}
		);

		$this->assertFalse( $results[0]->is_valid(), 'The invalid rule is rejected.' );
		$this->assertTrue( $results[1]->is_valid(), 'The unchanged input is accepted.' );
		$this->assertSame( array( 'settings' => 0, 'config' => 0 ), $writes, 'Neither a write nor a compile happened.' );
		$this->assertSame( $stored, get_option( Names::OPTION_SETTINGS ) );
		$this->assertSame( $config, get_option( Names::OPTION_CONFIG ) );
	}

	public function test_a_settings_save_on_a_partial_server_leaves_the_snapshot_serving_and_marked_stale(): void {
		$this->new_rest_server(); // The global server is already initialised, so reading it fires no `rest_api_init`.
		delete_option( Names::OPTION_SETTINGS );
		delete_option( Names::OPTION_CONFIG );
		Config::instance()->save_settings( array( 'default_ttl' => 1234 ) );
		$current = Compiled_Config::load();
		$this->assertNotNull( $current, 'Precondition: the first save compiles.' );
		$this->assertSame( 1234, $current->default_ttl() );
		$this->assertNotSame( '', $current->fingerprint(), 'Precondition: the snapshot is current.' );
		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );

		Config::instance()->save_settings( array( 'default_ttl' => 2345 ) );

		$kept = Compiled_Config::load();
		$this->assertSame( 1234, $kept->default_ttl(), 'The partial server did not recompile the snapshot.' );
		$this->assertSame( '', $kept->fingerprint(), 'The snapshot is marked stale.' );
		$this->assertFalse( $kept->pending_discovery(), 'The snapshot keeps serving its routes.' );
		$this->assertEquals( $current->routes(), $kept->routes() );
		$this->assertSame( 'compile', Failure_Recorder::last()['context'] ?? null, 'The deferred compile is recorded, not silent.' );

		remove_filter( Hooks::PARTIAL_SERVER, '__return_true' );
		$this->assertTrue( $this->plugin->compiler()->maybe_recompile_for_server( rest_get_server() ), 'The next full-server request rebuilds it.' );
		$rebuilt = Compiled_Config::load();
		$this->assertSame( 2345, $rebuilt->default_ttl(), 'With the saved settings.' );
		$this->assertNotSame( '', $rebuilt->fingerprint() );
	}

	/**
	 * Whether the snapshot caches a route: a compiled entry that is enabled.
	 *
	 * @param Compiled_Config $config Snapshot.
	 * @param string          $key    Route key.
	 *
	 * @return bool
	 */
	private function snapshot_caches_route( Compiled_Config $config, string $key ): bool {
		foreach ( $config->routes() as $route ) {
			if ( $key === $route->key && $route->enabled ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A current snapshot compiled from a full server that caches `/wp/v2/posts`, with no failure recorded yet.
	 *
	 * @return void
	 */
	private function compile_a_current_snapshot_caching_posts(): void {
		$this->new_rest_server(); // The global server is already initialised, so reading it fires no `rest_api_init`.
		delete_option( Names::OPTION_SETTINGS );
		delete_option( Names::OPTION_CONFIG );
		Config::instance()->save_settings( array( 'default_ttl' => 1234 ) );
		delete_option( Failure_Recorder::OPTION );

		$current = Compiled_Config::load();
		$this->assertNotNull( $current, 'Precondition: the first save compiles.' );
		$this->assertNotSame( '', $current->fingerprint(), 'Precondition: the snapshot is current.' );
		$this->assertTrue( $current->enabled(), 'Precondition: the cache is enabled.' );
		$this->assertTrue( $this->snapshot_caches_route( $current, '/wp/v2/posts' ), 'Precondition: the snapshot caches the posts route.' );
	}

	public function test_a_route_exclusion_saved_on_a_partial_server_is_reported_as_pending_until_the_snapshot_is_rebuilt(): void {
		$this->compile_a_current_snapshot_caching_posts();
		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );

		$saved = Config::instance()->save_route_rule( '/wp/v2/posts', array( 'status' => 'excluded' ) );

		$this->assertTrue( $saved->is_valid(), 'The admin sees the save succeed.' );
		$kept = Compiled_Config::load();
		$this->assertTrue( $this->snapshot_caches_route( $kept, '/wp/v2/posts' ), 'The partial server did not recompile, so the old snapshot still serves the route.' );
		$last = Failure_Recorder::last();
		$this->assertSame( 'compile', $last['context'] ?? null, 'The admin is told the change is pending.' );
		$this->assertStringContainsString( 'not compiled', $last['message'] ?? '' );
		$stored = get_option( Failure_Recorder::OPTION );
		$this->assertTrue( $stored['clearable'] ?? null, 'The next successful rebuild clears it.' );

		remove_filter( Hooks::PARTIAL_SERVER, '__return_true' );
		$this->fire( 'rest_api_init', rest_get_server() );

		$this->assertFalse( $this->snapshot_caches_route( Compiled_Config::load(), '/wp/v2/posts' ), 'The next full-server request applies the exclusion.' );
		$this->assertNull( Failure_Recorder::last(), 'The rebuild clears the pending notice.' );
	}

	public function test_disabling_the_cache_on_a_partial_server_is_reported_as_pending_until_the_snapshot_is_rebuilt(): void {
		$this->compile_a_current_snapshot_caching_posts();
		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );

		$saved = Config::instance()->save_settings( array( 'enabled' => false ) );

		$this->assertTrue( $saved->is_valid(), 'The admin sees the save succeed.' );
		$this->assertTrue( Compiled_Config::load()->enabled(), 'The partial server did not recompile, so the old snapshot still serves.' );
		$last = Failure_Recorder::last();
		$this->assertSame( 'compile', $last['context'] ?? null, 'The admin is told the change is pending.' );
		$stored = get_option( Failure_Recorder::OPTION );
		$this->assertTrue( $stored['clearable'] ?? null, 'The next successful rebuild clears it.' );

		remove_filter( Hooks::PARTIAL_SERVER, '__return_true' );
		$this->fire( 'rest_api_init', rest_get_server() );

		$this->assertFalse( Compiled_Config::load()->enabled(), 'The next full-server request applies it.' );
		$this->assertNull( Failure_Recorder::last(), 'The rebuild clears the pending notice.' );
	}

	public function test_a_later_settings_save_on_a_full_server_clears_the_pending_notice(): void {
		$this->compile_a_current_snapshot_caching_posts();
		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );
		Config::instance()->save_settings( array( 'default_ttl' => 2345 ) );
		$this->assertSame( 'compile', Failure_Recorder::last()['context'] ?? null, 'Precondition: the change is reported as pending.' );

		remove_filter( Hooks::PARTIAL_SERVER, '__return_true' );
		Config::instance()->save_settings( array( 'default_ttl' => 3456 ) );

		$this->assertSame( 3456, Compiled_Config::load()->default_ttl(), 'The save compiled.' );
		$this->assertNull( Failure_Recorder::last(), 'The compile that applies the settings clears the pending notice.' );
	}

	public function test_a_settings_save_on_a_partial_server_keeps_a_missing_snapshot_missing(): void {
		$this->new_rest_server();
		delete_option( Names::OPTION_SETTINGS );
		delete_option( Names::OPTION_CONFIG );
		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );

		Config::instance()->save_settings( array( 'default_ttl' => 1234 ) );

		$this->assertFalse( get_option( Names::OPTION_CONFIG ), 'The first save (add_option) compiles nothing.' );
		$this->assertNull( Failure_Recorder::last(), 'A missing snapshot serves nothing, so nothing is pending.' );
	}

	public function test_a_settings_save_passes_the_recorded_route_and_the_discovery_server_to_the_partial_server_filter(): void {
		$server = $this->new_rest_server();
		delete_option( Names::OPTION_SETTINGS );
		$this->simulate( '/wp-json/wc/store/v1/products' );
		Request_State::record_lookup( Request_Context::from_globals( 'wp-json' ), \Rockfire\Rest_Api_Cache\Request\Policy_Decision::bypass( Bypass_Reason::ERROR ), null );
		$seen = array();
		add_filter(
			Hooks::PARTIAL_SERVER,
			static function ( $partial, $given = null, $route = null ) use ( &$seen ): bool {
				$seen[] = array( $partial, $given, $route );
				return false;
			},
			10,
			3
		);

		Config::instance()->save_settings( array( 'default_ttl' => 1234 ) );

		$this->assertNotEmpty( $seen, 'The settings save asks the filter.' );
		foreach ( $seen as $call ) {
			$this->assertFalse( $call[0] );
			$this->assertSame( $server, $call[1] );
			$this->assertSame( '/wc/store/v1/products', $call[2] );
		}
	}

	public function test_migration_and_direct_compiles_ignore_the_partial_server_filter(): void {
		$this->new_rest_server();
		delete_option( Names::OPTION_CONFIG );
		$calls = 0;
		add_filter(
			Hooks::PARTIAL_SERVER,
			static function () use ( &$calls ): bool {
				++$calls;
				return true;
			}
		);

		$this->fire( Hooks::MIGRATED, 0, 1 );
		$this->assertTrue( Compiled_Config::load()->pending_discovery(), 'The migration compiles.' );
		$this->plugin->compiler()->compile( rest_get_server() );
		$this->assertFalse( Compiled_Config::load()->pending_discovery(), 'compile() with a server compiles.' );

		$this->assertSame( 0, $calls, 'The filter is only applied by the live-server recompile and the settings-save compile.' );
	}

	public function test_migration_and_site_initialisation_compile_a_pending_config(): void {
		delete_option( Names::OPTION_CONFIG );
		$this->fire( Hooks::MIGRATED, 0, 1 );
		$this->assertTrue( Compiled_Config::load()->pending_discovery() );

		delete_option( Names::OPTION_CONFIG );
		$this->fire( Hooks::SITE_INITIALIZED, 2 );
		$this->assertTrue( Compiled_Config::load()->pending_discovery() );
	}

	public function test_environment_changes_mark_the_config_stale(): void {
		$this->plugin->compiler()->compile( $this->new_rest_server() );
		foreach ( array( 'activated_plugin', 'deactivated_plugin', 'switch_theme', 'upgrader_process_complete' ) as $hook ) {
			$this->plugin->compiler()->compile( rest_get_server() );
			$this->assertNotSame( '', Compiled_Config::load()->fingerprint() );

			$this->fire( $hook, null, array() );

			$this->assertSame( '', Compiled_Config::load()->fingerprint(), "$hook marks the config stale" );
		}
	}

	public function test_rest_api_init_recompile_failure_is_swallowed_and_recorded(): void {
		$throwing = static function (): void {
			throw new RuntimeException( 'boom' );
		};
		add_filter( Hooks::UNIVERSAL_PARAMS, $throwing );
		$this->fire( 'rest_api_init', new WP_REST_Server() );

		$last = Failure_Recorder::last();
		$this->assertSame( 'compile', $last['context'] ?? null );
		$this->assertStringContainsString( 'boom', $last['message'] ?? '' );
	}

	public function test_a_recompile_that_throws_does_not_count_as_a_rebuild_of_the_outdated_snapshot(): void {
		$this->plugin->compiler()->compile();
		Request_State::record_outdated_snapshot();
		add_filter(
			Hooks::UNIVERSAL_PARAMS,
			static function (): void {
				throw new RuntimeException( 'boom' );
			}
		);

		$this->fire( 'rest_api_init', new WP_REST_Server() );

		$this->assertSame( 'compile', Failure_Recorder::last()['context'] ?? null );
		$this->assertTrue( Request_State::outdated_snapshot() );
		$this->assertFalse( Request_State::outdated_snapshot_rebuilt() );
	}

	public function test_a_partial_server_rebuilds_nothing_and_exempts_nothing(): void {
		$this->plugin->compiler()->compile();
		Request_State::record_outdated_snapshot();
		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );

		$this->fire( 'rest_api_init', new WP_REST_Server() );

		$this->assertTrue( Compiled_Config::load()->pending_discovery(), 'Nothing was rebuilt.' );
		$this->assertFalse( Request_State::outdated_snapshot_rebuilt() );
	}

	public function test_a_recompile_after_an_outdated_observation_records_the_rebuild(): void {
		$this->plugin->compiler()->compile();
		Request_State::record_outdated_snapshot();

		$this->fire( 'rest_api_init', new WP_REST_Server() );

		$this->assertFalse( Compiled_Config::load()->pending_discovery(), 'Recompiled.' );
		$this->assertTrue( Request_State::outdated_snapshot_rebuilt() );
	}

	public function test_a_recompile_without_an_outdated_observation_does_not_record_a_rebuild(): void {
		$this->plugin->compiler()->compile();

		$this->fire( 'rest_api_init', new WP_REST_Server() );

		$this->assertFalse( Compiled_Config::load()->pending_discovery(), 'Recompiled.' );
		$this->assertFalse( Request_State::outdated_snapshot_rebuilt(), 'No lookup saw the snapshot outdated, so nothing is exempt.' );
	}

	public function test_a_successful_recompile_clears_a_recorded_compile_failure(): void {
		$this->plugin->compiler()->compile();
		$this->assertTrue( Compiled_Config::load()->pending_discovery(), 'Precondition: a recompile is due.' );
		Failure_Recorder::record( 'compile', 'boom' );

		$this->fire( 'rest_api_init', new WP_REST_Server() );

		$this->assertFalse( Compiled_Config::load()->pending_discovery(), 'Recompiled.' );
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_a_current_config_keeps_a_recorded_compile_failure(): void {
		$this->plugin->compiler()->compile( $this->new_rest_server() );
		Failure_Recorder::record( 'compile', 'boom' );
		set_transient( Config_Compiler::CHECK_TRANSIENT, time(), HOUR_IN_SECONDS ); // No periodic check either.

		$this->fire( 'rest_api_init', new WP_REST_Server() );

		$this->assertSame( 'compile', Failure_Recorder::last()['context'] ?? null, 'Nothing was rebuilt, so nothing proves the compile works again.' );
	}

	public function test_a_successful_recompile_keeps_a_failure_of_another_context(): void {
		$this->plugin->compiler()->compile();
		Failure_Recorder::record( 'capture', 'boom', '/wp/v2/posts' );

		$this->fire( 'rest_api_init', new WP_REST_Server() );

		$this->assertSame( 'capture', Failure_Recorder::last()['context'] ?? null );
	}

	public function test_rest_api_init_does_not_rebuild_a_current_config(): void {
		$this->plugin->compiler()->compile( $this->new_rest_server() );
		$before = Compiled_Config::load()->fingerprint();

		// A new route appears, e.g. registered by a plugin that was just activated.
		$register = static function (): void {
			register_rest_route(
				'rfrac-new/v1',
				'/thing',
				array(
					'methods'             => 'GET',
					'callback'            => '__return_empty_array',
					'permission_callback' => '__return_true',
				)
			);
		};
		$server = $this->new_rest_server( $register ); // Fires the container's rest_api_init callback.
		$this->assertSame( $before, Compiled_Config::load()->fingerprint(), 'Not rebuilt on every REST request.' );

		$this->fire( 'activated_plugin', 'new/new.php', false );
		$this->fire( 'rest_api_init', $server );

		$keys = array_map( static fn( $route ) => $route->key, Compiled_Config::load()->routes() );
		$this->assertContains( '/rfrac-new/v1/thing', $keys, 'The activation made the next REST request recompile.' );
		$this->assertNotSame( '', Compiled_Config::load()->fingerprint() );
	}

	public function test_rest_api_init_passes_the_recorded_route_and_the_server_to_the_partial_server_filter(): void {
		$this->plugin->compiler()->compile();
		$this->assertTrue( Compiled_Config::load()->pending_discovery(), 'Precondition: a recompile is due.' );
		$seen = array();
		add_filter(
			Hooks::PARTIAL_SERVER,
			static function ( $partial, $server = null, $route = null ) use ( &$seen ): bool {
				$seen[] = array( $partial, $server, $route );
				return false;
			},
			10,
			3
		);

		$this->simulate( '/wp-json/wc/store/v1/products' );
		Request_State::record_lookup( Request_Context::from_globals( 'wp-json' ), \Rockfire\Rest_Api_Cache\Request\Policy_Decision::bypass( Bypass_Reason::ERROR ), null );
		$server = new WP_REST_Server();
		$this->fire( 'rest_api_init', $server );

		$this->assertCount( 1, $seen );
		$this->assertFalse( $seen[0][0] );
		$this->assertSame( $server, $seen[0][1] );
		$this->assertSame( '/wc/store/v1/products', $seen[0][2] );
		$this->assertFalse( Compiled_Config::load()->pending_discovery(), 'A filter that returns false does not skip.' );
	}

	public function test_rest_api_init_passes_an_empty_route_without_a_recorded_context(): void {
		$this->plugin->compiler()->compile();
		Request_State::reset();
		$seen = array();
		add_filter(
			Hooks::PARTIAL_SERVER,
			static function ( $partial, $server = null, $route = null ) use ( &$seen ): bool {
				$seen[] = $route;
				return false;
			},
			10,
			3
		);

		$this->fire( 'rest_api_init', new WP_REST_Server() );

		$this->assertSame( array( '' ), $seen );
	}

	public function test_a_partial_server_keeps_the_snapshot_and_a_recorded_failure(): void {
		$this->plugin->compiler()->compile();
		Failure_Recorder::record( 'compile', 'boom' );
		add_filter( Hooks::PARTIAL_SERVER, '__return_true' );

		$this->fire( 'rest_api_init', new WP_REST_Server() );

		$this->assertTrue( Compiled_Config::load()->pending_discovery(), 'The snapshot was not rebuilt from the partial server.' );
		$this->assertSame( 'compile', Failure_Recorder::last()['context'] ?? null, 'Nothing was rebuilt, so the failure stays.' );

		remove_filter( Hooks::PARTIAL_SERVER, '__return_true' );
		$this->fire( 'rest_api_init', new WP_REST_Server() );
		$this->assertFalse( Compiled_Config::load()->pending_discovery(), 'The next full-server request recompiles as before.' );
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_periodic_check_runs_only_for_anonymous_get_requests(): void {
		$server = $this->new_rest_server();
		$this->plugin->compiler()->compile( $server );
		add_filter( Hooks::ALLOW_SKIP_CACHE, '__return_true' );

		$this->simulate( '/wp-json/wp/v2/posts', array( 'REQUEST_METHOD' => 'POST' ) );
		Request_State::record_lookup( Request_Context::from_globals( 'wp-json' ), \Rockfire\Rest_Api_Cache\Request\Policy_Decision::bypass( Bypass_Reason::METHOD ), null );
		$this->fire( 'rest_api_init', $server );
		$this->assertFalse( Compiled_Config::load()->allow_skip_cache(), 'No periodic check on a POST.' );

		Request_State::reset();
		$this->simulate( '/wp-json/wp/v2/posts', array( 'REQUEST_METHOD' => 'GET' ) );
		Request_State::record_lookup( Request_Context::from_globals( 'wp-json' ), \Rockfire\Rest_Api_Cache\Request\Policy_Decision::bypass( Bypass_Reason::ERROR ), null );
		$this->fire( 'rest_api_init', $server );
		$this->assertTrue( Compiled_Config::load()->allow_skip_cache(), 'The periodic check of an anonymous GET picks up the filter change.' );
	}

	/**
	 * Whether the periodic fingerprint check ran for a GET request: a filter change after the compile is only picked
	 * up when it did.
	 *
	 * @param WP_REST_Server      $server        REST server.
	 * @param bool                $nonce_caching Whether the snapshot in force compiled nonce caching on.
	 * @param array<string,mixed> $request       Extra `$_SERVER` entries of the request.
	 *
	 * @return bool
	 */
	private function periodic_check_ran( WP_REST_Server $server, bool $nonce_caching, array $request ): bool {
		remove_all_filters( Hooks::ALLOW_SKIP_CACHE );
		remove_all_filters( Hooks::NONCE_CACHING );
		if ( $nonce_caching ) {
			add_filter( Hooks::NONCE_CACHING, '__return_true' );
		}
		$this->plugin->compiler()->compile( $server );
		$this->assertSame( $nonce_caching, Compiled_Config::load()->nonce_caching() );
		$this->assertFalse( Compiled_Config::load()->allow_skip_cache() );
		delete_transient( Config_Compiler::CHECK_TRANSIENT );
		add_filter( Hooks::ALLOW_SKIP_CACHE, '__return_true' );

		unset( $_SERVER['HTTP_X_WP_NONCE'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'] );
		Request_State::reset();
		$this->simulate( '/wp-json/wp/v2/posts', $request );
		Request_State::record_lookup( Request_Context::from_globals( 'wp-json' ), \Rockfire\Rest_Api_Cache\Request\Policy_Decision::bypass( Bypass_Reason::ERROR ), null );
		$this->fire( 'rest_api_init', $server );

		return Compiled_Config::load()->allow_skip_cache();
	}

	public function test_periodic_check_ignores_a_nonce_only_while_the_snapshot_has_nonce_caching_on(): void {
		$server = $this->new_rest_server();

		$this->assertTrue( $this->periodic_check_ran( $server, true, array( 'HTTP_X_WP_NONCE' => 'abc123' ) ), 'A nonce is no credential signal with nonce caching on.' );
		$this->assertFalse( $this->periodic_check_ran( $server, false, array( 'HTTP_X_WP_NONCE' => 'abc123' ) ), 'A nonce is a credential signal with nonce caching off.' );
		$this->assertTrue( $this->periodic_check_ran( $server, false, array() ), 'Control: an anonymous GET runs the periodic check.' );
		$this->assertFalse( $this->periodic_check_ran( $server, true, array( 'HTTP_X_WP_NONCE' => 'abc123', 'HTTP_AUTHORIZATION' => 'Bearer abc' ) ), 'Authorization never runs it.' );
		$this->assertFalse( $this->periodic_check_ran( $server, false, array( 'HTTP_AUTHORIZATION' => 'Bearer abc' ) ), 'Authorization never runs it.' );
		$this->assertFalse( $this->periodic_check_ran( $server, true, array( 'PHP_AUTH_USER' => 'admin' ) ), 'HTTP basic auth never runs it.' );
	}

	public function test_a_throwing_service_degrades_instead_of_breaking_the_site(): void {
		$plugin = new class() extends Plugin {
			public function capture(): Capture {
				throw new RuntimeException( 'constructor failed' );
			}
		};
		$plugin->register_engine_hooks();
		$callback = null;
		foreach ( $plugin->engine_hooks() as $hook ) {
			// The first `rest_post_dispatch` callback is Capture's (the tag collector registers one later).
			if ( 'rest_post_dispatch' === $hook['hook'] && null === $callback ) {
				$callback = $hook['callback'];
			}
		}
		$response = new WP_REST_Response( array( 'a' => 1 ) );

		$this->assertSame( $response, $callback( $response, rest_get_server(), new WP_REST_Request( 'GET', '/wp/v2/posts' ) ), 'The filter returns its first argument.' );
		$this->assertSame( 'hook:rest_post_dispatch', Failure_Recorder::last()['context'] ?? null );
	}

	public function test_store_change_flushes_both_stores(): void {
		$id = Rfrac_Entry_Fixtures::insert_entry();
		wp_cache_set( 'generation', 5, Names::CACHE_GROUP );

		do_action( Hooks::CONFIG_STORE_CHANGED, 'database', 'object_cache' );

		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
		$this->assertNotEquals( 5, wp_cache_get( 'generation', Names::CACHE_GROUP ), 'Object-cache generation bumped.' );
	}

	/**
	 * Store a POST of a JSON body to `/x/v1/q` through the live wiring, on a rule that caches only POST.
	 *
	 * @param string            $query  Query string.
	 * @param array<int,string> $ignore Ignored parameters of the rule.
	 *
	 * @return int Id of the stored entry.
	 */
	private function store_post_entry( string $query, array $ignore = array() ): int {
		global $wpdb;
		// A current snapshot (with a fingerprint), so the REST request does not rebuild it.
		$this->set_compiled_config(
			Rfrac_Serving_Harness::snapshot(
				array(),
				array(
					Rfrac_Serving_Harness::route(
						'/x/v1/q',
						array(
							'methods'       => array( 'POST' ),
							'param_mode'    => 'allow_all',
							'ignore_params' => $ignore,
						)
					),
				)
			)
		);
		$register = static function (): void {
			register_rest_route(
				'x/v1',
				'/q',
				array(
					'methods'             => 'POST',
					'callback'            => static fn(): array => array( 'results' => array( 1, 2 ) ),
					'permission_callback' => '__return_true',
				)
			);
		};
		$body = '{"q":"alpha"}';
		$this->simulate(
			'/wp-json/x/v1/q?' . $query,
			array(
				'REQUEST_METHOD' => 'POST',
				'CONTENT_TYPE'   => 'application/json',
				'CONTENT_LENGTH' => (string) strlen( $body ),
			)
		);
		$GLOBALS['HTTP_RAW_POST_DATA'] = $body;
		add_action( 'rest_api_init', $register );
		try {
			$server = $this->serve( '/x/v1/q' );
		} finally {
			remove_action( 'rest_api_init', $register );
			unset( $GLOBALS['HTTP_RAW_POST_DATA'] );
			Request_State::reset();
		}

		$this->assertSame( 'MISS', $server->sent_headers['X-Rfrac-Cache'] ?? null );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
		$row =$wpdb->get_row( $wpdb->prepare( 'SELECT id, method FROM %i', $wpdb->prefix . Names::TABLE_ENTRIES ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 'POST', $row['method'] ?? null );
		return (int) ( $row['id'] ?? 0 );
	}

	public function test_flush_url_flushes_a_stored_post_entry_of_a_post_only_rule(): void {
		$id = $this->store_post_entry( 'a=1' );

		$this->assertSame( 1, rfrac_flush_url( rest_url( 'x/v1/q?a=1' ) ) );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
	}

	public function test_flush_url_hashes_the_key_parameters_of_a_post_entry_as_the_capture_stored_them(): void {
		$id = $this->store_post_entry( 'a=1&b=2', array( 'a' ) );

		$this->assertSame( 0, rfrac_flush_url( rest_url( 'x/v1/q?b=3' ) ), 'Another value is another URL.' );
		$this->assertSame( 1, rfrac_flush_url( rest_url( 'x/v1/q?a=1&b=2' ) ), 'The ignored parameter is left out, as when the entry was stored.' );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $id ) );
	}

	public function test_drop_tables_filter_adds_the_plugin_tables(): void {
		global $wpdb;
		$tables = $this->fire( 'wpmu_drop_tables', array( 'x' ), 1 );
		$this->assertSame( array( 'x', $wpdb->get_blog_prefix( 1 ) . Names::TABLE_ENTRIES, $wpdb->get_blog_prefix( 1 ) . Names::TABLE_TAGS ), $tables );
		$this->assertSame( 'not-an-array', $this->fire( 'wpmu_drop_tables', 'not-an-array', 1 ) );
	}

	public function test_site_status_hooks_take_the_site_or_both_site_objects(): void {
		foreach ( array( 'archive_blog', 'make_spam_blog', 'deactivate_blog' ) as $hook ) {
			$hooks = $this->hooks_on( $hook );
			$this->assertCount( 1, $hooks, $hook );
			$this->assertSame( 1, $hooks[0]['args'], "$hook passes the site ID." );
		}
		$hooks = $this->hooks_on( 'wp_update_site' );
		$this->assertCount( 1, $hooks );
		$this->assertSame( 2, $hooks[0]['args'], 'wp_update_site passes the new and the old site.' );
	}

	public function test_site_status_hooks_are_inert_on_a_site_without_the_plugin_or_a_wrong_argument(): void {
		global $wpdb;
		delete_option( Failure_Recorder::OPTION );
		$recorded = array();
		add_action(
			'query',
			static function ( $query ) use ( &$recorded ) {
				$recorded[] = (string) $query;
				return $query;
			}
		);
		$this->fire( 'wp_update_site', 'not-a-site', 'not-a-site' );
		$this->fire( 'archive_blog', 0 );

		$this->assertSame( array(), array_filter( $recorded, static fn( string $q ): bool => false !== strpos( $q, $wpdb->prefix . Names::TABLE_ENTRIES ) ) );
		$this->assertFalse( get_option( Failure_Recorder::OPTION ), 'Nothing is recorded as a failure.' );
	}

	// ---------- Must-use file ----------

	/**
	 * Write an outdated copy built from the template at the target of the container's installer.
	 *
	 * @return string The written content.
	 */
	private function write_outdated_template_copy(): string {
		$installer = $this->plugin->mu_installer();
		$old       = (string) preg_replace( '/^(\s*\*\s*Version:\s+)\S+/m', '${1}0.0.1', (string) $installer->desired_content(), 1 );
		$this->assertNotSame( $installer->desired_content(), $old, 'The copy differs from the desired content.' );
		wp_mkdir_p( dirname( $installer->target_path() ) );
		file_put_contents( $installer->target_path(), $old );
		clearstatcache( true, $installer->target_path() );
		return $old;
	}

	public function test_admin_screens_never_write_the_mu_file(): void {
		$installer = $this->plugin->mu_installer();
		$target    = $installer->target_path();
		if ( file_exists( $target ) ) {
			unlink( $target );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		$this->assertTrue( Mu_Installer::current_user_can_install(), 'The user may install the file.' );
		update_option( Admin_Trigger::LAST_ATTEMPT_OPTION, time(), false ); // Keep the maintenance trigger quiet.

		$open_screens = function (): void {
			do_action( 'load-plugins.php' );
			$this->fire( Hooks::ADMIN_PAGE_LOAD, 'rfrac-tools' );
		};
		try {
			$this->assertSame( Mu_Status::MISSING, $installer->status() );
			$open_screens();
			$this->assertSame( Mu_Status::MISSING, $installer->status() );
			$this->assertFileDoesNotExist( $target, 'A missing file stays missing.' );

			$old = $this->write_outdated_template_copy();
			$this->assertSame( Mu_Status::OUTDATED, $installer->status() );
			$open_screens();
			$this->assertSame( Mu_Status::OUTDATED, $installer->status() );
			$this->assertSame( $old, file_get_contents( $target ), 'An outdated file is not rewritten.' );
		} finally {
			if ( file_exists( $target ) ) {
				unlink( $target );
			}
		}
	}

	public function test_no_repair_listener_remains(): void {
		global $wp_filter;

		$this->assertSame( array(), $this->hooks_on( 'load-plugins.php' ), 'The container has no callback on load-plugins.php.' );
		foreach ( isset( $wp_filter['load-plugins.php'] ) ? $wp_filter['load-plugins.php']->callbacks : array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( $callback['function'] instanceof \Closure ) {
					$this->assertNotInstanceOf( Plugin::class, ( new ReflectionFunction( $callback['function'] ) )->getClosureThis(), 'No plugin closure on load-plugins.php.' );
				}
			}
		}

		$listeners = $this->hooks_on( Hooks::ADMIN_PAGE_LOAD );
		$this->assertCount( 1, $listeners, 'Only the maintenance trigger listens to the admin page load.' );
		$this->assertContains( Hooks::ADMIN_PAGE_LOAD, array_column( Admin_Trigger::hooks(), 0 ) );

		$this->assertFalse( method_exists( Mu_Installer::class, 'maybe_repair' ) );
		$this->assertFalse( method_exists( Status_Service::class, 'maybe_repair_mu' ) );
	}

	public function test_must_use_file_is_written_on_activation_by_a_user_who_may_install_it(): void {
		$installer = $this->plugin->mu_installer();
		$target    = $installer->target_path();
		if ( file_exists( $target ) ) {
			unlink( $target );
		}
		try {
			if ( is_multisite() ) {
				$this->fire( Hooks::ACTIVATED );
				$this->assertSame( Mu_Status::MISSING, $installer->status(), 'User 0 leaves the file.' );

				wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
				$this->fire( Hooks::ACTIVATED );
				$this->assertSame( Mu_Status::MISSING, $installer->status(), 'A site administrator leaves the file.' );

				grant_super_admin( get_current_user_id() );
			}
			$this->fire( Hooks::ACTIVATED );
			$this->assertSame( Mu_Status::CURRENT, $installer->status() );
		} finally {
			if ( file_exists( $target ) ) {
				unlink( $target );
			}
		}
	}

	public function test_must_use_file_is_refreshed_after_this_plugin_was_updated(): void {
		$installer = $this->plugin->mu_installer();
		$target    = $installer->target_path();
		if ( file_exists( $target ) ) {
			unlink( $target );
		}
		$own = array(
			'type'    => 'plugin',
			'plugins' => array( $this->plugin->basename() ),
		);
		try {
			// A missing file stays missing, whoever updated what.
			$this->fire( 'upgrader_process_complete', null, array( 'type' => 'theme' ) );
			$this->fire( 'upgrader_process_complete', null, $own );
			$this->assertFileDoesNotExist( $target );

			// Another plugin or a theme leaves an outdated copy alone.
			$old = $this->write_outdated_template_copy();
			$this->fire( 'upgrader_process_complete', null, array( 'type' => 'theme' ) );
			$this->fire(
				'upgrader_process_complete',
				null,
				array(
					'type'    => 'plugin',
					'plugins' => array( 'other/other.php' ),
				)
			);
			$this->assertSame( $old, file_get_contents( $target ) );

			$this->fire( 'upgrader_process_complete', null, $own );
			$this->assertSame( Mu_Status::CURRENT, $installer->status() );

			// Single and automatic updates pass `plugin` instead of `plugins`.
			$this->write_outdated_template_copy();
			$this->fire(
				'upgrader_process_complete',
				null,
				array(
					'type'   => 'plugin',
					'action' => 'update',
					'plugin' => $this->plugin->basename(),
				)
			);
			$this->assertSame( Mu_Status::CURRENT, $installer->status() );

			// An outdated file that is not built from the template is left alone.
			file_put_contents( $target, "<?php // legacy loader\n" );
			$this->fire( 'upgrader_process_complete', null, $own );
			$this->assertSame( "<?php // legacy loader\n", file_get_contents( $target ) );
		} finally {
			if ( file_exists( $target ) ) {
				unlink( $target );
			}
		}
	}

	public function test_readme_names_only_the_three_write_moments(): void {
		$readme  = (string) file_get_contents( RFRAC_DIR . 'readme.txt' );
		$section = (string) strstr( (string) strstr( $readme, '**When it is written:**' ), '**When it is removed:**', true );

		$this->assertNotSame( '', $section, 'The readme has a "When it is written" paragraph.' );
		$this->assertStringContainsString( 'on activation', strtolower( $section ) );
		$this->assertStringContainsString( '"Install must-use file" button', $section );
		$this->assertStringContainsString( 'this plugin is updated', $section );
		$this->assertStringContainsString( 'outdated copy built from the plugin\'s template', $section );
		$this->assertStringContainsString( '`RFRAC Template:`', $section );
		$this->assertStringContainsString( 'exactly three moments', $section );

		$written = (string) strstr( (string) strstr( $section, '**When it is written:**' ), '**Notice:**', true );
		$this->assertNotSame( '', $written );
		foreach ( array( 'Plugins screen', 'admin page', 'is loaded', 'page load', 'load-plugins', 'admin_page_load', 'admin screen' ) as $moment ) {
			$this->assertStringNotContainsStringIgnoringCase( $moment, $written, "'$moment' is not a write moment." );
		}

		$notice = (string) strstr( $section, '**Notice:**' );
		$this->assertStringContainsString( 'missing or outdated', $notice );
		$this->assertStringContainsString( 'Plugins screen', $notice );

		$installation = (string) strstr( (string) strstr( $readme, '== Installation ==' ), '== Frequently Asked Questions ==', true );
		$this->assertStringContainsString( 'Install must-use file', $installation );
	}
}
