<?php
/**
 * Deterministic health score calculator.
 *
 * @package HealthLens
 */

namespace HealthLens\Domain;

use InvalidArgumentException;

/**
 * Calculates weighted score and coverage in the domain layer.
 */
final class ScoreCalculator {
	/**
	 * Calculate a score from definitions, normalized results, and mandatory IDs.
	 *
	 * Unknown and missing results reduce coverage and do not enter the score
	 * denominator. Mandatory IDs must have non-unknown results before a score is
	 * returned.
	 *
	 * @param array<int|string, mixed> $definitions All defined checks.
	 * @param array<int|string, mixed> $results Results keyed by check ID.
	 * @param array<int, mixed>        $mandatory_ids Mandatory check IDs.
	 * @throws InvalidArgumentException If definitions, results, or IDs are invalid.
	 * @return ScoreSummary
	 */
	public function calculate( array $definitions, array $results, array $mandatory_ids = array() ) {
		$definitions_by_id = $this->index_definitions( $definitions );
		$results_by_id     = $this->index_results( $results, $definitions_by_id );
		$mandatory         = $this->index_mandatory_ids( $mandatory_ids, $definitions_by_id );

		$total_checks               = count( $definitions_by_id );
		$completed_checks           = 0;
		$unknown_checks             = 0;
		$missing_checks             = 0;
		$weighted_value             = 0;
		$total_weight               = 0;
		$has_critical               = false;
		$completed_mandatory_checks = 0;

		foreach ( $definitions_by_id as $id => $definition ) {
			if ( ! isset( $results_by_id[ $id ] ) ) {
				++$missing_checks;
				continue;
			}

			$result = $results_by_id[ $id ];
			if ( CheckResult::STATE_UNKNOWN === $result->state() ) {
				++$unknown_checks;
				continue;
			}

			++$completed_checks;
			if ( isset( $mandatory[ $id ] ) ) {
				++$completed_mandatory_checks;
			}

			$value           = self::severity_value( $result->severity() );
			$weighted_value += $value * $definition->weight();
			$total_weight   += $definition->weight();
			$has_critical    = $has_critical || CheckResult::SEVERITY_CRITICAL === $result->severity();
		}

		$coverage_percentage = 0;
		if ( $total_checks > 0 ) {
			$coverage_percentage = (int) round( ( $completed_checks / $total_checks ) * 100, 0, PHP_ROUND_HALF_UP );
		}

		$mandatory_complete = count( $mandatory ) === $completed_mandatory_checks;
		$score              = null;
		$band               = ScoreSummary::BAND_INSUFFICIENT;

		if ( $total_weight > 0 && $mandatory_complete ) {
			$score = (int) round( $weighted_value / $total_weight, 0, PHP_ROUND_HALF_UP );
			if ( $has_critical ) {
				$score = min( 49, $score );
			}

			$band = self::band_for_score( $score );
		}

		return new ScoreSummary(
			$score,
			$band,
			$total_checks,
			$completed_checks,
			$unknown_checks,
			$missing_checks,
			$coverage_percentage,
			count( $mandatory ),
			$completed_mandatory_checks
		);
	}

	/**
	 * Index definitions by stable ID.
	 *
	 * @param array<int|string, mixed> $definitions Definitions.
	 * @throws InvalidArgumentException If a definition is invalid or duplicated.
	 * @return array<string, CheckDefinition>
	 */
	private function index_definitions( array $definitions ) {
		$indexed = array();
		foreach ( $definitions as $definition ) {
			if ( ! $definition instanceof CheckDefinition ) {
				throw new InvalidArgumentException( 'Score definitions must be CheckDefinition objects.' );
			}

			$id = $definition->id();
			if ( isset( $indexed[ $id ] ) ) {
				throw new InvalidArgumentException( 'Score definitions must have unique IDs.' );
			}

			$indexed[ $id ] = $definition;
		}

		ksort( $indexed, SORT_STRING );

		return $indexed;
	}

	/**
	 * Index normalized results by check ID.
	 *
	 * @param array<int|string, mixed>       $results Results keyed by ID.
	 * @param array<string, CheckDefinition> $definitions Indexed definitions.
	 * @throws InvalidArgumentException If a result is invalid or has no definition.
	 * @return array<string, CheckResult>
	 */
	private function index_results( array $results, array $definitions ) {
		$indexed = array();
		foreach ( $results as $id => $result ) {
			if ( ! is_string( $id ) || ! $result instanceof CheckResult || ! isset( $definitions[ $id ] ) ) {
				throw new InvalidArgumentException( 'Score results must be normalized and match a definition.' );
			}

			$indexed[ $id ] = $result;
		}

		return $indexed;
	}

	/**
	 * Index mandatory IDs.
	 *
	 * @param array<int, mixed>              $mandatory_ids Mandatory IDs.
	 * @param array<string, CheckDefinition> $definitions Indexed definitions.
	 * @throws InvalidArgumentException If a mandatory ID is invalid or unknown.
	 * @return array<string, bool>
	 */
	private function index_mandatory_ids( array $mandatory_ids, array $definitions ) {
		$indexed = array();
		foreach ( $mandatory_ids as $id ) {
			if ( ! is_string( $id ) || ! isset( $definitions[ $id ] ) ) {
				throw new InvalidArgumentException( 'Mandatory score IDs must match a definition.' );
			}

			$indexed[ $id ] = true;
		}

		return $indexed;
	}

	/**
	 * Map a severity to its score value.
	 *
	 * @param string $severity Result severity.
	 * @throws InvalidArgumentException If the severity is not scoreable.
	 * @return int
	 */
	private static function severity_value( $severity ) {
		$values = array(
			CheckResult::SEVERITY_HEALTHY  => 100,
			CheckResult::SEVERITY_INFO     => 90,
			CheckResult::SEVERITY_WARNING  => 60,
			CheckResult::SEVERITY_CRITICAL => 0,
		);

		if ( ! isset( $values[ $severity ] ) ) {
			throw new InvalidArgumentException( 'Result severity is not scoreable.' );
		}

		return $values[ $severity ];
	}

	/**
	 * Resolve the band for a score.
	 *
	 * @param int $score Rounded score.
	 * @return string
	 */
	private static function band_for_score( $score ) {
		if ( $score >= 90 ) {
			return ScoreSummary::BAND_HEALTHY;
		}

		if ( $score >= 75 ) {
			return ScoreSummary::BAND_ATTENTION;
		}

		if ( $score >= 50 ) {
			return ScoreSummary::BAND_PROBLEMS;
		}

		return ScoreSummary::BAND_CRITICAL;
	}
}
