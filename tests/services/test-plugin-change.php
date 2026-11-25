<?php
/**
 * Tests for the plugin-change marker: set by any activation or deactivation of another plugin, cleared by a full flush.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Services\Plugin_Change;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Services\Plugin_Change
 * @covers \Rockfire\Rest_Api_Cache\Plugin::plugin_change
 */
class Test_Plugin_Change extends Rfrac_Test_Case {

	/**
	 * Basename of this plugin.
	 *
	 * @var string
	 */
	private string $own = '';

	/**
	 * Repository.
	 *
	 * @var Repository
	 */
	private Repository $repo;

	/**
	 * Marker under test.
	 *
	 * @var Plugin_Change
	 */
	private Plugin_Change $change;

	public function set_up() {
		parent::set_up();
		global $wpdb;
		foreach ( array( Names::OPTION_FLUSH_EPOCH, Names::OPTION_FLUSH_ALL_EPOCH ) as $option ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		delete_option( Names::OPTION_PLUGIN_CHANGED );
		$this->own    = plugin_basename( RFRAC_FILE );
		$this->repo   = new Repository();
		$this->change = new Plugin_Change( $this->repo, $this->own );
	}

	/**
	 * The full-flush epoch of the current site (a failed read fails the test).
	 */
	private function all_epoch(): int {
		$epoch = $this->repo->flush_all_epoch();
		$this->assertNotNull( $epoch );
		return (int) $epoch;
	}

	/**
	 * A container whose engine hooks are registered, with a request that is an AJAX one and no admin registered.
	 */
	private function engine_container(): Plugin {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$plugin = new Plugin();
		$plugin->register_engine_hooks();
		return $plugin;
	}

	public function test_the_option_name_is_prefixed(): void {
		$this->assertSame( 'rfrac_plugin_changed', Names::OPTION_PLUGIN_CHANGED );
	}

	public function test_a_change_of_another_plugin_stores_the_full_flush_epoch_unautoloaded(): void {
		global $wpdb;
		$this->repo->flush_all( gmdate( 'Y-m-d H:i:s' ) );
		$this->repo->flush_all( gmdate( 'Y-m-d H:i:s' ) );

		$this->change->on_plugin_changed( 'other/other.php', false );

		$this->assertSame( '2', get_option( Names::OPTION_PLUGIN_CHANGED ) );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Names::OPTION_PLUGIN_CHANGED ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertNotContains( $autoload, wp_autoload_values_to_autoload() );
	}

	public function test_a_never_flushed_site_is_marked_with_zero(): void {
		$this->change->on_plugin_changed( 'other/other.php', false );

		$this->assertSame( '0', get_option( Names::OPTION_PLUGIN_CHANGED ) );
		$this->assertTrue( $this->change->pending() );
	}

	public function test_a_non_string_empty_or_own_basename_is_ignored(): void {
		foreach ( array( null, 7, array( 'other/other.php' ), '', $this->own ) as $plugin ) {
			$this->change->on_plugin_changed( $plugin, false );
			$this->change->on_plugin_changed( $plugin, true );
		}

		$this->assertFalse( get_option( Names::OPTION_PLUGIN_CHANGED ) );
		$this->assertFalse( $this->change->pending() );
	}

	public function test_a_network_wide_flag_marks_the_current_site_when_this_plugin_is_not_network_active(): void {
		$this->change->on_plugin_changed( 'other/other.php', true );

		$this->assertTrue( $this->change->pending() );
	}

	public function test_an_unreadable_epoch_throws_and_writes_nothing(): void {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$fail     = static fn( $query ) => is_string( $query ) && false !== strpos( $query, Names::OPTION_FLUSH_ALL_EPOCH ) ? 'RFRAC INVALID SQL' : $query;
		add_filter( 'query', $fail );
		try {
			$this->change->mark();
			$this->fail( 'Expected a RuntimeException.' );
		} catch ( RuntimeException $e ) {
			$this->assertFalse( get_option( Names::OPTION_PLUGIN_CHANGED ) );
			$this->assertFalse( $this->change->pending(), 'An unreadable epoch is not pending.' );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppress );
		}
	}

	public function test_pending_needs_a_numeric_marker_equal_to_the_live_epoch(): void {
		$this->assertFalse( $this->change->pending(), 'No marker.' );

		update_option( Names::OPTION_PLUGIN_CHANGED, 'abc', false );
		$this->assertFalse( $this->change->pending(), 'Not numeric.' );

		update_option( Names::OPTION_PLUGIN_CHANGED, (string) ( $this->all_epoch() + 1 ), false );
		$this->assertFalse( $this->change->pending(), 'Not the live epoch.' );

		update_option( Names::OPTION_PLUGIN_CHANGED, (string) $this->all_epoch(), false );
		$this->assertTrue( $this->change->pending() );
	}

	public function test_pending_writes_nothing(): void {
		$this->change->mark();
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();

		$this->change->pending();
		$this->change->pending();

		$recorder->stop();
		$this->assertSame( array(), $recorder->matching( '/^\s*(INSERT|UPDATE|REPLACE|DELETE)\b/i' ) );
	}

	public function test_dismiss_deletes_the_option(): void {
		$this->change->mark();
		$this->assertTrue( $this->change->pending() );

		$this->change->dismiss();

		$this->assertFalse( get_option( Names::OPTION_PLUGIN_CHANGED ) );
		$this->assertFalse( $this->change->pending() );
	}

	public function test_activation_and_deactivation_mark_the_site_with_only_the_engine_hooks_registered(): void {
		$this->engine_container();
		$this->assertTrue( wp_doing_ajax(), 'Precondition: an AJAX request.' );
		$this->assertFalse( has_action( 'admin_post_rfrac_flush_all' ), 'Precondition: no admin registered.' );
		$this->repo->flush_all( gmdate( 'Y-m-d H:i:s' ) );

		foreach ( array( 'activated_plugin', 'deactivated_plugin' ) as $hook ) {
			delete_option( Names::OPTION_PLUGIN_CHANGED );

			do_action( $hook, 'other/other.php', false );

			$this->assertSame( (string) $this->all_epoch(), get_option( Names::OPTION_PLUGIN_CHANGED ), $hook );
		}
	}

	public function test_this_plugins_own_activation_and_deactivation_write_nothing(): void {
		$this->engine_container();

		foreach ( array( 'activated_plugin', 'deactivated_plugin' ) as $hook ) {
			do_action( $hook, $this->own, false );
			do_action( $hook, $this->own, true );

			$this->assertFalse( get_option( Names::OPTION_PLUGIN_CHANGED ), $hook );
		}
	}

	public function test_the_container_resolves_one_marker_for_its_own_basename(): void {
		$plugin = new Plugin();

		$this->assertSame( $plugin->plugin_change(), $plugin->plugin_change() );

		$plugin->plugin_change()->on_plugin_changed( $plugin->basename(), false );
		$this->assertFalse( get_option( Names::OPTION_PLUGIN_CHANGED ) );
		$plugin->plugin_change()->on_plugin_changed( 'other/other.php', false );
		$this->assertTrue( $plugin->plugin_change()->pending() );
	}

	public function test_a_failing_marker_is_recorded_by_the_guarded_hook_and_does_not_break_the_activation(): void {
		global $wpdb;
		$this->engine_container();
		$suppress = $wpdb->suppress_errors( true );
		$fail     = static fn( $query ) => is_string( $query ) && false !== strpos( $query, Names::OPTION_FLUSH_ALL_EPOCH ) ? 'RFRAC INVALID SQL' : $query;
		add_filter( 'query', $fail );
		try {
			do_action( 'activated_plugin', 'other/other.php', false );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertFalse( get_option( Names::OPTION_PLUGIN_CHANGED ) );
		$this->assertSame( 'hook:activated_plugin', Failure_Recorder::last()['context'] ?? null );
		delete_option( Failure_Recorder::OPTION );
	}

	public function test_a_full_flush_clears_the_marker_even_on_an_empty_cache(): void {
		$this->change->mark();
		$this->assertTrue( $this->change->pending() );

		rfrac_flush_all();

		$this->assertFalse( $this->change->pending() );
	}

	public function test_a_flush_by_tag_or_a_post_save_commit_keeps_the_marker(): void {
		$plugin = new Plugin();
		$plugin->register_engine_hooks();
		$plugin->plugin_change()->mark();
		$this->assertTrue( $plugin->plugin_change()->pending() );

		rfrac_flush_tag( 'post:1' );
		$this->assertTrue( $plugin->plugin_change()->pending(), 'A tag flush is not a full flush.' );

		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$plugin->invalidator()->commit();
		$this->assertTrue( $plugin->plugin_change()->pending(), 'A post-save commit is not a full flush.' );
	}

	public function test_a_new_change_after_a_flush_is_pending_again(): void {
		$this->change->mark();
		rfrac_flush_all();
		$this->assertFalse( $this->change->pending() );

		$this->change->on_plugin_changed( 'other/other.php', false );

		$this->assertTrue( $this->change->pending() );
	}
}
