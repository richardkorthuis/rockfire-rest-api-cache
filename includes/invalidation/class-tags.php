<?php
/**
 * Cache tag vocabulary, builders and normaliser.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Invalidation;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers for building and normalising cache tags.
 *
 * Makes no WordPress calls, so it is safe to use in the mu-plugin phase.
 *
 * Vocabulary: `post:{ID}`, `term:{term_id}`, `term-ref:{term_id}`, `user:{ID}`, `comment:{ID}`,
 * `list:{post_type|taxonomy}`, `list:users`, `list:comments`, `route:{path}` and custom `{prefix}:{value}` tags.
 *
 * `term:{id}` marks a response that shows the data of a term (name, slug, count, ...) and is flushed whenever the
 * term changes. `term-ref:{id}` marks a response that refers to a term without showing its data, like a post with
 * its `categories`, `class_list` and `%category%` link. It is flushed when the term is deleted or its slug or parent
 * changes (for the term and its descendants); renaming, describing or recounting the term leaves such a response as it is.
 *
 * No normalised tag contains whitespace or a comma, so a list of tags can always be written as one
 * string separated by commas and/or whitespace (see {@see self::normalize_many()}).
 *
 * @since 1.0.0
 */
final class Tags {

	/**
	 * Maximum length of a tag in bytes.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 191;

	/**
	 * Tag for "any user list changed".
	 *
	 * @var string
	 */
	public const LIST_USERS = 'list:users';

	/**
	 * Tag for "any comment list changed".
	 *
	 * @var string
	 */
	public const LIST_COMMENTS = 'list:comments';

	/**
	 * Longest route path (bytes) stored verbatim; longer paths are hashed.
	 *
	 * @var int
	 */
	private const MAX_ROUTE_LENGTH = 185;

	/**
	 * Tag for a post (attachments included).
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Post ID.
	 * @return string|null Tag, or null when the ID is below 1.
	 */
	public static function post( int $id ): ?string {
		return self::id_tag( 'post', $id );
	}

	/**
	 * Tag for a term.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Term ID.
	 * @return string|null Tag, or null when the ID is below 1.
	 */
	public static function term( int $id ): ?string {
		return self::id_tag( 'term', $id );
	}

	/**
	 * Tag for a response that refers to a term by its ID only (e.g. the `categories` of a post).
	 *
	 * Flushed when the term is deleted or its slug or parent changes (for the term and its descendants), not when
	 * it is renamed, described or recounted.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Term ID.
	 * @return string|null Tag, or null when the ID is below 1.
	 */
	public static function term_ref( int $id ): ?string {
		return self::id_tag( 'term-ref', $id );
	}

	/**
	 * Tag for a user.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id User ID.
	 * @return string|null Tag, or null when the ID is below 1.
	 */
	public static function user( int $id ): ?string {
		return self::id_tag( 'user', $id );
	}

	/**
	 * Tag for a comment.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Comment ID.
	 * @return string|null Tag, or null when the ID is below 1.
	 */
	public static function comment( int $id ): ?string {
		return self::id_tag( 'comment', $id );
	}

	/**
	 * Tag for a post type or taxonomy list.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Post type or taxonomy name.
	 * @return string|null Tag, or null when the name is not `[a-z0-9_-]{1,64}` after lower-casing.
	 */
	public static function list_for( string $name ): ?string {
		$name = strtolower( $name );
		if ( 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/D', $name ) ) {
			return null;
		}
		return 'list:' . $name;
	}

	/**
	 * Tag for a concrete request path.
	 *
	 * The path is lower-cased, given a leading slash, stripped of trailing slashes (the root stays `/`)
	 * and has unsafe bytes and commas percent-encoded (a comma becomes `%2c`). Paths over 185 bytes are
	 * replaced by `#` + md5.
	 * The returned tag is stable under `normalize()`: an already-hashed `route:#<md5>` tag is passed
	 * through unchanged there.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Request path.
	 * @return string|null Tag.
	 */
	public static function route( string $path ): ?string {
		// Encode whitespace, control and non-ASCII bytes (> 0x7E) so the tag stays printable ASCII, and commas
		// so the tag survives the comma separated string form of normalize_many().
		$path = preg_replace_callback(
			'/[\x00-\x20,\x7f-\xff]/',
			static function ( array $m ): string {
				return '%' . bin2hex( $m[0] );
			},
			$path
		);
		if ( null === $path ) {
			return null;
		}

		$path = strtolower( $path );
		$path = '/' . ltrim( rtrim( $path, '/' ), '/' );

		if ( strlen( $path ) > self::MAX_ROUTE_LENGTH ) {
			return 'route:#' . md5( $path );
		}
		return 'route:' . $path;
	}

	/**
	 * Normalise one tag, or reject it.
	 *
	 * Idempotent: an already-hashed `route:#<md5>` tag (as returned by `route()`) is passed through unchanged.
	 * A comma is not allowed in a tag value; in a route value it is percent-encoded like in `route()`.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $tag Candidate tag.
	 * @return string|null Normalised tag, or null when invalid.
	 */
	public static function normalize( mixed $tag ): ?string {
		if ( ! is_string( $tag ) ) {
			return null;
		}

		$tag = strtolower( trim( $tag ) );
		$pos = strpos( $tag, ':' );
		if ( false === $pos ) {
			return null;
		}

		$prefix = substr( $tag, 0, $pos );
		$value  = substr( $tag, $pos + 1 );
		if ( '' === $value || 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,31}$/D', $prefix ) ) {
			return null;
		}

		// Whitespace and control characters are never allowed, in any tag.
		if ( 1 === preg_match( '/[\x00-\x20\x7f]/', $value ) ) {
			return null;
		}

		if ( 'route' === $prefix ) {
			if ( 1 === preg_match( '/^#[0-9a-f]{32}$/D', $value ) ) {
				// Already-hashed route tag from route(); pass through so normalisation is idempotent.
				$result = 'route:' . $value;
			} else {
				// Route values may contain raw non-ASCII; route() percent-encodes it.
				$result = self::route( $value );
			}
		} else {
			// Printable ASCII without the comma, the separator of the string form of normalize_many().
			if ( 1 !== preg_match( '/^[\x21-\x2b\x2d-\x7e]+$/D', $value ) ) {
				return null;
			}
			$result = self::normalize_non_route( $prefix, $value );
		}

		if ( null === $result || strlen( $result ) > self::MAX_LENGTH ) {
			return null;
		}
		return $result;
	}

	/**
	 * Normalise many tags into a unique, sorted list; invalid entries are dropped.
	 *
	 * The string form is split on commas and whitespace. That loses nothing: no valid tag contains either
	 * (a comma in a route path is written `%2c`, see {@see self::route()}).
	 *
	 * @since 1.0.0
	 *
	 * @param string|iterable<mixed> $tags Tags, or a string of tags separated by commas and/or whitespace.
	 * @return list<string> Unique tags in ascending order.
	 */
	public static function normalize_many( string|iterable $tags ): array {
		if ( is_string( $tags ) ) {
			$tags = preg_split( '/[\s,]+/', $tags, -1, PREG_SPLIT_NO_EMPTY );
			$tags = false === $tags ? array() : $tags;
		}

		$out = array();
		foreach ( $tags as $tag ) {
			$normalized = self::normalize( $tag );
			if ( null !== $normalized ) {
				$out[ $normalized ] = true;
			}
		}

		$out = array_map( 'strval', array_keys( $out ) );
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Build a `{prefix}:{id}` tag.
	 *
	 * @param string $prefix Tag prefix.
	 * @param int    $id     Object ID.
	 * @return string|null Tag, or null when the ID is below 1.
	 */
	private static function id_tag( string $prefix, int $id ): ?string {
		return $id < 1 ? null : $prefix . ':' . $id;
	}

	/**
	 * Validate the value of a non-route tag.
	 *
	 * @param string $prefix Lower-case prefix.
	 * @param string $value  Lower-case, printable-ASCII value.
	 * @return string|null Tag, or null when the value does not suit the prefix.
	 */
	private static function normalize_non_route( string $prefix, string $value ): ?string {
		switch ( $prefix ) {
			case 'post':
			case 'term':
			case 'term-ref':
			case 'user':
			case 'comment':
				return 1 === preg_match( '/^[1-9][0-9]{0,19}$/D', $value ) ? $prefix . ':' . $value : null;
			case 'list':
				return self::list_for( $value );
			default:
				return $prefix . ':' . $value;
		}
	}
}
