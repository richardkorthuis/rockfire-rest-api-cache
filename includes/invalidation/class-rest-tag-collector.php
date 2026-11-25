<?php
/**
 * Collects invalidation tags while a REST response is generated.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Invalidation;

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Deny_List;
use Rockfire\Rest_Api_Cache\Config\Effective_Rule;
use Rockfire\Rest_Api_Cache\Config\Route_Rule;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Serving\Refusal_Aware_Tag_Provider_Interface;
use Rockfire\Rest_Api_Cache\Serving\Request_State;
use Rockfire\Rest_Api_Cache\Serving\Rule_Aware_Tag_Provider_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Records `post:`, `term:`, `term-ref:`, `user:`, `comment:`, `list:` and `route:` tags during REST dispatch.
 *
 * Item tags come from the `rest_prepare_*` filters, which also run for embedded and nested requests
 * and before `_fields` is applied, so the item itself is tagged whatever `_fields` asks for. A prepared
 * term (its own route, a term list or an `_embed`ded term) gets `term:{id}`; the terms a post refers
 * to (term fields, `class_list`, `%category%` links) get `term-ref:{id}`, which a deletion of the term
 * or a change of its slug or parent flushes, but not a rename or recount. The filters never run a
 * query and return the response untouched. Tags accumulate across the
 * dispatches of one main request until {@see self::collect()} or {@see self::reset()}; the start of
 * the main dispatch ({@see self::on_main_dispatch()}) clears what earlier requests of the same
 * process collected.
 *
 * A failure inside one of the hook handlers marks the collection incomplete: {@see self::collect()}
 * then returns null, so a response is never stored without the tags that would invalidate it.
 * {@see self::refusal()} tells the two refusals apart (`error` for an incomplete collection,
 * `too_many_tags` for a tag limit overflow). Both are recorded with {@see Failure_Recorder} for the
 * route; an overflow is recorded as not clearable, since a later success elsewhere does not fix it.
 *
 * An external tag provider attaches through two public methods: {@see self::add_tags()} adds the tags
 * it knows, and {@see self::fail_collection()} marks the collection incomplete when it cannot
 * establish them, so the response is refused instead of stored without its tags. Both only act while
 * a main dispatch is in progress.
 *
 * @since 1.0.0
 */
final class Rest_Tag_Collector implements Rule_Aware_Tag_Provider_Interface, Refusal_Aware_Tag_Provider_Interface {

	/**
	 * Why the last collect() returned null; null when it returned tags.
	 *
	 * @var Bypass_Reason|null
	 */
	private ?Bypass_Reason $refusal = null;

	/**
	 * Default maximum number of tags per entry.
	 */
	private const DEFAULT_MAX_TAGS = 5000;

	/**
	 * Number of remembered matched routes.
	 */
	private const MAX_REMEMBERED_ROUTES = 20;

	/**
	 * Collected tags as a set.
	 *
	 * @var array<string,true>
	 */
	private array $tags = array();

	/**
	 * Number of distinct item tags (everything but `list:` and `route:` tags).
	 *
	 * @var int
	 */
	private int $item_tags = 0;

	/**
	 * Whether the tag limit was exceeded.
	 *
	 * @var bool
	 */
	private bool $overflow = false;

	/**
	 * Whether a failure inside a hook handler left the tags incomplete.
	 *
	 * @var bool
	 */
	private bool $incomplete = false;

	/**
	 * Whether a main REST dispatch is in progress (from {@see self::on_main_dispatch()} until {@see self::collect()} or {@see self::reset()}).
	 *
	 * @var bool
	 */
	private bool $dispatching = false;

	/**
	 * Tag limit of the current collection, resolved on first use.
	 *
	 * @var int|null
	 */
	private ?int $limit = null;

	/**
	 * Matched route pattern per request object id.
	 *
	 * @var array<int,string>
	 */
	private array $matched = array();

	/**
	 * Effective route rules, memoised until the next main dispatch.
	 *
	 * @var array<string,Effective_Rule>|null
	 */
	private ?array $rules = null;

	/**
	 * Key of the rule the cache policy applied, when the caller told us.
	 *
	 * @var string|null
	 */
	private ?string $rule_key = null;

	/**
	 * Constructor.
	 *
	 * @param Config        $config Settings (route rules).
	 * @param Route_Tag_Map $map    List tags per route.
	 * @param Oembed_Tagger $oembed oEmbed tagger.
	 * @param Shape_Parser  $parser Fallback parser of custom endpoint data.
	 */
	public function __construct(
		private readonly Config $config,
		private readonly Route_Tag_Map $map,
		private readonly Oembed_Tagger $oembed,
		private readonly Shape_Parser $parser
	) {
	}

	/**
	 * Register every hook of the collector.
	 *
	 * The container registers the same hooks through its own table, see `Plugin::register_tag_hooks()`.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rest_authentication_errors', array( $this, 'on_main_dispatch' ), 0, 1 );
		add_action( 'rest_api_init', array( $this, 'on_rest_api_init' ), PHP_INT_MAX );
		add_action( 'registered_post_type', array( $this, 'on_registered_post_type' ), 10, 1 );
		add_action( 'registered_taxonomy', array( $this, 'on_registered_taxonomy' ), 10, 1 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'on_before_callbacks' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'on_post_dispatch' ), PHP_INT_MAX, 3 );
	}

	/**
	 * Filter `rest_authentication_errors`: the main request starts being served.
	 *
	 * Core runs this filter once at the start of `WP_REST_Server::serve_request()`, never for nested
	 * or internal `rest_do_request()` calls. Tags that earlier requests of the same PHP process
	 * collected (a `rest_do_request()` during `init`, preloading) must not count for the main
	 * response, so the collected state, the memoised route rules and the route map are renewed here.
	 *
	 * @param mixed $result Authentication result (returned untouched).
	 *
	 * @return mixed
	 */
	public function on_main_dispatch( mixed $result = null ): mixed {
		$this->reset();
		$this->rules       = null;
		$this->dispatching = true;
		$this->map->flush();
		return $result;
	}

	/**
	 * Action `rest_api_init`: hook the prepare filters of every REST-enabled type.
	 *
	 * @return void
	 */
	public function on_rest_api_init(): void {
		foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $post_type ) {
			add_filter( 'rest_prepare_' . $post_type, array( $this, 'on_prepare_post' ), PHP_INT_MAX, 3 );
		}
		foreach ( get_taxonomies( array( 'show_in_rest' => true ) ) as $taxonomy ) {
			add_filter( 'rest_prepare_' . $taxonomy, array( $this, 'on_prepare_term' ), PHP_INT_MAX, 3 );
		}
		add_filter( 'rest_prepare_user', array( $this, 'on_prepare_user' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_prepare_comment', array( $this, 'on_prepare_comment' ), PHP_INT_MAX, 3 );
	}

	/**
	 * Action `registered_post_type`: hook a type registered after `rest_api_init`.
	 *
	 * @param mixed $post_type Post type name.
	 *
	 * @return void
	 */
	public function on_registered_post_type( mixed $post_type = null ): void {
		$this->map->flush();
		if ( ! is_string( $post_type ) || 0 === did_action( 'rest_api_init' ) ) {
			return;
		}
		$object = get_post_type_object( $post_type );
		if ( $object instanceof \WP_Post_Type && $object->show_in_rest ) {
			add_filter( 'rest_prepare_' . $post_type, array( $this, 'on_prepare_post' ), PHP_INT_MAX, 3 );
		}
	}

	/**
	 * Action `registered_taxonomy`: hook a taxonomy registered after `rest_api_init`.
	 *
	 * @param mixed $taxonomy Taxonomy name.
	 *
	 * @return void
	 */
	public function on_registered_taxonomy( mixed $taxonomy = null ): void {
		$this->map->flush();
		if ( ! is_string( $taxonomy ) || 0 === did_action( 'rest_api_init' ) ) {
			return;
		}
		$object = get_taxonomy( $taxonomy );
		if ( $object instanceof \WP_Taxonomy && $object->show_in_rest ) {
			add_filter( 'rest_prepare_' . $taxonomy, array( $this, 'on_prepare_term' ), PHP_INT_MAX, 3 );
		}
	}

	/**
	 * Filter `rest_prepare_{post_type}`: tag the post and the term references present in the response data.
	 *
	 * @param mixed $response Response.
	 * @param mixed $post     Post (other objects, e.g. templates, are ignored).
	 * @param mixed $request  Request (unused).
	 *
	 * @return mixed The response, untouched.
	 */
	public function on_prepare_post( mixed $response, mixed $post = null, mixed $request = null ): mixed {
		unset( $request );
		try {
			$this->tag_post( $response, $post );
		} catch ( \Throwable $e ) {
			$this->fail( $e );
		}
		return $response;
	}

	/**
	 * Tag a prepared post and the terms it refers to.
	 *
	 * A post response refers to its terms without showing their data: their IDs in the term fields, their slugs in
	 * `class_list` and (with `%category%` permalinks) in `link`. So the terms get `term-ref:{id}` rather than
	 * `term:{id}`: renaming or recounting a term (e.g. because another post joined it) leaves this response
	 * unchanged, while deleting it or changing its slug or parent flushes it (see Term_Events). The terms are taken
	 * from the term fields (REST taxonomies) and from the post's cached term relationships (REST and public taxonomies,
	 * as `class_list` and permalinks use public ones), so they are found whatever `_fields` asks
	 * for: any output that depends on the terms has loaded them into that cache. No query runs.
	 *
	 * @param mixed $response Response.
	 * @param mixed $post     Post.
	 *
	 * @return void
	 */
	private function tag_post( mixed $response, mixed $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$this->add( array( Tags::post( $post->ID ) ), true );
		if ( 'attachment' === $post->post_type && (int) $post->post_parent > 0 ) {
			// A media item is attached to its parent, which hides it once the parent stops being public.
			$this->add( array( Tags::post( (int) $post->post_parent ) ), true );
		}

		$data = $response instanceof \WP_HTTP_Response ? $response->get_data() : null;
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			// Term fields exist for REST taxonomies; `class_list` and `%taxonomy%` permalinks use public ones.
			if ( empty( $taxonomy->show_in_rest ) && empty( $taxonomy->public ) ) {
				continue;
			}
			$ids = null;
			if ( ! empty( $taxonomy->show_in_rest ) && is_array( $data ) ) {
				$key = is_string( $taxonomy->rest_base ) && '' !== $taxonomy->rest_base ? $taxonomy->rest_base : $taxonomy->name;
				$ids = $data[ $key ] ?? null;
			}
			if ( is_int( $ids ) ) {
				$ids = array( $ids ); // Nav menu items carry one scalar term ID in `menus`.
			}
			$ids = is_array( $ids ) ? $ids : array();

			$cached = wp_cache_get( $post->ID, $taxonomy->name . '_relationships' );
			foreach ( is_array( $cached ) ? $cached : array() as $term ) {
				if ( is_numeric( $term ) ) {
					$ids[] = (int) $term;
				} elseif ( is_object( $term ) && isset( $term->term_id ) ) {
					$ids[] = (int) $term->term_id;
				}
			}

			$tags = array();
			foreach ( $ids as $term_id ) {
				if ( is_int( $term_id ) && $term_id > 0 ) {
					$tags[] = Tags::term_ref( $term_id );
				}
			}
			$this->add( $tags, true );
		}
	}

	/**
	 * Filter `rest_prepare_{taxonomy}`: tag the term.
	 *
	 * @param mixed $response Response.
	 * @param mixed $term     Term.
	 * @param mixed $request  Request (unused).
	 *
	 * @return mixed The response, untouched.
	 */
	public function on_prepare_term( mixed $response, mixed $term = null, mixed $request = null ): mixed {
		unset( $request );
		try {
			if ( $term instanceof \WP_Term ) {
				$this->add( array( Tags::term( $term->term_id ) ), true );
			}
		} catch ( \Throwable $e ) {
			$this->fail( $e );
		}
		return $response;
	}

	/**
	 * Filter `rest_prepare_user`: tag the user.
	 *
	 * @param mixed $response Response.
	 * @param mixed $user     User.
	 * @param mixed $request  Request (unused).
	 *
	 * @return mixed The response, untouched.
	 */
	public function on_prepare_user( mixed $response, mixed $user = null, mixed $request = null ): mixed {
		unset( $request );
		try {
			if ( $user instanceof \WP_User ) {
				$this->add( array( Tags::user( $user->ID ) ), true );
			}
		} catch ( \Throwable $e ) {
			$this->fail( $e );
		}
		return $response;
	}

	/**
	 * Filter `rest_prepare_comment`: tag the comment.
	 *
	 * @param mixed $response Response.
	 * @param mixed $comment  Comment.
	 * @param mixed $request  Request (unused).
	 *
	 * @return mixed The response, untouched.
	 */
	public function on_prepare_comment( mixed $response, mixed $comment = null, mixed $request = null ): mixed {
		unset( $request );
		try {
			if ( $comment instanceof \WP_Comment ) {
				$this->add( array( Tags::comment( (int) $comment->comment_ID ) ), true );
				if ( (int) $comment->comment_post_ID > 0 ) {
					// A comment is hidden once its post stops being public.
					$this->add( array( Tags::post( (int) $comment->comment_post_ID ) ), true );
				}
			}
		} catch ( \Throwable $e ) {
			$this->fail( $e );
		}
		return $response;
	}

	/**
	 * Filter `rest_request_before_callbacks`: add the list and oEmbed tags of the route.
	 *
	 * @param mixed $response Response (unchanged).
	 * @param mixed $handler  Route handler (unused).
	 * @param mixed $request  Request.
	 *
	 * @return mixed The response, untouched.
	 */
	public function on_before_callbacks( mixed $response, mixed $handler = null, mixed $request = null ): mixed {
		unset( $handler );
		try {
			if ( $request instanceof \WP_REST_Request ) {
				$this->add( $this->map->list_tags_for( $request->get_route() ), false );
				$this->add( $this->oembed->tags_for( $request ), true );
			}
		} catch ( \Throwable $e ) {
			$this->fail( $e );
		}
		return $response;
	}

	/**
	 * Filter `rest_post_dispatch`: remember the route pattern that matched the request.
	 *
	 * @param mixed $result  Response.
	 * @param mixed $server  Server (unused).
	 * @param mixed $request Request.
	 *
	 * @return mixed The response, untouched.
	 */
	public function on_post_dispatch( mixed $result, mixed $server = null, mixed $request = null ): mixed {
		unset( $server );
		if ( $result instanceof \WP_REST_Response && $request instanceof \WP_REST_Request ) {
			$matched = $result->get_matched_route();
			if ( '' !== $matched ) {
				$this->matched[ spl_object_id( $request ) ] = $matched;
				if ( count( $this->matched ) > self::MAX_REMEMBERED_ROUTES ) {
					$this->matched = array_slice( $this->matched, -self::MAX_REMEMBERED_ROUTES, null, true );
				}
			}
		}
		return $result;
	}

	/**
	 * Add tags (normalised); used by `rfrac_tag_response()`.
	 *
	 * Tags other than `list:` and `route:` tags count as item tags. Tags can only be added while a main
	 * REST dispatch is in progress ({@see self::is_dispatching()}).
	 *
	 * @param string[] $tags Tags.
	 * @phpstan-param list<string> $tags
	 *
	 * @return bool True when the tags were added; false outside a main dispatch or when the tag limit was exceeded
	 *              (the response is then not stored).
	 */
	public function add_tags( array $tags ): bool {
		if ( ! $this->dispatching ) {
			return false;
		}
		$this->add( $tags, true );
		return ! $this->overflow && ! $this->incomplete;
	}

	/**
	 * Mark the collection incomplete: an external tag provider could not establish the tags of the response.
	 *
	 * {@see self::collect()} then returns null and {@see self::refusal()} is `error`, so the response of the
	 * current main dispatch is not stored without the tags that would invalidate it. The failure is
	 * recorded for the route. Like {@see self::add_tags()} it only acts while a main dispatch is in progress
	 * ({@see self::is_dispatching()}); the next dispatch starts complete again.
	 *
	 * @param \Throwable $error The failure.
	 *
	 * @return bool True when the collection was marked incomplete; false outside a main dispatch (nothing is recorded).
	 */
	public function fail_collection( \Throwable $error ): bool {
		if ( ! $this->dispatching ) {
			return false;
		}
		$this->fail( $error );
		return true;
	}

	/**
	 * Whether a main REST dispatch is in progress, i.e. tags added now end up with the response being built.
	 *
	 * @return bool
	 */
	public function is_dispatching(): bool {
		return $this->dispatching;
	}

	/**
	 * Number of distinct item tags collected so far (`post:`, `term:`, `term-ref:`, `user:`, `comment:` and custom tags).
	 *
	 * @return int
	 */
	public function item_tag_count(): int {
		return $this->item_tags;
	}

	/**
	 * Set the key of the route rule the cache policy applied to the response about to be collected.
	 *
	 * @param string|null $key Rule key.
	 *
	 * @return void
	 */
	public function use_rule_key( ?string $key ): void {
		$this->rule_key = $key;
	}

	/**
	 * Tags of the main request, draining the collected state.
	 *
	 * @param \WP_REST_Request $request The main request.
	 * @param mixed            $data    The response data.
	 *
	 * @return string[]|null Sorted unique tags; null when the tag limit was exceeded or a collector
	 *                       hook failed (the response must not be stored then).
	 * @phpstan-return list<string>|null
	 */
	public function collect( \WP_REST_Request $request, mixed $data ): ?array {
		$this->refusal = null;
		try {
			$this->add( array( Tags::route( $request->get_route() ) ), false );
			$this->add( $this->rule_tags( $request ), false );

			/**
			 * Filters whether the fallback parser looks for tags in the response data.
			 *
			 * @see Hooks::RUN_FALLBACK_PARSER
			 */
			if ( apply_filters( Hooks::RUN_FALLBACK_PARSER, 0 === $this->item_tags, $request, $data ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
				$this->add( $this->parser->parse( $data ), true );
			}

			/**
			 * Filters the collected tags.
			 *
			 * @see Hooks::COLLECTED_TAGS
			 */
			$filtered = apply_filters( Hooks::COLLECTED_TAGS, array_keys( $this->tags ), $request, $data ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
			$tags     = Tags::normalize_many( is_iterable( $filtered ) || is_string( $filtered ) ? $filtered : array_keys( $this->tags ) );

			if ( $this->incomplete ) {
				$this->refusal = Bypass_Reason::ERROR;
				return null; // The failure was recorded when it happened.
			}
			if ( $this->overflow || count( $tags ) > $this->limit() ) {
				$this->refusal = Bypass_Reason::TOO_MANY_TAGS;
				Failure_Recorder::record( 'tags', 'Tag limit of ' . $this->limit() . ' exceeded on ' . $request->get_route() . '; the response was not stored.', $request->get_route(), false );
				return null;
			}
			return $tags;
		} finally {
			$this->reset();
		}
	}

	/**
	 * Forget everything collected so far.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->dispatching = false;
		$this->tags        = array();
		$this->item_tags   = 0;
		$this->overflow    = false;
		$this->incomplete  = false;
		$this->limit       = null;
		$this->matched     = array();
		$this->rule_key    = null;
	}

	/**
	 * The effective route rules, resolved once per main request (route discovery is expensive and rules do not change mid-request).
	 *
	 * @return array<string,Effective_Rule>
	 */
	private function rules(): array {
		$this->rules ??= $this->config->route_rules();
		return $this->rules;
	}

	/**
	 * List tags of the route rule that applies to a request.
	 *
	 * The rule is the one the cache policy chose ({@see self::use_rule_key()}). Without that key it is
	 * looked up by the matched route pattern, then by the route path, then among the enabled wildcard
	 * rules the way the compiler matches them.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private function rule_tags( \WP_REST_Request $request ): array {
		$rules = $this->rules();
		$rule  = null !== $this->rule_key ? ( $rules[ $this->rule_key ] ?? null ) : null;
		if ( null === $rule ) {
			$matched = $this->matched[ spl_object_id( $request ) ] ?? null;
			$rule    = null !== $matched && isset( $rules[ $matched ] ) ? $rules[ $matched ] : ( $rules[ $request->get_route() ] ?? null );
		}
		$rule ??= $this->wildcard_rule( $rules, $request->get_route() );
		if ( null === $rule ) {
			return array();
		}

		$tags = array();
		foreach ( $rule->rule->flush_post_types as $name ) {
			$tags[] = Tags::list_for( $name );
		}
		foreach ( $rule->rule->flush_taxonomies as $name ) {
			$tags[] = Tags::list_for( $name );
		}
		return array_values( array_filter( $tags ) );
	}

	/**
	 * The first enabled wildcard rule that matches a route.
	 *
	 * @param array<string,Effective_Rule> $rules Effective rules.
	 * @param string                       $route Request route.
	 *
	 * @return Effective_Rule|null
	 */
	private function wildcard_rule( array $rules, string $route ): ?Effective_Rule {
		foreach ( $rules as $key => $effective ) {
			$rule = $effective->rule;
			if ( Route_Rule::MATCH_WILDCARD === $rule->match && Route_Rule::STATUS_ENABLED === $rule->status && 1 === preg_match( Deny_List::wildcard_regex( (string) $key ), $route ) ) {
				return $effective;
			}
		}
		return null;
	}

	/**
	 * Record a failure of a hook handler and mark the collection incomplete.
	 *
	 * @param \Throwable $e The failure.
	 *
	 * @return void
	 */
	private function fail( \Throwable $e ): void {
		$this->incomplete = true;
		Failure_Recorder::record( 'tags', $e, (string) Request_State::context()?->route );
	}

	/**
	 * Why the last collect() returned null: `error` (incomplete collection) or `too_many_tags`.
	 *
	 * @return Bypass_Reason|null Null when the last collect() returned tags.
	 */
	public function refusal(): ?Bypass_Reason {
		return $this->refusal;
	}

	/**
	 * Add tags to the set, honouring the tag limit.
	 *
	 * @param array<int,string|null> $tags Candidate tags.
	 * @param bool                   $item Whether the tags are item tags (`list:` and `route:` tags are never counted as items).
	 *
	 * @return void
	 */
	private function add( array $tags, bool $item ): void {
		if ( $this->overflow ) {
			return;
		}
		foreach ( Tags::normalize_many( array_filter( $tags ) ) as $tag ) {
			if ( isset( $this->tags[ $tag ] ) ) {
				continue;
			}
			if ( count( $this->tags ) >= $this->limit() ) {
				$this->overflow = true;
				return;
			}
			$this->tags[ $tag ] = true;
			if ( $item && ! str_starts_with( $tag, 'list:' ) && ! str_starts_with( $tag, 'route:' ) ) {
				++$this->item_tags;
			}
		}
	}

	/**
	 * The tag limit of this collection.
	 *
	 * @return int
	 */
	private function limit(): int {
		if ( null === $this->limit ) {
			$max         = apply_filters( Hooks::MAX_TAGS_PER_ENTRY, self::DEFAULT_MAX_TAGS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
			$this->limit = max( 1, is_numeric( $max ) ? (int) $max : self::DEFAULT_MAX_TAGS );
		}
		return $this->limit;
	}
}
