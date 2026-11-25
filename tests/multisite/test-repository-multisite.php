<?php
/**
 * Multisite tests for the repository.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Schema\Migrator;
use Rockfire\Rest_Api_Cache\Storage\Entry_Query;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Storage\Repository
 * @group multisite
 */
class Test_Repository_Multisite extends Rfrac_Test_Case {

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run via composer test-multisite.' );
		}
		parent::set_up();
	}

	public function test_entries_are_isolated_per_blog(): void {
		global $wpdb;
		$blog_id = self::factory()->blog->create();
		$repo    = new Repository();

		$id = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) );
		$this->assertSame( 1, $repo->count( new Entry_Query() ) );

		switch_to_blog( $blog_id );
		( new Migrator() )->install_site();
		$this->assertNotSame( $wpdb->base_prefix, $wpdb->prefix );
		$this->assertStringContainsString( (string) $blog_id, $repo->entries_table() );

		$this->assertSame( 0, $repo->count( new Entry_Query() ) );
		$this->assertSame( array(), $repo->query( new Entry_Query() ) );
		$this->assertSame( 0, $repo->flush_by_tags( array( 'post:1' ), gmdate( 'Y-m-d H:i:s' ) ) );
		$this->assertNull( $repo->get( $id ) );
		restore_current_blog();

		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $id ), 'The flush on the other blog did not touch blog 1.' );
		$this->assertSame( 1, $repo->count( new Entry_Query() ) );
	}

	public function test_a_flush_while_switched_advances_the_epoch_of_that_site_only(): void {
		$blog_id  = self::factory()->blog->create();
		$repo     = new Repository();
		$now      = gmdate( 'Y-m-d H:i:s' );
		$main     = (int) $repo->flush_epoch();
		$main_all = (int) $repo->flush_all_epoch();

		switch_to_blog( $blog_id );
		( new Migrator() )->install_site();
		$other = (int) $repo->flush_epoch();
		$this->assertSame( 0, $other, 'Site 2 never flushed.' );
		$repo->flush_by_tags( array( 'post:1' ), $now );
		$this->assertSame( 1, $repo->flush_epoch() );
		$this->assertSame( 0, $repo->flush_all_epoch() );
		$repo->flush_all( $now );
		$this->assertSame( 2, $repo->flush_epoch() );
		$this->assertSame( 1, $repo->flush_all_epoch() );
		restore_current_blog();

		$this->assertSame( $main, $repo->flush_epoch(), 'Site 1 did not move.' );
		$this->assertSame( $main_all, $repo->flush_all_epoch() );

		$repo->flush_by_tags( array( 'post:1' ), $now );
		$this->assertSame( $main + 1, $repo->flush_epoch() );
		switch_to_blog( $blog_id );
		$this->assertSame( 2, $repo->flush_epoch(), 'A flush on site 1 did not move site 2.' );
		restore_current_blog();
	}

	public function test_the_epoch_row_lives_in_the_options_table_of_the_flushed_site(): void {
		global $wpdb;
		$blog_id = self::factory()->blog->create();
		$repo    = new Repository();

		switch_to_blog( $blog_id );
		( new Migrator() )->install_site();
		$options = $wpdb->options;
		$repo->flush_by_tags( array( 'post:1' ), gmdate( 'Y-m-d H:i:s' ) );
		restore_current_blog();

		$this->assertNotSame( $wpdb->options, $options );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $options, 'rfrac_flush_epoch' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
