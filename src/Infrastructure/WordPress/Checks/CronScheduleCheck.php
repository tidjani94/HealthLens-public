<?php
/**
 * HealthLens WP-Cron schedule check.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress\Checks;

// phpcs:disable WordPress.WP.CapitalPDangit.MisspelledInText -- Machine-readable message codes use a lower-case namespace.

use DateTimeImmutable;
use DateTimeZone;
use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckDefinition;
use HealthLens\Domain\CheckResult;
use HealthLens\Domain\HealthCheckInterface;
use HealthLens\Infrastructure\WordPress\CronScheduler;

/**
 * Checks the current site's HealthLens WP-Cron event.
 */
final class CronScheduleCheck implements HealthCheckInterface {
	const CADENCE       = 900;
	const GRACE_SECONDS = 3600;

	/** Event reader callback.
	 *
	 * @var callable|null
	 */
	private $events_reader;
	/** Clock callback.
	 *
	 * @var callable|null
	 */
	private $time_reader;

	/** Describe the check.
	 *
	 * @param callable|null $events_reader Event reader double.
	 * @param callable|null $time_reader Clock double.
	 */
	public function __construct( $events_reader = null, $time_reader = null ) {
		$this->events_reader = is_callable( $events_reader ) ? $events_reader : null;
		$this->time_reader   = is_callable( $time_reader ) ? $time_reader : null;
	}

	/** Execute the check.
	 *
	 * @return CheckDefinition
	 */
	public function definition(): CheckDefinition {
		return new CheckDefinition( 'wp-cron-schedule', 'wordpress', 5, self::CADENCE );
	}

	/** Execute the check.
	 *
	 * @param CheckContext $context Execution context.
	 * @return CheckResult
	 */
	public function run( CheckContext $context ): CheckResult {
		$events = $this->read_events();
		$now    = $this->time_reader ? (int) call_user_func( $this->time_reader ) : time();
		$base   = array( 'event_count' => count( $events ) );

		if ( empty( $events ) ) {
			return $this->result( CheckResult::STATE_ISSUE, CheckResult::SEVERITY_CRITICAL, 'wordpress.cron-missing', $base + array( 'schedule_state' => 'missing' ) );
		}

		if ( count( $events ) > 1 ) {
			return $this->result( CheckResult::STATE_ISSUE, CheckResult::SEVERITY_WARNING, 'wordpress.cron-duplicate', $base + array( 'schedule_state' => 'duplicate' ) );
		}

		$event = $events[0];
		if ( ! empty( $event['has_args'] ) ) {
			return $this->result( CheckResult::STATE_ISSUE, CheckResult::SEVERITY_CRITICAL, 'wordpress.cron-arguments-invalid', $base + array( 'schedule_state' => 'arguments-invalid' ) );
		}

		$timestamp = isset( $event['timestamp'] ) ? (int) $event['timestamp'] : 0;
		$lateness  = $now - $timestamp;
		if ( $timestamp <= 0 || $lateness > self::GRACE_SECONDS ) {
			return $this->result(
				CheckResult::STATE_ISSUE,
				CheckResult::SEVERITY_WARNING,
				'wordpress.cron-overdue',
				$base + array(
					'schedule_state'   => 'overdue',
					'lateness_seconds' => max( 0, $lateness ),
				)
			);
		}

		return $this->result( CheckResult::STATE_HEALTHY, CheckResult::SEVERITY_HEALTHY, 'wordpress.cron-healthy', $base + array( 'schedule_state' => 'scheduled' ) );
	}

	/** Read scheduled HealthLens events.
	 *
	 * @return array<int, array<string, bool|int>>
	 */
	private function read_events() {
		if ( $this->events_reader ) {
			$events = call_user_func( $this->events_reader );
			return is_array( $events ) ? $events : array();
		}

		$events = array();
		if ( function_exists( '_get_cron_array' ) ) {
			$cron = (array) _get_cron_array();
			foreach ( $cron as $timestamp => $hooks ) {
				if ( ! isset( $hooks[ CronScheduler::HOOK ] ) || ! is_array( $hooks[ CronScheduler::HOOK ] ) ) {
					continue;
				}
				foreach ( $hooks[ CronScheduler::HOOK ] as $event ) {
					$events[] = array(
						'timestamp' => (int) $timestamp,
						'has_args'  => ! empty( $event['args'] ),
					);
				}
			}
		}

		if ( empty( $events ) && function_exists( 'wp_next_scheduled' ) ) {
			$timestamp = wp_next_scheduled( CronScheduler::HOOK );
			if ( false !== $timestamp ) {
				$events[] = array(
					'timestamp' => (int) $timestamp,
					'has_args'  => false,
				);
			}
		}

		return $events;
	}

	/** Build a normalized result.
	 *
	 * @param string                         $state Result state.
	 * @param string                         $severity Result severity.
	 * @param string                         $message_code Stable message code.
	 * @param array<string, bool|int|string> $values Safe context values.
	 * @return CheckResult
	 */
	private function result( $state, $severity, $message_code, array $values ) {
		return new CheckResult( $state, $severity, $message_code, new CheckContext( $values ), new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ), 0 );
	}
}
