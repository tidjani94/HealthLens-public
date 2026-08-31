<?php
/**
 * Normalized bounded HTTP probe result.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress;

use HealthLens\Domain\ContractValidator;

/**
 * Holds safe response metadata and the bounded body used for shape checks.
 */
final class ProbeResult {
	/** Whether transport completed.
	 *
	 * @var bool
	 */
	private $transport_ok;
	/** Stable transport error.
	 *
	 * @var string
	 */
	private $error_code;
	/** HTTP response code.
	 *
	 * @var int
	 */
	private $status;
	/** Response content type.
	 *
	 * @var string
	 */
	private $content_type;
	/** Bounded response body.
	 *
	 * @var string
	 */
	private $body;
	/** Elapsed milliseconds.
	 *
	 * @var int
	 */
	private $elapsed_ms;

	/** Build a successful result.
	 *
	 * @param bool   $transport_ok Whether transport completed.
	 * @param string $error_code Stable error code.
	 * @param int    $status HTTP response code.
	 * @param string $content_type Response content type.
	 * @param string $body Bounded response body.
	 * @param int    $elapsed_ms Elapsed milliseconds.
	 */
	private function __construct( $transport_ok, $error_code, $status, $content_type, $body, $elapsed_ms ) {
		$this->transport_ok = $transport_ok;
		$this->error_code   = $error_code;
		$this->status       = $status;
		$this->content_type = $content_type;
		$this->body         = $body;
		$this->elapsed_ms   = $elapsed_ms;
	}

	/** Build a failed result.
	 *
	 * Build a successful result.
	 *
	 * @param int    $status HTTP status.
	 * @param string $content_type Content type.
	 * @param string $body Bounded body.
	 * @param int    $elapsed_ms Elapsed milliseconds.
	 * @return self
	 */
	public static function success( $status, $content_type, $body, $elapsed_ms ) {
		return new self( true, '', (int) $status, (string) $content_type, (string) $body, (int) $elapsed_ms );
	}

	/** Return whether transport completed.
	 *
	 * Build a failed result.
	 *
	 * @param string $error_code Stable error code.
	 * @param int    $elapsed_ms Elapsed milliseconds.
	 * @param int    $status HTTP status.
	 * @param string $content_type Content type.
	 * @return self
	 */
	public static function failure( $error_code, $elapsed_ms = 0, $status = 0, $content_type = '' ) {
		return new self( false, ContractValidator::slug( $error_code, 'Probe error code' ), (int) $status, (string) $content_type, '', (int) $elapsed_ms );
	}

	/** Return the stable error code.
	 *
	 * @return bool
	 */
	public function transport_ok() {
		return $this->transport_ok;
	}

	/** Return the HTTP status.
	 *
	 * @return string
	 */
	public function error_code() {
		return $this->error_code;
	}

	/** Return the response content type.
	 *
	 * @return int
	 */
	public function status() {
		return $this->status;
	}

	/** Return the bounded response body.
	 *
	 * @return string
	 */
	public function content_type() {
		return $this->content_type;
	}

	/** Return elapsed milliseconds.
	 *
	 * @return string
	 */
	public function body() {
		return $this->body;
	}

	/** Return elapsed milliseconds.
	 *
	 * @return int
	 */
	public function elapsed_milliseconds() {
		return $this->elapsed_ms;
	}
}
