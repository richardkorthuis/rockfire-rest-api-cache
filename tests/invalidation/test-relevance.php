<?php
/**
 * Tests for the invalidation relevance rules.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Relevance;

/**
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\Relevance
 */
class Test_Relevance extends Rfrac_Test_Case {

	public function set_up() {
		parent::set_up();
		$this->new_rest_server();
		register_post_type( 'rfrac_hidden', array( 'public' => true ) );
		register_post_type( 'rfrac_rest', array( 'public' => true, 'show_in_rest' => true ) );
		register_taxonomy( 'rfrac_tax_hidden', 'post', array() );
		register_taxonomy( 'rfrac_tax_rest', 'post', array( 'show_in_rest' => true ) );
	}

	public function tear_down() {
		unregister_post_type( 'rfrac_hidden' );
		unregister_post_type( 'rfrac_rest' );
		unregister_taxonomy( 'rfrac_tax_hidden' );
		unregister_taxonomy( 'rfrac_tax_rest' );
		parent::tear_down();
	}

	private function relevance(): Relevance {
		return new Relevance( new Config() );
	}

	public function test_post_type_with_rest_exposure_is_relevant(): void {
		$this->assertTrue( $this->relevance()->post_type( 'post' ) );
		$this->assertTrue( $this->relevance()->post_type( 'rfrac_rest' ) );
	}

	public function test_post_type_without_rest_or_rule_is_not_relevant(): void {
		$this->assertFalse( $this->relevance()->post_type( 'rfrac_hidden' ) );
	}

	public function test_unknown_post_type_is_not_relevant(): void {
		$this->assertFalse( $this->relevance()->post_type( 'does_not_exist' ) );
	}

	public function test_revision_is_never_relevant_even_when_forced(): void {
		add_filter( Hooks::INVALIDATE_POST_TYPE, '__return_true' );
		$this->assertFalse( $this->relevance()->post_type( 'revision' ) );
	}

	public function test_post_type_referenced_by_a_rule_is_relevant(): void {
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( $rules ) {
				$rules['/my/v1/things'] = array(
					'status'           => 'enabled',
					'flush_post_types' => array( 'rfrac_hidden' ),
					'flush_taxonomies' => array( 'rfrac_tax_hidden' ),
				);
				return $rules;
			}
		);
		$relevance = $this->relevance();
		$this->assertTrue( $relevance->post_type( 'rfrac_hidden' ) );
		$this->assertTrue( $relevance->taxonomy( 'rfrac_tax_hidden' ) );
	}

	public function test_post_type_filter_can_override(): void {
		add_filter(
			Hooks::INVALIDATE_POST_TYPE,
			static fn( $relevant, $type ) => 'post' === $type ? false : ( 'rfrac_hidden' === $type ? true : $relevant ),
			10,
			2
		);
		$this->assertFalse( $this->relevance()->post_type( 'post' ) );
		$this->assertTrue( $this->relevance()->post_type( 'rfrac_hidden' ) );
	}

	public function test_taxonomy_rules(): void {
		$relevance = $this->relevance();
		$this->assertTrue( $relevance->taxonomy( 'category' ) );
		$this->assertTrue( $relevance->taxonomy( 'rfrac_tax_rest' ) );
		$this->assertFalse( $relevance->taxonomy( 'rfrac_tax_hidden' ) );
		$this->assertFalse( $relevance->taxonomy( 'does_not_exist' ) );
	}

	public function test_taxonomy_filter_can_override(): void {
		add_filter(
			Hooks::INVALIDATE_TAXONOMY,
			static fn( $relevant, $taxonomy ) => 'rfrac_tax_hidden' === $taxonomy ? true : $relevant,
			10,
			2
		);
		$this->assertTrue( $this->relevance()->taxonomy( 'rfrac_tax_hidden' ) );
	}

	public function test_post_is_public(): void {
		$relevance = $this->relevance();
		$this->assertTrue( $relevance->post_is_public( self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) ) ) );
		$this->assertFalse( $relevance->post_is_public( self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) ) ) );
		$this->assertFalse( $relevance->post_is_public( self::factory()->post->create_and_get( array( 'post_status' => 'private' ) ) ) );
	}

	public function test_attachment_is_always_public(): void {
		$attachment = self::factory()->attachment->create_and_get( array( 'post_status' => 'inherit' ) );
		$this->assertTrue( $this->relevance()->post_is_public( $attachment ) );
	}

	public function test_rule_unions_are_memoised(): void {
		$calls = 0;
		add_filter(
			Hooks::ROUTE_RULES,
			static function ( $rules ) use ( &$calls ) {
				++$calls;
				return $rules;
			}
		);
		$relevance = $this->relevance();
		$relevance->post_type( 'rfrac_hidden' );
		$relevance->post_type( 'rfrac_hidden' );
		$relevance->taxonomy( 'rfrac_tax_hidden' );
		$relevance->taxonomy( 'rfrac_tax_hidden' );
		$this->assertSame( 1, $calls );
	}
}
