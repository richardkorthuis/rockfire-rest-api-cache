<?php
/**
 * The WooCommerce section of the Site Health debug information.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Integrations\WooCommerce;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Compiled_Route;
use Rockfire\Rest_Api_Cache\Config\Route_Rule;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Rockfire REST API Cache – WooCommerce" section to Site Health → Info.
 *
 * {@see Module::register()} hooks {@see self::debug_information()} on admin requests only. The section is
 * built from {@see self::fields()}, the single method the other WooCommerce components append their rows to.
 * Nothing here runs unless the Site Health screen asks for the debug information.
 *
 * @since 1.0.0
 */
final class Health {

	/**
	 * Key of the section in the debug information (a sibling of the core section `rockfire-rest-api-cache`).
	 *
	 * @var string
	 */
	public const DEBUG_SECTION = 'rockfire-rest-api-cache-woocommerce';

	/**
	 * The container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Create the section.
	 *
	 * @param Plugin $plugin The container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Add the section to the debug information (filter `debug_information`).
	 *
	 * @param mixed $info The debug information of the other sections; anything but an array counts as empty.
	 *
	 * @return array<mixed> The debug information with this section added.
	 */
	public function debug_information( mixed $info ): array {
		$info = is_array( $info ) ? $info : [];

		$info[ self::DEBUG_SECTION ] = [
			'label'  => __( 'Rockfire – REST API Cache: WooCommerce', 'rockfire-rest-api-cache' ),
			'fields' => $this->fields(),
		];

		return $info;
	}

	/**
	 * The fields of the section, in display order.
	 *
	 * Components of the WooCommerce module that report their own status append their rows here.
	 *
	 * @return array<string,array{label:string,value:string|int,debug?:mixed}>
	 */
	public function fields(): array {
		$hpos = OrderUtil::custom_orders_table_usage_is_enabled();

		$fields = [
			'version'        => [
				'label' => __( 'WooCommerce version', 'rockfire-rest-api-cache' ),
				'value' => WC_VERSION,
			],
			'tested_up_to'   => [
				'label' => __( 'WooCommerce tested up to', 'rockfire-rest-api-cache' ),
				'value' => $this->tested_up_to(),
			],
			'hpos'           => [
				'label' => __( 'High-performance order storage', 'rockfire-rest-api-cache' ),
				'value' => $hpos ? __( 'Enabled', 'rockfire-rest-api-cache' ) : __( 'Disabled', 'rockfire-rest-api-cache' ),
				'debug' => $hpos,
			],
			'catalog_routes' => [
				'label' => __( 'Catalog routes', 'rockfire-rest-api-cache' ),
				'value' => $this->catalog_routes(),
			],
			'snapshot'       => [
				'label' => __( 'Route snapshot', 'rockfire-rest-api-cache' ),
				'value' => $this->snapshot(),
			],
		];

		$fields['per_visitor_pricing'] = $this->per_visitor_pricing();

		return $fields;
	}

	/**
	 * The WooCommerce version this plugin declares to be tested with: its `WC tested up to` plugin header.
	 *
	 * @return string
	 */
	private function tested_up_to(): string {
		$data   = get_file_data( RFRAC_FILE, [ 'tested_up_to' => 'WC tested up to' ] );
		$tested = $data['tested_up_to'] ?? '';

		return '' === $tested ? __( 'Not declared', 'rockfire-rest-api-cache' ) : $tested;
	}

	/**
	 * How many of the registered Store API catalog routes are enabled.
	 *
	 * @return string
	 */
	private function catalog_routes(): string {
		$enabled    = 0;
		$registered = 0;
		foreach ( $this->plugin->route_service()->all() as $view ) {
			if ( ! $view->is_registered || ! Store_Routes::is_catalog( $view->route ) ) {
				continue;
			}
			++$registered;
			if ( $view->enabled ) {
				++$enabled;
			}
		}

		/* translators: 1: number of enabled WooCommerce catalog routes, 2: number of registered WooCommerce catalog routes. */
		return sprintf( __( '%1$d of %2$d enabled', 'rockfire-rest-api-cache' ), $enabled, $registered );
	}

	/**
	 * Whether the compiled route snapshot is current, or still waits for a route discovery.
	 *
	 * A snapshot that was marked stale (its fingerprint blanked) waits like a pending one: the compiler
	 * keeps serving it until a request outside the WooCommerce namespaces or a settings save recompiles it.
	 *
	 * @return string
	 */
	private function snapshot(): string {
		$config = ( $this->plugin->compiled_config() )();
		if ( null === $config || $config->pending_discovery() || '' === $config->fingerprint() ) {
			return __( 'Pending route discovery: refreshes on the next REST request outside the WooCommerce namespaces or when the settings are saved', 'rockfire-rest-api-cache' );
		}

		return __( 'Current', 'rockfire-rest-api-cache' );
	}

	/**
	 * The per-visitor pricing row: whether products and collection data are cached or bypassed, why, and which currency cookies are declared.
	 *
	 * What the cache does follows the compiled snapshot, and only its enabled price-bearing routes count. An invalid
	 * vary declaration bypasses them whatever the live status is, and is reported as such, naming what is wrong: the
	 * currency-cookie declaration, or the global vary headers (unusable, or too many together with a route's own).
	 * Else the status is the live one ({@see Location_Pricing::status()}) and the cookies are the compiled
	 * declaration; a live status that differs from the snapshot is flagged as taking effect after the next recompile.
	 *
	 * @return array{label:string,value:string,debug:string}
	 */
	private function per_visitor_pricing(): array {
		$status = Location_Pricing::status();
		$config = ( $this->plugin->compiled_config() )();
		$routes = $this->price_routes( $config );
		$label  = __( 'Per-visitor pricing', 'rockfire-rest-api-cache' );

		$cause = $this->invalid_cause( $routes );
		if ( null !== $cause ) {
			return [
				'label' => $label,
				'value' => $this->invalid_text( $cause ),
				'debug' => match ( $cause ) {
					Compiled_Route::VARY_GLOBAL_HEADERS => 'bypassed (invalid global vary headers)',
					Compiled_Route::VARY_MERGED_HEADERS => 'bypassed (too many vary headers)',
					default                             => 'bypassed (invalid declaration)',
				},
			];
		}

		[ $text, $debug ] = match ( $status ) {
			Location_Pricing::STATUS_DETECTED   => [
				__( 'Products and collection data are not cached: a tax-location filter is active', 'rockfire-rest-api-cache' ),
				'bypassed (detected)',
			],
			Location_Pricing::STATUS_FORCED_ON  => [
				/* translators: %s: name of the filter. */
				sprintf( __( 'Products and collection data are not cached: location pricing is forced on by the %s filter', 'rockfire-rest-api-cache' ), Hooks::WC_LOCATION_PRICING ),
				'bypassed (forced)',
			],
			Location_Pricing::STATUS_FORCED_OFF => [
				/* translators: %s: name of the filter. */
				sprintf( __( 'Products and collection data are cached: location pricing is forced off by the %s filter', 'rockfire-rest-api-cache' ), Hooks::WC_LOCATION_PRICING ),
				'cached (forced off)',
			],
			default                             => [
				__( 'Products and collection data are cached: no tax-location filter is active', 'rockfire-rest-api-cache' ),
				'cached',
			],
		};

		/* translators: 1: whether products and collection data are cached, and why, 2: the declared currency cookies. */
		$value = sprintf( __( '%1$s. %2$s.', 'rockfire-rest-api-cache' ), $text, $this->currency_cookies( $routes ) );

		// With no enabled price-bearing route the snapshot caches no prices and no recompile changes the row.
		$live_bypass = Location_Pricing::STATUS_DETECTED === $status || Location_Pricing::STATUS_FORCED_ON === $status;
		if ( [] !== $routes && $live_bypass !== $this->compiled_bypass( $routes ) ) {
			$value .= ' ' . __( 'The current status takes effect after the next recompile.', 'rockfire-rest-api-cache' );
		}

		return [
			'label' => $label,
			'value' => $value,
			'debug' => $debug,
		];
	}

	/**
	 * The enabled price-bearing routes of the compiled snapshot: the only ones the row reports on.
	 *
	 * @param Compiled_Config|null $config The compiled snapshot.
	 *
	 * @return list<Compiled_Route>
	 */
	private function price_routes( ?Compiled_Config $config ): array {
		$routes = [];
		if ( null === $config ) {
			return $routes;
		}

		foreach ( $config->routes() as $route ) {
			if ( $route->enabled && Store_Routes::is_price_bearing( $route->key ) ) {
				$routes[] = $route;
			}
		}

		return $routes;
	}

	/**
	 * Whether the snapshot bypasses any of the price-bearing routes as varying per visitor.
	 *
	 * @param Compiled_Route[] $routes The enabled price-bearing routes of the snapshot.
	 * @phpstan-param list<Compiled_Route> $routes
	 *
	 * @return bool
	 */
	private function compiled_bypass( array $routes ): bool {
		foreach ( $routes as $route ) {
			if ( $route->visitor_varies ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Why the snapshot bypasses the price-bearing routes as having an invalid vary declaration, if it does.
	 *
	 * When the routes differ in cause, the site-wide one is reported first: the global vary headers being unusable,
	 * then the global headers together with a route's own being too many, then the currency-cookie declaration.
	 *
	 * @param Compiled_Route[] $routes The enabled price-bearing routes of the snapshot.
	 * @phpstan-param list<Compiled_Route> $routes
	 *
	 * @return string|null One of the `Compiled_Route::VARY_*` causes, or null when no route has an invalid declaration.
	 */
	private function invalid_cause( array $routes ): ?string {
		$found = [];
		foreach ( $routes as $route ) {
			if ( $route->vary_invalid ) {
				$found[ $route->vary_cause ?? Compiled_Route::VARY_COOKIES ] = true;
			}
		}

		foreach ( [ Compiled_Route::VARY_GLOBAL_HEADERS, Compiled_Route::VARY_MERGED_HEADERS, Compiled_Route::VARY_COOKIES ] as $cause ) {
			if ( isset( $found[ $cause ] ) ) {
				return $cause;
			}
		}

		return null;
	}

	/**
	 * The row text for an invalid vary declaration.
	 *
	 * @param string $cause One of the `Compiled_Route::VARY_*` causes.
	 *
	 * @return string
	 */
	private function invalid_text( string $cause ): string {
		return match ( $cause ) {
			Compiled_Route::VARY_GLOBAL_HEADERS => sprintf(
				/* translators: 1: name of the constant, 2: name of the filter. */
				__( 'Products and collection data are not cached: the global vary headers are unusable (see the %1$s constant and the %2$s filter).', 'rockfire-rest-api-cache' ),
				'RFRAC_GLOBAL_VARY_HEADERS',
				Hooks::GLOBAL_VARY_HEADERS
			),
			Compiled_Route::VARY_MERGED_HEADERS => sprintf(
				/* translators: %d: the number of request headers a route can vary by. */
				__( 'Products and collection data are not cached: the global vary headers together with the vary headers of a route are more than %d request headers.', 'rockfire-rest-api-cache' ),
				Route_Rule::MAX_VARY_HEADERS
			),
			default                             => sprintf(
				/* translators: %s: name of the filter. */
				__( 'Products and collection data are not cached: the currency-cookie declaration is invalid (see the %s filter).', 'rockfire-rest-api-cache' ),
				Hooks::WC_CURRENCY_COOKIES
			),
		};
	}

	/**
	 * The currency cookies the compiled snapshot declares for the price-bearing routes, each with the number of values it allows.
	 *
	 * @param Compiled_Route[] $routes The enabled price-bearing routes of the snapshot.
	 * @phpstan-param list<Compiled_Route> $routes
	 *
	 * @return string
	 */
	private function currency_cookies( array $routes ): string {
		// The values of a cookie, summed over the routes that declare it.
		$allowed = [];
		foreach ( $routes as $route ) {
			foreach ( $route->vary_cookies as $name => $values ) {
				$allowed[ $name ] ??= [];
				foreach ( $values as $value ) {
					$allowed[ $name ][ $value ] = true;
				}
			}
		}
		if ( [] === $allowed ) {
			return __( 'No currency cookies declared', 'rockfire-rest-api-cache' );
		}

		ksort( $allowed, SORT_STRING );

		$cookies = [];
		foreach ( $allowed as $name => $seen ) {
			$count     = count( $seen );
			$cookies[] = sprintf(
				/* translators: 1: name of a cookie, 2: number of values the cookie may have. */
				_n( '%1$s (%2$d value)', '%1$s (%2$d values)', $count, 'rockfire-rest-api-cache' ),
				$name,
				$count
			);
		}

		/* translators: %s: the declared currency cookies, comma separated. */
		return sprintf( __( 'Currency cookies: %s', 'rockfire-rest-api-cache' ), implode( ', ', $cookies ) );
	}
}
