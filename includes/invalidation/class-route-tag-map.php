<?php
/**
 * Maps core list routes to their list tags.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Invalidation;

use Rockfire\Rest_Api_Cache\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Knows which `list:` tags a REST route carries (`/wp/v2/posts` => `list:post`).
 *
 * The map is built lazily from the registered post types and taxonomies and kept for the rest of
 * the request; {@see self::flush()} drops it when a type is registered late and at the start of
 * the main dispatch. Matching is exact, after lower-casing and removing trailing slashes. The
 * non-empty result of a route is filtered once via {@see Hooks::ROUTE_LIST_TAGS} and remembered; the
 * remembered results are capped and empty results are not remembered, so long-running workers that
 * see arbitrary routes do not grow the memo.
 *
 * @since 1.0.0
 */
final class Route_Tag_Map {

	/**
	 * Maximum number of remembered filtered results.
	 */
	private const MAX_RESOLVED = 100;

	/**
	 * Route => list tags, or null before the first lookup.
	 *
	 * @var array<string,list<string>>|null
	 */
	private ?array $map = null;

	/**
	 * Filtered result per route.
	 *
	 * @var array<string,list<string>>
	 */
	private array $resolved = array();

	/**
	 * List tags of a route.
	 *
	 * @param string $route Request route, e.g. `/wp/v2/posts`.
	 *
	 * @return string[] Normalised, sorted tags.
	 * @phpstan-return list<string>
	 */
	public function list_tags_for( string $route ): array {
		$key = strtolower( untrailingslashit( $route ) );
		if ( isset( $this->resolved[ $key ] ) ) {
			return $this->resolved[ $key ];
		}

		$tags = $this->map()[ $key ] ?? array();
		/**
		 * Filters the list tags of a route.
		 *
		 * @see Hooks::ROUTE_LIST_TAGS
		 */
		$tags = apply_filters( Hooks::ROUTE_LIST_TAGS, $tags, $route ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.

		$tags = is_iterable( $tags ) || is_string( $tags ) ? Tags::normalize_many( $tags ) : array();
		if ( array() !== $tags ) {
			if ( count( $this->resolved ) >= self::MAX_RESOLVED ) {
				$this->resolved = array();
			}
			$this->resolved[ $key ] = $tags;
		}
		return $tags;
	}

	/**
	 * Forget the built map and the filtered results (a post type or taxonomy was registered).
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->map      = null;
		$this->resolved = array();
	}

	/**
	 * The route map, built on first use.
	 *
	 * @return array<string,list<string>>
	 */
	private function map(): array {
		if ( null !== $this->map ) {
			return $this->map;
		}

		$map = array();
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $type ) {
			$this->add( $map, $type, $type->name );
		}
		foreach ( get_taxonomies( array( 'show_in_rest' => true ), 'objects' ) as $taxonomy ) {
			$this->add( $map, $taxonomy, $taxonomy->name );
		}

		$map['/wp/v2/users']    = array( Tags::LIST_USERS );
		$map['/wp/v2/comments'] = array( Tags::LIST_COMMENTS );

		$search = array();
		foreach ( get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		) as $name ) {
			if ( 'attachment' !== $name ) {
				$search[] = Tags::list_for( (string) $name );
			}
		}
		foreach ( get_taxonomies(
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		) as $name ) {
			$search[] = Tags::list_for( (string) $name );
		}
		$map['/wp/v2/search'] = array_values( array_filter( $search ) );

		$this->map = $map;
		return $map;
	}

	/**
	 * Add the route of a post type or taxonomy.
	 *
	 * @param array<string,list<string>> $map  Map being built.
	 * @param \WP_Post_Type|\WP_Taxonomy $type Post type or taxonomy.
	 * @param string                     $name Its name.
	 *
	 * @return void
	 */
	private function add( array &$map, \WP_Post_Type|\WP_Taxonomy $type, string $name ): void {
		$tag = Tags::list_for( $name );
		if ( null === $tag ) {
			return;
		}
		$namespace = is_string( $type->rest_namespace ) && '' !== $type->rest_namespace ? $type->rest_namespace : 'wp/v2';
		$base      = is_string( $type->rest_base ) && '' !== $type->rest_base ? $type->rest_base : $name;
		$route     = strtolower( untrailingslashit( '/' . trim( $namespace, '/' ) . '/' . $base ) );

		$map[ $route ][] = $tag;
	}
}
