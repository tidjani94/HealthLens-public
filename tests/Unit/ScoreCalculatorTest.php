<?php
/**
 * @package HealthLens
 */

namespace HealthLens\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckDefinition;
use HealthLens\Domain\CheckResult;
use HealthLens\Domain\ScoreCalculator;
use HealthLens\Domain\ScoreSummary;
use PHPUnit\Framework\TestCase;

final class ScoreCalculatorTest extends TestCase {
	public function test_empty_input_withholds_score_and_reports_no_coverage(): void {
		$summary = ( new ScoreCalculator() )->calculate( array(), array() );

		$this->assertNull( $summary->score() );
		$this->assertSame( ScoreSummary::BAND_INSUFFICIENT, $summary->band() );
		$this->assertSame( 'Insufficient Coverage', $summary->band_label() );
		$this->assertSame( 0, $summary->coverage_percentage() );
	}

	public function test_all_unknown_results_are_excluded_and_withhold_score(): void {
		$definitions = array(
			$this->definition( 'first', 1 ),
			$this->definition( 'second', 1 ),
		);
		$results = array(
			'first'  => $this->result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING ),
			'second' => $this->result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING ),
		);

		$summary = ( new ScoreCalculator() )->calculate( $definitions, $results );

		$this->assertNull( $summary->score() );
		$this->assertSame( 0, $summary->completed_checks() );
		$this->assertSame( 2, $summary->unknown_checks() );
		$this->assertSame( 0, $summary->coverage_percentage() );
	}

	public function test_mixed_results_report_reduced_coverage_and_documented_band(): void {
		$definitions = array(
			$this->definition( 'healthy', 1 ),
			$this->definition( 'warning', 1 ),
			$this->definition( 'unknown', 1 ),
		);
		$results = array(
			'healthy' => $this->result( CheckResult::STATE_HEALTHY, CheckResult::SEVERITY_HEALTHY ),
			'warning' => $this->result( CheckResult::STATE_ISSUE, CheckResult::SEVERITY_WARNING ),
			'unknown' => $this->result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING ),
		);

		$summary = ( new ScoreCalculator() )->calculate( $definitions, $results );

		$this->assertSame( 80, $summary->score() );
		$this->assertSame( ScoreSummary::BAND_ATTENTION, $summary->band() );
		$this->assertSame( 'Attention Recommended', $summary->band_label() );
		$this->assertSame( 67, $summary->coverage_percentage() );
		$this->assertSame( 2, $summary->completed_checks() );
		$this->assertSame( 1, $summary->unknown_checks() );
	}

	public function test_info_result_maps_to_90_and_healthy_band(): void {
		$definitions = array( $this->definition( 'info', 1 ) );
		$results     = array( 'info' => $this->result( CheckResult::STATE_ISSUE, CheckResult::SEVERITY_INFO ) );

		$summary = ( new ScoreCalculator() )->calculate( $definitions, $results );

		$this->assertSame( 90, $summary->score() );
		$this->assertSame( ScoreSummary::BAND_HEALTHY, $summary->band() );
		$this->assertSame( 'Healthy', $summary->band_label() );
	}

	public function test_weighted_formula_uses_half_up_rounding(): void {
		$definitions = array(
			$this->definition( 'healthy', 1 ),
			$this->definition( 'warning', 5 ),
		);
		$results = array(
			'healthy' => $this->result( CheckResult::STATE_HEALTHY, CheckResult::SEVERITY_HEALTHY ),
			'warning' => $this->result( CheckResult::STATE_ISSUE, CheckResult::SEVERITY_WARNING ),
		);

		$summary = ( new ScoreCalculator() )->calculate( $definitions, $results );

		$this->assertSame( 67, $summary->score() );
		$this->assertSame( ScoreSummary::BAND_PROBLEMS, $summary->band() );
	}

	public function test_critical_result_caps_score_at_49(): void {
		$definitions = array(
			$this->definition( 'healthy', 1 ),
			$this->definition( 'critical', 1 ),
		);
		$results = array(
			'healthy'  => $this->result( CheckResult::STATE_HEALTHY, CheckResult::SEVERITY_HEALTHY ),
			'critical' => $this->result( CheckResult::STATE_ISSUE, CheckResult::SEVERITY_CRITICAL ),
		);

		$summary = ( new ScoreCalculator() )->calculate( $definitions, $results );

		$this->assertSame( 49, $summary->score() );
		$this->assertSame( ScoreSummary::BAND_CRITICAL, $summary->band() );
	}

	public function test_missing_mandatory_result_withholds_score(): void {
		$definitions = array(
			$this->definition( 'baseline', 1 ),
			$this->definition( 'optional', 1 ),
		);
		$results = array(
			'optional' => $this->result( CheckResult::STATE_HEALTHY, CheckResult::SEVERITY_HEALTHY ),
		);

		$summary = ( new ScoreCalculator() )->calculate( $definitions, $results, array( 'baseline' ) );

		$this->assertNull( $summary->score() );
		$this->assertSame( 1, $summary->missing_checks() );
		$this->assertSame( 0, $summary->completed_mandatory_checks() );
	}

	public function test_unknown_mandatory_result_withholds_score(): void {
		$definitions = array( $this->definition( 'baseline', 1 ) );
		$results     = array( 'baseline' => $this->result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING ) );

		$summary = ( new ScoreCalculator() )->calculate( $definitions, $results, array( 'baseline' ) );

		$this->assertNull( $summary->score() );
		$this->assertSame( 1, $summary->unknown_checks() );
	}

	/**
	 * @param string $id Check ID.
	 * @param int    $weight Importance weight.
	 * @return CheckDefinition
	 */
	private function definition( $id, $weight ) {
		return new CheckDefinition( $id, 'test', $weight, 900 );
	}

	/**
	 * @param string $state Result state.
	 * @param string $severity Result severity.
	 * @return CheckResult
	 */
	private function result( $state, $severity ) {
		return new CheckResult(
			$state,
			$severity,
			'test-result',
			new CheckContext(),
			new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
			0
		);
	}
}
