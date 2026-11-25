<?php
/**
 * Flushes the shared price tag when something that prices or renders the catalog changes.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Integrations\WooCommerce;

use Rockfire\Rest_Api_Cache\Hook_Subscriber_Trait;
use Rockfire\Rest_Api_Cache\Hooks;
use Rockfire\Rest_Api_Cache\Install\Sites;
use Rockfire\Rest_Api_Cache\Invalidation\Guarded_Trait;
use Rockfire\Rest_Api_Cache\Invalidation\Invalidator;
use Rockfire\Rest_Api_Cache\Invalidation\Tags;

defined( 'ABSPATH' ) || exit;

/**
 * Queues {@see Store_Routes::PRICE_TAG} on tax-rate changes, on changes of the WooCommerce catalog and price options
 * (updates through `Option_Events`' map seam, deletions through its own `deleted_option` listener) and on plugin and
 * theme changes.
 *
 * Every price-bearing catalog response carries the price tag, so queueing it flushes them all. Handlers
 * never call a WooCommerce function, and a failure is reported and swallowed, never thrown into WordPress or
 * WooCommerce.
 *
 * A network-wide plugin change walks every site of the network, also those that run WooCommerce while the request's
 * own site does not (the network admin loads the plugins of the main site only). On such a request {@see Module}
 * subscribes {@see self::network_hooks()} instead of {@see self::hooks()}: of the plugin changes only the
 * network-wide ones are handled, as a change on a site without WooCommerce is of no concern to its cache.
 *
 * @since 1.0.0
 */
final class Woo_Price_Events {

	use Guarded_Trait;
	use Hook_Subscriber_Trait;

	/**
	 * Event name: a tax rate or tax setting changed.
	 *
	 * Context: none.
	 *
	 * @var string
	 */
	public const EVENT_TAX_CHANGED = 'wc_tax_changed';

	/**
	 * Event name: a plugin was activated or deactivated.
	 *
	 * Context: `plugin` (basename) and `network_wide` (bool).
	 *
	 * @var string
	 */
	public const EVENT_PLUGIN_CHANGED = 'wc_plugin_changed';

	/**
	 * Event name: the theme was switched.
	 *
	 * Context: none.
	 *
	 * @var string
	 */
	public const EVENT_THEME_SWITCHED = 'wc_theme_switched';

	/**
	 * Event name: a mapped option was deleted.
	 *
	 * Context: `option`.
	 *
	 * @var string
	 */
	public const EVENT_OPTION_DELETED = 'wc_option_deleted';

	/**
	 * The options that change a price, a price format or the catalog display: each invalidates the price tag alone.
	 *
	 * @var list<string>
	 */
	private const PRICE_OPTIONS = array(
		'woocommerce_currency',
		'woocommerce_currency_pos',
		'woocommerce_price_thousand_sep',
		'woocommerce_price_decimal_sep',
		'woocommerce_price_num_decimals',
		'woocommerce_price_display_suffix',
		'woocommerce_calc_taxes',
		'woocommerce_prices_include_tax',
		'woocommerce_tax_display_shop',
		'woocommerce_tax_based_on',
		'woocommerce_default_country',
		'woocommerce_store_postcode',
		'woocommerce_store_city',
		'woocommerce_default_customer_address',
		'woocommerce_weight_unit',
		'woocommerce_dimension_unit',
		'woocommerce_stock_format',
		'woocommerce_notify_low_stock_amount',
		'woocommerce_notify_no_stock_amount',
		'woocommerce_manage_stock',
		'woocommerce_hide_out_of_stock_items',
		'default_product_cat',
	);

	/**
	 * Invalidator.
	 *
	 * @var Invalidator
	 */
	private Invalidator $invalidator;

	/**
	 * Constructor.
	 *
	 * @param Invalidator $invalidator Invalidator.
	 */
	public function __construct( Invalidator $invalidator ) {
		$this->invalidator = $invalidator;
	}

	/**
	 * The hooks: tax-rate and tax-setting changes, plugin and theme changes, the option map filter and `deleted_option`.
	 *
	 * @return array<int,array<int,mixed>>
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4?:list<mixed>}>
	 */
	public static function hooks(): array {
		return array(
			array( 'woocommerce_tax_rate_added', 'on_tax_changed', 10, 2 ),
			array( 'woocommerce_tax_rate_updated', 'on_tax_changed', 10, 2 ),
			array( 'woocommerce_tax_rate_deleted', 'on_tax_changed', 10, 1 ),
			array( 'woocommerce_rest_insert_tax', 'on_tax_changed', 10, 3 ),
			array( 'woocommerce_rest_delete_tax', 'on_tax_changed', 10, 3 ),
			array( 'woocommerce_update_options_tax', 'on_tax_changed', 10, 0 ),
			array( 'wp_ajax_woocommerce_tax_rates_save_changes', 'on_tax_rates_save', 1, 0 ),
			array( 'switch_theme', 'on_switch_theme', 10, 0 ),
			array( 'activated_plugin', 'on_plugin_changed', 10, 2 ),
			array( 'deactivated_plugin', 'on_plugin_changed', 10, 2 ),
			array( Hooks::OPTION_INVALIDATION_MAP, 'filter_option_map', 10, 1 ),
			array( 'deleted_option', 'on_deleted_option', 10, 1 ),
		);
	}

	/**
	 * The hooks for a request on which WooCommerce did not load: the network-wide plugin changes only.
	 *
	 * @return array<int,array<int,mixed>>
	 *
	 * @phpstan-return list<array{0:string,1:string,2:int,3:int,4?:list<mixed>}>
	 */
	public static function network_hooks(): array {
		return array(
			array( 'activated_plugin', 'on_network_plugin_changed', 10, 2 ),
			array( 'deactivated_plugin', 'on_network_plugin_changed', 10, 2 ),
		);
	}

	/**
	 * A tax rate was added, updated or deleted (core WooCommerce or its REST API), or the tax settings were saved.
	 *
	 * @return void
	 */
	public function on_tax_changed(): void {
		try {
			$this->queue_price_tag( self::EVENT_TAX_CHANGED );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, self::EVENT_TAX_CHANGED );
		}
	}

	/**
	 * The tax-rate table of the admin screen is being saved over AJAX.
	 *
	 * WooCommerce fires no hook for an edit that only changes a rate's postcodes or cities, so the request itself is the
	 * signal. Runs before WooCommerce checks the nonce, hence the capability check: a visitor cannot flush the cache.
	 *
	 * @return void
	 */
	public function on_tax_rates_save(): void {
		try {
			if ( current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Capability added by WooCommerce.
				$this->queue_price_tag( self::EVENT_TAX_CHANGED );
			}
		} catch ( \Throwable $error ) {
			$this->swallow( $error, self::EVENT_TAX_CHANGED );
		}
	}

	/**
	 * `switch_theme`: the theme changed, and with it possibly the price markup and filters.
	 *
	 * @return void
	 */
	public function on_switch_theme(): void {
		try {
			$this->queue_price_tag( self::EVENT_THEME_SWITCHED );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, self::EVENT_THEME_SWITCHED );
		}
	}

	/**
	 * `activated_plugin` and `deactivated_plugin`: a plugin may filter prices.
	 *
	 * A network-wide change queues the price tag for every site of the network, otherwise for the current site only.
	 *
	 * @param mixed $plugin       Plugin basename.
	 * @param mixed $network_wide Whether the change is network-wide.
	 *
	 * @return void
	 */
	public function on_plugin_changed( $plugin = '', $network_wide = false ): void {
		$context = array(
			'plugin'       => is_string( $plugin ) ? $plugin : '',
			'network_wide' => (bool) $network_wide,
		);
		try {
			if ( $context['network_wide'] ) {
				Sites::each( fn() => $this->queue_price_tag( self::EVENT_PLUGIN_CHANGED, $context ) );
				return;
			}
			$this->queue_price_tag( self::EVENT_PLUGIN_CHANGED, $context );
		} catch ( \Throwable $error ) {
			$this->swallow( $error, self::EVENT_PLUGIN_CHANGED, $context );
		}
	}

	/**
	 * `activated_plugin` and `deactivated_plugin` on a request on which WooCommerce did not load.
	 *
	 * Only a network-wide change is handled: it walks every site of the network, and WooCommerce may run on some of
	 * them. A change on this site alone says nothing about a site without WooCommerce, so it queues nothing.
	 *
	 * @param mixed $plugin       Plugin basename.
	 * @param mixed $network_wide Whether the change is network-wide.
	 *
	 * @return void
	 */
	public function on_network_plugin_changed( $plugin = '', $network_wide = false ): void {
		if ( $network_wide ) {
			$this->on_plugin_changed( $plugin, true );
		}
	}

	/**
	 * `rfrac/option_invalidation_map`: add the catalog and price options, keeping what other filters already set.
	 *
	 * An entry that is `all` stays `all`; a tag list is extended with this class's tags.
	 *
	 * @param mixed $map Option name => `'all'` or tags.
	 *
	 * @return mixed The map; unchanged when it is not an array or this filter fails.
	 */
	public function filter_option_map( $map ) {
		try {
			if ( ! is_array( $map ) ) {
				return $map;
			}
			$merged = $map;
			foreach ( $this->option_tags() as $option => $tags ) {
				$existing = $merged[ $option ] ?? null;
				if ( 'all' === $existing ) {
					continue;
				}
				$merged[ $option ] = is_array( $existing )
					? array_values( array_unique( array_merge( array_values( array_filter( $existing, 'is_string' ) ), $tags ) ) )
					: $tags;
			}
			return $merged;
		} catch ( \Throwable $error ) {
			$this->swallow( $error, Invalidator::EVENT_OPTION_UPDATED );
			return $map;
		}
	}

	/**
	 * `deleted_option`: deleting one of the mapped options invalidates what updating it does.
	 *
	 * `Option_Events` listens to additions and updates only. The map is read through the same filter, so an entry
	 * another filter overrode to `all` flushes everything, and an entry another filter removed flushes nothing.
	 *
	 * @param mixed $option Option name.
	 *
	 * @return void
	 */
	public function on_deleted_option( $option = '' ): void {
		$name = is_string( $option ) ? $option : '';
		try {
			if ( ! isset( $this->option_tags()[ $name ] ) ) {
				return;
			}
			/**
			 * Filters the option name to tags invalidation map.
			 *
			 * @see Hooks::OPTION_INVALIDATION_MAP
			 */
			$map = apply_filters( Hooks::OPTION_INVALIDATION_MAP, array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hooks constant, prefixed rfrac/.
			if ( ! is_array( $map ) || ! isset( $map[ $name ] ) ) {
				return;
			}
			$target = $map[ $name ];
			if ( 'all' === $target ) {
				$this->invalidator->flush_all();
			} elseif ( is_array( $target ) ) {
				$this->invalidator->queue( array_values( array_filter( $target, 'is_string' ) ), self::EVENT_OPTION_DELETED, array( 'option' => $name ) );
			}
		} catch ( \Throwable $error ) {
			$this->swallow( $error, self::EVENT_OPTION_DELETED, array( 'option' => $name ) );
		}
	}

	/**
	 * Queue the price tag for the current site.
	 *
	 * @param string              $event   Event name.
	 * @param array<string,mixed> $context Subject of the change.
	 *
	 * @return void
	 */
	private function queue_price_tag( string $event, array $context = array() ): void {
		$this->invalidator->queue( array( Store_Routes::PRICE_TAG ), $event, $context, (int) get_current_blog_id() );
	}

	/**
	 * Option name => the tags a change of the option invalidates.
	 *
	 * @return array<string,list<string>>
	 */
	private function option_tags(): array {
		$map = array();
		foreach ( self::PRICE_OPTIONS as $option ) {
			$map[ $option ] = array( Store_Routes::PRICE_TAG );
		}
		// The permalink bases change the links in category, brand and review responses as well as in product ones.
		$permalinks = array( Store_Routes::PRICE_TAG );
		foreach ( array( 'product_cat', 'product_brand' ) as $taxonomy ) {
			$tag = Tags::list_for( $taxonomy );
			if ( null !== $tag ) {
				$permalinks[] = $tag;
			}
		}
		$permalinks[]                  = Woo_Tags::LIST_REVIEWS;
		$map['woocommerce_permalinks'] = $permalinks;
		// Avatars appear in review responses only.
		$map['show_avatars'] = array( Woo_Tags::LIST_REVIEWS );
		return $map;
	}
}
