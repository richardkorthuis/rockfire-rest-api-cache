<?php
/**
 * WP-CLI command `wp rfrac config`.
 *
 * @since 1.0.0
 *
 * @package    Rockfire_Rest_Api_Cache
 * @subpackage Rockfire_Rest_Api_Cache/Cli
 */

namespace Rockfire\Rest_Api_Cache\Cli;

use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Services\Settings_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Read the plugin settings.
 *
 * @since 1.0.0
 */
class Config_Command extends \WP_CLI_Command {

	use Flag_Trait;

	/**
	 * Settings service.
	 *
	 * @var Settings_Service
	 */
	private Settings_Service $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings_Service $settings Settings service.
	 */
	public function __construct( Settings_Service $settings ) {
		parent::__construct();
		$this->settings = $settings;
	}

	/**
	 * Get the effective settings, or one setting.
	 *
	 * ## OPTIONS
	 *
	 * [<key>]
	 * : Setting key. Without it every setting is listed with its source: constant:RFRAC_X (locked by a constant), stored, clamped (the stored number was out of range and is limited), or default (nothing stored, or the stored value was invalid). A list setting is shown as a, b in a table and as an array in json and yaml. A key prints only its effective value.
	 *
	 * [--compiled]
	 * : Print the compiled configuration the serving layer reads instead of the settings.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp rfrac config get
	 *     wp rfrac config get default_ttl
	 *     wp rfrac config get --compiled --format=json
	 *
	 * @subcommand get
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function get( $args, $assoc_args ) {
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		if ( ! in_array( $format, array( 'table', 'json', 'yaml' ), true ) ) {
			\WP_CLI::error( __( 'Invalid format. Use table, json or yaml.', 'rockfire-rest-api-cache' ) );
		}
		$key = isset( $args[0] ) ? (string) $args[0] : '';

		if ( $this->flag( $assoc_args, 'compiled' ) ) {
			$data = $this->settings->compiled();
			if ( '' !== $key ) {
				if ( ! array_key_exists( $key, $data ) ) {
					/* translators: %s: setting key */
					\WP_CLI::error( sprintf( __( 'Unknown setting: %s', 'rockfire-rest-api-cache' ), $key ) );
				}
				$this->print_value( $data[ $key ], $format );
				return;
			}
			$this->print_map( $data, $format );
			return;
		}

		if ( '' !== $key ) {
			$effective = $this->settings->all_effective();
			if ( ! array_key_exists( $key, $effective ) ) {
				/* translators: %s: setting key */
				\WP_CLI::error( sprintf( __( 'Unknown setting: %s', 'rockfire-rest-api-cache' ), $key ) );
			}
			$this->print_value( $effective[ $key ], $format );
			return;
		}

		$this->print_rows( $this->setting_rows( $format ), array( 'key', 'value', 'source' ), $format );
	}

	/**
	 * One row per editable setting: key, effective value and source.
	 *
	 * A list setting reads `a, b` in a table (an empty cell when empty); json and yaml keep the array.
	 *
	 * @param string $format Output format.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function setting_rows( string $format ): array {
		$sources = $this->settings->sources();
		$rows    = array();
		foreach ( $this->settings->view() as $setting => $item ) {
			$value = $item['value'];
			if ( 'table' === $format && is_array( $value ) ) {
				$value = implode( ', ', array_filter( $value, 'is_string' ) );
			}
			$rows[] = array(
				'key'    => $setting,
				'value'  => $value,
				'source' => $sources[ $setting ] ?? 'default',
			);
		}
		return $rows;
	}

	/**
	 * Print one value: scalars as they are, arrays as JSON or YAML.
	 *
	 * @param mixed  $value  Value.
	 * @param string $format Output format.
	 *
	 * @return void
	 */
	private function print_value( mixed $value, string $format ): void {
		if ( is_array( $value ) ) {
			\WP_CLI::print_value( $value, array( 'format' => 'table' === $format ? 'json' : $format ) );
			return;
		}
		if ( 'json' === $format || 'yaml' === $format ) {
			\WP_CLI::print_value( $value, array( 'format' => $format ) );
			return;
		}
		\WP_CLI::line( $this->scalar( $value ) );
	}

	/**
	 * Print a key => value map (the compiled configuration).
	 *
	 * @param array<string,mixed> $data   Map.
	 * @param string              $format Output format.
	 *
	 * @return void
	 */
	private function print_map( array $data, string $format ): void {
		if ( 'json' === $format || 'yaml' === $format ) {
			\WP_CLI::print_value( $data, array( 'format' => $format ) );
			return;
		}
		$rows = array();
		foreach ( $data as $key => $value ) {
			$rows[] = array(
				'key'   => (string) $key,
				'value' => $value,
			);
		}
		$this->print_rows( $rows, array( 'key', 'value' ), $format );
	}

	/**
	 * Print rows through the WP-CLI formatter.
	 *
	 * @param array<int,array<string,mixed>> $rows   Rows.
	 * @param string[]                       $fields Columns.
	 * @param string                         $format Output format.
	 *
	 * @return void
	 */
	private function print_rows( array $rows, array $fields, string $format ): void {
		$rows = array_map(
			fn( array $row ): array => array_map( fn( $cell ) => 'table' === $format ? $this->scalar( $cell ) : $cell, $row ),
			$rows
		);
		$args = array( 'format' => $format );
		( new \WP_CLI\Formatter( $args, $fields ) )->display_items( $rows );
	}

	/**
	 * Text form of a value for a table cell.
	 *
	 * @param mixed $value Value.
	 *
	 * @return string
	 */
	private function scalar( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( null === $value ) {
			return '';
		}
		$json = wp_json_encode( $value );
		return false === $json ? '' : $json;
	}
}
