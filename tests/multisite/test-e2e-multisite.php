<?php
/**
 * End-to-end multisite test: entries live in the tables of their own site and never cross sites.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Schema\Migrator;

/**
 * @covers \Rockfire\Rest_Api_Cache\Serving\Lookup
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 * @covers \Rockfire\Rest_Api_Cache\Storage\Repository
 * @covers \Rockfire\Rest_Api_Cache\Request\Cache_Key
 * @group multisite
 */
class Test_E2e_Multisite extends Rfrac_E2e_Test_Case {

	/**
	 * Id of the second site.
	 *
	 * @var int
	 */
	private int $blog_id = 0;

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run via composer test-multisite.' );
		}
		parent::set_up();
		$this->blog_id = (int) self::factory()->blog->create();
	}

	/**
	 * Run a callback inside the second site.
	 *
	 * @param callable $callback Callback.
	 *
	 * @return mixed
	 */
	private function on_blog_2( callable $callback ) {
		switch_to_blog( $this->blog_id );
		try {
			return $callback();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Number of rows in the entries table of a site, read by table name.
	 *
	 * @param int $blog_id Site id.
	 *
	 * @return int
	 */
	private function rows_of( int $blog_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->get_blog_prefix( $blog_id ) . Names::TABLE_ENTRIES ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * L8: entries stored on a switched site go to its own tables and are never served to the other site.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_entries_of_a_switched_site_use_its_tables_and_do_not_cross_sites( string $path ): void {
		self::factory()->post->create( array( 'post_title' => 'First site' ) );
		$this->on_blog_2(
			function (): void {
				$this->assertTrue( ( new Migrator() )->install_site(), 'The tables of the second site exist.' );
				update_option( 'permalink_structure', '/%postname%/' );
				set_transient( Config_Compiler::CHECK_TRANSIENT, time(), 300 );
				$this->configure();
				self::factory()->post->create( array( 'post_title' => 'Second site' ) );
			}
		);
		$h = $this->harness( $path );

		// Site 1.
		$h->request( '/wp-json/wp/v2/posts' );
		$body_1 = $h->miss( '/wp/v2/posts' );
		$this->assertNotNull( $body_1 );
		$this->assertStringContainsString( 'First site', $body_1 );
		$this->assertSame( 1, $this->rows_of( 1 ) );
		$this->assertSame( 0, $this->rows_of( $this->blog_id ) );

		// Site 2: no hit from the entry of site 1.
		$body_2 = $this->on_blog_2(
			function () use ( $h ): ?string {
				Coexistence_Guard::reset();
				$prefix = (string) wp_parse_url( home_url(), PHP_URL_PATH );
				$h->request( $prefix . '/wp-json/wp/v2/posts' );
				$body = $h->miss( '/wp/v2/posts' );
				$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'The entry is in the tables of site 2.' );
				return $body;
			}
		);
		$this->assertNotNull( $body_2, 'Site 2 did not get the entry of site 1.' );
		$this->assertStringContainsString( 'Second site', $body_2 );
		$this->assertStringNotContainsString( 'First site', $body_2 );
		$this->assertSame( 1, $this->rows_of( 1 ), 'Site 1 is untouched.' );
		$this->assertSame( 1, $this->rows_of( $this->blog_id ) );

		// Every site is served its own entry.
		$this->on_blog_2(
			function () use ( $h, $body_2 ): void {
				$prefix = (string) wp_parse_url( home_url(), PHP_URL_PATH );
				$h->request( $prefix . '/wp-json/wp/v2/posts' );
				$this->assertTrue( $h->lookup() );
				$this->assertSame( $body_2, $h->output );
			}
		);
		$h->request( '/wp-json/wp/v2/posts' );
		$this->assertTrue( $h->lookup() );
		$this->assertSame( $body_1, $h->output );
		$this->assertNoFailures();
	}
}
