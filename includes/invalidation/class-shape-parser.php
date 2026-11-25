<?php
/**
 * Finds tags in response data of custom endpoints.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Invalidation;

defined( 'ABSPATH' ) || exit;

/**
 * Walks response data and tags every object shape it knows: WordPress objects and the arrays core
 * controllers produce (posts, terms, users, comments, search results).
 *
 * The walk is bounded: it stops descending below {@see self::MAX_DEPTH} levels and stops after
 * {@see self::MAX_NODES} arrays and objects, returning what it has found so far. Arrays with more
 * than {@see self::MAX_RECORD_KEYS} entries are lists or maps, not object shapes: only their children
 * are visited, the keys are not matched (and not copied). It reads data and runs at most one
 * `get_post()` per comment shape; it never writes.
 *
 * Term shapes carry the term's data and get `term:{id}`; the term IDs a post shape lists are not tagged. A comment
 * shape keeps the `post:{id}` of its post: the comment's `link` is built from the post's permalink.
 *
 * @since 1.0.0
 */
final class Shape_Parser {

	/**
	 * Number of levels that are parsed.
	 */
	public const MAX_DEPTH = 12;

	/**
	 * Number of arrays and objects that are visited.
	 */
	public const MAX_NODES = 20000;

	/**
	 * Largest array that is matched against the object shapes.
	 */
	public const MAX_RECORD_KEYS = 500;

	/**
	 * Parse response data.
	 *
	 * @param mixed $data Response data.
	 *
	 * @return string[] Normalised, sorted tags.
	 * @phpstan-return list<string>
	 */
	public function parse( mixed $data ): array {
		$state = array(
			'tags'  => array(),
			'nodes' => 0,
		);
		$this->walk( $data, 0, $state );
		return Tags::normalize_many( array_keys( $state['tags'] ) );
	}

	/**
	 * Visit a value.
	 *
	 * @param mixed               $value Value.
	 * @param int                 $depth Depth of the value, 0 for the root.
	 * @param array<string,mixed> $state Found tags and visited node count.
	 * @phpstan-param array{tags:array<string,true>,nodes:int} $state
	 *
	 * @return void
	 */
	private function walk( mixed $value, int $depth, array &$state ): void {
		if ( ! is_array( $value ) && ! is_object( $value ) ) {
			return;
		}
		if ( $depth >= self::MAX_DEPTH || $state['nodes'] >= self::MAX_NODES ) {
			return;
		}
		++$state['nodes'];

		if ( is_object( $value ) ) {
			$this->walk_object( $value, $depth, $state );
			return;
		}

		if ( count( $value ) > self::MAX_RECORD_KEYS ) {
			$record = $value; // A long list: no shape, no copy.
		} else {
			$record = array_change_key_case( $value, CASE_LOWER );
			$this->match_shape( $record, $state );
		}

		foreach ( $record as $child ) {
			if ( $state['nodes'] >= self::MAX_NODES ) {
				return;
			}
			if ( is_array( $child ) || is_object( $child ) ) {
				$this->walk( $child, $depth + 1, $state );
			}
		}
	}

	/**
	 * Visit an object.
	 *
	 * @param object              $item Object.
	 * @param int                 $depth Depth of the object.
	 * @param array<string,mixed> $state Found tags and visited node count.
	 * @phpstan-param array{tags:array<string,true>,nodes:int} $state
	 *
	 * @return void
	 */
	private function walk_object( object $item, int $depth, array &$state ): void {
		if ( $item instanceof \WP_Post ) {
			$this->add( $state, Tags::post( $item->ID ), Tags::list_for( (string) $item->post_type ) );
		} elseif ( $item instanceof \WP_Term ) {
			$this->add( $state, Tags::term( $item->term_id ), Tags::list_for( (string) $item->taxonomy ) );
		} elseif ( $item instanceof \WP_User ) {
			$this->add( $state, Tags::user( $item->ID ), Tags::LIST_USERS );
		} elseif ( $item instanceof \WP_Comment ) {
			$this->add( $state, Tags::comment( (int) $item->comment_ID ), Tags::LIST_COMMENTS );
		} elseif ( $item instanceof \JsonSerializable ) {
			$this->walk( $item->jsonSerialize(), $depth + 1, $state );
		} else {
			$this->walk( get_object_vars( $item ), $depth + 1, $state );
		}
	}

	/**
	 * Match the shapes of one array level.
	 *
	 * @param array<int|string,mixed> $record Array with lower-cased keys.
	 * @param array<string,mixed>     $state Found tags and visited node count.
	 * @phpstan-param array{tags:array<string,true>,nodes:int} $state
	 *
	 * @return void
	 */
	private function match_shape( array $record, array &$state ): void {
		$id = array_key_exists( 'id', $record ) ? self::to_id( $record['id'] ) : null;

		if ( array_key_exists( 'id', $record ) && self::is_post_type( $record['post_type'] ?? null ) ) { // R1.
			if ( null !== $id ) {
				$this->add( $state, Tags::post( $id ), Tags::list_for( (string) $record['post_type'] ) );
			}
		} elseif ( self::is_taxonomy( $record['taxonomy'] ?? null ) ) { // R2.
			$term_id = null;
			if ( array_key_exists( 'id', $record ) && array_key_exists( 'name', $record ) && array_key_exists( 'slug', $record ) ) {
				$term_id = $id;
			} elseif ( array_key_exists( 'term_id', $record ) ) {
				$term_id = self::to_id( $record['term_id'] );
			}
			if ( null !== $term_id ) {
				$this->add( $state, Tags::term( $term_id ), Tags::list_for( (string) $record['taxonomy'] ) );
			}
		} elseif ( array_key_exists( 'id', $record ) && array_key_exists( 'type', $record ) && ( array_key_exists( 'slug', $record ) || array_key_exists( 'status', $record ) ) ) { // R3.
			if ( null !== $id && self::is_post_type( $record['type'] ) ) {
				$this->add( $state, Tags::post( (int) $id ), Tags::list_for( (string) $record['type'] ) );
			}
		} elseif ( null !== $id && array_key_exists( 'slug', $record ) && self::links_to_users( $record['_links'] ?? null ) ) { // R4.
			$this->add( $state, Tags::user( $id ), Tags::LIST_USERS );
		} elseif ( null !== $id && array_key_exists( 'subtype', $record ) && in_array( $record['type'] ?? null, array( 'post', 'term' ), true ) ) { // R6.
			if ( 'post' === $record['type'] ) {
				$this->add( $state, Tags::post( $id ), self::is_post_type( $record['subtype'] ) ? Tags::list_for( (string) $record['subtype'] ) : null );
			} else {
				$this->add( $state, Tags::term( $id ), self::is_taxonomy( $record['subtype'] ) ? Tags::list_for( (string) $record['subtype'] ) : null );
			}
		} elseif ( array_key_exists( 'comment_id', $record ) ) { // R7.
			$this->add( $state, Tags::comment( (int) self::to_id( $record['comment_id'] ) ) );
		} elseif ( null !== $id && array_key_exists( 'user_login', $record ) ) { // R8.
			$this->add( $state, Tags::user( $id ) );
		}

		// R5 is independent of the chain above.
		if ( 'comment' === ( $record['type'] ?? null ) && array_key_exists( 'post', $record ) ) {
			$post_id = self::to_id( $record['post'] );
			if ( null !== $post_id && null !== get_post( $post_id ) ) {
				$this->add( $state, Tags::post( $post_id ) );
			}
			$this->add( $state, null !== $id ? Tags::comment( $id ) : null, Tags::LIST_COMMENTS );
		}
	}

	/**
	 * Add tags to the result.
	 *
	 * @param array<string,mixed> $state Found tags and visited node count.
	 * @phpstan-param array{tags:array<string,true>,nodes:int} $state
	 * @param string|null         ...$tags Tags; null is skipped.
	 *
	 * @return void
	 */
	private function add( array &$state, ?string ...$tags ): void {
		foreach ( $tags as $tag ) {
			if ( null !== $tag ) {
				$state['tags'][ $tag ] = true;
			}
		}
	}

	/**
	 * An ID: an int or a digit string above zero.
	 *
	 * @param mixed $value Candidate.
	 *
	 * @return int|null
	 */
	private static function to_id( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) {
			$id = (int) $value;
			return $id > 0 ? $id : null;
		}
		return null;
	}

	/**
	 * Whether a value names a registered post type.
	 *
	 * @param mixed $value Candidate.
	 *
	 * @return bool
	 */
	private static function is_post_type( mixed $value ): bool {
		return is_string( $value ) && '' !== $value && post_type_exists( $value );
	}

	/**
	 * Whether a value names a registered taxonomy.
	 *
	 * @param mixed $value Candidate.
	 *
	 * @return bool
	 */
	private static function is_taxonomy( mixed $value ): bool {
		return is_string( $value ) && '' !== $value && taxonomy_exists( $value );
	}

	/**
	 * Whether `_links.collection[0].href` ends with `/wp/v2/users`.
	 *
	 * @param mixed $links `_links` value.
	 *
	 * @return bool
	 */
	private static function links_to_users( mixed $links ): bool {
		$href = is_array( $links ) && isset( $links['collection'][0]['href'] ) ? $links['collection'][0]['href'] : null;
		return is_string( $href ) && str_ends_with( $href, '/wp/v2/users' );
	}
}
