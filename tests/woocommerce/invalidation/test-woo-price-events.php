<?php
/**
 * Tests for the WooCommerce price events: tax, catalog option, plugin and theme changes flush the price tag.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Woo_Price_Events;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Invalidation\Option_Events;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Woo_Price_Events
 */
class Test_Woo_Price_Events extends Rfrac_Test_Case {

	use Rfrac_Wc_Test_Helpers;

	/**
	 * Actions the tests fire by hand with synthetic arguments; other listeners (core, WooCommerce) must not see them.
	 *
	 * @var string[]
	 */
	private const FIRED_BY_HAND = array(
		'switch_theme',
		'activated_plugin',
		'deactivated_plugin',
		'woocommerce_rest_insert_tax',
		'woocommerce_rest_delete_tax',
		'woocommerce_update_options_tax',
		'wp_ajax_woocommerce_tax_rates_save_changes',
	);

	/**
	 * Invalidator under test.
	 *
	 * @var Invalidator
	 */
	private Invalidator $inv;

	/**
	 * Spy store.
	 *
	 * @var Rfrac_Spy_Store
	 */
	private Rfrac_Spy_Store $spy;

	public function set_up() {
		parent::set_up();
		$this->reset_wc_state();
		foreach ( self::FIRED_BY_HAND as $hook ) {
			remove_all_actions( $hook );
		}
		wp_set_current_user( 0 );
		$this->spy = new Rfrac_Spy_Store();
		$this->inv = new Invalidator( $this->spy, static fn( string $url ): ?string => null );
		$this->wire( new Woo_Price_Events( $this->inv ) );
	}

	public function tear_down() {
		$this->reset_wc_state();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Hook a listener to the hooks it declares, like `Module::subscribe()` does without the engine's guard.
	 *
	 * @param Woo_Price_Events $listener Listener.
	 *
	 * @return void
	 */
	private function wire( Woo_Price_Events $listener ): void {
		foreach ( Woo_Price_Events::hooks() as $hook ) {
			add_filter( $hook[0], array( $listener, $hook[1] ), $hook[2], $hook[3] );
		}
	}

	/**
	 * Pending tags of the current site.
	 *
	 * @return string[]
	 */
	private function pending(): array {
		return $this->inv->pending()[ get_current_blog_id() ] ?? array();
	}

	/**
	 * Drain the buffer, so the next assertion sees only what the next change queued.
	 *
	 * @return void
	 */
	private function drain(): void {
		$this->inv->commit();
		$this->spy->calls = array();
	}

	/**
	 * A fresh option listener: its map is built (and filtered) on the first option write.
	 *
	 * @return Option_Events
	 */
	private function fresh_option_events(): Option_Events {
		$events = new Option_Events( $this->inv );
		$events->register();
		return $events;
	}

	/**
	 * Tags sorted like `Invalidator::pending()` reports them.
	 *
	 * @param string[] $tags Tags.
	 *
	 * @return string[]
	 */
	private static function sorted( array $tags ): array {
		sort( $tags, SORT_STRING );
		return $tags;
	}

	/**
	 * Every option the class maps and the tags a change of it invalidates.
	 *
	 * @return array<string,array{0:string,1:string[]}>
	 */
	public static function catalog_options(): array {
		$price   = array( Store_Routes::PRICE_TAG );
		$options = array();
		foreach (
			array(
				'woocommerce_currency',
				'woocommerce_currency_pos',
				'woocommerce_price_thousand_sep',
				'woocommerce_price_decimal_sep',
				'woocommerce_price_num_decimals',
				'woocommerce_price_display_suffix',
				'woocommerce_calc_taxes',
				'woocommerce_prices_include_tax',
				'woocommerce_tax_display_shop',
				'woocommerce_tax_based_on',
				'woocommerce_default_country',
				'woocommerce_store_postcode',
				'woocommerce_store_city',
				'woocommerce_default_customer_address',
				'woocommerce_weight_unit',
				'woocommerce_dimension_unit',
				'woocommerce_stock_format',
				'woocommerce_notify_low_stock_amount',
				'woocommerce_notify_no_stock_amount',
				'woocommerce_manage_stock',
				'woocommerce_hide_out_of_stock_items',
				'default_product_cat',
			) as $option
		) {
			$options[ $option ] = array( $option, $price );
		}
		$options['woocommerce_permalinks'] = array(
			'woocommerce_permalinks',
			array( Store_Routes::PRICE_TAG, 'list:product_cat', 'list:product_brand', 'list:wc-review' ),
		);
		$options['show_avatars']           = array( 'show_avatars', array( 'list:wc-review' ) );

		return $options;
	}

	public function test_tax_rate_crud_flushes_price_tag(): void {
		$rate_id = $this->wc_tax_rate( array( 'tax_rate_name' => 'Crud tax' ) );
		$this->assertSame( array( 'wc-price:all' ), $this->pending(), 'Inserting a tax rate.' );
		$this->drain();

		WC_Tax::_update_tax_rate( $rate_id, array( 'tax_rate' => '12.0000' ) );
		$this->assertSame( array( 'wc-price:all' ), $this->pending(), 'Updating a tax rate.' );
		$this->drain();

		WC_Tax::_delete_tax_rate( $rate_id );
		$this->assertSame( array( 'wc-price:all' ), $this->pending(), 'Deleting a tax rate.' );
	}

	public function test_the_price_tag_is_the_shared_price_tag(): void {
		$this->assertSame( 'wc-price:all', Store_Routes::PRICE_TAG );
	}

	public function test_tax_rates_save_changes_flushes_for_a_shop_manager(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->assertTrue( current_user_can( 'manage_woocommerce' ) );

		do_action( 'wp_ajax_woocommerce_tax_rates_save_changes' );

		$this->assertSame( array( 'wc-price:all' ), $this->pending() );
	}

	public function test_tax_rates_save_changes_queues_nothing_for_a_subscriber(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		do_action( 'wp_ajax_woocommerce_tax_rates_save_changes' );

		$this->assertSame( array(), $this->pending() );
	}

	public function test_tax_rates_save_changes_queues_nothing_for_a_visitor(): void {
		do_action( 'wp_ajax_woocommerce_tax_rates_save_changes' );

		$this->assertSame( array(), $this->pending() );
	}

	public function test_rest_tax_changes_and_tax_settings_flush_the_price_tag(): void {
		do_action( 'woocommerce_rest_insert_tax', (object) array( 'id' => 1 ), new WP_REST_Request(), true );
		$this->assertSame( array( 'wc-price:all' ), $this->pending(), 'woocommerce_rest_insert_tax' );
		$this->drain();

		do_action( 'woocommerce_rest_delete_tax', (object) array( 'id' => 1 ), new WP_REST_Response(), new WP_REST_Request() );
		$this->assertSame( array( 'wc-price:all' ), $this->pending(), 'woocommerce_rest_delete_tax' );
		$this->drain();

		do_action( 'woocommerce_update_options_tax' );
		$this->assertSame( array( 'wc-price:all' ), $this->pending(), 'woocommerce_update_options_tax' );
	}

	/**
	 * @dataProvider catalog_options
	 *
	 * @param string   $option Option name.
	 * @param string[] $tags   Tags its change invalidates.
	 */
	public function test_catalog_option_map( string $option, array $tags ): void {
		$this->fresh_option_events();

		update_option( $option, 'rfrac-new-value-' . $option );

		$this->assertSame( self::sorted( $tags ), $this->pending() );
	}

	public function test_an_unmapped_woocommerce_option_queues_nothing(): void {
		$this->fresh_option_events();

		add_option( 'woocommerce_email_from_name', 'Shop' );
		$this->drain();

		update_option( 'woocommerce_email_from_name', 'Other shop' );
		$this->assertSame( array(), $this->pending(), 'Updated.' );

		delete_option( 'woocommerce_email_from_name' );
		$this->assertSame( array(), $this->pending(), 'Deleted.' );
		$this->assertSame( array(), $this->spy->calls_of( 'flush_all' ) );
	}

	public function test_an_existing_all_is_never_downgraded(): void {
		add_filter(
			Hooks::OPTION_INVALIDATION_MAP,
			static function ( $map ) {
				$map['woocommerce_currency'] = 'all';
				return $map;
			},
			5
		);
		$events = $this->fresh_option_events();

		$map = ( new ReflectionMethod( Option_Events::class, 'map' ) )->invoke( $events );

		$this->assertIsArray( $map );
		$this->assertSame( 'all', $map['woocommerce_currency'] );
		$this->assertSame( array( Store_Routes::PRICE_TAG ), $map['woocommerce_calc_taxes'], 'The other entries are still added.' );

		update_option( 'woocommerce_currency', 'XTS' );
		$this->assertCount( 1, $this->spy->calls_of( 'flush_all' ) );
	}

	public function test_an_existing_tag_list_is_extended_not_replaced(): void {
		add_filter(
			Hooks::OPTION_INVALIDATION_MAP,
			static function ( $map ) {
				$map['woocommerce_currency'] = array( 'route:/custom' );
				return $map;
			},
			5
		);
		$this->fresh_option_events();

		update_option( 'woocommerce_currency', 'XTS' );

		$this->assertSame( array( 'route:/custom', 'wc-price:all' ), $this->pending() );
	}

	public function test_a_filter_that_returns_something_else_than_a_map_is_left_alone(): void {
		$listener = new Woo_Price_Events( $this->inv );

		$this->assertSame( 'not a map', $listener->filter_option_map( 'not a map' ) );
		$this->assertNull( $listener->filter_option_map( null ) );
	}

	public function test_deleted_option_uses_the_map(): void {
		$this->fresh_option_events();
		add_option( 'woocommerce_price_display_suffix', ' incl. VAT' );
		add_option( 'woocommerce_currency', 'EUR' );
		$this->drain();

		delete_option( 'woocommerce_price_display_suffix' );
		$this->assertSame( array( 'wc-price:all' ), $this->pending(), 'woocommerce_price_display_suffix' );
		$this->drain();

		delete_option( 'woocommerce_currency' );
		$this->assertSame( array( 'wc-price:all' ), $this->pending(), 'woocommerce_currency' );
	}

	public function test_deleting_the_permalink_options_queues_all_their_tags(): void {
		$this->fresh_option_events();
		add_option( 'woocommerce_permalinks', array( 'product_base' => 'shop' ) );
		add_option( 'show_avatars', '1' );
		$this->drain();

		delete_option( 'woocommerce_permalinks' );
		$this->assertSame( array( 'list:product_brand', 'list:product_cat', 'list:wc-review', 'wc-price:all' ), $this->pending() );
		$this->drain();

		delete_option( 'show_avatars' );
		$this->assertSame( array( 'list:wc-review' ), $this->pending() );
	}

	public function test_deleting_an_option_another_filter_maps_to_all_flushes_everything(): void {
		add_filter(
			Hooks::OPTION_INVALIDATION_MAP,
			static function ( $map ) {
				$map['woocommerce_currency'] = 'all';
				return $map;
			},
			5
		);
		add_option( 'woocommerce_currency', 'EUR' );
		$this->drain();

		delete_option( 'woocommerce_currency' );

		$this->assertCount( 1, $this->spy->calls_of( 'flush_all' ) );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_deleting_an_option_another_filter_removed_from_the_map_queues_nothing(): void {
		add_filter(
			Hooks::OPTION_INVALIDATION_MAP,
			static function ( $map ) {
				unset( $map['woocommerce_currency'] );
				return $map;
			},
			20
		);
		add_option( 'woocommerce_currency', 'EUR' );
		$this->drain();

		delete_option( 'woocommerce_currency' );

		$this->assertSame( array(), $this->pending() );
		$this->assertSame( array(), $this->spy->calls_of( 'flush_all' ) );
	}

	public function test_a_plugin_activation_deactivation_and_theme_switch_flush_the_price_tag(): void {
		do_action( 'activated_plugin', 'example/example.php', false );
		$this->assertSame( array( 'wc-price:all' ), $this->pending(), 'activated_plugin' );
		$this->drain();

		do_action( 'deactivated_plugin', 'example/example.php', false );
		$this->assertSame( array( 'wc-price:all' ), $this->pending(), 'deactivated_plugin' );
		$this->drain();

		do_action( 'switch_theme', 'example-theme' );
		$this->assertSame( array( 'wc-price:all' ), $this->pending(), 'switch_theme' );
	}

	public function test_the_events_carry_their_subject_in_the_context(): void {
		$seen = array();
		add_filter(
			Hooks::INVALIDATION_TAGS,
			static function ( $tags, $event, $context ) use ( &$seen ) {
				$seen[] = array( $event, $context );
				return $tags;
			},
			10,
			3
		);

		do_action( 'activated_plugin', 'example/example.php', false );

		$this->assertSame( array( array( 'wc_plugin_changed', array( 'plugin' => 'example/example.php', 'network_wide' => false ) ) ), $seen );
	}

	public function test_a_failing_invalidator_is_swallowed_and_reported(): void {
		$failures = array();
		add_action(
			Hooks::INVALIDATION_FAILED,
			static function ( $error, $context ) use ( &$failures ) {
				$failures[] = array( $error, $context );
			},
			10,
			2
		);
		add_filter(
			Hooks::INVALIDATION_TAGS,
			static function (): never {
				throw new RuntimeException( 'listener failed' );
			}
		);

		// With WP_DEBUG on, a failure is also written to the PHP error log; keep it out of the test output.
		$log = (string) wp_tempnam( 'rfrac-error-log' );
		$old = (string) ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		try {
			do_action( 'woocommerce_update_options_tax' );
			do_action( 'switch_theme', 'example-theme' );
			do_action( 'activated_plugin', 'example/example.php', false );
		} finally {
			ini_set( 'error_log', $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			unlink( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		$this->assertCount( 3, $failures, 'One report per failed handler.' );
		$this->assertSame( array( 'wc_tax_changed', 'wc_theme_switched', 'wc_plugin_changed' ), array_map( static fn( array $failure ): string => (string) $failure[1]['event'], $failures ) );
	}

	public function test_the_module_wires_the_price_events_as_engine_hooks(): void {
		do_action( 'woocommerce_loaded' );
		$plugin = new Plugin();
		$plugin->register_integrations();

		$wired = array();
		foreach ( $plugin->engine_hooks() as $hook ) {
			$wired[ $hook['hook'] ] = $hook['priority'];
		}

		foreach ( Woo_Price_Events::hooks() as $hook ) {
			$this->assertArrayHasKey( $hook[0], $wired, $hook[0] );
			$this->assertSame( $hook[2], $wired[ $hook[0] ], 'Priority of ' . $hook[0] );
		}
		$this->assertSame( 1, $wired['wp_ajax_woocommerce_tax_rates_save_changes'] );
	}

	public function test_the_hooks_of_the_class(): void {
		$this->assertSame(
			array(
				'woocommerce_tax_rate_added',
				'woocommerce_tax_rate_updated',
				'woocommerce_tax_rate_deleted',
				'woocommerce_rest_insert_tax',
				'woocommerce_rest_delete_tax',
				'woocommerce_update_options_tax',
				'wp_ajax_woocommerce_tax_rates_save_changes',
				'switch_theme',
				'activated_plugin',
				'deactivated_plugin',
				Hooks::OPTION_INVALIDATION_MAP,
				'deleted_option',
			),
			array_column( Woo_Price_Events::hooks(), 0 )
		);
	}
}
