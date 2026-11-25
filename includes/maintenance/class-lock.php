<?php
/**
 * Single-runner lock for maintenance.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Maintenance;

defined( 'ABSPATH' ) || exit;

/**
 * An option-based lock: the option value is the expiry timestamp. Not a transient, so an
 * external object cache cannot make the lock vanish.
 *
 * The whole lock is best effort, not a mutex: `add_option()` is an INSERT ... ON DUPLICATE KEY
 * UPDATE, so two processes can both think they hold it. Duplicate runs only repeat idempotent
 * deletes.
 *
 * @since 1.0.0
 */
class Lock {

	/**
	 * Option holding the lock expiry.
	 *
	 * @var string
	 */
	public const OPTION = 'rfrac_maintenance_lock';

	/**
	 * Whether this instance holds the lock.
	 *
	 * @var bool
	 */
	private bool $held = false;

	/**
	 * Expiry this instance stored when it took the lock.
	 *
	 * @var int
	 */
	private int $expires = 0;

	/**
	 * Try to take the lock, taking over an expired one once.
	 *
	 * @param int $ttl Seconds the lock stays valid.
	 *
	 * @return bool Whether the lock was acquired.
	 */
	public function acquire( int $ttl ): bool {
		$expires = time() + max( 1, $ttl );
		if ( add_option( self::OPTION, $expires, '', false ) ) {
			$this->held    = true;
			$this->expires = $expires;
			return true;
		}

		// Read the stored value past the options cache.
		wp_cache_delete( self::OPTION, 'options' );
		$current = (int) get_option( self::OPTION, 0 );
		if ( $current >= time() ) {
			return false;
		}

		// Expired: remove it and race for it once. This is best effort, not a mutex: two
		// processes that both saw the expired lock can both end up holding it. That only
		// costs duplicate work, because every maintenance step is an idempotent delete.
		delete_option( self::OPTION );
		$won           = (bool) add_option( self::OPTION, $expires, '', false );
		$this->held    = $won;
		$this->expires = $expires;
		return $won;
	}

	/**
	 * Whether a valid (unexpired) lock is stored on the current site, by anyone.
	 *
	 * @return bool
	 */
	public function is_locked(): bool {
		wp_cache_delete( self::OPTION, 'options' );
		return (int) get_option( self::OPTION, 0 ) >= time();
	}

	/**
	 * Release the lock if this instance holds it and the stored value is still its own expiry.
	 *
	 * @return void
	 */
	public function release(): void {
		if ( $this->held ) {
			// Read past the options cache; another holder may have replaced an expired lock.
			wp_cache_delete( self::OPTION, 'options' );
			if ( (int) get_option( self::OPTION, 0 ) === $this->expires ) {
				delete_option( self::OPTION );
			}
			$this->held = false;
		}
	}
}
