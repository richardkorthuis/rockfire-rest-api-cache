<?php
/**
 * Tests of the nonce caching setting: what the compiler hands the `rfrac/nonce_caching` filter, and that a stored
 * choice reaches both serving paths through a real compile.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Compiled_Config;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Names;

/**
 * @covers \Rockfire\Rest_Api_Cache\Config\Config_Compiler
 * @covers \Rockfire\Rest_Api_Cache\Config\Config
 */
class Test_Nonce_Caching_Setting extends Rfrac_E2e_Test_Case {

	public function set_up() {
		parent::set_up();
		// Content to store: an empty result set is never stored.
		self::factory()->post->create_many( 3 );
	}

	/**
	 * Compile with a probe on the filter.
	 *
	 * @return array{0:bool|null,1:string|null,2:Compiled_Config} The value and source the filter got (null when it was not applied), and the snapshot.
	 */
	private function probe(): array {
		$seen = array();
		$spy  = static function ( $value, $source = null ) use ( &$seen ) {
			$seen[] = array( $value, $source );
			return $value;
		};
		add_filter( Hooks::NONCE_CACHING, $spy, 10, 2 );
		$config = ( new Config_Compiler() )->compile( $this->new_rest_server() );
		remove_filter( Hooks::NONCE_CACHING, $spy, 10 );

		$this->assertCount( 1, $seen, 'The filter is applied once per compile.' );
		return array( $seen[0][0], $seen[0][1], $config );
	}

	/**
	 * Store the settings option as given, without going through a save.
	 *
	 * @param array<string,mixed> $settings Stored settings.
	 */
	private function store( array $settings ): void {
		update_option( Names::OPTION_SETTINGS, $settings, false );
	}

	public function test_nothing_stored_hands_the_filter_false_and_default(): void {
		[ $value, $source, $config ] = $this->probe();

		$this->assertFalse( $value );
		$this->assertSame( 'default', $source );
		$this->assertFalse( $config->nonce_caching() );
	}

	public function test_a_stored_true_hands_the_filter_true_and_setting(): void {
		$this->store( array( 'nonce_caching' => true ) );
		[ $value, $source, $config ] = $this->probe();

		$this->assertTrue( $value );
		$this->assertSame( 'setting', $source );
		$this->assertTrue( $config->nonce_caching() );
	}

	public function test_a_stored_false_hands_the_filter_false_and_setting(): void {
		$this->store( array( 'nonce_caching' => false ) );
		[ $value, $source, $config ] = $this->probe();

		$this->assertFalse( $value );
		$this->assertSame( 'setting', $source );
		$this->assertFalse( $config->nonce_caching() );
	}

	public function test_a_stored_value_that_is_not_a_boolean_counts_as_the_default(): void {
		$this->store( array( 'nonce_caching' => 'maybe' ) );
		[ $value, $source ] = $this->probe();

		$this->assertFalse( $value );
		$this->assertSame( 'default', $source );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_constant_wins_over_a_stored_false_and_the_filter_gets_constant(): void {
		$this->store( array( 'nonce_caching' => false ) );
		define( 'RFRAC_NONCE_CACHING', true );
		[ $value, $source, $config ] = $this->probe();

		$this->assertTrue( $value );
		$this->assertSame( 'constant', $source );
		$this->assertTrue( $config->nonce_caching() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_invalid_constant_is_set_aside_for_the_stored_setting(): void {
		$this->store( array( 'nonce_caching' => true ) );
		define( 'RFRAC_NONCE_CACHING', 'maybe' );
		[ $value, $source, $config ] = $this->probe();

		$this->assertTrue( $value );
		$this->assertSame( 'setting', $source );
		$this->assertTrue( $config->nonce_caching() );
	}

	public function test_a_filter_can_still_turn_it_on_with_the_default_source(): void {
		add_filter( Hooks::NONCE_CACHING, '__return_true' );
		$config = ( new Config_Compiler() )->compile( $this->new_rest_server() );

		$this->assertTrue( $config->nonce_caching() );
		$this->assertFalse( ( new Config() )->is_stored( 'nonce_caching' ) );
	}

	/**
	 * With a stored true and no snapshot override, a nonce request is a miss and then a hit on both paths.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_stored_true_caches_nonce_requests_on_both_paths( string $path ): void {
		$this->assertTrue( ( new Config() )->save_settings( array( 'nonce_caching' => '1' ) )->is_valid() );
		( new Config_Compiler() )->compile( $this->new_rest_server() );
		$this->assertTrue( Compiled_Config::load()->nonce_caching() );

		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_X_WP_NONCE' => wp_create_nonce( 'wp_rest' ) ) );
		$this->assertNotNull( $h->miss( '/wp/v2/posts' ), 'The first request is a miss.' );
		$this->assertSame( 'MISS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_X_WP_NONCE' => 'ce11f4c7a0' ) );
		$this->assertTrue( $h->lookup(), 'The repeat is a hit.' );
		$this->assertSame( 'HIT', $h->hit_header( 'X-Rfrac-Cache' ) );
		$this->assertNoFailures();
	}

	/**
	 * A stored false changes nothing: the nonce request still bypasses.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_stored_false_keeps_nonce_requests_bypassing_on_both_paths( string $path ): void {
		$this->assertTrue( ( new Config() )->save_settings( array( 'nonce_caching' => '0' ) )->is_valid() );
		( new Config_Compiler() )->compile( $this->new_rest_server() );
		$this->assertFalse( Compiled_Config::load()->nonce_caching() );

		$h = $this->harness( $path );
		$h->request( '/wp-json/wp/v2/posts', array( 'HTTP_X_WP_NONCE' => 'ce11f4c7a0' ) );
		$this->assertFalse( $h->lookup() );
	}

	public function test_a_stored_false_compiles_to_the_fingerprint_of_nothing_stored(): void {
		$compiler = new Config_Compiler();
		$server   = $this->new_rest_server();
		$empty    = $compiler->fingerprint( $server );

		$this->store( array( 'nonce_caching' => false ) );
		$this->assertSame( $empty, $compiler->fingerprint( $server ) );

		$compiler->compile( $server );
		$this->assertArrayNotHasKey( 'nonce_caching', get_option( Names::OPTION_CONFIG ), 'The flag is written only when on.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_false_constant_and_a_stored_false_compile_to_the_fingerprint_of_nothing_stored(): void {
		$compiler = new Config_Compiler();
		$server   = $this->new_rest_server();
		$empty    = $compiler->fingerprint( $server );

		$this->store( array( 'nonce_caching' => false ) );
		define( 'RFRAC_NONCE_CACHING', false );

		$this->assertSame( $empty, $compiler->fingerprint( $server ) );
	}

	public function test_a_stored_true_changes_the_fingerprint_and_a_stored_false_restores_it(): void {
		$compiler = new Config_Compiler();
		$server   = $this->new_rest_server();
		$empty    = $compiler->fingerprint( $server );

		$this->store( array( 'nonce_caching' => true ) );
		$this->assertNotSame( $empty, $compiler->fingerprint( $server ) );

		$this->store( array( 'nonce_caching' => false ) );
		$this->assertSame( $empty, $compiler->fingerprint( $server ) );
	}
}
