<?php
/**
 * Discovery of the registered REST routes.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Config;

use Rockfire\Rest_Api_Cache\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the routes of a `WP_REST_Server` into {@see Route_Info} objects.
 *
 * @since 1.0.0
 */
final class Route_Discovery {

	/**
	 * The oEmbed route that is cached by default besides the `wp/v2` routes.
	 *
	 * @var string
	 */
	public const OEMBED_ROUTE = '/oembed/1.0/embed';

	/**
	 * Access kind: the handler has no permission check (`__return_true`, or none at all, which core also lets through).
	 *
	 * @var string
	 */
	public const ACCESS_PUBLIC = 'public';

	/**
	 * Access kind: the permission check is core's own, a function or a class declared by WordPress core (a file in
	 * `wp-includes`), e.g. a `WP_REST_Posts_Controller` method. A plugin controller that extends a core controller is
	 * custom even when it inherits the method: core's check calls helpers (`check_read_permission()`) that the
	 * subclass can override.
	 *
	 * @var string
	 */
	public const ACCESS_CORE = 'core';

	/**
	 * Access kind: any other permission check, or one that cannot be resolved. It may rely on something the cache
	 * key does not hold (a secret header, the visitor's IP), so a response it allowed could be served to everyone.
	 *
	 * @var string
	 */
	public const ACCESS_CUSTOM = 'custom';

	/**
	 * The REST server of this request, built for reading its routes.
	 *
	 * Fires {@see Hooks::DISCOVERY_SERVER} first, so an integration can register routes this request would
	 * otherwise lack. Used wherever the plugin itself builds the server to read routes, never on the serving
	 * path: the route manager, the catalog preset, the settings-save compile and the invalidation rule read
	 * during content saves (`Config::route_rules()` without a server, reached from `Relevance`, so a wp-admin
	 * product save builds the server and runs `rest_api_init`).
	 *
	 * @return \WP_REST_Server
	 */
	public static function server(): \WP_REST_Server {
		do_action( Hooks::DISCOVERY_SERVER ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
		return rest_get_server();
	}

	/**
	 * Discover the routes of a server, in registration order.
	 *
	 * @param \WP_REST_Server $server REST server with its routes registered.
	 *
	 * @return array<string,Route_Info> Route => info.
	 */
	public function discover( \WP_REST_Server $server ): array {
		$nss = array_map( 'strval', $server->get_namespaces() );
		usort( $nss, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		$out = [];
		foreach ( $server->get_routes() as $route => $handlers ) {
			$route       = (string) $route;
			$ns          = $this->namespace_of( $route, $nss );
			$method_args = [];
			$custom      = [];

			foreach ( $handlers as $handler ) {
				if ( ! is_array( $handler ) ) {
					continue;
				}
				$names = [];
				if ( isset( $handler['args'] ) && is_array( $handler['args'] ) ) {
					foreach ( array_keys( $handler['args'] ) as $name ) {
						$names[] = (string) $name;
					}
				}
				$is_custom = self::ACCESS_CUSTOM === self::access_kind( $handler['permission_callback'] ?? null );
				foreach ( $this->handler_methods( $handler['methods'] ?? [] ) as $method ) {
					$method_args[ $method ] = array_merge( $method_args[ $method ] ?? [], $names );
					if ( $is_custom ) {
						$custom[ $method ] = true;
					}
				}
			}

			// The supported methods only, in canonical order, each with its sorted argument names.
			$methods       = [];
			$by_name       = [];
			$custom_access = [];
			foreach ( Route_Rule::METHODS as $method ) {
				if ( isset( $method_args[ $method ] ) ) {
					$names = array_values( array_unique( $method_args[ $method ] ) );
					sort( $names );
					$methods[]          = $method;
					$by_name[ $method ] = $names;
					if ( isset( $custom[ $method ] ) ) {
						$custom_access[] = $method;
					}
				}
			}
			$readable = isset( $by_name['GET'] );

			$denied        = Deny_List::is_denied( $route );
			$out[ $route ] = new Route_Info(
				$route,
				$ns,
				$readable,
				$by_name['GET'] ?? [],
				$denied,
				! $denied && $this->is_default( $route, $ns, $readable ),
				$methods,
				$by_name,
				$custom_access
			);
		}
		return $out;
	}

	/**
	 * The access kind of a handler's `permission_callback`, see the `ACCESS_*` constants.
	 *
	 * Core skips the check for an empty callback, so that is public, like `__return_true`. A function, or a method
	 * of a class, declared in `wp-includes` is core's own check. Everything else, including a method that a plugin
	 * class inherits from core (it may override the helpers that check calls), a callback that is not callable
	 * and one that cannot be reflected, is custom: the result only drives warnings, so it errs towards warning.
	 *
	 * @param mixed $callback The registered `permission_callback`.
	 *
	 * @return string
	 */
	public static function access_kind( mixed $callback ): string {
		if ( empty( $callback ) ) {
			return self::ACCESS_PUBLIC;
		}
		if ( is_string( $callback ) && '__return_true' === strtolower( ltrim( $callback, '\\' ) ) ) {
			return self::ACCESS_PUBLIC;
		}
		$file = self::code_file( $callback );
		if ( null === $file ) {
			return self::ACCESS_CUSTOM;
		}
		$core = trailingslashit( wp_normalize_path( ABSPATH . ( defined( 'WPINC' ) ? (string) constant( 'WPINC' ) : 'wp-includes' ) ) );
		return 0 === strncasecmp( wp_normalize_path( $file ), $core, strlen( $core ) ) ? self::ACCESS_CORE : self::ACCESS_CUSTOM;
	}

	/**
	 * The file the code of a callback comes from: the function's, or the file of the class the method is called on.
	 *
	 * For a method that is the class of the object (or the named class), not the class that declares the method:
	 * a subclass in a plugin that inherits a core method still changes what that method does, because the method
	 * calls helpers (`$this->check_read_permission()`) the subclass can override.
	 *
	 * @param mixed $callback Callback.
	 *
	 * @return string|null Null when the callback is not callable or has no file (an internal function or class).
	 */
	private static function code_file( mixed $callback ): ?string {
		if ( ! is_callable( $callback ) ) {
			return null;
		}
		try {
			if ( is_string( $callback ) && str_contains( $callback, '::' ) ) {
				$callback = explode( '::', $callback, 2 );
			}
			if ( is_array( $callback ) ) {
				// A callable array is always `[ object|class-string, method ]`.
				$class = new \ReflectionClass( $callback[0] );
				if ( ! $class->hasMethod( $callback[1] ) ) {
					return null; // A callable that cannot be reflected (a magic `__call` method).
				}
				$file = $class->getFileName();
			} elseif ( $callback instanceof \Closure || is_string( $callback ) ) {
				$file = ( new \ReflectionFunction( $callback ) )->getFileName();
			} elseif ( is_object( $callback ) ) {
				// An invokable object: its own class, also when `__invoke()` is inherited.
				$file = ( new \ReflectionObject( $callback ) )->getFileName();
			} else {
				return null;
			}
		} catch ( \ReflectionException $e ) {
			return null; // A callable that cannot be reflected.
		}
		return false === $file ? null : $file;
	}

	/**
	 * Whether a route is cached by default.
	 *
	 * @param string $route     Route.
	 * @param string $ns Namespace.
	 * @param bool   $readable  Whether the route has a GET handler.
	 *
	 * @return bool
	 */
	private function is_default( string $route, string $ns, bool $readable ): bool {
		if ( self::OEMBED_ROUTE === $route ) {
			return true;
		}
		return $readable && 'wp/v2' === $ns && '/wp/v2' !== rtrim( $route, '/' );
	}

	/**
	 * Find the namespace a route belongs to.
	 *
	 * @param string   $route      Route.
	 * @param string[] $nss Namespaces, longest first.
	 *
	 * @return string
	 */
	private function namespace_of( string $route, array $nss ): string {
		foreach ( $nss as $ns ) {
			$prefix = '/' . $ns;
			if ( $route === $prefix || str_starts_with( $route, $prefix . '/' ) ) {
				return $ns;
			}
		}
		return '';
	}

	/**
	 * The method names of a handler, uppercased.
	 *
	 * @param mixed $methods Methods as registered: `['GET' => true]`, `['GET']` or `'GET, HEAD'`.
	 *
	 * @return list<string>
	 */
	private function handler_methods( mixed $methods ): array {
		if ( is_string( $methods ) ) {
			$methods = preg_split( '/[\s,]+/', $methods );
		}
		if ( ! is_array( $methods ) ) {
			return [];
		}
		$out = [];
		foreach ( $methods as $method => $enabled ) {
			$name = is_string( $method ) ? $method : ( is_string( $enabled ) ? $enabled : '' );
			if ( '' !== $name ) {
				$out[] = strtoupper( $name );
			}
		}
		return $out;
	}
}
