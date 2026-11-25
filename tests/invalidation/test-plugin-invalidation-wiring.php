<?php
/**
 * Tests that the plugin container wires every invalidation listener to one Invalidator.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Invalidation\Comment_Events;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Invalidation\Meta_Events;
use Rockfire\Rest_Api_Cache\Invalidation\Option_Events;
use Rockfire\Rest_Api_Cache\Invalidation\Post_Events;
use Rockfire\Rest_Api_Cache\Invalidation\Term_Events;
use Rockfire\Rest_Api_Cache\Invalidation\User_Events;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Storage\Database_Store;
use Rockfire\Rest_Api_Cache\Storage\Object_Cache_Store;

/**
 * Inspects the invalidation hooks a container registers (Plugin::register_invalidation_hooks(), part of the
 * engine hooks registered at boot).
 *
 * @covers \Rockfire\Rest_Api_Cache\Plugin
 */
class Test_Plugin_Invalidation_Wiring extends Rfrac_Test_Case {

	/**
	 * Fresh container with only its invalidation hooks registered.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	public function set_up() {
		parent::set_up();
		$this->plugin = new Plugin();
		$this->plugin->register_invalidation_hooks();
	}

	/**
	 * Read a private property.
	 *
	 * @param object $object   Object.
	 * @param string $property Property.
	 *
	 * @return mixed
	 */
	private function read( object $object, string $property ) {
		return ( new ReflectionProperty( $object, $property ) )->getValue( $object );
	}

	/**
	 * Hook => priorities registered by the container.
	 *
	 * @param Plugin $plugin Container.
	 *
	 * @return array<string,list<int>>
	 */
	private function registered( Plugin $plugin ): array {
		$out = array();
		foreach ( $plugin->engine_hooks() as $hook ) {
			$out[ $hook['hook'] ][] = $hook['priority'];
		}
		return $out;
	}

	public function test_the_invalidator_commits_at_shutdown_and_before_rest_echo(): void {
		$hooks = $this->registered( $this->plugin );
		$this->assertSame( array( 0 ), $hooks['shutdown'] );
		$this->assertSame( array( PHP_INT_MAX ), $hooks['rest_pre_echo_response'] );
	}

	public function test_nothing_is_built_until_a_hook_fires(): void {
		$this->assertNull( $this->read( $this->plugin, 'invalidator' ) );
		$this->assertSame( array(), $this->read( $this->plugin, 'listeners' ) );
	}

	/**
	 * @return array<string,array{0:string,1:list<string>}>
	 */
	public function listeners(): array {
		return array(
			'posts'    => array( Post_Events::class, array( 'wp_after_insert_post', 'transition_post_status', 'deleted_post', 'add_attachment', 'edit_attachment', 'wp_media_attach_action' ) ),
			'options'  => array( Option_Events::class, array( 'updated_option', 'added_option' ) ),
			'terms'    => array( Term_Events::class, array( 'created_term', 'edit_terms', 'edited_term', 'delete_term', 'added_term_relationship', 'deleted_term_relationships', 'set_object_terms', 'edited_term_taxonomy', 'edited_term_taxonomies' ) ),
			'users'    => array( User_Events::class, array( 'profile_update', 'user_register', 'deleted_user', 'add_user_to_blog', 'remove_user_from_blog' ) ),
			'comments' => array( Comment_Events::class, array( 'wp_insert_comment', 'edit_comment', 'transition_comment_status' ) ),
			'meta'     => array( Meta_Events::class, array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'updated_term_meta', 'updated_user_meta', 'updated_comment_meta' ) ),
		);
	}

	/**
	 * @dataProvider listeners
	 *
	 * @param string       $class Listener class.
	 * @param list<string> $hooks Hooks it must listen to.
	 */
	public function test_every_listener_is_hooked_once_and_uses_the_plugin_invalidator( string $class, array $hooks ): void {
		$registered = $this->registered( $this->plugin );
		foreach ( $hooks as $hook ) {
			$this->assertSame( array( 10 ), $registered[ $hook ] ?? array(), "$class on $hook" );
		}
		$listener = $this->plugin->listener( $class );
		$this->assertInstanceOf( $class, $listener );
		$this->assertSame( $listener, $this->plugin->listener( $class ), 'Built once.' );
		$this->assertSame( $this->plugin->invalidator(), $this->read( $listener, 'invalidator' ) );
	}

	public function test_the_media_attach_action_is_registered_at_priority_10_with_three_arguments(): void {
		$hooks = array_values( array_filter( $this->plugin->engine_hooks(), static fn( array $h ): bool => 'wp_media_attach_action' === $h['hook'] ) );
		$this->assertCount( 1, $hooks );
		$this->assertSame( 10, $hooks[0]['priority'] );
		$this->assertSame( 3, $hooks[0]['args'] );
	}

	public function test_the_booted_container_registers_the_same_invalidation_hooks(): void {
		$booted = $this->registered( Plugin::instance() );
		foreach ( $this->registered( $this->plugin ) as $hook => $priorities ) {
			foreach ( $priorities as $priority ) {
				$this->assertContains( $priority, $booted[ $hook ] ?? array(), $hook );
			}
		}
	}

	public function test_a_hook_resolves_the_listener_and_queues_on_the_container_invalidator(): void {
		$post_id = self::factory()->post->create();

		$pending = $this->plugin->invalidator()->pending();
		$this->assertContains( 'post:' . $post_id, $pending[ get_current_blog_id() ] ?? array() );
	}

	public function test_the_invalidator_flushes_through_the_container_store_of_the_current_site(): void {
		$this->set_compiled_config( array( 'store_mode' => 'database' ) );
		$resolve = $this->read( $this->plugin->invalidator(), 'store' );
		$this->assertInstanceOf( Closure::class, $resolve );
		$this->assertSame( $this->plugin->store(), $resolve() );
		$this->assertInstanceOf( Database_Store::class, $resolve() );

		// A store change (rfrac/config/store_changed resets the container stores) is followed.
		$this->set_compiled_config( array( 'store_mode' => 'object_cache' ) );
		( new ReflectionProperty( Plugin::class, 'stores' ) )->setValue( $this->plugin, array() );
		$this->assertInstanceOf( Object_Cache_Store::class, $resolve() );
		delete_option( Names::OPTION_CONFIG );
	}

	public function test_the_url_hasher_hashes_rest_urls_and_rejects_others(): void {
		$hasher = $this->read( $this->plugin->invalidator(), 'url_hasher' );
		$this->assertInstanceOf( Closure::class, $hasher );

		$posts = $hasher( rest_url( 'wp/v2/posts' ) );
		$this->assertIsArray( $posts );
		$this->assertCount( 1, $posts, 'A canonical URL has one form.' );
		$this->assertIsString( $posts[0] );
		$this->assertNotSame( '', $posts[0] );
		$this->assertSame( $posts, $hasher( rest_url( 'wp/v2/posts' ) ) );
		$this->assertNotSame( $posts, $hasher( rest_url( 'wp/v2/pages' ) ) );
		$this->assertSame( array(), $hasher( home_url( '/sample-page/' ) ) );
	}

	public function test_an_unknown_listener_class_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->plugin->listener( stdClass::class );
	}

	public function test_the_invalidator_is_its_own_listener(): void {
		$this->assertSame( $this->plugin->invalidator(), $this->plugin->listener( Invalidator::class ) );
	}
}
