<?php
/**
 * Folds the legacy hit filters that do not depend on the request into the compiled configuration.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the compile-time filters of the engine from the callbacks of the legacy filters whose answer does not
 * depend on the request: `allow_skip_cache`, `disable_cors_headers`, a constant `skip_caching` and a constant
 * `skip_nonce_caching`.
 *
 * Each answer is compiled into the snapshot, so it applies on the early and on the fallback path alike and a hit
 * fires no legacy hook. The filters that depend on the request (`cache_key`, `filter_cache_output`,
 * `pre_output_headers`, and a `skip_caching` callback that is not constant) are not folded: they run on a hit only
 * with `RFRAC_LEGACY_HIT_FILTERS` ({@see Hit_Filter_Pass}), and {@see Hook_Findings} reports them otherwise. A
 * `skip_nonce_caching` callback that is not constant is not carried over at all (it would need the nonce of a
 * request, which a hit never verifies), and {@see Hook_Findings} reports it.
 *
 * Every legacy callback is run through {@see Bridge::filter()}, so the callbacks see the deprecation notice of the
 * hook and a throwing callback leaves the engine's value unchanged.
 *
 * @since 1.0.0
 */
final class Compile_Folding {

	use Hook_Subscriber_Trait;

	/**
	 * The callbacks of core that answer the same without looking at the request.
	 *
	 * @var list<string>
	 */
	public const CONSTANT_CALLBACKS = array(
		'__return_true',
		'__return_false',
		'__return_null',
		'__return_zero',
		'__return_empty_string',
		'__return_empty_array',
	);

	/**
	 * The legacy filter that makes every request bypass the cache.
	 *
	 * @var string
	 */
	public const SKIP_CACHING = Hook_Registry::PREFIX . 'skip_caching';

	/**
	 * The legacy filter that says whether a request that carries a nonce skips the cache.
	 *
	 * @var string
	 */
	public const SKIP_NONCE_CACHING = Hook_Registry::PREFIX . 'skip_nonce_caching';

	/**
	 * The hooks of this component.
	 *
	 * @return array<int,array<int,mixed>> `[ hook, method, priority, accepted args ]` entries.
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		return array(
			array( Hooks::ALLOW_SKIP_CACHE, 'allow_skip_cache', 10, 1 ),
			array( Hooks::CORS_ON_HITS, 'cors', 10, 1 ),
			array( Hooks::BYPASS_ALL, 'bypass_all', 10, 1 ),
			array( Hooks::NONCE_CACHING, 'nonce_caching', 10, 2 ),
		);
	}

	/**
	 * Whether `skip_cache` is honoured: the value of the engine, filtered by `allow_skip_cache` of the original.
	 *
	 * Not reached when the `RFRAC_ALLOW_SKIP_CACHE` constant is defined, because the compiler then does not apply the
	 * filter.
	 *
	 * @param mixed $allow The value of the engine.
	 *
	 * @return bool
	 */
	public function allow_skip_cache( mixed $allow ): bool {
		return (bool) Bridge::filter( Hook_Registry::PREFIX . 'allow_skip_cache', array( (bool) $allow ) );
	}

	/**
	 * Whether a hit carries the CORS headers: the value of the engine, unless `disable_cors_headers` of the original
	 * switches them off.
	 *
	 * The original's filter says whether to disable the headers, so it starts from the opposite of the engine's value
	 * and only the strict answer `true` disables them. It is only asked while the engine sends the headers, so it
	 * always starts from `false`.
	 *
	 * @param mixed $send The value of the engine.
	 *
	 * @return bool
	 */
	public function cors( mixed $send ): bool {
		return $send && true !== Bridge::filter( Hook_Registry::PREFIX . 'disable_cors_headers', array( false ) );
	}

	/**
	 * Whether every request bypasses the cache: the value of the engine, or a truthy constant `skip_caching` of the original.
	 *
	 * A `skip_caching` callback that is not constant may depend on the request, so it is not folded.
	 *
	 * @param mixed $bypass The value of the engine.
	 *
	 * @return mixed
	 */
	public function bypass_all( mixed $bypass ): mixed {
		if ( ! self::is_constant( self::SKIP_CACHING ) ) {
			return $bypass;
		}

		return $bypass || (bool) Bridge::filter( self::SKIP_CACHING, array( false ) );
	}

	/**
	 * Whether a logged-out request that only carries a nonce may be cached: the value of the engine, or a constant
	 * `skip_nonce_caching` of the original that does not skip, while Rockfire has not decided.
	 *
	 * Only a value that still comes from the `default` source is folded; a setting or a constant of Rockfire has
	 * decided, and the legacy filter is not asked. The original's filter says whether to skip, and it starts from
	 * `true` (skip), so a callback that answers a falsy value switches nonce caching on. A callback that is not
	 * constant may depend on the request, so it is not carried over, and a callback that throws leaves the flag off.
	 *
	 * @param mixed $enabled The value of the engine.
	 * @param mixed $source  Where the value comes from: `constant`, `setting` or `default`.
	 *
	 * @return mixed
	 */
	public function nonce_caching( mixed $enabled, mixed $source = null ): mixed {
		if ( 'default' !== $source || ! self::is_constant( self::SKIP_NONCE_CACHING ) ) {
			return $enabled;
		}

		return $enabled || ! (bool) Bridge::filter( self::SKIP_NONCE_CACHING, array( true ) );
	}

	/**
	 * Whether a hook is hooked and every one of its callbacks answers the same on every request.
	 *
	 * Only the named callbacks of {@see self::CONSTANT_CALLBACKS} count; a closure or a method is never constant.
	 *
	 * @param string $hook Full name of the hook.
	 *
	 * @return bool
	 */
	public static function is_constant( string $hook ): bool {
		$callbacks = Callback_Locator::callbacks( $hook );
		if ( array() === $callbacks ) {
			return false;
		}

		foreach ( $callbacks as $callback ) {
			if ( ! is_string( $callback ) || ! in_array( $callback, self::CONSTANT_CALLBACKS, true ) ) {
				return false;
			}
		}
		return true;
	}
}
