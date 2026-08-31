<?php
/**
 * Database charset and HealthLens schema check.
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
 * Inspects only fixed HealthLens tables and current-site charset metadata.
 */
final class DatabaseCharsetSchemaCheck implements HealthCheckInterface {
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
		return new CheckDefinition( 'database-charset-schema', 'database', 4, 86400 );
	}

	/**
	 * Run the fixed schema compatibility probe.
	 *
	 * @param CheckContext $context Execution context.
	 * @return CheckResult
	 */
	public function run( CheckContext $context ): CheckResult {
		if ( ! DatabaseScanSupport::available( $this->wpdb ) ) {
			return DatabaseScanSupport::result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING, 'database.metadata-unavailable', array( 'status' => 'unavailable' ) );
		}

		$schema   = new SchemaManager( $this->wpdb );
		$tables   = array( $schema->results_table(), $schema->incidents_table(), $schema->errors_table() );
		$missing  = 0;
		$required = array(
			$schema->results_table()   => array( 'check_id', 'context_json' ),
			$schema->incidents_table() => array( 'check_id', 'resolved_at' ),
			$schema->errors_table()    => array( 'event_code', 'context_json' ),
		);
		foreach ( $tables as $table ) {
			$columns = DatabaseScanSupport::columns( $this->wpdb, $table );
			$names   = array();
			foreach ( $columns as $column ) {
				if ( isset( $column['Field'] ) ) {
					$names[] = $column['Field'];
				}
			}
			foreach ( $required[ $table ] as $column ) {
				if ( ! in_array( $column, $names, true ) ) {
					++$missing;
				}
			}
		}

		$charset = DatabaseScanSupport::compatible_charset( $this->wpdb );
		if ( $missing > 0 || ! $charset ) {
			return DatabaseScanSupport::result(
				CheckResult::STATE_ISSUE,
				CheckResult::SEVERITY_CRITICAL,
				'database.schema-incompatible',
				array(
					'charset_compatible' => $charset,
					'missing_fields'     => $missing,
				)
			);
		}

		return DatabaseScanSupport::result(
			CheckResult::STATE_HEALTHY,
			CheckResult::SEVERITY_HEALTHY,
			'database.schema-compatible',
			array(
				'charset_compatible' => true,
				'missing_fields'     => 0,
			)
		);
	}
}
