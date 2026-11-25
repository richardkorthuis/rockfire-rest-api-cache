<?php
/**
 * Hit filter set test double.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Hit_Filters_Interface;
use Rockfire\Rest_Api_Cache\Storage\Cached_Response;

/**
 * Records every call as the method name in `$calls` and passes everything through, unless a closure is set.
 *
 * A closure receives the arguments of its method and returns what the method returns, so `null` is a real answer
 * of `$on_key` and `$on_response`.
 */
final class Rfrac_Fake_Hit_Filters implements Hit_Filters_Interface {

	/**
	 * Names of the methods called, in order.
	 *
	 * @var list<string>
	 */
	public array $calls = array();

	/**
	 * Replaces `decide()`: `( Policy_Decision, Request_Context, Compiled_Config ): Policy_Decision`.
	 *
	 * @var Closure|null
	 */
	public ?Closure $on_decide = null;

	/**
	 * Replaces `key()`: `( string, Request_Context, Policy_Decision, Compiled_Config ): ?string`.
	 *
	 * @var Closure|null
	 */
	public ?Closure $on_key = null;

	/**
	 * Replaces `response()`: `( Cached_Response, Request_Context, Compiled_Config ): ?Cached_Response`.
	 *
	 * @var Closure|null
	 */
	public ?Closure $on_response = null;

	/**
	 * Replaces `cors()`: `( Request_Context, Compiled_Config ): bool`.
	 *
	 * @var Closure|null
	 */
	public ?Closure $on_cors = null;

	public function decide( Policy_Decision $decision, Request_Context $context, Compiled_Config $config ): Policy_Decision {
		$this->calls[] = 'decide';
		return null === $this->on_decide ? $decision : ( $this->on_decide )( $decision, $context, $config );
	}

	public function key( string $key, Request_Context $context, Policy_Decision $decision, Compiled_Config $config ): ?string {
		$this->calls[] = 'key';
		return null === $this->on_key ? $key : ( $this->on_key )( $key, $context, $decision, $config );
	}

	public function response( Cached_Response $hit, Request_Context $context, Compiled_Config $config ): ?Cached_Response {
		$this->calls[] = 'response';
		return null === $this->on_response ? $hit : ( $this->on_response )( $hit, $context, $config );
	}

	public function cors( Request_Context $context, Compiled_Config $config ): bool {
		$this->calls[] = 'cors';
		return null === $this->on_cors ? $config->cors_on_hits() : ( $this->on_cors )( $context, $config );
	}
}
