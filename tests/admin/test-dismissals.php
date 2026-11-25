<?php
/**
 * Tests for the per-user dismissals of the persistent notices.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Dismissals;

/**
 * @covers \Rockfire\Rest_Api_Cache\Admin\Dismissals
 */
class Test_Dismissals extends Rfrac_Test_Case {

	use Rfrac_Admin_Test_Helpers;

	public function test_a_dismissal_holds_for_that_user_and_that_token_only(): void {
		$user_a = $this->as_admin();
		Dismissals::dismiss( Dismissals::COEXISTENCE, 100 );
		Dismissals::dismiss( Dismissals::IMPORT_SUMMARY, 100 );

		$this->assertTrue( Dismissals::is_dismissed( Dismissals::COEXISTENCE, 100 ) );
		$this->assertTrue( Dismissals::is_dismissed( Dismissals::IMPORT_SUMMARY, 100 ) );
		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE, 200 ), 'A changed token shows the notice again.' );
		$this->assertFalse( Dismissals::is_dismissed( Dismissals::IMPORT_SUMMARY, 200 ) );
		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE ), 'The default token is 1.' );
		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE_NETWORK, 100 ), 'Another notice is untouched.' );

		$user_b = $this->as_admin();
		$this->assertNotSame( $user_a, $user_b );
		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE, 100 ) );
		$this->assertFalse( Dismissals::is_dismissed( Dismissals::IMPORT_SUMMARY, 100 ) );

		wp_set_current_user( $user_a );
		$this->assertTrue( Dismissals::is_dismissed( Dismissals::COEXISTENCE, 100 ) );
	}

	public function test_the_default_token_is_one(): void {
		$this->as_admin();
		Dismissals::dismiss( Dismissals::COEXISTENCE_NETWORK );

		$this->assertTrue( Dismissals::is_dismissed( Dismissals::COEXISTENCE_NETWORK ) );
		$this->assertTrue( Dismissals::is_dismissed( Dismissals::COEXISTENCE_NETWORK, 1 ) );
		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE_NETWORK, 2 ) );
	}

	public function test_dismissing_again_replaces_the_token_and_keeps_the_other_notices(): void {
		$this->as_admin();
		Dismissals::dismiss( Dismissals::COEXISTENCE, 100 );
		Dismissals::dismiss( Dismissals::IMPORT_SUMMARY, 5 );
		Dismissals::dismiss( Dismissals::COEXISTENCE, 200 );

		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE, 100 ) );
		$this->assertTrue( Dismissals::is_dismissed( Dismissals::COEXISTENCE, 200 ) );
		$this->assertTrue( Dismissals::is_dismissed( Dismissals::IMPORT_SUMMARY, 5 ) );
	}

	public function test_no_user_writes_nothing_and_dismisses_nothing(): void {
		global $wpdb;
		wp_set_current_user( 0 );
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE '%rfrac\\_dismissed\\_notices'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		Dismissals::dismiss( Dismissals::COEXISTENCE, 100 );

		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE, 100 ) );
		$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE '%rfrac\\_dismissed\\_notices'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Read the table: get_user_meta() refuses user 0 before it queries, with a return shape that varies by WordPress version.
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = 0 AND meta_key = %s", Dismissals::meta_key() ) ), 'Nothing is stored for user 0.' );
	}

	public function test_the_map_is_stored_under_the_blog_prefixed_key(): void {
		global $wpdb;
		$user_id = $this->as_admin();
		Dismissals::dismiss( Dismissals::IMPORT_SUMMARY, 7 );

		$this->assertSame( $wpdb->get_blog_prefix() . 'rfrac_dismissed_notices', Dismissals::meta_key() );
		$this->assertSame( array( 'import_summary' => 7 ), get_user_meta( $user_id, Dismissals::meta_key(), true ) );
	}

	public function test_a_string_meta_value_reads_as_not_dismissed_and_is_overwritten_by_the_next_dismissal(): void {
		$user_id = $this->as_admin();
		update_user_meta( $user_id, Dismissals::meta_key(), 'coexistence' );

		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE ) );

		Dismissals::dismiss( Dismissals::IMPORT_SUMMARY, 3 );

		$this->assertSame( array( 'import_summary' => 3 ), get_user_meta( $user_id, Dismissals::meta_key(), true ) );
		$this->assertTrue( Dismissals::is_dismissed( Dismissals::IMPORT_SUMMARY, 3 ) );
	}

	public function test_entries_that_are_not_integer_tokens_read_as_not_dismissed(): void {
		$user_id = $this->as_admin();
		update_user_meta(
			$user_id,
			Dismissals::meta_key(),
			array(
				Dismissals::COEXISTENCE         => '1',
				Dismissals::COEXISTENCE_NETWORK => array( 1 ),
				Dismissals::IMPORT_SUMMARY      => 4,
			)
		);

		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE ), 'A string token is not an integer token.' );
		$this->assertFalse( Dismissals::is_dismissed( Dismissals::COEXISTENCE_NETWORK ) );
		$this->assertTrue( Dismissals::is_dismissed( Dismissals::IMPORT_SUMMARY, 4 ) );
	}
}
