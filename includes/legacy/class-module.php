<?php
/**
 * The legacy hook-bridge module.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the bridges of the hooks of the original plugin into the engine, whenever the coexistence guard does not block.
 *
 * The container creates it from {@see Plugin::register_legacy_hooks()} and keeps its hooks in a list of their own
 * ({@see Plugin::legacy_hooks()}), apart from the engine hooks.
 *
 * The rule for every component is the one of the WooCommerce module: it adds hooks only through the `$on` callable
 * it is handed (the guarded, recorded hook helper of the container), never calls `add_action()` or `add_filter()`
 * itself, and fires a legacy hook only through {@see Bridge}.
 *
 * @since 1.0.0
 */
final class Module {

	/**
	 * The container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Adds a guarded hook: `fn( string $hook, \Closure $callback, int $priority = 10, int $args = 1 ): void`.
	 *
	 * @var \Closure
	 * @phpstan-var \Closure(string, \Closure, int=, int=): void
	 */
	private \Closure $on;

	/**
	 * Create the module.
	 *
	 * @param Plugin   $plugin The container.
	 * @param \Closure $on     Adds a guarded hook; forwards to the container's hook helper.
	 *
	 * @phpstan-param \Closure(string, \Closure, int=, int=): void $on
	 */
	public function __construct( Plugin $plugin, \Closure $on ) {
		$this->plugin = $plugin;
		$this->on     = $on;
	}

	/**
	 * The container the module is wired into.
	 *
	 * @return Plugin
	 */
	public function plugin(): Plugin {
		return $this->plugin;
	}

	/**
	 * Register every component of the module.
	 *
	 * Each component has one private `register_*()` method, called from here.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_endpoint_bridge();
		$this->register_store_bridge();
		$this->register_invalidation_bridge();
		$this->register_compile_folding();
		$this->register_findings();
	}

	/**
	 * The endpoint bridge: the legacy endpoint filters answered by the route rules.
	 *
	 * {@see Endpoint_Bridge} answers `rfrac/route_rules` (at priority 1, so the site's own callbacks override it) and
	 * `rfrac/global_ignore_params`, and records what it could not translate once a compile is saved.
	 *
	 * @return void
	 */
	private function register_endpoint_bridge(): void {
		$this->subscribe( new Endpoint_Bridge( $this->plugin->config(), $this->plugin->legacy_findings() ) );
	}

	/**
	 * The store bridge: the legacy filters of storing a response (timeout, empty result sets, tag collection).
	 *
	 * The store decisions ride on the Rockfire store hooks ({@see Store_Bridge}); the object type and the relations of a
	 * response tag it while it is dispatched, and the collected item tags are reported back ({@see Tag_Bridge}).
	 *
	 * @return void
	 */
	private function register_store_bridge(): void {
		$this->subscribe( new Store_Bridge() );
		$this->subscribe( new Tag_Bridge( $this->plugin->tag_provider() ) );
	}

	/**
	 * The invalidation bridge: the legacy filters and actions of flushing entries.
	 *
	 * @return void
	 */
	private function register_invalidation_bridge(): void {
		$this->subscribe( new Invalidation_Bridge() );
	}

	/**
	 * The compile folding: the legacy filters whose answer is compiled into the configuration.
	 *
	 * @return void
	 */
	private function register_compile_folding(): void {
		$this->subscribe( new Compile_Folding() );
	}

	/**
	 * The findings: the legacy hooks that have a callback but are not bridged.
	 *
	 * @return void
	 */
	private function register_findings(): void {
		$this->subscribe(
			new Hook_Findings(
				$this->plugin->legacy_findings(),
				new Callback_Locator(),
				fn(): string => $this->plugin->mu_installer()->target_path()
			)
		);
	}

	/**
	 * Register the hooks a component declares in its static `hooks()` (the shape of {@see Hook_Subscriber_Trait::hooks()}).
	 *
	 * Every entry is added through `$on`, with the entry's bound arguments passed before the hook's own arguments.
	 * The component must itself use {@see Hook_Subscriber_Trait}, and all its entries are checked against the
	 * contract of the trait before the first one is registered.
	 *
	 * @param object $component The component.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the component does not use the trait or declares an entry that breaks its contract.
	 */
	public function subscribe( object $component ): void {
		foreach ( self::declared_hooks( $component ) as [ $hook, $method, $priority, $args, $bound ] ) {
			( $this->on )( $hook, fn( ...$params ) => $component->{$method}( ...$bound, ...$params ), $priority, $args );
		}
	}

	/**
	 * The hook entries a component declares, each one checked against the {@see Hook_Subscriber_Trait::hooks()} contract.
	 *
	 * A trait cannot be required by a type, and PHPStan does not compare the declared return type of a static method with
	 * the contract it implements, so the shape is verified here instead of being asserted.
	 *
	 * @param object $component The component.
	 *
	 * @return array<int,array<int,mixed>> `[ hook, method, priority, accepted args, bound args ]` entries.
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4:list<mixed>}>
	 *
	 * @throws \InvalidArgumentException When the component does not use the trait or declares an entry that breaks its contract.
	 */
	private static function declared_hooks( object $component ): array {
		$callable = array( $component, 'hooks' );
		if ( ! in_array( Hook_Subscriber_Trait::class, class_uses( $component ), true ) || ! is_callable( $callable ) ) {
			throw new \InvalidArgumentException( 'A legacy component must use Hook_Subscriber_Trait.' );
		}

		$declared = call_user_func( $callable );
		$declared = is_array( $declared ) ? $declared : array();
		$entries  = array();
		foreach ( $declared as $entry ) {
			$entry    = is_array( $entry ) ? $entry : array();
			$hook     = $entry[0] ?? null;
			$method   = $entry[1] ?? null;
			$priority = $entry[2] ?? null;
			$args     = $entry[3] ?? null;
			$bound    = $entry[4] ?? array();
			if ( ! is_string( $hook ) || ! is_string( $method ) || ! is_int( $priority ) || ! is_int( $args ) || ! is_array( $bound ) || ! array_is_list( $bound ) ) {
				throw new \InvalidArgumentException( sprintf( '%s::hooks() must return a list of [ hook, method, priority, accepted args, bound args? ] entries.', $component::class ) );
			}
			$entries[] = array( $hook, $method, $priority, $args, $bound );
		}

		return $entries;
	}
}
