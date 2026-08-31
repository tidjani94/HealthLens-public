<?php
/**
 * Current-site HTTPS and TLS health check.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress\Checks;

use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckDefinition;
use HealthLens\Domain\CheckResult;
use HealthLens\Domain\HealthCheckInterface;
use HealthLens\Infrastructure\WordPress\BoundedHttpProbe;
use HealthLens\Infrastructure\WordPress\StorageScanSupport;

/**
 * Checks the canonical current-site URL through the existing verified probe.
 */
final class SslHttpsCheck implements HealthCheckInterface {
	/**
	 * Bounded current-site HTTP probe.
	 *
	 * @var BoundedHttpProbe
	 */
	private $probe;

	/**
	 * Create the check.
	 *
	 * @param BoundedHttpProbe|null $probe Bounded current-site probe.
	 */
	public function __construct( $probe = null ) {
		$this->probe = $probe instanceof BoundedHttpProbe ? $probe : new BoundedHttpProbe();
	}

	/**
	 * Return the check definition.
	 *
	 * @return CheckDefinition
	 */
	public function definition(): CheckDefinition {
		return new CheckDefinition( 'ssl-https', 'wordpress', 4, 86400 ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- The contract requires the lower-case WordPress category identifier.
	}

	/**
	 * Check the canonical current-site HTTPS endpoint.
	 *
	 * @param CheckContext $context Execution context.
	 * @return CheckResult
	 */
	public function run( CheckContext $context ): CheckResult {
		if ( ! function_exists( 'home_url' ) || ! function_exists( 'wp_parse_url' ) ) {
			return StorageScanSupport::result(
				CheckResult::STATE_UNKNOWN,
				CheckResult::SEVERITY_WARNING,
				'WordPress.ssl-unavailable',
				array( 'status' => 'unavailable' )
			);
		}
		$url    = home_url( '/' );
		$parts  = wp_parse_url( $url );
		$scheme = is_array( $parts ) && isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'unknown';
		if ( 'https' !== $scheme ) {
			return StorageScanSupport::result(
				CheckResult::STATE_ISSUE,
				CheckResult::SEVERITY_WARNING,
				'WordPress.ssl-http-configured',
				array(
					'scheme' => $scheme,
					'status' => 'http-configured',
				)
			);
		}

		$probe = $this->probe->probe( $url );
		if ( ! $probe->transport_ok() ) {
			return StorageScanSupport::result(
				CheckResult::STATE_UNKNOWN,
				CheckResult::SEVERITY_WARNING,
				'WordPress.ssl-transport-failed',
				array(
					'scheme'        => 'https',
					'status'        => 'transport-failed',
					'response_code' => $probe->status(),
				)
			);
		}
		$healthy = $probe->status() >= 200 && $probe->status() < 400;
		return StorageScanSupport::result(
			$healthy ? CheckResult::STATE_HEALTHY : CheckResult::STATE_ISSUE,
			$healthy ? CheckResult::SEVERITY_HEALTHY : CheckResult::SEVERITY_WARNING,
			$healthy ? 'WordPress.ssl-healthy' : 'WordPress.ssl-response-failed',
			array(
				'scheme'        => 'https',
				'status'        => $healthy ? 'verified' : 'response-failed',
				'response_code' => $probe->status(),
				'elapsed_ms'    => $probe->elapsed_milliseconds(),
			)
		);
	}
}
