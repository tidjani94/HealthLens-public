<?php
/**
 * Bounded site-local error-event repository.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\Database;

use DateTimeImmutable;
use DateTimeZone;
use HealthLens\Domain\ErrorEvent;
use Throwable;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are generated from a validated site prefix; values use placeholders.

/**
 * Stores only normalized events with bounded deduplication and cleanup.
 */
final class ErrorEventRepository implements ErrorEventRepositoryInterface {
	/** Maximum retained rows per site. */
	const MAX_ROWS = 500;
	/** Retention period in seconds. */
	const RETENTION_SECONDS = 2592000;
	/** Maximum cleanup rows per write. */
	const MAX_CLEANUP_ROWS = 100;
	/** Duplicate window in seconds. */
	const DUPLICATE_WINDOW_SECONDS = 600;

	/**
	 * WordPress database connection.
	 *
	 * @var object
	 */
	private $wpdb;
	/**
	 * Schema manager.
	 *
	 * @var SchemaManager
	 */
	private $schema;

	/**
	 * Create a repository.
	 *
	 * @param mixed         $wpdb WordPress database object.
	 * @param SchemaManager $schema Schema manager.
	 */
	public function __construct( $wpdb, SchemaManager $schema ) {
		$this->wpdb   = $wpdb;
		$this->schema = $schema;
	}

	/**
	 * Persist one event after bounded duplicate and retention checks.
	 *
	 * @param ErrorEvent $event Normalized event.
	 * @return bool
	 */
	public function save( ErrorEvent $event ) {
		try {
			$now       = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			$duplicate = $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT id FROM %i WHERE dedupe_hash = %s AND occurred_at >= %s LIMIT 1',
					$this->schema->errors_table(),
					$event->dedupe_hash(),
					$now->setTimestamp( $now->getTimestamp() - self::DUPLICATE_WINDOW_SECONDS )->format( 'Y-m-d H:i:s' )
				)
			);
			if ( null !== $duplicate && false !== $duplicate ) {
				return false;
			}

			$query   = $this->wpdb->prepare(
				'INSERT INTO %i (event_type, event_code, severity, source, location, context_json, occurred_at, dedupe_hash) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)',
				$this->schema->errors_table(),
				$event->event_type(),
				$event->code(),
				$event->severity(),
				$event->source(),
				$event->location(),
				$event->context()->to_json(),
				$event->occurred_at()->format( 'Y-m-d H:i:s' ),
				$event->dedupe_hash()
			);
			$written = $this->wpdb->query( $query );
			if ( false === $written ) {
				return false;
			}

			$this->prune( $now );
			$this->trim_rows();
			return true;
		} catch ( Throwable $throwable ) {
			return false;
		}
	}

	/**
	 * Delete a bounded number of expired events.
	 *
	 * @param DateTimeImmutable|null $now Current UTC time.
	 * @return int|false
	 */
	public function prune( $now = null ) {
		$now    = $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$cutoff = $now->setTimestamp( $now->getTimestamp() - self::RETENTION_SECONDS )->format( 'Y-m-d H:i:s' );

		return $this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE occurred_at < %s ORDER BY id ASC LIMIT %d',
				$this->schema->errors_table(),
				$cutoff,
				self::MAX_CLEANUP_ROWS
			)
		);
	}

	/**
	 * Trim oldest rows when the fixed site cap is exceeded.
	 *
	 * @return int|false
	 */
	public function trim_rows() {
		return $this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE id IN (SELECT id FROM (SELECT id FROM %i ORDER BY id ASC LIMIT %d OFFSET %d) AS healthlens_old_errors)',
				$this->schema->errors_table(),
				$this->schema->errors_table(),
				self::MAX_CLEANUP_ROWS,
				self::MAX_ROWS
			)
		);
	}

	/**
	 * Return bounded normalized rows for a future history read model.
	 *
	 * @param int $limit Requested row count.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( $limit = 50 ) {
		$limit = max( 1, min( 50, (int) $limit ) );
		$rows  = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT id, event_type, event_code, severity, source, location, context_json, occurred_at FROM %i ORDER BY occurred_at DESC, id DESC LIMIT %d', $this->schema->errors_table(), $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
