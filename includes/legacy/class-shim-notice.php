<?php
/**
 * Developer notices of the shims for the original plugin's public classes.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

defined( 'ABSPATH' ) || exit;

/**
 * Raises the notices the shims share: a call that still works but has a replacement, and a call the shim
 * cannot honour.
 *
 * Each method raises at most one notice per request, however often the shim is called. The notices are plain
 * developer strings, not translated; the names of the original appear only in the `$method` argument.
 *
 * A call the shim cannot honour is also stored as a finding of the classes group, so the import page and Site
 * Health name the calls that no longer behave as before. The finding shares the memo of the notice, so a method
 * costs at most one read of the store per request, and the store itself writes nothing for a method it already
 * holds. Its message is translated and names the method as `%s`; the subject of the finding carries the method as
 * `Short_Class::method`. A call that still works is never stored.
 *
 * Some methods are unsupported only for some arguments or contexts, and work on their other branches. The message
 * therefore does not claim the whole method is dead: it says that a call had no effect and appends the reason of
 * that call, the sentence the shim passed to {@see self::unsupported()}, as `%s`. The reason is a developer
 * sentence in English, exactly as the notice shows it, so the translated message wraps it without translating it.
 * The store identifies a finding by its code and subject, so a method holds one finding: the condition that was
 * met first stays, and a later call under another condition adds nothing.
 *
 * @since 1.0.0
 */
final class Shim_Notice {

	/**
	 * Version the notices are reported for.
	 *
	 * @var string
	 */
	public const VERSION = '1.0.0';

	/**
	 * Sentence that closes every notice about an unsupported call.
	 *
	 * @var string
	 */
	private const MIGRATION_HINT = 'See the migration section of the plugin readme.';

	/**
	 * The notices raised in this request, by kind and method.
	 *
	 * @var array<string,true>
	 */
	private static array $raised = array();

	/**
	 * Report a call that still works but is replaced by a public function of the plugin.
	 *
	 * @param string $method      The called method of the original, e.g. `Class::method`.
	 * @param string $replacement The replacement to use instead, e.g. `rfrac_flush_tag()`.
	 *
	 * @return void
	 */
	public static function deprecated( string $method, string $replacement ): void {
		if ( ! self::first( 'deprecated', $method ) ) {
			return;
		}

		_deprecated_function( esc_html( $method ), esc_html( self::VERSION ), esc_html( $replacement ) );
	}

	/**
	 * Report a call the shim cannot honour, and store it as a finding of the classes group.
	 *
	 * @param string $method The called method of the original, e.g. `Class::method`.
	 * @param string $reason Why the call has no effect, as a sentence.
	 *
	 * @return void
	 */
	public static function unsupported( string $method, string $reason ): void {
		if ( ! self::first( 'unsupported', $method ) ) {
			return;
		}

		self::record( $method, $reason );

		_doing_it_wrong( esc_html( $method ), esc_html( $reason . ' ' . self::MIGRATION_HINT ), esc_html( self::VERSION ) );
	}

	/**
	 * Forget the notices raised so far (intended for tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$raised = array();
	}

	/**
	 * Store an unsupported call as a finding of the classes group.
	 *
	 * The message carries the reason of this call, because a method can be unsupported for some calls only. The
	 * store adds nothing when the group already holds a finding for the same method, whatever its reason.
	 *
	 * @param string $method The called method of the original, e.g. `Class::method`.
	 * @param string $reason Why the call has no effect, as a sentence.
	 *
	 * @return void
	 */
	private static function record( string $method, string $reason ): void {
		$subject = self::subject( $method );

		( new Findings() )->add(
			Findings::GROUP_CLASSES,
			new Finding(
				Finding::UNSUPPORTED,
				'class_call',
				$subject,
				sprintf(
					/* translators: 1: called method of WP REST Cache, as Class::method. 2: why the call had no effect, a sentence in English. */
					__( 'A call to %1$s had no effect: %2$s', 'rockfire-rest-api-cache' ),
					$subject,
					$reason
				)
			)
		);
	}

	/**
	 * The called method as `Short_Class::method`: the class without its namespace.
	 *
	 * @param string $method The called method of the original, e.g. `Name\Space\Class::method`.
	 *
	 * @return string
	 */
	private static function subject( string $method ): string {
		$separator = strpos( $method, '::' );
		if ( false === $separator ) {
			return $method;
		}

		$class = substr( $method, 0, $separator );
		$slash = strrpos( $class, '\\' );

		return ( false === $slash ? $class : substr( $class, $slash + 1 ) ) . substr( $method, $separator );
	}

	/**
	 * Record a notice and tell whether it is the first of its kind for the method in this request.
	 *
	 * @param string $kind   The notice kind.
	 * @param string $method The method of the original.
	 *
	 * @return bool
	 */
	private static function first( string $kind, string $method ): bool {
		$key = $kind . ':' . $method;
		if ( isset( self::$raised[ $key ] ) ) {
			return false;
		}

		self::$raised[ $key ] = true;
		return true;
	}
}
