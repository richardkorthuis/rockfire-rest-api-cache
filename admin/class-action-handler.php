<?php
/**
 * Handler of one admin action.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps every state change of the admin: an `admin-post.php?action=rfrac_{name}` request.
 *
 * Order: capability (else `wp_die` 403) → `target` via {@see Request::key()} → nonce
 * (`check_admin_referer`, else `wp_die`) → callback → {@see Redirect::safe_and_exit()} to the URL it
 * returns. Only `admin_post_` hooks are registered, never `admin_post_nopriv_`.
 *
 * @since 1.0.0
 */
final class Action_Handler {

	/**
	 * Prefix of the action names (the `action` parameter of `admin-post.php`).
	 *
	 * @var string
	 */
	public const PREFIX = 'rfrac_';

	/**
	 * Action name without the prefix.
	 *
	 * @var string
	 */
	private string $action;

	/**
	 * Callback: receives the target, queues notices and returns the redirect URL.
	 *
	 * @var callable
	 * @phpstan-var callable(string): string
	 */
	private $callback;

	/**
	 * Capability.
	 *
	 * @var Capability
	 */
	private Capability $capability;

	/**
	 * Whether the target is part of the nonce.
	 *
	 * @var bool
	 */
	private bool $per_target_nonce;

	/**
	 * Constructor.
	 *
	 * @param string     $action           Action name without the `rfrac_` prefix (a key).
	 * @param callable   $callback         Receives the sanitised target (`''` when none), returns the redirect URL.
	 * @phpstan-param callable(string): string $callback
	 * @param Capability $capability       Capability.
	 * @param bool       $per_target_nonce Whether the nonce includes the target (default). False for actions
	 *                                     whose target is chosen in the form, e.g. a `<select name="target">`.
	 */
	public function __construct( string $action, callable $callback, Capability $capability, bool $per_target_nonce = true ) {
		$this->action           = $action;
		$this->callback         = $callback;
		$this->capability       = $capability;
		$this->per_target_nonce = $per_target_nonce;
	}

	/**
	 * The nonce action for an action and target.
	 *
	 * @param string $action Action name without the prefix.
	 * @param string $target Target; ignored when empty.
	 *
	 * @return string `rfrac_{action}` or `rfrac_{action}_{target}`.
	 */
	public static function nonce_action( string $action, string $target = '' ): string {
		return self::PREFIX . $action . ( '' !== $target ? '_' . $target : '' );
	}

	/**
	 * The action name without the prefix.
	 *
	 * @return string
	 */
	public function action(): string {
		return $this->action;
	}

	/**
	 * The WordPress hook of the action.
	 *
	 * @return string `admin_post_rfrac_{action}`.
	 */
	public function hook(): string {
		return 'admin_post_' . self::PREFIX . $this->action;
	}

	/**
	 * Register the handler on its `admin_post_` hook (never `admin_post_nopriv_`).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->hook(), array( $this, 'handle' ) );
	}

	/**
	 * Handle the request; ends with a redirect (or `wp_die` on a failed check).
	 *
	 * @return void
	 */
	public function handle(): void {
		$this->capability->require();

		$target = Request::key( 'target' );
		check_admin_referer( self::nonce_action( $this->action, $this->per_target_nonce ? $target : '' ) );

		$url = ( $this->callback )( $target );
		Redirect::safe_and_exit( '' !== $url ? $url : admin_url() );
	}
}
