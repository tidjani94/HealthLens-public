<?php
/**
 * Normalized health check result value object.
 *
 * @package HealthLens
 */

namespace HealthLens\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Represents one completed or unknown health check outcome.
 */
final class CheckResult {
	/** Healthy result state. */
	const STATE_HEALTHY = 'healthy';
	/** Known non-healthy result state. */
	const STATE_ISSUE = 'issue';
	/** Result state when execution could not produce a known outcome. */
	const STATE_UNKNOWN = 'unknown';

	/** Healthy severity. */
	const SEVERITY_HEALTHY = 'healthy';
	/** Informational severity. */
	const SEVERITY_INFO = 'info';
	/** Warning severity. */
	const SEVERITY_WARNING = 'warning';
	/** Critical severity. */
	const SEVERITY_CRITICAL = 'critical';

	/**
	 * Normalized result state.
	 *
	 * @var string
	 */
	private $state;

	/**
	 * Normalized result severity.
	 *
	 * @var string
	 */
	private $severity;

	/**
	 * Stable message code.
	 *
	 * @var string
	 */
	private $message_code;

	/**
	 * Bounded technical context.
	 *
	 * @var CheckContext
	 */
	private $context;

	/**
	 * Completion timestamp in UTC.
	 *
	 * @var DateTimeImmutable
	 */
	private $checked_at;

	/**
	 * Execution duration in milliseconds.
	 *
	 * Duration in milliseconds.
	 *
	 * @var int
	 */
	private $duration_milliseconds;

	/**
	 * Create a validated normalized result.
	 *
	 * @param mixed             $state Normalized result state.
	 * @param mixed             $severity Normalized result severity.
	 * @param mixed             $message_code Stable lower-case message code.
	 * @param CheckContext      $context Bounded technical context.
	 * @param DateTimeImmutable $checked_at Completion time.
	 * @param mixed             $duration_milliseconds Non-negative duration in milliseconds.
	 * @throws InvalidArgumentException If a state, severity, message code, or duration is invalid.
	 */
	public function __construct( $state, $severity, $message_code, CheckContext $context, DateTimeImmutable $checked_at, $duration_milliseconds ) {
		$allowed_severities = array(
			self::STATE_HEALTHY => array( self::SEVERITY_HEALTHY ),
			self::STATE_ISSUE   => array( self::SEVERITY_INFO, self::SEVERITY_WARNING, self::SEVERITY_CRITICAL ),
			self::STATE_UNKNOWN => array( self::SEVERITY_WARNING ),
		);

		if ( ! is_string( $state ) || ! is_string( $severity ) || ! isset( $allowed_severities[ $state ] ) || ! in_array( $severity, $allowed_severities[ $state ], true ) ) {
			throw new InvalidArgumentException( 'The result state and severity combination is invalid.' );
		}

		$this->state                 = $state;
		$this->severity              = $severity;
		$this->message_code          = ContractValidator::slug( $message_code, 'Message code', 100 );
		$this->context               = $context;
		$this->checked_at            = $checked_at->setTimezone( new DateTimeZone( 'UTC' ) );
		$this->duration_milliseconds = ContractValidator::non_negative_integer( $duration_milliseconds, 'Duration' );
	}

	/**
	 * Return the normalized result state.
	 *
	 * @return string
	 */
	public function state() {
		return $this->state;
	}

	/**
	 * Return the normalized result severity.
	 *
	 * @return string
	 */
	public function severity() {
		return $this->severity;
	}

	/**
	 * Return the stable message code.
	 *
	 * @return string
	 */
	public function message_code() {
		return $this->message_code;
	}

	/**
	 * Return the bounded technical context.
	 *
	 * @return CheckContext
	 */
	public function context() {
		return $this->context;
	}

	/**
	 * Return the UTC completion timestamp.
	 *
	 * @return DateTimeImmutable Always represented in UTC.
	 */
	public function checked_at() {
		return $this->checked_at;
	}

	/**
	 * Return the execution duration.
	 *
	 * @return int Duration in milliseconds.
	 */
	public function duration_milliseconds() {
		return $this->duration_milliseconds;
	}
}
