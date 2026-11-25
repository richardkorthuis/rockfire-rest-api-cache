<?php
/**
 * Store of the findings of the legacy import and compatibility layer.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Names;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the findings of the legacy layer per group in one non-autoloaded option.
 *
 * The option (`Names::OPTION_LEGACY_FINDINGS`) holds `{version:1, groups:{<id>:{time, meta, truncated,
 * findings:[…], overflow?:[…]}}}`. A group is one concern of the layer: the settings import, the
 * behaviour hooks, the load order, the endpoints, the classes, the pending notice. Per group the store
 * keeps at most {@see self::MAX_PER_GROUP} findings and counts the rest in `truncated`. It writes only
 * when the findings, their overflow or the meta of a group change, so a repeated run of the same check
 * never costs a database write. To keep that promise for the findings beyond the cap, the optional
 * `overflow` holds a short digest of the code and subject of up to {@see self::MAX_OVERFLOW_KEYS} of the
 * counted findings (absent while empty): such a finding is counted once, however often it is added.
 * Past that bound a counted finding is no longer remembered, so adding it again is counted and written
 * again. A malformed stored value reads as empty. Uninstalling removes the option with every other
 * option that starts with the plugin prefix.
 *
 * This store is not a failure context: a silent degradation of the engine goes to
 * {@see \Rockfire\Rest_Api_Cache\Failure_Recorder}, the findings describe what the legacy layer did
 * with the things of the original plugin.
 *
 * Naming rule, binding on everything under `includes/legacy/`: the identifiers of the original plugin
 * (constants, options, hooks, classes, file names) may appear in the files of this directory only.
 * Rockfire's own `Legacy\` classes use neutral names whose file paths never match the upstream
 * pattern of `bin/check-compliance.php`. A file under `includes/legacy/` whose path does match it
 * contains no gettext call, because every msgid lands in the shipped POT, which the pattern also
 * scans. Every msgid passes the identifiers of the original as `%s` (the display name "WP REST Cache"
 * is fine) and the identifier itself travels as the `subject` of a {@see Finding}, never as a msgid.
 *
 * @since 1.0.0
 *
 * @phpstan-import-type FindingData from Finding
 * @phpstan-type GroupData array{time:int, meta:array<string,mixed>, truncated:int, findings:list<FindingData>, overflow?:list<string>}
 */
final class Findings {

	/**
	 * Group: the import of the original's settings.
	 *
	 * @var string
	 */
	public const GROUP_IMPORT = 'import';

	/**
	 * Group: the behaviour hooks of the original.
	 *
	 * @var string
	 */
	public const GROUP_HOOKS = 'hooks';

	/**
	 * Group: the load order of the two plugins.
	 *
	 * @var string
	 */
	public const GROUP_LOAD_ORDER = 'load_order';

	/**
	 * Group: the endpoints of the original.
	 *
	 * @var string
	 */
	public const GROUP_ENDPOINTS = 'endpoints';

	/**
	 * Group: the classes of the original.
	 *
	 * @var string
	 */
	public const GROUP_CLASSES = 'classes';

	/**
	 * Group: the pending notice.
	 *
	 * @var string
	 */
	public const GROUP_NOTICE = 'notice';

	/**
	 * Maximum number of findings kept per group; the rest is counted in `truncated`.
	 *
	 * @var int
	 */
	public const MAX_PER_GROUP = 200;

	/**
	 * Maximum number of counted findings per group that are remembered, so that adding one again changes nothing.
	 *
	 * @var int
	 */
	public const MAX_OVERFLOW_KEYS = 1000;

	/**
	 * Version of the stored shape.
	 *
	 * @var int
	 */
	private const VERSION = 1;

	/**
	 * Name of the option holding the findings.
	 *
	 * @var string
	 */
	private string $option;

	/**
	 * Create the store.
	 *
	 * @param string $option Name of the option holding the findings.
	 */
	public function __construct( string $option = Names::OPTION_LEGACY_FINDINGS ) {
		$this->option = $option;
	}

	/**
	 * Replace the findings and the meta of a group.
	 *
	 * Findings beyond {@see self::MAX_PER_GROUP} are counted in `truncated` and, up to
	 * {@see self::MAX_OVERFLOW_KEYS} of them, remembered so that {@see self::add()} does not count them
	 * again. Nothing is written when the group already holds exactly these findings, this overflow and
	 * this meta.
	 *
	 * @param string               $group    Group id, `/^[a-z_]{1,32}$/`.
	 * @param Finding[]            $findings The findings.
	 * @param array<string, mixed> $meta     Data of the group (serialisable).
	 *
	 * @return bool Whether the group is stored as given: false for an invalid group id or a failed write.
	 */
	public function replace( string $group, array $findings, array $meta = array() ): bool {
		if ( ! self::valid_group( $group ) ) {
			return false;
		}

		$list      = array();
		$overflow  = array();
		$truncated = 0;
		foreach ( $findings as $finding ) {
			if ( count( $list ) < self::MAX_PER_GROUP ) {
				$list[] = $finding->to_array();
				continue;
			}

			++$truncated;
			$key = self::overflow_key( $finding );
			if ( count( $overflow ) < self::MAX_OVERFLOW_KEYS && ! in_array( $key, $overflow, true ) ) {
				$overflow[] = $key;
			}
		}

		$groups = $this->load();
		$stored = $groups[ $group ] ?? null;
		if (
			null !== $stored
			&& $stored['findings'] === $list
			&& $stored['truncated'] === $truncated
			&& ( $stored['overflow'] ?? array() ) === $overflow
			&& $stored['meta'] === $meta
		) {
			return true;
		}

		$new = array(
			'time'      => time(),
			'meta'      => $meta,
			'truncated' => $truncated,
			'findings'  => $list,
		);
		if ( array() !== $overflow ) {
			$new['overflow'] = $overflow;
		}

		$groups[ $group ] = $new;

		return $this->save( $groups );
	}

	/**
	 * Add one finding to a group, unless the group already has a finding with the same code and subject.
	 *
	 * A finding beyond {@see self::MAX_PER_GROUP} is counted in `truncated` and remembered (up to
	 * {@see self::MAX_OVERFLOW_KEYS} per group), so adding it again counts and writes nothing. Past that
	 * bound a counted finding is forgotten and adding it again counts and writes once more.
	 *
	 * @param string  $group   Group id, `/^[a-z_]{1,32}$/`.
	 * @param Finding $finding The finding.
	 *
	 * @return bool Whether the group holds the finding afterwards: false for an invalid group id or a failed write.
	 */
	public function add( string $group, Finding $finding ): bool {
		if ( ! self::valid_group( $group ) ) {
			return false;
		}

		$groups = $this->load();
		$stored = $groups[ $group ] ?? array(
			'time'      => time(),
			'meta'      => array(),
			'truncated' => 0,
			'findings'  => array(),
		);

		foreach ( $stored['findings'] as $existing ) {
			if ( $existing['code'] === $finding->code && $existing['subject'] === $finding->subject ) {
				return true;
			}
		}

		if ( count( $stored['findings'] ) < self::MAX_PER_GROUP ) {
			$stored['findings'][] = $finding->to_array();
		} else {
			$overflow = $stored['overflow'] ?? array();
			$key      = self::overflow_key( $finding );
			if ( in_array( $key, $overflow, true ) ) {
				return true;
			}

			++$stored['truncated'];
			if ( count( $overflow ) < self::MAX_OVERFLOW_KEYS ) {
				$overflow[]         = $key;
				$stored['overflow'] = $overflow;
			}
		}
		$stored['time'] = time();

		$groups[ $group ] = $stored;

		return $this->save( $groups );
	}

	/**
	 * The stored data of one group.
	 *
	 * @param string $group Group id.
	 *
	 * @return array{time:int, findings:list<Finding>, meta:array<string,mixed>, truncated:int}|null Null when the group has nothing stored.
	 */
	public function get( string $group ): ?array {
		$groups = $this->load();
		if ( ! isset( $groups[ $group ] ) ) {
			return null;
		}

		return self::hydrate( $groups[ $group ] );
	}

	/**
	 * The stored data of every group.
	 *
	 * @return array<string, array{time:int, findings:list<Finding>, meta:array<string,mixed>, truncated:int}>
	 */
	public function all(): array {
		$all = array();
		foreach ( $this->load() as $id => $group ) {
			$all[ $id ] = self::hydrate( $group );
		}

		return $all;
	}

	/**
	 * Every finding that needs attention (adjusted, skipped or unsupported), with its group.
	 *
	 * @return list<array{group:string, finding:Finding}>
	 */
	public function problems(): array {
		$problems = array();
		foreach ( $this->all() as $id => $group ) {
			foreach ( $group['findings'] as $finding ) {
				if ( $finding->is_problem() ) {
					$problems[] = array(
						'group'   => $id,
						'finding' => $finding,
					);
				}
			}
		}

		return $problems;
	}

	/**
	 * Remove the findings of one group, or of every group.
	 *
	 * @param string|null $group Group id; null removes everything, the option included.
	 *
	 * @return void
	 */
	public function clear( ?string $group = null ): void {
		if ( null === $group ) {
			delete_option( $this->option );
			return;
		}

		$groups = $this->load();
		if ( ! isset( $groups[ $group ] ) ) {
			return;
		}

		unset( $groups[ $group ] );
		if ( array() === $groups ) {
			delete_option( $this->option );
			return;
		}

		$this->save( $groups );
	}

	/**
	 * Whether a string is a valid group id.
	 *
	 * @param string $group The candidate.
	 *
	 * @return bool
	 */
	private static function valid_group( string $group ): bool {
		return 1 === preg_match( '/^[a-z_]{1,32}$/', $group );
	}

	/**
	 * The short digest under which a counted finding is remembered: 12 hex digits of its code and subject.
	 *
	 * @param Finding $finding The finding.
	 *
	 * @return string
	 */
	private static function overflow_key( Finding $finding ): string {
		return substr( md5( $finding->code . "\0" . $finding->subject ), 0, 12 );
	}

	/**
	 * Turn the stored form of a group into findings.
	 *
	 * @param array{time:int, meta:array<string,mixed>, truncated:int, findings:list<FindingData>, overflow?:list<string>} $group The stored group.
	 *
	 * @return array{time:int, findings:list<Finding>, meta:array<string,mixed>, truncated:int}
	 */
	private static function hydrate( array $group ): array {
		$findings = array();
		foreach ( $group['findings'] as $data ) {
			$finding = Finding::from_array( $data );
			if ( null !== $finding ) {
				$findings[] = $finding;
			}
		}

		return array(
			'time'      => $group['time'],
			'findings'  => $findings,
			'meta'      => $group['meta'],
			'truncated' => $group['truncated'],
		);
	}

	/**
	 * Read the option, validated: a malformed value, group or finding is dropped.
	 *
	 * @return array<string, GroupData>
	 */
	private function load(): array {
		$stored = get_option( $this->option, array() );
		if ( ! is_array( $stored ) || ( $stored['version'] ?? null ) !== self::VERSION ) {
			return array();
		}

		$raw = $stored['groups'] ?? null;
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$groups = array();
		foreach ( $raw as $id => $group ) {
			if ( ! is_string( $id ) || ! self::valid_group( $id ) ) {
				continue;
			}
			$normalized = self::normalize_group( $group );
			if ( null !== $normalized ) {
				$groups[ $id ] = $normalized;
			}
		}

		return $groups;
	}

	/**
	 * Validate the stored form of one group.
	 *
	 * @param mixed $group The stored value.
	 *
	 * @return GroupData|null Null when malformed.
	 */
	private static function normalize_group( mixed $group ): ?array {
		if ( ! is_array( $group ) ) {
			return null;
		}

		$time      = $group['time'] ?? null;
		$truncated = $group['truncated'] ?? null;
		$meta      = $group['meta'] ?? null;
		$findings  = $group['findings'] ?? null;
		if ( ! is_int( $time ) || ! is_int( $truncated ) || $truncated < 0 || ! is_array( $meta ) || ! is_array( $findings ) ) {
			return null;
		}

		$clean_meta = array();
		foreach ( $meta as $key => $value ) {
			if ( is_string( $key ) ) {
				$clean_meta[ $key ] = $value;
			}
		}

		$list = array();
		foreach ( $findings as $data ) {
			$finding = Finding::from_array( $data );
			if ( null !== $finding ) {
				$list[] = $finding->to_array();
			}
		}

		$overflow = array();
		$raw_keys = $group['overflow'] ?? null;
		if ( is_array( $raw_keys ) ) {
			foreach ( $raw_keys as $key ) {
				if ( count( $overflow ) >= self::MAX_OVERFLOW_KEYS ) {
					break;
				}
				if ( is_string( $key ) && 1 === preg_match( '/^[0-9a-f]{12}$/', $key ) && ! in_array( $key, $overflow, true ) ) {
					$overflow[] = $key;
				}
			}
		}

		$normalized = array(
			'time'      => $time,
			'meta'      => $clean_meta,
			'truncated' => $truncated,
			'findings'  => $list,
		);
		if ( array() !== $overflow ) {
			$normalized['overflow'] = $overflow;
		}

		return $normalized;
	}

	/**
	 * Write the groups, never autoloaded.
	 *
	 * @param array<string, GroupData> $groups The groups.
	 *
	 * @return bool Whether the option holds the groups afterwards.
	 */
	private function save( array $groups ): bool {
		$value = array(
			'version' => self::VERSION,
			'groups'  => $groups,
		);

		// update_option() reports false for an unchanged value as well as for a failed write.
		return update_option( $this->option, $value, false ) || get_option( $this->option ) === $value;
	}
}
