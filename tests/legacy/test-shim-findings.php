<?php
/**
 * Tests for the findings the shims store for the calls they cannot honour.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Invalidation\Api;
use Rockfire\Rest_Api_Cache\Legacy\Constant_Fallback;
use Rockfire\Rest_Api_Cache\Legacy\Detector;
use Rockfire\Rest_Api_Cache\Legacy\Finding;
use Rockfire\Rest_Api_Cache\Legacy\Findings;
use Rockfire\Rest_Api_Cache\Legacy\Health;
use Rockfire\Rest_Api_Cache\Legacy\Shim_Notice;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Shim_Notice
 */
class Test_Shim_Findings extends Rfrac_E2e_Test_Case {

	/**
	 * The Api registered by the plugin boot.
	 *
	 * @var Api
	 */
	private Api $booted;

	/**
	 * Names of the options added, updated or deleted since set_up().
	 *
	 * @var string[]
	 */
	private array $option_writes = array();

	public function set_up() {
		parent::set_up();
		$booted = Api::get();
		$this->assertInstanceOf( Api::class, $booted, 'The plugin registers the Api while booting.' );
		$this->booted        = $booted;
		$this->option_writes = array();
		delete_option( Names::OPTION_LEGACY_FINDINGS );
		Shim_Notice::reset();
		( new ReflectionProperty( Plugin::class, 'stores' ) )->setValue( Plugin::instance(), array() );
		$this->configure( array( 'store_mode' => 'database' ) );

		$record = function ( $option ): void {
			$this->option_writes[] = (string) $option;
		};
		add_action( 'added_option', $record );
		add_action( 'updated_option', $record );
		add_action( 'deleted_option', $record );
	}

	public function tear_down() {
		Api::register( $this->booted );
		Shim_Notice::reset();
		delete_option( Names::OPTION_LEGACY_FINDINGS );
		( new ReflectionProperty( Plugin::class, 'stores' ) )->setValue( Plugin::instance(), array() );
		parent::tear_down();
	}

	/**
	 * The findings the classes group holds.
	 *
	 * @return Finding[]
	 */
	private function stored(): array {
		$group = ( new Findings() )->get( Findings::GROUP_CLASSES );

		return null === $group ? array() : $group['findings'];
	}

	public function test_the_first_unsupported_call_stores_one_classes_finding_with_one_write(): void {
		$this->setExpectedIncorrectUsage( 'Caching::get_cache' );
		$caching = Caching::get_instance();

		$this->assertFalse( $caching->get_cache( 'x' ) );
		$this->assertFalse( $caching->get_cache( 'x' ) );

		$findings = $this->stored();
		$this->assertCount( 1, $findings );
		$this->assertSame( Finding::UNSUPPORTED, $findings[0]->status );
		$this->assertSame( 'class_call', $findings[0]->code );
		$this->assertSame( 'Caching::get_cache', $findings[0]->subject );
		$this->assertSame( array( Names::OPTION_LEGACY_FINDINGS ), $this->option_writes, 'Exactly one option write.' );
	}

	public function test_the_message_names_the_method_and_the_reason_of_the_call(): void {
		$this->setExpectedIncorrectUsage( 'Caching::get_cache' );

		Caching::get_instance()->get_cache( 'x' );

		$findings = $this->stored();
		$this->assertCount( 1, $findings );
		$this->assertStringContainsString( 'Caching::get_cache', $findings[0]->message );
		$this->assertStringContainsString( 'Rockfire – REST API Cache does not expose its cached responses through this method.', $findings[0]->message );
	}

	public function test_a_method_unsupported_for_some_calls_only_does_not_claim_to_do_nothing(): void {
		$this->setExpectedIncorrectUsage( 'Caching::insert_cache_relation' );

		// Outside a REST dispatch (a cron job, say) a response cannot be tagged; inside one the method works.
		Caching::get_instance()->insert_cache_relation( 1, 5, 'post' );

		$findings = $this->stored();
		$this->assertCount( 1, $findings );
		$this->assertSame( 'Caching::insert_cache_relation', $findings[0]->subject );
		$this->assertStringContainsString( 'only be tagged while a REST request is being dispatched', $findings[0]->message );
		$this->assertStringNotContainsString( 'accepted and ignored', $findings[0]->message );
		$this->assertStringNotContainsString( 'does nothing', $findings[0]->message );
	}

	public function test_each_method_stores_the_reason_it_passed(): void {
		$this->setExpectedIncorrectUsage( 'Caching::insert_cache_relation' );
		$this->setExpectedIncorrectUsage( 'Caching::delete_related_caches' );
		$this->setExpectedIncorrectUsage( 'Caching::delete_all_caches' );
		$caching = Caching::get_instance();

		$caching->insert_cache_relation( 1, 5, 'post' );
		$caching->delete_related_caches( 5, 'list' );
		$caching->delete_all_caches( true, 'some-filter' );

		$messages = array();
		foreach ( $this->stored() as $finding ) {
			$messages[ $finding->subject ] = $finding->message;
		}

		$this->assertCount( 3, $messages );
		$this->assertStringContainsString( 'only be tagged while a REST request is being dispatched', $messages['Caching::insert_cache_relation'] );
		$this->assertStringContainsString( 'The object type and ID name no cache tag, so no cache can be flushed for them.', $messages['Caching::delete_related_caches'] );
		$this->assertStringContainsString( 'Rockfire – REST API Cache cannot flush a filtered set of caches', $messages['Caching::delete_all_caches'] );
		$this->assertCount( 3, array_unique( $messages ), 'No two methods share a message.' );
	}

	public function test_a_method_holds_one_finding_whatever_the_reason(): void {
		$this->setExpectedIncorrectUsage( 'A::n' );

		Shim_Notice::unsupported( 'A::n', 'First reason.' );
		// A later request: the notice memo is empty, the store holds the first condition.
		Shim_Notice::reset();
		$this->option_writes = array();
		Shim_Notice::unsupported( 'A::n', 'Second reason.' );

		$findings = $this->stored();
		$this->assertCount( 1, $findings );
		$this->assertStringContainsString( 'First reason.', $findings[0]->message );
		$this->assertStringNotContainsString( 'Second reason.', $findings[0]->message );
		$this->assertSame( array(), $this->option_writes, 'The second condition costs no write.' );
	}

	public function test_a_reason_with_a_percent_sign_is_stored_as_it_is(): void {
		$this->setExpectedIncorrectUsage( 'A::n' );

		Shim_Notice::unsupported( 'A::n', 'Cannot flush 100% of the caches (%s).' );

		$findings = $this->stored();
		$this->assertCount( 1, $findings );
		$this->assertStringContainsString( 'Cannot flush 100% of the caches (%s).', $findings[0]->message );
	}

	public function test_a_method_stored_in_an_earlier_request_costs_no_write(): void {
		$this->setExpectedIncorrectUsage( 'Caching::get_cache' );
		$caching = Caching::get_instance();

		$caching->get_cache( 'x' );
		$this->assertCount( 1, $this->stored() );

		// A new request: the notice memo is empty, the store still holds the finding.
		Shim_Notice::reset();
		$this->option_writes = array();

		$caching->get_cache( 'x' );

		$this->assertSame( array(), $this->option_writes, 'No option write.' );
		$this->assertCount( 1, $this->stored() );
	}

	public function test_every_method_gets_its_own_finding(): void {
		$this->setExpectedIncorrectUsage( 'Caching::get_cache' );
		$this->setExpectedIncorrectUsage( 'Caching::get_cache_data' );
		$caching = Caching::get_instance();

		$caching->get_cache( 'x' );
		$caching->get_cache_data( 'x' );
		$caching->get_cache( 'y' );

		$this->assertSame(
			array( 'Caching::get_cache', 'Caching::get_cache_data' ),
			array_map( static fn( Finding $finding ): string => $finding->subject, $this->stored() )
		);
	}

	public function test_a_working_method_stores_no_finding(): void {
		$this->setExpectedDeprecated( 'Caching::delete_related_caches' );

		Caching::get_instance()->delete_related_caches( 1, 'post' );

		$this->assertSame( array(), $this->stored() );
		$this->assertNull( ( new Findings() )->get( Findings::GROUP_CLASSES ) );
		$this->assertSame( 'absent', get_option( Names::OPTION_LEGACY_FINDINGS, 'absent' ) );
		$this->assertNotContains( Names::OPTION_LEGACY_FINDINGS, $this->option_writes );
	}

	public function test_a_deprecated_notice_stores_no_finding(): void {
		$this->setExpectedDeprecated( 'A::m' );

		Shim_Notice::deprecated( 'A::m', 'rfrac_flush_tag()' );

		$this->assertSame( 'absent', get_option( Names::OPTION_LEGACY_FINDINGS, 'absent' ) );
		$this->assertNotContains( Names::OPTION_LEGACY_FINDINGS, $this->option_writes );
	}

	public function test_the_subject_is_the_class_without_its_namespace(): void {
		$this->setExpectedIncorrectUsage( 'Some\Name\Space\Klass::method' );
		$this->setExpectedIncorrectUsage( 'Global_Klass::method' );

		Shim_Notice::unsupported( 'Some\Name\Space\Klass::method', 'Reason.' );
		Shim_Notice::unsupported( 'Global_Klass::method', 'Reason.' );

		$this->assertSame(
			array( 'Klass::method', 'Global_Klass::method' ),
			array_map( static fn( Finding $finding ): string => $finding->subject, $this->stored() )
		);
	}

	public function test_the_site_health_test_lists_the_finding(): void {
		$this->setExpectedIncorrectUsage( 'Caching::get_cache' );
		Caching::get_instance()->get_cache( 'x' );
		$health = new Health( new Findings(), new Constant_Fallback(), new Detector() );

		$tests  = $health->register_tests( array() );
		$result = $health->test_legacy();

		$this->assertArrayHasKey( Health::TEST, $tests['direct'], 'A stored finding is a trace of the original.' );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertIsString( $result['description'] );
		$this->assertStringContainsString( 'Caching::get_cache', $result['description'] );
		$this->assertStringContainsString( 'Rockfire – REST API Cache does not expose its cached responses through this method.', $result['description'] );
	}

	public function test_the_site_health_test_names_the_condition_of_a_conditional_method(): void {
		$this->setExpectedIncorrectUsage( 'Caching::insert_cache_relation' );
		Caching::get_instance()->insert_cache_relation( 1, 5, 'post' );
		$health = new Health( new Findings(), new Constant_Fallback(), new Detector() );

		$result = $health->test_legacy();

		$this->assertIsString( $result['description'] );
		$this->assertStringContainsString( 'Caching::insert_cache_relation', $result['description'] );
		$this->assertStringContainsString( 'only be tagged while a REST request is being dispatched', $result['description'] );
		$this->assertStringNotContainsString( 'accepted and ignored', $result['description'] );
	}
}
