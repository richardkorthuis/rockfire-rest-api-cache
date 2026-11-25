<?php
/**
 * Multisite tests for the plugin-change marker: a network-wide change marks every site when this plugin is
 * network-active, a per-site change only the current site.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Services\Plugin_Change
 * @group multisite
 */
class Test_Plugin_Change_Network extends Rfrac_Test_Case {

	/**
	 * The sites of this test: the main site and the ones it created, three at least. Network-wide walks see only these.
	 *
	 * @var int[]
	 */
	private array $sites = array();

	/**
	 * Container whose engine hooks are registered.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test.' );
		}
		parent::set_up();

		// Other tests leave sites behind (some with dropped tables); only the sites of this test count. The
		// walk pages with an offset, so only a query that sets one is answered, with the list on the first page
		// only: core's own id lookups (domain_exists() when a site is created) set none and are left alone.
		$this->sites = array( 1 );
		add_filter(
			'sites_pre_query',
			function ( $result, $query ) {
				$offset = $query->query_vars['offset'] ?? '';
				if ( 'ids' !== ( $query->query_vars['fields'] ?? '' ) || '' === $offset ) {
					return $result;
				}
				return (int) $offset > 0 ? array() : $this->sites;
			},
			10,
			2
		);
		while ( count( $this->sites ) < 3 ) {
			$this->sites[] = (int) self::factory()->blog->create();
		}

		$this->plugin = new Plugin();
		$this->plugin->register_engine_hooks();
	}

	/**
	 * Make this plugin network-active (or not) for the test.
	 *
	 * @param bool $active Whether it is network-active.
	 *
	 * @return void
	 */
	private function network_activate( bool $active ): void {
		$network = (array) get_site_option( 'active_sitewide_plugins', array() );
		if ( $active ) {
			$network[ $this->plugin->basename() ] = time();
		} else {
			unset( $network[ $this->plugin->basename() ] );
		}
		update_site_option( 'active_sitewide_plugins', $network );
	}

	/**
	 * The marker of a site, null when none.
	 *
	 * @param int $site_id Site.
	 *
	 * @return mixed
	 */
	private function marker_of( int $site_id ) {
		switch_to_blog( $site_id );
		try {
			return get_option( Names::OPTION_PLUGIN_CHANGED, null );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * The last uncleared failure recorded on a site, null when none.
	 *
	 * @param int $site_id Site.
	 *
	 * @return array{context:string,message:string,time:int,count:int}|null
	 */
	private function failure_of( int $site_id ): ?array {
		switch_to_blog( $site_id );
		try {
			return Failure_Recorder::last();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Forget the failure recorded on a site (writes are throttled to one per minute).
	 *
	 * @param int $site_id Site.
	 *
	 * @return void
	 */
	private function clear_failure_of( int $site_id ): void {
		switch_to_blog( $site_id );
		try {
			delete_option( Failure_Recorder::OPTION );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Give every site a distinct, non-zero full-flush epoch and clear the markers.
	 *
	 * @return array<int,string> Site => epoch.
	 */
	private function prepare_sites(): array {
		global $wpdb;
		$epochs = array();
		foreach ( $this->sites as $index => $site_id ) {
			switch_to_blog( $site_id );
			try {
				delete_option( Names::OPTION_PLUGIN_CHANGED );
				// Written directly: a site created by the test has no cache tables, so it cannot flush.
				$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->options,
					array(
						'option_name'  => Names::OPTION_FLUSH_ALL_EPOCH,
						'option_value' => (string) ( $index + 1 ),
						'autoload'     => 'off',
					)
				);
				$epochs[ $site_id ] = (string) ( new Repository() )->flush_all_epoch();
			} finally {
				restore_current_blog();
			}
		}
		return $epochs;
	}

	public function test_a_site_created_while_the_scoping_filter_is_active_gets_a_new_id(): void {
		// Core's name check is an id lookup without an offset: the scoping filter must not answer it.
		$this->assertNull( domain_exists( 'rfrac-no-such-site.example.org', '/rfrac-no-such-site/' ), 'The name is free.' );

		$site_id = self::factory()->blog->create();

		$this->assertNotWPError( $site_id );
		$this->assertGreaterThan( 1, (int) $site_id );
		$this->assertNotContains( (int) $site_id, $this->sites );
	}

	public function test_a_network_wide_change_marks_every_site_when_this_plugin_is_network_active(): void {
		$this->network_activate( true );
		$epochs = $this->prepare_sites();

		foreach ( array( 'activated_plugin', 'deactivated_plugin' ) as $hook ) {
			foreach ( $this->sites as $site_id ) {
				switch_to_blog( $site_id );
				delete_option( Names::OPTION_PLUGIN_CHANGED );
				restore_current_blog();
			}

			do_action( $hook, 'example/example.php', true );

			foreach ( $this->sites as $site_id ) {
				$this->assertSame( $epochs[ $site_id ], $this->marker_of( $site_id ), "$hook, site $site_id" );
			}
			$this->assertSame( 1, get_current_blog_id(), 'The current site is restored.' );
		}
	}

	public function test_a_site_whose_epoch_cannot_be_read_does_not_stop_a_network_wide_change_from_marking_the_other_sites(): void {
		global $wpdb;
		$this->network_activate( true );
		$epochs  = $this->prepare_sites();
		$failing = $this->sites[1];
		$this->assertNotSame( 1, $failing );
		$this->assertGreaterThan( 2, count( $this->sites ), 'A site must follow the failing one.' );
		foreach ( $this->sites as $site_id ) {
			$this->clear_failure_of( $site_id );
		}

		$suppress = $wpdb->suppress_errors( true );
		$fail     = static fn( $query ) => get_current_blog_id() === $failing && is_string( $query ) && false !== strpos( $query, Names::OPTION_FLUSH_ALL_EPOCH ) ? 'RFRAC INVALID SQL' : $query;
		add_filter( 'query', $fail );
		try {
			do_action( 'activated_plugin', 'example/example.php', true );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppress );
		}

		foreach ( $this->sites as $site_id ) {
			if ( $failing === $site_id ) {
				$this->assertNull( $this->marker_of( $site_id ), "Site $site_id cannot be marked." );
				continue;
			}
			$this->assertSame( $epochs[ $site_id ], $this->marker_of( $site_id ), "Site $site_id is still marked." );
		}
		foreach ( $this->sites as $site_id ) {
			$last = $this->failure_of( $site_id );
			if ( $failing === $site_id ) {
				$this->assertSame( "plugin_change:site-$failing", $last['context'] ?? null, 'The failure is recorded on the failing site and names it.' );
				continue;
			}
			$this->assertNull( $last, "No failure is recorded on site $site_id." );
		}
		$this->assertSame( 1, get_current_blog_id(), 'The current site is restored.' );

		foreach ( $this->sites as $site_id ) {
			$this->clear_failure_of( $site_id );
		}
	}

	public function test_a_site_outside_the_test_list_whose_tables_are_gone_is_never_visited_by_a_network_wide_change(): void {
		global $wpdb;
		$this->network_activate( true );
		$epochs = $this->prepare_sites();

		// A site another test left behind: its row is there, its cache tables are not, and the test does not list it.
		$orphan = (int) self::factory()->blog->create();
		$this->assertNotContains( $orphan, $this->sites );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		foreach ( Names::tables() as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->get_blog_prefix( $orphan ) . $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		foreach ( array_merge( $this->sites, array( $orphan ) ) as $site_id ) {
			$this->clear_failure_of( $site_id );
		}

		do_action( 'activated_plugin', 'example/example.php', true );

		foreach ( $this->sites as $site_id ) {
			$this->assertSame( $epochs[ $site_id ], $this->marker_of( $site_id ), "Site $site_id is marked." );
			$this->assertNull( $this->failure_of( $site_id ), "No failure is recorded on site $site_id." );
		}
		$this->assertNull( $this->marker_of( $orphan ), 'The orphan is not marked.' );
		$this->assertNull( $this->failure_of( $orphan ), 'No failure is recorded on the orphan.' );
		$this->assertSame( 1, get_current_blog_id(), 'The current site is restored.' );
	}

	public function test_a_network_wide_change_marks_the_current_site_only_when_this_plugin_is_not_network_active(): void {
		$this->network_activate( false );
		$epochs = $this->prepare_sites();

		do_action( 'activated_plugin', 'example/example.php', true );

		$this->assertSame( $epochs[ get_current_blog_id() ], $this->marker_of( get_current_blog_id() ) );
		foreach ( $this->sites as $site_id ) {
			if ( get_current_blog_id() !== $site_id ) {
				$this->assertNull( $this->marker_of( $site_id ), "Site $site_id" );
			}
		}
	}

	public function test_a_per_site_change_marks_only_the_current_site(): void {
		$this->network_activate( true );
		$epochs = $this->prepare_sites();

		foreach ( array( 'activated_plugin', 'deactivated_plugin' ) as $hook ) {
			do_action( $hook, 'example/example.php', false );

			$this->assertSame( $epochs[ get_current_blog_id() ], $this->marker_of( get_current_blog_id() ), $hook );
			foreach ( $this->sites as $site_id ) {
				if ( get_current_blog_id() !== $site_id ) {
					$this->assertNull( $this->marker_of( $site_id ), "$hook, site $site_id" );
				}
			}
		}
	}

	public function test_a_per_site_change_on_another_site_marks_that_site_only(): void {
		$this->network_activate( true );
		$epochs = $this->prepare_sites();
		$other  = (int) end( $this->sites );
		$this->assertNotSame( 1, $other );

		switch_to_blog( $other );
		try {
			do_action( 'deactivated_plugin', 'example/example.php', false );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( $epochs[ $other ], $this->marker_of( $other ) );
		$this->assertNull( $this->marker_of( 1 ) );
	}

	public function test_a_flush_clears_the_marker_of_its_own_site_only(): void {
		$this->network_activate( true );
		$this->prepare_sites();
		do_action( 'activated_plugin', 'example/example.php', true );
		$other = (int) end( $this->sites );

		rfrac_flush_all();

		switch_to_blog( 1 );
		try {
			$this->assertFalse( $this->plugin->plugin_change()->pending(), 'The flushed site.' );
		} finally {
			restore_current_blog();
		}
		switch_to_blog( $other );
		try {
			$this->assertTrue( $this->plugin->plugin_change()->pending(), 'Another site keeps its notice.' );
		} finally {
			restore_current_blog();
		}
	}

	public function test_this_plugins_own_network_activation_marks_no_site(): void {
		$this->network_activate( true );
		$this->prepare_sites();

		do_action( 'activated_plugin', $this->plugin->basename(), true );
		do_action( 'deactivated_plugin', $this->plugin->basename(), true );

		foreach ( $this->sites as $site_id ) {
			$this->assertNull( $this->marker_of( $site_id ), "Site $site_id" );
		}
	}
}
