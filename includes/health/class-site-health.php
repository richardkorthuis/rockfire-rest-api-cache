<?php
/**
 * Site Health tests and debug information.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Health;

use Rockfire\Rest_Api_Cache\Admin\Admin;
use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Admin\Pages\Routes_Page;
use Rockfire\Rest_Api_Cache\Content_Path;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Install\Mu_Status;
use Rockfire\Rest_Api_Cache\Services\Route_Service;
use Rockfire\Rest_Api_Cache\Services\Status;
use Rockfire\Rest_Api_Cache\Services\Status_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Reports the must-use mode, the tables, the cleanup backlog and the object cache in Site Health, and
 * adds a debug information section.
 *
 * Every test reads the one {@see Status_Service::get()} snapshot (with the table check), which the
 * service memoises per request, so `SHOW TABLES` runs at most once however many tests run.
 *
 * @since 1.0.0
 */
class Site_Health {

	/**
	 * Section slug of the debug information panel.
	 */
	public const DEBUG_SECTION = 'rockfire-rest-api-cache';

	/**
	 * How long the next maintenance may be overdue (seconds) before the backlog test complains.
	 */
	private const OVERDUE = HOUR_IN_SECONDS;

	/**
	 * Status service.
	 *
	 * @var Status_Service
	 */
	private Status_Service $status_service;

	/**
	 * Most routes listed by name in the custom access test; the rest are counted.
	 */
	private const MAX_LISTED_ROUTES = 10;

	/**
	 * Longest error text, in characters, named in the result of the custom access test.
	 */
	private const MAX_ERROR_LENGTH = 300;

	/**
	 * Constructor.
	 *
	 * @param Status_Service     $status_service Status service.
	 * @param Route_Service|null $route_service  Route service; without one the custom access test is not added.
	 */
	public function __construct( Status_Service $status_service, private readonly ?Route_Service $route_service = null ) {
		$this->status_service = $status_service;
	}

	/**
	 * Add the direct tests (filter `site_status_tests`).
	 *
	 * @param mixed $tests Tests.
	 *
	 * @return array<string,mixed>
	 */
	public function register_tests( $tests ): array {
		$tests = is_array( $tests ) ? $tests : array();
		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}
		$tests['direct']['rfrac_mu_mode']         = array(
			'label' => __( 'REST API Cache early serving', 'rockfire-rest-api-cache' ),
			'test'  => array( $this, 'test_mu_mode' ),
		);
		$tests['direct']['rfrac_tables']          = array(
			'label' => __( 'REST API Cache database tables', 'rockfire-rest-api-cache' ),
			'test'  => array( $this, 'test_tables' ),
		);
		$tests['direct']['rfrac_cleanup_backlog'] = array(
			'label' => __( 'REST API Cache cleanup', 'rockfire-rest-api-cache' ),
			'test'  => array( $this, 'test_cleanup_backlog' ),
		);
		$tests['direct']['rfrac_object_cache']    = array(
			'label' => __( 'REST API Cache storage', 'rockfire-rest-api-cache' ),
			'test'  => array( $this, 'test_object_cache' ),
		);
		if ( null !== $this->route_service ) {
			$tests['direct']['rfrac_custom_access_routes'] = array(
				'label' => __( 'REST API Cache routes with their own access check', 'rockfire-rest-api-cache' ),
				'test'  => array( $this, 'test_custom_access_routes' ),
			);
		}
		return $tests;
	}

	/**
	 * Test: no cached route checks access with a custom permission callback.
	 *
	 * Such a route may rely on something the cache key does not hold (a secret header, the visitor's IP), and then a
	 * response one visitor was allowed to see is served to everyone. A heuristic: the test only names the routes.
	 *
	 * A failed read is named in the result itself and not recorded in the engine's last failure
	 * ({@see Failure_Recorder}), which holds one failure and is for the engine. A read that succeeds
	 * clears a `site_health` failure that an earlier version recorded.
	 *
	 * @return array<string,mixed>
	 */
	public function test_custom_access_routes(): array {
		$security = array( __( 'Security', 'rockfire-rest-api-cache' ), 'blue' );
		try {
			$routes = null === $this->route_service ? array() : $this->route_service->cached_custom_access();
		} catch ( \Throwable $e ) {
			return $this->result(
				'rfrac_custom_access_routes',
				'recommended',
				__( 'REST API Cache could not check the access of its cached routes', 'rockfire-rest-api-cache' ),
				'<p>' . sprintf(
					/* translators: %s: error message. */
					esc_html__( 'Reading the registered REST routes failed: %s', 'rockfire-rest-api-cache' ),
					esc_html( $this->error_text( $e ) )
				) . '</p>',
				'',
				$security
			);
		}
		Failure_Recorder::clear( array( 'site_health' ) );

		if ( array() === $routes ) {
			return $this->result(
				'rfrac_custom_access_routes',
				'good',
				__( 'Cached REST routes have no access checks of their own', 'rockfire-rest-api-cache' ),
				'<p>' . esc_html__( 'Every cached route is public, or checks access with WordPress core\'s own permission checks.', 'rockfire-rest-api-cache' ) . '</p>',
				'',
				$security
			);
		}

		$items = '';
		foreach ( array_slice( $routes, 0, self::MAX_LISTED_ROUTES ) as $route ) {
			$items .= sprintf(
				'<li><a href="%1$s"><code>%2$s</code></a> (%3$s)</li>',
				esc_url(
					Admin_Url::page(
						Routes_Page::SLUG,
						array(
							'action'   => 'edit',
							'route_id' => $route->id,
						)
					)
				),
				esc_html( $route->route ),
				esc_html( implode( ', ', $route->cached_custom ) )
			);
		}
		$more = count( $routes ) - self::MAX_LISTED_ROUTES;
		if ( $more > 0 ) {
			/* translators: %d: number of routes not listed. */
			$items .= '<li>' . esc_html( sprintf( _n( 'and %d more route', 'and %d more routes', $more, 'rockfire-rest-api-cache' ), $more ) ) . '</li>';
		}

		$description  = '<p>' . esc_html__( 'These cached routes check access with their own permission callback. Responses are only stored for visitors who are not logged in, but when such a check relies on something the cache does not see, such as a secret request header or the visitor\'s IP address, a response one visitor was allowed to see is served to every visitor.', 'rockfire-rest-api-cache' ) . '</p>';
		$description .= '<ul>' . $items . '</ul>';
		$description .= '<p>' . sprintf(
			/* translators: 1: constant name, 2: filter name. */
			esc_html__( 'Keep caching a route only when it is public to every visitor who is not logged in. If its check relies on a request header, add that header to %1$s in wp-config.php or with the %2$s filter.', 'rockfire-rest-api-cache' ),
			'<code>RFRAC_BYPASS_HEADERS</code>',
			'<code>rfrac/bypass_headers</code>'
		) . '</p>';

		return $this->result(
			'rfrac_custom_access_routes',
			'recommended',
			sprintf(
				/* translators: %d: number of routes. */
				_n( '%d cached REST route checks access with its own permission callback', '%d cached REST routes check access with their own permission callback', count( $routes ), 'rockfire-rest-api-cache' ),
				count( $routes )
			),
			$description,
			sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( Admin_Url::page( Routes_Page::SLUG ) ),
				esc_html__( 'Open the REST API Cache routes', 'rockfire-rest-api-cache' )
			),
			$security
		);
	}

	/**
	 * Test: the must-use file serves cached responses early.
	 *
	 * @return array<string,mixed>
	 */
	public function test_mu_mode(): array {
		$status = $this->status();

		if ( 'early' === $status->mu_mode ) {
			return $this->result(
				'rfrac_mu_mode',
				'good',
				__( 'Cached REST responses are served early', 'rockfire-rest-api-cache' ),
				'<p>' . esc_html__( 'The must-use file is installed and up to date; cached responses are served before most of WordPress loads.', 'rockfire-rest-api-cache' ) . '</p>'
			);
		}

		$description = '<p>' . esc_html__( 'Early serving is not active; responses are served after plugins load.', 'rockfire-rest-api-cache' ) . '</p>';

		$reason = $this->mu_reason( $status );
		if ( '' !== $reason ) {
			$description .= '<p>' . esc_html( $reason ) . '</p>';
		}
		$description .= '<p>' . sprintf(
			/* translators: 1: path of the must-use template, 2: path of the must-use file. */
			esc_html__( 'To install it manually, copy %1$s to %2$s.', 'rockfire-rest-api-cache' ),
			'<code>' . esc_html( Content_Path::shown( $status->mu_template_path, true ) ) . '</code>',
			'<code>' . esc_html( Content_Path::shown( $status->mu_target_path, true ) ) . '</code>'
		) . '</p>';
		$description .= $this->engine_failure( $status );

		return $this->result(
			'rfrac_mu_mode',
			'recommended',
			__( 'Early serving of cached REST responses is not active', 'rockfire-rest-api-cache' ),
			$description,
			$this->action()
		);
	}

	/**
	 * Test: the tables exist and the schema is up to date.
	 *
	 * @return array<string,mixed>
	 */
	public function test_tables(): array {
		$status = $this->status();
		$failed = null !== $status->migration_error || $status->db_version < $status->db_version_expected;

		if ( false === $status->tables_present ) {
			$description = '<p>' . esc_html__( 'The database tables of the cache are missing, so nothing can be cached. Deactivate and reactivate the plugin to recreate them.', 'rockfire-rest-api-cache' ) . '</p>'
				. $this->migration_details( $status )
				. $this->engine_failure( $status );
			return $this->result( 'rfrac_tables', 'critical', __( 'REST API Cache tables are missing', 'rockfire-rest-api-cache' ), $description );
		}

		if ( $failed ) {
			$description = '<p>' . sprintf(
				/* translators: 1: installed database version, 2: expected database version. */
				esc_html__( 'The database tables exist, but the last upgrade did not finish (database version %1$s, expected %2$s). Deactivate and reactivate the plugin to run the upgrade again.', 'rockfire-rest-api-cache' ),
				esc_html( (string) $status->db_version ),
				esc_html( (string) $status->db_version_expected )
			) . '</p>'
				. $this->migration_details( $status )
				. $this->engine_failure( $status );
			return $this->result( 'rfrac_tables', 'recommended', __( 'REST API Cache database upgrade is incomplete', 'rockfire-rest-api-cache' ), $description );
		}

		return $this->result(
			'rfrac_tables',
			'good',
			__( 'REST API Cache tables are present', 'rockfire-rest-api-cache' ),
			'<p>' . esc_html__( 'Both database tables of the cache exist and are up to date.', 'rockfire-rest-api-cache' ) . '</p>'
		);
	}

	/**
	 * Test: the cleanup backlog and the maintenance schedule.
	 *
	 * @return array<string,mixed>
	 */
	public function test_cleanup_backlog(): array {
		$status = $this->status();
		$issues = array();

		/**
		 * Filter: the number of flushed and expired entries waiting for cleanup above which Site Health recommends action.
		 *
		 * @since 1.0.0
		 *
		 * @param int $threshold Default 1000.
		 */
		$threshold = (int) apply_filters( Hooks::HEALTH_BACKLOG_THRESHOLD, 1000 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		if ( $status->backlog > $threshold ) {
			$issues[] = sprintf(
				/* translators: 1: number of entries waiting for cleanup, 2: threshold. */
				__( '%1$s entries are waiting for cleanup (threshold: %2$s).', 'rockfire-rest-api-cache' ),
				number_format_i18n( $status->backlog ),
				number_format_i18n( $threshold )
			);
		}
		if ( ! $status->cron_disabled ) {
			if ( null === $status->next_maintenance ) {
				$issues[] = __( 'The maintenance task is not scheduled.', 'rockfire-rest-api-cache' );
			} elseif ( $status->next_maintenance < time() - self::OVERDUE ) {
				$issues[] = __( 'The maintenance task is more than an hour overdue.', 'rockfire-rest-api-cache' );
			}
		}

		if ( array() === $issues ) {
			return $this->result(
				'rfrac_cleanup_backlog',
				'good',
				__( 'REST API Cache cleanup is up to date', 'rockfire-rest-api-cache' ),
				'<p>' . esc_html__( 'Expired and flushed entries are cleaned up on schedule.', 'rockfire-rest-api-cache' ) . '</p>'
			);
		}

		$description = '';
		foreach ( $issues as $issue ) {
			$description .= '<p>' . esc_html( $issue ) . '</p>';
		}
		$description .= $this->engine_failure( $status );

		return $this->result( 'rfrac_cleanup_backlog', 'recommended', __( 'REST API Cache cleanup needs attention', 'rockfire-rest-api-cache' ), $description, $this->action() );
	}

	/**
	 * Test: where the payloads are stored, by the store in use.
	 *
	 * Good when the store fits the environment; recommended when the object cache store is configured but
	 * there is no persistent object cache, because payloads then do not survive from one request to the next.
	 *
	 * @return array<string,mixed>
	 */
	public function test_object_cache(): array {
		$status = $this->status();

		if ( 'object_cache' === $status->store_type ) {
			if ( $status->object_cache_detected ) {
				return $this->result(
					'rfrac_object_cache',
					'good',
					__( 'REST API Cache uses the persistent object cache', 'rockfire-rest-api-cache' ),
					'<p>' . esc_html__( 'Persistent object cache detected; payloads are stored in the object cache.', 'rockfire-rest-api-cache' ) . '</p>'
				);
			}
			return $this->result(
				'rfrac_object_cache',
				'recommended',
				__( 'REST API Cache is set to the object cache, but there is no persistent object cache', 'rockfire-rest-api-cache' ),
				'<p>' . esc_html__( 'The store is set to the object cache, but no persistent object cache is installed, so cached payloads do not survive between requests and nothing is served from the cache. Install a persistent object cache or set the store to the database or automatic.', 'rockfire-rest-api-cache' ) . '</p>'
					. $this->engine_failure( $status )
			);
		}

		$description = esc_html__( 'Payloads are stored in the database (one indexed query per hit).', 'rockfire-rest-api-cache' );
		if ( ! $status->object_cache_detected ) {
			$description = esc_html__( 'No persistent object cache; payloads are stored in the database (one indexed query per hit).', 'rockfire-rest-api-cache' );
		}
		return $this->result( 'rfrac_object_cache', 'good', __( 'REST API Cache uses the database', 'rockfire-rest-api-cache' ), '<p>' . $description . '</p>' );
	}

	/**
	 * Add the debug information section (filter `debug_information`).
	 *
	 * @param mixed $info Debug information.
	 *
	 * @return array<string,mixed>
	 */
	public function debug_information( $info ): array {
		$info   = is_array( $info ) ? $info : array();
		$status = $this->status();
		$counts = $status->counts;

		$next = null === $status->next_maintenance
			? __( 'Not scheduled', 'rockfire-rest-api-cache' )
			: gmdate( 'Y-m-d H:i:s', $status->next_maintenance ) . ' UTC';

		$locked = array();
		foreach ( $status->locked_settings as $setting => $constant ) {
			$locked[] = $setting . ' (' . $constant . ')';
		}

		$info[ self::DEBUG_SECTION ] = array(
			'label'  => __( 'REST API Cache', 'rockfire-rest-api-cache' ),
			'fields' => array(
				'version'          => array(
					'label' => __( 'Plugin version', 'rockfire-rest-api-cache' ),
					'value' => $status->plugin_version,
				),
				'db_version'       => array(
					'label' => __( 'Database version', 'rockfire-rest-api-cache' ),
					'value' => $status->db_version . ' / ' . $status->db_version_expected,
				),
				'mu_mode'          => array(
					'label' => __( 'Serving mode', 'rockfire-rest-api-cache' ),
					'value' => $status->mu_mode,
				),
				'store_type'       => array(
					'label' => __( 'Store', 'rockfire-rest-api-cache' ),
					'value' => $status->store_type,
				),
				'entries_active'   => array(
					'label' => __( 'Active entries', 'rockfire-rest-api-cache' ),
					'value' => $counts['active'],
				),
				'entries_flushed'  => array(
					'label' => __( 'Flushed entries', 'rockfire-rest-api-cache' ),
					'value' => $counts['flushed'],
				),
				'entries_expired'  => array(
					'label' => __( 'Expired entries', 'rockfire-rest-api-cache' ),
					'value' => $counts['expired'],
				),
				'total_size'       => array(
					'label' => __( 'Total size', 'rockfire-rest-api-cache' ),
					'value' => size_format( $status->total_size ),
					'debug' => $status->total_size,
				),
				'max_entries'      => array(
					'label' => __( 'Maximum entries', 'rockfire-rest-api-cache' ),
					'value' => $status->max_entries,
				),
				'next_maintenance' => array(
					'label' => __( 'Next maintenance', 'rockfire-rest-api-cache' ),
					'value' => $next,
				),
				'locked_settings'  => array(
					'label' => __( 'Settings locked by a constant', 'rockfire-rest-api-cache' ),
					'value' => array() === $locked ? __( 'None', 'rockfire-rest-api-cache' ) : implode( ', ', $locked ),
				),
			),
		);
		return $info;
	}

	/**
	 * The snapshot, with the table check; memoised by the service.
	 *
	 * @return Status
	 */
	private function status(): Status {
		return $this->status_service->get( true );
	}

	/**
	 * A human explanation of why early serving is not active.
	 *
	 * @param Status $status Snapshot.
	 *
	 * @return string
	 */
	private function mu_reason( Status $status ): string {
		if ( Mu_Status::DISABLED === $status->mu_status ) {
			return __( 'Installing the must-use file is disabled by RFRAC_DISABLE_MU_INSTALL.', 'rockfire-rest-api-cache' );
		}
		if ( ! $status->mu_dir_writable ) {
			return __( 'The must-use directory is not writable.', 'rockfire-rest-api-cache' );
		}
		if ( Mu_Status::OUTDATED === $status->mu_status ) {
			return __( 'The must-use file is outdated.', 'rockfire-rest-api-cache' );
		}
		$last = $status->mu_last_result;
		if ( is_array( $last ) && Mu_Status::CURRENT->value !== $last['status'] ) {
			/* translators: %s: failure code of the last install attempt. */
			return sprintf( __( 'The last install attempt failed (%s).', 'rockfire-rest-api-cache' ), $last['code'] );
		}
		return __( 'The must-use file is not installed.', 'rockfire-rest-api-cache' );
	}

	/**
	 * The recorded migration error and the database version, as paragraphs.
	 *
	 * @param Status $status Snapshot.
	 *
	 * @return string HTML, escaped.
	 */
	private function migration_details( Status $status ): string {
		if ( null === $status->migration_error ) {
			return '';
		}
		return '<p>' . sprintf(
			/* translators: %s: error message. */
			esc_html__( 'Last migration error: %s', 'rockfire-rest-api-cache' ),
			esc_html( $status->migration_error )
		) . '</p>';
	}

	/**
	 * An error as one line of text: its class and message, cut to the longest length shown.
	 *
	 * @param \Throwable $error Error.
	 *
	 * @return string Plain text; escape it on output.
	 */
	private function error_text( \Throwable $error ): string {
		$text = get_class( $error ) . ': ' . str_replace( array( "\r", "\n" ), ' ', $error->getMessage() );
		return mb_strlen( $text ) > self::MAX_ERROR_LENGTH ? mb_substr( $text, 0, self::MAX_ERROR_LENGTH ) . '...' : $text;
	}

	/**
	 * The last recorded engine failure, as a paragraph.
	 *
	 * @param Status $status Snapshot.
	 *
	 * @return string HTML, escaped.
	 */
	private function engine_failure( Status $status ): string {
		if ( null === $status->last_error ) {
			return '';
		}
		return '<p>' . sprintf(
			/* translators: 1: where the failure happened, 2: error message. */
			esc_html__( 'Last recorded failure in %1$s: %2$s', 'rockfire-rest-api-cache' ),
			'<code>' . esc_html( $status->last_error['context'] ) . '</code>',
			esc_html( $status->last_error['message'] )
		) . '</p>';
	}

	/**
	 * URL of the Tools page.
	 *
	 * @return string
	 */
	private function tools_url(): string {
		return Admin_Url::page( Admin::TOOLS_SLUG );
	}

	/**
	 * The action link to the Tools page.
	 *
	 * @return string HTML.
	 */
	private function action(): string {
		return sprintf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( $this->tools_url() ),
			esc_html__( 'Open the REST API Cache tools', 'rockfire-rest-api-cache' )
		);
	}

	/**
	 * Build a test result.
	 *
	 * @param string $test        Test name.
	 * @param string $status      `good`, `recommended` or `critical`.
	 * @param string $label       Label.
	 * @param string $description Description (HTML, already escaped).
	 * @param string $actions     Actions (HTML, already escaped).
	 * @param array  $badge       Badge label and color; default the blue Performance badge.
	 * @phpstan-param array{0:string,1:string}|array{} $badge
	 *
	 * @return array<string,mixed>
	 */
	private function result( string $test, string $status, string $label, string $description, string $actions = '', array $badge = array() ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => $badge[0] ?? __( 'Performance', 'rockfire-rest-api-cache' ),
				'color' => $badge[1] ?? 'blue',
			),
			'description' => $description,
			'actions'     => $actions,
			'test'        => $test,
		);
	}
}
