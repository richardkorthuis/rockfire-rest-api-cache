<?php
/**
 * Tests for the hard route deny-list.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Deny_List;

/**
 * @covers \Rockfire\Rest_Api_Cache\Config\Deny_List
 */
class Test_Deny_List extends WP_UnitTestCase {

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function routes(): array {
		return array(
			'cart items'           => array( '/wc/store/v1/cart/items', true ),
			'unversioned cart'     => array( '/wc/store/cart', true ),
			'checkout'             => array( '/wc/store/v1/checkout', true ),
			'store products'       => array( '/wc/store/v1/products', false ),
			'cart lookalike'       => array( '/wc/store/v1/cartoon', false ),
			'me trailing slash'    => array( '/wp/v2/users/me/', true ),
			'me upper case'        => array( '/WP/V2/USERS/ME', true ),
			'users meta'           => array( '/wp/v2/users/meta', false ),
			'users list'           => array( '/wp/v2/users', false ),
			'batch'                => array( '/batch/v1', true ),
			'batch child'          => array( '/batch/v1/x', true ),
			'batch lookalike'      => array( '/batch/v10', false ),
			'not anchored'         => array( '/x/batch/v1', false ),

			'shopper lists'                => array( '/wc/store/v1/shopper-lists', true ),
			'shopper list items'           => array( '/wc/store/v1/shopper-lists/(?P<slug>[a-z0-9-]+)/items/(?P<key>[\w-]{32})', true ),
			'agentic checkout sessions'    => array( '/wc/agentic/v1/checkout_sessions', true ),
			'agentic session complete'     => array( '/wc/agentic/v1/checkout_sessions/(?P<checkout_session_id>[a-zA-Z0-9._-]+)/complete', true ),
			'private patterns'             => array( '/wc/private/patterns', true ),
			'cart extensions'              => array( '/wc/store/v1/cart/extensions', true ),
			'checkout by id'               => array( '/wc/store/v1/checkout/(?P<id>[\d]+)', true ),
			'private upper case'           => array( '/WC/PRIVATE/patterns', true ),
			'shopper lists lookalike'      => array( '/wc/store/v1/shopper-listsx', false ),
			'agentic lookalike'            => array( '/wc/agenticx/v1', false ),
			'private lookalike'            => array( '/wc/privates', false ),
			'shopper lists not a segment'  => array( '/wc/store/v1/products/shopper-lists', false ),
			'store products v1'            => array( '/wc/store/v1/products', false ),
			'store product categories'     => array( '/wc/store/v1/products/categories', false ),
		);
	}

	/**
	 * @dataProvider routes
	 */
	public function test_is_denied( string $route, bool $denied ): void {
		$this->assertSame( $denied, Deny_List::is_denied( $route ) );
	}

	public function test_wildcard_that_could_match_a_denied_route_is_denied(): void {
		$this->assertTrue( Deny_List::is_wildcard_denied( '/wc/store/v1/*' ) );
		$this->assertTrue( Deny_List::is_wildcard_denied( '/wp/v2/users/*' ) );
		$this->assertTrue( Deny_List::is_wildcard_denied( '/batch/*' ) );
		$this->assertFalse( Deny_List::is_wildcard_denied( '/my.plugin/v1/items/*' ) );
		$this->assertFalse( Deny_List::is_wildcard_denied( '/wp/v2/*' ) );
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function woocommerce_wildcards(): array {
		return array(
			'any namespace and version' => array( '/wc/*/v1/*', true ),
			'any namespace, any route'  => array( '/wc/*/*', true ),
			'shopper list items'        => array( '/wc/store/v1/*/x/items', true ),
			'agentic any version'       => array( '/wc/agentic/*/*', true ),
			'store products'            => array( '/wc/store/v1/products/*', false ),
		);
	}

	/**
	 * @dataProvider woocommerce_wildcards
	 */
	public function test_woocommerce_wildcard_that_could_match_a_denied_route_is_denied( string $key, bool $denied ): void {
		$this->assertSame( $denied, Deny_List::is_wildcard_denied( $key ) );
	}

	public function test_wildcard_regex_escapes_segments(): void {
		$regex = Deny_List::wildcard_regex( '/my.plugin/v1/items/*' );

		$this->assertSame( 1, preg_match( $regex, '/my.plugin/v1/items/5' ) );
		$this->assertSame( 0, preg_match( $regex, '/myXplugin/v1/items/5' ) );
		$this->assertSame( 0, preg_match( $regex, '/my.plugin/v1/items/5/6' ) );
		$this->assertSame( 1, preg_match( $regex, '/MY.PLUGIN/v1/items/5' ) );
	}
}
