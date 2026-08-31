<?php
/**
 * Stored technical-context codec.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\Database;

use HealthLens\Domain\CheckContext;
use InvalidArgumentException;

/**
 * Reuses the domain context boundary for database serialization.
 */
final class ContextCodec {
	/**
	 * Encode bounded context.
	 *
	 * @param CheckContext $context Bounded context.
	 * @return string
	 */
	public static function encode( CheckContext $context ) {
		return $context->to_json();
	}

	/**
	 * Decode and revalidate stored context.
	 *
	 * @param mixed $serialized JSON context.
	 * @throws InvalidArgumentException If the stored value is invalid.
	 * @return CheckContext
	 */
	public static function decode( $serialized ) {
		if ( ! is_string( $serialized ) || '' === $serialized ) {
			throw new InvalidArgumentException( 'Stored context must be a JSON string.' );
		}

		$values = json_decode( $serialized, true );
		if ( ! is_array( $values ) ) {
			throw new InvalidArgumentException( 'Stored context is not a JSON object.' );
		}

		return new CheckContext( $values );
	}
}
