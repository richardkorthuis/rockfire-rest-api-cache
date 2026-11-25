<?php
/**
 * Serves cached hits from the must-use phase.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Serving;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Settings_Schema;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Install\Mu_Installer;
use Rockfire\Rest_Api_Cache\Request\Cache_Policy;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Schema\Schema;
use Rockfire\Rest_Api_Cache\Storage\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of the must-use file. Loads only classes of this plugin's `includes/`, no
 * `wp-admin/includes/*`, and registers no hooks.
 *
 * @since 1.0.0
 */
final class Early_Server {

	/**
	 * Test seam: a writer used instead of the real one.
	 *
	 * @var Response_Writer|null
	 */
	private static ?Response_Writer $writer_override = null;

	/**
	 * Test seam: a hit filter set used instead of {@see Lookup::hit_filters()}.
	 *
	 * @var Hit_Filters_Interface|null
	 */
	private static ?Hit_Filters_Interface $filters_override = null;

	/**
	 * Boot the early server. Called only by the must-use file.
	 *
	 * Ends the request on a hit; otherwise the outcome is left in {@see Request_State}.
	 *
	 * @param int $template_version Template version of the must-use file.
	 *
	 * @return void
	 */
	public static function boot( int $template_version ): void {
		// The early server only counts as booted once its preparation succeeded, so that the
		// fallback server retries otherwise.
		Request_State::record_boot( $template_version, false );
		// The RFRAC_DISABLE kill switch acts immediately, without a recompile.
		if ( Mu_Installer::TEMPLATE_VERSION !== $template_version || Settings_Schema::kill_switch() ) {
			return;
		}

		// Preparation is guarded; Lookup::run() stays outside so that a hit can end the request.
		try {
			$config = Compiled_Config::load();
			if ( null === $config || Schema::VERSION !== $config->schema_version() ) {
				return;
			}
			$context = Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() );
			$lookup  = new Lookup(
				new Cache_Policy( Coexistence_Guard::is_blocked( ... ) ),
				new Repository(),
				self::$writer_override ?? new Response_Writer(),
				null,
				null,
				self::$filters_override ?? Lookup::hit_filters()
			);
		} catch ( \Throwable $e ) {
			Failure_Recorder::record( 'early_server', $e );
			return;
		}

		Request_State::record_boot( $template_version, true );
		$lookup->run( $context, $config );
	}

	/**
	 * Test seam: use this writer in {@see self::boot()}; null restores the real one.
	 *
	 * @param Response_Writer|null $writer Writer.
	 *
	 * @return void
	 */
	public static function use_writer( ?Response_Writer $writer ): void {
		self::$writer_override = $writer;
	}

	/**
	 * Test seam: use this hit filter set in {@see self::boot()}; null restores {@see Lookup::hit_filters()}.
	 *
	 * @param Hit_Filters_Interface|null $filters Hit filter set.
	 *
	 * @return void
	 */
	public static function use_hit_filters( ?Hit_Filters_Interface $filters ): void {
		self::$filters_override = $filters;
	}
}
