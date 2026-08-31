<?php
/**
 * Health check definition contract value object.
 *
 * @package HealthLens
 */

namespace HealthLens\Domain;

/**
 * Describes a registered health check without executing it.
 */
final class CheckDefinition {
	/**
	 * Stable check identifier.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Stable category identifier.
	 *
	 * @var string
	 */
	private $category;

	/**
	 * Importance weight.
	 *
	 * @var int
	 */
	private $weight;

	/**
	 * Minimum interval between executions in seconds.
	 *
	 * @var int
	 */
	private $cadence;

	/**
	 * Create a validated check definition.
	 *
	 * @param mixed $id Stable lower-case check identifier.
	 * @param mixed $category Stable lower-case category identifier.
	 * @param mixed $weight Importance weight from 1 through 5.
	 * @param mixed $cadence Minimum interval between runs, in seconds.
	 * @throws \InvalidArgumentException If a contract value is invalid.
	 */
	public function __construct( $id, $category, $weight, $cadence ) {
		$this->id       = ContractValidator::slug( $id, 'Check ID' );
		$this->category = ContractValidator::slug( $category, 'Check category' );

		if ( ! is_int( $weight ) || $weight < 1 || $weight > 5 ) {
			throw new \InvalidArgumentException( 'Check weight must be an integer from 1 through 5.' );
		}

		$this->weight  = $weight;
		$this->cadence = ContractValidator::positive_integer( $cadence, 'Check cadence' );
	}

	/**
	 * Return the stable check identifier.
	 *
	 * @return string
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * Return the stable category identifier.
	 *
	 * @return string
	 */
	public function category() {
		return $this->category;
	}

	/**
	 * Return the importance weight.
	 *
	 * @return int
	 */
	public function weight() {
		return $this->weight;
	}

	/**
	 * Return the minimum execution interval.
	 *
	 * @return int Cadence in seconds.
	 */
	public function cadence() {
		return $this->cadence;
	}
}
