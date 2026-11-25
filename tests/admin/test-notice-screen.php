<?php
/**
 * Tests for the screen predicate of the persistent notices.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Admin\Notice_Screen;

/**
 * @covers \Rockfire\Rest_Api_Cache\Admin\Notice_Screen
 */
class Test_Notice_Screen extends Rfrac_Test_Case {

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
	}

	public function tear_down() {
		unset( $GLOBALS['current_screen'], $GLOBALS['hook_suffix'] );
		parent::tear_down();
	}

	/**
	 * Screen ID => whether a notice may print there, whether it is a Plugins list.
	 *
	 * @return array<string,array{string,bool,bool}>
	 */
	public function screen_provider(): array {
		return array(
			'plugins'                => array( 'plugins', true, true ),
			'network plugins'        => array( 'plugins-network', true, true ),
			'caches page'            => array( 'toplevel_page_rfrac-caches', true, false ),
			'tools page'             => array( 'rest-api-cache_page_rfrac-tools', true, false ),
			'import page'            => array( 'rest-api-cache_page_rfrac-import', true, false ),
			'dashboard'              => array( 'dashboard', false, false ),
			'post list'              => array( 'edit-post', false, false ),
			'page of another plugin' => array( 'toplevel_page_other-plugin', false, false ),
		);
	}

	/**
	 * @dataProvider screen_provider
	 *
	 * @param string $screen_id  Screen ID.
	 * @param bool   $matches    Expected `matches()`.
	 * @param bool   $is_plugins Expected `is_plugins_screen()`.
	 */
	public function test_the_predicates_follow_the_current_screen( string $screen_id, bool $matches, bool $is_plugins ): void {
		set_current_screen( $screen_id );

		$this->assertSame( $matches, Notice_Screen::matches() );
		$this->assertSame( $is_plugins, Notice_Screen::is_plugins_screen() );
	}

	public function test_both_predicates_are_false_without_a_screen(): void {
		unset( $GLOBALS['current_screen'] );

		$this->assertFalse( Notice_Screen::matches() );
		$this->assertFalse( Notice_Screen::is_plugins_screen() );
	}
}
