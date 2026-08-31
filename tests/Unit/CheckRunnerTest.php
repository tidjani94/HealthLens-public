<?php
/**
 * @package HealthLens
 */

namespace HealthLens\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use HealthLens\Application\CheckRegistry;
use HealthLens\Application\CheckRunner;
use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckDefinition;
use HealthLens\Domain\CheckResult;
use HealthLens\Domain\HealthCheckInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckRunnerTest extends TestCase {
	public function test_registry_rejects_duplicate_ids_before_execution(): void {
		$registry = new CheckRegistry();
		$registry->register( $this->make_check( 'duplicate' ) );

		$this->expectException( InvalidArgumentException::class );
		$registry->register( $this->make_check( 'duplicate' ) );
	}

	public function test_registry_rejects_an_invalid_definition_before_execution(): void {
		$invalid_check = new class implements HealthCheckInterface {
			public function definition(): CheckDefinition {
				return new CheckDefinition( 'invalid id', 'test', 1, 900 );
			}

			public function run( CheckContext $context ): CheckResult {
				return new CheckResult(
					CheckResult::STATE_HEALTHY,
					CheckResult::SEVERITY_HEALTHY,
					'check-healthy',
					new CheckContext(),
					new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
					0
				);
			}
		};

		$this->expectException( InvalidArgumentException::class );
		( new CheckRegistry() )->register( $invalid_check );
	}

	public function test_runner_executes_selected_checks_once_in_deterministic_order(): void {
		$registry = new CheckRegistry();
		$registry->register( $this->make_check( 'zeta' ) );
		$registry->register( $this->make_check( 'alpha' ) );
		$registry->register( $this->make_check( 'middle' ) );

		$results = ( new CheckRunner( $registry ) )->run( new CheckContext(), array( 'zeta', 'alpha', 'zeta' ) );

		$this->assertSame( array( 'alpha', 'zeta' ), array_keys( $results ) );
		$this->assertSame( CheckResult::STATE_HEALTHY, $results['alpha']->state() );
		$this->assertSame( CheckResult::STATE_HEALTHY, $results['zeta']->state() );
		$this->assertIsInt( $results['alpha']->duration_milliseconds() );
		$this->assertGreaterThanOrEqual( 0, $results['alpha']->duration_milliseconds() );
	}

	public function test_runner_runs_all_registered_checks_in_registry_order_when_selection_is_empty(): void {
		$registry = new CheckRegistry();
		$registry->register( $this->make_check( 'zeta' ) );
		$registry->register( $this->make_check( 'alpha' ) );

		$results = ( new CheckRunner( $registry ) )->run( new CheckContext() );

		$this->assertSame( array( 'alpha', 'zeta' ), array_keys( $results ) );
	}

	public function test_runner_rejects_unknown_selected_id_before_execution(): void {
		$registry = new CheckRegistry();
		$registry->register( $this->make_check( 'known' ) );

		$this->expectException( InvalidArgumentException::class );
		( new CheckRunner( $registry ) )->run( new CheckContext(), array( 'missing' ) );
	}

	public function test_exception_is_normalized_and_later_checks_still_run(): void {
		$registry = new CheckRegistry();
		$registry->register( $this->make_check( 'first', static function () {
			throw new \RuntimeException( 'token=do-not-store ' . str_repeat( 'x', 20000 ) );
		} ) );
		$registry->register( $this->make_check( 'second' ) );

		$results = ( new CheckRunner( $registry ) )->run( new CheckContext() );

		$this->assertSame( CheckResult::STATE_UNKNOWN, $results['first']->state() );
		$this->assertSame( CheckResult::SEVERITY_WARNING, $results['first']->severity() );
		$this->assertSame( CheckRunner::FAILURE_MESSAGE_CODE, $results['first']->message_code() );
		$this->assertSame( array( 'check_id' => 'first' ), $results['first']->context()->to_array() );
		$this->assertLessThanOrEqual( CheckContext::MAX_SERIALIZED_BYTES, strlen( $results['first']->context()->to_json() ) );
		$this->assertSame( CheckResult::STATE_HEALTHY, $results['second']->state() );
	}

	public function test_error_is_normalized_and_later_checks_still_run(): void {
		$registry = new CheckRegistry();
		$registry->register( $this->make_check( 'first', static function () {
			throw new \Error( 'programming failure' );
		} ) );
		$registry->register( $this->make_check( 'second' ) );

		$results = ( new CheckRunner( $registry ) )->run( new CheckContext() );

		$this->assertSame( CheckResult::STATE_UNKNOWN, $results['first']->state() );
		$this->assertSame( CheckResult::STATE_HEALTHY, $results['second']->state() );
	}

	public function test_registry_returns_checks_in_stable_order(): void {
		$registry = new CheckRegistry();
		$registry->register( $this->make_check( 'zeta' ) );
		$registry->register( $this->make_check( 'alpha' ) );

		$this->assertSame( 'alpha', $registry->all()[0]->definition()->id() );
		$this->assertSame( 'zeta', $registry->get( 'zeta' )->definition()->id() );
		$this->assertTrue( $registry->has( 'alpha' ) );
	}

	/**
	 * Build a small fake check for pure runner tests.
	 *
	 * @param string        $id Check ID.
	 * @param callable|null $callback Optional callback that returns or throws.
	 * @return HealthCheckInterface
	 */
	private function make_check( $id, $callback = null ): HealthCheckInterface {
		return new class( $id, $callback ) implements HealthCheckInterface {
			/**
			 * @var CheckDefinition
			 */
			private $definition;

			/**
			 * @var callable|null
			 */
			private $callback;

			/**
			 * @param string        $id Check ID.
			 * @param callable|null $callback Optional callback.
			 */
			public function __construct( $id, $callback ) {
				$this->definition = new CheckDefinition( $id, 'test', 1, 900 );
				$this->callback   = $callback;
			}

			/**
			 * @return CheckDefinition
			 */
			public function definition(): CheckDefinition {
				return $this->definition;
			}

			/**
			 * @param CheckContext $context Execution context.
			 * @return CheckResult
			 */
			public function run( CheckContext $context ): CheckResult {
				if ( null !== $this->callback ) {
					return call_user_func( $this->callback );
				}

				return new CheckResult(
					CheckResult::STATE_HEALTHY,
					CheckResult::SEVERITY_HEALTHY,
					'check-healthy',
					new CheckContext(),
					new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
					0
				);
			}
		};
	}
}
