<?php
/**
 * Shared fixtures for the cache policy and cache key tests.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Config\Deny_List;
use Rockfire\Rest_Api_Cache\Request\Cache_Policy;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;

/**
 * Builds compiled configs and request contexts without global state.
 */
abstract class Rfrac_Policy_Test_Case extends Rfrac_Test_Case {

	/**
	 * A registered route rule as stored in the compiled config.
	 *
	 * @param string              $key       WordPress route regex, e.g. `/wp/v2/posts`.
	 * @param array<string,mixed> $overrides Route fields.
	 *
	 * @return array<string,mixed>
	 */
	protected function route( string $key, array $overrides = array() ): array {
		return array_merge(
			array(
				'key'            => $key,
				'regex'          => '@^' . $key . '$@i',
				'ttl'            => 3600,
				'param_mode'     => 'registered',
				'allowed_params' => array( 'context', 'page', 'per_page', 'search', 'status' ),
				'ignore_params'  => array(),
				'bypass_params'  => array(),
				'vary_headers'   => array(),
			),
			$overrides
		);
	}

	/**
	 * A wildcard route rule as stored in the compiled config.
	 *
	 * @param string              $key       Wildcard key, e.g. `/myns/v1/*`.
	 * @param array<string,mixed> $overrides Route fields.
	 *
	 * @return array<string,mixed>
	 */
	protected function wildcard( string $key, array $overrides = array() ): array {
		return $this->route( $key, array_merge( array( 'regex' => Deny_List::wildcard_regex( $key ) ), $overrides ) );
	}

	/**
	 * A compiled config with the compiler's default cookie prefixes and universal params.
	 *
	 * @param array<int,array<string,mixed>>|null $routes    Routes (default: `/wp/v2/posts`).
	 * @param array<string,mixed>                 $overrides Top-level fields.
	 *
	 * @return Compiled_Config
	 */
	protected function config( ?array $routes = null, array $overrides = array() ): Compiled_Config {
		return Compiled_Config::from_array(
			array_merge(
				Compiled_Config::defaults(),
				array(
					'universal_params'       => Config_Compiler::UNIVERSAL_PARAMS,
					'bypass_cookie_prefixes' => Config_Compiler::BYPASS_COOKIE_PREFIXES,
					'bypass_headers'         => Config_Compiler::BYPASS_HEADERS,
					'routes'                 => $routes ?? array( $this->route( '/wp/v2/posts' ) ),
				),
				$overrides
			)
		);
	}

	/**
	 * A request context for a URI.
	 *
	 * @param string              $uri  Request URI, e.g. `/wp-json/wp/v2/posts?page=2`.
	 * @param array<string,mixed> $args server, post, cookie, vary_cookie_names, blog_id, ssl, pretty, body (a string, or a reader closure).
	 *
	 * @return Request_Context
	 */
	protected function request( string $uri, array $args = array() ): Request_Context {
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		$get   = array();
		parse_str( $query, $get );

		return Request_Context::from_parts(
			array_merge(
				array(
					'REQUEST_METHOD' => 'GET',
					'REQUEST_URI'    => $uri,
				),
				$args['server'] ?? array()
			),
			$get,
			$args['post'] ?? array(),
			$args['cookie'] ?? array(),
			'wp-json',
			'',
			$args['pretty'] ?? true,
			$args['blog_id'] ?? 1,
			$args['ssl'] ?? false,
			$args['vary_cookie_names'] ?? array(),
			$args['body'] ?? null
		);
	}

	/**
	 * A policy with a fixed coexistence result.
	 *
	 * @param bool $blocked Whether the coexistence guard blocks.
	 *
	 * @return Cache_Policy
	 */
	protected function policy( bool $blocked = false ): Cache_Policy {
		return new Cache_Policy( static fn(): bool => $blocked );
	}

	/**
	 * Evaluate a URI.
	 *
	 * @param string               $uri    Request URI.
	 * @param Compiled_Config|null $config Config (default: {@see self::config()}).
	 * @param array<string,mixed>  $args   Request arguments.
	 *
	 * @return Policy_Decision
	 */
	protected function evaluate( string $uri, ?Compiled_Config $config = null, array $args = array() ): Policy_Decision {
		return $this->policy()->evaluate( $this->request( $uri, $args ), $config ?? $this->config() );
	}

	/**
	 * Assert a bypass with a reason.
	 *
	 * @param string          $reason   Expected reason value.
	 * @param Policy_Decision $decision Decision.
	 */
	protected function assertBypass( string $reason, Policy_Decision $decision ): void {
		$this->assertFalse( $decision->cacheable, 'Expected a bypass: ' . $reason );
		$this->assertNotNull( $decision->reason );
		$this->assertSame( $reason, $decision->reason->value );
	}

	/**
	 * Assert a cacheable decision.
	 *
	 * @param Policy_Decision $decision Decision.
	 */
	protected function assertCacheable( Policy_Decision $decision ): void {
		$this->assertTrue( $decision->cacheable, 'Expected cacheable, got ' . ( $decision->reason->value ?? '' ) );
		$this->assertNull( $decision->reason );
		$this->assertNotNull( $decision->rule );
	}
}
