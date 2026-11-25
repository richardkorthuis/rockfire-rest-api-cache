<?php
/**
 * Validation result collector.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the errors produced while validating settings or route rules.
 *
 * @since 1.0.0
 */
final class Validation_Result {

	/**
	 * Collected errors.
	 *
	 * @var list<array{code:string,message:string,field:string}>
	 */
	private array $errors = [];

	/**
	 * Add an error.
	 *
	 * @param string $code    Machine readable error code.
	 * @param string $message Human readable message.
	 * @param string $field   Optional settings key or rule field the error relates to.
	 *
	 * @return self
	 */
	public function add_error( string $code, string $message, string $field = '' ): self {
		$this->errors[] = [
			'code'    => $code,
			'message' => $message,
			'field'   => $field,
		];
		return $this;
	}

	/**
	 * Whether no errors were collected.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return [] === $this->errors;
	}

	/**
	 * All collected errors.
	 *
	 * @return list<array{code:string,message:string,field:string}>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * The distinct error codes, in first-seen order.
	 *
	 * @return list<string>
	 */
	public function codes(): array {
		return array_values( array_unique( array_column( $this->errors, 'code' ) ) );
	}

	/**
	 * Whether an error with the given code was collected.
	 *
	 * @param string $code Error code.
	 *
	 * @return bool
	 */
	public function has_code( string $code ): bool {
		return in_array( $code, $this->codes(), true );
	}

	/**
	 * Append all errors of another result.
	 *
	 * @param Validation_Result $other The result to merge in.
	 *
	 * @return self
	 */
	public function merge( Validation_Result $other ): self {
		foreach ( $other->errors() as $error ) {
			$this->errors[] = $error;
		}
		return $this;
	}
}
