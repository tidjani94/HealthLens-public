<?php
/**
 * System clock implementation.
 *
 * @package HealthLens
 */

namespace HealthLens\Application;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads the current UTC wall clock and PHP high-resolution timer.
 */
final class SystemClock implements ClockInterface {
	/**
	 * {@inheritdoc}
	 */
	public function now() {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function microtime() {
		return microtime( true );
	}
}
