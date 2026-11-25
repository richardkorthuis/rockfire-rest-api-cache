<?php
/**
 * Filter, sort and paging parameters of an entry listing.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable, already clamped listing query.
 *
 * @since 1.0.0
 */
final class Entry_Query {

	/**
	 * Allowed `state` values.
	 *
	 * @var string[]
	 */
	public const STATES = [ 'all', 'active', 'flushed', 'expired' ];

	/**
	 * Allowed `orderby` values.
	 *
	 * @var string[]
	 */
	public const ORDERBY = [ 'id', 'route', 'method', 'size', 'hits', 'created_at', 'expires_at', 'last_hit_at' ];

	/**
	 * Maximum length of the search string.
	 *
	 * @var int
	 */
	public const MAX_SEARCH = 200;

	/**
	 * Maximum length of the route prefix, rule key and tag filters.
	 *
	 * @var int
	 */
	public const MAX_FILTER = 191;

	/**
	 * Default page size.
	 *
	 * @var int
	 */
	public const DEFAULT_PER_PAGE = 20;

	/**
	 * Maximum page size.
	 *
	 * @var int
	 */
	public const MAX_PER_PAGE = 500;

	/**
	 * Highest page number; keeps the row offset a small integer (no float overflow).
	 *
	 * @var int
	 */
	public const MAX_PAGE = 1000000;

	/**
	 * Page size, 1 to {@see self::MAX_PER_PAGE}.
	 *
	 * @var int
	 */
	public readonly int $per_page;

	/**
	 * Page number, 1 to {@see self::MAX_PAGE}.
	 *
	 * @var int
	 */
	public readonly int $page;

	/**
	 * Constructor; use {@see self::from_array()} for untrusted input. Page size and page number are
	 * clamped here, so the LIMIT and the OFFSET of a listing always use the same, bounded values.
	 *
	 * @param string $search       Text the route or request must contain.
	 * @param string $state        One of {@see self::STATES}.
	 * @param string $route_prefix Route (or route subtree) filter.
	 * @param string $rule_key     Rule key filter.
	 * @param string $tag          Tag filter.
	 * @param string $orderby      One of {@see self::ORDERBY}.
	 * @param string $order        `asc` or `desc`.
	 * @param int    $per_page     Page size, clamped to 1 to {@see self::MAX_PER_PAGE}.
	 * @param int    $page         Page number, clamped to 1 to {@see self::MAX_PAGE}.
	 */
	public function __construct(
		public readonly string $search = '',
		public readonly string $state = 'all',
		public readonly string $route_prefix = '',
		public readonly string $rule_key = '',
		public readonly string $tag = '',
		public readonly string $orderby = 'created_at',
		public readonly string $order = 'desc',
		int $per_page = self::DEFAULT_PER_PAGE,
		int $page = 1
	) {
		$this->per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$this->page     = max( 1, min( self::MAX_PAGE, $page ) );
	}

	/**
	 * Build a query from untrusted input, clamping every field to its allowed range.
	 *
	 * @param array<string,mixed> $args Raw arguments.
	 *
	 * @return self
	 */
	public static function from_array( array $args ): self {
		$state   = self::text( $args['state'] ?? '', 20 );
		$orderby = self::text( $args['orderby'] ?? '', 20 );
		$order   = strtolower( self::text( $args['order'] ?? '', 4 ) );

		$per_page = isset( $args['per_page'] ) && is_numeric( $args['per_page'] ) ? (int) $args['per_page'] : self::DEFAULT_PER_PAGE;
		$page     = isset( $args['page'] ) && is_numeric( $args['page'] ) ? (int) $args['page'] : 1;

		return new self(
			self::text( $args['search'] ?? '', self::MAX_SEARCH ),
			in_array( $state, self::STATES, true ) ? $state : 'all',
			self::text( $args['route_prefix'] ?? '', self::MAX_FILTER ),
			self::text( $args['rule_key'] ?? '', self::MAX_FILTER ),
			self::text( $args['tag'] ?? '', self::MAX_FILTER ),
			in_array( $orderby, self::ORDERBY, true ) ? $orderby : 'created_at',
			'asc' === $order ? 'asc' : 'desc',
			$per_page,
			$page
		);
	}

	/**
	 * Row offset of the requested page.
	 *
	 * @return int
	 */
	public function offset(): int {
		return ( $this->page - 1 ) * $this->per_page;
	}

	/**
	 * Trimmed string of at most the given length; non-scalars become an empty string.
	 *
	 * @param mixed $value  Raw value.
	 * @param int   $length Maximum length in characters.
	 *
	 * @return string
	 */
	private static function text( $value, int $length ): string {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return '';
		}
		return trim( mb_substr( trim( (string) $value ), 0, $length ) );
	}
}
