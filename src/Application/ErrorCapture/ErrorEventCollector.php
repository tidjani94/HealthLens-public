<?php
/**
 * Failure-isolated error-event collector.
 *
 * @package HealthLens
 */

namespace HealthLens\Application\ErrorCapture;

use HealthLens\Domain\ErrorEvent;
use HealthLens\Infrastructure\Database\ErrorEventRepositoryInterface;
use Throwable;

/**
 * Applies opt-in, request-rate, normalization, and storage boundaries.
 */
final class ErrorEventCollector implements ErrorEventCollectorInterface {
	/** Maximum capture attempts per request. */
	const MAX_EVENTS_PER_REQUEST = 10;

	/**
	 * Site-local event repository.
	 *
	 * @var ErrorEventRepositoryInterface
	 */
	private $repository;
	/**
	 * Whether capture is enabled.
	 *
	 * @var bool
	 */
	private $enabled;
	/**
	 * Number of attempts in this request.
	 *
	 * @var int
	 */
	private $attempts = 0;

	/**
	 * Create a collector.
	 *
	 * @param ErrorEventRepositoryInterface $repository Site-local repository.
	 * @param bool                          $enabled Whether capture is enabled.
	 */
	public function __construct( ErrorEventRepositoryInterface $repository, $enabled ) {
		$this->repository = $repository;
		$this->enabled    = (bool) $enabled;
	}

	/** Return whether capture is enabled. @return bool */
	public function enabled() {
		return $this->enabled;
	}

	/**
	 * Capture a normalized candidate without changing caller behavior.
	 *
	 * @param string $event_type Event type.
	 * @param string $code Stable code.
	 * @param string $severity Severity.
	 * @param string $source Producer.
	 * @param string $location Location.
	 * @param array  $context Candidate context.
	 * @return bool Whether persistence accepted the event.
	 */
	public function capture( $event_type, $code, $severity, $source, $location, array $context = array() ) {
		if ( ! $this->enabled || $this->attempts >= self::MAX_EVENTS_PER_REQUEST ) {
			return false;
		}

		++$this->attempts;
		try {
			$event = ErrorEventNormalizer::event( $event_type, $code, $severity, $source, $location, $context );
			return $this->repository->save( $event );
		} catch ( Throwable $throwable ) {
			return false;
		}
	}

	/**
	 * Capture an owned-operation throwable.
	 *
	 * @param Throwable $throwable Failure.
	 * @param string    $source Producer.
	 * @param string    $location Location.
	 * @param string    $code Stable code.
	 * @return bool
	 */
	public function capture_throwable( Throwable $throwable, $source, $location, $code ) {
		if ( ! $this->enabled || $this->attempts >= self::MAX_EVENTS_PER_REQUEST ) {
			return false;
		}

		++$this->attempts;
		try {
			return $this->repository->save( ErrorEventNormalizer::throwable( $throwable, $source, $location, $code ) );
		} catch ( Throwable $ignored ) {
			return false;
		}
	}

	/**
	 * Capture a supported WP_Error-like value.
	 *
	 * @param mixed  $error WP_Error-like value.
	 * @param string $source Producer.
	 * @param string $location Location.
	 * @param string $code Stable code.
	 * @return bool
	 */
	public function capture_wp_error( $error, $source, $location, $code ) {
		if ( ! $this->enabled || $this->attempts >= self::MAX_EVENTS_PER_REQUEST ) {
			return false;
		}

		try {
			$event = ErrorEventNormalizer::wp_error( $error, $source, $location, $code );
			if ( ! $event instanceof ErrorEvent ) {
				return false;
			}
			++$this->attempts;
			return $this->repository->save( $event );
		} catch ( Throwable $ignored ) {
			return false;
		}
	}
}
