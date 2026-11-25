<?php
/**
 * Route enumeration of the WooCommerce REST namespaces, with every feature-flagged route family on.
 *
 * Every Store API and `wc/private` route WooCommerce registers must be classified: a public catalog route
 * ({@see Store_Routes::CATALOG}) or a hard-denied one ({@see Deny_List::PATTERNS}), never both. A new or renamed
 * route family therefore fails this suite before the `WC tested up to` header is bumped. Every other WooCommerce
 * namespace must be neither catalog nor cached by default. The hard deny-list also keeps a `wc/agentic` pattern for
 * a WooCommerce that registers that namespace again (see the FLAGS constant), and every registered `wc/agentic` route
 * must be hard-denied, whether or not the WooCommerce under test registers any.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Deny_List;
use Rockfire\Rest_Api_Cache\Config\Route_Discovery;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes;
use Rockfire\Rest_Api_Cache\Services\Route_Service;
use Rockfire\Rest_Api_Cache\Services\Route_View;

/**
 * Classifies every registered WooCommerce route, with the shopper list features on.
 *
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes
 * @covers \Rockfire\Rest_Api_Cache\Config\Deny_List
 * @covers \Rockfire\Rest_Api_Cache\Config\Route_Discovery
 * @covers \Rockfire\Rest_Api_Cache\Services\Route_Service
 */
class Test_Wc_Route_Enumeration extends Rfrac_Test_Case {

	/**
	 * WooCommerce features that register extra REST routes while they are on.
	 *
	 * Checked against WooCommerce 11.3.1, the version CI pins (FeaturesController, ShopperListsController and the
	 * Store API RoutesController): the shopper list routes register under `wc/store/v1` when either flag is on.
	 * WooCommerce 11.2.0 removed the agentic checkout API, so 11.3.1 has no `agentic_checkout` feature and no
	 * `wc/agentic` namespace. Deny_List keeps its `wc/agentic` pattern, so a later WooCommerce that adds the
	 * namespace back is still denied, and {@see self::test_every_agentic_route_is_hard_denied()} fails when it is
	 * not; re-check this list whenever the pinned version changes. WooCommerce 11.3.0 added the hidden, experimental
	 * `ucp` feature (off by default): read-only catalog lookups for AI shopping agents, sent as POST under
	 * `wc/ucp/v1`. They are not cart or checkout routes and a POST is never cached unless a route opts in, so the
	 * namespace is not classified here.
	 *
	 * @var string[]
	 */
	private const FLAGS = array( 'cart_save_for_later', 'product_wishlist' );

	/**
	 * Namespace prefixes whose routes are catalog or hard-denied (WooCommerce 11.3.1).
	 *
	 * @var string[]
	 */
	private const CLASSIFIED_PREFIXES = array( 'wc/store', 'wc/private' );

	/**
	 * Namespace prefix of the removed agentic checkout API, whose routes must be hard-denied if they ever return.
	 *
	 * WooCommerce 11.3.1 registers none, so it is not a classified prefix (those must register routes to classify).
	 *
	 * @var string
	 */
	private const AGENTIC_PREFIX = 'wc/agentic';

	/**
	 * Namespace prefixes of every WooCommerce REST namespace.
	 *
	 * @var string[]
	 */
	private const WOOCOMMERCE_PREFIXES = array( 'wc/', 'wc-', 'wccom-', 'woocommerce' );

	/**
	 * The namespace index routes of the classified namespaces (WooCommerce 11.3.1).
	 *
	 * @var string[]
	 */
	private const INDEX_ROUTES = array( '/wc/store', '/wc/store/v1', '/wc/private' );

	/**
	 * The REST server built with the flags on.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * Option values before the test turned the flags on, by option name; null for an option that did not exist.
	 *
	 * @var array<string,mixed>
	 */
	private array $flag_options = array();

	/**
	 * The `rest_route` query var before the test removed it, null when it was not set.
	 *
	 * @var mixed
	 */
	private mixed $rest_route = null;

	/**
	 * The global REST server before the test replaced it.
	 *
	 * @var mixed
	 */
	private mixed $previous_server = null;

	public function set_up() {
		parent::set_up();

		$features = wc_get_container()->get( FeaturesController::class );
		foreach ( self::FLAGS as $flag ) {
			$option                        = $features->feature_enable_option_name( $flag );
			$this->flag_options[ $option ] = get_option( $option, null );
			$features->change_feature_enable( $flag, true );
		}

		// WooCommerce loads only the namespace of the request, so no `rest_route` query var must be set.
		$wp = $GLOBALS['wp'] ?? null;
		if ( $wp instanceof WP ) {
			$this->rest_route = $wp->query_vars['rest_route'] ?? null;
			unset( $wp->query_vars['rest_route'] );
		}

		$this->previous_server = $GLOBALS['wp_rest_server'] ?? null;
		unset( $GLOBALS['wp_rest_server'] );
		$this->server = rest_get_server();
	}

	public function tear_down() {
		foreach ( $this->flag_options as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value );
			}
		}
		$this->flag_options = array();

		$wp = $GLOBALS['wp'] ?? null;
		if ( $wp instanceof WP && null !== $this->rest_route ) {
			$wp->query_vars['rest_route'] = $this->rest_route;
		}
		$this->rest_route = null;

		parent::tear_down();

		// After the parent, which resets the global server to nothing.
		$GLOBALS['wp_rest_server'] = $this->previous_server;
		$this->previous_server     = null;
	}

	public function test_the_flags_register_the_shopper_list_routes(): void {
		foreach ( self::FLAGS as $flag ) {
			$this->assertTrue( FeaturesUtil::feature_is_enabled( $flag ), "The {$flag} feature is on." );
		}

		$this->assertNotEmpty( $this->shopper_list_routes(), 'The server registers a /wc/store/v1/shopper-lists route.' );
	}

	/**
	 * @dataProvider flag_provider
	 *
	 * @param string $flag One of {@see self::FLAGS}.
	 */
	public function test_each_flag_alone_registers_the_shopper_list_routes( string $flag ): void {
		$features = wc_get_container()->get( FeaturesController::class );
		foreach ( self::FLAGS as $other ) {
			$features->change_feature_enable( $other, $other === $flag );
		}
		$this->assertTrue( FeaturesUtil::feature_is_enabled( $flag ), "The {$flag} feature is on." );

		unset( $GLOBALS['wp_rest_server'] );
		$this->server = rest_get_server();

		$this->assertNotEmpty( $this->shopper_list_routes(), "The {$flag} feature alone registers a /wc/store/v1/shopper-lists route." );
	}

	/**
	 * The feature flags of {@see self::FLAGS}, one per case.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function flag_provider(): array {
		$cases = array();
		foreach ( self::FLAGS as $flag ) {
			$cases[ $flag ] = array( $flag );
		}
		return $cases;
	}

	public function test_every_store_and_private_route_is_catalog_or_hard_denied(): void {
		$unclassified = array();
		$ambiguous    = array();
		$counts       = array_fill_keys( self::CLASSIFIED_PREFIXES, 0 );

		foreach ( $this->classified_routes() as $namespace => $routes ) {
			foreach ( $routes as $route ) {
				$counts[ $this->prefix_of( $namespace ) ] += 1;

				$catalog = Store_Routes::is_catalog( $route );
				$denied  = Deny_List::is_denied( $route );
				if ( $catalog && $denied ) {
					$ambiguous[] = $route;
				} elseif ( ! $catalog && ! $denied ) {
					$unclassified[] = $route;
				}
			}
		}

		foreach ( $counts as $prefix => $count ) {
			$this->assertGreaterThan( 0, $count, "The server registers {$prefix} routes to classify." );
		}
		$this->assertSame(
			array(),
			$unclassified,
			'These routes are neither catalog (Store_Routes::CATALOG) nor hard-denied (Deny_List::PATTERNS): ' . implode( ', ', $unclassified )
		);
		$this->assertSame(
			array(),
			$ambiguous,
			'These routes are both catalog and hard-denied: ' . implode( ', ', $ambiguous )
		);
	}

	public function test_every_agentic_route_is_hard_denied(): void {
		// WooCommerce 11.3.1 registers no wc/agentic route, so none may be required: a later WooCommerce that brings
		// the namespace back must find every one of its routes hard-denied, the namespace index routes included.
		$not_denied = array();
		foreach ( $this->woocommerce_namespaces() as $namespace ) {
			if ( ! str_starts_with( $namespace, self::AGENTIC_PREFIX ) ) {
				continue;
			}
			foreach ( $this->routes_of( $namespace ) as $route ) {
				if ( ! Deny_List::is_denied( $route ) ) {
					$not_denied[] = $route;
				}
			}
		}

		$this->assertSame( array(), $not_denied, 'These ' . self::AGENTIC_PREFIX . ' routes are not hard-denied (Deny_List::PATTERNS): ' . implode( ', ', $not_denied ) );
	}

	public function test_every_catalog_pattern_is_registered(): void {
		$registered = array_map( 'strval', array_keys( $this->server->get_routes() ) );
		$missing    = array_values( array_diff( Store_Routes::CATALOG, $registered ) );

		$this->assertSame( array(), $missing, 'These Store_Routes::CATALOG patterns are not registered by WooCommerce: ' . implode( ', ', $missing ) );
	}

	public function test_route_discovery_marks_every_hard_denied_route_as_denied(): void {
		$infos      = ( new Route_Discovery() )->discover( $this->server );
		$denied     = array();
		$not_denied = array();

		foreach ( $this->classified_routes( true ) as $routes ) {
			foreach ( $routes as $route ) {
				if ( ! Deny_List::is_denied( $route ) ) {
					continue;
				}
				$denied[] = $route;
				if ( ! isset( $infos[ $route ] ) || ! $infos[ $route ]->denied ) {
					$not_denied[] = $route;
				}
			}
		}

		$this->assertNotEmpty( $denied, 'The server registers hard-denied routes.' );
		$this->assertSame( array(), $not_denied, 'Route_Discovery does not flag these hard-denied routes as denied: ' . implode( ', ', $not_denied ) );
	}

	public function test_no_other_woocommerce_route_is_catalog_or_cached_by_default(): void {
		$infos   = ( new Route_Discovery() )->discover( $this->server );
		$views   = array();
		$service = new Route_Service( new Config(), new Route_Discovery() );
		foreach ( $service->all() as $view ) {
			$views[ $view->route ] = $view;
		}

		$namespaces = $this->other_woocommerce_namespaces();
		$this->assertContains( 'wc/v3', $namespaces, 'The wc/v3 namespace is registered.' );

		$catalog     = array();
		$no_view     = array();
		$cached      = array();
		$checked     = 0;
		$view_checks = 0;
		foreach ( $namespaces as $namespace ) {
			foreach ( $this->routes_of( $namespace ) as $route ) {
				++$checked;
				if ( Store_Routes::is_catalog( $route ) ) {
					$catalog[] = $route;
				}

				// Only a route with a GET handler is listed by the service.
				if ( ! isset( $infos[ $route ] ) || ! $infos[ $route ]->readable ) {
					continue;
				}
				++$view_checks;
				$view = $views[ $route ] ?? null;
				if ( ! $view instanceof Route_View ) {
					$no_view[] = $route;
				} elseif ( $view->is_default || $view->enabled ) {
					$cached[] = $route;
				}
			}
		}

		$this->assertGreaterThan( 0, $checked, 'The server registers routes in other WooCommerce namespaces.' );
		$this->assertGreaterThan( 0, $view_checks, 'Some of those routes are readable.' );
		$this->assertSame( array(), $catalog, 'These routes of other WooCommerce namespaces are catalog routes: ' . implode( ', ', $catalog ) );
		$this->assertSame( array(), $no_view, 'Route_Service::all() does not list these readable routes: ' . implode( ', ', $no_view ) );
		$this->assertSame( array(), $cached, 'These routes of other WooCommerce namespaces are cached or enabled by default: ' . implode( ', ', $cached ) );
	}

	public function test_no_namespace_index_route_is_catalog(): void {
		$registered = array_map( 'strval', array_keys( $this->server->get_routes() ) );
		foreach ( self::INDEX_ROUTES as $index ) {
			$this->assertContains( $index, $registered, "The {$index} index route is registered." );
		}

		$catalog = array();
		foreach ( $this->woocommerce_namespaces() as $namespace ) {
			$index = '/' . $namespace;
			$this->assertContains( $index, $registered, "The {$namespace} namespace has an index route." );
			if ( Store_Routes::is_catalog( $index ) ) {
				$catalog[] = $index;
			}
		}

		$this->assertSame( array(), $catalog, 'These namespace index routes are catalog routes: ' . implode( ', ', $catalog ) );
	}

	/**
	 * The `/wc/store/v1/shopper-lists` routes the current server registers.
	 *
	 * @return list<string>
	 */
	private function shopper_list_routes(): array {
		$routes = array_map( 'strval', array_keys( $this->server->get_routes() ) );

		return array_values(
			array_filter(
				$routes,
				static fn( string $route ): bool => str_starts_with( $route, '/wc/store/v1/shopper-lists' )
			)
		);
	}

	/**
	 * The routes of the classified (Store API and private) namespaces, by namespace.
	 *
	 * @param bool $with_index Whether to list the namespace index routes too.
	 *
	 * @return array<string,list<string>>
	 */
	private function classified_routes( bool $with_index = false ): array {
		$out = array();
		foreach ( $this->woocommerce_namespaces() as $namespace ) {
			if ( '' === $this->prefix_of( $namespace ) ) {
				continue;
			}
			$routes = $this->routes_of( $namespace );
			if ( ! $with_index ) {
				$routes = array_values( array_diff( $routes, array( '/' . $namespace ) ) );
			}
			$out[ $namespace ] = $routes;
		}
		return $out;
	}

	/**
	 * The classified prefix a namespace starts with, empty when it starts with none of them.
	 *
	 * @param string $namespace REST namespace.
	 *
	 * @return string
	 */
	private function prefix_of( string $namespace ): string {
		foreach ( self::CLASSIFIED_PREFIXES as $prefix ) {
			if ( str_starts_with( $namespace, $prefix ) ) {
				return $prefix;
			}
		}
		return '';
	}

	/**
	 * Every registered WooCommerce namespace.
	 *
	 * @return list<string>
	 */
	private function woocommerce_namespaces(): array {
		$out = array();
		foreach ( $this->server->get_namespaces() as $namespace ) {
			$namespace = (string) $namespace;
			foreach ( self::WOOCOMMERCE_PREFIXES as $prefix ) {
				if ( str_starts_with( $namespace, $prefix ) ) {
					$out[] = $namespace;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * The registered WooCommerce namespaces that are neither Store API nor private.
	 *
	 * @return list<string>
	 */
	private function other_woocommerce_namespaces(): array {
		return array_values(
			array_filter(
				$this->woocommerce_namespaces(),
				fn( string $namespace ): bool => '' === $this->prefix_of( $namespace )
			)
		);
	}

	/**
	 * The routes registered in a namespace, index route included.
	 *
	 * @param string $namespace REST namespace.
	 *
	 * @return list<string>
	 */
	private function routes_of( string $namespace ): array {
		return array_values( array_map( 'strval', array_keys( $this->server->get_routes( $namespace ) ) ) );
	}
}
