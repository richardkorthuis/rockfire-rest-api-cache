<?php
/**
 * URLs and form fields of the admin pages and actions.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds page URLs and nonce'd `admin-post.php` action URLs and form fields that match
 * {@see Action_Handler}: action `rfrac_{action}`, parameter `target`, nonce action
 * `rfrac_{action}` or `rfrac_{action}_{target}` (per-target nonce) in `_wpnonce`.
 *
 * URLs are raw: escape them with `esc_url()` when printing.
 *
 * @since 1.0.0
 */
final class Admin_Url {

	/**
	 * URL of an admin page of the plugin.
	 *
	 * @param string               $slug Page slug, e.g. `rfrac-tools`.
	 * @param array<string,scalar> $args Extra query arguments (URL-encoded here).
	 *
	 * @return string
	 */
	public static function page( string $slug, array $args = array() ): string {
		return add_query_arg( rawurlencode_deep( array_merge( array( 'page' => $slug ), $args ) ), admin_url( 'admin.php' ) );
	}

	/**
	 * Nonce'd `admin-post.php` URL of an action (for links and buttons).
	 *
	 * @param string $action Action name without the `rfrac_` prefix, e.g. `flush_all`.
	 * @param string $target Target (a key: entry ID, route ID …); part of the nonce when not empty.
	 *
	 * @return string
	 */
	public static function action( string $action, string $target = '' ): string {
		$args = array( 'action' => Action_Handler::PREFIX . $action );
		if ( '' !== $target ) {
			$args['target'] = $target;
		}
		$url = add_query_arg( rawurlencode_deep( $args ), admin_url( 'admin-post.php' ) );
		return add_query_arg( '_wpnonce', wp_create_nonce( Action_Handler::nonce_action( $action, $target ) ), $url );
	}

	/**
	 * Print the hidden fields of a POST form to `admin-post.php` for an action.
	 *
	 * The form itself is `<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">`.
	 * Without a target the form may supply its own `target` field (e.g. a select) when the action
	 * opted out of per-target nonces.
	 *
	 * @param string $action Action name without the `rfrac_` prefix.
	 * @param string $target Target; part of the nonce when not empty.
	 *
	 * @return void
	 */
	public static function form_fields( string $action, string $target = '' ): void {
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( Action_Handler::PREFIX . $action ) );
		if ( '' !== $target ) {
			printf( '<input type="hidden" name="target" value="%s" />', esc_attr( $target ) );
		}
		wp_nonce_field( Action_Handler::nonce_action( $action, $target ) );
	}

	/**
	 * The raw query arguments of the referring page when it is the given page of the plugin, else null.
	 *
	 * The referer is the validated (same-site) one of `wp_get_referer()`: the `_wp_http_referer` field of a
	 * form, else the `Referer` header. Callers sanitise every argument they use.
	 *
	 * @param string $slug Page slug.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function referer_args( string $slug ): ?array {
		$referer = wp_get_referer();
		if ( ! is_string( $referer ) || ! str_starts_with( $referer, admin_url( 'admin.php' ) ) ) {
			return null;
		}
		parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $args );
		return isset( $args['page'] ) && $slug === $args['page'] ? $args : null;
	}

	/**
	 * Where to send the user back to: the referring admin page, else a fallback.
	 *
	 * The referer is the validated (same-site) one of `wp_get_referer()`, and is used only when it lies under
	 * `admin_url()` (or `network_admin_url()` on multisite). That is an absolute URL (the `Referer` header), or
	 * the host-less path (`/wp-admin/plugins.php`) that {@see self::form_fields()} posts in `_wp_http_referer`;
	 * a path is returned as an absolute URL.
	 *
	 * @param string $fallback URL to use when there is no such referer.
	 *
	 * @return string
	 */
	public static function back( string $fallback ): string {
		$referer = wp_get_referer();
		if ( ! is_string( $referer ) ) {
			return $fallback;
		}
		if ( str_starts_with( $referer, '/' ) ) {
			return self::admin_path_url( $referer ) ?? $fallback;
		}
		if ( str_starts_with( $referer, admin_url() ) || ( is_multisite() && str_starts_with( $referer, network_admin_url() ) ) ) {
			return $referer;
		}

		return $fallback;
	}

	/**
	 * The absolute URL of a host-less path when it lies under the admin path, else null.
	 *
	 * The admin path is the path component of `admin_url()` (`/wp-admin/`, or `/sub/wp-admin/` for a site in a
	 * subdirectory), and of `network_admin_url()` on multisite. A path that could leave it (`//host`, a
	 * backslash, or a `.` or `..` segment, also percent-encoded) is refused, as browsers resolve those.
	 *
	 * @param string $path Path with an optional query, starting with `/`.
	 *
	 * @return string|null
	 */
	private static function admin_path_url( string $path ): ?string {
		$path_only = (string) wp_parse_url( $path, PHP_URL_PATH );
		$decoded   = rawurldecode( $path_only );
		if ( str_starts_with( $path, '//' ) || str_contains( $decoded, '\\' ) ) {
			return null;
		}
		if ( array() !== array_intersect( explode( '/', $decoded ), array( '.', '..' ) ) ) {
			return null;
		}

		$bases = is_multisite() ? array( network_admin_url(), admin_url() ) : array( admin_url() );
		foreach ( $bases as $base ) {
			$parts = wp_parse_url( $base );
			if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'], $parts['path'] ) ) {
				continue;
			}
			if ( str_starts_with( $path_only, trailingslashit( $parts['path'] ) ) ) {
				return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $path;
			}
		}

		return null;
	}
}
