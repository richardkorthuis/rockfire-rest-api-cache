<?php
/**
 * End-to-end invalidation matrix, multisite row: a change on one site flushes only that site's entries.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Invalidation\Api;
use Rockfire\Rest_Api_Cache\Schema\Migrator;

/**
 * @coversNothing
 * @group multisite
 */
class Test_Invalidation_Matrix_Multisite extends Rfrac_Matrix_Test_Case {

	/**
	 * Entry ids by blog and served URI.
	 *
	 * @var array<int,array<string,int>>
	 */
	private array $blog_entries = array();

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run via composer test-multisite.' );
		}
		parent::set_up();
		$this->blog_entries = array();
	}

	/**
	 * Serve a request on a site and remember its entry.
	 *
	 * @param int    $blog_id Site id.
	 * @param string $uri     URI below the REST prefix.
	 * @param string $store   Store mode.
	 *
	 * @return void
	 */
	private function serve_on( int $blog_id, string $uri, string $store ): void {
		switch_to_blog( $blog_id );
		$this->configure_site( $store );
		$this->serve( $uri );
		$this->blog_entries[ $blog_id ][ $uri ] = $this->entries[ $uri ];
		unset( $this->entries[ $uri ] );
		restore_current_blog();
	}

	/**
	 * State of an entry of a site.
	 *
	 * @param int    $blog_id Site id.
	 * @param string $uri     Served URI.
	 *
	 * @return string
	 */
	private function state_on( int $blog_id, string $uri ): string {
		switch_to_blog( $blog_id );
		$state = Rfrac_Entry_Fixtures::get_state( $this->blog_entries[ $blog_id ][ $uri ] );
		restore_current_blog();
		return $state;
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_15_a_change_on_site_2_flushes_only_site_2_entries( string $store ): void {
		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		( new Migrator() )->install_site();
		update_option( 'permalink_structure', '/%postname%/' );
		restore_current_blog();

		// The post of site 2 gets the ID of the post of site 1, so both sites carry the same tag (post:N).
		$main_post = $this->factory()->post->create( array( 'post_title' => 'Site 1' ) );
		$this->factory()->post->create( array( 'post_type' => 'page' ) );
		switch_to_blog( $blog_id );
		$blog_post = wp_insert_post(
			array(
				'import_id'   => $main_post,
				'post_title'  => 'Site 2',
				'post_status' => 'publish',
			)
		);
		$this->assertSame( $main_post, $blog_post, 'Both sites have a post with the same ID.' );
		$this->factory()->post->create( array( 'post_type' => 'page' ) );
		restore_current_blog();

		$this->arm( $store );

		$uris = array( '/wp/v2/posts/' . $main_post, '/wp/v2/posts', '/wp/v2/pages' );
		foreach ( array( get_current_blog_id(), $blog_id ) as $site ) {
			foreach ( $uris as $uri ) {
				$this->serve_on( $site, $uri, $store );
			}
		}

		$this->recorder->start();
		switch_to_blog( $blog_id );
		wp_update_post( array( 'ID' => $blog_post, 'post_title' => 'Changed on site 2' ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		restore_current_blog();
		Api::get()->invalidator()->commit();
		$this->recorder->stop();

		$main = get_current_blog_id();
		foreach ( array( '/wp/v2/posts/' . $main_post, '/wp/v2/posts' ) as $uri ) {
			$this->assertSame( '2', $this->state_on( $blog_id, $uri ), 'Site 2 flushed: ' . $uri );
		}
		$this->assertSame( '1', $this->state_on( $blog_id, '/wp/v2/pages' ), 'Site 2 pages stay active.' );
		foreach ( $uris as $uri ) {
			$this->assertSame( '1', $this->state_on( $main, $uri ), 'Site 1 untouched: ' . $uri );
		}
		$this->assertCount( 1, $this->entry_updates_on( $blog_id ), 'Exactly one UPDATE on the entries table of site 2.' );
		$this->assertSame( array(), $this->entry_updates_on( $main ), 'No UPDATE on the entries table of site 1.' );
	}

	/**
	 * Serve the same two URIs on the main site and on a new site, with the plugin network-active and the engine armed.
	 *
	 * @param string $store Store mode.
	 *
	 * @return int The ID of the new site.
	 */
	private function serve_on_both_sites( string $store ): int {
		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		( new Migrator() )->install_site();
		update_option( 'permalink_structure', '/%postname%/' );
		$this->factory()->post->create();
		$this->factory()->post->create( array( 'post_type' => 'page' ) );
		restore_current_blog();
		$this->factory()->post->create();
		$this->factory()->post->create( array( 'post_type' => 'page' ) );

		$this->arm( $store );
		foreach ( array( get_current_blog_id(), $blog_id ) as $site ) {
			foreach ( array( '/wp/v2/posts', '/wp/v2/pages' ) as $uri ) {
				$this->serve_on( $site, $uri, $store );
			}
		}
		update_site_option( 'active_sitewide_plugins', array( plugin_basename( RFRAC_FILE ) => time() ) );
		return $blog_id;
	}

	/**
	 * Assert every entry of the site is flushed, none of the main site is, and no UPDATE ran on the main site.
	 *
	 * @param int $blog_id Site ID.
	 *
	 * @return void
	 */
	private function assert_only_site_flushed( int $blog_id ): void {
		$main = get_current_blog_id();
		foreach ( array( '/wp/v2/posts', '/wp/v2/pages' ) as $uri ) {
			$this->assertSame( '2', $this->state_on( $blog_id, $uri ), 'Site 2 flushed: ' . $uri );
			$this->assertSame( '1', $this->state_on( $main, $uri ), 'Site 1 untouched: ' . $uri );
		}
		$this->assertSame( array(), $this->entry_updates_on( $main ), 'No UPDATE on the entries table of site 1.' );
		$this->assertNotEmpty( $this->entry_updates_on( $blog_id ), 'The entries table of site 2 was updated.' );
		$this->assertSame( $main, get_current_blog_id(), 'The previous blog is restored.' );
	}

	/**
	 * Whether the lookup of the fallback path serves a request of a site from the cache.
	 *
	 * Runs in the context of the site (its tables, its object-cache keys), like a request to it would.
	 *
	 * @param int    $blog_id Site id.
	 * @param string $uri     URI below the REST prefix.
	 *
	 * @return bool
	 */
	private function lookup_hits_on( int $blog_id, string $uri ): bool {
		switch_to_blog( $blog_id );
		$harness = new Rfrac_Serving_Harness( Rfrac_Serving_Harness::FALLBACK );
		try {
			$harness->request( untrailingslashit( (string) wp_parse_url( rest_url(), PHP_URL_PATH ) ) . $uri );
			return $harness->lookup();
		} finally {
			$harness->restore();
			restore_current_blog();
		}
	}

	/**
	 * Assert the lookup serves the entries of both sites from the cache (before the site is taken down).
	 *
	 * @param int $blog_id Site ID.
	 *
	 * @return void
	 */
	private function assert_both_sites_hit( int $blog_id ): void {
		foreach ( array( get_current_blog_id(), $blog_id ) as $site ) {
			foreach ( array( '/wp/v2/posts', '/wp/v2/pages' ) as $uri ) {
				$this->assertTrue( $this->lookup_hits_on( $site, $uri ), 'Site ' . $site . ' hits before the take-down: ' . $uri );
			}
		}
	}

	/**
	 * Restore the site and assert its flushed entries stay unserved while those of the main site still hit.
	 *
	 * With the object-cache store a hit reads only the generation and the object, never the row, so the generation
	 * must have been bumped on the site: the objects of the take-down time are orphaned, not just their rows flagged.
	 *
	 * @param int    $blog_id Site ID.
	 * @param string $flag    Status flag the take-down set: `archived`, `spam` or `deleted`.
	 *
	 * @return void
	 */
	private function assert_restored_site_serves_nothing_old( int $blog_id, string $flag ): void {
		update_blog_status( $blog_id, $flag, '0' );

		$main = get_current_blog_id();
		foreach ( array( '/wp/v2/posts', '/wp/v2/pages' ) as $uri ) {
			$this->assertFalse( $this->lookup_hits_on( $blog_id, $uri ), 'Site 2 does not serve its pre-take-down entry once restored: ' . $uri );
			$this->assertTrue( $this->lookup_hits_on( $main, $uri ), 'Site 1 still hits: ' . $uri );
		}
		$this->assertSame( $main, get_current_blog_id(), 'The previous blog is restored.' );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_20_archiving_site_2_flushes_all_its_entries( string $store ): void {
		$blog_id = $this->serve_on_both_sites( $store );
		$this->assert_both_sites_hit( $blog_id );

		$this->recorder->start();
		update_blog_status( $blog_id, 'archived', '1' );
		$this->recorder->stop();

		$this->assert_only_site_flushed( $blog_id );
		$this->assert_restored_site_serves_nothing_old( $blog_id, 'archived' );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_21_marking_site_2_as_spam_flushes_all_its_entries( string $store ): void {
		$blog_id = $this->serve_on_both_sites( $store );
		$this->assert_both_sites_hit( $blog_id );

		$this->recorder->start();
		update_blog_status( $blog_id, 'spam', '1' );
		$this->recorder->stop();

		$this->assert_only_site_flushed( $blog_id );
		$this->assert_restored_site_serves_nothing_old( $blog_id, 'spam' );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_row_22_deactivating_site_2_flushes_all_its_entries( string $store ): void {
		$blog_id = $this->serve_on_both_sites( $store );
		$this->assert_both_sites_hit( $blog_id );

		$this->recorder->start();
		update_blog_status( $blog_id, 'deleted', '1' );
		$this->recorder->stop();

		$this->assert_only_site_flushed( $blog_id );
		$this->assert_restored_site_serves_nothing_old( $blog_id, 'deleted' );
	}

	/**
	 * UPDATE statements recorded on the entries table of a site.
	 *
	 * @param int $blog_id Site id.
	 *
	 * @return string[]
	 */
	private function entry_updates_on( int $blog_id ): array {
		global $wpdb;
		$table = $wpdb->get_blog_prefix( $blog_id ) . 'rfrac_entries';
		return $this->recorder->matching( '/^\s*UPDATE\s+`?' . preg_quote( $table, '/' ) . '`?\b/i' );
	}
}
