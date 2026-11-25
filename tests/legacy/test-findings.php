<?php
/**
 * Tests for the legacy findings store and its finding value object.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Legacy\Finding;
use Rockfire\Rest_Api_Cache\Legacy\Findings;
use Rockfire\Rest_Api_Cache\Names;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Finding
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Findings
 */
class Test_Legacy_Findings extends WP_UnitTestCase {

	/**
	 * Number of `update_option()` calls on the findings option since the spy was added.
	 *
	 * @var int
	 */
	private int $writes = 0;

	public function set_up() {
		parent::set_up();
		delete_option( Names::OPTION_LEGACY_FINDINGS );
		$this->writes = 0;
		add_filter(
			'pre_update_option_' . Names::OPTION_LEGACY_FINDINGS,
			function ( $value ) {
				++$this->writes;
				return $value;
			}
		);
	}

	public function tear_down() {
		delete_option( Names::OPTION_LEGACY_FINDINGS );
		parent::tear_down();
	}

	/**
	 * A finding with a given code and subject.
	 *
	 * @param string $status  Status.
	 * @param string $code    Code.
	 * @param string $subject Subject.
	 *
	 * @return Finding
	 */
	private function finding( string $status = Finding::IMPORTED, string $code = 'a_code', string $subject = 'a_subject' ): Finding {
		return new Finding( $status, $code, $subject, 'A message.' );
	}

	public function test_constants_and_option_name() {
		$this->assertSame( 'rfrac_legacy_findings', Names::OPTION_LEGACY_FINDINGS );
		$this->assertSame( array( 'imported', 'kept', 'adjusted', 'skipped', 'unsupported' ), Finding::STATUSES );
		$this->assertSame( 500, Finding::MAX_MESSAGE );
		$this->assertSame( 200, Findings::MAX_PER_GROUP );
		$this->assertSame(
			array( 'import', 'hooks', 'load_order', 'endpoints', 'classes', 'notice' ),
			array(
				Findings::GROUP_IMPORT,
				Findings::GROUP_HOOKS,
				Findings::GROUP_LOAD_ORDER,
				Findings::GROUP_ENDPOINTS,
				Findings::GROUP_CLASSES,
				Findings::GROUP_NOTICE,
			)
		);
	}

	public function test_finding_rejects_an_unknown_status() {
		$this->expectException( \InvalidArgumentException::class );
		new Finding( 'unknown', 'a_code', 'subject', 'Message.' );
	}

	public function test_finding_rejects_a_malformed_code() {
		$this->expectException( \InvalidArgumentException::class );
		new Finding( Finding::IMPORTED, 'Bad-Code', 'subject', 'Message.' );
	}

	public function test_finding_rejects_an_empty_and_a_too_long_code() {
		foreach ( array( '', str_repeat( 'a', 65 ) ) as $code ) {
			try {
				new Finding( Finding::IMPORTED, $code, 'subject', 'Message.' );
				$this->fail( 'A code of the wrong length must be rejected.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertInstanceOf( \InvalidArgumentException::class, $e );
			}
		}

		$this->assertSame( str_repeat( 'a', 64 ), ( new Finding( Finding::IMPORTED, str_repeat( 'a', 64 ), 's', 'm' ) )->code );
	}

	public function test_finding_truncates_the_message() {
		$finding = new Finding( Finding::SKIPPED, 'a_code', 'subject', str_repeat( 'x', 600 ) );
		$this->assertSame( 500, strlen( $finding->message ) );

		$finding = new Finding( Finding::SKIPPED, 'a_code', 'subject', str_repeat( 'é', 600 ) );
		$this->assertSame( 500, mb_strlen( $finding->message, 'UTF-8' ) );
	}

	public function test_finding_is_a_problem_only_when_adjusted_skipped_or_unsupported() {
		$expected = array(
			Finding::IMPORTED    => false,
			Finding::KEPT        => false,
			Finding::ADJUSTED    => true,
			Finding::SKIPPED     => true,
			Finding::UNSUPPORTED => true,
		);
		foreach ( $expected as $status => $problem ) {
			$this->assertSame( $problem, $this->finding( $status )->is_problem(), $status );
		}
	}

	public function test_finding_round_trips_through_an_array() {
		$finding = new Finding( Finding::ADJUSTED, 'a_code', 'SOME_IDENTIFIER', 'A message.' );
		$this->assertSame(
			array(
				'status'  => 'adjusted',
				'code'    => 'a_code',
				'subject' => 'SOME_IDENTIFIER',
				'message' => 'A message.',
			),
			$finding->to_array()
		);
		$this->assertEquals( $finding, Finding::from_array( $finding->to_array() ) );
	}

	public function test_finding_from_array_is_null_when_malformed() {
		$valid = $this->finding()->to_array();

		$this->assertNull( Finding::from_array( 'string' ) );
		$this->assertNull( Finding::from_array( null ) );
		$this->assertNull( Finding::from_array( array() ) );
		$this->assertNull( Finding::from_array( array_merge( $valid, array( 'status' => 'nope' ) ) ) );
		$this->assertNull( Finding::from_array( array_merge( $valid, array( 'code' => 'Bad-Code' ) ) ) );
		$this->assertNull( Finding::from_array( array_merge( $valid, array( 'subject' => 5 ) ) ) );
		unset( $valid['message'] );
		$this->assertNull( Finding::from_array( $valid ) );
	}

	public function test_replace_stores_a_group_and_get_returns_it() {
		$store = new Findings();
		$this->assertNull( $store->get( Findings::GROUP_IMPORT ) );

		$this->assertTrue( $store->replace( Findings::GROUP_IMPORT, array( $this->finding(), $this->finding( Finding::SKIPPED, 'other', 'x' ) ), array( 'source' => 'test' ) ) );

		$group = $store->get( Findings::GROUP_IMPORT );
		$this->assertIsArray( $group );
		$this->assertCount( 2, $group['findings'] );
		$this->assertContainsOnlyInstancesOf( Finding::class, $group['findings'] );
		$this->assertSame( 'other', $group['findings'][1]->code );
		$this->assertSame( array( 'source' => 'test' ), $group['meta'] );
		$this->assertSame( 0, $group['truncated'] );
		$this->assertEqualsWithDelta( time(), $group['time'], 5 );
	}

	public function test_stored_shape_is_versioned_and_never_autoloaded() {
		global $wpdb;
		( new Findings() )->replace( Findings::GROUP_HOOKS, array( $this->finding() ) );

		$stored = get_option( Names::OPTION_LEGACY_FINDINGS );
		$this->assertSame( 1, $stored['version'] );
		$this->assertSame( array( 'hooks' ), array_keys( $stored['groups'] ) );
		$this->assertSame( array( 'time', 'meta', 'truncated', 'findings' ), array_keys( $stored['groups']['hooks'] ) );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Names::OPTION_LEGACY_FINDINGS ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_replace_with_equal_findings_and_meta_does_not_write() {
		$store    = new Findings();
		$findings = array( $this->finding(), $this->finding( Finding::ADJUSTED, 'second', 'y' ) );

		$this->assertTrue( $store->replace( Findings::GROUP_IMPORT, $findings, array( 'n' => 1 ) ) );
		$this->assertSame( 1, $this->writes );

		$this->assertTrue( $store->replace( Findings::GROUP_IMPORT, $findings, array( 'n' => 1 ) ) );
		$this->assertTrue( ( new Findings() )->replace( Findings::GROUP_IMPORT, $findings, array( 'n' => 1 ) ) );
		$this->assertSame( 1, $this->writes, 'Equal findings and meta must not be written again.' );
	}

	public function test_replace_writes_when_findings_or_meta_change() {
		$store = new Findings();
		$store->replace( Findings::GROUP_IMPORT, array( $this->finding() ), array( 'n' => 1 ) );

		$store->replace( Findings::GROUP_IMPORT, array( $this->finding() ), array( 'n' => 2 ) );
		$this->assertSame( 2, $this->writes );

		$store->replace( Findings::GROUP_IMPORT, array( $this->finding( Finding::KEPT ) ), array( 'n' => 2 ) );
		$this->assertSame( 3, $this->writes );

		$this->assertSame( array( 'n' => 2 ), $store->get( Findings::GROUP_IMPORT )['meta'] );
	}

	public function test_replace_keeps_200_findings_and_counts_the_rest() {
		$findings = array();
		for ( $i = 0; $i < 250; $i++ ) {
			$findings[] = $this->finding( Finding::IMPORTED, 'code_' . $i, 'subject_' . $i );
		}

		$store = new Findings();
		$store->replace( Findings::GROUP_IMPORT, $findings );

		$group = $store->get( Findings::GROUP_IMPORT );
		$this->assertCount( 200, $group['findings'] );
		$this->assertSame( 50, $group['truncated'] );
		$this->assertSame( 'code_199', $group['findings'][199]->code );

		$store->replace( Findings::GROUP_IMPORT, $findings );
		$this->assertSame( 1, $this->writes, 'The same overflow must not be written again.' );

		$store->replace( Findings::GROUP_IMPORT, array_merge( $findings, array( $this->finding() ) ) );
		$this->assertSame( 51, $store->get( Findings::GROUP_IMPORT )['truncated'] );
		$this->assertSame( 2, $this->writes );
	}

	public function test_replace_and_add_reject_an_invalid_group_id() {
		$store = new Findings();
		foreach ( array( '', 'Bad', 'with-dash', 'digit1', str_repeat( 'a', 33 ) ) as $group ) {
			$this->assertFalse( $store->replace( $group, array( $this->finding() ) ), $group );
			$this->assertFalse( $store->add( $group, $this->finding() ), $group );
		}

		$this->assertSame( 0, $this->writes );
		$this->assertSame( array(), $store->all() );
		$this->assertTrue( $store->replace( str_repeat( 'a', 32 ), array() ) );
	}

	public function test_add_appends_a_finding_and_creates_the_group() {
		$store = new Findings();

		$this->assertTrue( $store->add( Findings::GROUP_CLASSES, $this->finding( Finding::IMPORTED, 'first', 'a' ) ) );
		$this->assertTrue( $store->add( Findings::GROUP_CLASSES, $this->finding( Finding::IMPORTED, 'first', 'b' ) ) );
		$this->assertTrue( $store->add( Findings::GROUP_CLASSES, $this->finding( Finding::IMPORTED, 'second', 'a' ) ) );

		$this->assertCount( 3, $store->get( Findings::GROUP_CLASSES )['findings'] );
		$this->assertSame( 3, $this->writes );
	}

	public function test_add_of_an_existing_code_and_subject_changes_nothing() {
		$store = new Findings();
		$store->replace( Findings::GROUP_HOOKS, array( new Finding( Finding::SKIPPED, 'a_code', 'a_subject', 'First.' ) ) );
		$before = get_option( Names::OPTION_LEGACY_FINDINGS );

		$this->assertTrue( $store->add( Findings::GROUP_HOOKS, new Finding( Finding::ADJUSTED, 'a_code', 'a_subject', 'Second.' ) ) );

		$this->assertSame( 1, $this->writes );
		$this->assertSame( $before, get_option( Names::OPTION_LEGACY_FINDINGS ) );
		$this->assertSame( 'First.', $store->get( Findings::GROUP_HOOKS )['findings'][0]->message );
	}

	public function test_add_beyond_the_limit_is_counted() {
		$findings = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$findings[] = $this->finding( Finding::IMPORTED, 'code_' . $i, 'subject' );
		}
		$store = new Findings();
		$store->replace( Findings::GROUP_IMPORT, $findings );

		$store->add( Findings::GROUP_IMPORT, $this->finding( Finding::IMPORTED, 'one_more', 'subject' ) );

		$group = $store->get( Findings::GROUP_IMPORT );
		$this->assertCount( 200, $group['findings'] );
		$this->assertSame( 1, $group['truncated'] );
	}

	/**
	 * A store holding a full group of 200 findings.
	 *
	 * @return Findings
	 */
	private function full_group_store(): Findings {
		$findings = array();
		for ( $i = 0; $i < Findings::MAX_PER_GROUP; $i++ ) {
			$findings[] = $this->finding( Finding::IMPORTED, 'code_' . $i, 'subject' );
		}
		$store = new Findings();
		$store->replace( Findings::GROUP_IMPORT, $findings );
		$this->writes = 0;

		return $store;
	}

	public function test_add_of_a_repeated_finding_beyond_the_limit_does_not_write_or_count_again() {
		$store = $this->full_group_store();

		$this->assertTrue( $store->add( Findings::GROUP_IMPORT, $this->finding( Finding::IMPORTED, 'one_more', 'subject' ) ) );
		$this->assertSame( 1, $this->writes );
		$this->assertTrue( $store->add( Findings::GROUP_IMPORT, $this->finding( Finding::SKIPPED, 'another', 'subject' ) ) );
		$this->assertSame( 2, $this->writes );
		$before = get_option( Names::OPTION_LEGACY_FINDINGS );

		// The repeat comes from the same store and from a fresh one (a later request).
		$this->assertTrue( $store->add( Findings::GROUP_IMPORT, $this->finding( Finding::IMPORTED, 'one_more', 'subject' ) ) );
		$this->assertTrue( ( new Findings() )->add( Findings::GROUP_IMPORT, $this->finding( Finding::SKIPPED, 'another', 'subject' ) ) );

		$this->assertSame( 2, $this->writes, 'A finding that was counted already must not be written again.' );
		$this->assertSame( $before, get_option( Names::OPTION_LEGACY_FINDINGS ) );
		$group = $store->get( Findings::GROUP_IMPORT );
		$this->assertCount( 200, $group['findings'] );
		$this->assertSame( 2, $group['truncated'] );
	}

	public function test_add_beyond_the_limit_counts_a_different_subject_of_the_same_code() {
		$store = $this->full_group_store();

		$store->add( Findings::GROUP_IMPORT, $this->finding( Finding::IMPORTED, 'one_more', 'first' ) );
		$store->add( Findings::GROUP_IMPORT, $this->finding( Finding::IMPORTED, 'one_more', 'second' ) );

		$this->assertSame( 2, $store->get( Findings::GROUP_IMPORT )['truncated'] );
		$this->assertSame( 2, $this->writes );
	}

	public function test_add_of_a_finding_that_replace_counted_does_not_write_or_count_again() {
		$findings = array();
		for ( $i = 0; $i < 250; $i++ ) {
			$findings[] = $this->finding( Finding::IMPORTED, 'code_' . $i, 'subject' );
		}
		$store = new Findings();
		$store->replace( Findings::GROUP_IMPORT, $findings );

		$this->assertTrue( $store->add( Findings::GROUP_IMPORT, $this->finding( Finding::IMPORTED, 'code_230', 'subject' ) ) );

		$this->assertSame( 1, $this->writes );
		$this->assertSame( 50, $store->get( Findings::GROUP_IMPORT )['truncated'] );
	}

	public function test_the_memory_of_counted_findings_is_stored_only_when_needed_and_stays_bounded() {
		$store = new Findings();
		$store->replace( Findings::GROUP_HOOKS, array( $this->finding() ) );
		$this->assertSame( array( 'time', 'meta', 'truncated', 'findings' ), array_keys( get_option( Names::OPTION_LEGACY_FINDINGS )['groups']['hooks'] ) );

		$findings = array();
		$total    = Findings::MAX_PER_GROUP + Findings::MAX_OVERFLOW_KEYS + 5;
		for ( $i = 0; $i < $total; $i++ ) {
			$findings[] = $this->finding( Finding::IMPORTED, 'code_' . $i, 'subject' );
		}
		$store->replace( Findings::GROUP_IMPORT, $findings );
		$writes = $this->writes;

		$stored = get_option( Names::OPTION_LEGACY_FINDINGS )['groups']['import'];
		$this->assertCount( Findings::MAX_OVERFLOW_KEYS, $stored['overflow'] );
		$this->assertSame( Findings::MAX_OVERFLOW_KEYS + 5, $stored['truncated'] );
		$this->assertSame( array( 'time', 'findings', 'meta', 'truncated' ), array_keys( $store->get( Findings::GROUP_IMPORT ) ), 'Readers see the same shape as before.' );

		// A remembered finding is not counted again; one beyond the bound is (the documented limit).
		$store->add( Findings::GROUP_IMPORT, $this->finding( Finding::IMPORTED, 'code_200', 'subject' ) );
		$this->assertSame( $writes, $this->writes );
		$store->add( Findings::GROUP_IMPORT, $this->finding( Finding::IMPORTED, 'code_' . ( $total - 1 ), 'subject' ) );
		$this->assertSame( $writes + 1, $this->writes );
		$this->assertCount( Findings::MAX_OVERFLOW_KEYS, get_option( Names::OPTION_LEGACY_FINDINGS )['groups']['import']['overflow'] );
	}

	public function test_a_malformed_memory_of_counted_findings_is_ignored() {
		$store = $this->full_group_store();
		$store->add( Findings::GROUP_IMPORT, $this->finding( Finding::IMPORTED, 'one_more', 'subject' ) );

		$option                                 = get_option( Names::OPTION_LEGACY_FINDINGS );
		$option['groups']['import']['overflow'] = array( 5, 'not a digest', array(), 'ABCDEF012345' );
		update_option( Names::OPTION_LEGACY_FINDINGS, $option, false );

		$group = $store->get( Findings::GROUP_IMPORT );
		$this->assertCount( 200, $group['findings'] );
		$this->assertSame( 1, $group['truncated'] );

		// Nothing valid is remembered, so the finding is counted again.
		$store->add( Findings::GROUP_IMPORT, $this->finding( Finding::IMPORTED, 'one_more', 'subject' ) );
		$this->assertSame( 2, $store->get( Findings::GROUP_IMPORT )['truncated'] );

		$option['groups']['import']['overflow'] = 'nope';
		update_option( Names::OPTION_LEGACY_FINDINGS, $option, false );
		$this->assertSame( 1, $store->get( Findings::GROUP_IMPORT )['truncated'] );
	}

	public function test_a_malformed_option_reads_as_empty() {
		$store     = new Findings();
		$malformed = array(
			'a string',
			42,
			array(),
			array( 'groups' => array() ),
			array(
				'version' => 2,
				'groups'  => array(),
			),
			array(
				'version' => 1,
				'groups'  => 'nope',
			),
			array(
				'version' => 1,
				'groups'  => array( 'import' => 'nope' ),
			),
			array(
				'version' => 1,
				'groups'  => array(
					'import' => array(
						'time'      => 'yesterday',
						'meta'      => array(),
						'truncated' => 0,
						'findings'  => array(),
					),
				),
			),
			array(
				'version' => 1,
				'groups'  => array(
					'Bad-Group' => array(
						'time'      => 1,
						'meta'      => array(),
						'truncated' => 0,
						'findings'  => array(),
					),
				),
			),
		);

		foreach ( $malformed as $index => $value ) {
			update_option( Names::OPTION_LEGACY_FINDINGS, $value, false );
			$this->assertSame( array(), $store->all(), 'Malformed value #' . $index );
			$this->assertNull( $store->get( 'import' ), 'Malformed value #' . $index );
			$this->assertSame( array(), $store->problems(), 'Malformed value #' . $index );
		}
	}

	public function test_a_malformed_finding_is_dropped_and_its_siblings_are_kept() {
		update_option(
			Names::OPTION_LEGACY_FINDINGS,
			array(
				'version' => 1,
				'groups'  => array(
					'import' => array(
						'time'      => 10,
						'meta'      => array(),
						'truncated' => 0,
						'findings'  => array(
							$this->finding()->to_array(),
							array(
								'status'  => 'nope',
								'code'    => 'a_code',
								'subject' => 's',
								'message' => 'm',
							),
							'not an array',
						),
					),
				),
			),
			false
		);

		$this->assertCount( 1, ( new Findings() )->get( 'import' )['findings'] );
	}

	public function test_problems_returns_only_adjusted_skipped_and_unsupported_with_their_group() {
		$store = new Findings();
		$store->replace(
			Findings::GROUP_IMPORT,
			array(
				$this->finding( Finding::IMPORTED, 'one', 's' ),
				$this->finding( Finding::KEPT, 'two', 's' ),
				$this->finding( Finding::ADJUSTED, 'three', 's' ),
			)
		);
		$store->replace(
			Findings::GROUP_HOOKS,
			array(
				$this->finding( Finding::SKIPPED, 'four', 's' ),
				$this->finding( Finding::UNSUPPORTED, 'five', 's' ),
			)
		);

		$problems = $store->problems();

		$this->assertSame(
			array( 'import:three', 'hooks:four', 'hooks:five' ),
			array_map(
				static function ( array $problem ): string {
					return $problem['group'] . ':' . $problem['finding']->code;
				},
				$problems
			)
		);
		$this->assertContainsOnlyInstancesOf( Finding::class, array_column( $problems, 'finding' ) );
	}

	public function test_all_returns_every_group() {
		$store = new Findings();
		$store->replace( Findings::GROUP_IMPORT, array( $this->finding() ) );
		$store->replace( Findings::GROUP_NOTICE, array() );

		$this->assertSame( array( 'import', 'notice' ), array_keys( $store->all() ) );
	}

	public function test_clear_removes_one_group_or_everything() {
		$store = new Findings();
		$store->replace( Findings::GROUP_IMPORT, array( $this->finding() ) );
		$store->replace( Findings::GROUP_HOOKS, array( $this->finding() ) );

		$store->clear( 'unknown' );
		$this->assertSame( 2, $this->writes );

		$store->clear( Findings::GROUP_IMPORT );
		$this->assertSame( array( 'hooks' ), array_keys( $store->all() ) );

		$store->clear( Findings::GROUP_HOOKS );
		$this->assertFalse( get_option( Names::OPTION_LEGACY_FINDINGS ) );

		$store->replace( Findings::GROUP_IMPORT, array( $this->finding() ) );
		$store->clear();
		$this->assertFalse( get_option( Names::OPTION_LEGACY_FINDINGS ) );
		$this->assertSame( array(), $store->all() );
	}

	public function test_a_store_with_another_option_name_is_independent() {
		$other = new Findings( 'rfrac_legacy_findings_other' );
		$other->replace( Findings::GROUP_IMPORT, array( $this->finding() ) );

		$this->assertSame( array(), ( new Findings() )->all() );
		$this->assertCount( 1, $other->all() );

		delete_option( 'rfrac_legacy_findings_other' );
	}
}
