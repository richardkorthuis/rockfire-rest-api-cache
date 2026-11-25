<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @since 1.0.0
 *
 * @package    Rockfire_Rest_Api_Cache
 * @subpackage Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Config\Route_Discovery;
use Rockfire\Rest_Api_Cache\Health\Site_Health;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Install\Mu_Installer;
use Rockfire\Rest_Api_Cache\Invalidation\{Oembed_Tagger, Rest_Tag_Collector, Route_Tag_Map, Shape_Parser};
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Cache_Policy;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Schema\{Migrator, Schema, Site_Lifecycle};
use Rockfire\Rest_Api_Cache\Serving\Capture;
use Rockfire\Rest_Api_Cache\Serving\Fallback_Server;
use Rockfire\Rest_Api_Cache\Serving\Hit_Filters_Interface;
use Rockfire\Rest_Api_Cache\Serving\Lookup;
use Rockfire\Rest_Api_Cache\Serving\Request_State;
use Rockfire\Rest_Api_Cache\Serving\Response_Writer;
use Rockfire\Rest_Api_Cache\Serving\Tag_Provider_Interface;
use Rockfire\Rest_Api_Cache\Services\{Cache_Service, Plugin_Change, Route_Service, Settings_Service, Status_Service};
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * The core plugin class and the service container of the engine.
 *
 * Composition root: every engine service is reached through a lazy, typed getter that builds it on
 * first use and keeps it for the rest of the request (per container instance; the store per site).
 * Request state shared with the must-use phase lives in {@see Serving\Request_State}. Hooks are
 * registered by {@see self::register_hooks()}; engine callbacks are closures that resolve their
 * service when the hook fires, so nothing is built (and no query runs) before it is needed. Every
 * engine callback is guarded: a `\Throwable` (e.g. from a constructor) is recorded with
 * {@see Failure_Recorder} (context `hook:<name>`) and the callback returns its first argument
 * unchanged, so a broken service degrades to "not cached" instead of breaking the site.
 *
 * Reaching the container: the main plugin file calls {@see self::boot()} once; other code (invalidation, maintenance, admin pages, WP-CLI) uses {@see self::instance()}. Tests may create
 * an isolated container with `new Plugin()`, which registers no hooks.
 *
 * Adding a service: add a private nullable property, a typed getter that fills it with `??=`, and,
 * when it listens to hooks, register them in its own `register_*_hooks()` method called from
 * {@see self::register_engine_hooks()} via {@see self::on()}.
 *
 * @package    Rockfire_Rest_Api_Cache
 * @subpackage Rockfire_Rest_Api_Cache
 */
class Plugin {

	/**
	 * Default number of sites set up eagerly during a network activation.
	 *
	 * @var int
	 */
	public const NETWORK_ACTIVATION_BATCH = 100;

	/**
	 * The container booted by the main plugin file.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @access protected
	 * @var    string $plugin_name The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @access protected
	 * @var    string $version The current version of the plugin.
	 */
	protected $version;

	/**
	 * Engine hooks registered by this container.
	 *
	 * @var list<array{hook:string,callback:\Closure,priority:int,args:int}>
	 */
	private array $engine_hooks = [];

	/**
	 * Hooks registered by the legacy module of this container, apart from the engine hooks.
	 *
	 * @var list<array{hook:string,callback:\Closure,priority:int,args:int}>
	 */
	private array $legacy_hooks = [];

	/**
	 * Sites set up by `rfrac/activate_site` during the current activation.
	 *
	 * @var int
	 */
	private int $activated_sites = 0;

	/**
	 * Config compiler.
	 *
	 * @var Config_Compiler|null
	 */
	private ?Config_Compiler $compiler = null;

	/**
	 * Route discovery.
	 *
	 * @var Route_Discovery|null
	 */
	private ?Route_Discovery $route_discovery = null;

	/**
	 * The route service.
	 *
	 * @var Route_Service|null
	 */
	private ?Route_Service $route_service = null;

	/**
	 * The cache service.
	 *
	 * @var Cache_Service|null
	 */
	private ?Cache_Service $cache_service = null;

	/**
	 * The settings service.
	 *
	 * @var Settings_Service|null
	 */
	private ?Settings_Service $settings_service = null;

	/**
	 * The status service.
	 *
	 * @var Status_Service|null
	 */
	private ?Status_Service $status_service = null;

	/**
	 * The marker that another plugin was switched on or off.
	 *
	 * @var Plugin_Change|null
	 */
	private ?Plugin_Change $plugin_change = null;

	/**
	 * Site Health tests.
	 *
	 * @var Site_Health|null
	 */
	private ?Site_Health $site_health = null;

	/**
	 * Site Health test and debug section of the legacy layer.
	 *
	 * @var Legacy\Health|null
	 */
	private ?Legacy\Health $legacy_health = null;

	/**
	 * Findings of the legacy layer.
	 *
	 * @var Legacy\Findings|null
	 */
	private ?Legacy\Findings $legacy_findings = null;

	/**
	 * Entries repository.
	 *
	 * @var Repository|null
	 */
	private ?Repository $repository = null;

	/**
	 * Stores by blog ID.
	 *
	 * @var array<int,Store_Interface>
	 */
	private array $stores = [];

	/**
	 * Request policy.
	 *
	 * @var Cache_Policy|null
	 */
	private ?Cache_Policy $policy = null;

	/**
	 * Hit writer.
	 *
	 * @var Response_Writer|null
	 */
	private ?Response_Writer $writer = null;

	/**
	 * Hit filter set, once resolved (null when the site did not opt in).
	 *
	 * @var Hit_Filters_Interface|null
	 */
	private ?Hit_Filters_Interface $hit_filters = null;

	/**
	 * Whether {@see self::$hit_filters} was resolved; null is a valid result, so it needs its own flag.
	 *
	 * @var bool
	 */
	private bool $hit_filters_resolved = false;

	/**
	 * Lookup chain.
	 *
	 * @var Lookup|null
	 */
	private ?Lookup $lookup = null;

	/**
	 * Fallback server.
	 *
	 * @var Fallback_Server|null
	 */
	private ?Fallback_Server $fallback_server = null;

	/**
	 * Capture.
	 *
	 * @var Capture|null
	 */
	private ?Capture $capture = null;

	/**
	 * Tag collector.
	 *
	 * @var Rest_Tag_Collector|null
	 */
	private ?Rest_Tag_Collector $tag_provider = null;

	/**
	 * Migrator.
	 *
	 * @var Migrator|null
	 */
	private ?Migrator $migrator = null;

	/**
	 * Site lifecycle.
	 *
	 * @var Site_Lifecycle|null
	 */
	private ?Site_Lifecycle $site_lifecycle = null;

	/**
	 * Must-use installer.
	 *
	 * @var Mu_Installer|null
	 */
	private ?Mu_Installer $mu_installer = null;

	/**
	 * Buffered invalidator.
	 *
	 * @var Invalidation\Invalidator|null
	 */
	private ?Invalidation\Invalidator $invalidator = null;

	/**
	 * Invalidation relevance rules.
	 *
	 * @var Invalidation\Relevance|null
	 */
	private ?Invalidation\Relevance $relevance = null;

	/**
	 * Maintenance runner.
	 *
	 * @var Maintenance\Maintenance|null
	 */
	private ?Maintenance\Maintenance $maintenance = null;

	/**
	 * Invalidation event listeners and the maintenance admin trigger, by class.
	 *
	 * @var array<string,object>
	 */
	private array $listeners = [];

	/**
	 * Admin capability.
	 *
	 * @var Admin\Capability|null
	 */
	private ?Admin\Capability $capability = null;

	/**
	 * Admin flash notices.
	 *
	 * @var Admin\Notices|null
	 */
	private ?Admin\Notices $notices = null;

	/**
	 * The admin.
	 *
	 * @var Admin\Admin|null
	 */
	private ?Admin\Admin $admin = null;

	/**
	 * Importer of the settings of the original plugin.
	 *
	 * @var Legacy\Importer|null
	 */
	private ?Legacy\Importer $legacy_importer = null;

	/**
	 * The WooCommerce module; null until WooCommerce has loaded and the integrations are registered.
	 *
	 * @var Integrations\WooCommerce\Module|null
	 */
	private ?Integrations\WooCommerce\Module $woocommerce = null;

	/**
	 * The legacy hook-bridge module; null while the coexistence guard blocks or the legacy hooks are not registered.
	 *
	 * @var Legacy\Module|null
	 */
	private ?Legacy\Module $legacy = null;

	/**
	 * Whether the network-wide events of the WooCommerce module are wired on a request where WooCommerce did not load.
	 *
	 * @var bool
	 */
	private bool $network_integrations = false;

	/**
	 * Set the plugin name and version. Registers no hooks (see {@see self::register_hooks()}).
	 *
	 * @param Response_Writer|null $writer Hit writer; a seam for tests (default: the real writer).
	 */
	public function __construct( ?Response_Writer $writer = null ) {
		$this->plugin_name = 'rfrac';
		$this->version     = RFRAC_VERSION;
		$this->writer      = $writer;
	}

	/**
	 * Create the container and register its hooks; called once by the main plugin file.
	 *
	 * Also registers the class loader of the compatibility shims. A second call returns the booted container
	 * without registering anything again.
	 *
	 * @return Plugin
	 */
	public static function boot(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_hooks();
			self::$instance->register_public_api();
			Legacy\Shim_Loader::register();
		}
		return self::$instance;
	}

	/**
	 * Load the public `rfrac_*()` functions and hand them their services.
	 *
	 * Runs while the plugin file loads, so the functions exist before other plugins' `plugins_loaded` handlers.
	 *
	 * @return void
	 */
	private function register_public_api(): void {
		require_once RFRAC_DIR . 'includes/invalidation/functions.php';
		Invalidation\Api::register( new Invalidation\Api( $this->invalidator(), $this->tag_provider() ) );
	}

	/**
	 * The container booted by the main plugin file.
	 *
	 * @return Plugin
	 *
	 * @throws \LogicException When the plugin was not booted.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			throw new \LogicException( 'The plugin container is not booted.' );
		}
		return self::$instance;
	}

	/**
	 * Register every hook of the plugin: the admin, the coexistence guard, the engine and the legacy bridges.
	 *
	 * First it installs the legacy constants as the fallback below the `RFRAC_*` constants, so that every
	 * read of the settings sees them.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		Config::set_fallback_constants( new Legacy\Constant_Fallback() );
		$this->register_admin_hooks();
		$this->define_coexistence_hooks();
		$this->register_engine_hooks();
		$this->register_legacy_hooks();
		$this->register_cli_commands();
	}

	/**
	 * Register the `wp rfrac` commands; only when running under WP-CLI.
	 *
	 * @return void
	 */
	public function register_cli_commands(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		\WP_CLI::add_command(
			'rfrac',
			new Cli\Command(
				$this->cache_service(),
				$this->route_service(),
				$this->status_service(),
				new Cli\Network_Iterator( $this->basename() ),
				new Legacy\Import_Cli( $this->legacy_importer(), new Cli\Network_Iterator( $this->basename() ) )
			)
		);
		\WP_CLI::add_command( 'rfrac config', new Cli\Config_Command( $this->settings_service() ) );
	}

	// ---------------------------------------------------------------------------------------
	// Engine hooks.
	// ---------------------------------------------------------------------------------------

	/**
	 * Register the engine hooks: invalidation, serving, schema, config, must-use file, lifecycle and maintenance.
	 *
	 * Everything is registered when the plugin boots (also during an activation, which runs after
	 * `plugins_loaded`); services are resolved only when a hook fires. The integrations are wired on
	 * `plugins_loaded` (priority 10), or right away when that action has already fired.
	 *
	 * @return void
	 */
	public function register_engine_hooks(): void {
		$this->register_invalidation_hooks();
		$this->register_serving_hooks();
		$this->register_tag_hooks();
		$this->register_schema_hooks();
		$this->register_config_hooks();
		$this->register_mu_hooks();
		$this->register_lifecycle_hooks();
		$this->register_maintenance_hooks();
		if ( is_admin() || wp_doing_cron() ) {
			$this->register_health_hooks();
		}
		$this->on( 'plugins_loaded', fn() => $this->register_integrations(), 10, 0 );
		if ( did_action( 'plugins_loaded' ) > 0 ) {
			$this->register_integrations();
		}
	}

	/**
	 * Wire the integrations of the plugins that have loaded; only WooCommerce so far.
	 *
	 * Idempotent: a container wires an integration once. A call before the integration's plugin has
	 * loaded does nothing and does not count as a registration, so a later call still wires it. The one
	 * exception is a multisite request: the network admin loads the plugins of the main site only, so a
	 * network-wide plugin change must still reach the sites that run WooCommerce when the main site does not.
	 *
	 * @return void
	 */
	public function register_integrations(): void {
		if ( null !== $this->woocommerce ) {
			return;
		}
		if ( ! Integrations\WooCommerce\Module::is_active() ) {
			$this->register_network_integrations();
			return;
		}
		$this->woocommerce = $this->woocommerce_module();
		$this->woocommerce->register();
	}

	/**
	 * Wire the network-wide events of the WooCommerce module on a multisite request where WooCommerce did not load.
	 *
	 * Wired once per container; the container's module is not set, so {@see self::woocommerce()} stays null.
	 *
	 * @return void
	 */
	private function register_network_integrations(): void {
		if ( ! is_multisite() || $this->network_integrations ) {
			return;
		}
		$this->network_integrations = true;
		$this->woocommerce_module()->register_network_events();
	}

	/**
	 * A WooCommerce module wired into this container's engine hooks.
	 *
	 * @return Integrations\WooCommerce\Module
	 */
	private function woocommerce_module(): Integrations\WooCommerce\Module {
		return new Integrations\WooCommerce\Module(
			$this,
			fn( string $hook, \Closure $callback, int $priority = 10, int $args = 1 ) => $this->on( $hook, $callback, $priority, $args )
		);
	}

	/**
	 * The WooCommerce module.
	 *
	 * @return Integrations\WooCommerce\Module|null Null until WooCommerce has loaded and the integrations are registered.
	 */
	public function woocommerce(): ?Integrations\WooCommerce\Module {
		return $this->woocommerce;
	}

	/**
	 * The engine hooks registered by this container, in registration order.
	 *
	 * @return list<array{hook:string,callback:\Closure,priority:int,args:int}>
	 */
	public function engine_hooks(): array {
		return $this->engine_hooks;
	}

	/**
	 * Wire the legacy hook-bridge module, unless the original plugin is active.
	 *
	 * Idempotent: a container wires the module once. While the coexistence guard blocks, the original plugin
	 * answers its own hooks, so nothing is wired and {@see self::legacy()} stays null. The module adds its hooks
	 * through the guarded hook helper into {@see self::legacy_hooks()}, never into {@see self::engine_hooks()}.
	 *
	 * @return void
	 */
	public function register_legacy_hooks(): void {
		if ( null !== $this->legacy || Coexistence_Guard::is_blocked() ) {
			return;
		}
		$this->legacy = new Legacy\Module(
			$this,
			fn( string $hook, \Closure $callback, int $priority = 10, int $args = 1 ) => $this->on( $hook, $callback, $priority, $args, true )
		);
		$this->legacy->register();
	}

	/**
	 * The legacy hook-bridge module.
	 *
	 * @return Legacy\Module|null Null while the coexistence guard blocks or the legacy hooks are not registered.
	 */
	public function legacy(): ?Legacy\Module {
		return $this->legacy;
	}

	/**
	 * The hooks registered by the legacy module of this container, in registration order.
	 *
	 * @return list<array{hook:string,callback:\Closure,priority:int,args:int}>
	 */
	public function legacy_hooks(): array {
		return $this->legacy_hooks;
	}

	/**
	 * Commit invalidations at `shutdown` and before a REST response is echoed, and listen to content changes.
	 *
	 * Public so a test can register this group alone.
	 *
	 * @return void
	 */
	public function register_invalidation_hooks(): void {
		foreach ( [ Invalidation\Invalidator::class, Invalidation\Post_Events::class, Invalidation\Option_Events::class, Invalidation\Term_Events::class, Invalidation\User_Events::class, Invalidation\Comment_Events::class, Invalidation\Meta_Events::class ] as $listener_class ) {
			$this->subscribe( $listener_class );
		}
	}

	/**
	 * Run maintenance from cron and from the plugin's admin pages; (un)schedule it with the site lifecycle.
	 *
	 * The lifecycle callbacks are registered at boot, so they exist while the plugin is being activated.
	 * Public so a test can register this group alone.
	 *
	 * @return void
	 */
	public function register_maintenance_hooks(): void {
		$run = fn() => $this->maintenance()->run( [], 'cron' );
		$this->on( Maintenance\Scheduler::HOOK, $run, 10, 0 );
		$this->on( Maintenance\Scheduler::CONTINUE_HOOK, $run, 10, 0 );
		$this->subscribe( Maintenance\Admin_Trigger::class );

		$this->on( Hooks::ACTIVATE_SITE, static fn() => Maintenance\Scheduler::schedule(), 10, 0 );
		$this->on( Hooks::DEACTIVATE_SITE, static fn() => Maintenance\Scheduler::unschedule(), 10, 0 );
		$this->on(
			'wp_initialize_site',
			static function ( $site ): void {
				if ( $site instanceof \WP_Site ) {
					Maintenance\Scheduler::on_initialize_site( $site );
				}
			},
			1000
		);
	}

	/**
	 * Register the Site Health tests (admin and cron requests, so the scheduled health check runs them) and the debug information (admin requests only).
	 *
	 * Public so a test can register this group alone.
	 *
	 * @return void
	 */
	public function register_health_hooks(): void {
		$this->on( 'site_status_tests', fn( $tests ) => $this->legacy_health()->register_tests( $this->site_health()->register_tests( $tests ) ) );
		if ( is_admin() ) {
			$this->on( 'debug_information', fn( $info ) => $this->legacy_health()->debug_information( $this->site_health()->debug_information( $info ) ) );
		}
	}

	/**
	 * Site Health tests (lazy).
	 *
	 * @return Site_Health
	 */
	public function site_health(): Site_Health {
		return $this->site_health ??= new Site_Health( $this->status_service(), $this->route_service() );
	}

	/**
	 * Site Health test and debug section of the legacy layer (lazy).
	 *
	 * @return Legacy\Health
	 */
	public function legacy_health(): Legacy\Health {
		return $this->legacy_health ??= new Legacy\Health( $this->legacy_findings(), new Legacy\Constant_Fallback(), new Legacy\Detector() );
	}

	/**
	 * The findings of the legacy layer (lazy).
	 *
	 * @return Legacy\Findings
	 */
	public function legacy_findings(): Legacy\Findings {
		return $this->legacy_findings ??= new Legacy\Findings();
	}

	/**
	 * Register the hooks a listener declares ({@see Hook_Subscriber_Trait::hooks()}); the listener is
	 * resolved through {@see self::listener()} when one of them fires.
	 *
	 * @param string $listener_class Listener class.
	 *
	 * @phpstan-param class-string $listener_class
	 *
	 * @return void
	 */
	private function subscribe( string $listener_class ): void {
		foreach ( $listener_class::hooks() as $hook ) {
			$method = $hook[1];
			$bound  = $hook[4] ?? [];
			$this->on( $hook[0], fn( ...$params ) => $this->listener( $listener_class )->{$method}( ...$bound, ...$params ), $hook[2], $hook[3] );
		}
	}

	/**
	 * Serve hits from `plugins_loaded`, recompile once the routes are known and capture misses.
	 *
	 * @return void
	 */
	private function register_serving_hooks(): void {
		$this->on( 'plugins_loaded', fn() => $this->fallback_server()->maybe_serve(), 1, 0 );

		$this->on(
			'rest_api_init',
			function ( $server = null ): void {
				if ( ! $server instanceof \WP_REST_Server ) {
					return;
				}
				try {
					// Only a missing, pending or stale snapshot is rebuilt; the rate-limited periodic
					// fingerprint check runs only for anonymous GET requests.
					$context    = Request_State::context();
					$recompiled = $this->compiler()->maybe_recompile_for_server(
						$server,
						null !== $context && 'GET' === $context->method && $this->has_no_credential_signal( $context ) && 0 === get_current_user_id(),
						$context->route ?? ''
					);
					if ( $recompiled ) {
						// The recompile reports a rebuild even when its write took no effect (a failed write, a filter on the option, an
						// object cache serving the old one), so only a stored snapshot that is neither pending nor stale proves that the next
						// request's lookup can run. Otherwise this request's refusal stays a recorded failure; it would be every request's.
						$stored = Compiled_Config::load();
						if ( null !== $stored && ! $stored->pending_discovery() && '' !== $stored->fingerprint() ) {
							Request_State::record_snapshot_rebuilt(); // Counts only when this request's lookup ran on a pending or stale snapshot.
						}
						Failure_Recorder::clear( [ 'compile' ] ); // Only after a rebuild, so no option read on other requests.
					}
				} catch ( \Throwable $e ) {
					Failure_Recorder::record( 'compile', $e ); // The request is served uncached; the next one retries.
				}
			},
			PHP_INT_MAX
		);

		// Capture is resolved when the hook fires, i.e. never before the hit decision.
		$this->on( 'rest_exposed_cors_headers', fn( $headers, $request = null ) => $this->capture()->record_expose_headers( $headers, $request ), PHP_INT_MAX, 2 );
		$this->on( 'rest_allowed_cors_headers', fn( $headers, $request = null ) => $this->capture()->record_allow_headers( $headers, $request ), PHP_INT_MAX, 2 );
		$this->on( 'rest_post_dispatch', fn( $result, $server = null, $request = null ) => $this->capture()->on_post_dispatch( $result, $server, $request ), PHP_INT_MAX, 3 );
		$this->on( 'rest_pre_echo_response', fn( $data, $server = null, $request = null ) => $this->capture()->on_pre_echo( $data, $server, $request ), PHP_INT_MAX, 3 );
	}

	/**
	 * Whether a request carries no credential signal that bypasses the cache, i.e. whether it may run the periodic
	 * fingerprint check. The nonce carriers do not count while the loaded snapshot has nonce caching on; without a
	 * snapshot every signal counts.
	 *
	 * @param Request_Context $context Request.
	 *
	 * @return bool
	 */
	private function has_no_credential_signal( Request_Context $context ): bool {
		if ( [] === $context->signals ) {
			return true;
		}
		$snapshot = Compiled_Config::load();
		return [] === ( null === $snapshot ? $context->signals : Cache_Policy::credential_signals( $context, $snapshot ) );
	}

	/**
	 * Collect the tags of REST responses.
	 *
	 * The collector is resolved when a hook fires. {@see Rest_Tag_Collector::register()} adds the same hooks
	 * for standalone use; the `rest_prepare_*` filters are added by its `rest_api_init` callback.
	 *
	 * @return void
	 */
	private function register_tag_hooks(): void {
		$this->on( 'rest_authentication_errors', fn( $result = null ) => $this->tag_provider()->on_main_dispatch( $result ), 0, 1 );
		$this->on( 'rest_api_init', fn() => $this->tag_provider()->on_rest_api_init(), PHP_INT_MAX, 0 );
		$this->on( 'registered_post_type', fn( $post_type = null ) => $this->tag_provider()->on_registered_post_type( $post_type ), 10, 1 );
		$this->on( 'registered_taxonomy', fn( $taxonomy = null ) => $this->tag_provider()->on_registered_taxonomy( $taxonomy ), 10, 1 );
		$this->on( 'rest_request_before_callbacks', fn( $response, $handler = null, $request = null ) => $this->tag_provider()->on_before_callbacks( $response, $handler, $request ), 10, 3 );
		$this->on( 'rest_post_dispatch', fn( $result, $server = null, $request = null ) => $this->tag_provider()->on_post_dispatch( $result, $server, $request ), PHP_INT_MAX, 3 );
	}

	/**
	 * Migrate lazily, create the tables of new sites and drop them with deleted sites.
	 *
	 * @return void
	 */
	private function register_schema_hooks(): void {
		$this->on( 'init', fn() => $this->migrator()->maybe_migrate(), 1, 0 );

		$this->on(
			'wp_initialize_site',
			function ( $site ): void {
				if ( $site instanceof \WP_Site ) {
					$this->site_lifecycle()->on_initialize_site( $site );
				}
			},
			100
		);

		$this->on(
			'wpmu_drop_tables',
			fn( $tables, $site_id = 0 ) => is_array( $tables ) ? $this->site_lifecycle()->filter_drop_tables( $tables, (int) $site_id ) : $tables,
			10,
			2
		);

		// A site that is archived, marked as spam or deactivated has its cache flushed at once.
		foreach ( array( 'archive_blog', 'make_spam_blog', 'deactivate_blog' ) as $hook ) {
			$this->on( $hook, fn( $site_id = 0 ) => $this->site_lifecycle()->on_site_suspended( is_numeric( $site_id ) ? (int) $site_id : 0 ), 10, 1 );
		}
		$this->on(
			'wp_update_site',
			function ( $new_site = null, $old_site = null ): void {
				if ( $new_site instanceof \WP_Site && $old_site instanceof \WP_Site ) {
					$this->site_lifecycle()->on_site_updated( $new_site, $old_site );
				}
			},
			10,
			2
		);
	}

	/**
	 * Keep `rfrac_config` in step with the settings, the schema and the environment.
	 *
	 * @return void
	 */
	private function register_config_hooks(): void {
		$compile = fn() => $this->compiler()->compile();
		$this->on( Hooks::MIGRATED, $compile, 10, 0 );
		$this->on( Hooks::SITE_INITIALIZED, $compile, 10, 0 );

		// A partial discovery server (the `rfrac/partial_server` filter) leaves the snapshot serving, marked
		// stale and recorded as a clearable `compile` failure (the compiler does that), so the next request
		// with a full server rebuilds it with the saved settings and clears the failure.
		// A save made while another site is switched to has no REST server of that site (the global one belongs to
		// the origin site), so it only marks the switched site's snapshot stale and its next request rebuilds it.
		$recompile = function (): void {
			if ( is_multisite() && ms_is_switched() ) {
				$this->compiler()->mark_stale();
				return;
			}
			$this->compiler()->compile_unless_partial( Route_Discovery::server(), Request_State::context()->route ?? '' );
		};
		$this->on( 'add_option_' . Names::OPTION_SETTINGS, $recompile, 10, 0 );
		$this->on( 'update_option_' . Names::OPTION_SETTINGS, $recompile, 10, 0 );

		$mark_stale = fn() => $this->compiler()->mark_stale();
		foreach ( [ 'activated_plugin', 'deactivated_plugin', 'switch_theme', 'upgrader_process_complete' ] as $hook ) {
			$this->on( $hook, $mark_stale, 10, 0 );
		}

		// Any other plugin switched on or off, by any channel (admin, AJAX, WP-CLI), leaves a notice that offers a flush.
		$plugin_changed = fn( $plugin = null, $network_wide = false ) => $this->plugin_change()->on_plugin_changed( $plugin, $network_wide );
		$this->on( 'activated_plugin', $plugin_changed, 10, 2 );
		$this->on( 'deactivated_plugin', $plugin_changed, 10, 2 );

		$this->on(
			Hooks::CONFIG_STORE_CHANGED,
			function (): void {
				$this->stores = [];
				$this->flush_all_stores();
			},
			10,
			0
		);
	}

	/**
	 * Refresh an outdated must-use file after this plugin was updated (by any updater, including AJAX and automatic
	 * updates).
	 *
	 * That is the only must-use write left besides the activation and the install button: no admin screen writes
	 * the file, and a missing one stays missing until one of those moments.
	 *
	 * @return void
	 */
	private function register_mu_hooks(): void {
		$this->on(
			'upgrader_process_complete',
			function ( $upgrader = null, $extra = [] ): void {
				if ( is_array( $extra ) ) {
					$this->mu_installer()->maybe_repair_after_upgrade( $upgrader, $extra );
				}
			},
			10,
			2
		);
	}

	/**
	 * Set up and tear down sites on (de)activation.
	 *
	 * @return void
	 */
	private function register_lifecycle_hooks(): void {
		$this->on( Hooks::ACTIVATE_SITE, fn() => $this->activate_site(), 10, 0 );
		$this->on( Hooks::ACTIVATED, fn() => $this->activated(), 10, 0 );
		$this->on( Hooks::DEACTIVATE_SITE, fn() => $this->deactivate_site(), 10, 0 );
		$this->on( Hooks::DEACTIVATED, fn( $network_wide = false ) => $this->mu_installer()->remove( false, (bool) $network_wide ), 10, 1 );
	}

	/**
	 * Set up the current site (`rfrac/activate_site`): tables, flush of stale entries, pending config.
	 *
	 * During a network activation only the first `rfrac/network_activation_batch` sites are set up
	 * here; the others migrate lazily on their first `init`.
	 *
	 * @return void
	 */
	private function activate_site(): void {
		++$this->activated_sites;

		/**
		 * Filters the number of sites set up eagerly during a network activation.
		 *
		 * @see Hooks::NETWORK_ACTIVATION_BATCH
		 */
		$batch = (int) apply_filters( Hooks::NETWORK_ACTIVATION_BATCH, self::NETWORK_ACTIVATION_BATCH ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		if ( $this->activated_sites > max( 1, $batch ) ) {
			return;
		}

		$this->migrator()->install_site();
		unset( $this->stores[ get_current_blog_id() ] );
		$this->flush_all_stores();
		$this->compiler()->compile();
	}

	/**
	 * Finish an activation (`rfrac/activated`): install the must-use file.
	 *
	 * A legacy loader the installer could not replace is deleted ({@see Mu_Installer::ensure_installed()}).
	 * On multisite a user without `manage_network_plugins` (including WP-CLI, user 0) leaves the file
	 * - and the legacy-loader deletion - to the install button ({@see Mu_Installer::ensure_installed()}).
	 *
	 * @return void
	 */
	private function activated(): void {
		$this->activated_sites = 0;
		if ( is_multisite() && ! Mu_Installer::current_user_can_install() ) {
			return;
		}
		$this->mu_installer()->ensure_installed();
	}

	/**
	 * Tear down the current site (`rfrac/deactivate_site`): flush its entries; errors are suppressed.
	 *
	 * @return void
	 */
	private function deactivate_site(): void {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		try {
			$this->flush_all_stores();
		} catch ( \Throwable $e ) {
			unset( $e ); // Deactivation must never fail on a missing table.
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
	}

	/**
	 * Flush the current site on both stores: marks every entry flushed and bumps the object-cache generation.
	 *
	 * @return void
	 */
	private function flush_all_stores(): void {
		( new Storage\Database_Store( $this->repository(), 0 ) )->flush_all();
		( new Storage\Object_Cache_Store( $this->repository(), 0 ) )->flush_all();
	}

	/**
	 * Add a guarded engine hook and remember it.
	 *
	 * The registered callback runs `$callback`; a `\Throwable` is recorded and the first argument is
	 * returned unchanged (null for an action without arguments).
	 *
	 * @param string   $hook     Hook name.
	 * @param \Closure $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted arguments.
	 * @param bool     $legacy   Whether the hook belongs to the legacy module: it is remembered in {@see self::legacy_hooks()} instead of {@see self::engine_hooks()}.
	 *
	 * @return void
	 */
	private function on( string $hook, \Closure $callback, int $priority = 10, int $args = 1, bool $legacy = false ): void {
		$guarded = static function ( ...$params ) use ( $hook, $callback ) {
			try {
				return $callback( ...$params );
			} catch ( \Throwable $e ) {
				Failure_Recorder::record( 'hook:' . $hook, $e );
				return $params[0] ?? null;
			}
		};
		add_filter( $hook, $guarded, $priority, $args );
		$entry = [
			'hook'     => $hook,
			'callback' => $guarded,
			'priority' => $priority,
			'args'     => $args,
		];
		if ( $legacy ) {
			$this->legacy_hooks[] = $entry;
			return;
		}
		$this->engine_hooks[] = $entry;
	}

	// ---------------------------------------------------------------------------------------
	// Container getters.
	// ---------------------------------------------------------------------------------------

	/**
	 * Settings access (the shared {@see Config} instance).
	 *
	 * @return Config
	 */
	public function config(): Config {
		return Config::instance();
	}

	/**
	 * Loader of the compiled config; it reads the option on every call.
	 *
	 * @return \Closure
	 * @phpstan-return \Closure(): ?Compiled_Config
	 */
	public function compiled_config(): \Closure {
		return static fn(): ?Compiled_Config => Compiled_Config::load();
	}

	/**
	 * Config compiler.
	 *
	 * @return Config_Compiler
	 */
	public function compiler(): Config_Compiler {
		return $this->compiler ??= new Config_Compiler( $this->config() );
	}

	/**
	 * Route discovery.
	 *
	 * @return Route_Discovery
	 */
	public function route_discovery(): Route_Discovery {
		return $this->route_discovery ??= new Route_Discovery();
	}

	/**
	 * The route service.
	 *
	 * @return Route_Service
	 */
	public function route_service(): Route_Service {
		return $this->route_service ??= new Route_Service( $this->config(), $this->route_discovery() );
	}

	/**
	 * Entries repository.
	 *
	 * @return Repository
	 */
	public function repository(): Repository {
		return $this->repository ??= new Repository();
	}

	/**
	 * Store of the current site, for the mode of the compiled config (the settings before the first compile).
	 *
	 * Built once per site, so it follows `switch_to_blog()`.
	 *
	 * @return Store_Interface
	 */
	public function store(): Store_Interface {
		$blog_id = get_current_blog_id();
		if ( ! isset( $this->stores[ $blog_id ] ) ) {
			$compiled = Compiled_Config::load();
			if ( null !== $compiled ) {
				$mode     = $compiled->store_mode();
				$sampling = $compiled->hit_sampling();
			} else {
				$settings = $this->config()->settings();
				$mode     = (string) $settings['store'];
				$sampling = (int) $settings['hit_sampling'];
			}
			$this->stores[ $blog_id ] = Storage\Store_Factory::for_mode( $mode, $this->repository(), $sampling );
		}
		return $this->stores[ $blog_id ];
	}

	/**
	 * Request policy; caching is paused while the original WP REST Cache is active.
	 *
	 * @return Cache_Policy
	 */
	public function policy(): Cache_Policy {
		return $this->policy ??= new Cache_Policy( Coexistence_Guard::is_blocked( ... ) );
	}

	/**
	 * Cache key builder ({@see Cache_Key} is static).
	 *
	 * @return \Closure
	 * @phpstan-return \Closure(Request_Context, Policy_Decision): string
	 */
	public function cache_key(): \Closure {
		return static fn( Request_Context $context, Policy_Decision $decision ): string => Cache_Key::key( $context, $decision );
	}

	/**
	 * Hit writer.
	 *
	 * @return Response_Writer
	 */
	public function writer(): Response_Writer {
		return $this->writer ??= new Response_Writer();
	}

	/**
	 * Hit filter set shared by the lookup and the capture; null unless the site opted in.
	 *
	 * Resolved once per container.
	 *
	 * @return Hit_Filters_Interface|null
	 */
	public function hit_filters(): ?Hit_Filters_Interface {
		if ( ! $this->hit_filters_resolved ) {
			$this->hit_filters          = Lookup::hit_filters();
			$this->hit_filters_resolved = true;
		}
		return $this->hit_filters;
	}

	/**
	 * Lookup chain.
	 *
	 * @return Lookup
	 */
	public function lookup(): Lookup {
		return $this->lookup ??= new Lookup( $this->policy(), $this->repository(), $this->writer(), $this->cache_key(), null, $this->hit_filters() );
	}

	/**
	 * Fallback server (serves from `plugins_loaded` when the must-use file did not).
	 *
	 * @return Fallback_Server
	 */
	public function fallback_server(): Fallback_Server {
		return $this->fallback_server ??= new Fallback_Server( $this->lookup(), $this->compiled_config() );
	}

	/**
	 * Capture of misses.
	 *
	 * @return Capture
	 */
	public function capture(): Capture {
		return $this->capture ??= new Capture(
			$this->policy(),
			$this->repository(),
			$this->tag_provider(),
			$this->compiled_config(),
			static fn( Compiled_Config $config ): Request_Context => Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() ),
			$this->cache_key(),
			null,
			null,
			$this->hit_filters()
		);
	}

	/**
	 * Tag collector (the engine's {@see Tag_Provider_Interface}).
	 *
	 * @return Rest_Tag_Collector
	 */
	public function tag_provider(): Rest_Tag_Collector {
		return $this->tag_provider ??= new Rest_Tag_Collector( $this->config(), new Route_Tag_Map(), new Oembed_Tagger(), new Shape_Parser() );
	}

	/**
	 * Schema migrator.
	 *
	 * @return Migrator
	 */
	public function migrator(): Migrator {
		return $this->migrator ??= new Migrator();
	}

	/**
	 * Multisite site lifecycle.
	 *
	 * @return Site_Lifecycle
	 */
	public function site_lifecycle(): Site_Lifecycle {
		return $this->site_lifecycle ??= new Site_Lifecycle( $this->basename(), $this->migrator(), fn() => $this->flush_all_stores() );
	}

	/**
	 * Must-use file installer.
	 *
	 * @return Mu_Installer
	 */
	public function mu_installer(): Mu_Installer {
		return $this->mu_installer ??= new Mu_Installer( $this->basename(), RFRAC_DIR . 'mu-plugin/' . Names::MU_FILE, $this->version );
	}

	/**
	 * Buffered invalidator; flushes go to the store of the site they belong to ({@see self::store()}).
	 *
	 * @return Invalidation\Invalidator
	 */
	public function invalidator(): Invalidation\Invalidator {
		return $this->invalidator ??= new Invalidation\Invalidator(
			fn(): Store_Interface => $this->store(),
			function ( string $url ): array {
				$compiled = Compiled_Config::load();
				$hashes   = array();
				// Every form the request may have been stored under (as given, canonical, PATH_INFO).
				foreach ( Request_Context::url_variants( $url, null !== $compiled ? $compiled->rest_prefix() : rest_get_url_prefix(), null !== $compiled ? $compiled->vary_cookie_names() : array() ) as $context ) {
					if ( null === $context->route ) {
						continue;
					}
					// Hash what Capture stores: the key params after the policy dropped ignored and skip_cache params. The
					// URL hash is method-free and the method check is left out, so the entries of every method are reached.
					$params = $context->params;
					if ( null !== $compiled ) {
						$decision = $this->policy()->evaluate_for_url( $context, $compiled );
						if ( $decision->cacheable ) {
							$params = $decision->key_params;
						}
					}
					$hashes[] = Cache_Key::url_hash( $context, $params );
				}
				return array_values( array_unique( $hashes ) );
			}
		);
	}

	/**
	 * Invalidation relevance rules.
	 *
	 * @return Invalidation\Relevance
	 */
	public function relevance(): Invalidation\Relevance {
		return $this->relevance ??= new Invalidation\Relevance( $this->config() );
	}

	/**
	 * Maintenance runner; it prunes the store of the current site ({@see self::store()}).
	 *
	 * @return Maintenance\Maintenance
	 */
	public function maintenance(): Maintenance\Maintenance {
		return $this->maintenance ??= new Maintenance\Maintenance( $this->repository(), fn(): Store_Interface => $this->store(), $this->config(), new Maintenance\Lock() );
	}

	/**
	 * The cache service (list, inspect, flush, delete, stats, maintenance) for the admin pages and WP-CLI.
	 *
	 * @return Cache_Service
	 */
	public function cache_service(): Cache_Service {
		return $this->cache_service ??= new Cache_Service( $this->repository(), fn(): Store_Interface => $this->store(), $this->maintenance() );
	}

	/**
	 * The settings service (schema view and sanitising) for the Settings page and WP-CLI.
	 *
	 * @return Settings_Service
	 */
	public function settings_service(): Settings_Service {
		return $this->settings_service ??= new Settings_Service( $this->config() );
	}

	/**
	 * The status service (status snapshot, must-use file actions) for the Tools page, WP-CLI and Site Health.
	 *
	 * @return Status_Service
	 */
	public function status_service(): Status_Service {
		return $this->status_service ??= new Status_Service( $this->config(), fn(): Store_Interface => $this->store(), new Schema(), $this->mu_installer(), $this->cache_service() );
	}

	/**
	 * The marker that another plugin was switched on or off, for the engine hooks and the admin notice.
	 *
	 * @return Plugin_Change
	 */
	public function plugin_change(): Plugin_Change {
		return $this->plugin_change ??= new Plugin_Change( $this->repository(), $this->basename() );
	}

	/**
	 * The importer of the settings of the original plugin; it reads the routes through `Route_Discovery::server()`.
	 *
	 * @return Legacy\Importer
	 */
	public function legacy_importer(): Legacy\Importer {
		return $this->legacy_importer ??= new Legacy\Importer( $this->config(), new Legacy\Findings(), new Legacy\Original_Settings() );
	}

	/**
	 * A hook listener of the engine: the invalidator, an invalidation event listener or the maintenance admin trigger.
	 *
	 * @param string $listener_class Listener class.
	 *
	 * @phpstan-template T of object
	 * @phpstan-param class-string<T> $listener_class
	 * @phpstan-return T
	 *
	 * @return object
	 *
	 * @throws \InvalidArgumentException For a class that is not an engine listener.
	 */
	public function listener( string $listener_class ): object {
		if ( Invalidation\Invalidator::class === $listener_class ) {
			return $this->invalidator();
		}
		$listener = $this->listeners[ $listener_class ] ??= match ( $listener_class ) {
			Invalidation\Post_Events::class    => new Invalidation\Post_Events( $this->invalidator(), $this->relevance() ),
			Invalidation\Option_Events::class  => new Invalidation\Option_Events( $this->invalidator() ),
			Invalidation\Term_Events::class    => new Invalidation\Term_Events( $this->invalidator(), $this->relevance(), new Invalidation\Term_Ancestry() ),
			Invalidation\User_Events::class    => new Invalidation\User_Events( $this->invalidator() ),
			Invalidation\Comment_Events::class => new Invalidation\Comment_Events( $this->invalidator() ),
			Invalidation\Meta_Events::class    => new Invalidation\Meta_Events( $this->invalidator(), $this->relevance() ),
			Maintenance\Admin_Trigger::class   => new Maintenance\Admin_Trigger( $this->maintenance() ),
			default                            => throw new \InvalidArgumentException( sprintf( 'Not an engine listener: %s', esc_html( $listener_class ) ) ),
		};
		return $listener;
	}

	/**
	 * Plugin basename, e.g. `rockfire-rest-api-cache/rockfire-rest-api-cache.php`.
	 *
	 * @return string
	 */
	public function basename(): string {
		return plugin_basename( RFRAC_FILE );
	}

	// ---------------------------------------------------------------------------------------
	// Admin.
	// ---------------------------------------------------------------------------------------

	/**
	 * Register the admin-bar flush (front end and admin) and, in wp-admin, the admin ({@see Admin\Admin::register()},
	 * which hooks nothing during AJAX requests).
	 *
	 * Also hooks the import notice of the legacy layer on `admin_notices` of the site admin, except during AJAX requests.
	 *
	 * Admin callbacks are not guarded by {@see self::on()}: they must be able to end the request
	 * (`wp_die`, redirect + exit).
	 *
	 * @return void
	 */
	public function register_admin_hooks(): void {
		( new Admin\Admin_Bar( $this->capability() ) )->register();
		if ( is_admin() ) {
			$this->admin()->register();
			if ( ! wp_doing_ajax() ) {
				add_action( 'admin_notices', array( new Legacy\Import_Notice( $this->capability(), $this->legacy_findings() ), 'render' ) );
			}
		}
	}

	/**
	 * The admin capability.
	 *
	 * @return Admin\Capability
	 */
	public function capability(): Admin\Capability {
		return $this->capability ??= new Admin\Capability();
	}

	/**
	 * The flash notices of the admin.
	 *
	 * @return Admin\Notices
	 */
	public function notices(): Admin\Notices {
		return $this->notices ??= new Admin\Notices();
	}

	/**
	 * The admin pages, in any order (the admin sorts them by position).
	 *
	 * Adding a page: construct it here with the services it needs, e.g.
	 * `new Admin\Pages\Tools_Page( $this->notices(), $this->cache_service(), ... )`.
	 *
	 * The Import page of the legacy layer is only added while WP REST Cache or something it left is present, or a
	 * finding is stored.
	 *
	 * @return Admin\Pages\Page_Interface[]
	 * @phpstan-return list<Admin\Pages\Page_Interface>
	 */
	public function admin_pages(): array {
		$pages = array(
			new Admin\Pages\Routes_Page( $this->notices(), $this->route_service(), $this->cache_service(), $this->settings_service() ),
			new Admin\Pages\Caches_Page( $this->notices(), $this->cache_service(), $this->route_service(), $this->settings_service() ),
			new Admin\Pages\Settings_Page( $this->settings_service(), $this->status_service(), $this->capability() ),
			new Admin\Pages\Tools_Page( $this->notices(), $this->cache_service(), $this->status_service(), $this->route_service() ),
		);

		// The Import page exists only while WP REST Cache or something it left is present, or a finding is stored.
		$import = new Legacy\Import_Page( $this->notices(), $this->legacy_importer(), $this->legacy_findings() );
		if ( $import->available() ) {
			$pages[] = $import;
		}

		return $pages;
	}

	/**
	 * The admin.
	 *
	 * @return Admin\Admin
	 */
	public function admin(): Admin\Admin {
		return $this->admin ??= new Admin\Admin( $this->capability(), $this->notices(), $this->status_service(), $this->cache_service(), $this->admin_pages(), $this->plugin_change() );
	}

	// ---------------------------------------------------------------------------------------
	// Coexistence guard.
	// ---------------------------------------------------------------------------------------

	/**
	 * Register the coexistence guard hooks (pause caching while WP REST Cache is active).
	 *
	 * The engine consumes {@see Coexistence_Guard::is_blocked()} through {@see self::policy()}. The episode count of
	 * the notice follows `activated_plugin` in every context (WP-CLI, cron, REST, AJAX, wp-admin), so an activation
	 * outside wp-admin brings a dismissed notice back. The notice that caching is paused and its dismiss action are
	 * hooked in wp-admin only, never during AJAX requests.
	 *
	 * @return void
	 */
	private function define_coexistence_hooks() {
		add_action( 'activated_plugin', array( Admin\Coexistence_Notice::class, 'on_plugin_activated' ), 10, 2 );

		if ( is_admin() && ! wp_doing_ajax() ) {
			( new Admin\Coexistence_Notice( $this->capability() ) )->register();
		}
	}

	// ---------------------------------------------------------------------------------------
	// Identity.
	// ---------------------------------------------------------------------------------------

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @return string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @return string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}
