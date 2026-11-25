<?php
/**
 * The Import page: preview, import, stored report and the offer to deactivate the original plugin.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Admin\Dismissals;
use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Admin\Pages\Page_Interface;
use Rockfire\Rest_Api_Cache\Config\Validation_Result;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a site owner preview what importing the settings of WP REST Cache would do, import them with one click,
 * read the stored report and the other findings of the legacy layer, and follow WordPress's own link to deactivate the
 * original plugin afterwards.
 *
 * The page only exists while the original plugin or something it left behind is present, or a finding is stored
 * ({@see self::available()}). The report is read from the `import` group of {@see Findings}, never from the flash
 * notices: those only carry a one-line summary. A page never checks the plugin capability; the admin does, and so
 * does every action through its handler. The original is deactivated by the nonce'd link of the Plugins screen
 * ({@see self::deactivate_url()}), which checks the permission itself; nothing here deactivates a plugin.
 *
 * @since 1.0.0
 */
final class Import_Page implements Page_Interface {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'rfrac-import';

	/**
	 * Deactivate offer: nothing to offer this user.
	 *
	 * @var string
	 */
	public const OFFER_NONE = 'none';

	/**
	 * Deactivate offer: the user may deactivate the original on this site.
	 *
	 * @var string
	 */
	public const OFFER_FORM = 'form';

	/**
	 * Deactivate offer: the original is network-active and the user manages the network plugins.
	 *
	 * @var string
	 */
	public const OFFER_NETWORK = 'network';

	/**
	 * Notices.
	 *
	 * @var Notices
	 */
	private Notices $notices;

	/**
	 * The importer.
	 *
	 * @var Importer
	 */
	private Importer $importer;

	/**
	 * The findings store.
	 *
	 * @var Findings
	 */
	private Findings $findings;

	/**
	 * Create the page.
	 *
	 * @param Notices  $notices  Flash notices.
	 * @param Importer $importer The importer.
	 * @param Findings $findings The findings store.
	 */
	public function __construct( Notices $notices, Importer $importer, Findings $findings ) {
		$this->notices  = $notices;
		$this->importer = $importer;
		$this->findings = $findings;
	}

	/**
	 * Page slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function page_title(): string {
		return __( 'Import from WP REST Cache', 'rockfire-rest-api-cache' );
	}

	/**
	 * Menu label.
	 *
	 * @return string
	 */
	public function menu_title(): string {
		return __( 'Import', 'rockfire-rest-api-cache' );
	}

	/**
	 * Menu position.
	 *
	 * @return int
	 */
	public function position(): int {
		return 50;
	}

	/**
	 * Nothing to do before output.
	 *
	 * @return void
	 */
	public function load(): void {
	}

	/**
	 * Whether the page is wanted: the original plugin or something it left is present, or a finding is stored.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return Detector::present() || array() !== $this->findings->all();
	}

	/**
	 * Print the page body.
	 *
	 * @return void
	 */
	public function render(): void {
		$stored          = $this->findings->get( Findings::GROUP_IMPORT );
		$preview         = null === $stored ? $this->importer->run( true ) : null;
		$groups          = $this->other_groups();
		$offer           = self::deactivate_offer();
		$basename        = self::original_basename();
		$original_active = Detector::original_active();
		require RFRAC_DIR . 'includes/legacy/views/import.php';
	}

	/**
	 * The actions of the page.
	 *
	 * @return array<string,callable|array{callback:callable,per_target_nonce?:bool}>
	 */
	public function actions(): array {
		return array(
			'import_legacy'          => array(
				'callback'         => array( $this, 'import_legacy' ),
				'per_target_nonce' => false,
			),
			'dismiss_import_notice'  => array(
				'callback'         => array( $this, 'dismiss_import_notice' ),
				'per_target_nonce' => false,
			),
			'dismiss_import_summary' => array(
				'callback'         => array( $this, 'dismiss_import_summary' ),
				'per_target_nonce' => false,
			),
		);
	}

	/**
	 * Action `import_legacy`: import the settings of the original, queue a summary and go to the report.
	 *
	 * @param string $target Unused.
	 *
	 * @return string Redirect URL.
	 */
	public function import_legacy( string $target = '' ): string {
		unset( $target );

		$report    = $this->importer->run();
		$attention = self::attention( $report->findings );
		$message   = self::summary( $report->counts, $report->result, $attention );
		if ( Import_Report::RESULT_FAILED === $report->result ) {
			$this->notices->error( self::with_failure_reasons( $message, $report->validation ) );
		} elseif ( Import_Report::RESULT_NOTHING_FOUND === $report->result ) {
			$this->notices->info( $message );
		} elseif ( Import_Report::RESULT_DEFERRED === $report->result || $attention > 0 ) {
			$this->notices->warning( $message );
		} else {
			$this->notices->success( $message );
		}

		return Admin_Url::page( self::SLUG );
	}

	/**
	 * Action `dismiss_import_notice`: stop showing the import notice on every admin screen.
	 *
	 * @param string $target Unused.
	 *
	 * @return string Redirect URL.
	 */
	public function dismiss_import_notice( string $target = '' ): string {
		unset( $target );

		$this->findings->replace( Findings::GROUP_NOTICE, array(), array( 'dismissed' => true ) );

		return Admin_Url::back( admin_url() );
	}

	/**
	 * Action `dismiss_import_summary`: stop showing the summary of the stored import to the current user.
	 *
	 * The dismissal holds for the time of the stored import, so a new import shows the summary again. Without a
	 * stored import there is nothing to dismiss.
	 *
	 * @param string $target Unused.
	 *
	 * @return string Redirect URL.
	 */
	public function dismiss_import_summary( string $target = '' ): string {
		unset( $target );

		$import = $this->findings->get( Findings::GROUP_IMPORT );
		if ( null !== $import ) {
			Dismissals::dismiss( Dismissals::IMPORT_SUMMARY, $import['time'] );
		}

		return Admin_Url::back( admin_url() );
	}

	/**
	 * Basename of the original plugin among the plugins active on this site, or null.
	 *
	 * @return string|null
	 */
	public static function original_basename(): ?string {
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			if ( is_string( $plugin ) && basename( $plugin ) === Coexistence_Guard::ORIGINAL_FILE ) {
				return $plugin;
			}
		}

		return null;
	}

	/**
	 * Whether the original plugin is active on the whole network.
	 *
	 * @return bool
	 */
	public static function original_network_active(): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) as $plugin ) {
			if ( basename( (string) $plugin ) === Coexistence_Guard::ORIGINAL_FILE ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The link of the Plugins screen that deactivates a plugin of this site.
	 *
	 * It is the link core prints itself: core checks the nonce and the permission to deactivate the plugin, and
	 * deactivates it.
	 *
	 * @param string $basename Basename of the plugin, such as `folder/file.php`.
	 *
	 * @return string Raw URL; print it with `esc_url()`.
	 */
	public static function deactivate_url( string $basename ): string {
		return add_query_arg(
			array(
				'action'   => 'deactivate',
				'plugin'   => rawurlencode( $basename ),
				'_wpnonce' => wp_create_nonce( 'deactivate-plugin_' . $basename ),
			),
			admin_url( 'plugins.php' )
		);
	}

	/**
	 * What the current user may do about the active original plugin.
	 *
	 * @return string One of the `OFFER_*` constants: the deactivate link for a user who may deactivate the plugin on
	 *                this site, the link to the network Plugins screen for a network administrator when it is
	 *                network-active.
	 */
	public static function deactivate_offer(): string {
		if ( self::original_network_active() ) {
			return current_user_can( 'manage_network_plugins' ) ? self::OFFER_NETWORK : self::OFFER_NONE;
		}

		$basename = self::original_basename();
		if ( null === $basename || ! current_user_can( 'deactivate_plugin', $basename ) ) {
			return self::OFFER_NONE;
		}

		return self::OFFER_FORM;
	}

	/**
	 * One sentence about an import: what was done with how many values.
	 *
	 * @param array<string,int> $counts    The number of findings per status.
	 * @param string            $result    One of {@see Import_Report::RESULTS}.
	 * @param int               $attention How many findings need attention, see {@see self::attention()}.
	 *
	 * @return string
	 */
	public static function summary( array $counts, string $result, int $attention ): string {
		if ( Import_Report::RESULT_FAILED === $result ) {
			return __( 'The settings of WP REST Cache could not be imported: saving the settings failed and nothing was changed.', 'rockfire-rest-api-cache' );
		}
		if ( Import_Report::RESULT_NOTHING_FOUND === $result ) {
			return __( 'There are no settings of WP REST Cache to import.', 'rockfire-rest-api-cache' );
		}

		$message = sprintf(
			/* translators: 1: number of imported values, 2: kept, 3: adjusted, 4: skipped, 5: unsupported. */
			__( 'Imported the settings of WP REST Cache: %1$d imported, %2$d kept, %3$d adjusted, %4$d skipped, %5$d unsupported.', 'rockfire-rest-api-cache' ),
			$counts[ Finding::IMPORTED ] ?? 0,
			$counts[ Finding::KEPT ] ?? 0,
			$counts[ Finding::ADJUSTED ] ?? 0,
			$counts[ Finding::SKIPPED ] ?? 0,
			$counts[ Finding::UNSUPPORTED ] ?? 0
		);
		if ( Import_Report::RESULT_DEFERRED === $result ) {
			$message .= ' ' . __( 'The settings are saved; the cache configuration is rebuilt on a later request.', 'rockfire-rest-api-cache' );
		}
		if ( $attention > 0 ) {
			$message .= ' ' . __( 'Some values need your attention: see the report.', 'rockfire-rest-api-cache' );
		}

		return $message;
	}

	/**
	 * The summary of a failed import followed by why the settings could not be saved.
	 *
	 * The reasons are the messages of the validation, which name the rejected setting, route or write and are joined
	 * the way `wp rfrac import-legacy` joins them. The result is plain text: a flash notice escapes it when it prints.
	 *
	 * @param string                 $summary    The summary, see {@see self::summary()}.
	 * @param Validation_Result|null $validation The result of the save that failed; null when there is none.
	 *
	 * @return string The summary alone when the validation holds no message.
	 */
	public static function with_failure_reasons( string $summary, ?Validation_Result $validation ): string {
		$messages = array();
		if ( null !== $validation ) {
			foreach ( $validation->errors() as $error ) {
				$messages[] = $error['message'];
			}
		}
		if ( array() === $messages ) {
			return $summary;
		}

		/* translators: 1: the summary of the failed import, 2: the reasons the settings could not be saved */
		return sprintf( __( '%1$s Details: %2$s', 'rockfire-rest-api-cache' ), $summary, implode( ' ', $messages ) );
	}

	/**
	 * How many findings need the attention of the administrator: adjusted, skipped and unsupported ones, except the
	 * standing note that requests which were cached before may bypass the cache now, which every import carries.
	 *
	 * @param Finding[] $findings The findings.
	 * @phpstan-param list<Finding> $findings
	 *
	 * @return int
	 */
	public static function attention( array $findings ): int {
		$attention = 0;
		foreach ( $findings as $finding ) {
			if ( $finding->is_problem() && Importer::CODE_STRICTER_REQUESTS !== $finding->code ) {
				++$attention;
			}
		}

		return $attention;
	}

	/**
	 * The counts per status of a stored import, read from the meta of its group.
	 *
	 * @param array<string,mixed> $meta The meta of the `import` group.
	 *
	 * @return array<string,int>
	 */
	public static function counts_of( array $meta ): array {
		$counts = array();
		foreach ( Finding::STATUSES as $status ) {
			$count             = $meta[ $status ] ?? 0;
			$counts[ $status ] = is_int( $count ) ? $count : 0;
		}

		return $counts;
	}

	/**
	 * The findings of a report in reading order: what needs attention (adjusted, skipped, unsupported) first, then
	 * what was kept, then what was imported. The order within a status is kept.
	 *
	 * @param Finding[] $findings The findings.
	 * @phpstan-param list<Finding> $findings
	 *
	 * @return Finding[]
	 * @phpstan-return list<Finding>
	 */
	public static function ordered( array $findings ): array {
		$rank = array(
			Finding::ADJUSTED    => 0,
			Finding::SKIPPED     => 1,
			Finding::UNSUPPORTED => 2,
			Finding::KEPT        => 3,
			Finding::IMPORTED    => 4,
		);

		usort(
			$findings,
			static fn( Finding $a, Finding $b ): int => ( $rank[ $a->status ] ?? 5 ) <=> ( $rank[ $b->status ] ?? 5 )
		);

		return $findings;
	}

	/**
	 * The label of the status of a finding.
	 *
	 * @param string $status One of {@see Finding::STATUSES}.
	 *
	 * @return string
	 */
	public static function status_label( string $status ): string {
		return match ( $status ) {
			Finding::IMPORTED    => __( 'Imported', 'rockfire-rest-api-cache' ),
			Finding::KEPT        => __( 'Kept', 'rockfire-rest-api-cache' ),
			Finding::ADJUSTED    => __( 'Adjusted', 'rockfire-rest-api-cache' ),
			Finding::SKIPPED     => __( 'Skipped', 'rockfire-rest-api-cache' ),
			Finding::UNSUPPORTED => __( 'Unsupported', 'rockfire-rest-api-cache' ),
			default              => $status,
		};
	}

	/**
	 * The label of the result of an import.
	 *
	 * @param string $result One of {@see Import_Report::RESULTS}.
	 *
	 * @return string
	 */
	public static function result_label( string $result ): string {
		return match ( $result ) {
			Import_Report::RESULT_COMPILED  => __( 'The settings are saved and the cache configuration is up to date.', 'rockfire-rest-api-cache' ),
			Import_Report::RESULT_DEFERRED  => __( 'The settings are saved; the cache configuration is rebuilt on a later request.', 'rockfire-rest-api-cache' ),
			Import_Report::RESULT_UNCHANGED => __( 'Nothing needed to change.', 'rockfire-rest-api-cache' ),
			Import_Report::RESULT_FAILED    => __( 'The settings could not be saved.', 'rockfire-rest-api-cache' ),
			default                         => $result,
		};
	}

	/**
	 * The title of a group of findings; an unknown group is "Other findings".
	 *
	 * @param string $group The group id.
	 *
	 * @return string
	 */
	public static function group_title( string $group ): string {
		return match ( $group ) {
			Findings::GROUP_IMPORT     => __( 'Import', 'rockfire-rest-api-cache' ),
			Findings::GROUP_HOOKS      => __( 'Hooks', 'rockfire-rest-api-cache' ),
			Findings::GROUP_LOAD_ORDER => __( 'Load order', 'rockfire-rest-api-cache' ),
			Findings::GROUP_ENDPOINTS  => __( 'Endpoints', 'rockfire-rest-api-cache' ),
			Findings::GROUP_CLASSES    => __( 'Classes', 'rockfire-rest-api-cache' ),
			Findings::GROUP_NOTICE     => __( 'Notice', 'rockfire-rest-api-cache' ),
			default                    => __( 'Other findings', 'rockfire-rest-api-cache' ),
		};
	}

	/**
	 * The time of a stored group, in the date and time format of the site.
	 *
	 * @param int $time Unix timestamp.
	 *
	 * @return string
	 */
	public static function format_time( int $time ): string {
		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );
		$format      = ( is_string( $date_format ) && '' !== $date_format ? $date_format : 'Y-m-d' ) . ' ' . ( is_string( $time_format ) && '' !== $time_format ? $time_format : 'H:i' );
		$formatted   = wp_date( $format, $time );

		return false === $formatted ? gmdate( 'Y-m-d H:i', $time ) . ' UTC' : $formatted;
	}

	/**
	 * The stored groups other than the import that hold findings.
	 *
	 * @return list<array{id:string,title:string,findings:list<Finding>,truncated:int}>
	 */
	private function other_groups(): array {
		$groups = array();
		foreach ( $this->findings->all() as $id => $group ) {
			if ( Findings::GROUP_IMPORT === $id || ( array() === $group['findings'] && 0 === $group['truncated'] ) ) {
				continue;
			}
			$groups[] = array(
				'id'        => $id,
				'title'     => self::group_title( $id ),
				'findings'  => $group['findings'],
				'truncated' => $group['truncated'],
			);
		}

		return $groups;
	}
}
