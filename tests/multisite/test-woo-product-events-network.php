<?php
/**
 * Multisite tests for the WooCommerce product events: the fan-out cap applies to each site on its own.
 *
 * WooCommerce is not needed: the handler under test is given plain post ids and never calls WooCommerce.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Woo_Product_Events;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;

/**
 * @covers \Rockfire\Rest_Api_Cache\Integrations\WooCommerce\Woo_Product_Events
 * @group multisite
 */
class Test_Woo_Product_Events_Network extends Rfrac_Test_Case {

	/**
	 * Invalidator under test.
	 *
	 * @var Invalidator
	 */
	private Invalidator $inv;

	/**
	 * Listener under test, with a fan-out cap of one distinct id per site.
	 *
	 * @var Woo_Product_Events
	 */
	private Woo_Product_Events $listener;

	/**
	 * Every site of the network, two at least.
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
		while ( count( $this->sites ) < 2 ) {
			self::factory()->blog->create();
			$this->sites = $this->network_sites();
		}

		$this->inv      = new Invalidator( new Rfrac_Spy_Store(), static fn( string $url ): ?string => null );
		$this->listener = new Woo_Product_Events( $this->inv, 1 );
	}

	/**
	 * The IDs of every site of the network.
	 *
	 * @return int[]
	 */
	private function network_sites(): array {
		return array_map( 'intval', get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) );
	}

	/**
	 * The ID of a site other than the main site.
	 *
	 * @return int
	 */
	private function other_site(): int {
		$other = (int) end( $this->sites );
		$this->assertNotSame( 1, $other );
		return $other;
	}

	/**
	 * Change the stock of a product on a site, as a direct-SQL stock update does.
	 *
	 * @param int $site_id    Site the product belongs to.
	 * @param int $product_id Product ID; it needs no row, the handler only reads its parent.
	 *
	 * @return void
	 */
	private function change_on_site( int $site_id, int $product_id ): void {
		switch_to_blog( $site_id );
		try {
			$this->listener->on_stock_updated( $product_id );
		} finally {
			restore_current_blog();
		}
	}

	public function test_a_site_over_the_cap_leaves_another_site_queueing_per_product_tags(): void {
		$other = $this->other_site();

		$this->change_on_site( 1, 9001 );
		$this->change_on_site( 1, 9002 );
		$saturated = array( 'list:product', 'post:9001', 'wc-price:all' );
		$this->assertSame( array( 1 => $saturated ), $this->inv->pending(), 'The second product exceeds the cap of site 1.' );

		$this->change_on_site( $other, 9003 );

		$this->assertSame(
			array(
				1      => $saturated,
				$other => array( 'post:9003' ),
			),
			$this->inv->pending()
		);
		$this->assertSame( 1, get_current_blog_id(), 'The current site is restored.' );
	}

	public function test_the_distinct_ids_are_counted_per_site(): void {
		$other = $this->other_site();

		$this->change_on_site( 1, 9001 );
		$this->change_on_site( $other, 9002 );

		$this->assertSame(
			array(
				1      => array( 'post:9001' ),
				$other => array( 'post:9002' ),
			),
			$this->inv->pending(),
			'One product on each site stays within the cap of one.'
		);
	}

	public function test_a_site_over_the_cap_stays_over_it_while_another_site_queues(): void {
		$other = $this->other_site();

		$this->change_on_site( $other, 9001 );
		$this->change_on_site( $other, 9002 );
		$this->change_on_site( 1, 9003 );
		$this->change_on_site( $other, 9004 );

		$this->assertSame(
			array(
				$other => array( 'list:product', 'post:9001', 'wc-price:all' ),
				1      => array( 'post:9003' ),
			),
			$this->inv->pending(),
			'Only the saturated site falls back to the catalog tags, however the events interleave.'
		);
	}
}
