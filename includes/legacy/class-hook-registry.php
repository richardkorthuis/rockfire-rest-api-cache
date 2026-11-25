<?php
/**
 * The registry of the hooks of the original plugin.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies every `wp_rest_cache/*` hook of the original plugin exactly once: bridged onto a Rockfire seam, or not
 * bridged for a reason.
 *
 * The registry is the single source of the hook bridges and the migration findings; the hooks reference on rockfire.nl
 * documents the same classification. It is pure data: it fires no hook, and only {@see self::hooked_unsupported()}
 * calls WordPress. A bridged hook carries the phase of the engine it belongs to, the Rockfire hook that replaces it
 * (`''` when there is none) and a note on what the bridge cannot give the old callback (`''` when nothing is lost).
 * Dynamic segments of a name are written
 * `{meta_type}`, as in {@see Hooks}.
 *
 * @since 1.0.0
 *
 * @phpstan-type Bridged_Entry array{kind:'filter'|'action',phase:string,replacement:string,lossy:string}
 * @phpstan-type Unsupported_Entry array{kind:'filter'|'action',reason:string,note:string}
 */
final class Hook_Registry {

	/**
	 * The prefix of every hook of the original.
	 *
	 * @var string
	 */
	public const PREFIX = 'wp_rest_cache/';

	/**
	 * The release in which the bridged hooks are reported as deprecated.
	 *
	 * @var string
	 */
	public const DEPRECATED_SINCE = '1.0.0';

	/**
	 * The constant that makes the legacy filters of a cache hit run per request.
	 *
	 * @var string
	 */
	public const HIT_FILTERS_CONSTANT = 'RFRAC_LEGACY_HIT_FILTERS';

	/**
	 * Phase: the hook is compiled into the configuration.
	 *
	 * @var string
	 */
	public const PHASE_COMPILE = 'compile';

	/**
	 * Phase: the hook belongs to storing a response.
	 *
	 * @var string
	 */
	public const PHASE_STORE = 'store';

	/**
	 * Phase: the hook belongs to the tags collected for a response.
	 *
	 * @var string
	 */
	public const PHASE_TAGGING = 'tagging';

	/**
	 * Phase: the hook belongs to invalidating entries.
	 *
	 * @var string
	 */
	public const PHASE_INVALIDATION = 'invalidation';

	/**
	 * Phase: the hook belongs to the admin screens.
	 *
	 * @var string
	 */
	public const PHASE_ADMIN = 'admin';

	/**
	 * Phase: the hook belongs to serving a cache hit.
	 *
	 * @var string
	 */
	public const PHASE_HIT = 'hit';

	/**
	 * Reason: the hook belongs to an admin screen that was rebuilt.
	 *
	 * @var string
	 */
	public const REASON_ADMIN_UI = 'admin_ui';

	/**
	 * Reason: the hook belongs to a feature that was removed.
	 *
	 * @var string
	 */
	public const REASON_REMOVED = 'removed_feature';

	/**
	 * Reason: Rockfire has no place where the hook could be fired.
	 *
	 * @var string
	 */
	public const REASON_NO_SEAM = 'no_seam';

	/**
	 * Reason: the feature behind the hook is planned for a later release.
	 *
	 * @var string
	 */
	public const REASON_DEFERRED = 'deferred';

	/**
	 * Note of the hooks that run when a change is queued instead of right before entries are deleted.
	 *
	 * @var string
	 */
	private const TIMING_NOTE = 'Fires when queued.';

	/**
	 * Note of the hooks of the tagging phase: there is no cache row yet.
	 *
	 * @var string
	 */
	private const TAGGING_NOTE = 'Cache ID is 0.';

	/**
	 * Note of the hit hooks that always depend on the request, so they only run per request with the constant.
	 *
	 * @var string
	 */
	private const HIT_NOTE = 'Needs ' . self::HIT_FILTERS_CONSTANT . '.';

	/**
	 * Note of the hit hooks that are compiled into the configuration without the constant: only a callback that
	 * depends on the request needs it.
	 *
	 * @var string
	 */
	private const COMPILED_HIT_NOTE = 'Compiled in; a callback that depends on the request needs ' . self::HIT_FILTERS_CONSTANT . '.';

	/**
	 * Note of the nonce hook: inverted, and only a constant callback is carried over while Rockfire has not decided.
	 *
	 * @var string
	 */
	private const NONCE_NOTE = 'Inverted; only a constant callback carries over, and only while no setting or constant of this plugin decides; logged-in requests are never cached.';

	/**
	 * The bridged hooks, by full name.
	 *
	 * @var array<string,array<string,string>>
	 * @phpstan-var array<string,Bridged_Entry>
	 */
	private const BRIDGED = array(
		'wp_rest_cache/allowed_endpoints'               => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_COMPILE,
			'replacement' => Hooks::ROUTE_RULES,
			'lossy'       => '',
		),
		'wp_rest_cache/disallowed_endpoints'            => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_COMPILE,
			'replacement' => Hooks::ROUTE_RULES,
			'lossy'       => '',
		),
		'wp_rest_cache/cacheable_request_headers'       => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_COMPILE,
			'replacement' => Hooks::ROUTE_RULES,
			'lossy'       => '',
		),
		'wp_rest_cache/uncached_parameters'             => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_COMPILE,
			'replacement' => Hooks::GLOBAL_IGNORE_PARAMS,
			'lossy'       => '',
		),
		'wp_rest_cache/skip_nonce_caching'              => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_COMPILE,
			'replacement' => Hooks::NONCE_CACHING,
			'lossy'       => self::NONCE_NOTE,
		),
		'wp_rest_cache/timeout'                         => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_STORE,
			'replacement' => Hooks::ENTRY_TTL,
			'lossy'       => 'Empty object type and request headers.',
		),
		'wp_rest_cache/skip_empty_result_set'           => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_STORE,
			'replacement' => Hooks::CACHE_EMPTY_RESULTS,
			'lossy'       => 'Inverted.',
		),
		'wp_rest_cache/determine_object_type'           => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_TAGGING,
			'replacement' => Hooks::COLLECTED_TAGS,
			'lossy'       => self::TAGGING_NOTE,
		),
		'wp_rest_cache/is_single_item'                  => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_TAGGING,
			'replacement' => Hooks::COLLECTED_TAGS,
			'lossy'       => self::TAGGING_NOTE,
		),
		'wp_rest_cache/process_cache_relations'         => array(
			'kind'        => 'action',
			'phase'       => self::PHASE_TAGGING,
			'replacement' => Hooks::COLLECTED_TAGS,
			'lossy'       => self::TAGGING_NOTE,
		),
		'wp_rest_cache/insert_cache_relation'           => array(
			'kind'        => 'action',
			'phase'       => self::PHASE_TAGGING,
			'replacement' => Hooks::COLLECTED_TAGS,
			'lossy'       => self::TAGGING_NOTE,
		),
		'wp_rest_cache/flush_on_meta_update'            => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_INVALIDATION,
			'replacement' => Hooks::FLUSH_ON_META_UPDATE,
			'lossy'       => 'Meta ID is 0; now true for REST keys.',
		),
		'wp_rest_cache/flush_on_meta_update/{meta_type}/{meta_key}' => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_INVALIDATION,
			'replacement' => Hooks::FLUSH_ON_META_UPDATE_KEY,
			'lossy'       => 'Meta ID is 0.',
		),
		'wp_rest_cache/flush_on_set_terms'              => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_INVALIDATION,
			'replacement' => Hooks::FLUSH_ON_SET_TERMS,
			'lossy'       => '',
		),
		'wp_rest_cache/pre_delete_related_caches'       => array(
			'kind'        => 'action',
			'phase'       => self::PHASE_INVALIDATION,
			'replacement' => Hooks::INVALIDATION_TAGS,
			'lossy'       => self::TIMING_NOTE,
		),
		'wp_rest_cache/pre_delete_related_caches_batch' => array(
			'kind'        => 'action',
			'phase'       => self::PHASE_INVALIDATION,
			'replacement' => Hooks::INVALIDATION_TAGS,
			'lossy'       => self::TIMING_NOTE,
		),
		'wp_rest_cache/pre_delete_object_type_caches'   => array(
			'kind'        => 'action',
			'phase'       => self::PHASE_INVALIDATION,
			'replacement' => Hooks::INVALIDATION_TAGS,
			'lossy'       => self::TIMING_NOTE,
		),
		'wp_rest_cache/settings_capability'             => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_ADMIN,
			'replacement' => Hooks::SETTINGS_CAPABILITY,
			'lossy'       => 'Default manage_options.',
		),
		'wp_rest_cache/allow_skip_cache'                => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_HIT,
			'replacement' => Hooks::ALLOW_SKIP_CACHE,
			'lossy'       => 'Default false.',
		),
		'wp_rest_cache/disable_cors_headers'            => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_HIT,
			'replacement' => Hooks::CORS_ON_HITS,
			'lossy'       => 'Inverted. ' . self::COMPILED_HIT_NOTE,
		),
		'wp_rest_cache/skip_caching'                    => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_HIT,
			'replacement' => Hooks::BYPASS_ALL,
			'lossy'       => self::COMPILED_HIT_NOTE,
		),
		'wp_rest_cache/cache_key'                       => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_HIT,
			'replacement' => '',
			'lossy'       => self::HIT_NOTE,
		),
		'wp_rest_cache/filter_cache_output'             => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_HIT,
			'replacement' => '',
			'lossy'       => self::HIT_NOTE,
		),
		'wp_rest_cache/pre_output_headers'              => array(
			'kind'        => 'filter',
			'phase'       => self::PHASE_HIT,
			'replacement' => '',
			'lossy'       => self::HIT_NOTE,
		),
	);

	/**
	 * The hooks that are never fired, by full name.
	 *
	 * @var array<string,array<string,string>>
	 * @phpstan-var array<string,Unsupported_Entry>
	 */
	private const NOT_BRIDGED = array(
		'wp_rest_cache/allowed_request_methods'        => array(
			'kind'   => 'filter',
			'reason' => self::REASON_NO_SEAM,
			'note'   => 'Request methods are chosen per route (methods in a route rule, or REST API Cache → Routes); no filter changes them for every route.',
		),
		'wp_rest_cache/cache_headers'                  => array(
			'kind'   => 'filter',
			'reason' => self::REASON_NO_SEAM,
			'note'   => 'Use rfrac/replay_headers.',
		),
		'wp_rest_cache/cache_header'                   => array(
			'kind'   => 'filter',
			'reason' => self::REASON_NO_SEAM,
			'note'   => 'Stored headers are fixed.',
		),
		'wp_rest_cache/cache_hit_recording'            => array(
			'kind'   => 'filter',
			'reason' => self::REASON_REMOVED,
			'note'   => 'Hits are not counted.',
		),
		'wp_rest_cache/skip_cache_regeneration'        => array(
			'kind'   => 'filter',
			'reason' => self::REASON_REMOVED,
			'note'   => 'Nothing is regenerated.',
		),
		'wp_rest_cache/delete_caches_immediately'      => array(
			'kind'   => 'filter',
			'reason' => self::REASON_REMOVED,
			'note'   => 'No cleanup queue.',
		),
		'wp_rest_cache/max_cleanup_caches'             => array(
			'kind'   => 'filter',
			'reason' => self::REASON_REMOVED,
			'note'   => 'No cleanup queue.',
		),
		'wp_rest_cache/deleted_caches'                 => array(
			'kind'   => 'action',
			'reason' => self::REASON_REMOVED,
			'note'   => 'Use rfrac/flushed.',
		),
		'wp_rest_cache/filtered_cache_keys'            => array(
			'kind'   => 'filter',
			'reason' => self::REASON_REMOVED,
			'note'   => 'Flush by tag or route.',
		),
		'wp_rest_cache/cache_authenticated_response'   => array(
			'kind'   => 'filter',
			'reason' => self::REASON_REMOVED,
			'note'   => 'Logged-in responses are never stored.',
		),
		'wp_rest_cache/purge_stale_caches'             => array(
			'kind'   => 'filter',
			'reason' => self::REASON_REMOVED,
			'note'   => 'Maintenance prunes hourly.',
		),
		'wp_rest_cache/stale_cache_grace_period'       => array(
			'kind'   => 'filter',
			'reason' => self::REASON_REMOVED,
			'note'   => 'Maintenance prunes hourly.',
		),
		'wp_rest_cache/max_purge_caches'               => array(
			'kind'   => 'filter',
			'reason' => self::REASON_REMOVED,
			'note'   => 'Maintenance prunes hourly.',
		),
		'wp_rest_cache/settings_panels'                => array(
			'kind'   => 'filter',
			'reason' => self::REASON_ADMIN_UI,
			'note'   => 'Not extensible.',
		),
		'wp_rest_cache/api_caches_table_data'          => array(
			'kind'   => 'filter',
			'reason' => self::REASON_ADMIN_UI,
			'note'   => 'Not extensible.',
		),
		'wp_rest_cache/api_caches_table_columns'       => array(
			'kind'   => 'filter',
			'reason' => self::REASON_ADMIN_UI,
			'note'   => 'Not extensible.',
		),
		'wp_rest_cache/api_caches_table_column_output' => array(
			'kind'   => 'filter',
			'reason' => self::REASON_ADMIN_UI,
			'note'   => 'Not extensible.',
		),
		'wp_rest_cache/cache_details_info_rows'        => array(
			'kind'   => 'action',
			'reason' => self::REASON_ADMIN_UI,
			'note'   => 'Not extensible.',
		),
		'wp_rest_cache/cache_details_request_headers'  => array(
			'kind'   => 'filter',
			'reason' => self::REASON_ADMIN_UI,
			'note'   => 'Not extensible.',
		),
		'wp_rest_cache/clear_cache_form_options'       => array(
			'kind'   => 'action',
			'reason' => self::REASON_ADMIN_UI,
			'note'   => 'Not extensible.',
		),
		'wp_rest_cache/display_clear_cache_button'     => array(
			'kind'   => 'filter',
			'reason' => self::REASON_ADMIN_UI,
			'note'   => 'Use rfrac/show_admin_bar_flush.',
		),
	);

	/**
	 * The hooks that are bridged onto a Rockfire seam.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,array<string,string>> Full legacy name => `kind` (`filter` or `action`), `phase`, `replacement`
	 *                                            (a Rockfire hook, `''` when there is none) and `lossy` (what the old
	 *                                            callback cannot get, `''` when nothing is lost).
	 * @phpstan-return array<string,Bridged_Entry>
	 */
	public static function bridged(): array {
		return self::BRIDGED;
	}

	/**
	 * The hooks that are never fired.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,array<string,string>> Full legacy name => `kind` (`filter` or `action`), `reason` (one of the
	 *                                            REASON_* constants) and `note` (what to do instead).
	 * @phpstan-return array<string,Unsupported_Entry>
	 */
	public static function not_bridged(): array {
		return self::NOT_BRIDGED;
	}

	/**
	 * Whether the site applies the legacy hit filters on every hit: the constant is defined and is not `false`.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function hit_filters_enabled(): bool {
		return defined( self::HIT_FILTERS_CONSTANT ) && false !== constant( self::HIT_FILTERS_CONSTANT );
	}

	/**
	 * The hooks that run on a cache hit, which need `RFRAC_LEGACY_HIT_FILTERS` to run per request.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string> Full legacy names.
	 */
	public static function hit_filters(): array {
		$names = array();
		foreach ( self::BRIDGED as $name => $entry ) {
			if ( self::PHASE_HIT === $entry['phase'] ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/**
	 * The Rockfire hook that replaces a legacy hook.
	 *
	 * A concrete dynamic name such as `wp_rest_cache/flush_on_meta_update/post/_price` resolves through the
	 * `{meta_type}`/`{meta_key}` entry.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Full legacy name.
	 *
	 * @return string|null The replacement, `''` when the hook is bridged without one, null when it is not bridged.
	 */
	public static function replacement( string $name ): ?string {
		if ( isset( self::BRIDGED[ $name ] ) ) {
			return self::BRIDGED[ $name ]['replacement'];
		}

		foreach ( self::BRIDGED as $pattern => $entry ) {
			if ( ! str_contains( $pattern, '{' ) ) {
				continue;
			}
			if ( 1 === preg_match( self::dynamic_regex( $pattern ), $name ) ) {
				return $entry['replacement'];
			}
		}
		return null;
	}

	/**
	 * The hooks that are never fired but have a callback.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string> Full legacy names.
	 */
	public static function hooked_unsupported(): array {
		$hooked = array();
		foreach ( array_keys( self::NOT_BRIDGED ) as $name ) {
			if ( false !== has_filter( $name ) ) {
				$hooked[] = $name;
			}
		}
		return $hooked;
	}

	/**
	 * The expression that matches the concrete names of a dynamic hook name.
	 *
	 * A segment `{type}` matches one path segment, a placeholder at the end of the name the rest of it (a meta
	 * key can hold a slash).
	 *
	 * @param string $pattern Hook name with `{segment}` placeholders.
	 *
	 * @return string
	 */
	private static function dynamic_regex( string $pattern ): string {
		$parts = preg_split( '/(\{[a-z_]+\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$parts = false === $parts ? array() : $parts;
		$last  = count( $parts ) - 1;

		$regex = '';
		foreach ( $parts as $index => $part ) {
			if ( '{' === $part[0] ) {
				$regex .= $index === $last ? '.+' : '[^/]+';
			} else {
				$regex .= preg_quote( $part, '#' );
			}
		}
		return '#^' . $regex . '$#D';
	}
}
