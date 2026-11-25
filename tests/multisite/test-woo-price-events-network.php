<?php
/**
 * Multisite tests for the WooCommerce price events: a network-wide plugin change flushes the price tag of every site.
 *
 * WooCommerce is not needed: the handlers under test never call it. The tests at the end go through the container's
 * own wiring on a request on which WooCommerce did not load (the network admin loads the plugins of the main site only).
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Woo_Price_Events;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Plugin;

/**
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Woo_Price_Events
 * @group multisite
 */
class Test_Woo_Price_Events_Network extends Rfrac_Test_Case {

	/**
	 * Invalidator under test.
	 *
	 * @var Invalidator
	 */
	private Invalidator $inv;

	/**
	 * Every site of the network, three at least.
	 *
	 * @var int[]
	 */
	private array $sites = array();

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test.' );
		}
		parent::set_up();

		$this->sites = $this->network_sites();
		while ( count( $this->sites ) < 3 ) {
			self::factory()->blog->create();
			$this->sites = $this->network_sites();
		}

		remove_all_actions( 'activated_plugin' );
		remove_all_actions( 'deactivated_plugin' );
		$this->inv = new Invalidator( new Rfrac_Spy_Store(), static fn( string $url ): ?string => null );
		$this->wire( new Woo_Price_Events( $this->inv ) );
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
	 * The IDs of every site of the network.
	 *
	 * @return int[]
	 */
	private function network_sites(): array {
		return array_map( 'intval', get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) );
	}

	public function test_a_network_wide_activation_flushes_the_price_tag_of_every_site(): void {
		do_action( 'activated_plugin', 'example/example.php', true );

		$pending = $this->inv->pending();
		$this->assertEqualsCanonicalizing( $this->sites, array_keys( $pending ) );
		foreach ( $this->sites as $site_id ) {
			$this->assertSame( array( 'wc-price:all' ), $pending[ $site_id ], 'Site ' . $site_id );
		}
		$this->assertSame( 1, get_current_blog_id(), 'The current site is restored.' );
	}

	public function test_a_network_wide_deactivation_flushes_the_price_tag_of_every_site(): void {
		do_action( 'deactivated_plugin', 'example/example.php', true );

		$pending = $this->inv->pending();
		$this->assertEqualsCanonicalizing( $this->sites, array_keys( $pending ) );
		foreach ( $this->sites as $site_id ) {
			$this->assertSame( array( 'wc-price:all' ), $pending[ $site_id ], 'Site ' . $site_id );
		}
	}

	public function test_a_site_level_activation_flushes_the_price_tag_of_the_current_site_only(): void {
		do_action( 'activated_plugin', 'example/example.php', false );

		$this->assertSame( array( get_current_blog_id() => array( 'wc-price:all' ) ), $this->inv->pending() );
	}

	public function test_a_site_level_deactivation_on_another_site_flushes_that_site_only(): void {
		$other = (int) end( $this->sites );
		$this->assertNotSame( 1, $other );

		switch_to_blog( $other );
		try {
			do_action( 'deactivated_plugin', 'example/example.php', false );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( array( $other => array( 'wc-price:all' ) ), $this->inv->pending() );
	}

	/**
	 * A container wired like a multisite request on which WooCommerce did not load, without the hand-wired listener.
	 *
	 * @return Plugin
	 */
	private function container_without_woocommerce(): Plugin {
		$this->assertSame( 0, did_action( 'woocommerce_loaded' ), 'Precondition: WooCommerce did not load on this request.' );
		remove_all_actions( 'activated_plugin' );
		remove_all_actions( 'deactivated_plugin' );

		$plugin = new Plugin();
		$plugin->register_integrations();
		$this->assertNull( $plugin->woocommerce(), 'No WooCommerce module is wired.' );
		return $plugin;
	}

	public function test_a_network_wide_activation_reaches_a_site_running_woocommerce_when_the_request_site_does_not(): void {
		$other = (int) end( $this->sites );
		$this->assertNotSame( 1, $other );
		switch_to_blog( $other );
		try {
			update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );
		} finally {
			restore_current_blog();
		}
		$plugin = $this->container_without_woocommerce();

		do_action( 'activated_plugin', 'example/example.php', true );

		$pending = $plugin->invalidator()->pending();
		$this->assertSame( array( 'wc-price:all' ), $pending[ $other ] ?? null );
		$this->assertSame( 1, get_current_blog_id(), 'The current site is restored.' );
	}

	public function test_a_network_wide_deactivation_reaches_a_site_running_woocommerce_when_the_request_site_does_not(): void {
		$other  = (int) end( $this->sites );
		$plugin = $this->container_without_woocommerce();

		do_action( 'deactivated_plugin', 'example/example.php', true );

		$this->assertSame( array( 'wc-price:all' ), $plugin->invalidator()->pending()[ $other ] ?? null );
	}

	public function test_a_site_level_change_queues_nothing_when_woocommerce_did_not_load(): void {
		$plugin = $this->container_without_woocommerce();

		do_action( 'activated_plugin', 'example/example.php', false );
		do_action( 'deactivated_plugin', 'example/example.php', false );

		$this->assertSame( array(), $plugin->invalidator()->pending() );
	}

	public function test_the_network_events_are_wired_once_per_container(): void {
		$plugin = $this->container_without_woocommerce();
		$this->assertSame( array( 'activated_plugin', 'deactivated_plugin' ), array_column( $plugin->engine_hooks(), 'hook' ) );

		$plugin->register_integrations();

		$this->assertCount( 2, $plugin->engine_hooks(), 'A second call adds no engine hook.' );
	}
}
