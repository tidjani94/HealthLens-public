<?php
/**
 * Health incident repository.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\Database;

use DateTimeImmutable;
use DateTimeZone;
use HealthLens\Domain\CheckDefinition;
use HealthLens\Domain\CheckResult;
use HealthLens\Domain\ContractValidator;
use HealthLens\Domain\IncidentTransition;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are generated from a validated site prefix; all data values use prepared placeholders.

/**
 * Stores continuous non-healthy periods without duplicate open incidents.
 */
final class IncidentRepository {
	/**
	 * WordPress database connection.
	 *
	 * @var object
	 */
	private $wpdb;

	/**
	 * Schema and validated table identifiers.
	 *
	 * @var SchemaManager
	 */
	private $schema;

	/**
	 * Create an incident repository.
	 *
	 * @param object        $wpdb WordPress database object.
	 * @param SchemaManager $schema Schema manager.
	 */
	public function __construct( $wpdb, SchemaManager $schema ) {
		$this->wpdb   = $wpdb;
		$this->schema = $schema;
	}

	/**
	 * Apply one result to the check's continuous incident period.
	 *
	 * @param CheckDefinition $definition Check definition.
	 * @param CheckResult     $result Normalized result.
	 * @return bool
	 */
	public function record( CheckDefinition $definition, CheckResult $result ) {
		$check_id = $definition->id();
		$open     = $this->get_open( $check_id );
		$action   = IncidentTransition::decide( is_array( $open ), $result );
		$time     = $this->format_timestamp( $result->checked_at() );
		$context  = ContextCodec::encode( $result->context() );

		if ( IncidentTransition::NONE === $action ) {
			return true;
		}

		if ( IncidentTransition::OPEN === $action ) {
			return false !== $this->wpdb->query(
				$this->wpdb->prepare(
					'INSERT INTO %i (check_id, severity, message_code, context_json, first_detected_at, last_detected_at, resolved_at) VALUES (%s, %s, %s, %s, %s, %s, NULL)',
					$this->schema->incidents_table(),
					$check_id,
					$result->severity(),
					$result->message_code(),
					$context,
					$time,
					$time
				)
			);
		}

		if ( IncidentTransition::UPDATE === $action ) {
			return false !== $this->wpdb->query(
				$this->wpdb->prepare(
					'UPDATE %i SET severity = %s, message_code = %s, context_json = %s, last_detected_at = %s WHERE id = %d AND resolved_at IS NULL',
					$this->schema->incidents_table(),
					$result->severity(),
					$result->message_code(),
					$context,
					$time,
					(int) $open['id']
				)
			);
		}

		return false !== $this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET resolved_at = %s WHERE id = %d AND resolved_at IS NULL',
				$this->schema->incidents_table(),
				$time,
				(int) $open['id']
			)
		);
	}

	/**
	 * Find the current open incident for a check.
	 *
	 * @param mixed $check_id Stable check identifier.
	 * @return array<string, mixed>|null
	 */
	public function get_open( $check_id ) {
		$check_id = ContractValidator::slug( $check_id, 'Check ID' );
		$row      = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT id, severity, message_code, context_json, first_detected_at, last_detected_at FROM %i WHERE check_id = %s AND resolved_at IS NULL ORDER BY id DESC LIMIT 1',
				$this->schema->incidents_table(),
				$check_id
			),
			'ARRAY_A'
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Read a deterministic, bounded set of open incidents.
	 *
	 * @param int $limit Maximum number of rows to read.
	 * @return array<int, array<string, mixed>>
	 */
	public function all_open( $limit = 50 ) {
		$limit = max( 1, min( 100, (int) $limit ) );
		$rows  = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT id, check_id, severity, message_code, first_detected_at, last_detected_at FROM %i WHERE resolved_at IS NULL ORDER BY check_id ASC, id DESC LIMIT %d',
				$this->schema->incidents_table(),
				$limit
			),
			'ARRAY_A'
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Read bounded recent incident history for the current site.
	 *
	 * @param DateTimeImmutable $before UTC retention cutoff.
	 * @param int               $limit Maximum number of rows to read.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent_history( DateTimeImmutable $before, $limit = 50 ) {
		$limit = max( 1, min( 100, (int) $limit ) );
		$rows  = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT id, check_id, severity, message_code, first_detected_at, last_detected_at, resolved_at FROM %i WHERE resolved_at IS NOT NULL AND resolved_at >= %s ORDER BY resolved_at DESC, id DESC LIMIT %d',
				$this->schema->incidents_table(),
				$this->format_timestamp( $before ),
				$limit
			),
			'ARRAY_A'
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete resolved incidents older than the retention cutoff.
	 *
	 * @param DateTimeImmutable $before UTC cutoff.
	 * @param int               $limit Maximum rows to delete.
	 * @return int
	 */
	public function delete_resolved( DateTimeImmutable $before, $limit = 100 ) {
		$limit = max( 1, min( 1000, (int) $limit ) );
		return (int) $this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE resolved_at IS NOT NULL AND resolved_at < %s LIMIT %d',
				$this->schema->incidents_table(),
				$this->format_timestamp( $before ),
				$limit
			)
		);
	}

	/**
	 * Format a timestamp for UTC database storage.
	 *
	 * @param DateTimeImmutable $timestamp Timestamp to format.
	 * @return string
	 */
	private function format_timestamp( DateTimeImmutable $timestamp ) {
		return $timestamp->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
