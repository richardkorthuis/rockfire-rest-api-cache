<?php
/**
 * Writes a cached hit to the client.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Serving;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Compiled_Route;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Storage\Cached_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Outputs a cached response byte for byte with a safe header set.
 *
 * Runs on the hit path, possibly from an mu-plugin early in the bootstrap: it applies no filters
 * or actions, reads no options and does not call `home_url()`. The status is set with
 * `http_response_code()` (core's `status_header()` would apply a filter).
 *
 * Every header line is built and validated before anything is emitted; once emitting started the
 * request is terminated whatever happens, so a failure can never be followed by the live response.
 *
 * @since 1.0.0
 */
final class Response_Writer {

	/**
	 * Maximum length of a replayed header value in bytes.
	 */
	public const MAX_VALUE_BYTES = 8192;

	/**
	 * Methods advertised to an allow-listed CORS origin (core's list).
	 */
	public const CORS_METHODS = 'OPTIONS, GET, POST, PUT, PATCH, DELETE';

	/**
	 * Stored headers replayed regardless of the configured allow-list.
	 */
	private const ALWAYS_REPLAYED = [ 'access-control-expose-headers', 'access-control-allow-headers' ];

	/**
	 * Valid header name (RFC 9110 token).
	 */
	private const NAME_PATTERN = '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/D';

	/**
	 * Header emitter, `header( $line, $replace )`.
	 *
	 * @var \Closure
	 */
	private \Closure $emit_header;

	/**
	 * Terminates the request.
	 *
	 * @var \Closure
	 */
	private \Closure $terminate;

	/**
	 * Outputs the payload.
	 *
	 * @var \Closure
	 */
	private \Closure $output;

	/**
	 * Sets the HTTP status, `http_response_code( $code )`.
	 *
	 * @var \Closure
	 */
	private \Closure $status;

	/**
	 * Constructor; every closure defaults to the real PHP behaviour.
	 *
	 * @param \Closure|null $emit_header Receives `( string $header_line, bool $replace )`.
	 * @param \Closure|null $terminate   Called after the payload (also when emitting failed); must not return.
	 * @param \Closure|null $output      Receives `( string $payload )`.
	 * @param \Closure|null $status      Receives `( int $code )`.
	 */
	public function __construct( ?\Closure $emit_header = null, ?\Closure $terminate = null, ?\Closure $output = null, ?\Closure $status = null ) {
		$this->emit_header = $emit_header ?? static function ( string $line, bool $replace = true ): void {
			header( $line, $replace );
		};
		$this->terminate   = $terminate ?? static function (): never {
			exit;
		};
		$this->output      = $output ?? static function ( string $payload ): void {
			echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The stored REST response body, sent byte-for-byte with its stored Content-Type; escaping would corrupt the JSON.
		};
		$this->status      = $status ?? static function ( int $code ): void {
			http_response_code( $code );
		};
	}

	/**
	 * Send a cached hit and terminate.
	 *
	 * @param Cached_Response      $response Cached response.
	 * @param Request_Context      $context  Request context.
	 * @param Compiled_Config      $config   Compiled config.
	 * @param Policy_Decision|null $decision Decision of the lookup; the vary headers of its rule are advertised in `Vary`.
	 * @param bool|null            $cors     Whether the hit carries the `Access-Control-Allow-*` headers; null takes the flag of the compiled config.
	 *
	 * @return never
	 *
	 * @throws \LogicException When the injected terminate closure returns.
	 */
	public function send_hit( Cached_Response $response, Request_Context $context, Compiled_Config $config, ?Policy_Decision $decision = null, ?bool $cors = null ): never {
		$cors ??= $config->cors_on_hits();

		// Build and validate everything first: a failure here leaves the request untouched.
		$lines = [];
		self::add( $lines, 'Content-Type', 'application/json; charset=' . $config->charset() );
		self::add( $lines, 'X-Content-Type-Options', 'nosniff' );
		self::add( $lines, 'X-Robots-Tag', 'noindex' );

		$allowed = array_merge( $config->replay_headers(), self::ALWAYS_REPLAYED );
		foreach ( $response->headers as $name => $value ) {
			$lower = strtolower( (string) $name );
			if ( in_array( $lower, Config_Compiler::FORBIDDEN_REPLAY_HEADERS, true ) || ! in_array( $lower, $allowed, true ) ) {
				continue;
			}
			self::add( $lines, (string) $name, (string) $value );
		}

		self::add_cors( $lines, $context, $config, $decision?->rule, $cors );
		self::add( $lines, 'X-Rfrac-Cache', 'HIT' );
		$payload = $response->payload;

		// Once emitting started the request ends here, also when emitting fails.
		try {
			( $this->status )( 200 );
			foreach ( $lines as $line ) {
				( $this->emit_header )( $line, true );
			}
			( $this->output )( $payload );
		} finally {
			( $this->terminate )();
		}

		throw new \LogicException( 'The terminate callback returned.' );
	}

	/**
	 * Headers for a bypassed request.
	 *
	 * @param Bypass_Reason $reason Why the request bypasses the cache.
	 * @param bool          $debug  Whether to expose the reason (`WP_DEBUG`).
	 *
	 * @return array<string,string>
	 */
	public static function bypass_headers( Bypass_Reason $reason, bool $debug ): array {
		$headers = [ 'X-Rfrac-Cache' => 'BYPASS' ];
		if ( $debug ) {
			$headers['X-Rfrac-Cache-Reason'] = $reason->value;
		}
		return $headers;
	}

	/**
	 * The `Vary` value of a cacheable response: `Origin` plus the vary headers of the rule.
	 *
	 * @param Compiled_Route|null $rule Matched rule.
	 *
	 * @return string
	 */
	public static function vary_value( ?Compiled_Route $rule ): string {
		$names = [ 'Origin' ];
		$seen  = [ 'origin' => true ];
		foreach ( null !== $rule ? $rule->vary_headers : [] as $name ) {
			$lower = strtolower( (string) $name );
			if ( isset( $seen[ $lower ] ) || 1 !== preg_match( self::NAME_PATTERN, $lower ) ) {
				continue;
			}
			$seen[ $lower ] = true;
			$names[]        = $lower;
		}
		return implode( ', ', $names );
	}

	/**
	 * Add the CORS headers and `Vary`.
	 *
	 * Only an origin listed in the compiled config is ever echoed. The `Access-Control-Allow-*` headers are
	 * skipped when CORS on hits is switched off (by the compiled config or the hit filter set); `Vary` is always added.
	 *
	 * @param string[]            $lines   Header lines, extended in place.
	 * @param Request_Context     $context Request context.
	 * @param Compiled_Config     $config  Compiled config.
	 * @param Compiled_Route|null $rule    Matched rule.
	 * @param bool                $cors    Whether the `Access-Control-Allow-*` headers are sent.
	 *
	 * @return void
	 */
	private static function add_cors( array &$lines, Request_Context $context, Compiled_Config $config, ?Compiled_Route $rule, bool $cors ): void {
		$origin = $context->origin;

		if ( $cors && null !== $origin && '' !== $origin ) {
			if ( in_array( $origin, $config->cors_origins(), true ) ) {
				self::add( $lines, 'Access-Control-Allow-Origin', $origin );
				self::add( $lines, 'Access-Control-Allow-Methods', self::CORS_METHODS );
				self::add( $lines, 'Access-Control-Allow-Credentials', 'true' );
			} elseif ( $config->cors_wildcard() ) {
				self::add( $lines, 'Access-Control-Allow-Origin', '*' );
			}
		}

		self::add( $lines, 'Vary', self::vary_value( $rule ) );
	}

	/**
	 * Add one header line after sanitising it; invalid names are skipped.
	 *
	 * @param string[] $lines Header lines, extended in place.
	 * @param string   $name  Header name.
	 * @param string   $value Header value.
	 *
	 * @return void
	 */
	private static function add( array &$lines, string $name, string $value ): void {
		if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
			return;
		}
		$value = str_replace( [ "\r", "\n", "\0" ], '', $value );
		if ( strlen( $value ) > self::MAX_VALUE_BYTES ) {
			$value = substr( $value, 0, self::MAX_VALUE_BYTES );
		}
		$lines[] = $name . ': ' . $value;
	}
}
