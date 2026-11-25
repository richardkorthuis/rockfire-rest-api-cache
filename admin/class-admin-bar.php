<?php
/**
 * The "Flush REST cache" admin-bar node.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Admin;

use Rockfire\Rest_Api_Cache\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Flush REST cache" node to the admin bar (front end and admin) for users with the
 * capability. The link is the nonce'd `flush_all` action handled by {@see Admin}.
 *
 * @since 1.0.0
 */
class Admin_Bar {

	/**
	 * Node ID.
	 *
	 * @var string
	 */
	public const NODE = 'rfrac-flush';

	/**
	 * Capability.
	 *
	 * @var Capability
	 */
	private Capability $capability;

	/**
	 * Constructor.
	 *
	 * @param Capability $capability Capability.
	 */
	public function __construct( Capability $capability ) {
		$this->capability = $capability;
	}

	/**
	 * Register the hooks: the icon style on `admin_bar_init` (before the styles are printed) and the
	 * node on `admin_bar_menu` (priority 100).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_bar_init', array( $this, 'add_style' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_node' ), 100 );
	}

	/**
	 * Whether the node is shown: the user has the capability and `rfrac/show_admin_bar_flush` is true.
	 *
	 * @return bool
	 */
	public function should_show(): bool {
		if ( ! is_user_logged_in() || ! $this->capability->current_user_can() ) {
			return false;
		}
		/**
		 * Filters whether the "Flush REST cache" admin-bar node is shown to a user with the capability.
		 * Only a strict `true` shows it.
		 *
		 * @since 1.0.0
		 *
		 * @see Hooks::SHOW_ADMIN_BAR_FLUSH
		 *
		 * @param bool $show Whether to show the node. Default true.
		 */
		return true === apply_filters( Hooks::SHOW_ADMIN_BAR_FLUSH, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
	}

	/**
	 * Add the node.
	 *
	 * @param \WP_Admin_Bar $admin_bar Admin bar.
	 *
	 * @return void
	 */
	public function add_node( \WP_Admin_Bar $admin_bar ): void {
		if ( ! $this->should_show() ) {
			return;
		}
		$admin_bar->add_node(
			array(
				'id'    => self::NODE,
				'title' => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'Flush REST cache', 'rockfire-rest-api-cache' ) . '</span>',
				'href'  => Admin_Url::action( 'flush_all' ),
				'meta'  => array(
					'title' => __( 'Flush every cached REST API response of this site', 'rockfire-rest-api-cache' ),
				),
			)
		);
		$this->add_style();
	}

	/**
	 * Add the icon CSS to the `admin-bar` style when the node is shown.
	 *
	 * Hooked on `admin_bar_init`, before the admin-bar styles are printed; {@see self::add_node()}
	 * calls it again for an admin bar rendered without that hook. Idempotent: the CSS is added once.
	 *
	 * @return void
	 */
	public function add_style(): void {
		if ( ! $this->should_show() ) {
			return;
		}
		$after = wp_styles()->get_data( 'admin-bar', 'after' );
		$css   = '#wpadminbar #wp-admin-bar-' . self::NODE . ' .ab-icon:before{content:"\f182";top:2px;}';
		if ( is_array( $after ) && in_array( $css, $after, true ) ) {
			return;
		}
		wp_add_inline_style( 'admin-bar', $css );
	}
}
