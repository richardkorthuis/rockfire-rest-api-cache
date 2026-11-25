<?php
/**
 * The routes list table.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Admin\Tables;

use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Admin\Pages\Routes_Page;
use Rockfire\Rest_Api_Cache\Admin\Request;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Route_Rule;
use Rockfire\Rest_Api_Cache\Services\Route_Service;
use Rockfire\Rest_Api_Cache\Services\Route_View;
use Rockfire\Rest_Api_Cache\Services\Settings_Service;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists every REST route that registers GET, POST, PUT or PATCH with its effective policy; paginates
 * {@see Route_Service::all()} in memory.
 *
 * Filters (view, namespace, search, sort) come from the request through {@see Request}.
 *
 * @since 1.0.0
 */
class Routes_Table extends \WP_List_Table {

	/**
	 * Screen option holding the page size.
	 *
	 * @var string
	 */
	public const PER_PAGE_OPTION = 'rfrac_routes_per_page';

	/**
	 * Default page size.
	 *
	 * @var int
	 */
	public const DEFAULT_PER_PAGE = 50;

	/**
	 * Largest page size.
	 *
	 * @var int
	 */
	public const MAX_PER_PAGE = 200;

	/**
	 * Route service.
	 *
	 * @var Route_Service
	 */
	private Route_Service $routes;

	/**
	 * Settings service.
	 *
	 * @var Settings_Service
	 */
	private Settings_Service $settings;

	/**
	 * Constructor.
	 *
	 * @param Route_Service    $routes   Route service.
	 * @param Settings_Service $settings Settings service.
	 */
	public function __construct( Route_Service $routes, Settings_Service $settings ) {
		$this->routes   = $routes;
		$this->settings = $settings;
		parent::__construct(
			array(
				'singular' => 'rfrac_route',
				'plural'   => 'rfrac_routes',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The current page size (screen option, clamped to 1..200).
	 *
	 * @return int
	 */
	public function per_page(): int {
		$value = get_user_option( self::PER_PAGE_OPTION );
		$value = is_numeric( $value ) ? (int) $value : self::DEFAULT_PER_PAGE;
		return max( 1, min( self::MAX_PER_PAGE, $value ) );
	}

	/**
	 * The filter of the current request.
	 *
	 * @param string|null $view Override of the view.
	 *
	 * @return array<string,string>
	 */
	public function filter( ?string $view = null ): array {
		$view = $view ?? Request::key( 'view', 'all' );
		return array(
			'view'      => in_array( $view, Route_Service::VIEWS, true ) ? $view : 'all',
			'namespace' => Request::text( 'namespace' ),
			'search'    => Request::text( 's' ),
			'orderby'   => Request::key( 'orderby', 'route' ),
			'order'     => Request::key( 'order', 'asc' ),
		);
	}

	/**
	 * Load the items of the current page.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), get_hidden_columns( $this->screen ), $this->get_sortable_columns(), 'route' );

		$all      = $this->routes->all( $this->filter() );
		$per_page = $this->per_page();
		$this->set_pagination_args(
			array(
				'total_items' => count( $all ),
				'per_page'    => $per_page,
			)
		);
		$this->items = array_slice( $all, ( $this->get_pagenum() - 1 ) * $per_page, $per_page );
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'cb'           => '<input type="checkbox" />',
			'route'        => __( 'Route', 'rockfire-rest-api-cache' ),
			'namespace'    => __( 'Namespace', 'rockfire-rest-api-cache' ),
			'status'       => __( 'Status', 'rockfire-rest-api-cache' ),
			'ttl'          => __( 'TTL', 'rockfire-rest-api-cache' ),
			'params'       => __( 'Parameters', 'rockfire-rest-api-cache' ),
			'vary'         => __( 'Vary', 'rockfire-rest-api-cache' ),
			'invalidation' => __( 'Invalidation', 'rockfire-rest-api-cache' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'route'     => array( 'route', false ),
			'namespace' => array( 'namespace', false ),
		);
	}

	/**
	 * The primary column.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name(): string {
		return 'route';
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'enable'  => __( 'Enable', 'rockfire-rest-api-cache' ),
			'exclude' => __( 'Exclude', 'rockfire-rest-api-cache' ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Bulk action name, not a query argument.
			'reset'   => __( 'Reset to defaults', 'rockfire-rest-api-cache' ),
		);
	}

	/**
	 * The view links with their counts.
	 *
	 * @return array<string,string>
	 */
	protected function get_views(): array {
		$labels  = array(
			'all'          => __( 'All', 'rockfire-rest-api-cache' ),
			'enabled'      => __( 'Enabled', 'rockfire-rest-api-cache' ),
			'disabled'     => __( 'Disabled', 'rockfire-rest-api-cache' ),
			'configured'   => __( 'Configured', 'rockfire-rest-api-cache' ),
			'locked'       => __( 'Locked', 'rockfire-rest-api-cache' ),
			'unregistered' => __( 'Not registered', 'rockfire-rest-api-cache' ),
		);
		$current = $this->filter()['view'];
		$views   = array();
		foreach ( $labels as $view => $label ) {
			$count          = count( $this->routes->all( array( 'view' => $view ) ) );
			$views[ $view ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
				esc_url( Admin_Url::page( Routes_Page::SLUG, 'all' === $view ? array() : array( 'view' => $view ) ) ),
				$current === $view ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				$count
			);
		}
		return $views;
	}

	/**
	 * The namespace filter above the table.
	 *
	 * @param string $which `top` or `bottom`.
	 *
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$current = $this->filter()['namespace'];
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="rfrac-namespace-filter">' . esc_html__( 'Filter by namespace', 'rockfire-rest-api-cache' ) . '</label>';
		echo '<select name="namespace" id="rfrac-namespace-filter">';
		printf( '<option value="">%s</option>', esc_html__( 'All namespaces', 'rockfire-rest-api-cache' ) );
		foreach ( $this->routes->namespaces() as $rest_namespace ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $rest_namespace ), selected( $current, $rest_namespace, false ), esc_html( $rest_namespace ) );
		}
		echo '</select>';
		submit_button( __( 'Filter', 'rockfire-rest-api-cache' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Message for an empty list.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No routes found.', 'rockfire-rest-api-cache' );
	}

	/**
	 * Checkbox column; absent for denied, locked and unregistered routes.
	 *
	 * @param Route_View $item Route.
	 *
	 * @return string
	 */
	protected function column_cb( $item ): string {
		if ( $item->is_denied || $item->locked || ! $item->is_registered ) {
			return '';
		}
		return sprintf(
			'<input type="checkbox" name="route[]" id="rfrac-route-%1$s" value="%1$s" /><label for="rfrac-route-%1$s"><span class="screen-reader-text">%2$s</span></label>',
			esc_attr( $item->id ),
			/* translators: %s: route pattern. */
			esc_html( sprintf( __( 'Select %s', 'rockfire-rest-api-cache' ), $item->route ) )
		);
	}

	/**
	 * Route column with its row actions.
	 *
	 * @param Route_View $item Route.
	 *
	 * @return string
	 */
	protected function column_route( $item ): string {
		$edit    = Admin_Url::page(
			Routes_Page::SLUG,
			array(
				'action'   => 'edit',
				'route_id' => $item->id,
			)
		);
		$actions = array(
			'edit' => sprintf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html__( 'Edit', 'rockfire-rest-api-cache' ) ),
		);
		if ( $item->is_registered ) {
			$actions['entries'] = sprintf( '<a href="%s">%s</a>', esc_url( Admin_Url::page( 'rfrac-caches', array( 'route_id' => $item->id ) ) ), esc_html__( 'View entries', 'rockfire-rest-api-cache' ) );
		}
		if ( $item->enabled ) {
			$actions['flush'] = sprintf( '<a href="%s">%s</a>', esc_url( Admin_Url::action( 'flush_route_cache', $item->id ) ), esc_html__( 'Flush', 'rockfire-rest-api-cache' ) );
		}
		if ( null !== $item->rule && ! $item->locked ) {
			$actions['reset'] = sprintf(
				'<a href="%s" data-rfrac-confirm="%s">%s</a>',
				esc_url( Admin_Url::action( 'reset_route', $item->id ) ),
				esc_attr__( 'Reset this route to its default rule?', 'rockfire-rest-api-cache' ),
				esc_html__( 'Reset', 'rockfire-rest-api-cache' )
			);
		}

		return sprintf(
			'<strong><a class="row-title" href="%1$s"><code class="rfrac-path">%2$s</code></a></strong>%3$s',
			esc_url( $edit ),
			esc_html( $item->route ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Namespace column.
	 *
	 * @param Route_View $item Route.
	 *
	 * @return string
	 */
	protected function column_namespace( $item ): string {
		return '' === $item->namespace ? '&mdash;' : esc_html( $item->namespace );
	}

	/**
	 * Status column.
	 *
	 * @param Route_View $item Route.
	 *
	 * @return string
	 */
	protected function column_status( $item ): string {
		if ( ! $item->is_registered ) {
			$badge = '<span class="rfrac-badge rfrac-badge--muted">' . esc_html__( 'Not registered', 'rockfire-rest-api-cache' ) . '</span>';
			if ( null !== $item->invalid ) {
				/* translators: %s: why the stored rule is invalid. */
				$badge .= ' <span class="rfrac-help">' . esc_html( sprintf( __( '(invalid stored rule: %s Reset it.)', 'rockfire-rest-api-cache' ), $item->invalid ) ) . '</span>';
			}
			return $badge;
		}
		if ( $item->is_denied ) {
			return '<span class="rfrac-badge rfrac-badge--muted">' . esc_html__( 'Denied', 'rockfire-rest-api-cache' ) . '</span>';
		}
		if ( $item->locked ) {
			return '<span class="rfrac-badge rfrac-badge--muted">' . esc_html__( 'Locked', 'rockfire-rest-api-cache' ) . '</span>';
		}
		if ( $item->enabled ) {
			$badge = '<span class="rfrac-badge rfrac-badge--success">' . esc_html__( 'Enabled', 'rockfire-rest-api-cache' ) . '</span>';
		} elseif ( null !== $item->rule && Route_Rule::STATUS_EXCLUDED === $item->rule->status ) {
			$badge = '<span class="rfrac-badge rfrac-badge--warning">' . esc_html__( 'Excluded', 'rockfire-rest-api-cache' ) . '</span>';
		} else {
			$badge = '<span class="rfrac-badge rfrac-badge--muted">' . esc_html__( 'Disabled', 'rockfire-rest-api-cache' ) . '</span>';
		}
		if ( null === $item->rule ) {
			$badge .= ' <span class="rfrac-help">' . esc_html__( '(default)', 'rockfire-rest-api-cache' ) . '</span>';
		}
		if ( null !== $item->invalid ) {
			/* translators: %s: why the stored rule is invalid. */
			$badge .= ' <span class="rfrac-help">' . esc_html( sprintf( __( '(invalid stored rule: %s Reset it.)', 'rockfire-rest-api-cache' ), $item->invalid ) ) . '</span>';
		}
		return $badge;
	}

	/**
	 * TTL column.
	 *
	 * @param Route_View $item Route.
	 *
	 * @return string
	 */
	protected function column_ttl( $item ): string {
		if ( $item->is_denied ) {
			return '&mdash;';
		}
		$text = human_time_diff( 0, $item->ttl );
		if ( null === self::rule_of( $item )->ttl ) {
			$text = sprintf(
				/* translators: %s: duration such as "1 hour". */
				__( '%s (site default)', 'rockfire-rest-api-cache' ),
				$text
			);
		}
		return esc_html( $text );
	}

	/**
	 * Parameters column.
	 *
	 * @param Route_View $item Route.
	 *
	 * @return string
	 */
	protected function column_params( $item ): string {
		if ( $item->is_denied ) {
			return '&mdash;';
		}
		$rule = self::rule_of( $item );
		if ( Route_Rule::PARAM_ALLOW_ALL === $rule->param_mode ) {
			/* translators: %d: maximum number of query parameters. */
			$lines = array( sprintf( __( 'All (max %d)', 'rockfire-rest-api-cache' ), (int) $this->settings->get( 'max_params' ) ) );
		} elseif ( array() !== $rule->ignore_params ) {
			$count = count( $rule->ignore_params );
			/* translators: %d: number of ignored parameters. */
			$lines = array( sprintf( _n( 'Registered args, %d ignored', 'Registered args, %d ignored', $count, 'rockfire-rest-api-cache' ), $count ) );
		} else {
			$lines = array( __( 'Registered args', 'rockfire-rest-api-cache' ) );
		}
		if ( array() !== $rule->bypass_params ) {
			/* translators: %s: comma separated parameter names. */
			$lines[] = sprintf( __( 'Bypass when present: %s', 'rockfire-rest-api-cache' ), implode( ', ', $rule->bypass_params ) );
		}
		if ( Route_Rule::DEFAULT_METHODS !== $rule->methods ) {
			/* translators: %s: comma separated request methods such as GET, POST. */
			$lines[] = sprintf( __( 'Methods: %s', 'rockfire-rest-api-cache' ), implode( ', ', $rule->methods ) );
		}
		return self::lines( $lines );
	}

	/**
	 * Escaped lines of a cell, one per line.
	 *
	 * @param string[] $lines Plain-text lines.
	 *
	 * @return string
	 */
	private static function lines( array $lines ): string {
		return implode( '<br />', array_map( 'esc_html', $lines ) );
	}

	/**
	 * Vary column.
	 *
	 * @param Route_View $item Route.
	 *
	 * @return string
	 */
	protected function column_vary( $item ): string {
		$headers = $item->is_denied ? array() : self::rule_of( $item )->vary_headers;
		return array() === $headers ? '&mdash;' : esc_html( implode( ', ', $headers ) );
	}

	/**
	 * Invalidation column.
	 *
	 * @param Route_View $item Route.
	 *
	 * @return string
	 */
	protected function column_invalidation( $item ): string {
		if ( $item->is_denied ) {
			return '&mdash;';
		}
		$rule  = self::rule_of( $item );
		$lines = array( __( 'Automatic tags', 'rockfire-rest-api-cache' ) );
		if ( array() !== $rule->flush_post_types ) {
			/* translators: %s: comma separated post types. */
			$lines[] = sprintf( __( 'Also on changes of the post types: %s', 'rockfire-rest-api-cache' ), implode( ', ', $rule->flush_post_types ) );
		}
		if ( array() !== $rule->flush_taxonomies ) {
			/* translators: %s: comma separated taxonomies. */
			$lines[] = sprintf( __( 'Also on changes of the taxonomies: %s', 'rockfire-rest-api-cache' ), implode( ', ', $rule->flush_taxonomies ) );
		}
		return self::lines( $lines );
	}

	/**
	 * The rule that applies to a route: its override, else its built-in rule.
	 *
	 * @param Route_View $item Route.
	 *
	 * @return Route_Rule
	 */
	public static function rule_of( Route_View $item ): Route_Rule {
		return $item->rule ?? ( $item->is_default ? Config::default_route_rule() : Route_Rule::defaults() );
	}
}
