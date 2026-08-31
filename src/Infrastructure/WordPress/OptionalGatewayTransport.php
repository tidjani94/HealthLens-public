<?php
/**
 * Runtime-gated authenticated gateway transport boundary.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress;

use HealthLens\Domain\IntegrationPayload;

/**
 * Uses a fixed approved host list and bounded WordPress HTTPS transport.
 */
final class OptionalGatewayTransport {
	const MAX_SECONDS        = 5;
	const MAX_REDIRECTS      = 0;
	const MAX_RESPONSE_BYTES = 8192;
	const APPROVED_HOSTS     = array( 'gateway.healthlens.example' );

	/**
	 * Validate an endpoint without making a request.
	 *
	 * @param mixed $endpoint Candidate endpoint.
	 * @return bool
	 */
	public static function approved_endpoint( $endpoint ) {
		$parts = is_string( $endpoint ) ? wp_parse_url( $endpoint ) : false;
		return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && 'https' === strtolower( $parts['scheme'] ) && in_array( strtolower( $parts['host'] ), self::APPROVED_HOSTS, true ) && empty( $parts['user'] ) && empty( $parts['pass'] ) && empty( $parts['fragment'] );
	}

	/**
	 * Send one minimized payload and return an aggregate outcome.
	 *
	 * @param string             $endpoint Approved endpoint.
	 * @param string             $token Site-local authentication token.
	 * @param IntegrationPayload $payload Safe payload.
	 * @return array<string, mixed>
	 */
	public function send( $endpoint, $token, IntegrationPayload $payload ) {
		$body = $payload->encode();
		if ( ! self::approved_endpoint( $endpoint ) || '' === $token || ! is_string( $body ) || ! function_exists( 'wp_safe_remote_post' ) ) {
			return array( 'status' => 'disabled' );
		}
		try {
			$response = wp_safe_remote_post(
				$endpoint,
				array(
					'timeout'     => self::MAX_SECONDS,
					'redirection' => self::MAX_REDIRECTS,
					'sslverify'   => true,
					'headers'     => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . substr( $token, 0, 256 ),
					),
					'body'        => $body,
				)
			);
			if ( is_wp_error( $response ) ) {
				return array( 'status' => 'transport-failed' );
			}
			$code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
			$raw  = function_exists( 'wp_remote_retrieve_body' ) ? wp_remote_retrieve_body( $response ) : '';
			if ( strlen( $raw ) > self::MAX_RESPONSE_BYTES ) {
				return array(
					'status'        => 'response-too-large',
					'response_code' => $code,
				);
			}
			return array(
				'status'        => $code >= 200 && $code < 300 ? 'accepted' : 'response-failed',
				'response_code' => $code,
			);
		} catch ( \Throwable $throwable ) {
			return array( 'status' => 'transport-failed' );
		}
	}
}
