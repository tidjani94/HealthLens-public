<?php
/**
 * WordPress/PHP error-capture integration boundary.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress;

use HealthLens\Application\ErrorCapture\ErrorEventCollectorInterface;
use Throwable;

/**
 * Installs passive opt-in handlers without changing WordPress debug behavior.
 */
final class ErrorCaptureBootstrap {
	/** Non-fatal levels explicitly approved for the PHP handler. */
	const NON_FATAL_LEVELS = E_NOTICE | E_WARNING | E_USER_NOTICE | E_USER_WARNING | E_RECOVERABLE_ERROR | E_DEPRECATED | E_USER_DEPRECATED;
	/** Fatal levels observable from the shutdown API. */
	const FATAL_LEVELS = E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_COMPILE_WARNING;

	/**
	 * Event collector.
	 *
	 * @var ErrorEventCollectorInterface
	 */
	private $collector;
	/**
	 * Previous PHP handler.
	 *
	 * @var callable|null
	 */
	private $previous_handler;
	/**
	 * Recursion guard.
	 *
	 * @var bool
	 */
	private $handling = false;
	/**
	 * Whether handlers were registered.
	 *
	 * @var bool
	 */
	private $registered = false;

	/**
	 * Create the integration boundary.
	 *
	 * @param ErrorEventCollectorInterface $collector Event collector.
	 */
	public function __construct( ErrorEventCollectorInterface $collector ) {
		$this->collector = $collector;
	}

	/**
	 * Register handlers only when capture is enabled and the runtime supports them.
	 *
	 * @return bool
	 */
	public function register() {
		if ( ! $this->collector->enabled() || ! function_exists( 'set_error_handler' ) || ! function_exists( 'register_shutdown_function' ) ) {
			return false;
		}

		try {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- This is the explicitly documented opt-in capture boundary.
			$this->previous_handler = set_error_handler( array( $this, 'handle' ), self::NON_FATAL_LEVELS );
			register_shutdown_function( array( $this, 'observe_shutdown' ) );
			$this->registered = true;
			return true;
		} catch ( Throwable $throwable ) {
			return false;
		}
	}

	/**
	 * Restore the previous PHP handler when a test/runtime explicitly tears down capture.
	 *
	 * @return bool
	 */
	public function restore() {
		if ( ! $this->registered || ! function_exists( 'restore_error_handler' ) ) {
			return false;
		}

		$this->registered = false;
		return (bool) restore_error_handler();
	}

	/**
	 * Capture one approved non-fatal error, then preserve prior handling.
	 *
	 * @param int    $errno Error level.
	 * @param string $message Ignored message.
	 * @param string $file Ignored path.
	 * @param int    $line Source line bucketed before capture.
	 * @return bool Whether the previous handler handled the error.
	 */
	public function handle( $errno, $message, $file, $line ) {
		if ( 0 === ( (int) $errno & self::NON_FATAL_LEVELS ) || $this->handling ) {
			return false;
		}

		$this->handling = true;
		try {
			$this->collector->capture(
				'php-error',
				self::code_for_level( (int) $errno ),
				'warning',
				'php',
				'runtime',
				array(
					'error_level' => (int) $errno,
					'line_bucket' => max( 0, (int) floor( (int) $line / 10 ) * 10 ),
				)
			);
		} catch ( Throwable $throwable ) {
			// Capture must never alter PHP's error path.
			$this->handling = false;
		}
		$this->handling = false;

		if ( is_callable( $this->previous_handler ) ) {
			try {
				return (bool) call_user_func( $this->previous_handler, $errno, $message, $file, $line );
			} catch ( Throwable $throwable ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Observe an approved fatal outcome without rendering or recovery changes.
	 *
	 * @return void
	 */
	public function observe_shutdown() {
		if ( ! function_exists( 'error_get_last' ) ) {
			return;
		}

		$last = error_get_last();
		if ( ! is_array( $last ) || 0 === ( (int) $last['type'] & self::FATAL_LEVELS ) ) {
			return;
		}

		try {
			$this->collector->capture(
				'php-fatal',
				self::code_for_level( (int) $last['type'] ),
				'critical',
				'php',
				'shutdown',
				array(
					'error_level' => (int) $last['type'],
					'line_bucket' => max( 0, (int) floor( (int) $last['line'] / 10 ) * 10 ),
				)
			);
		} catch ( Throwable $throwable ) {
			// Shutdown capture is advisory and must not replace core handling.
			unset( $throwable );
		}
	}

	/**
	 * Convert a PHP level into a stable machine code.
	 *
	 * @param int $level PHP error level.
	 * @return string
	 */
	private static function code_for_level( $level ) {
		$codes = array(
			E_NOTICE            => 'php.notice',
			E_WARNING           => 'php.warning',
			E_USER_NOTICE       => 'php.user-notice',
			E_USER_WARNING      => 'php.user-warning',
			E_RECOVERABLE_ERROR => 'php.recoverable',
			E_DEPRECATED        => 'php.deprecated',
			E_USER_DEPRECATED   => 'php.user-deprecated',
			E_ERROR             => 'php.fatal',
			E_PARSE             => 'php.parse',
			E_CORE_ERROR        => 'php.core-fatal',
			E_CORE_WARNING      => 'php.core-warning',
			E_COMPILE_ERROR     => 'php.compile-fatal',
			E_COMPILE_WARNING   => 'php.compile-warning',
		);

		return isset( $codes[ $level ] ) ? $codes[ $level ] : 'php.unknown';
	}
}
