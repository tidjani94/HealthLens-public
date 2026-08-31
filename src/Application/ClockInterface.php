<?php
/**
 * Clock abstraction for bounded background work.
 *
 * @package HealthLens
 */

namespace HealthLens\Application;

use DateTimeImmutable;

/**
 * Supplies wall-clock and monotonic-ish elapsed time to application services.
 */
interface ClockInterface {
	/**
	 * Return the current UTC time.
	 *
	 * @return DateTimeImmutable
	 */
	public function now();

	/**
	 * Return a high-resolution elapsed-time reading.
	 *
	 * @return float
	 */
	public function microtime();
}
