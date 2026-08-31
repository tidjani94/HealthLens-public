<?php
/**
 * Failure-isolated health check runner.
 *
 * @package HealthLens
 */

namespace HealthLens\Application;

use DateTimeImmutable;
use DateTimeZone;
use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckResult;
use HealthLens\Application\ErrorCapture\ErrorEventCollectorInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Executes registered checks in deterministic order.
 */
final class CheckRunner {
	/**
	 * Safe message code for a check that could not produce a result.
	 *
	 * @var string
	 */
	const FAILURE_MESSAGE_CODE = 'check.execution-failed';

	/**
	 * Registry used for check selection.
	 *
	 * @var CheckRegistry
	 */
	private $registry;

	/**
	 * Optional side-effect-only error collector.
	 *
	 * @var ErrorEventCollectorInterface|null
	 */
	private $error_collector;

	/**
	 * Create a runner for a registry.
	 *
	 * @param CheckRegistry                     $registry        Registered checks.
	 * @param ErrorEventCollectorInterface|null $error_collector Optional collector.
	 */
	public function __construct( CheckRegistry $registry, $error_collector = null ) {
		$this->registry        = $registry;
		$this->error_collector = $error_collector instanceof ErrorEventCollectorInterface ? $error_collector : null;
	}

	/**
	 * Run all registered checks or only the selected IDs.
	 *
	 * The application layer supplies selected IDs when a scheduler has already
	 * determined which checks are due. An empty selection runs every check.
	 *
	 * @param CheckContext      $context Bounded execution context.
	 * @param array<int, mixed> $selected_ids Optional check IDs to run.
	 * @throws InvalidArgumentException If a selected ID is not registered.
	 * @return array<string, CheckResult> Results keyed by stable check ID.
	 */
	public function run( CheckContext $context, array $selected_ids = array() ) {
		$selected = $this->select_checks( $selected_ids );
		$results  = array();

		foreach ( $selected as $check ) {
			$id         = $check->definition()->id();
			$started_at = microtime( true );

			try {
				$result         = $check->run( $context );
				$results[ $id ] = new CheckResult(
					$result->state(),
					$result->severity(),
					$result->message_code(),
					$result->context(),
					$result->checked_at(),
					self::duration_milliseconds( $started_at )
				);
			} catch ( Throwable $throwable ) {
				if ( $this->error_collector ) {
					$this->error_collector->capture_throwable( $throwable, 'healthlens', 'check', 'healthlens.check.failed' );
				}
				$results[ $id ] = new CheckResult(
					CheckResult::STATE_UNKNOWN,
					CheckResult::SEVERITY_WARNING,
					self::FAILURE_MESSAGE_CODE,
					new CheckContext( array( 'check_id' => $id ) ),
					new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
					self::duration_milliseconds( $started_at )
				);
			}
		}

		return $results;
	}

	/**
	 * Select checks in registry order and validate requested IDs.
	 *
	 * @param array<int, mixed> $selected_ids Requested IDs.
	 * @throws InvalidArgumentException If an ID is not registered.
	 * @return array<int, \HealthLens\Domain\HealthCheckInterface>
	 */
	private function select_checks( array $selected_ids ) {
		if ( empty( $selected_ids ) ) {
			return $this->registry->all();
		}

		$selected_map = array();
		foreach ( $selected_ids as $id ) {
			if ( ! is_string( $id ) || ! $this->registry->has( $id ) ) {
				throw new InvalidArgumentException( 'A selected health check is not registered.' );
			}

			$selected_map[ $id ] = true;
		}

		$selected = array();
		foreach ( $this->registry->all() as $check ) {
			if ( isset( $selected_map[ $check->definition()->id() ] ) ) {
				$selected[] = $check;
			}
		}

		return $selected;
	}

	/**
	 * Convert elapsed wall time to a non-negative integer duration.
	 *
	 * @param float $started_at Start timestamp from microtime().
	 * @return int Duration in milliseconds.
	 */
	private static function duration_milliseconds( $started_at ) {
		return max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );
	}
}
