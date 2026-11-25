<?php
/**
 * Multisite tests for the lookup of an archived, spammed or deleted site: nothing is served from the cache.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Schema\Migrator;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * @covers \Rockfire\Rest_Api_Cache\Serving\Lookup
 * @group multisite
 */
class Test_Suspended_Site_Lookup extends Rfrac_E2e_Test_Case {

	/**
	 * Payload of the primed entry.
	 */
	private const PAYLOAD = '{"primed":true}';

	/**
	 * Id of the second site.
	 *
	 * @var int
	 */
	private int $blog_id = 0;

	/**
	 * Whether the test switched to the second site.
	 *
	 * @var bool
	 */
	private bool $switched = false;

	/**
	 * The `current_blog` global before the test.
	 *
	 * @var mixed
	 */
	private $saved_blog = null;

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run via composer test-multisite.' );
		}
		parent::set_up();
		$this->saved_blog = $GLOBALS['current_blog'] ?? null;
		$this->blog_id    = (int) self::factory()->blog->create();
		switch_to_blog( $this->blog_id );
		$this->switched = true;

		$this->assertTrue( ( new Migrator() )->install_site(), 'The tables of the second site exist.' );
		update_option( 'permalink_structure', '/%postname%/' );
		set_transient( Config_Compiler::CHECK_TRANSIENT, time(), 300 );
		$this->configure();
		Coexistence_Guard::reset();
	}

	public function tear_down() {
		if ( $this->switched ) {
			restore_current_blog();
			$this->switched = false;
		}
		$GLOBALS['current_blog'] = $this->saved_blog;
		parent::tear_down();
	}

	/**
	 * Point `current_blog` at the second site with the given status flags.
	 *
	 * @param array<string,string> $flags Flags to set, e.g. `array( 'archived' => '1' )`; the others stay `'0'`.
	 */
	private function current_blog_with( array $flags ): void {
		$site = get_site( $this->blog_id );
		$this->assertInstanceOf( WP_Site::class, $site );
		$site = clone $site;
		foreach ( array( 'archived', 'spam', 'deleted' ) as $flag ) {
			$site->$flag = $flags[ $flag ] ?? '0';
		}
		$GLOBALS['current_blog'] = $site;
	}

	/**
	 * Point the request at the posts route of the second site and prime its entry.
	 *
	 * @param Rfrac_Serving_Harness $h Harness.
	 */
	private function request_primed_entry( Rfrac_Serving_Harness $h ): void {
		$prefix = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$h->request( $prefix . '/wp-json/wp/v2/posts' );
		$this->prime_entry( self::PAYLOAD );
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_site_with_all_flags_at_zero_serves_its_entry( string $path ): void {
		$h = $this->harness( $path );
		$this->request_primed_entry( $h );
		$this->current_blog_with( array() );

		$this->assertTrue( $h->lookup() );
		$this->assertSame( self::PAYLOAD, $h->output );
		$this->assertContains( 'X-Rfrac-Cache: HIT', $h->headers );
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_an_archived_spammed_or_deleted_site_is_not_served_its_primed_entry( string $path ): void {
		$h = $this->harness( $path );
		$this->request_primed_entry( $h );

		$suspensions = array(
			'archived'  => array( 'archived' => '1' ),
			'spam'      => array( 'spam' => '1' ),
			'deleted'   => array( 'deleted' => '1' ),
			'deleted 2' => array( 'deleted' => '2' ),
		);
		foreach ( $suspensions as $label => $flags ) {
			$this->current_blog_with( array() );
			$this->assertTrue( $h->lookup(), $label . ': the entry is primed.' );

			$this->current_blog_with( $flags );
			$this->assertFalse( $h->lookup(), $label );
			$this->assertSame( '', $h->output, $label );
			$this->assertSame( array(), $h->headers, $label );
			$this->assertTrue( Request_State::lookup_done(), $label );
			$this->assertFalse( Request_State::decision()->cacheable, $label );
			$this->assertSame( Bypass_Reason::DISABLED, Request_State::decision()->reason, $label );
			$this->assertNull( Request_State::cache_key(), $label );
			$this->assertNull( Request_State::flush_epoch(), $label );
			$this->assertSame( array(), $h->table_queries(), $label . ': no cache table was read.' );
			$this->assertSame( array(), preg_grep( '/rfrac_flush_epoch/', $h->queries ), $label . ': no epoch was read.' );
		}
		$this->assertNoFailures();
	}

	/**
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_only_a_site_object_in_current_blog_counts( string $path ): void {
		$h = $this->harness( $path );
		$this->request_primed_entry( $h );

		$GLOBALS['current_blog'] = (object) array(
			'blog_id'  => (string) $this->blog_id,
			'archived' => '1',
			'spam'     => '1',
			'deleted'  => '1',
		);
		$this->assertTrue( $h->lookup(), 'A plain object is not a site.' );

		unset( $GLOBALS['current_blog'] );
		$this->assertTrue( $h->lookup(), 'Without current_blog nothing is read.' );
		$this->assertNoFailures();
	}
}
