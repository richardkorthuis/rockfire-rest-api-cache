<?php
/**
 * The bridge of the legacy endpoint filters onto the route rules.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Effective_Rule;
use Rockfire\Rest_Api_Cache\Config\Route_Info;
use Rockfire\Rest_Api_Cache\Config\Route_Rule;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Answers `rfrac/route_rules` and `rfrac/global_ignore_params` from the compile-time endpoint filters of the original.
 *
 * The original filtered three endpoint lists when it saved its options: the allowed endpoints, the disallowed endpoints
 * and the cacheable request headers per endpoint. This bridge hands those filters the lists the current route rules
 * describe, read back with {@see Endpoint_Translator::reverse()}: the enabled registered rules as the allowed endpoints,
 * the excluded registered rules as the disallowed endpoints, and the vary headers of the enabled registered rules as the
 * request headers. What the callbacks change is translated forward again with {@see Endpoint_Translator::forward()}:
 * an added allowed endpoint enables its routes, a removed allowed endpoint and an added disallowed endpoint exclude the
 * routes that would otherwise be cached, and a new or changed header entry varies its routes. An endpoint the
 * callbacks left as it was changes nothing, so the rules Rockfire holds are never re-applied at the level of a rest base.
 *
 * Rockfire wins: a rule set in the settings or in `RFRAC_ROUTES` is never changed, and every such route gets a `kept`
 * line in the report instead. Wildcard rules are never produced or changed, and the hard deny-list stays authoritative,
 * because the translator never builds a rule for a denied route. The bridge runs at priority 1, so a site's own
 * `rfrac/route_rules` callbacks see its result and override it.
 *
 * The rules are built on every cache miss and on content saves, not only when the configuration is compiled, so the
 * legacy callbacks run at most once per request for the same input: the result is kept per request, keyed by the
 * rules, their layers, the discovered routes and the number of legacy callbacks. When no legacy endpoint filter has a
 * callback the rules are returned untouched, without firing anything. Once a compile is saved, what could not be
 * translated replaces the `endpoints` group of {@see Findings}.
 *
 * @since 1.0.0
 *
 * @phpstan-type Line array{status:string,note:Translation_Note,route:string,layer:string,max_vary:int}
 */
final class Endpoint_Bridge {

	use Hook_Subscriber_Trait;

	/**
	 * The legacy filter of the allowed endpoints, `namespace => [endpoint]`.
	 *
	 * @var string
	 */
	public const ALLOWED = 'wp_rest_cache/allowed_endpoints';

	/**
	 * The legacy filter of the disallowed endpoints, `namespace => [endpoint]`.
	 *
	 * @var string
	 */
	public const DISALLOWED = 'wp_rest_cache/disallowed_endpoints';

	/**
	 * The legacy filter of the cacheable request headers, `namespace/endpoint => 'Header,Header'`.
	 *
	 * @var string
	 */
	public const HEADERS = 'wp_rest_cache/cacheable_request_headers';

	/**
	 * The legacy filter of the query parameters left out of the cache key.
	 *
	 * @var string
	 */
	public const UNCACHED = 'wp_rest_cache/uncached_parameters';

	/**
	 * How many results the per-request memo keeps.
	 *
	 * @var int
	 */
	private const MEMO_LIMIT = 8;

	/**
	 * The legacy filter of each input of the translator.
	 *
	 * @var array<string,string>
	 */
	private const FILTER_OF_SOURCE = [
		Translation_Note::SOURCE_ALLOWED    => self::ALLOWED,
		Translation_Note::SOURCE_DISALLOWED => self::DISALLOWED,
		Translation_Note::SOURCE_HEADERS    => self::HEADERS,
	];

	/**
	 * The note codes reported as adjusted: the entry was applied, but not as it was written.
	 *
	 * @var list<string>
	 */
	private const ADJUSTED_CODES = [ Translation_Note::CONCRETE_PATH, Translation_Note::VARY_LIMIT ];

	/**
	 * The note codes reported as skipped: the entry, or part of it, was not applied.
	 *
	 * @var list<string>
	 */
	private const SKIPPED_CODES = [
		Translation_Note::PATTERN,
		Translation_Note::NO_ROUTE,
		Translation_Note::DENIED,
		Translation_Note::INVALID_ENTRY,
		Translation_Note::VARY_FORBIDDEN,
		Translation_Note::VARY_INVALID,
		Translation_Note::INVALID_RULE,
	];

	/**
	 * The note codes of the decisions that produce a rule.
	 *
	 * @var list<string>
	 */
	private const RULE_CODES = [ Translation_Note::ENABLED, Translation_Note::EXCLUDED, Translation_Note::VARY ];

	/**
	 * Settings access, for the global vary headers.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Store of the findings.
	 *
	 * @var Findings
	 */
	private Findings $findings;

	/**
	 * The results of this request, by the hash of their input.
	 *
	 * @var array<string,array{rules:array<mixed>,report:list<Line>}>
	 */
	private array $memo = [];

	/**
	 * The report lines of the rules built last: the notes on what could not be translated as written, and the routes
	 * kept because their rule is set in Rockfire.
	 *
	 * @var list<Line>
	 */
	private array $report = [];

	/**
	 * The rules being built.
	 *
	 * @var array<mixed>
	 */
	private array $working = [];

	/**
	 * The layer of each rule being built.
	 *
	 * @var array<mixed>
	 */
	private array $layers = [];

	/**
	 * The routes that already have a kept line in the report being built.
	 *
	 * @var array<string,true>
	 */
	private array $kept = [];

	/**
	 * Create the bridge.
	 *
	 * @param Config   $config   Settings access.
	 * @param Findings $findings Store of the findings.
	 */
	public function __construct( Config $config, Findings $findings ) {
		$this->config   = $config;
		$this->findings = $findings;
	}

	/**
	 * The hooks: `rfrac/route_rules` (1, 3), `rfrac/global_ignore_params` (1, 1) and `rfrac/compiled` (10, 1).
	 *
	 * @return array<int,array<int,mixed>>
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int}>
	 */
	public static function hooks(): array {
		return [
			[ Hooks::ROUTE_RULES, 'route_rules', 1, 3 ],
			[ Hooks::GLOBAL_IGNORE_PARAMS, 'uncached_parameters', 1, 1 ],
			[ Hooks::COMPILED, 'record_report', 10, 1 ],
		];
	}

	/**
	 * `rfrac/route_rules`: apply what the legacy endpoint filters change to the route rules.
	 *
	 * Returns `$rules` untouched when it is not an array or none of the three legacy endpoint filters has a callback.
	 * A repeat of the same input in this request returns the result of the first call without firing a filter.
	 *
	 * @param mixed $rules   Route key => rule array.
	 * @param mixed $sources Route key => the layer of the rule: `default`, `settings` or `constant`.
	 * @param mixed $routes  Route key => {@see Route_Info}, in registration order.
	 *
	 * @return mixed The rules.
	 */
	public function route_rules( mixed $rules, mixed $sources = [], mixed $routes = [] ): mixed {
		if ( ! is_array( $rules ) ) {
			return $rules;
		}
		if ( ! self::hooked() ) {
			$this->report = [];
			return $rules;
		}

		$sources = is_array( $sources ) ? $sources : [];
		$routes  = is_array( $routes ) ? $routes : [];
		$hash    = md5( serialize( [ $rules, $sources, array_keys( $routes ), self::callback_counts() ] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Only hashed, never stored or unserialized.
		if ( isset( $this->memo[ $hash ] ) ) {
			$this->report = $this->memo[ $hash ]['report'];
			return $this->memo[ $hash ]['rules'];
		}

		$result = $this->bridge( $rules, $sources, self::route_infos( $routes ) );
		if ( count( $this->memo ) >= self::MEMO_LIMIT ) {
			unset( $this->memo[ (string) array_key_first( $this->memo ) ] );
		}
		$this->memo[ $hash ] = [
			'rules'  => $result,
			'report' => $this->report,
		];
		return $result;
	}

	/**
	 * `rfrac/global_ignore_params`: the parameters the legacy filter of the uncached parameters returns.
	 *
	 * The result is used when it is an array of strings; anything else keeps `$params`. The compiler validates the names.
	 *
	 * @param array<mixed> $params Parameter names.
	 *
	 * @return array<mixed>
	 */
	public function uncached_parameters( array $params ): array {
		$result = Bridge::filter( self::UNCACHED, [ $params ] );
		if ( ! is_array( $result ) ) {
			return $params;
		}
		foreach ( $result as $param ) {
			if ( ! is_string( $param ) ) {
				return $params;
			}
		}
		return $result;
	}

	/**
	 * `rfrac/compiled`: replace the `endpoints` group of the findings with the report of the rules the compile used.
	 *
	 * One finding per report line, so an entry that no longer applies disappears with the next saved compile. A compile
	 * without a server builds no rules and leaves the group alone. An empty report removes the group instead of storing
	 * an empty one, so a site without legacy callbacks keeps no trace of the original.
	 *
	 * @param Compiled_Config $config The compiled configuration that was saved.
	 *
	 * @return void
	 */
	public function record_report( Compiled_Config $config ): void {
		if ( $config->pending_discovery() ) {
			return;
		}

		$findings = [];
		$seen     = [];
		foreach ( $this->report as $line ) {
			$finding = $this->finding( $line );
			$id      = implode( "\0", $finding->to_array() );
			if ( ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$findings[]  = $finding;
			}
		}

		if ( [] === $findings ) {
			$this->findings->clear( Findings::GROUP_ENDPOINTS );
			return;
		}
		$this->findings->replace( Findings::GROUP_ENDPOINTS, $findings );
	}

	/**
	 * Fire the legacy endpoint filters and apply what they change.
	 *
	 * @param array<mixed>             $rules   Route key => rule array.
	 * @param array<mixed>             $sources Route key => the layer of the rule.
	 * @param array<string,Route_Info> $routes  The discovered routes.
	 *
	 * @return array<mixed> The rules.
	 */
	private function bridge( array $rules, array $sources, array $routes ): array {
		$this->report  = [];
		$this->working = $rules;
		$this->layers  = $sources;
		$this->kept    = [];

		$translator                                  = new Endpoint_Translator( $routes );
		[ $allowed_in, $disallowed_in, $headers_in ] = self::legacy_inputs( $translator, $rules );

		$allowed_out    = self::normalize_map( self::fire( self::ALLOWED, $allowed_in ) );
		$disallowed_out = self::normalize_map( self::fire( self::DISALLOWED, $disallowed_in ) );
		$headers_out    = self::fire( self::HEADERS, $headers_in );

		// Only what the callbacks changed is excluded: an endpoint handed in as disallowed is a rest base, so excluding it
		// again would also exclude the sibling routes of a route that is excluded on its own.
		$added   = self::added( $allowed_in, $allowed_out );
		$removed = self::removed( $allowed_in, $allowed_out );
		$deny    = self::merge_maps( $removed, self::added( $disallowed_in, $disallowed_out ) );
		$changed = self::changed_headers( $headers_in, $headers_out );

		if ( [] !== $added ) {
			// A disallowed endpoint wins over an allowed one, as it did in the original, whether it was excluded already or not.
			$denied      = self::matched_routes( $translator, self::merge_maps( $removed, $disallowed_out ) );
			$translation = $translator->forward( $added );
			$this->note( $translation, 0 );
			foreach ( $translation->rules as $key => $rule ) {
				if ( ! isset( $denied[ $key ] ) ) {
					$this->apply( $key, $rule, $translation, false );
				}
			}
		}

		if ( [] !== $deny ) {
			$translation = $translator->forward( [], $deny );
			$this->note( $translation, 0 );
			foreach ( $translation->rules as $key => $rule ) {
				$this->apply( $key, $rule, $translation, false );
			}
		}

		if ( [] !== $changed ) {
			$max_vary    = $this->max_vary();
			$translation = $translator->forward( [], [], $changed, $max_vary );
			$this->note( $translation, $max_vary );
			foreach ( $translation->rules as $key => $rule ) {
				$this->apply( $key, $rule, $translation, true );
			}
		}

		$result        = $this->working;
		$this->working = [];
		$this->layers  = [];
		$this->kept    = [];
		return $result;
	}

	/**
	 * The legacy inputs the current rules describe.
	 *
	 * @param Endpoint_Translator $translator The translator of the discovered routes.
	 * @param array<mixed>        $rules      Route key => rule array.
	 *
	 * @return array{0:array<string,list<string>>,1:array<string,list<string>>,2:array<string,string>} The allowed
	 *         endpoints (the enabled registered rules), the disallowed endpoints (the excluded registered rules) and
	 *         the request headers per endpoint (the vary headers of the enabled registered rules).
	 */
	private static function legacy_inputs( Endpoint_Translator $translator, array $rules ): array {
		$enabled  = [];
		$excluded = [];
		$headers  = [];

		foreach ( $rules as $key => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$key  = (string) $key;
			$rule = Route_Rule::from_array( $data );
			if ( Route_Rule::MATCH_REGISTERED !== $rule->match ) {
				continue;
			}
			if ( Route_Rule::STATUS_EXCLUDED === $rule->status ) {
				// The translator reads enabled rules back; the excluded routes are read the same way.
				$excluded[ $key ] = new Route_Rule( Route_Rule::STATUS_ENABLED );
				continue;
			}
			if ( Route_Rule::STATUS_ENABLED !== $rule->status ) {
				continue;
			}

			$enabled[ $key ] = $rule;
			if ( [] === $rule->vary_headers ) {
				continue;
			}
			foreach ( $translator->reverse( [ $key => $rule ] ) as $namespace => $bases ) {
				foreach ( $bases as $base ) {
					$path             = $namespace . '/' . $base;
					$names            = isset( $headers[ $path ] ) ? explode( ',', $headers[ $path ] ) : [];
					$headers[ $path ] = implode( ',', array_values( array_unique( array_merge( $names, $rule->vary_headers ) ) ) );
				}
			}
		}

		return [ $translator->reverse( $enabled ), $translator->reverse( $excluded ), $headers ];
	}

	/**
	 * Run the callbacks of a legacy endpoint filter.
	 *
	 * A callback that throws is recorded by {@see Bridge}; a result that is not an array is recorded here. Either way
	 * the list counts as unchanged.
	 *
	 * @param string       $name  The legacy filter.
	 * @param array<mixed> $value The list to filter.
	 *
	 * @phpstan-param non-empty-string $name
	 *
	 * @return array<mixed>
	 */
	private static function fire( string $name, array $value ): array {
		$result = Bridge::filter( $name, [ $value ] );
		if ( is_array( $result ) ) {
			return $result;
		}

		Failure_Recorder::record( 'hook:' . $name, sprintf( 'The filter returned %s instead of an array and was ignored.', gettype( $result ) ) );
		return $value;
	}

	/**
	 * A `namespace => [endpoint]` map with its namespaces and endpoints trimmed of slashes and without duplicates.
	 *
	 * A value that is not a list is kept as it is, so the translator reports the entry.
	 *
	 * @param array<mixed> $map The map.
	 *
	 * @return array<string,mixed>
	 */
	private static function normalize_map( array $map ): array {
		return self::merge_maps( [], $map );
	}

	/**
	 * Add the entries of a `namespace => [endpoint]` map to another one, per namespace and without duplicates.
	 *
	 * Namespaces and string endpoints are trimmed of slashes. A value that is not a list is kept as it is, so the
	 * translator reports the entry, unless the namespace already holds a list.
	 *
	 * @param array<string,mixed> $into The map to add to.
	 * @param array<mixed>        $map  The map to add.
	 *
	 * @return array<string,mixed>
	 */
	private static function merge_maps( array $into, array $map ): array {
		foreach ( $map as $namespace => $endpoints ) {
			$namespace = trim( (string) $namespace, '/' );
			$list      = $into[ $namespace ] ?? [];
			if ( ! is_array( $endpoints ) || ! is_array( $list ) ) {
				if ( ! array_key_exists( $namespace, $into ) ) {
					$into[ $namespace ] = $endpoints;
				}
				continue;
			}
			foreach ( $endpoints as $endpoint ) {
				$endpoint = is_string( $endpoint ) ? trim( $endpoint, '/' ) : $endpoint;
				if ( ! in_array( $endpoint, $list, true ) ) {
					$list[] = $endpoint;
				}
			}
			$into[ $namespace ] = $list;
		}
		return $into;
	}

	/**
	 * The entries of a filtered `namespace => [endpoint]` map that the input did not hold.
	 *
	 * @param array<string,list<string>> $before The endpoints handed to the filter.
	 * @param array<string,mixed>        $after  The endpoints it returned, normalised.
	 *
	 * @return array<string,mixed>
	 */
	private static function added( array $before, array $after ): array {
		$added = [];
		foreach ( $after as $namespace => $endpoints ) {
			if ( ! is_array( $endpoints ) ) {
				$added[ $namespace ] = $endpoints;
				continue;
			}
			$new = [];
			foreach ( $endpoints as $endpoint ) {
				if ( ! is_string( $endpoint ) || ! in_array( $endpoint, $before[ $namespace ] ?? [], true ) ) {
					$new[] = $endpoint;
				}
			}
			if ( [] !== $new ) {
				$added[ $namespace ] = $new;
			}
		}
		return $added;
	}

	/**
	 * The entries of the allowed endpoints that the filter removed.
	 *
	 * @param array<string,list<string>> $before The allowed endpoints handed to the filter.
	 * @param array<string,mixed>        $after  The allowed endpoints it returned, normalised.
	 *
	 * @return array<string,list<string>>
	 */
	private static function removed( array $before, array $after ): array {
		$removed = [];
		foreach ( $before as $namespace => $endpoints ) {
			$left = $after[ $namespace ] ?? [];
			$left = is_array( $left ) ? $left : [];
			$gone = array_values( array_diff( $endpoints, array_filter( $left, 'is_string' ) ) );
			if ( [] !== $gone ) {
				$removed[ $namespace ] = $gone;
			}
		}
		return $removed;
	}

	/**
	 * The header entries the filter added or changed.
	 *
	 * @param array<string,string> $before The request headers handed to the filter.
	 * @param array<mixed>         $after  The request headers it returned.
	 *
	 * @return array<mixed>
	 */
	private static function changed_headers( array $before, array $after ): array {
		$changed = [];
		foreach ( $after as $path => $header_list ) {
			if ( ! array_key_exists( $path, $before ) || $before[ $path ] !== $header_list ) {
				$changed[ $path ] = $header_list;
			}
		}
		return $changed;
	}

	/**
	 * The route keys the string entries of a `namespace => [endpoint]` map match.
	 *
	 * @param Endpoint_Translator $translator The translator of the discovered routes.
	 * @param array<string,mixed> $map        The map.
	 *
	 * @return array<string,true>
	 */
	private static function matched_routes( Endpoint_Translator $translator, array $map ): array {
		$keys = [];
		foreach ( $map as $namespace => $endpoints ) {
			if ( ! is_array( $endpoints ) ) {
				continue;
			}
			foreach ( $endpoints as $endpoint ) {
				if ( ! is_string( $endpoint ) ) {
					continue;
				}
				foreach ( $translator->match( $namespace, $endpoint ) as $key ) {
					$keys[ $key ] = true;
				}
			}
		}
		return $keys;
	}

	/**
	 * Apply one translated rule to the rules being built.
	 *
	 * A rule set in the settings or in `RFRAC_ROUTES` is kept and gets a report line. A wildcard rule under the same
	 * key is never changed.
	 *
	 * @param string              $key          Route key.
	 * @param array<string,mixed> $rule         The translated rule.
	 * @param Translation         $translation  The translation that produced it.
	 * @param bool                $only_enabled Whether only a route that is enabled now may get the rule.
	 *
	 * @return void
	 */
	private function apply( string $key, array $rule, Translation $translation, bool $only_enabled ): void {
		$layer = $this->layers[ $key ] ?? null;
		if ( null !== $layer && Effective_Rule::SOURCE_DEFAULT !== $layer ) {
			$this->keep( $key, is_string( $layer ) ? $layer : '', $translation );
			return;
		}

		$current = $this->working[ $key ] ?? null;
		$current = is_array( $current ) ? Route_Rule::from_array( $current ) : null;
		if ( null !== $current && Route_Rule::MATCH_WILDCARD === $current->match ) {
			return;
		}
		// Request headers never enable a route that is not cached, such as one a disallowed endpoint excluded.
		if ( $only_enabled && ( null === $current || Route_Rule::STATUS_ENABLED !== $current->status ) ) {
			return;
		}

		$this->working[ $key ] = $rule;
	}

	/**
	 * Report a route whose rule is set in Rockfire, once.
	 *
	 * @param string      $key         Route key.
	 * @param string      $layer       The layer of its rule: `settings` or `constant`.
	 * @param Translation $translation The translation whose rule it keeps out.
	 *
	 * @return void
	 */
	private function keep( string $key, string $layer, Translation $translation ): void {
		if ( isset( $this->kept[ $key ] ) ) {
			return;
		}

		// The note of the decision that produced the rule; any note about the route when there is none.
		$found = null;
		$any   = null;
		foreach ( $translation->notes as $note ) {
			if ( ! in_array( $key, $note->routes, true ) ) {
				continue;
			}
			$any ??= $note;
			if ( in_array( $note->code, self::RULE_CODES, true ) ) {
				$found = $note;
				break;
			}
		}
		$found ??= $any;
		if ( null === $found ) {
			return;
		}

		$this->kept[ $key ] = true;
		$this->report[]     = [
			'status'   => Finding::KEPT,
			'note'     => $found,
			'route'    => $key,
			'layer'    => $layer,
			'max_vary' => 0,
		];
	}

	/**
	 * Add the notes of a translation that tell what could not be translated as written to the report.
	 *
	 * @param Translation $translation The translation.
	 * @param int         $max_vary    How many headers a rule could vary on.
	 *
	 * @return void
	 */
	private function note( Translation $translation, int $max_vary ): void {
		foreach ( $translation->notes as $note ) {
			if ( in_array( $note->code, self::ADJUSTED_CODES, true ) ) {
				$status = Finding::ADJUSTED;
			} elseif ( in_array( $note->code, self::SKIPPED_CODES, true ) ) {
				$status = Finding::SKIPPED;
			} else {
				continue;
			}
			$this->report[] = [
				'status'   => $status,
				'note'     => $note,
				'route'    => '',
				'layer'    => '',
				'max_vary' => $max_vary,
			];
		}
	}

	/**
	 * How many request headers a translated rule may vary on besides the global ones.
	 *
	 * @return int
	 */
	private function max_vary(): int {
		$global = $this->config->settings()['global_vary_headers'] ?? [];
		return max( 0, Route_Rule::MAX_VARY_HEADERS - ( is_array( $global ) ? count( $global ) : 0 ) );
	}

	/**
	 * The finding of a report line.
	 *
	 * @param array<string,mixed> $line The line.
	 * @phpstan-param Line $line
	 *
	 * @return Finding
	 */
	private function finding( array $line ): Finding {
		$note    = $line['note'];
		$subject = trim( $note->namespace . '/' . $note->endpoint, '/' );
		$filter  = self::FILTER_OF_SOURCE[ $note->source ] ?? '';

		return new Finding( $line['status'], $note->code, $subject, self::message( $line, $subject, $filter ) );
	}

	/**
	 * The sentence of a report line; the identifiers of the original are never part of a message id.
	 *
	 * @param array<string,mixed> $line    The line.
	 * @phpstan-param Line $line
	 * @param string              $subject The entry, such as `wp/v2/posts`.
	 * @param string              $filter  The legacy filter it came from.
	 *
	 * @return string
	 */
	private static function message( array $line, string $subject, string $filter ): string {
		$note   = $line['note'];
		$routes = implode( ', ', $note->routes );
		$values = implode( ', ', $note->values );

		if ( Finding::KEPT === $line['status'] ) {
			if ( Effective_Rule::SOURCE_CONSTANT === $line['layer'] ) {
				/* translators: 1: an endpoint, such as wp/v2/posts, 2: a filter hook of WP REST Cache, 3: a route pattern, 4: a constant name. */
				return sprintf( __( '%1$s from %2$s does not change %3$s: its rule is defined by %4$s.', 'rockfire-rest-api-cache' ), $subject, $filter, $line['route'], Config::ROUTES_CONSTANT );
			}
			/* translators: 1: an endpoint, such as wp/v2/posts, 2: a filter hook of WP REST Cache, 3: a route pattern. */
			return sprintf( __( '%1$s from %2$s does not change %3$s: its rule is set in this plugin.', 'rockfire-rest-api-cache' ), $subject, $filter, $line['route'] );
		}

		switch ( $note->code ) {
			case Translation_Note::CONCRETE_PATH:
				/* translators: 1: an endpoint, such as wp/v2/posts/5, 2: a filter hook of WP REST Cache, 3: comma separated route patterns. */
				return sprintf( __( '%1$s from %2$s is a concrete path; it applies to the whole route %3$s.', 'rockfire-rest-api-cache' ), $subject, $filter, $routes );
			case Translation_Note::PATTERN:
				/* translators: 1: an endpoint, 2: a filter hook of WP REST Cache. */
				return sprintf( __( '%1$s from %2$s is a pattern; only registered routes can be matched, so it is ignored.', 'rockfire-rest-api-cache' ), $subject, $filter );
			case Translation_Note::NO_ROUTE:
				/* translators: 1: an endpoint, 2: a filter hook of WP REST Cache. */
				return sprintf( __( '%1$s from %2$s matches no registered route, so it is ignored.', 'rockfire-rest-api-cache' ), $subject, $filter );
			case Translation_Note::DENIED:
				/* translators: 1: an endpoint, 2: a filter hook of WP REST Cache, 3: comma separated route patterns. */
				return sprintf( __( '%1$s from %2$s: %3$s are never cached.', 'rockfire-rest-api-cache' ), $subject, $filter, $routes );
			case Translation_Note::INVALID_ENTRY:
				if ( '' === $subject ) {
					/* translators: %s: a filter hook of WP REST Cache. */
					return sprintf( __( '%s returned an entry that is not valid, so it is ignored.', 'rockfire-rest-api-cache' ), $filter );
				}
				/* translators: 1: an endpoint, 2: a filter hook of WP REST Cache. */
				return sprintf( __( '%1$s from %2$s is not a valid entry, so it is ignored.', 'rockfire-rest-api-cache' ), $subject, $filter );
			case Translation_Note::VARY_FORBIDDEN:
				/* translators: 1: an endpoint, 2: a filter hook of WP REST Cache, 3: comma separated header names. */
				return sprintf( __( '%1$s from %2$s: %3$s carry credentials and can never vary the cache.', 'rockfire-rest-api-cache' ), $subject, $filter, $values );
			case Translation_Note::VARY_INVALID:
				/* translators: 1: an endpoint, 2: a filter hook of WP REST Cache, 3: comma separated header names. */
				return sprintf( __( '%1$s from %2$s: %3$s are not valid request header names.', 'rockfire-rest-api-cache' ), $subject, $filter, $values );
			case Translation_Note::VARY_LIMIT:
				/* translators: 1: an endpoint, 2: a filter hook of WP REST Cache, 3: comma separated header names, 4: number of routes, 5: maximum number of headers. */
				return sprintf( __( '%1$s from %2$s: %3$s left out on %4$d routes; a route varies on at most %5$d request headers besides the global ones.', 'rockfire-rest-api-cache' ), $subject, $filter, $values, count( $note->routes ), $line['max_vary'] );
			case Translation_Note::INVALID_RULE:
				/* translators: 1: an endpoint, 2: a filter hook of WP REST Cache, 3: a route pattern, 4: comma separated error codes. */
				return sprintf( __( '%1$s from %2$s: no rule could be built for %3$s (%4$s).', 'rockfire-rest-api-cache' ), $subject, $filter, $routes, $values );
		}

		/* translators: 1: an endpoint, 2: a filter hook of WP REST Cache, 3: a reason code. */
		return sprintf( __( '%1$s from %2$s: %3$s.', 'rockfire-rest-api-cache' ), $subject, $filter, $note->code );
	}

	/**
	 * Whether a legacy endpoint filter has a callback.
	 *
	 * @return bool
	 */
	private static function hooked(): bool {
		return false !== has_filter( self::ALLOWED ) || false !== has_filter( self::DISALLOWED ) || false !== has_filter( self::HEADERS );
	}

	/**
	 * The number of callbacks of each legacy endpoint filter.
	 *
	 * @return array<string,int>
	 */
	private static function callback_counts(): array {
		$counts = [];
		foreach ( [ self::ALLOWED, self::DISALLOWED, self::HEADERS ] as $name ) {
			$counts[ $name ] = self::callback_count( $name );
		}
		return $counts;
	}

	/**
	 * The number of callbacks of a filter.
	 *
	 * @param string $name Filter name.
	 *
	 * @return int
	 */
	private static function callback_count( string $name ): int {
		$filters = $GLOBALS['wp_filter'] ?? [];
		$hook    = is_array( $filters ) ? ( $filters[ $name ] ?? null ) : null;
		if ( ! $hook instanceof \WP_Hook ) {
			return 0;
		}

		$count = 0;
		foreach ( $hook->callbacks as $callbacks ) {
			$count += is_array( $callbacks ) ? count( $callbacks ) : 0;
		}
		return $count;
	}

	/**
	 * The discovered routes among the filter arguments.
	 *
	 * @param array<mixed> $routes Route key => route info.
	 *
	 * @return array<string,Route_Info>
	 */
	private static function route_infos( array $routes ): array {
		$infos = [];
		foreach ( $routes as $key => $info ) {
			if ( $info instanceof Route_Info ) {
				$infos[ (string) $key ] = $info;
			}
		}
		return $infos;
	}
}
