<?php
/**
 * Shared fixtures for the Lookup, Early_Server and Fallback_Server tests.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Request\Cache_Key;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Schema\Schema;
use Rockfire\Rest_Api_Cache\Serving\Response_Writer;

/**
 * Simulates a request through the superglobals and primes a matching cache entry.
 */
abstract class Rfrac_Serving_Test_Case extends Rfrac_Policy_Test_Case {

	/**
	 * Emitted header lines.
	 *
	 * @var string[]
	 */
	protected array $headers = array();

	/**
	 * Output.
	 *
	 * @var string
	 */
	protected string $output = '';

	/**
	 * Saved superglobals.
	 *
	 * @var array<string,mixed>
	 */
	private array $saved_globals = array();

	public function set_up() {
		parent::set_up();
		$this->saved_globals = array(
			'server' => $_SERVER,
			'get'    => $_GET,
			'cookie' => $_COOKIE,
		);
		$this->headers       = array();
		$this->output        = '';
		Coexistence_Guard::reset();
		update_option( 'permalink_structure', '/%postname%/' );
		$this->set_compiled_config( $this->snapshot() );
	}

	public function tear_down() {
		$_SERVER = $this->saved_globals['server'];
		$_GET    = $this->saved_globals['get'];
		$_COOKIE = $this->saved_globals['cookie'];
		Coexistence_Guard::reset();
		parent::tear_down();
	}

	/**
	 * Compiled config fields: current schema version and `/wp/v2/posts` enabled.
	 *
	 * @return array<string,mixed>
	 */
	protected function snapshot(): array {
		return array_merge( $this->config()->to_array(), array( 'schema_version' => Schema::VERSION ) );
	}

	/**
	 * A writer capturing headers and output whose terminate closure throws {@see Rfrac_Test_Exit}.
	 *
	 * @return Response_Writer
	 */
	protected function capturing_writer(): Response_Writer {
		return new Response_Writer(
			function ( string $line, bool $replace = true ): void {
				$this->headers[] = $line;
			},
			function (): never {
				throw new Rfrac_Test_Exit( 'exit' );
			},
			function ( string $out ): void {
				$this->output .= $out;
			},
			static function ( int $code ): void {}
		);
	}

	/**
	 * Point the superglobals at a GET request.
	 *
	 * @param string              $uri    Request URI.
	 * @param array<string,mixed> $server Extra `$_SERVER` entries.
	 * @param array<string,mixed> $cookie Cookies.
	 *
	 * @return void
	 */
	protected function simulate( string $uri, array $server = array(), array $cookie = array() ): void {
		$_SERVER = array_merge( $_SERVER, array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri ), $server ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$_GET    = array();
		parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $_GET );
		$_COOKIE = $cookie;
	}

	/**
	 * Insert an active entry whose key matches the simulated request.
	 *
	 * @param string $payload Payload.
	 *
	 * @return int Entry id.
	 */
	protected function prime( string $payload = '{"primed":true}' ): int {
		$config   = Compiled_Config::load();
		$this->assertNotNull( $config );
		$context  = Request_Context::from_globals( $config->rest_prefix(), $config->vary_cookie_names(), $config->request_header_names() );
		$decision = $this->policy()->evaluate( $context, $config );
		$this->assertCacheable( $decision );

		return Rfrac_Entry_Fixtures::insert_entry(
			array(
				'cache_key' => Cache_Key::key( $context, $decision ),
				'payload'   => $payload,
				'size'      => strlen( $payload ),
			)
		);
	}

	/**
	 * Make the coexistence guard report the original plugin as active.
	 *
	 * @return void
	 */
	protected function block_by_coexistence(): void {
		update_option( 'active_plugins', array( 'wp-rest-cache/wp-rest-cache.php' ) );
		Coexistence_Guard::reset();
	}
}
