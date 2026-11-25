<?php
/**
 * Tests for the per-visitor pricing row of the WooCommerce Site Health section: whether products and collection
 * data are cached or bypassed, why, and which currency cookies are declared.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Health;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Health
 */
class Test_Pricing_Site_Health extends Rfrac_Test_Case {

	/**
	 * The cookie a currency plugin keeps the visitor's currency in.
	 *
	 * @var string
	 */
	private const COOKIE = 'wmc_current_currency';

	/**
	 * The text of a detected tax-location filter.
	 *
	 * @var string
	 */
	private const DETECTED = 'Products and collection data are not cached: a tax-location filter is active';

	/**
	 * The text of a missing currency-cookie declaration.
	 *
	 * @var string
	 */
	private const NO_COOKIES = 'No currency cookies declared';

	/**
	 * The text of an invalid vary declaration that blames the currency cookies.
	 *
	 * @var string
	 */
	private const COOKIE_DECLARATION = 'the currency-cookie declaration is invalid';

	/**
	 * The note of a live status that the compiled snapshot does not have yet.
	 *
	 * @var string
	 */
	private const RECOMPILE = 'takes effect after the next recompile';

	public function set_up() {
		parent::set_up();
		unset( $GLOBALS['wp_rest_server'] );
	}

	public function tear_down() {
		unset( $GLOBALS['wp_rest_server'] );
		parent::tear_down();
	}

	/**
	 * The per-visitor pricing row of the section of a fresh container.
	 *
	 * @return array<string,mixed>
	 */
	private function row(): array {
		$fields = ( new Health( new Plugin() ) )->fields();

		$this->assertArrayHasKey( 'per_visitor_pricing', $fields );

		return $fields['per_visitor_pricing'];
	}

	/**
	 * A callback on the tax-location filter, like a geolocation or multi-currency plugin adds.
	 *
	 * @return void
	 */
	private function hook_location_filter(): void {
		add_filter( 'woocommerce_get_tax_location', static fn( $location ) => $location );
	}

	/**
	 * A compiled price-bearing route.
	 *
	 * @param string              $key   Route key; the product list by default.
	 * @param array<string,mixed> $extra Route fields on top of the plain ones.
	 *
	 * @return array<string,mixed>
	 */
	private function route( string $key = '/wc/store/v1/products', array $extra = array() ): array {
		return array_merge(
			array(
				'key'        => $key,
				'regex'      => '#^' . $key . '$#',
				'ttl'        => 3600,
				'param_mode' => 'registered',
			),
			$extra
		);
	}

	/**
	 * Store a compiled snapshot with the given routes.
	 *
	 * @param list<array<string,mixed>> $routes Routes.
	 *
	 * @return void
	 */
	private function seed( array $routes ): void {
		$this->set_compiled_config( array( 'routes' => $routes ) );
	}

	/**
	 * Recompile against a server with the Store API routes, with the module wired and, unless told otherwise, the price routes enabled.
	 *
	 * @param bool $enable_price_routes Whether to enable the price-bearing routes, which are disabled by default.
	 *
	 * @return void
	 */
	private function recompile( bool $enable_price_routes = true ): void {
		$plugin = new Plugin();
		$plugin->register_integrations();
		$this->assertNotNull( $plugin->woocommerce(), 'WooCommerce is loaded.' );

		if ( $enable_price_routes ) {
			add_filter(
				Hooks::ROUTE_RULES,
				static function ( $rules ) {
					foreach ( Store_Routes::PRICE_BEARING as $key ) {
						$rules[ $key ] = array( 'status' => 'enabled' );
					}
					return $rules;
				}
			);
		}

		( new Config_Compiler() )->compile( $this->new_rest_server() );
	}

	/**
	 * Whether the stored snapshot enables any price-bearing route.
	 *
	 * @return bool
	 */
	private function snapshot_enables_a_price_route(): bool {
		$config = Compiled_Config::load();
		$this->assertNotNull( $config, 'A snapshot is stored.' );

		foreach ( $config->routes() as $route ) {
			if ( $route->enabled && Store_Routes::is_price_bearing( $route->key ) ) {
				return true;
			}
		}

		return false;
	}

	public function test_the_row_follows_the_snapshot_row_and_has_a_label_a_value_and_a_debug_value(): void {
		$fields = ( new Health( new Plugin() ) )->fields();

		$this->assertSame( array( 'version', 'tested_up_to', 'hpos', 'catalog_routes', 'snapshot', 'per_visitor_pricing' ), array_keys( $fields ) );
		$row = $fields['per_visitor_pricing'];
		$this->assertSame( 'Per-visitor pricing', $row['label'] );
		$this->assertIsString( $row['value'] );
		$this->assertIsString( $row['debug'] );
	}

	public function test_the_row_is_part_of_the_debug_information(): void {
		$info = ( new Health( new Plugin() ) )->debug_information( array() );

		$this->assertArrayHasKey( 'per_visitor_pricing', $info[ Health::DEBUG_SECTION ]['fields'] );
	}

	public function test_a_tax_location_filter_that_is_compiled_in_bypasses_products_and_collection_data(): void {
		$this->hook_location_filter();
		$this->recompile();

		$row = $this->row();

		$this->assertSame( 'bypassed (detected)', $row['debug'] );
		$this->assertStringContainsString( self::DETECTED, $row['value'] );
		$this->assertStringNotContainsString( self::RECOMPILE, $row['value'], 'The snapshot has the same status.' );
	}

	public function test_without_a_tax_location_filter_products_and_collection_data_are_cached(): void {
		$this->recompile();

		$row = $this->row();

		$this->assertSame( 'cached', $row['debug'] );
		$this->assertStringContainsString( 'Products and collection data are cached: no tax-location filter is active', $row['value'] );
		$this->assertStringNotContainsString( self::RECOMPILE, $row['value'] );
	}

	public function test_a_filter_that_forces_the_bypass_on_is_reported_as_forced(): void {
		add_filter( Hooks::WC_LOCATION_PRICING, '__return_true' );
		$this->seed( array( $this->route( '/wc/store/v1/products', array( 'visitor_varies' => true ) ) ) );

		$row = $this->row();

		$this->assertSame( 'bypassed (forced)', $row['debug'] );
		$this->assertStringContainsString( 'Products and collection data are not cached: location pricing is forced on by the ' . Hooks::WC_LOCATION_PRICING . ' filter', $row['value'] );
		$this->assertStringNotContainsString( self::DETECTED, $row['value'] );
		$this->assertStringNotContainsString( self::RECOMPILE, $row['value'] );
	}

	public function test_a_filter_that_forces_the_bypass_off_is_reported_as_forced_off(): void {
		$this->hook_location_filter();
		add_filter( Hooks::WC_LOCATION_PRICING, '__return_false' );
		$this->seed( array( $this->route() ) );

		$row = $this->row();

		$this->assertSame( 'cached (forced off)', $row['debug'] );
		$this->assertStringContainsString( 'Products and collection data are cached: location pricing is forced off by the ' . Hooks::WC_LOCATION_PRICING . ' filter', $row['value'] );
		$this->assertStringNotContainsString( self::DETECTED, $row['value'] );
		$this->assertStringNotContainsString( self::RECOMPILE, $row['value'] );
	}

	public function test_a_location_filter_that_is_not_detected_is_reported_as_cached(): void {
		$this->seed( array( $this->route() ) );

		$row = $this->row();

		$this->assertSame( 'cached', $row['debug'] );
		$this->assertStringNotContainsString( self::DETECTED, $row['value'] );
	}

	public function test_a_declared_currency_cookie_is_listed_with_the_number_of_its_values(): void {
		add_filter( Hooks::WC_CURRENCY_COOKIES, static fn() => array( self::COOKIE => array( 'EUR', 'USD', 'GBP' ) ) );
		$this->recompile();

		$value = $this->row()['value'];

		$this->assertStringContainsString( self::COOKIE . ' (3 values)', $value );
		$this->assertStringNotContainsString( self::NO_COOKIES, $value );
	}

	public function test_no_declared_currency_cookie_is_said_so(): void {
		$this->recompile();

		$this->assertStringContainsString( self::NO_COOKIES, $this->row()['value'] );
	}

	public function test_without_a_snapshot_no_cookie_is_declared_and_nothing_is_flagged(): void {
		delete_option( Names::OPTION_CONFIG );

		$row = $this->row();

		$this->assertSame( 'cached', $row['debug'] );
		$this->assertStringContainsString( self::NO_COOKIES, $row['value'] );
		$this->assertStringNotContainsString( self::RECOMPILE, $row['value'] );
	}

	public function test_a_single_value_is_not_counted_in_the_plural(): void {
		$this->seed( array( $this->route( '/wc/store/v1/products', array( 'vary_cookies' => array( self::COOKIE => array( 'EUR' ) ) ) ) ) );

		$this->assertStringContainsString( self::COOKIE . ' (1 value)', $this->row()['value'] );
	}

	public function test_every_cookie_is_listed_by_name_with_the_values_of_all_price_routes_that_declare_it(): void {
		$this->seed(
			array(
				$this->route(
					'/wc/store/v1/products',
					array(
						'vary_cookies' => array(
							self::COOKIE => array( 'EUR', 'USD' ),
							'lang'       => array( 'nl', 'en' ),
						),
					)
				),
				$this->route( '/wc/store/v1/products/collection-data', array( 'vary_cookies' => array( self::COOKIE => array( 'EUR', 'USD', 'GBP' ) ) ) ),
			)
		);

		$value = $this->row()['value'];

		$this->assertStringContainsString( 'lang (2 values), ' . self::COOKIE . ' (3 values)', $value, 'Sorted by name, with the values counted once.' );
	}

	public function test_a_cookie_declared_on_a_route_without_prices_is_not_a_currency_cookie(): void {
		$this->seed(
			array(
				$this->route( '/wc/store/v1/products', array( 'vary_cookies' => array( self::COOKIE => array( 'EUR', 'USD' ) ) ) ),
				$this->route( '/wc/store/v1/products/categories', array( 'vary_cookies' => array( 'lang' => array( 'nl', 'en' ) ) ) ),
				$this->route( '/my/v1/things', array( 'vary_cookies' => array( 'region' => array( 'eu', 'us' ) ) ) ),
			)
		);

		$value = $this->row()['value'];

		$this->assertStringContainsString( 'Currency cookies: ' . self::COOKIE . ' (2 values).', $value );
		$this->assertStringNotContainsString( 'lang', $value );
		$this->assertStringNotContainsString( 'region', $value );
	}

	public function test_without_a_cookie_on_a_price_route_no_currency_cookie_is_declared(): void {
		$this->seed(
			array(
				$this->route( '/wc/store/v1/products' ),
				$this->route( '/wc/store/v1/products/categories', array( 'vary_cookies' => array( 'lang' => array( 'nl', 'en' ) ) ) ),
			)
		);

		$value = $this->row()['value'];

		$this->assertStringContainsString( self::NO_COOKIES, $value );
		$this->assertStringNotContainsString( 'lang', $value );
	}

	public function test_a_route_that_is_not_cached_declares_no_cookie(): void {
		$this->seed(
			array(
				array(
					'key'     => '/wc/store/v1/products',
					'regex'   => '#^/wc/store/v1/products$#',
					'enabled' => false,
				),
			)
		);

		$this->assertStringContainsString( self::NO_COOKIES, $this->row()['value'] );
	}

	public function test_a_tax_location_filter_hooked_after_the_compile_takes_effect_after_the_next_recompile(): void {
		$this->recompile();
		$this->hook_location_filter();

		$row = $this->row();

		$this->assertSame( 'bypassed (detected)', $row['debug'] );
		$this->assertStringContainsString( self::DETECTED, $row['value'] );
		$this->assertStringContainsString( self::RECOMPILE, $row['value'] );
	}

	public function test_a_tax_location_filter_that_is_gone_since_the_compile_takes_effect_after_the_next_recompile(): void {
		$this->seed( array( $this->route( '/wc/store/v1/products', array( 'visitor_varies' => true ) ) ) );

		$row = $this->row();

		$this->assertSame( 'cached', $row['debug'] );
		$this->assertStringContainsString( self::RECOMPILE, $row['value'] );
	}

	public function test_a_forced_status_that_the_snapshot_does_not_have_yet_takes_effect_after_the_next_recompile(): void {
		$this->seed( array( $this->route() ) );
		add_filter( Hooks::WC_LOCATION_PRICING, '__return_true' );

		$row = $this->row();

		$this->assertSame( 'bypassed (forced)', $row['debug'] );
		$this->assertStringContainsString( self::RECOMPILE, $row['value'] );
	}

	public function test_only_a_price_bearing_route_counts_for_the_snapshot(): void {
		$this->hook_location_filter();
		$this->seed(
			array(
				$this->route(),
				$this->route( '/wc/store/v1/products/categories', array( 'visitor_varies' => true ) ),
			)
		);

		$this->assertStringContainsString( self::RECOMPILE, $this->row()['value'], 'The snapshot bypasses no price route.' );
	}

	public function test_a_tax_location_filter_with_the_price_routes_disabled_has_no_recompile_note(): void {
		$this->hook_location_filter();
		$this->recompile( false );
		$this->assertFalse( $this->snapshot_enables_a_price_route(), 'The price routes are disabled by default.' );

		$row = $this->row();

		$this->assertSame( 'bypassed (detected)', $row['debug'] );
		$this->assertStringContainsString( self::DETECTED, $row['value'] );
		$this->assertStringNotContainsString( self::RECOMPILE, $row['value'], 'No recompile changes a snapshot without price routes.' );
	}

	public function test_a_snapshot_with_only_routes_that_are_not_price_bearing_has_no_recompile_note(): void {
		$this->hook_location_filter();
		$this->seed( array( $this->route( '/wc/store/v1/products/categories' ) ) );

		$this->assertStringNotContainsString( self::RECOMPILE, $this->row()['value'] );
	}

	public function test_a_tax_location_filter_without_a_snapshot_has_no_recompile_note(): void {
		$this->hook_location_filter();
		delete_option( Names::OPTION_CONFIG );

		$row = $this->row();

		$this->assertSame( 'bypassed (detected)', $row['debug'] );
		$this->assertStringNotContainsString( self::RECOMPILE, $row['value'] );
	}

	/**
	 * Currency-cookie declarations the engine rejects.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function invalid_declarations(): array {
		return array(
			'a string'                => array( 'EUR' ),
			'a cookie with no values' => array( array( self::COOKIE => array() ) ),
			'too many values'         => array( array( self::COOKIE => array_map( 'strval', range( 1, 1001 ) ) ) ),
			'a control char'          => array( array( self::COOKIE => array( "EU\x01R" ) ) ),
		);
	}

	/**
	 * @dataProvider invalid_declarations
	 *
	 * @param mixed $declared What the declaration filter returns.
	 */
	public function test_an_invalid_currency_cookie_declaration_is_reported_as_the_reason_the_price_routes_are_not_cached( $declared ): void {
		add_filter( Hooks::WC_CURRENCY_COOKIES, static fn() => $declared );
		$this->recompile();

		$row = $this->row();

		$this->assertSame( 'bypassed (invalid declaration)', $row['debug'] );
		$this->assertStringContainsString( 'Products and collection data are not cached: the currency-cookie declaration is invalid', $row['value'] );
		$this->assertStringContainsString( Hooks::WC_CURRENCY_COOKIES, $row['value'] );
		$this->assertStringNotContainsString( 'are cached', $row['value'] );
		$this->assertStringNotContainsString( self::NO_COOKIES, $row['value'] );
		$this->assertStringNotContainsString( self::RECOMPILE, $row['value'], 'A recompile gives the same result.' );
	}

	public function test_a_currency_cookie_declaration_that_throws_is_reported_as_invalid(): void {
		add_filter(
			Hooks::WC_CURRENCY_COOKIES,
			static function () {
				throw new RuntimeException( 'No declaration.' );
			}
		);
		$this->recompile();

		$row = $this->row();

		$this->assertSame( 'bypassed (invalid declaration)', $row['debug'] );
		$this->assertStringNotContainsString( self::RECOMPILE, $row['value'] );
	}

	public function test_an_invalid_declaration_is_reported_over_a_tax_location_filter(): void {
		$this->hook_location_filter();
		add_filter( Hooks::WC_CURRENCY_COOKIES, static fn() => 'EUR' );
		$this->recompile();

		$row = $this->row();

		$this->assertSame( 'bypassed (invalid declaration)', $row['debug'] );
		$this->assertStringNotContainsString( self::RECOMPILE, $row['value'] );
	}

	public function test_an_invalid_declaration_of_a_snapshot_is_reported_without_a_live_filter(): void {
		$this->seed(
			array(
				$this->route(
					'/wc/store/v1/products',
					array(
						'visitor_varies' => true,
						'vary_invalid'   => true,
					)
				),
			)
		);

		$row = $this->row();

		$this->assertSame( 'bypassed (invalid declaration)', $row['debug'] );
		$this->assertStringNotContainsString( self::NO_COOKIES, $row['value'] );
		$this->assertStringNotContainsString( self::RECOMPILE, $row['value'] );
	}

	public function test_unusable_global_vary_headers_are_reported_by_name_and_not_as_a_currency_cookie_declaration(): void {
		add_filter( Hooks::WC_CURRENCY_COOKIES, static fn() => array( self::COOKIE => array( 'EUR', 'USD' ) ) );
		add_filter( Hooks::GLOBAL_VARY_HEADERS, static fn() => array( 'cookie' ) );
		$this->recompile();

		$row = $this->row();

		$this->assertSame( 'bypassed (invalid global vary headers)', $row['debug'] );
		$this->assertStringContainsString( 'Products and collection data are not cached: the global vary headers are unusable', $row['value'] );
		$this->assertStringContainsString( 'RFRAC_GLOBAL_VARY_HEADERS', $row['value'] );
		$this->assertStringContainsString( Hooks::GLOBAL_VARY_HEADERS, $row['value'] );
		$this->assertStringNotContainsString( self::COOKIE_DECLARATION, $row['value'] );
		$this->assertStringNotContainsString( Hooks::WC_CURRENCY_COOKIES, $row['value'] );
		$this->assertStringNotContainsString( self::RECOMPILE, $row['value'], 'A recompile gives the same result.' );
	}

	public function test_global_vary_headers_that_push_a_price_route_past_the_limit_are_reported_by_name_and_not_as_a_currency_cookie_declaration(): void {
		add_filter( Hooks::WC_CURRENCY_COOKIES, static fn() => array( self::COOKIE => array( 'EUR', 'USD' ) ) );
		add_filter( Hooks::GLOBAL_VARY_HEADERS, static fn() => array( 'x-e', 'x-f' ) );
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( $rules ) {
				foreach ( Store_Routes::PRICE_BEARING as $key ) {
					$rules[ $key ] = array(
						'status'       => 'enabled',
						'vary_headers' => array( 'x-a', 'x-b', 'x-c', 'x-d' ),
					);
				}
				return $rules;
			},
			20
		);
		$this->recompile();

		$row = $this->row();

		$this->assertSame( 'bypassed (too many vary headers)', $row['debug'] );
		$this->assertStringContainsString( 'Products and collection data are not cached: the global vary headers together with the vary headers of a route are more than 5 request headers', $row['value'] );
		$this->assertStringNotContainsString( self::COOKIE_DECLARATION, $row['value'] );
		$this->assertStringNotContainsString( Hooks::WC_CURRENCY_COOKIES, $row['value'] );
		$this->assertStringNotContainsString( self::RECOMPILE, $row['value'], 'A recompile gives the same result.' );
	}

	public function test_a_snapshot_with_a_global_vary_cause_is_reported_without_a_live_filter(): void {
		foreach ( array( 'global_headers' => 'bypassed (invalid global vary headers)', 'merged_headers' => 'bypassed (too many vary headers)' ) as $cause => $debug ) {
			$this->seed(
				array(
					$this->route(
						'/wc/store/v1/products',
						array(
							'visitor_varies' => true,
							'vary_invalid'   => true,
							'vary_cause'     => $cause,
						)
					),
				)
			);

			$row = $this->row();

			$this->assertSame( $debug, $row['debug'], $cause );
			$this->assertStringContainsString( 'global vary headers', $row['value'], $cause );
			$this->assertStringNotContainsString( self::COOKIE_DECLARATION, $row['value'], $cause );
			$this->assertStringNotContainsString( self::NO_COOKIES, $row['value'], $cause );
		}
	}

	public function test_a_global_vary_cause_is_reported_over_a_cookie_declaration_on_another_price_route(): void {
		$this->seed(
			array(
				$this->route(
					'/wc/store/v1/products',
					array(
						'visitor_varies' => true,
						'vary_invalid'   => true,
					)
				),
				$this->route(
					'/wc/store/v1/products/collection-data',
					array(
						'visitor_varies' => true,
						'vary_invalid'   => true,
						'vary_cause'     => 'global_headers',
					)
				),
			)
		);

		$this->assertSame( 'bypassed (invalid global vary headers)', $this->row()['debug'] );
	}

	public function test_an_invalid_declaration_on_a_route_without_prices_is_not_reported(): void {
		$this->seed(
			array(
				$this->route(),
				$this->route(
					'/wc/store/v1/products/categories',
					array(
						'visitor_varies' => true,
						'vary_invalid'   => true,
					)
				),
			)
		);

		$row = $this->row();

		$this->assertSame( 'cached', $row['debug'] );
	}

	public function test_a_snapshot_that_varies_without_the_reason_is_not_reported_as_invalid(): void {
		$this->hook_location_filter();
		$this->seed( array( $this->route( '/wc/store/v1/products', array( 'visitor_varies' => true ) ) ) );

		$row = $this->row();

		$this->assertSame( 'bypassed (detected)', $row['debug'] );
		$this->assertStringContainsString( self::DETECTED, $row['value'] );
		$this->assertStringNotContainsString( self::RECOMPILE, $row['value'] );
	}
}
