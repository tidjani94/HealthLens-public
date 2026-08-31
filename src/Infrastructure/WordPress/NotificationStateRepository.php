<?php
/**
 * Bounded site-local notification state stored without autoloading.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress;

/**
 * Stores only event keys, attempt counters, status categories, and UTC times.
 */
final class NotificationStateRepository {
	const OPTION     = 'healthlens_notification_state';
	const MAX_EVENTS = 50;

	/**
	 * Return one event state.
	 *
	 * @param string $event_key Stable bounded event key.
	 * @return array<string, mixed>
	 */
	public function get( $event_key ) {
		$all = $this->all();
		return isset( $all[ $event_key ] ) ? $all[ $event_key ] : array();
	}

	/**
	 * Save one bounded event state.
	 *
	 * @param string $event_key Stable bounded event key.
	 * @param array  $state Sanitized state.
	 * @return bool
	 */
	public function put( $event_key, array $state ) {
		$all               = $this->all();
		$all[ $event_key ] = array_intersect_key(
			$state,
			array_flip( array( 'status', 'attempts', 'last_attempt_at', 'next_attempt_at', 'severity' ) )
		);
		$all               = array_slice( $all, -self::MAX_EVENTS, self::MAX_EVENTS, true );
		add_option( self::OPTION, array(), '', false );
		return function_exists( 'update_option' ) ? (bool) update_option( self::OPTION, $all, false ) : false;
	}

	/**
	 * Return a safe aggregate status for the dashboard.
	 *
	 * @return array<string, mixed>
	 */
	public function summary() {
		$all    = $this->all();
		$failed = 0;
		$sent   = 0;
		$last   = null;
		foreach ( $all as $state ) {
			$failed += isset( $state['status'] ) && 'failed' === $state['status'] ? 1 : 0;
			$sent   += isset( $state['status'] ) && 'sent' === $state['status'] ? 1 : 0;
			if ( isset( $state['last_attempt_at'] ) && is_string( $state['last_attempt_at'] ) ) {
				$last = $state['last_attempt_at'];
			}
		}

		return array(
			'event_count'     => count( $all ),
			'sent_count'      => $sent,
			'failed_count'    => $failed,
			'last_attempt_at' => $last,
		);
	}

	/**
	 * Load and normalize the bounded option.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function all() {
		$value = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return is_array( $value ) ? array_slice( $value, -self::MAX_EVENTS, self::MAX_EVENTS, true ) : array();
	}
}
