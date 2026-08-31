<?php
/**
 * Database storage-growth health check.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress\Checks;

use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckDefinition;
use HealthLens\Domain\CheckResult;
use HealthLens\Domain\HealthCheckInterface;
use HealthLens\Infrastructure\Database\SchemaManager;
use HealthLens\Infrastructure\WordPress\DatabaseScanSupport;

/**
 * Aggregates size metadata for the three fixed HealthLens tables only.
 */
final class DatabaseStorageGrowthCheck implements HealthCheckInterface {
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
		return new CheckDefinition( 'database-storage-growth', 'database', 3, 86400 );
	}

	/**
	 * Run the fixed storage metadata probe.
	 *
	 * @param CheckContext $context Execution context.
	 * @return CheckResult
	 */
	public function run( CheckContext $context ): CheckResult {
		if ( ! DatabaseScanSupport::available( $this->wpdb ) ) {
			return DatabaseScanSupport::result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING, 'database.storage-unavailable', array( 'status' => 'unavailable' ) );
		}
		$schema      = new SchemaManager( $this->wpdb );
		$total_rows  = 0;
		$total_bytes = 0;
		foreach ( array( $schema->results_table(), $schema->incidents_table(), $schema->errors_table() ) as $table ) {
			$status = DatabaseScanSupport::table_status( $this->wpdb, $table );
			if ( empty( $status ) ) {
				return DatabaseScanSupport::result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING, 'database.storage-metadata-failed', array( 'status' => 'metadata-failed' ) );
			}
			$total_rows  += isset( $status['Rows'] ) ? (int) $status['Rows'] : 0;
			$total_bytes += isset( $status['Data_length'] ) ? (int) $status['Data_length'] : 0;
		}
		$warning = $total_rows > DatabaseScanSupport::STORAGE_WARNING_ROWS || $total_bytes > DatabaseScanSupport::STORAGE_WARNING_BYTES;
		return DatabaseScanSupport::result(
			$warning ? CheckResult::STATE_ISSUE : CheckResult::STATE_HEALTHY,
			$warning ? CheckResult::SEVERITY_WARNING : CheckResult::SEVERITY_HEALTHY,
			$warning ? 'database.storage-large' : 'database.storage-bounded',
			array(
				'rows_bucket'     => DatabaseScanSupport::bucket( $total_rows, 100 ),
				'bytes_bucket'    => DatabaseScanSupport::bucket( $total_bytes, 1048576 ),
				'threshold_state' => $warning ? 'over-threshold' : 'within-threshold',
			)
		);
	}
}
