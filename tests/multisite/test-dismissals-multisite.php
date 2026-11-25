<?php
/**
 * Multisite tests for the dismissals: they are stored per site.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Dismissals;

/**
 * @covers \Rockfire\Rest_Api_Cache\Admin\Dismissals
 * @group multisite
 */
class Test_Dismissals_Multisite extends Rfrac_Test_Case {

	use Rfrac_Admin_Test_Helpers;

	/**
	 * A second site of the network.
	 *
	 * @var int
	 */
	private int $site_b = 0;

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run via composer test-multisite.' );
		}
		parent::set_up();
		$this->site_b = (int) self::factory()->blog->create();
	}

	public function test_a_dismissal_on_one_site_does_not_hold_on_another(): void {
		$user_id = $this->as_admin();
		$site_a  = get_current_blog_id();
		Dismissals::dismiss( Dismissals::COEXISTENCE, 100 );
		$this->assertTrue( Dismissals::is_dismissed( Dismissals::COEXISTENCE, 100 ) );

		switch_to_blog( $this->site_b );
		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE, 100 ) );
		restore_current_blog();

		$this->assertSame( $site_a, get_current_blog_id() );
		$this->assertTrue( Dismissals::is_dismissed( Dismissals::COEXISTENCE, 100 ), 'Still dismissed on the first site.' );
		$this->assertNotSame( '', get_user_meta( $user_id, Dismissals::meta_key(), true ) );
	}

	public function test_the_map_is_stored_under_the_blog_prefixed_key_only(): void {
		global $wpdb;
		$user_id = $this->as_admin();
		Dismissals::dismiss( Dismissals::IMPORT_SUMMARY, 1 );
		switch_to_blog( $this->site_b );
		Dismissals::dismiss( Dismissals::IMPORT_SUMMARY, 2 );
		$key_b = $wpdb->get_blog_prefix() . Dismissals::META;
		$this->assertSame( $key_b, Dismissals::meta_key() );
		restore_current_blog();
		$key_a = $wpdb->get_blog_prefix() . Dismissals::META;

		$this->assertNotSame( $key_a, $key_b );
		$this->assertSame( $key_a, Dismissals::meta_key() );
		$this->assertSame( array( 'import_summary' => 1 ), get_user_meta( $user_id, $key_a, true ) );
		$this->assertSame( array( 'import_summary' => 2 ), get_user_meta( $user_id, $key_b, true ) );
		$this->assertSame( '', get_user_meta( $user_id, Dismissals::META, true ) );
	}
}
