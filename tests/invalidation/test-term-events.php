<?php
/**
 * Tests for the term invalidation listeners and the ancestor resolver.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Invalidation\Relevance;
use Rockfire\Rest_Api_Cache\Invalidation\Term_Ancestry;
use Rockfire\Rest_Api_Cache\Invalidation\Term_Events;
use Rockfire\Rest_Api_Cache\Storage\Database_Store;
use Rockfire\Rest_Api_Cache\Storage\Repository;

/**
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\Term_Events
 * @covers \Rockfire\Rest_Api_Cache\Invalidation\Term_Ancestry
 */
class Test_Term_Events extends Rfrac_Test_Case {

	/**
	 * Invalidator under test.
	 *
	 * @var Invalidator
	 */
	private Invalidator $inv;

	/**
	 * Listener under test.
	 *
	 * @var Term_Events
	 */
	private Term_Events $events;

	public function set_up() {
		parent::set_up();
		$this->inv    = new Invalidator( new Rfrac_Spy_Store(), static fn( string $url ): ?string => null );
		$this->events = new Term_Events( $this->inv, new Relevance( new Config() ), new Term_Ancestry() );
		$this->events->register();
	}

	/**
	 * Pending tags of the current site.
	 *
	 * @return string[]
	 */
	private function pending(): array {
		return $this->inv->pending()[ get_current_blog_id() ] ?? array();
	}

	/**
	 * Sorted tags for assertions.
	 *
	 * @param string ...$tags Tags.
	 *
	 * @return string[]
	 */
	private function sorted( string ...$tags ): array {
		sort( $tags, SORT_STRING );
		return $tags;
	}

	/**
	 * Create a post without categories and forget what its creation queued.
	 *
	 * @param string $status Status.
	 *
	 * @return int
	 */
	private function make_post( string $status = 'publish' ): int {
		$id = self::factory()->post->create( array( 'post_status' => $status ) );
		wp_set_object_terms( $id, array(), 'category' );
		$this->inv->commit();
		return $id;
	}

	public function test_filter_returning_false_skips_the_assignment_tags(): void {
		$post_id = $this->make_post();
		$tag_id  = self::factory()->tag->create();
		$this->inv->commit();
		add_filter( Hooks::FLUSH_ON_SET_TERMS, '__return_false' );
		wp_set_object_terms( $post_id, array( $tag_id ), 'post_tag' );
		$this->assertNotContains( 'post:' . $post_id, $this->pending() );
		$this->assertNotContains( 'list:post', $this->pending() );
		// The recount of the published post's new tag is a separate event...
		$this->assertSame( $this->sorted( 'term:' . $tag_id, 'list:post_tag' ), $this->pending() );
	}

	public function test_filter_returning_false_plus_an_event_filter_skips_everything(): void {
		$post_id = $this->make_post();
		$tag_id  = self::factory()->tag->create();
		$this->inv->commit();
		add_filter( Hooks::FLUSH_ON_SET_TERMS, '__return_false' );
		add_filter(
			Hooks::INVALIDATION_TAGS,
			static fn( $tags, $event ) => Invalidator::EVENT_TERM_COUNTED === $event ? array() : $tags,
			10,
			2
		);
		wp_set_object_terms( $post_id, array( $tag_id ), 'post_tag' );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_filter_must_return_strict_true(): void {
		$post_id = $this->make_post();
		$tag_id  = self::factory()->tag->create();
		$this->inv->commit();
		add_filter( Hooks::FLUSH_ON_SET_TERMS, static fn() => 1 );
		wp_set_object_terms( $post_id, array( $tag_id ), 'post_tag' );
		$this->assertNotContains( 'post:' . $post_id, $this->pending() );
	}

	public function test_filter_receives_all_arguments(): void {
		$seen    = null;
		$post_id = $this->make_post();
		$tag_id  = self::factory()->tag->create();
		add_filter(
			Hooks::FLUSH_ON_SET_TERMS,
			static function ( ...$args ) use ( &$seen ) {
				$seen = $args;
				return true;
			},
			10,
			7
		);
		$this->events->on_set_object_terms( $post_id, array( $tag_id ), array( 5 ), 'post_tag', true, array( 4 ) );
		$this->assertSame( array( true, $post_id, array( $tag_id ), array( 5 ), 'post_tag', true, array( 4 ) ), $seen );
	}

	public function test_same_terms_again_queue_nothing_and_run_no_queries(): void {
		$post_id = $this->make_post();
		$tag_id  = self::factory()->tag->create();
		wp_set_object_terms( $post_id, array( $tag_id ), 'post_tag' );
		$this->inv->commit();
		$tt = wp_get_object_terms( $post_id, 'post_tag', array( 'fields' => 'tt_ids' ) );

		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$this->events->on_set_object_terms( $post_id, array( $tag_id ), array_reverse( $tt ), 'post_tag', false, $tt );
		$recorder->stop();

		$this->assertSame( array(), $this->pending() );
		$this->assertSame( array(), $recorder->matching( '/./' ) );
	}

	public function test_new_tag_on_a_published_post_queues_term_list_post_and_post_list(): void {
		$post_id = $this->make_post();
		$tag_id  = self::factory()->tag->create();
		$this->inv->commit();
		wp_set_object_terms( $post_id, array( $tag_id ), 'post_tag' );
		$this->assertSame( $this->sorted( 'term:' . $tag_id, 'list:post_tag', 'post:' . $post_id, 'list:post' ), $this->pending() );
	}

	public function test_append_queues_only_the_added_term(): void {
		$post_id = $this->make_post();
		$tag_a   = self::factory()->tag->create();
		$tag_b   = self::factory()->tag->create();
		wp_set_object_terms( $post_id, array( $tag_a ), 'post_tag' );
		$this->inv->commit();
		wp_set_object_terms( $post_id, array( $tag_b ), 'post_tag', true );
		$this->assertContains( 'term:' . $tag_b, $this->pending() );
		$this->assertNotContains( 'term:' . $tag_a, $this->pending() );
	}

	public function test_replace_queues_added_and_removed_terms(): void {
		$post_id = $this->make_post();
		$tag_a   = self::factory()->tag->create();
		$tag_b   = self::factory()->tag->create();
		wp_set_object_terms( $post_id, array( $tag_a ), 'post_tag' );
		$this->inv->commit();
		wp_set_object_terms( $post_id, array( $tag_b ), 'post_tag' );
		$this->assertContains( 'term:' . $tag_a, $this->pending() );
		$this->assertContains( 'term:' . $tag_b, $this->pending() );
	}

	public function test_append_without_new_terms_queues_nothing(): void {
		$post_id = $this->make_post();
		$this->events->on_set_object_terms( $post_id, array(), array( 1 ), 'category', true, array( 1, 2 ) );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_unknown_taxonomy_queues_nothing(): void {
		$post_id = $this->make_post();
		$this->events->on_set_object_terms( $post_id, array(), array( 1 ), 'rfrac_definitely_not_a_taxonomy', false, array() );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_private_taxonomy_outside_rest_is_not_relevant(): void {
		register_taxonomy( 'rfrac_hidden_tax', 'post', array( 'public' => false ) );
		$post_id = $this->make_post();
		$term    = wp_insert_term( 'hidden', 'rfrac_hidden_tax' );
		$this->inv->commit();
		wp_set_object_terms( $post_id, array( (int) $term['term_id'] ), 'rfrac_hidden_tax' );
		wp_update_term( (int) $term['term_id'], 'rfrac_hidden_tax', array( 'slug' => 'moved' ) );
		$this->assertSame( array(), $this->pending() );
		unregister_taxonomy( 'rfrac_hidden_tax' );
	}

	public function test_public_taxonomy_outside_rest_flushes_the_posts_referring_to_it_only(): void {
		register_taxonomy( 'rfrac_public_tax', 'post', array( 'public' => true ) );
		$post_id = $this->make_post();
		$term    = wp_insert_term( 'public', 'rfrac_public_tax' );
		$this->inv->commit();

		wp_set_object_terms( $post_id, array( (int) $term['term_id'] ), 'rfrac_public_tax' );
		$this->assertSame( $this->sorted( 'post:' . $post_id, 'list:post' ), $this->pending(), 'class_list changed; no term or list tags.' );
		$this->inv->commit();

		wp_update_term( (int) $term['term_id'], 'rfrac_public_tax', array( 'slug' => 'moved' ) );
		$this->assertSame( array( 'term-ref:' . $term['term_id'] ), $this->pending() );
		$this->inv->commit();

		wp_update_term( (int) $term['term_id'], 'rfrac_public_tax', array( 'name' => 'Renamed' ) );
		$this->assertSame( array(), $this->pending() );
		unregister_taxonomy( 'rfrac_public_tax' );
	}

	/**
	 * A spy invalidator with its own term listener, detached from the one of set_up().
	 *
	 * @return array{0:Invalidator,1:Rfrac_Spy_Store}
	 */
	private function own_invalidator(): array {
		remove_all_actions( 'added_term_relationship' );
		remove_all_actions( 'deleted_term_relationships' );
		remove_all_actions( 'set_object_terms' );
		remove_all_actions( 'edited_term_taxonomy' );
		$spy    = new Rfrac_Spy_Store();
		$inv    = new Invalidator( $spy, static fn( string $url ): ?string => null );
		$events = new Term_Events( $inv, new Relevance( new Config() ), new Term_Ancestry() );
		foreach ( Term_Events::hooks() as $hook ) {
			if ( in_array( $hook[0], array( 'added_term_relationship', 'deleted_term_relationships', 'set_object_terms', 'edited_term_taxonomy' ), true ) ) {
				add_filter( $hook[0], array( $events, $hook[1] ), $hook[2], $hook[3] );
			}
		}
		return array( $inv, $spy );
	}

	/**
	 * Every tag the spy store was asked to flush.
	 *
	 * @param Rfrac_Spy_Store $spy Spy.
	 *
	 * @return string[]
	 */
	private function flushed( Rfrac_Spy_Store $spy ): array {
		$tags = array();
		foreach ( $spy->calls_of( 'flush_by_tags' ) as $chunk ) {
			$tags = array_merge( $tags, $chunk );
		}
		return $tags;
	}

	/**
	 * Data provider: what makes the invalidator commit in the middle of wp_set_object_terms().
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function mid_call_commits(): array {
		return array(
			'after shutdown'     => array( 'shutdown' ),
			'at the threshold 1' => array( 'threshold' ),
		);
	}

	/**
	 * @dataProvider mid_call_commits
	 *
	 * @param string $trigger What commits mid-call.
	 */
	public function test_a_rejected_set_object_terms_flushes_nothing_even_when_a_commit_runs_mid_call( string $trigger ): void {
		$post_id = $this->make_post();
		$tag_a   = self::factory()->tag->create();
		$tag_b   = self::factory()->tag->create();
		wp_set_object_terms( $post_id, array( $tag_a ), 'post_tag' );
		list( $inv, $spy ) = $this->own_invalidator();
		add_filter( Hooks::FLUSH_ON_SET_TERMS, '__return_false' );
		if ( 'shutdown' === $trigger ) {
			$inv->commit_action();
		} else {
			add_filter( Hooks::AUTO_COMMIT_THRESHOLD, static fn() => 1 );
		}

		wp_set_object_terms( $post_id, array( $tag_b ), 'post_tag' ); // Removes a (and recounts it) before set_object_terms.
		$inv->commit();
		$this->assertNotContains( 'post:' . $post_id, $this->flushed( $spy ) );
	}

	/**
	 * @dataProvider mid_call_commits
	 *
	 * @param string $trigger What commits mid-call.
	 */
	public function test_terms_added_before_a_mid_call_commit_keep_their_ancestors( string $trigger ): void {
		$root    = self::factory()->category->create();
		$child   = self::factory()->category->create( array( 'parent' => $root ) );
		$old     = self::factory()->category->create();
		$post_id = $this->make_post();
		wp_set_object_terms( $post_id, array( $old ), 'category' );
		list( $inv, $spy ) = $this->own_invalidator();
		if ( 'shutdown' === $trigger ) {
			$inv->commit_action();
		} else {
			add_filter( Hooks::AUTO_COMMIT_THRESHOLD, static fn() => 1 );
		}

		wp_set_object_terms( $post_id, array( $child ), 'category' ); // Inserts child, then removes old (a commit runs there).
		$inv->commit();
		$flushed = $this->flushed( $spy );
		foreach ( array( 'term:' . $child, 'term:' . $root, 'term:' . $old, 'post:' . $post_id ) as $tag ) {
			$this->assertContains( $tag, $flushed );
		}
	}

	public function test_a_bulk_assignment_resolves_each_term_once(): void {
		$cat   = self::factory()->category->create();
		$posts = self::factory()->post->create_many( 5 );
		foreach ( $posts as $id ) {
			wp_set_object_terms( $id, array(), 'category' );
		}
		$this->inv->commit();

		$lookups = 0;
		add_filter(
			'get_terms_args',
			static function ( $args ) use ( &$lookups ) {
				if ( ! empty( $args['term_taxonomy_id'] ) && empty( $args['number'] ) ) {
					++$lookups; // The listener's lookups (get_term_by() asks for one term).
				}
				return $args;
			}
		);
		foreach ( $posts as $id ) {
			wp_set_object_terms( $id, array( $cat ), 'category' );
		}
		$this->inv->commit();
		foreach ( $posts as $id ) {
			wp_set_object_terms( $id, array(), 'category' );
		}
		$this->inv->commit();
		$this->assertSame( 1, $lookups, 'One lookup for the term over ten changes.' );
	}

	public function test_a_parent_change_refreshes_the_memoised_ancestors(): void {
		$root_a = self::factory()->category->create();
		$root_b = self::factory()->category->create();
		$child  = self::factory()->category->create( array( 'parent' => $root_a ) );
		$post_a = $this->make_post();
		$post_b = $this->make_post();
		wp_set_object_terms( $post_a, array( $child ), 'category' );
		$this->assertContains( 'term:' . $root_a, $this->pending() );
		$this->inv->commit();

		wp_update_term( $child, 'category', array( 'parent' => $root_b ) );
		$this->inv->commit();
		wp_set_object_terms( $post_b, array( $child ), 'category' );
		$pending = $this->pending();
		$this->assertContains( 'term:' . $root_b, $pending );
		$this->assertNotContains( 'term:' . $root_a, $pending );
	}

	public function test_hierarchical_term_expands_to_all_ancestors(): void {
		$root    = self::factory()->category->create();
		$parent  = self::factory()->category->create( array( 'parent' => $root ) );
		$child   = self::factory()->category->create( array( 'parent' => $parent ) );
		$post_id = $this->make_post();
		$this->inv->commit();

		wp_set_object_terms( $post_id, array( $child ), 'category' );
		$this->assertSame(
			$this->sorted( 'term:' . $child, 'term:' . $parent, 'term:' . $root, 'list:category', 'post:' . $post_id, 'list:post' ),
			$this->pending()
		);
	}

	public function test_shared_ancestor_is_queued_once(): void {
		$root    = self::factory()->category->create();
		$c1      = self::factory()->category->create( array( 'parent' => $root ) );
		$c2      = self::factory()->category->create( array( 'parent' => $root ) );
		$post_id = $this->make_post();
		$this->inv->commit();

		wp_set_object_terms( $post_id, array( $c1, $c2 ), 'category' );
		$this->assertCount( 1, array_keys( $this->pending(), 'term:' . $root, true ) );
		$this->assertContains( 'term:' . $c1, $this->pending() );
		$this->assertContains( 'term:' . $c2, $this->pending() );
	}

	public function test_draft_post_queues_term_and_list_tags_only(): void {
		$post_id = $this->make_post( 'draft' );
		$tag_id  = self::factory()->tag->create();
		$this->inv->commit();
		wp_set_object_terms( $post_id, array( $tag_id ), 'post_tag' );
		$this->assertSame( $this->sorted( 'term:' . $tag_id, 'list:post_tag' ), $this->pending() );
	}

	public function test_flat_taxonomy_never_asks_for_ancestors(): void {
		$calls = 0;
		add_filter(
			'get_ancestors',
			static function ( $ancestors ) use ( &$calls ) {
				++$calls;
				return $ancestors;
			}
		);
		$tag_id = self::factory()->tag->create();
		$this->assertSame( array(), ( new Term_Ancestry() )->ancestors( array( $tag_id ), 'post_tag' ) );
		$this->assertSame( 0, $calls );
	}

	public function test_hierarchical_set_object_terms_commits_with_one_update(): void {
		$root    = self::factory()->category->create();
		$child   = self::factory()->category->create( array( 'parent' => $root ) );
		$post_id = $this->make_post();

		$inv    = new Invalidator( new Database_Store( new Repository(), 0 ), static fn( string $url ): ?string => null );
		$events = new Term_Events( $inv, new Relevance( new Config() ), new Term_Ancestry() );
		remove_all_actions( 'set_object_terms' );
		$events->register();

		wp_set_object_terms( $post_id, array( $child ), 'category' );
		$recorder = new Rfrac_Query_Recorder();
		$recorder->start();
		$inv->commit();
		$recorder->stop();
		$this->assertCount( 1, $recorder->matching( '/^\s*UPDATE.*rfrac_entries/is' ) );
	}

	public function test_created_term_queues_taxonomy_list(): void {
		wp_insert_term( 'Fresh category', 'category' );
		$this->assertSame( array( 'list:category' ), $this->pending() );
	}

	public function test_edited_term_queues_term_and_list(): void {
		$term = self::factory()->category->create();
		$this->inv->commit();
		wp_update_term( $term, 'category', array( 'name' => 'Renamed' ) );
		$this->assertSame( $this->sorted( 'term:' . $term, 'list:category' ), $this->pending() );
	}

	public function test_delete_term_queues_each_object_up_to_200(): void {
		$term  = self::factory()->category->create();
		$posts = self::factory()->post->create_many( 3 );
		foreach ( $posts as $id ) {
			wp_set_object_terms( $id, array( $term ), 'category' );
		}
		$this->inv->commit();

		// Only the delete_term listener: reassigning the posts to the default category and recounting queue more.
		remove_action( 'set_object_terms', array( $this->events, 'on_set_object_terms' ), 10 );
		remove_action( 'deleted_term_relationships', array( $this->events, 'on_deleted_term_relationships' ), 10 );
		remove_action( 'edited_term_taxonomy', array( $this->events, 'on_edited_term_taxonomy' ), 10 );
		wp_delete_term( $term, 'category' );
		$expected = array( 'term:' . $term, 'term-ref:' . $term, 'list:category' );
		foreach ( $posts as $id ) {
			$expected[] = 'post:' . $id;
		}
		$this->assertEqualsCanonicalizing( $expected, $this->pending() );
	}

	public function test_delete_term_with_201_objects_queues_the_reference_and_post_type_lists(): void {
		$ids = range( 1000, 1200 );
		$this->events->on_delete_term( 77, 78, 'category', null, $ids );
		$this->assertSame( $this->sorted( 'term:77', 'term-ref:77', 'list:category', 'list:post' ), $this->pending() );
	}

	public function test_delete_term_with_200_objects_still_tags_each_object(): void {
		$this->events->on_delete_term( 77, 78, 'category', null, range( 1000, 1199 ) );
		$this->assertCount( 203, $this->pending() );
		$this->assertContains( 'term-ref:77', $this->pending() );
		$this->assertNotContains( 'list:post', $this->pending() );
	}

	public function test_term_edits_and_recounts_never_queue_the_term_reference(): void {
		list( $post_id, $cat ) = $this->post_in_category( 'publish' );
		$other                 = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->inv->commit();

		wp_update_term( $cat, 'category', array( 'name' => 'Renamed', 'description' => 'New' ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		wp_set_object_terms( $other, array( $cat ), 'category' );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		$pending = $this->pending();
		$this->assertContains( 'term:' . $cat, $pending );
		$this->assertSame( array(), preg_grep( '/^term-ref:/', $pending ) );
	}

	public function test_an_assignment_reverted_in_the_same_request_flushes_only_the_post_itself(): void {
		$post_id = $this->make_post();
		$tag_a   = self::factory()->tag->create();
		$tag_b   = self::factory()->tag->create();
		wp_set_object_terms( $post_id, array( $tag_a ), 'post_tag' );
		$this->inv->commit();
		$seen = $this->assignment_contexts();

		wp_set_object_terms( $post_id, array( $tag_b ), 'post_tag' );
		wp_set_object_terms( $post_id, array( $tag_a ), 'post_tag' );
		$pending = $this->pending();
		$this->assertCount( 1, $seen );
		$this->assertSame( array(), $seen[0]['term_ids'], 'The net assignment is unchanged.' );
		$this->assertContains( 'post:' . $post_id, $pending, 'A reader in between may have cached the intermediate terms.' );
		$this->assertContains( 'list:post', $pending );
		// Each recount changed the count while it happened, so the term itself is still queued.
		$this->assertContains( 'term:' . $tag_b, $pending );
	}

	public function test_default_category_replaced_in_the_same_save_is_not_an_assignment_change(): void {
		$default = (int) get_option( 'default_category' );
		$cat     = self::factory()->category->create();
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->inv->commit();
		$seen = $this->assignment_contexts();

		// What the REST controller does: wp_insert_post() assigns the default category, then the requested ones.
		$post_id = wp_insert_post( array( 'post_title' => 'New', 'post_status' => 'publish' ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame( array( $default ), wp_get_post_categories( $post_id ) );
		wp_set_post_categories( $post_id, array( $cat ) );

		$pending = $this->pending();
		$this->assertCount( 1, $seen );
		$this->assertSame( array( $cat ), $seen[0]['term_ids'] );
		$this->assertNotContains( 'term-ref:' . $default, $pending );
		$this->assertContains( 'term:' . $cat, $pending );
		$this->assertContains( 'list:category', $pending );
	}

	public function test_a_commit_between_two_assignments_settles_each_of_them(): void {
		$post_id = $this->make_post();
		$tag_a   = self::factory()->tag->create();
		$tag_b   = self::factory()->tag->create();
		$this->inv->commit();

		wp_set_object_terms( $post_id, array( $tag_a ), 'post_tag' );
		$this->assertContains( 'term:' . $tag_a, $this->pending() );
		$this->inv->commit();

		wp_set_object_terms( $post_id, array( $tag_b ), 'post_tag', true );
		wp_set_object_terms( $post_id, array( $tag_b ), 'post_tag' );
		$pending = $this->pending();
		$this->assertContains( 'term:' . $tag_a, $pending, 'Removed after the first commit.' );
		$this->assertContains( 'term:' . $tag_b, $pending );
		$this->assertContains( 'post:' . $post_id, $pending );
	}

	public function test_append_then_replace_in_one_request_settles_against_the_original_terms(): void {
		$post_id = $this->make_post();
		$tag_a   = self::factory()->tag->create();
		$tag_b   = self::factory()->tag->create();
		$tag_c   = self::factory()->tag->create();
		wp_set_object_terms( $post_id, array( $tag_a ), 'post_tag' );
		$this->inv->commit();
		$seen = $this->assignment_contexts();

		wp_set_object_terms( $post_id, array( $tag_b ), 'post_tag', true ); // a, b.
		wp_set_object_terms( $post_id, array( $tag_a, $tag_c ), 'post_tag' ); // a, c.
		$pending = $this->pending();
		$this->assertCount( 1, $seen );
		$this->assertSame( array( $tag_c ), $seen[0]['term_ids'] );
		$this->assertContains( 'post:' . $post_id, $pending );
	}

	public function test_append_of_a_term_the_object_already_has_is_not_an_assignment_change(): void {
		$post_id = $this->make_post();
		$tag_a   = self::factory()->tag->create();
		wp_set_object_terms( $post_id, array( $tag_a ), 'post_tag' );
		$this->inv->commit();
		$seen = $this->assignment_contexts();

		wp_add_object_terms( $post_id, array( $tag_a ), 'post_tag' );
		$this->assertSame( array(), $this->pending() );
		$this->assertSame( array(), $seen->getArrayCopy() );
	}

	public function test_removing_terms_outside_set_object_terms_is_settled_too(): void {
		$post_id = $this->make_post();
		$tag_a   = self::factory()->tag->create();
		$tag_b   = self::factory()->tag->create();
		wp_set_object_terms( $post_id, array( $tag_a, $tag_b ), 'post_tag' );
		$this->inv->commit();
		$seen = $this->assignment_contexts();

		wp_remove_object_terms( $post_id, array( $tag_a ), 'post_tag' );
		$pending = $this->pending();
		$this->assertContains( 'post:' . $post_id, $pending );
		$this->assertContains( 'term:' . $tag_a, $pending );
		$this->assertNotContains( 'term:' . $tag_b, $pending );
		$this->assertSame( array( $tag_a ), $seen[0]['term_ids'] );
	}

	public function test_a_removal_reverted_by_an_append_flushes_only_the_post_itself(): void {
		$post_id = $this->make_post();
		$tag_a   = self::factory()->tag->create();
		wp_set_object_terms( $post_id, array( $tag_a ), 'post_tag' );
		$this->inv->commit();
		$seen = $this->assignment_contexts();

		wp_remove_object_terms( $post_id, array( $tag_a ), 'post_tag' );
		wp_add_object_terms( $post_id, array( $tag_a ), 'post_tag' );
		$pending = $this->pending();
		$this->assertCount( 1, $seen );
		$this->assertSame( array(), $seen[0]['term_ids'] );
		$this->assertContains( 'post:' . $post_id, $pending );
		$this->assertNotContains( 'term-ref:' . $tag_a, $pending );
	}

	public function test_deleting_all_relationships_of_a_post_is_settled(): void {
		$post_id = $this->make_post();
		$tag_a   = self::factory()->tag->create();
		$cat     = self::factory()->category->create();
		wp_set_object_terms( $post_id, array( $tag_a ), 'post_tag' );
		wp_set_object_terms( $post_id, array( $cat ), 'category' );
		$this->inv->commit();

		wp_delete_object_term_relationships( $post_id, array( 'post_tag', 'category' ) );
		$pending = $this->pending();
		$this->assertContains( 'term:' . $tag_a, $pending );
		$this->assertContains( 'term:' . $cat, $pending );
		$this->assertContains( 'post:' . $post_id, $pending );
	}

	public function test_a_rejected_set_object_terms_drops_its_removals_too(): void {
		$post_id = $this->make_post();
		$tag_a   = self::factory()->tag->create();
		$tag_b   = self::factory()->tag->create();
		wp_set_object_terms( $post_id, array( $tag_a ), 'post_tag' );
		$this->inv->commit();
		add_filter( Hooks::FLUSH_ON_SET_TERMS, '__return_false' );
		$seen = $this->assignment_contexts();

		wp_set_object_terms( $post_id, array( $tag_b ), 'post_tag' );
		$this->assertNotContains( 'post:' . $post_id, $this->pending() );
		$this->assertSame( array(), $seen->getArrayCopy() );
	}

	public function test_a_deleted_term_is_resolved_while_it_still_exists(): void {
		$cat     = self::factory()->category->create();
		$post_id = $this->make_post();
		wp_set_object_terms( $post_id, array( $cat ), 'category' );
		$this->inv->commit();

		wp_delete_term( $cat, 'category' );
		$pending = $this->pending();
		$this->assertContains( 'term:' . $cat, $pending );
		$this->assertContains( 'term-ref:' . $cat, $pending );
		$this->assertContains( 'post:' . $post_id, $pending );
	}

	public function test_every_recount_queues_the_term(): void {
		list( $post_id, $cat ) = $this->post_in_category( 'publish' );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
		$this->assertContains( 'term:' . $cat, $this->pending() );
		$this->assertNotContains( 'term-ref:' . $cat, $this->pending() );
	}

	/**
	 * @return void
	 */
	public function test_ancestry_cases(): void {
		$ancestry = new Term_Ancestry();
		$this->assertSame( array(), $ancestry->ancestors( array(), 'category' ) );

		$gp = self::factory()->category->create();
		$p  = self::factory()->category->create( array( 'parent' => $gp ) );
		$c1 = self::factory()->category->create( array( 'parent' => $p ) );
		$c2 = self::factory()->category->create( array( 'parent' => $p ) );

		$this->assertSame( array(), $ancestry->ancestors( array( $gp ), 'category' ) );
		$this->assertSame( array( $gp ), $ancestry->ancestors( array( $p ), 'category' ) );
		$this->assertEqualsCanonicalizing( array( $p, $gp ), $ancestry->ancestors( array( $c1 ), 'category' ) );
		$shared = $ancestry->ancestors( array( $c1, $c2 ), 'category' );
		$this->assertCount( 2, $shared );
		$this->assertEqualsCanonicalizing( array( $p, $gp ), $shared );
		// Inputs are excluded from the result.
		$this->assertSame( array( $gp ), $ancestry->ancestors( array( $c1, $p ), 'category' ) );
	}

	public function test_ancestry_does_not_cross_taxonomies(): void {
		register_taxonomy( 'rfrac_other', 'post', array( 'hierarchical' => true ) );
		$cat_parent = self::factory()->category->create();
		$cat_child  = self::factory()->category->create( array( 'parent' => $cat_parent ) );
		$other      = wp_insert_term( 'other-parent', 'rfrac_other' );
		wp_insert_term( 'other-child', 'rfrac_other', array( 'parent' => $other['term_id'] ) );

		$this->assertSame( array( $cat_parent ), ( new Term_Ancestry() )->ancestors( array( $cat_child ), 'category' ) );
		unregister_taxonomy( 'rfrac_other' );
	}

	/**
	 * A post in one category (and no other terms), with the queue emptied.
	 *
	 * @param string $status Post status.
	 *
	 * @return array{0:int,1:int} Post and category IDs.
	 */
	private function post_in_category( string $status ): array {
		$cat  = self::factory()->category->create();
		$args = array(
			'post_status'   => $status,
			'post_category' => array( $cat ),
		);
		if ( 'future' === $status ) {
			$args['post_date'] = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		}
		$post_id = self::factory()->post->create( $args );
		$this->inv->commit();
		return array( $post_id, $cat );
	}

	public function test_publishing_a_draft_recounts_and_queues_its_terms(): void {
		list( $post_id, $cat ) = $this->post_in_category( 'draft' );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
		$this->assertSame( $this->sorted( 'term:' . $cat, 'list:category' ), $this->pending() );
		$this->assertSame( 1, (int) get_term( $cat )->count );
	}

	public function test_publishing_a_scheduled_post_recounts_its_terms(): void {
		list( $post_id, $cat ) = $this->post_in_category( 'future' );
		wp_publish_post( $post_id );
		$this->assertContains( 'term:' . $cat, $this->pending() );
	}

	public function test_unpublishing_trashing_and_deleting_recount_its_terms(): void {
		list( $post_id, $cat ) = $this->post_in_category( 'publish' );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		$this->assertContains( 'term:' . $cat, $this->pending() );
		$this->inv->commit();

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
		$this->inv->commit();
		wp_trash_post( $post_id );
		$this->assertContains( 'term:' . $cat, $this->pending() );
		$this->inv->commit();

		wp_untrash_post( $post_id );
		wp_publish_post( $post_id );
		$this->inv->commit();
		wp_delete_post( $post_id, true );
		$this->assertContains( 'term:' . $cat, $this->pending() );
		$this->assertContains( 'list:category', $this->pending() );
	}

	public function test_draft_edit_does_not_recount(): void {
		list( $post_id ) = $this->post_in_category( 'draft' );
		wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Draft edit' ) );

		// WordPress before 6.7 recounts the post's terms on every save (harmless term flush);
		// newer versions don't. Either way a draft edit must not flush the post or its list.
		$pending = $this->pending();
		$this->assertNotContains( 'post:' . $post_id, $pending );
		$this->assertNotContains( 'list:post', $pending );
		$this->assertSame( array(), array_values( array_filter( $pending, static fn( string $tag ): bool => ! str_starts_with( $tag, 'term:' ) && 'list:category' !== $tag ) ) );
	}

	public function test_recount_of_an_irrelevant_taxonomy_queues_nothing(): void {
		register_taxonomy( 'rfrac_hidden_tax', 'post', array() );
		$term = wp_insert_term( 'hidden', 'rfrac_hidden_tax' );
		$this->inv->commit();
		$this->events->on_edited_term_taxonomy( (int) $term['term_taxonomy_id'], 'rfrac_hidden_tax' );
		$this->assertSame( array(), $this->pending() );
		unregister_taxonomy( 'rfrac_hidden_tax' );
	}

	public function test_deleting_a_parent_term_queues_its_reparented_children(): void {
		$root   = self::factory()->category->create();
		$parent = self::factory()->category->create( array( 'parent' => $root ) );
		$c1     = self::factory()->category->create( array( 'parent' => $parent ) );
		$c2     = self::factory()->category->create( array( 'parent' => $parent ) );
		$other  = self::factory()->category->create();
		$this->inv->commit();

		wp_delete_term( $parent, 'category' );
		$this->assertSame( $root, get_term( $c1 )->parent );
		$this->assertContains( 'term:' . $c1, $this->pending() );
		$this->assertContains( 'term:' . $c2, $this->pending() );
		$this->assertNotContains( 'term:' . $other, $this->pending() );
	}

	public function test_reparenting_with_no_children_queues_nothing(): void {
		$this->events->on_edited_term_taxonomies( array() );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_removing_a_hierarchical_term_queues_its_ancestors(): void {
		$root    = self::factory()->category->create();
		$parent  = self::factory()->category->create( array( 'parent' => $root ) );
		$child   = self::factory()->category->create( array( 'parent' => $parent ) );
		$post_id = $this->make_post();
		wp_set_object_terms( $post_id, array( $child ), 'category' );
		$this->inv->commit();

		wp_set_object_terms( $post_id, array(), 'category' );
		foreach ( array( $child, $parent, $root ) as $term_id ) {
			$this->assertContains( 'term:' . $term_id, $this->pending() );
		}
	}

	public function test_term_events_pass_the_term_as_context(): void {
		$seen = array();
		add_filter(
			Hooks::INVALIDATION_TAGS,
			static function ( $tags, $event, $context ) use ( &$seen ) {
				$seen[] = array( $event, $context );
				return $tags;
			},
			10,
			3
		);
		$term = self::factory()->category->create();
		$seen = array();
		wp_update_term( $term, 'category', array( 'name' => 'Context' ) );
		$this->assertContains( array( Invalidator::EVENT_TERM_EDITED, array( 'term_id' => $term, 'taxonomy' => 'category' ) ), $seen );
		$this->assertContains( array( Invalidator::EVENT_TERM_COUNTED, array( 'term_id' => $term, 'taxonomy' => 'category' ) ), $seen );
	}

	public function test_a_rename_or_description_change_does_not_queue_term_references(): void {
		$parent = self::factory()->category->create();
		$term   = self::factory()->category->create( array( 'parent' => $parent ) );
		$this->inv->commit();
		wp_update_term( $term, 'category', array( 'name' => 'Renamed', 'description' => 'New' ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame( $this->sorted( 'term:' . $term, 'list:category' ), $this->pending() );
	}

	public function test_a_slug_change_queues_references_to_the_term_and_its_descendants(): void {
		$root  = self::factory()->category->create();
		$term  = self::factory()->category->create( array( 'parent' => $root ) );
		$child = self::factory()->category->create( array( 'parent' => $term ) );
		$grand = self::factory()->category->create( array( 'parent' => $child ) );
		$this->inv->commit();

		wp_update_term( $term, 'category', array( 'slug' => 'new-slug' ) );
		$pending = $this->pending();
		foreach ( array( $term, $child, $grand ) as $id ) {
			$this->assertContains( 'term-ref:' . $id, $pending );
		}
		$this->assertNotContains( 'term-ref:' . $root, $pending, 'The parent path is unchanged.' );
		$this->assertContains( 'term:' . $term, $pending );
	}

	public function test_a_parent_change_queues_references_to_the_term_and_its_descendants(): void {
		$old   = self::factory()->category->create();
		$new   = self::factory()->category->create();
		$term  = self::factory()->category->create( array( 'parent' => $old ) );
		$child = self::factory()->category->create( array( 'parent' => $term ) );
		$this->inv->commit();

		wp_update_term( $term, 'category', array( 'parent' => $new ) );
		$pending = $this->pending();
		$this->assertContains( 'term-ref:' . $term, $pending );
		$this->assertContains( 'term-ref:' . $child, $pending );
		$this->assertNotContains( 'term-ref:' . $old, $pending );
		$this->assertNotContains( 'term-ref:' . $new, $pending );
	}

	public function test_a_flat_tag_slug_change_queues_only_its_reference(): void {
		$tag = self::factory()->tag->create();
		$this->inv->commit();
		wp_update_term( $tag, 'post_tag', array( 'slug' => 'other-slug' ) );
		$this->assertSame( $this->sorted( 'term:' . $tag, 'term-ref:' . $tag, 'list:post_tag' ), $this->pending() );
	}

	public function test_an_edit_without_a_prior_snapshot_queues_the_reference_to_be_safe(): void {
		$tag = self::factory()->tag->create();
		$this->inv->commit();
		$this->events->on_edited_term( $tag, 0, 'post_tag' );
		$this->assertContains( 'term-ref:' . $tag, $this->pending() );
	}

	public function test_deleting_a_parent_term_queues_references_to_the_reparented_subtree(): void {
		$parent = self::factory()->category->create();
		$child  = self::factory()->category->create( array( 'parent' => $parent ) );
		$grand  = self::factory()->category->create( array( 'parent' => $child ) );
		$this->inv->commit();
		wp_delete_term( $parent, 'category' );
		$pending = $this->pending();
		$this->assertContains( 'term-ref:' . $child, $pending );
		$this->assertContains( 'term-ref:' . $grand, $pending );
	}

	public function test_set_object_terms_context_lists_the_changed_terms_and_the_post(): void {
		$post_id = $this->make_post();
		$tag_id  = self::factory()->tag->create();
		$this->inv->commit();
		$seen = $this->assignment_contexts();
		wp_set_object_terms( $post_id, array( $tag_id ), 'post_tag' );
		$this->pending();
		$this->assertSame(
			array(
				array(
					'object_id' => $post_id,
					'taxonomy'  => 'post_tag',
					'post_id'   => $post_id,
					'post_type' => 'post',
					'term_ids'  => array( $tag_id ),
				),
			),
			$seen->getArrayCopy()
		);
	}

	/**
	 * Record the context of every `object_terms_set` event.
	 *
	 * @return ArrayObject<int,array<string,mixed>>
	 */
	private function assignment_contexts(): ArrayObject {
		$seen = new ArrayObject();
		add_filter(
			Hooks::INVALIDATION_TAGS,
			static function ( $tags, $event, $context ) use ( $seen ) {
				if ( Invalidator::EVENT_OBJECT_TERMS_SET === $event ) {
					$seen[] = $context;
				}
				return $tags;
			},
			10,
			3
		);
		return $seen;
	}
}
