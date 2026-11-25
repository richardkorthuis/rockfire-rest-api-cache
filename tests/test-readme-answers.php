<?php
/**
 * Tests for the readme answers about stored space and the restrictions of other plugins: they stay accurate about
 * flushed entries, the entries stored before a restriction and the moment a hit is served.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Services\Settings_Service;

/**
 * The FAQ of readme.txt is read as text. The hooks answer is covered in tests/legacy/test-hit-filter-pass.php.
 */
class Test_Readme_Answers extends WP_UnitTestCase {

	/**
	 * The answer to a FAQ question, without its heading.
	 *
	 * @param string $question The question, as written in readme.txt.
	 *
	 * @return string
	 */
	private function answer( string $question ): string {
		$readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$heading = '= ' . $question . ' =';
		$start   = strpos( $readme, $heading );
		$this->assertIsInt( $start, 'readme.txt has no question "' . $question . '".' );
		$start += strlen( $heading );
		$end    = strpos( $readme, "\n= ", $start );
		$this->assertIsInt( $end );

		return substr( $readme, $start, $end - $start );
	}

	/**
	 * The storage answer does not give 1.5 × Maximum entries × Maximum response size as a hard bound: a flush keeps
	 * the payloads of the flushed entries, which do not count towards the entry ceiling, until maintenance deletes them.
	 *
	 * @return void
	 */
	public function test_the_storage_answer_says_flushed_entries_keep_their_space_until_maintenance(): void {
		$answer = $this->answer( 'How much database space can the cache use?' );

		$this->assertStringContainsString( 'of active entries', $answer );
		$this->assertStringContainsString( 'A flush only marks entries as flushed', $answer );
		$this->assertStringContainsString( 'keep their payloads', $answer );
		$this->assertStringContainsString( 'until the hourly maintenance deletes them', $answer );
		$this->assertStringContainsString( 'that bound is not hard', $answer );
		$this->assertStringNotContainsString( 'The cache stores at most about 1.5 times', $answer, 'The old answer gave the bound for the whole table.' );
	}

	/**
	 * The description of the Maximum response size setting gives the bound for the active entries only.
	 *
	 * @return void
	 */
	public function test_the_payload_setting_description_names_the_flushed_entries(): void {
		$description = ( new Settings_Service( new Config() ) )->schema()['max_payload_kb']['description'];

		$this->assertStringContainsString( 'the active entries can take up to about', $description );
		$this->assertStringContainsString( 'flushed entries keep their space until the hourly maintenance deletes them', $description );
		$this->assertStringNotContainsString( 'the cache can take up to about', $description, 'The old description gave the bound for the whole table.' );
	}

	/**
	 * A route that an added restriction blocks is not "never cached": the entries stored earlier keep being served,
	 * so the answer tells the reader to flush the cache or exclude the route.
	 *
	 * @return void
	 */
	public function test_the_restrictions_answer_tells_the_reader_to_flush_or_exclude(): void {
		$answer = $this->answer( 'Do the REST API restrictions of other plugins apply to cached responses?' );

		$this->assertStringNotContainsString( 'is never cached', $answer );
		$this->assertStringContainsString( 'Entries stored before that', $answer );
		$this->assertStringContainsString( 'before the restriction was added in code', $answer );
		$this->assertStringContainsString( 'keep being served until they expire', $answer );
		$this->assertStringContainsString( 'flush the cache', $answer );
		$this->assertStringContainsString( 'wp rfrac flush --all', $answer );
		$this->assertStringContainsString( 'or exclude the route', $answer );
	}

	/**
	 * In fallback mode a hit is served on `plugins_loaded`, which comes after the regular plugins loaded: only the
	 * must-use file serves a hit before them.
	 *
	 * @return void
	 */
	public function test_the_restrictions_answer_places_a_fallback_hit_on_plugins_loaded(): void {
		$answer = $this->answer( 'Do the REST API restrictions of other plugins apply to cached responses?' );

		$this->assertStringNotContainsString( 'served before regular plugins load', $answer );
		$this->assertStringContainsString( 'The must-use file serves a hit before regular plugins load', $answer );
		$this->assertStringContainsString( 'in fallback mode, without the must-use file, a hit is served on `plugins_loaded`, before `init` and the REST API run', $answer );
	}
}
