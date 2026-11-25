<?php
/**
 * Maps changes of site-wide options to invalidation.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Invalidation;

use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Flushes everything when URLs change and the site-info route when the site identity changes.
 *
 * @since 1.0.0
 */
final class Option_Events {

	use Guarded_Trait;
	use Hook_Subscriber_Trait;

	/**
	 * Invalidator.
	 *
	 * @var Invalidator
	 */
	private Invalidator $invalidator;

	/**
	 * Option name => `'all'` or tags; built (and filtered) on first use.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $map = null;

	/**
	 * Constructor.
	 *
	 * @param Invalidator $invalidator Invalidator.
	 */
	public function __construct( Invalidator $invalidator ) {
		$this->invalidator = $invalidator;
	}

	/**
	 * The hooks: `updated_option` (10, 3) and `added_option` (10, 2).
	 *
	 * @return array<int,array<int,mixed>>
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		return array(
			array( 'updated_option', 'on_updated_option', 10, 3 ),
			array( 'added_option', 'on_added_option', 10, 2 ),
		);
	}

	/**
	 * `updated_option`: an option changed.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $value     New value.
	 *
	 * @return void
	 */
	public function on_updated_option( $option, $old_value, $value ): void {
		$this->handle( (string) $option, $old_value, $value, Invalidator::EVENT_OPTION_UPDATED );
	}

	/**
	 * `added_option`: an option was created (update_option() of a missing option ends up here too).
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 *
	 * @return void
	 */
	public function on_added_option( $option, $value ): void {
		$this->handle( (string) $option, null, $value, Invalidator::EVENT_OPTION_ADDED );
	}

	/**
	 * Flush or queue what a mapped option change invalidates.
	 *
	 * `sticky_posts` also queues `post:{id}` for every post whose sticky flag changed.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value (null when the option was created).
	 * @param mixed  $value     New value.
	 * @param string $event     Event name.
	 *
	 * @return void
	 */
	private function handle( string $option, $old_value, $value, string $event ): void {
		try {
			$map = $this->map();
			if ( ! isset( $map[ $option ] ) || $old_value === $value ) {
				return;
			}
			$target = $map[ $option ];
			if ( 'all' === $target ) {
				$this->invalidator->flush_all();
			} elseif ( is_array( $target ) ) {
				$tags = array_values( array_filter( $target, 'is_string' ) );
				if ( 'sticky_posts' === $option ) {
					$tags = array_merge( $tags, $this->sticky_tags( $old_value, $value ) );
				}
				$this->invalidator->queue( $tags, $event, array( 'option' => $option ) );
			}
		} catch ( \Throwable $error ) {
			$this->swallow( $error, $event, array( 'option' => $option ) );
		}
	}

	/**
	 * `post:{id}` for the posts that were made sticky or unsticky.
	 *
	 * @param mixed $old_value Old sticky IDs.
	 * @param mixed $value     New sticky IDs.
	 *
	 * @return string[]
	 *
	 * @phpstan-return list<string>
	 */
	private function sticky_tags( $old_value, $value ): array {
		$old = array_map( 'intval', is_array( $old_value ) ? $old_value : array() );
		$new = array_map( 'intval', is_array( $value ) ? $value : array() );
		$raw = array();
		foreach ( array_unique( array_merge( array_diff( $old, $new ), array_diff( $new, $old ) ) ) as $post_id ) {
			$raw[] = Tags::post( $post_id );
		}
		return $this->tags( ...$raw );
	}

	/**
	 * The option map, filtered once.
	 *
	 * @return array<string,mixed>
	 */
	private function map(): array {
		if ( null === $this->map ) {
			$default = array(
				'permalink_structure' => 'all',
				'home'                => 'all',
				'siteurl'             => 'all',
				'blogname'            => array( 'route:/' ),
				'blogdescription'     => array( 'route:/' ),
				'site_icon'           => array( 'route:/' ),
				'gmt_offset'          => array( 'route:/' ),
				'timezone_string'     => array( 'route:/' ),
				'sticky_posts'        => array( 'list:post' ),
			);
			/**
			 * Filters the option name to tags invalidation map.
			 *
			 * @see Hooks::OPTION_INVALIDATION_MAP
			 */
			$map       = apply_filters( Hooks::OPTION_INVALIDATION_MAP, $default ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
			$this->map = is_array( $map ) ? $map : $default;
		}
		return $this->map;
	}
}
