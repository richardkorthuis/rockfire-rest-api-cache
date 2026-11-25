<?php
/**
 * One machine-readable decision of an endpoint translation.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable record of what the {@see Endpoint_Translator} decided about one input entry.
 *
 * The note carries codes and data only, never text, so the caller decides how to word it.
 *
 * @since 1.0.0
 */
final class Translation_Note {

	/**
	 * A route got a rule that enables caching for it.
	 *
	 * @var string
	 */
	public const ENABLED = 'enabled';

	/**
	 * A route is cached by default already, so it needs no rule.
	 *
	 * @var string
	 */
	public const ALREADY_DEFAULT = 'already_default';

	/**
	 * A route got a rule that excludes it from caching.
	 *
	 * @var string
	 */
	public const EXCLUDED = 'excluded';

	/**
	 * The entry is a concrete path that only matched routes through their patterns.
	 *
	 * @var string
	 */
	public const CONCRETE_PATH = 'concrete_path';

	/**
	 * The entry is a pattern, which a registered route can not be matched with.
	 *
	 * @var string
	 */
	public const PATTERN = 'pattern';

	/**
	 * The entry matches no registered route.
	 *
	 * @var string
	 */
	public const NO_ROUTE = 'no_route';

	/**
	 * Routes the hard deny-list blocks; they never get a rule.
	 *
	 * @var string
	 */
	public const DENIED = 'denied';

	/**
	 * The entry is unusable (no namespace, or not a string).
	 *
	 * @var string
	 */
	public const INVALID_ENTRY = 'invalid_entry';

	/**
	 * Request headers were added to the cache key of routes.
	 *
	 * @var string
	 */
	public const VARY = 'vary';

	/**
	 * Header names that carry credentials and can never vary the cache.
	 *
	 * @var string
	 */
	public const VARY_FORBIDDEN = 'vary_forbidden';

	/**
	 * Header names that are not valid header names.
	 *
	 * @var string
	 */
	public const VARY_INVALID = 'vary_invalid';

	/**
	 * Header names left out because a rule holds only a limited number of them.
	 *
	 * @var string
	 */
	public const VARY_LIMIT = 'vary_limit';

	/**
	 * A rule failed validation and was not produced.
	 *
	 * @var string
	 */
	public const INVALID_RULE = 'invalid_rule';

	public const SOURCE_ALLOWED    = 'allowed';
	public const SOURCE_DISALLOWED = 'disallowed';
	public const SOURCE_HEADERS    = 'headers';

	/**
	 * Every note code.
	 *
	 * @var list<string>
	 */
	public const CODES = [
		self::ENABLED,
		self::ALREADY_DEFAULT,
		self::EXCLUDED,
		self::CONCRETE_PATH,
		self::PATTERN,
		self::NO_ROUTE,
		self::DENIED,
		self::INVALID_ENTRY,
		self::VARY,
		self::VARY_FORBIDDEN,
		self::VARY_INVALID,
		self::VARY_LIMIT,
		self::INVALID_RULE,
	];

	/**
	 * Every input the translator reads.
	 *
	 * @var list<string>
	 */
	public const SOURCES = [ self::SOURCE_ALLOWED, self::SOURCE_DISALLOWED, self::SOURCE_HEADERS ];

	/**
	 * Constructor.
	 *
	 * @param string   $code      One of {@see self::CODES}.
	 * @param string   $source    One of {@see self::SOURCES}: the input the entry came from.
	 * @param string   $namespace Namespace of the entry (without surrounding slashes).
	 * @param string   $endpoint  Endpoint of the entry (without surrounding slashes).
	 * @param string[] $routes    Route keys the note is about.
	 * @phpstan-param list<string> $routes
	 * @param string[] $values    Values the note is about, e.g. header names or validation error codes.
	 * @phpstan-param list<string> $values
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $source,
		public readonly string $namespace, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound -- Public field name mandated by the note schema.
		public readonly string $endpoint,
		public readonly array $routes = [],
		public readonly array $values = []
	) {
	}
}
