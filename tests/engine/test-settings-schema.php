<?php
/**
 * Tests for the settings schema and the canonical route rule.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use PHPUnit\Framework\TestCase;
use Rockfire\Rest_Api_Cache\Config\Route_Rule;
use Rockfire\Rest_Api_Cache\Config\Settings_Schema;
use Rockfire\Rest_Api_Cache\Config\Validation_Result;

/**
 * @covers \Rockfire\Rest_Api_Cache\Config\Settings_Schema
 * @covers \Rockfire\Rest_Api_Cache\Config\Validation_Result
 */
class Test_Settings_Schema extends TestCase {

	public function test_defaults(): void {
		$this->assertSame(
			array(
				'enabled'              => true,
				'store'                => 'auto',
				'default_ttl'          => 86400,
				'max_ttl'              => 2592000,
				'max_entries'          => 10000,
				'max_payload_kb'       => 1024,
				'max_params'           => 20,
				'hit_sampling'         => 10,
				'global_ignore_params' => array(),
				'global_vary_headers'  => array(),
				'nonce_caching'        => false,
			),
			Settings_Schema::defaults()
		);
	}

	public function test_nonce_caching_is_an_off_by_default_bool_with_its_constant(): void {
		$definition = Settings_Schema::definitions()['nonce_caching'];

		$this->assertSame( 'bool', $definition['type'] );
		$this->assertFalse( $definition['default'] );
		$this->assertSame( 'RFRAC_NONCE_CACHING', $definition['constant'] );
		$this->assertSame( 'RFRAC_NONCE_CACHING', Settings_Schema::constant_names()['nonce_caching'] );
		$this->assertTrue( Settings_Schema::normalize_value( 'nonce_caching', 'yes', 0 ) );
		$this->assertFalse( Settings_Schema::normalize_value( 'nonce_caching', '0', 0 ) );
		$this->assertNull( Settings_Schema::normalize_value( 'nonce_caching', 'maybe', 0 ) );
	}

	public function test_the_list_settings_are_described_with_their_item_limit_and_constant(): void {
		$definitions = Settings_Schema::definitions();

		$this->assertSame( 'list', $definitions['global_ignore_params']['type'] );
		$this->assertSame( 'param', $definitions['global_ignore_params']['item'] );
		$this->assertSame( Route_Rule::MAX_PARAM_NAMES, $definitions['global_ignore_params']['max_items'] );
		$this->assertSame( 'RFRAC_GLOBAL_IGNORE_PARAMS', $definitions['global_ignore_params']['constant'] );
		$this->assertSame( 'list', $definitions['global_vary_headers']['type'] );
		$this->assertSame( 'header', $definitions['global_vary_headers']['item'] );
		$this->assertSame( Route_Rule::MAX_VARY_HEADERS, $definitions['global_vary_headers']['max_items'] );
		$this->assertSame( 'RFRAC_GLOBAL_VARY_HEADERS', $definitions['global_vary_headers']['constant'] );
		$this->assertSame( 'RFRAC_GLOBAL_VARY_HEADERS', Settings_Schema::constant_names()['global_vary_headers'] );
	}

	/**
	 * @return array<string,array{0:string,1:mixed,2:list<string>}>
	 */
	public static function valid_list_provider(): array {
		return array(
			'string split on commas, spaces and lines' => array( 'global_ignore_params', "utm_source, fbclid\nutm_source", array( 'utm_source', 'fbclid' ) ),
			'array form is the same'                   => array( 'global_ignore_params', array( 'utm_source', 'fbclid', 'utm_source' ), array( 'utm_source', 'fbclid' ) ),
			'entries are trimmed'                      => array( 'global_ignore_params', array( ' a ', "\tb\n", '' ), array( 'a', 'b' ) ),
			'param names keep their case'              => array( 'global_ignore_params', 'UTM_Source filter[a].b-c', array( 'UTM_Source', 'filter[a].b-c' ) ),
			'empty string is an empty list'            => array( 'global_ignore_params', '', array() ),
			'blank string is an empty list'            => array( 'global_ignore_params', " \r\n, ,", array() ),
			'empty array is an empty list'             => array( 'global_vary_headers', array(), array() ),
			'header lowercased with dashes'            => array( 'global_vary_headers', array( 'Accept_Language' ), array( 'accept-language' ) ),
			'header duplicates after normalising'      => array( 'global_vary_headers', 'Accept-Language, accept_language', array( 'accept-language' ) ),
			'exactly the maximum of params'            => array( 'global_ignore_params', array_map( static fn( $i ) => 'p' . $i, range( 1, 20 ) ), array_map( static fn( $i ) => 'p' . $i, range( 1, 20 ) ) ),
			'exactly the maximum of headers'           => array( 'global_vary_headers', array( 'a', 'b', 'c', 'd', 'e' ), array( 'a', 'b', 'c', 'd', 'e' ) ),
			'duplicates do not count towards the max'  => array( 'global_vary_headers', array( 'a', 'b', 'c', 'd', 'e', 'a' ), array( 'a', 'b', 'c', 'd', 'e' ) ),
		);
	}

	/**
	 * @dataProvider valid_list_provider
	 *
	 * @param string       $key      Key.
	 * @param mixed        $value    Input.
	 * @param list<string> $expected Expected list.
	 */
	public function test_normalize_value_of_a_list( string $key, $value, array $expected ): void {
		$this->assertSame( $expected, Settings_Schema::normalize_value( $key, $value, 2592000 ) );
		$this->assertNull( Settings_Schema::list_error( $key, $value ) );
	}

	/**
	 * @return array<string,array{0:string,1:mixed,2:string}>
	 */
	public static function invalid_list_provider(): array {
		return array(
			'param name with a bad character'  => array( 'global_ignore_params', array( 'ok', 'bad name' ), 'Invalid parameter name "bad name"' ),
			'param name too long'              => array( 'global_ignore_params', array( str_repeat( 'a', 65 ) ), 'Invalid parameter name' ),
			'param name with a symbol'         => array( 'global_ignore_params', 'a,b$c', 'Invalid parameter name "b$c"' ),
			'too many params'                  => array( 'global_ignore_params', array_map( static fn( $i ) => 'p' . $i, range( 1, 21 ) ), 'At most 20 entries' ),
			'a non-string entry'               => array( 'global_ignore_params', array( 'a', 5 ), 'must be a string' ),
			'a nested array entry'             => array( 'global_ignore_params', array( array( 'a' ) ), 'must be a string' ),
			'a number'                         => array( 'global_ignore_params', 5, 'Expected an array of strings' ),
			'null'                             => array( 'global_ignore_params', null, 'Expected an array of strings' ),
			'a bool'                           => array( 'global_vary_headers', true, 'Expected an array of strings' ),
			'forbidden cookie'                 => array( 'global_vary_headers', array( 'cookie' ), 'The header "cookie" can not be used' ),
			'forbidden authorization'          => array( 'global_vary_headers', 'Authorization', 'The header "authorization" can not be used' ),
			'forbidden nonce with underscores' => array( 'global_vary_headers', array( 'X_WP_Nonce' ), 'The header "x-wp-nonce" can not be used' ),
			'bad header name'                  => array( 'global_vary_headers', array( 'bad header' ), 'Invalid header name "bad header"' ),
			'bad header character'             => array( 'global_vary_headers', array( 'accept.language' ), 'Invalid header name "accept.language"' ),
			'six headers'                      => array( 'global_vary_headers', array( 'a', 'b', 'c', 'd', 'e', 'f' ), 'At most 5 entries' ),
			'one bad entry rejects the list'   => array( 'global_vary_headers', array( 'accept-language', 'cookie' ), 'can not be used' ),
		);
	}

	/**
	 * @dataProvider invalid_list_provider
	 *
	 * @param string $key      Key.
	 * @param mixed  $value    Input.
	 * @param string $expected Part of the reason.
	 */
	public function test_an_invalid_list_is_null_and_list_error_names_the_cause( string $key, $value, string $expected ): void {
		$this->assertNull( Settings_Schema::normalize_value( $key, $value, 2592000 ) );
		$this->assertStringContainsString( $expected, (string) Settings_Schema::list_error( $key, $value ) );
	}

	public function test_list_error_is_null_for_a_setting_that_is_not_a_list(): void {
		$this->assertNull( Settings_Schema::list_error( 'max_params', 'abc' ) );
		$this->assertNull( Settings_Schema::list_error( 'nope', 'abc' ) );
	}

	public function test_list_error_shortens_and_cleans_a_long_entry(): void {
		$error = (string) Settings_Schema::list_error( 'global_ignore_params', array( str_repeat( 'x', 500 ) . "\n" . 'y' ) );

		$this->assertLessThan( 100, strlen( $error ) );
		$this->assertStringContainsString( '...', $error );
		$this->assertStringNotContainsString( "\n", $error );
	}

	public function test_apply_keeps_the_old_list_and_reports_the_reason_for_an_invalid_one(): void {
		$base   = array_merge( Settings_Schema::defaults(), array( 'global_vary_headers' => array( 'accept-language' ) ) );
		$result = new Validation_Result();
		$out    = Settings_Schema::apply(
			array(
				'global_ignore_params' => 'utm_source fbclid',
				'global_vary_headers'  => array( 'cookie' ),
			),
			$base,
			$result
		);

		$this->assertSame( array( 'utm_source', 'fbclid' ), $out['global_ignore_params'] );
		$this->assertSame( array( 'accept-language' ), $out['global_vary_headers'] );
		$this->assertTrue( $result->has_code( 'invalid_value' ) );
		$this->assertSame( 'global_vary_headers', $result->errors()[0]['field'] );
		$this->assertStringContainsString( 'can not be used', $result->errors()[0]['message'] );
	}

	public function test_resolve_keeps_a_valid_stored_list_and_ignores_an_invalid_one(): void {
		$resolved = Settings_Schema::resolve(
			array(
				'global_ignore_params' => array( 'utm_source', 'bad name' ),
				'global_vary_headers'  => 'Accept-Language',
			)
		);

		$this->assertSame( array(), $resolved['global_ignore_params'] );
		$this->assertSame( array( 'accept-language' ), $resolved['global_vary_headers'] );
	}

	/**
	 * @return array<string,array{0:string,1:mixed,2:mixed}>
	 */
	public static function clamp_provider(): array {
		return array(
			'max_params high'     => array( 'max_params', 500, 100 ),
			'max_params low'      => array( 'max_params', 0, 1 ),
			'default_ttl low'     => array( 'default_ttl', 10, 60 ),
			'default_ttl > max'   => array( 'default_ttl', 99999999, 2592000 ),
			'max_ttl high'        => array( 'max_ttl', 99999999, 31536000 ),
			'max_entries low'     => array( 'max_entries', 1, 100 ),
			'max_payload_kb high' => array( 'max_payload_kb', 9000, 8192 ),
			'hit_sampling off'    => array( 'hit_sampling', 0, 0 ),
			'hit_sampling high'   => array( 'hit_sampling', 5000, 1000 ),
			'numeric string'      => array( 'max_params', '30', 30 ),
		);
	}

	/**
	 * @dataProvider clamp_provider
	 *
	 * @param string $key      Key.
	 * @param mixed  $value    Input.
	 * @param mixed  $expected Expected.
	 */
	public function test_normalize_value_clamps( string $key, $value, $expected ): void {
		$this->assertSame( $expected, Settings_Schema::normalize_value( $key, $value, 2592000 ) );
	}

	/**
	 * @return array<string,array{0:string,1:mixed}>
	 */
	public static function invalid_provider(): array {
		return array(
			'int as text'    => array( 'default_ttl', 'abc' ),
			'int as array'   => array( 'max_params', array( 1 ) ),
			'fractional'     => array( 'max_params', 1.5 ),
			'enum unknown'   => array( 'store', 'redis' ),
			'enum wrong'     => array( 'store', 5 ),
			'bool wrong'     => array( 'enabled', 'maybe' ),
			'unknown key'    => array( 'nope', 1 ),
		);
	}

	/**
	 * @dataProvider invalid_provider
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Input.
	 */
	public function test_normalize_value_rejects( string $key, $value ): void {
		$this->assertNull( Settings_Schema::normalize_value( $key, $value, 2592000 ) );
	}

	public function test_apply_keeps_old_value_and_reports_error(): void {
		$result = new Validation_Result();
		$out    = Settings_Schema::apply( array( 'store' => 'nope', 'max_params' => 7 ), Settings_Schema::defaults(), $result );

		$this->assertSame( 'auto', $out['store'] );
		$this->assertSame( 7, $out['max_params'] );
		$this->assertTrue( $result->has_code( 'invalid_value' ) );
		$this->assertSame( 'store', $result->errors()[0]['field'] );
	}

	public function test_apply_clamps_default_ttl_to_new_max_ttl(): void {
		$out = Settings_Schema::apply( array( 'max_ttl' => 3600 ), Settings_Schema::defaults(), new Validation_Result() );
		$this->assertSame( 3600, $out['default_ttl'] );
	}

	public function test_apply_skips_listed_keys(): void {
		$out = Settings_Schema::apply( array( 'max_params' => 7 ), Settings_Schema::defaults(), new Validation_Result(), array( 'max_params' ) );
		$this->assertSame( 20, $out['max_params'] );
	}

	public function test_resolve_ignores_garbage(): void {
		$this->assertSame( Settings_Schema::defaults(), Settings_Schema::resolve( 'junk' ) );
		$this->assertSame( 5, Settings_Schema::resolve( array( 'max_params' => 5, 'store' => 'x' ) )['max_params'] );
	}

	public function test_kill_switch_is_off_without_the_constant(): void {
		$this->assertFalse( defined( 'RFRAC_DISABLE' ) );
		$this->assertFalse( Settings_Schema::kill_switch() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_kill_switch_follows_the_boolean_rule_of_the_config(): void {
		define( 'RFRAC_DISABLE', 'yes' );
		$this->assertTrue( Settings_Schema::kill_switch() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_kill_switch_ignores_an_invalid_value_like_the_config(): void {
		define( 'RFRAC_DISABLE', 'maybe' );
		$this->assertFalse( Settings_Schema::kill_switch() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_kill_switch_is_off_for_false(): void {
		define( 'RFRAC_DISABLE', false );
		$this->assertFalse( Settings_Schema::kill_switch() );
	}
}

/**
 * @covers \Rockfire\Rest_Api_Cache\Config\Route_Rule
 */
class Test_Settings_Schema_Route_Rule extends TestCase {

	public function test_defaults_and_round_trip(): void {
		$defaults = Route_Rule::defaults();
		$this->assertSame( 'default', $defaults->status );
		$this->assertSame( 'registered', $defaults->match );
		$this->assertNull( $defaults->ttl );
		$this->assertSame( 'registered', $defaults->param_mode );
		$this->assertTrue( $defaults->validate()->is_valid() );

		$rule = Route_Rule::from_array(
			array(
				'status'           => 'enabled',
				'ttl'              => 300,
				'ignore_params'    => array( '_locale' ),
				'bypass_params'    => array( 'preview' ),
				'vary_headers'     => array( 'Accept-Language' ),
				'flush_post_types' => array( 'post' ),
				'flush_taxonomies' => array( 'category' ),
			)
		);
		$this->assertTrue( $rule->validate()->is_valid() );
		$this->assertSame( array( 'accept-language' ), $rule->vary_headers );
		$this->assertEquals( $rule, Route_Rule::from_array( $rule->to_array() ) );
		$this->assertSame( Route_Rule::defaults()->to_array(), Route_Rule::from_array( array() )->to_array() );
	}

	public function test_ttl_is_clamped(): void {
		$this->assertSame( 60, Route_Rule::from_array( array( 'ttl' => 10 ) )->ttl );
		$this->assertSame( 31536000, Route_Rule::from_array( array( 'ttl' => 99999999999 ) )->ttl );
		$this->assertNull( Route_Rule::from_array( array( 'ttl' => null ) )->ttl );
	}

	/**
	 * @return array<string,array{0:array<mixed>,1:string}>
	 */
	public static function invalid_provider(): array {
		$too_many = array_map( static fn( $i ) => 'p' . $i, range( 1, 21 ) );
		return array(
			'status'             => array( array( 'status' => 'bogus' ), 'invalid_status' ),
			'match'              => array( array( 'match' => 'regex' ), 'invalid_match' ),
			'param mode'         => array( array( 'param_mode' => 'x' ), 'invalid_param_mode' ),
			'ttl type'           => array( array( 'ttl' => 'abc' ), 'invalid_ttl' ),
			'list type'          => array( array( 'ignore_params' => 'a' ), 'invalid_type' ),
			'string type'        => array( array( 'status' => 5 ), 'invalid_type' ),
			'param name'         => array( array( 'ignore_params' => array( 'bad name' ) ), 'invalid_param_name' ),
			'too many params'    => array( array( 'bypass_params' => $too_many ), 'too_many_params' ),
			'forbidden cookie'   => array( array( 'vary_headers' => array( 'Cookie' ) ), 'forbidden_vary_header' ),
			'forbidden nonce'    => array( array( 'vary_headers' => array( 'X-WP-Nonce' ) ), 'forbidden_vary_header' ),
			'forbidden auth'     => array( array( 'vary_headers' => array( 'authorization' ) ), 'forbidden_vary_header' ),
			'forbidden set'      => array( array( 'vary_headers' => array( 'set-cookie' ) ), 'forbidden_vary_header' ),
			'forbidden proxy'    => array( array( 'vary_headers' => array( 'proxy-authorization' ) ), 'forbidden_vary_header' ),
			'header name'        => array( array( 'vary_headers' => array( 'bad_header' ) ), 'invalid_vary_header' ),
			'too many headers'   => array( array( 'vary_headers' => array( 'a', 'b', 'c', 'd', 'e', 'f' ) ), 'too_many_vary_headers' ),
			'flush name'         => array( array( 'flush_post_types' => array( 'Bad Name' ) ), 'invalid_flush_name' ),
		);
	}

	/**
	 * @dataProvider invalid_provider
	 *
	 * @param array<mixed> $data Rule data.
	 * @param string       $code Expected code.
	 */
	public function test_validate_reports( array $data, string $code ): void {
		$this->assertTrue( Route_Rule::from_array( $data )->validate()->has_code( $code ) );
	}

	public function test_exactly_five_param_names_and_headers_allowed(): void {
		$rule = Route_Rule::from_array(
			array(
				'ignore_params' => array_map( static fn( $i ) => 'p' . $i, range( 1, 20 ) ),
				'vary_headers'  => array( 'a', 'b', 'c', 'd', 'e' ),
			)
		);
		$this->assertTrue( $rule->validate()->is_valid() );
	}

	public function test_validate_key(): void {
		$this->assertTrue( Route_Rule::validate_key( '/wp/v2/posts/(?P<id>[\d]+)', 'registered' )->is_valid() );
		$this->assertTrue( Route_Rule::validate_key( '/wp/v2/*', 'wildcard' )->is_valid() );
		$this->assertFalse( Route_Rule::validate_key( '/wp/v2/(?P<id>\d)', 'wildcard' )->is_valid() );
		$this->assertFalse( Route_Rule::validate_key( 'wp/v2', 'registered' )->is_valid() );
		$this->assertFalse( Route_Rule::validate_key( '', 'registered' )->is_valid() );
	}
}
