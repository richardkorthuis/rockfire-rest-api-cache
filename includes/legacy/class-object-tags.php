<?php
/**
 * Cache tags for the object types and object IDs of the legacy hooks.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Invalidation\Tags;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the `object_type` and object ID pairs of the legacy hooks onto Rockfire cache tags.
 *
 * The legacy cache relations name an object by a type (`post`, `page`, `category`, `users`, `comments`, a custom
 * relation such as `acf-options`, ...) and an ID. Rockfire tags a response with `post:{id}`, `term:{id}`, `user:{id}`,
 * `comment:{id}`, `list:{name}` and `route:{path}` instead. The class only asks WordPress whether a name is a
 * registered post type or taxonomy, so it is shared by the hook bridges and the class shims.
 *
 * @since 1.0.0
 */
final class Object_Tags {

	/**
	 * Object types of the legacy lists that Rockfire serves from a fixed route.
	 *
	 * @var list<string>
	 */
	private const ROUTE_LISTS = array( 'statuses', 'taxonomies', 'types' );

	/**
	 * The tag of one object.
	 *
	 * `user`/`users` give `user:{id}`, `comment`/`comments` give `comment:{id}`, a registered post type `post:{id}` and
	 * a registered taxonomy `term:{id}`; for these the ID must be a positive integer (or a string of digits). `list`
	 * and `route` name tag families, not objects. Any other type is a custom relation and gives the normalised
	 * `{type}:{id}` tag, for example `acf-options:header`.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $object_type Legacy object type.
	 * @param int|string $id          Object ID, or the value of a custom relation.
	 *
	 * @return string|null The tag, or null when the pair names no valid tag.
	 */
	public static function item_tag( string $object_type, int|string $id ): ?string {
		$type = strtolower( $object_type );

		if ( 'list' === $type || 'route' === $type ) {
			return null;
		}
		if ( 'user' === $type || 'users' === $type ) {
			return Tags::user( self::positive_id( $id ) );
		}
		if ( 'comment' === $type || 'comments' === $type ) {
			return Tags::comment( self::positive_id( $id ) );
		}
		if ( post_type_exists( $type ) ) {
			return Tags::post( self::positive_id( $id ) );
		}
		if ( taxonomy_exists( $type ) ) {
			return Tags::term( self::positive_id( $id ) );
		}

		return Tags::normalize( $type . ':' . $id );
	}

	/**
	 * What to flush for a list of objects of a type.
	 *
	 * `user`/`users` give the `list:users` tag, `comment`/`comments` the `list:comments` tag, `statuses`,
	 * `taxonomies` and `types` the route `/wp/v2/{name}`, and any other name that makes a list tag (a post type or
	 * a taxonomy) the `list:{name}` tag.
	 *
	 * @since 1.0.0
	 *
	 * @param string $object_type Legacy object type.
	 *
	 * @return array{0:string,1:string}|null Target kind (`tag` or `route`) and the tag or route; null when the name
	 *                                       makes no list tag.
	 * @phpstan-return array{0:'tag'|'route',1:string}|null
	 */
	public static function list_target( string $object_type ): ?array {
		$name = strtolower( $object_type );

		if ( 'user' === $name || 'users' === $name ) {
			return array( 'tag', Tags::LIST_USERS );
		}
		if ( 'comment' === $name || 'comments' === $name ) {
			return array( 'tag', Tags::LIST_COMMENTS );
		}
		if ( in_array( $name, self::ROUTE_LISTS, true ) ) {
			return array( 'route', '/wp/v2/' . $name );
		}

		$tag = Tags::list_for( $name );
		return null === $tag ? null : array( 'tag', $tag );
	}

	/**
	 * An object ID as a positive integer.
	 *
	 * @param int|string $id Object ID.
	 *
	 * @return int The ID, or 0 for a value that is not a whole number.
	 */
	private static function positive_id( int|string $id ): int {
		if ( is_int( $id ) ) {
			return $id;
		}
		return 1 === preg_match( '/^[0-9]{1,18}$/D', $id ) ? (int) $id : 0;
	}
}
