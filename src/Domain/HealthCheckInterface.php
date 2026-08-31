<?php
/**
 * Health check implementation contract.
 *
 * @package HealthLens
 */

namespace HealthLens\Domain;

/**
 * Contract implemented by each independent health check.
 */
interface HealthCheckInterface {
	/**
	 * Describe the check's stable identity and execution cadence.
	 *
	 * @return CheckDefinition
	 */
	public function definition(): CheckDefinition;

	/**
	 * Execute the check with bounded input context.
	 *
	 * @param CheckContext $context Bounded execution context.
	 * @return CheckResult
	 */
	public function run( CheckContext $context ): CheckResult;
}
