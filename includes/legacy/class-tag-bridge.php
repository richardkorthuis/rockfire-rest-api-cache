<?php
/**
 * The bridge of the tagging hooks of the original plugin.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Rest_Tag_Collector;
use Rockfire\Rest_Api_Cache\Invalidation\Tags;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

defined( 'ABSPATH' ) || exit;

/**
 * Tags the response of the main request with what the callbacks of the original's cache relation hooks name.
 *
 * It runs on `rest_request_after_callbacks`, inside the main dispatch, so a tag added by a callback of
 * `wp_rest_cache/process_cache_relations` (for example with `rfrac_tag_response()`) is part of the entry: tags added
 * after `rfrac/collected_tags` are lost. The response data is handed to the callbacks in the shape of the original,
 * `[ 'data' => ..., 'headers' => [] ]`, with the data decoded from its JSON form. There is no cache row yet, so the
 * cache ID the callbacks receive is 0. A type from `wp_rest_cache/determine_object_type` adds the list tag of the
 * type and the tag of the item of a single response or of every item of a list.
 *
 * `rfrac/collected_tags` then reports every item tag of the entry (`post:`, `term:`, `user:` and `comment:`) to the
 * callbacks of `wp_rest_cache/insert_cache_relation` and returns the tags unchanged.
 *
 * Only a request whose lookup decision was cacheable is bridged, which already holds the credential checks, and only
 * the main request: its method and its route equal those of the lookup (`GET`, or the opted-in `POST`, `PUT` or
 * `PATCH`), and its response is a 2xx REST response. The data
 * a route callback returns without a response object counts as such a response: core wraps it in a 200 response
 * right after the filter. Anything the bridge itself cannot do marks the collection incomplete, so the response is
 * never stored without its tags. That includes a callback of `wp_rest_cache/determine_object_type`,
 * `wp_rest_cache/is_single_item` or `wp_rest_cache/process_cache_relations` that throws: {@see Bridge} records it
 * under the name of its hook, and the collection is marked incomplete as well, because the tags the callback would
 * have named are unknown and a later edit of the object it relates to must not leave the entry served.
 *
 * While no callback is hooked to the legacy hooks it asks, it does nothing, so a site without legacy code pays
 * nothing on a miss.
 *
 * @since 1.0.0
 */
final class Tag_Bridge {

	use Hook_Subscriber_Trait;

	/**
	 * The legacy hooks that are asked while a response is dispatched.
	 *
	 * @var list<string>
	 */
	private const DISPATCH_HOOKS = array(
		'wp_rest_cache/determine_object_type',
		'wp_rest_cache/is_single_item',
		'wp_rest_cache/process_cache_relations',
	);

	/**
	 * The tag collector.
	 *
	 * @var Rest_Tag_Collector
	 */
	private Rest_Tag_Collector $collector;

	/**
	 * Create the bridge.
	 *
	 * @param Rest_Tag_Collector $collector The tag collector the tags are added to.
	 */
	public function __construct( Rest_Tag_Collector $collector ) {
		$this->collector = $collector;
	}

	/**
	 * The hooks: `rest_request_after_callbacks` (10, 3) and the collected tags (10, 3).
	 *
	 * @return array<int,array<int,mixed>>
	 *
	 * @phpstan-return list<array{0:string,1:'after_callbacks'|'collected_tags',2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		return array(
			array( 'rest_request_after_callbacks', 'after_callbacks', 10, 3 ),
			array( Hooks::COLLECTED_TAGS, 'collected_tags', 10, 3 ),
		);
	}

	/**
	 * Filter `rest_request_after_callbacks`: tag the response of the main request with the legacy object type and relations.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $response Response: a REST response, or what the callback of a route returned (core wraps it in a 200 response right after this filter).
	 * @param mixed $handler  Route handler (unused).
	 * @param mixed $request  Request.
	 *
	 * @return mixed The response, untouched.
	 */
	public function after_callbacks( mixed $response, mixed $handler = null, mixed $request = null ): mixed {
		unset( $handler );
		if ( ! $this->collector->is_dispatching() || ! self::is_hooked() ) {
			return $response;
		}

		$context = self::cacheable_context();
		if ( null === $context || ! $request instanceof \WP_REST_Request || $context->method !== $request->get_method() || $request->get_route() !== $context->route ) {
			return $response;
		}
		if ( $response instanceof \WP_REST_Response ) {
			if ( $response->get_status() < 200 || $response->get_status() >= 300 ) {
				return $response;
			}
			$data = $response->get_data();
		} elseif ( null === $response || is_wp_error( $response ) || $response instanceof \WP_HTTP_Response ) {
			return $response;
		} else {
			$data = $response;
		}

		try {
			$this->tag( $data, $context );
		} catch ( \Throwable $e ) {
			$this->collector->fail_collection( $e );
		}

		return $response;
	}

	/**
	 * Filter `rfrac/collected_tags`: report every item tag to the callbacks of `wp_rest_cache/insert_cache_relation`.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $tags    The collected tags.
	 * @param mixed $request Main request (unused).
	 * @param mixed $data    Response data (unused).
	 *
	 * @return mixed The tags, unchanged.
	 */
	public function collected_tags( mixed $tags, mixed $request = null, mixed $data = null ): mixed {
		unset( $request, $data );
		if ( false === has_action( 'wp_rest_cache/insert_cache_relation' ) || null === self::cacheable_context() || ! ( is_string( $tags ) || is_iterable( $tags ) ) ) {
			return $tags;
		}

		foreach ( Tags::normalize_many( $tags ) as $tag ) {
			if ( 1 === preg_match( '/^(post|term|user|comment):([1-9][0-9]{0,17})$/D', $tag, $match ) ) {
				Bridge::action( 'wp_rest_cache/insert_cache_relation', array( 0, (int) $match[2], $match[1] ) );
			}
		}

		return $tags;
	}

	/**
	 * Ask the legacy hooks for the object type and the relations of a response, and tag it.
	 *
	 * @param mixed           $payload The data of the response.
	 * @param Request_Context $context The request of the lookup.
	 *
	 * @return void
	 */
	private function tag( mixed $payload, Request_Context $context ): void {
		$json = wp_json_encode( $payload );
		if ( ! is_string( $json ) ) {
			return; // The capture refuses a response it cannot encode itself.
		}

		$body = json_decode( $json );
		$data = array(
			'data'    => $body,
			'headers' => array(),
		);
		$uri  = Request_Uri::of( $context, rest_get_url_prefix() );

		$type   = $this->ask_filter( 'wp_rest_cache/determine_object_type', array( 'unknown', '', $data, $uri ) );
		$single = $this->ask_filter( 'wp_rest_cache/is_single_item', array( null !== self::id_of( $body ), $data, $uri ) );
		$type   = is_string( $type ) ? $type : 'unknown';

		if ( 'unknown' !== $type ) {
			$tags   = array();
			$target = Object_Tags::list_target( $type );
			if ( null !== $target && 'tag' === $target[0] ) {
				$tags[] = $target[1];
			}
			foreach ( self::ids( $body, (bool) $single ) as $id ) {
				$item = Object_Tags::item_tag( $type, $id );
				if ( null !== $item ) {
					$tags[] = $item;
				}
			}
			if ( array() !== $tags ) {
				$this->collector->add_tags( $tags );
			}
		}

		$this->ask_action( 'wp_rest_cache/process_cache_relations', array( 0, $data, $type, $uri ) );
	}

	/**
	 * Run the callbacks of a legacy filter of the dispatch; the collection fails when one of them throws.
	 *
	 * @param string           $name The full name of the legacy hook.
	 * @param array<int,mixed> $args The arguments of the legacy hook; the first one is the value to filter.
	 *
	 * @phpstan-param non-empty-string $name
	 *
	 * @return mixed The filtered value; the first argument unchanged when a callback throws.
	 */
	private function ask_filter( string $name, array $args ): mixed {
		return $this->guarded( $name, static fn(): mixed => Bridge::filter( $name, $args ) );
	}

	/**
	 * Run the callbacks of a legacy action of the dispatch; the collection fails when one of them throws.
	 *
	 * @param string           $name The full name of the legacy hook.
	 * @param array<int,mixed> $args The arguments of the legacy hook.
	 *
	 * @phpstan-param non-empty-string $name
	 *
	 * @return void
	 */
	private function ask_action( string $name, array $args ): void {
		$this->guarded(
			$name,
			static function () use ( $name, $args ): mixed {
				Bridge::action( $name, $args );
				return null;
			}
		);
	}

	/**
	 * Run the callbacks of a legacy hook through {@see Bridge} and mark the collection incomplete when one throws.
	 *
	 * {@see Bridge} records a throwing callback under the name of its hook and swallows it, so the bridge cannot see
	 * the failure. A marker hooked behind every callback of the hook, for the duration of the run only, tells: it runs
	 * only when every callback before it returned. A hook without callbacks is run as it is: the marker would be the
	 * only callback, and {@see Bridge} would report a deprecated hook nobody uses.
	 *
	 * @param string   $name The full name of the legacy hook.
	 * @param \Closure $run  Runs the callbacks through {@see Bridge}.
	 *
	 * @phpstan-param non-empty-string $name
	 * @phpstan-param \Closure(): mixed $run
	 *
	 * @return mixed What the run returns.
	 */
	private function guarded( string $name, \Closure $run ): mixed {
		if ( false === has_filter( $name ) ) {
			return $run();
		}

		$completed = false;
		$marker    = static function ( mixed $value = null ) use ( &$completed ): mixed {
			$completed = true;
			return $value;
		};

		add_filter( $name, $marker, PHP_INT_MAX );
		try {
			$result = $run();
		} finally {
			remove_filter( $name, $marker, PHP_INT_MAX );
		}

		if ( ! $completed ) {
			$this->collector->fail_collection( new \RuntimeException( 'A callback of ' . $name . ' failed.' ) );
		}
		return $result;
	}

	/**
	 * The IDs of the objects of a response.
	 *
	 * @param mixed $body   The decoded response data.
	 * @param bool  $single Whether the response is one item (its own ID) or a list (the ID of every item).
	 *
	 * @return array<int,int|string>
	 *
	 * @phpstan-return list<int|string>
	 */
	private static function ids( mixed $body, bool $single ): array {
		if ( $single ) {
			$id = self::id_of( $body );
			return null === $id ? array() : array( $id );
		}

		$ids = array();
		if ( is_array( $body ) ) {
			foreach ( $body as $item ) {
				$id = self::id_of( $item );
				if ( null !== $id ) {
					$ids[] = $id;
				}
			}
		}
		return $ids;
	}

	/**
	 * The scalar ID of an object of the decoded response data.
	 *
	 * @param mixed $item A decoded JSON object.
	 *
	 * @return int|string|null Null when it is not an object or has no ID.
	 */
	private static function id_of( mixed $item ): int|string|null {
		if ( ! is_object( $item ) ) {
			return null;
		}

		$id = get_object_vars( $item )['id'] ?? null;
		if ( is_int( $id ) || ( is_string( $id ) && '' !== $id ) ) {
			return $id;
		}
		if ( is_float( $id ) && is_finite( $id ) && floor( $id ) === $id ) {
			return (int) $id;
		}
		return null;
	}

	/**
	 * Whether a callback is hooked to any of the legacy hooks asked while a response is dispatched.
	 *
	 * @return bool
	 */
	private static function is_hooked(): bool {
		foreach ( self::DISPATCH_HOOKS as $name ) {
			if ( false !== has_filter( $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The request of the lookup, when its decision was cacheable.
	 *
	 * @return Request_Context|null Null without a lookup, for a request that is not a REST request and for a decision
	 *                              that was a bypass (credentials included).
	 */
	private static function cacheable_context(): ?Request_Context {
		$context  = Request_State::context();
		$decision = Request_State::decision();
		if ( null === $context || null === $context->route || null === $decision || ! $decision->cacheable ) {
			return null;
		}
		return $context;
	}
}
