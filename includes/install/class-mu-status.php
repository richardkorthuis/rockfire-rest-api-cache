<?php
/**
 * State of the must-use early-cache file.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the must-use file is installed and up to date.
 *
 * @since 1.0.0
 */
enum Mu_Status: string {

	/**
	 * Installing the file is disabled by `RFRAC_DISABLE_MU_INSTALL`.
	 */
	case DISABLED = 'disabled';

	/**
	 * The file does not exist.
	 */
	case MISSING = 'missing';

	/**
	 * The file exists and equals the desired content.
	 */
	case CURRENT = 'current';

	/**
	 * The file exists but differs from the desired content.
	 */
	case OUTDATED = 'outdated';
}
