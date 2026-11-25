<?php
/**
 * Tests for the route to list tag map.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Route_Tag_Map;

/**
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\Route_Tag_Map
 */
class Test_Route_Tag_Map extends Rfrac_Test_Case {

	/**
	 * Map under test.
	 *
	 * @var Route_Tag_Map
	 */
	private Route_Tag_Map $map;

	public function set_up() {
		parent::set_up();
		$this->map = new Route_Tag_Map();
	}

	public function tear_down() {
		unregister_post_type( 'book' );
		unregister_post_type( 'item' );
		parent::tear_down();
	}

	public function test_core_routes(): void {
		$this->assertSame( array( 'list:post' ), $this->map->list_tags_for( '/wp/v2/posts' ) );
		$this->assertSame( array( 'list:page' ), $this->map->list_tags_for( '/wp/v2/pages' ) );
		$this->assertSame( array( 'list:attachment' ), $this->map->list_tags_for( '/wp/v2/media' ) );
		$this->assertSame( array( 'list:category' ), $this->map->list_tags_for( '/wp/v2/categories' ) );
		$this->assertSame( array( 'list:post_tag' ), $this->map->list_tags_for( '/wp/v2/tags' ) );
		$this->assertSame( array( 'list:users' ), $this->map->list_tags_for( '/wp/v2/users' ) );
		$this->assertSame( array( 'list:comments' ), $this->map->list_tags_for( '/wp/v2/comments' ) );
	}

	public function test_unknown_and_item_routes_have_no_list_tags(): void {
		$this->assertSame( array(), $this->map->list_tags_for( '/wp/v2/nothing' ) );
		$this->assertSame( array(), $this->map->list_tags_for( '/wp/v2/posts/5' ) );
		$this->assertSame( array(), $this->map->list_tags_for( '' ) );
	}

	public function test_match_ignores_case_and_trailing_slash(): void {
		$this->assertSame( array( 'list:post' ), $this->map->list_tags_for( '/WP/v2/Posts/' ) );
	}

	public function test_custom_rest_base_and_namespace(): void {
		register_post_type(
			'book',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'books',
			)
		);
		register_post_type(
			'item',
			array(
				'public'         => true,
				'show_in_rest'   => true,
				'rest_namespace' => 'shop/v1',
			)
		);

		$this->assertSame( array( 'list:book' ), $this->map->list_tags_for( '/wp/v2/books' ) );
		$this->assertSame( array(), $this->map->list_tags_for( '/wp/v2/book' ) );
		$this->assertSame( array( 'list:item' ), $this->map->list_tags_for( '/shop/v1/item' ) );
	}

	public function test_search_lists_public_types_without_attachments(): void {
		$tags = $this->map->list_tags_for( '/wp/v2/search' );

		$this->assertContains( 'list:post', $tags );
		$this->assertContains( 'list:page', $tags );
		$this->assertContains( 'list:category', $tags );
		$this->assertContains( 'list:post_tag', $tags );
		$this->assertNotContains( 'list:attachment', $tags );
	}

	public function test_non_rest_types_are_not_mapped(): void {
		register_post_type( 'book', array( 'public' => true ) );

		$this->assertSame( array(), $this->map->list_tags_for( '/wp/v2/book' ) );
		$this->assertNotContains( 'list:book', $this->map->list_tags_for( '/wp/v2/search' ) );
	}

	public function test_filter_runs_once_per_route_and_can_change_the_tags(): void {
		$calls = 0;
		add_filter(
			Hooks::ROUTE_LIST_TAGS,
			static function ( $tags ) use ( &$calls ) {
				++$calls;
				$tags[] = 'LIST:Extra';
				return $tags;
			},
			10,
			2
		);

		$this->assertSame( array( 'list:extra', 'list:post' ), $this->map->list_tags_for( '/wp/v2/posts' ) );
		$this->map->list_tags_for( '/wp/v2/posts' );
		$this->assertSame( 1, $calls );
	}

	public function test_string_filter_result_is_accepted(): void {
		add_filter(
			Hooks::ROUTE_LIST_TAGS,
			static fn() => 'list:one, LIST:two',
			10,
			2
		);

		$this->assertSame( array( 'list:one', 'list:two' ), $this->map->list_tags_for( '/wp/v2/posts' ) );
	}

	public function test_empty_results_are_not_remembered_and_the_memo_is_capped(): void {
		$calls = 0;
		add_filter(
			Hooks::ROUTE_LIST_TAGS,
			static function ( $tags, $route ) use ( &$calls ) {
				++$calls;
				return str_starts_with( $route, '/many/' ) ? array( 'list:x' ) : $tags;
			},
			10,
			2
		);

		$this->map->list_tags_for( '/unknown/route' );
		$this->map->list_tags_for( '/unknown/route' );
		$this->assertSame( 2, $calls, 'An empty result is not memoised.' );

		for ( $i = 0; $i < 250; $i++ ) {
			$this->map->list_tags_for( '/many/' . $i );
		}
		$property = new ReflectionProperty( $this->map, 'resolved' );
		$property->setAccessible( true );
		$this->assertLessThanOrEqual( 100, count( $property->getValue( $this->map ) ) );
	}

	public function test_flush_picks_up_late_registrations(): void {
		$this->assertSame( array(), $this->map->list_tags_for( '/wp/v2/book' ) );

		register_post_type(
			'book',
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);
		$this->assertSame( array(), $this->map->list_tags_for( '/wp/v2/book' ), 'Still the built map.' );

		$this->map->flush();
		$this->assertSame( array( 'list:book' ), $this->map->list_tags_for( '/wp/v2/book' ) );
	}
}
