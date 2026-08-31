<?php
/**
 * HealthLens WP-Cron lifecycle integration.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress;

/**
 * Registers and maintains the single per-site HealthLens cron event.
 */
final class CronScheduler {
	const HOOK        = 'healthlens_run_checks';
	const MANUAL_HOOK = 'healthlens_run_checks_now';
	const RECURRENCE  = 'healthlens_fifteen_minutes';
	const INTERVAL    = 900;

	/**
	 * Register the custom recurrence for the current request.
	 *
	 * @return void
	 */
	public function register() {
		if ( function_exists( 'add_filter' ) ) {
			// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- The custom interval is declared in add_interval below.
			add_filter( 'cron_schedules', array( $this, 'add_interval' ) );
		}
		if ( function_exists( 'add_action' ) ) {
			add_action( 'action_scheduler_init', array( $this, 'migrate_to_action_scheduler' ), 20 );
		}
	}

	/**
	 * Add the documented fifteen-minute recurrence.
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_interval( array $schedules ) {
		if ( ! isset( $schedules[ self::RECURRENCE ] ) ) {
			$schedules[ self::RECURRENCE ] = array(
				'interval' => self::INTERVAL,
				'display'  => function_exists( '__' ) ? __( 'Every 15 minutes', 'healthlens' ) : 'Every 15 minutes',
			);
		}

		return $schedules;
	}

	/**
	 * Ensure one recurring HealthLens event exists.
	 *
	 * WP-Cron is load-triggered; this method is safe to call during every
	 * plugin bootstrap and during activation.
	 *
	 * @return void
	 */
	public function schedule() {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}

		if ( ActionSchedulerAdapter::schedule() ) {
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			return;
		}

		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + self::INTERVAL, self::RECURRENCE, self::HOOK );
		}
	}

	/**
	 * Remove only the HealthLens event.
	 *
	 * @return void
	 */
	public function unschedule() {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::HOOK );
			wp_clear_scheduled_hook( self::MANUAL_HOOK );
		}
		ActionSchedulerAdapter::unschedule();
	}

	/**
	 * Queue one immediate background run requested from the dashboard.
	 *
	 * The manual hook is separate from the recurring hook so the cron health
	 * check continues to see exactly one argument-free recurring event.
	 *
	 * @return bool Whether a run is queued or was already pending.
	 */
	public function request_manual_run() {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return false;
		}

		if ( false !== wp_next_scheduled( self::MANUAL_HOOK ) ) {
			return true;
		}

		$scheduled = wp_schedule_single_event( time(), self::MANUAL_HOOK, array(), true );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $scheduled ) ) {
			return false;
		}

		return (bool) $scheduled;
	}

	/**
	 * Move the one logical dispatcher to Action Scheduler after initialization.
	 *
	 * @return void
	 */
	public function migrate_to_action_scheduler() {
		if ( ActionSchedulerAdapter::schedule() && function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}
}
