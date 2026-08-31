<?php
/**
 * Administrator email configuration check.
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

/**
 * Checks the current site's administrator email configuration.
 */
final class AdministratorEmailCheck implements HealthCheckInterface {
	/** Number of seconds between checks. */
	const CADENCE = 86400;

	/** Email reader callback.
	 *
	 * @var callable|null
	 */
	private $email_reader;
	/** Email validator callback.
	 *
	 * @var callable|null
	 */
	private $validator;

	/** Create the check.
	 *
	 * @param callable|null $email_reader Email reader double.
	 * @param callable|null $validator Email validator double.
	 */
	public function __construct( $email_reader = null, $validator = null ) {
		$this->email_reader = is_callable( $email_reader ) ? $email_reader : null;
		$this->validator    = is_callable( $validator ) ? $validator : null;
	}

	/** Create the check.
	 *
	 * @return CheckDefinition
	 */
	public function definition(): CheckDefinition {
		return new CheckDefinition( 'administrator-email', 'wordpress', 2, self::CADENCE );
	}

	/** Execute the check.
	 *
	 * @param CheckContext $context Execution context.
	 * @return CheckResult
	 */
	public function run( CheckContext $context ): CheckResult {
		$email      = $this->email_reader ? (string) call_user_func( $this->email_reader ) : ( function_exists( 'get_option' ) ? (string) get_option( 'admin_email', '' ) : '' );
		$is_valid   = $this->validator ? (bool) call_user_func( $this->validator, $email ) : ( function_exists( 'is_email' ) && false !== is_email( $email ) );
		$configured = '' !== trim( $email );

		if ( $is_valid ) {
			return $this->result(
				CheckResult::STATE_HEALTHY,
				CheckResult::SEVERITY_HEALTHY,
				'wordpress.administrator-email-valid',
				array(
					'configured' => true,
					'valid'      => true,
					'state'      => 'valid',
				)
			);
		}

		return $this->result(
			CheckResult::STATE_ISSUE,
			CheckResult::SEVERITY_WARNING,
			$configured ? 'wordpress.administrator-email-invalid' : 'wordpress.administrator-email-missing',
			array(
				'configured' => $configured,
				'valid'      => false,
				'state'      => $configured ? 'invalid' : 'missing',
			)
		);
	}

	/** Build a normalized result.
	 *
	 * @param string                     $state Result state.
	 * @param string                     $severity Result severity.
	 * @param string                     $message_code Stable message code.
	 * @param array<string, bool|string> $values Safe context values.
	 * @return CheckResult
	 */
	private function result( $state, $severity, $message_code, array $values ) {
		return new CheckResult( $state, $severity, $message_code, new CheckContext( $values ), new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ), 0 );
	}
}
