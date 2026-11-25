<?php
/**
 * The Routes admin page: the route manager.
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
use Rockfire\Rest_Api_Cache\Admin\Tables\Routes_Table;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Route_Rule;
use Rockfire\Rest_Api_Cache\Services\Cache_Service;
use Rockfire\Rest_Api_Cache\Services\Route_Service;
use Rockfire\Rest_Api_Cache\Services\Route_View;
use Rockfire\Rest_Api_Cache\Services\Settings_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the routes with their effective policy, handles the bulk actions of the list and the
 * `save_route`, `reset_route`, `flush_route_cache`, `enable_route_preset` and `disable_route_preset` actions, and renders the edit form of one route.
 *
 * `save()` of the route service replaces the whole rule, so the form posts every field. When a save
 * is rejected the entered values are not preserved (documented 1.0 behaviour).
 *
 * @since 1.0.0
 */
class Routes_Page implements Page_Interface {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'rfrac-routes';

	/**
	 * Hidden field of the edit form that says its method boxes were shown, so that no ticked box means no method.
	 *
	 * @var string
	 */
	public const METHODS_SHOWN_FIELD = 'methods_shown';

	/**
	 * Bulk actions of the list that set a status, and the status each one sets.
	 *
	 * The `reset` bulk action removes the overrides instead, like the row action.
	 *
	 * @var array<string,string>
	 */
	private const BULK_STATUS = array(
		'enable'  => Route_Rule::STATUS_ENABLED,
		'exclude' => Route_Rule::STATUS_EXCLUDED, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Bulk action name, not a query argument.
	);

	/**
	 * List arguments kept across filtering, bulk actions and row actions.
	 *
	 * @var string[]
	 */
	private const LIST_ARGS = array( 'view', 'namespace', 's', 'orderby', 'order', 'paged' );

	/**
	 * Flash notices.
	 *
	 * @var Notices
	 */
	private Notices $notices;

	/**
	 * Route service.
	 *
	 * @var Route_Service
	 */
	private Route_Service $routes;

	/**
	 * Cache service.
	 *
	 * @var Cache_Service
	 */
	private Cache_Service $cache;

	/**
	 * Settings service.
	 *
	 * @var Settings_Service
	 */
	private Settings_Service $settings;

	/**
	 * The list table, built when first needed.
	 *
	 * @var Routes_Table|null
	 */
	private ?Routes_Table $table = null;

	/**
	 * Constructor.
	 *
	 * @param Notices          $notices  Flash notices.
	 * @param Route_Service    $routes   Route service.
	 * @param Cache_Service    $cache    Cache service.
	 * @param Settings_Service $settings Settings service.
	 */
	public function __construct( Notices $notices, Route_Service $routes, Cache_Service $cache, Settings_Service $settings ) {
		$this->notices  = $notices;
		$this->routes   = $routes;
		$this->cache    = $cache;
		$this->settings = $settings;

		// WordPress saves screen options before the load action of the page, so the filter is registered up front.
		add_filter( 'set_screen_option_' . Routes_Table::PER_PAGE_OPTION, array( self::class, 'sanitize_per_page' ), 10, 3 );
	}

	/**
	 * Clamp a saved "routes per page" screen option to 1..200.
	 *
	 * @param mixed $screen_option Value so far (false = not handled).
	 * @param mixed $option        Option name.
	 * @param mixed $value         Submitted value.
	 *
	 * @return int
	 */
	public static function sanitize_per_page( $screen_option, $option, $value ): int {
		unset( $screen_option, $option );
		return max( 1, min( Routes_Table::MAX_PER_PAGE, absint( is_scalar( $value ) ? $value : 0 ) ) );
	}

	/**
	 * Page slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function page_title(): string {
		return __( 'Routes', 'rockfire-rest-api-cache' );
	}

	/**
	 * Menu title.
	 *
	 * @return string
	 */
	public function menu_title(): string {
		return __( 'Routes', 'rockfire-rest-api-cache' );
	}

	/**
	 * Menu position; the lowest, so this is the landing page.
	 *
	 * @return int
	 */
	public function position(): int {
		return 10;
	}

	/**
	 * Screen option and the bulk actions of the list.
	 *
	 * The bulk action comes from `current_action()` of the list table, which ignores the action
	 * selects when the "Filter" button was used.
	 *
	 * @return void
	 */
	public function load(): void {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Routes per page', 'rockfire-rest-api-cache' ),
				'default' => Routes_Table::DEFAULT_PER_PAGE,
				'max'     => Routes_Table::MAX_PER_PAGE,
				'option'  => Routes_Table::PER_PAGE_OPTION,
			)
		);

		$action = $this->table()->current_action();
		if ( false === $action && ! Request::has( 'filter_action' ) ) {
			// The select below the table.
			$action = Request::key( 'action2', '-1' );
		}
		$action = is_string( $action ) ? $action : '';
		if ( 'reset' === $action || isset( self::BULK_STATUS[ $action ] ) ) {
			$this->bulk( $action );
		}
	}

	/**
	 * Handle a bulk action: nonce, ids, write(s), notice, redirect back to the list as it was.
	 *
	 * @param string $action `enable`, `exclude` or `reset`.
	 *
	 * @return never
	 */
	private function bulk( string $action ): never {
		check_admin_referer( 'bulk-rfrac_routes' );

		$ids     = Request::key_list( 'route' );
		$skipped = 0;
		foreach ( $ids as $id ) {
			$view = $this->routes->find( $id );
			if ( null === $view || $view->is_denied || $view->locked || ! $view->is_registered ) {
				++$skipped;
			}
		}

		$needs_methods = 0;
		$not_changed   = 0; // Counted above; only the routes that need their methods chosen are new.
		$result        = 'reset' === $action ? $this->bulk_reset( $ids ) : $this->routes->set_status( $ids, self::BULK_STATUS[ $action ], $not_changed, $needs_methods );

		if ( is_wp_error( $result ) ) {
			$this->notices->error( $result->get_error_message() );
		} else {
			/* translators: %d: number of routes. */
			$message = sprintf( _n( '%d route updated.', '%d routes updated.', $result, 'rockfire-rest-api-cache' ), $result );
			$this->notices->success( $message . $this->skipped_note( $skipped, $needs_methods ) );
		}

		Redirect::safe_and_exit( $this->list_url( $this->request_list_args() ) );
	}

	/**
	 * Remove the overrides of several routes, exactly like the row action "Reset" (one write per route).
	 *
	 * @param string[] $ids Route ids.
	 * @phpstan-param list<string> $ids
	 *
	 * @return int|\WP_Error Number of routes reset, or the first failed write's error.
	 */
	private function bulk_reset( array $ids ): int|\WP_Error {
		$count = 0;
		foreach ( $ids as $id ) {
			$view = $this->routes->find( $id );
			if ( null === $view || $view->is_denied || $view->locked || ! $view->is_registered ) {
				continue;
			}
			$result = $this->routes->reset( $id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$count += true === $result ? 1 : 0;
		}
		return $count;
	}

	/**
	 * The list arguments of the current request.
	 *
	 * @return array<string,mixed>
	 */
	private function request_list_args(): array {
		$args = array();
		foreach ( self::LIST_ARGS as $key ) {
			$args[ $key ] = Request::text( $key );
		}
		return $args;
	}

	/**
	 * The list URL with the sanitised view, namespace, search, sorting and page of a set of arguments.
	 *
	 * @param array<string,mixed>|null $raw Raw arguments (the request, or the referring list page); null for none.
	 *
	 * @return string
	 */
	private function list_url( ?array $raw = null ): string {
		$raw  = $raw ?? array();
		$text = static fn( string $key ): string => isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? sanitize_text_field( (string) $raw[ $key ] ) : '';
		$args = array();

		$view = sanitize_key( $text( 'view' ) );
		if ( in_array( $view, Route_Service::VIEWS, true ) && 'all' !== $view ) {
			$args['view'] = $view;
		}
		if ( '' !== $text( 'namespace' ) ) {
			$args['namespace'] = $text( 'namespace' );
		}
		if ( '' !== $text( 's' ) ) {
			$args['s'] = $text( 's' );
		}
		$orderby = sanitize_key( $text( 'orderby' ) );
		if ( in_array( $orderby, array( 'route', 'namespace' ), true ) ) {
			$args['orderby'] = $orderby;
			$args['order']   = 'desc' === sanitize_key( $text( 'order' ) ) ? 'desc' : 'asc';
		}
		$paged = absint( $text( 'paged' ) );
		if ( $paged > 1 ) {
			$filter = array(
				'view'      => $args['view'] ?? 'all',
				'namespace' => $args['namespace'] ?? '',
				'search'    => $args['s'] ?? '',
			);
			$pages  = (int) ceil( count( $this->routes->all( $filter ) ) / $this->table()->per_page() );
			$paged  = min( $paged, max( 1, $pages ) );
			if ( $paged > 1 ) {
				$args['paged'] = $paged;
			}
		}
		return Admin_Url::page( self::SLUG, $args );
	}

	/**
	 * The list URL to return to after a row action: the referring list page as it was, else the plain list.
	 *
	 * @return string
	 */
	private function return_url(): string {
		return $this->list_url( Admin_Url::referer_args( self::SLUG ) );
	}

	/**
	 * The edit URL of a route.
	 *
	 * @param string $route_id Route id.
	 *
	 * @return string
	 */
	private function edit_url( string $route_id ): string {
		return Admin_Url::page(
			self::SLUG,
			array(
				'action'   => 'edit',
				'route_id' => $route_id,
			)
		);
	}

	/**
	 * The list table.
	 *
	 * @return Routes_Table
	 */
	private function table(): Routes_Table {
		return $this->table ??= new Routes_Table( $this->routes, $this->settings );
	}

	/**
	 * Print the list or the edit form.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( 'edit' === Request::key( 'action' ) ) {
			$this->render_edit( Request::key( 'route_id' ) );
			return;
		}

		$table = $this->table();
		$table->prepare_items();
		$page_slug = self::SLUG;
		$view      = $table->filter()['view'];
		$presets   = $this->routes->presets();
		$enabled   = array();
		foreach ( array_keys( $presets ) as $preset_key ) {
			$enabled[ $preset_key ] = $this->routes->preset_enabled( (string) $preset_key );
		}
		include RFRAC_DIR . 'admin/views/routes.php';
	}

	/**
	 * Print the edit form of a route.
	 *
	 * @param string $route_id Route id.
	 *
	 * @return void
	 */
	private function render_edit( string $route_id ): void {
		$route = $this->routes->find( $route_id );
		if ( null === $route ) {
			Notices::output( 'error', __( 'Route not found.', 'rockfire-rest-api-cache' ) );
			printf( '<p><a href="%s">%s</a></p>', esc_url( Admin_Url::page( self::SLUG ) ), esc_html__( 'Back to the routes', 'rockfire-rest-api-cache' ) );
			return;
		}

		$rule          = Routes_Table::rule_of( $route );
		$settings      = $this->settings->all_effective();
		$read_only     = $route->is_denied || $route->locked;
		$list_url      = Admin_Url::page( self::SLUG );
		$post_types    = self::choices( get_post_types( array( 'show_in_rest' => true ), 'objects' ), $rule->flush_post_types );
		$taxonomies    = self::choices( get_taxonomies( array( 'show_in_rest' => true ), 'objects' ), $rule->flush_taxonomies );
		$routes_const  = Config::ROUTES_CONSTANT;
		$arguments     = self::arguments_by_method( $route );
		$default_ttl   = (int) ( $settings['default_ttl'] ?? 0 );
		$max_ttl       = (int) ( $settings['max_ttl'] ?? 0 );
		$max_params    = (int) ( $settings['max_params'] ?? 0 );
		$global_ignore = self::names( $settings['global_ignore_params'] ?? array() );
		$global_vary   = self::names( $settings['global_vary_headers'] ?? array() );
		include RFRAC_DIR . 'admin/views/route-edit.php';
	}

	/**
	 * The methods a rule of a route can cache, with the registered argument names of each.
	 *
	 * The methods the route registers; a core `wp/v2` route only offers GET, which is also shown when the route has
	 * no method to offer, so that the screen always has its GET arguments.
	 *
	 * @param Route_View $route Route.
	 *
	 * @return array<string,list<string>> Method => registered argument names, in method order.
	 */
	private static function arguments_by_method( Route_View $route ): array {
		$methods = $route->is_core() ? array_values( array_intersect( $route->methods, array( 'GET' ) ) ) : $route->methods;
		if ( array() === $methods ) {
			$methods = Route_Rule::DEFAULT_METHODS;
		}

		$out = array();
		foreach ( $methods as $method ) {
			$out[ $method ] = $route->method_args[ $method ] ?? ( 'GET' === $method ? $route->args : array() );
		}
		return $out;
	}

	/**
	 * The names of a list setting.
	 *
	 * @param mixed $value Setting value.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private static function names( mixed $value ): array {
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	/**
	 * Name => label choices from registered objects plus the names a rule already has.
	 *
	 * @param array<int|string,object> $objects Post type or taxonomy objects.
	 * @param string[]                 $current Names in the rule.
	 *
	 * @return array<string,string>
	 */
	private static function choices( array $objects, array $current ): array {
		$choices = array();
		foreach ( $objects as $name => $object ) {
			$label            = isset( $object->labels->name ) && is_string( $object->labels->name ) ? $object->labels->name : (string) $name;
			$choices[ $name ] = $label;
		}
		foreach ( $current as $name ) {
			$choices[ $name ] ??= $name;
		}
		return $choices;
	}

	/**
	 * The admin-post actions.
	 *
	 * @return array<string,callable>
	 */
	public function actions(): array {
		return array(
			'save_route'           => array( $this, 'save_route' ),
			'reset_route'          => array( $this, 'reset_route' ),
			'flush_route_cache'    => array( $this, 'flush_route' ),
			'enable_route_preset'  => array( $this, 'enable_route_preset' ),
			'disable_route_preset' => array( $this, 'disable_route_preset' ),
		);
	}

	/**
	 * Save the rule posted for a route.
	 *
	 * A browser posts nothing for method boxes that are all unticked, so the edit form also posts the hidden field
	 * {@see self::METHODS_SHOWN_FIELD}: with it, no `methods` means that none was chosen (which the service refuses
	 * for an enabled rule); without it the form had no method boxes and the rule caches GET.
	 *
	 * @param string $route_id Route id.
	 *
	 * @return string Redirect URL.
	 */
	public function save_route( string $route_id ): string {
		$ttl     = Request::text( 'ttl' );
		$methods = Request::text_list( 'methods' );
		$input   = array(
			'status'           => Request::key( 'status', Route_Rule::STATUS_DEFAULT ),
			'ttl'              => '' === $ttl ? null : Request::int( 'ttl' ),
			'param_mode'       => Request::key( 'param_mode', Route_Rule::PARAM_REGISTERED ),
			'ignore_params'    => Request::text_list( 'ignore_params' ),
			'bypass_params'    => Request::text_list( 'bypass_params' ),
			'vary_headers'     => Request::text_list( 'vary_headers' ),
			'flush_post_types' => Request::key_list( 'flush_post_types' ),
			'flush_taxonomies' => Request::key_list( 'flush_taxonomies' ),
		);
		if ( array() !== $methods || Request::has( self::METHODS_SHOWN_FIELD ) ) {
			$input['methods'] = $methods;
		}

		$adjusted_ttl = null;
		$result       = $this->routes->save( $route_id, $input, $adjusted_ttl );
		if ( is_wp_error( $result ) ) {
			$this->notices->error( $this->message( $result ) );
			return $this->edit_url( $route_id );
		}
		$this->notices->success( __( 'Route saved.', 'rockfire-rest-api-cache' ) );
		if ( null !== $adjusted_ttl ) {
			/* translators: %d: TTL in seconds. */
			$this->notices->warning( sprintf( __( 'TTL adjusted to %d seconds.', 'rockfire-rest-api-cache' ), $adjusted_ttl ) );
		}
		return Admin_Url::page( self::SLUG );
	}

	/**
	 * Remove the override of a route.
	 *
	 * @param string $route_id Route id.
	 *
	 * @return string Redirect URL.
	 */
	public function reset_route( string $route_id ): string {
		$result = $this->routes->reset( $route_id );
		if ( is_wp_error( $result ) ) {
			$this->notices->error( $this->message( $result ) );
		} elseif ( true === $result ) {
			$this->notices->success( __( 'Route reset to defaults.', 'rockfire-rest-api-cache' ) );
		} else {
			$this->notices->info( __( 'This route already uses its defaults.', 'rockfire-rest-api-cache' ) );
		}
		return $this->return_url();
	}

	/**
	 * Flush the cached responses of a route.
	 *
	 * @param string $route_id Route id.
	 *
	 * @return string Redirect URL.
	 */
	public function flush_route( string $route_id ): string {
		$route = $this->routes->find( $route_id );
		if ( null === $route ) {
			$this->notices->error( __( 'Route not found.', 'rockfire-rest-api-cache' ) );
			return $this->return_url();
		}

		$result = $this->cache->flush_route( $route->route );
		if ( is_wp_error( $result ) ) {
			$this->notices->error( $this->message( $result ) );
		} else {
			$count = $result;
			$this->notices->success(
				/* translators: %d: number of cached responses. */
				sprintf( _n( 'Flushed %d cached response of this route.', 'Flushed %d cached responses of this route.', $count, 'rockfire-rest-api-cache' ), $count )
			);
		}
		return $this->return_url();
	}

	/**
	 * Enable the routes of a route preset.
	 *
	 * @param string $key Preset key.
	 *
	 * @return string Redirect URL.
	 */
	public function enable_route_preset( string $key ): string {
		$skipped       = 0;
		$needs_methods = 0;
		$result        = $this->routes->enable_preset( $key, $skipped, $needs_methods );
		if ( is_wp_error( $result ) ) {
			$this->notices->error( 'rfrac_preset_not_found' === $result->get_error_code() ? __( 'Unknown route preset.', 'rockfire-rest-api-cache' ) : $this->message( $result ) );
			return $this->return_url();
		}

		$label        = $this->routes->presets()[ $key ]['label'] ?? $key;
		$skipped_note = $this->skipped_note( $skipped - $needs_methods, $needs_methods ); // The service counts the routes that need methods among the skipped ones.

		if ( 0 === $result && 0 === $skipped ) {
			$this->notices->info(
				/* translators: %s: name of a group of routes. */
				sprintf( __( 'Every registered route of %s is already enabled.', 'rockfire-rest-api-cache' ), $label )
			);
		} elseif ( 0 === $result ) {
			$this->notices->warning(
				/* translators: %s: name of a group of routes. */
				sprintf( __( 'No routes of %s were enabled.', 'rockfire-rest-api-cache' ), $label ) . $skipped_note
			);
		} else {
			$this->notices->success(
				/* translators: 1: number of routes, 2: name of a group of routes. */
				sprintf( _n( 'Enabled %1$d route of %2$s.', 'Enabled %1$d routes of %2$s.', $result, 'rockfire-rest-api-cache' ), $result, $label ) . $skipped_note
			);
		}
		return $this->return_url();
	}

	/**
	 * Return the routes of a route preset to their default policy.
	 *
	 * @param string $key Preset key.
	 *
	 * @return string Redirect URL.
	 */
	public function disable_route_preset( string $key ): string {
		$skipped = 0;
		$result  = $this->routes->disable_preset( $key, $skipped );
		if ( is_wp_error( $result ) ) {
			$this->notices->error( 'rfrac_preset_not_found' === $result->get_error_code() ? __( 'Unknown route preset.', 'rockfire-rest-api-cache' ) : $this->message( $result ) );
			return $this->return_url();
		}

		$label = $this->routes->presets()[ $key ]['label'] ?? $key;
		if ( 0 === $result ) {
			$this->notices->info(
				/* translators: %s: name of a group of routes. */
				sprintf( __( 'No route of %s is enabled.', 'rockfire-rest-api-cache' ), $label ) . $this->skipped_note( $skipped )
			);
		} else {
			$this->notices->success(
				/* translators: 1: number of routes, 2: name of a group of routes. */
				sprintf( _n( 'Disabled %1$d route of %2$s.', 'Disabled %1$d routes of %2$s.', $result, 'rockfire-rest-api-cache' ), $result, $label ) . $this->skipped_note( $skipped )
			);
		}
		return $this->return_url();
	}

	/**
	 * The sentences about the routes of a bulk action or a preset that were skipped, each with a leading space; empty
	 * for none.
	 *
	 * The routes that can not be changed at all and the ones that only need their methods chosen on their edit screen
	 * (for example a POST-only route) are told apart, because the admin has a next step for the second kind.
	 *
	 * @param int $skipped       Number of routes that could not be changed.
	 * @param int $needs_methods Number of routes skipped because their methods have to be chosen on their edit screen first.
	 *
	 * @return string
	 */
	private function skipped_note( int $skipped, int $needs_methods = 0 ): string {
		$note = '';
		if ( $skipped > 0 ) {
			$note .= ' ' . sprintf(
				/* translators: %d: number of routes. */
				_n( '%d route was skipped because it can not be changed.', '%d routes were skipped because they can not be changed.', $skipped, 'rockfire-rest-api-cache' ),
				$skipped
			);
		}
		if ( $needs_methods > 0 ) {
			$note .= ' ' . sprintf(
				/* translators: %d: number of routes. */
				_n( '%d route was skipped because it needs its methods chosen on its edit screen.', '%d routes were skipped because they need their methods chosen on their edit screens.', $needs_methods, 'rockfire-rest-api-cache' ),
				$needs_methods
			);
		}
		return $note;
	}

	/**
	 * The message of an error; "Route not found." for an unknown route.
	 *
	 * @param \WP_Error $error Error.
	 *
	 * @return string
	 */
	private function message( \WP_Error $error ): string {
		if ( 'rfrac_route_not_found' === $error->get_error_code() ) {
			return __( 'Route not found.', 'rockfire-rest-api-cache' );
		}
		return $error->get_error_message();
	}
}
