<?php
/**
 * Current-site REST API availability check.
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
use HealthLens\Infrastructure\WordPress\BoundedHttpProbe;
use HealthLens\Infrastructure\WordPress\ProbeResult;

/**
 * Checks availability and shape of the current site's REST API.
 */
final class RestApiAvailabilityCheck implements HealthCheckInterface {
	const CADENCE = 900;

	/** Bounded transport.
	 *
	 * @var BoundedHttpProbe
	 */
	private $probe;
	/** URL reader callback.
	 *
	 * @var callable|null
	 */
	private $url_reader;

	/** Create the check.
	 *
	 * @param BoundedHttpProbe $probe Bounded transport.
	 * @param callable|null    $url_reader URL reader double.
	 */
	public function __construct( BoundedHttpProbe $probe, $url_reader = null ) {
		$this->probe      = $probe;
		$this->url_reader = is_callable( $url_reader ) ? $url_reader : null;
	}

	/** Describe the check.
	 *
	 * @return CheckDefinition
	 */
	public function definition(): CheckDefinition {
		return new CheckDefinition( 'rest-api-availability', 'wordpress', 4, self::CADENCE );
	}

	/** Execute the check.
	 *
	 * @param CheckContext $context Execution context.
	 * @return CheckResult
	 */
	public function run( CheckContext $context ): CheckResult {
		$url   = $this->url_reader ? (string) call_user_func( $this->url_reader ) : ( function_exists( 'rest_url' ) ? (string) rest_url( '' ) : '' );
		$probe = $this->probe->probe( $url );

		if ( ! $probe->transport_ok() ) {
			return $this->result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING, 'wordpress.rest-' . $probe->error_code(), $this->context( $probe ) );
		}

		if ( $probe->status() < 200 || $probe->status() >= 300 ) {
			return $this->result( CheckResult::STATE_ISSUE, CheckResult::SEVERITY_WARNING, 'wordpress.rest-http-failure', $this->context( $probe ) );
		}

		$content_type = strtolower( $probe->content_type() );
		if ( '' !== $content_type && false === strpos( $content_type, 'json' ) ) {
			return $this->result( CheckResult::STATE_ISSUE, CheckResult::SEVERITY_WARNING, 'wordpress.rest-invalid-content-type', $this->context( $probe ) );
		}

		$decoded = json_decode( $probe->body(), true );
		if ( ! is_array( $decoded ) ) {
			return $this->result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING, 'wordpress.rest-malformed-response', $this->context( $probe ) );
		}

		return $this->result( CheckResult::STATE_HEALTHY, CheckResult::SEVERITY_HEALTHY, 'wordpress.rest-available', $this->context( $probe ) );
	}

	/** Build safe response context.
	 *
	 * @param ProbeResult $probe Normalized probe result.
	 * @return array<string, int>
	 */
	private function context( ProbeResult $probe ) {
		return array(
			'response_code' => $probe->status(),
			'elapsed_ms'    => $probe->elapsed_milliseconds(),
		);
	}

	/** Build a normalized result.
	 *
	 * @param string             $state Result state.
	 * @param string             $severity Result severity.
	 * @param string             $message_code Stable message code.
	 * @param array<string, int> $values Safe context values.
	 * @return CheckResult
	 */
	private function result( $state, $severity, $message_code, array $values ) {
		return new CheckResult( $state, $severity, $message_code, new CheckContext( $values ), new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ), 0 );
	}
}
