<?php
/**
 * The result of an endpoint translation.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable pair of the route rules a translation produced and the notes that explain it.
 *
 * @since 1.0.0
 *
 * @phpstan-type Rule_Array array{status:string,match:string,ttl:?int,param_mode:string,ignore_params:list<string>,bypass_params:list<string>,vary_headers:list<string>,flush_post_types:list<string>,flush_taxonomies:list<string>}
 */
final class Translation {

	/**
	 * Constructor.
	 *
	 * @param array<string,array<string,mixed>> $rules Route key => validated rule, as {@see \Rockfire\Rest_Api_Cache\Config\Route_Rule::to_array()} returns it.
	 * @phpstan-param array<string,Rule_Array>  $rules
	 * @param Translation_Note[]                $notes One note per decision, in input order.
	 * @phpstan-param list<Translation_Note>    $notes
	 */
	public function __construct(
		public readonly array $rules,
		public readonly array $notes
	) {
	}
}
