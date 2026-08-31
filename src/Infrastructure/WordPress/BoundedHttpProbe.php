<?php
/**
 * Bounded current-site HTTP probe boundary.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress;

/**
 * Executes safe, bounded GET requests without retaining response bodies.
 */
final class BoundedHttpProbe {
	const TIMEOUT_SECONDS    = 5;
	const MAX_REDIRECTS      = 1;
	const MAX_RESPONSE_BYTES = 8192;
	const MAX_URL_LENGTH     = 2048;

	/** Transport callback.
	 *
	 * @var callable
	 */
	private $transport;

	/** Current-site host allowlist.
	 *
	 * @var string|null
	 */
	private $allowed_host;

	/** Create a bounded probe.
	 *
	 * @param callable|null $transport Optional transport double.
	 * @param string|null   $allowed_host Optional host allowlist entry.
	 */
	public function __construct( $transport = null, $allowed_host = null ) {
		$this->transport    = is_callable( $transport ) ? $transport : array( $this, 'wordpress_transport' );
		$this->allowed_host = is_string( $allowed_host ) && '' !== $allowed_host ? strtolower( $allowed_host ) : null;
	}

	/**
	 * Probe one URL and normalize the safe scalar response metadata.
	 *
	 * @param mixed $url Candidate URL.
	 * @return ProbeResult
	 */
	public function probe( $url ) {
		$validation = $this->validate_url( $url );
		if ( true !== $validation ) {
			return ProbeResult::failure( $validation );
		}

		$started_at = microtime( true );
		try {
			$response = call_user_func(
				$this->transport,
				$url,
				array(
					'timeout'             => self::TIMEOUT_SECONDS,
					'redirection'         => self::MAX_REDIRECTS,
					'limit_response_size' => self::MAX_RESPONSE_BYTES,
					'reject_unsafe_urls'  => true,
					'headers'             => array( 'Accept' => 'application/json' ),
				)
			);
		} catch ( \Throwable $throwable ) {
			return ProbeResult::failure( 'transport-exception', $this->elapsed( $started_at ) );
		}

		return $this->normalize_response( $response, $started_at );
	}

	/**
	 * WordPress HTTP API transport used in production.
	 *
	 * @param string               $url Request URL.
	 * @param array<string, mixed> $args Bounded request arguments.
	 * @return mixed
	 */
	public function wordpress_transport( $url, array $args ) {
		if ( ! function_exists( 'wp_safe_remote_get' ) ) {
			return array( 'error' => 'transport-unavailable' );
		}

		return wp_safe_remote_get( $url, $args );
	}

	/**
	 * Validate protocol, host, URL size, and user-info boundaries.
	 *
	 * @param mixed $url Candidate URL.
	 * @return bool|string True or a stable error code.
	 */
	private function validate_url( $url ) {
		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > self::MAX_URL_LENGTH ) {
			return 'invalid-url';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return 'invalid-url';
		}

		if ( ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return 'invalid-scheme';
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return 'unsafe-url';
		}

		$host = strtolower( (string) $parts['host'] );
		if ( null !== $this->allowed_host && ! hash_equals( $this->allowed_host, $host ) ) {
			return 'host-not-allowlisted';
		}

		return true;
	}

	/**
	 * Normalize transport results without retaining the body.
	 *
	 * @param mixed $response Transport response.
	 * @param float $started_at Start timestamp.
	 * @return ProbeResult
	 */
	private function normalize_response( $response, $started_at ) {
		$elapsed = $this->elapsed( $started_at );

		if ( is_array( $response ) && isset( $response['error'] ) && is_string( $response['error'] ) ) {
			return ProbeResult::failure( $response['error'], $elapsed );
		}

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			return ProbeResult::failure( 'transport-error', $elapsed );
		}

		$status       = 0;
		$content_type = '';
		$body         = '';
		if ( is_array( $response ) ) {
			$status = isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
			$body   = isset( $response['body'] ) && is_string( $response['body'] ) ? $response['body'] : '';
			if ( isset( $response['headers'] ) && is_array( $response['headers'] ) ) {
				foreach ( $response['headers'] as $header => $value ) {
					if ( 'content-type' === strtolower( (string) $header ) ) {
						$content_type = is_string( $value ) ? strtolower( $value ) : '';
						break;
					}
				}
			}
		} elseif ( function_exists( 'wp_remote_retrieve_response_code' ) ) {
			$status       = (int) wp_remote_retrieve_response_code( $response );
			$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
			$body         = (string) wp_remote_retrieve_body( $response );
		}

		if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			return ProbeResult::failure( 'response-too-large', $elapsed, $status, $content_type );
		}

		return ProbeResult::success( $status, $content_type, $body, $elapsed );
	}

	/**
	 * Return a non-negative integer duration.
	 *
	 * @param float $started_at Start timestamp.
	 * @return int
	 */
	private function elapsed( $started_at ) {
		return max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );
	}
}
