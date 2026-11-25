<?php
/**
 * Serves cached hits from `plugins_loaded` when the must-use file did not.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Serving;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Settings_Schema;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the same chain as {@see Early_Server} when the must-use file is absent or outdated.
 *
 * @since 1.0.0
 */
final class Fallback_Server {

	/**
	 * Returns the compiled config, `(): ?Compiled_Config`.
	 *
	 * @var \Closure
	 */
	private \Closure $config_loader;

	/**
	 * Constructor.
	 *
	 * @param Lookup   $lookup        Lookup chain.
	 * @param \Closure $config_loader Returns the compiled config or null, e.g. `Compiled_Config::load(...)`.
	 * @phpstan-param \Closure(): ?Compiled_Config $config_loader
	 */
	public function __construct( private readonly Lookup $lookup, \Closure $config_loader ) {
		$this->config_loader = $config_loader;
	}

	/**
	 * Serve a hit unless the early server already ran or a lookup is already done.
	 *
	 * @return void
	 */
	public function maybe_serve(): void {
		// The RFRAC_DISABLE kill switch acts immediately, without a recompile.
		if ( Request_State::early_booted() || Request_State::lookup_done() || Settings_Schema::kill_switch() ) {
			return;
		}

		// Preparation is guarded; Lookup::run() stays outside so that a hit can end the request.
		try {
			$config = ( $this->config_loader )();
			if ( ! $config instanceof Compiled_Config || Schema::VERSION !== $config->schema_version() ) {
				return;
			}
			$context = Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() );
		} catch ( \Throwable $e ) {
			Failure_Recorder::record( 'fallback_server', $e );
			return;
		}

		$this->lookup->run( $context, $config );
	}
}
