<?php
/**
 * Local, opt-in notification dispatcher.
 *
 * @package HealthLens
 */

namespace HealthLens\Application;

use HealthLens\Domain\CheckResult;
use HealthLens\Infrastructure\WordPress\NotificationStateRepository;
use HealthLens\Plugin;

/**
 * Attempts bounded WordPress mail only from the background dispatcher path.
 */
final class NotificationDispatcher {
	const MAX_EVENTS   = 5;
	const COOLDOWN     = 86400;
	const MAX_ATTEMPTS = 3;

	/**
	 * State repository.
	 *
	 * @var NotificationStateRepository
	 */
	private $state;

	/**
	 * Create the notification dispatcher.
	 *
	 * @param NotificationStateRepository $state State repository.
	 */
	public function __construct( NotificationStateRepository $state ) {
		$this->state = $state;
	}

	/**
	 * Deliver eligible notifications for completed background results.
	 *
	 * @param array<string, CheckResult> $results Results from this batch.
	 * @return int Number of mail attempts.
	 */
	public function dispatch( array $results ) {
		$settings = function_exists( 'get_option' ) ? get_option( Plugin::SETTINGS_OPTION, array() ) : array();
		if ( ! is_array( $settings ) || empty( $settings['notifications_enabled'] ) || ! function_exists( 'is_email' ) || ! function_exists( 'wp_mail' ) ) {
			return 0;
		}
		$recipient = isset( $settings['notification_email'] ) && is_string( $settings['notification_email'] ) ? ( function_exists( 'sanitize_email' ) ? sanitize_email( $settings['notification_email'] ) : filter_var( $settings['notification_email'], FILTER_SANITIZE_EMAIL ) ) : '';
		if ( ! $recipient || ! is_email( $recipient ) ) {
			return 0;
		}

		$attempts = 0;
		foreach ( array_slice( $results, 0, self::MAX_EVENTS, true ) as $check_id => $result ) {
			if ( in_array( $result->state(), array( CheckResult::STATE_ISSUE, CheckResult::STATE_UNKNOWN ), true ) ) {
				$attempts += $this->send_event( $recipient, (string) $check_id, $result->severity(), $result->message_code(), false );
			} elseif ( CheckResult::STATE_HEALTHY === $result->state() && ! empty( $settings['notification_recovery'] ) ) {
				$attempts += $this->send_event( $recipient, (string) $check_id, 'healthy', 'notification.recovered', true );
			}
			if ( $attempts >= self::MAX_EVENTS ) {
				break;
			}
		}

		return $attempts;
	}

	/**
	 * Attempt one fixed-template event with cooldown and finite retry state.
	 *
	 * @param string $recipient Validated local recipient.
	 * @param string $check_id Stable check ID.
	 * @param string $severity Stable severity category.
	 * @param string $message_code Stable message code.
	 * @param bool   $recovery Whether this is a recovery event.
	 * @return int
	 */
	private function send_event( $recipient, $check_id, $severity, $message_code, $recovery ) {
		$key   = hash( 'sha256', $check_id . '|' . $severity . '|' . $message_code . '|' . ( $recovery ? 'recovery' : 'issue' ) );
		$state = $this->state->get( $key );
		$now   = time();
		if ( 'sent' === ( isset( $state['status'] ) ? $state['status'] : '' ) && isset( $state['last_attempt_at'] ) && $now - (int) $state['last_attempt_at'] < self::COOLDOWN ) {
			return 0;
		}
		if ( 'failed' === ( isset( $state['status'] ) ? $state['status'] : '' ) && isset( $state['next_attempt_at'] ) && $now < (int) $state['next_attempt_at'] ) {
			return 0;
		}
		$used = isset( $state['attempts'] ) ? (int) $state['attempts'] : 0;
		if ( $used >= self::MAX_ATTEMPTS ) {
			return 0;
		}

		$subject = $recovery ? __( 'HealthLens incident recovered', 'healthlens' ) : __( 'HealthLens site health needs attention', 'healthlens' );
		if ( $recovery ) {
			$body = __( 'A previously reported HealthLens incident has returned to a healthy state.', 'healthlens' );
		} else {
			// translators: %1$s is a stable severity and %2$s is a stable check ID.
			$body = sprintf( __( 'A HealthLens check reported a %1$s state. Check ID: %2$s. Review the saved dashboard result; no raw diagnostic data is included in this message.', 'healthlens' ), $this->safe_key( $severity ), $this->safe_key( $check_id ) );
		}
		$body = substr( (string) $body, 0, 1000 );
		$sent = false;
		try {
			$sent = (bool) wp_mail( $recipient, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
		} catch ( \Throwable $throwable ) {
			$sent = false;
		}
		++$used;
		$this->state->put(
			$key,
			array(
				'status'          => $sent ? 'sent' : 'failed',
				'attempts'        => $sent ? 0 : $used,
				'last_attempt_at' => $now,
				'next_attempt_at' => $sent ? 0 : $now + ( $used * 300 ),
				'severity'        => $this->safe_key( $severity ),
			)
		);

		return 1;
	}

	/**
	 * Normalize a bounded identifier for a fixed notification template.
	 *
	 * @param string $value Candidate identifier.
	 * @return string
	 */
	private function safe_key( $value ) {
		return function_exists( 'sanitize_key' ) ? sanitize_key( $value ) : preg_replace( '/[^a-z0-9_-]/i', '', strtolower( (string) $value ) );
	}
}
