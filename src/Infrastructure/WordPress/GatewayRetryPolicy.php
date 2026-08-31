<?php
/**
 * Finite optional-gateway retry policy.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress;

/**
 * Provides deterministic backoff without an unbounded queue or retry storm.
 */
final class GatewayRetryPolicy {
	const MAX_ATTEMPTS = 3;
	const MAX_DELAY    = 7200;

	/**
	 * Return whether another attempt is allowed.
	 *
	 * @param int $attempts Attempts already made.
	 * @param int $now Current epoch time.
	 * @param int $next_attempt_at Earliest next attempt.
	 * @return bool
	 */
	public static function eligible( $attempts, $now, $next_attempt_at ) {
		return (int) $attempts < self::MAX_ATTEMPTS && (int) $now >= (int) $next_attempt_at;
	}

	/**
	 * Return bounded exponential backoff seconds.
	 *
	 * @param int $attempts Attempts after the failure.
	 * @return int
	 */
	public static function delay( $attempts ) {
		$attempts = max( 1, min( self::MAX_ATTEMPTS, (int) $attempts ) );
		return min( self::MAX_DELAY, 300 * ( 2 ** ( $attempts - 1 ) ) );
	}
}
