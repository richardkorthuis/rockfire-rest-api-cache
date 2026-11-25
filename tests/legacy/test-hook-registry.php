<?php
/**
 * Tests for the registry of the hooks of the original plugin.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Legacy\Hook_Registry;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Hook_Registry
 */
class Test_Hook_Registry extends WP_UnitTestCase {

	/**
	 * The hooks of the original (version 2026.3.1, plus the four of 2026.4.x), without the prefix.
	 *
	 * @var list<string>
	 */
	private const ORIGINAL = array(
		'allow_skip_cache',
		'allowed_endpoints',
		'allowed_request_methods',
		'api_caches_table_column_output',
		'api_caches_table_columns',
		'api_caches_table_data',
		'cache_authenticated_response',
		'cache_details_info_rows',
		'cache_details_request_headers',
		'cache_header',
		'cache_headers',
		'cache_hit_recording',
		'cache_key',
		'cacheable_request_headers',
		'clear_cache_form_options',
		'delete_caches_immediately',
		'deleted_caches',
		'determine_object_type',
		'disable_cors_headers',
		'disallowed_endpoints',
		'display_clear_cache_button',
		'filter_cache_output',
		'filtered_cache_keys',
		'flush_on_meta_update',
		'flush_on_meta_update/{meta_type}/{meta_key}',
		'flush_on_set_terms',
		'insert_cache_relation',
		'is_single_item',
		'max_cleanup_caches',
		'max_purge_caches',
		'pre_delete_object_type_caches',
		'pre_delete_related_caches',
		'pre_delete_related_caches_batch',
		'pre_output_headers',
		'process_cache_relations',
		'purge_stale_caches',
		'settings_capability',
		'settings_panels',
		'skip_cache_regeneration',
		'skip_caching',
		'skip_empty_result_set',
		'skip_nonce_caching',
		'stale_cache_grace_period',
		'timeout',
		'uncached_parameters',
	);

	/**
	 * Plugin root.
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * The `wp_rest_cache/...` hooks fired by the PHP files under the given path.
	 *
	 * @param string $path Absolute file or directory.
	 *
	 * @return array<string,string> Hook name, with `{$x}` written as `{x}`, => first file that fires it.
	 */
	private static function fired_hooks( string $path ): array {
		$files = array();
		if ( is_file( $path ) ) {
			$files[] = $path;
		} elseif ( is_dir( $path ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( $file instanceof SplFileInfo && 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}

		$found = array();
		foreach ( $files as $file ) {
			$code = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( preg_match_all( '/(?:apply_filters_deprecated|apply_filters|do_action_deprecated|do_action)\(\s*[\'"](wp_rest_cache\/[^\'"]+)[\'"]/', $code, $m ) ) {
				foreach ( $m[1] as $name ) {
					$name = (string) preg_replace( '/\{\$([a-z_]+)\}/', '{$1}', $name );

					$found[ $name ] ??= $file;
				}
			}
		}
		return $found;
	}

	/**
	 * The readme answer about the hooks, from its heading to the next heading.
	 *
	 * @return string
	 */
	private function readme_answer(): string {
		$lines = file( self::root() . '/readme.txt', FILE_IGNORE_NEW_LINES );
		$this->assertIsArray( $lines );

		$in_section = false;
		$found      = array();
		foreach ( $lines as $line ) {
			if ( '= Do my WP REST Cache hooks still work? =' === $line ) {
				$in_section = true;
				continue;
			}
			if ( $in_section && 1 === preg_match( '/^==?[^=]/', $line ) ) {
				break;
			}
			if ( $in_section ) {
				$found[] = $line;
			}
		}
		$this->assertNotSame( array(), $found, 'The readme answer about the hooks is missing or empty.' );
		return implode( "\n", $found );
	}

	public function test_the_original_has_45_hooks_that_are_classified_exactly_once(): void {
		$this->assertCount( 45, self::ORIGINAL );

		$expected = array_map(
			static function ( string $name ): string {
				return 'wp_rest_cache/' . $name;
			},
			self::ORIGINAL
		);
		$bridged  = array_keys( Hook_Registry::bridged() );
		$not      = array_keys( Hook_Registry::not_bridged() );

		$this->assertSame( array(), array_intersect( $bridged, $not ), 'A hook is classified twice.' );

		$combined = array_merge( $bridged, $not );
		$this->assertSame( count( $combined ), count( array_unique( $combined ) ) );
		sort( $combined );
		sort( $expected );
		$this->assertSame( $expected, $combined );
	}

	public function test_counts_and_constants(): void {
		$this->assertCount( 24, Hook_Registry::bridged() );
		$this->assertCount( 21, Hook_Registry::not_bridged() );
		$this->assertSame( 'wp_rest_cache/', Hook_Registry::PREFIX );
		$this->assertSame( '1.0.0', Hook_Registry::DEPRECATED_SINCE );
		$this->assertSame(
			array( 'compile', 'store', 'tagging', 'invalidation', 'admin', 'hit' ),
			array(
				Hook_Registry::PHASE_COMPILE,
				Hook_Registry::PHASE_STORE,
				Hook_Registry::PHASE_TAGGING,
				Hook_Registry::PHASE_INVALIDATION,
				Hook_Registry::PHASE_ADMIN,
				Hook_Registry::PHASE_HIT,
			)
		);
		$this->assertSame(
			array( 'admin_ui', 'removed_feature', 'no_seam', 'deferred' ),
			array(
				Hook_Registry::REASON_ADMIN_UI,
				Hook_Registry::REASON_REMOVED,
				Hook_Registry::REASON_NO_SEAM,
				Hook_Registry::REASON_DEFERRED,
			)
		);
	}

	public function test_every_entry_is_well_formed(): void {
		$phases = array(
			Hook_Registry::PHASE_COMPILE,
			Hook_Registry::PHASE_STORE,
			Hook_Registry::PHASE_TAGGING,
			Hook_Registry::PHASE_INVALIDATION,
			Hook_Registry::PHASE_ADMIN,
			Hook_Registry::PHASE_HIT,
		);
		foreach ( Hook_Registry::bridged() as $name => $entry ) {
			$this->assertStringStartsWith( Hook_Registry::PREFIX, $name );
			$this->assertSame( array( 'kind', 'phase', 'replacement', 'lossy' ), array_keys( $entry ), $name );
			$this->assertContains( $entry['kind'], array( 'filter', 'action' ), $name );
			$this->assertContains( $entry['phase'], $phases, $name );
			$this->assertStringNotContainsString( '|', $entry['lossy'], "$name: a note must fit a readme table cell." );
		}

		$reasons = array(
			Hook_Registry::REASON_ADMIN_UI,
			Hook_Registry::REASON_REMOVED,
			Hook_Registry::REASON_NO_SEAM,
			Hook_Registry::REASON_DEFERRED,
		);
		foreach ( Hook_Registry::not_bridged() as $name => $entry ) {
			$this->assertStringStartsWith( Hook_Registry::PREFIX, $name );
			$this->assertSame( array( 'kind', 'reason', 'note' ), array_keys( $entry ), $name );
			$this->assertContains( $entry['kind'], array( 'filter', 'action' ), $name );
			$this->assertContains( $entry['reason'], $reasons, $name );
			$this->assertNotSame( '', $entry['note'], $name );
			$this->assertStringNotContainsString( '|', $entry['note'], "$name: a note must fit a readme table cell." );
		}
	}

	public function test_phases_and_replacements_of_the_bridged_hooks(): void {
		$expected = array(
			'allowed_endpoints'                           => array( Hook_Registry::PHASE_COMPILE, 'rfrac/route_rules' ),
			'disallowed_endpoints'                        => array( Hook_Registry::PHASE_COMPILE, 'rfrac/route_rules' ),
			'cacheable_request_headers'                   => array( Hook_Registry::PHASE_COMPILE, 'rfrac/route_rules' ),
			'uncached_parameters'                         => array( Hook_Registry::PHASE_COMPILE, 'rfrac/global_ignore_params' ),
			'skip_nonce_caching'                          => array( Hook_Registry::PHASE_COMPILE, 'rfrac/nonce_caching' ),
			'timeout'                                     => array( Hook_Registry::PHASE_STORE, 'rfrac/entry_ttl' ),
			'skip_empty_result_set'                       => array( Hook_Registry::PHASE_STORE, 'rfrac/cache_empty_results' ),
			'determine_object_type'                       => array( Hook_Registry::PHASE_TAGGING, 'rfrac/collected_tags' ),
			'is_single_item'                              => array( Hook_Registry::PHASE_TAGGING, 'rfrac/collected_tags' ),
			'process_cache_relations'                     => array( Hook_Registry::PHASE_TAGGING, 'rfrac/collected_tags' ),
			'insert_cache_relation'                       => array( Hook_Registry::PHASE_TAGGING, 'rfrac/collected_tags' ),
			'flush_on_meta_update'                        => array( Hook_Registry::PHASE_INVALIDATION, 'rfrac/flush_on_meta_update' ),
			'flush_on_meta_update/{meta_type}/{meta_key}' => array( Hook_Registry::PHASE_INVALIDATION, 'rfrac/flush_on_meta_update/{meta_type}/{meta_key}' ),
			'flush_on_set_terms'                          => array( Hook_Registry::PHASE_INVALIDATION, 'rfrac/flush_on_set_terms' ),
			'pre_delete_related_caches'                   => array( Hook_Registry::PHASE_INVALIDATION, 'rfrac/invalidation_tags' ),
			'pre_delete_related_caches_batch'             => array( Hook_Registry::PHASE_INVALIDATION, 'rfrac/invalidation_tags' ),
			'pre_delete_object_type_caches'               => array( Hook_Registry::PHASE_INVALIDATION, 'rfrac/invalidation_tags' ),
			'settings_capability'                         => array( Hook_Registry::PHASE_ADMIN, 'rfrac/settings_capability' ),
			'allow_skip_cache'                            => array( Hook_Registry::PHASE_HIT, 'rfrac/allow_skip_cache' ),
			'disable_cors_headers'                        => array( Hook_Registry::PHASE_HIT, 'rfrac/cors_on_hits' ),
			'skip_caching'                                => array( Hook_Registry::PHASE_HIT, 'rfrac/bypass_all' ),
			'cache_key'                                   => array( Hook_Registry::PHASE_HIT, '' ),
			'filter_cache_output'                         => array( Hook_Registry::PHASE_HIT, '' ),
			'pre_output_headers'                          => array( Hook_Registry::PHASE_HIT, '' ),
		);

		$bridged = Hook_Registry::bridged();
		$this->assertCount( count( $expected ), $bridged );
		foreach ( $expected as $name => list( $phase, $replacement ) ) {
			$entry = $bridged[ Hook_Registry::PREFIX . $name ] ?? null;
			$this->assertIsArray( $entry, $name );
			$this->assertSame( $phase, $entry['phase'], $name );
			$this->assertSame( $replacement, $entry['replacement'], $name );
		}
	}

	public function test_kinds_follow_the_original(): void {
		$actions = array(
			'process_cache_relations',
			'insert_cache_relation',
			'pre_delete_related_caches',
			'pre_delete_related_caches_batch',
			'pre_delete_object_type_caches',
			'cache_details_info_rows',
			'clear_cache_form_options',
			'deleted_caches',
		);

		$all = Hook_Registry::bridged() + Hook_Registry::not_bridged();
		$this->assertCount( 45, $all );
		foreach ( $all as $name => $entry ) {
			$short = substr( $name, strlen( Hook_Registry::PREFIX ) );
			$this->assertSame( in_array( $short, $actions, true ) ? 'action' : 'filter', $entry['kind'], $name );
		}
	}

	public function test_not_bridged_reasons(): void {
		$expected = array(
			Hook_Registry::REASON_NO_SEAM  => array( 'allowed_request_methods', 'cache_headers', 'cache_header' ),
			Hook_Registry::REASON_REMOVED  => array(
				'cache_hit_recording',
				'skip_cache_regeneration',
				'delete_caches_immediately',
				'max_cleanup_caches',
				'deleted_caches',
				'filtered_cache_keys',
				'cache_authenticated_response',
				'purge_stale_caches',
				'stale_cache_grace_period',
				'max_purge_caches',
			),
			Hook_Registry::REASON_ADMIN_UI => array(
				'settings_panels',
				'api_caches_table_data',
				'api_caches_table_columns',
				'api_caches_table_column_output',
				'cache_details_info_rows',
				'cache_details_request_headers',
				'clear_cache_form_options',
				'display_clear_cache_button',
			),
		);

		$actual = array();
		foreach ( Hook_Registry::not_bridged() as $name => $entry ) {
			$actual[ $entry['reason'] ][] = substr( $name, strlen( Hook_Registry::PREFIX ) );
		}
		$this->assertEqualsCanonicalizing( array_keys( $expected ), array_keys( $actual ) );
		foreach ( $expected as $reason => $names ) {
			$this->assertEqualsCanonicalizing( $names, $actual[ $reason ], $reason );
		}
	}

	/**
	 * AC-5: skip_nonce_caching is bridged at the compile phase, and allowed_request_methods has no seam.
	 */
	public function test_the_two_hooks_that_were_deferred_are_classified(): void {
		$nonce = Hook_Registry::bridged()['wp_rest_cache/skip_nonce_caching'] ?? null;
		$this->assertIsArray( $nonce );
		$this->assertSame( 'filter', $nonce['kind'] );
		$this->assertSame( Hook_Registry::PHASE_COMPILE, $nonce['phase'], 'Never a hit hook: no legacy code runs on a hit for a nonce.' );
		$this->assertSame( Hooks::NONCE_CACHING, $nonce['replacement'] );
		$this->assertStringContainsString( 'Inverted', $nonce['lossy'] );
		$this->assertStringContainsString( 'constant callback', $nonce['lossy'] );
		$this->assertStringContainsString( 'logged-in requests are never cached', $nonce['lossy'] );
		$this->assertStringContainsString( 'only while no setting or constant of this plugin decides', $nonce['lossy'], 'Compile_Folding folds the callback only while the source is the default.' );
		$this->assertArrayNotHasKey( 'wp_rest_cache/skip_nonce_caching', Hook_Registry::not_bridged() );
		$this->assertNotContains( 'wp_rest_cache/skip_nonce_caching', Hook_Registry::hit_filters() );

		$methods = Hook_Registry::not_bridged()['wp_rest_cache/allowed_request_methods'] ?? null;
		$this->assertIsArray( $methods );
		$this->assertSame( Hook_Registry::REASON_NO_SEAM, $methods['reason'] );
		$this->assertSame( 'Request methods are chosen per route (methods in a route rule, or REST API Cache → Routes); no filter changes them for every route.', $methods['note'] );
		$this->assertArrayNotHasKey( 'wp_rest_cache/allowed_request_methods', Hook_Registry::bridged() );
	}

	public function test_no_hook_has_the_deferred_reason_but_the_reason_stays_defined(): void {
		$this->assertSame( 'deferred', Hook_Registry::REASON_DEFERRED );
		foreach ( Hook_Registry::not_bridged() as $name => $entry ) {
			$this->assertNotSame( Hook_Registry::REASON_DEFERRED, $entry['reason'], $name );
		}
	}

	public function test_hit_filters_are_the_six_hit_phase_hooks(): void {
		$expected = array(
			'wp_rest_cache/allow_skip_cache',
			'wp_rest_cache/disable_cors_headers',
			'wp_rest_cache/skip_caching',
			'wp_rest_cache/cache_key',
			'wp_rest_cache/filter_cache_output',
			'wp_rest_cache/pre_output_headers',
		);
		$this->assertEqualsCanonicalizing( $expected, Hook_Registry::hit_filters() );
		$this->assertCount( 6, Hook_Registry::hit_filters() );

		$bridged = Hook_Registry::bridged();
		foreach ( Hook_Registry::hit_filters() as $name ) {
			$this->assertArrayHasKey( $name, $bridged );
			$this->assertSame( Hook_Registry::PHASE_HIT, $bridged[ $name ]['phase'], $name );
		}
		foreach ( $bridged as $name => $entry ) {
			if ( Hook_Registry::PHASE_HIT === $entry['phase'] ) {
				$this->assertContains( $name, Hook_Registry::hit_filters() );
			}
		}
	}

	/**
	 * Compile_Folding folds disable_cors_headers and a constant skip_caching into the configuration without the
	 * constant, so their notes must not say the constant is needed for them as such.
	 */
	public function test_the_notes_of_the_hit_hooks_say_which_need_the_constant(): void {
		$bridged = Hook_Registry::bridged();
		$needs   = 'Needs ' . Hook_Registry::HIT_FILTERS_CONSTANT . '.';

		foreach ( array( 'disable_cors_headers', 'skip_caching' ) as $name ) {
			$note = $bridged[ Hook_Registry::PREFIX . $name ]['lossy'];
			$this->assertStringNotContainsString( $needs, $note, "$name works without the constant." );
			$this->assertStringContainsString( 'Compiled in', $note, $name );
			$this->assertStringContainsString( 'a callback that depends on the request needs ' . Hook_Registry::HIT_FILTERS_CONSTANT, $note, $name );
		}

		foreach ( array( 'cache_key', 'filter_cache_output', 'pre_output_headers' ) as $name ) {
			$this->assertStringContainsString( $needs, $bridged[ Hook_Registry::PREFIX . $name ]['lossy'], "$name always depends on the request." );
		}
	}

	public function test_every_replacement_is_a_registered_hook(): void {
		$registered = array_values( Hooks::all() );
		foreach ( Hook_Registry::bridged() as $name => $entry ) {
			if ( '' !== $entry['replacement'] ) {
				$this->assertContains( $entry['replacement'], $registered, $name );
			}
		}
	}

	public function test_replacement_resolves_dynamic_names(): void {
		$this->assertSame(
			'rfrac/flush_on_meta_update/{meta_type}/{meta_key}',
			Hook_Registry::replacement( 'wp_rest_cache/flush_on_meta_update/post/_price' )
		);
		$this->assertSame(
			'rfrac/flush_on_meta_update/{meta_type}/{meta_key}',
			Hook_Registry::replacement( 'wp_rest_cache/flush_on_meta_update/term/acf/field_1' ),
			'A meta key may hold a slash.'
		);
		$this->assertSame(
			'rfrac/flush_on_meta_update/{meta_type}/{meta_key}',
			Hook_Registry::replacement( 'wp_rest_cache/flush_on_meta_update/{meta_type}/{meta_key}' )
		);
	}

	public function test_replacement_of_plain_and_unknown_names(): void {
		$this->assertSame( 'rfrac/flush_on_meta_update', Hook_Registry::replacement( 'wp_rest_cache/flush_on_meta_update' ) );
		$this->assertSame( 'rfrac/entry_ttl', Hook_Registry::replacement( 'wp_rest_cache/timeout' ) );
		$this->assertSame( '', Hook_Registry::replacement( 'wp_rest_cache/cache_key' ), 'Bridged without a replacement.' );
		$this->assertNull( Hook_Registry::replacement( 'wp_rest_cache/settings_panels' ) );
		$this->assertNull( Hook_Registry::replacement( 'wp_rest_cache/unknown' ) );
		$this->assertNull( Hook_Registry::replacement( 'rfrac/entry_ttl' ) );
		$this->assertNull( Hook_Registry::replacement( 'wp_rest_cache/flush_on_meta_update/post' ), 'The dynamic name needs both segments.' );
		$this->assertNull( Hook_Registry::replacement( 'xwp_rest_cache/flush_on_meta_update/post/_price' ) );
		$this->assertNull( Hook_Registry::replacement( '' ) );
	}

	public function test_hooked_unsupported_reports_a_callback_on_a_not_bridged_hook(): void {
		$this->assertSame( array(), Hook_Registry::hooked_unsupported() );

		add_filter( 'wp_rest_cache/cache_headers', '__return_empty_array' );
		add_action( 'wp_rest_cache/deleted_caches', '__return_null' );
		add_filter( 'wp_rest_cache/timeout', '__return_zero' );

		$hooked = Hook_Registry::hooked_unsupported();
		$this->assertContains( 'wp_rest_cache/cache_headers', $hooked );
		$this->assertContains( 'wp_rest_cache/deleted_caches', $hooked );
		$this->assertNotContains( 'wp_rest_cache/timeout', $hooked, 'A bridged hook is not unsupported.' );
		$this->assertCount( 2, $hooked );
	}

	/**
	 * AC-1: the four filters upstream added in 2026.4.x are never fired, so a callback on one is reported with its note.
	 */
	public function test_the_hooks_added_upstream_in_2026_4_are_not_bridged_and_reported_when_hooked(): void {
		$expected = array(
			'wp_rest_cache/cache_authenticated_response' => 'Logged-in responses are never stored.',
			'wp_rest_cache/purge_stale_caches'           => 'Maintenance prunes hourly.',
			'wp_rest_cache/stale_cache_grace_period'     => 'Maintenance prunes hourly.',
			'wp_rest_cache/max_purge_caches'             => 'Maintenance prunes hourly.',
		);

		$this->assertSame( array(), Hook_Registry::hooked_unsupported() );
		foreach ( $expected as $name => $note ) {
			$entry = Hook_Registry::not_bridged()[ $name ] ?? null;
			$this->assertIsArray( $entry, $name );
			$this->assertSame( 'filter', $entry['kind'], $name );
			$this->assertSame( Hook_Registry::REASON_REMOVED, $entry['reason'], $name );
			$this->assertSame( $note, $entry['note'], $name );
			$this->assertArrayNotHasKey( $name, Hook_Registry::bridged(), $name );
			$this->assertNull( Hook_Registry::replacement( $name ), $name );

			add_filter( $name, '__return_true' );
		}

		$hooked = Hook_Registry::hooked_unsupported();
		foreach ( array_keys( $expected ) as $name ) {
			$this->assertContains( $name, $hooked );
		}
		$this->assertCount( 4, $hooked );
	}

	public function test_the_legacy_module_fires_only_bridged_hooks(): void {
		$bridged = Hook_Registry::bridged();
		$not     = Hook_Registry::not_bridged();

		foreach ( self::fired_hooks( self::root() . '/includes/legacy' ) as $name => $file ) {
			$this->assertArrayHasKey( $name, $bridged, "$name is fired in $file but is not a bridged hook." );
			$this->assertArrayNotHasKey( $name, $not, "$name is fired in $file but is not bridged." );
		}
	}

	public function test_the_fired_hook_scan_finds_literals_and_dynamic_names(): void {
		$dir = sys_get_temp_dir() . '/rfrac-legacy-' . uniqid();
		mkdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		$file = $dir . '/fixture.php';
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$file,
			"<?php\n"
			. "\$a = apply_filters( 'wp_rest_cache/cache_headers', 1 );\n"
			. "\$b = apply_filters_deprecated( \"wp_rest_cache/flush_on_meta_update/{\$meta_type}/{\$meta_key}\", array( 1 ), '1.0.0', 'x' );\n"
			. "do_action( 'wp_rest_cache/deleted_caches' );\n"
			. "do_action_deprecated( 'wp_rest_cache/timeout', array(), '1.0.0', 'x' );\n"
			. "\$c = apply_filters( 'rfrac/entry_ttl', 1 );\n"
		);

		try {
			$fired = self::fired_hooks( $dir );
		} finally {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}

		$this->assertSame(
			array(
				'wp_rest_cache/cache_headers',
				'wp_rest_cache/flush_on_meta_update/{meta_type}/{meta_key}',
				'wp_rest_cache/deleted_caches',
				'wp_rest_cache/timeout',
			),
			array_keys( $fired )
		);
	}

	/**
	 * The legacy hooks reference on rockfire.nl lists every original hook with its replacement or reason, from this
	 * registry; the readme only summarises it and links to it.
	 */
	public function test_every_bridged_replacement_is_a_registered_rockfire_hook(): void {
		$registered = array_values( Hooks::all() );

		foreach ( Hook_Registry::bridged() as $name => $entry ) {
			if ( '' !== $entry['replacement'] ) {
				$this->assertContains( $entry['replacement'], $registered, "$name is replaced by {$entry['replacement']}, which is not a registered hook." );
			}
		}
		$this->assertCount( 45, Hook_Registry::bridged() + Hook_Registry::not_bridged(), 'Every original hook is either bridged or not, exactly once.' );
	}

	public function test_the_readme_answer_links_to_the_legacy_hooks_reference(): void {
		$this->assertStringContainsString( 'https://rockfire.nl/docs/migration/legacy-hooks/', $this->readme_answer() );
	}
}
