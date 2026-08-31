<?php
/**
 * HealthLens database schema manager.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\Database;

use InvalidArgumentException;
use RuntimeException;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are generated from a validated site prefix; all data values use prepared placeholders.

/**
 * Creates and upgrades site-prefixed HealthLens tables idempotently.
 */
final class SchemaManager {
	/**
	 * Current database schema version.
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = 3;

	/**
	 * WordPress database connection.
	 *
	 * @var object
	 */
	private $wpdb;

	/**
	 * Create a schema manager.
	 *
	 * @param mixed $wpdb WordPress database object.
	 * @throws InvalidArgumentException If the database object is invalid.
	 */
	public function __construct( $wpdb ) {
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) || ! is_string( $wpdb->prefix ) ) {
			throw new InvalidArgumentException( 'A valid WordPress database object is required.' );
		}

		$this->wpdb = $wpdb;
	}

	/**
	 * Create or upgrade both plugin-owned tables.
	 *
	 * @throws RuntimeException If dbDelta() is unavailable.
	 * @return void
	 */
	public function upgrade() {
		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			throw new RuntimeException( 'WordPress dbDelta() is unavailable.' );
		}

		dbDelta( $this->results_sql() );
		dbDelta( $this->incidents_sql() );
		dbDelta( $this->errors_sql() );

		if ( function_exists( 'update_option' ) ) {
			update_option( 'healthlens_schema_version', self::SCHEMA_VERSION, false );
		}
	}

	/**
	 * Drop plugin-owned tables during uninstall.
	 *
	 * @return void
	 */
	public function drop() {
		$this->wpdb->query( $this->wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->results_table() ) );
		$this->wpdb->query( $this->wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->incidents_table() ) );
		$this->wpdb->query( $this->wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->errors_table() ) );
	}

	/**
	 * Return the current-result table name.
	 *
	 * @return string
	 */
	public function results_table() {
		return $this->table_name( 'healthlens_results' );
	}

	/**
	 * Return the incident table name.
	 *
	 * @return string
	 */
	public function incidents_table() {
		return $this->table_name( 'healthlens_incidents' );
	}

	/**
	 * Return the site-local error-event table name.
	 *
	 * @return string
	 */
	public function errors_table() {
		return $this->table_name( 'healthlens_errors' );
	}

	/**
	 * Build the current-result table definition.
	 *
	 * @return string
	 */
	private function results_sql() {
		return 'CREATE TABLE ' . $this->results_table() . ' (
			check_id varchar(64) NOT NULL,
			category varchar(64) NOT NULL,
			state varchar(16) NOT NULL,
			severity varchar(16) NOT NULL,
			message_code varchar(100) NOT NULL,
			context_json longtext NOT NULL,
			checked_at datetime NOT NULL,
			duration_ms bigint unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (check_id),
			KEY checked_at (checked_at)
		) ' . $this->charset_collate() . ';';
	}

	/**
	 * Build the incident table definition.
	 *
	 * @return string
	 */
	private function incidents_sql() {
		return 'CREATE TABLE ' . $this->incidents_table() . ' (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			check_id varchar(64) NOT NULL,
			severity varchar(16) NOT NULL,
			message_code varchar(100) NOT NULL,
			context_json longtext NOT NULL,
			first_detected_at datetime NOT NULL,
			last_detected_at datetime NOT NULL,
			resolved_at datetime NULL,
			PRIMARY KEY  (id),
			KEY check_open (check_id, resolved_at),
			KEY resolved_at (resolved_at)
		) ' . $this->charset_collate() . ';';
	}

	/**
	 * Build the bounded error-event table definition.
	 *
	 * @return string
	 */
	private function errors_sql() {
		return 'CREATE TABLE ' . $this->errors_table() . ' (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(32) NOT NULL,
			event_code varchar(100) NOT NULL,
			severity varchar(16) NOT NULL,
			source varchar(64) NOT NULL,
			location varchar(64) NOT NULL,
			context_json varchar(4096) NOT NULL,
			occurred_at datetime NOT NULL,
			dedupe_hash char(64) NOT NULL,
			PRIMARY KEY  (id),
			KEY dedupe_window (dedupe_hash, occurred_at),
			KEY occurred_at (occurred_at)
		) ' . $this->charset_collate() . ';';
	}

	/**
	 * Return the configured character set and collation.
	 *
	 * @return string
	 */
	private function charset_collate() {
		if ( method_exists( $this->wpdb, 'get_charset_collate' ) ) {
			return $this->wpdb->get_charset_collate();
		}

		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	/**
	 * Build a table identifier from the validated site prefix and fixed suffix.
	 *
	 * @param string $suffix Fixed table suffix.
	 * @throws InvalidArgumentException If the configured prefix is unsafe.
	 * @return string
	 */
	private function table_name( $suffix ) {
		if ( ! preg_match( '/\A[a-zA-Z0-9_]+\z/', $this->wpdb->prefix ) ) {
			throw new InvalidArgumentException( 'The WordPress table prefix is invalid.' );
		}

		return $this->wpdb->prefix . $suffix;
	}
}
