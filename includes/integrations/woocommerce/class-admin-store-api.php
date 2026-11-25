<?php
/**
 * Registers the WooCommerce Store API routes when the plugin reads the routes in a wp-admin request.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Integrations\WooCommerce;

use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Blocks\Registry\Container;
use Automattic\WooCommerce\StoreApi\Authentication;
use Automattic\WooCommerce\StoreApi\StoreApi;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Initialises the WooCommerce Store API in wp-admin requests that read the routes, where WooCommerce itself does not.
 *
 * WooCommerce initialises the Store API only outside wp-admin (`Blocks\Domain\Bootstrap`, `! is_admin()`), so
 * the REST server of an admin request has no `/wc/store` routes. The route manager, the catalog preset and the
 * settings-save compile read that server, and would otherwise treat every Store API route as unregistered: the
 * preset skips them all and a compile drops them from the snapshot.
 *
 * Subscribed only in admin requests, this component answers {@see Hooks::DISCOVERY_SERVER}, which fires only when
 * the plugin itself is about to build the REST server to read routes. It then runs WooCommerce's own Store API
 * initialisation, so the server lists the same routes as a front-end one. Other admin requests that build a REST
 * server (the block editor's preloading, other plugins) are left as WooCommerce made them. When this request's
 * server was already built, its routes are fixed and nothing is initialised.
 *
 * The initialisation calls WooCommerce internals, so it is guarded. Each class and method it
 * needs ({@see self::REQUIRED}) is checked before the call, and before the detector below runs. A missing
 * internal, or one that throws, is recorded as a `woocommerce:store_api` failure and leaves the request running
 * with no Store API routes; a later successful initialisation clears it. The Store API is initialised at most
 * once per request, and never when WooCommerce, or another container, already did:
 * {@see self::store_api_initialised()} detects that from the session handler callback the Store API adds, so no
 * flag outlives this component. Every WooCommerce bump re-verifies that detector
 * ({@see self::DETECTOR_REQUIRED}), together with {@see self::REQUIRED}, in the WooCommerce suite.
 *
 * @since 1.0.0
 */
final class Admin_Store_Api {

	use Hook_Subscriber_Trait;

	/**
	 * The failure context the guards record under.
	 *
	 * @var string
	 */
	public const FAILURE_CONTEXT = 'woocommerce:store_api';

	/**
	 * The WooCommerce internals the default initialisation calls, as `[ class, method ]`, each checked before the call.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	public const REQUIRED = array(
		array( Package::class, 'container' ),
		array( Container::class, 'get' ),
		array( StoreApi::class, 'init' ),
	);

	/**
	 * The WooCommerce internals the detector reads, as `[ class, method ]`, each checked before the call.
	 *
	 * Public so the admin-context suite pins them, like {@see self::REQUIRED}.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	public const DETECTOR_REQUIRED = array(
		array( StoreApi::class, 'container' ),
		array( Container::class, 'get' ),
		array( Authentication::class, 'maybe_use_store_api_session_handler' ),
	);

	/**
	 * The filter whose callback proves that the Store API was initialised.
	 *
	 * @var string
	 */
	private const SESSION_HANDLER_FILTER = 'woocommerce_session_handler';

	/**
	 * Runs the Store API initialisation; the default asks the WooCommerce Blocks container.
	 *
	 * @var \Closure
	 * @phpstan-var \Closure(): void
	 */
	private \Closure $init;

	/**
	 * Tells whether the Store API was already initialised in this request; the default is the session handler detector.
	 *
	 * @var \Closure
	 * @phpstan-var \Closure(): bool
	 */
	private \Closure $initialised;

	/**
	 * The WooCommerce internals that must exist before the initialisation runs, as `[ class, method ]`.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private array $required;

	/**
	 * Whether the guards ran in this request: the Store API was initialised, found initialised, or failed.
	 *
	 * @var bool
	 */
	private bool $done = false;

	/**
	 * Create the component.
	 *
	 * @param \Closure|null                            $init        Runs the Store API initialisation; null for WooCommerce's own.
	 * @param \Closure|null                            $initialised Tells whether the Store API is already initialised; null for {@see self::store_api_initialised()}.
	 * @param array<int,array{0:string,1:string}>|null $required    The `[ class, method ]` internals checked before the initialisation; null for {@see self::REQUIRED}.
	 *
	 * @phpstan-param (\Closure(): void)|null $init
	 * @phpstan-param (\Closure(): bool)|null $initialised
	 */
	public function __construct( ?\Closure $init = null, ?\Closure $initialised = null, ?array $required = null ) {
		$this->init        = $init ?? static function (): void {
			$container = Package::container();
			if ( ! $container instanceof Container ) {
				throw new \UnexpectedValueException( 'The WooCommerce Blocks container is not a Container.' );
			}
			$store_api = $container->get( StoreApi::class );
			if ( ! $store_api instanceof StoreApi ) {
				throw new \UnexpectedValueException( 'The WooCommerce Blocks container did not return the Store API.' );
			}
			$store_api->init();
		};
		$this->initialised = $initialised ?? self::store_api_initialised( ... );
		$this->required    = $required ?? self::REQUIRED;
	}

	/**
	 * Whether the Store API was already initialised in this request, by WooCommerce or another container.
	 *
	 * `StoreApi::init()` hooks `Authentication::maybe_use_store_api_session_handler()` on
	 * `woocommerce_session_handler`, at any priority; that callback, of the shared Authentication instance of the
	 * Store API container, is the proof. It never throws: when an internal it reads is missing or throws, it
	 * records a failure that stays until dismissed and answers false.
	 *
	 * @return bool
	 */
	public static function store_api_initialised(): bool {
		try {
			$missing = self::first_missing( self::DETECTOR_REQUIRED );
			if ( null !== $missing ) {
				Failure_Recorder::record( self::FAILURE_CONTEXT, sprintf( 'The WooCommerce Store API internal %s::%s is missing; an initialised Store API cannot be told apart.', $missing[0], $missing[1] ), '', false );
				return false;
			}
			$container = StoreApi::container();
			if ( ! $container instanceof Container ) {
				return false;
			}
			$authentication = $container->get( Authentication::class );
			if ( ! $authentication instanceof Authentication ) {
				return false;
			}
			return false !== has_filter( self::SESSION_HANDLER_FILTER, array( $authentication, 'maybe_use_store_api_session_handler' ) );
		} catch ( \Throwable $e ) {
			Failure_Recorder::record( self::FAILURE_CONTEXT, $e, '', false );
			return false;
		}
	}

	/**
	 * The first of the given internals that does not exist.
	 *
	 * @param array<int,array{0:string,1:string}> $required The `[ class, method ]` internals.
	 *
	 * @return array{0:string,1:string}|null Null when all of them exist.
	 */
	private static function first_missing( array $required ): ?array {
		foreach ( $required as $internal ) {
			if ( ! method_exists( $internal[0], $internal[1] ) ) {
				return $internal;
			}
		}
		return null;
	}

	/**
	 * The hooks of the component.
	 *
	 * @return list<array{0:string,1:string,2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		return array(
			array( Hooks::DISCOVERY_SERVER, 'before_discovery', 10, 0 ),
		);
	}

	/**
	 * Initialise the Store API at most once, before this request builds its REST server.
	 *
	 * Skipped until the WooCommerce Blocks package has loaded, and once the server exists: its routes were
	 * registered on `rest_api_init`, which does not fire again. A missing internal is recorded before the detector
	 * runs: the detector shares internals with the initialisation, and its own record only says that it cannot
	 * tell, so it must not take the single failure slot ahead of the record that names the impact. Skipped too
	 * when the Store API was already initialised, by WooCommerce or another container. An initialisation that
	 * throws is recorded as well. Either leaves the request running without Store API routes; a successful
	 * initialisation clears that failure.
	 *
	 * @return void
	 */
	public function before_discovery(): void {
		if ( $this->done || 0 === did_action( 'woocommerce_blocks_loaded' ) || did_action( 'rest_api_init' ) > 0 ) {
			return;
		}
		$missing = self::first_missing( $this->required );
		if ( null !== $missing ) {
			$this->done = true;
			Failure_Recorder::record(
				self::FAILURE_CONTEXT,
				sprintf( 'The WooCommerce Store API internal %s::%s is missing; the Store API routes are not discovered in wp-admin.', $missing[0], $missing[1] )
			);
			return;
		}
		if ( ( $this->initialised )() ) {
			$this->done = true;
			return;
		}
		$this->done = true;
		try {
			( $this->init )();
		} catch ( \Throwable $e ) {
			Failure_Recorder::record( self::FAILURE_CONTEXT, $e );
			return;
		}
		Failure_Recorder::clear( array( self::FAILURE_CONTEXT ) );
	}
}
