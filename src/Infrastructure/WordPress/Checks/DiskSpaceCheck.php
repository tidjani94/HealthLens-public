<?php
/**
 * Disk space and temporary storage health check.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress\Checks;

use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckDefinition;
use HealthLens\Domain\CheckResult;
use HealthLens\Infrastructure\WordPress\StorageScanSupport;

/**
 * Uses bounded platform capacity APIs without recursion or writes.
 */
final class DiskSpaceCheck implements \HealthLens\Domain\HealthCheckInterface {
	/**
	 * Return the check definition.
	 *
	 * @return CheckDefinition
	 */
	public function definition(): CheckDefinition {
		return new CheckDefinition( 'disk-space', 'storage', 3, 86400 );
	}

	/**
	 * Read aggregate disk capacity metadata.
	 *
	 * @param CheckContext $context Execution context.
	 * @return CheckResult
	 */
	public function run( CheckContext $context ): CheckResult {
		$root = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ( function_exists( 'sys_get_temp_dir' ) ? sys_get_temp_dir() : '' );
		if ( '' === $root || ! function_exists( 'disk_free_space' ) || ! function_exists( 'disk_total_space' ) ) {
			return StorageScanSupport::result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING, 'storage.disk-unavailable', array( 'status' => 'unavailable' ) );
		}
		$free  = @disk_free_space( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unsupported or inaccessible hosts must degrade to unknown without emitting a path-bearing warning.
		$total = @disk_total_space( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unsupported or inaccessible hosts must degrade to unknown without emitting a path-bearing warning.
		if ( false === $free || false === $total || (float) $total <= 0 ) {
			return StorageScanSupport::result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING, 'storage.disk-unavailable', array( 'status' => 'unavailable' ) );
		}
		$ratio   = (float) $free / (float) $total;
		$warning = $ratio < StorageScanSupport::MIN_FREE_RATIO;
		return StorageScanSupport::result(
			$warning ? CheckResult::STATE_ISSUE : CheckResult::STATE_HEALTHY,
			$warning ? CheckResult::SEVERITY_WARNING : CheckResult::SEVERITY_HEALTHY,
			$warning ? 'storage.disk-low' : 'storage.disk-healthy',
			array(
				'free_bytes_bucket'  => StorageScanSupport::bucket( $free ),
				'total_bytes_bucket' => StorageScanSupport::bucket( $total ),
				'threshold_state'    => $warning ? 'low' : 'healthy',
			)
		);
	}
}
