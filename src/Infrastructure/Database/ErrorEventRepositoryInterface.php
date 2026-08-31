<?php
/**
 * Error-event persistence contract.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\Database;

use HealthLens\Domain\ErrorEvent;

/**
 * Bounds the collector to a site-local storage adapter.
 */
interface ErrorEventRepositoryInterface {
	/**
	 * Persist a normalized event.
	 *
	 * @param ErrorEvent $event Event to persist.
	 * @return bool
	 */
	public function save( ErrorEvent $event );
}
