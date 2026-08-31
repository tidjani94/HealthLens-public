<?php
/**
 * Current health-result repository.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\Database;

use DateTimeImmutable;
use DateTimeZone;
use HealthLens\Domain\CheckDefinition;
use HealthLens\Domain\CheckResult;
use HealthLens\Domain\ContractValidator;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are generated from a validated site prefix; all data values use prepared placeholders.

/**
 * Stores one current normalized result per check.
 */
final class ResultRepository {
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
	 * Create a current-result repository.
	 *
	 * @param object        $wpdb WordPress database object.
	 * @param SchemaManager $schema Schema manager.
	 */
	public function __construct( $wpdb, SchemaManager $schema ) {
		$this->wpdb   = $wpdb;
		$this->schema = $schema;
	}

	/**
	 * Insert or replace the current result for a check.
	 *
	 * @param CheckDefinition $definition Check definition.
	 * @param CheckResult     $result Normalized result.
	 * @return bool
	 */
	public function save( CheckDefinition $definition, CheckResult $result ) {
		return false !== $this->wpdb->query(
			$this->wpdb->prepare(
				'INSERT INTO %i (check_id, category, state, severity, message_code, context_json, checked_at, duration_ms) VALUES (%s, %s, %s, %s, %s, %s, %s, %d) ON DUPLICATE KEY UPDATE category = VALUES(category), state = VALUES(state), severity = VALUES(severity), message_code = VALUES(message_code), context_json = VALUES(context_json), checked_at = VALUES(checked_at), duration_ms = VALUES(duration_ms)',
				$this->schema->results_table(),
				$definition->id(),
				$definition->category(),
				$result->state(),
				$result->severity(),
				$result->message_code(),
				ContextCodec::encode( $result->context() ),
				$this->format_timestamp( $result->checked_at() ),
				$result->duration_milliseconds()
			)
		);
	}

	/**
	 * Read the current result for a check.
	 *
	 * @param mixed $check_id Stable check identifier.
	 * @return CheckResult|null
	 */
	public function get( $check_id ) {
		$check_id = ContractValidator::slug( $check_id, 'Check ID' );
		$row      = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT state, severity, message_code, context_json, checked_at, duration_ms FROM %i WHERE check_id = %s LIMIT 1',
				$this->schema->results_table(),
				$check_id
			),
			'ARRAY_A'
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * Read a deterministic, bounded set of current results.
	 *
	 * @param int $limit Maximum number of rows to read.
	 * @return array<string, CheckResult>
	 */
	public function all( $limit = 50 ) {
		$limit  = max( 1, min( 100, (int) $limit ) );
		$rows   = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT check_id, state, severity, message_code, context_json, checked_at, duration_ms FROM %i ORDER BY check_id ASC LIMIT %d',
				$this->schema->results_table(),
				$limit
			),
			'ARRAY_A'
		);
		$loaded = array();

		if ( ! is_array( $rows ) ) {
			return $loaded;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['check_id'] ) ) {
				continue;
			}

			$check_id            = ContractValidator::slug( $row['check_id'], 'Check ID' );
			$loaded[ $check_id ] = $this->hydrate( $row );
		}

		return $loaded;
	}

	/**
	 * Delete old current rows that have no unresolved incident.
	 *
	 * @param DateTimeImmutable $before UTC cutoff.
	 * @param int               $limit Maximum rows to delete.
	 * @return int
	 */
	public function delete_orphans( DateTimeImmutable $before, $limit = 100 ) {
		$limit = max( 1, min( 1000, (int) $limit ) );
		return (int) $this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE r FROM %i AS r LEFT JOIN %i AS i ON i.check_id = r.check_id AND i.resolved_at IS NULL WHERE i.id IS NULL AND r.checked_at < %s LIMIT %d',
				$this->schema->results_table(),
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

	/**
	 * Hydrate one normalized result row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return CheckResult
	 */
	private function hydrate( array $row ) {
		return new CheckResult(
			$row['state'],
			$row['severity'],
			$row['message_code'],
			ContextCodec::decode( $row['context_json'] ),
			new DateTimeImmutable( $row['checked_at'], new DateTimeZone( 'UTC' ) ),
			(int) $row['duration_ms']
		);
	}
}
