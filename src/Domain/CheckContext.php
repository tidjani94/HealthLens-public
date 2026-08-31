<?php
/**
 * Bounded technical context value object.
 *
 * @package HealthLens
 */

namespace HealthLens\Domain;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Carries only deterministic, scalar technical context between layers.
 */
final class CheckContext {
	/**
	 * Maximum serialized JSON size allowed for context in bytes.
	 *
	 * @var int
	 */
	const MAX_SERIALIZED_BYTES = 16384;

	/**
	 * Sanitized context values.
	 *
	 * @var array<string, bool|float|int|string>
	 */
	private $values;

	/**
	 * Deterministic JSON representation.
	 *
	 * @var string
	 */
	private $serialized;

	/**
	 * Create a bounded technical context.
	 *
	 * @param array $values Scalar context values keyed by lower-case identifiers.
	 * @throws InvalidArgumentException If a key, value, or serialized size is invalid.
	 * @throws UnexpectedValueException If the context cannot be serialized.
	 */
	public function __construct( array $values = array() ) {
		$sanitized = array();

		foreach ( $values as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key || strlen( $key ) > 64 || ! preg_match( '/\A[a-z][a-z0-9_-]*\z/', $key ) ) {
				throw new InvalidArgumentException( 'Context keys must be lower-case identifiers.' );
			}

			if ( self::is_sensitive_key( $key ) ) {
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				throw new InvalidArgumentException( 'Context values must be scalar.' );
			}

			if ( self::is_sensitive_value( $value ) ) {
				continue;
			}

			$sanitized[ $key ] = $value;
		}

		ksort( $sanitized, SORT_STRING );
		// phpcs:ignore WordPress.PHP.DiscouragedFunctions -- The domain layer must remain WordPress-independent.
		$serialized = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The domain layer must remain WordPress-independent.
			$sanitized,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
		);

		if ( false === $serialized ) {
			throw new UnexpectedValueException( 'Context could not be serialized as JSON.' );
		}

		if ( strlen( $serialized ) > self::MAX_SERIALIZED_BYTES ) {
			throw new InvalidArgumentException( 'Serialized context exceeds 16 KiB.' );
		}

		$this->values     = $sanitized;
		$this->serialized = $serialized;
	}

	/**
	 * Return sanitized context values.
	 *
	 * @return array<string, bool|float|int|string>
	 */
	public function to_array() {
		return $this->values;
	}

	/**
	 * Return the deterministic JSON representation.
	 *
	 * @return string Deterministic JSON representation.
	 */
	public function to_json() {
		return $this->serialized;
	}

	/**
	 * Determine whether a context key is sensitive.
	 *
	 * Identify keys that must never be retained in technical context.
	 *
	 * @param string $key Context key.
	 * @return bool
	 */
	private static function is_sensitive_key( $key ) {
		$key = str_replace( array( '-', '.' ), '_', $key );

		return (bool) preg_match(
			'/(^|_)(?:absolute|api_key|apikey|auth|authorization|body|cookie|credential|credentials|email|file|login|nonce|password|pass|path|request|salt|secret|session|stack|token|trace|uri|url|user|username)(_|$)/',
			$key
		);
	}

	/**
	 * Determine whether a scalar value contains a sensitive diagnostic.
	 *
	 * Keys are not always descriptive, so redact obvious URLs, absolute paths,
	 * and inline credential-like assignments as a second privacy boundary.
	 *
	 * @param mixed $value Candidate scalar value.
	 * @return bool
	 */
	private static function is_sensitive_value( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return false;
		}

		$value = trim( $value );

		if ( preg_match( '#\A(?:https?|ftp)://#i', $value ) || preg_match( '#\A//#', $value ) ) {
			return true;
		}

		if ( preg_match( '#\A(?:[A-Za-z]:[\\/]|/)|\\\\\\\\#', $value ) ) {
			return true;
		}

		return (bool) preg_match(
			'/(?:password|token|secret|api[_-]?key|credential|authorization|bearer)\s*[:=]\s*\S+/i',
			$value
		);
	}
}
