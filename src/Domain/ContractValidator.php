<?php
/**
 * Shared validation for Health Engine contract values.
 *
 * @package HealthLens
 */

namespace HealthLens\Domain;

use InvalidArgumentException;

/**
 * Validates the primitive values used by the domain contracts.
 */
final class ContractValidator {
	/**
	 * Validate a stable lower-case identifier.
	 *
	 * @param mixed  $value The value to validate.
	 * @param string $field The field name for the exception message.
	 * @param int    $max_length Maximum allowed byte length.
	 * @throws InvalidArgumentException If the value is not a valid identifier.
	 * @return string
	 */
	public static function slug( $value, $field, $max_length = 64 ) {
		if ( ! is_string( $value ) || '' === $value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The field name is an internal constant.
			throw new InvalidArgumentException( $field . ' must be a non-empty string.' );
		}

		if ( strlen( $value ) > $max_length || ! preg_match( '/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The field name is an internal constant.
			throw new InvalidArgumentException( $field . ' must be a lower-case identifier.' );
		}

		return $value;
	}

	/**
	 * Validate a positive integer.
	 *
	 * @param mixed  $value The value to validate.
	 * @param string $field The field name for the exception message.
	 * @throws InvalidArgumentException If the value is not positive.
	 * @return int
	 */
	public static function positive_integer( $value, $field ) {
		if ( ! is_int( $value ) || $value < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The field name is an internal constant.
			throw new InvalidArgumentException( $field . ' must be a positive integer.' );
		}

		return $value;
	}

	/**
	 * Validate a non-negative integer.
	 *
	 * @param mixed  $value The value to validate.
	 * @param string $field The field name for the exception message.
	 * @throws InvalidArgumentException If the value is negative or not an integer.
	 * @return int
	 */
	public static function non_negative_integer( $value, $field ) {
		if ( ! is_int( $value ) || $value < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The field name is an internal constant.
			throw new InvalidArgumentException( $field . ' must be a non-negative integer.' );
		}

		return $value;
	}
}
