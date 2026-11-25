<?php
/**
 * Tests for the vary-cookie declaration normaliser.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Vary_Cookies;

/**
 * @covers \Rockfire\Rest_Api_Cache\Config\Vary_Cookies
 */
class Test_Vary_Cookies extends WP_UnitTestCase {

	/**
	 * @return array<string,array{0:mixed,1:array<string,list<string>>|null}>
	 */
	public static function declarations(): array {
		$values_at_cap = range( 1, Vary_Cookies::MAX_VALUES );
		$sorted_values = array_map( 'strval', $values_at_cap );
		sort( $sorted_values, SORT_STRING );
		$names_at_cap = array_fill_keys( array( 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h' ), array( 'x' ) );

		return array(
			'valid'                       => array( array( 'cur' => array( 'USD', 'EUR' ) ), array( 'cur' => array( 'EUR', 'USD' ) ) ),
			'name is normalised'          => array( array( 'my.cur' => array( 'EUR' ) ), array( 'my_cur' => array( 'EUR' ) ) ),
			'names are sorted'            => array( array( 'b' => array( 'x' ), 'a' => array( 'y' ) ), array( 'a' => array( 'y' ), 'b' => array( 'x' ) ) ),
			'values are de-duplicated'    => array( array( 'cur' => array( 'EUR', 'USD', 'EUR' ) ), array( 'cur' => array( 'EUR', 'USD' ) ) ),
			'values are re-indexed'       => array( array( 'cur' => array( 5 => 'b', 9 => 'a' ) ), array( 'cur' => array( 'a', 'b' ) ) ),
			'integer values'              => array( array( 'tier' => array( 2, 10, 1 ) ), array( 'tier' => array( '1', '10', '2' ) ) ),
			'integer and string collapse' => array( array( 'tier' => array( 1, '1' ) ), array( 'tier' => array( '1' ) ) ),
			'value at the byte limit'     => array( array( 'c' => array( str_repeat( 'a', 128 ) ) ), array( 'c' => array( str_repeat( 'a', 128 ) ) ) ),
			'value with a space'          => array( array( 'c' => array( 'a b' ) ), array( 'c' => array( 'a b' ) ) ),
			'values at the cap'           => array( array( 'c' => $values_at_cap ), array( 'c' => $sorted_values ) ),
			'cookies at the cap'          => array( $names_at_cap, $names_at_cap ),
			'empty declaration'           => array( array(), array() ),
			'value too long'              => array( array( 'c' => array( str_repeat( 'a', 129 ) ) ), null ),
			'multibyte value too long'    => array( array( 'c' => array( str_repeat( "\xC3\xA9", 65 ) ) ), null ),
			'empty value'                 => array( array( 'c' => array( '' ) ), null ),
			'control character'           => array( array( 'c' => array( "a\nb" ) ), null ),
			'nul byte'                    => array( array( 'c' => array( "a\0b" ) ), null ),
			'delete character'            => array( array( 'c' => array( "a\x7Fb" ) ), null ),
			'one bad value spoils all'    => array( array( 'c' => array( 'EUR', '' ) ), null ),
			'float value'                 => array( array( 'c' => array( 1.5 ) ), null ),
			'bool value'                  => array( array( 'c' => array( true ) ), null ),
			'array value'                 => array( array( 'c' => array( array( 'x' ) ) ), null ),
			'null value'                  => array( array( 'c' => array( null ) ), null ),
			'no values'                   => array( array( 'c' => array() ), null ),
			'values not a list'           => array( array( 'c' => 'EUR' ), null ),
			'too many values'             => array( array( 'c' => range( 1, Vary_Cookies::MAX_VALUES + 1 ) ), null ),
			'too many cookies'            => array( array_fill_keys( array( 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i' ), array( 'x' ) ), null ),
			'invalid name'                => array( array( 'bad%name' => array( 'x' ) ), null ),
			'empty name'                  => array( array( '' => array( 'x' ) ), null ),
			'name with a space'           => array( array( 'a b' => array( 'x' ) ), null ),
			'names equal once normalised' => array( array( 'a.b' => array( 'x' ), 'a_b' => array( 'y' ) ), null ),
			'one bad name spoils all'     => array( array( 'good' => array( 'x' ), 'bad;name' => array( 'y' ) ), null ),
			'null'                        => array( null, null ),
			'string'                      => array( 'cur', null ),
			'integer'                     => array( 5, null ),
			'true'                        => array( true, null ),
		);
	}

	/**
	 * @dataProvider declarations
	 *
	 * @param mixed                             $declared Declaration.
	 * @param array<string,list<string>>|null $expected Expected result.
	 */
	public function test_normalize( $declared, ?array $expected ): void {
		$this->assertSame( $expected, Vary_Cookies::normalize( $declared ) );
	}

	public function test_numeric_cookie_name_is_a_valid_name(): void {
		$this->assertSame( array( '123' ), array_map( 'strval', array_keys( (array) Vary_Cookies::normalize( array( '123' => array( 'x' ) ) ) ) ) );
	}

	public function test_normalize_is_idempotent(): void {
		$once = Vary_Cookies::normalize( array( 'b.c' => array( 'y', 'x', 'y' ), 'a' => array( 3, 1 ) ) );

		$this->assertSame( $once, Vary_Cookies::normalize( $once ) );
	}

	public function test_key_prefixes_the_cookie_name(): void {
		$this->assertSame( 'cookie:', Vary_Cookies::KEY_PREFIX );
		$this->assertSame( 'cookie:my_cur', Vary_Cookies::key( 'my_cur' ) );
	}

	public function test_limits(): void {
		$this->assertSame( 8, Vary_Cookies::MAX_COOKIES );
		$this->assertSame( 1000, Vary_Cookies::MAX_VALUES );
		$this->assertSame( 128, Vary_Cookies::MAX_VALUE_BYTES );
	}
}
