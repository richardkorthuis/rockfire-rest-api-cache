<?php
/**
 * Tests for the WooCommerce section of the Site Health debug information (#94).
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Automattic\WooCommerce\Utilities\OrderUtil;
use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Health\Site_Health;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Health;
use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Store_Routes;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Services\Route_Service;

/**
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Health
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Module
 */
class Test_Wc_Health extends Rfrac_Test_Case {

	/**
	 * The fields of the section, in order.
	 *
	 * @var list<string>
	 */
	private const FIELDS = array( 'version', 'tested_up_to', 'hpos', 'catalog_routes', 'snapshot', 'per_visitor_pricing' );

	/**
	 * The text of a pending snapshot.
	 *
	 * @var string
	 */
	private const PENDING = 'Pending route discovery: refreshes on the next REST request outside the WooCommerce namespaces or when the settings are saved';

	public function set_up() {
		parent::set_up();
		unset( $GLOBALS['wp_rest_server'] );
	}

	public function tear_down() {
		set_current_screen( 'front' );
		unset( $GLOBALS['wp_rest_server'] );
		parent::tear_down();
	}

	/**
	 * A container wired like an admin request, with the WooCommerce module registered.
	 *
	 * @return Plugin
	 */
	private function admin_plugin(): Plugin {
		set_current_screen( 'dashboard' );
		$plugin = new Plugin();
		$plugin->register_engine_hooks();

		return $plugin;
	}

	/**
	 * The fields of the section of a fresh container.
	 *
	 * @param Plugin|null $plugin Container; a fresh one by default.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function fields( ?Plugin $plugin = null ): array {
		return ( new Health( $plugin ?? new Plugin() ) )->fields();
	}

	/**
	 * Enable catalog routes through the route service.
	 *
	 * @param Plugin       $plugin Container.
	 * @param list<string> $routes Route patterns.
	 *
	 * @return void
	 */
	private function enable_routes( Plugin $plugin, array $routes ): void {
		$ids = array_map( array( Route_Service::class, 'id_for' ), $routes );

		$this->assertSame( count( $routes ), $plugin->route_service()->set_status( $ids, 'enabled' ) );
	}

	public function test_the_section_holds_the_six_fields_in_order_on_an_admin_screen(): void {
		$plugin = $this->admin_plugin();
		$this->enable_routes( $plugin, array( '/wc/store/products', '/wc/store/v1/products' ) );

		$info = apply_filters( 'debug_information', array() );

		$this->assertArrayHasKey( Health::DEBUG_SECTION, $info );
		$section = $info[ Health::DEBUG_SECTION ];
		$this->assertSame( 'Rockfire – REST API Cache: WooCommerce', $section['label'] );
		$this->assertSame( self::FIELDS, array_keys( $section['fields'] ) );
		foreach ( $section['fields'] as $field ) {
			$this->assertIsString( $field['label'] );
			$this->assertTrue( is_string( $field['value'] ) || is_int( $field['value'] ) );
		}
		$this->assertSame( WC_VERSION, $section['fields']['version']['value'] );
		$this->assertSame( '2 of 26 enabled', $section['fields']['catalog_routes']['value'] );
		$this->assertSame( RFRAC_WC_HPOS ? 'Enabled' : 'Disabled', $section['fields']['hpos']['value'], 'The value follows the HPOS leg of the run.' );
		$this->assertSame( RFRAC_WC_HPOS, $section['fields']['hpos']['debug'] );
	}

	public function test_the_core_section_is_left_alone(): void {
		$this->admin_plugin();

		$info = apply_filters( 'debug_information', array( 'wp-core' => array( 'label' => 'WordPress' ) ) );

		$this->assertSame( 'rockfire-rest-api-cache', Site_Health::DEBUG_SECTION );
		$this->assertSame( 'rockfire-rest-api-cache-woocommerce', Health::DEBUG_SECTION );
		$this->assertArrayHasKey( 'wp-core', $info );
		$this->assertSame( 'REST API Cache', $info[ Site_Health::DEBUG_SECTION ]['label'] );
		$this->assertArrayHasKey( 'entries_active', $info[ Site_Health::DEBUG_SECTION ]['fields'] );
		$this->assertArrayNotHasKey( 'catalog_routes', $info[ Site_Health::DEBUG_SECTION ]['fields'] );
	}

	public function test_other_sections_are_kept_and_a_non_array_counts_as_empty(): void {
		$health = new Health( new Plugin() );

		$info = $health->debug_information( array( 'wp-core' => array( 'label' => 'WordPress' ) ) );
		$this->assertSame( array( 'wp-core', Health::DEBUG_SECTION ), array_keys( $info ) );

		$this->assertSame( array( Health::DEBUG_SECTION ), array_keys( $health->debug_information( null ) ) );
		$this->assertSame( array( Health::DEBUG_SECTION ), array_keys( $health->debug_information( 'nope' ) ) );
	}

	public function test_the_version_and_hpos_come_from_woocommerce(): void {
		$fields = $this->fields();

		$this->assertSame( WC_VERSION, $fields['version']['value'] );
		$this->assertSame( OrderUtil::custom_orders_table_usage_is_enabled(), $fields['hpos']['debug'] );
		$this->assertSame( $fields['hpos']['debug'] ? 'Enabled' : 'Disabled', $fields['hpos']['value'] );
	}

	public function test_tested_up_to_is_the_wc_tested_up_to_plugin_header(): void {
		$header   = get_file_data( RFRAC_FILE, array( 'tested_up_to' => 'WC tested up to' ) )['tested_up_to'];
		$expected = '' === $header ? 'Not declared' : $header;

		$this->assertSame( $expected, $this->fields()['tested_up_to']['value'] );
	}

	public function test_no_catalog_route_is_enabled_by_default(): void {
		$this->assertSame( '0 of 26 enabled', $this->fields()['catalog_routes']['value'] );
	}

	public function test_catalog_routes_count_the_enabled_catalog_routes(): void {
		$plugin = new Plugin();
		$this->enable_routes( $plugin, array_slice( Store_Routes::CATALOG, 0, 5 ) );

		$this->assertSame( '5 of 26 enabled', $this->fields( $plugin )['catalog_routes']['value'] );

		$this->enable_routes( $plugin, array_slice( Store_Routes::CATALOG, 5 ) );

		$this->assertSame( '26 of 26 enabled', $this->fields( $plugin )['catalog_routes']['value'] );
	}

	public function test_catalog_routes_do_not_count_an_unregistered_route(): void {
		$plugin = new Plugin();
		$this->enable_routes( $plugin, Store_Routes::CATALOG );

		$unregistered = '/wc/store/v1/products/tags';
		add_filter(
			'rest_endpoints',
			static function ( $endpoints ) use ( $unregistered ) {
				unset( $endpoints[ $unregistered ] );

				return $endpoints;
			}
		);
		unset( $GLOBALS['wp_rest_server'] );

		$this->assertSame( '25 of 25 enabled', $this->fields( new Plugin() )['catalog_routes']['value'], 'The orphan rule of the missing route counts for nothing.' );
	}

	public function test_the_snapshot_is_current_once_discovery_has_run(): void {
		$this->set_compiled_config(
			array(
				'pending_discovery' => false,
				'fingerprint'       => 'compiled-fingerprint',
			)
		);

		$this->assertSame( 'Current', $this->fields()['snapshot']['value'] );
	}

	public function test_the_snapshot_is_pending_once_it_is_marked_stale(): void {
		$plugin = new Plugin();
		$this->set_compiled_config(
			array(
				'pending_discovery' => false,
				'fingerprint'       => 'compiled-fingerprint',
			)
		);
		$this->assertSame( 'Current', $this->fields( $plugin )['snapshot']['value'], 'Precondition: the snapshot is current.' );

		$plugin->compiler()->mark_stale();

		$this->assertSame( '', Compiled_Config::load()?->fingerprint(), 'Precondition: the snapshot is stale.' );
		$this->assertFalse( Compiled_Config::load()->pending_discovery(), 'Precondition: only the fingerprint is blanked.' );
		$this->assertSame( self::PENDING, $this->fields( $plugin )['snapshot']['value'] );
	}

	public function test_the_snapshot_is_pending_while_discovery_is_pending(): void {
		$this->set_compiled_config( array( 'pending_discovery' => true ) );

		$this->assertSame( self::PENDING, $this->fields()['snapshot']['value'] );
	}

	public function test_the_snapshot_is_pending_without_a_compiled_config(): void {
		delete_option( Names::OPTION_CONFIG );
		$this->assertNull( Compiled_Config::load() );

		$this->assertSame( self::PENDING, $this->fields()['snapshot']['value'] );
	}

	public function test_the_module_hooks_the_section_on_admin_requests(): void {
		$plugin = $this->admin_plugin();

		$this->assertNotNull( $plugin->woocommerce(), 'The module is registered.' );
		$this->assertCount( 2, array_keys( array_column( $plugin->engine_hooks(), 'hook' ), 'debug_information', true ), 'The core section and the WooCommerce section.' );
	}

	public function test_a_front_end_container_registers_no_module_debug_information(): void {
		set_current_screen( 'front' );
		$plugin = new Plugin();
		$plugin->register_engine_hooks();

		$this->assertNotNull( $plugin->woocommerce(), 'The module is registered.' );
		$this->assertNotContains( 'debug_information', array_column( $plugin->engine_hooks(), 'hook' ) );
		$this->assertArrayNotHasKey( Health::DEBUG_SECTION, apply_filters( 'debug_information', array() ) );
	}

	public function test_a_cron_container_registers_no_module_debug_information(): void {
		set_current_screen( 'front' );
		add_filter( 'wp_doing_cron', '__return_true' );
		try {
			$plugin = new Plugin();
			$plugin->register_engine_hooks();
		} finally {
			remove_filter( 'wp_doing_cron', '__return_true' );
		}

		$this->assertNotNull( $plugin->woocommerce(), 'The module is registered.' );
		$this->assertContains( 'site_status_tests', array_column( $plugin->engine_hooks(), 'hook' ) );
		$this->assertNotContains( 'debug_information', array_column( $plugin->engine_hooks(), 'hook' ) );
	}
}
