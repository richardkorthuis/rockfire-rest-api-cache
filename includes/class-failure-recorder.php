<?php
/**
 * Records engine failures that would otherwise stay silent.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the last failure of the engine in the non-autoloaded `rfrac_last_error` option.
 *
 * The engine degrades silently by design (a failing lookup, capture or recompile leaves the request
 * uncached), so this makes repeated failures visible to the Status and Site Health screens.
 * The option holds `{context, message, time, count, subject, clearable, cleared}`: the context names
 * where it failed (e.g. `lookup`, `capture`, `compile`, `flush`, `migration`, `hook:<name>`), the
 * subject what it failed on (the route for the serving contexts, '' when it is not specific to one),
 * `count` is the number of recorded failures. At most one write per minute; the message is
 * truncated. With `WP_DEBUG_LOG` every failure is also written to the PHP error log.
 *
 * A failure is cleared once the same thing succeeds again ({@see self::clear()}: a stored response
 * clears the serving contexts of its own route, a recompile clears `compile`) or when an
 * administrator dismisses it ({@see self::dismiss()}). A failure recorded as not clearable (a tag
 * limit overflow, which a later success elsewhere does not fix) is only dismissed. Clearing keeps
 * the time of the failure in the option (`cleared` is set), so it never resets the throttle: a
 * failure that keeps coming back is written at most once a minute however often it is cleared.
 *
 * Related options: `rfrac_migration_error` ({@see Schema\Migrator::ERROR_OPTION}) and
 * `rfrac_mu_notice` ({@see Install\Mu_Installer::OPTION_NOTICE}).
 *
 * Stateless and safe in the must-use phase; it never throws.
 *
 * @since 1.0.0
 */
final class Failure_Recorder {

	/**
	 * Non-autoloaded option holding the last failure.
	 *
	 * @var string
	 */
	public const OPTION = 'rfrac_last_error';

	/**
	 * Minimum number of seconds between two writes of the option.
	 *
	 * @var int
	 */
	public const THROTTLE = 60;

	/**
	 * Maximum stored message length in bytes.
	 *
	 * @var int
	 */
	private const MAX_MESSAGE_BYTES = 500;

	/**
	 * Record a failure.
	 *
	 * @param string            $context   Where it failed.
	 * @param \Throwable|string $error     The exception or a message.
	 * @param string            $subject   What it failed on, e.g. the route; '' when not specific to one.
	 * @param bool              $clearable Whether a later success may clear it ({@see self::clear()}).
	 *
	 * @return void
	 */
	public static function record( string $context, \Throwable|string $error, string $subject = '', bool $clearable = true ): void {
		try {
			$message = $error instanceof \Throwable ? get_class( $error ) . ': ' . $error->getMessage() : $error;
			$message = self::truncate( str_replace( array( "\r", "\n" ), ' ', $message ), self::MAX_MESSAGE_BYTES );

			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'Rockfire – REST API Cache [' . $context . ']: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only with WP_DEBUG_LOG.
			}

			$now    = time();
			$stored = get_option( self::OPTION, null );
			$count  = 0;
			if ( is_array( $stored ) ) {
				// Also for a cleared failure: clearing never resets the throttle.
				if ( isset( $stored['time'] ) && $now - (int) $stored['time'] < self::THROTTLE ) {
					return;
				}
				$count = (int) ( $stored['count'] ?? 0 );
			}

			update_option(
				self::OPTION,
				array(
					'context'   => $context,
					'message'   => $message,
					'time'      => $now,
					'count'     => $count + 1,
					'subject'   => self::truncate( $subject, self::MAX_MESSAGE_BYTES ),
					'clearable' => $clearable,
					'cleared'   => false,
				),
				false
			);
		} catch ( \Throwable $e ) {
			unset( $e ); // Recording must never add a failure of its own.
		}
	}

	/**
	 * Cut a text to at most the given number of bytes without leaving a partial UTF-8 sequence at the end.
	 *
	 * A sequence cut in half is invalid UTF-8, which `esc_html()` turns into an empty string. Pure byte
	 * arithmetic (no `mb_*`, which the must-use phase cannot rely on): the last lead byte within the final
	 * four bytes tells how long its sequence should be, and a sequence that does not fit is dropped.
	 *
	 * @param string $text      Text to cut.
	 * @param int    $max_bytes Maximum length in bytes.
	 *
	 * @return string
	 */
	private static function truncate( string $text, int $max_bytes ): string {
		if ( strlen( $text ) <= $max_bytes ) {
			return $text;
		}

		$cut      = substr( $text, 0, $max_bytes );
		$length   = strlen( $cut );
		$lead_pos = $length - 1;
		$lowest   = max( 0, $length - 4 );

		// Scan back over at most the last 4 bytes for the last byte that is not a continuation byte.
		while ( $lead_pos >= $lowest && ( ord( $cut[ $lead_pos ] ) & 0xC0 ) === 0x80 ) {
			--$lead_pos;
		}
		if ( $lead_pos < $lowest ) {
			return $cut; // Only continuation bytes: not a sequence cut by this limit.
		}

		$lead = ord( $cut[ $lead_pos ] );
		if ( $lead < 0x80 ) {
			$expected = 1;
		} elseif ( $lead >= 0xC0 && $lead <= 0xDF ) {
			$expected = 2;
		} elseif ( $lead >= 0xE0 && $lead <= 0xEF ) {
			$expected = 3;
		} elseif ( $lead >= 0xF0 && $lead <= 0xF7 ) {
			$expected = 4;
		} else {
			$expected = 1;
		}

		return $lead_pos + $expected > $length ? substr( $cut, 0, $lead_pos ) : $cut;
	}

	/**
	 * Clear the recorded failure when the same thing succeeded again: its context is one of the given
	 * contexts and it was recorded for the given subject (or for no particular subject).
	 *
	 * Costs one read of the non-autoloaded option (cached for the rest of the request, and free with a
	 * persistent object cache) and writes only when there is a matching failure to clear.
	 *
	 * @param string[] $contexts Contexts that succeeded, e.g. `capture`.
	 * @param string   $subject  What they succeeded on, e.g. the route.
	 *
	 * @return void
	 */
	public static function clear( array $contexts, string $subject = '' ): void {
		try {
			$stored = get_option( self::OPTION, null );
			if (
				! is_array( $stored )
				|| ! empty( $stored['cleared'] )
				|| false === ( $stored['clearable'] ?? true )
				|| ! in_array( (string) ( $stored['context'] ?? '' ), $contexts, true )
			) {
				return;
			}
			$stored_subject = (string) ( $stored['subject'] ?? '' );
			if ( '' !== $stored_subject && $subject !== $stored_subject ) {
				return;
			}
			self::mark_cleared( $stored );
		} catch ( \Throwable $e ) {
			unset( $e ); // Clearing must never add a failure of its own.
		}
	}

	/**
	 * Hide the recorded failure, whatever its context (an administrator dismissed it).
	 *
	 * @return void
	 */
	public static function dismiss(): void {
		$stored = get_option( self::OPTION, null );
		if ( is_array( $stored ) && empty( $stored['cleared'] ) ) {
			self::mark_cleared( $stored );
		}
	}

	/**
	 * Mark a stored failure cleared, keeping its time (the throttle) and count.
	 *
	 * @param array<mixed> $stored Stored failure.
	 *
	 * @return void
	 */
	private static function mark_cleared( array $stored ): void {
		$stored['cleared'] = true;
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * The last recorded failure that was not cleared.
	 *
	 * @return array{context:string,message:string,time:int,count:int}|null
	 */
	public static function last(): ?array {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) || ! isset( $stored['context'], $stored['message'], $stored['time'] ) || ! empty( $stored['cleared'] ) ) {
			return null;
		}
		return array(
			'context' => (string) $stored['context'],
			'message' => (string) $stored['message'],
			'time'    => (int) $stored['time'],
			'count'   => (int) ( $stored['count'] ?? 1 ),
		);
	}
}
