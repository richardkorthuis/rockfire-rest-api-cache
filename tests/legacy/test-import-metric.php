<?php
/**
 * End-to-end test of the success metric of the legacy import: every endpoint the original cached is cached here.
 *
 * Every test runs for the early server and for the fallback server.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Config_Compiler;
use Rockfire\Rest_Api_Cache\Config\Settings_Schema;
use Rockfire\Rest_Api_Cache\Legacy\Findings;
use Rockfire\Rest_Api_Cache\Legacy\Import_Report;
use Rockfire\Rest_Api_Cache\Legacy\Importer;
use Rockfire\Rest_Api_Cache\Legacy\Original_Settings;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Request\Bypass_Reason;
use Rockfire\Rest_Api_Cache\Serving\Request_State;

/**
 * @covers \Rockfire\Rest_Api_Cache\Legacy\Importer
 * @covers \Rockfire\Rest_Api_Cache\Serving\Early_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Fallback_Server
 * @covers \Rockfire\Rest_Api_Cache\Serving\Lookup
 * @covers \Rockfire\Rest_Api_Cache\Serving\Capture
 */
class Test_Import_Metric extends Rfrac_E2e_Test_Case {

	/**
	 * The metric URLs the original cached, at least.
	 *
	 * @var int
	 */
	const MIN_URLS = 15;

	public function set_up() {
		parent::set_up();
		Rfrac_Legacy_Fixture::clear();
		delete_option( Names::OPTION_LEGACY_FINDINGS );
		// Rockfire's settings were saved once, so the import updates the option instead of adding it.
		update_option( Names::OPTION_SETTINGS, array_merge( Settings_Schema::defaults(), array( 'routes' => array() ) ), false );
		$this->seed_content();
		$this->import_the_fixture_site();
		$this->empty_tables();
	}

	public function tear_down() {
		Rfrac_Legacy_Fixture::clear();
		delete_option( Names::OPTION_LEGACY_FINDINGS );
		parent::tear_down();
	}

	/**
	 * Create what the core endpoints of the metric answer with: an empty result is never stored.
	 *
	 * @return void
	 */
	private function seed_content(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$posts  = self::factory()->post->create_many( 3, array( 'post_author' => $author ) );
		self::factory()->post->create( array( 'post_author' => $author, 'post_type' => 'page' ) );
		self::factory()->tag->create();
		self::factory()->comment->create( array( 'comment_post_ID' => $posts[0], 'comment_approved' => '1' ) );
		self::factory()->attachment->create( array( 'post_parent' => $posts[0], 'post_mime_type' => 'image/jpeg' ) );
	}

	/**
	 * Write the options of the site that ran the original, import them, and compile the configuration from the real
	 * REST server, the way a settings save does on a live site.
	 *
	 * @return void
	 */
	private function import_the_fixture_site(): void {
		Rfrac_Legacy_Fixture::install_metric();
		$this->new_rest_server( array( 'Rfrac_Legacy_Fixture', 'register_metric_routes' ) );

		$report = ( new Importer( Config::instance(), new Findings(), new Original_Settings() ) )->run();
		$this->assertContains( $report->result, array( Import_Report::RESULT_COMPILED, Import_Report::RESULT_DEFERRED ), 'Precondition: the import saved its rules.' );

		( new Config_Compiler() )->compile( rest_get_server() );
	}

	/**
	 * The callback that registers the routes of the live request.
	 *
	 * @return array{0:string,1:string}
	 */
	private function registrar(): array {
		return array( 'Rfrac_Legacy_Fixture', 'register_metric_routes' );
	}

	/**
	 * Request a URL as an anonymous visitor: the lookup, and when it misses the live request.
	 *
	 * @param Rfrac_Serving_Harness $h       Harness.
	 * @param string                $url     Request path below the REST prefix, with its query string.
	 * @param array<string,mixed>   $server  Extra `$_SERVER` entries (request headers).
	 * @param array<string,mixed>   $cookie  Cookies.
	 *
	 * @return string|null The live body, null when the cache served the request.
	 */
	private function fetch( Rfrac_Serving_Harness $h, string $url, array $server = array(), array $cookie = array() ): ?string {
		$h->request( '/wp-json' . $url, $server, $cookie );
		return $h->miss( self::route_of( $url ), $this->registrar() );
	}

	/**
	 * Whether the next request for a URL is served from the cache.
	 *
	 * @param Rfrac_Serving_Harness $h      Harness.
	 * @param string                $url    Request path below the REST prefix, with its query string.
	 * @param array<string,mixed>   $server Extra `$_SERVER` entries (request headers).
	 * @param array<string,mixed>   $cookie Cookies.
	 *
	 * @return bool
	 */
	private function is_hit( Rfrac_Serving_Harness $h, string $url, array $server = array(), array $cookie = array() ): bool {
		$h->request( '/wp-json' . $url, $server, $cookie );
		return $h->lookup();
	}

	/**
	 * The route of a URL: its path.
	 *
	 * @param string $url Request path below the REST prefix, with its query string.
	 *
	 * @return string
	 */
	private static function route_of( string $url ): string {
		return (string) wp_parse_url( $url, PHP_URL_PATH );
	}

	/**
	 * AC-1: after the import the share of the URLs the original cached that are served as a hit is exactly 1.0.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_every_endpoint_the_original_cached_is_cached_after_import( string $path ): void {
		$urls = Rfrac_Legacy_Fixture::metric_urls();
		$this->assertFalse( Rfrac_Legacy_Fixture::original_caches( '/wp/v2/comments' ), 'Precondition: the original did not cache a disallowed endpoint.' );
		$this->assertTrue( Rfrac_Legacy_Fixture::original_caches( '/fixture/v1/items-archive' ), 'Precondition: the original matched an endpoint as a substring.' );
		$this->assertFalse( Rfrac_Legacy_Fixture::original_caches( '/other/v1/things' ), 'Precondition: the original did not cache a namespace it did not allow.' );

		$expected = array_values( array_filter( $urls, array( 'Rfrac_Legacy_Fixture', 'original_caches' ) ) );
		$this->assertGreaterThanOrEqual( self::MIN_URLS, count( $expected ), 'The metric covers at least ' . self::MIN_URLS . ' URLs.' );

		$h    = $this->harness( $path );
		$hits = array();
		$lost = array();
		foreach ( $expected as $url ) {
			// Every URL starts from an empty cache, so its own miss stores its own entry.
			$this->empty_tables();
			$stored = null !== $this->fetch( $h, $url ) && 1 === Rfrac_Entry_Fixtures::count();
			$hit    = $this->is_hit( $h, $url );

			if ( $stored && $hit ) {
				$hits[] = $url;
			} else {
				$lost[] = $url . ( $stored ? ' (not served from the cache)' : ' (not stored)' );
			}
		}

		$share = (float) count( $hits ) / count( $expected );
		$this->assertSame( 1.0, $share, sprintf( 'On the %s path, the original cached %d URLs and these are not cached after the import: %s', $path, count( $expected ), implode( ', ', $lost ) ) );
		$this->assertNoFailures();
	}

	/**
	 * AC-2: an endpoint the original disallowed is neither served from the cache nor stored.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_disallowed_endpoint_is_not_served_from_the_cache_and_not_stored( string $path ): void {
		$h = $this->harness( $path );

		$first = $this->fetch( $h, '/wp/v2/comments' );
		$this->assertNotNull( $first, 'The first request is answered live.' );
		$this->assertNotEmpty( json_decode( $first, true ), 'The live response has comments, so it is the route that keeps it out of the cache.' );
		$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ) );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), 'No entry is stored.' );

		$this->assertFalse( $this->is_hit( $h, '/wp/v2/comments' ), 'The repeat is not served from the cache.' );
		$this->assertNotNull( $this->fetch( $h, '/wp/v2/comments' ), 'The repeat is answered live too.' );
		$this->assertSame( 0, Rfrac_Entry_Fixtures::count() );
		$this->assertNoFailures();
	}

	/**
	 * AC-3: the cacheable request header of the original splits the entries of its endpoint.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_cacheable_request_header_stores_two_entries_that_each_hit( string $path ): void {
		$h      = $this->harness( $path );
		$bodies = array();
		foreach ( array( 'nl', 'en' ) as $lang ) {
			$bodies[ $lang ] = $this->fetch( $h, '/fixture/v1/items', array( 'HTTP_X_LANG' => $lang ) );
			$this->assertNotNull( $bodies[ $lang ], "The first request with X-Lang: $lang is a miss." );
		}
		$this->assertNotSame( $bodies['nl'], $bodies['en'], 'The live responses differ per language.' );
		$this->assertSame( 2, Rfrac_Entry_Fixtures::count(), 'Two distinct entries are stored.' );

		foreach ( array( 'nl', 'en' ) as $lang ) {
			$this->assertTrue( $this->is_hit( $h, '/fixture/v1/items', array( 'HTTP_X_LANG' => $lang ) ), "The repeat with X-Lang: $lang is a hit." );
			$this->assertSame( $bodies[ $lang ], $h->output, "The hit holds the entry of X-Lang: $lang." );
		}
		$this->assertNoFailures();
	}

	/**
	 * AC-4: an uncached parameter of the original does not split the entry of its endpoint.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_an_uncached_parameter_is_served_from_the_entry_without_it( string $path ): void {
		$h = $this->harness( $path );

		$body = $this->fetch( $h, '/fixture/v1/items' );
		$this->assertNotNull( $body, 'The first request is a miss.' );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count() );

		$this->assertTrue( $this->is_hit( $h, '/fixture/v1/items?utm_source=news' ), 'The tracking link is a hit.' );
		$this->assertSame( $body, $h->output );
		$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), 'Still one entry.' );
		$this->assertNoFailures();
	}

	/**
	 * The credentials of AC-5: a basic authorization header and a logged-in cookie.
	 *
	 * @return array<string,array{0:array<string,string>,1:array<string,string>}>
	 */
	public static function credentials(): array {
		return array(
			'authorization header' => array( array( 'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode( 'editor:secret' ) ), array() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'logged-in cookie'     => array( array(), array( 'wordpress_logged_in_' . md5( 'rfrac' ) => 'editor|1999999999|token|hash' ) ),
		);
	}

	/**
	 * AC-5: a request that carries a credential is never served from the cache and never stores an entry.
	 *
	 * @dataProvider paths
	 *
	 * @param string $path Serving path.
	 */
	public function test_a_request_with_a_credential_is_never_served_from_the_cache_or_stored( string $path ): void {
		$h = $this->harness( $path );

		foreach ( self::credentials() as $label => [ $server, $cookie ] ) {
			foreach ( Rfrac_Legacy_Fixture::metric_urls() as $url ) {
				$this->empty_tables();

				// On an empty cache the credentialed request is answered live and stores nothing.
				$this->assertNotNull( $this->fetch( $h, $url, $server, $cookie ), "$url with the $label is answered live." );
				$this->assertSame( 'BYPASS', $h->live_header( 'X-Rfrac-Cache' ), "$url with the $label is bypassed." );
				$this->assertSame( 0, Rfrac_Entry_Fixtures::count(), "$url with the $label stores nothing." );

				if ( ! Rfrac_Legacy_Fixture::original_caches( $url ) ) {
					continue;
				}

				// With the anonymous entry stored, the credentialed request is still not served from it.
				$this->assertNotNull( $this->fetch( $h, $url ), "$url is stored for an anonymous visitor." );
				$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), "Precondition: $url has an entry." );
				$this->assertFalse( $this->is_hit( $h, $url, $server, $cookie ), "$url with the $label is not served from the cache." );
				$this->assertSame( Bypass_Reason::CREDENTIALS, Request_State::decision()->reason, "$url with the $label is bypassed for its credential." );
				$this->assertNotNull( $this->fetch( $h, $url, $server, $cookie ), "$url with the $label is answered live again." );
				$this->assertSame( 1, Rfrac_Entry_Fixtures::count(), "$url with the $label stores nothing next to the entry." );
			}
		}
		$this->assertNoFailures();
	}
}
