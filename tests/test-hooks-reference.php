<?php
/**
 * Enforces that every public `rfrac/...` hook fired by shipped code is registered in Hooks.
 *
 * @package Rockfire_Rest_Api_Cache
 */

use PHPUnit\Framework\TestCase;
use Rockfire\Rest_Api_Cache\Hooks;

/**
 * @covers \Rockfire\Rest_Api_Cache\Hooks
 */
class Test_Hooks_Reference extends TestCase {

	/**
	 * Hooks fired by the legacy engine that have no Hooks constant.
	 *
	 * Emptied when the legacy engine was removed (#48). Do not add hooks here: add a Hooks constant.
	 *
	 * @var list<string>
	 */
	private const LEGACY_HOOKS = array();

	/**
	 * Plugin root.
	 */
	private static function root(): string {
		return dirname( __DIR__ );
	}

	/**
	 * Normalise dynamic segments (`{$var}` / `{var}`) to `{*}`.
	 *
	 * @param string $name Hook name.
	 */
	private static function normalize_name( string $name ): string {
		return (string) preg_replace( '/\{[^}]*\}/', '{*}', $name );
	}

	/**
	 * Collect the normalised hook names fired in the given files and directories.
	 *
	 * @param list<string> $paths Absolute files or directories.
	 * @return array<string,string> Hook name => first file that fires it.
	 */
	private static function fired_hooks( array $paths ): array {
		$files = array();
		foreach ( $paths as $path ) {
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
		}

		$found = array();
		foreach ( $files as $file ) {
			$code = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( preg_match_all( '/(?:do_action|apply_filters)(?:_ref_array)?\(\s*[\'"](rfrac\/[^\'"]+)/', $code, $m ) ) {
				foreach ( $m[1] as $name ) {
					$found[ self::normalize_name( $name ) ] ??= $file;
				}
			}
		}
		return $found;
	}

	/**
	 * Hooks fired by the given paths that are neither registered nor legacy.
	 *
	 * @param list<string> $paths Absolute files or directories.
	 * @return array<string,string> Hook name => file.
	 */
	private static function unregistered( array $paths ): array {
		$known = array_map( array( self::class, 'normalize_name' ), array_values( Hooks::all() ) );
		$known = array_merge( $known, self::LEGACY_HOOKS );
		return array_diff_key( self::fired_hooks( $paths ), array_flip( $known ) );
	}

	/**
	 * Paths scanned for fired hooks.
	 *
	 * @return list<string>
	 */
	private static function shipped_paths(): array {
		$root = self::root();
		return array(
			$root . '/includes',
			$root . '/admin',
			$root . '/mu-plugin',
			$root . '/uninstall.php',
			$root . '/rockfire-rest-api-cache.php',
		);
	}

	public function test_every_fired_hook_is_registered(): void {
		$missing = self::unregistered( self::shipped_paths() );
		$this->assertSame(
			array(),
			$missing,
			'Hooks fired but not registered in Hooks (add a constant): ' . implode( ', ', array_keys( $missing ) )
		);
	}

	public function test_unregistered_hook_is_reported_by_name(): void {
		$dir = sys_get_temp_dir() . '/rfrac-hooks-' . uniqid();
		mkdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		$file = $dir . '/fixture.php';
		file_put_contents( $file, "<?php\n\$x = apply_filters( 'rfrac/unknown_x', 1 );\ndo_action( \"rfrac/dyn/{\$a}\" );\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		try {
			$missing = self::unregistered( array( $dir ) );
		} finally {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}

		$this->assertSame( array( 'rfrac/unknown_x', 'rfrac/dyn/{*}' ), array_keys( $missing ) );
	}

	public function test_dynamic_names_normalise_to_wildcard(): void {
		$this->assertSame( 'rfrac/flush_on_meta_update/{*}/{*}', self::normalize_name( 'rfrac/flush_on_meta_update/{$meta_type}/{$meta_key}' ) );
		$this->assertContains(
			'rfrac/flush_on_meta_update/{*}/{*}',
			array_map( array( self::class, 'normalize_name' ), Hooks::all() )
		);
	}

	public function test_every_constant_is_documented(): void {
		$class = new ReflectionClass( Hooks::class );
		foreach ( $class->getReflectionConstants() as $constant ) {
			$doc = $constant->getDocComment();
			$this->assertIsString( $doc, $constant->getName() . ' needs a docblock' );
			$this->assertStringContainsString( '@since', (string) $doc, $constant->getName() . ' needs @since' );
		}
	}

	public function test_registry_values_are_unique_rfrac_names(): void {
		$all = Hooks::all();
		$this->assertNotEmpty( $all );
		$this->assertSame( count( $all ), count( array_unique( $all ) ) );
		foreach ( $all as $name => $value ) {
			$this->assertStringStartsWith( 'rfrac/', $value, $name );
		}
		$this->assertSame( 'rfrac/activate_site', $all['ACTIVATE_SITE'] );
	}

	public function test_the_global_request_rule_filters_are_registered(): void {
		$this->assertSame( 'rfrac/global_ignore_params', Hooks::GLOBAL_IGNORE_PARAMS );
		$this->assertSame( 'rfrac/global_vary_headers', Hooks::GLOBAL_VARY_HEADERS );
		$all = Hooks::all();
		$this->assertSame( 'rfrac/global_ignore_params', $all['GLOBAL_IGNORE_PARAMS'] );
		$this->assertSame( 'rfrac/global_vary_headers', $all['GLOBAL_VARY_HEADERS'] );
	}

	/**
	 * Whether the docblock of a `Hooks` constant says the filter is compiled into the configuration.
	 *
	 * @param string $constant Constant name.
	 *
	 * @return bool
	 */
	private static function documented_as_compiled( string $constant ): bool {
		$doc = (string) ( new ReflectionClassConstant( Hooks::class, $constant ) )->getDocComment();
		return 1 === preg_match( '/compiled into the\s+(?:\*\s+)?configuration/i', $doc );
	}

	/**
	 * The hooks reference on rockfire.nl is generated from these docblocks and marks the compiled filters, which apply
	 * after the next recompile, so every filter the compiler bakes into the snapshot has to say so, or it reads as
	 * taking effect at once.
	 */
	public function test_the_docblocks_mark_every_filter_that_is_compiled_into_the_configuration(): void {
		$source = (string) file_get_contents( self::root() . '/includes/config/class-config-compiler.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		preg_match_all( '/apply_filters\(\s*Hooks::([A-Z_]+)\b/', $source, $m );

		// The partial server filter only decides whether the compiler rebuilds the snapshot; it bakes nothing in.
		$constants = array_values( array_diff( array_unique( $m[1] ), array( 'PARTIAL_SERVER' ) ) );
		$this->assertGreaterThan( 10, count( $constants ), 'The scan of the compiler found its filters.' );

		$all = Hooks::all();
		foreach ( $constants as $constant ) {
			$this->assertArrayHasKey( $constant, $all, $constant );
		}
		// The WooCommerce filters are compiled into the configuration by the integration.
		$constants = array_merge( $constants, array( 'WC_LOCATION_PRICING', 'WC_CURRENCY_COOKIES' ) );

		foreach ( $constants as $constant ) {
			$this->assertTrue( self::documented_as_compiled( $constant ), "{$all[ $constant ]} is baked into the snapshot but its docblock does not say it is compiled into the configuration." );
		}

		$this->assertFalse( self::documented_as_compiled( 'PARTIAL_SERVER' ) );
		$this->assertFalse( self::documented_as_compiled( 'ENTRY_TTL' ) );
	}

	public function test_legacy_allow_list_has_no_stale_or_registered_entries(): void {
		$this->assertSame( array(), self::LEGACY_HOOKS, 'The legacy engine is gone (#48): the allow-list stays empty.' );
		$fired      = self::fired_hooks( self::shipped_paths() );
		$registered = array_map( array( self::class, 'normalize_name' ), array_values( Hooks::all() ) );
		foreach ( self::LEGACY_HOOKS as $name ) {
			$this->assertArrayHasKey( $name, $fired, "$name is no longer fired: remove it from LEGACY_HOOKS" );
			$this->assertNotContains( $name, $registered, "$name now has a constant: remove it from LEGACY_HOOKS" );
		}
	}
}
