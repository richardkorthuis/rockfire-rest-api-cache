<?php
/**
 * Registry of every public `rfrac/...` hook.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Single documented list of the public action and filter names of the plugin.
 *
 * Every new `rfrac/...` hook adds a constant here; tests/test-hooks-reference.php enforces it.
 * Dynamic segments are written as `{name}` in the value.
 *
 * @since 1.0.0
 */
final class Hooks {

	/**
	 * Action: fires after cache entries were flushed.
	 *
	 * Fired once per store call, so a flush of several chunks fires it several times: for `tags` once per chunk of up to 1,000 tags (per site on a buffered commit).
	 * `entries` fires only when at least one entry was flushed; the other types fire after every call, also with 0 affected.
	 * An exception thrown by a listener is reported through `rfrac/invalidation_failed` and does not stop the flush.
	 *
	 * @since 1.0.0
	 *
	 * @param string       $type     What was flushed: `tags`, `url`, `route`, `all` or `entries` (by entry ID, from the admin or WP-CLI).
	 * @param list<string> $targets  The tags of the chunk, the URL or the route; empty for `all`; the entry IDs, as strings, for `entries`.
	 * @param int          $affected Number of cache entries flushed.
	 */
	public const FLUSHED = 'rfrac/flushed';

	/**
	 * Action: fires after a response was stored in the cache.
	 *
	 * @since 1.0.0
	 *
	 * @param string       $cache_key Cache key of the stored entry.
	 * @param string       $route     Request route the entry belongs to.
	 * @param list<string> $tags      Tags attached to the entry.
	 * @param int          $size      Stored body size in bytes.
	 */
	public const STORED = 'rfrac/stored';

	/**
	 * Filter: the tags that a change invalidates.
	 *
	 * Applied to every queued change. The result is normalised again: invalid tags are dropped and a non-iterable
	 * result queues nothing.
	 *
	 * Most events are filtered when the change happens. `object_terms_set` is deferred: it is filtered when the
	 * invalidator commits (at `rest_pre_echo_response` or `shutdown`, or earlier once
	 * `rfrac/auto_commit_threshold` pending tags and deferred changes pile up), once per object and taxonomy
	 * with the net change since before the object's first change in the request (or since the previous commit): the
	 * changed terms (`term_ids`, empty when the changes cancelled out) plus the object's own tags. Each recount still
	 * fires its own `term_counted`.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string>        $tags    Normalised tags to invalidate. Default: the tags derived from the change.
	 * @param string              $event   What changed: one of the `Invalidation\Invalidator::EVENT_*` constants
	 *                                     (e.g. `post_saved`, `term_counted`, `user_updated`, `option_updated`) or a custom name.
	 * @param array<string,mixed> $context The subject of the change: `post_id`/`post_type`, `term_id`/`taxonomy`
	 *                                     (`term_ids` for several), `user_id`, `comment_id`, `option`, or
	 *                                     `meta_type`/`meta_key`/`object_id`, as documented per EVENT_* constant.
	 */
	public const INVALIDATION_TAGS = 'rfrac/invalidation_tags';

	/**
	 * Filter: whether setting the terms of an object flushes caches.
	 *
	 * Governs the assignment's own tags only; the term count updates core runs for the same assignment are queued
	 * as the `term_counted` event (filter those through `rfrac/invalidation_tags`). Runs for every call of
	 * wp_set_object_terms(); the relationships a call it lets through inserted and deleted are netted per request (a
	 * term assigned and replaced again in the same request is not queued) and queued as the `object_terms_set` event
	 * when the invalidator commits. Removals outside wp_set_object_terms() (wp_remove_object_terms(),
	 * wp_delete_object_term_relationships()) do not pass through this filter.
	 *
	 * @since 1.0.0
	 *
	 * @param bool         $flush      Whether to flush. Default true. Only a strict `true` flushes.
	 * @param int          $object_id  Object ID.
	 * @param array<mixed> $terms      Terms as passed to wp_set_object_terms().
	 * @param array<int>   $tt_ids     New term taxonomy IDs.
	 * @param string       $taxonomy   Taxonomy slug.
	 * @param bool         $append     Whether terms were appended.
	 * @param array<int>   $old_tt_ids Previous term taxonomy IDs.
	 */
	public const FLUSH_ON_SET_TERMS = 'rfrac/flush_on_set_terms';

	/**
	 * Filter: whether a meta update flushes caches.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $flush      Whether to flush. Default true when the key is registered with `show_in_rest`
	 *                           (or is a core post key such as `_thumbnail_id`), otherwise false. Only a strict `true` flushes.
	 * @param string $meta_type  Meta type (post, term, user, comment).
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @param string $action     What happened: `added`, `updated` or `deleted`.
	 */
	public const FLUSH_ON_META_UPDATE = 'rfrac/flush_on_meta_update';

	/**
	 * Filter: whether an update of a specific meta key flushes caches.
	 *
	 * The dynamic segments are the meta type and the meta key.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $flush      Whether to flush. Default: the result of the general meta update filter.
	 * @param int    $object_id  Object ID.
	 * @param mixed  $meta_value Meta value.
	 * @param string $action     What happened: `added`, `updated` or `deleted`.
	 */
	public const FLUSH_ON_META_UPDATE_KEY = 'rfrac/flush_on_meta_update/{meta_type}/{meta_key}';

	/**
	 * Filter: whether changes to a post type invalidate caches.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $invalidate Whether to invalidate. Default true when the post type exists and is exposed in REST
	 *                           (`show_in_rest`) or referenced by a route rule's `flush_post_types`; `revision` is
	 *                           never relevant and never reaches this filter.
	 * @param string $post_type  Post type slug.
	 */
	public const INVALIDATE_POST_TYPE = 'rfrac/invalidate_post_type';

	/**
	 * Filter: whether changes to a taxonomy invalidate caches.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $invalidate Whether to invalidate. Default true when the taxonomy exists and is exposed in REST
	 *                           (`show_in_rest`) or referenced by a route rule's `flush_taxonomies`.
	 * @param string $taxonomy   Taxonomy slug.
	 */
	public const INVALIDATE_TAXONOMY = 'rfrac/invalidate_taxonomy';

	/**
	 * Filter: whether changes to a comment type invalidate caches.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $invalidate   Whether to invalidate. Default true for `comment`, `pingback`, `trackback` and the empty type.
	 * @param string $comment_type Comment type.
	 */
	public const INVALIDATE_COMMENT_TYPE = 'rfrac/invalidate_comment_type';

	/**
	 * Filter: the option name to tags invalidation map.
	 *
	 * Applied once per listener, on the first option change. A value of `'all'` flushes every entry of the site
	 * immediately; a list of tags is queued (non-string entries are ignored). Applies to `updated_option` and
	 * `added_option`; a non-array result restores the default.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,'all'|list<string>> $map Option name => `'all'` or tags to invalidate. Default:
	 *                                              `permalink_structure`, `home`, `siteurl` => `'all'`; `blogname`,
	 *                                              `blogdescription`, `site_icon`, `gmt_offset`, `timezone_string` =>
	 *                                              `route:/`; `sticky_posts` => `list:post` (plus `post:{id}` of every
	 *                                              post whose sticky flag changed).
	 */
	public const OPTION_INVALIDATION_MAP = 'rfrac/option_invalidation_map';

	/**
	 * Filter: pending-invalidation count at which the queue is committed early.
	 *
	 * Compared with the number of unique pending tags over all sites after each queue call; the queue commits when
	 * the count reaches the threshold (values below 1 count as 1). Not applied while a commit is running or after
	 * the `shutdown` commit (from then on every queue call commits immediately).
	 *
	 * @since 1.0.0
	 *
	 * @param int $threshold Number of unique pending tags. Default 500.
	 */
	public const AUTO_COMMIT_THRESHOLD = 'rfrac/auto_commit_threshold';

	/**
	 * Filter: the tags collected while a response was generated.
	 *
	 * The route and rule tags and, when the fallback parser ran, its tags are already part of the list.
	 * The returned value is normalised, deduplicated and sorted afterwards: an array, an iterable or a
	 * string of comma or whitespace separated tags is accepted, any other value keeps the collected
	 * tags. The result counts against {@see self::MAX_TAGS_PER_ENTRY}. Removing tags makes the entry
	 * easier to leave stale.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string>     $tags    Collected tags. Default: the tags gathered during the request.
	 * @param \WP_REST_Request $request The main request.
	 * @param mixed            $data    The response data about to be echoed.
	 */
	public const COLLECTED_TAGS = 'rfrac/collected_tags';

	/**
	 * Filter: the maximum number of tags stored per entry.
	 *
	 * Counts every tag of the entry, `list:` and `route:` tags included, both while collecting and on the
	 * result of {@see self::COLLECTED_TAGS}. A non-numeric value falls back to 5000, the minimum is 1. A
	 * response that exceeds the limit is not stored (the failure is recorded as `tags`), so such a route
	 * never produces a cache hit.
	 *
	 * @since 1.0.0
	 *
	 * @param int $max Maximum tag count. Default 5000.
	 */
	public const MAX_TAGS_PER_ENTRY = 'rfrac/max_tags_per_entry';

	/**
	 * Filter: whether to run the fallback response parser to find tags.
	 *
	 * @since 1.0.0
	 *
	 * @param bool             $run     Whether to run the parser. Default: true when no `post:`, `term:`, `term-ref:`, `user:` or
	 *                                  `comment:` (or custom) item tag was collected for the main request, e.g. by a core controller.
	 * @param \WP_REST_Request $request The main request.
	 * @param mixed            $data    The response data about to be echoed.
	 */
	public const RUN_FALLBACK_PARSER = 'rfrac/run_fallback_parser';

	/**
	 * Filter: the list tags attached for a route.
	 *
	 * Runs for every request (nested ones included) before its callback. The result is normalised,
	 * deduplicated and sorted (an iterable or a string of comma or whitespace separated tags is accepted,
	 * anything else means no tags). The route is the request route as dispatched; the built-in map matches
	 * it lower-cased and without trailing slashes.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $tags  List tags. Default: the tags derived from the route.
	 * @param string       $route Request route, e.g. `/wp/v2/posts`.
	 */
	public const ROUTE_LIST_TAGS = 'rfrac/route_list_tags';

	/**
	 * Filter: the arguments of a maintenance run.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $args    Maintenance arguments: the built-in defaults of the context merged with the caller's overrides.
	 * @param string              $context Run context: `cron`, `admin`, `tools` or `cli`.
	 */
	public const MAINTENANCE_ARGS = 'rfrac/maintenance_args';

	/**
	 * Action: fires after every executed maintenance run, including incomplete ones; not for skipped runs.
	 *
	 * @since 1.0.0
	 *
	 * @param \Rockfire\Rest_Api_Cache\Maintenance\Maintenance_Result $result The outcome of the run (`->to_array()` for a plain array).
	 */
	public const MAINTENANCE_COMPLETED = 'rfrac/maintenance_completed';

	/**
	 * Action: fires when a plugin admin page loads.
	 *
	 * Fired on `load-{hook}` of every admin page of the plugin, after the capability check and before
	 * the page loads. The maintenance admin trigger listens to it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $page Admin page slug.
	 */
	public const ADMIN_PAGE_LOAD = 'rfrac/admin_page_load';

	/**
	 * Action: fires once per site while the plugin is activated.
	 *
	 * @since 1.0.0
	 *
	 * @param int $blog_id ID of the site being activated.
	 */
	public const ACTIVATE_SITE = 'rfrac/activate_site';

	/**
	 * Action: fires once after the plugin was activated, after all sites.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $network_wide Whether the activation was network-wide.
	 */
	public const ACTIVATED = 'rfrac/activated';

	/**
	 * Action: fires once per site while the plugin is deactivated.
	 *
	 * @since 1.0.0
	 *
	 * @param int $blog_id ID of the site being deactivated.
	 */
	public const DEACTIVATE_SITE = 'rfrac/deactivate_site';

	/**
	 * Action: fires once after the plugin was deactivated, after all sites.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $network_wide Whether the deactivation was network-wide.
	 */
	public const DEACTIVATED = 'rfrac/deactivated';

	/**
	 * Action: fires after the database schema of the current site was migrated.
	 *
	 * @since 1.0.0
	 *
	 * Only fires when the tables exist afterwards; a failed migration does not fire it.
	 *
	 * @param int $from Schema version before the migration (0 when nothing was installed).
	 * @param int $to   Schema version after the migration.
	 */
	public const MIGRATED = 'rfrac/migrated';

	/**
	 * Action: fires after the plugin was set up for a newly created multisite site.
	 *
	 * Not fired when the site's tables could not be created.
	 *
	 * @since 1.0.0
	 *
	 * @param int $site_id ID of the new site.
	 */
	public const SITE_INITIALIZED = 'rfrac/site_initialized';

	/**
	 * Filter: the effective route rules, before they are compiled.
	 *
	 * Each returned rule is validated. An invalid rule fails closed: its route is excluded from caching
	 * (it does not fall back to the rule below) and the problem is recorded as the plugin's last error.
	 * A key left out of the returned array has no rule. A return value that is not an array is ignored.
	 * Rules defined by the `RFRAC_ROUTES` constant are applied before this filter.
	 *
	 * The filter runs wherever the effective rules are built, not only when the configuration is compiled: also on
	 * a cache miss (`Rest_Tag_Collector::rules()` reads the rules to tag the response), on content saves (the
	 * invalidation listeners read them) and in the route manager. Keep the callback cheap and free of side effects,
	 * and use `rfrac/compiled` to act once per compiled snapshot.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,array<string,mixed>>                        $rules   Route key => rule array (see Route_Rule::to_array()).
	 * @param array<string,string>                                     $sources Route key => the layer that produced the rule in `$rules`: `default` (the built-in
	 *                                                                          default), `settings` (a stored rule, also an invalid one) or `constant` (`RFRAC_ROUTES`).
	 *                                                                          A callback that must respect user and constant rules leaves every key that is not `default` alone.
	 * @param array<string,\Rockfire\Rest_Api_Cache\Config\Route_Info> $routes  Route key => the route the server registered, in registration order.
	 */
	public const ROUTE_RULES = 'rfrac/route_rules';

	/**
	 * Filter: named groups of routes the Routes page can enable in one click.
	 *
	 * Each preset adds an "Enable {label}" button above the routes list. Enabling a preset enables the registered
	 * routes it lists that can be changed: unregistered routes, denied routes and routes locked by `RFRAC_ROUTES` are
	 * skipped, and a route keeps the rest of its stored rule. Routes stay opt-in: a preset only lists routes, nothing is
	 * enabled until an administrator uses the button. An entry whose key is not 1 to 64 characters of `a-z`, `0-9`, `_`
	 * and `-`, whose label is not a non-empty string, or whose routes are not a non-empty list of strings is ignored,
	 * and so is a return value that is not an array.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,array{label:string,routes:list<string>}> $presets Preset key => label and route patterns
	 *                                                                      exactly as registered, e.g. `/ns/v1/items`. Default: none.
	 */
	public const ROUTE_PRESETS = 'rfrac/route_presets';

	/**
	 * Filter: the query parameters every cached route accepts.
	 *
	 * Compiled into the configuration, so a change takes effect after the next recompile.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $params Parameter names. Default: `_fields`, `_embed`, `_locale`, `lang`.
	 */
	public const UNIVERSAL_PARAMS = 'rfrac/universal_params';

	/**
	 * Filter: the query parameters that never change the cache key on any route.
	 *
	 * Compiled into the configuration, so a change takes effect after the next recompile. It runs on top of the
	 * "Ignored query parameters" setting or its `RFRAC_GLOBAL_IGNORE_PARAMS` constant, so the filter always applies.
	 * A parameter a route registers is never ignored on that route.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $params Parameter names. Default: the setting, or the constant when it is defined.
	 */
	public const GLOBAL_IGNORE_PARAMS = 'rfrac/global_ignore_params';

	/**
	 * Filter: the request headers that vary the cache key on every route.
	 *
	 * Compiled into the configuration, so a change takes effect after the next recompile. It runs on top of the
	 * "Vary request headers" setting or its `RFRAC_GLOBAL_VARY_HEADERS` constant, so the filter always applies.
	 * A route can vary by at most 5 headers, its own and these together. Headers that carry credentials
	 * (`authorization`, `cookie`) are not allowed.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $headers Lowercase header names. Default: the setting, or the constant when it is defined.
	 */
	public const GLOBAL_VARY_HEADERS = 'rfrac/global_vary_headers';

	/**
	 * Filter: the cookie name prefixes that make a request bypass the cache.
	 *
	 * Compiled into the configuration, so a change takes effect after the next recompile.
	 *
	 * A return value that is not an array, or with only invalid entries, keeps the default list. Entries
	 * that are not valid cookie name characters are dropped and reported as the last error; a `.` is
	 * matched as `_`, the name PHP gives the cookie. The WordPress login cookies (`wordpress_logged_in_*`,
	 * `wordpress_sec_*`, `wp-postpass_*`, the `wordpress_<hash>` auth cookie and the names of the
	 * `LOGGED_IN_COOKIE`, `SECURE_AUTH_COOKIE` and `AUTH_COOKIE` constants) always bypass, whatever this filter returns.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $prefixes Cookie name prefixes. Default: the WordPress login, security, post password and WooCommerce session cookies plus `RFRAC_BYPASS_COOKIES`.
	 */
	public const BYPASS_COOKIE_PREFIXES = 'rfrac/bypass_cookie_prefixes';

	/**
	 * Filter: the response headers that are stored and replayed on a cache hit.
	 *
	 * Compiled into the configuration, so a change takes effect after the next recompile.
	 *
	 * Headers that carry credentials or per-request state (for example `set-cookie`) are removed afterwards.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $headers Lowercase header names. Default: `x-wp-total`, `x-wp-totalpages`, `link`, `allow`, `content-language`.
	 */
	public const REPLAY_HEADERS = 'rfrac/replay_headers';

	/**
	 * Filter: whether the `skip_cache` request parameter is honoured.
	 *
	 * Compiled into the configuration, so a change takes effect after the next recompile.
	 *
	 * Not applied when the `RFRAC_ALLOW_SKIP_CACHE` constant is defined.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $allow Whether `skip_cache` is honoured. Default false.
	 */
	public const ALLOW_SKIP_CACHE = 'rfrac/allow_skip_cache';

	/**
	 * Filter: whether a cache hit carries the `Access-Control-Allow-*` headers.
	 *
	 * Compiled into the configuration, so a change takes effect after the next recompile. Only the strict
	 * boolean `false` switches the headers off: hits then send no `Access-Control-Allow-Origin`,
	 * `Access-Control-Allow-Methods` or `Access-Control-Allow-Credentials` (on the early and on the
	 * fallback path alike) but still send `Vary`. The filter does not depend on the request, so it is not
	 * applied while a hit is served.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $send Whether hits carry the CORS headers. Default true.
	 */
	public const CORS_ON_HITS = 'rfrac/cors_on_hits';

	/**
	 * Filter: whether every request bypasses the cache.
	 *
	 * Compiled into the configuration, so a change takes effect after the next recompile. Only the strict
	 * boolean `true` bypasses: nothing is then served from or stored in the cache, and the bypass reason is
	 * `filtered`. The credential checks run first, so a request with credentials still reports `credentials`.
	 * The filter does not depend on the request, so it is not applied while a request is served.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $bypass Whether every request bypasses the cache. Default false.
	 */
	public const BYPASS_ALL = 'rfrac/bypass_all';

	/**
	 * Filter: whether a logged-out request that only carries a REST nonce may be cached.
	 *
	 * Compiled into the configuration, so a change takes effect after the next recompile. Only the strict
	 * boolean `true` switches it on: the `X-WP-Nonce` header and the `_wpnonce` parameter then stop counting
	 * as credential signals, and `_wpnonce` is left out of the cache key. Every other credential signal still
	 * bypasses (`Authorization`, `PHP_AUTH_USER`, `password`, the credential headers and the bypass cookies), and
	 * a request with a WordPress login cookie always bypasses, whatever this filter and
	 * `rfrac/bypass_cookie_prefixes` say. A cache hit never verifies the nonce: the response was generated for a
	 * logged-out visitor and is served as such. The filter does not depend on the request, so it is not
	 * applied while a request is served.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $enabled Whether nonce-only requests may be cached. Default false.
	 * @param string $source  Where the value comes from: `constant`, `setting` or `default`.
	 */
	public const NONCE_CACHING = 'rfrac/nonce_caching';

	/**
	 * Filter: whether this request's REST server registered only some routes.
	 *
	 * Applied on `rest_api_init` before the route snapshot is rebuilt from the live server (it is
	 * missing, pending discovery, stale or due for the periodic check), and when saving the settings
	 * recompiles it from the discovery server. Only the strict boolean `true` marks the server as
	 * partial: the rebuild is then skipped and the current snapshot keeps serving, so routes the server
	 * did not load are never dropped from it. On a settings save, a partial server leaves the snapshot
	 * serving, marked stale, so the next request with a full server rebuilds it with the saved
	 * settings; while a current snapshot serves, it also records a clearable `compile` failure that
	 * the rebuild clears. Migrations, site initialisation and direct `compile()` calls are not affected, and the
	 * engine never decides this itself.
	 *
	 * @since 1.0.0
	 *
	 * @param bool            $partial Whether the server is partial. Default false.
	 * @param \WP_REST_Server $server  The REST server of this request.
	 * @param string          $route   Request route with a leading slash, `''` when unknown.
	 */
	public const PARTIAL_SERVER = 'rfrac/partial_server';

	/**
	 * Action: fires right before the plugin builds the REST server to read the registered routes.
	 *
	 * Fired by {@see \Rockfire\Rest_Api_Cache\Config\Route_Discovery::server()}, so only when the plugin builds
	 * the server itself: the route manager, the catalog preset, the settings-save compile and the invalidation
	 * rule read during content saves. An integration whose routes the current request would not register (for
	 * example the WooCommerce Store API in wp-admin) can register them here. It is not fired when anything else
	 * builds the server, such as REST API requests, block-editor preloading or other plugins.
	 *
	 * @since 1.0.0
	 */
	public const DISCOVERY_SERVER = 'rfrac/discovery_server';

	/**
	 * Filter: whether a response with an empty result set is stored.
	 *
	 * Applied in the store phase only, never while deciding whether to serve from the cache.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $cache     Whether to store the empty result. Default false.
	 * @param string $route_key Key of the matched route rule.
	 */
	public const CACHE_EMPTY_RESULTS = 'rfrac/cache_empty_results';

	/**
	 * Filter: whether a response that passed every store-phase rule is still stored.
	 *
	 * Can only veto: it is applied after the policy accepted the response, so returning `true` never
	 * overrides a refusal, and anything other than the strict boolean `true` prevents storing.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $allow    Whether to store the response. Default true.
	 * @param string $route    Request route.
	 * @param string $rule_key Key of the matched route rule.
	 * @param int    $size     Payload size in bytes.
	 */
	public const STORE_ALLOWED = 'rfrac/store_allowed';

	/**
	 * Filter: the time to live in seconds of an entry about to be stored.
	 *
	 * The result is clamped to between 60 seconds and the configured maximum TTL.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $ttl      Time to live. Default: the TTL of the matched route rule.
	 * @param string $route    Request route.
	 * @param string $rule_key Key of the matched route rule.
	 */
	public const ENTRY_TTL = 'rfrac/entry_ttl';

	/**
	 * Action: fires after the compiled configuration was saved with a different store mode.
	 *
	 * @since 1.0.0
	 *
	 * @param string $old_mode Previous store mode (`database` or `object_cache`).
	 * @param string $new_mode New store mode.
	 */
	public const CONFIG_STORE_CHANGED = 'rfrac/config/store_changed';

	/**
	 * Action: fires after a compile saved the compiled configuration.
	 *
	 * Fires once per compile: after `Config_Compiler::compile()` (a settings save, activation, migration, site
	 * initialisation or a direct call) and after a live-server recompile that rebuilt the snapshot. It does not fire
	 * when a recompile is skipped (a partial server, a current snapshot) or finds the fingerprint unchanged, nor when a
	 * fingerprint is only computed, so a callback that records findings records them only on real compiles.
	 *
	 * @since 1.0.0
	 *
	 * @param \Rockfire\Rest_Api_Cache\Config\Compiled_Config $config The compiled configuration that was saved.
	 */
	public const COMPILED = 'rfrac/compiled';

	/**
	 * Filter: the number of sites set up eagerly (tables, flush, config) during a network activation.
	 *
	 * The remaining sites migrate lazily on their first `init`. Values below 1 count as 1.
	 *
	 * @since 1.0.0
	 *
	 * @param int $batch Number of sites. Default 100.
	 */
	public const NETWORK_ACTIVATION_BATCH = 'rfrac/network_activation_batch';

	/**
	 * Filter: the request headers that carry credentials; a request with any of them (non-empty) bypasses the cache.
	 *
	 * Compiled into the configuration, so a change takes effect after the next recompile. Only
	 * WordPress users, the listed headers, the `Authorization` header, nonces (unless the `rfrac/nonce_caching`
	 * filter switched them off) and the bypass cookies are recognised as credentials: routes protected by anything else (an IP allow-list, a custom
	 * credential in a query parameter or an unlisted header) must not be enabled for caching, or the
	 * protected response is served to everyone. A return value that is not an array, or with only invalid
	 * entries, keeps the default list; invalid entries are dropped and reported as the last error. An
	 * underscore counts as a dash (PHP passes both as `_`).
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $headers Lowercase header names. Default: `x-api-key`, `x-auth-token`, `x-access-token` plus `RFRAC_BYPASS_HEADERS`.
	 */
	public const BYPASS_HEADERS = 'rfrac/bypass_headers';

	/**
	 * Filter: whether the responses of a route differ per visitor in a way the cache key does not capture.
	 *
	 * Compiled into the configuration, so a change takes effect after the next recompile. A route that
	 * varies per visitor is never served from or stored in the cache. Only applied to routes that are
	 * enabled for caching, once per route and compile.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $varies    Whether the route varies per visitor. Default false.
	 * @param string $route_key Key of the route rule.
	 */
	public const ROUTE_VARIES_PER_VISITOR = 'rfrac/route_varies_per_visitor';

	/**
	 * Filter: the cookies whose value varies the cache key of a route, with the values that are allowed.
	 *
	 * Compiled into the configuration, so a change takes effect after the next recompile; it is
	 * re-checked at store time. Each cookie becomes part of the cache key of the route. A request whose
	 * cookie has a value outside the list is not cached. An absent cookie keys as empty. Names are
	 * normalised like `$_COOKIE` (a `.` counts as `_`). A declaration that is not valid (not an array,
	 * more than 8 cookies, an invalid name, a cookie without values or with more than 1,000 values, an
	 * empty value, a value longer than 128 bytes or with control characters) is reported as the last
	 * error and the route is not cached. Only applied to routes that are enabled for caching.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,list<string|int>> $cookies   Cookie name => allowed values. Default: none.
	 * @param string                         $route_key Key of the route rule.
	 */
	public const ROUTE_VARY_COOKIES = 'rfrac/route_vary_cookies';

	/**
	 * Filter: whether WooCommerce catalog prices vary by visitor location.
	 *
	 * Applied with the detected value: true when a callback is hooked on `woocommerce_get_tax_location`.
	 * Returning true forces the bypass of the price-bearing Store API routes on (they are never served
	 * from or stored in the cache); returning false forces it off. It is compiled into the configuration,
	 * so a change takes effect after the next recompile, and it is re-checked at store time: a price-bearing
	 * response is not stored while a customer is loaded or a location filter is hooked, unless this filter
	 * returns false.
	 *
	 * WooCommerce hooks the tax-location filter itself only while it sends stock-notification emails. A
	 * compile that runs then detects it, which fails safe: the price-bearing routes are bypassed until the
	 * next recompile.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $detected Whether a location filter is hooked. Default: whether a callback is hooked on `woocommerce_get_tax_location`.
	 */
	public const WC_LOCATION_PRICING = 'rfrac/woocommerce/location_pricing';

	/**
	 * Filter: the cookies, with their allowed values, that carry the visitor's currency, for the price-bearing WooCommerce routes.
	 *
	 * A currency plugin declares here the cookie it keeps the visitor's currency in. The declaration answers
	 * {@see self::ROUTE_VARY_COOKIES} for the public Store API routes whose responses carry prices (the product list,
	 * a product by id or slug and the collection data, under both Store API namespaces) and for no other route,
	 * so those responses are cached once per allowed value. A request with a value outside the list is not served
	 * from or stored in the cache. Only applied while WooCommerce is active.
	 *
	 * Compiled into the configuration, so a change takes effect after the next recompile. Cookie names follow
	 * the `$_COOKIE` normalisation (a `.` counts as `_`). A declaration that is not valid (see
	 * {@see self::ROUTE_VARY_COOKIES}), for example a value that is not a list, is reported as the last error and
	 * the price-bearing routes are not cached.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,list<string|int>> $cookies Cookie name => allowed values. Default: none.
	 */
	public const WC_CURRENCY_COOKIES = 'rfrac/woocommerce/currency_cookies';

	/**
	 * Action: fires when cache invalidation failed: a listener threw, a store call failed during a commit, a
	 * `rfrac/flushed` listener threw, or tags were still pending after the `shutdown` drain.
	 *
	 * Tags whose flush failed are re-queued once (`requeued`); a second failure drops them (`dropped`).
	 * With `WP_DEBUG` on, every failure is also written to the PHP error log.
	 *
	 * @since 1.0.0
	 *
	 * @param \Throwable          $error   The failure.
	 * @param array<string,mixed> $context `hook` (current WordPress hook), `event` (invalidation event or ''), and
	 *                                     where relevant `blog_id`, `requeued`, `dropped`, `pending`, `option`, `listener`.
	 */
	public const INVALIDATION_FAILED = 'rfrac/invalidation_failed';

	/**
	 * Filter: the capability required for the admin pages, the admin actions, saving the settings and the admin-bar flush.
	 *
	 * Checked for the menu, on every page load and render, in every `admin-post.php` action and list-table
	 * bulk action, for saving the settings through `options.php` (`option_page_capability_rfrac_settings`)
	 * and for the admin-bar node. An empty or non-string result falls back to `manage_options`.
	 *
	 * It does not grant writing the must-use file: the "Install must-use file" action also needs
	 * `activate_plugins` (`manage_network_plugins` on multisite, as the file is shared by the network)
	 * and is refused when `DISALLOW_FILE_MODS` is set.
	 *
	 * @since 1.0.0
	 *
	 * @param string $capability Capability. Default `manage_options`.
	 */
	public const SETTINGS_CAPABILITY = 'rfrac/settings_capability';

	/**
	 * Filter: whether the "Flush REST cache" admin-bar node is shown.
	 *
	 * Only applied for users with the {@see self::SETTINGS_CAPABILITY} capability; only a strict `true` shows it.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $show Whether to show the node. Default true.
	 */
	public const SHOW_ADMIN_BAR_FLUSH = 'rfrac/show_admin_bar_flush';

	/**
	 * Filter: the number of flushed and expired entries waiting for cleanup above which the Site Health test recommends action.
	 *
	 * @since 1.0.0
	 *
	 * @param int $threshold Default 1000.
	 */
	public const HEALTH_BACKLOG_THRESHOLD = 'rfrac/health_backlog_threshold';

	/**
	 * All registered hook names keyed by constant name.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Constant name => hook name.
	 */
	public static function all(): array {
		$out = array();
		foreach ( ( new \ReflectionClass( self::class ) )->getConstants() as $name => $value ) {
			if ( is_string( $value ) ) {
				$out[ $name ] = $value;
			}
		}
		return $out;
	}
}
