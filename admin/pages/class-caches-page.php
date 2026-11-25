<?php
/**
 * The Caches admin page: the cache entries and their details.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Admin\Pages;

use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Admin\Redirect;
use Rockfire\Rest_Api_Cache\Admin\Request;
use Rockfire\Rest_Api_Cache\Admin\Tables\Entries_Table;
use Rockfire\Rest_Api_Cache\Services\Cache_Service;
use Rockfire\Rest_Api_Cache\Services\Route_Service;
use Rockfire\Rest_Api_Cache\Services\Settings_Service;
use Rockfire\Rest_Api_Cache\Storage\Entry_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the cache entries (search, state views, sorting, paging, bulk flush and delete) and shows one entry
 * with its metadata, tags, headers and payload. Cached content is only ever printed escaped.
 *
 * @since 1.0.0
 */
class Caches_Page implements Page_Interface {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'rfrac-caches';

	/**
	 * Largest part of a payload shown in the details view, in bytes.
	 *
	 * @var int
	 */
	public const MAX_PAYLOAD_BYTES = 524288;

	/**
	 * Flash notices.
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
	 * Route service.
	 *
	 * @var Route_Service
	 */
	private Route_Service $route_service;

	/**
	 * Settings service.
	 *
	 * @var Settings_Service
	 */
	private Settings_Service $settings_service;

	/**
	 * Constructor.
	 *
	 * @param Notices          $notices          Flash notices.
	 * @param Cache_Service    $cache_service    Cache service.
	 * @param Route_Service    $route_service    Route service.
	 * @param Settings_Service $settings_service Settings service.
	 */
	public function __construct( Notices $notices, Cache_Service $cache_service, Route_Service $route_service, Settings_Service $settings_service ) {
		$this->notices          = $notices;
		$this->cache_service    = $cache_service;
		$this->route_service    = $route_service;
		$this->settings_service = $settings_service;

		// The screen option is saved before the load action of some WordPress versions, so the filter is registered up front.
		add_filter( 'set_screen_option_' . Entries_Table::PER_PAGE_OPTION, array( self::class, 'sanitize_per_page' ), 10, 3 );
	}

	/**
	 * Cap a saved "entries per page" screen option at 100.
	 *
	 * @param mixed $screen_option Value so far (false = not handled).
	 * @param mixed $option        Option name.
	 * @param mixed $value         Submitted value.
	 *
	 * @return int
	 */
	public static function sanitize_per_page( $screen_option, $option, $value ): int {
		unset( $screen_option, $option );
		return min( 100, absint( is_scalar( $value ) ? $value : 0 ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 */
	public function page_title(): string {
		return __( 'Caches', 'rockfire-rest-api-cache' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function menu_title(): string {
		return __( 'Caches', 'rockfire-rest-api-cache' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function position(): int {
		return 20;
	}

	/**
	 * Screen option and the bulk actions of the list.
	 *
	 * @return void
	 */
	public function load(): void {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Entries per page', 'rockfire-rest-api-cache' ),
				'default' => Entry_Query::DEFAULT_PER_PAGE,
				'option'  => Entries_Table::PER_PAGE_OPTION,
			)
		);

		$action = $this->table()->current_action();
		if ( ! in_array( $action, array( 'flush', 'delete' ), true ) ) {
			return;
		}

		check_admin_referer( 'bulk-rfrac_entries' );

		$ids = Request::int_list( 'entry' );
		if ( array() === $ids ) {
			$this->notices->warning( __( 'No cache entries were selected.', 'rockfire-rest-api-cache' ) );
		} elseif ( 'flush' === $action ) {
			$this->notices->success( $this->count_message( $this->cache_service->flush( $ids ), true ) );
		} else {
			$this->notices->success( $this->count_message( $this->cache_service->delete( $ids ), false ) );
		}

		Redirect::safe_and_exit( $this->list_url( $this->request_list_args() ) );
	}

	/**
	 * The list or the details of one entry.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( 'details' === Request::key( 'action' ) ) {
			$this->render_details( Request::int( 'entry' ) );
			return;
		}

		$table = $this->table();
		$table->prepare_items();
		$list_url      = Admin_Url::page( self::SLUG );
		$view          = Entries_Table::current_view();
		$route_filter  = $table->route_filter();
		$unknown_route = $table->has_unknown_route();
		require RFRAC_DIR . 'admin/views/caches.php';
	}

	/**
	 * The `flush_entry` and `delete_entry` row actions.
	 *
	 * @return array<string,callable>
	 */
	public function actions(): array {
		return array(
			'flush_entry'  => fn( string $target ): string => $this->row_action( $target, true ),
			'delete_entry' => fn( string $target ): string => $this->row_action( $target, false ),
		);
	}

	/**
	 * Flush or delete one entry.
	 *
	 * @param string $target Entry id.
	 * @param bool   $flush  True to flush, false to delete.
	 *
	 * @return string Redirect URL.
	 */
	private function row_action( string $target, bool $flush ): string {
		$id    = ctype_digit( $target ) ? (int) $target : 0; // Digits only: "5or11" is not entry 5.
		$count = 0;
		if ( $id > 0 ) {
			$count = $flush ? $this->cache_service->flush( array( $id ) ) : $this->cache_service->delete( array( $id ) );
		}
		if ( 0 === $count ) {
			$this->notices->error( __( 'Cache entry not found.', 'rockfire-rest-api-cache' ) );
		} else {
			$this->notices->success( $this->count_message( $count, $flush ) );
		}
		// Back to the list as it was when the action came from it (the details view returns to the plain list).
		return $this->list_url( Admin_Url::referer_args( self::SLUG ) ?? array() );
	}

	/**
	 * Notice text for a number of flushed or deleted entries.
	 *
	 * @param int  $count Number of entries.
	 * @param bool $flush True when flushed, false when deleted.
	 *
	 * @return string
	 */
	private function count_message( int $count, bool $flush ): string {
		if ( $flush ) {
			/* translators: %s: number of entries */
			return sprintf( _n( '%s cache entry flushed.', '%s cache entries flushed.', $count, 'rockfire-rest-api-cache' ), number_format_i18n( $count ) );
		}
		/* translators: %s: number of entries */
		return sprintf( _n( '%s cache entry deleted.', '%s cache entries deleted.', $count, 'rockfire-rest-api-cache' ), number_format_i18n( $count ) );
	}

	/**
	 * The list URL with the sanitised search, view, route filter, sorting and page of a set of arguments.
	 *
	 * A page past the end of the list (for example after deleting the entries of the last page) becomes the last page.
	 *
	 * @param array<string,mixed> $raw Raw arguments: the request, or the query of the referring list page.
	 *
	 * @return string
	 */
	private function list_url( array $raw ): string {
		$text = static fn( string $key ): string => isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? sanitize_text_field( (string) $raw[ $key ] ) : '';
		$args = array();

		if ( '' !== $text( 's' ) ) {
			$args['s'] = $text( 's' );
		}
		$view = sanitize_key( $text( 'view' ) );
		if ( in_array( $view, Entry_Query::STATES, true ) && 'all' !== $view ) {
			$args['view'] = $view;
		}
		if ( '' !== $text( 'route_id' ) ) {
			$args['route_id'] = $text( 'route_id' );
		}
		$orderby = sanitize_key( $text( 'orderby' ) );
		if ( in_array( $orderby, Entry_Query::ORDERBY, true ) ) {
			$args['orderby'] = $orderby;
			$args['order']   = 'asc' === sanitize_key( $text( 'order' ) ) ? 'asc' : 'desc';
		}
		$paged = min( absint( $text( 'paged' ) ), $this->table()->page_count( $args['s'] ?? '', $args['view'] ?? 'all', $args['route_id'] ?? '' ) );
		if ( $paged > 1 ) {
			$args['paged'] = $paged;
		}
		return Admin_Url::page( self::SLUG, $args );
	}

	/**
	 * The list arguments of the current request.
	 *
	 * @return array<string,mixed>
	 */
	private function request_list_args(): array {
		$args = array();
		foreach ( array( 's', 'view', 'route_id', 'orderby', 'order', 'paged' ) as $key ) {
			$args[ $key ] = Request::text( $key );
		}
		return $args;
	}

	/**
	 * The list table.
	 *
	 * @return Entries_Table
	 */
	private function table(): Entries_Table {
		return new Entries_Table( $this->cache_service, $this->route_service, (int) $this->settings_service->get( 'hit_sampling' ) );
	}

	/**
	 * Print the details of one entry.
	 *
	 * @param int $id Entry id.
	 *
	 * @return void
	 */
	private function render_details( int $id ): void {
		$entry    = $this->cache_service->get( $id );
		$list_url = Admin_Url::page( self::SLUG );
		if ( null === $entry ) {
			echo '<p>' . esc_html__( 'Cache entry not found.', 'rockfire-rest-api-cache' ) . '</p>';
			printf( '<p><a href="%s">%s</a></p>', esc_url( $list_url ), esc_html__( 'Back to the caches', 'rockfire-rest-api-cache' ) );
			return;
		}

		$row     = $entry['row'];
		$state   = $entry['state_label'];
		$headers = wp_json_encode( $entry['headers'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$headers = false === $headers ? '' : $headers;
		$tags    = $entry['tags'];

		$payload           = $entry['payload'];
		$payload_text      = '';
		$payload_truncated = false;
		if ( null !== $payload ) {
			if ( strlen( $payload ) > self::MAX_PAYLOAD_BYTES ) {
				$payload_text      = (string) mb_strcut( $payload, 0, self::MAX_PAYLOAD_BYTES, 'UTF-8' );
				$payload_truncated = true;
			} else {
				$decoded      = json_decode( $payload );
				$pretty       = null === $decoded && 'null' !== trim( $payload ) ? false : wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				$payload_text = false === $pretty ? $payload : $pretty;
			}
		}

		$hit_sampling = (int) $this->settings_service->get( 'hit_sampling' );
		$can_flush    = 'active' === $state;
		require RFRAC_DIR . 'admin/views/entry-details.php';
	}
}
