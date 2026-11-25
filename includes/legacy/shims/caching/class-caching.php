<?php
/**
 * Shim for the `Caching` class of the original plugin.
 *
 * Reproduces the public interface (class, constants, method signatures and their documentation) of WP REST Cache by Acato
 * (https://wordpress.org/plugins/wp-rest-cache/), GPLv3, so existing calls keep working. The implementation is new.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace WP_Rest_Cache_Plugin\Includes\Caching;

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Invalidation\Api;
use Rockfire\Rest_Api_Cache\Legacy\Object_Tags;
use Rockfire\Rest_Api_Cache\Legacy\Shim_Notice;
use Rockfire\Rest_Api_Cache\Request\Request_Context;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- The signatures are those of the original plugin.

/**
 * The public surface of the original `Caching` singleton (2026.3.1), so site code that calls it keeps working once
 * the original plugin is removed. It is loaded by the shim loader only while the original is neither active nor loaded.
 *
 * The flush methods map onto the public flush API (`rfrac_flush_tag()`, `rfrac_flush_url()`, `rfrac_flush_route()`,
 * `rfrac_flush_all()`) and raise a deprecation notice. Every storage, regeneration or event method is a neutral no-op
 * that raises a developer notice and writes only the legacy findings record, so the call shows as a finding on the
 * Import page and in Site Health; it touches nothing else. The class fires no hook of the original.
 *
 * @since 1.0.0
 */
class Caching {

	/**
	 * The version of the original's database tables.
	 *
	 * @var string
	 */
	const DB_VERSION = '2026.1.0';

	/**
	 * The name of the original's cache table, without the database prefix.
	 *
	 * @var string
	 */
	const TABLE_CACHES = 'wrc_caches';

	/**
	 * The name of the original's relations table, without the database prefix.
	 *
	 * @var string
	 */
	const TABLE_RELATIONS = 'wrc_relations';

	/**
	 * Match only the exact endpoint path with its query parameters.
	 *
	 * @var string
	 */
	const FLUSH_STRICT = 'strict';

	/**
	 * Match the endpoint path with any query parameters.
	 *
	 * @var string
	 */
	const FLUSH_PARAMS = 'params';

	/**
	 * Match the endpoint path and everything below it.
	 *
	 * @var string
	 */
	const FLUSH_LOOSE = 'loose';

	/**
	 * The singleton instance.
	 *
	 * @var Caching|null
	 */
	private static $instance = null;

	/**
	 * Class constructor.
	 */
	private function __construct() {
	}

	/**
	 * Get the singleton instance of this class.
	 *
	 * @return Caching
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new Caching();
		}

		return self::$instance;
	}

	/**
	 * Get a cached item.
	 *
	 * @param string $cache_key The cache key for the requested cache.
	 *
	 * @return false Always false: there is no cache item to read.
	 */
	public function get_cache( $cache_key ) {
		$this->unsupported( __FUNCTION__, 'Rockfire – REST API Cache does not expose its cached responses through this method.' );

		return false;
	}

	/**
	 * Save a cache item.
	 *
	 * @param string              $cache_key       The cache key.
	 * @param mixed               $value           The value to cache.
	 * @param string              $type            The cache type.
	 * @param string              $uri             The request URI.
	 * @param string              $object_type     The object type.
	 * @param array<string,mixed> $request_headers The request headers.
	 * @param string              $request_method  The request method.
	 *
	 * @return void
	 */
	public function set_cache( $cache_key, $value, $type, $uri = '', $object_type = '', $request_headers = array(), $request_method = 'GET' ) {
		$this->unsupported( __FUNCTION__, 'Rockfire – REST API Cache stores a response itself while it is served; use rfrac_tag_response() to tag one.' );
	}

	/**
	 * Delete a cache item.
	 *
	 * @param string $cache_key The cache key.
	 * @param bool   $force     Whether to delete statistics as well.
	 *
	 * @return void
	 */
	public function delete_cache( $cache_key, $force = false ) {
		$this->unsupported( __FUNCTION__, 'Rockfire – REST API Cache flushes entries by tag, URL or route; use rfrac_flush_tag(), rfrac_flush_url() or rfrac_flush_route().' );
	}

	/**
	 * Delete caches by endpoint path.
	 *
	 * An absolute URL or a path is accepted. A path of the REST API (`/wp-json/...` or `/?rest_route=...`) is resolved
	 * below the home path of the current site. `FLUSH_STRICT` flushes the entries of that URL, `FLUSH_PARAMS` and
	 * `FLUSH_LOOSE` the entries of its route and everything below it. The `$force` argument is ignored.
	 *
	 * @param string|mixed $endpoint   The endpoint path to match.
	 * @param string       $strictness The match type: `Caching::FLUSH_STRICT`, `Caching::FLUSH_PARAMS` or `Caching::FLUSH_LOOSE`.
	 * @param bool         $force      Ignored: flushed entries are pruned by the maintenance task.
	 *
	 * @return bool|\WP_Error True when entries were flushed, a `WP_Error` for an unknown strictness.
	 */
	public function delete_cache_by_endpoint( $endpoint, $strictness = self::FLUSH_STRICT, $force = false ) {
		$endpoint = is_string( $endpoint ) ? trim( $endpoint ) : '';
		if ( '' === $endpoint ) {
			return false;
		}

		if ( self::FLUSH_STRICT !== $strictness && self::FLUSH_PARAMS !== $strictness && self::FLUSH_LOOSE !== $strictness ) {
			return new \WP_Error( 'wp_rest_cache_invalid_strictness', __( 'Invalid strictness.', 'rockfire-rest-api-cache' ) );
		}

		$url   = $this->endpoint_url( $endpoint );
		$route = Request_Context::from_url( $url, rest_get_url_prefix() )->route;
		if ( null === $route ) {
			return false;
		}

		if ( self::FLUSH_STRICT === $strictness ) {
			$this->deprecated( __FUNCTION__, 'rfrac_flush_url()' );
			return rfrac_flush_url( $url ) > 0;
		}

		$this->deprecated( __FUNCTION__, 'rfrac_flush_route()' );
		return rfrac_flush_route( $route ) > 0;
	}

	/**
	 * Clear all saved caches.
	 *
	 * @param bool $force Ignored: flushed entries are pruned by the maintenance task.
	 *
	 * @return bool True if there were caches to flush.
	 */
	public function clear_caches( $force = false ) {
		$this->deprecated( __FUNCTION__, 'rfrac_flush_all()' );

		return rfrac_flush_all() > 0;
	}

	/**
	 * Fired upon the `save_post` hook.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an existing post being updated or not.
	 *
	 * @return void
	 */
	public function save_post( $post_id, $post, $update ) {
		$this->event( __FUNCTION__ );
	}

	/**
	 * Fired upon the `delete_post` hook.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public function delete_post( $post_id ) {
		$this->event( __FUNCTION__ );
	}

	/**
	 * Fired upon the `transition_post_status` hook.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 *
	 * @return void
	 */
	public function transition_post_status( $new_status, $old_status, $post ) {
		$this->event( __FUNCTION__ );
	}

	/**
	 * Fired upon the `updated_post_meta` hook.
	 *
	 * @param int    $meta_id     ID of the updated metadata entry.
	 * @param int    $object_id   Post ID.
	 * @param string $meta_key    Metadata key.
	 * @param mixed  $_meta_value Metadata value.
	 *
	 * @return void
	 */
	public function updated_post_meta( $meta_id, $object_id, $meta_key, $_meta_value ) {
		$this->event( __FUNCTION__ );
	}

	/**
	 * Fired upon the `add_attachment` hook.
	 *
	 * @return void
	 */
	public function add_attachment() {
		$this->event( __FUNCTION__ );
	}

	/**
	 * Fired upon the `edit_attachment` hook.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return void
	 */
	public function edit_attachment( $attachment_id ) {
		$this->event( __FUNCTION__ );
	}

	/**
	 * Fired upon the `created_term` hook.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return void
	 */
	public function created_term( $term_id, $tt_id, $taxonomy ) {
		$this->event( __FUNCTION__ );
	}

	/**
	 * Fired upon the `edited_term` hook.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return void
	 */
	public function edited_term( $term_id, $tt_id, $taxonomy ) {
		$this->event( __FUNCTION__ );
	}

	/**
	 * Fired upon the `delete_term` hook.
	 *
	 * @param int    $term     Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return void
	 */
	public function delete_term( $term, $tt_id, $taxonomy ) {
		$this->event( __FUNCTION__ );
	}

	/**
	 * Fired upon the `updated_term_meta` hook.
	 *
	 * @param int    $meta_id     ID of the updated metadata entry.
	 * @param int    $object_id   Term ID.
	 * @param string $meta_key    Metadata key.
	 * @param mixed  $_meta_value Metadata value.
	 *
	 * @return void
	 */
	public function updated_term_meta( $meta_id, $object_id, $meta_key, $_meta_value ) {
		$this->event( __FUNCTION__ );
	}

	/**
	 * Fired upon the `set_object_terms` hook.
	 *
	 * @param int              $object_id  Object ID.
	 * @param array<int,mixed> $terms      An array of object term IDs or slugs.
	 * @param array<int,int>   $tt_ids     An array of term taxonomy IDs.
	 * @param string           $taxonomy   Taxonomy slug.
	 * @param bool             $append     Whether to append new terms to the old terms.
	 * @param array<int,int>   $old_tt_ids Old array of term taxonomy IDs.
	 *
	 * @return void
	 */
	public function set_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		$this->event( __FUNCTION__ );
	}

	/**
	 * Fired upon the `profile_update` hook.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return void
	 */
	public function profile_update( $user_id ) {
		$this->event( __FUNCTION__ );
	}

	/**
	 * Fired upon the `user_register` hook.
	 *
	 * @return void
	 */
	public function user_register() {
		$this->event( __FUNCTION__ );
	}

	/**
	 * Fired upon the `deleted_user` hook.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return void
	 */
	public function deleted_user( $user_id ) {
		$this->event( __FUNCTION__ );
	}

	/**
	 * Fired upon the `updated_user_meta` hook.
	 *
	 * @param int    $meta_id     ID of the updated metadata entry.
	 * @param int    $object_id   User ID.
	 * @param string $meta_key    Metadata key.
	 * @param mixed  $_meta_value Metadata value.
	 *
	 * @return void
	 */
	public function updated_user_meta( $meta_id, $object_id, $meta_key, $_meta_value ) {
		$this->event( __FUNCTION__ );
	}

	/**
	 * Fired upon the comment hooks.
	 *
	 * @param int $comment_id Comment ID.
	 *
	 * @return void
	 */
	public function delete_comment_related_caches( $comment_id ) {
		$this->event( __FUNCTION__ );
	}

	/**
	 * Fired upon the comment type hooks.
	 *
	 * @return void
	 */
	public function delete_comment_type_related_caches() {
		$this->event( __FUNCTION__ );
	}

	/**
	 * Fired upon the `updated_comment_meta` hook.
	 *
	 * @param int    $meta_id     ID of the updated metadata entry.
	 * @param int    $object_id   Comment ID.
	 * @param string $meta_key    Metadata key.
	 * @param mixed  $_meta_value Metadata value.
	 *
	 * @return void
	 */
	public function updated_comment_meta( $meta_id, $object_id, $meta_key, $_meta_value ) {
		$this->event( __FUNCTION__ );
	}

	/**
	 * Delete all caches.
	 *
	 * @param bool         $delete       Ignored: flushed entries are pruned by the maintenance task.
	 * @param string|false $cache_filter Optional filter identifier; a filtered flush is not supported.
	 *
	 * @return int The number of flushed caches; 0 for a filtered flush.
	 */
	public function delete_all_caches( $delete, $cache_filter = false ) {
		if ( false !== $cache_filter ) {
			$this->unsupported( __FUNCTION__, 'Rockfire – REST API Cache cannot flush a filtered set of caches; flush by tag, URL or route instead.' );

			return 0;
		}

		$this->deprecated( __FUNCTION__, 'rfrac_flush_all()' );

		return rfrac_flush_all();
	}

	/**
	 * Delete all related caches for an object ID and object type.
	 *
	 * @param int|string $id                  The ID of the object.
	 * @param string     $object_type         The type of the object.
	 * @param bool       $force_single_delete Ignored: flushed entries are pruned by the maintenance task.
	 * @param bool       $delete              Ignored: flushed entries are pruned by the maintenance task.
	 *
	 * @return int The number of flushed caches; 0 when the object type and ID name no cache tag.
	 */
	public function delete_related_caches( $id, $object_type, $force_single_delete = false, $delete = false ) {
		$tag = $this->item_tag( $object_type, $id );
		if ( null === $tag ) {
			$this->unsupported( __FUNCTION__, 'The object type and ID name no cache tag, so no cache can be flushed for them.' );

			return 0;
		}

		$this->deprecated( __FUNCTION__, 'rfrac_flush_tag()' );

		return rfrac_flush_tag( $tag );
	}

	/**
	 * Delete all non-single caches for an object type.
	 *
	 * @param string|mixed $object_type The type of the object.
	 * @param bool         $delete      Ignored: flushed entries are pruned by the maintenance task.
	 *
	 * @return int The number of flushed caches; 0 when the object type names no list.
	 */
	public function delete_object_type_caches( $object_type, $delete = false ) {
		$target = is_string( $object_type ) ? Object_Tags::list_target( $object_type ) : null;
		if ( null === $target ) {
			$this->unsupported( __FUNCTION__, 'The object type names no list of cached responses, so no cache can be flushed for it.' );

			return 0;
		}

		if ( 'route' === $target[0] ) {
			$this->deprecated( __FUNCTION__, 'rfrac_flush_route()' );

			return rfrac_flush_route( $target[1] );
		}

		$this->deprecated( __FUNCTION__, 'rfrac_flush_tag()' );

		return rfrac_flush_tag( $target[1] );
	}

	/**
	 * Register a relation between the response being built and an object.
	 *
	 * Only while a REST request is being dispatched: the response then gets the tag of the object.
	 *
	 * @param int              $cache_id    Ignored: the response being built is the one that is tagged.
	 * @param int|string|mixed $object_id   The ID of the related object.
	 * @param string|mixed     $object_type The object type of the relation.
	 *
	 * @return void
	 */
	public function insert_cache_relation( $cache_id, $object_id, $object_type ) {
		// An OPTIONS call recurses with arrays; the original ignored them silently.
		if ( is_array( $object_id ) || is_array( $object_type ) ) {
			return;
		}

		$api = Api::get();
		if ( null === $api || ! $api->collector()->is_dispatching() ) {
			$this->unsupported( __FUNCTION__, 'A response can only be tagged while a REST request is being dispatched; call rfrac_tag_response() from the route callback.' );

			return;
		}

		$tag = $this->item_tag( $object_type, $object_id );
		if ( null === $tag ) {
			$this->unsupported( __FUNCTION__, 'The object type and ID name no cache tag, so the response is not tagged.' );

			return;
		}

		$this->deprecated( __FUNCTION__, 'rfrac_tag_response()' );
		rfrac_tag_response( $tag );
	}

	/**
	 * Get a page of cache records for the admin.
	 *
	 * @param string $api_type    The API type.
	 * @param int    $per_page    Records per page.
	 * @param int    $page_number The page number.
	 *
	 * @return array<int,array<string,mixed>> Always empty.
	 */
	public function get_api_data( $api_type, $per_page, $page_number ) {
		$this->unsupported( __FUNCTION__, 'Rockfire – REST API Cache keeps no list of cache records for this method; see the Caches screen or WP-CLI.' );

		return array();
	}

	/**
	 * Get the number of cache records.
	 *
	 * @param string $api_type The API type.
	 *
	 * @return int Always 0.
	 */
	public function get_record_count( $api_type ) {
		$this->unsupported( __FUNCTION__, 'Rockfire – REST API Cache keeps no list of cache records for this method; see the Caches screen or WP-CLI.' );

		return 0;
	}

	/**
	 * Get the data of one cache record.
	 *
	 * @param string $cache_key The cache key.
	 *
	 * @return null Always null: there is no cache record to read.
	 */
	public function get_cache_data( $cache_key ) {
		$this->unsupported( __FUNCTION__, 'Rockfire – REST API Cache does not expose its cached responses through this method.' );

		return null;
	}

	/**
	 * Get the cache timeout.
	 *
	 * @param bool                $calculated Ignored.
	 * @param array<string,mixed> $options    Ignored.
	 *
	 * @return int The default TTL of the plugin settings, in seconds.
	 */
	public function get_timeout( $calculated = true, $options = array() ) {
		$this->deprecated( __FUNCTION__, 'the default_ttl setting of the plugin' );

		$ttl = Config::instance()->settings()['default_ttl'] ?? 0;

		return is_numeric( $ttl ) ? (int) $ttl : 0;
	}

	/**
	 * Get the cache timeout interval.
	 *
	 * @return int Always 1: the timeout is already in seconds.
	 */
	public function get_timeout_interval() {
		$this->deprecated( __FUNCTION__, 'the default_ttl setting of the plugin' );

		return 1;
	}

	/**
	 * Should a cron be activated to regenerate the expired caches?
	 *
	 * @return bool Always false.
	 */
	public function should_regenerate() {
		$this->unsupported( __FUNCTION__, 'Rockfire – REST API Cache does not regenerate expired caches.' );

		return false;
	}

	/**
	 * Get the regeneration interval.
	 *
	 * @return string The default interval key.
	 */
	public function get_regenerate_interval() {
		$this->unsupported( __FUNCTION__, 'Rockfire – REST API Cache does not regenerate expired caches.' );

		return 'twicedaily';
	}

	/**
	 * Get the maximum number of regenerated caches per cron job.
	 *
	 * @return int The default maximum.
	 */
	public function get_regenerate_number() {
		$this->unsupported( __FUNCTION__, 'Rockfire – REST API Cache does not regenerate expired caches.' );

		return 10;
	}

	/**
	 * Cron job to regenerate the expired caches.
	 *
	 * @return void
	 */
	public function regenerate_expired_caches() {
		$this->unsupported( __FUNCTION__, 'Rockfire – REST API Cache does not regenerate expired caches.' );
	}

	/**
	 * Get whether Memcache(d) is being used as external object cache.
	 *
	 * @return bool Always false.
	 */
	public function get_memcache_used() {
		$this->unsupported( __FUNCTION__, 'Rockfire – REST API Cache has no setting for the object cache of the original.' );

		return false;
	}

	/**
	 * Get a list of global cacheable request headers, the list is comma separated.
	 *
	 * @return string Always empty.
	 */
	public function get_global_cacheable_request_headers() {
		$this->unsupported( __FUNCTION__, 'Rockfire – REST API Cache varies a cached response by the vary headers of its route rule.' );

		return '';
	}

	/**
	 * Get the cache key for the current ID.
	 *
	 * @param string|int $id The ID used for the cache key.
	 *
	 * @return string The key the original used for its transient.
	 */
	public function transient_key( $id ) {
		$this->unsupported( __FUNCTION__, 'Rockfire – REST API Cache does not store responses in transients.' );

		return 'wp_rest_cache_' . $id;
	}

	/**
	 * Delete flushed or deleted caches from the transients API.
	 *
	 * @return void
	 */
	public function cleanup_deleted_caches() {
		$this->unsupported( __FUNCTION__, 'Rockfire – REST API Cache prunes flushed entries in its own maintenance task.' );
	}

	/**
	 * Update the database tables of the original.
	 *
	 * @return void
	 */
	public function update_database_structure() {
		$this->unsupported( __FUNCTION__, 'Rockfire – REST API Cache manages its own tables.' );
	}

	/**
	 * Report a call that is a plain event callback of the original.
	 *
	 * @param string $method The called method.
	 *
	 * @return void
	 */
	private function event( $method ) {
		$this->unsupported( $method, 'Rockfire – REST API Cache flushes on that event itself.' );
	}

	/**
	 * Report a call that works but is replaced by a public function of the plugin.
	 *
	 * @param string $method      The called method.
	 * @param string $replacement The replacement.
	 *
	 * @return void
	 */
	private function deprecated( $method, $replacement ) {
		Shim_Notice::deprecated( 'Caching::' . $method, $replacement );
	}

	/**
	 * Report a call the shim cannot honour.
	 *
	 * @param string $method The called method.
	 * @param string $reason Why the call has no effect.
	 *
	 * @return void
	 */
	private function unsupported( $method, $reason ) {
		Shim_Notice::unsupported( 'Caching::' . $method, $reason );
	}

	/**
	 * The tag of an object, null when the pair names no valid tag.
	 *
	 * @param mixed $object_type The object type.
	 * @param mixed $id          The object ID.
	 *
	 * @return string|null
	 */
	private function item_tag( $object_type, $id ) {
		if ( ! is_string( $object_type ) || ! ( is_int( $id ) || is_string( $id ) ) ) {
			return null;
		}

		return Object_Tags::item_tag( $object_type, $id );
	}

	/**
	 * The URL to flush for an endpoint of the original.
	 *
	 * A bare path gets its leading slash. A host-less path of the REST API (the REST prefix or a `rest_route` query)
	 * gets the home path of the current site in front, unless it is below it already, so a subdirectory site flushes its
	 * own entries.
	 *
	 * @param string $endpoint The trimmed, non-empty endpoint.
	 *
	 * @return string
	 */
	private function endpoint_url( $endpoint ) {
		if ( 1 === preg_match( '#^([a-z][a-z0-9+.-]*:)?//#i', $endpoint ) ) {
			return $endpoint;
		}

		if ( '/' !== $endpoint[0] ) {
			$endpoint = '/' . $endpoint;
		}

		$home_path = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( '' === $home_path ) {
			return $endpoint;
		}

		$path = (string) wp_parse_url( $endpoint, PHP_URL_PATH );
		if ( $path === $home_path || str_starts_with( $path, $home_path . '/' ) ) {
			return $endpoint;
		}

		$prefix = '/' . trim( rest_get_url_prefix(), '/' );
		$query  = array();
		parse_str( (string) wp_parse_url( $endpoint, PHP_URL_QUERY ), $query );
		if ( $path === $prefix || str_starts_with( $path, $prefix . '/' ) || isset( $query['rest_route'] ) ) {
			return $home_path . $endpoint;
		}

		return $endpoint;
	}
}
