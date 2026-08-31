<?php
/**
 * Autoloaded-options health check.
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
 * Measures aggregate autoloaded option size without retaining names or values.
 */
final class AutoloadedOptionsCheck implements HealthCheckInterface {
	/**
	 * Return the check definition.
	 *
	 * @return CheckDefinition
	 */
	public function definition(): CheckDefinition {
		return new CheckDefinition( 'autoloaded-options', 'database', 4, 86400 );
	}

	/**
	 * Measure aggregate autoloaded option data.
	 *
	 * @param CheckContext $context Execution context.
	 * @return CheckResult
	 */
	public function run( CheckContext $context ): CheckResult {
		$started = microtime( true );
		if ( ! function_exists( 'wp_load_alloptions' ) || ! function_exists( 'maybe_serialize' ) ) {
			return DatabaseScanSupport::result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING, 'database.autoload-unavailable', array( 'status' => 'unavailable' ) );
		}

		try {
			$options = wp_load_alloptions();
			$count   = 0;
			$bytes   = 0;
			foreach ( $options as $value ) {
				++$count;
				$bytes += strlen( (string) maybe_serialize( $value ) );
				if ( $count > 2000 || DatabaseScanSupport::elapsed( $started ) > DatabaseScanSupport::MAX_MILLISECONDS ) {
					return DatabaseScanSupport::result(
						CheckResult::STATE_UNKNOWN,
						CheckResult::SEVERITY_WARNING,
						'database.autoload-budget',
						array(
							'status'       => 'budget-aborted',
							'count_bucket' => DatabaseScanSupport::bucket( $count, 100 ),
							'bytes_bucket' => DatabaseScanSupport::bucket( $bytes, 100000 ),
						)
					);
				}
			}
			$warning = $bytes > DatabaseScanSupport::AUTOLOAD_WARNING_BYTES || $count > DatabaseScanSupport::AUTOLOAD_WARNING_COUNT;
			return DatabaseScanSupport::result(
				$warning ? CheckResult::STATE_ISSUE : CheckResult::STATE_HEALTHY,
				$warning ? CheckResult::SEVERITY_WARNING : CheckResult::SEVERITY_HEALTHY,
				$warning ? 'database.autoload-large' : 'database.autoload-bounded',
				array(
					'count_bucket'    => DatabaseScanSupport::bucket( $count, 100 ),
					'bytes_bucket'    => DatabaseScanSupport::bucket( $bytes, 100000 ),
					'threshold_state' => $warning ? 'over-threshold' : 'within-threshold',
					'elapsed_ms'      => DatabaseScanSupport::elapsed( $started ),
				)
			);
		} catch ( \Throwable $throwable ) {
			return DatabaseScanSupport::result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING, 'database.autoload-failed', array( 'status' => 'failed' ) );
		}
	}
}
