<?php
/**
 * Contract of the opt-in pass that decides, keys and transforms hits.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Serving;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Storage\Cached_Response;

defined( 'ABSPATH' ) || exit;

/**
 * A name-free injection point into the serving chain.
 *
 * {@see Lookup} (the early and the fallback path) and {@see Capture} call the same four methods, so a
 * request is decided, keyed and served identically on both paths and stored under the key it is looked
 * up with. Every method runs strictly after `Cache_Policy::evaluate()` passed the credential checks: a
 * request with credentials, a vary-cookie or any other bypass never reaches the filter set.
 *
 * No method may throw. Should one throw anyway, the serving chain catches it and records a bypass
 * (`error`) instead of serving.
 *
 * @since 1.0.0
 */
interface Hit_Filters_Interface {

	/**
	 * Adjust the decision of a cacheable request.
	 *
	 * Called only for a cacheable decision.
	 *
	 * @param Policy_Decision $decision Cacheable decision of the policy.
	 * @param Request_Context $context  Request context.
	 * @param Compiled_Config $config   Compiled config.
	 *
	 * @return Policy_Decision The same decision, or a bypass decision.
	 */
	public function decide( Policy_Decision $decision, Request_Context $context, Compiled_Config $config ): Policy_Decision;

	/**
	 * Adjust the cache key of a cacheable request.
	 *
	 * @param string          $key      Cache key built by the engine.
	 * @param Request_Context $context  Request context.
	 * @param Policy_Decision $decision Cacheable decision.
	 * @param Compiled_Config $config   Compiled config.
	 *
	 * @return string|null The key to look up and to store under; null bypasses the cache (`filtered`).
	 */
	public function key( string $key, Request_Context $context, Policy_Decision $decision, Compiled_Config $config ): ?string;

	/**
	 * Transform a hit before it is served.
	 *
	 * The returned response keeps the entry ID of the hit, which is the entry counted as hit. Its headers are
	 * still replayed through the allow-list and sanitised by the writer.
	 *
	 * @param Cached_Response $hit     The stored response.
	 * @param Request_Context $context Request context.
	 * @param Compiled_Config $config  Compiled config.
	 *
	 * @return Cached_Response|null The response to serve; null does not serve the hit (`filtered`).
	 */
	public function response( Cached_Response $hit, Request_Context $context, Compiled_Config $config ): ?Cached_Response;

	/**
	 * Whether a hit carries the `Access-Control-Allow-*` headers.
	 *
	 * @param Request_Context $context Request context.
	 * @param Compiled_Config $config  Compiled config.
	 *
	 * @return bool
	 */
	public function cors( Request_Context $context, Compiled_Config $config ): bool;
}
