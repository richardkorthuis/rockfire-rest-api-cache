<?php
/**
 * Decides which post types, taxonomies and posts matter for cache invalidation.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Invalidation;

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Shared relevance rules for the invalidation listeners.
 *
 * @since 1.0.0
 */
final class Relevance {

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Memoised union of the `flush_post_types` of all rules.
	 *
	 * @var array<string,true>|null
	 */
	private ?array $rule_post_types = null;

	/**
	 * Memoised union of the `flush_taxonomies` of all rules.
	 *
	 * @var array<string,true>|null
	 */
	private ?array $rule_taxonomies = null;

	/**
	 * Constructor.
	 *
	 * @param Config $config Configuration.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Whether changes to a post type invalidate caches.
	 *
	 * The type must exist and be exposed in REST or referenced by a rule's `flush_post_types`;
	 * `revision` is never relevant. The result passes through {@see Hooks::INVALIDATE_POST_TYPE}.
	 *
	 * @param string $post_type Post type slug.
	 *
	 * @return bool
	 */
	public function post_type( string $post_type ): bool {
		if ( 'revision' === $post_type ) {
			return false;
		}
		$object   = get_post_type_object( $post_type );
		$relevant = null !== $object && ( ! empty( $object->show_in_rest ) || isset( $this->rule_post_types()[ $post_type ] ) );
		/**
		 * Filters whether changes to a post type invalidate caches.
		 *
		 * @see Hooks::INVALIDATE_POST_TYPE
		 */
		return (bool) apply_filters( Hooks::INVALIDATE_POST_TYPE, $relevant, $post_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
	}

	/**
	 * Whether changes to a taxonomy invalidate caches.
	 *
	 * The taxonomy must exist and be exposed in REST or referenced by a rule's `flush_taxonomies`.
	 * The result passes through {@see Hooks::INVALIDATE_TAXONOMY}.
	 *
	 * A taxonomy that is not exposed in REST reads the rule union, so the first call of a request may build the
	 * REST server (see {@see self::load_rule_unions()}). A content save in wp-admin, such as a WooCommerce product
	 * save through its `product_type` and `product_visibility` terms, reaches it.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return bool
	 */
	public function taxonomy( string $taxonomy ): bool {
		$object   = get_taxonomy( $taxonomy );
		$relevant = false !== $object && ( ! empty( $object->show_in_rest ) || isset( $this->rule_taxonomies()[ $taxonomy ] ) );
		/**
		 * Filters whether changes to a taxonomy invalidate caches.
		 *
		 * @see Hooks::INVALIDATE_TAXONOMY
		 */
		return (bool) apply_filters( Hooks::INVALIDATE_TAXONOMY, $relevant, $taxonomy ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
	}

	/**
	 * Whether a post is publicly visible (attachments always are).
	 *
	 * @param \WP_Post $post Post.
	 *
	 * @return bool
	 */
	public function post_is_public( \WP_Post $post ): bool {
		if ( 'attachment' === $post->post_type ) {
			return true;
		}
		return is_post_status_viewable( $post->post_status );
	}

	/**
	 * Union of the `flush_post_types` of all rules.
	 *
	 * @return array<string,true>
	 */
	private function rule_post_types(): array {
		if ( null === $this->rule_post_types ) {
			$this->load_rule_unions();
		}
		return $this->rule_post_types ?? array();
	}

	/**
	 * Union of the `flush_taxonomies` of all rules.
	 *
	 * The first read of a request loads both unions, which may build the REST server; see {@see self::load_rule_unions()}.
	 *
	 * @return array<string,true>
	 */
	private function rule_taxonomies(): array {
		if ( null === $this->rule_taxonomies ) {
			$this->load_rule_unions();
		}
		return $this->rule_taxonomies ?? array();
	}

	/**
	 * Read both rule unions with one pass over the rules.
	 *
	 * The first post or term event of a request that needs a union reads {@see Config::route_rules()}. That builds
	 * the REST server through {@see \Rockfire\Rest_Api_Cache\Config\Route_Discovery::server()}, which fires
	 * {@see Hooks::DISCOVERY_SERVER} and then, when this request has no REST server yet, builds one and runs
	 * `rest_api_init`. In wp-admin a product save, through its `product_type` and `product_visibility` terms,
	 * therefore builds a REST server and, when the route snapshot is missing or stale, may recompile it from that
	 * server (unless {@see Hooks::PARTIAL_SERVER} marks the server as partial). The unions are memoised, so later
	 * events of the request do not read the rules again.
	 *
	 * @return void
	 */
	private function load_rule_unions(): void {
		$post_types = array();
		$taxonomies = array();
		foreach ( $this->config->route_rules() as $effective ) {
			foreach ( $effective->rule->flush_post_types as $name ) {
				$post_types[ $name ] = true;
			}
			foreach ( $effective->rule->flush_taxonomies as $name ) {
				$taxonomies[ $name ] = true;
			}
		}
		$this->rule_post_types = $post_types;
		$this->rule_taxonomies = $taxonomies;
	}
}
