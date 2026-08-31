<?php
/**
 * Health check registry.
 *
 * @package HealthLens
 */

namespace HealthLens\Application;

use HealthLens\Domain\HealthCheckInterface;
use InvalidArgumentException;

/**
 * Stores validated health checks in deterministic identifier order.
 */
final class CheckRegistry {
	/**
	 * Registered checks keyed by stable ID.
	 *
	 * @var array<string, HealthCheckInterface>
	 */
	private $checks = array();

	/**
	 * Register one health check.
	 *
	 * @param HealthCheckInterface $check Check implementation.
	 * @throws InvalidArgumentException If the check ID is already registered.
	 * @return void
	 */
	public function register( HealthCheckInterface $check ) {
		$definition = $check->definition();
		$id         = $definition->id();

		if ( isset( $this->checks[ $id ] ) ) {
			throw new InvalidArgumentException( 'A health check with this ID is already registered.' );
		}

		$this->checks[ $id ] = $check;
	}

	/**
	 * Determine whether a check ID is registered.
	 *
	 * @param mixed $id Stable check identifier.
	 * @return bool
	 */
	public function has( $id ) {
		return is_string( $id ) && isset( $this->checks[ $id ] );
	}

	/**
	 * Return a registered check by ID.
	 *
	 * @param mixed $id Stable check identifier.
	 * @throws InvalidArgumentException If the ID is not registered.
	 * @return HealthCheckInterface
	 */
	public function get( $id ) {
		if ( ! $this->has( $id ) ) {
			throw new InvalidArgumentException( 'The requested health check is not registered.' );
		}

		return $this->checks[ $id ];
	}

	/**
	 * Return all registered checks in stable ID order.
	 *
	 * @return array<int, HealthCheckInterface>
	 */
	public function all() {
		$checks = $this->checks;
		ksort( $checks, SORT_STRING );

		return array_values( $checks );
	}

	/**
	 * Return registered definitions without executing checks.
	 *
	 * @return array<int, \HealthLens\Domain\CheckDefinition>
	 */
	public function definitions() {
		$definitions = array();

		foreach ( $this->all() as $check ) {
			$definitions[] = $check->definition();
		}

		return $definitions;
	}
}
