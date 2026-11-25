<?php
/**
 * List table of the cache entries.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Admin\Tables;

use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Admin\Request;
use Rockfire\Rest_Api_Cache\Services\Cache_Service;
use Rockfire\Rest_Api_Cache\Services\Route_Service;
use Rockfire\Rest_Api_Cache\Storage\Entry_Query;
use Rockfire\Rest_Api_Cache\Storage\Entry_Row;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Entries of the cache: search, state views, sorting, paging and bulk flush/delete.
 *
 * @since 1.0.0
 */
class Entries_Table extends \WP_List_Table {

	/**
	 * Page slug the links point to.
	 *
	 * @var string
	 */
	public const PAGE = 'rfrac-caches';

	/**
	 * Screen option holding the number of entries per page.
	 *
	 * @var string
	 */
	public const PER_PAGE_OPTION = 'rfrac_entries_per_page';

	/**
	 * Cache service.
	 *
	 * @var Cache_Service
	 */
	private Cache_Service $cache_service;

	/**
	 * Route service.
	 *
	 * @var Route_Service
	 */
	private Route_Service $route_service;

	/**
	 * Hit sampling rate (0 = off, 1 = every hit, N = one in N).
	 *
	 * @var int
	 */
	private int $hit_sampling;

	/**
	 * Constructor.
	 *
	 * @param Cache_Service $cache_service Cache service.
	 * @param Route_Service $route_service Route service.
	 * @param int           $hit_sampling  Hit sampling rate.
	 */
	public function __construct( Cache_Service $cache_service, Route_Service $route_service, int $hit_sampling = 0 ) {
		parent::__construct(
			array(
				'singular' => 'rfrac_entry',
				'plural'   => 'rfrac_entries',
				'ajax'     => false,
			)
		);
		$this->cache_service = $cache_service;
		$this->route_service = $route_service;
		$this->hit_sampling  = $hit_sampling;
	}

	/**
	 * The state view of the request (`all`, `active`, `flushed` or `expired`).
	 *
	 * @return string
	 */
	public static function current_view(): string {
		$view = Request::key( 'view', 'all' );
		return in_array( $view, Entry_Query::STATES, true ) ? $view : 'all';
	}

	/**
	 * The route filter of the request: the route id and its pattern, or null.
	 *
	 * @return array{id:string,route:string}|null
	 */
	public function route_filter(): ?array {
		$route_id = Request::text( 'route_id' );
		if ( '' === $route_id ) {
			return null;
		}
		$route = $this->route_service->find( $route_id );
		return null === $route ? null : array(
			'id'    => $route_id,
			'route' => $route->route,
		);
	}

	/**
	 * Whether the request carries a `route_id` that matches no route.
	 *
	 * @return bool
	 */
	public function has_unknown_route(): bool {
		return '' !== Request::text( 'route_id' ) && null === $this->route_filter();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox" />',
			'request'     => __( 'Request', 'rockfire-rest-api-cache' ),
			'method'      => __( 'Method', 'rockfire-rest-api-cache' ),
			'route'       => __( 'Route', 'rockfire-rest-api-cache' ),
			'state'       => __( 'State', 'rockfire-rest-api-cache' ),
			'size'        => __( 'Size', 'rockfire-rest-api-cache' ),
			'hits'        => __( 'Hits', 'rockfire-rest-api-cache' ),
			'created_at'  => __( 'Created', 'rockfire-rest-api-cache' ),
			'expires_at'  => __( 'Expires', 'rockfire-rest-api-cache' ),
			'last_hit_at' => __( 'Last hit', 'rockfire-rest-api-cache' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	protected function get_sortable_columns() {
		return array(
			'method'      => array( 'method', false ),
			'route'       => array( 'route', false ),
			'size'        => array( 'size', false ),
			'hits'        => array( 'hits', false ),
			'created_at'  => array( 'created_at', true ),
			'expires_at'  => array( 'expires_at', false ),
			'last_hit_at' => array( 'last_hit_at', false ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_default_primary_column_name() {
		return 'request';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		return array(
			'flush'  => __( 'Flush', 'rockfire-rest-api-cache' ),
			'delete' => __( 'Delete', 'rockfire-rest-api-cache' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$stats   = $this->cache_service->stats();
		$labels  = array(
			'all'     => array( __( 'All', 'rockfire-rest-api-cache' ), $stats['total'] ),
			'active'  => array( __( 'Active', 'rockfire-rest-api-cache' ), $stats['active'] ),
			'flushed' => array( __( 'Flushed', 'rockfire-rest-api-cache' ), $stats['flushed'] ),
			'expired' => array( __( 'Expired', 'rockfire-rest-api-cache' ), $stats['expired'] ),
		);
		$current = self::current_view();
		$filter  = $this->route_filter();
		$views   = array();
		foreach ( $labels as $view => $label ) {
			$args = array();
			if ( 'all' !== $view ) {
				$args['view'] = $view;
			}
			if ( null !== $filter ) {
				$args['route_id'] = $filter['id'];
			}
			$views[ $view ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( Admin_Url::page( self::PAGE, $args ) ),
				$current === $view ? ' class="current" aria-current="page"' : '',
				esc_html( $label[0] ),
				esc_html( number_format_i18n( $label[1] ) )
			);
		}
		return $views;
	}

	/**
	 * Load the entries of the current request; a page past the end shows the last page.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = $this->per_page();
		$filter   = $this->route_filter();
		$args     = array(
			'search'   => Request::text( 's' ),
			'state'    => self::current_view(),
			'rule_key' => null === $filter ? '' : $filter['route'],
			'orderby'  => Request::key( 'orderby' ),
			'order'    => Request::key( 'order' ),
			'per_page' => $per_page,
			'page'     => max( 1, Request::int( 'paged', 1 ) ),
		);

		$result = $this->cache_service->query( Entry_Query::from_array( $args ) );
		$last   = max( 1, (int) ceil( $result['total'] / $per_page ) );
		if ( $args['page'] > $last ) {
			// For example after deleting the entries of the last page.
			$args['page'] = $last;
			$result       = $this->cache_service->query( Entry_Query::from_array( $args ) );
		}

		$this->items = $result['items'];
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), get_hidden_columns( $this->screen ), $this->get_sortable_columns(), $this->get_default_primary_column_name() );
	}

	/**
	 * The number of entries per page (screen option).
	 *
	 * @return int
	 */
	public function per_page(): int {
		return max( 1, (int) $this->get_items_per_page( self::PER_PAGE_OPTION, Entry_Query::DEFAULT_PER_PAGE ) );
	}

	/**
	 * The number of pages of the list for a search, a state view and a route id (at least 1).
	 *
	 * @param string $search   Search text.
	 * @param string $view     State view.
	 * @param string $route_id Route id; '' or an unknown id for every route.
	 *
	 * @return int
	 */
	public function page_count( string $search, string $view, string $route_id ): int {
		$route = '' === $route_id ? null : $this->route_service->find( $route_id );
		$query = Entry_Query::from_array(
			array(
				'search'   => $search,
				'state'    => $view,
				'rule_key' => null === $route ? '' : $route->route,
				'per_page' => 1,
			)
		);
		return max( 1, (int) ceil( $this->cache_service->query( $query )['total'] / $this->per_page() ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function no_items(): void {
		esc_html_e( 'No cache entries found.', 'rockfire-rest-api-cache' );
	}

	/**
	 * State label of a row: `active`, `flushed` or `expired`.
	 *
	 * @param Entry_Row $item Entry.
	 *
	 * @return string
	 */
	public static function state_of( Entry_Row $item ): string {
		if ( Entry_Row::STATE_FLUSHED === $item->state ) {
			return 'flushed';
		}
		$expires = strtotime( $item->expires_at . ' UTC' );
		return false !== $expires && $expires <= time() ? 'expired' : 'active';
	}

	/**
	 * The translated label and badge modifier of a state.
	 *
	 * @param string $state `active`, `flushed` or `expired`.
	 *
	 * @return array{0:string,1:string} Label and badge modifier.
	 */
	public static function state_badge( string $state ): array {
		switch ( $state ) {
			case 'active':
				return array( __( 'Active', 'rockfire-rest-api-cache' ), 'success' );
			case 'expired':
				return array( __( 'Expired', 'rockfire-rest-api-cache' ), 'warning' );
			default:
				return array( __( 'Flushed', 'rockfire-rest-api-cache' ), 'muted' );
		}
	}

	/**
	 * Human-readable size.
	 *
	 * @param int $bytes Bytes.
	 *
	 * @return string
	 */
	public static function format_size( int $bytes ): string {
		$size = size_format( $bytes, 1 );
		return false === $size ? '0 B' : $size;
	}

	/**
	 * Format a UTC database date in the site's timezone; an em dash when empty.
	 *
	 * @param string $value UTC `Y-m-d H:i:s`.
	 *
	 * @return string
	 */
	public static function format_date( string $value ): string {
		if ( '' === $value || str_starts_with( $value, '0000' ) ) {
			return '—';
		}
		$time = strtotime( $value . ' UTC' );
		if ( false === $time ) {
			return '—';
		}
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	/**
	 * Checkbox column.
	 *
	 * @param Entry_Row $item Entry.
	 *
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="entry[]" value="%1$d" id="cb-select-%1$d" /><label for="cb-select-%1$d" class="screen-reader-text">%2$s</label>',
			(int) $item->id,
			/* translators: %d: entry id */
			esc_html( sprintf( __( 'Select entry %d', 'rockfire-rest-api-cache' ), $item->id ) )
		);
	}

	/**
	 * Request column (primary), with the row actions.
	 *
	 * @param Entry_Row $item Entry.
	 *
	 * @return string
	 */
	protected function column_request( $item ) {
		$details = Admin_Url::page(
			self::PAGE,
			array(
				'action' => 'details',
				'entry'  => $item->id,
			)
		);
		$actions = array(
			'details' => sprintf( '<a href="%s">%s</a>', esc_url( $details ), esc_html__( 'Details', 'rockfire-rest-api-cache' ) ),
		);
		if ( 'active' === self::state_of( $item ) ) {
			$actions['flush'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( Admin_Url::action( 'flush_entry', (string) $item->id ) ),
				esc_html__( 'Flush', 'rockfire-rest-api-cache' )
			);
		}
		$actions['delete'] = sprintf(
			'<a href="%s" class="submitdelete" data-rfrac-confirm="%s">%s</a>',
			esc_url( Admin_Url::action( 'delete_entry', (string) $item->id ) ),
			esc_attr__( 'Delete this cache entry?', 'rockfire-rest-api-cache' ),
			esc_html__( 'Delete', 'rockfire-rest-api-cache' )
		);

		return sprintf(
			'<strong><a class="row-title" href="%1$s"><code class="rfrac-path">%2$s</code></a></strong>%3$s',
			esc_url( $details ),
			esc_html( $item->request ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Route column.
	 *
	 * @param Entry_Row $item Entry.
	 *
	 * @return string
	 */
	protected function column_route( $item ) {
		$rule = '' !== $item->rule_key ? $item->rule_key : $item->route;
		return sprintf(
			'<a href="%s"><code class="rfrac-path">%s</code></a>',
			esc_url( Admin_Url::page( self::PAGE, array( 'route_id' => Route_Service::id_for( $rule ) ) ) ),
			esc_html( $item->route )
		);
	}

	/**
	 * State column.
	 *
	 * @param Entry_Row $item Entry.
	 *
	 * @return string
	 */
	protected function column_state( $item ) {
		$badge = self::state_badge( self::state_of( $item ) );
		// The column header already says "State"; the badge text is read as is.
		return sprintf( '<span class="rfrac-badge rfrac-badge--%1$s">%2$s</span>', esc_attr( $badge[1] ), esc_html( $badge[0] ) );
	}

	/**
	 * Default renderer of the remaining columns.
	 *
	 * @param Entry_Row $item        Entry.
	 * @param string    $column_name Column.
	 *
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'method':
				return esc_html( $item->method );
			case 'size':
				return esc_html( self::format_size( $item->size ) );
			case 'hits':
				return esc_html( ( $this->hit_sampling > 1 ? '≈' : '' ) . number_format_i18n( $item->hits ) );
			case 'created_at':
				return esc_html( self::format_date( $item->created_at ) );
			case 'expires_at':
				return esc_html( self::format_date( $item->expires_at ) );
			case 'last_hit_at':
				return esc_html( self::format_date( $item->last_hit_at ) );
		}
		return '';
	}
}
