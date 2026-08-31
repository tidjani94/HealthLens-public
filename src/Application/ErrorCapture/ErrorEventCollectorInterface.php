<?php
/**
 * Error-capture producer contract.
 *
 * @package HealthLens
 */

namespace HealthLens\Application\ErrorCapture;

use Throwable;

/**
 * Side-effect-only collector interface for supported producers.
 */
interface ErrorEventCollectorInterface {
	/**
	 * Capture an owned-operation throwable.
	 *
	 * @param Throwable $throwable Owned-operation failure.
	 * @param string    $source Producer.
	 * @param string    $location Location.
	 * @param string    $code Stable code.
	 * @return bool
	 */
	public function capture_throwable( Throwable $throwable, $source, $location, $code );
	/**
	 * Capture a supported WP_Error value.
	 *
	 * @param mixed  $error Supported WP_Error value.
	 * @param string $source Producer.
	 * @param string $location Location.
	 * @param string $code Stable code.
	 * @return bool
	 */
	public function capture_wp_error( $error, $source, $location, $code );
	/**
	 * Capture a candidate event.
	 *
	 * @param string $event_type Event type.
	 * @param string $code Event code.
	 * @param string $severity Severity.
	 * @param string $source Producer.
	 * @param string $location Location.
	 * @param array  $context Safe candidate context.
	 * @return bool
	 */
	public function capture( $event_type, $code, $severity, $source, $location, array $context = array() );
	/** Return whether capture is enabled. @return bool */
	public function enabled();
}
