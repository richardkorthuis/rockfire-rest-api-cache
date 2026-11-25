<?php
/**
 * Fixed reason codes for not serving or not storing a cached response.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Request;

defined( 'ABSPATH' ) || exit;

/**
 * Why a request bypasses the cache (request phase) or why a response is not stored (store phase).
 *
 * The values are fixed strings: request data is never part of a reason.
 *
 * @since 1.0.0
 */
enum Bypass_Reason: string {

	// Request phase, in evaluation order.

	/**
	 * Caching is disabled.
	 * Also a lookup reason: the multisite site is archived, marked as spam or deactivated, so nothing is served.
	 */
	case DISABLED = 'disabled';

	/**
	 * The original plugin is active (coexistence guard).
	 */
	case COEXISTENCE = 'coexistence';

	/**
	 * Not a REST request.
	 */
	case NOT_REST = 'not_rest';

	/**
	 * An `X-HTTP-Method-Override` header is present (even empty), on any method.
	 */
	case METHOD_OVERRIDE = 'method_override';

	/**
	 * A credential signal or a bypass cookie is present, or a dispatched request has a `password` parameter (Capture).
	 *
	 * Request phase: a credential signal (an `Authorization` header, an HTTP basic auth user, a non-empty `password`
	 * parameter and, unless nonce caching is on, an `X-WP-Nonce` header or a `_wpnonce` parameter), a login cookie, a
	 * cookie with a bypass prefix, or a credential header of the compiled list that is non-empty or that the context did
	 * not read.
	 *
	 * Also a store-phase reason (Capture): the dispatched request has a `password` parameter of any value, in its query,
	 * form, JSON or URL parameters, whichever way it got there (a callback on `rest_request_before_callbacks` included).
	 */
	case CREDENTIALS = 'credentials';

	/**
	 * `context` is not `view` or `embed`, in the query or at the top level of the request body.
	 */
	case CONTEXT = 'context';

	/**
	 * `status` is present with a value other than `publish`, in the query or at the top level of the request body.
	 */
	case STATUS_PARAM = 'status_param';

	/**
	 * The route is longer than 191 bytes.
	 */
	case ROUTE_TOO_LONG = 'route_too_long';

	/**
	 * The route is on the hard deny-list.
	 */
	case DENIED_ROUTE = 'denied_route';

	/**
	 * No enabled route matches.
	 */
	case ROUTE_NOT_ENABLED = 'route_not_enabled';

	/**
	 * The route's responses vary per visitor in a way the cache key does not capture.
	 *
	 * Request phase only: the matched rule is never served or stored, so it is never a store-phase reason.
	 */
	case VISITOR_VARIES = 'visitor_varies';

	/**
	 * The method is not one of the methods of the matched route (GET unless the route opted in to more).
	 */
	case METHOD = 'method';

	/**
	 * A GET carrying any body, or a non-GET body that cannot be keyed: multipart, an unsupported Content-Type, invalid
	 * or too large.
	 */
	case BODY = 'body';

	/**
	 * A response format parameter (`_jsonp`, `_envelope`, `_pretty`, `_method`) is present in the query.
	 */
	case FORMAT_PARAM = 'format_param';

	/**
	 * `skip_cache` is present (in the query or at the top level of the request body) and honoured.
	 */
	case SKIP_CACHE = 'skip_cache';

	/**
	 * A bypass parameter of the route is present, in the query or at the top level of the request body.
	 */
	case BYPASS_PARAM = 'bypass_param';

	/**
	 * A parameter is not known to the route (registered mode).
	 */
	case UNKNOWN_PARAM = 'unknown_param';

	/**
	 * More parameters than allowed.
	 */
	case TOO_MANY_PARAMS = 'too_many_params';

	/**
	 * The query string is longer than 2048 bytes.
	 */
	case QUERY_TOO_LONG = 'query_too_long';

	/**
	 * The route or a parameter name or value is not valid UTF-8.
	 */
	case INVALID_ENCODING = 'invalid_encoding';

	/**
	 * A declared vary cookie carries a value outside its allow-list, or the context did not read it.
	 * Also a store-phase reason: a cookie declaration changed since the configuration was compiled.
	 */
	case VARY_COOKIE = 'vary_cookie';

	// Store phase.

	/**
	 * A user is logged in.
	 */
	case USER = 'user';

	/**
	 * The response status is not 200.
	 */
	case STATUS = 'status';

	/**
	 * The payload exceeds the maximum size.
	 */
	case TOO_LARGE = 'too_large';

	/**
	 * The result set is empty.
	 */
	case EMPTY = 'empty';

	/**
	 * The response sets a cookie.
	 */
	case SET_COOKIE = 'set_cookie';

	/**
	 * The response is marked `private` or `no-store`.
	 */
	case PRIVATE = 'private';

	/**
	 * The response has too many invalidation tags.
	 */
	case TOO_MANY_TAGS = 'too_many_tags';

	/**
	 * The store is at its hard entry ceiling (`Cache_Policy::entry_ceiling()`, 1.5 times the entry limit).
	 *
	 * The estimate is the cached entry count plus the entries stored since it was cached; it is
	 * recounted once it reaches 80% of the ceiling (`Cache_Policy::entry_recount_floor()`).
	 */
	case ENTRY_CEILING = 'entry_ceiling';

	/**
	 * A filter vetoed serving or storing, or `rfrac/bypass_all` bypassed every request.
	 *
	 * Request phase: `rfrac/bypass_all` (after the credential checks, which keep their own reason); a legacy hit filter
	 * that skips caching (`skip_caching`) or throws; a legacy `cache_key` filter that returns null (anything but a
	 * non-empty string, or a throw); or a legacy hit filter that refuses the stored hit (its output or headers cannot be
	 * used, or it throws), which is then not served.
	 *
	 * Store phase (Capture): the lookup of the request ended refused by such a hit filter, so the response is not stored
	 * either; the tag provider vetoed without giving a reason; the `rfrac/store_allowed` filter did not return true; the
	 * legacy store bridge refused (a hooked legacy hit filter that a hit cannot apply); the legacy `cache_key` filter
	 * returned null for the key to store under; or the store verdict received a bypass decision without a reason.
	 */
	case FILTERED = 'filtered';

	/**
	 * A flush committed after this request's lookup, or while the response was being stored; the response may predate it, so it is not stored.
	 */
	case FLUSHED = 'flushed';

	/**
	 * No lookup recorded a flush epoch for this request, so the response has no baseline to compare a flush with.
	 *
	 * Capture stores nothing without a compiled configuration, so a configuration that was missing for the whole
	 * request never gets here. What does: a compiled configuration with a stale schema version (the early and fallback
	 * servers skip the lookup for it, the loader of Capture has no schema check); a configuration compiled during the
	 * request, after the lookup was skipped; a fallback preparation that failed, so no lookup ran; or a lookup that
	 * ended as a bypass (it records no epoch) while Capture found the request cacheable.
	 *
	 * The refusal is recorded as a `capture` failure of the route, except on the request whose lookup ran on a pending
	 * or stale snapshot that its own recompile then rebuilt (the first request after an activation, or after the
	 * snapshot was marked stale): the next request of the route is stored, so nothing is recorded.
	 */
	case NO_LOOKUP = 'no_lookup';

	/**
	 * An internal error or an unusable input; the request continues uncached.
	 *
	 * Request phase: the regex of a route rule failed, or a vary header of the matched rule was not read by the context
	 * (it is unknown, never absent, so it never keys as an empty value).
	 *
	 * Lookup: the lookup threw, including when it could not read the flush epoch of a cacheable miss.
	 *
	 * Store phase (Capture): Capture threw after the dispatch, or a bypass decision carried no reason; storing threw
	 * (the flush epoch could not be read, the store did not store the entry, or an entry stored during a flush could not
	 * be retracted); the payload could not be encoded as JSON; the tags could not be collected (a collector hook failed,
	 * or an external tag provider could not establish them); or the lookup of the request ended as an error, so there
	 * is no flush epoch to compare with.
	 */
	case ERROR = 'error';
}
