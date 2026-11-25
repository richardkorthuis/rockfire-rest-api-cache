<?php
/**
 * Multisite test of the `Caching` shim: a flush by endpoint acts on the current site of a subdirectory network.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Legacy\Shim_Notice;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Schema\Migrator;
use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching
 * @group multisite
 */
class Test_Shim_Caching_Multisite extends Rfrac_E2e_Test_Case {

	/**
	 * Id of the subsite at `/sub/`.
	 *
	 * @var int
	 */
	private int $blog_id = 0;

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run via composer test-multisite.' );
		}
		parent::set_up();
		if ( is_subdomain_install() ) {
			$this->markTestSkipped( 'The test needs a subdirectory network.' );
		}
		Shim_Notice::reset();
		( new ReflectionProperty( Plugin::class, 'stores' ) )->setValue( Plugin::instance(), array() );
		// Declare the shim while the main site is current.
		Caching::get_instance();

		self::factory()->post->create_many( 3, array( 'post_title' => 'Main site' ) );
		$this->blog_id = (int) self::factory()->blog->create(
			array(
				'domain' => get_network()->domain,
				'path'   => '/sub/',
			)
		);
		$this->on_subsite(
			function (): void {
				$this->assertTrue( ( new Migrator() )->install_site(), 'The tables of the subsite exist.' );
				update_option( 'permalink_structure', '/%postname%/' );
				set_transient( Config_Compiler::CHECK_TRANSIENT, time(), 300 );
				$this->configure( array( 'store_mode' => 'database' ) );
				self::factory()->post->create_many( 3, array( 'post_title' => 'Subsite' ) );
			}
		);
		$this->configure( array( 'store_mode' => 'database' ) );
	}

	public function tear_down() {
		Shim_Notice::reset();
		( new ReflectionProperty( Plugin::class, 'stores' ) )->setValue( Plugin::instance(), array() );
		parent::tear_down();
	}

	/**
	 * Run a callback inside the subsite.
	 *
	 * @param callable $callback Callback.
	 *
	 * @return mixed
	 */
	private function on_subsite( callable $callback ) {
		switch_to_blog( $this->blog_id );
		try {
			Coexistence_Guard::reset();
			return $callback();
		} finally {
			restore_current_blog();
			Coexistence_Guard::reset();
		}
	}

	/**
	 * Path of the subsite, e.g. `/sub`.
	 *
	 * @return string
	 */
	private function sub_path(): string {
		return untrailingslashit( (string) wp_parse_url( get_home_url( $this->blog_id ), PHP_URL_PATH ) );
	}

	/**
	 * Store the posts list on the main site and on the subsite.
	 *
	 * @param Rfrac_Serving_Harness $h Harness.
	 *
	 * @return void
	 */
	private function store_both( Rfrac_Serving_Harness $h ): void {
		$h->request( '/wp-json/wp/v2/posts' );
		$this->assertNotNull( $h->miss( '/wp/v2/posts' ), 'Stored on the main site.' );
		$this->on_subsite(
			function () use ( $h ): void {
				$h->request( $this->sub_path() . '/wp-json/wp/v2/posts' );
				$this->assertNotNull( $h->miss( '/wp/v2/posts' ), 'Stored on the subsite.' );
				$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
			}
		);
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );
	}

	/**
	 * Whether the posts list is served from the cache on a site.
	 *
	 * @param Rfrac_Serving_Harness $h       Harness.
	 * @param bool                  $subsite Whether to ask the subsite (otherwise the main site).
	 *
	 * @return bool
	 */
	private function is_hit( Rfrac_Serving_Harness $h, bool $subsite ): bool {
		if ( ! $subsite ) {
			$h->request( '/wp-json/wp/v2/posts' );
			return $h->lookup();
		}
		return (bool) $this->on_subsite(
			function () use ( $h ): bool {
				$h->request( $this->sub_path() . '/wp-json/wp/v2/posts' );
				return $h->lookup();
			}
		);
	}

	/**
	 * The strictness levels that match the entry without a query string.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function levels(): array {
		return array(
			'strict' => array( 'strict' ),
			'params' => array( 'params' ),
		);
	}

	/**
	 * @dataProvider levels
	 *
	 * @param string $strictness Strictness.
	 */
	public function test_a_path_of_the_rest_api_is_flushed_on_the_current_subsite_only( string $strictness ): void {
		$h = $this->harness( Rfrac_Serving_Harness::EARLY );
		$this->store_both( $h );
		$this->assertSame( '/sub', $this->sub_path() );
		$this->setExpectedDeprecated( 'Caching::delete_cache_by_endpoint' );

		$result = $this->on_subsite(
			static fn() => Caching::get_instance()->delete_cache_by_endpoint( '/wp-json/wp/v2/posts', $strictness )
		);

		$this->assertTrue( $result );
		$this->assertFalse( $this->is_hit( $h, true ), 'The entry of the subsite is flushed.' );
		$this->assertTrue( $this->is_hit( $h, false ), 'The entry of the main site is still active.' );
		$this->assertNoFailures();
	}

	public function test_the_same_call_on_the_main_site_leaves_the_subsite_alone(): void {
		$h = $this->harness( Rfrac_Serving_Harness::EARLY );
		$this->store_both( $h );
		$this->setExpectedDeprecated( 'Caching::delete_cache_by_endpoint' );

		$this->assertTrue( Caching::get_instance()->delete_cache_by_endpoint( '/wp-json/wp/v2/posts' ) );

		$this->assertFalse( $this->is_hit( $h, false ), 'The entry of the main site is flushed.' );
		$this->assertTrue( $this->is_hit( $h, true ), 'The entry of the subsite is still active.' );
	}
}
