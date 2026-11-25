<?php
/**
 * Tests for the word limits that bin/check-readme.php enforces on readme.txt.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/bin/lib/readme.php';

/**
 * Covers `rfrac_readme_words()`, `rfrac_readme_limits()`, `rfrac_readme_sections()` and
 * `rfrac_readme_word_failures()` in bin/lib/readme.php.
 *
 * Plain PHPUnit, without WordPress: the readme is a list of lines built in the test.
 */
class Test_Readme_Words extends TestCase {

	/**
	 * Builds a readme: the header, then each section as a heading line and the given body lines.
	 *
	 * @param array<string,string[]> $sections Section name => body lines.
	 * @return string[]
	 */
	private static function readme( array $sections ): array {
		$lines = array( '=== Example ===', 'Contributors: someone', '', 'A short description of the example.', '' );
		foreach ( $sections as $name => $body ) {
			$lines[] = "== {$name} ==";
			array_push( $lines, ...$body );
			$lines[] = '';
		}

		return $lines;
	}

	/**
	 * A body line of the given number of words.
	 *
	 * @param int $count Number of words.
	 * @return string
	 */
	private static function words( int $count ): string {
		return implode( ' ', array_fill( 0, $count, 'word' ) );
	}

	/**
	 * Words are separated by any whitespace, and surrounding whitespace counts for nothing.
	 *
	 * @return void
	 */
	public function test_words_are_split_on_whitespace(): void {
		$this->assertSame( 0, rfrac_readme_words( '' ) );
		$this->assertSame( 0, rfrac_readme_words( " \n\t " ) );
		$this->assertSame( 1, rfrac_readme_words( 'word' ) );
		$this->assertSame( 5, rfrac_readme_words( "  one two\tthree\n\nfour   five \n" ) );
		$this->assertSame( 3, rfrac_readme_words( '= Heading =' ), 'A heading line counts word by word.' );
	}

	/**
	 * The limits are 5,000 words for the FAQ and the changelog, 2,500 for every other section.
	 *
	 * @return void
	 */
	public function test_limits_follow_wordpress_org(): void {
		$this->assertSame(
			array(
				'Frequently Asked Questions' => 5000,
				'Changelog'                  => 5000,
				'*'                          => 2500,
			),
			rfrac_readme_limits()
		);
	}

	/**
	 * A body runs from the line after its heading to the next heading; the header is no section.
	 *
	 * @return void
	 */
	public function test_sections_run_from_heading_to_heading(): void {
		$sections = rfrac_readme_sections(
			self::readme(
				array(
					'Description'                => array( 'one two', 'three' ),
					'Frequently Asked Questions' => array( '= First question? =', 'An answer.', '= Second? =', 'Another answer here.' ),
					'Changelog'                  => array(),
				)
			)
		);

		$this->assertSame( array( 'Description', 'Frequently Asked Questions', 'Changelog' ), array_keys( $sections ) );
		$this->assertSame( 6, $sections['Description']['line'] );
		$this->assertSame( 3, $sections['Description']['words'] );
		$this->assertSame( 10, $sections['Frequently Asked Questions']['line'] );
		$this->assertSame( 0, $sections['Changelog']['words'] );
	}

	/**
	 * The question headings are part of the section body, and each question counts its own heading.
	 *
	 * @return void
	 */
	public function test_question_headings_are_counted(): void {
		$sections = rfrac_readme_sections(
			self::readme(
				array(
					'Frequently Asked Questions' => array( 'Intro words.', '= Why? =', 'Because.', '', 'Really so.', '= How now? =', 'Like this.' ),
				)
			)
		);
		$faq      = $sections['Frequently Asked Questions'];

		$this->assertSame( 2 + 3 + 1 + 2 + 4 + 2, $faq['words'] );
		$this->assertSame(
			array(
				'Why?'     => 3 + 1 + 2,
				'How now?' => 4 + 2,
			),
			$faq['subsections']
		);
	}

	/**
	 * The FAQ passes at 5,000 words and fails at 5,001, with the message of the check.
	 *
	 * @return void
	 */
	public function test_faq_limit_is_5000_words(): void {
		$faq = 'Frequently Asked Questions';

		$this->assertSame( array(), rfrac_readme_word_failures( self::readme( array( $faq => array( self::words( 5000 ) ) ) ) ) );
		$this->assertSame(
			array( 6 => "Section 'Frequently Asked Questions' has 5001 words; WordPress.org truncates it after 5000" ),
			rfrac_readme_word_failures( self::readme( array( $faq => array( self::words( 5001 ) ) ) ) )
		);
	}

	/**
	 * Question headings count towards the limit of the FAQ.
	 *
	 * @return void
	 */
	public function test_faq_headings_count_towards_the_limit(): void {
		$faq = 'Frequently Asked Questions';

		$this->assertSame( array(), rfrac_readme_word_failures( self::readme( array( $faq => array( '= Why? =', self::words( 4997 ) ) ) ) ) );
		$this->assertCount( 1, rfrac_readme_word_failures( self::readme( array( $faq => array( '= Why? =', self::words( 4998 ) ) ) ) ) );
	}

	/**
	 * A section that is not named takes the limit of 2,500 words: 2,501 fail, 2,500 pass.
	 *
	 * @return void
	 */
	public function test_other_sections_are_limited_to_2500_words(): void {
		foreach ( array( 'Description', 'Installation', 'Screenshots', 'Upgrade Notice' ) as $name ) {
			$this->assertSame( array(), rfrac_readme_word_failures( self::readme( array( $name => array( self::words( 2500 ) ) ) ) ), $name );
		}

		$this->assertSame(
			array( 6 => "Section 'Description' has 2501 words; WordPress.org truncates it after 2500" ),
			rfrac_readme_word_failures( self::readme( array( 'Description' => array( self::words( 2501 ) ) ) ) )
		);
	}

	/**
	 * The changelog has the limit of 5,000 words, not the default.
	 *
	 * @return void
	 */
	public function test_changelog_limit_is_5000_words(): void {
		$this->assertSame( array(), rfrac_readme_word_failures( self::readme( array( 'Changelog' => array( self::words( 5000 ) ) ) ) ) );
		$this->assertSame(
			array( 6 => "Section 'Changelog' has 5001 words; WordPress.org truncates it after 5000" ),
			rfrac_readme_word_failures( self::readme( array( 'Changelog' => array( self::words( 5001 ) ) ) ) )
		);
	}

	/**
	 * Every section is judged on its own words, against its own limit; a failure names its heading line.
	 *
	 * @return void
	 */
	public function test_each_section_is_judged_on_its_own(): void {
		$failures = rfrac_readme_word_failures(
			self::readme(
				array(
					'Description'                => array( self::words( 2501 ) ),
					'Installation'               => array( self::words( 2500 ) ),
					'Frequently Asked Questions' => array( self::words( 2501 ) ),
				)
			)
		);

		$this->assertSame( array( 6 ), array_keys( $failures ) );
	}
}
