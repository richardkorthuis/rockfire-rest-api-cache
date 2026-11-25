<?php
/**
 * Declarative hook list of a listener, shared by its own register() and the plugin container.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache;

defined( 'ABSPATH' ) || exit;

/**
 * A class using this trait declares its hooks in a static `hooks()` method:
 * `[ hook, public method, priority, accepted args ]`, optionally followed by a list of arguments
 * passed before the hook's own arguments.
 *
 * {@see self::register()} hooks the instance directly (array callables for plain entries) and throws
 * for an entry whose method is not callable, before it adds any hook. The plugin container registers
 * the same list through its guarded hook helper and resolves the instance only when a hook fires
 * ({@see Plugin::subscribe()}).
 *
 * @since 1.0.0
 */
trait Hook_Subscriber_Trait {

	/**
	 * The hooks of this listener.
	 *
	 * @return array<int,array<int,mixed>> `[ hook, method, priority, accepted args, bound args? ]` entries.
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4?:list<mixed>}>
	 */
	abstract public static function hooks(): array;

	/**
	 * Hook this instance to every hook of {@see static::hooks()}.
	 *
	 * Every entry is validated first, with or without bound arguments: an entry whose method is not
	 * callable on this instance is a wiring mistake (a typo or a renamed method) and throws before any
	 * hook is added, so a listener is never registered partly and none of its entries is skipped silently.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When an entry of `hooks()` names a method that is not callable.
	 */
	public function register(): void {
		$registrations = array();
		foreach ( static::hooks() as $hook ) {
			$method   = $hook[1];
			$callback = array( $this, $method );
			if ( ! is_callable( $callback ) ) {
				throw new \InvalidArgumentException( sprintf( 'The %1$s hook of %2$s names a method that is not callable: %3$s', esc_html( $hook[0] ), esc_html( static::class ), esc_html( $method ) ) );
			}
			$bound = $hook[4] ?? array();
			if ( array() !== $bound ) {
				$callback = fn( ...$params ) => $this->{$method}( ...$bound, ...$params );
			}
			$registrations[] = array( $hook[0], $callback, $hook[2], $hook[3] );
		}

		foreach ( $registrations as $registration ) {
			add_filter( $registration[0], $registration[1], $registration[2], $registration[3] );
		}
	}
}
