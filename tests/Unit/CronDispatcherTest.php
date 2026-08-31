<?php
/**
 * @package HealthLens
 */

namespace HealthLens\Tests\Unit;

use DateTimeImmutable;
use HealthLens\Application\CheckDispatcher;
use HealthLens\Application\CheckRegistry;
use HealthLens\Application\CheckRunner;
use HealthLens\Application\ClockInterface;
use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckDefinition;
use HealthLens\Domain\CheckResult;
use HealthLens\Domain\HealthCheckInterface;
use HealthLens\Infrastructure\WordPress\CronScheduler;
use HealthLens\Infrastructure\WordPress\ActionSchedulerAdapter;
use HealthLens\Infrastructure\WordPress\OptionLock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CronDispatcherTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['healthlens_test_options']            = array();
		$GLOBALS['healthlens_test_autoload']           = array();
		$GLOBALS['healthlens_test_scheduled_events']   = array();
		$GLOBALS['healthlens_test_cleared_hooks']      = array();
		$GLOBALS['healthlens_test_did_actions']        = array();
		$GLOBALS['healthlens_test_action_scheduler_scheduled'] = array();
		$GLOBALS['healthlens_test_action_scheduler_unscheduled'] = array();
	}

	public function test_scheduler_is_idempotent_and_uses_fifteen_minute_interval(): void {
		$scheduler = new CronScheduler();
		$schedules = $scheduler->add_interval( array() );

		$this->assertSame( CronScheduler::INTERVAL, $schedules[ CronScheduler::RECURRENCE ]['interval'] );
		$scheduler->schedule();
		$first = $GLOBALS['healthlens_test_scheduled_events'];
		$scheduler->schedule();

		$this->assertSame( $first, $GLOBALS['healthlens_test_scheduled_events'] );
	}

	public function test_action_scheduler_is_optional_and_owns_one_logical_event_when_initialized(): void {
		$GLOBALS['healthlens_test_did_actions']['action_scheduler_init'] = 1;
		$scheduler = new CronScheduler();

		$scheduler->schedule();

		$this->assertTrue( ActionSchedulerAdapter::available() );
		$this->assertSame( CronScheduler::HOOK, $GLOBALS['healthlens_test_action_scheduler_scheduled']['hook'] );
		$this->assertSame( array(), $GLOBALS['healthlens_test_scheduled_events'] );

		$scheduler->unschedule();
		$this->assertNotEmpty( $GLOBALS['healthlens_test_action_scheduler_unscheduled'] );
	}

	public function test_option_lock_does_not_overwrite_held_lock_and_recovers_expiry(): void {
		$lock  = new OptionLock( 'healthlens_lock' );
		$first = $lock->acquire( 20 );

		$this->assertIsString( $first );
		$this->assertFalse( $lock->acquire( 20 ) );
		$this->assertSame( false, $GLOBALS['healthlens_test_autoload']['healthlens_lock'] );

		$GLOBALS['healthlens_test_options']['healthlens_lock']['expires_at'] = time() - 1;
		$second = $lock->acquire( 20 );
		$this->assertIsString( $second );
		$this->assertNotSame( $first, $second );
		$this->assertFalse( $lock->release( $first ) );
		$this->assertTrue( $lock->release( $second ) );
	}

	public function test_dispatcher_runs_at_most_five_due_checks(): void {
		$runs     = 0;
		$registry = new CheckRegistry();
		for ( $index = 1; $index <= 6; $index++ ) {
			$registry->register( $this->check( 'check-' . $index, $runs ) );
		}

		$dispatcher = new CheckDispatcher(
			$registry,
			new CheckRunner( $registry ),
			new OptionLock( 'healthlens_lock' ),
			new FakeClock()
		);
		$results = $dispatcher->dispatch();

		$this->assertCount( CheckDispatcher::MAX_CHECKS, $results );
		$this->assertSame( CheckDispatcher::MAX_CHECKS, $runs );
		$this->assertArrayNotHasKey( 'healthlens_lock', $GLOBALS['healthlens_test_options'] );
	}

	public function test_dispatcher_preserves_later_checks_after_wall_clock_budget(): void {
		$runs     = 0;
		$clock    = new FakeClock();
		$registry = new CheckRegistry();
		$registry->register( $this->check( 'first', $runs, false, $clock ) );
		$registry->register( $this->check( 'second', $runs ) );

		$results = ( new CheckDispatcher(
			$registry,
			new CheckRunner( $registry ),
			new OptionLock( 'healthlens_lock' ),
			$clock
		) )->dispatch();

		$this->assertCount( 1, $results );
		$this->assertSame( 1, $runs );
	}

	public function test_thrown_check_releases_lock_and_later_check_runs(): void {
		$runs     = 0;
		$registry = new CheckRegistry();
		$registry->register( $this->check( 'failing', $runs, true ) );
		$registry->register( $this->check( 'later', $runs ) );

		$results = ( new CheckDispatcher(
			$registry,
			new CheckRunner( $registry ),
			new OptionLock( 'healthlens_lock' ),
			new FakeClock()
		) )->dispatch();

		$this->assertSame( 2, $runs );
		$this->assertSame( CheckResult::STATE_UNKNOWN, $results['failing']->state() );
		$this->assertArrayHasKey( 'later', $results );
		$this->assertArrayNotHasKey( 'healthlens_lock', $GLOBALS['healthlens_test_options'] );
	}

	public function test_held_lock_skips_dispatch_without_overwriting_owner(): void {
		$runs = 0;
		$GLOBALS['healthlens_test_options']['healthlens_lock'] = array(
			'token'      => 'other-owner',
			'expires_at' => time() + 60,
		);
		$registry = new CheckRegistry();
		$registry->register( $this->check( 'check', $runs ) );

		$results = ( new CheckDispatcher(
			$registry,
			new CheckRunner( $registry ),
			new OptionLock( 'healthlens_lock' ),
			new FakeClock()
		) )->dispatch();

		$this->assertSame( array(), $results );
		$this->assertSame( 'other-owner', $GLOBALS['healthlens_test_options']['healthlens_lock']['token'] );
		$this->assertSame( 0, $runs );
	}

	/**
	 * @param string     $id Check identifier.
	 * @param int        $runs Run counter.
	 * @param bool       $throws Whether to throw.
	 * @param FakeClock|null $clock Clock to advance.
	 * @return HealthCheckInterface
	 */
	private function check( $id, &$runs, $throws = false, $clock = null ) {
		return new class( $id, $runs, $throws, $clock ) implements HealthCheckInterface {
			private $id;
			private $runs;
			private $throws;
			private $clock;

			public function __construct( $id, &$runs, $throws, $clock ) {
				$this->id     = $id;
				$this->runs   =& $runs;
				$this->throws = $throws;
				$this->clock  = $clock;
			}

			public function definition(): CheckDefinition {
				return new CheckDefinition( $this->id, 'test', 1, 900 );
			}

			public function run( CheckContext $context ): CheckResult {
				++$this->runs;
				if ( $this->clock ) {
					$this->clock->advance( 16.0 );
				}

				if ( $this->throws ) {
					throw new RuntimeException( 'fixture failure' );
				}

				return new CheckResult(
					CheckResult::STATE_HEALTHY,
					CheckResult::SEVERITY_HEALTHY,
					$this->id . '-healthy',
					new CheckContext(),
					new DateTimeImmutable( '2026-08-18 12:00:00 UTC' ),
					0
				);
			}
		};
	}
}

final class FakeClock implements ClockInterface {
	private $seconds;

	public function __construct( $seconds = 100.0 ) {
		$this->seconds = $seconds;
	}

	public function now() {
		return new DateTimeImmutable( '@' . (int) $this->seconds );
	}

	public function microtime() {
		return $this->seconds;
	}

	public function advance( $seconds ) {
		$this->seconds += $seconds;
	}
}
