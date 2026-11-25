<?php
/**
 * Database access for cache entries and their tags.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Storage;

use Rockfire\Rest_Api_Cache\Names;

defined( 'ABSPATH' ) || exit;

/**
 * The only place (besides the schema) that runs SQL on the entries and tags tables.
 *
 * Every table name is resolved from `$wpdb->prefix` at call time (so a `switch_to_blog()` is honoured),
 * all values are bound through `$wpdb->prepare()` and times are passed in as `Y-m-d H:i:s` strings (UTC).
 * Every query is run as `$wpdb->method( $wpdb->prepare( ... ) )`, identifiers bound as `%i`, so a statement is
 * never held in a variable between its preparation and its execution.
 * Flushing, deleting and hit recording are set-based: a fixed number of statements, never one per entry.
 * Timestamps are never taken from the database clock. Every flush also advances a per-site flush epoch
 * (see {@see self::flush_epoch()}), so the class touches the options table through `$wpdb` for that one purpose.
 *
 * @since 1.0.0
 */
final class Repository {

	/**
	 * State value of an active entry.
	 *
	 * @var int
	 */
	public const STATE_ACTIVE = 1;

	/**
	 * State value of a flushed entry.
	 *
	 * @var int
	 */
	public const STATE_FLUSHED = 2;

	/**
	 * Rows per INSERT / DELETE / CASE UPDATE statement.
	 *
	 * @var int
	 */
	public const WRITE_CHUNK = 500;

	/**
	 * Values per IN (...) list of a flush or key lookup.
	 *
	 * @var int
	 */
	public const IN_CHUNK = 1000;

	/**
	 * Name of the entries table of the current site.
	 *
	 * @return string
	 */
	public function entries_table(): string {
		global $wpdb;
		return $wpdb->prefix . Names::TABLE_ENTRIES;
	}

	/**
	 * Name of the tags table of the current site.
	 *
	 * @return string
	 */
	public function tags_table(): string {
		global $wpdb;
		return $wpdb->prefix . Names::TABLE_TAGS;
	}

	/**
	 * Current time as the database string format.
	 *
	 * @return string
	 */
	private function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * The global database object, typed for static analysis only.
	 *
	 * @return \wpdb
	 * @phpstan-return \wpdb&Wpdb_Preparer_Interface
	 */
	private function db() {
		global $wpdb;
		/**
		 * Always a wpdb once WordPress has loaded; the tag only narrows the global for static analysis.
		 *
		 * @var \wpdb&Wpdb_Preparer_Interface $wpdb
		 */
		return $wpdb;
	}

	/**
	 * Rows a write statement changed, telling a failed statement (null) apart from one that changed no rows.
	 *
	 * @param int|bool $result Result of `$wpdb->query()`.
	 *
	 * @return int|null Affected rows, or null when the statement failed.
	 */
	private function affected( $result ): ?int {
		return is_int( $result ) ? $result : null;
	}

	/**
	 * The values of a `$wpdb->get_col()` result, telling a failed query (null) apart from an empty result.
	 *
	 * Call it straight after the query: it reads the last error of the database object.
	 *
	 * @param mixed $result Result of `$wpdb->get_col()`.
	 *
	 * @return string[]|null Null when the query failed.
	 * @phpstan-return list<string>|null
	 */
	private function col( $result ): ?array {
		$wpdb = $this->db();
		if ( '' !== $wpdb->last_error || ! is_array( $result ) ) {
			return null;
		}
		return array_values( array_map( 'strval', $result ) );
	}

	/**
	 * The integer of a `$wpdb->get_var()` result.
	 *
	 * @param string|null $result Result of `$wpdb->get_var()`.
	 *
	 * @return int
	 */
	private function var_int( $result ): int {
		return (int) $result;
	}

	/**
	 * The row of a `$wpdb->get_row( ..., ARRAY_A )` result (also one element of a `get_results()` result).
	 *
	 * @param mixed $result Result of `$wpdb->get_row()`.
	 *
	 * @return array<string,mixed>|null Null when the query failed or found no row.
	 */
	private function row_of( $result ): ?array {
		if ( ! is_array( $result ) ) {
			return null;
		}
		/**
		 * `ARRAY_A` rows are keyed by column name.
		 *
		 * @var array<string,mixed> $result
		 */
		return $result;
	}

	/**
	 * The rows of a `$wpdb->get_results( ..., ARRAY_A )` result; a failed query yields none.
	 *
	 * @param mixed $result Result of `$wpdb->get_results()`.
	 *
	 * @return array<int,array<string,mixed>>
	 * @phpstan-return list<array<string,mixed>>
	 */
	private function rows_of( $result ): array {
		$rows = [];
		foreach ( is_array( $result ) ? $result : [] as $row ) {
			$row = $this->row_of( $row );
			if ( null !== $row ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * Run flush statements (one per chunk) and add up the flushed entries.
	 *
	 * The flush epoch advances before the first statement, never after the last. A store is refused when the epoch
	 * moved since its request's lookup, so with the advance first, a response read before the flush either lands
	 * before the statements and is flushed by them, or meets the moved epoch and is refused. Advancing afterwards
	 * would leave a window in which that stale response is stored active after the statements ran and before the
	 * epoch moved. No statements (an empty target list) is no flush: nothing runs and the epoch stays.
	 *
	 * Every statement runs even when an earlier one failed, so as many entries as possible are flushed. A failed
	 * advance does not stop them either.
	 *
	 * @param \Closure[] $statements Closures that each prepare and run one UPDATE and return its `$wpdb->query()` result.
	 *
	 * @phpstan-param list<\Closure(): (int|bool)> $statements
	 *
	 * @return int|null Entries flushed, or null when the epoch advance or any statement failed (some entries may then still be active).
	 */
	private function run_flush( array $statements ): ?int {
		$flushed = 0;
		$failed  = [] !== $statements && ! $this->advance_epoch( Names::OPTION_FLUSH_EPOCH );
		foreach ( $statements as $statement ) {
			$result = $this->affected( $statement() );
			if ( null === $result ) {
				$failed = true;
				continue;
			}
			$flushed += $result;
		}
		return $failed ? null : $flushed;
	}

	/**
	 * Positive, unique integers.
	 *
	 * @param int[] $ids Ids.
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	private function clean_ids( array $ids ): array {
		$ids = array_filter( array_map( 'intval', $ids ), static fn( int $id ) => $id > 0 );
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Non-empty, unique strings.
	 *
	 * @param string[] $values Values.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private function clean_strings( array $values ): array {
		$values = array_filter( array_map( 'strval', $values ), static fn( string $value ) => '' !== $value );
		return array_values( array_unique( $values ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Read / write.
	// ---------------------------------------------------------------------------------------------

	/**
	 * The active, unexpired entry of a cache key (a single SELECT on the unique key).
	 *
	 * A missing table is a miss, not an error.
	 *
	 * @param string $key Cache key.
	 * @param string $now Current time (`Y-m-d H:i:s`, UTC).
	 *
	 * @return array<string,mixed>|null Row (all columns) or null.
	 */
	public function find_active( string $key, string $now ): ?array {
		$wpdb     = $this->db();
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE cache_key = %s AND state = %d AND expires_at > %s LIMIT 1',
				$this->entries_table(),
				$key,
				self::STATE_ACTIVE,
				$now
			),
			ARRAY_A
		);
		$wpdb->suppress_errors( $suppress );
		return $this->row_of( $row );
	}

	/**
	 * Insert an entry or refresh the existing one of the same cache key; it becomes active again.
	 *
	 * Required keys: `cache_key`, `url_hash`, `route`, `rule_key`, `request`, `payload` (null stores SQL NULL), `headers`, `size`,
	 * `created_at`, `expires_at`. `method` defaults to GET. On insert `hits` is 0 and `last_hit_at` equals
	 * `created_at`; on refresh the hit counter is kept and `last_hit_at` is reset to `created_at`.
	 *
	 * @param array<string,mixed> $row Entry data.
	 *
	 * @return int Entry id (0 on failure).
	 */
	public function upsert_entry( array $row ): int {
		$wpdb    = $this->db();
		$created = (string) ( $row['created_at'] ?? $this->now() );
		// A null payload (object-cache store) is written as SQL NULL, not as an empty string: the flag before the payload picks which one `IF( %d, NULL, %s )` stores.
		$payload = array_key_exists( 'payload', $row ) ? $row['payload'] : '';
		$values  = [
			$this->entries_table(),
			(string) ( $row['cache_key'] ?? '' ),
			(string) ( $row['url_hash'] ?? '' ),
			(string) ( $row['route'] ?? '' ),
			(string) ( $row['rule_key'] ?? '' ),
			(string) ( $row['request'] ?? '' ),
			(string) ( $row['method'] ?? 'GET' ),
			self::STATE_ACTIVE,
			null === $payload ? 1 : 0,
			(string) $payload,
		];
		array_push(
			$values,
			(string) ( $row['headers'] ?? '' ),
			(int) ( $row['size'] ?? 0 ),
			$created,
			(string) ( $row['expires_at'] ?? $created ),
			$created
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$result = $wpdb->query(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- `$values` holds the table name and exactly the values of the placeholders below.
				'INSERT INTO %i (cache_key, url_hash, route, rule_key, request, method, state, payload, headers, size, hits, created_at, expires_at, last_hit_at)
				VALUES (%s, %s, %s, %s, %s, %s, %d, IF( %d, NULL, %s ), %s, %d, 0, %s, %s, %s)
				ON DUPLICATE KEY UPDATE url_hash = VALUES(url_hash), route = VALUES(route), rule_key = VALUES(rule_key),
					request = VALUES(request), method = VALUES(method), payload = VALUES(payload), headers = VALUES(headers),
					size = VALUES(size), created_at = VALUES(created_at), expires_at = VALUES(expires_at),
					last_hit_at = VALUES(last_hit_at), state = 1, flushed_at = NULL, id = LAST_INSERT_ID(id)',
				$values
			)
		);
		if ( false === $result ) {
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Replace the tags of an entry: one DELETE, then multi-row INSERT IGNORE statements of 500 rows.
	 *
	 * @param int      $id   Entry id.
	 * @param string[] $tags Normalised tags.
	 *
	 * @phpstan-param list<string> $tags
	 *
	 * @return bool False when the DELETE or any INSERT statement failed (the entry then has no, or only some, of
	 *              its tags); statements that affect no rows are not failures.
	 */
	public function replace_tags( int $id, array $tags ): bool {
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$ok = false !== $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE entry_id = %d', $this->tags_table(), $id ) );

		foreach ( array_chunk( $this->clean_strings( $tags ), self::WRITE_CHUNK ) as $chunk ) {
			$values = [ $this->tags_table() ];
			foreach ( $chunk as $tag ) {
				$values[] = $tag;
				$values[] = $id;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
			$result = $wpdb->query(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The (%s, %d) tuple list repeats once per tag, and `$values` holds the table name and both values of every tuple.
					'INSERT IGNORE INTO %i (tag, entry_id) VALUES ' . implode( ', ', array_fill( 0, count( $chunk ), '(%s, %d)' ) ),
					$values
				)
			);
			if ( false === $result ) {
				$ok = false;
			}
		}
		return $ok;
	}

	/**
	 * Count a hit of an entry. A missing table is ignored.
	 *
	 * @param int    $id  Entry id.
	 * @param int    $inc Hits to add.
	 * @param string $now Hit time (`Y-m-d H:i:s`, UTC).
	 *
	 * @return void
	 */
	public function record_hit( int $id, int $inc, string $now ): void {
		$wpdb     = $this->db();
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits = hits + %d, last_hit_at = %s WHERE id = %d', $this->entries_table(), $inc, $now, $id ) );
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Count the hits of many entries with one CASE UPDATE per 500 entries. A missing table is ignored.
	 *
	 * @param array<int,array{0:int,1:string}> $hits Entry id => [ hits to add, hit time ].
	 *
	 * @return void
	 */
	public function record_hits( array $hits ): void {
		$wpdb     = $this->db();
		$suppress = $wpdb->suppress_errors( true );
		foreach ( array_chunk( $hits, self::WRITE_CHUNK, true ) as $chunk ) {
			$inc_values = [];
			$time_vals  = [];
			$ids        = [];
			foreach ( $chunk as $id => $hit ) {
				$id           = (int) $id;
				$inc_values[] = $id;
				$inc_values[] = (int) $hit[0];
				$time_vals[]  = $id;
				$time_vals[]  = (string) $hit[1];
				$ids[]        = $id;
			}
			$values = array_merge( [ $this->entries_table() ], $inc_values, $time_vals, $ids );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET hits = hits + CASE id' . implode( '', array_fill( 0, count( $ids ), ' WHEN %d THEN %d' ) ) . ' ELSE 0 END, last_hit_at = CASE id' . implode( '', array_fill( 0, count( $ids ), ' WHEN %d THEN %s' ) ) . ' ELSE last_hit_at END WHERE id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')',
					$values
				)
			);
		}
		$wpdb->suppress_errors( $suppress );
	}

	// ---------------------------------------------------------------------------------------------
	// Flush (set-based). Every flush returns null when a statement failed, so the caller can tell
	// "nothing matched" (0) from "some matching entries may still be active" (null).
	// ---------------------------------------------------------------------------------------------

	/**
	 * Flush the active entries carrying any of the tags: one UPDATE (per 1000 tags).
	 *
	 * @param string[] $tags Normalised tags.
	 * @param string   $now  Flush time (`Y-m-d H:i:s`, UTC).
	 *
	 * @phpstan-param list<string> $tags
	 *
	 * @return int|null Entries flushed; null when a statement failed.
	 */
	public function flush_by_tags( array $tags, string $now ): ?int {
		$wpdb       = $this->db();
		$statements = [];
		foreach ( array_chunk( $this->clean_strings( $tags ), self::IN_CHUNK ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
			$statements[] = fn() => $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i e INNER JOIN %i t ON t.entry_id = e.id SET e.state = 2, e.flushed_at = %s WHERE t.tag IN (' . implode( ', ', array_fill( 0, count( $chunk ), '%s' ) ) . ') AND e.state = 1',
					array_merge( [ $this->entries_table(), $this->tags_table(), $now ], $chunk )
				)
			);
		}
		return $this->run_flush( $statements );
	}

	/**
	 * Flush active entries by id.
	 *
	 * @param int[]  $ids Entry ids.
	 * @param string $now Flush time (`Y-m-d H:i:s`, UTC).
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @return int|null Entries flushed; null when a statement failed.
	 */
	public function flush_by_ids( array $ids, string $now ): ?int {
		return $this->flush_where_in( 'id', $this->clean_ids( $ids ), $now );
	}

	/**
	 * Flush active entries by cache key.
	 *
	 * @param string[] $keys Cache keys.
	 * @param string   $now  Flush time (`Y-m-d H:i:s`, UTC).
	 *
	 * @phpstan-param list<string> $keys
	 *
	 * @return int|null Entries flushed; null when a statement failed.
	 */
	public function flush_by_keys( array $keys, string $now ): ?int {
		return $this->flush_where_in( 'cache_key', $this->clean_strings( $keys ), $now );
	}

	/**
	 * Flush active entries by URL hash.
	 *
	 * @param string $url_hash URL hash.
	 * @param string $now      Flush time (`Y-m-d H:i:s`, UTC).
	 *
	 * @return int|null Entries flushed; null when the statement failed.
	 */
	public function flush_by_url_hash( string $url_hash, string $now ): ?int {
		return $this->flush_where_in( 'url_hash', [ $url_hash ], $now );
	}

	/**
	 * Flush the active entries of a route, its subtree (`route/...`) or a rule key.
	 *
	 * The route is matched literally: `/wp/v2/post` does not match `/wp/v2/posts`, and `%` / `_` in it are escaped.
	 *
	 * @param string $route Route or rule key.
	 * @param string $now   Flush time (`Y-m-d H:i:s`, UTC).
	 *
	 * @return int|null Entries flushed; null when the statement failed.
	 */
	public function flush_route( string $route, string $now ): ?int {
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$statement = fn() => $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET state = 2, flushed_at = %s WHERE state = 1 AND (rule_key = %s OR route = %s OR route LIKE %s)',
				$this->entries_table(),
				$now,
				$route,
				$route,
				$wpdb->esc_like( $route ) . '/%'
			)
		);
		return $this->run_flush( [ $statement ] );
	}

	/**
	 * Flush every active entry.
	 *
	 * @param string $now Flush time (`Y-m-d H:i:s`, UTC).
	 *
	 * @return int|null Entries flushed; null when the statement failed.
	 */
	public function flush_all( string $now ): ?int {
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$statement = fn() => $wpdb->query( $wpdb->prepare( 'UPDATE %i SET state = 2, flushed_at = %s WHERE state = 1', $this->entries_table(), $now ) );
		$flushed   = $this->run_flush( [ $statement ] );
		if ( null === $flushed ) {
			return null;
		}
		// The full-flush epoch only moves for a flush that succeeded; a failed advance is a failed flush.
		return $this->advance_epoch( Names::OPTION_FLUSH_ALL_EPOCH ) ? $flushed : null;
	}

	/**
	 * Flush the active entries whose column is in a list.
	 *
	 * @param string       $column Whitelisted column: id (integer values), cache_key or url_hash (string values).
	 * @param array<mixed> $values Values.
	 * @param string       $now    Flush time.
	 *
	 * @return int|null Entries flushed; null when a statement failed.
	 */
	private function flush_where_in( string $column, array $values, string $now ): ?int {
		if ( ! in_array( $column, [ 'id', 'cache_key', 'url_hash' ], true ) ) {
			return 0;
		}
		$wpdb       = $this->db();
		$statements = [];
		foreach ( array_chunk( $values, self::IN_CHUNK ) as $chunk ) {
			if ( 'id' === $column ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
				$statements[] = fn() => $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET state = 2, flushed_at = %s WHERE %i IN (' . implode( ', ', array_fill( 0, count( $chunk ), '%d' ) ) . ') AND state = 1',
						array_merge( [ $this->entries_table(), $now, $column ], $chunk )
					)
				);
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
			$statements[] = fn() => $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET state = 2, flushed_at = %s WHERE %i IN (' . implode( ', ', array_fill( 0, count( $chunk ), '%s' ) ) . ') AND state = 1',
					array_merge( [ $this->entries_table(), $now, $column ], $chunk )
				)
			);
		}
		return $this->run_flush( $statements );
	}

	// ---------------------------------------------------------------------------------------------
	// Flush epochs. Two counters in the options table of the current site, non-autoloaded and never
	// touched through `get_option()` / `update_option()`: the advance is one atomic statement (a
	// read-modify-write is not), and the read goes straight to the database so neither the object
	// cache, the `alloptions` cache nor an option filter can hand out a stale value.
	// ---------------------------------------------------------------------------------------------

	/**
	 * Number of flushes committed on the current site.
	 *
	 * Every flush that ran statements advances it by exactly 1, whatever the number of entries it matched.
	 *
	 * @return int|null 0 when the counter was never written, null when the query failed.
	 */
	public function flush_epoch(): ?int {
		return $this->read_epoch( Names::OPTION_FLUSH_EPOCH );
	}

	/**
	 * Number of full flushes ({@see self::flush_all()}) that succeeded on the current site.
	 *
	 * @return int|null 0 when the counter was never written, null when the query failed.
	 */
	public function flush_all_epoch(): ?int {
		return $this->read_epoch( Names::OPTION_FLUSH_ALL_EPOCH );
	}

	/**
	 * Read an epoch straight from the options table of the current site.
	 *
	 * @param string $option Epoch option name.
	 *
	 * @return int|null 0 when the row is missing, null when the query failed.
	 */
	private function read_epoch( string $option ): ?int {
		$table = $this->options_table();
		if ( null === $table ) {
			return null;
		}
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Flush epoch read and advanced in the database directly, past every cache.
		$result = $this->col( $wpdb->get_col( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $table, $option ) ) );
		if ( null === $result ) {
			return null;
		}
		return [] === $result ? 0 : (int) $result[0];
	}

	/**
	 * Name of the options table of the current site (so a `switch_to_blog()` is honoured).
	 *
	 * @return string|null Null when the database object is not a `wpdb`.
	 */
	private function options_table(): ?string {
		global $wpdb;
		return $wpdb instanceof \wpdb ? $wpdb->options : null;
	}

	/**
	 * Advance an epoch by 1 with one atomic statement (creating the row, non-autoloaded, at 1 when it is missing).
	 *
	 * @param string $option Epoch option name.
	 *
	 * @return bool False when the statement failed.
	 */
	private function advance_epoch( string $option ): bool {
		$table = $this->options_table();
		if ( null === $table ) {
			return false;
		}
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Flush epoch read and advanced in the database directly, past every cache.
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %d, %s)
				ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1',
				$table,
				$option,
				1,
				'off'
			)
		);
		return null !== $this->affected( $result );
	}

	// ---------------------------------------------------------------------------------------------
	// Cache keys of matching entries (for the object-cache store).
	//
	// Every lookup returns null when the query failed, so the caller can tell "no keys" from "unknown
	// keys" (and then orphans every object key instead). With `$flushed_at` the lookup matches the
	// entries flushed at that time instead of the active ones: the object-cache store looks keys up
	// after its flush UPDATE, so an entry stored concurrently is never missed.
	// ---------------------------------------------------------------------------------------------

	/**
	 * State condition of the key lookups, as the values it binds.
	 *
	 * A lookup repeats its literal ` AND state = %d` once per state and its literal ` AND flushed_at = %s` once per
	 * flush time, so the SQL stays literal and no fragment of it is interpolated.
	 *
	 * @param string|null $flushed_at Null for the active entries, else the flush time to match.
	 *
	 * @return array{0:list<int>,1:list<string>} The states to match and the flush times to match.
	 */
	private function key_state_condition( ?string $flushed_at ): array {
		if ( null === $flushed_at ) {
			return [ [ self::STATE_ACTIVE ], [] ];
		}
		return [ [ self::STATE_FLUSHED ], [ $flushed_at ] ];
	}

	/**
	 * Cache keys of the entries carrying any of the tags.
	 *
	 * @param string[]    $tags       Normalised tags.
	 * @param string|null $flushed_at Null for the active entries, else the entries flushed at this time.
	 *
	 * @phpstan-param list<string> $tags
	 *
	 * @return string[]|null Null when a query failed.
	 * @phpstan-return list<string>|null
	 */
	public function keys_for_tags( array $tags, ?string $flushed_at = null ): ?array {
		$wpdb                     = $this->db();
		[ $states, $flush_times ] = $this->key_state_condition( $flushed_at );

		$keys = [];
		foreach ( array_chunk( $this->clean_strings( $tags ), self::IN_CHUNK ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
			$found  = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT e.cache_key FROM %i e INNER JOIN %i t ON t.entry_id = e.id WHERE t.tag IN (' . implode( ', ', array_fill( 0, count( $chunk ), '%s' ) ) . ')' . implode( '', array_fill( 0, count( $states ), ' AND e.state = %d' ) ) . implode( '', array_fill( 0, count( $flush_times ), ' AND e.flushed_at = %s' ) ),
					array_merge( [ $this->entries_table(), $this->tags_table() ], $chunk, $states, $flush_times )
				)
			);
			$result = $this->col( $found );
			if ( null === $result ) {
				return null;
			}
			$keys = array_merge( $keys, $result );
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Cache keys of the entries of a URL hash.
	 *
	 * @param string      $url_hash   URL hash.
	 * @param string|null $flushed_at Null for the active entries, else the entries flushed at this time.
	 *
	 * @return string[]|null Null when the query failed.
	 * @phpstan-return list<string>|null
	 */
	public function keys_for_url_hash( string $url_hash, ?string $flushed_at = null ): ?array {
		$wpdb                     = $this->db();
		[ $states, $flush_times ] = $this->key_state_condition( $flushed_at );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$found = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT cache_key FROM %i WHERE url_hash = %s' . implode( '', array_fill( 0, count( $states ), ' AND state = %d' ) ) . implode( '', array_fill( 0, count( $flush_times ), ' AND flushed_at = %s' ) ),
				$this->entries_table(),
				$url_hash,
				...$states,
				...$flush_times
			)
		);
		return $this->col( $found );
	}

	/**
	 * Cache keys of the entries {@see self::flush_route()} matches.
	 *
	 * @param string      $route      Route or rule key.
	 * @param string|null $flushed_at Null for the active entries, else the entries flushed at this time.
	 *
	 * @return string[]|null Null when the query failed.
	 * @phpstan-return list<string>|null
	 */
	public function keys_for_route( string $route, ?string $flushed_at = null ): ?array {
		$wpdb                     = $this->db();
		[ $states, $flush_times ] = $this->key_state_condition( $flushed_at );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$found = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT cache_key FROM %i WHERE (rule_key = %s OR route = %s OR route LIKE %s)' . implode( '', array_fill( 0, count( $states ), ' AND state = %d' ) ) . implode( '', array_fill( 0, count( $flush_times ), ' AND flushed_at = %s' ) ),
				$this->entries_table(),
				$route,
				$route,
				$wpdb->esc_like( $route ) . '/%',
				...$states,
				...$flush_times
			)
		);
		return $this->col( $found );
	}

	/**
	 * Cache keys of the entries with the given ids.
	 *
	 * @param int[]       $ids        Entry ids.
	 * @param string|null $flushed_at Null for the active entries, else the entries flushed at this time.
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @return string[]|null Null when a query failed.
	 * @phpstan-return list<string>|null
	 */
	public function keys_for_ids( array $ids, ?string $flushed_at = null ): ?array {
		[ $states, $flush_times ] = $this->key_state_condition( $flushed_at );
		return $this->keys_where_ids( $ids, $states, $flush_times );
	}

	/**
	 * Cache keys of the entries with the given ids, whatever their state (for deleting them).
	 *
	 * @param int[] $ids Entry ids.
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @return string[]|null Null when a query failed.
	 * @phpstan-return list<string>|null
	 */
	public function all_keys_for_ids( array $ids ): ?array {
		return $this->keys_where_ids( $ids, [], [] );
	}

	/**
	 * Cache keys of the entries with the given ids that meet a state condition.
	 *
	 * @param int[]    $ids         Entry ids.
	 * @param int[]    $states      States to match, from {@see self::key_state_condition()} (none: any state).
	 * @param string[] $flush_times Flush times to match, from {@see self::key_state_condition()}.
	 *
	 * @phpstan-param list<int> $ids
	 * @phpstan-param list<int> $states
	 * @phpstan-param list<string> $flush_times
	 *
	 * @return string[]|null Null when a query failed.
	 * @phpstan-return list<string>|null
	 */
	private function keys_where_ids( array $ids, array $states, array $flush_times ): ?array {
		$wpdb = $this->db();
		$keys = [];
		foreach ( array_chunk( $this->clean_ids( $ids ), self::IN_CHUNK ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
			$found  = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT cache_key FROM %i WHERE id IN (' . implode( ', ', array_fill( 0, count( $chunk ), '%d' ) ) . ')' . implode( '', array_fill( 0, count( $states ), ' AND state = %d' ) ) . implode( '', array_fill( 0, count( $flush_times ), ' AND flushed_at = %s' ) ),
					array_merge( [ $this->entries_table() ], $chunk, $states, $flush_times )
				)
			);
			$result = $this->col( $found );
			if ( null === $result ) {
				return null;
			}
			$keys = array_merge( $keys, $result );
		}
		return $keys;
	}

	/**
	 * State of an entry.
	 *
	 * @param int $id Entry id.
	 *
	 * @return int|null {@see self::STATE_ACTIVE}, {@see self::STATE_FLUSHED}, 0 when the entry does not exist, null when the query failed.
	 */
	public function entry_state( int $id ): ?int {
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$result = $this->col( $wpdb->get_col( $wpdb->prepare( 'SELECT state FROM %i WHERE id = %d', $this->entries_table(), $id ) ) );
		if ( null === $result ) {
			return null;
		}
		return [] === $result ? 0 : (int) $result[0];
	}

	// ---------------------------------------------------------------------------------------------
	// Delete / prune.
	// ---------------------------------------------------------------------------------------------

	/**
	 * Delete entries and their tags (tags first), 500 entries per statement pair.
	 *
	 * @param int[] $ids Entry ids.
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @return int Entries deleted.
	 */
	public function delete_entries( array $ids ): int {
		$wpdb    = $this->db();
		$deleted = 0;
		foreach ( array_chunk( $this->clean_ids( $ids ), self::WRITE_CHUNK ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE entry_id IN (' . implode( ', ', array_fill( 0, count( $chunk ), '%d' ) ) . ')', array_merge( [ $this->tags_table() ], $chunk ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
			$result   = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . implode( ', ', array_fill( 0, count( $chunk ), '%d' ) ) . ')', array_merge( [ $this->entries_table() ], $chunk ) ) );
			$deleted += $this->affected( $result ) ?? 0;
		}
		return $deleted;
	}

	/**
	 * Ids of entries that can be deleted: flushed ones and active ones past their expiry.
	 *
	 * Written as `state = 2 OR (state = 1 AND expires_at <= now)` so both branches are ranges of the
	 * `state_expires` index.
	 *
	 * @param string $now   Current time (`Y-m-d H:i:s`, UTC).
	 * @param int    $limit Maximum number of ids.
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public function prunable_ids( string $now, int $limit ): array {
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$found = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE state = 2 OR (state = 1 AND expires_at <= %s) ORDER BY id ASC LIMIT %d',
				$this->entries_table(),
				$now,
				max( 0, $limit )
			)
		);
		return array_map( 'intval', $this->col( $found ) ?? [] );
	}

	/**
	 * Ids of the least recently hit active entries, oldest first.
	 *
	 * @param int $limit Maximum number of ids.
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public function lru_ids( int $limit ): array {
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$found = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE state = 1 ORDER BY last_hit_at ASC, id ASC LIMIT %d',
				$this->entries_table(),
				max( 0, $limit )
			)
		);
		return array_map( 'intval', $this->col( $found ) ?? [] );
	}

	/**
	 * Number of entries in state active (including those past their expiry that were not pruned yet).
	 *
	 * @return int
	 */
	public function count_active(): int {
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		return $this->var_int( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE state = 1', $this->entries_table() ) ) );
	}

	/**
	 * Number of entries {@see self::prunable_ids()} would return.
	 *
	 * @param string $now Current time (`Y-m-d H:i:s`, UTC).
	 *
	 * @return int
	 */
	public function count_stale( string $now ): int {
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE state = 2 OR (state = 1 AND expires_at <= %s)',
				$this->entries_table(),
				$now
			)
		);
		return $this->var_int( $count );
	}

	// ---------------------------------------------------------------------------------------------
	// Listing.
	// ---------------------------------------------------------------------------------------------

	/**
	 * Entries matching a listing query.
	 *
	 * @param Entry_Query $query Filters, order and paging.
	 *
	 * @return Entry_Row[]
	 * @phpstan-return list<Entry_Row>
	 */
	public function query( Entry_Query $query ): array {
		$wpdb = $this->db();
		[ $search_on, $like, $state, $now, $route_on, $route, $route_like, $rule_on, $rule, $tag_on, $tag ] = $this->listing_filters( $query );

		// Both come from whitelists in Entry_Query::from_array() and are re-checked here.
		$orderby = in_array( $query->orderby, Entry_Query::ORDERBY, true ) ? $query->orderby : 'created_at';

		// ASC and DESC are literals, one of them repeated once, so the direction is never interpolated.
		$ascending  = 'asc' === $query->order ? [ 1 ] : [];
		$descending = 'asc' === $query->order ? [] : [ 1 ];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$found = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, cache_key, url_hash, route, rule_key, request, method, state, size, hits, created_at, expires_at, last_hit_at, flushed_at FROM %i
				WHERE ( %d = 0 OR route LIKE %s OR request LIKE %s )
				AND ( %s = '' OR ( %s = 'active' AND state = 1 AND expires_at > %s ) OR ( %s = 'flushed' AND state = 2 ) OR ( %s = 'expired' AND state = 1 AND expires_at <= %s ) )
				AND ( %d = 0 OR route = %s OR route LIKE %s )
				AND ( %d = 0 OR rule_key = %s )
				AND ( %d = 0 OR id IN ( SELECT entry_id FROM %i WHERE tag = %s ) )
				ORDER BY %i" . implode( '', array_fill( 0, count( $ascending ), ' ASC, id ASC' ) ) . implode( '', array_fill( 0, count( $descending ), ' DESC, id DESC' ) ) . ' LIMIT %d OFFSET %d',
				$this->entries_table(),
				$search_on,
				$like,
				$like,
				$state,
				$state,
				$now,
				$state,
				$state,
				$now,
				$route_on,
				$route,
				$route_like,
				$rule_on,
				$rule,
				$tag_on,
				$this->tags_table(),
				$tag,
				$orderby,
				$query->per_page,
				$query->offset()
			),
			ARRAY_A
		);
		return array_map( [ Entry_Row::class, 'from_db' ], $this->rows_of( $found ) );
	}

	/**
	 * Number of entries matching a listing query (ignoring paging).
	 *
	 * @param Entry_Query $query Filters.
	 *
	 * @return int
	 */
	public function count( Entry_Query $query ): int {
		$wpdb = $this->db();
		[ $search_on, $like, $state, $now, $route_on, $route, $route_like, $rule_on, $rule, $tag_on, $tag ] = $this->listing_filters( $query );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				WHERE ( %d = 0 OR route LIKE %s OR request LIKE %s )
				AND ( %s = '' OR ( %s = 'active' AND state = 1 AND expires_at > %s ) OR ( %s = 'flushed' AND state = 2 ) OR ( %s = 'expired' AND state = 1 AND expires_at <= %s ) )
				AND ( %d = 0 OR route = %s OR route LIKE %s )
				AND ( %d = 0 OR rule_key = %s )
				AND ( %d = 0 OR id IN ( SELECT entry_id FROM %i WHERE tag = %s ) )",
				$this->entries_table(),
				$search_on,
				$like,
				$like,
				$state,
				$state,
				$now,
				$state,
				$state,
				$now,
				$route_on,
				$route,
				$route_like,
				$rule_on,
				$rule,
				$tag_on,
				$this->tags_table(),
				$tag
			)
		);
		return $this->var_int( $count );
	}

	/**
	 * One entry.
	 *
	 * @param int $id Entry id.
	 *
	 * @return Entry_Row|null
	 */
	public function get( int $id ): ?Entry_Row {
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$found = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, cache_key, url_hash, route, rule_key, request, method, state, size, hits, created_at, expires_at, last_hit_at, flushed_at FROM %i WHERE id = %d',
				$this->entries_table(),
				$id
			),
			ARRAY_A
		);
		$rows  = $this->rows_of( $found );
		return isset( $rows[0] ) ? Entry_Row::from_db( $rows[0] ) : null;
	}

	/**
	 * The stored payload of an entry.
	 *
	 * @param int $id Entry id.
	 *
	 * @return string|null Null when the entry is missing or has no payload.
	 */
	public function payload_for( int $id ): ?string {
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$payload = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM %i WHERE id = %d', $this->entries_table(), $id ) );
		return is_string( $payload ) ? $payload : null;
	}

	/**
	 * The stored response headers of an entry.
	 *
	 * @param int $id Entry id.
	 *
	 * @return array<string,string> Empty when the entry is missing or its headers cannot be decoded.
	 */
	public function headers_for( int $id ): array {
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$json = $wpdb->get_var( $wpdb->prepare( 'SELECT headers FROM %i WHERE id = %d', $this->entries_table(), $id ) );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) ) {
			return [];
		}
		$headers = [];
		foreach ( $data as $name => $value ) {
			if ( is_string( $name ) && is_scalar( $value ) ) {
				$headers[ $name ] = (string) $value;
			}
		}
		return $headers;
	}

	/**
	 * Tags of an entry, sorted.
	 *
	 * @param int $id Entry id.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function tags_for( int $id ): array {
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		return $this->col( $wpdb->get_col( $wpdb->prepare( 'SELECT tag FROM %i WHERE entry_id = %d ORDER BY tag ASC', $this->tags_table(), $id ) ) ) ?? [];
	}

	/**
	 * Aggregate numbers of the current site's cache.
	 *
	 * @return Stats
	 */
	public function stats(): Stats {
		$wpdb = $this->db();
		$now  = $this->now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$found = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total, COALESCE(SUM(size), 0) AS total_bytes, COALESCE(SUM(state = 2), 0) AS flushed,
					COALESCE(SUM(state = 1 AND expires_at > %s), 0) AS active, COALESCE(SUM(state = 1 AND expires_at <= %s), 0) AS expired
				FROM %i',
				$now,
				$now,
				$this->entries_table()
			),
			ARRAY_A
		);
		$row   = $this->rows_of( $found )[0] ?? [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own cache tables have no WordPress API, and their rows are the cache, so they are not object-cached.
		$tags = $this->var_int( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->tags_table() ) ) );

		return new Stats(
			(int) ( $row['active'] ?? 0 ),
			(int) ( $row['flushed'] ?? 0 ),
			(int) ( $row['expired'] ?? 0 ),
			(int) ( $row['total'] ?? 0 ),
			(int) ( $row['total_bytes'] ?? 0 ),
			$tags
		);
	}

	/**
	 * The values the filters of a listing query bind.
	 *
	 * The WHERE of {@see self::query()} and {@see self::count()} is one literal text for every query: each filter is a
	 * condition switched off by a bound flag (`%d = 0 OR …`), so a filter that is not set binds a 0 and empty values,
	 * and the state filter binds its name (empty for any state) once per branch.
	 *
	 * @param Entry_Query $query Filters.
	 *
	 * @return array{0:int,1:string,2:string,3:string,4:int,5:string,6:string,7:int,8:string,9:int,10:string} Search flag,
	 *         search pattern, state name, time, route flag, route prefix, route subtree pattern, rule flag, rule key,
	 *         tag flag and tag.
	 */
	private function listing_filters( Entry_Query $query ): array {
		$wpdb = $this->db();
		$like = '%' . $wpdb->esc_like( $query->search ) . '%';

		return [
			'' === $query->search ? 0 : 1,
			'' === $query->search ? '' : $like,
			in_array( $query->state, [ 'active', 'flushed', 'expired' ], true ) ? $query->state : '',
			$this->now(),
			'' === $query->route_prefix ? 0 : 1,
			$query->route_prefix,
			'' === $query->route_prefix ? '' : $wpdb->esc_like( $query->route_prefix ) . '/%',
			'' === $query->rule_key ? 0 : 1,
			$query->rule_key,
			'' === $query->tag ? 0 : 1,
			$query->tag,
		];
	}
}
