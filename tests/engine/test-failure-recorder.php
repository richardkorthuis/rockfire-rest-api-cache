<?php
/**
 * Tests for the failure recorder.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Failure_Recorder;

/**
 * @covers \Rockfire\Rest_Api_Cache\Failure_Recorder
 */
class Test_Failure_Recorder extends Rfrac_Test_Case {

	public function set_up() {
		parent::set_up();
		delete_option( Failure_Recorder::OPTION );
	}

	public function tear_down() {
		delete_option( Failure_Recorder::OPTION );
		parent::tear_down();
	}

	public function test_records_context_message_time_and_count_in_a_non_autoloaded_option(): void {
		global $wpdb;
		Failure_Recorder::record( 'lookup', new RuntimeException( "db\ndown" ) );

		$last = Failure_Recorder::last();
		$this->assertSame( 'lookup', $last['context'] );
		$this->assertSame( 'RuntimeException: db down', $last['message'] );
		$this->assertEqualsWithDelta( time(), $last['time'], 5 );
		$this->assertSame( 1, $last['count'] );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Failure_Recorder::OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_writes_at_most_once_per_minute(): void {
		Failure_Recorder::record( 'lookup', 'first' );
		Failure_Recorder::record( 'capture', 'second' );
		$this->assertSame( 'first', Failure_Recorder::last()['message'] );

		$stored         = get_option( Failure_Recorder::OPTION );
		$stored['time'] = time() - Failure_Recorder::THROTTLE;
		update_option( Failure_Recorder::OPTION, $stored, false );

		Failure_Recorder::record( 'capture', 'third' );
		$last = Failure_Recorder::last();
		$this->assertSame( 'capture', $last['context'] );
		$this->assertSame( 'third', $last['message'] );
		$this->assertSame( 2, $last['count'] );
	}

	public function test_message_is_truncated(): void {
		Failure_Recorder::record( 'compile', str_repeat( 'x', 2000 ) );
		$this->assertSame( 500, strlen( Failure_Recorder::last()['message'] ) );
	}

	public function test_a_message_cut_inside_a_three_byte_character_drops_the_partial_character(): void {
		Failure_Recorder::record( 'compile', str_repeat( 'x', 499 ) . '€' );

		$message = Failure_Recorder::last()['message'];
		$this->assertSame( str_repeat( 'x', 499 ), $message );
		$this->assertSame( 1, preg_match( '//u', $message ), 'Valid UTF-8.' );
		$this->assertNotSame( '', esc_html( $message ), 'The notice stays visible.' );
	}

	public function test_a_message_cut_inside_a_four_byte_character_drops_the_partial_character(): void {
		Failure_Recorder::record( 'compile', str_repeat( 'x', 498 ) . "\u{1F600}" );

		$message = Failure_Recorder::last()['message'];
		$this->assertSame( 498, strlen( $message ) );
		$this->assertSame( 1, preg_match( '//u', $message ), 'Valid UTF-8.' );
		$this->assertNotSame( '', esc_html( $message ) );
	}

	public function test_a_message_cut_inside_a_two_byte_character_drops_the_partial_character(): void {
		Failure_Recorder::record( 'compile', str_repeat( 'x', 499 ) . 'é' );

		$message = Failure_Recorder::last()['message'];
		$this->assertSame( str_repeat( 'x', 499 ), $message );
		$this->assertSame( 1, preg_match( '//u', $message ), 'Valid UTF-8.' );
	}

	public function test_a_message_that_ends_exactly_on_the_limit_is_stored_unchanged(): void {
		$text = str_repeat( 'x', 497 ) . '€';
		$this->assertSame( 500, strlen( $text ) );

		Failure_Recorder::record( 'compile', $text );
		$this->assertSame( $text, Failure_Recorder::last()['message'] );
	}

	public function test_a_message_cut_right_after_a_complete_character_keeps_it(): void {
		$text = str_repeat( 'x', 497 ) . '€' . '€';

		Failure_Recorder::record( 'compile', $text );
		$this->assertSame( str_repeat( 'x', 497 ) . '€', Failure_Recorder::last()['message'] );
	}

	public function test_a_long_multibyte_subject_is_cut_on_a_character_boundary(): void {
		Failure_Recorder::record( 'capture', 'x', str_repeat( '€', 400 ) );

		$subject = get_option( Failure_Recorder::OPTION )['subject'];
		$this->assertLessThanOrEqual( 500, strlen( $subject ) );
		$this->assertSame( 1, preg_match( '//u', $subject ), 'Valid UTF-8.' );
		$this->assertNotSame( '', esc_html( $subject ), 'The subject stays visible.' );
		$this->assertSame( str_repeat( '€', 166 ), $subject );
	}

	public function test_never_throws(): void {
		$boom = static function () {
			throw new RuntimeException( 'options down' );
		};
		add_filter( 'pre_option_' . Failure_Recorder::OPTION, $boom );
		Failure_Recorder::record( 'lookup', 'x' );
		Failure_Recorder::clear( array( 'lookup' ) );
		remove_filter( 'pre_option_' . Failure_Recorder::OPTION, $boom );
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_clear_needs_the_same_context(): void {
		Failure_Recorder::record( 'lookup', 'x' );

		Failure_Recorder::clear( array( 'capture', 'compile' ) );
		$this->assertSame( 'lookup', Failure_Recorder::last()['context'], 'Another context stays.' );

		Failure_Recorder::clear( array( 'capture', 'lookup' ) );
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_clear_needs_the_same_subject_unless_the_failure_has_none(): void {
		Failure_Recorder::record( 'capture', 'x', '/wp/v2/posts' );

		Failure_Recorder::clear( array( 'capture' ), '/wp/v2/pages' );
		$this->assertSame( 'capture', Failure_Recorder::last()['context'] ?? null, 'A success on another route says nothing about this one.' );
		Failure_Recorder::clear( array( 'capture' ) );
		$this->assertNotNull( Failure_Recorder::last(), 'Nor does a success without a route.' );

		Failure_Recorder::clear( array( 'capture' ), '/wp/v2/posts' );
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_a_failure_without_subject_is_cleared_by_a_success_on_any_subject(): void {
		Failure_Recorder::record( 'fallback_server', 'x' );
		Failure_Recorder::clear( array( 'fallback_server' ), '/wp/v2/posts' );
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_a_not_clearable_failure_is_only_dismissed(): void {
		Failure_Recorder::record( 'tags', 'Tag limit exceeded', '/wp/v2/posts', false );

		Failure_Recorder::clear( array( 'tags' ), '/wp/v2/posts' );
		$this->assertSame( 'tags', Failure_Recorder::last()['context'] ?? null );

		Failure_Recorder::dismiss();
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_a_flapping_failure_is_written_at_most_once_per_throttle_window(): void {
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		for ( $i = 0; $i < 10; $i++ ) {
			Failure_Recorder::record( 'capture', 'fails every other request', '/wp/v2/posts' );
			Failure_Recorder::clear( array( 'capture' ), '/wp/v2/posts' );
		}
		$recorder->stop();

		$writes = $recorder->matching( '/^\s*(INSERT|UPDATE|DELETE)\b.*rfrac_last_error/is' );
		$this->assertLessThanOrEqual( 2, count( $writes ), 'One write for the failure, one for clearing it; clearing does not reset the throttle.' );
		$this->assertNull( Failure_Recorder::last(), 'The failure is cleared and the later ones are throttled.' );

		// Once the window has passed, the next failure is shown again.
		$stored         = get_option( Failure_Recorder::OPTION );
		$stored['time'] = time() - Failure_Recorder::THROTTLE - 1;
		update_option( Failure_Recorder::OPTION, $stored, false );
		Failure_Recorder::record( 'capture', 'fails again', '/wp/v2/posts' );
		$this->assertSame( 'fails again', Failure_Recorder::last()['message'] ?? null );
		$this->assertSame( 2, Failure_Recorder::last()['count'] ?? null, 'The count survives clearing.' );
	}

	public function test_clear_without_a_recorded_failure_writes_nothing(): void {
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		Failure_Recorder::clear( array( 'capture' ) );
		Failure_Recorder::clear( array( 'capture' ) );
		$recorder->stop();
		$this->assertSame( array(), $recorder->matching( '/^\s*(INSERT|UPDATE|DELETE)\b/i' ), 'No write.' );
		$this->assertLessThanOrEqual( 1, count( $recorder->matching( '/^\s*SELECT/i' ) ), 'At most one read per request.' );
	}

	public function test_dismiss_hides_a_failure_of_any_context_and_keeps_the_throttle(): void {
		Failure_Recorder::record( 'hook:init', 'x' );
		Failure_Recorder::dismiss();
		$this->assertNull( Failure_Recorder::last() );

		Failure_Recorder::record( 'hook:init', 'right away again' );
		$this->assertNull( Failure_Recorder::last(), 'Still within the throttle window.' );
	}
}
