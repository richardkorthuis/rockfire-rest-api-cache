<?php
/**
 * Tests for the response writer.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Compiled_Route;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Request\Policy_Decision;
use Rockfire\Rest_Api_Cache\Request\Request_Context;
use Rockfire\Rest_Api_Cache\Serving\Response_Writer;
use Rockfire\Rest_Api_Cache\Storage\Cached_Response;

/**
 * @covers \Rockfire\Rest_Api_Cache\Serving\Response_Writer
 */
class Test_Response_Writer extends Rfrac_Test_Case {

	/**
	 * Emitted header lines.
	 *
	 * @var string[]
	 */
	private array $headers = array();

	/**
	 * Output.
	 *
	 * @var string
	 */
	private string $output = '';

	/**
	 * Status codes set.
	 *
	 * @var int[]
	 */
	private array $statuses = array();

	/**
	 * Whether terminate ran.
	 *
	 * @var bool
	 */
	private bool $terminated = false;

	/**
	 * Run send_hit with capturing closures.
	 *
	 * @param array<string,string> $stored  Stored headers.
	 * @param string|null          $origin  Request origin.
	 * @param array<string,mixed>  $config  Config overrides.
	 * @param string               $payload Payload.
	 * @param Policy_Decision|null $decision Decision of the lookup.
	 *
	 * @return void
	 */
	private function send( array $stored, ?string $origin = null, array $config = array(), string $payload = '{"a":1}', ?Policy_Decision $decision = null ): void {
		$this->headers    = array();
		$this->output     = '';
		$this->statuses   = array();
		$this->terminated = false;

		$writer = new Response_Writer(
			function ( string $line, bool $replace = true ): void {
				$this->headers[] = $line;
			},
			function (): never {
				$this->terminated = true;
				throw new RuntimeException( 'terminated' );
			},
			function ( string $out ): void {
				$this->output .= $out;
			},
			function ( int $code ): void {
				$this->statuses[] = $code;
			}
		);

		$server = array( 'REQUEST_URI' => '/wp-json/wp/v2/posts' );
		if ( null !== $origin ) {
			$server['HTTP_ORIGIN'] = $origin;
		}
		$context = Request_Context::from_parts( $server, array(), array(), array(), 'wp-json', '', true, 1, false );
		$cfg     = Compiled_Config::from_array( array_merge( Compiled_Config::defaults(), array( 'replay_headers' => array( 'x-wp-total', 'link' ) ), $config ) );
		$hit     = new Cached_Response( 1, 'k', $payload, $stored, 100, 200 );

		try {
			$writer->send_hit( $hit, $context, $cfg, $decision );
			$this->fail( 'send_hit returned' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'terminated', $e->getMessage() );
		}
	}

	public function test_only_allow_listed_headers_are_replayed(): void {
		$this->send(
			array(
				'Set-Cookie'    => 'a=b',
				'Cache-Control' => 'private',
				'X-WP-Total'    => '3',
			)
		);
		$this->assertContains( 'X-WP-Total: 3', $this->headers );
		$this->assertNotContains( 'Set-Cookie: a=b', $this->headers );
		$this->assertNotContains( 'Cache-Control: private', $this->headers );
	}

	public function test_fixed_headers_status_and_order(): void {
		$this->send( array( 'X-WP-Total' => '3' ) );
		$this->assertSame( array( 200 ), $this->statuses );
		$this->assertSame(
			array(
				'Content-Type: application/json; charset=UTF-8',
				'X-Content-Type-Options: nosniff',
				'X-Robots-Tag: noindex',
				'X-WP-Total: 3',
				'Vary: Origin',
				'X-Rfrac-Cache: HIT',
			),
			$this->headers
		);
		$this->assertTrue( $this->terminated );
	}

	public function test_expose_and_allow_headers_are_replayed(): void {
		$this->send(
			array(
				'Access-Control-Expose-Headers' => 'X-WP-Total',
				'access-control-allow-headers'  => 'Authorization',
				'X-Other'                       => 'no',
			)
		);
		$this->assertContains( 'Access-Control-Expose-Headers: X-WP-Total', $this->headers );
		$this->assertContains( 'access-control-allow-headers: Authorization', $this->headers );
		$this->assertNotContains( 'X-Other: no', $this->headers );
	}

	public function test_forbidden_header_in_corrupt_replay_list_is_never_emitted(): void {
		$all = array( 'set-cookie', 'cache-control', 'authorization', 'www-authenticate', 'vary', 'access-control-allow-origin', 'access-control-allow-credentials', 'content-length', 'etag', 'x-wp-total' );
		$this->send(
			array(
				'Set-Cookie'                       => 'a=b',
				'Cache-Control'                    => 'private',
				'Authorization'                    => 'Bearer x',
				'WWW-Authenticate'                 => 'Basic',
				'Vary'                             => 'Cookie',
				'Access-Control-Allow-Origin'      => 'https://evil.test',
				'Access-Control-Allow-Credentials' => 'true',
				'Content-Length'                   => '1',
				'ETag'                             => 'x',
				'X-WP-Total'                       => '3',
			),
			null,
			array( 'replay_headers' => $all )
		);
		$this->assertSame(
			array(
				'Content-Type: application/json; charset=UTF-8',
				'X-Content-Type-Options: nosniff',
				'X-Robots-Tag: noindex',
				'X-WP-Total: 3',
				'Vary: Origin',
				'X-Rfrac-Cache: HIT',
			),
			$this->headers
		);
	}

	public function test_cr_lf_nul_are_stripped_from_values(): void {
		$this->send( array( 'X-WP-Total' => "x\r\nSet-Cookie: y\0" ) );
		$this->assertContains( 'X-WP-Total: xSet-Cookie: y', $this->headers );
		foreach ( $this->headers as $line ) {
			$this->assertDoesNotMatchRegularExpression( '/[\r\n\0]/', $line );
		}
	}

	public function test_values_are_capped_at_8kb(): void {
		$this->send( array( 'Link' => str_repeat( 'a', 9000 ) ) );
		$this->assertContains( 'Link: ' . str_repeat( 'a', 8192 ), $this->headers );
	}

	public function test_invalid_header_names_are_skipped(): void {
		$this->send( array( "X Bad\r\nName" => 'v' ), null, array( 'replay_headers' => array( "x bad\r\nname" ) ) );
		$this->assertCount( 5, $this->headers );
	}

	public function test_header_name_with_trailing_newline_is_skipped(): void {
		$this->send( array( "X-Bad\n" => 'v' ), null, array( 'replay_headers' => array( "x-bad\n" ) ) );
		$this->assertCount( 5, $this->headers );
	}

	public function test_allow_listed_origin_is_reflected_with_credentials(): void {
		$home = home_url();
		$this->send( array(), $home, array( 'cors_origins' => array( $home ) ) );
		$this->assertContains( 'Access-Control-Allow-Origin: ' . $home, $this->headers );
		$this->assertContains( 'Access-Control-Allow-Methods: OPTIONS, GET, POST, PUT, PATCH, DELETE', $this->headers );
		$this->assertContains( 'Access-Control-Allow-Credentials: true', $this->headers );
		$this->assertContains( 'Vary: Origin', $this->headers );
	}

	public function test_other_origin_with_wildcard_gets_star_without_credentials(): void {
		$this->send(
			array(),
			'https://evil.test',
			array(
				'cors_origins'  => array( 'https://ok.test' ),
				'cors_wildcard' => true,
			)
		);
		$this->assertContains( 'Access-Control-Allow-Origin: *', $this->headers );
		$this->assertContains( 'Vary: Origin', $this->headers );
		$this->assertNotContains( 'Access-Control-Allow-Credentials: true', $this->headers );
	}

	public function test_other_origin_without_wildcard_gets_only_vary(): void {
		$this->send( array(), 'https://evil.test', array( 'cors_origins' => array( 'https://ok.test' ) ) );
		foreach ( $this->headers as $line ) {
			$this->assertStringStartsNotWith( 'Access-Control-Allow', $line );
		}
		$this->assertContains( 'Vary: Origin', $this->headers );
	}

	public function test_no_origin_gets_only_vary(): void {
		$this->send(
			array(),
			null,
			array(
				'cors_wildcard' => true,
				'cors_origins'  => array( 'https://ok.test' ),
			)
		);
		foreach ( $this->headers as $line ) {
			$this->assertStringStartsNotWith( 'Access-Control-Allow', $line );
		}
		$this->assertContains( 'Vary: Origin', $this->headers );
	}

	public function test_cors_off_on_hits_sends_no_allow_headers_for_an_allow_listed_origin(): void {
		$home = home_url();
		$this->send( array(), $home, array( 'cors_origins' => array( $home ), 'cors_on_hits' => false ) );

		foreach ( $this->headers as $line ) {
			$this->assertStringStartsNotWith( 'Access-Control-Allow', $line );
		}
		$this->assertContains( 'Vary: Origin', $this->headers );
		$this->assertSame( 'X-Rfrac-Cache: HIT', end( $this->headers ) );
	}

	public function test_cors_off_on_hits_sends_no_wildcard_for_another_origin(): void {
		$this->send(
			array(),
			'https://evil.test',
			array(
				'cors_origins'  => array( 'https://ok.test' ),
				'cors_wildcard' => true,
				'cors_on_hits'  => false,
			)
		);

		foreach ( $this->headers as $line ) {
			$this->assertStringStartsNotWith( 'Access-Control-Allow', $line );
		}
		$this->assertContains( 'Vary: Origin', $this->headers );
	}

	public function test_cors_off_on_hits_keeps_the_replayed_expose_headers_and_the_rule_vary(): void {
		$rule     = new Compiled_Route( '/wp/v2/posts', '@^/wp/v2/posts$@i', 60, 'registered', array(), array(), array(), array( 'accept-language' ) );
		$decision = Policy_Decision::cache( $rule, array(), array() );
		$this->send(
			array( 'Access-Control-Expose-Headers' => 'X-WP-Total' ),
			'https://ok.test',
			array( 'cors_origins' => array( 'https://ok.test' ), 'cors_on_hits' => false ),
			'{"a":1}',
			$decision
		);

		$this->assertContains( 'Access-Control-Expose-Headers: X-WP-Total', $this->headers );
		$this->assertContains( 'Vary: Origin, accept-language', $this->headers );
		$this->assertNotContains( 'Access-Control-Allow-Origin: https://ok.test', $this->headers );
	}

	public function test_cors_on_hits_stays_on_by_default(): void {
		$this->send( array(), 'https://ok.test', array( 'cors_origins' => array( 'https://ok.test' ), 'cors_on_hits' => true ) );
		$this->assertContains( 'Access-Control-Allow-Origin: https://ok.test', $this->headers );
	}

	public function test_payload_is_output_unchanged(): void {
		$this->send( array(), null, array(), '{"a":"é"}' );
		$this->assertSame( '{"a":"é"}', $this->output );
	}

	public function test_hit_marker_is_last_header(): void {
		$this->send( array( 'X-WP-Total' => '1' ), 'https://ok.test', array( 'cors_origins' => array( 'https://ok.test' ) ) );
		$this->assertSame( 'X-Rfrac-Cache: HIT', end( $this->headers ) );
	}

	public function test_returning_terminate_closure_throws(): void {
		$noop    = static function (): void {};
		$writer  = new Response_Writer( $noop, $noop, $noop, $noop );
		$context = Request_Context::from_parts( array(), array(), array(), array(), 'wp-json', '', true, 1, false );
		$this->expectException( LogicException::class );
		$writer->send_hit( new Cached_Response( 1, 'k', '{}', array(), 1, 2 ), $context, Compiled_Config::from_array( Compiled_Config::defaults() ) );
	}

	public function test_rule_vary_headers_are_advertised(): void {
		$rule = new Compiled_Route( '/wp/v2/posts', '@^/wp/v2/posts$@i', 60, 'registered', array(), array(), array(), array( 'accept-language', 'origin', 'x-region' ) );
		$this->send( array(), null, array(), '{}', Policy_Decision::cache( $rule, array(), array() ) );

		$this->assertContains( 'Vary: Origin, accept-language, x-region', $this->headers );
		$this->assertSame( 'Origin', Response_Writer::vary_value( null ) );
	}

	public function test_vary_cookies_are_not_advertised_in_the_vary_header(): void {
		$rule = new Compiled_Route(
			'/wp/v2/posts',
			'@^/wp/v2/posts$@i',
			60,
			'registered',
			array(),
			array(),
			array(),
			array( 'accept-language' ),
			true,
			false,
			array( 'cur' => array( 'EUR', 'USD' ) )
		);
		$this->assertSame( 'Origin, accept-language', Response_Writer::vary_value( $rule ), 'Only the header is listed; the cookie varies the key, not the Vary header.' );

		$this->send( array(), null, array(), '{}', Policy_Decision::cache( $rule, array(), array( 'accept-language' => 'nl', 'cookie:cur' => 'EUR' ) ) );
		$this->assertContains( 'Vary: Origin, accept-language', $this->headers );
	}

	public function test_failure_while_emitting_still_terminates_and_outputs_nothing_more(): void {
		$terminated = false;
		$output     = '';
		$writer     = new Response_Writer(
			static function ( string $line ): void {
				if ( str_starts_with( $line, 'X-Robots-Tag' ) ) {
					throw new RuntimeException( 'header failed' );
				}
			},
			static function () use ( &$terminated ): never {
				$terminated = true;
				throw new Rfrac_Test_Exit( 'exit' );
			},
			static function ( string $out ) use ( &$output ): void {
				$output .= $out;
			},
			static function ( int $code ): void {}
		);
		$context = Request_Context::from_parts( array(), array(), array(), array(), 'wp-json', '', true, 1, false );

		try {
			$writer->send_hit( new Cached_Response( 1, 'k', '{}', array(), 1, 2 ), $context, Compiled_Config::from_array( Compiled_Config::defaults() ) );
			$this->fail( 'send_hit returned' );
		} catch ( Rfrac_Test_Exit $e ) {
			$this->assertTrue( $terminated, 'Once emitting started the request always ends.' );
			$this->assertSame( '', $output );
		}
	}

	public function test_failure_before_emitting_leaves_the_request_untouched(): void {
		$touched = false;
		$mark    = static function () use ( &$touched ): void {
			$touched = true;
		};
		$writer  = new Response_Writer( $mark, $mark, $mark, $mark );
		$context = Request_Context::from_parts( array(), array(), array(), array(), 'wp-json', '', true, 1, false );
		// A stored header value that cannot be converted to a string fails while building.
		$hit = new Cached_Response( 1, 'k', '{}', array( 'X-WP-Total' => array( 'not', 'a', 'string' ) ), 1, 2 );
		$cfg = Compiled_Config::from_array( array_merge( Compiled_Config::defaults(), array( 'replay_headers' => array( 'x-wp-total' ) ) ) );

		set_error_handler(
			static function ( int $errno, string $errstr ): never {
				throw new ErrorException( $errstr, 0, $errno );
			}
		);
		try {
			$writer->send_hit( $hit, $context, $cfg );
			$this->fail( 'send_hit returned' );
		} catch ( ErrorException $e ) {
			$this->assertFalse( $touched, 'Nothing was emitted and the request was not ended.' );
		} finally {
			restore_error_handler();
		}
	}

	public function test_bypass_headers(): void {
		$this->assertSame( array( 'X-Rfrac-Cache' => 'BYPASS' ), Response_Writer::bypass_headers( Bypass_Reason::CREDENTIALS, false ) );
		$this->assertSame(
			array(
				'X-Rfrac-Cache'        => 'BYPASS',
				'X-Rfrac-Cache-Reason' => 'credentials',
			),
			Response_Writer::bypass_headers( Bypass_Reason::CREDENTIALS, true )
		);
	}
}
