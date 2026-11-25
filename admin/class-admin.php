<?php
/**
 * The admin of the plugin: menu, pages, actions, notices and assets.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Admin;

use Rockfire\Rest_Api_Cache\Admin\Pages\Page_Interface;
use Rockfire\Rest_Api_Cache\Admin\Pages\Settings_Page;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Install\Mu_Installer;
use Rockfire\Rest_Api_Cache\Install\Mu_Status;
use Rockfire\Rest_Api_Cache\Services\Cache_Service;
use Rockfire\Rest_Api_Cache\Services\Plugin_Change;
use Rockfire\Rest_Api_Cache\Services\Status_Service;
use Rockfire\Rest_Api_Cache\Storage\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top-level "REST API Cache" menu with one submenu per page, the `admin-post.php`
 * actions of the pages plus the global `flush_all`, `dismiss_error` and `dismiss_plugin_change`, the flash, status,
 * must-use and plugin-change notices, the assets and the plugin action link.
 *
 * Nothing is hooked during AJAX requests; `admin_init` only registers the Settings API setting of the
 * Settings page. On a page's `load-{hook}`: capability check →
 * `rfrac/admin_page_load` → {@see Page_Interface::load()}. Screens are recognised by the hook
 * suffixes WordPress returned when the pages were added.
 *
 * @since 1.0.0
 */
class Admin {

	/**
	 * Slug of the Tools page (the fallback redirect of `flush_all`).
	 *
	 * @var string
	 */
	public const TOOLS_SLUG = 'rfrac-tools';

	/**
	 * Recorded failures younger than this many seconds are shown on the plugin pages.
	 *
	 * @var int
	 */
	public const LAST_ERROR_MAX_AGE = DAY_IN_SECONDS;

	/**
	 * Action (without the `rfrac_` prefix) that dismisses the recorded failure.
	 *
	 * @var string
	 */
	public const DISMISS_ERROR_ACTION = 'dismiss_error';

	/**
	 * Action (without the `rfrac_` prefix) that dismisses the plugin-change notice.
	 *
	 * @var string
	 */
	public const DISMISS_PLUGIN_CHANGE_ACTION = 'dismiss_plugin_change';

	/**
	 * Capability.
	 *
	 * @var Capability
	 */
	private Capability $capability;

	/**
	 * Flash notices.
	 *
	 * @var Notices
	 */
	private Notices $notices;

	/**
	 * Status service.
	 *
	 * @var Status_Service
	 */
	private Status_Service $status_service;

	/**
	 * Cache service.
	 *
	 * @var Cache_Service
	 */
	private Cache_Service $cache_service;

	/**
	 * The marker that another plugin was switched on or off.
	 *
	 * @var Plugin_Change
	 */
	private Plugin_Change $plugin_change;

	/**
	 * Pages, sorted by position.
	 *
	 * @var list<Page_Interface>
	 */
	private array $pages;

	/**
	 * Pages by the hook suffix WordPress returned for them.
	 *
	 * @var array<string,Page_Interface>
	 */
	private array $hook_suffixes = array();

	/**
	 * The plugin page being loaded in this request, set on its `load-{hook}`.
	 *
	 * @var Page_Interface|null
	 */
	private ?Page_Interface $current_page = null;

	/**
	 * Constructor.
	 *
	 * @param Capability         $capability     Capability.
	 * @param Notices            $notices        Flash notices.
	 * @param Status_Service     $status_service Status service.
	 * @param Cache_Service      $cache_service  Cache service.
	 * @param Page_Interface[]   $pages          Pages (any order; sorted by position).
	 * @phpstan-param list<Page_Interface> $pages
	 * @param Plugin_Change|null $plugin_change  Plugin-change marker; default: the one of this plugin on the current site.
	 */
	public function __construct( Capability $capability, Notices $notices, Status_Service $status_service, Cache_Service $cache_service, array $pages, ?Plugin_Change $plugin_change = null ) {
		$this->capability     = $capability;
		$this->notices        = $notices;
		$this->status_service = $status_service;
		$this->cache_service  = $cache_service;
		$this->plugin_change  = $plugin_change ?? new Plugin_Change( new Repository(), plugin_basename( RFRAC_FILE ) );

		usort( $pages, static fn( Page_Interface $a, Page_Interface $b ): int => $a->position() <=> $b->position() );
		$this->pages = $pages;
	}

	/**
	 * Register the admin hooks; does nothing during an AJAX request.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_notices', array( $this, 'render_mu_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_plugin_change_notice' ) );
		add_action( 'network_admin_notices', array( $this->notices, 'render' ) );
		add_action( 'network_admin_notices', array( $this, 'render_mu_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RFRAC_FILE ), array( $this, 'plugin_action_links' ) );

		foreach ( $this->action_handlers() as $handler ) {
			$handler->register();
		}

		// The Settings API needs its setting registered on admin_init (also for options.php), never during AJAX.
		foreach ( $this->pages as $page ) {
			if ( $page instanceof Settings_Page ) {
				add_action( 'admin_init', array( $page, 'register_settings' ) );
			}
		}
	}

	/**
	 * The pages, sorted by position.
	 *
	 * @return list<Page_Interface>
	 */
	public function pages(): array {
		return $this->pages;
	}

	/**
	 * Hook suffixes of the registered pages (filled on `admin_menu`).
	 *
	 * @return array<string,Page_Interface>
	 */
	public function hook_suffixes(): array {
		return $this->hook_suffixes;
	}

	/**
	 * The action handlers: the global `flush_all`, `dismiss_error` and `dismiss_plugin_change` and the actions of every page.
	 *
	 * @return array<string,Action_Handler> Action name => handler.
	 *
	 * @throws \LogicException When two pages declare the same action.
	 */
	public function action_handlers(): array {
		$handlers = array(
			'flush_all'                        => new Action_Handler( 'flush_all', array( $this, 'flush_all' ), $this->capability, false ),
			self::DISMISS_ERROR_ACTION         => new Action_Handler( self::DISMISS_ERROR_ACTION, array( $this, 'dismiss_error' ), $this->capability, false ),
			self::DISMISS_PLUGIN_CHANGE_ACTION => new Action_Handler( self::DISMISS_PLUGIN_CHANGE_ACTION, array( $this, 'dismiss_plugin_change' ), $this->capability, false ),
		);
		foreach ( $this->pages as $page ) {
			foreach ( $page->actions() as $name => $definition ) {
				if ( isset( $handlers[ $name ] ) ) {
					throw new \LogicException( sprintf( 'Duplicate admin action: %s', esc_html( $name ) ) );
				}
				$per_target = true;
				$callback   = $definition;
				if ( is_array( $definition ) && isset( $definition['callback'] ) ) {
					$callback   = $definition['callback'];
					$per_target = (bool) ( $definition['per_target_nonce'] ?? true );
				}
				if ( is_callable( $callback ) ) {
					$handlers[ $name ] = new Action_Handler( $name, $callback, $this->capability, $per_target );
				}
			}
		}
		return $handlers;
	}

	/**
	 * Add the top-level menu and a submenu per page (`admin_menu`).
	 *
	 * @return void
	 */
	public function register_menu(): void {
		if ( array() === $this->pages || ! $this->capability->current_user_can() ) {
			return;
		}
		$capability = $this->capability->get();
		$parent     = $this->pages[0]->slug();

		// The submenu entry of the first page renders it; the top-level item itself has no callback.
		add_menu_page(
			__( 'REST API Cache', 'rockfire-rest-api-cache' ),
			__( 'REST API Cache', 'rockfire-rest-api-cache' ),
			$capability,
			$parent,
			'',
			'dashicons-performance'
		);

		foreach ( $this->pages as $page ) {
			$hook_suffix = add_submenu_page(
				$parent,
				$page->page_title(),
				$page->menu_title(),
				$capability,
				$page->slug(),
				function () use ( $page ): void {
					$this->render( $page );
				}
			);
			if ( ! is_string( $hook_suffix ) || '' === $hook_suffix ) {
				continue;
			}
			$this->hook_suffixes[ $hook_suffix ] = $page;
			add_action(
				'load-' . $hook_suffix,
				function () use ( $page ): void {
					$this->load( $page );
				}
			);
		}
	}

	/**
	 * Load a page (`load-{hook}`): capability → `rfrac/admin_page_load` → the page's load().
	 *
	 * @param Page_Interface $page Page.
	 *
	 * @return void
	 */
	public function load( Page_Interface $page ): void {
		$this->capability->require();
		$this->current_page = $page;

		/**
		 * Fires when a plugin admin page loads, after the capability check.
		 *
		 * @see Hooks::ADMIN_PAGE_LOAD
		 */
		do_action( Hooks::ADMIN_PAGE_LOAD, $page->slug() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.

		$page->load();
	}

	/**
	 * Render a page inside the `wrap`; re-checks the capability.
	 *
	 * @param Page_Interface $page Page.
	 *
	 * @return void
	 */
	public function render( Page_Interface $page ): void {
		$this->capability->require();
		echo '<div class="wrap rfrac-wrap">';
		printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html( $page->page_title() ) );
		echo '<hr class="wp-header-end" />';
		$page->render();
		echo '</div>';
	}

	/**
	 * Enqueue the admin assets on the plugin's own pages only (`admin_enqueue_scripts`).
	 *
	 * @param string $hook_suffix Hook suffix of the current screen.
	 *
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix = '' ): void {
		if ( ! isset( $this->hook_suffixes[ $hook_suffix ] ) ) {
			return;
		}
		wp_enqueue_style( 'rfrac-admin', plugins_url( 'admin/assets/admin.css', RFRAC_FILE ), array(), RFRAC_VERSION );
		wp_enqueue_script(
			'rfrac-admin',
			plugins_url( 'admin/assets/admin.js', RFRAC_FILE ),
			array(),
			RFRAC_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * Print the flash notices of the current user and, on the plugin's pages, the status notices (`admin_notices`).
	 *
	 * @return void
	 */
	public function render_notices(): void {
		$this->notices->render();
		if ( null !== $this->current_page && $this->capability->current_user_can() ) {
			$this->render_status_notices();
		}
	}

	/**
	 * Print the failure state recorded by the engine: a migration error and a recent recorded failure.
	 *
	 * The must-use file notice has its own hook: {@see self::render_mu_notice()}.
	 *
	 * @return void
	 */
	public function render_status_notices(): void {
		$status = $this->status_service->get();

		if ( null !== $status->migration_error ) {
			Notices::output(
				'error',
				/* translators: %s: error message. */
				sprintf( __( 'REST API Cache could not update its database tables; caching is paused. Error: %s', 'rockfire-rest-api-cache' ), $status->migration_error )
			);
		}

		$error = $status->last_error;
		if ( null !== $error && time() - (int) $error['time'] < self::LAST_ERROR_MAX_AGE ) {
			// Not `is-dismissible`: hiding it in the browser only would bring it back on the next page.
			printf(
				'<div class="notice notice-warning rfrac-notice"><p>%1$s</p><p><a href="%2$s">%3$s</a></p></div>',
				esc_html(
					sprintf(
						/* translators: 1: date and time, 2: where it failed, 3: error message. */
						__( 'REST API Cache recorded an error on %1$s (%2$s): %3$s', 'rockfire-rest-api-cache' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $error['time'] ),
						$error['context'],
						$error['message']
					)
				),
				esc_url( Admin_Url::action( self::DISMISS_ERROR_ACTION ) ),
				esc_html__( 'Dismiss this error', 'rockfire-rest-api-cache' )
			);
		}
	}

	/**
	 * Print the notice that the must-use file is missing or outdated, with an "Install must-use file" button (`admin_notices`, `network_admin_notices`).
	 *
	 * Shown on the Plugins screen and on the plugin's own pages ({@see Notice_Screen::matches()}) to a user who may
	 * install the file ({@see Mu_Installer::current_user_can_install()}, on a single site also the plugin capability)
	 * while the file is missing or outdated. The button posts `install_mu`, the only way the notice writes anything;
	 * where the installer cannot write the file from here ({@see Mu_Installer::install_blocker()}: file changes are
	 * disallowed, the directory is not writable, the filesystem method is not `direct`, ...) the notice gives the
	 * reason and the manual instructions instead. Printing it installs nothing.
	 *
	 * @return void
	 */
	public function render_mu_notice(): void {
		if ( ! Notice_Screen::matches() || ! Mu_Installer::current_user_can_install() ) {
			return;
		}
		if ( ! is_multisite() && ! $this->capability->current_user_can() ) {
			return;
		}

		// The cheap check first: the full status below counts the cache entries.
		$mu_status = $this->status_service->mu_status();
		if ( Mu_Status::MISSING !== $mu_status && Mu_Status::OUTDATED !== $mu_status ) {
			return;
		}

		$status       = $this->status_service->get();
		$code         = $status->mu_last_result['code'] ?? '';
		$blocker      = $this->status_service->mu_install_blocker();
		$can_install  = null === $blocker;
		$last_attempt = Mu_Help::reason( $code );

		echo '<div class="notice notice-warning rfrac-notice rfrac-mu-notice">';
		printf( '<p>%s</p>', esc_html__( 'REST API Cache runs in fallback mode: the must-use file for early caching is not installed or is outdated. Caching still works, but cache hits are served later in the request.', 'rockfire-rest-api-cache' ) );

		if ( null !== $blocker ) {
			printf( '<p>%s</p>', esc_html( Mu_Help::reason( $blocker ) ) );
		} elseif ( '' !== $last_attempt ) {
			/* translators: %s: reason, a full sentence. */
			printf( '<p>%s</p>', esc_html( sprintf( __( 'The last install attempt failed: %s', 'rockfire-rest-api-cache' ), $last_attempt ) ) );
		}

		if ( $can_install ) {
			echo '<div class="rfrac-mu-notice-actions">';
			self::notice_form( 'install_mu', __( 'Install must-use file', 'rockfire-rest-api-cache' ), 'button button-primary' );
			echo '</div>';
		}

		if ( ! $can_install || Mu_Help::needs_manual_install( $code ) ) {
			Mu_Help::render_manual_instructions( $status->mu_template_path, $status->mu_target_path );
		}
		if ( 'not_writable' === $blocker ) {
			printf( '<p>%s</p>', esc_html__( 'Or make the directory writable, reload this page and click Install must-use file.', 'rockfire-rest-api-cache' ) );
		}
		echo '</div>';
	}

	/**
	 * Add a link to the landing page of the plugin to its row on the Plugins screen.
	 *
	 * @param mixed $links Plugin action links.
	 *
	 * @return mixed
	 */
	public function plugin_action_links( $links ) {
		if ( ! is_array( $links ) || array() === $this->pages || ! $this->capability->current_user_can() ) {
			return $links;
		}
		array_unshift(
			$links,
			sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( Admin_Url::page( $this->pages[0]->slug() ) ),
				esc_html__( 'Settings', 'rockfire-rest-api-cache' )
			)
		);
		return $links;
	}

	/**
	 * Print the notice that another plugin was switched on or off, with Flush all and Dismiss (`admin_notices`).
	 *
	 * Shown to users with the plugin capability while the marker is pending, on the Plugins screen and on the plugin's
	 * own pages ({@see Notice_Screen::matches()}); any full flush clears it. Writes nothing.
	 *
	 * @return void
	 */
	public function render_plugin_change_notice(): void {
		if ( ! Notice_Screen::matches() || ! $this->capability->current_user_can() || ! $this->plugin_change->pending() ) {
			return;
		}

		echo '<div class="notice notice-warning rfrac-notice rfrac-plugin-change-notice">';
		printf(
			'<p>%s</p>',
			esc_html__( 'A plugin was switched on or off. Cached responses may be outdated. Flush all clears them and removes this notice.', 'rockfire-rest-api-cache' )
		);
		echo '<div class="rfrac-plugin-change-notice-actions">';
		self::notice_form( 'flush_all', __( 'Flush all', 'rockfire-rest-api-cache' ), 'button button-primary' );
		self::notice_form( self::DISMISS_PLUGIN_CHANGE_ACTION, __( 'Dismiss', 'rockfire-rest-api-cache' ), 'button button-link' );
		echo '</div></div>';
	}

	/**
	 * Print a one-button form of an admin action.
	 *
	 * @param string $action  Action name without the `rfrac_` prefix.
	 * @param string $label   Translated button label.
	 * @param string $classes Button classes.
	 *
	 * @return void
	 */
	private static function notice_form( string $action, string $label, string $classes ): void {
		printf( '<form method="post" action="%s" style="display:inline-block;margin:0 8px 0 0;">', esc_url( admin_url( 'admin-post.php' ) ) );
		Admin_Url::form_fields( $action );
		printf( '<button type="submit" class="%1$s">%2$s</button></form>', esc_attr( $classes ), esc_html( $label ) );
	}

	/**
	 * The `flush_all` action: flush every entry of the site, then back to the referring admin screen, else (a front-end page, where the notice would not show) the Tools page.
	 *
	 * @param string $target Unused.
	 *
	 * @return string Redirect URL.
	 */
	public function flush_all( string $target = '' ): string {
		unset( $target );
		$count = $this->cache_service->flush_all();
		$this->notices->success(
			/* translators: %d: number of cached responses. */
			sprintf( _n( 'Flushed %d cached response.', 'Flushed %d cached responses.', $count, 'rockfire-rest-api-cache' ), $count )
		);
		// Only back to an admin screen: front-end pages do not show the admin notice, so it would linger until later.
		return Admin_Url::back( Admin_Url::page( self::TOOLS_SLUG ) );
	}

	/**
	 * Action `dismiss_error`: forget the recorded failure and return to the plugin page it was dismissed on.
	 *
	 * @param string $target Unused.
	 *
	 * @return string Redirect URL.
	 */
	public function dismiss_error( string $target = '' ): string {
		unset( $target );
		Failure_Recorder::dismiss();
		return Admin_Url::back( Admin_Url::page( self::TOOLS_SLUG ) );
	}

	/**
	 * Action `dismiss_plugin_change`: forget the plugin-change marker of the site and return to the screen it was dismissed on.
	 *
	 * @param string $target Unused.
	 *
	 * @return string Redirect URL.
	 */
	public function dismiss_plugin_change( string $target = '' ): string {
		unset( $target );
		$this->plugin_change->dismiss();
		return Admin_Url::back( Admin_Url::page( self::TOOLS_SLUG ) );
	}
}
