<?php
/**
 * The Tools page: flush tools, maintenance, status and must-use install.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Admin\Pages;

use Rockfire\Rest_Api_Cache\Admin\Admin;
use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Admin\Mu_Help;
use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Admin\Request;
use Rockfire\Rest_Api_Cache\Install\Mu_Installer;
use Rockfire\Rest_Api_Cache\Install\Mu_Status;
use Rockfire\Rest_Api_Cache\Services\Cache_Service;
use Rockfire\Rest_Api_Cache\Services\Route_Service;
use Rockfire\Rest_Api_Cache\Services\Status_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Manual invalidation tools (tag, URL, route, everything), a bounded maintenance run, the plugin
 * status and the must-use install. Every operation is synchronous and goes through the services.
 *
 * @since 1.0.0
 */
class Tools_Page implements Page_Interface {

	/**
	 * Notices.
	 *
	 * @var Notices
	 */
	private Notices $notices;

	/**
	 * Cache service.
	 *
	 * @var Cache_Service
	 */
	private Cache_Service $cache_service;

	/**
	 * Status service.
	 *
	 * @var Status_Service
	 */
	private Status_Service $status_service;

	/**
	 * Route service.
	 *
	 * @var Route_Service
	 */
	private Route_Service $route_service;

	/**
	 * Constructor.
	 *
	 * @param Notices        $notices        Notices.
	 * @param Cache_Service  $cache_service  Cache service.
	 * @param Status_Service $status_service Status service.
	 * @param Route_Service  $route_service  Route service.
	 */
	public function __construct( Notices $notices, Cache_Service $cache_service, Status_Service $status_service, Route_Service $route_service ) {
		$this->notices        = $notices;
		$this->cache_service  = $cache_service;
		$this->status_service = $status_service;
		$this->route_service  = $route_service;
	}

	/**
	 * Page slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return Admin::TOOLS_SLUG;
	}

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function page_title(): string {
		return __( 'Tools', 'rockfire-rest-api-cache' );
	}

	/**
	 * Menu label.
	 *
	 * @return string
	 */
	public function menu_title(): string {
		return __( 'Tools', 'rockfire-rest-api-cache' );
	}

	/**
	 * Menu position.
	 *
	 * @return int
	 */
	public function position(): int {
		return 40;
	}

	/**
	 * Nothing to do before output.
	 *
	 * @return void
	 */
	public function load(): void {
	}

	/**
	 * Print the page body.
	 *
	 * @return void
	 */
	public function render(): void {
		$status = $this->status_service->get( true );
		$routes = $this->route_service->all( array( 'view' => 'enabled' ) );
		require RFRAC_DIR . 'admin/views/tools.php';
	}

	/**
	 * The actions of the page.
	 *
	 * @return array<string,callable|array{callback:callable,per_target_nonce?:bool}>
	 */
	public function actions(): array {
		return array(
			'flush_tag'       => array(
				'callback'         => array( $this, 'flush_tag' ),
				'per_target_nonce' => false,
			),
			'flush_url'       => array(
				'callback'         => array( $this, 'flush_url' ),
				'per_target_nonce' => false,
			),
			'flush_route'     => array(
				'callback'         => array( $this, 'flush_route' ),
				'per_target_nonce' => false,
			),
			'run_maintenance' => array(
				'callback'         => array( $this, 'run_maintenance' ),
				'per_target_nonce' => false,
			),
			'install_mu'      => array(
				'callback'         => array( $this, 'install_mu' ),
				'per_target_nonce' => false,
			),
		);
	}

	/**
	 * Flush by tag.
	 *
	 * @param string $target Unused.
	 *
	 * @return string Redirect URL.
	 */
	public function flush_tag( string $target = '' ): string {
		unset( $target );
		$this->report_flush( $this->cache_service->flush_tag( Request::text( 'rfrac_tag' ) ) );
		return $this->url();
	}

	/**
	 * Flush by URL.
	 *
	 * @param string $target Unused.
	 *
	 * @return string Redirect URL.
	 */
	public function flush_url( string $target = '' ): string {
		unset( $target );
		$url = Request::url( 'rfrac_url' );
		if ( '' === $url ) {
			$this->notices->error( __( 'Enter a full http(s) URL on this site.', 'rockfire-rest-api-cache' ) );
		} else {
			$this->report_flush( $this->cache_service->flush_url( $url ) );
		}
		return $this->url();
	}

	/**
	 * Flush a route chosen in the form.
	 *
	 * @param string $target Route id.
	 *
	 * @return string Redirect URL.
	 */
	public function flush_route( string $target = '' ): string {
		$view = '' === $target ? null : $this->route_service->find( $target );
		if ( null === $view ) {
			$this->notices->error( __( 'Unknown route.', 'rockfire-rest-api-cache' ) );
		} else {
			$this->report_flush( $this->cache_service->flush_route( $view->route ) );
		}
		return $this->url();
	}

	/**
	 * Run one bounded maintenance pass.
	 *
	 * @param string $target Unused.
	 *
	 * @return string Redirect URL.
	 */
	public function run_maintenance( string $target = '' ): string {
		unset( $target );
		$result = $this->cache_service->run_maintenance( 'tools' );
		if ( $result->skipped ) {
			$this->notices->warning( __( 'Maintenance is already running in another request. Nothing was done; try again in a minute.', 'rockfire-rest-api-cache' ) );
			return $this->url();
		}

		$message = sprintf(
			/* translators: 1: deleted entries, 2: evicted entries, 3: remaining backlog. */
			__( 'Maintenance deleted %1$d stale entries and evicted %2$d entries; %3$d stale entries remain.', 'rockfire-rest-api-cache' ),
			$result->deleted_stale,
			$result->evicted,
			$result->backlog_remaining
		);
		if ( $result->stalled ) {
			$this->notices->error(
				/* translators: %s: the result of the run, a full sentence. */
				sprintf( __( 'Maintenance could not make progress: entries could not be deleted. %s', 'rockfire-rest-api-cache' ), $message )
			);
		} else {
			$this->notices->success( $message );
		}
		return $this->url();
	}

	/**
	 * Install the must-use file.
	 *
	 * The file is shared by every site of a network, so besides the plugin capability this needs the
	 * capability to install it ({@see Mu_Installer::current_user_can_install()}: `manage_network_plugins`
	 * on multisite, else `activate_plugins`); without it the request ends with a 403. Refused when
	 * WordPress disallows file modifications. The request returns to the admin screen it was sent from (the Plugins
	 * screen, a plugin page), else to this page.
	 *
	 * @param string $target Unused.
	 *
	 * @return string Redirect URL.
	 */
	public function install_mu( string $target = '' ): string {
		unset( $target );
		if ( ! Mu_Installer::current_user_can_install() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to install the must-use file.', 'rockfire-rest-api-cache' ), 403 );
		}

		$mu = $this->status_service->install_mu();
		if ( Mu_Status::CURRENT === $mu ) {
			$this->notices->success( __( 'The must-use file is installed; REST API Cache now serves cache hits early.', 'rockfire-rest-api-cache' ) );
			return Admin_Url::back( $this->url() );
		}

		$reason = Mu_Help::reason( $this->status_service->get()->mu_last_result['code'] ?? '' );
		$this->notices->error(
			'' !== $reason
				/* translators: %s: reason, a full sentence. */
				? sprintf( __( 'The must-use file could not be installed: %s', 'rockfire-rest-api-cache' ), $reason )
				: __( 'The must-use file could not be installed.', 'rockfire-rest-api-cache' )
		);
		return Admin_Url::back( $this->url() );
	}

	/**
	 * Queue the notice of a flush result.
	 *
	 * @param int|\WP_Error $result Number flushed or an error.
	 *
	 * @return void
	 */
	private function report_flush( $result ): void {
		if ( is_wp_error( $result ) ) {
			$this->notices->error( $result->get_error_message() );
			return;
		}
		$this->notices->success(
			/* translators: %d: number of cached responses. */
			sprintf( _n( 'Flushed %d cached response.', 'Flushed %d cached responses.', (int) $result, 'rockfire-rest-api-cache' ), (int) $result )
		);
	}

	/**
	 * The Tools page URL.
	 *
	 * @return string
	 */
	private function url(): string {
		return Admin_Url::page( $this->slug() );
	}
}
