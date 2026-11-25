<?php
/**
 * Shim of the attachment controller of the original plugin.
 *
 * Reproduces the public interface (class name and method signatures) of WP REST Cache by Acato
 * (https://wordpress.org/plugins/wp-rest-cache/), GPLv3, so existing calls keep working. The implementation is new.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace WP_Rest_Cache_Plugin\Includes\Controller;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for an attachment post type that was registered with the original's controller class.
 *
 * It behaves as the core attachments controller, so the routes of the post type stay registered. It raises no
 * notice, as it is built on every REST initialisation.
 *
 * @since 1.0.0
 */
class Attachment_Controller extends \WP_REST_Attachments_Controller {

	use Controller_Trait;
}
