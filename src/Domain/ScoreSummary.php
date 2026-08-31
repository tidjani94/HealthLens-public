<?php
/**
 * Health score and coverage summary value object.
 *
 * @package HealthLens
 */

namespace HealthLens\Domain;

/**
 * Exposes a deterministic score together with availability metadata.
 */
final class ScoreSummary {
	/** Healthy score band. */
	const BAND_HEALTHY = 'healthy';
	/** Attention recommended score band. */
	const BAND_ATTENTION = 'attention-recommended';
	/** Problems detected score band. */
	const BAND_PROBLEMS = 'problems-detected';
	/** Critical attention required score band. */
	const BAND_CRITICAL = 'critical-attention-required';
	/** Score unavailable because coverage is insufficient. */
	const BAND_INSUFFICIENT = 'insufficient-coverage';

	/**
	 * Calculated score.
	 *
	 * @var int|null
	 */
	private $score;

	/**
	 * Stable score band identifier.
	 *
	 * @var string
	 */
	private $band;

	/**
	 * Number of defined checks.
	 *
	 * @var int
	 */
	private $total_checks;

	/**
	 * Number of completed non-unknown checks.
	 *
	 * @var int
	 */
	private $completed_checks;

	/**
	 * Number of explicit unknown results.
	 *
	 * @var int
	 */
	private $unknown_checks;

	/**
	 * Number of missing results.
	 *
	 * @var int
	 */
	private $missing_checks;

	/**
	 * Rounded result coverage percentage.
	 *
	 * @var int
	 */
	private $coverage_percentage;

	/**
	 * Number of mandatory checks.
	 *
	 * @var int
	 */
	private $mandatory_checks;

	/**
	 * Number of completed mandatory checks.
	 *
	 * @var int
	 */
	private $completed_mandatory_checks;

	/**
	 * Create a score and coverage summary.
	 *
	 * @param int|null $score Score from 0 through 100, or null when unavailable.
	 * @param string   $band Stable score band identifier.
	 * @param int      $total_checks Number of defined checks.
	 * @param int      $completed_checks Number of non-unknown results.
	 * @param int      $unknown_checks Number of explicit unknown results.
	 * @param int      $missing_checks Number of definitions without a result.
	 * @param int      $coverage_percentage Rounded availability percentage.
	 * @param int      $mandatory_checks Number of mandatory baseline checks.
	 * @param int      $completed_mandatory_checks Mandatory checks with non-unknown results.
	 */
	public function __construct( $score, $band, $total_checks, $completed_checks, $unknown_checks, $missing_checks, $coverage_percentage, $mandatory_checks, $completed_mandatory_checks ) {
		$this->score                      = $score;
		$this->band                       = $band;
		$this->total_checks               = $total_checks;
		$this->completed_checks           = $completed_checks;
		$this->unknown_checks             = $unknown_checks;
		$this->missing_checks             = $missing_checks;
		$this->coverage_percentage        = $coverage_percentage;
		$this->mandatory_checks           = $mandatory_checks;
		$this->completed_mandatory_checks = $completed_mandatory_checks;
	}

	/**
	 * Return the score, or null when coverage is insufficient.
	 *
	 * @return int|null
	 */
	public function score() {
		return $this->score;
	}

	/**
	 * Return the stable score band identifier.
	 *
	 * @return string
	 */
	public function band() {
		return $this->band;
	}

	/**
	 * Return the human-readable score band label.
	 *
	 * @return string
	 */
	public function band_label() {
		$labels = array(
			self::BAND_HEALTHY      => 'Healthy',
			self::BAND_ATTENTION    => 'Attention Recommended',
			self::BAND_PROBLEMS     => 'Problems Detected',
			self::BAND_CRITICAL     => 'Critical Attention Required',
			self::BAND_INSUFFICIENT => 'Insufficient Coverage',
		);

		return $labels[ $this->band ];
	}

	/**
	 * Return the number of defined checks.
	 *
	 * @return int
	 */
	public function total_checks() {
		return $this->total_checks;
	}

	/**
	 * Return the number of completed non-unknown checks.
	 *
	 * @return int
	 */
	public function completed_checks() {
		return $this->completed_checks;
	}

	/**
	 * Return the number of explicit unknown results.
	 *
	 * @return int
	 */
	public function unknown_checks() {
		return $this->unknown_checks;
	}

	/**
	 * Return the number of definitions without results.
	 *
	 * @return int
	 */
	public function missing_checks() {
		return $this->missing_checks;
	}

	/**
	 * Return result availability as a rounded percentage.
	 *
	 * @return int
	 */
	public function coverage_percentage() {
		return $this->coverage_percentage;
	}

	/**
	 * Return the number of mandatory baseline checks.
	 *
	 * @return int
	 */
	public function mandatory_checks() {
		return $this->mandatory_checks;
	}

	/**
	 * Return the number of completed mandatory baseline checks.
	 *
	 * @return int
	 */
	public function completed_mandatory_checks() {
		return $this->completed_mandatory_checks;
	}
}
