<?php
/**
 * The admin notice that caching is paused because the original plugin is active.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Admin;

use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Tells users who may manage plugins that caching is paused while WP REST Cache is active, on the Plugins screen
 * and on the pages of the plugin only, until each of them dismisses it.
 *
 * The dismissal is per user and per scope: the notice of a site and the notice of the network admin are dismissed
 * separately, so a dismissal on the network Plugins screen never hides the notice on a site, and the other way
 * round. Rendering writes nothing; only the `dismiss_coexistence` action does. Detection stays with
 * {@see Coexistence_Guard}, which is safe in the must-use phase because it knows nothing of the admin.
 *
 * A dismissal holds for one coexistence episode, not for ever: it is made with the token of the episode (see
 * {@see self::token()}), and activating WP REST Cache or this plugin starts a new one, so the notice shows again
 * after WP REST Cache was deactivated and later reactivated.
 *
 * @since 1.0.0
 */
final class Coexistence_Notice {

	/**
	 * Name of the option that counts the episodes of coexistence, per site and (on a network) for the network.
	 *
	 * @var string
	 */
	public const OPTION_EPISODE = 'rfrac_coexistence_episode';

	/**
	 * Name of the dismiss action, without the `rfrac_` prefix.
	 *
	 * @var string
	 */
	public const ACTION = 'dismiss_coexistence';

	/**
	 * Target of the dismiss action on the network admin.
	 *
	 * @var string
	 */
	public const TARGET_NETWORK = 'network';

	/**
	 * Target of the dismiss action on a site.
	 *
	 * @var string
	 */
	public const TARGET_SITE = 'site';

	/**
	 * The capability of the admin.
	 *
	 * @var Capability
	 */
	private Capability $capability;

	/**
	 * Create the notice.
	 *
	 * @param Capability $capability The capability of the admin; the Dismiss form needs it.
	 */
	public function __construct( Capability $capability ) {
		$this->capability = $capability;
	}

	/**
	 * Hook the notice on both notice actions and register its dismiss action.
	 *
	 * Admin only: the episode count that ends a dismissal is not hooked here but by the plugin in every context, see
	 * {@see self::on_plugin_activated()}.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'network_admin_notices', array( $this, 'render' ) );
		( new Action_Handler( self::ACTION, array( $this, 'dismiss' ), $this->capability, true ) )->register();
	}

	/**
	 * `activated_plugin`: start a new coexistence episode when WP REST Cache or this plugin is activated.
	 *
	 * Counts one more episode, so every earlier dismissal stops holding and the notice shows again while the original
	 * plugin blocks caching. A network-wide activation counts for the network (it reaches every site, so no site is
	 * walked); any other activation counts for the current site. Any other plugin is ignored. Static, as it uses no
	 * state of the notice: the plugin hooks it in every context (WP-CLI, cron, REST, AJAX, wp-admin), so an activation
	 * outside wp-admin counts too.
	 *
	 * @param mixed $plugin       Basename of the plugin that was activated.
	 * @param mixed $network_wide Whether it was activated for the whole network.
	 *
	 * @return void
	 */
	public static function on_plugin_activated( mixed $plugin = '', mixed $network_wide = false ): void {
		if ( ! is_string( $plugin ) || ! self::starts_episode( $plugin ) ) {
			return;
		}
		if ( (bool) $network_wide && is_multisite() ) {
			update_site_option( self::OPTION_EPISODE, self::episodes( get_site_option( self::OPTION_EPISODE, 0 ) ) + 1 );
			return;
		}
		update_option( self::OPTION_EPISODE, self::episodes( get_option( self::OPTION_EPISODE, 0 ) ) + 1, false );
	}

	/**
	 * Print the notice (`admin_notices`, `network_admin_notices`).
	 *
	 * Prints while the original plugin blocks caching, for a user who may manage plugins, on the Plugins screen
	 * and the pages of the plugin, and until the current user dismisses it for the site or the network admin.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! Coexistence_Guard::is_blocked() || ! current_user_can( 'activate_plugins' ) || ! Notice_Screen::matches() ) {
			return;
		}
		if ( Dismissals::is_dismissed( self::notice_id(), self::token() ) ) {
			return;
		}

		echo '<div class="notice notice-error rfrac-notice rfrac-coexistence-notice"><p>';
		echo esc_html__( 'Rockfire – REST API Cache is paused because WP REST Cache is also active. Deactivate WP REST Cache to enable caching.', 'rockfire-rest-api-cache' );
		if ( ! Notice_Screen::is_plugins_screen() ) {
			printf(
				' <a href="%1$s">%2$s</a>',
				esc_url( self_admin_url( 'plugins.php' ) ),
				esc_html__( 'Go to Plugins', 'rockfire-rest-api-cache' )
			);
		}
		echo '</p>';
		if ( $this->capability->current_user_can() ) {
			self::render_dismiss_form();
		}
		echo '</div>';
	}

	/**
	 * The `dismiss_coexistence` action: hide the notice for the current user for the current episode, then back to the
	 * referring admin screen.
	 *
	 * @param string $target `network` for the notice of the network admin, anything else for the one of the site.
	 *
	 * @return string Redirect URL.
	 */
	public function dismiss( string $target = '' ): string {
		Dismissals::dismiss( self::TARGET_NETWORK === $target ? Dismissals::COEXISTENCE_NETWORK : Dismissals::COEXISTENCE, self::token() );

		return Admin_Url::back( self_admin_url( 'plugins.php' ) );
	}

	/**
	 * Whether activating a plugin starts a new episode: it is WP REST Cache or this plugin.
	 *
	 * Compared by file name, like {@see Coexistence_Guard} finds the original plugin.
	 *
	 * @param string $plugin Basename of the plugin that was activated.
	 *
	 * @return bool
	 */
	private static function starts_episode( string $plugin ): bool {
		$file = basename( $plugin );

		return Coexistence_Guard::ORIGINAL_FILE === $file || basename( RFRAC_FILE ) === $file;
	}

	/**
	 * The token of the current coexistence episode, which a dismissal is made with.
	 *
	 * One more than the episodes counted for the current site and for the network, so it only ever grows: a dismissal
	 * made in an earlier episode never matches again. Reads only. Without any episode counted it is 1, the token of
	 * a dismissal made before episodes were counted.
	 *
	 * @return int
	 */
	private static function token(): int {
		$episodes = self::episodes( get_option( self::OPTION_EPISODE, 0 ) );
		if ( is_multisite() ) {
			$episodes += self::episodes( get_site_option( self::OPTION_EPISODE, 0 ) );
		}

		return $episodes + 1;
	}

	/**
	 * A stored episode count as a whole number; anything that is not a number counts as none.
	 *
	 * @param mixed $stored The option value.
	 *
	 * @return int
	 */
	private static function episodes( mixed $stored ): int {
		return is_numeric( $stored ) ? max( 0, (int) $stored ) : 0;
	}

	/**
	 * The ID the dismissal of the current screen is stored under.
	 *
	 * @return string
	 */
	private static function notice_id(): string {
		return is_network_admin() ? Dismissals::COEXISTENCE_NETWORK : Dismissals::COEXISTENCE;
	}

	/**
	 * Print the one-button form of the dismiss action; its target and nonce are those of the current scope.
	 *
	 * @return void
	 */
	private static function render_dismiss_form(): void {
		printf( '<form method="post" action="%s" style="display:inline-block;margin:0 0 8px;">', esc_url( admin_url( 'admin-post.php' ) ) );
		Admin_Url::form_fields( self::ACTION, is_network_admin() ? self::TARGET_NETWORK : self::TARGET_SITE );
		printf( '<button type="submit" class="button button-link">%s</button></form>', esc_html__( 'Dismiss', 'rockfire-rest-api-cache' ) );
	}
}
