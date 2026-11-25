<?php
/**
 * Holder of the services behind the public functions.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Invalidation;

defined( 'ABSPATH' ) || exit;

/**
 * The services the public `rfrac_*()` functions delegate to (see functions.php).
 *
 * The plugin registers one instance while it boots; `Api::get()` is null before that, which the
 * functions report as incorrect usage.
 *
 * @since 1.0.0
 */
final class Api {

	/**
	 * Explanation of the tag format, used in the notices of the public functions.
	 *
	 * @var string
	 */
	public const TAG_FORMAT_MESSAGE = 'Tags must look like "prefix:value": the prefix starts with a letter and has at most 32 letters, digits, _ or -; "post", "term", "term-ref", "user" and "comment" take a numeric ID, "list" a post type or taxonomy name; a custom value is printable ASCII without spaces; at most 191 bytes in total.';

	/**
	 * The registered instance.
	 *
	 * @var Api|null
	 */
	private static ?Api $instance = null;

	/**
	 * Invalidator.
	 *
	 * @var Invalidator
	 */
	private Invalidator $invalidator;

	/**
	 * Tag collector.
	 *
	 * @var Rest_Tag_Collector
	 */
	private Rest_Tag_Collector $collector;

	/**
	 * Constructor.
	 *
	 * @param Invalidator        $invalidator Flushes entries.
	 * @param Rest_Tag_Collector $collector   Collects the tags of the response being built.
	 */
	public function __construct( Invalidator $invalidator, Rest_Tag_Collector $collector ) {
		$this->invalidator = $invalidator;
		$this->collector   = $collector;
	}

	/**
	 * Make an instance the one the public functions use.
	 *
	 * @param Api $api The instance.
	 *
	 * @return void
	 */
	public static function register( Api $api ): void {
		self::$instance = $api;
	}

	/**
	 * The registered instance.
	 *
	 * @return Api|null Null before the plugin booted.
	 */
	public static function get(): ?Api {
		return self::$instance;
	}

	/**
	 * Forget the registered instance (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * The invalidator.
	 *
	 * @return Invalidator
	 */
	public function invalidator(): Invalidator {
		return $this->invalidator;
	}

	/**
	 * The tag collector.
	 *
	 * @return Rest_Tag_Collector
	 */
	public function collector(): Rest_Tag_Collector {
		return $this->collector;
	}

	/**
	 * Normalise the tags a caller passed and say whether every one of them was valid.
	 *
	 * @param string|iterable<mixed> $tags A string of tags separated by commas and/or whitespace, or a list of tags.
	 *
	 * @return array{0:list<string>,1:bool} The valid tags (unique, sorted) and whether every input tag was valid.
	 */
	public static function parse_tags( string|iterable $tags ): array {
		$candidates = $tags;
		if ( is_string( $tags ) ) {
			$split      = preg_split( '/[\s,]+/', $tags, -1, PREG_SPLIT_NO_EMPTY );
			$candidates = false === $split ? array() : $split;
		}
		$all_valid = true;
		foreach ( $candidates as $candidate ) {
			if ( null === Tags::normalize( $candidate ) ) {
				$all_valid = false;
				break;
			}
		}
		return array( Tags::normalize_many( $tags ), $all_valid );
	}
}
