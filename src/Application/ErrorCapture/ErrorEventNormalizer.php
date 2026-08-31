<?php
/**
 * Error-capture normalization boundary.
 *
 * @package HealthLens
 */

namespace HealthLens\Application\ErrorCapture;

use DateTimeImmutable;
use DateTimeZone;
use HealthLens\Domain\CheckContext;
use HealthLens\Domain\ContractValidator;
use HealthLens\Domain\ErrorEvent;
use Throwable;

/**
 * Converts supported producer values into safe, allowlisted events.
 */
final class ErrorEventNormalizer {
	/**
	 * Allowlisted context keys.
	 *
	 * @var array<int, string>
	 */
	private static $context_keys = array( 'error_level', 'line_bucket', 'check_id', 'operation', 'error_type' );

	/**
	 * Normalize a HealthLens-owned event.
	 *
	 * @param string $event_type Event type.
	 * @param string $code Event code.
	 * @param string $severity Severity.
	 * @param string $source Producer category.
	 * @param string $location Safe location category.
	 * @param array  $context Candidate scalar context.
	 * @return ErrorEvent
	 */
	public static function event( $event_type, $code, $severity, $source, $location, array $context = array() ) {
		return new ErrorEvent(
			self::safe_slug( $event_type, 'runtime' ),
			self::safe_slug( $code, 'unknown' ),
			self::safe_severity( $severity ),
			self::safe_slug( $source, 'healthlens' ),
			self::safe_slug( $location, 'runtime' ),
			new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
			new CheckContext( self::context( $context ) )
		);
	}

	/**
	 * Normalize a supported throwable without persisting its message/class.
	 *
	 * @param Throwable $throwable Throwable from an owned operation.
	 * @param string    $source Producer category.
	 * @param string    $location Safe location category.
	 * @param string    $code Stable event code.
	 * @return ErrorEvent
	 */
	public static function throwable( Throwable $throwable, $source, $location, $code ) {
		return self::event( 'throwable', $code, 'warning', $source, $location, array( 'error_type' => 'throwable' ) );
	}

	/**
	 * Normalize a supported WP_Error without retaining messages or arbitrary data.
	 *
	 * @param mixed  $error WP_Error-like value.
	 * @param string $source Producer category.
	 * @param string $location Safe location category.
	 * @param string $code Fallback event code.
	 * @return ErrorEvent|null
	 */
	public static function wp_error( $error, $source, $location, $code ) {
		if ( ! function_exists( 'is_wp_error' ) || ! is_wp_error( $error ) ) {
			return null;
		}

		$error_code = '';
		$codes      = $error->get_error_codes();
		if ( isset( $codes[0] ) && is_string( $codes[0] ) ) {
			$error_code = $codes[0];
		}

		return self::event( 'wp-error', '' !== $error_code ? $error_code : $code, 'warning', $source, $location, array( 'error_type' => 'wp_error' ) );
	}

	/**
	 * Keep only approved scalar keys and bounded primitive values.
	 *
	 * @param array $candidate Candidate context.
	 * @return array<string, bool|float|int|string>
	 */
	private static function context( array $candidate ) {
		$allowed = array_fill_keys( self::$context_keys, true );
		$safe    = array();

		foreach ( $candidate as $key => $value ) {
			if ( ! is_string( $key ) || ! isset( $allowed[ $key ] ) || ! is_scalar( $value ) ) {
				continue;
			}

			if ( is_string( $value ) ) {
				$value = substr( preg_replace( '/[^a-zA-Z0-9_.:-]/', '_', $value ), 0, 128 );
			}

			$safe[ $key ] = $value;
		}

		return $safe;
	}

	/**
	 * Normalize a candidate slug with a safe fallback.
	 *
	 * @param mixed  $value Candidate slug.
	 * @param string $fallback Safe fallback.
	 * @return string
	 */
	private static function safe_slug( $value, $fallback ) {
		try {
			return ContractValidator::slug( (string) $value, 'Event value', 100 );
		} catch ( \Throwable $throwable ) {
			return $fallback;
		}
	}

	/**
	 * Normalize a candidate severity.
	 *
	 * @param mixed $severity Candidate severity.
	 * @return string
	 */
	private static function safe_severity( $severity ) {
		return in_array( $severity, array( 'info', 'warning', 'critical' ), true ) ? $severity : 'warning';
	}
}
