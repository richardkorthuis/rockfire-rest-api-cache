<?php
/**
 * Regression test: the stores can be built in the must-use phase, before pluggable.php is loaded.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use PHPUnit\Framework\TestCase;

/**
 * Builds both stores in a separate PHP process where `wp_rand()` (a pluggable function) does not exist,
 * like the early phase of the must-use file. Found by the release activation check (#49).
 *
 * @covers \Rockfire\Rest_Api_Cache\Storage\Database_Store
 * @covers \Rockfire\Rest_Api_Cache\Storage\Object_Cache_Store
 */
class Test_Store_Before_Pluggable extends TestCase {

	public function test_stores_build_and_sample_without_wp_rand(): void {
		$autoloader = dirname( __DIR__, 2 ) . '/includes/class-autoloader.php';
		$script     = '<?php
define( "ABSPATH", __DIR__ . "/" );
require ' . var_export( $autoloader, true ) . ';
\Rockfire\Rest_Api_Cache\Autoloader::register();
if ( function_exists( "wp_rand" ) ) { echo "wp_rand exists"; exit( 1 ); }
$repo = ( new ReflectionClass( \Rockfire\Rest_Api_Cache\Storage\Repository::class ) )->newInstanceWithoutConstructor();
foreach ( array( \Rockfire\Rest_Api_Cache\Storage\Database_Store::class, \Rockfire\Rest_Api_Cache\Storage\Object_Cache_Store::class ) as $class ) {
	$store = new $class( $repo, 10 );
	$rng   = ( new ReflectionProperty( $class, "rng" ) )->getValue( $store );
	$n     = $rng( 1, 10 );
	if ( ! is_int( $n ) || $n < 1 || $n > 10 ) { echo "bad sample from $class"; exit( 1 ); }
}
echo "ok";
';
		$file = tempnam( sys_get_temp_dir(), 'rfrac' );
		file_put_contents( $file, $script ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		try {
			$output = array();
			$status = 1;
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );
		} finally {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertSame( 'ok', implode( "\n", $output ) );
	}
}
