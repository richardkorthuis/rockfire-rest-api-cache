<?php
/**
 * Tests for the bridge of the invalidation and capability hooks of the original plugin.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Admin\Admin;
use Rockfire\Rest_Api_Cache\Admin\Capability;
use Rockfire\Rest_Api_Cache\Admin\Notices;
use Rockfire\Rest_Api_Cache\Admin\Pages\Routes_Page;
use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Route_Discovery;
use Rockfire\Rest_Api_Cache\Failure_Recorder;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Api;
use Rockfire\Rest_Api_Cache\Legacy\Invalidation_Bridge;
use Rockfire\Rest_Api_Cache\Plugin;
use Rockfire\Rest_Api_Cache\Services\Route_Service;
use Rockfire\Rest_Api_Cache\Services\Settings_Service;

/**
 * Runs the bridge on its own (the arguments of every hook) and through real content changes (the flush decisions,
 * the pre-delete actions and the capability of the admin).
 *
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Invalidation_Bridge
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Module
 */
class Test_Invalidation_Bridge extends Rfrac_Matrix_Test_Case {

	use Rfrac_Admin_Test_Helpers;

	/**
	 * The bridge under test, called directly.
	 *
	 * @var Invalidation_Bridge
	 */
	private Invalidation_Bridge $bridge;

	/**
	 * The arguments of every `deprecated_hook_run` of the test.
	 *
	 * @var list<array{hook:string,replacement:string}>
	 */
	private array $deprecated = array();

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		delete_option( Failure_Recorder::OPTION );
		$this->bridge     = new Invalidation_Bridge();
		$this->deprecated = array();
		add_action(
			'deprecated_hook_run',
			function ( $hook, $replacement ): void {
				$this->deprecated[] = array(
					'hook'        => (string) $hook,
					'replacement' => (string) $replacement,
				);
			},
			10,
			2
		);
	}

	public function tear_down() {
		unregister_meta_key( 'post', 'foo', 'post' );
		unregister_meta_key( 'post', '_secret', 'post' );
		$this->reset_admin_request();
		$this->reset_menu_globals();
		unset( $GLOBALS['current_screen'] );
		delete_option( Failure_Recorder::OPTION );
		parent::tear_down();
	}

	/**
	 * A callback that throws.
	 *
	 * @return never
	 *
	 * @throws RuntimeException Always.
	 */
	public static function throwing(): never {
		throw new RuntimeException( 'legacy callback failed' );
	}

	/**
	 * Empty the globals the admin menu is built in.
	 *
	 * @return void
	 */
	private function reset_menu_globals(): void {
		foreach ( array( 'menu', 'submenu', '_wp_submenu_nopriv', '_registered_pages', '_parent_pages', 'admin_page_hooks', '_wp_real_parent_file' ) as $global ) {
			$GLOBALS[ $global ] = array();
		}
	}

	/**
	 * Hook recorders on legacy actions, and expect their deprecation notices.
	 *
	 * @param list<string> $hooks  Names of the hooks without the `wp_rest_cache/` prefix.
	 * @param bool         $expect Whether every one of them must fire.
	 *
	 * @return ArrayObject<int,list<mixed>> The calls, as `[ name, ...arguments ]`.
	 */
	private function listen( array $hooks, bool $expect = true ): ArrayObject {
		$calls = new ArrayObject();
		foreach ( $hooks as $hook ) {
			if ( $expect ) {
				$this->setExpectedDeprecated( 'wp_rest_cache/' . $hook );
			}
			add_action(
				'wp_rest_cache/' . $hook,
				static function ( ...$args ) use ( $calls, $hook ): void {
					$calls[] = array_merge( array( $hook ), $args );
				},
				10,
				3
			);
		}
		return $calls;
	}

	/**
	 * The names of the legacy hooks that raised a deprecation notice, once each.
	 *
	 * @return list<string>
	 */
	private function deprecated_hooks(): array {
		return array_values( array_unique( array_column( $this->deprecated, 'hook' ) ) );
	}

	// ---------- Wiring ----------

	public function test_the_module_subscribes_the_four_hooks_of_the_bridge_to_the_legacy_list(): void {
		$plugin = new Plugin();
		$plugin->register_legacy_hooks();

		$engine = array_column( $plugin->engine_hooks(), 'hook' );
		$wired  = array();
		foreach ( $plugin->legacy_hooks() as $hook ) {
			$wired[ $hook['hook'] ] = array( $hook['priority'], $hook['args'] );
		}

		$this->assertSame( array( 10, 6 ), $wired[ Hooks::FLUSH_ON_META_UPDATE ] ?? null );
		$this->assertSame( array( 10, 7 ), $wired[ Hooks::FLUSH_ON_SET_TERMS ] ?? null );
		$this->assertSame( array( 10, 3 ), $wired[ Hooks::INVALIDATION_TAGS ] ?? null );
		$this->assertSame( array( 10, 1 ), $wired[ Hooks::SETTINGS_CAPABILITY ] ?? null );
		$this->assertSame( array(), $engine, 'The module adds no engine hook.' );
	}

	public function test_the_hooks_of_the_bridge_are_the_ones_it_declares(): void {
		$this->assertSame(
			array(
				array( Hooks::FLUSH_ON_META_UPDATE, 'meta', 10, 6 ),
				array( Hooks::FLUSH_ON_SET_TERMS, 'set_terms', 10, 7 ),
				array( Hooks::INVALIDATION_TAGS, 'invalidation_tags', 10, 3 ),
				array( Hooks::SETTINGS_CAPABILITY, 'capability', 10, 1 ),
			),
			Invalidation_Bridge::hooks()
		);
		foreach ( Invalidation_Bridge::hooks() as $hook ) {
			$this->assertTrue( is_callable( array( $this->bridge, $hook[1] ) ), $hook[1] );
		}
	}

	// ---------- Flush decisions ----------

	public function test_the_meta_filters_get_the_arguments_of_the_original_with_a_meta_id_of_zero(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/flush_on_meta_update' );
		$this->setExpectedDeprecated( 'wp_rest_cache/flush_on_meta_update/post/foo' );
		$general  = array();
		$specific = array();
		add_filter(
			'wp_rest_cache/flush_on_meta_update',
			static function ( ...$args ) use ( &$general ) {
				$general = $args;
				return 'general';
			},
			10,
			6
		);
		add_filter(
			'wp_rest_cache/flush_on_meta_update/post/foo',
			static function ( ...$args ) use ( &$specific ) {
				$specific = $args;
				return false;
			},
			10,
			4
		);

		$flush = $this->bridge->meta( true, 'post', 7, 'foo', 'bar', 'updated' );

		$this->assertFalse( $flush, 'The answer of the key filter is the decision.' );
		$this->assertSame( array( true, 'post', 0, 7, 'foo', 'bar' ), $general, 'The engine decision seeds the general filter.' );
		$this->assertSame( array( 'general', 0, 7, 'bar' ), $specific, 'The key filter starts from the answer of the general one.' );
		$this->assertSame( array( 'wp_rest_cache/flush_on_meta_update', 'wp_rest_cache/flush_on_meta_update/post/foo' ), $this->deprecated_hooks() );
		$this->assertSame( array( Hooks::FLUSH_ON_META_UPDATE, Hooks::FLUSH_ON_META_UPDATE_KEY ), array_column( $this->deprecated, 'replacement' ) );
	}

	public function test_the_meta_decision_is_untouched_without_a_legacy_callback(): void {
		$this->assertTrue( $this->bridge->meta( true, 'post', 7, 'foo', 'bar', 'updated' ) );
		$this->assertFalse( $this->bridge->meta( false, 'term', 7, 'foo', 'bar', 'added' ) );
		$this->assertSame( array(), $this->deprecated, 'The notice only fires for a hooked callback.' );
	}

	public function test_a_meta_change_without_a_key_only_runs_the_general_filter(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/flush_on_meta_update' );
		add_filter( 'wp_rest_cache/flush_on_meta_update', '__return_false' );

		$this->assertFalse( $this->bridge->meta( true, 'post', 7, '', 'bar', 'updated' ) );
		$this->assertNull( Failure_Recorder::last() );
	}

	public function test_a_throwing_meta_callback_leaves_the_decision_of_the_engine(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/flush_on_meta_update' );
		add_filter( 'wp_rest_cache/flush_on_meta_update', array( self::class, 'throwing' ) );

		$this->assertTrue( $this->bridge->meta( true, 'post', 7, 'foo', 'bar', 'updated' ) );

		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'hook:wp_rest_cache/flush_on_meta_update', $last['context'] );
	}

	public function test_the_set_terms_filter_gets_the_arguments_of_the_engine_unchanged(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/flush_on_set_terms' );
		$seen = array();
		add_filter(
			'wp_rest_cache/flush_on_set_terms',
			static function ( ...$args ) use ( &$seen ) {
				$seen = $args;
				return false;
			},
			10,
			7
		);

		$flush = $this->bridge->set_terms( true, 12, array( 'news', 5 ), array( 5, 6 ), 'category', true, array( 1 ) );

		$this->assertFalse( $flush );
		$this->assertSame( array( true, 12, array( 'news', 5 ), array( 5, 6 ), 'category', true, array( 1 ) ), $seen );
		$this->assertSame( array( Hooks::FLUSH_ON_SET_TERMS ), array_column( $this->deprecated, 'replacement' ) );
	}

	public function test_the_set_terms_decision_is_untouched_without_a_legacy_callback(): void {
		$this->assertTrue( $this->bridge->set_terms( true, 12, array(), array(), 'category', false, array() ) );
		$this->assertFalse( $this->bridge->set_terms( false, 12, array(), array(), 'category', false, array() ) );
		$this->assertSame( array(), $this->deprecated );
	}

	// ---------- Pre-delete actions ----------

	/**
	 * Changes and the legacy actions each of them fires.
	 *
	 * @return array<string,array{0:list<string>,1:array<string,mixed>,2:list<list<mixed>>}>
	 */
	public static function changes(): array {
		return array(
			'a post'                     => array(
				array( 'list:page', 'post:5' ),
				array(
					'post_id'   => 5,
					'post_type' => 'page',
				),
				array(
					array( 'pre_delete_related_caches', 5, 'page' ),
					array( 'pre_delete_object_type_caches', 'page' ),
				),
			),
			'a term'                     => array(
				array( 'list:category', 'term:9' ),
				array(
					'term_id'  => 9,
					'taxonomy' => 'category',
				),
				array(
					array( 'pre_delete_related_caches', 9, 'category' ),
					array( 'pre_delete_object_type_caches', 'category' ),
				),
			),
			'several terms'              => array(
				array( 'list:category', 'term:3', 'term:4' ),
				array(
					'term_ids' => array( 3, 4 ),
					'taxonomy' => 'category',
				),
				array(
					array( 'pre_delete_related_caches_batch', array( 3, 4 ), 'category' ),
					array( 'pre_delete_object_type_caches', 'category' ),
				),
			),
			'a user'                     => array(
				array( 'list:users', 'user:3' ),
				array( 'user_id' => 3 ),
				array(
					array( 'pre_delete_related_caches', 3, 'user' ),
					array( 'pre_delete_object_type_caches', 'users' ),
				),
			),
			'a comment'                  => array(
				array( 'comment:8', 'list:comments' ),
				array(
					'comment_id' => 8,
					'post_id'    => 2,
				),
				array(
					array( 'pre_delete_related_caches', 8, 'comment' ),
					array( 'pre_delete_object_type_caches', 'comment' ),
				),
			),
			'a post and its terms'       => array(
				array( 'list:category', 'list:post' ),
				array(
					'post_id'   => 5,
					'post_type' => 'post',
					'term_ids'  => array( 3 ),
					'taxonomy'  => 'category',
				),
				array(
					array( 'pre_delete_related_caches', 5, 'post' ),
					array( 'pre_delete_related_caches_batch', array( 3 ), 'category' ),
					array( 'pre_delete_object_type_caches', 'category' ),
					array( 'pre_delete_object_type_caches', 'post' ),
				),
			),
			'lists of a custom type'     => array(
				array( 'list:product', 'list:wc-attribute' ),
				array(),
				array(
					array( 'pre_delete_object_type_caches', 'product' ),
					array( 'pre_delete_object_type_caches', 'wc-attribute' ),
				),
			),
			'ids that are strings'       => array(
				array( 'post:5' ),
				array(
					'post_id'   => '5',
					'post_type' => 'post',
				),
				array(
					array( 'pre_delete_related_caches', 5, 'post' ),
				),
			),
		);
	}

	/**
	 * @dataProvider changes
	 *
	 * @param list<string>        $tags     Tags of the change.
	 * @param array<string,mixed> $context  Context of the change.
	 * @param list<list<mixed>>   $expected The legacy actions that fire, in order.
	 */
	public function test_a_change_fires_the_pre_delete_actions_of_the_original_with_its_arguments( array $tags, array $context, array $expected ): void {
		$calls = $this->listen( array( 'pre_delete_related_caches', 'pre_delete_related_caches_batch', 'pre_delete_object_type_caches' ), false );
		foreach ( array_unique( array_column( $expected, 0 ) ) as $fired ) {
			$this->setExpectedDeprecated( 'wp_rest_cache/' . $fired );
		}

		$result = $this->bridge->invalidation_tags( $tags, 'any_event', $context );

		$this->assertSame( $tags, $result, 'The tags are handed back unchanged.' );
		$this->assertSame( $expected, $calls->getArrayCopy() );
		$this->assertSame( array( Hooks::INVALIDATION_TAGS ), array_values( array_unique( array_column( $this->deprecated, 'replacement' ) ) ) );
	}

	public function test_a_change_without_a_subject_or_a_list_fires_nothing(): void {
		$calls = $this->listen( array( 'pre_delete_related_caches', 'pre_delete_related_caches_batch', 'pre_delete_object_type_caches' ), false );
		$tags  = array( 'post:5', 'route:/wp/v2/posts', 'term-ref:3', 'custom:list:x' );

		$this->assertSame( $tags, $this->bridge->invalidation_tags( $tags, 'custom_event', array() ) );
		// A post ID without a post type, a term without a taxonomy and empty lists name no subject.
		$this->assertSame( array( 'list:' ), $this->bridge->invalidation_tags( array( 'list:' ), 'custom_event', array( 'post_id' => 5, 'term_id' => 3, 'term_ids' => array( 1 ), 'user_id' => 0 ) ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

		$this->assertSame( array(), $calls->getArrayCopy() );
		$this->assertSame( array(), $this->deprecated );
	}

	public function test_tags_or_a_context_of_the_wrong_type_are_returned_and_name_no_subject(): void {
		$calls = $this->listen( array( 'pre_delete_related_caches', 'pre_delete_related_caches_batch', 'pre_delete_object_type_caches' ), false );
		$this->setExpectedDeprecated( 'wp_rest_cache/pre_delete_object_type_caches' );

		$this->assertSame( 'post:5', $this->bridge->invalidation_tags( 'post:5', 'custom_event', array( 'post_id' => 5, 'post_type' => 'post' ) ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame( array(), $calls->getArrayCopy(), 'Tags that are not a list fire nothing.' );

		$this->assertSame( array( 'list:post' ), $this->bridge->invalidation_tags( array( 'list:post' ), 'custom_event', 'not a context' ) );
		$this->assertSame( array( array( 'pre_delete_object_type_caches', 'post' ) ), $calls->getArrayCopy(), 'A context that is not a list names no subject.' );
	}

	public function test_the_actions_of_a_change_fire_once_and_a_reentrant_change_fires_nothing(): void {
		$calls  = $this->listen( array( 'pre_delete_related_caches', 'pre_delete_object_type_caches' ) );
		$bridge = $this->bridge;
		add_action(
			'wp_rest_cache/pre_delete_related_caches',
			static function () use ( $bridge ): void {
				// What queueing another invalidation does: the filter of the tags runs again.
				$bridge->invalidation_tags( array( 'list:users', 'user:9' ), 'custom_event', array( 'user_id' => 9 ) );
			}
		);
		$tags    = array( 'list:post', 'post:5' );
		$context = array(
			'post_id'   => 5,
			'post_type' => 'post',
		);

		$this->assertSame( $tags, $this->bridge->invalidation_tags( $tags, 'post_saved', $context ) );
		$expected = array(
			array( 'pre_delete_related_caches', 5, 'post' ),
			array( 'pre_delete_object_type_caches', 'post' ),
		);
		$this->assertSame( $expected, $calls->getArrayCopy(), 'The nested change fired nothing.' );

		$this->bridge->invalidation_tags( $tags, 'post_saved', $context );
		$this->assertSame( array_merge( $expected, $expected ), $calls->getArrayCopy(), 'The bridge is open again after a change.' );
	}

	public function test_a_throwing_callback_does_not_close_the_bridge(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/pre_delete_related_caches' );
		add_action( 'wp_rest_cache/pre_delete_related_caches', array( self::class, 'throwing' ) );
		$calls = $this->listen( array( 'pre_delete_object_type_caches' ) );
		$tags  = array( 'list:post', 'post:5' );

		$this->assertSame(
			$tags,
			$this->bridge->invalidation_tags(
				$tags,
				'post_saved',
				array(
					'post_id'   => 5,
					'post_type' => 'post',
				)
			)
		);
		$last = Failure_Recorder::last();
		$this->assertNotNull( $last );
		$this->assertSame( 'hook:wp_rest_cache/pre_delete_related_caches', $last['context'] );
		$this->assertSame( array( array( 'pre_delete_object_type_caches', 'post' ) ), $calls->getArrayCopy() );

		$this->bridge->invalidation_tags( $tags, 'post_saved', array() );
		$this->assertCount( 2, $calls, 'The bridge still fires after a callback threw.' );
	}

	// ---------- Capability ----------

	public function test_the_capability_filter_gets_the_capability_of_the_engine(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/settings_capability' );
		$seen = array();
		add_filter(
			'wp_rest_cache/settings_capability',
			static function ( ...$args ) use ( &$seen ) {
				$seen = $args;
				return 'edit_pages';
			}
		);

		$this->assertSame( 'edit_pages', $this->bridge->capability( 'manage_options' ) );
		$this->assertSame( array( 'manage_options' ), $seen );
		$this->assertSame( array( Hooks::SETTINGS_CAPABILITY ), array_column( $this->deprecated, 'replacement' ) );
	}

	public function test_the_capability_is_untouched_without_a_legacy_callback(): void {
		$this->assertSame( 'manage_options', $this->bridge->capability( 'manage_options' ) );
		$this->assertSame( array(), $this->deprecated );
	}

	// ---------- The booted engine ----------

	public function test_a_legacy_false_on_flush_on_meta_update_stops_the_flush_of_a_meta_change(): void {
		register_post_meta(
			'post',
			'foo',
			array(
				'show_in_rest' => true,
				'single'       => true,
			)
		);
		$post_id = $this->factory()->post->create();
		$this->arm();
		$uri = '/wp/v2/posts/' . $post_id;
		$this->serve( $uri );
		$this->assert_active( array( $uri ) );

		add_filter( 'wp_rest_cache/flush_on_meta_update', '__return_false' );
		$this->setExpectedDeprecated( 'wp_rest_cache/flush_on_meta_update' );
		$this->change_and_commit( fn() => update_post_meta( $post_id, 'foo', 'bar' ) );

		$this->assert_active( array( $uri ) );
		$this->assertSame( array( 'wp_rest_cache/flush_on_meta_update' ), $this->deprecated_hooks() );
		$this->assertSame( Hooks::FLUSH_ON_META_UPDATE, $this->deprecated[0]['replacement'] );
		$this->assertSame( 'rfrac/flush_on_meta_update', $this->deprecated[0]['replacement'] );
	}

	public function test_a_meta_change_flushes_without_a_legacy_callback(): void {
		register_post_meta(
			'post',
			'foo',
			array(
				'show_in_rest' => true,
				'single'       => true,
			)
		);
		$post_id = $this->factory()->post->create();
		$this->arm();
		$uri = '/wp/v2/posts/' . $post_id;
		$this->serve( $uri );

		$this->change_and_commit( fn() => update_post_meta( $post_id, 'foo', 'bar' ) );

		$this->assert_flushed( array( $uri ), 'post:' . $post_id );
		$this->assertSame( array(), $this->deprecated, 'No legacy callback, no notice.' );
	}

	public function test_a_legacy_false_on_the_filter_of_a_meta_key_stops_the_flush_and_gets_a_meta_id_of_zero(): void {
		register_post_meta(
			'post',
			'_secret',
			array(
				'show_in_rest' => true,
				'single'       => true,
			)
		);
		$post_id = $this->factory()->post->create();
		$this->arm();
		$uri = '/wp/v2/posts/' . $post_id;
		$this->serve( $uri );

		$seen = array();
		add_filter(
			'wp_rest_cache/flush_on_meta_update/post/_secret',
			static function ( ...$args ) use ( &$seen ) {
				$seen = $args;
				return false;
			},
			10,
			4
		);
		$this->setExpectedDeprecated( 'wp_rest_cache/flush_on_meta_update/post/_secret' );
		$this->change_and_commit( fn() => update_post_meta( $post_id, '_secret', 'hidden' ) );

		$this->assert_active( array( $uri ) );
		$this->assertSame( array( true, 0, $post_id, 'hidden' ), $seen );
		$this->assertSame( 0, $seen[1] );
		$this->assertSame( array( Hooks::FLUSH_ON_META_UPDATE_KEY ), array_values( array_unique( array_column( $this->deprecated, 'replacement' ) ) ) );
	}

	public function test_a_legacy_false_on_flush_on_set_terms_keeps_the_entries_of_the_post(): void {
		$category = $this->factory()->category->create();
		$post_id  = $this->factory()->post->create();
		$this->arm();
		$uri = '/wp/v2/posts/' . $post_id;
		$this->serve( $uri );

		add_filter( 'wp_rest_cache/flush_on_set_terms', '__return_false' );
		$this->setExpectedDeprecated( 'wp_rest_cache/flush_on_set_terms' );
		$this->change_and_commit( fn() => wp_set_object_terms( $post_id, array( $category ), 'category' ) );

		$this->assert_active( array( $uri ) );
		$this->assertSame( array( 'wp_rest_cache/flush_on_set_terms' ), $this->deprecated_hooks() );
		$this->assertSame( Hooks::FLUSH_ON_SET_TERMS, $this->deprecated[0]['replacement'] );
	}

	public function test_a_change_of_the_terms_of_a_post_flushes_its_entries_without_a_legacy_callback(): void {
		$category = $this->factory()->category->create();
		$post_id  = $this->factory()->post->create();
		$this->arm();
		$uri = '/wp/v2/posts/' . $post_id;
		$this->serve( $uri );

		$this->change_and_commit( fn() => wp_set_object_terms( $post_id, array( $category ), 'category' ) );

		$this->assert_flushed( array( $uri ), 'post:' . $post_id );
	}

	public function test_a_post_save_fires_the_pre_delete_actions_of_the_original(): void {
		$post_id = $this->factory()->post->create();
		$this->arm();
		$calls = $this->listen( array( 'pre_delete_related_caches', 'pre_delete_object_type_caches' ) );

		$this->change_and_commit( fn() => wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Renamed' ) ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

		$this->assertContains( array( 'pre_delete_related_caches', $post_id, 'post' ), $calls->getArrayCopy() );
		$this->assertContains( array( 'pre_delete_object_type_caches', 'post' ), $calls->getArrayCopy() );
		$this->assertContains( Hooks::INVALIDATION_TAGS, array_column( $this->deprecated, 'replacement' ) );
	}

	public function test_the_actions_fire_once_per_queued_change_when_a_callback_flushes_and_queues_again(): void {
		$post_id = $this->factory()->post->create();
		$this->arm();
		$calls = $this->listen( array( 'pre_delete_related_caches', 'pre_delete_object_type_caches' ) );

		$queued = 0;
		add_filter(
			Hooks::INVALIDATION_TAGS,
			static function ( $tags, $event, $context ) use ( &$queued, $post_id ) {
				if ( is_array( $context ) && ( $context['post_id'] ?? 0 ) === $post_id && 'post' === ( $context['post_type'] ?? '' ) ) {
					++$queued;
				}
				return $tags;
			},
			20,
			3
		);
		add_action(
			'wp_rest_cache/pre_delete_related_caches',
			static function (): void {
				rfrac_flush_tag( 'post:999999' );
				Api::get()->invalidator()->queue(
					array( 'post:999998', 'list:legacy-recursion' ),
					'custom_event',
					array(
						'post_id'   => 999998,
						'post_type' => 'legacy-recursion',
					)
				);
			}
		);

		$this->change_and_commit( fn() => wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Renamed' ) ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

		$related = array_filter(
			$calls->getArrayCopy(),
			static fn( array $call ): bool => 'pre_delete_related_caches' === $call[0] && $post_id === $call[1]
		);
		$this->assertGreaterThan( 0, $queued, 'The post change was queued.' );
		$this->assertCount( $queued, $related, 'One legacy action per queued change.' );
		$this->assertNotContains( array( 'pre_delete_related_caches', 999998, 'legacy-recursion' ), $calls->getArrayCopy(), 'The nested change is not bridged.' );
		$this->assertNotContains( array( 'pre_delete_object_type_caches', 'legacy-recursion' ), $calls->getArrayCopy() );
	}

	// ---------- Capability of the admin ----------

	/**
	 * The admin with the routes page, as the plugin builds it.
	 *
	 * @param Routes_Page $page Receives the page.
	 *
	 * @return Admin
	 */
	private function routes_admin( ?Routes_Page &$page = null ): Admin {
		$this->new_rest_server();
		$config = new Config();
		$page   = new Routes_Page( new Notices(), new Route_Service( $config, new Route_Discovery() ), Plugin::instance()->cache_service(), new Settings_Service( $config ) );
		return new Admin( new Capability(), new Notices(), Plugin::instance()->status_service(), Plugin::instance()->cache_service(), array( $page ) );
	}

	public function test_the_legacy_capability_filter_changes_who_may_manage_the_cache(): void {
		$this->setExpectedDeprecated( 'wp_rest_cache/settings_capability' );
		add_filter( 'wp_rest_cache/settings_capability', static fn() => 'edit_posts' );
		$this->as_role( 'editor' );

		$this->assertSame( 'edit_posts', ( new Capability() )->get() );
		$this->assertTrue( ( new Capability() )->current_user_can() );

		$page  = null;
		$admin = $this->routes_admin( $page );
		$this->assertInstanceOf( Routes_Page::class, $page );
		$this->reset_menu_globals();
		$admin->register_menu();
		$this->assertSame( 'edit_posts', array_column( $GLOBALS['submenu']['rfrac-routes'] ?? array(), 1, 2 )['rfrac-routes'] ?? null, 'The editor gets the routes page in the menu.' );

		$this->set_request( array( 'page' => 'rfrac-routes' ) );
		$admin->load( $page );
		$this->assertSame( array( Hooks::SETTINGS_CAPABILITY ), array_values( array_unique( array_column( $this->deprecated, 'replacement' ) ) ) );
	}

	public function test_an_editor_may_not_open_the_routes_page_without_the_legacy_filter(): void {
		$this->as_role( 'editor' );
		$page  = null;
		$admin = $this->routes_admin( $page );
		$this->assertInstanceOf( Routes_Page::class, $page );
		$this->set_request( array( 'page' => 'rfrac-routes' ) );

		$this->assertSame( 'manage_options', ( new Capability() )->get() );
		$this->expectException( WPDieException::class );
		$admin->load( $page );
	}
}
