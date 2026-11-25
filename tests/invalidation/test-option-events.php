<?php
/**
 * Tests for the option invalidation listener.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Invalidation\Option_Events;

/**
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\Option_Events
 */
class Test_Option_Events extends Rfrac_Test_Case {

	/**
	 * Invalidator under test.
	 *
	 * @var Invalidator
	 */
	private Invalidator $inv;

	/**
	 * Spy store.
	 *
	 * @var Rfrac_Spy_Store
	 */
	private Rfrac_Spy_Store $spy;

	public function set_up() {
		parent::set_up();
		$this->spy = new Rfrac_Spy_Store();
		$this->inv = new Invalidator( $this->spy, static fn( string $url ): ?string => null );
		( new Option_Events( $this->inv ) )->register();
	}

	/**
	 * Pending tags of the current site.
	 *
	 * @return string[]
	 */
	private function pending(): array {
		return $this->inv->pending()[ get_current_blog_id() ] ?? array();
	}

	public function test_permalink_structure_change_flushes_everything_immediately(): void {
		$flushed = array();
		add_action(
			Hooks::FLUSHED,
			static function ( $type ) use ( &$flushed ) {
				$flushed[] = $type;
			}
		);

		update_option( 'permalink_structure', '/%postname%-rfrac/' ); // May create the option (add_option path).
		$this->spy->calls = array();
		$flushed = array();
		update_option( 'permalink_structure', '/%postname%-rfrac-2/' );
		$this->assertContains( 'all', $flushed ); // Also fired by the plugin's own listener.
		$this->assertSame( 1, count( $this->spy->calls_of( 'flush_all' ) ) );
	}

	public function test_home_and_siteurl_flush_everything(): void {
		update_option( 'home', 'http://example.org/rfrac-home' );
		update_option( 'siteurl', 'http://example.org/rfrac-site' );
		$this->assertSame( 2, count( $this->spy->calls_of( 'flush_all' ) ) );
	}

	public function test_blogname_change_queues_the_root_route(): void {
		update_option( 'blogname', 'Renamed blog' );
		$this->assertSame( array( 'route:/' ), $this->pending() );
		$this->assertSame( array(), $this->spy->calls );
	}

	public function test_same_value_queues_nothing(): void {
		update_option( 'blogname', 'Same' );
		$this->inv->commit();
		update_option( 'blogname', 'Same' );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_sticky_posts_change_queues_post_list_and_the_changed_posts(): void {
		$a = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$b = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		stick_post( $a );
		$this->assertSame( array( 'list:post', 'post:' . $a ), $this->pending() );
		$this->inv->commit();

		stick_post( $b );
		$this->assertSame( array( 'list:post', 'post:' . $b ), $this->pending() );
		$this->inv->commit();

		unstick_post( $a );
		$this->assertSame( array( 'list:post', 'post:' . $a ), $this->pending() );
	}

	public function test_first_creation_of_a_mapped_option_is_handled(): void {
		delete_option( 'permalink_structure' );
		$this->spy->calls = array();
		add_option( 'permalink_structure', '/%postname%/' );
		$this->assertCount( 1, $this->spy->calls_of( 'flush_all' ) );

		delete_option( 'sticky_posts' );
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->inv->commit();
		update_option( 'sticky_posts', array( $id ) ); // Missing option: goes through add_option().
		$this->assertSame( array( 'list:post', 'post:' . $id ), $this->pending() );
	}

	public function test_unmapped_option_does_nothing(): void {
		update_option( 'rfrac_unmapped_option', 'a' );
		update_option( 'rfrac_unmapped_option', 'b' );
		add_option( 'rfrac_unmapped_added', 'a' );
		$this->assertSame( array(), $this->pending() );
		$this->assertSame( array(), $this->spy->calls );
	}

	public function test_map_is_filtered_once_and_extensible(): void {
		$calls = 0;
		add_filter(
			Hooks::OPTION_INVALIDATION_MAP,
			static function ( $map ) use ( &$calls ) {
				++$calls;
				$map['rfrac_custom'] = array( 'list:post' );
				return $map;
			}
		);
		// Call a fresh listener directly: update_option() would also run the plugin's own listener, whose map
		// may or may not have been built by an earlier test.
		$events = new Option_Events( $this->inv );
		$events->on_updated_option( 'rfrac_custom', 'a', 'b' );
		$events->on_updated_option( 'rfrac_custom', 'b', 'c' );
		$events->on_added_option( 'rfrac_custom', 'd' );
		$this->assertSame( 1, $calls );
		$this->assertSame( array( 'list:post' ), $this->pending() );
	}

	public function test_option_events_pass_the_option_as_context(): void {
		$seen = array();
		add_filter(
			Hooks::INVALIDATION_TAGS,
			static function ( $tags, $event, $context ) use ( &$seen ) {
				$seen = array( $event, $context );
				return $tags;
			},
			10,
			3
		);
		( new Option_Events( $this->inv ) )->on_updated_option( 'blogname', 'a', 'b' );
		$this->assertSame( array( Invalidator::EVENT_OPTION_UPDATED, array( 'option' => 'blogname' ) ), $seen );
	}
}
