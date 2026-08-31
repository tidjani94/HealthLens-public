<?php
/**
 * Shared bounded filesystem and storage helpers.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress;

use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckResult;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Converts approved WordPress storage metadata into safe aggregate results.
 */
final class StorageScanSupport {
	/** Minimum free-space ratio before warning. */
	const MIN_FREE_RATIO = 0.10;
	/** Maximum filesystem categories inspected by one check. */
	const MAX_PATH_CATEGORIES = 4;

	/**
	 * Create a normalized storage result.
	 *
	 * @param string $state Result state.
	 * @param string $severity Severity.
	 * @param string $code Message code.
	 * @param array  $context Safe context.
	 * @return CheckResult
	 */
	public static function result( $state, $severity, $code, array $context ): CheckResult {
		return new CheckResult( $state, $severity, $code, new CheckContext( $context ), new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ), 0 );
	}

	/**
	 * Convert a numeric value to a coarse bucket.
	 *
	 * @param mixed $value Candidate byte count.
	 * @param int   $step Bucket size.
	 * @return int
	 */
	public static function bucket( $value, $step = 1048576 ): int {
		return (int) floor( max( 0, (int) $value ) / $step ) * $step;
	}
}
