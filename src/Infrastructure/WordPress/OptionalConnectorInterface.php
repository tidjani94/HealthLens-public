<?php
/**
 * Optional integration connector contract.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress;

/**
 * Runtime-gated connector boundary; core does not require a provider.
 */
interface OptionalConnectorInterface {
	/**
	 * Return whether this connector is available and explicitly enabled.
	 *
	 * @return bool
	 */
	public function available();

	/**
	 * Return normalized aggregate signals only.
	 *
	 * @return array<string, scalar>
	 */
	public function signals();

	/**
	 * Remove connector-owned local state.
	 *
	 * @return void
	 */
	public function cleanup();
}
