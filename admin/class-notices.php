<?php
/**
 * Flash notices of the admin.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * One-time notices for a user, shown on the next admin page they view.
 *
 * Stored per site in the user meta {@see self::meta_key()} as a list of `{type, message}` (at most
 * {@see self::MAX}, de-duplicated, newest last), so a notice queued on one site is never shown on, or
 * consumed by, another. Written only when a notice is queued; read and deleted by
 * {@see self::render()} only when there is something to show. Messages are plain text and always
 * rendered with `esc_html`. There is no notices option and no dismissed-notices store.
 *
 * Known race (accepted for flash messages): queueing and rendering are a read-modify-write of the
 * user meta without a lock, so two concurrent requests of the same user (e.g. two tabs, or an action
 * finishing while another page renders) can drop a notice or, rarely, show one twice. Nothing else
 * depends on these notices.
 *
 * @since 1.0.0
 */
class Notices {

	/**
	 * Base of the per-site user meta key of the queued notices.
	 *
	 * @var string
	 */
	public const META = 'rfrac_notices';

	/**
	 * Maximum number of queued notices per user; the oldest are dropped.
	 *
	 * @var int
	 */
	public const MAX = 10;

	/**
	 * Notice types (the `notice-{type}` class of WordPress).
	 *
	 * @var list<string>
	 */
	public const TYPES = array( 'success', 'error', 'warning', 'info' );

	/**
	 * User meta key of the queued notices on the current site.
	 *
	 * Prefixed with the blog prefix at call time, so it follows `switch_to_blog()`. A plain `rfrac_notices`
	 * left by an earlier version is never read.
	 *
	 * @return string
	 */
	public static function meta_key(): string {
		global $wpdb;

		// Always a wpdb once WordPress has loaded; the check only narrows the global for static analysis.
		$prefix = $wpdb instanceof \wpdb ? $wpdb->get_blog_prefix() : '';

		return $prefix . self::META;
	}

	/**
	 * Queue a success notice for the current user.
	 *
	 * @param string $message Plain-text message.
	 *
	 * @return void
	 */
	public function success( string $message ): void {
		$this->add( 'success', $message );
	}

	/**
	 * Queue an error notice for the current user.
	 *
	 * @param string $message Plain-text message.
	 *
	 * @return void
	 */
	public function error( string $message ): void {
		$this->add( 'error', $message );
	}

	/**
	 * Queue a warning notice for the current user.
	 *
	 * @param string $message Plain-text message.
	 *
	 * @return void
	 */
	public function warning( string $message ): void {
		$this->add( 'warning', $message );
	}

	/**
	 * Queue an info notice for the current user.
	 *
	 * @param string $message Plain-text message.
	 *
	 * @return void
	 */
	public function info( string $message ): void {
		$this->add( 'info', $message );
	}

	/**
	 * Queue a notice.
	 *
	 * An identical queued notice is not added twice; beyond {@see self::MAX} the oldest are dropped.
	 * Nothing is stored without a user.
	 *
	 * @param string $type    `success`, `error`, `warning` or `info` (anything else counts as `info`).
	 * @param string $message Plain-text message.
	 * @param int    $user_id User; 0 for the current user.
	 *
	 * @return void
	 */
	public function add( string $type, string $message, int $user_id = 0 ): void {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		if ( $user_id <= 0 || '' === $message ) {
			return;
		}
		$notice = array(
			'type'    => in_array( $type, self::TYPES, true ) ? $type : 'info',
			'message' => $message,
		);

		$notices = $this->queued( $user_id );
		if ( in_array( $notice, $notices, true ) ) {
			return;
		}
		$notices[] = $notice;
		update_user_meta( $user_id, self::meta_key(), array_slice( $notices, -self::MAX ) );
	}

	/**
	 * The queued notices of a user.
	 *
	 * @param int $user_id User; 0 for the current user.
	 *
	 * @return array<int,array{type:string,message:string}>
	 * @phpstan-return list<array{type:string,message:string}>
	 */
	public function queued( int $user_id = 0 ): array {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}
		$stored = get_user_meta( $user_id, self::meta_key(), true );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$notices = array();
		foreach ( $stored as $notice ) {
			if ( is_array( $notice ) && isset( $notice['type'], $notice['message'] ) && is_string( $notice['type'] ) && is_string( $notice['message'] ) ) {
				$notices[] = array(
					'type'    => $notice['type'],
					'message' => $notice['message'],
				);
			}
		}
		return $notices;
	}

	/**
	 * Print and delete the queued notices of the current user (`admin_notices`).
	 *
	 * Does nothing (and writes nothing) when no notice is queued.
	 *
	 * @return void
	 */
	public function render(): void {
		$user_id = get_current_user_id();
		$key     = self::meta_key();
		if ( $user_id <= 0 || '' === get_user_meta( $user_id, $key, true ) ) {
			return;
		}
		$notices = $this->queued( $user_id );
		delete_user_meta( $user_id, $key );
		foreach ( $notices as $notice ) {
			self::output( $notice['type'], $notice['message'] );
		}
	}

	/**
	 * Print one dismissible notice; the message is escaped.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Plain-text message.
	 *
	 * @return void
	 */
	public static function output( string $type, string $message ): void {
		$type = in_array( $type, self::TYPES, true ) ? $type : 'info';
		printf(
			'<div class="notice notice-%1$s is-dismissible rfrac-notice"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}
}
