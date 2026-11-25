<?php
/**
 * Tests for the shims of the original's endpoint API class and its deprecated global proxy.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Legacy\Shim_Notice;
use Rockfire\Rest_Api_Cache\Names;
use WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api
 * @covers \WP_Rest_Cache_Endpoint_Api
 */
class Test_Shim_Endpoint_Api extends WP_UnitTestCase {

	const OWN = 'rockfire-rest-api-cache/rockfire-rest-api-cache.php';

	const API = 'WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api';

	const PROXY = 'WP_Rest_Cache_Endpoint_Api';

	/**
	 * The public methods of the original (version 2026.3.1) with their parameters, as the shim must declare them.
	 *
	 * @var array<string,array<string,string>>
	 */
	const SIGNATURES = array(
		self::API   => array(
			'get_api_cache'           => '',
			'skip_caching'            => '',
			'save_cache'              => '$result',
			'save_cache_headers'      => '$served, WP_HTTP_Response $result',
			'save_options'            => '',
			'add_wordpress_endpoints' => 'array $allowed_endpoints',
			'determine_object_type'   => '$object_type, $cache_key, $data, $uri',
		),
		self::PROXY => array(
			'__construct' => '',
			'__call'      => '$name, $arguments',
		),
	);

	/**
	 * Arguments of every `doing_it_wrong_run` since set_up().
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private $incorrect = array();

	/**
	 * Names of the options added, updated or deleted since the listeners were attached.
	 *
	 * @var string[]
	 */
	private $option_writes = array();

	public function set_up() {
		parent::set_up();
		update_option( 'active_plugins', array( self::OWN ) );
		Coexistence_Guard::reset();
		Shim_Notice::reset();
		$this->incorrect     = array();
		$this->option_writes = array();

		add_action(
			'doing_it_wrong_run',
			function ( ...$args ) {
				$this->incorrect[] = $args;
			},
			10,
			3
		);

		// The findings store is the one write of the shims (tests/legacy/test-shim-findings.php); any other option is a failure.
		$record = function ( $option ) {
			if ( Names::OPTION_LEGACY_FINDINGS !== $option ) {
				$this->option_writes[] = (string) $option;
			}
		};
		add_action( 'added_option', $record );
		add_action( 'updated_option', $record );
		add_action( 'deleted_option', $record );
	}

	public function tear_down() {
		Coexistence_Guard::reset();
		Shim_Notice::reset();
		parent::tear_down();
	}

	/**
	 * The functions the notices were raised for, in order.
	 *
	 * @return string[]
	 */
	private function noticed(): array {
		return array_map( static fn( array $args ): string => (string) $args[0], $this->incorrect );
	}

	public function test_the_guard_does_not_block_and_the_loader_resolves_the_classes() {
		$this->assertFalse( Coexistence_Guard::is_blocked() );
		$this->assertTrue( class_exists( self::API ) );
		$this->assertTrue( class_exists( self::PROXY ) );
	}

	public function test_get_api_cache_does_nothing_and_does_not_exit() {
		$this->setExpectedIncorrectUsage( self::API . '::get_api_cache' );
		$headers = headers_list();

		ob_start();
		try {
			$result = ( new Endpoint_Api() )->get_api_cache();
		} finally {
			$output = ob_get_clean();
		}

		$this->assertNull( $result );
		$this->assertSame( '', $output );
		$this->assertSame( $headers, headers_list() );
		$this->assertSame( array( self::API . '::get_api_cache' ), $this->noticed() );
		$this->assertSame( array(), $this->option_writes );
	}

	public function test_the_notice_names_the_method_and_points_to_the_readme() {
		$this->setExpectedIncorrectUsage( self::API . '::get_api_cache' );

		( new Endpoint_Api() )->get_api_cache();

		$this->assertCount( 1, $this->incorrect );
		$this->assertSame( self::API . '::get_api_cache', $this->incorrect[0][0] );
		$this->assertStringEndsWith( 'See the migration section of the plugin readme.', $this->incorrect[0][1] );
	}

	public function test_a_method_raises_its_notice_once_per_request() {
		$this->setExpectedIncorrectUsage( self::API . '::skip_caching' );
		$api = new Endpoint_Api();

		$api->skip_caching();
		$api->skip_caching();

		$this->assertSame( array( self::API . '::skip_caching' ), $this->noticed() );
	}

	public function test_skip_caching_returns_true() {
		$this->setExpectedIncorrectUsage( self::API . '::skip_caching' );

		$this->assertTrue( ( new Endpoint_Api() )->skip_caching() );
		$this->assertSame( array(), $this->option_writes );
	}

	public function test_save_cache_returns_its_argument() {
		$this->setExpectedIncorrectUsage( self::API . '::save_cache' );
		$result = array(
			'id'    => 7,
			'title' => 'Sample',
		);

		$this->assertSame( $result, ( new Endpoint_Api() )->save_cache( $result ) );
		$this->assertSame( array(), $this->option_writes );
	}

	public function test_save_cache_headers_returns_the_served_flag() {
		$this->setExpectedIncorrectUsage( self::API . '::save_cache_headers' );
		$api      = new Endpoint_Api();
		$response = new WP_HTTP_Response( array( 'a' => 1 ), 200, array( 'X-Sample' => 'yes' ) );

		$this->assertTrue( $api->save_cache_headers( true, $response ) );
		$this->assertFalse( $api->save_cache_headers( false, $response ) );
		$this->assertSame( array(), $this->option_writes );
	}

	public function test_save_options_returns_null_and_writes_no_option() {
		$this->setExpectedIncorrectUsage( self::API . '::save_options' );

		$this->assertNull( ( new Endpoint_Api() )->save_options() );
		$this->assertSame( array(), $this->option_writes );
		$this->assertSame( 'absent', get_option( 'wp_rest_cache_allowed_endpoints', 'absent' ) );
		$this->assertSame( 'absent', get_option( 'wp_rest_cache_item_allowed_endpoints', 'absent' ) );
		$this->assertSame( 'absent', get_option( 'wp_rest_cache_hit_recording', 'absent' ) );
	}

	public function test_add_wordpress_endpoints_returns_its_input() {
		$this->setExpectedIncorrectUsage( self::API . '::add_wordpress_endpoints' );
		$endpoints = array( 'my/v1' => array( 'items' ) );

		$this->assertSame( $endpoints, ( new Endpoint_Api() )->add_wordpress_endpoints( $endpoints ) );
		$this->assertSame( array(), $this->option_writes );
	}

	public function test_determine_object_type_returns_the_object_type() {
		$this->setExpectedIncorrectUsage( self::API . '::determine_object_type' );
		$api = new Endpoint_Api();

		$this->assertSame( 'unknown', $api->determine_object_type( 'unknown', 'abc', array( 'x' => 1 ), '/wp-json/wp/v2/types' ) );
		$this->assertSame( 'post', $api->determine_object_type( 'post', 'abc', array(), '/wp-json/wp/v2/posts' ) );
		$this->assertSame( array(), $this->option_writes );
	}

	public function test_the_proxy_forwards_to_the_shim_and_ignores_unknown_methods() {
		$this->setExpectedIncorrectUsage( self::API . '::skip_caching' );
		$this->setExpectedIncorrectUsage( self::PROXY . '::no_such_method' );

		$proxy = new WP_Rest_Cache_Endpoint_Api();

		$this->assertTrue( $proxy->skip_caching() );
		$this->assertNull( $proxy->no_such_method( 1 ) );

		$this->assertSame( array( self::API . '::skip_caching', self::PROXY . '::no_such_method' ), $this->noticed() );
		$this->assertCount( 1, array_keys( $this->noticed(), self::PROXY . '::no_such_method', true ) );
		$this->assertSame( array(), $this->option_writes );
	}

	public function test_the_proxy_forwards_the_arguments_and_returns_the_result() {
		$this->setExpectedIncorrectUsage( self::API . '::save_cache' );
		$this->setExpectedIncorrectUsage( self::API . '::add_wordpress_endpoints' );
		$proxy = new WP_Rest_Cache_Endpoint_Api();

		$this->assertSame( array( 'a' => 1 ), $proxy->save_cache( array( 'a' => 1 ) ) );
		$this->assertSame( array( 'wp/v2' => array( 'types' ) ), $proxy->add_wordpress_endpoints( array( 'wp/v2' => array( 'types' ) ) ) );
	}

	public function test_the_proxy_ignores_a_call_to_a_missing_method_without_arguments() {
		$this->setExpectedIncorrectUsage( self::PROXY . '::get_api_cache_x' );
		$proxy = new WP_Rest_Cache_Endpoint_Api();

		$this->assertNull( $proxy->get_api_cache_x() );
	}

	public function test_the_proxy_does_not_throw_on_arguments_that_do_not_fit() {
		$this->setExpectedIncorrectUsage( self::PROXY . '::save_cache' );
		$this->setExpectedIncorrectUsage( self::PROXY . '::save_cache_headers' );
		$this->setExpectedIncorrectUsage( self::PROXY . '::add_wordpress_endpoints' );
		$proxy = new WP_Rest_Cache_Endpoint_Api();

		$this->assertNull( $proxy->save_cache() );
		$this->assertNull( $proxy->save_cache_headers( true, 'not a response' ) );
		$this->assertNull( $proxy->add_wordpress_endpoints( 'not an array' ) );
	}

	public function test_the_proxy_never_calls_a_method_the_shim_does_not_have() {
		$this->setExpectedIncorrectUsage( self::PROXY . '::build_request_uri' );
		$proxy = new WP_Rest_Cache_Endpoint_Api();

		$this->assertNull( $proxy->build_request_uri() );
	}

	/**
	 * Parameters of a method as `type $name` strings, comma separated.
	 *
	 * @param ReflectionMethod $method The method.
	 *
	 * @return string
	 */
	private static function parameters_of( ReflectionMethod $method ): string {
		$parts = array();
		foreach ( $method->getParameters() as $parameter ) {
			$type    = $parameter->getType();
			$parts[] = ( null === $type ? '' : $type . ' ' )
				. ( $parameter->isPassedByReference() ? '&' : '' )
				. ( $parameter->isVariadic() ? '...' : '' )
				. '$' . $parameter->getName()
				. ( $parameter->isOptional() && ! $parameter->isVariadic() ? ' = ' : '' );
		}
		return implode( ', ', $parts );
	}

	/**
	 * The two classes with their expected methods.
	 *
	 * @return array<string,array{0:string,1:array<string,string>}>
	 */
	public static function classes(): array {
		$rows = array();
		foreach ( self::SIGNATURES as $class => $signatures ) {
			$rows[ $class ] = array( $class, $signatures );
		}
		return $rows;
	}

	/**
	 * @dataProvider classes
	 *
	 * @param string                $class      Class name.
	 * @param array<string,string> $signatures Expected public methods with their parameters.
	 */
	public function test_the_public_methods_match_the_original( string $class, array $signatures ) {
		$reflection = new ReflectionClass( $class );
		$actual     = array();
		foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			$this->assertFalse( $method->isStatic(), $method->getName() . ' is not static.' );
			$this->assertFalse( $method->hasReturnType(), $method->getName() . ' has no native return type.' );
			$actual[ $method->getName() ] = self::parameters_of( $method );
		}

		ksort( $actual );
		ksort( $signatures );

		$this->assertSame( $signatures, $actual );
	}

	public function test_the_endpoint_api_shim_has_no_constructor_and_no_other_methods() {
		$reflection = new ReflectionClass( self::API );

		$this->assertNull( $reflection->getConstructor() );
		$this->assertCount( count( self::SIGNATURES[ self::API ] ), $reflection->getMethods() );
		$this->assertSame( array(), $reflection->getProperties() );
	}

	public function test_the_shim_files_have_no_gettext_calls() {
		$files = array(
			RFRAC_DIR . 'includes/legacy/shims/api/class-endpoint-api.php',
			RFRAC_DIR . 'includes/legacy/shims/class-wp-rest-cache-endpoint-api.php',
		);

		foreach ( $files as $file ) {
			$this->assertFileExists( $file );
			$this->assertDoesNotMatchRegularExpression(
				'/\b(?:__|_e|_x|_ex|_n|_nx|esc_html__|esc_html_e|esc_html_x|esc_attr__|esc_attr_e|esc_attr_x)\s*\(/',
				(string) file_get_contents( $file ),
				basename( $file )
			);
		}
	}
}
