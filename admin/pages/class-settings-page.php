<?php
/**
 * The Settings admin page (Settings API).
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Admin\Pages;

use Rockfire\Rest_Api_Cache\Admin\Capability;
use Rockfire\Rest_Api_Cache\Names;
use Rockfire\Rest_Api_Cache\Services\Settings_Service;
use Rockfire\Rest_Api_Cache\Services\Status_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Defaults, request rules, limits and performance settings through the Settings API. The setting is registered on
 * `admin_init` (never during AJAX) by {@see \Rockfire\Rest_Api_Cache\Admin\Admin::register()}; saving goes
 * through `options.php` and {@see Settings_Service::sanitize()}. Settings locked by a constant are shown
 * disabled with the constant's name and the effective value.
 *
 * @since 1.0.0
 */
class Settings_Page implements Page_Interface {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'rfrac-settings';

	/**
	 * Settings API option group.
	 *
	 * @var string
	 */
	public const GROUP = 'rfrac_settings';

	/**
	 * Settings service.
	 *
	 * @var Settings_Service
	 */
	private Settings_Service $settings;

	/**
	 * Status service.
	 *
	 * @var Status_Service
	 */
	private Status_Service $status;

	/**
	 * Capability.
	 *
	 * @var Capability
	 */
	private Capability $capability;

	/**
	 * Whether a write of the settings option started and did not (yet) succeed.
	 *
	 * @var bool
	 */
	private bool $write_pending = false;

	/**
	 * The value of the pending write.
	 *
	 * @var mixed
	 */
	private $write_value = null;

	/**
	 * Constructor.
	 *
	 * @param Settings_Service $settings   Settings service.
	 * @param Status_Service   $status     Status service.
	 * @param Capability       $capability Capability.
	 */
	public function __construct( Settings_Service $settings, Status_Service $status, Capability $capability ) {
		$this->settings   = $settings;
		$this->status     = $status;
		$this->capability = $capability;
	}

	/**
	 * Page slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function page_title(): string {
		return __( 'Settings', 'rockfire-rest-api-cache' );
	}

	/**
	 * Menu title.
	 *
	 * @return string
	 */
	public function menu_title(): string {
		return __( 'Settings', 'rockfire-rest-api-cache' );
	}

	/**
	 * Menu position.
	 *
	 * @return int
	 */
	public function position(): int {
		return 30;
	}

	/**
	 * Nothing to do before output.
	 *
	 * @return void
	 */
	public function load(): void {
	}

	/**
	 * No admin-post actions; saving goes through `options.php`.
	 *
	 * @return array<string,callable>
	 */
	public function actions(): array {
		return array();
	}

	/**
	 * Register the setting, its sections and fields, and the capability for saving it (`admin_init`).
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Names::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
		add_filter( 'option_page_capability_' . self::GROUP, array( $this->capability, 'get' ) );

		// options.php reports "Settings saved." even when the database write failed; watch the write.
		add_action( 'update_option', array( $this, 'watch_write' ), 10, 3 );
		add_action( 'add_option', array( $this, 'watch_write' ), 10, 2 );
		add_action( 'updated_option', array( $this, 'write_done' ) );
		add_action( 'added_option', array( $this, 'write_done' ) );
		add_filter( 'pre_set_transient_settings_errors', array( $this, 'report_failed_write' ) );

		$sections = array(
			'defaults'    => __( 'Defaults', 'rockfire-rest-api-cache' ),
			'request'     => __( 'Request rules', 'rockfire-rest-api-cache' ),
			'limits'      => __( 'Limits', 'rockfire-rest-api-cache' ),
			'performance' => __( 'Performance', 'rockfire-rest-api-cache' ),
		);
		foreach ( $sections as $id => $title ) {
			add_settings_section( 'rfrac_' . $id, $title, '__return_empty_string', self::SLUG );
		}

		foreach ( $this->settings->schema() as $key => $field ) {
			add_settings_field(
				'rfrac_' . $key,
				(string) $field['label'],
				array( $this, 'render_field' ),
				self::SLUG,
				'rfrac_' . (string) $field['section'],
				array(
					'key'       => $key,
					'label_for' => 'rfrac-' . $key,
				)
			);
		}
	}

	/**
	 * A write of the settings option is about to happen (`update_option` / `add_option`).
	 *
	 * Core fires these only when the value changed, right before the database query.
	 *
	 * @param mixed $option Option name.
	 * @param mixed ...$args `update_option`: old value, new value; `add_option`: the value.
	 *
	 * @return void
	 */
	public function watch_write( $option, ...$args ): void {
		if ( Names::OPTION_SETTINGS === $option ) {
			$this->write_pending = true;
			$this->write_value   = [] === $args ? null : $args[ count( $args ) - 1 ];
		}
	}

	/**
	 * The write of the settings option succeeded (`updated_option` / `added_option`).
	 *
	 * @param mixed $option Option name.
	 *
	 * @return void
	 */
	public function write_done( $option ): void {
		if ( Names::OPTION_SETTINGS === $option ) {
			$this->write_pending = false;
		}
	}

	/**
	 * Replace the "Settings saved." message of options.php by an error when the write failed.
	 *
	 * @param mixed $errors The settings errors options.php is about to store for the redirect.
	 *
	 * @return mixed
	 */
	public function report_failed_write( $errors ) {
		if ( ! $this->write_pending || ! is_array( $errors ) ) {
			return $errors;
		}
		$this->write_pending = false;

		// Confirm against the database, not a possibly stale object cache.
		wp_cache_delete( Names::OPTION_SETTINGS, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$stored = get_option( Names::OPTION_SETTINGS );
		if ( maybe_serialize( $stored ) === maybe_serialize( $this->write_value ) ) {
			return $errors;
		}

		$errors   = array_values( array_filter( $errors, static fn( $e ): bool => ! is_array( $e ) || 'settings_updated' !== ( $e['code'] ?? '' ) ) );
		$errors[] = array(
			'setting' => Settings_Service::ERROR_SLUG,
			'code'    => Names::PREFIX . 'save_failed',
			'message' => __( 'The settings could not be saved to the database.', 'rockfire-rest-api-cache' ),
			'type'    => 'error',
		);
		return $errors;
	}

	/**
	 * Print one field.
	 *
	 * @param array<string,mixed> $args Field arguments (`key`).
	 *
	 * @return void
	 */
	public function render_field( array $args ): void {
		$key    = isset( $args['key'] ) && is_string( $args['key'] ) ? $args['key'] : '';
		$schema = $this->settings->schema();
		$view   = $this->settings->view();
		if ( ! isset( $schema[ $key ], $view[ $key ] ) ) {
			return;
		}
		$field    = $schema[ $key ];
		$value    = $view[ $key ]['value'];
		$locked   = $view[ $key ]['locked'];
		$constant = $view[ $key ]['constant'];
		$id       = 'rfrac-' . $key;
		$name     = Names::OPTION_SETTINGS . '[' . $key . ']';
		// Whether a filter or a carried-over callback overrides a bool setting: true = on although the box is not, false = off although it is.
		$overridden = null;

		if ( 'bool' === $field['type'] ) {
			// The compiler applies the filters on top of every source, so a compiled flag that differs from the setting is theirs.
			if ( array() !== $this->settings->compiled() ) {
				$setting = true === $value;
				$flag    = $this->settings->compiled_flag( $key );
				if ( $setting !== $flag ) {
					$overridden = $flag;
				}
			}
			// The box shows the setting, never the filter: ticking it is the only way to store an opt-in.
			$stored = 'default' !== ( $this->settings->sources()[ $key ] ?? 'default' );
			if ( ! $locked && ( $stored || true === $value ) ) {
				// An unticked box posts nothing, so the hidden 0 first makes unticking an explicit choice. An opt-in nobody stored
				// has no such choice to make: without the hidden 0 a save that leaves the box alone posts nothing and stores nothing.
				printf( '<input type="hidden" name="%s" value="0">', esc_attr( $name ) );
			}
			printf(
				'<input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s%4$s%5$s>',
				esc_attr( $id ),
				esc_attr( $name ),
				checked( true === $value, true, false ),
				$locked ? ' disabled="disabled"' : '',
				! empty( $field['description'] ) ? ' aria-describedby="' . esc_attr( $id . '-description' ) . '"' : ''
			);
		} elseif ( 'enum' === $field['type'] ) {
			printf(
				'<select id="%1$s" name="%2$s"%3$s%4$s>',
				esc_attr( $id ),
				esc_attr( $name ),
				$locked ? ' disabled="disabled"' : '',
				! empty( $field['description'] ) ? ' aria-describedby="' . esc_attr( $id . '-description' ) . '"' : ''
			);
			foreach ( (array) $field['options'] as $option => $label ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $option ), selected( (string) $value, (string) $option, false ), esc_html( (string) $label ) );
			}
			echo '</select>';
		} elseif ( 'list' === $field['type'] ) {
			$lines = is_array( $value ) ? implode( "\n", array_filter( $value, 'is_string' ) ) : '';
			printf(
				'<textarea id="%1$s" name="%2$s" class="large-text code" rows="4"%3$s%4$s>%5$s</textarea>',
				esc_attr( $id ),
				esc_attr( $name ),
				$locked ? ' disabled="disabled"' : '',
				! empty( $field['description'] ) ? ' aria-describedby="' . esc_attr( $id . '-description' ) . '"' : '',
				esc_textarea( $lines )
			);
		} else {
			printf(
				'<input type="number" id="%1$s" name="%2$s" value="%3$s" min="%4$s" max="%5$s" step="1" class="small-text"%6$s%7$s />',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( is_scalar( $value ) ? (string) $value : '' ),
				esc_attr( (string) ( $field['min'] ?? 0 ) ),
				esc_attr( (string) ( $field['max'] ?? 0 ) ),
				$locked ? ' disabled="disabled"' : '',
				! empty( $field['description'] ) ? ' aria-describedby="' . esc_attr( $id . '-description' ) . '"' : ''
			);
			if ( isset( $field['unit'] ) && '' !== $field['unit'] ) {
				echo ' ' . esc_html( (string) $field['unit'] );
			}
		}

		if ( $locked && null !== $constant ) {
			printf(
				'<p class="description">%s</p>',
				sprintf(
					/* translators: %s: constant name. */
					esc_html__( 'Locked by constant %s in wp-config.php', 'rockfire-rest-api-cache' ),
					'<code>' . esc_html( $constant ) . '</code>'
				)
			);
		}
		if ( true === $overridden ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Turned on by a filter or a carried-over WP REST Cache callback, so requests with a nonce are cached whatever this box says.', 'rockfire-rest-api-cache' )
			);
		} elseif ( false === $overridden ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Turned off by a filter, so requests with a nonce are not cached whatever this box says.', 'rockfire-rest-api-cache' )
			);
		}
		if ( ! empty( $field['description'] ) ) {
			printf( '<p class="description" id="%1$s">%2$s</p>', esc_attr( $id . '-description' ), esc_html( (string) $field['description'] ) );
		}
	}

	/**
	 * Print the page body.
	 *
	 * @return void
	 */
	public function render(): void {
		$status = $this->status->get();
		include RFRAC_DIR . 'admin/views/settings.php';
	}
}
