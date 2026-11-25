<?php
/**
 * Tests for the shims of the original's REST controllers.
 *
 * @package Rockfire_Rest_Api_Cache
 */

defined( 'ABSPATH' ) || exit;

use Rockfire\Rest_Api_Cache\Config\Route_Discovery;
use Rockfire\Rest_Api_Cache\Install\Coexistence_Guard;
use Rockfire\Rest_Api_Cache\Legacy\Shim_Notice;
use WP_Rest_Cache_Plugin\Includes\Controller\Attachment_Controller;
use WP_Rest_Cache_Plugin\Includes\Controller\Post_Controller;
use WP_Rest_Cache_Plugin\Includes\Controller\Term_Controller;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Controller\Post_Controller
 * @covers \WP_Rest_Cache_Plugin\Includes\Controller\Term_Controller
 * @covers \WP_Rest_Cache_Plugin\Includes\Controller\Attachment_Controller
 * @covers \WP_Rest_Cache_Plugin\Includes\Controller\Controller_Trait
 */
class Test_Shim_Controllers extends Rfrac_Test_Case {

	const OWN = 'rockfire-rest-api-cache/rockfire-rest-api-cache.php';

	const TRAIT_NAME = 'WP_Rest_Cache_Plugin\Includes\Controller\Controller_Trait';

	const ITEM_OPTION = 'wp_rest_cache_item_allowed_endpoints';

	/**
	 * Arguments of every `doing_it_wrong_run` and `deprecated_function_run` since set_up().
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private $notices = array();

	/**
	 * Names of the options added, updated or deleted since the listeners were attached.
	 *
	 * @var string[]
	 */
	private $option_writes = array();

	public function set_up() {
		parent::set_up();
		update_option( 'active_plugins', array( self::OWN ) );
		Coexistence_Guard::reset();
		Shim_Notice::reset();
		$this->notices       = array();
		$this->option_writes = array();

		register_post_type(
			'rfrac_book',
			array(
				'public'                => true,
				'show_in_rest'          => true,
				'rest_base'             => 'books',
				'rest_controller_class' => Post_Controller::class,
			)
		);
		register_taxonomy(
			'rfrac_genre',
			'rfrac_book',
			array(
				'public'                => true,
				'show_in_rest'          => true,
				'rest_base'             => 'genres',
				'rest_controller_class' => Term_Controller::class,
			)
		);
		register_post_type(
			'rfrac_file',
			array(
				'public'                => true,
				'show_in_rest'          => true,
				'rest_base'             => 'files',
				'rest_controller_class' => Attachment_Controller::class,
			)
		);

		$notice = function ( ...$args ) {
			$this->notices[] = $args;
		};
		add_action( 'doing_it_wrong_run', $notice, 10, 3 );
		add_action( 'deprecated_function_run', $notice, 10, 3 );

		$record = function ( $option ) {
			$this->option_writes[] = (string) $option;
		};
		add_action( 'added_option', $record );
		add_action( 'updated_option', $record );
		add_action( 'deleted_option', $record );
	}

	public function tear_down() {
		_unregister_post_type( 'rfrac_file' );
		unregister_taxonomy( 'rfrac_genre' );
		_unregister_post_type( 'rfrac_book' );
		Coexistence_Guard::reset();
		Shim_Notice::reset();
		parent::tear_down();
	}

	public function test_the_routes_of_a_post_type_and_a_taxonomy_stay_registered() {
		$server = $this->new_rest_server();
		$routes = $server->get_routes();

		$this->assertArrayHasKey( '/wp/v2/books', $routes );
		$this->assertArrayHasKey( '/wp/v2/books/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/wp/v2/genres', $routes );
		$this->assertArrayHasKey( '/wp/v2/genres/(?P<id>[\d]+)', $routes );
	}

	public function test_the_collection_of_a_post_type_is_served() {
		$server = $this->new_rest_server();
		$id     = self::factory()->post->create(
			array(
				'post_type'   => 'rfrac_book',
				'post_status' => 'publish',
				'post_title'  => 'Sample book',
			)
		);

		$response = $server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/books' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $id, wp_list_pluck( (array) $response->get_data(), 'id' ) );
	}

	public function test_the_collection_of_a_taxonomy_is_served() {
		$server = $this->new_rest_server();
		$id     = self::factory()->term->create(
			array(
				'taxonomy' => 'rfrac_genre',
				'name'     => 'Sample genre',
			)
		);

		$response = $server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/genres' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $id, wp_list_pluck( (array) $response->get_data(), 'id' ) );
	}

	public function test_the_controllers_are_the_core_controllers() {
		$this->new_rest_server();

		$books = get_post_type_object( 'rfrac_book' );
		$this->assertInstanceOf( WP_Post_Type::class, $books );
		$this->assertInstanceOf( WP_REST_Posts_Controller::class, $books->get_rest_controller() );
		$this->assertInstanceOf( Post_Controller::class, $books->get_rest_controller() );

		$genres = get_taxonomy( 'rfrac_genre' );
		$this->assertInstanceOf( WP_Taxonomy::class, $genres );
		$this->assertInstanceOf( WP_REST_Terms_Controller::class, $genres->get_rest_controller() );
		$this->assertInstanceOf( Term_Controller::class, $genres->get_rest_controller() );
	}

	public function test_discovery_lists_the_post_type_route_as_a_default() {
		$server = $this->new_rest_server();

		$found = ( new Route_Discovery() )->discover( $server );

		$this->assertArrayHasKey( '/wp/v2/books', $found );
		$this->assertTrue( $found['/wp/v2/books']->is_default );
		$this->assertTrue( $found['/wp/v2/books']->readable );
	}

	public function test_no_option_is_written_and_no_notice_is_raised() {
		$this->new_rest_server();
		get_post_type_object( 'rfrac_book' )->get_rest_controller();
		get_taxonomy( 'rfrac_genre' )->get_rest_controller();
		get_post_type_object( 'rfrac_file' )->get_rest_controller();

		$original_options = array_filter( $this->option_writes, static fn( string $option ): bool => str_starts_with( $option, 'wp_rest_cache' ) );

		$this->assertSame( 'absent', get_option( self::ITEM_OPTION, 'absent' ) );
		$this->assertSame( array(), array_values( $original_options ) );
		$this->assertSame( array(), $this->notices );
	}

	public function test_an_attachment_style_post_type_keeps_its_collection_route() {
		$server = $this->new_rest_server();

		$this->assertArrayHasKey( '/wp/v2/files', $server->get_routes() );
		$controller = get_post_type_object( 'rfrac_file' )->get_rest_controller();
		$this->assertInstanceOf( WP_REST_Attachments_Controller::class, $controller );
		$this->assertInstanceOf( Attachment_Controller::class, $controller );
		$this->assertTrue( trait_exists( self::TRAIT_NAME ) );
		$this->assertSame( 'absent', get_option( self::ITEM_OPTION, 'absent' ) );
	}

	public function test_the_trait_is_used_by_the_three_controllers() {
		foreach ( array( Post_Controller::class, Term_Controller::class, Attachment_Controller::class ) as $class ) {
			$this->assertContains( self::TRAIT_NAME, class_uses( $class ), $class );
		}
	}

	public function test_the_trait_constructor_has_the_original_signature() {
		$constructor = ( new ReflectionClass( Post_Controller::class ) )->getConstructor();

		$this->assertInstanceOf( ReflectionMethod::class, $constructor );
		$this->assertSame( array( 'item' ), array_map( static fn( ReflectionParameter $p ): string => $p->getName(), $constructor->getParameters() ) );
		$this->assertFalse( $constructor->hasReturnType() );
		$this->assertNull( $constructor->getParameters()[0]->getType() );
	}

	public function test_the_shim_files_have_no_gettext_calls() {
		$files = array(
			'class-post-controller.php',
			'class-term-controller.php',
			'class-attachment-controller.php',
			'trait-controller.php',
		);

		foreach ( $files as $file ) {
			$path = RFRAC_DIR . 'includes/legacy/shims/controller/' . $file;
			$this->assertFileExists( $path );
			$this->assertDoesNotMatchRegularExpression(
				'/\b(?:__|_e|_x|_ex|_n|_nx|esc_html__|esc_html_e|esc_html_x|esc_attr__|esc_attr_e|esc_attr_x)\s*\(/',
				(string) file_get_contents( $path ),
				$file
			);
		}
	}
}
