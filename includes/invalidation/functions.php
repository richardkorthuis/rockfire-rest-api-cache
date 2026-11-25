<?php
/**
 * The public PHP API: flush cached REST responses and tag them from your own code.
 *
 * Every function is a thin, validated wrapper around the plugin's invalidator and tag collector. A
 * wrong argument is reported with `_doing_it_wrong()` and the function returns 0 or false without
 * touching the cache. Flushing happens immediately (it is not buffered until `shutdown`) and acts on
 * the current site of a multisite network.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Invalidation\Api;
use Rockfire\Rest_Api_Cache\Services\Cache_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Flush every cached response that carries one of the given tags.
 *
 * Tags are `{prefix}:{value}`, e.g. `post:12`, `term:3`, `list:product` or a custom tag you added with
 * `rfrac_tag_response()`. They are lowercased and normalised; invalid tags are dropped and reported
 * once with `_doing_it_wrong()`.
 *
 * Tag format: the prefix is 1 to 32 characters of `[a-z][a-z0-9_-]`, starting with a letter. The prefixes
 * `post`, `term`, `term-ref`, `user` and `comment` are reserved for numeric IDs (`post:12`), `list` takes a post type or
 * taxonomy name (`list:product`, `list:users`, `list:comments`) and `route` is managed by the plugin. A custom
 * value is printable ASCII without spaces, and the whole tag is at most 191 bytes.
 *
 * Example:
 *
 *     // After saving an ACF options page, drop every response tagged with it.
 *     $flushed = rfrac_flush_tag( 'acf-options:header' );
 *     rfrac_flush_tag( array( 'post:12', 'list:product' ) );
 *
 * @since 1.0.0
 *
 * @param string|string[] $tags One tag, tags separated by commas or whitespace, or an array of tags.
 * @phpstan-param string|list<string> $tags
 *
 * @return int Number of cache entries flushed; 0 when no valid tag was given (an empty list is not reported, an invalid tag is).
 */
function rfrac_flush_tag( string|array $tags ): int {
	$api = Api::get();
	if ( null === $api ) {
		_doing_it_wrong( __FUNCTION__, 'The Rockfire – REST API Cache plugin is not loaded yet; call this function from plugins_loaded or later.', '1.0.0' );
		return 0;
	}
	list( $valid, $all_valid ) = Api::parse_tags( $tags );
	if ( ! $all_valid ) {
		_doing_it_wrong( __FUNCTION__, esc_html( Api::TAG_FORMAT_MESSAGE . ' Invalid tags were ignored.' ), '1.0.0' );
	}
	if ( array() === $valid ) {
		return 0;
	}
	return $api->invalidator()->flush_tags( $valid );
}

/**
 * Flush the cached responses of one URL.
 *
 * The URL may be absolute or root-relative; the query string counts (`?per_page=5` is another entry
 * than `?per_page=10`), and parameters that do not change the response are ignored like at lookup time (the cache policy of the current
 * configuration decides which). The entries of the path as given, of its canonical encoding (so
 * `/wp-json/x/café` and `/wp-json/x/caf%c3%a9` also reach a request sent as `/wp-json/x/caf%C3%A9`) and,
 * for an `index.php/` URL, of the decoded PATH_INFO request are flushed.
 *
 * Example:
 *
 *     rfrac_flush_url( rest_url( 'wp/v2/posts' ) );
 *
 * @since 1.0.0
 *
 * @param string $url The REST URL, e.g. `/wp-json/wp/v2/posts?per_page=5`.
 *
 * @return int Number of cache entries flushed; 0 for an empty or over-long (more than 2048 characters) URL (reported with `_doing_it_wrong()`) or a URL that is not a REST route.
 */
function rfrac_flush_url( string $url ): int {
	$api = Api::get();
	if ( null === $api ) {
		_doing_it_wrong( __FUNCTION__, 'The Rockfire – REST API Cache plugin is not loaded yet; call this function from plugins_loaded or later.', '1.0.0' );
		return 0;
	}
	$url = trim( $url );
	if ( '' === $url || strlen( $url ) > Cache_Service::MAX_URL_LENGTH ) {
		_doing_it_wrong( __FUNCTION__, esc_html( 'The URL must not be empty or longer than ' . Cache_Service::MAX_URL_LENGTH . ' characters.' ), '1.0.0' );
		return 0;
	}
	return $api->invalidator()->flush_url( $url );
}

/**
 * Flush the cached responses of a REST route.
 *
 * Accepts a registered route pattern such as `/wp/v2/posts/(?P<id>[\d]+)` or a concrete path such
 * as `/wp/v2/posts/12`. Leading and trailing slashes are optional; `/` is the index route. The match is
 * literal (a pattern is not evaluated as a regular expression, `%` and `_` are not wildcards) and
 * includes everything below the route: flushing `/wp/v2/posts` also flushes `/wp/v2/posts/12`.
 *
 * Example:
 *
 *     rfrac_flush_route( '/wp/v2/posts' );
 *
 * @since 1.0.0
 *
 * @param string $route The route, e.g. `wp/v2/posts`.
 *
 * @return int Number of cache entries flushed; 0 for an empty route (nothing to flush, not reported as incorrect usage).
 */
function rfrac_flush_route( string $route ): int {
	$api = Api::get();
	if ( null === $api ) {
		_doing_it_wrong( __FUNCTION__, 'The Rockfire – REST API Cache plugin is not loaded yet; call this function from plugins_loaded or later.', '1.0.0' );
		return 0;
	}
	$route = trim( $route );
	if ( '' === $route ) {
		return 0; // Nothing to flush; not a usage error.
	}
	return $api->invalidator()->flush_route( '/' . trim( $route, '/' ) );
}

/**
 * Flush every cached response of the current site.
 *
 * Example:
 *
 *     rfrac_flush_all();
 *
 * @since 1.0.0
 *
 * @return int Number of cache entries flushed.
 */
function rfrac_flush_all(): int {
	$api = Api::get();
	if ( null === $api ) {
		_doing_it_wrong( __FUNCTION__, 'The Rockfire – REST API Cache plugin is not loaded yet; call this function from plugins_loaded or later.', '1.0.0' );
		return 0;
	}
	return $api->invalidator()->flush_all();
}

/**
 * Tag the response being built, so it can be flushed with `rfrac_flush_tag()` later.
 *
 * Call it from the route callback or from `rest_post_dispatch`, before the response is sent. It only works
 * while the main REST request is being dispatched: not from `rest_api_init` handlers, not after the response
 * was sent, and not for nested `rest_do_request()` calls made outside a main request. The tags are stored with
 * the cached response; when the response has too many tags it is not cached at all and false is returned.
 *
 * Example:
 *
 *     register_rest_route(
 *         'my/v1',
 *         '/header',
 *         array(
 *             'methods'             => 'GET',
 *             'permission_callback' => '__return_true',
 *             'callback'            => static function () {
 *                 rfrac_tag_response( 'acf-options:header' );
 *                 return get_fields( 'option' );
 *             },
 *         )
 *     );
 *
 * @since 1.0.0
 *
 * @param string|string[] $tags One tag, tags separated by commas or whitespace, or an array of tags.
 * @phpstan-param string|list<string> $tags
 *
 * @return bool True when the tags were added; false (with `_doing_it_wrong()`) outside a main REST dispatch or without a valid tag, and false when the tags could not be added because the tag limit was exceeded.
 */
function rfrac_tag_response( string|array $tags ): bool {
	$api = Api::get();
	if ( null === $api ) {
		_doing_it_wrong( __FUNCTION__, 'The Rockfire – REST API Cache plugin is not loaded yet; call this function from plugins_loaded or later.', '1.0.0' );
		return false;
	}
	if ( ! $api->collector()->is_dispatching() ) {
		_doing_it_wrong( __FUNCTION__, 'Responses can only be tagged while a REST request is being dispatched, e.g. from a REST route callback or rest_post_dispatch.', '1.0.0' );
		return false;
	}
	list( $valid, $all_valid ) = Api::parse_tags( $tags );
	if ( ! $all_valid ) {
		_doing_it_wrong( __FUNCTION__, esc_html( Api::TAG_FORMAT_MESSAGE ), '1.0.0' );
	}
	if ( array() === $valid ) {
		return false;
	}
	return $api->collector()->add_tags( $valid );
}
