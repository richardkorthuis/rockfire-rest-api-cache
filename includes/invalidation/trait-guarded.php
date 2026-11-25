<?php
/**
 * Shared failure handling of the invalidation event listeners.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Invalidation;

use Rockfire\Rest_Api_Cache\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Listeners never throw into WordPress core hooks: a failure is reported and swallowed.
 *
 * @since 1.0.0
 */
trait Guarded_Trait {

	/**
	 * Report a failure without rethrowing it.
	 *
	 * With `WP_DEBUG` on, the exception class, message, file:line, WordPress hook and invalidation event are logged.
	 * Always fires {@see Hooks::INVALIDATION_FAILED}; an exception thrown by one of its listeners is ignored.
	 *
	 * @param \Throwable          $error   The failure.
	 * @param string              $event   Invalidation event (an `Invalidator::EVENT_*` constant), or '' when unknown.
	 * @param array<string,mixed> $context Extra details, e.g. `blog_id` and `tags`.
	 *
	 * @return void
	 */
	protected function swallow( \Throwable $error, string $event = '', array $context = array() ): void {
		$hook    = (string) current_filter();
		$context = array_merge(
			array(
				'hook'  => $hook,
				'event' => $event,
			),
			$context
		);

		$hook = is_string( $context['hook'] ) ? $context['hook'] : '';
		try {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug mode only.
					sprintf(
						'rfrac invalidation failed (hook: %s, event: %s): %s: %s in %s:%d',
						'' === $hook ? '-' : $hook,
						'' === $event ? '-' : $event,
						get_class( $error ),
						$error->getMessage(),
						$error->getFile(),
						$error->getLine()
					)
				);
			}
			/**
			 * Fires when cache invalidation failed.
			 *
			 * @see Hooks::INVALIDATION_FAILED
			 */
			do_action( Hooks::INVALIDATION_FAILED, $error, $context ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		} catch ( \Throwable $ignored ) {
			// Reporting must never throw into WordPress.
			unset( $ignored );
		}
	}

	/**
	 * Fire {@see Hooks::FLUSHED}; a listener exception is reported, never propagated.
	 *
	 * @param string   $type     `tags`, `url`, `route`, `all` or `entries`.
	 * @param string[] $targets  What was flushed.
	 * @param int      $affected Entries flushed.
	 *
	 * @return void
	 */
	protected function fire_flushed( string $type, array $targets, int $affected ): void {
		try {
			/**
			 * Fires after cache entries were flushed.
			 *
			 * @see Hooks::FLUSHED
			 */
			do_action( Hooks::FLUSHED, $type, $targets, $affected ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		} catch ( \Throwable $error ) {
			$this->swallow( $error, '', array( 'listener' => Hooks::FLUSHED ) );
		}
	}

	/**
	 * Build a tag list, dropping tags the builders reject.
	 *
	 * @param string|null ...$tags Tags from the Tags builders.
	 *
	 * @return string[]
	 *
	 * @phpstan-return list<string>
	 */
	protected function tags( ?string ...$tags ): array {
		return array_values( array_filter( $tags, static fn( ?string $tag ): bool => null !== $tag ) );
	}
}
