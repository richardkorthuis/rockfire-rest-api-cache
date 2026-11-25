<?php
/**
 * WP-CLI command `wp rfrac`: flush, list, status, prune and import-legacy.
 *
 * @since 1.0.0
 *
 * @package    Rockfire_Rest_Api_Cache
 * @subpackage Rockfire_Rest_Api_Cache/Cli
 */

namespace Rockfire\Rest_Api_Cache\Cli;

use Rockfire\Rest_Api_Cache\Legacy\Import_Cli;
use Rockfire\Rest_Api_Cache\Maintenance\Lock;
use Rockfire\Rest_Api_Cache\Services\Cache_Service;
use Rockfire\Rest_Api_Cache\Services\Route_Service;
use Rockfire\Rest_Api_Cache\Services\Status_Service;
use Rockfire\Rest_Api_Cache\Storage\Entry_Query;
use Rockfire\Rest_Api_Cache\Storage\Entry_Row;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the REST API cache.
 *
 * The command works on the services only; on a multisite network it works on the current site
 * (select one with the global site selector of WP-CLI), or on every site with the plugin active with `--network`.
 *
 * @since 1.0.0
 */
class Command extends \WP_CLI_Command {

	use Flag_Trait;

	/**
	 * Output formats of `list`.
	 *
	 * @var string[]
	 */
	private const LIST_FORMATS = array( 'table', 'csv', 'json', 'yaml', 'count', 'ids' );

	/**
	 * Cache service.
	 *
	 * @var Cache_Service
	 */
	private Cache_Service $cache;

	/**
	 * Route service.
	 *
	 * @var Route_Service
	 */
	private Route_Service $routes;

	/**
	 * Status service.
	 *
	 * @var Status_Service
	 */
	private Status_Service $status;

	/**
	 * Network iterator.
	 *
	 * @var Network_Iterator
	 */
	private Network_Iterator $network;

	/**
	 * Import of the settings of the original plugin; null when it is not wired.
	 *
	 * @var Import_Cli|null
	 */
	private ?Import_Cli $import;

	/**
	 * Constructor.
	 *
	 * @param Cache_Service    $cache   Cache service.
	 * @param Route_Service    $routes  Route service.
	 * @param Status_Service   $status  Status service.
	 * @param Network_Iterator $network Network iterator.
	 * @param Import_Cli|null  $import  Import of the settings of the original plugin.
	 */
	public function __construct( Cache_Service $cache, Route_Service $routes, Status_Service $status, Network_Iterator $network, ?Import_Cli $import = null ) {
		parent::__construct();
		$this->cache   = $cache;
		$this->routes  = $routes;
		$this->status  = $status;
		$this->network = $network;
		$this->import  = $import;
	}

	/**
	 * Flush cached responses.
	 *
	 * Flushed responses are no longer served and are removed by maintenance. Specify exactly one selector.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Flush every cached response.
	 *
	 * [--tag=<tags>]
	 * : Flush the responses carrying any of these tags, separated by commas (for example post:1,term:5), at most 50. Route tags (route:...) are rejected; use --route.
	 *
	 * [--request-url=<url>]
	 * : Flush the responses of one REST URL of this site (an http(s) URL on this site's host and path). Not named --url, because WP-CLI reserves that for its global site selector. With --network, sites that do not own the URL are skipped.
	 *
	 * [--route=<route-or-path>]
	 * : Flush the responses of a route, given as route pattern or as a request path (for example /wp/v2/posts/5).
	 *
	 * [--network]
	 * : Flush on every site of the network where the plugin is active.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rfrac flush --all
	 *     wp rfrac flush --tag=post:1,post:2
	 *     wp rfrac flush --route=/wp/v2/posts
	 *     wp rfrac flush --request-url=https://example.com/wp-json/wp/v2/posts
	 *     wp rfrac flush --all --network
	 *
	 * @subcommand flush
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function flush( $args, $assoc_args ) {
		$selectors = array();
		if ( $this->flag( $assoc_args, 'all' ) ) {
			$selectors['all'] = '';
		}
		foreach ( array( 'tag', 'request-url', 'route' ) as $name ) {
			if ( isset( $assoc_args[ $name ] ) ) {
				$selectors[ $name ] = is_string( $assoc_args[ $name ] ) ? $assoc_args[ $name ] : '';
			}
		}
		if ( 1 !== count( $selectors ) ) {
			\WP_CLI::error( __( 'Specify exactly one of --all, --tag, --request-url, --route.', 'rockfire-rest-api-cache' ) );
		}
		$selector = (string) array_key_first( $selectors );
		$value    = $selectors[ $selector ];
		$network  = $this->flag( $assoc_args, 'network' );

		// The tags do not depend on the site: validate them once, so a bad tag errors once and not per site.
		if ( 'tag' === $selector ) {
			$parsed = $this->cache->parse_tags( $value );
			if ( is_wp_error( $parsed ) ) {
				\WP_CLI::error( $parsed->get_error_message() );
			}
		}

		$total    = 0;
		$failures = 0;
		$accepted = 0;
		$this->each_site(
			$network,
			function () use ( $selector, $value, $network, &$total, &$failures, &$accepted ): void {
				$result = $this->flush_selected( $selector, $value );
				if ( is_wp_error( $result ) ) {
					if ( ! $network ) {
						\WP_CLI::error( $result->get_error_message() );
					}
					// A URL that belongs to another site of the network is not a failure: that site is skipped.
					if ( 'request-url' === $selector && 'rfrac_invalid_url' === $result->get_error_code() ) {
						return;
					}
					++$failures;
					\WP_CLI::warning( $this->prefix( true ) . $result->get_error_message() );
					return;
				}
				++$accepted;
				$total += $result;
				if ( $network ) {
					\WP_CLI::log( $this->prefix( true ) . $this->flushed_message( $result ) );
					return;
				}
				\WP_CLI::success( $this->flushed_message( $result ) );
			}
		);

		if ( $network ) {
			if ( 'request-url' === $selector && 0 === $accepted && 0 === $failures ) {
				\WP_CLI::error( __( 'No site of the network accepts this URL. Enter a full http(s) URL of a REST route of one of the sites.', 'rockfire-rest-api-cache' ) );
			}
			/* translators: %d: number of flushed cached responses over all sites */
			\WP_CLI::log( sprintf( __( 'Flushed %d cached responses in total.', 'rockfire-rest-api-cache' ), $total ) );
			if ( $failures > 0 ) {
				/* translators: %d: number of sites where the flush failed */
				\WP_CLI::error( sprintf( __( 'The flush failed on %d site(s).', 'rockfire-rest-api-cache' ), $failures ) );
			}
		}
	}

	/**
	 * List cached responses.
	 *
	 * ## OPTIONS
	 *
	 * [--route=<route>]
	 * : Only responses of this route (or route subtree).
	 *
	 * [--state=<state>]
	 * : Only responses in this state.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - active
	 *   - flushed
	 *   - expired
	 * ---
	 *
	 * [--search=<text>]
	 * : Only responses whose route or request contains this text.
	 *
	 * [--per-page=<number>]
	 * : Responses per page, 1 to 500.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--page=<number>]
	 * : Page number.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific fields. Defaults to id,route,request,state,size,hits,expires_at. Also available: method, rule_key, created_at, last_hit_at, flushed_at. With --network, blog_id is added to the default fields.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format. `count` prints the total number of matching responses, `ids` the IDs of the page.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * [--network]
	 * : List the responses of every site of the network where the plugin is active (adds a blog_id column; paging applies per site).
	 *
	 * ## EXAMPLES
	 *
	 *     wp rfrac list --state=flushed --format=ids
	 *     wp rfrac list --route=/wp/v2/posts --format=json
	 *     wp rfrac list --format=count --network
	 *
	 * @subcommand list
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function list_entries( $args, $assoc_args ) {
		$format = isset( $assoc_args['format'] ) && is_string( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		if ( ! in_array( $format, self::LIST_FORMATS, true ) ) {
			/* translators: %s: the formats that can be used, separated by commas */
			\WP_CLI::error( sprintf( __( 'Invalid format. Use one of: %s.', 'rockfire-rest-api-cache' ), implode( ', ', self::LIST_FORMATS ) ) );
		}
		$state = isset( $assoc_args['state'] ) && is_string( $assoc_args['state'] ) ? $assoc_args['state'] : 'all';
		if ( ! in_array( $state, Entry_Query::STATES, true ) ) {
			/* translators: %s: the states that can be used, separated by commas */
			\WP_CLI::error( sprintf( __( 'Invalid state. Use one of: %s.', 'rockfire-rest-api-cache' ), implode( ', ', Entry_Query::STATES ) ) );
		}
		$network = $this->flag( $assoc_args, 'network' );

		$query = Entry_Query::from_array(
			array(
				'route_prefix' => $assoc_args['route'] ?? '',
				'state'        => $state,
				'search'       => $assoc_args['search'] ?? '',
				'per_page'     => $assoc_args['per-page'] ?? null,
				'page'         => $assoc_args['page'] ?? null,
			)
		);

		$rows  = array();
		$total = 0;
		$this->each_site(
			$network,
			function ( int $blog_id ) use ( $query, $network, &$rows, &$total ): void {
				$result = $this->cache->query( $query );
				$total += $result['total'];
				foreach ( $result['items'] as $entry ) {
					$row = $this->entry_to_row( $entry );
					if ( $network ) {
						$row = array( 'blog_id' => $blog_id ) + $row;
					}
					$rows[] = $row;
				}
			}
		);

		if ( 'count' === $format ) {
			\WP_CLI::line( (string) $total );
			return;
		}

		$fields = array( 'id', 'route', 'request', 'state', 'size', 'hits', 'expires_at' );
		if ( $network ) {
			array_unshift( $fields, 'blog_id' );
		}
		if ( 'ids' === $format ) {
			$rows = array_column( $rows, 'id' );
		}
		$formatter_args = array( 'format' => $format );
		if ( isset( $assoc_args['fields'] ) ) {
			$formatter_args['fields'] = $assoc_args['fields'];
		}
		( new \WP_CLI\Formatter( $formatter_args, $fields ) )->display_items( $rows );
	}

	/**
	 * Show the status of the cache.
	 *
	 * Warns when the must-use loader is not serving early or when the tables are missing.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp rfrac status
	 *     wp rfrac status --format=json
	 *
	 * @subcommand status
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		$format = isset( $assoc_args['format'] ) && is_string( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		if ( ! in_array( $format, array( 'table', 'json', 'yaml' ), true ) ) {
			\WP_CLI::error( __( 'Invalid format. Use table, json or yaml.', 'rockfire-rest-api-cache' ) );
		}

		$status = $this->status->get( true );
		$data   = array(
			'plugin_version'        => $status->plugin_version,
			'db_version'            => $status->db_version,
			'db_version_expected'   => $status->db_version_expected,
			'tables_present'        => $status->tables_present,
			'mu_status'             => $status->mu_status->value,
			'mu_mode'               => $status->mu_mode,
			'store_type'            => $status->store_type,
			'object_cache_detected' => $status->object_cache_detected,
			'entries_active'        => $status->counts['active'],
			'entries_flushed'       => $status->counts['flushed'],
			'entries_expired'       => $status->counts['expired'],
			'entries_total'         => $status->counts['total'],
			'total_size'            => $status->total_size,
			'max_entries'           => $status->max_entries,
			'backlog'               => $status->backlog,
			'last_maintenance'      => $status->last_maintenance,
			'next_maintenance'      => null === $status->next_maintenance ? null : gmdate( 'Y-m-d H:i:s', $status->next_maintenance ),
			'cron_disabled'         => $status->cron_disabled,
			'coexistence_blocked'   => $status->coexistence_blocked,
			'locked_settings'       => $status->locked_settings,
		);

		if ( 'early' !== $status->mu_mode ) {
			/* translators: %s: serving mode */
			\WP_CLI::warning( sprintf( __( 'The must-use loader is not active (mode: %s); responses are served late in the request.', 'rockfire-rest-api-cache' ), $status->mu_mode ) );
		}
		if ( false === $status->tables_present ) {
			\WP_CLI::warning( __( 'The cache tables are missing. Deactivate and reactivate the plugin to create them.', 'rockfire-rest-api-cache' ) );
		}

		if ( 'json' === $format || 'yaml' === $format ) {
			\WP_CLI::print_value( $data, array( 'format' => $format ) );
			return;
		}

		$rows = array();
		foreach ( $data as $key => $value ) {
			$rows[] = array(
				'key'   => $key,
				'value' => $this->cell( $value ),
			);
		}
		$args = array( 'format' => 'table' );
		( new \WP_CLI\Formatter( $args, array( 'key', 'value' ) ) )->display_items( $rows );
	}

	/**
	 * Run one maintenance pass: delete stale entries and evict the least recently used above the limit.
	 *
	 * Stale means flushed or expired. Runs without a time limit; it reports an error when a run makes no progress (for example when deletes keep failing) and when maintenance is already running. With --network a site where maintenance is already running is skipped and counted.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Run on every site of the network where the plugin is active.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rfrac prune
	 *     wp rfrac prune --network
	 *
	 * @subcommand prune
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function prune( $args, $assoc_args ) {
		$network    = $this->flag( $assoc_args, 'network' );
		$deleted    = 0;
		$evicted    = 0;
		$skipped    = 0;
		$stalled    = 0;
		$incomplete = 0;

		$visited = $this->each_site(
			$network,
			function () use ( $network, &$deleted, &$evicted, &$skipped, &$stalled, &$incomplete ): void {
				$result = $this->cache->run_maintenance( 'cli' );
				$prefix = $this->prefix( $network );
				if ( $result->skipped ) {
					if ( ! ( new Lock() )->is_locked() ) {
						// No lock is held: the site could not take it (for example its options table is missing).
						++$stalled;
						\WP_CLI::warning( $prefix . __( 'Maintenance could not start on this site (its lock could not be taken); check that its database tables exist.', 'rockfire-rest-api-cache' ) );
						return;
					}
					++$skipped;
					\WP_CLI::warning( $prefix . __( 'Maintenance is already running; this site was skipped.', 'rockfire-rest-api-cache' ) );
					return;
				}
				$deleted += $result->deleted_stale;
				$evicted += $result->evicted;
				/* translators: 1: number of deleted entries, 2: number of evicted entries */
				$message = sprintf( __( 'Deleted %1$d, evicted %2$d.', 'rockfire-rest-api-cache' ), $result->deleted_stale, $result->evicted );
				if ( $result->stalled ) {
					++$stalled;
					\WP_CLI::log( $prefix . $message );
					\WP_CLI::warning( $prefix . __( 'Maintenance made no progress (for example because entries cannot be deleted); check the database and the error log.', 'rockfire-rest-api-cache' ) );
					return;
				}
				if ( $network || ! $result->complete ) {
					\WP_CLI::log( $prefix . $message );
				} else {
					\WP_CLI::success( $message );
				}
				if ( ! $result->complete ) {
					++$incomplete;
					if ( $result->backlog_remaining > 0 ) {
						/* translators: %d: number of stale entries still waiting */
						\WP_CLI::warning( $prefix . sprintf( __( '%d stale entries remain; run the command again.', 'rockfire-rest-api-cache' ), $result->backlog_remaining ) );
					} else {
						/* translators: %d: number of active entries */
						\WP_CLI::warning( $prefix . sprintf( __( 'There are still more active entries (%d) than the entry limit allows; run the command again.', 'rockfire-rest-api-cache' ), $result->entries_after ) );
					}
				}
			}
		);

		if ( $skipped > 0 && $skipped === $visited ) {
			\WP_CLI::error( __( 'Maintenance is already running; nothing was done.', 'rockfire-rest-api-cache' ) );
		}
		if ( $stalled > 0 ) {
			/* translators: 1: number of sites that made no progress, 2: number of deleted entries, 3: number of evicted entries */
			\WP_CLI::error( sprintf( __( 'Maintenance made no progress on %1$d site(s). Deleted %2$d, evicted %3$d.', 'rockfire-rest-api-cache' ), $stalled, $deleted, $evicted ) );
		}

		if ( $network ) {
			/* translators: 1: number of deleted entries, 2: number of evicted entries */
			$total = sprintf( __( 'Deleted %1$d, evicted %2$d in total.', 'rockfire-rest-api-cache' ), $deleted, $evicted );
			if ( $skipped > 0 ) {
				/* translators: %d: number of sites */
				$total .= ' ' . sprintf( __( '%d site(s) skipped because maintenance was already running.', 'rockfire-rest-api-cache' ), $skipped );
			}
			if ( $incomplete > 0 ) {
				/* translators: %d: number of sites */
				$total .= ' ' . sprintf( __( '%d site(s) still have work left; run the command again.', 'rockfire-rest-api-cache' ), $incomplete );
			}
			\WP_CLI::success( $total );
		}
	}

	/**
	 * Import the settings of WP REST Cache.
	 *
	 * Reads the options and constants that WP REST Cache left on this site and saves the matching settings and route rules. A setting that already holds a saved value, or that a constant controls, is kept. Prints what became of every value: imported, kept, adjusted, skipped or unsupported. The options of WP REST Cache are only read, never changed. Prints a warning, and exits with 0, when the site has none.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Print what the import would do and save nothing.
	 *
	 * [--network]
	 * : Import on every site of the network where the plugin is active. Each site runs in its own WP-CLI process, so that the plugins of that site are loaded.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp rfrac import-legacy --dry-run
	 *     wp rfrac import-legacy
	 *     wp rfrac import-legacy --dry-run --network --format=json
	 *
	 * @subcommand import-legacy
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function import_legacy( $args, $assoc_args ) {
		if ( null === $this->import ) {
			\WP_CLI::error( __( 'The import is not available.', 'rockfire-rest-api-cache' ) );
			return;
		}
		$this->import->run( $assoc_args );
	}

	/**
	 * Run a callback for the current site, or for every site of the network.
	 *
	 * Errors when `--network` finds no site where the plugin is active.
	 *
	 * @param bool     $network  Whether to loop over the network.
	 * @param callable $callback Receives the blog ID.
	 * @phpstan-param callable(int): void $callback
	 *
	 * @return int Number of sites the callback ran on.
	 */
	private function each_site( bool $network, callable $callback ): int {
		if ( $network ) {
			$visited = $this->network->each( $callback );
			if ( 0 === $visited ) {
				\WP_CLI::error( __( 'The plugin is not active on any site of the network.', 'rockfire-rest-api-cache' ) );
			}
			return $visited;
		}
		$callback( get_current_blog_id() );
		return 1;
	}

	/**
	 * Flush by one selector on the current site.
	 *
	 * @param string $selector `all`, `tag`, `request-url` or `route`.
	 * @param string $value    Selector value.
	 *
	 * @return int|\WP_Error Number of flushed entries.
	 */
	private function flush_selected( string $selector, string $value ): int|\WP_Error {
		switch ( $selector ) {
			case 'tag':
				return $this->cache->flush_tag( $value );
			case 'request-url':
				return $this->cache->flush_url( $value );
			case 'route':
				$view = $this->routes->resolve( $value );
				if ( null === $view ) {
					/* translators: %s: route or path the user entered */
					return new \WP_Error( 'rfrac_unknown_route', sprintf( __( 'Unknown route: %s', 'rockfire-rest-api-cache' ), $value ) );
				}
				return $this->cache->flush_route( $view->route );
			default:
				return $this->cache->flush_all();
		}
	}

	/**
	 * Success text of a flush.
	 *
	 * @param int $count Number of flushed responses.
	 *
	 * @return string
	 */
	private function flushed_message( int $count ): string {
		/* translators: %d: number of flushed cached responses */
		return sprintf( __( 'Flushed %d cached responses.', 'rockfire-rest-api-cache' ), $count );
	}

	/**
	 * Line prefix naming the current site in network mode.
	 *
	 * @param bool $network Whether the command runs over the network.
	 *
	 * @return string
	 */
	private function prefix( bool $network ): string {
		return $network ? '[' . site_url() . '] ' : '';
	}

	/**
	 * A listing row of one entry.
	 *
	 * @param Entry_Row $entry Entry.
	 *
	 * @return array<string,int|string|null>
	 */
	private function entry_to_row( Entry_Row $entry ): array {
		return array(
			'id'          => $entry->id,
			'route'       => $entry->route,
			'request'     => $entry->request,
			'state'       => $this->state_label( $entry ),
			'size'        => $entry->size,
			'hits'        => $entry->hits,
			'expires_at'  => $entry->expires_at,
			'method'      => $entry->method,
			'rule_key'    => $entry->rule_key,
			'created_at'  => $entry->created_at,
			'last_hit_at' => $entry->last_hit_at,
			'flushed_at'  => $entry->flushed_at,
		);
	}

	/**
	 * State label of an entry: `active`, `flushed` or `expired`.
	 *
	 * @param Entry_Row $entry Entry.
	 *
	 * @return string
	 */
	private function state_label( Entry_Row $entry ): string {
		if ( Entry_Row::STATE_FLUSHED === $entry->state ) {
			return 'flushed';
		}
		$expires = strtotime( $entry->expires_at . ' UTC' );
		return false !== $expires && $expires <= time() ? 'expired' : 'active';
	}

	/**
	 * Text form of a status value for a table cell.
	 *
	 * @param mixed $value Value.
	 *
	 * @return string
	 */
	private function cell( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( null === $value ) {
			return '';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		$json = wp_json_encode( $value );
		return false === $json ? '' : $json;
	}
}
