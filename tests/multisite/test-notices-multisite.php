<?php
/**
 * Multisite tests for the flash notices: the queue is stored per site.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Notices;

/**
 * @covers \Rockfire\Rest_Api_Cache\Admin\Notices
 * @group multisite
 */
class Test_Notices_Multisite extends Rfrac_Test_Case {

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

	/**
	 * Output of the render pass of the current user's notices on the current site.
	 */
	private function rendered(): string {
		ob_start();
		( new Notices() )->render();
		return (string) ob_get_clean();
	}

	public function test_a_notice_queued_on_one_site_is_invisible_on_another_and_shown_once_back(): void {
		$this->as_admin();
		$site_a = get_current_blog_id();
		( new Notices() )->error( 'Only for site A.' );

		switch_to_blog( $this->site_b );
		$this->assertSame( array(), ( new Notices() )->queued() );
		$this->assertSame( '', $this->rendered() );
		restore_current_blog();

		$this->assertSame( $site_a, get_current_blog_id() );
		$this->assertSame( array( array( 'type' => 'error', 'message' => 'Only for site A.' ) ), ( new Notices() )->queued() );
		$html = $this->rendered();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertSame( 1, substr_count( $html, 'Only for site A.' ) );
		$this->assertSame( '', $this->rendered(), 'Shown once.' );
	}

	public function test_rendering_on_one_site_leaves_the_queue_of_another_intact(): void {
		$user_id = $this->as_admin();
		( new Notices() )->info( 'Queued on A.' );
		switch_to_blog( $this->site_b );
		( new Notices() )->warning( 'Queued on B.' );

		$html = $this->rendered();

		$this->assertStringContainsString( 'Queued on B.', $html );
		$this->assertStringNotContainsString( 'Queued on A.', $html );
		$this->assertSame( array(), ( new Notices() )->queued( $user_id ) );
		restore_current_blog();

		$this->assertSame( array( array( 'type' => 'info', 'message' => 'Queued on A.' ) ), ( new Notices() )->queued( $user_id ) );
	}

	public function test_a_leftover_global_notices_row_is_neither_returned_nor_printed(): void {
		$user_id = $this->as_admin();
		update_user_meta( $user_id, Notices::META, array( array( 'type' => 'error', 'message' => 'Stale global notice.' ) ) );

		$this->assertSame( array(), ( new Notices() )->queued() );
		$this->assertSame( '', $this->rendered() );

		switch_to_blog( $this->site_b );
		$this->assertSame( array(), ( new Notices() )->queued() );
		$this->assertSame( '', $this->rendered() );
		restore_current_blog();

		$this->assertNotSame( '', get_user_meta( $user_id, Notices::META, true ), 'The leftover row is left alone.' );
	}

	public function test_the_queue_is_stored_under_the_blog_prefixed_key_only(): void {
		global $wpdb;
		$user_id = $this->as_admin();
		( new Notices() )->success( 'Main site.' );
		switch_to_blog( $this->site_b );
		( new Notices() )->success( 'Second site.' );
		$key_b = $wpdb->get_blog_prefix() . Notices::META;
		$this->assertSame( $key_b, Notices::meta_key() );
		restore_current_blog();
		$key_a = $wpdb->get_blog_prefix() . Notices::META;

		$this->assertNotSame( $key_a, $key_b );
		$this->assertSame( $key_a, Notices::meta_key() );
		$this->assertNotSame( '', get_user_meta( $user_id, $key_a, true ) );
		$this->assertNotSame( '', get_user_meta( $user_id, $key_b, true ) );
		$this->assertSame( '', get_user_meta( $user_id, Notices::META, true ) );
	}

	public function test_an_empty_queue_renders_without_a_usermeta_write(): void {
		$this->as_admin();
		switch_to_blog( $this->site_b );
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$html = $this->rendered();
		$recorder->stop();
		restore_current_blog();

		$this->assertSame( '', $html );
		$this->assertSame( array(), $recorder->matching( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b.*usermeta/is' ) );
	}
}
