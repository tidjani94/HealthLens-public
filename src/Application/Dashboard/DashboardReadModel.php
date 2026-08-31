<?php
/**
 * Compose the bounded dashboard summary from cached state.
 *
 * @package HealthLens
 */

namespace HealthLens\Application\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use HealthLens\Application\CheckRegistry;
use HealthLens\Application\ClockInterface;
use HealthLens\Domain\CheckDefinition;
use HealthLens\Domain\CheckResult;
use HealthLens\Domain\ScoreCalculator;
use HealthLens\Infrastructure\Database\IncidentRepository;
use HealthLens\Infrastructure\Database\ResultRepository;
use HealthLens\Infrastructure\WordPress\NotificationStateRepository;

/**
 * Builds a deterministic, read-only presentation model for the dashboard.
 */
final class DashboardReadModel {
	/** Maximum number of definitions, results, incidents, and view items. */
	const MAX_ITEMS = 50;

	/**
	 * Registered checks and their definitions.
	 *
	 * @var CheckRegistry
	 */
	private $registry;

	/**
	 * Current-result reader.
	 *
	 * @var ResultRepository
	 */
	private $results;

	/**
	 * Open-incident reader.
	 *
	 * @var IncidentRepository
	 */
	private $incidents;

	/**
	 * Score calculator.
	 *
	 * @var ScoreCalculator
	 */
	private $score_calculator;

	/**
	 * Current UTC clock.
	 *
	 * @var ClockInterface
	 */
	private $clock;

	/**
	 * Mandatory baseline IDs.
	 *
	 * @var array<int, string>
	 */
	private $mandatory_ids;

	/**
	 * Read limit.
	 *
	 * @var int
	 */
	private $limit;

	/**
	 * Optional notification status reader.
	 *
	 * @var NotificationStateRepository|null
	 */
	private $notification_state;

	/**
	 * Create a dashboard read model composer.
	 *
	 * @param CheckRegistry                    $registry Registered checks.
	 * @param ResultRepository                 $results Current-result repository.
	 * @param IncidentRepository               $incidents Incident repository.
	 * @param ScoreCalculator                  $score_calculator Score calculator.
	 * @param ClockInterface                   $clock UTC clock.
	 * @param array<int, string>               $mandatory_ids Mandatory baseline IDs.
	 * @param int                              $limit Maximum rows and view items.
	 * @param NotificationStateRepository|null $notification_state Notification status reader.
	 */
	public function __construct( CheckRegistry $registry, ResultRepository $results, IncidentRepository $incidents, ScoreCalculator $score_calculator, ClockInterface $clock, array $mandatory_ids = array(), $limit = self::MAX_ITEMS, $notification_state = null ) {
		$this->registry           = $registry;
		$this->results            = $results;
		$this->incidents          = $incidents;
		$this->score_calculator   = $score_calculator;
		$this->clock              = $clock;
		$this->mandatory_ids      = array_values( $mandatory_ids );
		$this->limit              = max( 1, min( self::MAX_ITEMS, (int) $limit ) );
		$this->notification_state = $notification_state instanceof NotificationStateRepository ? $notification_state : null;
	}

	/**
	 * Compose the current dashboard summary without executing checks.
	 *
	 * @return array<string, mixed>
	 */
	public function compose() {
		$definitions    = array_slice( $this->registry->definitions(), 0, $this->limit );
		$definition_ids = array();

		foreach ( $definitions as $definition ) {
			$definition_ids[ $definition->id() ] = true;
		}

		$results   = array_intersect_key( $this->results->all( $this->limit ), $definition_ids );
		$incidents = $this->index_incidents( $this->incidents->all_open( $this->limit ), $definition_ids );
		$summary   = $this->score_calculator->calculate( $definitions, $results, $this->mandatory_ids );
		$items     = $this->build_items( $definitions, $results, $incidents );
		$freshness = $this->build_freshness( $definitions, $results, $summary );
		$history   = $this->history( $this->incidents->recent_history( $this->clock->now()->modify( '-7 days' ), $this->limit ) );

		return array(
			'availability'  => $this->availability( $definitions, $results, $summary ),
			'score'         => array(
				'value'               => $summary->score(),
				'band'                => $summary->band(),
				'coverage_percentage' => $summary->coverage_percentage(),
			),
			'coverage'      => array(
				'total'              => $summary->total_checks(),
				'completed'          => $summary->completed_checks(),
				'unknown'            => $summary->unknown_checks(),
				'missing'            => $summary->missing_checks(),
				'mandatory'          => $summary->mandatory_checks(),
				'mandatory_complete' => $summary->completed_mandatory_checks(),
			),
			'freshness'     => $freshness,
			'priorities'    => $this->priority_counts( $items ),
			'incidents'     => array(
				'open_count' => count( $incidents ),
				'items'      => array_values( $incidents ),
			),
			'history'       => $history,
			'notifications' => $this->notification_summary(),
			'items'         => $items,
			'limits'        => array(
				'checks'    => $this->limit,
				'incidents' => $this->limit,
			),
		);
	}

	/**
	 * Normalize recent resolved incidents for presentation.
	 *
	 * @param array<int, array<string, mixed>> $rows Incident rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function history( array $rows ) {
		$history = array();
		foreach ( array_slice( $rows, 0, $this->limit ) as $row ) {
			if ( ! isset( $row['check_id'], $row['resolved_at'] ) ) {
				continue;
			}
			$history[] = array(
				'check_id'          => (string) $row['check_id'],
				'severity'          => isset( $row['severity'] ) ? (string) $row['severity'] : 'unknown',
				'message_code'      => isset( $row['message_code'] ) ? (string) $row['message_code'] : 'incident.unknown',
				'first_detected_at' => isset( $row['first_detected_at'] ) ? $this->format_database_timestamp( $row['first_detected_at'] ) : null,
				'last_detected_at'  => isset( $row['last_detected_at'] ) ? $this->format_database_timestamp( $row['last_detected_at'] ) : null,
				'resolved_at'       => $this->format_database_timestamp( $row['resolved_at'] ),
			);
		}
		return $history;
	}

	/**
	 * Return safe notification delivery status.
	 *
	 * @return array<string, mixed>
	 */
	private function notification_summary() {
		if ( ! $this->notification_state ) {
			return array(
				'event_count'     => 0,
				'sent_count'      => 0,
				'failed_count'    => 0,
				'last_attempt_at' => null,
			);
		}
		return $this->notification_state->summary();
	}

	/**
	 * Index only known open incidents and keep the newest row per check.
	 *
	 * @param array<int, array<string, mixed>> $rows Incident rows.
	 * @param array<string, bool>              $definition_ids Known IDs.
	 * @return array<string, array<string, mixed>>
	 */
	private function index_incidents( array $rows, array $definition_ids ) {
		$indexed = array();

		foreach ( $rows as $row ) {
			if ( ! isset( $row['check_id'], $definition_ids[ $row['check_id'] ] ) || isset( $indexed[ $row['check_id'] ] ) ) {
				continue;
			}

			$indexed[ $row['check_id'] ] = array(
				'check_id'          => $row['check_id'],
				'severity'          => isset( $row['severity'] ) ? $row['severity'] : null,
				'message_code'      => isset( $row['message_code'] ) ? $row['message_code'] : null,
				'first_detected_at' => $this->format_database_timestamp( $row['first_detected_at'] ),
				'last_detected_at'  => $this->format_database_timestamp( $row['last_detected_at'] ),
			);
		}

		return $indexed;
	}

	/**
	 * Build one safe summary item per definition.
	 *
	 * @param array<int, CheckDefinition>         $definitions Definitions.
	 * @param array<string, CheckResult>          $results Current results.
	 * @param array<string, array<string, mixed>> $incidents Open incidents.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_items( array $definitions, array $results, array $incidents ) {
		$items = array();

		foreach ( $definitions as $definition ) {
			$id      = $definition->id();
			$result  = isset( $results[ $id ] ) ? $results[ $id ] : null;
			$items[] = array(
				'check_id'          => $id,
				'category'          => $definition->category(),
				'state'             => $result ? $result->state() : 'missing',
				'severity'          => $result ? $result->severity() : 'missing',
				'message_code'      => $result ? $result->message_code() : null,
				'checked_at'        => $result ? $this->format_timestamp( $result->checked_at() ) : null,
				'technical_details' => $result ? $this->technical_details( $result ) : array(),
				'incident'          => isset( $incidents[ $id ] ) ? $incidents[ $id ] : null,
			);
		}

		usort(
			$items,
			function ( $left, $right ) {
				$left_rank  = $this->severity_rank( $left['severity'] );
				$right_rank = $this->severity_rank( $right['severity'] );

				if ( $left_rank !== $right_rank ) {
					return $left_rank < $right_rank ? -1 : 1;
				}

				return strcmp( $left['check_id'], $right['check_id'] );
			}
		);

		return array_slice( $items, 0, $this->limit );
	}

	/**
	 * Count visible result priorities and missing/unknown states.
	 *
	 * @param array<int, array<string, mixed>> $items View items.
	 * @return array<string, int>
	 */
	private function priority_counts( array $items ) {
		$counts = array(
			'critical' => 0,
			'warning'  => 0,
			'info'     => 0,
			'healthy'  => 0,
			'unknown'  => 0,
			'missing'  => 0,
		);

		foreach ( $items as $item ) {
			if ( isset( $counts[ $item['severity'] ] ) ) {
				++$counts[ $item['severity'] ];
			}

			if ( CheckResult::STATE_UNKNOWN === $item['state'] ) {
				++$counts['unknown'];
			}
		}

		return $counts;
	}

	/**
	 * Determine score availability without implying certainty.
	 *
	 * @param array<int, CheckDefinition>     $definitions Definitions.
	 * @param array<string, CheckResult>      $results Results.
	 * @param \HealthLens\Domain\ScoreSummary $summary Score summary.
	 * @return array<string, string>
	 */
	private function availability( array $definitions, array $results, $summary ) {
		if ( empty( $definitions ) ) {
			return array(
				'state'        => 'no-data',
				'message_code' => 'dashboard.no-data',
			);
		}

		if ( empty( $results ) ) {
			return array(
				'state'        => 'never-run',
				'message_code' => 'dashboard.never-run',
			);
		}

		if ( null === $summary->score() ) {
			return array(
				'state'        => 'insufficient-coverage',
				'message_code' => 'dashboard.insufficient-coverage',
			);
		}

		return array(
			'state'        => 'ready',
			'message_code' => 'dashboard.ready',
		);
	}

	/**
	 * Determine freshness and the most recent cached timestamp.
	 *
	 * @param array<int, CheckDefinition>     $definitions Definitions.
	 * @param array<string, CheckResult>      $results Results.
	 * @param \HealthLens\Domain\ScoreSummary $summary Score summary.
	 * @return array<string, mixed>
	 */
	private function build_freshness( array $definitions, array $results, $summary ) {
		$now      = $this->clock->now()->setTimezone( new DateTimeZone( 'UTC' ) );
		$last     = null;
		$is_stale = false;

		foreach ( $definitions as $definition ) {
			$id = $definition->id();
			if ( ! isset( $results[ $id ] ) ) {
				continue;
			}

			$checked_at = $results[ $id ]->checked_at();
			if ( null === $last || $checked_at > $last ) {
				$last = $checked_at;
			}

			if ( $checked_at->getTimestamp() + $definition->cadence() <= $now->getTimestamp() ) {
				$is_stale = true;
			}
		}

		if ( empty( $definitions ) ) {
			$state        = 'no-data';
			$message_code = 'dashboard.freshness.no-data';
		} elseif ( empty( $results ) ) {
			$state        = 'never-run';
			$message_code = 'dashboard.freshness.never-run';
		} elseif ( $is_stale ) {
			$state        = 'stale';
			$message_code = 'dashboard.freshness.stale-wp-cron';
		} elseif ( $summary->missing_checks() > 0 ) {
			$state        = 'partial';
			$message_code = 'dashboard.freshness.partial';
		} else {
			$state        = 'current';
			$message_code = 'dashboard.freshness.current';
		}

		return array(
			'state'           => $state,
			'message_code'    => $message_code,
			'is_stale'        => $is_stale,
			'last_checked_at' => null === $last ? null : $this->format_timestamp( $last ),
		);
	}

	/**
	 * Return a stable priority rank.
	 *
	 * @param mixed $severity Severity or presentation state.
	 * @return int
	 */
	private function severity_rank( $severity ) {
		$ranks = array(
			CheckResult::SEVERITY_CRITICAL => 0,
			CheckResult::SEVERITY_WARNING  => 1,
			CheckResult::SEVERITY_INFO     => 2,
			CheckResult::SEVERITY_HEALTHY  => 3,
			'missing'                      => 4,
		);

		return isset( $ranks[ $severity ] ) ? $ranks[ $severity ] : 2;
	}

	/**
	 * Return a small, already-redacted technical detail set.
	 *
	 * @param CheckResult $result Normalized result.
	 * @return array<string, scalar>
	 */
	private function technical_details( CheckResult $result ) {
		$details = array_slice( $result->context()->to_array(), 0, 12, true );
		ksort( $details, SORT_STRING );

		return $details;
	}

	/**
	 * Format a UTC timestamp for presentation.
	 *
	 * @param DateTimeImmutable $timestamp Timestamp.
	 * @return string
	 */
	private function format_timestamp( DateTimeImmutable $timestamp ) {
		return $timestamp->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'c' );
	}

	/**
	 * Normalize a database timestamp for presentation.
	 *
	 * @param mixed $timestamp Database timestamp.
	 * @return string|null
	 */
	private function format_database_timestamp( $timestamp ) {
		if ( ! is_string( $timestamp ) || '' === $timestamp ) {
			return null;
		}

		return $this->format_timestamp( new DateTimeImmutable( $timestamp, new DateTimeZone( 'UTC' ) ) );
	}
}
