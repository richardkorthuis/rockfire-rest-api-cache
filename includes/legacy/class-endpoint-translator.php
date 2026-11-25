<?php
/**
 * Translation between legacy endpoint lists and Rockfire route rules.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Config\Effective_Rule;
use Rockfire\Rest_Api_Cache\Config\Route_Info;
use Rockfire\Rest_Api_Cache\Config\Route_Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Maps `namespace => [endpoint]` prefixes, disallowed endpoints and per-endpoint request headers onto
 * registered-match route rules, and maps enabled rules back to `namespace => [endpoint]`.
 *
 * The class is pure: it only reads the routes it was built with and the rule constants, so the import and the
 * hook bridge can share it. An endpoint is a plain path prefix: it enables every route whose key starts with it,
 * as `posts` also covers `/wp/v2/posts-archive`. A pattern is never interpreted; a concrete path such as
 * `posts/5` is matched against the route patterns instead. Rules are never wildcards.
 *
 * @since 1.0.0
 *
 * @phpstan-import-type Rule_Array from Translation
 * @phpstan-type Entry array{source:string,namespace:string,endpoint:string,code:string,routes:list<string>}
 * @phpstan-type Header_Entry array{source:string,namespace:string,endpoint:string,code:string,routes:list<string>,valid:list<string>,forbidden:list<string>,invalid:list<string>}
 * @phpstan-type Decision array{code:string,routes:list<string>,values:list<string>}
 * @phpstan-type Applied array{kept:list<string>,kept_routes:list<string>,dropped:list<string>,dropped_routes:list<string>}
 * @phpstan-type Vary_Result array{vary:array<string,list<string>>,claim:array<string,int>,applied:array<int,Applied>}
 */
final class Endpoint_Translator {

	/**
	 * Characters that make an endpoint a pattern instead of a path.
	 *
	 * @var string
	 */
	public const PATTERN_CHARS = '()[]{}?*+^$|\\';

	/**
	 * Resolution outcome of an entry that matched routes as a plain path prefix.
	 *
	 * @var string
	 */
	private const LITERAL = 'literal';

	/**
	 * The registered routes, in registration order.
	 *
	 * @var array<string,Route_Info>
	 */
	private array $routes;

	/**
	 * The namespaces of the routes, longest first.
	 *
	 * @var list<string>
	 */
	private array $namespaces;

	/**
	 * Constructor.
	 *
	 * @param array<string,Route_Info> $routes Route key => info, as `Route_Discovery::discover()` returns it.
	 */
	public function __construct( array $routes ) {
		$this->routes = $routes;

		$namespaces = [];
		foreach ( $routes as $info ) {
			if ( '' !== $info->namespace ) {
				$namespaces[ $info->namespace ] = true;
			}
		}
		$names = array_map( 'strval', array_keys( $namespaces ) );
		usort( $names, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		$this->namespaces = $names;
	}

	/**
	 * Translate legacy inputs into registered-match route rules.
	 *
	 * Every route that is discovered, readable and not denied under an allowed prefix is enabled, a route
	 * cached by default needing no rule unless a header varies it. A disallowed route gets an excluded rule
	 * when it would be enabled otherwise. Headers vary the routes they match that are enabled this way.
	 *
	 * With request methods given, a route without a GET handler is usable too when it registers one of them, and the
	 * rule of an allowed route also caches the given methods the route registers: never a method on a core `wp/v2`
	 * route. Without methods the translation is the same as ever.
	 *
	 * @param array<mixed> $allowed    `namespace => [endpoint]` that were allowed.
	 * @param array<mixed> $disallowed `namespace => [endpoint]` that were disallowed.
	 * @param array<mixed> $headers    `namespace/endpoint => 'Header-One,Header-Two'`; a leading slash is tolerated.
	 * @param int          $max_vary   How many headers one rule may vary by.
	 * @param string[]     $methods    Request methods besides GET that allowed routes should cache; only POST, PUT and PATCH count.
	 * @phpstan-param list<string> $methods
	 *
	 * @return Translation
	 */
	public function forward( array $allowed, array $disallowed = [], array $headers = [], int $max_vary = Route_Rule::MAX_VARY_HEADERS, array $methods = [] ): Translation {
		$max_vary = max( 0, $max_vary );
		$methods  = self::extra_methods( $methods );

		$allow_entries  = $this->map_entries( Translation_Note::SOURCE_ALLOWED, $allowed );
		$deny_entries   = $this->map_entries( Translation_Note::SOURCE_DISALLOWED, $disallowed );
		$header_entries = $this->header_entries( $headers );

		$allow_claims = $this->claims( $allow_entries, $methods );
		$deny_claims  = $this->claims( $deny_entries, $methods );
		$varied       = $this->apply_vary( $header_entries, $allow_claims, $deny_claims, $max_vary, $methods );

		[ $rules, $decisions ] = $this->decide( $allow_claims, $deny_claims, $varied, $methods );

		$notes = [];
		foreach ( $allow_entries as $index => $entry ) {
			$notes = array_merge(
				$notes,
				$this->entry_notes( $entry ),
				$this->decision_notes( $entry, $decisions[ Translation_Note::SOURCE_ALLOWED ][ $index ] ?? [] )
			);
		}
		foreach ( $deny_entries as $index => $entry ) {
			$notes = array_merge(
				$notes,
				$this->entry_notes( $entry ),
				$this->decision_notes( $entry, $decisions[ Translation_Note::SOURCE_DISALLOWED ][ $index ] ?? [] )
			);
		}
		foreach ( $header_entries as $index => $entry ) {
			$notes = array_merge(
				$notes,
				$this->entry_notes( $entry ),
				$this->header_notes( $entry, $varied['applied'][ $index ] ?? null ),
				$this->decision_notes( $entry, $decisions[ Translation_Note::SOURCE_HEADERS ][ $index ] ?? [] )
			);
		}

		return new Translation( $rules, $notes );
	}

	/**
	 * The route keys an endpoint matches.
	 *
	 * Both parts are trimmed of slashes; an empty namespace matches nothing. The needle is `/namespace/endpoint`,
	 * or `/namespace/` for an empty endpoint. Routes whose key starts with the needle match, in registration
	 * order. Without such a route an endpoint holding a pattern character matches nothing; otherwise the routes
	 * whose pattern matches the needle as a concrete path do.
	 *
	 * @param string $namespace Route namespace, e.g. `wp/v2`.
	 * @param string $endpoint  Endpoint below the namespace, e.g. `posts`.
	 *
	 * @return list<string>
	 */
	public function match( string $namespace, string $endpoint ): array { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound -- Parameter name mandated by the translator interface.
		return $this->resolve( $namespace, $endpoint )['routes'];
	}

	/**
	 * Recover `namespace => [rest base]` from enabled registered rules.
	 *
	 * The rest base is the first path segment after the namespace, up to the first slash or opening
	 * parenthesis. The namespace index, rules without a known namespace and wildcard rules are skipped.
	 *
	 * @param array<mixed> $rules Route key => rule: a `Route_Rule`, an `Effective_Rule` or the array form of a rule.
	 *
	 * @return array<string,list<string>>
	 */
	public function reverse( array $rules ): array {
		$out = [];
		foreach ( $rules as $key => $raw ) {
			$key  = (string) $key;
			$rule = $this->rule_of( $raw );
			if ( null === $rule || Route_Rule::STATUS_ENABLED !== $rule->status || Route_Rule::MATCH_REGISTERED !== $rule->match ) {
				continue;
			}

			$info = $this->routes[ $key ] ?? null;
			if ( null === $info || '' === $info->namespace ) {
				continue;
			}

			$base = $this->base_of( $key, $info->namespace );
			if ( '' === $base || in_array( $base, $out[ $info->namespace ] ?? [], true ) ) {
				continue;
			}
			$out[ $info->namespace ][] = $base;
		}
		return $out;
	}

	/**
	 * Resolve an endpoint to the routes it matches.
	 *
	 * @param string $namespace Route namespace.
	 * @param string $endpoint  Endpoint.
	 *
	 * @return array{code:string,routes:list<string>} `code` is `literal`, `concrete_path`, `pattern`, `no_route` or `invalid_entry`.
	 */
	private function resolve( string $namespace, string $endpoint ): array { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound -- Same parameter name as match().
		$ns = trim( $namespace, '/' );
		$ep = trim( $endpoint, '/' );
		if ( '' === $ns ) {
			return [
				'code'   => Translation_Note::INVALID_ENTRY,
				'routes' => [],
			];
		}

		$needle = '' === $ep ? '/' . $ns . '/' : '/' . $ns . '/' . $ep;

		$literal = [];
		foreach ( array_keys( $this->routes ) as $key ) {
			if ( str_starts_with( (string) $key, $needle ) ) {
				$literal[] = (string) $key;
			}
		}
		if ( [] !== $literal ) {
			return [
				'code'   => self::LITERAL,
				'routes' => $literal,
			];
		}

		if ( '' !== $ep && false !== strpbrk( $ep, self::PATTERN_CHARS ) ) {
			return [
				'code'   => Translation_Note::PATTERN,
				'routes' => [],
			];
		}

		$concrete = [];
		foreach ( array_keys( $this->routes ) as $key ) {
			$matched = @preg_match( '@^' . $key . '$@i', $needle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A broken third-party pattern simply does not match.
			if ( 1 === $matched ) {
				$concrete[] = (string) $key;
			}
		}
		if ( [] !== $concrete ) {
			return [
				'code'   => Translation_Note::CONCRETE_PATH,
				'routes' => $concrete,
			];
		}

		return [
			'code'   => Translation_Note::NO_ROUTE,
			'routes' => [],
		];
	}

	/**
	 * Read the entries of a `namespace => [endpoint]` map.
	 *
	 * @param string       $source One of the note sources.
	 * @param array<mixed> $map    The map.
	 *
	 * @return list<Entry>
	 */
	private function map_entries( string $source, array $map ): array {
		$entries = [];
		foreach ( $map as $namespace => $endpoints ) {
			$namespace = (string) $namespace;
			if ( ! is_array( $endpoints ) ) {
				$entries[] = $this->invalid_entry( $source, $namespace, '' );
				continue;
			}
			foreach ( $endpoints as $endpoint ) {
				$entries[] = is_string( $endpoint )
					? $this->entry( $source, $namespace, $endpoint )
					: $this->invalid_entry( $source, $namespace, '' );
			}
		}
		return $entries;
	}

	/**
	 * Read the entries of a `namespace/endpoint => 'Header,Header'` map.
	 *
	 * @param array<mixed> $headers The map.
	 *
	 * @return list<Header_Entry>
	 */
	private function header_entries( array $headers ): array {
		$entries = [];
		foreach ( $headers as $path => $header_list ) {
			$path                     = trim( (string) $path, '/' );
			[ $namespace, $endpoint ] = '' === $path ? [ '', '' ] : $this->split_path( $path );

			if ( '' === $path || ! is_string( $header_list ) ) {
				$entry = $this->invalid_entry( Translation_Note::SOURCE_HEADERS, $namespace, $endpoint );
				$names = [
					'valid'     => [],
					'forbidden' => [],
					'invalid'   => [],
				];
			} else {
				$entry = $this->entry( Translation_Note::SOURCE_HEADERS, $namespace, $endpoint );
				$names = $this->parse_names( $header_list );
			}
			$entries[] = array_merge( $entry, $names );
		}
		return $entries;
	}

	/**
	 * Split `namespace/endpoint` at the longest known namespace, or else after the first segment.
	 *
	 * @param string $path Path without surrounding slashes.
	 *
	 * @return array{0:string,1:string} Namespace and endpoint.
	 */
	private function split_path( string $path ): array {
		foreach ( $this->namespaces as $namespace ) {
			if ( $path === $namespace ) {
				return [ $namespace, '' ];
			}
			if ( str_starts_with( $path, $namespace . '/' ) ) {
				return [ $namespace, substr( $path, strlen( $namespace ) + 1 ) ];
			}
		}

		$parts = explode( '/', $path, 2 );
		return [ $parts[0], $parts[1] ?? '' ];
	}

	/**
	 * Sort the names of a comma separated header list.
	 *
	 * @param string $header_list The list, e.g. `X_Lang, Authorization`.
	 *
	 * @return array{valid:list<string>,forbidden:list<string>,invalid:list<string>}
	 */
	private function parse_names( string $header_list ): array {
		$valid     = [];
		$forbidden = [];
		$invalid   = [];

		foreach ( explode( ',', $header_list ) as $raw ) {
			$raw = trim( $raw );
			if ( '' === $raw ) {
				continue;
			}

			$name = Config_Compiler::normalize_header_name( $raw );
			if ( null === $name ) {
				$invalid[] = $raw;
			} elseif ( in_array( $name, Route_Rule::FORBIDDEN_VARY_HEADERS, true ) ) {
				$forbidden[] = $name;
			} else {
				$valid[] = $name;
			}
		}

		return [
			'valid'     => array_values( array_unique( $valid ) ),
			'forbidden' => array_values( array_unique( $forbidden ) ),
			'invalid'   => array_values( array_unique( $invalid ) ),
		];
	}

	/**
	 * Build the entry of an endpoint with the routes it resolves to.
	 *
	 * @param string $source    One of the note sources.
	 * @param string $namespace Namespace.
	 * @param string $endpoint  Endpoint.
	 *
	 * @return Entry
	 */
	private function entry( string $source, string $namespace, string $endpoint ): array { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound -- Same parameter name as match().
		$resolved = $this->resolve( $namespace, $endpoint );
		return [
			'source'    => $source,
			'namespace' => trim( $namespace, '/' ),
			'endpoint'  => trim( $endpoint, '/' ),
			'code'      => $resolved['code'],
			'routes'    => $resolved['routes'],
		];
	}

	/**
	 * Build an entry that can not be used.
	 *
	 * @param string $source    One of the note sources.
	 * @param string $namespace Namespace.
	 * @param string $endpoint  Endpoint.
	 *
	 * @return Entry
	 */
	private function invalid_entry( string $source, string $namespace, string $endpoint ): array { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound -- Same parameter name as match().
		return [
			'source'    => $source,
			'namespace' => trim( $namespace, '/' ),
			'endpoint'  => trim( $endpoint, '/' ),
			'code'      => Translation_Note::INVALID_ENTRY,
			'routes'    => [],
		];
	}

	/**
	 * The first entry that claims each usable route: discovered, not denied, and readable or registering a method.
	 *
	 * @param array<mixed> $entries The entries of one input.
	 * @phpstan-param list<Entry> $entries
	 * @param string[]     $methods The request methods besides GET that make a route usable without a GET handler.
	 * @phpstan-param list<string> $methods
	 *
	 * @return array<string,int> Route key => entry index.
	 */
	private function claims( array $entries, array $methods ): array {
		$claims = [];
		foreach ( $entries as $index => $entry ) {
			foreach ( $entry['routes'] as $key ) {
				$info = $this->routes[ $key ] ?? null;
				if ( null === $info || ! $this->usable( $key, $info, $methods ) || isset( $claims[ $key ] ) ) {
					continue;
				}
				$claims[ $key ] = $index;
			}
		}
		return $claims;
	}

	/**
	 * Whether a route is enabled, by an allowed entry or by default, and not excluded.
	 *
	 * @param string            $key          Route key.
	 * @param array<string,int> $allow_claims Routes claimed by allowed entries.
	 * @param array<string,int> $deny_claims  Routes claimed by disallowed entries.
	 * @param string[]          $methods      The request methods besides GET that make a route usable without a GET handler.
	 * @phpstan-param list<string> $methods
	 *
	 * @return bool
	 */
	private function is_active( string $key, array $allow_claims, array $deny_claims, array $methods ): bool {
		$info = $this->routes[ $key ] ?? null;
		if ( null === $info || ! $this->usable( $key, $info, $methods ) || isset( $deny_claims[ $key ] ) ) {
			return false;
		}
		return isset( $allow_claims[ $key ] ) || $info->is_default;
	}

	/**
	 * Whether a route can be given a rule: it is not denied, and it is readable or registers a method to cache.
	 *
	 * @param string     $key     Route key.
	 * @param Route_Info $info    The route.
	 * @param string[]   $methods The request methods besides GET to cache.
	 * @phpstan-param list<string> $methods
	 *
	 * @return bool
	 */
	private function usable( string $key, Route_Info $info, array $methods ): bool {
		if ( $info->denied ) {
			return false;
		}
		return $info->readable || [] !== $this->route_methods( $key, $info, $methods );
	}

	/**
	 * The request methods besides GET that a route caches: the given ones it registers, none on a core `wp/v2` route.
	 *
	 * @param string     $key     Route key.
	 * @param Route_Info $info    The route.
	 * @param string[]   $methods The request methods besides GET to cache.
	 * @phpstan-param list<string> $methods
	 *
	 * @return list<string> In {@see Route_Rule::METHODS} order.
	 */
	private function route_methods( string $key, Route_Info $info, array $methods ): array {
		if ( [] === $methods || 'wp/v2' === $info->namespace || str_starts_with( $key, '/wp/v2' ) ) {
			return [];
		}
		return array_values(
			array_filter(
				$methods,
				static fn( string $method ): bool => $info->registers( $method )
			)
		);
	}

	/**
	 * The request methods besides GET that a translation can cache: POST, PUT and PATCH, uppercased, in canonical order.
	 *
	 * @param string[] $methods Request methods.
	 * @phpstan-param list<string> $methods
	 *
	 * @return list<string>
	 */
	private static function extra_methods( array $methods ): array {
		$names = array_map( static fn( string $method ): string => strtoupper( trim( $method ) ), $methods );
		return array_values( array_intersect( array_diff( Route_Rule::METHODS, Route_Rule::DEFAULT_METHODS ), $names ) );
	}

	/**
	 * Add the header names of every header entry to the routes they match that are active.
	 *
	 * @param array<mixed>      $entries      The header entries.
	 * @phpstan-param list<Header_Entry> $entries
	 * @param array<string,int> $allow_claims Routes claimed by allowed entries.
	 * @param array<string,int> $deny_claims  Routes claimed by disallowed entries.
	 * @param int               $max_vary     How many headers one route may vary by.
	 * @param string[]          $methods      The request methods besides GET that make a route usable without a GET handler.
	 * @phpstan-param list<string> $methods
	 *
	 * @return Vary_Result The header names per route, the first header entry per route and what each entry applied.
	 */
	private function apply_vary( array $entries, array $allow_claims, array $deny_claims, int $max_vary, array $methods ): array {
		$vary    = [];
		$claim   = [];
		$applied = [];

		foreach ( $entries as $index => $entry ) {
			$result = [
				'kept'           => [],
				'kept_routes'    => [],
				'dropped'        => [],
				'dropped_routes' => [],
			];

			foreach ( $entry['routes'] as $key ) {
				if ( ! $this->is_active( $key, $allow_claims, $deny_claims, $methods ) ) {
					continue;
				}

				$held = $vary[ $key ] ?? [];
				$got  = false;
				$lost = false;
				foreach ( $entry['valid'] as $name ) {
					if ( in_array( $name, $held, true ) || count( $held ) < $max_vary ) {
						if ( ! in_array( $name, $held, true ) ) {
							$held[] = $name;
						}
						if ( ! in_array( $name, $result['kept'], true ) ) {
							$result['kept'][] = $name;
						}
						$got = true;
					} else {
						if ( ! in_array( $name, $result['dropped'], true ) ) {
							$result['dropped'][] = $name;
						}
						$lost = true;
					}
				}

				if ( [] !== $held ) {
					$vary[ $key ] = $held;
					if ( ! isset( $claim[ $key ] ) ) {
						$claim[ $key ] = $index;
					}
				}
				if ( $got ) {
					$result['kept_routes'][] = $key;
				}
				if ( $lost ) {
					$result['dropped_routes'][] = $key;
				}
			}

			$applied[ $index ] = $result;
		}

		return [
			'vary'    => $vary,
			'claim'   => $claim,
			'applied' => $applied,
		];
	}

	/**
	 * Decide the rule of every route, in registration order.
	 *
	 * @param array<string,int>   $allow_claims Routes claimed by allowed entries.
	 * @param array<string,int>   $deny_claims  Routes claimed by disallowed entries.
	 * @param array<string,mixed> $varied The header names per route.
	 * @phpstan-param Vary_Result $varied
	 * @param string[]            $methods The request methods besides GET that allowed routes cache.
	 * @phpstan-param list<string> $methods
	 *
	 * @return array{0:array<string,Rule_Array>,1:array<string,array<int,array<string,Decision>>>} The rules, and the decisions per note source and entry index.
	 */
	private function decide( array $allow_claims, array $deny_claims, array $varied, array $methods ): array {
		$rules     = [];
		$decisions = [];

		foreach ( $this->routes as $key => $info ) {
			$key = (string) $key;
			if ( ! $this->usable( $key, $info, $methods ) ) {
				continue;
			}

			$in_allow = isset( $allow_claims[ $key ] );
			$in_deny  = isset( $deny_claims[ $key ] );
			$headers  = $varied['vary'][ $key ] ?? [];
			if ( ! $in_allow && ! $in_deny && [] === $headers ) {
				continue;
			}
			// Only a route that is enabled, by an allowed entry or by default, has anything to change.
			if ( ! $in_allow && ! $info->is_default ) {
				continue;
			}

			if ( $in_deny ) {
				$source = Translation_Note::SOURCE_DISALLOWED;
				$index  = $deny_claims[ $key ];
				$code   = Translation_Note::EXCLUDED;
				$rule   = new Route_Rule( Route_Rule::STATUS_EXCLUDED );
			} elseif ( $in_allow ) {
				$extra  = $this->route_methods( $key, $info, $methods );
				$source = Translation_Note::SOURCE_ALLOWED;
				$index  = $allow_claims[ $key ];
				$code   = $info->is_default && [] === $headers && [] === $extra ? Translation_Note::ALREADY_DEFAULT : Translation_Note::ENABLED;
				$rule   = Translation_Note::ALREADY_DEFAULT === $code ? null : $this->enabled_rule( $headers, $info->readable, $extra );
			} else {
				$source = Translation_Note::SOURCE_HEADERS;
				$index  = $varied['claim'][ $key ] ?? 0;
				$code   = '';
				$rule   = $this->enabled_rule( $headers );
			}

			$problems = Route_Rule::validate_key( $key, Route_Rule::MATCH_REGISTERED );
			if ( null !== $rule ) {
				$problems->merge( $rule->validate() );
			}
			if ( ! $problems->is_valid() ) {
				$decisions[ $source ][ $index ][ Translation_Note::INVALID_RULE . ':' . $key ] = [
					'code'   => Translation_Note::INVALID_RULE,
					'routes' => [ $key ],
					'values' => $problems->codes(),
				];
				continue;
			}

			if ( null !== $rule ) {
				$rules[ $key ] = $rule->to_array();
			}
			if ( '' !== $code ) {
				$decision                                = $decisions[ $source ][ $index ][ $code ] ?? [
					'code'   => $code,
					'routes' => [],
					'values' => [],
				];
				$decision['routes'][]                    = $key;
				$decisions[ $source ][ $index ][ $code ] = $decision;
			}
		}

		return [ $rules, $decisions ];
	}

	/**
	 * The rule that enables a route: the built-in default rule, varied by the given headers and caching the given
	 * methods besides GET, which it keeps while the route is readable.
	 *
	 * @param string[] $headers  Lowercase header names.
	 * @phpstan-param list<string> $headers
	 * @param bool     $readable Whether the route has a GET handler.
	 * @param string[] $extra    Request methods besides GET the rule caches.
	 * @phpstan-param list<string> $extra
	 *
	 * @return Route_Rule
	 */
	private function enabled_rule( array $headers, bool $readable = true, array $extra = [] ): Route_Rule {
		$rule = Config::default_route_rule();
		if ( [] !== $headers ) {
			$rule = Route_Rule::from_array( array_merge( $rule->to_array(), [ 'vary_headers' => $headers ] ) );
		}
		if ( [] === $extra ) {
			return $rule;
		}
		return $rule->with_methods( $readable ? array_merge( Route_Rule::DEFAULT_METHODS, $extra ) : $extra );
	}

	/**
	 * The notes about how an entry itself was read.
	 *
	 * @param array<string,mixed> $entry The entry.
	 * @phpstan-param Entry $entry
	 *
	 * @return list<Translation_Note>
	 */
	private function entry_notes( array $entry ): array {
		$notes = [];

		switch ( $entry['code'] ) {
			case Translation_Note::INVALID_ENTRY:
			case Translation_Note::PATTERN:
			case Translation_Note::NO_ROUTE:
				$notes[] = $this->note( $entry, $entry['code'] );
				break;
			case Translation_Note::CONCRETE_PATH:
				$notes[] = $this->note( $entry, Translation_Note::CONCRETE_PATH, $entry['routes'] );
				break;
		}

		$denied = [];
		foreach ( $entry['routes'] as $key ) {
			$info = $this->routes[ $key ] ?? null;
			if ( null !== $info && $info->denied ) {
				$denied[] = $key;
			}
		}
		if ( [] !== $denied ) {
			$notes[] = $this->note( $entry, Translation_Note::DENIED, $denied );
		}

		return $notes;
	}

	/**
	 * The notes about the header names of a header entry and what became of them.
	 *
	 * @param array<string,mixed>      $entry The entry.
	 * @phpstan-param Header_Entry $entry
	 * @param array<string,mixed>|null $applied What the entry applied to routes.
	 * @phpstan-param Applied|null $applied
	 *
	 * @return list<Translation_Note>
	 */
	private function header_notes( array $entry, ?array $applied ): array {
		$notes = [];

		if ( [] !== $entry['forbidden'] ) {
			$notes[] = $this->note( $entry, Translation_Note::VARY_FORBIDDEN, [], $entry['forbidden'] );
		}
		if ( [] !== $entry['invalid'] ) {
			$notes[] = $this->note( $entry, Translation_Note::VARY_INVALID, [], $entry['invalid'] );
		}
		if ( null !== $applied && [] !== $applied['kept'] ) {
			$notes[] = $this->note( $entry, Translation_Note::VARY, $applied['kept_routes'], $applied['kept'] );
		}
		if ( null !== $applied && [] !== $applied['dropped'] ) {
			$notes[] = $this->note( $entry, Translation_Note::VARY_LIMIT, $applied['dropped_routes'], $applied['dropped'] );
		}

		return $notes;
	}

	/**
	 * The notes about the rules decided for the routes an entry claimed.
	 *
	 * @param array<string,mixed>               $entry The entry.
	 * @phpstan-param Entry $entry
	 * @param array<string,array<string,mixed>> $decisions The decisions, in the order they were made.
	 * @phpstan-param array<string,Decision> $decisions
	 *
	 * @return list<Translation_Note>
	 */
	private function decision_notes( array $entry, array $decisions ): array {
		$notes = [];
		foreach ( $decisions as $decision ) {
			$notes[] = $this->note( $entry, $decision['code'], $decision['routes'], $decision['values'] );
		}
		return $notes;
	}

	/**
	 * Build a note about an entry.
	 *
	 * @param array<string,mixed> $entry  The entry.
	 * @phpstan-param Entry $entry
	 * @param string              $code   Note code.
	 * @param string[]            $routes Route keys.
	 * @phpstan-param list<string> $routes
	 * @param string[]            $values Values.
	 * @phpstan-param list<string> $values
	 *
	 * @return Translation_Note
	 */
	private function note( array $entry, string $code, array $routes = [], array $values = [] ): Translation_Note {
		return new Translation_Note( $code, $entry['source'], $entry['namespace'], $entry['endpoint'], $routes, $values );
	}

	/**
	 * The rule behind an entry of the reverse input.
	 *
	 * @param mixed $raw A `Route_Rule`, an `Effective_Rule` or the array form of a rule.
	 *
	 * @return Route_Rule|null
	 */
	private function rule_of( mixed $raw ): ?Route_Rule {
		if ( $raw instanceof Effective_Rule ) {
			return $raw->rule;
		}
		if ( $raw instanceof Route_Rule ) {
			return $raw;
		}
		if ( is_array( $raw ) ) {
			return Route_Rule::from_array( $raw );
		}
		return null;
	}

	/**
	 * The rest base of a route: the head after the namespace, up to the first slash or opening parenthesis.
	 *
	 * @param string $key       Route key.
	 * @param string $namespace Namespace of the route.
	 *
	 * @return string Empty when the route has no base, as the namespace index.
	 */
	private function base_of( string $key, string $namespace ): string { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound -- Same parameter name as match().
		$prefix = '/' . $namespace . '/';
		if ( ! str_starts_with( $key, $prefix ) ) {
			return '';
		}

		$head = substr( $key, strlen( $prefix ) );
		$end  = strcspn( $head, '/(' );
		return substr( $head, 0, $end );
	}
}
