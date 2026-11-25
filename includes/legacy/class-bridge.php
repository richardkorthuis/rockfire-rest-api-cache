<?php
/**
 * Fires a hook of the original plugin as a deprecated hook.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Failure_Recorder;

defined( 'ABSPATH' ) || exit;

/**
 * The one helper every hook bridge uses to hand a value or an event to the callbacks of the original's hooks.
 *
 * A bridge fires the legacy hook through `apply_filters_deprecated()` or `do_action_deprecated()`, so site code that
 * still hooks it keeps working and WordPress reports the replacement (the Rockfire hook of {@see Hook_Registry}) when
 * a callback is attached. A callback that throws never reaches the engine: the failure is recorded under the name
 * of the legacy hook (`hook:<name>`) and a filter hands back the value it was given, so the Rockfire value stays
 * unchanged.
 *
 * Only the hooks the registry bridges can be fired; any other name is a wiring mistake.
 *
 * @since 1.0.0
 */
final class Bridge {

	/**
	 * Run the callbacks of a legacy filter.
	 *
	 * @since 1.0.0
	 *
	 * @param string           $name The full name of the legacy hook.
	 * @param array<int,mixed> $args The arguments of the legacy hook; the first one is the value to filter.
	 *
	 * @phpstan-param non-empty-string $name
	 *
	 * @return mixed The filtered value; the first argument unchanged when no callback is attached or a callback throws.
	 *
	 * @throws \InvalidArgumentException When the hook is not bridged.
	 */
	public static function filter( string $name, array $args ): mixed {
		$replacement = self::replacement( $name );
		if ( false === has_filter( $name ) ) {
			return $args[0] ?? null;
		}

		try {
			return apply_filters_deprecated( $name, $args, Hook_Registry::DEPRECATED_SINCE, $replacement, self::message() );
		} catch ( \Throwable $e ) {
			Failure_Recorder::record( 'hook:' . $name, $e );
			return $args[0] ?? null;
		}
	}

	/**
	 * Run the callbacks of a legacy action.
	 *
	 * @since 1.0.0
	 *
	 * @param string           $name The full name of the legacy hook.
	 * @param array<int,mixed> $args The arguments of the legacy hook.
	 *
	 * @phpstan-param non-empty-string $name
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the hook is not bridged.
	 */
	public static function action( string $name, array $args ): void {
		$replacement = self::replacement( $name );
		if ( false === has_action( $name ) ) {
			return;
		}

		try {
			do_action_deprecated( $name, $args, Hook_Registry::DEPRECATED_SINCE, $replacement, self::message() );
		} catch ( \Throwable $e ) {
			Failure_Recorder::record( 'hook:' . $name, $e );
		}
	}

	/**
	 * The Rockfire hook that replaces a bridged legacy hook.
	 *
	 * @param string $name The full name of the legacy hook; a concrete name of a dynamic hook is accepted.
	 *
	 * @return string The replacement, `''` when the hook is bridged without one.
	 *
	 * @throws \InvalidArgumentException When the hook is not bridged.
	 */
	private static function replacement( string $name ): string {
		$replacement = Hook_Registry::replacement( $name );
		if ( null === $replacement ) {
			throw new \InvalidArgumentException( sprintf( 'The hook %s is not bridged.', esc_html( $name ) ) );
		}
		return $replacement;
	}

	/**
	 * The sentence that follows the deprecation notice.
	 *
	 * @return string
	 */
	private static function message(): string {
		return __( 'Rockfire – REST API Cache bridges this hook; see the migration section of its readme.', 'rockfire-rest-api-cache' );
	}
}
