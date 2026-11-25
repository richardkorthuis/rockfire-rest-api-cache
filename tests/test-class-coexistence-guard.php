<?php
/**
 * Tests for the Coexistence_Guard.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;

/**
 * @covers \Rockfire\Rest_Api_Cache\Install\Coexistence_Guard
 */
class Test_Coexistence_Guard extends WP_UnitTestCase {

	const OWN = 'rockfire-rest-api-cache/rockfire-rest-api-cache.php';

	/** @var array<string,mixed> $_SERVER backup. */
	private $server_backup = [];

	public function set_up() {
		parent::set_up();
		$this->server_backup = [
			'REQUEST_URI'    => $_SERVER['REQUEST_URI'] ?? null,
			'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? null,
		];
		Coexistence_Guard::reset();
	}

	public function tear_down() {
		Coexistence_Guard::reset();
		remove_all_filters( 'rest_pre_serve_request', 9999 );
		remove_all_filters( 'rest_pre_echo_response', 1000 );
		foreach ( $this->server_backup as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		parent::tear_down();
	}

	private function activate( array $plugins ) {
		update_option( 'active_plugins', $plugins );
		Coexistence_Guard::reset();
	}

	public function test_constants() {
		$this->assertSame( 'wp-rest-cache.php', Coexistence_Guard::ORIGINAL_FILE );
		$this->assertSame( 'WP_Rest_Cache_Plugin\\Includes\\Plugin', Coexistence_Guard::ORIGINAL_CLASS );
	}

	public function test_not_blocked_when_only_rockfire_active() {
		$this->activate( [ self::OWN ] );
		$this->assertFalse( Coexistence_Guard::is_blocked() );
	}

	public function test_blocked_when_original_active() {
		$this->activate( [ 'wp-rest-cache/wp-rest-cache.php', self::OWN ] );
		$this->assertTrue( Coexistence_Guard::is_blocked() );
	}

	public function test_blocked_when_original_folder_renamed() {
		$this->activate( [ 'wp-rest-cache-old/wp-rest-cache.php' ] );
		$this->assertTrue( Coexistence_Guard::is_blocked() );
	}

	public function test_not_blocked_for_similarly_named_file() {
		$this->activate( [ 'foo/not-wp-rest-cache.php' ] );
		$this->assertFalse( Coexistence_Guard::is_blocked() );
	}

	public function test_result_is_memoised_until_reset() {
		$this->activate( [ self::OWN ] );
		$this->assertFalse( Coexistence_Guard::is_blocked() );

		update_option( 'active_plugins', [ 'wp-rest-cache/wp-rest-cache.php' ] );
		$this->assertFalse( Coexistence_Guard::is_blocked(), 'memoised value must be returned' );

		Coexistence_Guard::reset();
		$this->assertTrue( Coexistence_Guard::is_blocked() );
	}

	public function test_is_blocked_runs_no_queries_once_options_are_loaded() {
		global $wpdb;
		$this->activate( [ 'wp-rest-cache/wp-rest-cache.php' ] );
		get_option( 'active_plugins' );

		$before = $wpdb->num_queries;
		Coexistence_Guard::is_blocked();
		$this->assertSame( $before, $wpdb->num_queries );
	}

	public function test_the_guard_prints_no_notice_and_references_no_admin_class() {
		$this->assertFalse( method_exists( Coexistence_Guard::class, 'render_notice' ), 'The notice lives in Admin\\Coexistence_Notice.' );

		$source = (string) file_get_contents( RFRAC_DIR . 'includes/install/class-coexistence-guard.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringNotContainsString( 'Admin\\', $source, 'The guard is safe in the must-use phase: it knows nothing of the admin.' );
		$this->assertStringNotContainsString( 'add_action', $source );
		$this->assertFalse( has_action( 'admin_notices', [ Coexistence_Guard::class, 'render_notice' ] ) );
		$this->assertFalse( has_action( 'network_admin_notices', [ Coexistence_Guard::class, 'render_notice' ] ) );
	}

	public function test_the_interim_legacy_skip_filter_is_gone() {
		// The engine consults the guard through its Cache_Policy (see tests/engine/test-plugin-wiring.php).
		$this->assertFalse( has_filter( 'rfrac/skip_caching' ) );
	}
}
