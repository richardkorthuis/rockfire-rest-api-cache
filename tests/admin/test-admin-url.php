<?php
/**
 * Tests for Admin_Url::back() with the host-less referer the plugin's forms post.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Admin_Url;

/**
 * Every plugin form carries `_wp_http_referer` as written by `wp_nonce_field()`: the REQUEST_URI of the screen,
 * a path without a host (`/wp-admin/plugins.php`). The absolute `Referer` header cases are in
 * test-redirect-and-request.php.
 *
 * @covers \Rockfire\Rest_Api_Cache\Admin\Admin_Url::back
 */
class Test_Admin_Url extends Rfrac_Test_Case {

	use Rfrac_Admin_Test_Helpers;

	public function tear_down() {
		$this->reset_admin_request();
		parent::tear_down();
	}

	/**
	 * Post a form from a screen: the host-less `_wp_http_referer` of the form is the only referer.
	 *
	 * @param string $path Path of the screen, with its query.
	 */
	private function posted_from( string $path ): void {
		$this->set_request( array(), array( '_wp_http_referer' => $path ) );
	}

	public function test_form_fields_post_a_path_only_referer(): void {
		$request_uri            = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/wp-admin/plugins.php';

		ob_start();
		try {
			Admin_Url::form_fields( 'install_mu' );
		} finally {
			$html = (string) ob_get_clean();
			if ( null === $request_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $request_uri;
			}
		}

		$this->assertStringContainsString( 'name="_wp_http_referer" value="/wp-admin/plugins.php"', $html );
	}

	public function test_back_returns_to_the_admin_screen_a_form_was_posted_from(): void {
		$this->posted_from( '/wp-admin/plugins.php' );

		$this->assertSame( admin_url( 'plugins.php' ), Admin_Url::back( 'F' ) );
	}

	public function test_back_keeps_the_query_of_the_screen(): void {
		$this->posted_from( '/wp-admin/admin.php?page=rfrac-tools&view=all' );

		$this->assertSame( admin_url( 'admin.php?page=rfrac-tools&view=all' ), Admin_Url::back( 'F' ) );
	}

	public function test_back_returns_to_the_network_admin_screen_on_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run it on the multisite database.' );
		}
		$this->posted_from( (string) wp_parse_url( network_admin_url( 'plugins.php' ), PHP_URL_PATH ) );

		$this->assertSame( network_admin_url( 'plugins.php' ), Admin_Url::back( 'F' ) );
	}

	public function test_back_follows_the_admin_path_of_a_site_in_a_subdirectory(): void {
		add_filter( 'pre_option_siteurl', static fn() => 'http://example.org/blog' );

		$this->posted_from( '/blog/wp-admin/plugins.php' );
		$this->assertSame( 'http://example.org/blog/wp-admin/plugins.php', Admin_Url::back( 'F' ) );

		$this->posted_from( '/wp-admin/plugins.php' );
		$this->assertSame( 'F', Admin_Url::back( 'F' ), 'The root admin path is not this site\'s admin.' );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function outside_the_admin_provider(): array {
		return array(
			'front-end page'           => array( '/sample-page/' ),
			'login screen'             => array( '/wp-login.php' ),
			'site root'                => array( '/' ),
			'sibling of wp-admin'      => array( '/wp-admin-other/plugins.php' ),
			'wp-admin without a slash' => array( '/wp-admin' ),
			'dot segments'             => array( '/wp-admin/../wp-login.php' ),
			'encoded dot segments'     => array( '/wp-admin/%2e%2e/wp-login.php' ),
			'single-dot segment'       => array( '/wp-admin/./../x' ),
			'encoded backslash'        => array( '/wp-admin/%5c..%5cx' ),
			'foreign host'           => array( 'https://evil.example/wp-admin/plugins.php' ),
			'protocol-relative host'   => array( '//evil.example/wp-admin/' ),
		);
	}

	/**
	 * @dataProvider outside_the_admin_provider
	 *
	 * @param string $referer Referer outside the admin.
	 */
	public function test_back_refuses_a_referer_outside_the_admin( string $referer ): void {
		$this->posted_from( $referer );

		$this->assertSame( 'F', Admin_Url::back( 'F' ) );
	}

	public function test_back_prefers_the_posted_path_over_a_front_end_referer_header(): void {
		$_SERVER['HTTP_REFERER'] = home_url( '/sample-page/' );
		$this->posted_from( '/wp-admin/plugins.php' );

		$this->assertSame( admin_url( 'plugins.php' ), Admin_Url::back( 'F' ), 'Core prefers _wp_http_referer over the header.' );
	}
}
