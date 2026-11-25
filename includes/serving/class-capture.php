<?php
/**
 * Stores the response core is about to echo.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Serving;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Config\Settings_Schema;
use Rockfire\Rest_Api_Cache\Config\Vary_Cookies;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Tags;
use Rockfire\Rest_Api_Cache\Legacy\Store_Bridge;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Cache_Policy;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Storage\Entry_Data;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Factory;
use Rockfire\Rest_Api_Cache\Storage\Store_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Stashes a cacheable main request on `rest_post_dispatch`, stores the exact JSON on
 * `rest_pre_echo_response` and only then marks the response with the outcome.
 *
 * Storing happens late because `_embed` sub-requests run in `response_to_data()`, after `rest_post_dispatch`.
 * A cacheable response is provisionally marked `BYPASS` on `rest_post_dispatch` (it stays so when it
 * is never echoed, e.g. when `rest_pre_serve_request` served it); on `rest_pre_echo_response`, before
 * anything is echoed, the header is replaced by `MISS` when the response was stored, else by `BYPASS`
 * with the reason it was not stored: `error` when storing failed or the lookup failed (it then recorded no flush epoch
 * to compare with), `flushed` when a flush committed after the lookup or hit the entry while it was stored, otherwise
 * the store-phase reason (`no_lookup`, no lookup recorded a flush epoch, is also recorded as a `capture` failure of the route, except on the request whose
 * lookup ran on a pending or stale snapshot that its own recompile rebuilt). The reason goes into `X-Rfrac-Cache-Reason` only under `WP_DEBUG` and only for a
 * REST request. Not cacheable requests are marked `BYPASS` with their reason on `rest_post_dispatch`.
 *
 * Every callback returns its input unchanged, swallows any `\Throwable` (recorded with
 * {@see Failure_Recorder}, context `capture`, for the route) and never reads a superglobal itself:
 * the request context comes from {@see Request_State} or the injected factory. A stored response
 * clears a recorded failure of the serving path for its own route ({@see Failure_Recorder::clear()}).
 *
 * The cache key is always computed from the decision re-evaluated here (never reused from the
 * lookup), so key, URL hash, rule, TTL and vary come from the same config. An injected
 * {@see Hit_Filters_Interface} runs after the policy passed the credential checks: `decide()` on a
 * cacheable decision and `key()` on the computed key (null is not stored, `filtered`), so an entry is
 * stored under the key the lookup uses. The dispatched request is checked too: one with a `password` parameter
 * from any source (`credentials`) or a GET with a body (`body`) is not stored. A hit the lookup's `response()` refused (the lookup recorded a
 * `filtered` decision) is not stored either, so the entry is not rewritten on every request. The store verdict also
 * sees the headers already sent with `header()` (core's no-cache headers, cookies set by a
 * callback); those are never replayed. A route whose live cookie declaration (`rfrac/route_vary_cookies`)
 * differs from the compiled one is not stored (`vary_cookie`), as its key would lack a declared cookie. After
 * `rfrac/store_allowed` the response is also not stored (`filtered`) while {@see Store_Bridge::refuses_store()} says so, a
 * check that is no filter, so no callback of a site undoes it.
 * The tag provider is reset on every `rest_pre_echo_response`, whatever happens. `Cache_Key` and
 * `Store_Factory` are static classes, so they are injected as optional closures.
 *
 * @since 1.0.0
 */
final class Capture {

	/**
	 * Object cache group of the cached entry count.
	 */
	private const COUNT_GROUP = 'rfrac';

	/**
	 * Object cache key of the cached entry count.
	 */
	private const COUNT_KEY = 'count';

	/**
	 * Lifetime of the cached entry count in seconds.
	 */
	private const COUNT_TTL = 300;

	/**
	 * Object cache key of the marker that the entries were recounted moments ago.
	 */
	private const RECOUNT_KEY = 'recounted';

	/**
	 * Shortest time between two recounts in seconds, at or above the recount floor.
	 */
	private const RECOUNT_INTERVAL = 30;

	/**
	 * Lowest stored TTL in seconds.
	 */
	private const MIN_TTL = 60;

	/**
	 * Builds the cache key, `( Request_Context, Policy_Decision ): string`.
	 *
	 * @var \Closure
	 * @phpstan-var \Closure(Request_Context, Policy_Decision): string
	 */
	private \Closure $key_function;

	/**
	 * Builds the store, `( Compiled_Config ): Store_Interface`.
	 *
	 * @var \Closure
	 */
	private \Closure $store_factory;

	/**
	 * Returns the header lines already sent with `header()`, `(): list<string>`.
	 *
	 * @var \Closure
	 */
	private \Closure $sent_headers;

	/**
	 * Value of the exposed CORS headers recorded for this request.
	 *
	 * @var string|null
	 */
	private ?string $expose_headers = null;

	/**
	 * Value of the allowed CORS headers recorded for this request.
	 *
	 * @var string|null
	 */
	private ?string $allow_headers = null;

	/**
	 * Whether the main request was already seen on `rest_post_dispatch`.
	 *
	 * @var bool
	 */
	private bool $handled = false;

	/**
	 * The stashed main request.
	 *
	 * @var \WP_REST_Request|null
	 */
	private ?\WP_REST_Request $request = null;

	/**
	 * The stashed main response; its status and headers are read when storing.
	 *
	 * @var \WP_HTTP_Response|null
	 */
	private ?\WP_HTTP_Response $response = null;

	/**
	 * Context, decision and config of the stashed request.
	 *
	 * @var array{0:Request_Context,1:Policy_Decision,2:Compiled_Config}|null
	 */
	private ?array $stash = null;

	/**
	 * Constructor.
	 *
	 * @param Cache_Policy               $policy          Policy.
	 * @param Repository                 $repository      Repository (entry count, default store).
	 * @param Tag_Provider_Interface     $tag_provider    Tag collector.
	 * @param \Closure                   $config_loader   Returns the compiled config or null, e.g. `Compiled_Config::load(...)`.
	 * @param \Closure                   $context_factory Builds the request context when {@see Request_State} has none, `( Compiled_Config ): Request_Context`, e.g. `fn( $config ) => Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() )`.
	 * @param \Closure|null              $key_function    Optional key builder; default `Cache_Key::key()`.
	 * @param \Closure|null              $store_factory   Optional `( Compiled_Config ): Store_Interface`; default builds the store from the config's mode and hit sampling.
	 * @param \Closure|null              $sent_headers    Optional `(): list<string>` returning the header lines sent so far; default `headers_list()`.
	 * @param Hit_Filters_Interface|null $filters         Optional opt-in pass that decides and keys; the set the lookup runs, default none.
	 *
	 * @phpstan-param \Closure(): ?Compiled_Config $config_loader
	 * @phpstan-param \Closure(Compiled_Config): Request_Context $context_factory
	 * @phpstan-param (\Closure(Request_Context, Policy_Decision): string)|null $key_function
	 */
	public function __construct(
		private readonly Cache_Policy $policy,
		private readonly Repository $repository,
		private readonly Tag_Provider_Interface $tag_provider,
		private readonly \Closure $config_loader,
		private readonly \Closure $context_factory,
		?\Closure $key_function = null,
		?\Closure $store_factory = null,
		?\Closure $sent_headers = null,
		private readonly ?Hit_Filters_Interface $filters = null
	) {
		$this->key_function  = $key_function ?? Cache_Key::key( ... );
		$this->store_factory = $store_factory ?? fn( Compiled_Config $config ): Store_Interface => Store_Factory::for_mode( $config->store_mode(), $this->repository, $config->hit_sampling() );
		$this->sent_headers  = $sent_headers ?? headers_list( ... );
	}

	/**
	 * Filter `rest_exposed_cors_headers`: remember what core sends as `Access-Control-Expose-Headers`.
	 *
	 * @param mixed $headers Header names.
	 * @param mixed $request Request (unused).
	 *
	 * @return mixed The input.
	 */
	public function record_expose_headers( mixed $headers, mixed $request = null ): mixed {
		unset( $request );
		$this->expose_headers = self::join( $headers );
		return $headers;
	}

	/**
	 * Filter `rest_allowed_cors_headers`: remember what core sends as `Access-Control-Allow-Headers`.
	 *
	 * @param mixed $headers Header names.
	 * @param mixed $request Request (unused).
	 *
	 * @return mixed The input.
	 */
	public function record_allow_headers( mixed $headers, mixed $request = null ): mixed {
		unset( $request );
		$this->allow_headers = self::join( $headers );
		return $headers;
	}

	/**
	 * Filter `rest_post_dispatch`: mark the live response (provisionally, when cacheable) and stash a storable main request.
	 *
	 * Only the first dispatch whose route equals the context route counts; embeds and batch
	 * sub-requests are ignored. A dispatch whose method differs from the method of the request
	 * (a method override) is handled and left untouched: nothing is stored and no outcome is marked.
	 *
	 * @param mixed $result  Response.
	 * @param mixed $server  Server (unused).
	 * @param mixed $request Request.
	 *
	 * @return mixed The input.
	 */
	public function on_post_dispatch( mixed $result, mixed $server = null, mixed $request = null ): mixed {
		if ( $this->handled || ! $result instanceof \WP_HTTP_Response || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		try {
			$config = ( $this->config_loader )();
			if ( ! $config instanceof Compiled_Config ) {
				return $result;
			}
			$context = Request_State::context() ?? ( $this->context_factory )( $config );
			if ( $request->get_route() !== $context->route ) {
				return $result;
			}
			$this->handled = true;
			if ( strtoupper( $request->get_method() ) !== $context->method ) {
				return $result;
			}

			$decision = $this->policy->evaluate( $context, $config );
			if ( $decision->cacheable && null !== $this->filters ) {
				// Only after the policy passed the credential checks, like the lookup.
				$decision = $this->filters->decide( $decision, $context, $config );
				if ( $decision->cacheable && self::lookup_refused() ) {
					// The hit pass refused the stored hit (`response()`), which only the lookup ran: neither served nor stored.
					$decision = Policy_Decision::bypass( Bypass_Reason::FILTERED, $decision->rule );
				}
			}
			if ( $decision->cacheable ) {
				// What core dispatched, which the context may not have seen: a callback can add a parameter and a body can arrive unannounced.
				$veto = self::dispatched_veto( $request, $context );
				if ( null !== $veto ) {
					$decision = Policy_Decision::bypass( $veto, $decision->rule );
				}
			}
			if ( ! $decision->cacheable ) {
				self::mark_bypass( $result, $decision->reason ?? Bypass_Reason::ERROR, $context->is_rest );
				return $result;
			}

			// Provisional: replaced on rest_pre_echo_response by the outcome of storing.
			$result->header( 'X-Rfrac-Cache', 'BYPASS' );
			if ( null !== $decision->rule && [] !== $decision->rule->vary_headers ) {
				// Core adds `Vary: Origin` itself; advertise the vary headers of the rule as well.
				$result->header( 'Vary', Response_Writer::vary_value( $decision->rule ), false );
			}
			$this->request  = $request;
			$this->response = $result;
			$this->stash    = array( $context, $decision, $config );
		} catch ( \Throwable $e ) {
			Failure_Recorder::record( 'capture', $e, $request->get_route() );
			$this->discard();
			try {
				self::mark_bypass( $result, Bypass_Reason::ERROR, true );
			} catch ( \Throwable $inner ) {
				unset( $inner ); // The response stays untouched.
			}
		}
		return $result;
	}

	/**
	 * Filter `rest_pre_echo_response`: store the JSON core is about to echo and mark the outcome.
	 *
	 * Core has sent the headers of the response object but has not echoed anything yet, so the
	 * provisional marker is replaced here: `MISS` when stored, else `BYPASS` with the reason.
	 * The tag provider is reset on every call, also for a response that is not stored.
	 *
	 * @param mixed $data    Response data.
	 * @param mixed $server  Server; receives the outcome headers.
	 * @param mixed $request Request.
	 *
	 * @return mixed The input.
	 */
	public function on_pre_echo( mixed $data, mixed $server = null, mixed $request = null ): mixed {
		try {
			if ( null === $this->stash || ! $request instanceof \WP_REST_Request || $request !== $this->request ) {
				return $data;
			}

			$is_rest = $this->stash[0]->is_rest;
			try {
				$reason = $this->store( $data, $request );
			} catch ( \Throwable $e ) {
				// Storing is best-effort: the response is served as it is, but not as a MISS.
				Failure_Recorder::record( 'capture', $e, $request->get_route() );
				$reason = Bypass_Reason::ERROR;
			}
			if ( null === $reason ) {
				$this->count_stored();
			}
			$this->discard();
			$this->mark_outcome( $server, $reason, $is_rest );
			return $data;
		} finally {
			try {
				$this->tag_provider->reset();
			} catch ( \Throwable $e ) {
				unset( $e ); // Nothing left to do.
			}
		}
	}

	/**
	 * Replace the provisional marker, which core has already sent, by the outcome.
	 *
	 * When output has already started (e.g. a notice was printed), core's `send_header()` could only
	 * raise a "headers already sent" warning, so the provisional marker stays. A server class that
	 * overrides `send_header()` decides itself.
	 *
	 * @param mixed              $server  REST server.
	 * @param Bypass_Reason|null $reason  Why the response was not stored; null when it was stored.
	 * @param bool               $is_rest Whether the request is a REST request (the reason is only exposed then).
	 *
	 * @return void
	 */
	private function mark_outcome( mixed $server, ?Bypass_Reason $reason, bool $is_rest ): void {
		if ( ! $server instanceof \WP_REST_Server ) {
			return;
		}
		try {
			if ( headers_sent() && \WP_REST_Server::class === ( new \ReflectionMethod( $server, 'send_header' ) )->getDeclaringClass()->getName() ) {
				return;
			}
			if ( null === $reason ) {
				$server->send_header( 'X-Rfrac-Cache', 'MISS' );
				return;
			}
			$debug = defined( 'WP_DEBUG' ) && WP_DEBUG && $is_rest;
			foreach ( Response_Writer::bypass_headers( $reason, $debug ) as $name => $value ) {
				$server->send_header( $name, $value );
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // The response stays marked as it is.
		}
	}

	/**
	 * Store the entry when every rule allows it.
	 *
	 * A cacheable response that is refused because no lookup recorded a flush epoch (`no_lookup`) is recorded as a `capture`
	 * failure of its route, so a site where every request ends so shows it on the Status and Site Health screens; the next
	 * stored response of the route clears it ({@see self::clear_recorded_failure()}). The one exception is the request whose
	 * lookup ran on a pending or stale snapshot that its own recompile rebuilt ({@see Request_State::outdated_snapshot_rebuilt()}):
	 * nothing is recorded and no recorded failure is cleared, as the next request of the route is stored. The verdict stays `no_lookup`.
	 *
	 * @param mixed            $data    Response data.
	 * @param \WP_REST_Request $request Main request.
	 *
	 * @return Bypass_Reason|null Why the response was not stored; null when it was stored.
	 *
	 * @throws \RuntimeException When the store did not store the entry, the flush epoch could not be read, or an entry stored during a flush could not be retracted.
	 */
	private function store( mixed $data, \WP_REST_Request $request ): ?Bypass_Reason {
		if ( Settings_Schema::kill_switch() ) {
			return Bypass_Reason::DISABLED;
		}
		if ( null === $this->stash || null === $this->response || null === $data ) {
			return Bypass_Reason::EMPTY;
		}
		[ $context, $decision, $config ] = $this->stash;
		$rule                            = $decision->rule;
		if ( null === $rule ) {
			return Bypass_Reason::ROUTE_NOT_ENABLED;
		}

		// This filter is documented in wp-includes/rest-api/class-wp-rest-server.php.
		$json = wp_json_encode( $data, apply_filters( 'rest_json_encode_options', 0, $request ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		if ( ! is_string( $json ) ) {
			return Bypass_Reason::ERROR;
		}

		if ( $this->tag_provider instanceof Rule_Aware_Tag_Provider_Interface ) {
			$this->tag_provider->use_rule_key( $rule->key );
		}
		$tags = $this->tag_provider->collect( $request, $data );
		if ( null === $tags ) {
			// A provider that cannot say why it refused counts as a veto.
			$refusal = $this->tag_provider instanceof Refusal_Aware_Tag_Provider_Interface ? $this->tag_provider->refusal() : null;
			return $refusal ?? Bypass_Reason::FILTERED;
		}
		$tags = Tags::normalize_many( $tags );

		$response_headers = $this->response->get_headers();
		$size             = strlen( $json );
		$user_id          = get_current_user_id();
		$status           = $this->response->get_status();
		// The headers sent with header() count for the verdict; they are never replayed.
		$verdict_headers = array_merge( $response_headers, array_values( (array) ( $this->sent_headers )() ) );
		$is_empty        = array() === $data;
		$tag_count       = count( $tags );
		$verdict_for     = fn( int $entry_estimate ): ?Bypass_Reason => $this->policy->store_verdict(
			$decision,
			$user_id,
			$status,
			$size,
			$verdict_headers,
			$is_empty,
			$tag_count,
			$entry_estimate,
			$config
		);

		// The ceiling rule is the last one, so the entry count is only looked up (a COUNT query when it is not
		// cached) for a response every other rule allows; 0, the lowest possible count, stands in until then.
		$verdict = $verdict_for( 0 );
		if ( null === $verdict ) {
			$verdict = $verdict_for( $this->entry_estimate( $config ) );
		}
		if ( null !== $verdict ) {
			return $verdict;
		}

		/**
		 * Filters the cookies that vary the cache key of a route; compared with the compiled declaration so a
		 * declaration changed since the compile never stores an entry keyed without its cookie. A veto only:
		 * keying still comes from the compiled configuration and the request.
		 *
		 * @see Hooks::ROUTE_VARY_COOKIES
		 */
		if ( Vary_Cookies::normalize( apply_filters( Hooks::ROUTE_VARY_COOKIES, [], $rule->key ) ) !== $rule->vary_cookies ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
			return Bypass_Reason::VARY_COOKIE;
		}

		$route = (string) $context->route;

		/**
		 * Filters whether a storable response is stored; it can only veto.
		 *
		 * @see Hooks::STORE_ALLOWED
		 */
		if ( true !== apply_filters( Hooks::STORE_ALLOWED, true, $route, $rule->key, $size ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
			return Bypass_Reason::FILTERED;
		}

		// Not a filter, so no callback of a site can lift it: a hooked legacy hit filter that a hit cannot apply.
		if ( Store_Bridge::refuses_store() ) {
			return Bypass_Reason::FILTERED;
		}

		/**
		 * Filters the time to live of the entry.
		 *
		 * @see Hooks::ENTRY_TTL
		 */
		$ttl = apply_filters( Hooks::ENTRY_TTL, $rule->ttl, $route, $rule->key ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		$ttl = is_numeric( $ttl ) ? (int) $ttl : $rule->ttl;
		$ttl = min( $config->max_ttl(), max( self::MIN_TTL, $ttl ) );

		$cache_key = ( $this->key_function )( $context, $decision );
		if ( null !== $this->filters ) {
			// The entry is stored under the key the lookup looks it up with.
			$cache_key = $this->filters->key( $cache_key, $context, $decision, $config );
			if ( null === $cache_key ) {
				return Bypass_Reason::FILTERED;
			}
		}
		$entry = new Entry_Data(
			$cache_key,
			Cache_Key::url_hash( $context, $decision->key_params ),
			$route,
			$rule->key,
			Cache_Key::display( $context, $decision ),
			$context->method,
			$json,
			$this->replayable_headers( $response_headers, $config ),
			$ttl,
			$tags
		);

		$store = ( $this->store_factory )( $config );

		// The response may predate a flush that committed since the lookup, so the comparison starts at the epoch the lookup recorded.
		// A request whose lookup recorded none (a lookup that failed, no lookup at all, or one that ended as a bypass) has no baseline:
		// an epoch read now would already include a flush that committed while the response was generated, so nothing is stored.
		if ( self::lookup_failed() ) {
			return Bypass_Reason::ERROR;
		}
		$baseline = Request_State::flush_epoch();
		if ( null === $baseline ) {
			// Only a debug header would tell otherwise: a site whose every request ends here (a compiled config the lookup cannot use) would stop caching unnoticed.
			// The one request that is no sign of that is the one whose lookup ran on a pending or stale snapshot that its own recompile rebuilt: the next request stores.
			if ( ! Request_State::outdated_snapshot_rebuilt() ) {
				Failure_Recorder::record( 'capture', 'No lookup recorded a flush epoch for this request; the response was not stored.', $request->get_route() );
			}
			return Bypass_Reason::NO_LOOKUP;
		}
		if ( $this->current_epoch() !== $baseline ) {
			return Bypass_Reason::FLUSHED;
		}

		$id = $store instanceof Store_Interface ? $store->set( $entry ) : null;
		if ( null === $id ) {
			throw new \RuntimeException( 'The store did not store the entry.' );
		}
		if ( $id < 1 ) {
			// A concurrent flush or delete hit the entry while it was stored: it is not served, but nothing failed.
			return Bypass_Reason::FLUSHED;
		}

		// A flush that committed while the entry was written may have missed it: retract it (a flush would advance the epoch again).
		// An epoch that cannot be read counts as moved: the entry is retracted before the failure propagates.
		$moved = true;
		try {
			$moved = $this->current_epoch() !== $baseline;
		} finally {
			if ( $moved ) {
				$this->retract( $store, $id );
			}
		}
		if ( $moved ) {
			return Bypass_Reason::FLUSHED;
		}

		/**
		 * Fires after a response was stored.
		 *
		 * @see Hooks::STORED
		 */
		do_action( Hooks::STORED, $cache_key, $route, $tags, $size ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.

		$this->clear_recorded_failure( $request->get_route() );
		return null;
	}

	/**
	 * Number of flushes committed on the current site, read from the database.
	 *
	 * @return int
	 *
	 * @throws \RuntimeException When the epoch could not be read.
	 */
	private function current_epoch(): int {
		$epoch = $this->repository->flush_epoch();
		if ( null === $epoch ) {
			throw new \RuntimeException( 'The flush epoch could not be read.' );
		}
		return $epoch;
	}

	/**
	 * Take an entry out of service that a flush may have missed: delete it, else flag it flushed.
	 *
	 * A delete never throws and reports a failed statement (a lock wait timeout during the very flush that caused
	 * this) as 0 rows, the same as a row that is already gone. Either way the entry is flagged flushed instead, which
	 * both stores support (the object cache store also drops the object it serves from), and its state is checked.
	 *
	 * @param Store_Interface $store Store the entry was written to.
	 * @param int             $id    Entry id.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the entry is still active, or its state is unknown, after both attempts.
	 */
	private function retract( Store_Interface $store, int $id ): void {
		if ( $store->delete( array( $id ) ) > 0 ) {
			return;
		}
		$store->flush_by_ids( array( $id ) );
		$state = $this->repository->entry_state( $id );
		if ( null === $state || Repository::STATE_ACTIVE === $state ) {
			throw new \RuntimeException( 'The entry stored while a flush committed could not be retracted.' );
		}
	}

	/**
	 * Clear a recorded failure of the serving path now that a response of the route was looked up and stored.
	 *
	 * Storing and tagging worked for this route, so a `capture` or `tags` failure recorded for this
	 * route (or for no particular route) is cleared; one of another route stays, as this success says
	 * nothing about it. A tag limit overflow is never cleared this way. The lookup and the server
	 * that ran it only count when this request's lookup completed without an error. Costs one option
	 * read (none with a persistent object cache).
	 *
	 * @param string $route Route of the stored response.
	 *
	 * @return void
	 */
	private function clear_recorded_failure( string $route ): void {
		$contexts = array( 'capture', 'tags' );
		$decision = Request_State::decision();
		if ( Request_State::lookup_done() && null !== $decision && Bypass_Reason::ERROR !== $decision->reason ) {
			array_push( $contexts, 'lookup', Request_State::early_booted() ? 'early_server' : 'fallback_server' );
		}
		Failure_Recorder::clear( $contexts, $route );
	}

	/**
	 * The headers that may be stored: the replay allow-list plus the two recorded CORS headers.
	 *
	 * Cookies and credentials never pass, whatever the allow-list says.
	 *
	 * @param array<mixed>    $headers Response headers.
	 * @param Compiled_Config $config  Config.
	 *
	 * @return array<string,string>
	 */
	private function replayable_headers( array $headers, Compiled_Config $config ): array {
		$allowed = array_diff( array_map( 'strtolower', $config->replay_headers() ), Config_Compiler::FORBIDDEN_REPLAY_HEADERS );
		$out     = array();
		foreach ( $headers as $name => $value ) {
			if ( ! in_array( strtolower( (string) $name ), $allowed, true ) || ! is_scalar( $value ) ) {
				continue;
			}
			$value = (string) $value;
			if ( 1 === preg_match( '/[\r\n]/', $value ) ) {
				continue;
			}
			$out[ (string) $name ] = $value;
		}
		if ( null !== $this->expose_headers ) {
			$out['Access-Control-Expose-Headers'] = $this->expose_headers;
		}
		if ( null !== $this->allow_headers ) {
			$out['Access-Control-Allow-Headers'] = $this->allow_headers;
		}
		return $out;
	}

	/**
	 * Number of active entries: the cached count (every store bumps it) while it is below the recount floor,
	 * else a fresh count that replaces it.
	 *
	 * From the recount floor on the cached count is still trusted for {@see self::RECOUNT_INTERVAL} after a
	 * recount (the bump of every store keeps it from under-counting, so the entry ceiling holds), so a client
	 * that holds the store in that band costs one COUNT query per interval, not one per request. The marker is
	 * claimed with `wp_cache_add()`, so concurrent requests do not recount together either.
	 *
	 * @param Compiled_Config $config Compiled configuration.
	 *
	 * @return int
	 */
	private function entry_estimate( Compiled_Config $config ): int {
		$cached = wp_cache_get( self::COUNT_KEY, self::COUNT_GROUP );
		if ( is_int( $cached ) ) {
			if ( $cached < Cache_Policy::entry_recount_floor( $config ) ) {
				return $cached;
			}
			if ( ! wp_cache_add( self::RECOUNT_KEY, 1, self::COUNT_GROUP, self::RECOUNT_INTERVAL ) ) {
				return $cached;
			}
		}
		$count = $this->repository->count_active();
		wp_cache_set( self::COUNT_KEY, $count, self::COUNT_GROUP, self::COUNT_TTL );
		wp_cache_set( self::RECOUNT_KEY, 1, self::COUNT_GROUP, self::RECOUNT_INTERVAL );
		return $count;
	}

	/**
	 * Add the entry that was just stored to the cached entry count; a count that is not cached is left alone.
	 *
	 * @return void
	 */
	private function count_stored(): void {
		try {
			wp_cache_incr( self::COUNT_KEY, 1, self::COUNT_GROUP );
		} catch ( \Throwable $e ) {
			unset( $e ); // The count is recounted when it expires.
		}
	}

	/**
	 * Set the bypass headers on the live response.
	 *
	 * @param \WP_HTTP_Response $result  Response.
	 * @param Bypass_Reason     $reason  Reason.
	 * @param bool              $is_rest Whether the request is a REST request (the reason is only exposed then).
	 *
	 * @return void
	 */
	private static function mark_bypass( \WP_HTTP_Response $result, Bypass_Reason $reason, bool $is_rest ): void {
		$debug = defined( 'WP_DEBUG' ) && WP_DEBUG && $is_rest;
		foreach ( Response_Writer::bypass_headers( $reason, $debug ) as $name => $value ) {
			$result->header( $name, $value );
		}
	}

	/**
	 * Why a dispatched request is never stored although the policy let its context through.
	 *
	 * A `password` key (any value, defaults do not count) in the query, form, JSON or URL parameters is a credential
	 * whichever way it arrived, a callback on `rest_request_before_callbacks` included. A GET whose dispatched request
	 * carries a body is never keyed by it. Both are checked on the dispatched request itself: it holds what the
	 * handler saw.
	 *
	 * @param \WP_REST_Request $request Dispatched request.
	 * @param Request_Context  $context Context of the request.
	 *
	 * @return Bypass_Reason|null Null when nothing vetoes storing.
	 */
	private static function dispatched_veto( \WP_REST_Request $request, Request_Context $context ): ?Bypass_Reason {
		$sources = array(
			$request->get_query_params(),
			$request->get_body_params(),
			(array) $request->get_json_params(),
			$request->get_url_params(),
		);
		foreach ( $sources as $params ) {
			if ( array_key_exists( 'password', $params ) ) {
				return Bypass_Reason::CREDENTIALS;
			}
		}
		// A request core built itself has no body set at all (null), which is no body.
		if ( 'GET' === $context->method && '' !== (string) $request->get_body() ) {
			return Bypass_Reason::BODY;
		}
		return null;
	}

	/**
	 * Whether the lookup of this request ended with a `filtered` decision.
	 *
	 * The hit filter set's `response()` runs on the lookup only (the capture has no hit), so the capture learns
	 * a refused hit from the decision the lookup recorded in {@see Request_State}, the one the legacy store and
	 * tag bridges read. No lookup or any other outcome (an `error` included) is not a refusal.
	 *
	 * @return bool
	 */
	private static function lookup_refused(): bool {
		return Request_State::lookup_done() && Bypass_Reason::FILTERED === Request_State::decision()?->reason;
	}

	/**
	 * Whether the lookup of this request failed.
	 *
	 * A lookup that threw, or could not read the flush epoch of a cacheable miss, records an `error` decision and no
	 * epoch. No lookup at all is not a failure either, but it records no epoch too, so it is told apart by the epoch
	 * being null (`no_lookup`), not by this check.
	 *
	 * @return bool
	 */
	private static function lookup_failed(): bool {
		return Request_State::lookup_done() && Bypass_Reason::ERROR === Request_State::decision()?->reason;
	}

	/**
	 * Forget the stashed request.
	 *
	 * @return void
	 */
	private function discard(): void {
		$this->request  = null;
		$this->response = null;
		$this->stash    = null;
	}

	/**
	 * Join a header name list the way core does.
	 *
	 * @param mixed $headers Header names.
	 *
	 * @return string|null Null when the input is not an array.
	 */
	private static function join( mixed $headers ): ?string {
		if ( ! is_array( $headers ) ) {
			return null;
		}
		return implode( ', ', array_filter( $headers, 'is_string' ) );
	}
}
