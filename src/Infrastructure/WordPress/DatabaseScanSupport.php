<?php
/**
 * Shared bounded database-scan helpers.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress;

use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckResult;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Keeps M5 checks read-only, current-site-only, and privacy-safe.
 */
final class DatabaseScanSupport {
	/** Maximum scan time in milliseconds. */
	const MAX_MILLISECONDS = 500;
	/** Maximum metadata queries in one scan. */
	const MAX_QUERIES = 4;
	/** Autoload warning byte threshold. */
	const AUTOLOAD_WARNING_BYTES = 2000000;
	/** Autoload warning count threshold. */
	const AUTOLOAD_WARNING_COUNT = 1000;
	/** Storage warning byte threshold. */
	const STORAGE_WARNING_BYTES = 52428800;
	/** Storage warning row threshold. */
	const STORAGE_WARNING_ROWS = 5000;

	/**
	 * Determine whether the database adapter supports the bounded probe.
	 *
	 * @param mixed $wpdb WordPress database object.
	 * @return bool
	 */
	public static function available( $wpdb ) {
		return is_object( $wpdb ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_var' );
	}

	/**
	 * Return elapsed probe time.
	 *
	 * @param float $started_at Start time.
	 * @return int
	 */
	public static function elapsed( $started_at ) {
		return max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );
	}

	/**
	 * Create a normalized scan result.
	 *
	 * @param string $state Result state.
	 * @param string $severity Severity.
	 * @param string $code Message code.
	 * @param array  $context Safe context.
	 * @return CheckResult
	 */
	public static function result( $state, $severity, $code, array $context ) {
		return new CheckResult( $state, $severity, $code, new CheckContext( $context ), new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ), 0 );
	}

	/**
	 * Reduce a server version to a safe family.
	 *
	 * @param string $value Candidate database version.
	 * @return string
	 */
	public static function version_family( $value ) {
		$value = strtolower( (string) $value );
		if ( false !== strpos( $value, 'mariadb' ) ) {
			return 'mariadb';
		}
		if ( preg_match( '/\A[0-9]+\.[0-9]+/', $value ) ) {
			return 'mysql';
		}
		return 'unknown';
	}

	/**
	 * Bucket a count or size.
	 *
	 * @param int $value Count or byte size.
	 * @param int $step Bucket size.
	 * @return int
	 */
	public static function bucket( $value, $step ) {
		$value = max( 0, (int) $value );
		return (int) ( floor( $value / $step ) * $step );
	}

	/**
	 * Check the documented charset family.
	 *
	 * @param mixed $wpdb WordPress database object.
	 * @return bool
	 */
	public static function compatible_charset( $wpdb ) {
		if ( ! method_exists( $wpdb, 'get_charset_collate' ) ) {
			return false;
		}

		$collate = strtolower( (string) $wpdb->get_charset_collate() );
		return false !== strpos( $collate, 'utf8mb4' );
	}

	/**
	 * Read columns for one fixed table.
	 *
	 * @param mixed  $wpdb WordPress database object.
	 * @param string $table Fixed table identifier.
	 * @return array<int, array<string, mixed>>
	 */
	public static function columns( $wpdb, $table ) {
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The M5 boundary permits one bounded metadata query; caching would cross site/privilege boundaries.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
			return is_array( $rows ) ? $rows : array();
		} catch ( Throwable $throwable ) {
			return array();
		}
	}

	/**
	 * Read aggregate status for one fixed table.
	 *
	 * @param mixed  $wpdb WordPress database object.
	 * @param string $table Fixed table identifier.
	 * @return array<string, mixed>
	 */
	public static function table_status( $wpdb, $table ) {
		try {
			$like = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $table ) : $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The M5 boundary permits one bounded metadata query; caching would cross site/privilege boundaries.
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A );
			return is_array( $row ) ? $row : array();
		} catch ( Throwable $throwable ) {
			return array();
		}
	}
}
