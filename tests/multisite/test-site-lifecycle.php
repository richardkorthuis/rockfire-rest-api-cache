<?php
/**
 * Multisite tests for the site lifecycle.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Schema\Migrator;
use Rockfire\Rest_Api_Cache\Schema\Site_Lifecycle;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Schema\Site_Lifecycle
 * @group multisite
 */
class Test_Site_Lifecycle extends Rfrac_Test_Case {

	/**
	 * Plugin basename used for the network-active flag.
	 */
	private const BASENAME = 'rockfire-rest-api-cache/rockfire-rest-api-cache.php';

	/**
	 * Lifecycle under test.
	 *
	 * @var Site_Lifecycle
	 */
	private Site_Lifecycle $lifecycle;

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run via composer test-multisite.' );
		}
		parent::set_up();
		$this->lifecycle = new Site_Lifecycle( self::BASENAME, new Migrator() );
	}

	/**
	 * Whether a table (real or temporary) is queryable.
	 *
	 * @param string $table Full table name.
	 *
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$result   = $wpdb->query( "SELECT 1 FROM `{$table}` LIMIT 0" ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( $suppress );
		return false !== $result;
	}

	private function network_activate(): void {
		update_site_option( 'active_sitewide_plugins', array( self::BASENAME => time() ) );
	}

	public function test_new_site_gets_tables_when_network_active(): void {
		global $wpdb;
		$this->network_activate();
		add_action( 'wp_initialize_site', array( $this->lifecycle, 'on_initialize_site' ), 100 );
		$initialized = array();
		add_action(
			Hooks::SITE_INITIALIZED,
			static function ( $site_id ) use ( &$initialized ) {
				$initialized[] = $site_id;
			}
		);
		$current = get_current_blog_id();

		$blog_id = (int) self::factory()->blog->create();

		$prefix = $wpdb->get_blog_prefix( $blog_id );
		$this->assertTrue( $this->table_exists( $prefix . Names::TABLE_ENTRIES ) );
		$this->assertTrue( $this->table_exists( $prefix . Names::TABLE_TAGS ) );
		$this->assertSame( array( $blog_id ), $initialized );
		$this->assertSame( $current, get_current_blog_id(), 'The previous blog is restored.' );
	}

	public function test_site_initialized_not_fired_when_install_fails(): void {
		$this->network_activate();
		$failing = new class() extends Migrator {
			public function install_site(): bool {
				return false;
			}
		};
		$lifecycle   = new Site_Lifecycle( self::BASENAME, $failing );
		$initialized = 0;
		add_action(
			Hooks::SITE_INITIALIZED,
			static function () use ( &$initialized ) {
				++$initialized;
			}
		);
		$current = get_current_blog_id();
		$blog_id = (int) self::factory()->blog->create();

		$lifecycle->on_initialize_site( get_site( $blog_id ) );

		$this->assertSame( 0, $initialized );
		$this->assertSame( $current, get_current_blog_id(), 'The previous blog is restored.' );
	}

	public function test_site_initialized_not_fired_while_lock_is_held(): void {
		$this->network_activate();
		$blog_id = (int) self::factory()->blog->create();
		switch_to_blog( $blog_id );
		add_option( Migrator::LOCK_OPTION, time(), '', false );
		restore_current_blog();
		$initialized = 0;
		add_action(
			Hooks::SITE_INITIALIZED,
			static function () use ( &$initialized ) {
				++$initialized;
			}
		);

		$this->lifecycle->on_initialize_site( get_site( $blog_id ) );

		$this->assertSame( 0, $initialized );
	}

	public function test_new_site_gets_nothing_when_not_network_active(): void {
		global $wpdb;
		update_site_option( 'active_sitewide_plugins', array() );
		add_action( 'wp_initialize_site', array( $this->lifecycle, 'on_initialize_site' ), 100 );

		$blog_id = (int) self::factory()->blog->create();

		$this->assertFalse( $this->table_exists( $wpdb->get_blog_prefix( $blog_id ) . Names::TABLE_ENTRIES ) );
	}

	public function test_wp_delete_site_drop_tables_include_plugin_tables(): void {
		global $wpdb;
		add_filter( 'wpmu_drop_tables', array( $this->lifecycle, 'filter_drop_tables' ), 10, 2 );
		$blog_id = (int) self::factory()->blog->create();
		$prefix  = $wpdb->get_blog_prefix( $blog_id );

		$seen = array();
		add_filter(
			'wpmu_drop_tables',
			static function ( $tables ) use ( &$seen ) {
				$seen = $tables;
				return $tables;
			},
			PHP_INT_MAX
		);

		wp_delete_site( $blog_id );

		$this->assertContains( $prefix . Names::TABLE_ENTRIES, $seen );
		$this->assertContains( $prefix . Names::TABLE_TAGS, $seen );
	}

	public function test_filter_drop_tables_appends_both_tables(): void {
		global $wpdb;
		$tables = $this->lifecycle->filter_drop_tables( array( 'x' ), 7 );
		$prefix = $wpdb->get_blog_prefix( 7 );
		$this->assertSame( array( 'x', $prefix . 'rfrac_entries', $prefix . 'rfrac_tags' ), $tables );
	}

	// ---------- A site that is archived, marked as spam or deactivated ----------

	/**
	 * A lifecycle whose flush marks every entry of the current site flushed (the repository flush the plugin uses).
	 *
	 * @return Site_Lifecycle
	 */
	private function flushing_lifecycle(): Site_Lifecycle {
		return new Site_Lifecycle(
			self::BASENAME,
			new Migrator(),
			static fn() => ( new Repository() )->flush_all( gmdate( 'Y-m-d H:i:s' ) )
		);
	}

	/**
	 * Hook the lifecycle the way the plugin wires it.
	 *
	 * @param Site_Lifecycle $lifecycle Lifecycle.
	 *
	 * @return void
	 */
	private function hook_status_changes( Site_Lifecycle $lifecycle ): void {
		foreach ( array( 'archive_blog', 'make_spam_blog', 'deactivate_blog' ) as $hook ) {
			add_action( $hook, array( $lifecycle, 'on_site_suspended' ), 10, 1 );
		}
		add_action( 'wp_update_site', array( $lifecycle, 'on_site_updated' ), 10, 2 );
	}

	/**
	 * A site with tables and two active entries, and two active entries on the main site.
	 *
	 * @return array{0:int,1:list<int>,2:list<int>} The site ID, its entry IDs and the entry IDs of the main site.
	 */
	private function seed_two_sites(): array {
		$blog_id = (int) self::factory()->blog->create();
		switch_to_blog( $blog_id );
		( new Migrator() )->install_site();
		$site_entries = array( Rfrac_Entry_Fixtures::insert_entry(), Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) ) );
		restore_current_blog();
		$main_entries = array( Rfrac_Entry_Fixtures::insert_entry(), Rfrac_Entry_Fixtures::insert_entry( array(), array( 'post:1' ) ) );
		return array( $blog_id, $site_entries, $main_entries );
	}

	/**
	 * The states of entries of a site.
	 *
	 * @param int       $blog_id Site ID.
	 * @param list<int> $ids     Entry IDs.
	 *
	 * @return list<string>
	 */
	private function states_on( int $blog_id, array $ids ): array {
		switch_to_blog( $blog_id );
		$states = array_map( array( Rfrac_Entry_Fixtures::class, 'get_state' ), $ids );
		restore_current_blog();
		return $states;
	}

	/**
	 * UPDATE statements on the entries table of any site.
	 *
	 * @param Rfrac_Query_Recorder $recorder Recorder.
	 *
	 * @return string[]
	 */
	private function entry_updates( Rfrac_Query_Recorder $recorder ): array {
		return $recorder->matching( '/^\s*UPDATE\s+`?\w*' . preg_quote( Names::TABLE_ENTRIES, '/' ) . '`?\b/i' );
	}

	/**
	 * Data provider: the ways a site is taken down.
	 *
	 * @return array<string,array{0:\Closure}>
	 */
	public static function take_downs(): array {
		return array(
			'archived'                  => array( static fn( int $blog_id ) => update_blog_status( $blog_id, 'archived', '1' ) ),
			'spam'                      => array( static fn( int $blog_id ) => update_blog_status( $blog_id, 'spam', '1' ) ),
			'deleted'                   => array( static fn( int $blog_id ) => update_blog_status( $blog_id, 'deleted', '1' ) ),
			'deactivate_blog'           => array( static fn( int $blog_id ) => do_action( 'deactivate_blog', $blog_id ) ),
			'archive_blog'              => array( static fn( int $blog_id ) => do_action( 'archive_blog', $blog_id ) ),
			'make_spam_blog'            => array( static fn( int $blog_id ) => do_action( 'make_spam_blog', $blog_id ) ),
			'deleted set to 2'          => array( static fn( int $blog_id ) => wp_update_site( $blog_id, array( 'deleted' => 2 ) ) ),
		);
	}

	/**
	 * @dataProvider take_downs
	 */
	public function test_taking_a_site_down_flushes_its_entries_and_leaves_the_others( \Closure $take_down ): void {
		$this->network_activate();
		list( $blog_id, $site_entries, $main_entries ) = $this->seed_two_sites();
		$this->hook_status_changes( $this->flushing_lifecycle() );
		$current = get_current_blog_id();

		$take_down( $blog_id );

		$this->assertSame( array( '2', '2' ), $this->states_on( $blog_id, $site_entries ), 'Every entry of the site is flushed.' );
		$this->assertSame( array( '1', '1' ), $this->states_on( $current, $main_entries ), 'The entries of the other site are untouched.' );
		$this->assertSame( $current, get_current_blog_id(), 'The previous blog is restored.' );
		$this->assertFalse( ms_is_switched() );
	}

	public function test_archiving_a_site_flushes_it_twice_because_core_also_fires_archive_blog(): void {
		$this->network_activate();
		$blog_id = (int) self::factory()->blog->create();
		$flushes = 0;
		$count   = new Site_Lifecycle(
			self::BASENAME,
			new Migrator(),
			static function () use ( &$flushes ): void {
				++$flushes;
			}
		);
		$this->hook_status_changes( $count );

		update_blog_status( $blog_id, 'archived', '1' );

		$this->assertSame( 2, $flushes, 'wp_update_site and core\'s archive_blog both flush; nothing deduplicates them.' );
	}

	/**
	 * Data provider: a status that was set and is now cleared.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function statuses(): array {
		return array(
			'archived' => array( 'archived' ),
			'spam'     => array( 'spam' ),
			'deleted'  => array( 'deleted' ),
		);
	}

	/**
	 * @dataProvider statuses
	 */
	public function test_clearing_a_status_runs_no_entries_update( string $status ): void {
		$this->network_activate();
		list( $blog_id, $site_entries ) = $this->seed_two_sites();
		update_blog_status( $blog_id, $status, '1' );
		$this->hook_status_changes( $this->flushing_lifecycle() );
		$recorder = new Rfrac_Query_Recorder();

		$recorder->start();
		update_blog_status( $blog_id, $status, '0' );
		$recorder->stop();

		$this->assertSame( array(), $this->entry_updates( $recorder ) );
		$this->assertSame( array( '1', '1' ), $this->states_on( $blog_id, $site_entries ) );
	}

	/**
	 * Data provider: fields other than the statuses.
	 *
	 * @return array<string,array{0:string,1:string|int}>
	 */
	public static function other_fields(): array {
		return array(
			'public'  => array( 'public', 0 ),
			'mature'  => array( 'mature', 1 ),
			'lang_id' => array( 'lang_id', 3 ),
			'domain'  => array( 'domain', 'moved.example.org' ),
		);
	}

	/**
	 * @dataProvider other_fields
	 *
	 * @param string     $field Field.
	 * @param string|int $value New value.
	 */
	public function test_other_fields_flush_nothing( string $field, $value ): void {
		$this->network_activate();
		list( $blog_id, $site_entries ) = $this->seed_two_sites();
		$this->hook_status_changes( $this->flushing_lifecycle() );
		$recorder = new Rfrac_Query_Recorder();

		$recorder->start();
		$result = wp_update_site( $blog_id, array( $field => $value ) );
		$recorder->stop();

		$this->assertNotWPError( $result );
		$this->assertSame( array(), $this->entry_updates( $recorder ) );
		$this->assertSame( array( '1', '1' ), $this->states_on( $blog_id, $site_entries ) );
	}

	/**
	 * Data provider: old fields, new fields and whether the site is flushed.
	 *
	 * @return array<string,array{0:array<string,string>,1:array<string,string>,2:int}>
	 */
	public static function transitions(): array {
		return array(
			'archived 0 to 1'              => array( array(), array( 'archived' => '1' ), 1 ),
			'spam 0 to 1'                  => array( array(), array( 'spam' => '1' ), 1 ),
			'deleted 0 to 1'               => array( array(), array( 'deleted' => '1' ), 1 ),
			'deleted 0 to 2'               => array( array(), array( 'deleted' => '2' ), 1 ),
			'archived 1 to 0'              => array( array( 'archived' => '1' ), array( 'archived' => '0' ), 0 ),
			'deleted 1 to 2'               => array( array( 'deleted' => '1' ), array( 'deleted' => '2' ), 0 ),
			'archived stays 1'             => array( array( 'archived' => '1' ), array( 'archived' => '1', 'domain' => 'x.example.org' ), 0 ), // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
			'public 1 to 0'                => array( array(), array( 'public' => '0' ), 0 ),
			'mature 0 to 1'                => array( array(), array( 'mature' => '1' ), 0 ),
			'lang_id 0 to 3'               => array( array(), array( 'lang_id' => '3' ), 0 ),
			'domain'                       => array( array(), array( 'domain' => 'y.example.org' ), 0 ),
			'path'                         => array( array(), array( 'path' => '/y/' ), 0 ),
			'archived set, spam 0 to 1'    => array( array( 'archived' => '1' ), array( 'spam' => '1' ), 1 ),
		);
	}

	/**
	 * @dataProvider transitions
	 *
	 * @param array<string,string> $old_fields Fields of the site before the update.
	 * @param array<string,string> $new_fields Fields changed by the update.
	 * @param int                  $expected   Flushes expected.
	 */
	public function test_only_a_status_leaving_zero_flushes( array $old_fields, array $new_fields, int $expected ): void {
		$this->network_activate();
		$blog_id = (int) self::factory()->blog->create();
		$flushes = 0;
		$count   = new Site_Lifecycle(
			self::BASENAME,
			new Migrator(),
			static function () use ( &$flushes ): void {
				++$flushes;
			}
		);
		$old = clone get_site( $blog_id );
		foreach ( $old_fields as $field => $value ) {
			$old->$field = $value;
		}
		$new = clone $old;
		foreach ( $new_fields as $field => $value ) {
			$new->$field = $value;
		}

		$count->on_site_updated( $new, $old );

		$this->assertSame( $expected, $flushes );
	}

	public function test_the_flush_runs_on_the_site_with_errors_suppressed(): void {
		global $wpdb;
		$this->network_activate();
		$blog_id = (int) self::factory()->blog->create();
		$seen    = array();
		$lifecycle = new Site_Lifecycle(
			self::BASENAME,
			new Migrator(),
			static function () use ( &$seen, $wpdb ): void {
				$seen[] = array( get_current_blog_id(), $wpdb->suppress_errors( true ) );
			}
		);
		$before = $wpdb->suppress_errors( false );

		$lifecycle->on_site_suspended( $blog_id );

		$this->assertSame( array( array( $blog_id, true ) ), $seen );
		$this->assertSame( $before, $wpdb->suppress_errors( $before ), 'The error setting is put back.' );
	}

	public function test_a_site_without_a_flush_closure_flushes_nothing(): void {
		$this->network_activate();
		list( $blog_id, $site_entries ) = $this->seed_two_sites();
		$recorder = new Rfrac_Query_Recorder();

		$recorder->start();
		$this->lifecycle->on_site_suspended( $blog_id );
		$recorder->stop();

		$this->assertSame( array(), $this->entry_updates( $recorder ) );
		$this->assertSame( array( '1', '1' ), $this->states_on( $blog_id, $site_entries ) );
	}

	public function test_a_site_where_the_plugin_is_not_active_is_not_queried_and_records_nothing(): void {
		global $wpdb;
		update_site_option( 'active_sitewide_plugins', array() );
		$blog_id = (int) self::factory()->blog->create(); // Not network-active: the site gets no tables.
		$prefix  = $wpdb->get_blog_prefix( $blog_id );
		$this->hook_status_changes( $this->flushing_lifecycle() );
		$recorder = new Rfrac_Query_Recorder();

		$recorder->start();
		update_blog_status( $blog_id, 'archived', '1' );
		$recorder->stop();

		$this->assertSame( array(), $recorder->matching( '/' . preg_quote( $prefix . 'rfrac_', '/' ) . '/' ), 'No query touches the tables of the site.' );
		switch_to_blog( $blog_id );
		$this->assertFalse( get_option( 'rfrac_flush_epoch', false ), 'No flush epoch is created on the site.' );
		$this->assertFalse( get_option( Failure_Recorder::OPTION, false ), 'No failure is recorded on the site.' );
		restore_current_blog();
		$this->assertFalse( get_option( Failure_Recorder::OPTION, false ), 'No failure is recorded on the acting site.' );
	}

	public function test_a_site_that_lists_the_plugin_in_its_active_plugins_is_flushed(): void {
		update_site_option( 'active_sitewide_plugins', array() );
		list( $blog_id, $site_entries, $main_entries ) = $this->seed_two_sites();
		switch_to_blog( $blog_id );
		update_option( 'active_plugins', array( self::BASENAME ) );
		restore_current_blog();
		$this->hook_status_changes( $this->flushing_lifecycle() );

		update_blog_status( $blog_id, 'spam', '1' );

		$this->assertSame( array( '2', '2' ), $this->states_on( $blog_id, $site_entries ) );
		$this->assertSame( array( '1', '1' ), $this->states_on( get_current_blog_id(), $main_entries ) );
	}

	public function test_a_flush_that_throws_still_restores_the_blog(): void {
		global $wpdb;
		$this->network_activate();
		$blog_id   = (int) self::factory()->blog->create();
		$lifecycle = new Site_Lifecycle(
			self::BASENAME,
			new Migrator(),
			static function (): void {
				throw new \RuntimeException( 'flush failed' );
			}
		);
		$current = get_current_blog_id();
		$before  = $wpdb->suppress_errors( false );

		try {
			$lifecycle->on_site_suspended( $blog_id );
			$this->fail( 'The exception is not swallowed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'flush failed', $e->getMessage() );
		}

		$this->assertSame( $current, get_current_blog_id(), 'The previous blog is restored.' );
		$this->assertFalse( ms_is_switched() );
		$this->assertSame( $before, $wpdb->suppress_errors( $before ), 'The error setting is put back.' );
	}
}
