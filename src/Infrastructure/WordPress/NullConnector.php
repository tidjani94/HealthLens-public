<?php
/**
 * Absent optional integration connector.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress;

/**
 * Keeps the local plugin identical when optional integrations are absent.
 */
final class NullConnector implements OptionalConnectorInterface {
	/**
	 * Report that no connector is available.
	 *
	 * @return bool
	 */
	public function available() {
		return false;
	}

	/**
	 * Return no third-party signals.
	 *
	 * @return array<string, scalar>
	 */
	public function signals() {
		return array();
	}

	/**
	 * Keep cleanup a no-op.
	 *
	 * @return void
	 */
	public function cleanup() {
	}
}
