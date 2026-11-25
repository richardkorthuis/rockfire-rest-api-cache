<?php
/**
 * Findings about the legacy hooks that are not applied the way their callbacks expect.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Rebuilds the findings of the hooks of the original plugin on every saved compile.
 *
 * Two groups are stored with {@see Findings::replace()}, so a hook whose callback was removed disappears from them
 * the next time the site compiles. A group with nothing to report is only written when it is already stored, so a
 * site without legacy callbacks keeps no finding (and gets no Import page):
 *
 * - {@see Findings::GROUP_HOOKS}: a hooked hook that is never fired, by the reason the registry gives; and, without
 *   `RFRAC_LEGACY_HIT_FILTERS`, a hit filter that is hooked but not applied on cache hits: for `cache_key` and
 *   `filter_cache_output` that is a refusal to store anything (`hit_filter_store_refused`, see
 *   {@see Store_Bridge::refuses_store()}), for `pre_output_headers` and a `skip_caching` that is not
 *   constant (see {@see Compile_Folding}) a plain note (`hit_filter_not_applied`);
 *   with or without the constant, a `skip_nonce_caching` that is not constant, which is not carried over to nonce
 *   caching;
 * - {@see Findings::GROUP_LOAD_ORDER}: with the constant, the callbacks of the six hit filters that a hit does not
 *   have loaded, by where their file lives ({@see Callback_Locator}).
 *
 * The refusal to store is also recorded when a store is refused ({@see self::record_store_refusal()}), because a
 * callback hooked by a code edit or a manual update does not change the snapshot fingerprint, so no compile need
 * follow; the next compile rebuilds the group from the hooks as they are then.
 *
 * The findings are not failures: they never go to the failure recorder. Nothing here changes the load order or
 * touches the must-use file.
 *
 * @since 1.0.0
 */
final class Hook_Findings {

	use Hook_Subscriber_Trait;

	/**
	 * Code: a hit filter is hooked but a cache hit does not apply it.
	 *
	 * @var string
	 */
	public const CODE_HIT_FILTER_NOT_APPLIED = 'hit_filter_not_applied';

	/**
	 * Code: a `cache_key` or `filter_cache_output` is hooked and a cache hit cannot apply it, so nothing is stored.
	 *
	 * @var string
	 */
	public const CODE_STORE_REFUSED = 'hit_filter_store_refused';

	/**
	 * Code: a `skip_nonce_caching` callback depends on the request, so it is not carried over to nonce caching.
	 *
	 * @var string
	 */
	public const CODE_NONCE_NOT_CARRIED_OVER = 'nonce_not_carried_over';

	/**
	 * The hit filters that are only applied on a hit with the constant, and are not folded into the snapshot.
	 *
	 * @var list<string>
	 */
	private const REQUEST_FILTERS = array(
		Hook_Registry::PREFIX . 'cache_key',
		Hook_Registry::PREFIX . 'filter_cache_output',
		Hook_Registry::PREFIX . 'pre_output_headers',
		Compile_Folding::SKIP_CACHING,
	);

	/**
	 * The hit filters whose callback makes {@see Store_Bridge::refuses_store()} refuse to store.
	 *
	 * @var list<string>
	 */
	private const STORE_REFUSING_FILTERS = array(
		Hook_Registry::PREFIX . 'cache_key',
		Hook_Registry::PREFIX . 'filter_cache_output',
	);

	/**
	 * Maximum number of files a message lists.
	 *
	 * @var int
	 */
	private const MAX_FILES = 5;

	/**
	 * The store of the findings.
	 *
	 * @var Findings
	 */
	private Findings $findings;

	/**
	 * Resolves and classifies the files of the callbacks.
	 *
	 * @var Callback_Locator
	 */
	private Callback_Locator $locator;

	/**
	 * Gives the path the must-use early-cache file is installed at.
	 *
	 * @var \Closure
	 * @phpstan-var \Closure(): string
	 */
	private \Closure $mu_target;

	/**
	 * Create the component.
	 *
	 * @param Findings         $findings  The store of the findings.
	 * @param Callback_Locator $locator   Resolves and classifies the files of the callbacks.
	 * @param \Closure         $mu_target Gives the path of the must-use early-cache file, installed or not.
	 *
	 * @phpstan-param \Closure(): string $mu_target
	 */
	public function __construct( Findings $findings, Callback_Locator $locator, \Closure $mu_target ) {
		$this->findings  = $findings;
		$this->locator   = $locator;
		$this->mu_target = $mu_target;
	}

	/**
	 * The hooks of this component.
	 *
	 * @return array<int,array<int,mixed>> `[ hook, method, priority, accepted args ]` entries.
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		return array(
			array( Hooks::COMPILED, 'record', 10, 1 ),
		);
	}

	/**
	 * Rebuild and store both groups; runs when a compile saved the snapshot.
	 *
	 * @param mixed $config The compiled configuration; not needed, the findings describe the hooks as they are now.
	 *
	 * @return void
	 */
	public function record( mixed $config = null ): void {
		unset( $config );

		$this->store( Findings::GROUP_HOOKS, $this->hook_findings() );
		$this->store( Findings::GROUP_LOAD_ORDER, $this->load_order_findings() );
	}

	/**
	 * Record that responses are not stored because a hit filter is hooked, unless the group already says so.
	 *
	 * Called by {@see Store_Bridge::refuses_store()} when it refuses a store, so the finding exists no later than the
	 * first refused store: a callback added by a code edit, or a release deployed by hand, changes nothing the
	 * snapshot fingerprint covers, and no compile may follow to record it. Each hooked filter that makes the store
	 * refuse is added with {@see Findings::add()}, which writes nothing for a finding the group already holds, so a
	 * refused store costs a read of the option and writes only the first time. Nothing is recorded with the constant.
	 *
	 * @param Findings $findings The store of the findings.
	 *
	 * @return void
	 */
	public static function record_store_refusal( Findings $findings ): void {
		if ( Hook_Registry::hit_filters_enabled() ) {
			return;
		}

		foreach ( self::STORE_REFUSING_FILTERS as $name ) {
			if ( false !== has_filter( $name ) ) {
				$findings->add( Findings::GROUP_HOOKS, self::store_refused_finding( $name ) );
			}
		}
	}

	/**
	 * The finding of a `cache_key` or `filter_cache_output` that is hooked and makes nothing stored.
	 *
	 * @param string $name Full name of the hit filter.
	 *
	 * @return Finding
	 */
	private static function store_refused_finding( string $name ): Finding {
		return new Finding(
			Finding::UNSUPPORTED,
			self::CODE_STORE_REFUSED,
			$name,
			/* translators: 1: a hook of the original plugin, 2: a constant name. */
			sprintf( __( '%1$s is hooked, so responses are not stored: a cache hit cannot apply it. Define %2$s to apply it on cache hits, or remove the callback, then flush the cache once.', 'rockfire-rest-api-cache' ), $name, Hook_Registry::HIT_FILTERS_CONSTANT )
		);
	}

	/**
	 * Replace a group with its findings; an empty list for a group that is not stored writes nothing, so a site
	 * without legacy callbacks keeps no finding.
	 *
	 * @param string             $group    Group id.
	 * @param array<int,Finding> $findings The findings of the group.
	 *
	 * @phpstan-param list<Finding> $findings
	 *
	 * @return void
	 */
	private function store( string $group, array $findings ): void {
		if ( array() === $findings && null === $this->findings->get( $group ) ) {
			return;
		}

		$this->findings->replace( $group, $findings );
	}

	/**
	 * The findings of the hooks group.
	 *
	 * @return list<Finding>
	 */
	private function hook_findings(): array {
		$findings = array();

		$not_bridged = Hook_Registry::not_bridged();
		foreach ( Hook_Registry::hooked_unsupported() as $name ) {
			$entry = $not_bridged[ $name ] ?? null;
			if ( null !== $entry ) {
				$findings[] = new Finding( Finding::UNSUPPORTED, $entry['reason'], $name, self::unsupported_message( $name, $entry['reason'], $entry['note'] ) );
			}
		}

		// Only a constant skip_nonce_caching is folded into the snapshot; one that depends on the request is not carried over.
		$nonce = Compile_Folding::SKIP_NONCE_CACHING;
		if ( false !== has_filter( $nonce ) && ! Compile_Folding::is_constant( $nonce ) ) {
			$findings[] = new Finding(
				Finding::UNSUPPORTED,
				self::CODE_NONCE_NOT_CARRIED_OVER,
				$nonce,
				/* translators: %s: a hook of the original plugin. */
				sprintf( __( '%s is hooked, but a callback that depends on the request is not carried over. Turn nonce caching on under REST API Cache → Settings to cache requests that carry a nonce.', 'rockfire-rest-api-cache' ), $nonce )
			);
		}

		if ( Hook_Registry::hit_filters_enabled() ) {
			return $findings;
		}

		foreach ( self::REQUEST_FILTERS as $name ) {
			// Only a constant skip_caching is folded into the snapshot; the others always depend on the request.
			if ( false === has_filter( $name ) || ( Compile_Folding::SKIP_CACHING === $name && Compile_Folding::is_constant( $name ) ) ) {
				continue;
			}

			// A key or an output that a hit cannot apply means nothing is stored (Store_Bridge::refuses_store()).
			if ( in_array( $name, self::STORE_REFUSING_FILTERS, true ) ) {
				$findings[] = self::store_refused_finding( $name );
				continue;
			}

			$findings[] = new Finding(
				Finding::UNSUPPORTED,
				self::CODE_HIT_FILTER_NOT_APPLIED,
				$name,
				/* translators: 1: a hook of the original plugin, 2: a constant name. */
				sprintf( __( '%1$s is hooked but not applied on cache hits; define %2$s to apply it.', 'rockfire-rest-api-cache' ), $name, Hook_Registry::HIT_FILTERS_CONSTANT )
			);
		}

		return $findings;
	}

	/**
	 * The findings of the load-order group: only with the constant, the callbacks of the hit filters no hit has loaded.
	 *
	 * @return list<Finding>
	 */
	private function load_order_findings(): array {
		if ( ! Hook_Registry::hit_filters_enabled() ) {
			return array();
		}

		$target    = ( $this->mu_target )();
		$installed = is_file( $target ) ? $target : null;

		$findings = array();
		foreach ( Hook_Registry::hit_filters() as $name ) {
			$files = array();
			foreach ( Callback_Locator::callbacks( $name ) as $callback ) {
				$kind = $this->locator->classify( $callback, $installed );
				if ( Callback_Locator::EARLY === $kind ) {
					continue;
				}
				$file             = $this->locator->file( $callback );
				$files[ $kind ][] = null === $file ? __( 'an unknown file', 'rockfire-rest-api-cache' ) : $this->locator->display( $file );
			}

			foreach ( array( Callback_Locator::LOAD_ORDER, Callback_Locator::FALLBACK_ONLY, Callback_Locator::NOT_ON_HITS ) as $kind ) {
				if ( isset( $files[ $kind ] ) ) {
					$findings[] = new Finding( Finding::UNSUPPORTED, $kind, $name, self::load_order_message( $name, $kind, $files[ $kind ], basename( $target ) ) );
				}
			}
		}

		return $findings;
	}

	/**
	 * The message of a hook that is never fired.
	 *
	 * @param string $name   Full name of the hook.
	 * @param string $reason The reason of the registry.
	 * @param string $note   What to do instead; may be empty.
	 *
	 * @return string
	 */
	private static function unsupported_message( string $name, string $reason, string $note ): string {
		$message = match ( $reason ) {
			/* translators: %s: a hook of the original plugin. */
			Hook_Registry::REASON_ADMIN_UI => sprintf( __( '%s is hooked, but the admin screens were rebuilt and the hook is never fired, so the callback never runs.', 'rockfire-rest-api-cache' ), $name ),
			/* translators: %s: a hook of the original plugin. */
			Hook_Registry::REASON_REMOVED  => sprintf( __( '%s is hooked, but the feature it belongs to was removed and the hook is never fired, so the callback never runs.', 'rockfire-rest-api-cache' ), $name ),
			/* translators: %s: a hook of the original plugin. */
			Hook_Registry::REASON_NO_SEAM  => sprintf( __( '%s is hooked, but there is no place where this plugin could fire it, so the callback never runs.', 'rockfire-rest-api-cache' ), $name ),
			/* translators: %s: a hook of the original plugin. */
			default                        => sprintf( __( '%s is hooked, but the feature it belongs to is planned for a later release, so the callback never runs.', 'rockfire-rest-api-cache' ), $name ),
		};

		return '' === $note ? $message : $message . ' ' . $note;
	}

	/**
	 * The message of the callbacks of a hit filter that a cache hit does not have loaded.
	 *
	 * @param string            $name  Full name of the hit filter.
	 * @param string            $kind  One of the classes of {@see Callback_Locator} but the early one.
	 * @param array<int,string> $files The files of the callbacks, as displayed.
	 * @param string            $mu    File name of the must-use early-cache file.
	 *
	 * @phpstan-param list<string> $files
	 *
	 * @return string
	 */
	private static function load_order_message( string $name, string $kind, array $files, string $mu ): string {
		$files = array_values( array_unique( $files ) );
		$list  = implode( ', ', array_slice( $files, 0, self::MAX_FILES ) ) . ( count( $files ) > self::MAX_FILES ? ', …' : '' );

		return match ( $kind ) {
			/* translators: 1: a hook of the original plugin, 2: a file name, 3: the list of files. */
			Callback_Locator::LOAD_ORDER    => sprintf( __( '%1$s has callbacks in must-use files that load after %2$s, so a cache hit served early does not apply them: %3$s', 'rockfire-rest-api-cache' ), $name, $mu, $list ),
			/* translators: 1: a hook of the original plugin, 2: the list of files. */
			Callback_Locator::FALLBACK_ONLY => sprintf( __( '%1$s has callbacks in plugins, which a cache hit served early does not apply; only the fallback path does: %2$s', 'rockfire-rest-api-cache' ), $name, $list ),
			/* translators: 1: a hook of the original plugin, 2: the list of files. */
			default                         => sprintf( __( '%1$s has callbacks in a theme or in a file that cannot be found, so no cache hit applies them: %2$s', 'rockfire-rest-api-cache' ), $name, $list ),
		};
	}
}
