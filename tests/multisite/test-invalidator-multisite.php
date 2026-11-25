<?php
/**
 * Multisite tests for the invalidator.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Schema\Migrator;
use Rockfire\Rest_Api_Cache\Storage\Database_Store;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\Invalidator
 * @group multisite
 */
class Test_Invalidator_Multisite extends Rfrac_Test_Case {

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run via composer test-multisite.' );
		}
		parent::set_up();
	}

	private function invalidator( $store ): Invalidator {
		return new Invalidator( $store, static fn( string $url ): ?string => null );
	}

	public function test_tags_queued_on_another_blog_flush_that_blog_only(): void {
		$blog_id = self::factory()->blog->create();
		$store   = new Database_Store( new Repository(), 0 );
		$inv     = $this->invalidator( $store );

		$on_main = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) );

		switch_to_blog( $blog_id );
		( new Migrator() )->install_site();
		$on_blog = Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) );
		$inv->queue( array( 'post:1' ), 'e' );
		$this->assertSame( array( $blog_id => array( 'post:1' ) ), $inv->pending() );
		restore_current_blog();

		$this->assertSame( 1, $inv->commit() );

		$this->assertSame( '1', Rfrac_Entry_Fixtures::get_state( $on_main ), 'Blog 1 is untouched.' );
		switch_to_blog( $blog_id );
		$this->assertSame( '2', Rfrac_Entry_Fixtures::get_state( $on_blog ) );
		restore_current_blog();
	}

	public function test_commit_restores_the_current_blog(): void {
		$blog_id = self::factory()->blog->create();
		$spy     = new Rfrac_Spy_Store();
		$inv     = $this->invalidator( $spy );
		$inv->queue( array( 'post:1' ), 'e', array(), $blog_id );
		$inv->queue( array( 'post:2' ), 'e' );

		$current = get_current_blog_id();
		$inv->commit();

		$this->assertSame( $current, get_current_blog_id() );
		$this->assertCount( 2, $spy->calls );
		$by_blog = array();
		foreach ( $spy->calls as $call ) {
			$by_blog[ $call[2] ] = $call[1];
		}
		$this->assertSame( array( $blog_id => array( 'post:1' ), $current => array( 'post:2' ) ), $by_blog );
	}

	public function test_blog_deleted_before_commit_is_skipped(): void {
		$blog_id = self::factory()->blog->create();
		$spy     = new Rfrac_Spy_Store();
		$inv     = $this->invalidator( $spy );
		$inv->queue( array( 'post:1' ), 'e', array(), $blog_id );
		$inv->queue( array( 'post:2' ), 'e' );

		wpmu_delete_blog( $blog_id, true );

		$this->assertSame( 1, $inv->commit() );
		$this->assertSame( array( array( 'post:2' ) ), $spy->calls_of( 'flush_by_tags' ) );
		$this->assertSame( array(), $inv->pending() );
	}

	public function test_flush_all_drops_only_the_current_blogs_pending_tags(): void {
		$blog_id = self::factory()->blog->create();
		$spy     = new Rfrac_Spy_Store();
		$inv     = $this->invalidator( $spy );
		$inv->queue( array( 'post:1' ), 'e', array(), $blog_id );
		$inv->queue( array( 'post:2' ), 'e' );

		$inv->flush_all();
		$this->assertSame( array( $blog_id => array( 'post:1' ) ), $inv->pending() );
	}

	public function test_a_store_resolver_is_called_on_the_blog_of_the_tags(): void {
		$blog_id = self::factory()->blog->create();
		$spy     = new Rfrac_Spy_Store();
		$seen    = array();
		$inv     = $this->invalidator(
			static function () use ( &$seen, $spy ): Rfrac_Spy_Store {
				$seen[] = get_current_blog_id();
				return $spy;
			}
		);
		$inv->queue( array( 'post:1' ), 'e', array(), $blog_id );
		$inv->queue( array( 'post:2' ), 'e' );

		$inv->commit();

		$this->assertEqualsCanonicalizing( array( $blog_id, get_current_blog_id() ), $seen );
	}

	public function test_the_container_invalidator_uses_the_container_store_of_each_blog(): void {
		$blog_id = self::factory()->blog->create();
		$plugin  = new Plugin();
		$resolve = ( new ReflectionProperty( Invalidator::class, 'store' ) )->getValue( $plugin->invalidator() );

		$main = $resolve();
		switch_to_blog( $blog_id );
		$other = $resolve();
		restore_current_blog();

		$stores = ( new ReflectionProperty( Plugin::class, 'stores' ) )->getValue( $plugin );
		$this->assertSame( $main, $stores[ get_current_blog_id() ] );
		$this->assertSame( $other, $stores[ $blog_id ] );
		$this->assertEqualsCanonicalizing( array( $blog_id, get_current_blog_id() ), array_keys( $stores ) );
		$this->assertNotSame( $stores[ $blog_id ], $stores[ get_current_blog_id() ] );
	}

	public function test_a_deferred_change_flushes_the_blog_it_was_deferred_on(): void {
		$blog_id = self::factory()->blog->create();
		$inv     = $this->invalidator( new Rfrac_Spy_Store() );

		switch_to_blog( $blog_id );
		$inv->defer( 'k', static fn(): array => array( array( 'post:1' ), 'e' ) );
		restore_current_blog();

		$this->assertSame( array( $blog_id => array( 'post:1' ) ), $inv->pending() );
	}

	public function test_term_changes_on_two_blogs_are_settled_per_blog(): void {
		$blog_id = self::factory()->blog->create();
		$inv     = $this->invalidator( new Rfrac_Spy_Store() );
		$events  = new Rockfire\Rest_Api_Cache\Invalidation\Term_Events( $inv, Plugin::instance()->relevance(), new Rockfire\Rest_Api_Cache\Invalidation\Term_Ancestry() );
		$events->register();

		$main_post = self::factory()->post->create();
		$main_tag  = self::factory()->tag->create();
		switch_to_blog( $blog_id );
		$blog_post = self::factory()->post->create();
		$blog_tag  = self::factory()->tag->create();
		restore_current_blog();
		$inv->commit();

		wp_set_object_terms( $main_post, array( $main_tag ), 'post_tag' );
		switch_to_blog( $blog_id );
		wp_set_object_terms( $blog_post, array( $blog_tag ), 'post_tag' );
		restore_current_blog();

		$pending = $inv->pending();
		$this->assertContains( 'term:' . $main_tag, $pending[ get_current_blog_id() ] );
		$this->assertContains( 'post:' . $main_post, $pending[ get_current_blog_id() ] );
		$this->assertContains( 'term:' . $blog_tag, $pending[ $blog_id ] );
		$this->assertContains( 'post:' . $blog_post, $pending[ $blog_id ] );
	}

	public function test_the_same_object_id_on_two_blogs_is_netted_separately(): void {
		$blog_id = self::factory()->blog->create();
		$inv     = $this->invalidator( new Rfrac_Spy_Store() );
		$events  = new Rockfire\Rest_Api_Cache\Invalidation\Term_Events( $inv, Plugin::instance()->relevance(), new Rockfire\Rest_Api_Cache\Invalidation\Term_Ancestry() );
		$events->register();

		$main_tag = self::factory()->tag->create();
		$main_tt  = (int) get_term( $main_tag )->term_taxonomy_id;
		switch_to_blog( $blog_id );
		$blog_tag = self::factory()->tag->create();
		$blog_tt  = (int) get_term( $blog_tag )->term_taxonomy_id;
		restore_current_blog();
		$inv->commit();

		// Object 999 on both blogs: added on the main site, added and removed again on the other one.
		$events->on_added_term_relationship( 999, $main_tt, 'post_tag' );
		$events->on_set_object_terms( 999, array( $main_tag ), array( $main_tt ), 'post_tag', false, array() );
		switch_to_blog( $blog_id );
		$events->on_added_term_relationship( 999, $blog_tt, 'post_tag' );
		$events->on_set_object_terms( 999, array( $blog_tag ), array( $blog_tt ), 'post_tag', false, array() );
		$events->on_deleted_term_relationships( 999, array( $blog_tt ), 'post_tag' );
		$events->on_set_object_terms( 999, array(), array(), 'post_tag', false, array( $blog_tt ) );
		restore_current_blog();

		$pending = $inv->pending();
		$this->assertContains( 'term:' . $main_tag, $pending[ get_current_blog_id() ] );
		$this->assertArrayNotHasKey( $blog_id, $pending, 'Assigned and removed again on the other site: nothing to flush there.' );
	}

	public function test_a_default_category_replaced_on_a_sub_site_is_not_an_assignment_change(): void {
		$blog_id = self::factory()->blog->create();
		$inv     = $this->invalidator( new Rfrac_Spy_Store() );
		$events  = new Rockfire\Rest_Api_Cache\Invalidation\Term_Events( $inv, Plugin::instance()->relevance(), new Rockfire\Rest_Api_Cache\Invalidation\Term_Ancestry() );
		$events->register();
		$seen = array();
		add_filter(
			Rockfire\Rest_Api_Cache\Hooks::INVALIDATION_TAGS,
			static function ( $tags, $event, $context ) use ( &$seen ) {
				if ( Invalidator::EVENT_OBJECT_TERMS_SET === $event ) {
					$seen[] = array( get_current_blog_id(), $context['term_ids'] );
				}
				return $tags;
			},
			10,
			3
		);

		switch_to_blog( $blog_id );
		$cat     = self::factory()->category->create();
		$post_id = wp_insert_post( array( 'post_title' => 'New', 'post_status' => 'publish' ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		wp_set_post_categories( $post_id, array( $cat ) );
		restore_current_blog();

		$pending = $inv->pending();
		$this->assertSame( array( array( get_current_blog_id(), array( $cat ) ) ), $seen );
		$this->assertContains( 'post:' . $post_id, $pending[ $blog_id ] );
		$this->assertContains( 'term:' . $cat, $pending[ $blog_id ] );
	}
}
