<?php
/**
 * A route rule together with the layer that produced it.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Config;

defined( 'ABSPATH' ) || exit;

/**
 * One entry of {@see Config::route_rules()}.
 *
 * @since 1.0.0
 */
final class Effective_Rule {

	public const SOURCE_DEFAULT  = 'default';
	public const SOURCE_SETTINGS = 'settings';
	public const SOURCE_CONSTANT = 'constant';
	public const SOURCE_FILTER   = 'filter';

	/**
	 * Constructor.
	 *
	 * @param Route_Rule $rule   The effective rule.
	 * @param string     $source `default`, `settings`, `constant` or `filter`.
	 */
	public function __construct(
		public readonly Route_Rule $rule,
		public readonly string $source
	) {
	}
}
