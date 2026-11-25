<?php
/**
 * Tags of oEmbed requests.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Invalidation;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the post an oEmbed request is about (`/oembed/1.0/embed?url=…`) to `post:` and `user:` tags.
 *
 * @since 1.0.0
 */
final class Oembed_Tagger {

	/**
	 * The oEmbed route.
	 */
	private const ROUTE = '/oembed/1.0/embed';

	/**
	 * Tags of a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return string[] Tags; empty for other routes and unknown URLs.
	 * @phpstan-return list<string>
	 */
	public function tags_for( \WP_REST_Request $request ): array {
		if ( self::ROUTE !== strtolower( untrailingslashit( $request->get_route() ) ) ) {
			return array();
		}
		$url = $request->get_param( 'url' );
		if ( ! is_string( $url ) || '' === $url ) {
			return array();
		}

		// Only a path (a single leading slash) belongs to this site; `//host/…` and absolute URLs are resolved as given.
		$resolve = $url;
		if ( str_starts_with( $resolve, '/' ) && ! str_starts_with( $resolve, '//' ) ) {
			$resolve = home_url( $resolve );
		}
		$post_id = url_to_postid( $resolve );

		// This filter is documented in wp-includes/embed.php (rest route of oEmbed).
		$post_id = apply_filters( 'oembed_request_post_id', $post_id, $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$post_id = is_numeric( $post_id ) ? (int) $post_id : 0;
		if ( $post_id < 1 ) {
			return array();
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$tags      = array( Tags::post( $post->ID ) );
		$thumbnail = (int) get_post_thumbnail_id( $post ); // Core's oEmbed data includes the thumbnail.
		if ( $thumbnail > 0 ) {
			$tags[] = Tags::post( $thumbnail );
		}
		if ( (int) $post->post_author > 0 ) {
			$tags[] = Tags::user( (int) $post->post_author );
		}
		return array_values( array_filter( $tags ) );
	}
}
