<?php
/**
 * Tests for the endpoint translator of the legacy layer.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Effective_Rule;
use Rockfire\Rest_Api_Cache\Config\Route_Info;
use Rockfire\Rest_Api_Cache\Config\Route_Rule;
use Rockfire\Rest_Api_Cache\Legacy\Endpoint_Translator;
use Rockfire\Rest_Api_Cache\Legacy\Translation;
use Rockfire\Rest_Api_Cache\Legacy\Translation_Note;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Endpoint_Translator
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Translation
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Translation_Note
 */
class Test_Endpoint_Translator extends WP_UnitTestCase {

	/**
	 * Route info for a fixture route.
	 *
	 * @param string $route     Route.
	 * @param string $namespace Namespace.
	 * @param bool   $default   Whether the route is cached by default.
	 * @param bool   $readable  Whether the route has a GET handler.
	 * @param bool   $denied    Whether the deny-list blocks the route.
	 *
	 * @return Route_Info
	 */
	private function info( string $route, string $namespace, bool $default = false, bool $readable = true, bool $denied = false ): Route_Info { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound -- Mirrors the route info field.
		return new Route_Info( $route, $namespace, $readable, array(), $denied, $default );
	}

	/**
	 * A translator over the given route infos, keyed by route in the given order.
	 *
	 * @param Route_Info[] $infos Route infos.
	 *
	 * @return Endpoint_Translator
	 */
	private function translator( array $infos ): Endpoint_Translator {
		$routes = array();
		foreach ( $infos as $info ) {
			$routes[ $info->route ] = $info;
		}
		return new Endpoint_Translator( $routes );
	}

	/**
	 * Translator over a small site: core routes, the users routes and a plugin namespace.
	 *
	 * @return Endpoint_Translator
	 */
	private function site(): Endpoint_Translator {
		return $this->translator(
			array(
				$this->info( '/wp/v2', 'wp/v2' ),
				$this->info( '/wp/v2/posts', 'wp/v2', true ),
				$this->info( '/wp/v2/posts/(?P<id>[\d]+)', 'wp/v2', true ),
				$this->info( '/wp/v2/posts-archive', 'wp/v2' ),
				$this->info( '/wp/v2/comments', 'wp/v2', true ),
				$this->info( '/wp/v2/comments/(?P<id>[\d]+)', 'wp/v2', true ),
				$this->info( '/wp/v2/users', 'wp/v2', true ),
				$this->info( '/wp/v2/users/me', 'wp/v2', false, true, true ),
				$this->info( '/acme/v1', 'acme/v1' ),
				$this->info( '/acme/v1/items', 'acme/v1' ),
				$this->info( '/acme/v1/items/(?P<id>\d+)', 'acme/v1' ),
				$this->info( '/acme/v1/other', 'acme/v1' ),
				$this->info( '/acme/v1/write-only', 'acme/v1', false, false ),
			)
		);
	}

	/**
	 * The codes of the notes of a translation, in order.
	 *
	 * @param Translation $translation Translation.
	 *
	 * @return string[]
	 */
	private function codes( Translation $translation ): array {
		return array_map(
			static function ( Translation_Note $note ): string {
				return $note->code;
			},
			$translation->notes
		);
	}

	/**
	 * The notes of a translation with a code.
	 *
	 * @param Translation $translation Translation.
	 * @param string      $code        Note code.
	 *
	 * @return Translation_Note[]
	 */
	private function notes_of( Translation $translation, string $code ): array {
		return array_values(
			array_filter(
				$translation->notes,
				static function ( Translation_Note $note ) use ( $code ): bool {
					return $note->code === $code;
				}
			)
		);
	}

	public function test_pattern_chars(): void {
		$this->assertSame( '()[]{}?*+^$|\\', Endpoint_Translator::PATTERN_CHARS );
	}

	/**
	 * AC-1: a plain endpoint is a raw path prefix; routes cached by default need no rule.
	 */
	public function test_prefix_match_enables_non_default_routes_and_skips_default_ones(): void {
		$translator = $this->translator(
			array(
				$this->info( '/wp/v2/posts', 'wp/v2', true ),
				$this->info( '/wp/v2/posts/(?P<id>[\d]+)', 'wp/v2', true ),
				$this->info( '/wp/v2/posts-archive', 'wp/v2' ),
			)
		);

		$this->assertSame(
			array( '/wp/v2/posts', '/wp/v2/posts/(?P<id>[\d]+)', '/wp/v2/posts-archive' ),
			$translator->match( 'wp/v2', 'posts' )
		);

		$result = $translator->forward( array( 'wp/v2' => array( 'posts' ) ) );

		$this->assertSame( array( '/wp/v2/posts-archive' ), array_keys( $result->rules ) );
		$this->assertSame( Config::default_route_rule()->to_array(), $result->rules['/wp/v2/posts-archive'] );
		$this->assertSame( array( Translation_Note::ALREADY_DEFAULT, Translation_Note::ENABLED ), $this->codes( $result ) );

		$default = $result->notes[0];
		$this->assertSame( Translation_Note::SOURCE_ALLOWED, $default->source );
		$this->assertSame( 'wp/v2', $default->namespace );
		$this->assertSame( 'posts', $default->endpoint );
		$this->assertSame( array( '/wp/v2/posts', '/wp/v2/posts/(?P<id>[\d]+)' ), $default->routes );
		$this->assertSame( array( '/wp/v2/posts-archive' ), $result->notes[1]->routes );
	}

	/**
	 * AC-2: an empty endpoint covers the routes below the namespace, not its index.
	 */
	public function test_empty_endpoint_matches_below_the_namespace_only(): void {
		$translator = $this->translator(
			array(
				$this->info( '/acme/v1', 'acme/v1' ),
				$this->info( '/acme/v1/items', 'acme/v1' ),
			)
		);

		$this->assertSame( array( '/acme/v1/items' ), $translator->match( 'acme/v1', '' ) );

		$result = $translator->forward( array( 'acme/v1' => array( '' ) ) );

		$this->assertSame( array( '/acme/v1/items' ), array_keys( $result->rules ) );
		$this->assertSame( Route_Rule::STATUS_ENABLED, $result->rules['/acme/v1/items']['status'] );
		$this->assertSame( Route_Rule::MATCH_REGISTERED, $result->rules['/acme/v1/items']['match'] );
	}

	public function test_match_trims_slashes_and_keeps_registration_order(): void {
		$translator = $this->site();

		$this->assertSame( $translator->match( 'acme/v1', 'items' ), $translator->match( '/acme/v1/', '/items/' ) );
		$this->assertSame( array( '/acme/v1/items', '/acme/v1/items/(?P<id>\d+)' ), $translator->match( 'acme/v1', 'items' ) );
		$this->assertSame( array(), $translator->match( '', 'items' ) );
		$this->assertSame( array(), $translator->match( '/', 'items' ) );
	}

	/**
	 * AC-3: a concrete path is matched against the route patterns.
	 */
	public function test_concrete_path_matches_a_route_pattern(): void {
		$translator = $this->translator(
			array(
				$this->info( '/wp/v2/posts', 'wp/v2' ),
				$this->info( '/wp/v2/posts/(?P<id>[\d]+)', 'wp/v2' ),
			)
		);

		$this->assertSame( array( '/wp/v2/posts/(?P<id>[\d]+)' ), $translator->match( 'wp/v2', 'posts/5' ) );
		$this->assertSame( array( '/wp/v2/posts/(?P<id>[\d]+)' ), $translator->match( 'WP/V2', 'POSTS/5' ), 'A concrete path is matched case-insensitively.' );

		$result = $translator->forward( array( 'wp/v2' => array( 'posts/5' ) ) );

		$this->assertSame( array( '/wp/v2/posts/(?P<id>[\d]+)' ), array_keys( $result->rules ) );
		$this->assertSame( Route_Rule::STATUS_ENABLED, $result->rules['/wp/v2/posts/(?P<id>[\d]+)']['status'] );
		$this->assertSame( array( Translation_Note::CONCRETE_PATH, Translation_Note::ENABLED ), $this->codes( $result ) );
		$this->assertSame( array( '/wp/v2/posts/(?P<id>[\d]+)' ), $result->notes[0]->routes );
		$this->assertSame( 'posts/5', $result->notes[0]->endpoint );
	}

	/**
	 * AC-4: patterns, unknown endpoints and a missing namespace are reported and give no rule.
	 */
	public function test_pattern_unknown_and_invalid_entries_give_notes_but_no_rules(): void {
		$result = $this->site()->forward(
			array(
				'wp/v2' => array( 'items/(\d+)', 'nothing' ),
				''      => array( 'posts' ),
			)
		);

		$this->assertSame( array(), $result->rules );
		$this->assertSame(
			array( Translation_Note::PATTERN, Translation_Note::NO_ROUTE, Translation_Note::INVALID_ENTRY ),
			$this->codes( $result )
		);
		$this->assertSame( 'items/(\d+)', $result->notes[0]->endpoint );
		$this->assertSame( array(), $result->notes[0]->routes );
		$this->assertSame( 'nothing', $result->notes[1]->endpoint );
		$this->assertSame( '', $result->notes[2]->namespace );
	}

	public function test_pattern_characters_do_not_hide_a_literal_match(): void {
		$translator = $this->site();

		$this->assertSame( array( '/acme/v1/items/(?P<id>\d+)' ), $translator->match( 'acme/v1', 'items/(?P<id>\d+)' ) );
		$this->assertSame( array(), $translator->match( 'acme/v1', 'items/(\d+)' ) );
	}

	public function test_values_that_are_not_a_map_of_strings_are_invalid_entries(): void {
		$result = $this->site()->forward(
			array(
				'acme/v1' => 'items',
				'wp/v2'   => array( 12, array( 'posts' ) ),
			)
		);

		$this->assertSame( array(), $result->rules );
		$this->assertSame(
			array( Translation_Note::INVALID_ENTRY, Translation_Note::INVALID_ENTRY, Translation_Note::INVALID_ENTRY ),
			$this->codes( $result )
		);
		$this->assertSame( 'acme/v1', $result->notes[0]->namespace );
	}

	/**
	 * AC-5: denied routes are reported and never get a rule.
	 */
	public function test_denied_routes_have_no_rule(): void {
		$result = $this->site()->forward( array( 'wp/v2' => array( 'users' ) ) );

		$this->assertArrayNotHasKey( '/wp/v2/users/me', $result->rules );
		$denied = $this->notes_of( $result, Translation_Note::DENIED );
		$this->assertCount( 1, $denied );
		$this->assertSame( array( '/wp/v2/users/me' ), $denied[0]->routes );
		$this->assertSame( array( Translation_Note::DENIED, Translation_Note::ALREADY_DEFAULT ), $this->codes( $result ) );
	}

	public function test_routes_without_a_get_handler_are_dropped(): void {
		$result = $this->site()->forward( array( 'acme/v1' => array( 'write' ) ) );

		$this->assertSame( array(), $result->rules );
		$this->assertSame( array(), $result->notes );
	}

	/**
	 * Route info for a route read from a server: it has a handler for each of the given methods.
	 *
	 * @param string   $route     Route.
	 * @param string   $namespace Namespace.
	 * @param string[] $methods   The methods the route has a handler for.
	 * @param bool     $default   Whether the route is cached by default.
	 *
	 * @return Route_Info
	 */
	private function method_info( string $route, string $namespace, array $methods, bool $default = false ): Route_Info { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound -- Mirrors the route info field.
		return new Route_Info( $route, $namespace, in_array( 'GET', $methods, true ), array(), false, $default, $methods );
	}

	/**
	 * Translator over routes that register request methods besides GET.
	 *
	 * @return Endpoint_Translator
	 */
	private function writing_site(): Endpoint_Translator {
		return $this->translator(
			array(
				$this->method_info( '/wp/v2/posts', 'wp/v2', array( 'GET', 'POST' ), true ),
				$this->method_info( '/wp/v2/posts-archive', 'wp/v2', array( 'GET', 'POST' ) ),
				$this->method_info( '/wp/v2/submit', 'wp/v2', array( 'POST' ) ),
				$this->method_info( '/acme/v1/items', 'acme/v1', array( 'GET' ) ),
				$this->method_info( '/acme/v1/read-write', 'acme/v1', array( 'GET', 'POST', 'PUT', 'PATCH' ) ),
				$this->method_info( '/acme/v1/submit', 'acme/v1', array( 'POST' ) ),
				$this->method_info( '/acme/v1/replace', 'acme/v1', array( 'PUT' ) ),
			)
		);
	}

	/**
	 * AC-5: without methods the translation is the same as before and a POST-only route is not claimed.
	 */
	public function test_without_methods_the_output_is_unchanged_and_a_post_only_route_is_not_claimed(): void {
		$site    = $this->writing_site();
		$allowed = array(
			'acme/v1' => array( 'items', 'read-write', 'submit' ),
			'wp/v2'   => array( 'posts', 'submit' ),
		);

		$plain = $site->forward( $allowed );

		$this->assertEquals( $plain, $site->forward( $allowed, array(), array(), Route_Rule::MAX_VARY_HEADERS, array() ) );
		$this->assertEquals( $plain, $site->forward( $allowed, array(), array(), Route_Rule::MAX_VARY_HEADERS, array( 'GET', 'DELETE' ) ), 'Only POST, PUT and PATCH count.' );
		$default = Config::default_route_rule()->to_array();
		$this->assertSame(
			array(
				'/wp/v2/posts-archive'  => $default,
				'/acme/v1/items'        => $default,
				'/acme/v1/read-write'   => $default,
			),
			array_intersect_key(
				$plain->rules,
				array_flip( array( '/wp/v2/posts-archive', '/acme/v1/items', '/acme/v1/read-write' ) )
			)
		);
		$this->assertArrayNotHasKey( '/acme/v1/submit', $plain->rules, 'A POST-only route is not claimed.' );
		$this->assertArrayNotHasKey( '/wp/v2/submit', $plain->rules );
	}

	/**
	 * AC-5: with methods a route that registers them is enabled for them, a POST-only route included.
	 */
	public function test_with_methods_the_routes_that_register_them_cache_them(): void {
		$result = $this->writing_site()->forward(
			array(
				'acme/v1' => array( 'items', 'read-write', 'submit', 'replace' ),
				'wp/v2'   => array( 'posts', 'submit' ),
			),
			array(),
			array(),
			Route_Rule::MAX_VARY_HEADERS,
			array( 'post', 'PATCH', 'DELETE' )
		);

		$default = Config::default_route_rule();
		$this->assertSame( $default->to_array(), $result->rules['/acme/v1/items'], 'A route without the methods keeps the default rule.' );
		$this->assertSame( array( 'GET', 'POST', 'PATCH' ), Route_Rule::from_array( $result->rules['/acme/v1/read-write'] )->methods );
		$this->assertSame( array( 'POST' ), Route_Rule::from_array( $result->rules['/acme/v1/submit'] )->methods );
		$this->assertSame( $default->with_methods( array( 'POST' ) )->to_array(), $result->rules['/acme/v1/submit'], 'Apart from its methods the rule is the default rule.' );
		$this->assertArrayNotHasKey( '/acme/v1/replace', $result->rules, 'A route that registers none of the methods and has no GET handler is not claimed.' );

		$this->assertSame( $default->to_array(), $result->rules['/wp/v2/posts-archive'], 'A wp/v2 route never caches a method.' );
		$this->assertArrayNotHasKey( '/wp/v2/posts', $result->rules, 'A route cached by default stays without a rule.' );
		$this->assertArrayNotHasKey( '/wp/v2/submit', $result->rules, 'A POST-only wp/v2 route is not claimed.' );

		$enabled = $this->notes_of( $result, Translation_Note::ENABLED );
		$this->assertSame( array( '/acme/v1/items', '/acme/v1/read-write', '/acme/v1/submit' ), array_merge( ...array_map( static fn( Translation_Note $note ): array => $note->routes, array_slice( $enabled, 0, 3 ) ) ) );
		foreach ( $result->rules as $key => $rule ) {
			$this->assertTrue( Route_Rule::from_array( $rule )->validate()->is_valid(), $key . ' is a valid rule.' );
		}
	}

	/**
	 * AC-5: a route that is cached by default and registers a method is enabled with a rule for it, a core route is not.
	 */
	public function test_a_default_route_outside_core_gets_a_rule_for_the_method(): void {
		$translator = $this->translator(
			array(
				$this->method_info( '/oembed/1.0/embed', 'oembed/1.0', array( 'GET', 'POST' ), true ),
				$this->method_info( '/wp/v2/posts', 'wp/v2', array( 'GET', 'POST' ), true ),
			)
		);

		$result = $translator->forward( array( 'oembed/1.0' => array( 'embed' ), 'wp/v2' => array( 'posts' ) ), array(), array(), Route_Rule::MAX_VARY_HEADERS, array( 'POST' ) );

		$this->assertSame( array( '/oembed/1.0/embed' ), array_keys( $result->rules ) );
		$this->assertSame( array( 'GET', 'POST' ), Route_Rule::from_array( $result->rules['/oembed/1.0/embed'] )->methods );
	}

	/**
	 * AC-5: a disallowed entry still excludes a route that is claimed for its methods.
	 */
	public function test_a_disallowed_entry_still_excludes_a_route_claimed_for_a_method(): void {
		$result = $this->writing_site()->forward(
			array( 'acme/v1' => array( 'submit', 'read-write' ) ),
			array( 'acme/v1' => array( 'submit' ) ),
			array(),
			Route_Rule::MAX_VARY_HEADERS,
			array( 'POST' )
		);

		$this->assertSame( ( new Route_Rule( Route_Rule::STATUS_EXCLUDED ) )->to_array(), $result->rules['/acme/v1/submit'] );
		$this->assertSame( array( 'GET', 'POST' ), Route_Rule::from_array( $result->rules['/acme/v1/read-write'] )->methods );
		$excluded = $this->notes_of( $result, Translation_Note::EXCLUDED );
		$this->assertCount( 1, $excluded );
		$this->assertSame( array( '/acme/v1/submit' ), $excluded[0]->routes );

		$only_disallowed = $this->writing_site()->forward( array(), array( 'acme/v1' => array( 'submit' ) ), array(), Route_Rule::MAX_VARY_HEADERS, array( 'POST' ) );
		$this->assertSame( array(), $only_disallowed->rules, 'A route that is not allowed has nothing to exclude.' );
	}

	/**
	 * AC-5: headers vary a POST-only route that is enabled for its method.
	 */
	public function test_headers_vary_a_route_enabled_for_a_method(): void {
		$result = $this->writing_site()->forward(
			array( 'acme/v1' => array( 'submit' ) ),
			array(),
			array( 'acme/v1/submit' => 'X-Lang' ),
			Route_Rule::MAX_VARY_HEADERS,
			array( 'POST' )
		);

		$rule = Route_Rule::from_array( $result->rules['/acme/v1/submit'] );
		$this->assertSame( array( 'x-lang' ), $rule->vary_headers );
		$this->assertSame( array( 'POST' ), $rule->methods );
	}

	/**
	 * AC-6: disallowed routes get an excluded rule when they would otherwise be enabled.
	 */
	public function test_disallowed_routes_are_excluded_when_they_would_be_enabled(): void {
		$result = $this->site()->forward(
			array( 'acme/v1' => array( 'items' ) ),
			array(
				'acme/v1' => array( 'items', 'other' ),
				'wp/v2'   => array( 'comments' ),
			)
		);

		$excluded = ( new Route_Rule( Route_Rule::STATUS_EXCLUDED ) )->to_array();
		$this->assertSame(
			array(
				'/wp/v2/comments'             => $excluded,
				'/wp/v2/comments/(?P<id>[\d]+)' => $excluded,
				'/acme/v1/items'              => $excluded,
				'/acme/v1/items/(?P<id>\d+)'  => $excluded,
			),
			$result->rules
		);
		$this->assertArrayNotHasKey( '/acme/v1/other', $result->rules, 'A route that is neither allowed nor default stays as it is.' );

		$notes = $this->notes_of( $result, Translation_Note::EXCLUDED );
		$this->assertCount( 2, $notes );
		$this->assertSame( Translation_Note::SOURCE_DISALLOWED, $notes[0]->source );
		$this->assertSame( array( '/acme/v1/items', '/acme/v1/items/(?P<id>\d+)' ), $notes[0]->routes );
		$this->assertSame( array( '/wp/v2/comments', '/wp/v2/comments/(?P<id>[\d]+)' ), $notes[1]->routes );
		$this->assertSame( array(), $this->notes_of( $result, Translation_Note::ENABLED ), 'An excluded route is not reported as enabled.' );
	}

	/**
	 * AC-7: headers become vary headers of the routes they match that are enabled.
	 */
	public function test_headers_vary_the_matched_enabled_routes(): void {
		$result = $this->site()->forward(
			array(),
			array(),
			array( '/wp/v2/posts' => 'X_Lang, Authorization,bad header' )
		);

		$expected                 = Config::default_route_rule()->to_array();
		$expected['vary_headers'] = array( 'x-lang' );
		$this->assertSame(
			array(
				'/wp/v2/posts'                => $expected,
				'/wp/v2/posts/(?P<id>[\d]+)'  => $expected,
			),
			$result->rules,
			'Only the enabled routes below the endpoint get the header; posts-archive is not enabled.'
		);

		$this->assertSame(
			array( Translation_Note::VARY_FORBIDDEN, Translation_Note::VARY_INVALID, Translation_Note::VARY ),
			$this->codes( $result )
		);
		$this->assertSame( array( 'authorization' ), $result->notes[0]->values );
		$this->assertSame( array( 'bad header' ), $result->notes[1]->values );
		$this->assertSame( array( 'x-lang' ), $result->notes[2]->values );
		$this->assertSame( array( '/wp/v2/posts', '/wp/v2/posts/(?P<id>[\d]+)' ), $result->notes[2]->routes );
		$this->assertSame( Translation_Note::SOURCE_HEADERS, $result->notes[2]->source );
		$this->assertSame( 'wp/v2', $result->notes[2]->namespace );
		$this->assertSame( 'posts', $result->notes[2]->endpoint );
	}

	public function test_headers_over_the_limit_are_reported(): void {
		$result = $this->site()->forward( array(), array(), array( 'wp/v2/pages' => 'A, B' ), 1 );
		$this->assertSame( array( Translation_Note::NO_ROUTE ), $this->codes( $result ), 'The pages route does not exist in this fixture.' );

		$result = $this->site()->forward( array(), array(), array( 'wp/v2/users' => 'A_One, B-Two, C' ), 2 );

		$this->assertSame( array( 'a-one', 'b-two' ), $result->rules['/wp/v2/users']['vary_headers'] );
		$this->assertSame(
			array( Translation_Note::DENIED, Translation_Note::VARY, Translation_Note::VARY_LIMIT ),
			$this->codes( $result )
		);
		$limit = $this->notes_of( $result, Translation_Note::VARY_LIMIT );
		$this->assertSame( array( 'c' ), $limit[0]->values );
		$this->assertSame( array( '/wp/v2/users' ), $limit[0]->routes );
	}

	public function test_headers_default_to_the_rule_limit(): void {
		$result = $this->site()->forward( array(), array(), array( 'wp/v2/users' => 'a,b,c,d,e,f,g' ) );

		$this->assertCount( Route_Rule::MAX_VARY_HEADERS, $result->rules['/wp/v2/users']['vary_headers'] );
		$limit = $this->notes_of( $result, Translation_Note::VARY_LIMIT );
		$this->assertSame( array( 'f', 'g' ), $limit[0]->values );
	}

	public function test_headers_accumulate_over_entries_and_skip_excluded_routes(): void {
		$result = $this->site()->forward(
			array( 'acme/v1' => array( 'items' ) ),
			array( 'wp/v2' => array( 'comments' ) ),
			array(
				'acme/v1/items'    => 'X-One',
				'acme/v1'          => 'x_one, X-Two',
				'wp/v2/comments'   => 'X-Three',
			)
		);

		$this->assertSame( array( 'x-one', 'x-two' ), $result->rules['/acme/v1/items']['vary_headers'] );
		$this->assertSame( array( 'x-one', 'x-two' ), $result->rules['/acme/v1/items/(?P<id>\d+)']['vary_headers'] );
		$this->assertArrayNotHasKey( '/acme/v1/other', $result->rules, 'A route that is not enabled gets no header.' );
		$this->assertSame( Route_Rule::STATUS_EXCLUDED, $result->rules['/wp/v2/comments']['status'] );
		$this->assertSame( array(), $result->rules['/wp/v2/comments']['vary_headers'], 'An excluded route is not varied.' );
	}

	public function test_header_entries_that_match_nothing_or_are_unusable_are_reported(): void {
		$result = $this->site()->forward(
			array(),
			array(),
			array(
				'nowhere/v1/x'     => 'X-One',
				'acme/v1/(a|b)'    => 'X-One',
				'/'                => 'X-One',
				'acme/v1/items'    => array( 'X-One' ),
			)
		);

		$this->assertSame( array(), $result->rules );
		$this->assertSame(
			array( Translation_Note::NO_ROUTE, Translation_Note::PATTERN, Translation_Note::INVALID_ENTRY, Translation_Note::INVALID_ENTRY ),
			$this->codes( $result )
		);
		$this->assertSame( Translation_Note::SOURCE_HEADERS, $result->notes[0]->source );
		$this->assertSame( 'acme/v1', $result->notes[1]->namespace );
		$this->assertSame( '(a|b)', $result->notes[1]->endpoint );
	}

	/**
	 * AC-8: a route key the rule schema rejects gives an invalid_rule note and no rule.
	 */
	public function test_an_over_long_route_key_gives_an_invalid_rule_note(): void {
		$long = '/acme/v1/' . str_repeat( 'a', Route_Rule::MAX_KEY_LENGTH );

		foreach ( array( false, true ) as $default ) {
			$translator = $this->translator( array( $this->info( $long, 'acme/v1', $default ) ) );
			$result     = $translator->forward( array( 'acme/v1' => array( 'aaa' ) ) );

			$this->assertSame( array(), $result->rules );
			$this->assertSame( array( Translation_Note::INVALID_RULE ), $this->codes( $result ) );
			$this->assertSame( array( $long ), $result->notes[0]->routes );
			$this->assertContains( 'invalid_route_key', $result->notes[0]->values );
		}
	}

	public function test_a_rule_over_the_vary_limit_gives_an_invalid_rule_note(): void {
		$result = $this->site()->forward( array(), array(), array( 'wp/v2/users' => 'a,b,c,d,e,f,g' ), Route_Rule::MAX_VARY_HEADERS + 2 );

		$this->assertArrayNotHasKey( '/wp/v2/users', $result->rules );
		$invalid = $this->notes_of( $result, Translation_Note::INVALID_RULE );
		$this->assertCount( 1, $invalid );
		$this->assertContains( 'too_many_vary_headers', $invalid[0]->values );
	}

	public function test_every_rule_is_valid(): void {
		$result = $this->site()->forward(
			array(
				'wp/v2'   => array( 'posts', 'users', 'comments' ),
				'acme/v1' => array( '' ),
			),
			array( 'wp/v2' => array( 'comments' ) ),
			array(
				'wp/v2/posts' => 'X-One',
				'acme/v1'     => 'X-Two',
			)
		);

		$this->assertNotSame( array(), $result->rules );
		foreach ( $result->rules as $key => $rule ) {
			$this->assertTrue( Route_Rule::validate_key( (string) $key, Route_Rule::MATCH_REGISTERED )->is_valid(), $key );
			$this->assertTrue( Route_Rule::from_array( $rule )->validate()->is_valid(), $key );
			$this->assertSame( Route_Rule::MATCH_REGISTERED, $rule['match'] );
		}
	}

	/**
	 * Fixture routes of AC-9.
	 *
	 * @return Endpoint_Translator
	 */
	private function reverse_site(): Endpoint_Translator {
		return $this->translator(
			array(
				$this->info( '/wp/v2', 'wp/v2' ),
				$this->info( '/wp/v2/posts', 'wp/v2', true ),
				$this->info( '/wp/v2/posts/(?P<id>[\d]+)', 'wp/v2', true ),
				$this->info( '/wp/v2/users', 'wp/v2', true ),
				$this->info( '/acme/v1', 'acme/v1' ),
				$this->info( '/acme/v1/items/(?P<id>\d+)', 'acme/v1' ),
				$this->info( '/acme/v1/(?P<slug>[a-z]+)', 'acme/v1' ),
				$this->info( '/loose/route', '' ),
			)
		);
	}

	/**
	 * AC-9: enabled registered rules map back to namespace => rest bases.
	 */
	public function test_reverse_recovers_namespaces_and_rest_bases(): void {
		$enabled = ( new Route_Rule( Route_Rule::STATUS_ENABLED ) )->to_array();
		$rules   = array(
			'/wp/v2/posts'                => $enabled,
			'/wp/v2/posts/(?P<id>[\d]+)'  => $enabled,
			'/acme/v1/items/(?P<id>\d+)'  => $enabled,
			'/wp/v2/users'                => ( new Route_Rule( Route_Rule::STATUS_EXCLUDED ) )->to_array(),
			'/wp/v2/*'                    => ( new Route_Rule( Route_Rule::STATUS_ENABLED, Route_Rule::MATCH_WILDCARD ) )->to_array(),
		);

		$this->assertSame(
			array(
				'wp/v2'   => array( 'posts' ),
				'acme/v1' => array( 'items' ),
			),
			$this->reverse_site()->reverse( $rules )
		);
	}

	public function test_reverse_skips_what_has_no_rest_base(): void {
		$enabled = new Route_Rule( Route_Rule::STATUS_ENABLED );
		$rules   = array(
			'/wp/v2'                   => $enabled,
			'/acme/v1'                 => $enabled,
			'/acme/v1/(?P<slug>[a-z]+)' => $enabled,
			'/loose/route'             => $enabled,
			'/unknown/v1/thing'        => $enabled,
			'/wp/v2/posts'             => new Route_Rule( Route_Rule::STATUS_DEFAULT ),
			'/wp/v2/users'             => 'enabled',
		);

		$this->assertSame( array(), $this->reverse_site()->reverse( $rules ) );
		$this->assertSame( array(), $this->reverse_site()->reverse( array() ) );
	}

	public function test_reverse_reads_rule_objects_and_effective_rules(): void {
		$rules = array(
			'/wp/v2/posts'             => new Route_Rule( Route_Rule::STATUS_ENABLED ),
			'/wp/v2/posts/(?P<id>[\d]+)' => new Effective_Rule( new Route_Rule( Route_Rule::STATUS_ENABLED ), Effective_Rule::SOURCE_SETTINGS ),
			'/acme/v1/items/(?P<id>\d+)' => array( 'status' => 'enabled' ),
		);

		$this->assertSame(
			array(
				'wp/v2'   => array( 'posts' ),
				'acme/v1' => array( 'items' ),
			),
			$this->reverse_site()->reverse( $rules )
		);
	}

	public function test_reverse_inverts_forward(): void {
		$translator = $this->site();
		$result     = $translator->forward( array( 'acme/v1' => array( 'items' ) ) );

		$this->assertSame( array( 'acme/v1' => array( 'items' ) ), $translator->reverse( $result->rules ) );
	}

	/**
	 * AC-10: every enabled key is matched again by the base reverse() gives it.
	 */
	public function test_every_enabled_key_is_matched_by_its_reverse_entry(): void {
		$suffixes = array(
			'/things',
			'/things/(?P<id>[\d]+)',
			'/things/(?P<id>[\d]+)/revisions',
			'/feed(?:/(?P<kind>[a-z]+))?',
			'/tag-list',
			'/tag-list/(?P<id>\d+)',
			'/odd.name',
			'/odd.name/(?P<id>\d+)',
			'/a-b',
			'/a-b/(?P<x>\d+)/(?P<y>\d+)',
		);

		$infos = array();
		foreach ( array( 'wp/v2', 'acme/v1', 'my.plugin/v2' ) as $namespace ) {
			foreach ( $suffixes as $suffix ) {
				$infos[] = $this->info( '/' . $namespace . $suffix, $namespace );
			}
		}
		$this->assertCount( 30, $infos );

		$translator = $this->translator( $infos );
		$enabled    = ( new Route_Rule( Route_Rule::STATUS_ENABLED ) )->to_array();
		$rules      = array();
		foreach ( $infos as $info ) {
			$rules[ $info->route ] = $enabled;
		}

		$reverse = $translator->reverse( $rules );
		$this->assertSame( array( 'wp/v2', 'acme/v1', 'my.plugin/v2' ), array_keys( $reverse ) );

		foreach ( $infos as $info ) {
			$found = false;
			foreach ( $reverse[ $info->namespace ] as $base ) {
				$found = $found || in_array( $info->route, $translator->match( $info->namespace, $base ), true );
			}
			$this->assertTrue( $found, $info->route );
		}
	}

	public function test_the_translator_is_pure(): void {
		$dir = dirname( __DIR__, 2 ) . '/includes/legacy/';
		foreach ( array( 'class-endpoint-translator.php', 'class-translation.php', 'class-translation-note.php' ) as $file ) {
			$source = (string) file_get_contents( $dir . $file );

			$this->assertNotSame( '', $source, $file );
			$this->assertSame( 0, preg_match( '/apply_filters|do_action|get_option|__\(/', $source ), $file . ' calls WordPress.' );
			$this->assertSame( 0, preg_match( '/wp_rest_cache|wp-rest-cache|wprc|wrc_|acato/i', $source ), $file . ' names the original.' );
		}
	}
}
