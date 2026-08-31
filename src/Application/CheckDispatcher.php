<?php
/**
 * Bounded due-check dispatcher.
 *
 * @package HealthLens
 */

namespace HealthLens\Application;

use HealthLens\Domain\CheckContext;
use HealthLens\Application\ErrorCapture\ErrorEventCollectorInterface;
use HealthLens\Infrastructure\Database\IncidentRepository;
use HealthLens\Infrastructure\Database\ResultRepository;
use HealthLens\Infrastructure\WordPress\OptionLock;
use Throwable;

/**
 * Runs due checks from a single locked WP-Cron invocation.
 */
final class CheckDispatcher {
	/** Maximum number of checks per invocation. */
	const MAX_CHECKS = 5;
	/** Maximum wall-clock duration per invocation, in seconds. */
	const MAX_SECONDS = 15.0;
	/** Lock lifetime, allowing a later invocation to recover stale work. */
	const LOCK_TTL_SECONDS = 20;

	/**
	 * Registered checks.
	 *
	 * @var CheckRegistry
	 */
	private $registry;

	/**
	 * Check runner.
	 *
	 * @var CheckRunner
	 */
	private $runner;

	/**
	 * Per-site lock.
	 *
	 * @var OptionLock
	 */
	private $lock;

	/**
	 * Clock fixture or system clock.
	 *
	 * @var ClockInterface
	 */
	private $clock;

	/**
	 * Current-result repository.
	 *
	 * @var ResultRepository|null
	 */
	private $results;

	/**
	 * Incident repository.
	 *
	 * @var IncidentRepository|null
	 */
	private $incidents;

	/**
	 * Bounded execution context.
	 *
	 * @var CheckContext
	 */
	private $context;

	/**
	 * Optional side-effect-only error collector.
	 *
	 * @var ErrorEventCollectorInterface|null
	 */
	private $error_collector;

	/**
	 * Create a bounded dispatcher.
	 *
	 * @param CheckRegistry                     $registry Registered checks.
	 * @param CheckRunner                       $runner Check runner.
	 * @param OptionLock                        $lock Per-site lock.
	 * @param ClockInterface                    $clock Clock fixture or system clock.
	 * @param ResultRepository|null             $results Current-result repository.
	 * @param IncidentRepository|null           $incidents Incident repository.
	 * @param CheckContext|null                 $context Execution context.
	 * @param ErrorEventCollectorInterface|null $error_collector Optional collector.
	 */
	public function __construct( CheckRegistry $registry, CheckRunner $runner, OptionLock $lock, ClockInterface $clock, $results = null, $incidents = null, $context = null, $error_collector = null ) {
		$this->registry        = $registry;
		$this->runner          = $runner;
		$this->lock            = $lock;
		$this->clock           = $clock;
		$this->results         = $results;
		$this->incidents       = $incidents;
		$this->context         = $context instanceof CheckContext ? $context : new CheckContext();
		$this->error_collector = $error_collector instanceof ErrorEventCollectorInterface ? $error_collector : null;
	}

	/**
	 * Dispatch due checks under the per-site execution budgets.
	 *
	 * Checks not reached because of either budget remain due for a later run.
	 * A per-check Throwable or persistence error is isolated so later checks
	 * remain eligible, while the finally block always releases the lock.
	 *
	 * @return array<string, \HealthLens\Domain\CheckResult> Results completed in this invocation.
	 */
	public function dispatch() {
		$token = $this->lock->acquire( self::LOCK_TTL_SECONDS );
		if ( false === $token ) {
			return array();
		}

		$started_at = $this->clock->microtime();
		$executed   = 0;
		$results    = array();

		try {
			foreach ( $this->registry->all() as $check ) {
				if ( $executed >= self::MAX_CHECKS || $this->elapsed( $started_at ) >= self::MAX_SECONDS ) {
					break;
				}

				try {
					$definition = $check->definition();
					if ( ! $this->is_due( $definition->id(), $definition->cadence() ) ) {
						continue;
					}

					++$executed;
					$batch = $this->runner->run( $this->context, array( $definition->id() ) );
					if ( ! isset( $batch[ $definition->id() ] ) ) {
						continue;
					}

					$result                       = $batch[ $definition->id() ];
					$results[ $definition->id() ] = $result;
					$this->persist( $definition, $result );
				} catch ( Throwable $throwable ) {
					if ( $this->error_collector ) {
						$this->error_collector->capture_throwable( $throwable, 'healthlens', 'dispatcher', 'healthlens.dispatcher.failed' );
					}
					// Keep the exception bounded to this check; later checks remain due.
					continue;
				}
			}

			return $results;
		} finally {
			$this->lock->release( $token );
		}
	}

	/**
	 * Determine whether the stored result is outside its cadence.
	 *
	 * @param string $check_id Check identifier.
	 * @param int    $cadence Minimum interval in seconds.
	 * @return bool
	 */
	private function is_due( $check_id, $cadence ) {
		if ( ! $this->results ) {
			return true;
		}

		$current = $this->results->get( $check_id );
		if ( null === $current ) {
			return true;
		}

		$due_at = $current->checked_at()->getTimestamp() + $cadence;
		return $due_at <= $this->clock->now()->getTimestamp();
	}

	/**
	 * Persist a normalized result and its incident transition.
	 *
	 * @param \HealthLens\Domain\CheckDefinition $definition Check definition.
	 * @param \HealthLens\Domain\CheckResult     $result Check result.
	 * @return void
	 */
	private function persist( $definition, $result ) {
		if ( $this->results ) {
			$this->results->save( $definition, $result );
		}

		if ( $this->incidents ) {
			$this->incidents->record( $definition, $result );
		}
	}

	/**
	 * Return elapsed wall time for this invocation.
	 *
	 * @param float $started_at Starting clock reading.
	 * @return float
	 */
	private function elapsed( $started_at ) {
		return max( 0.0, $this->clock->microtime() - $started_at );
	}
}
