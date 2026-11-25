<?php
/**
 * The admin notice that offers the import of the original plugin's settings.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Admin\Capability;
use Rockfire\Rest_Api_Cache\Admin\Dismissals;
use Rockfire\Rest_Api_Cache\Admin\Notice_Screen;
use Rockfire\Rest_Api_Cache\Admin\Request;

defined( 'ABSPATH' ) || exit;

/**
 * Prints, on the Plugins screen and the pages of the plugin except the Import page, an offer to import the settings
 * of WP REST Cache while something of it is present and no import ran, and, after an import while the original
 * plugin is still active, the summary with the link to deactivate it.
 *
 * The notice is hooked raw on `admin_notices` and prints nothing for a user without the settings capability. It
 * never changes anything itself: its buttons are forms of the actions of {@see Import_Page}, and the deactivate link
 * is the one of the Plugins screen. The offer is dismissed for every user; the summary per user, for the time of the
 * stored import.
 *
 * @since 1.0.0
 */
final class Import_Notice {

	/**
	 * The capability of the admin.
	 *
	 * @var Capability
	 */
	private Capability $capability;

	/**
	 * The findings store.
	 *
	 * @var Findings
	 */
	private Findings $findings;

	/**
	 * Create the notice.
	 *
	 * @param Capability $capability The capability of the admin.
	 * @param Findings   $findings   The findings store.
	 */
	public function __construct( Capability $capability, Findings $findings ) {
		$this->capability = $capability;
		$this->findings   = $findings;
	}

	/**
	 * Print the notice (`admin_notices`).
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! Notice_Screen::matches() || Import_Page::SLUG === Request::key( 'page' ) || ! $this->capability->current_user_can() ) {
			return;
		}

		$import = $this->findings->get( Findings::GROUP_IMPORT );
		if ( null === $import ) {
			if ( Detector::present() && ! $this->dismissed() ) {
				$this->render_offer();
			}
			return;
		}

		if ( Detector::original_active() && ! Dismissals::is_dismissed( Dismissals::IMPORT_SUMMARY, $import['time'] ) ) {
			$this->render_summary( $import['meta'], Import_Page::attention( $import['findings'] ) );
		}
	}

	/**
	 * Whether the notice was dismissed.
	 *
	 * @return bool
	 */
	private function dismissed(): bool {
		$group = $this->findings->get( Findings::GROUP_NOTICE );

		return null !== $group && true === ( $group['meta']['dismissed'] ?? false );
	}

	/**
	 * Print the offer to import: import now, review on the Import page, dismiss.
	 *
	 * @return void
	 */
	private function render_offer(): void {
		echo '<div class="notice notice-info rfrac-notice rfrac-import-notice">';
		printf(
			'<p>%s</p>',
			esc_html__( 'WP REST Cache left settings on this site. Import them into Rockfire – REST API Cache, so that caching keeps behaving the way your site is used to.', 'rockfire-rest-api-cache' )
		);
		echo '<div class="rfrac-import-notice-actions">';
		self::form( 'import_legacy', __( 'Import now', 'rockfire-rest-api-cache' ), 'button button-primary' );
		self::link( Admin_Url::page( Import_Page::SLUG ), __( 'Review on the Import page', 'rockfire-rest-api-cache' ) );
		self::form( 'dismiss_import_notice', __( 'Dismiss', 'rockfire-rest-api-cache' ), 'button button-link' );
		echo '</div></div>';
	}

	/**
	 * Print the summary of the import with the link to the report, the link to deactivate the original and the button
	 * that dismisses the summary for the current user.
	 *
	 * @param array<string,mixed> $meta      The meta of the `import` group.
	 * @param int                 $attention How many findings of the import need attention.
	 *
	 * @return void
	 */
	private function render_summary( array $meta, int $attention ): void {
		$counts = Import_Page::counts_of( $meta );
		$result = isset( $meta['result'] ) && is_string( $meta['result'] ) ? $meta['result'] : '';
		$type   = 'success';
		if ( Import_Report::RESULT_FAILED === $result ) {
			$type = 'error';
		} elseif ( Import_Report::RESULT_DEFERRED === $result || $attention > 0 ) {
			$type = 'warning';
		}

		printf( '<div class="notice notice-%s rfrac-notice rfrac-import-notice">', esc_attr( $type ) );
		printf( '<p>%s</p>', esc_html( Import_Page::summary( $counts, $result, $attention ) ) );
		echo '<div class="rfrac-import-notice-actions">';
		self::link( Admin_Url::page( Import_Page::SLUG ), __( 'View report', 'rockfire-rest-api-cache' ) );

		$offer    = Import_Page::deactivate_offer();
		$basename = Import_Page::original_basename();
		if ( Import_Page::OFFER_FORM === $offer && null !== $basename ) {
			self::link( Import_Page::deactivate_url( $basename ), __( 'Deactivate WP REST Cache', 'rockfire-rest-api-cache' ) );
		} elseif ( Import_Page::OFFER_NETWORK === $offer ) {
			self::link( network_admin_url( 'plugins.php' ), __( 'Deactivate WP REST Cache on the network Plugins screen', 'rockfire-rest-api-cache' ) );
		}
		self::form( 'dismiss_import_summary', __( 'Dismiss', 'rockfire-rest-api-cache' ), 'button button-link' );
		echo '</div></div>';
	}

	/**
	 * Print a one-button form of an admin action.
	 *
	 * @param string $action  Action name without the `rfrac_` prefix.
	 * @param string $label   Translated button label.
	 * @param string $classes Button classes.
	 *
	 * @return void
	 */
	private static function form( string $action, string $label, string $classes ): void {
		printf( '<form method="post" action="%s" style="display:inline-block;margin:0 8px 0 0;">', esc_url( admin_url( 'admin-post.php' ) ) );
		Admin_Url::form_fields( $action );
		printf( '<button type="submit" class="%1$s">%2$s</button></form>', esc_attr( $classes ), esc_html( $label ) );
	}

	/**
	 * Print a link styled as a button.
	 *
	 * @param string $url   Raw URL.
	 * @param string $label Translated label.
	 *
	 * @return void
	 */
	private static function link( string $url, string $label ): void {
		printf( '<a class="button" href="%1$s" style="margin:0 8px 0 0;">%2$s</a>', esc_url( $url ), esc_html( $label ) );
	}
}
