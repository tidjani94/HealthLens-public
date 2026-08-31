<?php
/**
 * @package HealthLens
 */

namespace HealthLens\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use HealthLens\Application\CheckRegistry;
use HealthLens\Application\ClockInterface;
use HealthLens\Application\Dashboard\DashboardReadModel;
use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckDefinition;
use HealthLens\Domain\CheckResult;
use HealthLens\Domain\HealthCheckInterface;
use HealthLens\Domain\ScoreCalculator;
use HealthLens\Infrastructure\Database\IncidentRepository;
use HealthLens\Infrastructure\Database\ResultRepository;
use HealthLens\Infrastructure\Database\SchemaManager;
use PHPUnit\Framework\TestCase;

final class DashboardReadModelTest extends TestCase {
	public function test_empty_cached_state_is_never_run_and_does_not_execute_checks(): void {
		$check = new DashboardTestCheck( new CheckDefinition( 'first', 'wordpress', 1, 900 ) );
		$model = $this->model( array( $check ) );

		$view = $model->compose();

		$this->assertSame( 'never-run', $view['availability']['state'] );
		$this->assertSame( 'never-run', $view['freshness']['state'] );
		$this->assertNull( $view['score']['value'] );
		$this->assertSame( 1, $view['coverage']['missing'] );
		$this->assertSame( 1, $view['priorities']['missing'] );
		$this->assertSame( 0, $check->run_count );
	}

	public function test_mixed_state_summary_reports_score_freshness_priorities_and_incident(): void {
		$checks = array(
			new DashboardTestCheck( new CheckDefinition( 'critical', 'wordpress', 1, 900 ) ),
			new DashboardTestCheck( new CheckDefinition( 'warning', 'wordpress', 1, 900 ) ),
			new DashboardTestCheck( new CheckDefinition( 'unknown', 'wordpress', 1, 900 ) ),
			new DashboardTestCheck( new CheckDefinition( 'missing', 'wordpress', 1, 900 ) ),
		);
		$results = array(
			$this->row( 'critical', CheckResult::STATE_ISSUE, CheckResult::SEVERITY_CRITICAL, 'critical-result', '2026-08-19 11:59:00' ),
			$this->row( 'warning', CheckResult::STATE_ISSUE, CheckResult::SEVERITY_WARNING, 'warning-result', '2026-08-19 10:00:00' ),
			$this->row( 'unknown', CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING, 'unknown-result', '2026-08-19 11:59:00' ),
		);
		$incidents = array(
			array(
				'id'               => 4,
				'check_id'         => 'warning',
				'severity'         => CheckResult::SEVERITY_WARNING,
				'message_code'     => 'warning-result',
				'first_detected_at' => '2026-08-19 09:00:00',
				'last_detected_at'  => '2026-08-19 10:00:00',
				'context_json'     => '{"private":"must-not-leak"}',
			),
		);
		$model = $this->model( $checks, $results, $incidents, '2026-08-19 12:00:00' );

		$view = $model->compose();

		$this->assertSame( 'ready', $view['availability']['state'] );
		$this->assertSame( 30, $view['score']['value'] );
		$this->assertSame( 50, $view['score']['coverage_percentage'] );
		$this->assertSame( 'stale', $view['freshness']['state'] );
		$this->assertTrue( $view['freshness']['is_stale'] );
		$this->assertSame( '2026-08-19T11:59:00+00:00', $view['freshness']['last_checked_at'] );
		$this->assertSame( 1, $view['priorities']['critical'] );
		$this->assertSame( 1, $view['priorities']['unknown'] );
		$this->assertSame( 1, $view['incidents']['open_count'] );
		$this->assertSame( 'warning', $view['incidents']['items'][0]['check_id'] );
		$this->assertArrayNotHasKey( 'context_json', $view['incidents']['items'][0] );
		$this->assertSame( 'critical', $view['items'][0]['check_id'] );
		$this->assertSame( 'missing', $view['items'][3]['state'] );
	}

	public function test_equal_priorities_are_ordered_by_stable_check_id_and_reads_are_bounded(): void {
		$checks = array(
			new DashboardTestCheck( new CheckDefinition( 'beta', 'wordpress', 1, 900 ) ),
			new DashboardTestCheck( new CheckDefinition( 'alpha', 'wordpress', 1, 900 ) ),
		);
		$wpdb = new DashboardReadModelWpdb(
			array(
				$this->row( 'beta', CheckResult::STATE_ISSUE, CheckResult::SEVERITY_WARNING, 'beta-result', '2026-08-19 11:59:00' ),
				$this->row( 'alpha', CheckResult::STATE_ISSUE, CheckResult::SEVERITY_WARNING, 'alpha-result', '2026-08-19 11:59:00' ),
			),
			array()
		);
		$model = $this->model( $checks, array(), array(), '2026-08-19 12:00:00', $wpdb );

		$view = $model->compose();

		$this->assertSame( 'alpha', $view['items'][0]['check_id'] );
		$this->assertSame( 'beta', $view['items'][1]['check_id'] );
		$this->assertStringContainsString( 'LIMIT 50', implode( "\n", $wpdb->queries ) );
	}

	/**
	 * @param array<int, DashboardTestCheck> $checks Checks.
	 * @param array<int, array<string, mixed>> $results Result rows.
	 * @param array<int, array<string, mixed>> $incidents Incident rows.
	 * @param string $now Fixed current time.
	 * @param DashboardReadModelWpdb|null $wpdb Database double.
	 * @return DashboardReadModel
	 */
	private function model( array $checks, array $results = array(), array $incidents = array(), $now = '2026-08-19 12:00:00', $wpdb = null ) {
		$registry = new CheckRegistry();
		foreach ( $checks as $check ) {
			$registry->register( $check );
		}

		$wpdb = $wpdb instanceof DashboardReadModelWpdb ? $wpdb : new DashboardReadModelWpdb( $results, $incidents );
		return new DashboardReadModel(
			$registry,
			new ResultRepository( $wpdb, new SchemaManager( $wpdb ) ),
			new IncidentRepository( $wpdb, new SchemaManager( $wpdb ) ),
			new ScoreCalculator(),
			new DashboardTestClock( new DateTimeImmutable( $now, new DateTimeZone( 'UTC' ) ) )
		);
	}

	/**
	 * @param string $check_id Check ID.
	 * @param string $state State.
	 * @param string $severity Severity.
	 * @param string $message_code Message code.
	 * @param string $checked_at Checked timestamp.
	 * @return array<string, string>
	 */
	private function row( $check_id, $state, $severity, $message_code, $checked_at ) {
		return array(
			'check_id'     => $check_id,
			'state'        => $state,
			'severity'     => $severity,
			'message_code' => $message_code,
			'context_json' => '{}',
			'checked_at'   => $checked_at,
			'duration_ms'  => '10',
		);
	}
}

final class DashboardTestCheck implements HealthCheckInterface {
	/** @var CheckDefinition */
	private $definition;

	/** @var int */
	public $run_count = 0;

	public function __construct( CheckDefinition $definition ) {
		$this->definition = $definition;
	}

	public function definition(): CheckDefinition {
		return $this->definition;
	}

	public function run( CheckContext $context ): CheckResult {
		++$this->run_count;
		throw new \RuntimeException( 'Dashboard composition must never execute a check.' );
	}
}

final class DashboardTestClock implements ClockInterface {
	/** @var DateTimeImmutable */
	private $now;

	public function __construct( DateTimeImmutable $now ) {
		$this->now = $now;
	}

	public function now() {
		return $this->now;
	}

	public function microtime() {
		return 0.0;
	}
}

final class DashboardReadModelWpdb {
	/** @var string */
	public $prefix = 'wp_test_';

	/** @var array<int, array<string, mixed>> */
	private $result_rows;

	/** @var array<int, array<string, mixed>> */
	private $incident_rows;

	/** @var array<int, string> */
	public $queries = array();

	public function __construct( array $result_rows, array $incident_rows ) {
		$this->result_rows   = $result_rows;
		$this->incident_rows = $incident_rows;
	}

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query       = preg_replace( '/%[sdi]/', $replacement, $query, 1 );
		}

		return $query;
	}

	public function get_results( $query, $output ) {
		$this->queries[] = $query;

		return false !== strpos( $query, 'healthlens_incidents' ) ? $this->incident_rows : $this->result_rows;
	}
}
