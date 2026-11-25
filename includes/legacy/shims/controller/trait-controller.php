<?php
/**
 * Shim of the controller trait of the original plugin.
 *
 * Reproduces the public interface (trait name and method signatures) of WP REST Cache by Acato
 * (https://wordpress.org/plugins/wp-rest-cache/), GPLv3, so existing calls keep working. The implementation is new.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace WP_Rest_Cache_Plugin\Includes\Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Constructor of the controller shims.
 *
 * The original registered the route base of the controller as an allowed endpoint through an option write; the
 * shim only hands the post type or taxonomy to the core controller. It raises no notice, as it runs on every REST
 * initialisation.
 *
 * @since 1.0.0
 */
trait Controller_Trait {

	/**
	 * Constructor.
	 *
	 * @param string $item Post type or taxonomy key.
	 */
	public function __construct( $item ) { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Constructor of the original, kept for its signature.
		parent::__construct( $item );
	}
}
