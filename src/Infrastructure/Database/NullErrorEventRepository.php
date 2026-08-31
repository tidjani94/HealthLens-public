<?php
/**
 * No-op error-event repository for runtimes without a database.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\Database;

use HealthLens\Domain\ErrorEvent;

/**
 * Keeps capture failure-isolated when persistence is unavailable.
 */
final class NullErrorEventRepository implements ErrorEventRepositoryInterface {
	/**
	 * Ignore an event when storage is unavailable.
	 *
	 * @param ErrorEvent $event Ignored event.
	 * @return bool
	 */
	public function save( ErrorEvent $event ) {
		return false;
	}
}
