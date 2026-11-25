<?php
/**
 * The WooCommerce integration module.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Integrations\WooCommerce;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the WooCommerce components into the engine, and only on a site where WooCommerce has loaded.
 *
 * The container creates it from {@see Plugin::register_integrations()} once `woocommerce_loaded` has fired.
 * Without WooCommerce a site behaves exactly as before; the one exception is a multisite request, where
 * {@see self::register_network_events()} wires the network-wide plugin changes, which also concern the sites
 * that run WooCommerce while the request's own site does not.
 *
 * The rule for every component: it adds hooks only through the `$on` callable it is handed (the guarded,
 * recorded engine hook helper of the container), never calls a WooCommerce function while it registers
 * and never calls `add_action()` or `add_filter()` itself. WooCommerce is touched only when one of
 * those hooks fires.
 *
 * @since 1.0.0
 */
final class Module {

	/**
	 * The container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Adds a guarded engine hook: `fn( string $hook, \Closure $callback, int $priority = 10, int $args = 1 ): void`.
	 *
	 * @var \Closure
	 * @phpstan-var \Closure(string, \Closure, int=, int=): void
	 */
	private \Closure $on;

	/**
	 * Whether WooCommerce has loaded: its `woocommerce_loaded` action has fired.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return did_action( 'woocommerce_loaded' ) > 0;
	}

	/**
	 * Create the module.
	 *
	 * @param Plugin   $plugin The container.
	 * @param \Closure $on     Adds a guarded engine hook; forwards to the container's hook helper.
	 *
	 * @phpstan-param \Closure(string, \Closure, int=, int=): void $on
	 */
	public function __construct( Plugin $plugin, \Closure $on ) {
		$this->plugin = $plugin;
		$this->on     = $on;
	}

	/**
	 * The container the module is wired into.
	 *
	 * @return Plugin
	 */
	public function plugin(): Plugin {
		return $this->plugin;
	}

	/**
	 * Register every component of the module.
	 *
	 * Each component has one private `register_*()` method, called from here.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_admin_store_api();
		$this->register_compatibility();
		$this->register_partial_server();
		$this->register_route_presets();
		$this->register_health();
		$this->register_location_pricing();
		$this->register_currency_cookies();
		$this->register_store_api_tagger();
		$this->register_product_events();
		$this->register_catalog_events();
		$this->register_price_events();
	}

	/**
	 * Register the network-wide plugin changes on a multisite request where WooCommerce did not load.
	 *
	 * Used instead of {@see self::register()}. The network admin loads the plugins of the main site only, yet a
	 * network-wide activation or deactivation walks every site, so the price tag of the sites that run WooCommerce
	 * must be flushed although the main site does not.
	 *
	 * @return void
	 */
	public function register_network_events(): void {
		$listener = new Woo_Price_Events( $this->plugin->invalidator() );
		$this->add_hooks( $listener, self::declared_hooks( $listener, 'network_hooks' ) );
	}

	/**
	 * Register the Store API when the plugin reads the routes in a wp-admin request, where WooCommerce does not initialise it.
	 *
	 * Without it the route manager, the catalog preset, the settings-save compile and Site Health would see
	 * no Store API routes in wp-admin. Other admin requests are left alone. See {@see Admin_Store_Api}.
	 *
	 * The component calls WooCommerce internals, each guarded: it checks that they exist,
	 * records a `woocommerce:store_api` failure instead of fataling when one is missing or throws (the request
	 * then runs with no Store API routes), and never initialises the Store API when WooCommerce or another
	 * container already did, which it detects from the session handler callback the Store API adds. Every
	 * WooCommerce bump re-verifies that detector.
	 *
	 * @return void
	 */
	private function register_admin_store_api(): void {
		if ( ! is_admin() ) {
			return;
		}
		$this->subscribe( new Admin_Store_Api() );
	}

	/**
	 * Declare compatibility with the WooCommerce features this plugin supports.
	 *
	 * The plugin does not touch orders, so it is compatible with the custom order tables (HPOS), and it
	 * caches only the public Store API, so it is compatible with the cart and checkout blocks.
	 * WooCommerce accepts a declaration only while `before_woocommerce_init` runs, so nothing is declared
	 * here, at registration time.
	 *
	 * @return void
	 */
	private function register_compatibility(): void {
		( $this->on )(
			'before_woocommerce_init',
			static function (): void {
				foreach ( [ 'custom_order_tables', 'cart_checkout_blocks' ] as $feature ) {
					FeaturesUtil::declare_compatibility( $feature, RFRAC_FILE, true );
				}
			},
			10,
			0
		);
	}

	/**
	 * Answer the engine's partial server filter for WooCommerce's lazily loaded namespaces.
	 *
	 * In wp-admin the answer is also true while the live REST server carries no `wc/store/v1` namespace, so only the
	 * plugin's own discovery path compiles in admin and no foreign admin request recompiles without the catalog
	 * routes. The admin context is read when the filter runs, not here.
	 *
	 * @see Partial_Server
	 *
	 * @return void
	 */
	private function register_partial_server(): void {
		( $this->on )(
			Hooks::PARTIAL_SERVER,
			static fn( mixed $partial = false, mixed $server = null, mixed $route = '' ): bool => Partial_Server::filter( $partial, $server, $route ),
			10,
			3
		);
	}

	/**
	 * Offer the `woocommerce-catalog` route preset: the Routes page then shows "Enable WooCommerce catalog".
	 *
	 * The preset lists exactly {@see Store_Routes::CATALOG}. It only lists routes: nothing is enabled until an
	 * administrator uses the button (catalog routes stay opt-in).
	 *
	 * @return void
	 */
	private function register_route_presets(): void {
		( $this->on )(
			Hooks::ROUTE_PRESETS,
			static function ( mixed $presets ): array {
				$presets                        = is_array( $presets ) ? $presets : [];
				$presets['woocommerce-catalog'] = [
					'label'  => __( 'WooCommerce catalog', 'rockfire-rest-api-cache' ),
					'routes' => Store_Routes::CATALOG,
				];
				return $presets;
			}
		);
	}

	/**
	 * Add the WooCommerce section to the Site Health debug information, on admin requests only.
	 *
	 * @return void
	 */
	private function register_health(): void {
		if ( ! is_admin() ) {
			return;
		}

		( $this->on )( 'debug_information', fn( mixed $info ): array => ( new Health( $this->plugin ) )->debug_information( $info ) );
	}

	/**
	 * Register the location pricing component: the visitor-variance seam and the store-time guard of the price-bearing routes.
	 *
	 * @return void
	 */
	private function register_location_pricing(): void {
		$this->subscribe( new Location_Pricing() );
	}

	/**
	 * Answer the engine's vary-cookie seam with the currency-cookie declaration of the site.
	 *
	 * @return void
	 */
	private function register_currency_cookies(): void {
		$this->subscribe( new Currency_Cookies() );
	}

	/**
	 * Tag the Store API catalog responses with everything they show.
	 *
	 * @return void
	 */
	private function register_store_api_tagger(): void {
		$this->subscribe( new Store_Api_Tagger( $this->plugin->tag_provider() ) );
	}

	/**
	 * Register the product, variation, stock and sale invalidation listener.
	 *
	 * @return void
	 */
	private function register_product_events(): void {
		$this->subscribe( new Woo_Product_Events( $this->plugin->invalidator() ) );
	}

	/**
	 * Register the catalog events: attribute terms, attributes, product reviews and the term meta behind recounts.
	 *
	 * @return void
	 */
	private function register_catalog_events(): void {
		$this->subscribe( new Woo_Catalog_Events( $this->plugin->invalidator() ) );
	}

	/**
	 * The price events: tax, price and catalog option, plugin and theme changes flush the shared price tag.
	 *
	 * @return void
	 */
	private function register_price_events(): void {
		$this->subscribe( new Woo_Price_Events( $this->plugin->invalidator() ) );
	}

	/**
	 * Register the hooks a component declares in its static `hooks()` (the shape of {@see Hook_Subscriber_Trait::hooks()}).
	 *
	 * Every entry is added through `$on`, with the entry's bound arguments passed before the hook's own arguments.
	 * The component must itself use {@see Hook_Subscriber_Trait}, and all its entries are checked against the
	 * contract of the trait before the first one is registered.
	 *
	 * @param object $listener The component.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the component does not use the trait or declares an entry that breaks its contract.
	 */
	public function subscribe( object $listener ): void {
		$this->add_hooks( $listener, self::declared_hooks( $listener ) );
	}

	/**
	 * Add checked hook entries of a component through `$on`.
	 *
	 * @param object                                                   $listener The component.
	 * @param list<array{0:string,1:string,2:int,3:int,4:list<mixed>}> $entries  The entries, as {@see self::declared_hooks()} returns them.
	 *
	 * @return void
	 */
	private function add_hooks( object $listener, array $entries ): void {
		foreach ( $entries as [ $hook, $method, $priority, $args, $bound ] ) {
			( $this->on )( $hook, fn( ...$params ) => $listener->{$method}( ...$bound, ...$params ), $priority, $args );
		}
	}

	/**
	 * The hook entries a component declares, each one checked against the {@see Hook_Subscriber_Trait::hooks()} contract.
	 *
	 * A trait cannot be required by a type, and PHPStan does not compare the declared return type of a static method with
	 * the contract it implements, so the shape is verified here instead of being asserted.
	 *
	 * @param object $listener The component.
	 * @param string $source   The static method that declares the entries, `hooks` unless a component declares a second set.
	 *
	 * @return array<int,array<int,mixed>> `[ hook, method, priority, accepted args, bound args ]` entries.
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4:list<mixed>}>
	 *
	 * @throws \InvalidArgumentException When the component does not use the trait or declares an entry that breaks its contract.
	 */
	private static function declared_hooks( object $listener, string $source = 'hooks' ): array {
		$callable = [ $listener, $source ];
		if ( ! in_array( Hook_Subscriber_Trait::class, class_uses( $listener ), true ) || ! is_callable( $callable ) ) {
			throw new \InvalidArgumentException( 'A WooCommerce component must use Hook_Subscriber_Trait.' );
		}

		$declared = call_user_func( $callable );
		$declared = is_array( $declared ) ? $declared : [];
		$entries  = [];
		foreach ( $declared as $entry ) {
			$entry    = is_array( $entry ) ? $entry : [];
			$hook     = $entry[0] ?? null;
			$method   = $entry[1] ?? null;
			$priority = $entry[2] ?? null;
			$args     = $entry[3] ?? null;
			$bound    = $entry[4] ?? [];
			if ( ! is_string( $hook ) || ! is_string( $method ) || ! is_int( $priority ) || ! is_int( $args ) || ! is_array( $bound ) || ! array_is_list( $bound ) ) {
				throw new \InvalidArgumentException( sprintf( '%s::%s() must return a list of [ hook, method, priority, accepted args, bound args? ] entries.', $listener::class, esc_html( $source ) ) );
			}
			$entries[] = [ $hook, $method, $priority, $args, $bound ];
		}

		return $entries;
	}
}
