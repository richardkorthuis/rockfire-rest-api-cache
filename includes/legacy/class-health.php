<?php
/**
 * Site Health test and debug information section of the legacy layer.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Admin\Admin_Url;

defined( 'ABSPATH' ) || exit;

/**
 * Shows in Site Health which constants of the original plugin are honoured, shadowed, invalid or without
 * effect, and every stored finding of the legacy layer that does not behave as before.
 *
 * The test only exists on a site that carries a trace of the original plugin, so a site that never used it
 * shows nothing. The names of the original plugin travel as `%s` arguments of the messages and as escaped
 * data, never inside a message id. {@see \Rockfire\Rest_Api_Cache\Plugin::register_health_hooks()} chains this class after
 * {@see \Rockfire\Rest_Api_Cache\Health\Site_Health} inside its own closures; the registered hooks do not change.
 *
 * @since 1.0.0
 */
final class Health {

	/**
	 * Key of the direct test.
	 *
	 * @var string
	 */
	public const TEST = 'rfrac_legacy';

	/**
	 * Key of the section in the debug information (a sibling of the core section `rockfire-rest-api-cache`).
	 *
	 * @var string
	 */
	public const DEBUG_SECTION = 'rockfire-rest-api-cache-legacy';

	/**
	 * Slug of the import page.
	 *
	 * @var string
	 */
	private const IMPORT_PAGE = 'rfrac-import';

	/**
	 * Constant that replaces both constants of the cache lifetime.
	 *
	 * @var string
	 */
	private const TTL_REPLACEMENT = 'RFRAC_DEFAULT_TTL';

	/**
	 * Constant that raises the upper bound of the cache lifetime.
	 *
	 * @var string
	 */
	private const MAX_TTL_CONSTANT = 'RFRAC_MAX_TTL';

	/**
	 * The findings store.
	 *
	 * @var Findings
	 */
	private Findings $findings;

	/**
	 * The constants of the original as a fallback.
	 *
	 * @var Constant_Fallback
	 */
	private Constant_Fallback $constants;

	/**
	 * The detector of the original plugin.
	 *
	 * @var Detector
	 */
	private Detector $detector;

	/**
	 * Create the Site Health integration.
	 *
	 * @param Findings          $findings  The findings store.
	 * @param Constant_Fallback $constants The constants of the original as a fallback.
	 * @param Detector          $detector  The detector of the original plugin.
	 */
	public function __construct( Findings $findings, Constant_Fallback $constants, Detector $detector ) {
		$this->findings  = $findings;
		$this->constants = $constants;
		$this->detector  = $detector;
	}

	/**
	 * Add the direct test (filter `site_status_tests`), only when the site carries a trace of the original plugin.
	 *
	 * A trace is the plugin itself, its leftovers, a constant of it or any stored finding group.
	 *
	 * @param mixed $tests Tests.
	 *
	 * @return array<mixed>
	 */
	public function register_tests( mixed $tests ): array {
		$tests = is_array( $tests ) ? $tests : array();
		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}

		if ( $this->has_trace() ) {
			$tests['direct'][ self::TEST ] = array(
				'label' => __( 'WP REST Cache compatibility', 'rockfire-rest-api-cache' ),
				'test'  => array( $this, 'test_legacy' ),
			);
		}

		return $tests;
	}

	/**
	 * Test: nothing of the original plugin needs attention.
	 *
	 * Recommended when a constant of the original is defined or a stored finding needs attention (adjusted,
	 * skipped or unsupported, except the standing note about stricter requests that every import carries, the
	 * same definition as {@see Import_Page::attention()}); good otherwise.
	 *
	 * @return array<string,mixed>
	 */
	public function test_legacy(): array {
		$report   = $this->constants->report();
		$problems = $this->attention_problems();

		if ( array() === $report && array() === $problems ) {
			return $this->result(
				'good',
				__( 'Nothing of WP REST Cache needs attention', 'rockfire-rest-api-cache' ),
				'<p>' . esc_html__( 'No constant of WP REST Cache is defined, and every finding of the import and the compatibility layer was imported or kept as it was.', 'rockfire-rest-api-cache' ) . '</p>'
			);
		}

		$description = '<p>' . esc_html__( 'Some of what WP REST Cache left on this site needs your attention.', 'rockfire-rest-api-cache' ) . '</p>';
		if ( array() !== $report ) {
			$description .= '<p>' . esc_html__( 'These constants of WP REST Cache are defined:', 'rockfire-rest-api-cache' ) . '</p><ul>';
			foreach ( $report as $entry ) {
				$description .= '<li>' . $this->constant_line( $entry['constant'], $entry['replacement'], $entry['state'] ) . '</li>';
			}
			$description .= '</ul>';
		}
		if ( array() !== $problems ) {
			$description .= '<p>' . esc_html__( 'These findings do not behave as before:', 'rockfire-rest-api-cache' ) . '</p><ul>';
			foreach ( $problems as $problem ) {
				$description .= '<li>' . $this->problem_line( $problem['group'], $problem['finding'] ) . '</li>';
			}
			$description .= '</ul>';
		}

		return $this->result(
			'recommended',
			__( 'WP REST Cache left settings that need attention', 'rockfire-rest-api-cache' ),
			$description
		);
	}

	/**
	 * Add the debug information section (filter `debug_information`).
	 *
	 * @param mixed $info Debug information.
	 *
	 * @return array<mixed>
	 */
	public function debug_information( mixed $info ): array {
		$info = is_array( $info ) ? $info : array();

		$info[ self::DEBUG_SECTION ] = array(
			'label'  => __( 'Rockfire – REST API Cache: WP REST Cache', 'rockfire-rest-api-cache' ),
			'fields' => array(
				'original'    => array(
					'label' => __( 'WP REST Cache', 'rockfire-rest-api-cache' ),
					'value' => $this->original_state(),
				),
				'last_import' => array(
					'label' => __( 'Last import', 'rockfire-rest-api-cache' ),
					'value' => $this->last_import(),
				),
				'constants'   => array(
					'label' => __( 'Constants', 'rockfire-rest-api-cache' ),
					'value' => $this->constants_summary(),
				),
				'findings'    => array(
					'label' => __( 'Findings', 'rockfire-rest-api-cache' ),
					'value' => $this->findings_summary(),
				),
			),
		);

		return $info;
	}

	/**
	 * Every stored finding that needs attention, with its group: the problems, without the standing note about
	 * stricter requests that every import carries ({@see Import_Page::attention()} is the definition).
	 *
	 * @return list<array{group:string, finding:Finding}>
	 */
	private function attention_problems(): array {
		$problems = array();
		foreach ( $this->findings->problems() as $problem ) {
			if ( Import_Page::attention( array( $problem['finding'] ) ) > 0 ) {
				$problems[] = $problem;
			}
		}

		return $problems;
	}

	/**
	 * Whether anything of the original plugin is present, or the legacy layer stored anything.
	 *
	 * @return bool
	 */
	private function has_trace(): bool {
		return $this->detector::present() || array() !== $this->findings->all() || array() !== $this->constants->report();
	}

	/**
	 * One constant of the original, as a list item body.
	 *
	 * @param string      $constant    The constant of the original.
	 * @param string|null $replacement The constant of the plugin that replaces it, if any.
	 * @param string      $state       One of the `Constant_Fallback::STATE_*` states.
	 *
	 * @return string HTML, escaped.
	 */
	private function constant_line( string $constant, ?string $replacement, string $state ): string {
		$name = '<code>' . esc_html( $constant ) . '</code>';
		if ( null === $replacement ) {
			return sprintf(
				/* translators: %s: constant of WP REST Cache. */
				esc_html__( '%s has no effect: the feature has no counterpart in this plugin.', 'rockfire-rest-api-cache' ),
				$name
			);
		}

		$other = '<code>' . esc_html( $replacement ) . '</code>';
		if ( Constant_Fallback::STATE_SHADOWED === $state ) {
			return sprintf(
				/* translators: 1: constant of WP REST Cache, 2: constant of this plugin that replaces it. */
				esc_html__( '%1$s is ignored because %2$s is defined and wins.', 'rockfire-rest-api-cache' ),
				$name,
				$other
			);
		}
		if ( Constant_Fallback::STATE_INVALID === $state ) {
			return sprintf(
				/* translators: 1: constant of WP REST Cache, 2: constant of this plugin that replaces it. */
				esc_html__( '%1$s has an invalid value and is ignored. Fix its value or define %2$s instead.', 'rockfire-rest-api-cache' ),
				$name,
				$other
			);
		}

		$line = sprintf(
			/* translators: 1: constant of WP REST Cache, 2: constant of this plugin that replaces it. */
			esc_html__( '%1$s still works. Define %2$s instead.', 'rockfire-rest-api-cache' ),
			$name,
			$other
		);
		if ( self::TTL_REPLACEMENT === $replacement ) {
			$line .= ' ' . sprintf(
				/* translators: %s: constant of this plugin that raises the maximum TTL. */
				esc_html__( 'The value is limited by the maximum TTL: raise Max TTL or %s when it is clamped.', 'rockfire-rest-api-cache' ),
				'<code>' . esc_html( self::MAX_TTL_CONSTANT ) . '</code>'
			);
		}

		return $line;
	}

	/**
	 * One finding that does not behave as before, as a list item body.
	 *
	 * @param string  $group   The id of the group of the finding.
	 * @param Finding $finding The finding.
	 *
	 * @return string HTML, escaped.
	 */
	private function problem_line( string $group, Finding $finding ): string {
		$label   = esc_html( $this->group_label( $group ) );
		$subject = '' !== $finding->subject ? '<code>' . esc_html( $finding->subject ) . '</code>' : $label;

		return $subject . ' (' . $label . ', ' . esc_html( $this->status_label( $finding->status ) ) . '): ' . esc_html( $finding->message );
	}

	/**
	 * The state of the original plugin on this site.
	 *
	 * @return string
	 */
	private function original_state(): string {
		if ( $this->detector::original_active() ) {
			return __( 'Active', 'rockfire-rest-api-cache' );
		}
		if ( $this->detector::has_leftovers() ) {
			return __( 'Inactive, with leftovers', 'rockfire-rest-api-cache' );
		}

		return __( 'Not found', 'rockfire-rest-api-cache' );
	}

	/**
	 * When the last import ran and what it reported.
	 *
	 * @return string
	 */
	private function last_import(): string {
		$group = $this->findings->get( Findings::GROUP_IMPORT );
		if ( null === $group ) {
			return __( 'Never', 'rockfire-rest-api-cache' );
		}

		$parts = array();
		foreach ( Finding::STATUSES as $status ) {
			$count = $group['meta'][ $status ] ?? null;
			if ( is_int( $count ) ) {
				$parts[] = $this->status_label( $status ) . ' ' . $count;
			}
		}
		$result = $group['meta']['result'] ?? null;
		if ( is_string( $result ) && '' !== $result ) {
			$parts[] = sprintf(
				/* translators: %s: result of the import, e.g. compiled or unchanged. */
				__( 'result: %s', 'rockfire-rest-api-cache' ),
				$result
			);
		}

		$time = gmdate( 'Y-m-d H:i:s', $group['time'] ) . ' UTC';

		return array() === $parts ? $time : $time . ' (' . implode( ', ', $parts ) . ')';
	}

	/**
	 * The constants of the original that are defined, with their state.
	 *
	 * @return string
	 */
	private function constants_summary(): string {
		$items = array();
		foreach ( $this->constants->report() as $entry ) {
			if ( null === $entry['replacement'] ) {
				$items[] = $entry['constant'] . ' (' . $this->state_label( $entry['state'] ) . ')';
				continue;
			}
			$items[] = sprintf(
				/* translators: 1: constant of WP REST Cache, 2: its state, 3: constant of this plugin that replaces it. */
				__( '%1$s (%2$s, replaced by %3$s)', 'rockfire-rest-api-cache' ),
				$entry['constant'],
				$this->state_label( $entry['state'] ),
				$entry['replacement']
			);
		}

		return array() === $items ? __( 'None', 'rockfire-rest-api-cache' ) : implode( ', ', $items );
	}

	/**
	 * How many findings every group holds, and how many of them need attention.
	 *
	 * @return string
	 */
	private function findings_summary(): string {
		$items = array();
		foreach ( $this->findings->all() as $id => $group ) {
			$items[] = sprintf(
				/* translators: 1: group of findings, 2: number of findings in the group, 3: number of them that need attention. */
				__( '%1$s: %2$d (%3$d need attention)', 'rockfire-rest-api-cache' ),
				$this->group_label( $id ),
				count( $group['findings'] ) + $group['truncated'],
				Import_Page::attention( $group['findings'] )
			);
		}

		return array() === $items ? __( 'None', 'rockfire-rest-api-cache' ) : implode( '; ', $items );
	}

	/**
	 * The label of a group of findings.
	 *
	 * @param string $group The group id.
	 *
	 * @return string
	 */
	private function group_label( string $group ): string {
		return match ( $group ) {
			Findings::GROUP_IMPORT     => __( 'Import', 'rockfire-rest-api-cache' ),
			Findings::GROUP_HOOKS      => __( 'Hooks', 'rockfire-rest-api-cache' ),
			Findings::GROUP_LOAD_ORDER => __( 'Load order', 'rockfire-rest-api-cache' ),
			Findings::GROUP_ENDPOINTS  => __( 'Endpoints', 'rockfire-rest-api-cache' ),
			Findings::GROUP_CLASSES    => __( 'Classes', 'rockfire-rest-api-cache' ),
			Findings::GROUP_NOTICE     => __( 'Notice', 'rockfire-rest-api-cache' ),
			default                    => $group,
		};
	}

	/**
	 * The label of the status of a finding.
	 *
	 * @param string $status One of {@see Finding::STATUSES}.
	 *
	 * @return string
	 */
	private function status_label( string $status ): string {
		return match ( $status ) {
			Finding::IMPORTED    => __( 'imported', 'rockfire-rest-api-cache' ),
			Finding::KEPT        => __( 'kept', 'rockfire-rest-api-cache' ),
			Finding::ADJUSTED    => __( 'adjusted', 'rockfire-rest-api-cache' ),
			Finding::SKIPPED     => __( 'skipped', 'rockfire-rest-api-cache' ),
			Finding::UNSUPPORTED => __( 'unsupported', 'rockfire-rest-api-cache' ),
			default              => $status,
		};
	}

	/**
	 * The label of the state of a constant.
	 *
	 * @param string $state One of the `Constant_Fallback::STATE_*` states.
	 *
	 * @return string
	 */
	private function state_label( string $state ): string {
		return match ( $state ) {
			Constant_Fallback::STATE_HONOURED  => __( 'honoured', 'rockfire-rest-api-cache' ),
			Constant_Fallback::STATE_SHADOWED  => __( 'shadowed', 'rockfire-rest-api-cache' ),
			Constant_Fallback::STATE_INVALID   => __( 'invalid', 'rockfire-rest-api-cache' ),
			Constant_Fallback::STATE_NO_EFFECT => __( 'no effect', 'rockfire-rest-api-cache' ),
			default                            => $state,
		};
	}

	/**
	 * Build a test result, shaped like the ones of {@see \Rockfire\Rest_Api_Cache\Health\Site_Health}.
	 *
	 * @param string $status      `good`, `recommended` or `critical`.
	 * @param string $label       Label.
	 * @param string $description Description (HTML, already escaped).
	 *
	 * @return array<string,mixed>
	 */
	private function result( string $status, string $label, string $description ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Performance', 'rockfire-rest-api-cache' ),
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( Admin_Url::page( self::IMPORT_PAGE ) ),
				esc_html__( 'Open the import page', 'rockfire-rest-api-cache' )
			),
			'test'        => self::TEST,
		);
	}
}
