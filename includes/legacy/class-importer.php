<?php
/**
 * The import of the settings of the original plugin.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Config\Route_Discovery;
use Rockfire\Rest_Api_Cache\Config\Route_Info;
use Rockfire\Rest_Api_Cache\Config\Route_Rule;
use Rockfire\Rest_Api_Cache\Config\Settings_Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Imports the options and the effective constants of the original WP REST Cache plugin into the settings and route
 * rules of this site, and reports what became of every value.
 *
 * Rockfire wins: a setting is only replaced while it holds its default and no constant locks it, and a route only
 * gets a rule when it has neither a stored rule nor one in `RFRAC_ROUTES`. Everything that changes is written with
 * one {@see Config::save_settings_and_rules()} call, so the settings save compiles the configuration once; a dry run
 * writes nothing at all. The original's options are only read, never changed. The findings of a real run replace
 * the `import` group of {@see Findings}, unless the save failed: then nothing was imported, the group keeps the
 * report of the last import that was saved, and the returned report carries the validation errors.
 *
 * @since 1.0.0
 */
final class Importer {

	/**
	 * The options of the original, without the `wp_rest_cache_` prefix.
	 *
	 * @var list<string>
	 */
	public const OPTIONS = array(
		'allowed_endpoints',
		'disallowed_endpoints',
		'rest_prefix',
		'cacheable_request_headers',
		'allowed_request_methods',
		'uncached_parameters',
		'hit_recording',
		'timeout',
		'timeout_interval',
		'regenerate',
		'regenerate_interval',
		'regenerate_number',
		'memcache_used',
		'global_cacheable_request_headers',
		'item_allowed_endpoints',
		'database_version',
		'admin_notices',
	);

	/**
	 * Code: the cache lifetime of the original as `default_ttl`.
	 *
	 * @var string
	 */
	public const CODE_DEFAULT_TTL = 'default_ttl';

	/**
	 * Code: `max_ttl` was raised to fit the cache lifetime of the original.
	 *
	 * @var string
	 */
	public const CODE_MAX_TTL_RAISED = 'max_ttl_raised';

	/**
	 * Code: the global cacheable request headers as `global_vary_headers`.
	 *
	 * @var string
	 */
	public const CODE_GLOBAL_VARY_HEADERS = 'global_vary_headers';

	/**
	 * Code: the uncached parameters as `global_ignore_params`, which a route that registers a parameter does not ignore.
	 *
	 * @var string
	 */
	public const CODE_GLOBAL_IGNORE = 'global_ignore_registered';

	/**
	 * Code: a constant controls the setting or the route.
	 *
	 * @var string
	 */
	public const CODE_LOCKED = 'locked';

	/**
	 * Code: the setting or the rule already holds the imported value.
	 *
	 * @var string
	 */
	public const CODE_ALREADY_IMPORTED = 'already_imported';

	/**
	 * Code: the setting holds a value saved in Rockfire.
	 *
	 * @var string
	 */
	public const CODE_STORED_VALUE = 'stored_value';

	/**
	 * Code: the route has a rule saved in this plugin.
	 *
	 * @var string
	 */
	public const CODE_STORED_RULE = 'stored_rule';

	/**
	 * Code: a disallowed route the original did not allow either is not excluded.
	 *
	 * @var string
	 */
	public const CODE_NOT_ALLOWED = 'not_allowed';

	/**
	 * Code: an option does not hold the type of value the original keeps in it.
	 *
	 * @var string
	 */
	public const CODE_INVALID_VALUE = 'invalid_value';

	/**
	 * Code: an uncached parameter is not a valid query parameter name.
	 *
	 * @var string
	 */
	public const CODE_INVALID_PARAM = 'invalid_param';

	/**
	 * Code: the global headers would make a route rule vary on too many request headers.
	 *
	 * @var string
	 */
	public const CODE_TOO_MANY_VARY = 'too_many_vary_headers';

	/**
	 * Code: the request methods the original cached.
	 *
	 * @var string
	 */
	public const CODE_REQUEST_METHODS = 'request_methods';

	/**
	 * Code: request methods the original allowed that this plugin can not cache.
	 *
	 * @var string
	 */
	public const CODE_REQUEST_METHODS_UNSUPPORTED = 'request_methods_unsupported';

	/**
	 * Code: request methods the original allowed that no imported route registers, while another method is cached.
	 *
	 * @var string
	 */
	public const CODE_REQUEST_METHODS_NO_ROUTE = 'request_methods_no_route';

	/**
	 * Code: routes that register an allowed request method but keep without it.
	 *
	 * @var string
	 */
	public const CODE_REQUEST_METHODS_NOT_APPLIED = 'request_methods_not_applied';

	/**
	 * Code: the regeneration of expired caches.
	 *
	 * @var string
	 */
	public const CODE_REGENERATE = 'regenerate';

	/**
	 * Code: the Memcache(d) switch.
	 *
	 * @var string
	 */
	public const CODE_MEMCACHE_USED = 'memcache_used';

	/**
	 * Code: the switch of the hit recording.
	 *
	 * @var string
	 */
	public const CODE_HIT_RECORDING = 'hit_recording';

	/**
	 * Code: requests this plugin does not cache although the original did.
	 *
	 * @var string
	 */
	public const CODE_STRICTER_REQUESTS = 'stricter_requests';

	/**
	 * Code: a hook that keeps working through its bridge.
	 *
	 * @var string
	 */
	public const CODE_BRIDGED = 'bridged';

	/**
	 * Code: a hook of a cache hit, which runs per request only with `RFRAC_LEGACY_HIT_FILTERS`.
	 *
	 * @var string
	 */
	public const CODE_HIT_FILTER = 'hit_filter';

	/**
	 * Code: a hook name the original does not have.
	 *
	 * @var string
	 */
	public const CODE_UNKNOWN_HOOK = 'unknown_hook';

	/**
	 * The subject of the findings about the original as a whole: its plugin slug.
	 *
	 * @var string
	 */
	public const PLUGIN_SUBJECT = 'wp-rest-cache';

	/**
	 * Start of the name of every option of the original.
	 *
	 * @var string
	 */
	private const OPTION_PREFIX = 'wp_rest_cache_';

	/**
	 * Route outcome: the route gets the translated rule.
	 *
	 * @var string
	 */
	private const OUTCOME_WRITE = 'write';

	/**
	 * Route outcome: the route is cached by default and needs no rule.
	 *
	 * @var string
	 */
	private const OUTCOME_DEFAULT = 'default';

	/**
	 * Settings access.
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
	 * Reader of the options and constants of the original.
	 *
	 * @var Original_Settings
	 */
	private Original_Settings $original;

	/**
	 * Returns the REST server whose routes the endpoints are translated against.
	 *
	 * @var \Closure
	 * @phpstan-var \Closure(): \WP_REST_Server
	 */
	private \Closure $server;

	/**
	 * The findings of the current run.
	 *
	 * @var list<Finding>
	 */
	private array $report = array();

	/**
	 * The settings the current run changes.
	 *
	 * @var array<string,mixed>
	 */
	private array $setting_changes = array();

	/**
	 * The route rules the current run adds, by route key.
	 *
	 * @var array<string,mixed>
	 */
	private array $rule_changes = array();

	/**
	 * The stored settings when the run started.
	 *
	 * @var array<string,mixed>
	 */
	private array $raw = array();

	/**
	 * The effective settings when the run started.
	 *
	 * @var array<string,mixed>
	 */
	private array $effective = array();

	/**
	 * The settings a constant controls, with what controls them.
	 *
	 * @var array<string,string>
	 */
	private array $locked = array();

	/**
	 * The valid stored route rules.
	 *
	 * @var array<string,Route_Rule>
	 */
	private array $stored_rules = array();

	/**
	 * The stored route rules that are not valid.
	 *
	 * @var array<string,string>
	 */
	private array $invalid_rules = array();

	/**
	 * The outcome of every route decided in the current run.
	 *
	 * @var array<string,string>
	 */
	private array $outcomes = array();

	/**
	 * The request methods besides GET that a route caches after the current run, by route key: the methods its new rule
	 * names and those the rule it keeps already names.
	 *
	 * @var array<string,list<string>>
	 */
	private array $method_routes = array();

	/**
	 * The request methods besides GET that a route registers but keeps without, by route key: a core route, a route
	 * cached by default, a route with a stored rule or one defined in `RFRAC_ROUTES`.
	 *
	 * @var array<string,list<string>>
	 */
	private array $method_kept = array();

	/**
	 * How many request headers a translated rule may vary on besides the global ones.
	 *
	 * @var int
	 */
	private int $max_vary = Route_Rule::MAX_VARY_HEADERS;

	/**
	 * Create the importer.
	 *
	 * @param Config            $config   Settings access.
	 * @param Findings          $findings Store of the findings.
	 * @param Original_Settings $original Reader of the options and constants of the original.
	 * @param \Closure|null     $server   Returns the REST server to read the routes from; default {@see Route_Discovery::server()}.
	 * @phpstan-param (\Closure(): \WP_REST_Server)|null $server
	 */
	public function __construct( Config $config, Findings $findings, Original_Settings $original, ?\Closure $server = null ) {
		$this->config   = $config;
		$this->findings = $findings;
		$this->original = $original;
		$this->server   = $server ?? Route_Discovery::server( ... );
	}

	/**
	 * Import the settings of the original.
	 *
	 * Without any option or constant of the original nothing is read further and nothing is written. Otherwise
	 * every value is translated and reported; a real run then saves the changed settings and route rules with one
	 * {@see Config::save_settings_and_rules()} call and replaces the `import` group of the findings. When the save
	 * fails the group is left as it was: the report has the result `failed` and the validation errors, nothing else
	 * is stored.
	 *
	 * @param bool $dry_run Whether to only report what an import would do, without writing anything.
	 *
	 * @return Import_Report
	 */
	public function run( bool $dry_run = false ): Import_Report {
		if ( ! $this->found() ) {
			return new Import_Report( array(), Import_Report::RESULT_NOTHING_FOUND, null, $dry_run );
		}

		$this->begin();
		$this->import_ttl();
		$global_vary = $this->import_global_headers();
		$this->import_uncached_parameters();
		$methods = $this->read_request_methods();
		$this->import_endpoints( max( 0, Route_Rule::MAX_VARY_HEADERS - $global_vary ), $methods ?? array() );
		$this->report_request_methods( $methods );
		$this->report_removed_features();
		$this->report_stricter_requests();
		$this->report_hooks();

		$findings = $this->report;
		if ( $dry_run ) {
			return new Import_Report( $findings, Import_Report::RESULT_DRY_RUN, null, true );
		}

		$changed    = array() !== $this->setting_changes || array() !== $this->rule_changes;
		$validation = $this->config->save_settings_and_rules( $this->setting_changes, $this->rule_changes );
		if ( ! $validation->is_valid() ) {
			$result = Import_Report::RESULT_FAILED;
		} elseif ( ! $changed ) {
			$result = Import_Report::RESULT_UNCHANGED;
		} else {
			$result = self::compiled() ? Import_Report::RESULT_COMPILED : Import_Report::RESULT_DEFERRED;
		}

		$report = new Import_Report( $findings, $result, $validation, false );
		// A failed save wrote nothing, so the stored report keeps describing the last import that did: the findings of
		// this run would list values as imported that were never written. With no earlier report the group stays
		// absent, so the notice still offers the import and the Import page still previews it.
		if ( Import_Report::RESULT_FAILED !== $result ) {
			$this->findings->replace( Findings::GROUP_IMPORT, $findings, $report->counts + array( 'result' => $result ) );
		}
		return $report;
	}

	/**
	 * Whether the site has any option or constant of the original.
	 *
	 * @return bool
	 */
	private function found(): bool {
		if ( array() !== $this->original::defined_constants() ) {
			return true;
		}
		foreach ( self::OPTIONS as $suffix ) {
			if ( $this->original::has_option( $suffix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reset the state of a run and read the current settings once.
	 *
	 * @return void
	 */
	private function begin(): void {
		$this->report          = array();
		$this->setting_changes = array();
		$this->rule_changes    = array();
		$this->outcomes        = array();
		$this->method_routes   = array();
		$this->method_kept     = array();
		$this->max_vary        = Route_Rule::MAX_VARY_HEADERS;
		$this->raw             = $this->config->raw_settings();
		$this->effective       = $this->config->settings();
		$this->locked          = $this->config->locked();
		$this->stored_rules    = $this->config->stored_route_rules();
		$this->invalid_rules   = $this->config->invalid_stored_route_rules();
	}

	// ---------------------------------------------------------------------------------------
	// Settings.
	// ---------------------------------------------------------------------------------------

	/**
	 * Import the cache lifetime of the original as `default_ttl`, raising `max_ttl` when it is longer.
	 *
	 * The lifetime is clamped into the range of the setting. When it exceeds the effective `max_ttl`, `max_ttl` is
	 * raised while it is unlocked and at its default; otherwise the lifetime is clamped to `max_ttl`. A `default_ttl`
	 * locked by the original's own constants still raises `max_ttl`, so their lifetime applies in full.
	 *
	 * @return void
	 */
	private function import_ttl(): void {
		$ttl = $this->original::ttl();
		if ( null === $ttl ) {
			return;
		}

		$subject = $ttl['label'];
		$seconds = $ttl['seconds'];
		if ( null === $seconds ) {
			$this->add(
				Finding::SKIPPED,
				self::CODE_DEFAULT_TTL,
				$subject,
				/* translators: %s: the option or constant of the original plugin that holds the cache lifetime. */
				sprintf( __( '%s is not a valid cache lifetime, so it was not imported.', 'rockfire-rest-api-cache' ), $subject )
			);
			return;
		}

		$target    = max( Settings_Schema::MIN_TTL, min( Settings_Schema::ABSOLUTE_MAX_TTL, $seconds ) );
		$max_ttl   = self::int_of( $this->effective, 'max_ttl' );
		$can_raise = ! isset( $this->locked['max_ttl'] ) && self::int_of( $this->raw, 'max_ttl' ) === self::int_of( Settings_Schema::defaults(), 'max_ttl' );
		$raise     = $target > $max_ttl && $can_raise;
		if ( $target > $max_ttl && ! $can_raise ) {
			$target = $max_ttl;
		}

		$kept = $this->kept_reason( 'default_ttl', $target );
		if ( null !== $kept ) {
			$this->add_kept( $kept, $subject, 'default_ttl' );
			if ( self::CODE_LOCKED === $kept && $raise && $ttl['from_constant'] && ( $this->locked['default_ttl'] ?? '' ) === $ttl['label'] ) {
				$this->raise_max_ttl( $subject, $target );
			}
			return;
		}

		if ( $raise ) {
			$this->raise_max_ttl( $subject, $target );
		}
		if ( self::int_of( $this->raw, 'default_ttl' ) !== $target ) {
			$this->setting_changes['default_ttl'] = $target;
		}

		if ( $target === $seconds ) {
			$this->add(
				Finding::IMPORTED,
				self::CODE_DEFAULT_TTL,
				$subject,
				/* translators: 1: the option or constant of the original plugin, 2: a settings key, 3: number of seconds. */
				sprintf( __( '%1$s imported as %2$s: %3$d seconds.', 'rockfire-rest-api-cache' ), $subject, 'default_ttl', $target )
			);
			return;
		}
		$this->add(
			Finding::ADJUSTED,
			self::CODE_DEFAULT_TTL,
			$subject,
			/* translators: 1: the option or constant of the original plugin, 2: its number of seconds, 3: a settings key, 4: the imported number of seconds. */
			sprintf( __( '%1$s is %2$d seconds; %3$s is set to %4$d seconds, the closest value allowed.', 'rockfire-rest-api-cache' ), $subject, $seconds, 'default_ttl', $target )
		);
	}

	/**
	 * Raise `max_ttl` to the imported cache lifetime.
	 *
	 * @param string $subject The identifier of the lifetime of the original.
	 * @param int    $max_ttl The new maximum lifetime.
	 *
	 * @return void
	 */
	private function raise_max_ttl( string $subject, int $max_ttl ): void {
		$this->setting_changes['max_ttl'] = $max_ttl;
		$this->add(
			Finding::ADJUSTED,
			self::CODE_MAX_TTL_RAISED,
			$subject,
			/* translators: 1: the option or constant of the original plugin, 2: a settings key, 3: number of seconds. */
			sprintf( __( '%1$s is longer than the maximum cache lifetime, so %2$s is raised to %3$d seconds.', 'rockfire-rest-api-cache' ), $subject, 'max_ttl', $max_ttl )
		);
	}

	/**
	 * Import the global cacheable request headers of the original as `global_vary_headers`.
	 *
	 * Names are normalised; a forbidden or invalid name is skipped on its own, and at most
	 * {@see Route_Rule::MAX_VARY_HEADERS} names are kept. Headers that would make a stored or `RFRAC_ROUTES` rule
	 * vary on too many request headers are not imported.
	 *
	 * @return int How many global vary headers apply after the import.
	 */
	private function import_global_headers(): int {
		$current = count( self::strings( $this->effective['global_vary_headers'] ?? array() ) );
		$global  = $this->original::global_headers();
		if ( null === $global || array() === $global['headers'] ) {
			return $current;
		}

		$subject = $global['label'];
		$valid   = array();
		$left    = false;
		foreach ( $global['headers'] as $raw ) {
			$name = Config_Compiler::normalize_header_name( $raw );
			if ( null === $name ) {
				$left = true;
				$this->add(
					Finding::SKIPPED,
					Translation_Note::VARY_INVALID,
					$subject,
					/* translators: 1: the option or constant of the original plugin, 2: a header name. */
					sprintf( __( '%1$s: %2$s is not a valid request header name.', 'rockfire-rest-api-cache' ), $subject, $raw )
				);
			} elseif ( in_array( $name, Route_Rule::FORBIDDEN_VARY_HEADERS, true ) ) {
				$left = true;
				$this->add(
					Finding::SKIPPED,
					Translation_Note::VARY_FORBIDDEN,
					$subject,
					/* translators: 1: the option or constant of the original plugin, 2: a header name. */
					sprintf( __( '%1$s: %2$s carries credentials and can never vary the cache.', 'rockfire-rest-api-cache' ), $subject, $name )
				);
			} elseif ( ! in_array( $name, $valid, true ) ) {
				$valid[] = $name;
			}
		}
		if ( array() === $valid ) {
			return $current;
		}

		$list    = array_slice( $valid, 0, Route_Rule::MAX_VARY_HEADERS );
		$dropped = array_slice( $valid, Route_Rule::MAX_VARY_HEADERS );
		$kept    = $this->kept_reason( 'global_vary_headers', $list );
		if ( null !== $kept ) {
			$this->add_kept( $kept, $subject, 'global_vary_headers' );
			return $current;
		}

		$misfits = $this->config->vary_fit( $list )->errors();
		if ( array() !== $misfits ) {
			$this->add(
				Finding::SKIPPED,
				self::CODE_TOO_MANY_VARY,
				$subject,
				/* translators: 1: the option or constant of the original plugin, 2: the reason. */
				sprintf( __( '%1$s was not imported: %2$s', 'rockfire-rest-api-cache' ), $subject, $misfits[0]['message'] )
			);
			return $current;
		}

		$this->setting_changes['global_vary_headers'] = $list;

		/* translators: 1: the option or constant of the original plugin, 2: a settings key, 3: comma separated header names. */
		$message = sprintf( __( '%1$s imported as %2$s: %3$s.', 'rockfire-rest-api-cache' ), $subject, 'global_vary_headers', implode( ', ', $list ) );
		if ( array() !== $dropped ) {
			/* translators: 1: maximum number of headers, 2: comma separated header names. */
			$message .= ' ' . sprintf( __( 'At most %1$d headers vary every route, so %2$s were left out.', 'rockfire-rest-api-cache' ), Route_Rule::MAX_VARY_HEADERS, implode( ', ', $dropped ) );
		}
		$this->add( $left || array() !== $dropped ? Finding::ADJUSTED : Finding::IMPORTED, self::CODE_GLOBAL_VARY_HEADERS, $subject, $message );
		return count( $list );
	}

	/**
	 * Import the uncached parameters of the original as `global_ignore_params`.
	 *
	 * Names that are not valid query parameter names are skipped on their own and at most
	 * {@see Route_Rule::MAX_PARAM_NAMES} are kept. The import is always reported as adjusted: unlike the original,
	 * a route that registers a parameter keeps it in its cache key.
	 *
	 * @return void
	 */
	private function import_uncached_parameters(): void {
		$suffix = 'uncached_parameters';
		if ( ! $this->original::has_option( $suffix ) ) {
			return;
		}

		$subject = self::OPTION_PREFIX . $suffix;
		$value   = $this->original::option( $suffix );
		if ( ! is_array( $value ) ) {
			$this->report_not_a_list( $subject, $value );
			return;
		}

		$valid = array();
		foreach ( $value as $entry ) {
			$name = is_string( $entry ) ? trim( $entry ) : null;
			if ( '' === $name ) {
				continue;
			}
			if ( null === $name || 1 !== preg_match( Route_Rule::PARAM_NAME_PATTERN, $name ) ) {
				$this->add(
					Finding::SKIPPED,
					self::CODE_INVALID_PARAM,
					$subject,
					/* translators: 1: the option of the original plugin, 2: a query parameter name. */
					sprintf( __( '%1$s: %2$s is not a valid query parameter name.', 'rockfire-rest-api-cache' ), $subject, self::display( $entry ) )
				);
				continue;
			}
			if ( ! in_array( $name, $valid, true ) ) {
				$valid[] = $name;
			}
		}
		if ( array() === $valid ) {
			return;
		}

		$list    = array_slice( $valid, 0, Route_Rule::MAX_PARAM_NAMES );
		$dropped = array_slice( $valid, Route_Rule::MAX_PARAM_NAMES );
		$kept    = $this->kept_reason( 'global_ignore_params', $list );
		if ( null !== $kept ) {
			$this->add_kept( $kept, $subject, 'global_ignore_params' );
			return;
		}

		$this->setting_changes['global_ignore_params'] = $list;

		/* translators: 1: the option of the original plugin, 2: a settings key, 3: comma separated query parameter names. */
		$message = sprintf( __( '%1$s imported as %2$s: %3$s. A parameter is ignored only on routes that do not register it.', 'rockfire-rest-api-cache' ), $subject, 'global_ignore_params', implode( ', ', $list ) );
		if ( array() !== $dropped ) {
			/* translators: 1: maximum number of parameters, 2: comma separated query parameter names. */
			$message .= ' ' . sprintf( __( 'At most %1$d parameters are kept, so %2$s were left out.', 'rockfire-rest-api-cache' ), Route_Rule::MAX_PARAM_NAMES, implode( ', ', $dropped ) );
		}
		$this->add( Finding::ADJUSTED, self::CODE_GLOBAL_IGNORE, $subject, $message );
	}

	/**
	 * Why a setting keeps its value instead of the imported one.
	 *
	 * A setting is kept when a constant locks it, or when its stored value differs from the default: as
	 * `already_imported` when that value is the imported one. A setting at its default is replaced.
	 *
	 * @param string $key      Settings key.
	 * @param mixed  $imported The value the import would write.
	 *
	 * @return string|null The code of the reason; null when the setting is replaced.
	 */
	private function kept_reason( string $key, mixed $imported ): ?string {
		if ( isset( $this->locked[ $key ] ) ) {
			return self::CODE_LOCKED;
		}
		$stored = $this->raw[ $key ] ?? null;
		if ( ( Settings_Schema::defaults()[ $key ] ?? null ) === $stored ) {
			return null;
		}
		return $stored === $imported ? self::CODE_ALREADY_IMPORTED : self::CODE_STORED_VALUE;
	}

	/**
	 * Report a setting that keeps its value.
	 *
	 * @param string $code    One of `locked`, `already_imported` and `stored_value`.
	 * @param string $subject The identifier of the value of the original.
	 * @param string $key     Settings key.
	 *
	 * @return void
	 */
	private function add_kept( string $code, string $subject, string $key ): void {
		if ( self::CODE_LOCKED === $code ) {
			/* translators: 1: the option or constant of the original plugin, 2: a settings key, 3: the constant that defines it. */
			$message = sprintf( __( '%1$s is not imported: %2$s is defined by %3$s.', 'rockfire-rest-api-cache' ), $subject, $key, $this->locked[ $key ] ?? '' );
		} elseif ( self::CODE_ALREADY_IMPORTED === $code ) {
			/* translators: 1: the option or constant of the original plugin, 2: a settings key. */
			$message = sprintf( __( '%1$s is already imported into %2$s.', 'rockfire-rest-api-cache' ), $subject, $key );
		} else {
			/* translators: 1: the option or constant of the original plugin, 2: a settings key. */
			$message = sprintf( __( '%1$s is not imported: %2$s keeps the value saved in this plugin.', 'rockfire-rest-api-cache' ), $subject, $key );
		}
		$this->add( Finding::KEPT, $code, $subject, $message );
	}

	/**
	 * Report an option that does not hold a list; an empty value is not reported.
	 *
	 * @param string $subject The option of the original.
	 * @param mixed  $value   Its value.
	 *
	 * @return void
	 */
	private function report_not_a_list( string $subject, mixed $value ): void {
		if ( null === $value || false === $value || '' === $value ) {
			return;
		}
		$this->add(
			Finding::SKIPPED,
			self::CODE_INVALID_VALUE,
			$subject,
			/* translators: %s: the option of the original plugin. */
			sprintf( __( '%s is not a list, so it was not imported.', 'rockfire-rest-api-cache' ), $subject )
		);
	}

	// ---------------------------------------------------------------------------------------
	// Endpoints.
	// ---------------------------------------------------------------------------------------

	/**
	 * Translate the allowed and disallowed endpoints and the cacheable request headers into route rules.
	 *
	 * Every entry is reported once with its routes, and once more per note on how it was read. A route keeps its
	 * rule when `RFRAC_ROUTES` defines it or it has a stored rule; a route cached by default needs no rule; a route
	 * the original did not allow is never excluded. The request methods of the original are given to the translator,
	 * which caches them on the allowed routes that register them; what became of each such route is recorded for
	 * {@see self::report_request_methods()}.
	 *
	 * @param int      $max_vary How many request headers a rule may vary on besides the global ones.
	 * @param string[] $methods  The request methods the original cached, uppercase.
	 * @phpstan-param list<string> $methods
	 *
	 * @return void
	 */
	private function import_endpoints( int $max_vary, array $methods ): void {
		$this->max_vary = $max_vary;

		$inputs = array();
		foreach ( array( 'allowed_endpoints', 'disallowed_endpoints', 'cacheable_request_headers' ) as $suffix ) {
			$value = $this->original::option( $suffix, array() );
			if ( ! is_array( $value ) ) {
				$this->report_not_a_list( self::OPTION_PREFIX . $suffix, $value );
				$value = array();
			}
			$inputs[ $suffix ] = $value;
		}
		if ( array() === $inputs['allowed_endpoints'] && array() === $inputs['disallowed_endpoints'] && array() === $inputs['cacheable_request_headers'] ) {
			return;
		}

		$infos       = ( new Route_Discovery() )->discover( ( $this->server )() );
		$translator  = new Endpoint_Translator( $infos );
		$translation = $translator->forward( $inputs['allowed_endpoints'], $inputs['disallowed_endpoints'], $inputs['cacheable_request_headers'], $max_vary, $methods );
		$allowed     = self::allowed_routes( $translator, $inputs['allowed_endpoints'] );

		$entries = array();
		$routes  = array();
		$headers = array();
		$others  = array();
		foreach ( $translation->notes as $note ) {
			$key             = $note->source . "\0" . $note->namespace . "\0" . $note->endpoint;
			$entries[ $key ] = $entries[ $key ] ?? $note;
			if ( in_array( $note->code, array( Translation_Note::ENABLED, Translation_Note::ALREADY_DEFAULT, Translation_Note::EXCLUDED, Translation_Note::VARY ), true ) ) {
				$routes[ $key ][ $note->code ] = array_merge( $routes[ $key ][ $note->code ] ?? array(), $note->routes );
				if ( Translation_Note::VARY === $note->code ) {
					$headers[ $key ] = array_values( array_unique( array_merge( $headers[ $key ] ?? array(), $note->values ) ) );
				}
			} else {
				$others[ $key ][] = $note;
			}
		}

		foreach ( $entries as $key => $entry ) {
			$subject = self::entry_subject( $entry );
			$label   = self::entry_label( $entry );
			$decided = $routes[ $key ] ?? array();
			if ( Translation_Note::SOURCE_ALLOWED === $entry->source ) {
				$this->report_allowed( $subject, $label, $decided[ Translation_Note::ENABLED ] ?? array(), $decided[ Translation_Note::ALREADY_DEFAULT ] ?? array(), $translation->rules );
			} elseif ( Translation_Note::SOURCE_DISALLOWED === $entry->source ) {
				$this->report_disallowed( $subject, $label, $decided[ Translation_Note::EXCLUDED ] ?? array(), $allowed, $translation->rules );
			} else {
				$this->report_vary( $subject, $label, $decided[ Translation_Note::VARY ] ?? array(), $headers[ $key ] ?? array(), $translation->rules );
			}
			foreach ( $others[ $key ] ?? array() as $note ) {
				$this->report_note( $subject, $label, $note );
			}
		}

		$this->record_methods( $infos, $allowed, $translation->rules, self::extra_methods( $methods ) );
	}

	/**
	 * Record, for every allowed route that registers a request method besides GET, whether the route caches it.
	 *
	 * The route caches the methods when it gets, or already holds, the translated rule that names them. It keeps
	 * without them when it is a core route, is cached by default, has a stored rule or is defined in `RFRAC_ROUTES`:
	 * the rule it keeps decides, so a kept rule that caches a method counts as caching it and only the methods it does
	 * not cache are recorded as kept. An excluded route and a route without a rule are not recorded: the methods do
	 * not matter for them.
	 *
	 * @param array<string,Route_Info>          $infos   The discovered routes.
	 * @param array<string,true>                $allowed Routes an allowed entry matches.
	 * @param array<string,array<string,mixed>> $rules   The translated rules, by route key.
	 * @param string[]                          $extra   The request methods besides GET to cache.
	 * @phpstan-param list<string> $extra
	 *
	 * @return void
	 */
	private function record_methods( array $infos, array $allowed, array $rules, array $extra ): void {
		if ( array() === $extra ) {
			return;
		}

		foreach ( array_keys( $allowed ) as $route ) {
			$route = (string) $route;
			$info  = $infos[ $route ] ?? null;
			if ( null === $info || $info->denied ) {
				continue;
			}

			$taken = array_values( array_filter( $extra, static fn( string $method ): bool => $info->registers( $method ) ) );
			$rule  = $rules[ $route ] ?? null;
			if ( array() === $taken || ( null !== $rule && Route_Rule::STATUS_EXCLUDED === ( $rule['status'] ?? null ) ) ) {
				continue;
			}

			$outcome = $this->outcomes[ $route ] ?? null;
			$cached  = array_values( array_intersect( $taken, null === $rule ? array() : self::strings( $rule['methods'] ?? null ) ) );
			if ( array() !== $cached && in_array( $outcome, array( self::OUTCOME_WRITE, self::CODE_ALREADY_IMPORTED ), true ) ) {
				$this->method_routes[ $route ] = $cached;
			} elseif ( self::is_core_route( $route, $info ) || in_array( $outcome, array( self::OUTCOME_DEFAULT, self::CODE_LOCKED, self::CODE_STORED_RULE ), true ) ) {
				$held = $this->methods_in_force( $route, $info, $outcome );
				$kept = array_values( array_diff( $taken, $held ) );
				$held = array_values( array_intersect( $taken, $held ) );
				if ( array() !== $held ) {
					$this->method_routes[ $route ] = $held;
				}
				if ( array() !== $kept ) {
					$this->method_kept[ $route ] = $kept;
				}
			}
		}
	}

	/**
	 * The request methods besides GET that the rule in force on a route caches.
	 *
	 * The rule in force is the one the route keeps: the rule defined in `RFRAC_ROUTES`, the stored rule or the built-in
	 * default. A core route is only ever cached for GET, whatever its rule says, and an excluded rule, a stored rule
	 * that is not valid or a rule that follows the default of a route that is not cached by default caches nothing.
	 *
	 * @param string      $route   Route key.
	 * @param Route_Info  $info    The discovered route.
	 * @param string|null $outcome The outcome of the route in this run.
	 *
	 * @return list<string> In {@see Route_Rule::METHODS} order.
	 */
	private function methods_in_force( string $route, Route_Info $info, ?string $outcome ): array {
		if ( self::is_core_route( $route, $info ) ) {
			return array();
		}

		$rule = null;
		if ( self::CODE_LOCKED === $outcome ) {
			$rule = $this->config->constant_route_rules()[ $route ] ?? null;
		} elseif ( in_array( $outcome, array( self::CODE_STORED_RULE, self::CODE_ALREADY_IMPORTED ), true ) ) {
			$rule = $this->stored_rules[ $route ] ?? null;
		}
		if ( null === $rule || Route_Rule::STATUS_EXCLUDED === $rule->status || ( Route_Rule::STATUS_DEFAULT === $rule->status && ! $info->is_default ) ) {
			return array();
		}
		return array_values( array_diff( $rule->methods, Route_Rule::DEFAULT_METHODS ) );
	}

	/**
	 * Whether a route is a core `wp/v2` route, which is only ever cached for GET.
	 *
	 * @param string     $route Route key.
	 * @param Route_Info $info  The discovered route.
	 *
	 * @return bool
	 */
	private static function is_core_route( string $route, Route_Info $info ): bool {
		return 'wp/v2' === $info->namespace || str_starts_with( $route, '/wp/v2' );
	}

	/**
	 * Report an allowed entry: the routes that get a rule and the routes cached by default.
	 *
	 * @param string                            $subject The identifier of the entry.
	 * @param string                            $label   The entry for a message.
	 * @param string[]                          $enabled Routes the entry enables with a rule.
	 * @phpstan-param list<string> $enabled
	 * @param string[]                          $cached  Routes the entry allows that are cached by default.
	 * @phpstan-param list<string> $cached
	 * @param array<string,array<string,mixed>> $rules   The translated rules, by route key.
	 *
	 * @return void
	 */
	private function report_allowed( string $subject, string $label, array $enabled, array $cached, array $rules ): void {
		$set = array();
		foreach ( $enabled as $route ) {
			if ( isset( $rules[ $route ] ) ) {
				$set[ $route ] = $rules[ $route ];
			}
		}
		foreach ( $cached as $route ) {
			$set[ $route ] = null;
		}

		$groups = $this->outcomes_of( $set );
		if ( $groups[ self::OUTCOME_WRITE ] + $groups[ self::OUTCOME_DEFAULT ] > 0 ) {
			$this->add(
				Finding::IMPORTED,
				$groups[ self::OUTCOME_WRITE ] > 0 ? Translation_Note::ENABLED : Translation_Note::ALREADY_DEFAULT,
				$subject,
				/* translators: 1: an endpoint of the original plugin, such as "posts in wp/v2", 2: number of routes that are now cached, 3: number of routes that were cached by default already. */
				sprintf( __( '%1$s: %2$d routes cached, %3$d already cached by default.', 'rockfire-rest-api-cache' ), $label, $groups[ self::OUTCOME_WRITE ], $groups[ self::OUTCOME_DEFAULT ] )
			);
		}
		$this->report_kept_routes( $subject, $label, $groups );
	}

	/**
	 * Report a disallowed entry: the routes that get an excluded rule.
	 *
	 * A route the original did not allow was not cached by it, so the import does not exclude it.
	 *
	 * @param string                            $subject  The identifier of the entry.
	 * @param string                            $label    The entry for a message.
	 * @param string[]                          $excluded Routes the entry excludes with a rule.
	 * @phpstan-param list<string> $excluded
	 * @param array<string,true>                $allowed  Routes an allowed entry matches.
	 * @param array<string,array<string,mixed>> $rules    The translated rules, by route key.
	 *
	 * @return void
	 */
	private function report_disallowed( string $subject, string $label, array $excluded, array $allowed, array $rules ): void {
		$set         = array();
		$not_allowed = 0;
		foreach ( $excluded as $route ) {
			if ( ! isset( $rules[ $route ] ) ) {
				continue;
			}
			if ( ! isset( $allowed[ $route ] ) ) {
				++$not_allowed;
				continue;
			}
			$set[ $route ] = $rules[ $route ];
		}

		$groups = $this->outcomes_of( $set );
		if ( $groups[ self::OUTCOME_WRITE ] > 0 ) {
			$this->add(
				Finding::IMPORTED,
				Translation_Note::EXCLUDED,
				$subject,
				sprintf(
					/* translators: 1: an endpoint of the original plugin, such as "posts in wp/v2", 2: number of routes. */
					_n( '%1$s: %2$d route excluded from caching.', '%1$s: %2$d routes excluded from caching.', $groups[ self::OUTCOME_WRITE ], 'rockfire-rest-api-cache' ),
					$label,
					$groups[ self::OUTCOME_WRITE ]
				)
			);
		}
		if ( $not_allowed > 0 ) {
			$this->add(
				Finding::SKIPPED,
				self::CODE_NOT_ALLOWED,
				$subject,
				sprintf(
					/* translators: 1: an endpoint of the original plugin, such as "posts in wp/v2", 2: number of routes. */
					_n( '%1$s: %2$d route is not excluded, because WP REST Cache did not allow it either.', '%1$s: %2$d routes are not excluded, because WP REST Cache did not allow them either.', $not_allowed, 'rockfire-rest-api-cache' ),
					$label,
					$not_allowed
				)
			);
		}
		$this->report_kept_routes( $subject, $label, $groups );
	}

	/**
	 * Report a cacheable request headers entry: the routes whose rule varies on its headers.
	 *
	 * @param string                            $subject The identifier of the entry.
	 * @param string                            $label   The entry for a message.
	 * @param string[]                          $varied  Routes the headers were added to.
	 * @phpstan-param list<string> $varied
	 * @param string[]                          $names   The header names that were added.
	 * @phpstan-param list<string> $names
	 * @param array<string,array<string,mixed>> $rules   The translated rules, by route key.
	 *
	 * @return void
	 */
	private function report_vary( string $subject, string $label, array $varied, array $names, array $rules ): void {
		$set = array();
		foreach ( $varied as $route ) {
			if ( isset( $rules[ $route ] ) ) {
				$set[ $route ] = $rules[ $route ];
			}
		}

		$groups = $this->outcomes_of( $set );
		if ( $groups[ self::OUTCOME_WRITE ] > 0 ) {
			$this->add(
				Finding::IMPORTED,
				Translation_Note::VARY,
				$subject,
				sprintf(
					/* translators: 1: an endpoint of the original plugin, such as "posts in wp/v2", 2: number of routes, 3: comma separated header names. */
					_n( '%1$s: %2$d route varies by %3$s.', '%1$s: %2$d routes vary by %3$s.', $groups[ self::OUTCOME_WRITE ], 'rockfire-rest-api-cache' ),
					$label,
					$groups[ self::OUTCOME_WRITE ],
					implode( ', ', $names )
				)
			);
		}
		$this->report_kept_routes( $subject, $label, $groups );
	}

	/**
	 * Report the routes of an entry that keep their rule, one finding per reason.
	 *
	 * @param string            $subject The identifier of the entry.
	 * @param string            $label   The entry for a message.
	 * @param array<string,int> $groups  The number of routes per outcome.
	 *
	 * @return void
	 */
	private function report_kept_routes( string $subject, string $label, array $groups ): void {
		$locked = $groups[ self::CODE_LOCKED ] ?? 0;
		if ( $locked > 0 ) {
			$this->add(
				Finding::KEPT,
				self::CODE_LOCKED,
				$subject,
				sprintf(
					/* translators: 1: an endpoint of the original plugin, such as "posts in wp/v2", 2: number of routes, 3: a constant name. */
					_n( '%1$s: %2$d route keeps the rule defined by %3$s.', '%1$s: %2$d routes keep the rule defined by %3$s.', $locked, 'rockfire-rest-api-cache' ),
					$label,
					$locked,
					Config::ROUTES_CONSTANT
				)
			);
		}
		$stored = $groups[ self::CODE_STORED_RULE ] ?? 0;
		if ( $stored > 0 ) {
			$this->add(
				Finding::KEPT,
				self::CODE_STORED_RULE,
				$subject,
				sprintf(
					/* translators: 1: an endpoint of the original plugin, such as "posts in wp/v2", 2: number of routes. */
					_n( '%1$s: %2$d route keeps the rule saved in this plugin.', '%1$s: %2$d routes keep the rule saved in this plugin.', $stored, 'rockfire-rest-api-cache' ),
					$label,
					$stored
				)
			);
		}
		$imported = $groups[ self::CODE_ALREADY_IMPORTED ] ?? 0;
		if ( $imported > 0 ) {
			$this->add(
				Finding::KEPT,
				self::CODE_ALREADY_IMPORTED,
				$subject,
				sprintf(
					/* translators: 1: an endpoint of the original plugin, such as "posts in wp/v2", 2: number of routes. */
					_n( '%1$s: %2$d route is already imported.', '%1$s: %2$d routes are already imported.', $imported, 'rockfire-rest-api-cache' ),
					$label,
					$imported
				)
			);
		}
	}

	/**
	 * Report a note on how an entry was read.
	 *
	 * @param string           $subject The identifier of the entry.
	 * @param string           $label   The entry for a message.
	 * @param Translation_Note $note    The note.
	 *
	 * @return void
	 */
	private function report_note( string $subject, string $label, Translation_Note $note ): void {
		$label  = '' === $label ? $subject : $label;
		$routes = implode( ', ', $note->routes );
		$values = implode( ', ', $note->values );

		switch ( $note->code ) {
			case Translation_Note::CONCRETE_PATH:
				/* translators: 1: an endpoint of the original plugin, 2: comma separated route patterns. */
				$this->add( Finding::ADJUSTED, $note->code, $subject, sprintf( __( '%1$s is a concrete path; it is imported for the whole route %2$s.', 'rockfire-rest-api-cache' ), $label, $routes ) );
				break;
			case Translation_Note::PATTERN:
				/* translators: %s: an endpoint of the original plugin. */
				$this->add( Finding::SKIPPED, $note->code, $subject, sprintf( __( '%s is a pattern; only registered routes can be imported.', 'rockfire-rest-api-cache' ), $label ) );
				break;
			case Translation_Note::NO_ROUTE:
				/* translators: %s: an endpoint of the original plugin. */
				$this->add( Finding::SKIPPED, $note->code, $subject, sprintf( __( '%s matches no registered route.', 'rockfire-rest-api-cache' ), $label ) );
				break;
			case Translation_Note::DENIED:
				/* translators: 1: an endpoint of the original plugin, 2: comma separated route patterns. */
				$this->add( Finding::SKIPPED, $note->code, $subject, sprintf( __( '%1$s: %2$s are never cached.', 'rockfire-rest-api-cache' ), $label, $routes ) );
				break;
			case Translation_Note::INVALID_ENTRY:
				/* translators: %s: an entry of an option of the original plugin. */
				$this->add( Finding::SKIPPED, $note->code, $subject, sprintf( __( '%s is not a valid entry.', 'rockfire-rest-api-cache' ), $subject ) );
				break;
			case Translation_Note::VARY_FORBIDDEN:
				/* translators: 1: an endpoint of the original plugin, 2: comma separated header names. */
				$this->add( Finding::SKIPPED, $note->code, $subject, sprintf( __( '%1$s: %2$s carry credentials and can never vary the cache.', 'rockfire-rest-api-cache' ), $label, $values ) );
				break;
			case Translation_Note::VARY_INVALID:
				/* translators: 1: an endpoint of the original plugin, 2: comma separated header names. */
				$this->add( Finding::SKIPPED, $note->code, $subject, sprintf( __( '%1$s: %2$s are not valid request header names.', 'rockfire-rest-api-cache' ), $label, $values ) );
				break;
			case Translation_Note::VARY_LIMIT:
				/* translators: 1: an endpoint of the original plugin, 2: comma separated header names, 3: number of routes, 4: maximum number of headers. */
				$this->add( Finding::ADJUSTED, $note->code, $subject, sprintf( __( '%1$s: %2$s left out on %3$d routes; a route varies on at most %4$d request headers besides the global ones.', 'rockfire-rest-api-cache' ), $label, $values, count( $note->routes ), $this->max_vary ) );
				break;
			case Translation_Note::INVALID_RULE:
				/* translators: 1: an endpoint of the original plugin, 2: a route pattern, 3: comma separated error codes. */
				$this->add( Finding::SKIPPED, $note->code, $subject, sprintf( __( '%1$s: no rule could be built for %2$s (%3$s).', 'rockfire-rest-api-cache' ), $label, $routes, $values ) );
				break;
		}
	}

	/**
	 * Decide the outcome of a set of routes and count them per outcome.
	 *
	 * @param array<string,array<string,mixed>|null> $set Route key => translated rule, or null for a route cached by default.
	 *
	 * @return array<string,int> Outcome => number of routes, every outcome present.
	 */
	private function outcomes_of( array $set ): array {
		$groups = array(
			self::OUTCOME_WRITE         => 0,
			self::OUTCOME_DEFAULT       => 0,
			self::CODE_LOCKED           => 0,
			self::CODE_STORED_RULE      => 0,
			self::CODE_ALREADY_IMPORTED => 0,
		);
		foreach ( $set as $route => $rule ) {
			$outcome            = $this->outcome( (string) $route, $rule );
			$groups[ $outcome ] = ( $groups[ $outcome ] ?? 0 ) + 1;
		}
		return $groups;
	}

	/**
	 * The outcome of one route, decided once per run; a route that gets its rule joins the changes.
	 *
	 * @param string                   $route Route key.
	 * @param array<string,mixed>|null $rule  The translated rule, or null for a route cached by default.
	 *
	 * @return string `write`, `default`, `locked`, `stored_rule` or `already_imported`.
	 */
	private function outcome( string $route, ?array $rule ): string {
		if ( isset( $this->outcomes[ $route ] ) ) {
			return $this->outcomes[ $route ];
		}

		if ( $this->config->is_route_locked( $route ) ) {
			$outcome = self::CODE_LOCKED;
		} elseif ( isset( $this->stored_rules[ $route ] ) ) {
			$outcome = null !== $rule && $this->stored_rules[ $route ]->to_array() === $rule ? self::CODE_ALREADY_IMPORTED : self::CODE_STORED_RULE;
		} elseif ( isset( $this->invalid_rules[ $route ] ) ) {
			$outcome = self::CODE_STORED_RULE;
		} elseif ( null !== $rule ) {
			$this->rule_changes[ $route ] = $rule;
			$outcome                      = self::OUTCOME_WRITE;
		} else {
			$outcome = self::OUTCOME_DEFAULT;
		}

		$this->outcomes[ $route ] = $outcome;
		return $outcome;
	}

	/**
	 * The routes the allowed entries match.
	 *
	 * @param Endpoint_Translator $translator The translator.
	 * @param array<mixed>        $allowed    `namespace => [endpoint]` that were allowed.
	 *
	 * @return array<string,true>
	 */
	private static function allowed_routes( Endpoint_Translator $translator, array $allowed ): array {
		$routes = array();
		foreach ( $allowed as $namespace => $endpoints ) {
			if ( ! is_array( $endpoints ) ) {
				continue;
			}
			foreach ( $endpoints as $endpoint ) {
				if ( ! is_string( $endpoint ) ) {
					continue;
				}
				foreach ( $translator->match( (string) $namespace, $endpoint ) as $route ) {
					$routes[ $route ] = true;
				}
			}
		}
		return $routes;
	}

	/**
	 * The identifier of an entry: its option with the namespace and the endpoint, or the path for a header entry.
	 *
	 * @param Translation_Note $note A note about the entry.
	 *
	 * @return string
	 */
	private static function entry_subject( Translation_Note $note ): string {
		if ( Translation_Note::SOURCE_HEADERS === $note->source ) {
			$path = '' === $note->endpoint ? $note->namespace : $note->namespace . '/' . $note->endpoint;
			return self::OPTION_PREFIX . 'cacheable_request_headers[' . $path . ']';
		}
		$option = Translation_Note::SOURCE_ALLOWED === $note->source ? 'allowed_endpoints' : 'disallowed_endpoints';
		return self::OPTION_PREFIX . $option . '[' . $note->namespace . '][' . $note->endpoint . ']';
	}

	/**
	 * An entry for a message, such as "posts in wp/v2"; the namespace alone for an entry without an endpoint.
	 *
	 * @param Translation_Note $note A note about the entry.
	 *
	 * @return string Empty for an entry without a namespace.
	 */
	private static function entry_label( Translation_Note $note ): string {
		if ( '' === $note->endpoint ) {
			return $note->namespace;
		}
		/* translators: 1: an endpoint, such as "posts", 2: a REST namespace, such as "wp/v2". */
		return sprintf( __( '%1$s in %2$s', 'rockfire-rest-api-cache' ), $note->endpoint, $note->namespace );
	}

	// ---------------------------------------------------------------------------------------
	// Values without an equivalent, hooks.
	// ---------------------------------------------------------------------------------------

	/**
	 * The request methods the original cached, uppercased and without duplicates.
	 *
	 * @return list<string>|null Null when the site has no such option.
	 */
	private function read_request_methods(): ?array {
		$suffix = 'allowed_request_methods';
		if ( ! $this->original::has_option( $suffix ) ) {
			return null;
		}

		$value   = $this->original::option( $suffix );
		$methods = array();
		foreach ( is_array( $value ) ? $value : array() as $method ) {
			if ( is_string( $method ) && '' !== trim( $method ) ) {
				$methods[] = strtoupper( trim( $method ) );
			}
		}
		return array_values( array_unique( $methods ) );
	}

	/**
	 * The request methods besides GET that a route rule can cache: those of the list that are POST, PUT or PATCH.
	 *
	 * @param string[] $methods Request methods, uppercase.
	 * @phpstan-param list<string> $methods
	 *
	 * @return list<string> In {@see Route_Rule::METHODS} order.
	 */
	private static function extra_methods( array $methods ): array {
		return array_values( array_intersect( array_diff( Route_Rule::METHODS, Route_Rule::DEFAULT_METHODS ), $methods ) );
	}

	/**
	 * Report the request methods the original cached.
	 *
	 * GET only is imported. POST, PUT and PATCH were cached on the allowed routes that register them, which is adjusted
	 * with the routes that now cache them, and skipped when no route does; a method that no route registers is skipped
	 * as well when others are adjusted. The routes that register one of them but keep without it (a core route, a
	 * route cached by default, a route with a stored rule or one defined in `RFRAC_ROUTES` that does not cache it) and
	 * the methods that can not be cached at all are skipped.
	 *
	 * @param string[]|null $methods The methods of the original; null without the option.
	 * @phpstan-param list<string>|null $methods
	 *
	 * @return void
	 */
	private function report_request_methods( ?array $methods ): void {
		if ( null === $methods ) {
			return;
		}

		$subject     = self::OPTION_PREFIX . 'allowed_request_methods';
		$extra       = self::extra_methods( $methods );
		$unsupported = array_values( array_diff( $methods, Route_Rule::METHODS ) );

		if ( array() === $extra ) {
			if ( in_array( 'GET', $methods, true ) ) {
				/* translators: %s: the option of the original plugin. */
				$this->add( Finding::IMPORTED, self::CODE_REQUEST_METHODS, $subject, sprintf( __( '%s: GET requests are cached, as before.', 'rockfire-rest-api-cache' ), $subject ) );
			} elseif ( array() === $methods ) {
				/* translators: %s: the option of the original plugin. */
				$this->add( Finding::SKIPPED, self::CODE_REQUEST_METHODS, $subject, sprintf( __( '%s allows no request method, but GET requests are cached.', 'rockfire-rest-api-cache' ), $subject ) );
			} else {
				/* translators: %s: the option of the original plugin. */
				$this->add( Finding::SKIPPED, self::CODE_REQUEST_METHODS, $subject, sprintf( __( '%s does not allow GET requests, but GET requests are cached.', 'rockfire-rest-api-cache' ), $subject ) );
			}
		} elseif ( array() === $this->method_routes ) {
			$this->add(
				Finding::SKIPPED,
				self::CODE_REQUEST_METHODS,
				$subject,
				/* translators: 1: the option of the original plugin, 2: comma separated request methods. */
				sprintf( __( '%1$s: only GET requests are cached; %2$s requests are not, because no imported route takes them.', 'rockfire-rest-api-cache' ), $subject, implode( ', ', $extra ) )
			);
		} else {
			$this->add(
				Finding::ADJUSTED,
				self::CODE_REQUEST_METHODS,
				$subject,
				sprintf(
					/* translators: 1: the option of the original plugin, 2: comma separated request methods, 3: number of routes. */
					_n( '%1$s: %2$s requests are cached on %3$d imported route.', '%1$s: %2$s requests are cached on %3$d imported routes.', count( $this->method_routes ), 'rockfire-rest-api-cache' ),
					$subject,
					implode( ', ', self::extra_methods( array_merge( ...array_values( $this->method_routes ) ) ) ),
					count( $this->method_routes )
				)
			);

			$unregistered = array_values(
				array_diff(
					$extra,
					self::extra_methods( array_merge( array(), ...array_values( $this->method_routes ) ) ),
					self::extra_methods( array_merge( array(), ...array_values( $this->method_kept ) ) )
				)
			);
			if ( array() !== $unregistered ) {
				$this->add(
					Finding::SKIPPED,
					self::CODE_REQUEST_METHODS_NO_ROUTE,
					$subject,
					/* translators: 1: the option of the original plugin, 2: comma separated request methods. */
					sprintf( __( '%1$s: %2$s requests are not cached, because no imported route takes them.', 'rockfire-rest-api-cache' ), $subject, implode( ', ', $unregistered ) )
				);
			}
		}

		if ( array() !== $unsupported ) {
			$this->add(
				Finding::SKIPPED,
				self::CODE_REQUEST_METHODS_UNSUPPORTED,
				$subject,
				/* translators: 1: the option of the original plugin, 2: comma separated request methods. */
				sprintf( __( '%1$s: %2$s requests can not be cached.', 'rockfire-rest-api-cache' ), $subject, implode( ', ', $unsupported ) )
			);
		}

		if ( array() !== $this->method_kept ) {
			$this->add(
				Finding::SKIPPED,
				self::CODE_REQUEST_METHODS_NOT_APPLIED,
				$subject,
				sprintf(
					/* translators: 1: the option of the original plugin, 2: comma separated request methods, 3: number of routes, 4: a constant name. */
					_n(
						'%1$s: %2$s requests are not cached on %3$d route; a core route is only cached for GET, and a route keeps the rule it is cached with by default, the rule saved in this plugin or the rule defined by %4$s.',
						'%1$s: %2$s requests are not cached on %3$d routes; a core route is only cached for GET, and a route keeps the rule it is cached with by default, the rule saved in this plugin or the rule defined by %4$s.',
						count( $this->method_kept ),
						'rockfire-rest-api-cache'
					),
					$subject,
					implode( ', ', self::extra_methods( array_merge( ...array_values( $this->method_kept ) ) ) ),
					count( $this->method_kept ),
					Config::ROUTES_CONSTANT
				)
			);
		}
	}

	/**
	 * Report the options and constants of features this plugin does not have: regeneration, Memcache(d) and
	 * switching off the hit recording.
	 *
	 * @return void
	 */
	private function report_removed_features(): void {
		/* translators: %s: the option or constant of the original plugin. */
		$regenerate = __( '%s: expired caches are not regenerated in the background; an expired entry is stored again by the next request for it.', 'rockfire-rest-api-cache' );
		/* translators: %s: the option or constant of the original plugin. */
		$memcache = __( '%s: the store is chosen automatically and works with any persistent object cache; pick one under Store in the settings to override it.', 'rockfire-rest-api-cache' );

		if ( true === Settings_Schema::to_bool( $this->original::option( 'regenerate' ) ) ) {
			$subject = self::OPTION_PREFIX . 'regenerate';
			$this->add( Finding::SKIPPED, self::CODE_REGENERATE, $subject, sprintf( $regenerate, $subject ) );
		}
		if ( true === Settings_Schema::to_bool( $this->original::option( 'memcache_used' ) ) ) {
			$subject = self::OPTION_PREFIX . 'memcache_used';
			$this->add( Finding::SKIPPED, self::CODE_MEMCACHE_USED, $subject, sprintf( $memcache, $subject ) );
		}
		if ( $this->original::has_option( 'hit_recording' ) && false === Settings_Schema::to_bool( $this->original::option( 'hit_recording' ) ) ) {
			$subject = self::OPTION_PREFIX . 'hit_recording';
			$this->add(
				Finding::SKIPPED,
				self::CODE_HIT_RECORDING,
				$subject,
				/* translators: %s: the option of the original plugin. */
				sprintf( __( '%s: hits are counted by sampling; set Hit sampling to 0 in the settings to stop counting them.', 'rockfire-rest-api-cache' ), $subject )
			);
		}

		$constants = array(
			Original_Settings::REGENERATE          => self::CODE_REGENERATE,
			Original_Settings::REGENERATE_INTERVAL => self::CODE_REGENERATE,
			Original_Settings::REGENERATE_NUMBER   => self::CODE_REGENERATE,
			Original_Settings::MEMCACHE_USED       => self::CODE_MEMCACHE_USED,
		);
		foreach ( array_keys( $this->original::defined_constants() ) as $constant ) {
			$code = $constants[ $constant ] ?? null;
			if ( null !== $code ) {
				$this->add( Finding::SKIPPED, $code, $constant, sprintf( self::CODE_REGENERATE === $code ? $regenerate : $memcache, $constant ) );
			}
		}
	}

	/**
	 * Report the requests this plugin does not cache although the original did.
	 *
	 * @return void
	 */
	private function report_stricter_requests(): void {
		$this->add(
			Finding::ADJUSTED,
			self::CODE_STRICTER_REQUESTS,
			self::PLUGIN_SUBJECT,
			__( 'Some requests that WP REST Cache cached bypass the cache here: requests with a query parameter the route does not register, with the search parameter, with credentials, or with a method the route does not cache.', 'rockfire-rest-api-cache' )
		);
	}

	/**
	 * Report every hook of the original that has a callback: bridged hooks keep working, the others never run.
	 *
	 * @return void
	 */
	private function report_hooks(): void {
		$hooks = $GLOBALS['wp_filter'] ?? array();
		if ( ! is_array( $hooks ) ) {
			return;
		}

		$names = array();
		foreach ( $hooks as $name => $hook ) {
			$name = (string) $name;
			if ( str_starts_with( $name, Hook_Registry::PREFIX ) && $hook instanceof \WP_Hook && $hook->has_filters() ) {
				$names[] = $name;
			}
		}
		sort( $names );

		$hit_filters = Hook_Registry::hit_filters();
		$unsupported = Hook_Registry::not_bridged();
		foreach ( $names as $name ) {
			$replacement = Hook_Registry::replacement( $name );
			if ( null !== $replacement ) {
				if ( in_array( $name, $hit_filters, true ) ) {
					/* translators: 1: a hook of the original plugin, 2: a constant name. */
					$this->add( Finding::IMPORTED, self::CODE_HIT_FILTER, $name, sprintf( __( '%1$s keeps working, but is applied on cache hits only with %2$s.', 'rockfire-rest-api-cache' ), $name, Hook_Registry::HIT_FILTERS_CONSTANT ) );
				} elseif ( '' === $replacement ) {
					/* translators: %s: a hook of the original plugin. */
					$this->add( Finding::IMPORTED, self::CODE_BRIDGED, $name, sprintf( __( '%s keeps working.', 'rockfire-rest-api-cache' ), $name ) );
				} else {
					/* translators: 1: a hook of the original plugin, 2: the hook that replaces it. */
					$this->add( Finding::IMPORTED, self::CODE_BRIDGED, $name, sprintf( __( '%1$s keeps working; replace it with %2$s.', 'rockfire-rest-api-cache' ), $name, $replacement ) );
				}
				continue;
			}

			$entry = $unsupported[ $name ] ?? null;
			if ( null === $entry ) {
				/* translators: %s: a hook name. */
				$this->add( Finding::UNSUPPORTED, self::CODE_UNKNOWN_HOOK, $name, sprintf( __( '%s is not a hook of WP REST Cache that this plugin knows, so it never runs.', 'rockfire-rest-api-cache' ), $name ) );
				continue;
			}
			/* translators: 1: a hook of the original plugin, 2: why it is not supported. */
			$this->add( Finding::UNSUPPORTED, $entry['reason'], $name, sprintf( __( '%1$s never runs: %2$s', 'rockfire-rest-api-cache' ), $name, $entry['note'] ) );
		}
	}

	// ---------------------------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------------------------

	/**
	 * Add a finding to the report of the run.
	 *
	 * @param string $status  One of {@see Finding::STATUSES}.
	 * @param string $code    Reason code.
	 * @param string $subject The identifier of the original the finding is about.
	 * @param string $message Human-readable sentence.
	 *
	 * @return void
	 */
	private function add( string $status, string $code, string $subject, string $message ): void {
		$this->report[] = new Finding( $status, $code, $subject, $message );
	}

	/**
	 * Whether the stored snapshot is current: present, fingerprinted and built from a server.
	 *
	 * @return bool
	 */
	private static function compiled(): bool {
		$compiled = Compiled_Config::load();
		return null !== $compiled && '' !== $compiled->fingerprint() && ! $compiled->pending_discovery();
	}

	/**
	 * An integer setting of a settings array, the default when it is missing or not an integer.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param string              $key      Settings key.
	 *
	 * @return int
	 */
	private static function int_of( array $settings, string $key ): int {
		return Settings_Schema::to_int( $settings[ $key ] ?? null )
			?? Settings_Schema::to_int( Settings_Schema::defaults()[ $key ] ?? null )
			?? 0;
	}

	/**
	 * The string entries of a list value.
	 *
	 * @param mixed $value List value.
	 *
	 * @return list<string>
	 */
	private static function strings( mixed $value ): array {
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	/**
	 * A value as short text for a message.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function display( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : gettype( $value );
	}
}
