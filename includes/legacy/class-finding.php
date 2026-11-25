<?php
/**
 * One finding of the legacy import and compatibility layer.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Legacy;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object: what the legacy layer did with, or could not do with, one thing of the
 * original plugin (a setting, a constant, a hook, a class, an endpoint).
 *
 * The `status` is one of {@see self::STATUSES}. The `code` is a stable machine-readable reason
 * (`[a-z0-9_]`, at most 64 characters). The `subject` is data: the identifier of the original the
 * finding is about, never a message id. The `message` is a short human-readable sentence, truncated
 * to {@see self::MAX_MESSAGE} characters.
 *
 * @since 1.0.0
 *
 * @phpstan-type FindingData array{status:string, code:string, subject:string, message:string}
 */
final class Finding {

	/**
	 * Status: the thing was imported as it was.
	 *
	 * @var string
	 */
	public const IMPORTED = 'imported';

	/**
	 * Status: the thing was left as it is on purpose.
	 *
	 * @var string
	 */
	public const KEPT = 'kept';

	/**
	 * Status: the thing was imported with a different value.
	 *
	 * @var string
	 */
	public const ADJUSTED = 'adjusted';

	/**
	 * Status: the thing was not imported.
	 *
	 * @var string
	 */
	public const SKIPPED = 'skipped';

	/**
	 * Status: the thing has no equivalent in this plugin.
	 *
	 * @var string
	 */
	public const UNSUPPORTED = 'unsupported';

	/**
	 * Every valid status.
	 *
	 * @var list<string>
	 */
	public const STATUSES = array( self::IMPORTED, self::KEPT, self::ADJUSTED, self::SKIPPED, self::UNSUPPORTED );

	/**
	 * Maximum stored message length in characters.
	 *
	 * @var int
	 */
	public const MAX_MESSAGE = 500;

	/**
	 * One of {@see self::STATUSES}.
	 *
	 * @var string
	 */
	public readonly string $status;

	/**
	 * Stable machine-readable reason.
	 *
	 * @var string
	 */
	public readonly string $code;

	/**
	 * The identifier the finding is about (data, never a message id).
	 *
	 * @var string
	 */
	public readonly string $subject;

	/**
	 * Short human-readable sentence, at most {@see self::MAX_MESSAGE} characters.
	 *
	 * @var string
	 */
	public readonly string $message;

	/**
	 * Create a finding.
	 *
	 * @param string $status  One of {@see self::STATUSES}.
	 * @param string $code    Reason code matching `/^[a-z0-9_]{1,64}$/`.
	 * @param string $subject The identifier the finding is about.
	 * @param string $message Human-readable sentence; truncated to {@see self::MAX_MESSAGE} characters.
	 *
	 * @throws \InvalidArgumentException For an unknown status or a malformed code.
	 */
	public function __construct( string $status, string $code, string $subject, string $message ) {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown finding status.' );
		}
		if ( 1 !== preg_match( '/^[a-z0-9_]{1,64}$/', $code ) ) {
			throw new \InvalidArgumentException( 'Malformed finding code.' );
		}

		$this->status  = $status;
		$this->code    = $code;
		$this->subject = $subject;
		$this->message = mb_substr( $message, 0, self::MAX_MESSAGE, 'UTF-8' );
	}

	/**
	 * Whether the finding needs the attention of the administrator (adjusted, skipped or unsupported).
	 *
	 * @return bool
	 */
	public function is_problem(): bool {
		return in_array( $this->status, array( self::ADJUSTED, self::SKIPPED, self::UNSUPPORTED ), true );
	}

	/**
	 * The finding as a plain array, the stored form.
	 *
	 * @return array{status:string, code:string, subject:string, message:string}
	 */
	public function to_array(): array {
		return array(
			'status'  => $this->status,
			'code'    => $this->code,
			'subject' => $this->subject,
			'message' => $this->message,
		);
	}

	/**
	 * Build a finding from its stored form.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return self|null Null when the value is not a well-formed finding.
	 */
	public static function from_array( mixed $value ): ?self {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$status  = $value['status'] ?? null;
		$code    = $value['code'] ?? null;
		$subject = $value['subject'] ?? null;
		$message = $value['message'] ?? null;
		if ( ! is_string( $status ) || ! is_string( $code ) || ! is_string( $subject ) || ! is_string( $message ) ) {
			return null;
		}

		try {
			return new self( $status, $code, $subject, $message );
		} catch ( \InvalidArgumentException $e ) {
			unset( $e );
			return null;
		}
	}
}
