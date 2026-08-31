<?php
/**
 * Minimized, deterministic payload for optional integrations.
 *
 * @package HealthLens
 */

namespace HealthLens\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Contains aggregate check metadata only; raw result context is never copied.
 */
final class IntegrationPayload {
	const SCHEMA     = 'healthlens.integration.v1';
	const MAX_CHECKS = 50;
	const MAX_BYTES  = 8192;

	/**
	 * Safe aggregate payload data.
	 *
	 * @var array<string, mixed>
	 */
	private $data;

	/**
	 * Build a payload from normalized results.
	 *
	 * @param array<string, CheckResult> $results Normalized results.
	 * @param DateTimeImmutable|null     $now UTC creation time.
	 */
	public function __construct( array $results, $now = null ) {
		$now    = $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$checks = array();
		foreach ( array_slice( $results, 0, self::MAX_CHECKS, true ) as $check_id => $result ) {
			$checks[] = array(
				'check_id'     => (string) $check_id,
				'state'        => $result->state(),
				'severity'     => $result->severity(),
				'message_code' => $result->message_code(),
				'checked_at'   => $result->checked_at()->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'c' ),
			);
		}
		usort(
			$checks,
			static function ( $left, $right ) {
				return strcmp( $left['check_id'], $right['check_id'] );
			}
		);
		$this->data = array(
			'schema'       => self::SCHEMA,
			'generated_at' => $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'c' ),
			'checks'       => $checks,
		);
	}

	/**
	 * Return the safe payload array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return $this->data;
	}

	/**
	 * Encode deterministically and cap the output.
	 *
	 * @return string|null
	 */
	public function encode() {
		$json = wp_json_encode( $this->data );
		return is_string( $json ) && strlen( $json ) <= self::MAX_BYTES ? $json : null;
	}
}
