<?php
/**
 * Inspecting and invalidating cache entries.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Services;

use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Guarded_Trait;
use Rockfire\Rest_Api_Cache\Invalidation\Tags;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Maintenance\Maintenance;
use Rockfire\Rest_Api_Cache\Maintenance\Maintenance_Result;
use Rockfire\Rest_Api_Cache\Storage\Entry_Query;
use Rockfire\Rest_Api_Cache\Storage\Entry_Row;
use Rockfire\Rest_Api_Cache\Storage\Repository;
use Rockfire\Rest_Api_Cache\Storage\Store_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * The one service for listing, inspecting, flushing and deleting cache entries, shared by the admin
 * pages and WP-CLI. Flushes by tag, URL, route and everything go only through the public functions
 * (`rfrac_flush_*()`), so flush semantics and the `rfrac/flushed` hook stay single-sourced; flushes by
 * entry ID go through the store. It works on the current site of a multisite network.
 *
 * @since 1.0.0
 */
class Cache_Service {

	use Guarded_Trait;

	/**
	 * Maximum number of tags accepted by {@see self::flush_tag()}.
	 *
	 * @var int
	 */
	public const MAX_TAGS = 50;

	/**
	 * Maximum length of a URL accepted by {@see self::flush_url()} and `rfrac_flush_url()`.
	 *
	 * @var int
	 */
	public const MAX_URL_LENGTH = 2048;

	/**
	 * Maximum number of characters of rejected input echoed in an error message.
	 *
	 * @var int
	 */
	private const ECHO_LENGTH = 64;

	/**
	 * Entries repository.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Store, or a resolver of the store of the current site.
	 *
	 * @var Store_Interface|\Closure
	 * @phpstan-var Store_Interface|\Closure(): Store_Interface
	 */
	private Store_Interface|\Closure $store;

	/**
	 * Maintenance runner.
	 *
	 * @var Maintenance
	 */
	private Maintenance $maintenance;

	/**
	 * Constructor.
	 *
	 * @param Repository               $repository  Entries repository.
	 * @param Store_Interface|\Closure $store       Store, or a closure returning the store of the current site.
	 * @phpstan-param Store_Interface|\Closure(): Store_Interface $store
	 * @param Maintenance              $maintenance Maintenance runner.
	 */
	public function __construct( Repository $repository, Store_Interface|\Closure $store, Maintenance $maintenance ) {
		$this->repository  = $repository;
		$this->store       = $store;
		$this->maintenance = $maintenance;
	}

	/**
	 * Entries matching a query, one page, with the total over all pages.
	 *
	 * @param Entry_Query $query Filters, order and paging.
	 *
	 * @return array{items: Entry_Row[], total: int}
	 * @phpstan-return array{items: list<Entry_Row>, total: int}
	 */
	public function query( Entry_Query $query ): array {
		return array(
			'items' => $this->repository->query( $query ),
			'total' => $this->repository->count( $query ),
		);
	}

	/**
	 * One entry with its headers, tags and payload.
	 *
	 * @param int $id Entry id.
	 *
	 * @return array|null Null when the entry does not exist. Keys: `row` (Entry_Row), `headers`, `tags`,
	 *                    `payload` (string|null) and `state_label` (`active`, `flushed` or `expired`).
	 * @phpstan-return array{row: Entry_Row, headers: array<string,string>, tags: list<string>, payload: string|null, state_label: string}|null
	 */
	public function get( int $id ): ?array {
		$row = $id > 0 ? $this->repository->get( $id ) : null;
		if ( null === $row ) {
			return null;
		}
		return array(
			'row'         => $row,
			'headers'     => $this->repository->headers_for( $id ),
			'tags'        => $this->repository->tags_for( $id ),
			'payload'     => $this->store()->payload_for( $id ),
			'state_label' => $this->state_label( $row ),
		);
	}

	/**
	 * Flush entries by ID.
	 *
	 * Fires `rfrac/flushed` with the type `entries` and the IDs as strings, only when at least one entry was flushed.
	 *
	 * @param array $ids Entry IDs; anything but a positive integer or a string of digits is dropped.
	 * @phpstan-param array<mixed> $ids
	 *
	 * @return int Number of entries flushed.
	 */
	public function flush( array $ids ): int {
		$ids = $this->clean_ids( $ids );
		if ( array() === $ids ) {
			return 0;
		}
		$affected = $this->store()->flush_by_ids( $ids );
		if ( $affected > 0 ) {
			$this->fire_flushed( 'entries', array_map( 'strval', $ids ), $affected );
		}
		return $affected;
	}

	/**
	 * Delete entries (and their tags) by ID.
	 *
	 * @param array $ids Entry IDs; anything but a positive integer or a string of digits is dropped.
	 * @phpstan-param array<mixed> $ids
	 *
	 * @return int Number of entries deleted.
	 */
	public function delete( array $ids ): int {
		$ids = $this->clean_ids( $ids );
		return array() === $ids ? 0 : $this->store()->delete( $ids );
	}

	/**
	 * Flush every entry of the current site.
	 *
	 * @return int Number of entries flushed.
	 */
	public function flush_all(): int {
		return rfrac_flush_all();
	}

	/**
	 * Flush the entries carrying any of the given tags.
	 *
	 * @param string $tags Tags separated by commas or whitespace.
	 *
	 * @return int|\WP_Error Number of entries flushed; `rfrac_use_route_flush` for a `route:` tag,
	 *                       `rfrac_invalid_tag` for an invalid tag, no tag or more than 50 tags.
	 */
	public function flush_tag( string $tags ): int|\WP_Error {
		$valid = $this->parse_tags( $tags );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		return rfrac_flush_tag( $valid );
	}

	/**
	 * Validate and normalise a list of tags without flushing anything (site independent).
	 *
	 * @param string $tags Tags separated by commas or whitespace.
	 *
	 * @return string[]|\WP_Error The normalised tags; the same errors as {@see self::flush_tag()}.
	 * @phpstan-return list<string>|\WP_Error
	 */
	public function parse_tags( string $tags ): array|\WP_Error {
		$split = preg_split( '/[\s,]+/', $tags, -1, PREG_SPLIT_NO_EMPTY );
		$raw   = false === $split ? array() : $split;
		if ( array() === $raw ) {
			return new \WP_Error( 'rfrac_invalid_tag', __( 'Enter at least one tag.', 'rockfire-rest-api-cache' ) );
		}
		if ( count( $raw ) > self::MAX_TAGS ) {
			return new \WP_Error(
				'rfrac_invalid_tag',
				/* translators: %d: maximum number of tags */
				sprintf( __( 'Enter at most %d tags.', 'rockfire-rest-api-cache' ), self::MAX_TAGS )
			);
		}

		$valid = array();
		foreach ( $raw as $tag ) {
			if ( 0 === stripos( $tag, 'route:' ) ) {
				return new \WP_Error( 'rfrac_use_route_flush', __( 'Route tags cannot be flushed here; flush the route instead.', 'rockfire-rest-api-cache' ) );
			}
			$normalized = Tags::normalize( $tag );
			if ( null === $normalized ) {
				return new \WP_Error(
					'rfrac_invalid_tag',
					/* translators: %s: the rejected tag */
					sprintf( __( '"%s" is not a valid tag. Tags look like prefix:value.', 'rockfire-rest-api-cache' ), $this->echo_text( $tag ) )
				);
			}
			$valid[] = $normalized;
		}
		return $valid;
	}

	/**
	 * Flush the entries of one URL of this site.
	 *
	 * @param string $url Absolute http(s) URL of a REST route of this site (same host, under the home path).
	 *
	 * @return int|\WP_Error Number of entries flushed; `rfrac_invalid_url` for another scheme, host or
	 *                       path, a URL that is no REST route, or one longer than {@see self::MAX_URL_LENGTH}.
	 */
	public function flush_url( string $url ): int|\WP_Error {
		$url     = trim( $url );
		$invalid = new \WP_Error( 'rfrac_invalid_url', __( 'Enter a full http(s) URL of a REST route on this site.', 'rockfire-rest-api-cache' ) );
		if ( '' === $url || strlen( $url ) > self::MAX_URL_LENGTH ) {
			return $invalid;
		}
		$parts  = wp_parse_url( $url );
		$scheme = is_array( $parts ) && isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$home   = wp_parse_url( home_url() );
		$home   = is_array( $home ) ? $home : array();
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || ! isset( $home['host'] ) || strtolower( $home['host'] ) !== $host ) {
			return $invalid;
		}

		// On a subdirectory network the other sites live beside this one, under their own path.
		$home_path = untrailingslashit( isset( $home['path'] ) ? $home['path'] : '' );
		$path      = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		if ( '' !== $home_path && $path !== $home_path && ! str_starts_with( $path, $home_path . '/' ) ) {
			return $invalid;
		}

		if ( null === Request_Context::from_url( $url, rest_get_url_prefix() )->route ) {
			return $invalid;
		}
		return rfrac_flush_url( $url );
	}

	/**
	 * Flush the entries of a route and everything below it (the caller resolves a route ID through the route service).
	 *
	 * @param string $route Route pattern or path; surrounding slashes are optional and `/` is the index route.
	 *
	 * @return int|\WP_Error Number of entries flushed; `rfrac_invalid_route` for an empty route.
	 */
	public function flush_route( string $route ): int|\WP_Error {
		if ( '' === trim( $route ) ) {
			return new \WP_Error( 'rfrac_invalid_route', __( 'Enter a route.', 'rockfire-rest-api-cache' ) );
		}
		return rfrac_flush_route( $route );
	}

	/**
	 * Cache statistics of the current site.
	 *
	 * @return array{active: int, flushed: int, expired: int, total: int, size: int}
	 */
	public function stats(): array {
		$stats = $this->repository->stats();
		return array(
			'active'  => $stats->active,
			'flushed' => $stats->flushed,
			'expired' => $stats->expired,
			'total'   => $stats->total,
			'size'    => $stats->total_bytes,
		);
	}

	/**
	 * Run one bounded maintenance pass.
	 *
	 * @param string $context Who runs it: `tools` (admin) or `cli`.
	 *
	 * @return Maintenance_Result
	 */
	public function run_maintenance( string $context ): Maintenance_Result {
		return $this->maintenance->run( array(), $context );
	}

	/**
	 * The store of the current site.
	 *
	 * @return Store_Interface
	 */
	private function store(): Store_Interface {
		return $this->store instanceof \Closure ? ( $this->store )() : $this->store;
	}

	/**
	 * Unique positive integers of a list: integers above zero and strings of digits only (no signs, floats or trailing text).
	 *
	 * @param array $ids Raw IDs.
	 * @phpstan-param array<mixed> $ids
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	private function clean_ids( array $ids ): array {
		$clean = array();
		foreach ( $ids as $id ) {
			if ( is_string( $id ) && 1 === preg_match( '/^\d{1,18}$/D', $id ) ) {
				$id = (int) $id;
			}
			if ( is_int( $id ) && $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}
		return array_values( $clean );
	}

	/**
	 * Raw input shortened for an error message.
	 *
	 * @param string $text Input.
	 *
	 * @return string
	 */
	private function echo_text( string $text ): string {
		return mb_strlen( $text ) > self::ECHO_LENGTH ? mb_substr( $text, 0, self::ECHO_LENGTH ) . '...' : $text;
	}

	/**
	 * State label of a row: `active`, `flushed` or `expired`.
	 *
	 * @param Entry_Row $row Entry.
	 *
	 * @return string
	 */
	private function state_label( Entry_Row $row ): string {
		if ( Entry_Row::STATE_FLUSHED === $row->state ) {
			return 'flushed';
		}
		$expires = strtotime( $row->expires_at . ' UTC' );
		return false !== $expires && $expires <= time() ? 'expired' : 'active';
	}
}
