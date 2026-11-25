<?php
/**
 * The constants of the original plugin as a fallback below the `RFRAC_*` constants.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

use Rockfire\Rest_Api_Cache\Config\Config;
use Rockfire\Rest_Api_Cache\Config\Fallback_Constants_Interface;
use Rockfire\Rest_Api_Cache\Config\Settings_Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Honours the `WP_REST_CACHE_*` constants of the original plugin for as long as their `RFRAC_*` replacement
 * is not defined.
 *
 * {@see Config} asks {@see self::values()} for every setting whose own constant is not defined, so a
 * `RFRAC_*` constant always wins and the value of the original is locked in the settings screen under a label
 * that names the constants it comes from. {@see self::report()} says what became of every constant that is
 * defined, for Site Health and the import report. This class only reads: {@see Config} calls it repeatedly.
 *
 * @since 1.0.0
 */
final class Constant_Fallback implements Fallback_Constants_Interface {

	/**
	 * State of a constant that is applied.
	 *
	 * @var 'honoured'
	 */
	public const STATE_HONOURED = 'honoured';

	/**
	 * State of a constant whose `RFRAC_*` replacement is defined and wins.
	 *
	 * @var 'shadowed'
	 */
	public const STATE_SHADOWED = 'shadowed';

	/**
	 * State of a constant whose value is not valid and so is ignored.
	 *
	 * @var 'invalid'
	 */
	public const STATE_INVALID = 'invalid';

	/**
	 * State of a constant that has no counterpart and does nothing.
	 *
	 * @var 'no_effect'
	 */
	public const STATE_NO_EFFECT = 'no_effect';

	/**
	 * The settings key and `RFRAC_*` replacement of the constants that are honoured.
	 *
	 * @var array<string,array{key:string,replacement:string}>
	 */
	private const REPLACEMENTS = [
		Original_Settings::TIMEOUT          => [
			'key'         => 'default_ttl',
			'replacement' => 'RFRAC_DEFAULT_TTL',
		],
		Original_Settings::TIMEOUT_INTERVAL => [
			'key'         => 'default_ttl',
			'replacement' => 'RFRAC_DEFAULT_TTL',
		],
		Original_Settings::GLOBAL_HEADERS   => [
			'key'         => 'global_vary_headers',
			'replacement' => 'RFRAC_GLOBAL_VARY_HEADERS',
		],
	];

	/**
	 * The values of the constants that are defined, by settings key.
	 *
	 * `default_ttl` is the product of the timeout and its interval when at least one of the two constants is
	 * defined, labelled with the names of the constants that are; a product that is not valid passes a value
	 * that {@see Config} rejects, so the label is reported with "Expected an integer.". `global_vary_headers`
	 * is the constant as it is.
	 *
	 * @return array<string,array{value:mixed,label:string}>
	 */
	public function values(): array {
		$defined = Original_Settings::defined_constants();
		if ( [] === $defined ) {
			return [];
		}

		$values = [];
		if ( array_key_exists( Original_Settings::TIMEOUT, $defined ) || array_key_exists( Original_Settings::TIMEOUT_INTERVAL, $defined ) ) {
			$ttl = Original_Settings::ttl();
			if ( null !== $ttl && $ttl['from_constant'] ) {
				$values['default_ttl'] = [
					'value' => $ttl['seconds'] ?? self::invalid_ttl( $defined ),
					'label' => $ttl['label'],
				];
			}
		}
		if ( array_key_exists( Original_Settings::GLOBAL_HEADERS, $defined ) ) {
			$values['global_vary_headers'] = [
				'value' => $defined[ Original_Settings::GLOBAL_HEADERS ],
				'label' => Original_Settings::GLOBAL_HEADERS,
			];
		}
		return $values;
	}

	/**
	 * What became of every constant of the original that is defined.
	 *
	 * `shadowed` is a constant whose `RFRAC_*` replacement is defined, `invalid` one whose value
	 * {@see Config} rejected, `no_effect` one that has no replacement because the feature is not in this plugin.
	 * Any other is `honoured`.
	 *
	 * @return list<array{constant:string,replacement:?string,state:'honoured'|'shadowed'|'invalid'|'no_effect'}>
	 */
	public function report(): array {
		$defined = Original_Settings::defined_constants();
		if ( [] === $defined ) {
			return [];
		}

		$values = $this->values();
		$errors = Config::instance()->constant_errors();

		$report = [];
		foreach ( array_keys( $defined ) as $constant ) {
			$target = self::REPLACEMENTS[ $constant ] ?? null;
			if ( null === $target ) {
				$report[] = [
					'constant'    => $constant,
					'replacement' => null,
					'state'       => self::STATE_NO_EFFECT,
				];
				continue;
			}

			$label = $values[ $target['key'] ]['label'] ?? null;
			if ( defined( $target['replacement'] ) ) {
				$state = self::STATE_SHADOWED;
			} elseif ( null !== $label && isset( $errors[ $label ] ) ) {
				$state = self::STATE_INVALID;
			} else {
				$state = self::STATE_HONOURED;
			}
			$report[] = [
				'constant'    => $constant,
				'replacement' => $target['replacement'],
				'state'       => $state,
			];
		}
		return $report;
	}

	/**
	 * A value for `default_ttl` that {@see Config} rejects as not an integer.
	 *
	 * It is the value of the first TTL constant that is not integer-like, as the constant holds it; when the product
	 * is invalid for another reason (a half that is zero or less, or an option that is not a number), null.
	 *
	 * @param array<string,mixed> $defined The constants of the original that are defined.
	 *
	 * @return mixed
	 */
	private static function invalid_ttl( array $defined ): mixed {
		foreach ( [ Original_Settings::TIMEOUT, Original_Settings::TIMEOUT_INTERVAL ] as $constant ) {
			if ( array_key_exists( $constant, $defined ) && null === Settings_Schema::to_int( $defined[ $constant ] ) ) {
				return $defined[ $constant ];
			}
		}
		return null;
	}
}
