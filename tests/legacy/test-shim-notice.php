<?php
/**
 * Tests for the notices of the shims.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Legacy\Shim_Notice;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Shim_Notice
 */
class Test_Shim_Notice extends WP_UnitTestCase {

	/**
	 * Arguments of every `deprecated_function_run` since set_up().
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private $deprecated = array();

	/**
	 * Arguments of every `doing_it_wrong_run` since set_up().
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private $incorrect = array();

	public function set_up() {
		parent::set_up();
		Shim_Notice::reset();
		$this->deprecated = array();
		$this->incorrect  = array();

		add_action(
			'deprecated_function_run',
			function ( ...$args ) {
				$this->deprecated[] = $args;
			},
			10,
			3
		);
		add_action(
			'doing_it_wrong_run',
			function ( ...$args ) {
				$this->incorrect[] = $args;
			},
			10,
			3
		);
	}

	public function tear_down() {
		Shim_Notice::reset();
		parent::tear_down();
	}

	public function test_the_version() {
		$this->assertSame( '1.0.0', Shim_Notice::VERSION );
	}

	public function test_deprecated_fires_once_per_request_and_again_after_reset() {
		$this->setExpectedDeprecated( 'A::m' );

		Shim_Notice::deprecated( 'A::m', 'rfrac_flush_tag()' );
		Shim_Notice::deprecated( 'A::m', 'rfrac_flush_tag()' );

		$this->assertSame( array( array( 'A::m', 'rfrac_flush_tag()', '1.0.0' ) ), $this->deprecated );

		Shim_Notice::reset();
		Shim_Notice::deprecated( 'A::m', 'rfrac_flush_tag()' );

		$this->assertCount( 2, $this->deprecated );
		$this->assertSame( array( 'A::m', 'rfrac_flush_tag()', '1.0.0' ), $this->deprecated[1] );
	}

	public function test_deprecated_counts_each_method_on_its_own() {
		$this->setExpectedDeprecated( 'A::m' );
		$this->setExpectedDeprecated( 'B::m' );

		Shim_Notice::deprecated( 'A::m', 'rfrac_flush_tag()' );
		Shim_Notice::deprecated( 'B::m', 'rfrac_flush_url()' );
		Shim_Notice::deprecated( 'A::m', 'rfrac_flush_tag()' );

		$this->assertSame(
			array(
				array( 'A::m', 'rfrac_flush_tag()', '1.0.0' ),
				array( 'B::m', 'rfrac_flush_url()', '1.0.0' ),
			),
			$this->deprecated
		);
	}

	public function test_unsupported_fires_once_per_request_and_again_after_reset() {
		$this->setExpectedIncorrectUsage( 'A::n' );

		Shim_Notice::unsupported( 'A::n', 'Reason.' );
		Shim_Notice::unsupported( 'A::n', 'Reason.' );

		$this->assertCount( 1, $this->incorrect );
		$this->assertSame( 'A::n', $this->incorrect[0][0] );
		$this->assertSame( '1.0.0', $this->incorrect[0][2] );
		$this->assertSame( 'Reason. See the migration section of the plugin readme.', $this->incorrect[0][1] );

		Shim_Notice::reset();
		Shim_Notice::unsupported( 'A::n', 'Reason.' );

		$this->assertCount( 2, $this->incorrect );
	}

	public function test_unsupported_message_ends_with_the_readme_pointer() {
		$this->setExpectedIncorrectUsage( 'A::n' );

		Shim_Notice::unsupported( 'A::n', 'The shim cannot do that.' );

		$this->assertStringEndsWith( 'See the migration section of the plugin readme.', $this->incorrect[0][1] );
		$this->assertStringStartsWith( 'The shim cannot do that.', $this->incorrect[0][1] );
	}

	public function test_a_method_has_its_notice_per_kind() {
		$this->setExpectedDeprecated( 'A::m' );
		$this->setExpectedIncorrectUsage( 'A::m' );

		Shim_Notice::deprecated( 'A::m', 'rfrac_flush_tag()' );
		Shim_Notice::unsupported( 'A::m', 'Reason.' );

		$this->assertCount( 1, $this->deprecated );
		$this->assertCount( 1, $this->incorrect );
	}
}
