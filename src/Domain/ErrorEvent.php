<?php
/**
 * Normalized, bounded error-capture event.
 *
 * @package HealthLens
 */

namespace HealthLens\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Represents the only event shape permitted to reach persistence.
 */
final class ErrorEvent {
	/** Maximum event type length. */
	const MAX_EVENT_TYPE_LENGTH = 32;
	/** Maximum event code length. */
	const MAX_CODE_LENGTH = 100;
	/** Maximum source/location length. */
	const MAX_CATEGORY_LENGTH = 64;

	/**
	 * Event type.
	 *
	 * @var string
	 */
	private $event_type;
	/**
	 * Stable event code.
	 *
	 * @var string
	 */
	private $code;
	/**
	 * Event severity.
	 *
	 * @var string
	 */
	private $severity;
	/**
	 * Producer category.
	 *
	 * @var string
	 */
	private $source;
	/**
	 * Safe location category.
	 *
	 * @var string
	 */
	private $location;
	/**
	 * UTC event time.
	 *
	 * @var DateTimeImmutable
	 */
	private $occurred_at;
	/**
	 * Allowlisted context.
	 *
	 * @var CheckContext
	 */
	private $context;
	/**
	 * Duplicate grouping hash.
	 *
	 * @var string
	 */
	private $dedupe_hash;

	/**
	 * Create a validated event.
	 *
	 * @param string            $event_type Stable event type.
	 * @param string            $code Stable machine code.
	 * @param string            $severity Event severity.
	 * @param string            $source Producer category.
	 * @param string            $location Safe location category.
	 * @param DateTimeImmutable $occurred_at UTC event time.
	 * @param CheckContext      $context Allowlisted context.
	 * @throws InvalidArgumentException If a field is invalid.
	 */
	public function __construct( $event_type, $code, $severity, $source, $location, DateTimeImmutable $occurred_at, CheckContext $context ) {
		$event_type = ContractValidator::slug( $event_type, 'Event type', self::MAX_EVENT_TYPE_LENGTH );
		$code       = ContractValidator::slug( $code, 'Event code', self::MAX_CODE_LENGTH );
		$source     = ContractValidator::slug( $source, 'Event source', self::MAX_CATEGORY_LENGTH );
		$location   = ContractValidator::slug( $location, 'Event location', self::MAX_CATEGORY_LENGTH );

		if ( ! in_array( $severity, array( 'info', 'warning', 'critical' ), true ) ) {
			throw new InvalidArgumentException( 'Event severity is not supported.' );
		}

		$this->event_type  = $event_type;
		$this->code        = $code;
		$this->severity    = $severity;
		$this->source      = $source;
		$this->location    = $location;
		$this->occurred_at = $occurred_at->setTimezone( new DateTimeZone( 'UTC' ) );
		$this->context     = $context;
		$this->dedupe_hash = hash( 'sha256', implode( '|', array( $event_type, $code, $severity, $source, $location, $context->to_json() ) ) );
	}

	/** Return the event type. @return string */
	public function event_type() {
		return $this->event_type;
	}
	/** Return the stable code. @return string */
	public function code() {
		return $this->code;
	}
	/** Return the severity. @return string */
	public function severity() {
		return $this->severity;
	}
	/** Return the source category. @return string */
	public function source() {
		return $this->source;
	}
	/** Return the location category. @return string */
	public function location() {
		return $this->location;
	}
	/** Return the UTC occurrence time. @return DateTimeImmutable */
	public function occurred_at() {
		return $this->occurred_at;
	}
	/** Return the bounded context. @return CheckContext */
	public function context() {
		return $this->context;
	}
	/** Return the duplicate grouping hash. @return string */
	public function dedupe_hash() {
		return $this->dedupe_hash;
	}
}
