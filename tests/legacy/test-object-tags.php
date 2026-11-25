<?php
/**
 * Tests for the cache tags of the legacy object types.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Legacy\Object_Tags;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Object_Tags
 */
class Test_Object_Tags extends WP_UnitTestCase {

	/**
	 * Item tags for pairs of an object type and an ID.
	 *
	 * @return array<string,array{0:string,1:int|string,2:string|null}>
	 */
	public static function item_tag_provider(): array {
		return array(
			'post type page'          => array( 'page', 5, 'post:5' ),
			'post type post'          => array( 'post', 7, 'post:7' ),
			'attachment'              => array( 'attachment', 8, 'post:8' ),
			'taxonomy category'       => array( 'category', 3, 'term:3' ),
			'taxonomy post_tag'       => array( 'post_tag', 4, 'term:4' ),
			'users'                   => array( 'users', 2, 'user:2' ),
			'user'                    => array( 'user', 2, 'user:2' ),
			'comment'                 => array( 'comment', 9, 'comment:9' ),
			'comments'                => array( 'comments', 9, 'comment:9' ),
			'a string ID'             => array( 'page', '5', 'post:5' ),
			'an upper case type'      => array( 'Users', 2, 'user:2' ),
			'a custom relation'       => array( 'acf-options', 'header', 'acf-options:header' ),
			'a custom numeric ID'     => array( 'order', 12, 'order:12' ),
			'a custom upper case ID'  => array( 'acf-options', 'Header', 'acf-options:header' ),
			'a non-numeric post ID'   => array( 'post', 'abc', null ),
			'a non-numeric user ID'   => array( 'users', 'abc', null ),
			'a fractional comment ID' => array( 'comment', '1.5', null ),
			'a zero post ID'          => array( 'post', 0, null ),
			'a negative term ID'      => array( 'category', -3, null ),
			'a zero user ID'          => array( 'user', '0', null ),
			'the list family'         => array( 'list', 5, null ),
			'the route family'        => array( 'route', '/wp/v2/posts', null ),
			'an invalid custom value' => array( 'acf-options', 'a b', null ),
			'an empty custom value'   => array( 'acf-options', '', null ),
		);
	}

	/**
	 * @dataProvider item_tag_provider
	 *
	 * @param string      $object_type Object type.
	 * @param int|string  $id          Object ID.
	 * @param string|null $expected    Expected tag.
	 */
	public function test_item_tag( string $object_type, $id, ?string $expected ): void {
		$this->assertSame( $expected, Object_Tags::item_tag( $object_type, $id ) );
	}

	public function test_a_registered_custom_post_type_and_taxonomy(): void {
		register_post_type( 'rfrac_book', array( 'public' => true ) );
		register_taxonomy( 'rfrac_genre', 'rfrac_book' );

		try {
			$this->assertSame( 'post:6', Object_Tags::item_tag( 'rfrac_book', 6 ) );
			$this->assertSame( 'term:6', Object_Tags::item_tag( 'rfrac_genre', 6 ) );
			$this->assertNull( Object_Tags::item_tag( 'rfrac_book', 'abc' ) );
			$this->assertSame( array( 'tag', 'list:rfrac_book' ), Object_Tags::list_target( 'rfrac_book' ) );
		} finally {
			unregister_taxonomy( 'rfrac_genre' );
			unregister_post_type( 'rfrac_book' );
		}

		$this->assertSame( 'rfrac_book:6', Object_Tags::item_tag( 'rfrac_book', 6 ), 'Once unregistered, the type is a custom relation.' );
	}

	/**
	 * List targets for object types.
	 *
	 * @return array<string,array{0:string,1:array{0:string,1:string}|null}>
	 */
	public static function list_target_provider(): array {
		return array(
			'a post type'     => array( 'post', array( 'tag', 'list:post' ) ),
			'a taxonomy'      => array( 'category', array( 'tag', 'list:category' ) ),
			'users'           => array( 'users', array( 'tag', 'list:users' ) ),
			'user'            => array( 'user', array( 'tag', 'list:users' ) ),
			'comments'        => array( 'comments', array( 'tag', 'list:comments' ) ),
			'comment'         => array( 'comment', array( 'tag', 'list:comments' ) ),
			'statuses'        => array( 'statuses', array( 'route', '/wp/v2/statuses' ) ),
			'taxonomies'      => array( 'taxonomies', array( 'route', '/wp/v2/taxonomies' ) ),
			'types'           => array( 'types', array( 'route', '/wp/v2/types' ) ),
			'an upper case'   => array( 'Types', array( 'route', '/wp/v2/types' ) ),
			'a custom name'   => array( 'acf-options', array( 'tag', 'list:acf-options' ) ),
			'an invalid name' => array( 'Bad Name!', null ),
			'an empty name'   => array( '', null ),
			'a colon'         => array( 'a:b', null ),
		);
	}

	/**
	 * @dataProvider list_target_provider
	 *
	 * @param string                           $object_type Object type.
	 * @param array{0:string,1:string}|null $expected    Expected target.
	 */
	public function test_list_target( string $object_type, ?array $expected ): void {
		$this->assertSame( $expected, Object_Tags::list_target( $object_type ) );
	}
}
