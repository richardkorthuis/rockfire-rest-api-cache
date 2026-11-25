<?php
/**
 * Tests for the cache tag vocabulary and normaliser.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use PHPUnit\Framework\TestCase;
use Rockfire\Rest_Api_Cache\Invalidation\Tags;

/**
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\Tags
 */
class Test_Tags extends TestCase {

	/**
	 * Acceptance table: input => expected normalised tag.
	 *
	 * @return array<string,array{0:mixed,1:?string}>
	 */
	public static function normalize_provider(): array {
		return array(
			'trim and lowercase'       => array( ' POST:12 ', 'post:12' ),
			'zero id'                  => array( 'post:0', null ),
			'trailing junk'            => array( 'post:12a', null ),
			'empty value'              => array( 'post:', null ),
			'no prefix'                => array( 'noprefix', null ),
			'whitespace'               => array( 'has space:x', null ),
			'non-string'               => array( 123, null ),
			'null'                     => array( null, null ),
			'array'                    => array( array( 'post:1' ), null ),
			'leading zero id'          => array( 'post:012', null ),
			'21-digit id'              => array( 'post:' . str_repeat( '1', 21 ), null ),
			'20-digit id'              => array( 'term:' . str_repeat( '1', 20 ), 'term:' . str_repeat( '1', 20 ) ),
			'term-ref id'              => array( 'Term-Ref:7', 'term-ref:7' ),
			'term-ref non-numeric'     => array( 'term-ref:abc', null ),
			'list lowercased'          => array( 'list:Product', 'list:product' ),
			'list invalid chars'       => array( 'list:a b', null ),
			'list too long'            => array( 'list:' . str_repeat( 'a', 65 ), null ),
			'list users'               => array( 'list:users', 'list:users' ),
			'route normalised'         => array( 'route:wp/v2/Posts/', 'route:/wp/v2/posts' ),
			'route root'               => array( 'route:/', 'route:/' ),
			'route non-ascii'          => array( 'route:/x/é', 'route:/x/%c3%a9' ),
			'route whitespace'         => array( "route:/a\tb", null ),
			'custom unchanged'         => array( 'acf-options:header', 'acf-options:header' ),
			'custom non-ascii'         => array( 'custom:é', null ),
			'custom bad prefix'        => array( '1custom:x', null ),
			'custom control char'      => array( "custom:a\x01", null ),
			'custom 191 chars'         => array( 'c:' . str_repeat( 'a', 189 ), 'c:' . str_repeat( 'a', 189 ) ),
			'custom 192 chars'         => array( 'c:' . str_repeat( 'a', 190 ), null ),
			'custom prefix too long'   => array( str_repeat( 'a', 33 ) . ':x', null ),
			'custom with comma'        => array( 'custom:a,b', null ),
			'route with comma encoded' => array( 'route:/a/b,c', 'route:/a/b%2cc' ),
		);
	}

	/**
	 * @dataProvider normalize_provider
	 *
	 * @param mixed   $input    Input.
	 * @param ?string $expected Expected output.
	 */
	public function test_normalize( $input, ?string $expected ): void {
		$this->assertSame( $expected, Tags::normalize( $input ) );
	}

	public function test_builders(): void {
		$this->assertSame( 'post:5', Tags::post( 5 ) );
		$this->assertSame( 'term:5', Tags::term( 5 ) );
		$this->assertSame( 'term-ref:5', Tags::term_ref( 5 ) );
		$this->assertSame( 'user:5', Tags::user( 5 ) );
		$this->assertSame( 'comment:5', Tags::comment( 5 ) );
		foreach ( array( 0, -3 ) as $id ) {
			$this->assertNull( Tags::post( $id ) );
			$this->assertNull( Tags::term( $id ) );
			$this->assertNull( Tags::term_ref( $id ) );
			$this->assertNull( Tags::user( $id ) );
			$this->assertNull( Tags::comment( $id ) );
		}
	}

	public function test_list_for(): void {
		$this->assertSame( 'list:product', Tags::list_for( 'Product' ) );
		$this->assertNull( Tags::list_for( '' ) );
		$this->assertNull( Tags::list_for( 'a b' ) );
		$this->assertNull( Tags::list_for( str_repeat( 'a', 65 ) ) );
		$this->assertSame( 'list:' . str_repeat( 'a', 64 ), Tags::list_for( str_repeat( 'a', 64 ) ) );
	}

	public function test_constants(): void {
		$this->assertSame( 191, Tags::MAX_LENGTH );
		$this->assertSame( 'list:users', Tags::LIST_USERS );
		$this->assertSame( 'list:comments', Tags::LIST_COMMENTS );
	}

	public function test_route(): void {
		$this->assertSame( 'route:/', Tags::route( '' ) );
		$this->assertSame( 'route:/', Tags::route( '/' ) );
		$this->assertSame( 'route:/x/%c3%a9', Tags::route( '/x/é' ) );
		$this->assertSame( 'route:/a', Tags::route( 'A//' ) );
	}

	public function test_long_route_is_hashed(): void {
		$path = '/' . str_repeat( 'a', 249 );
		$tag  = Tags::route( $path );
		$this->assertSame( 'route:#' . md5( $path ), $tag );
		$this->assertSame( 39, strlen( (string) $tag ) );
		$this->assertSame( $tag, Tags::normalize( 'route:' . $path ) );

		// 185 bytes stays verbatim, 186 is hashed.
		$this->assertSame( 'route:/' . str_repeat( 'a', 184 ), Tags::route( '/' . str_repeat( 'a', 184 ) ) );
		$this->assertStringStartsWith( 'route:#', (string) Tags::route( '/' . str_repeat( 'a', 185 ) ) );
	}

	public function test_route_tags_round_trip_through_normalize(): void {
		$paths = array( '/a/b', '', '/', '/x/é', '/' . str_repeat( 'a', 249 ), '/' . str_repeat( 'é', 100 ) );
		foreach ( $paths as $path ) {
			$tag = Tags::route( $path );
			$this->assertNotNull( $tag );
			$this->assertSame( $tag, Tags::normalize( $tag ), 'Round trip failed for path of ' . strlen( $path ) . ' bytes.' );
		}
		$this->assertStringStartsWith( 'route:#', (string) Tags::route( '/' . str_repeat( 'a', 249 ) ) );
		$this->assertStringStartsWith( 'route:#', (string) Tags::route( '/' . str_repeat( 'é', 100 ) ) );
	}

	/**
	 * @dataProvider normalize_provider
	 *
	 * @param mixed   $input    Input.
	 * @param ?string $expected Expected output.
	 */
	public function test_normalize_is_idempotent( $input, ?string $expected ): void {
		if ( null === $expected ) {
			$this->assertNull( Tags::normalize( $input ) );
			return;
		}
		$once = Tags::normalize( $input );
		$this->assertSame( $once, Tags::normalize( $once ) );
	}

	public function test_normalize_many(): void {
		$this->assertSame(
			array( 'post:1', 'post:2' ),
			Tags::normalize_many( array( 'post:2', 'post:1', 'POST:1', 'bad' ) )
		);
		$this->assertSame( array( 'post:1', 'term:3' ), Tags::normalize_many( 'term:3, post:1 post:1' ) );
		$this->assertSame( array(), Tags::normalize_many( '' ) );
		$this->assertSame( array( 'post:1' ), Tags::normalize_many( new ArrayIterator( array( 'post:1', 7 ) ) ) );
	}

	public function test_no_tag_contains_a_comma_so_the_string_form_round_trips(): void {
		$this->assertSame( 'route:/a/b%2cc', Tags::route( '/a/b,c' ) );
		$this->assertSame( array( 'route:/a/b%2cc' ), Tags::normalize_many( array( 'route:/a/b,c' ) ) );
		$this->assertSame( array( 'route:/a/b%2cc' ), Tags::normalize_many( 'route:/a/b%2cc' ) );
		$this->assertSame( array( 'post:1', 'route:/a/b%2cc' ), Tags::normalize_many( 'route:/a/b%2cc,post:1' ) );

		foreach ( array( 'route:/a/b,c', 'route:/x/é', 'acf-options:header', 'list:product', 'post:12' ) as $input ) {
			$tag = Tags::normalize( $input );
			$this->assertIsString( $tag );
			$this->assertStringNotContainsString( ',', $tag );
			$this->assertSame( array( $tag ), Tags::normalize_many( $tag ), $input );
		}
	}

	public function test_comma_edge_cases(): void {
		$this->assertSame( 'route:/%2c', Tags::normalize( 'route:,' ) );
		$this->assertNull( Tags::normalize( 'custom:,' ) );
		$this->assertNull( Tags::normalize( 'custom:a,' ) );
		$this->assertSame( array(), Tags::normalize_many( ',,, ,' ) );

		$long = Tags::route( '/' . str_repeat( 'a,', 100 ) );
		$this->assertMatchesRegularExpression( '/^route:#[0-9a-f]{32}$/', $long, 'Over 185 bytes (commas count encoded) the path is hashed.' );
		$this->assertSame( array( $long ), Tags::normalize_many( $long ) );
		$this->assertSame( array( $long ), Tags::normalize_many( array( 'route:/' . str_repeat( 'a,', 100 ) ) ) );
	}
}
