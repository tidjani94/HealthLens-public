<?php
/**
 * Database connectivity health check.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress\Checks;

use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckDefinition;
use HealthLens\Domain\CheckResult;
use HealthLens\Domain\HealthCheckInterface;
use HealthLens\Infrastructure\WordPress\DatabaseScanSupport;

/**
 * Executes one fixed read-only query and retains only aggregate metadata.
 */
final class DatabaseConnectivityCheck implements HealthCheckInterface {
	/**
	 * WordPress database connection.
	 *
	 * @var object|null
	 */
	private $wpdb;

	/**
	 * Create the check.
	 *
	 * @param mixed $wpdb WordPress database object.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb = is_object( $wpdb ) ? $wpdb : null;
	}

	/**
	 * Return the check definition.
	 *
	 * @return CheckDefinition
	 */
	public function definition(): CheckDefinition {
		return new CheckDefinition( 'database-connectivity', 'database', 5, 900 );
	}

	/**
	 * Run the bounded connection probe.
	 *
	 * @param CheckContext $context Execution context.
	 * @return CheckResult
	 */
	public function run( CheckContext $context ): CheckResult {
		$started = microtime( true );
		if ( ! DatabaseScanSupport::available( $this->wpdb ) ) {
			return DatabaseScanSupport::result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING, 'database.unavailable', array( 'status' => 'unavailable' ) );
		}

		try {
			$value   = $this->wpdb->get_var( 'SELECT 1' );
			$elapsed = DatabaseScanSupport::elapsed( $started );
			$family  = method_exists( $this->wpdb, 'db_version' ) ? DatabaseScanSupport::version_family( $this->wpdb->db_version() ) : 'unknown';
			if ( 1 !== (int) $value || $elapsed > DatabaseScanSupport::MAX_MILLISECONDS ) {
				return DatabaseScanSupport::result(
					CheckResult::STATE_UNKNOWN,
					CheckResult::SEVERITY_WARNING,
					'database.probe-timeout',
					array(
						'status'         => 'timeout',
						'version_family' => $family,
						'elapsed_ms'     => $elapsed,
					)
				);
			}

			return DatabaseScanSupport::result(
				CheckResult::STATE_HEALTHY,
				CheckResult::SEVERITY_HEALTHY,
				'database.connected',
				array(
					'status'         => 'connected',
					'version_family' => $family,
					'elapsed_ms'     => $elapsed,
				)
			);
		} catch ( \Throwable $throwable ) {
			return DatabaseScanSupport::result(
				CheckResult::STATE_UNKNOWN,
				CheckResult::SEVERITY_WARNING,
				'database.query-failed',
				array(
					'status'     => 'query-failed',
					'elapsed_ms' => DatabaseScanSupport::elapsed( $started ),
				)
			);
		}
	}
}
