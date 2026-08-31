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
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DomainContractsTest extends TestCase {
	public function test_definition_preserves_valid_contract_values(): void {
		$definition = new CheckDefinition( 'rest-api', 'wordpress', 5, 900 );

		$this->assertSame( 'rest-api', $definition->id() );
		$this->assertSame( 'wordpress', $definition->category() );
		$this->assertSame( 5, $definition->weight() );
		$this->assertSame( 900, $definition->cadence() );
	}

	/**
	 * @dataProvider invalid_definition_provider
	 *
	 * @param mixed  $id
	 * @param mixed  $category
	 * @param mixed  $weight
	 * @param mixed  $cadence
	 */
	public function test_definition_rejects_invalid_values( $id, $category, $weight, $cadence ): void {
		$this->expectException( InvalidArgumentException::class );

		new CheckDefinition( $id, $category, $weight, $cadence );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public function invalid_definition_provider(): array {
		return array(
			'invalid id'       => array( 'REST API', 'wordpress', 1, 900 ),
			'invalid category' => array( 'rest-api', 'WordPress', 1, 900 ),
			'weight too low'   => array( 'rest-api', 'wordpress', 0, 900 ),
			'weight too high'  => array( 'rest-api', 'wordpress', 6, 900 ),
			'cadence invalid'  => array( 'rest-api', 'wordpress', 1, 0 ),
		);
	}

	public function test_context_sorts_keys_and_removes_sensitive_values(): void {
		$context = new CheckContext(
			array(
				'z_status'  => 'ok',
				'password'  => 'not-retained',
				'a_count'   => 2,
				'api-token' => 'not-retained',
			)
		);

		$this->assertSame( array( 'a_count' => 2, 'z_status' => 'ok' ), $context->to_array() );
		$this->assertSame( '{"a_count":2,"z_status":"ok"}', $context->to_json() );
	}

	public function test_context_redacts_sensitive_values_even_when_keys_are_generic(): void {
		$context = new CheckContext(
			array(
				'endpoint'  => 'https://example.test/wp-json',
				'path_info' => 'C:\\private\\config.php',
				'diagnostic' => 'token=do-not-retain',
				'healthy'   => true,
			)
		);

		$this->assertSame( array( 'healthy' => true ), $context->to_array() );
	}

	public function test_context_rejects_non_scalar_values(): void {
		$this->expectException( InvalidArgumentException::class );

		new CheckContext( array( 'details' => array( 'nested' => true ) ) );
	}

	public function test_context_rejects_context_over_16_kibibytes(): void {
		$this->expectException( InvalidArgumentException::class );

		new CheckContext( array( 'diagnostic' => str_repeat( 'x', CheckContext::MAX_SERIALIZED_BYTES ) ) );
	}

	public function test_result_preserves_normalized_values_and_converts_time_to_utc(): void {
		$checked_at = new DateTimeImmutable( '2026-08-18 12:34:56+02:00' );
		$result     = new CheckResult(
			CheckResult::STATE_ISSUE,
			CheckResult::SEVERITY_WARNING,
			'wp-cron-delayed',
			new CheckContext( array( 'lag_seconds' => 120 ) ),
			$checked_at,
			35
		);

		$this->assertSame( CheckResult::STATE_ISSUE, $result->state() );
		$this->assertSame( CheckResult::SEVERITY_WARNING, $result->severity() );
		$this->assertSame( 'wp-cron-delayed', $result->message_code() );
		$this->assertSame( 'UTC', $result->checked_at()->getTimezone()->getName() );
		$this->assertSame( '2026-08-18T10:34:56+00:00', $result->checked_at()->format( DATE_ATOM ) );
		$this->assertSame( 35, $result->duration_milliseconds() );
	}

	/**
	 * @dataProvider invalid_result_provider
	 *
	 * @param mixed $state
	 * @param mixed $severity
	 */
	public function test_result_rejects_invalid_state_and_severity_combinations( $state, $severity ): void {
		$this->expectException( InvalidArgumentException::class );

		new CheckResult(
			$state,
			$severity,
			'check-result',
			new CheckContext(),
			new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
			0
		);
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function invalid_result_provider(): array {
		return array(
			'healthy warning' => array( CheckResult::STATE_HEALTHY, CheckResult::SEVERITY_WARNING ),
			'issue healthy'   => array( CheckResult::STATE_ISSUE, CheckResult::SEVERITY_HEALTHY ),
			'unknown info'    => array( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_INFO ),
		);
	}
}
