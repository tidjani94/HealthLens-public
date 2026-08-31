<?php
/**
 * Optional Action Scheduler backend.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress;

use Throwable;

/**
 * Runtime-gated adapter that never becomes a hard dependency.
 */
final class ActionSchedulerAdapter {
	/** Stable Action Scheduler group. */
	const GROUP = 'healthlens';

	/**
	 * Determine whether Action Scheduler is initialized and supports the needed API.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_unschedule_all_actions' ) && function_exists( 'did_action' ) && did_action( 'action_scheduler_init' ) > 0;
	}

	/**
	 * Schedule the logical HealthLens dispatcher once.
	 *
	 * @return bool Whether Action Scheduler owns the backend.
	 */
	public static function schedule() {
		if ( ! self::available() ) {
			return false;
		}

		try {
			if ( false === self::invoke( 'as_has_scheduled_action', array( CronScheduler::HOOK, array(), self::GROUP ) ) ) {
				self::invoke( 'as_schedule_recurring_action', array( time() + CronScheduler::INTERVAL, CronScheduler::INTERVAL, CronScheduler::HOOK, array(), self::GROUP, true ) );
			}
			return true;
		} catch ( Throwable $throwable ) {
			return false;
		}
	}

	/**
	 * Remove only HealthLens-owned Action Scheduler work.
	 *
	 * @return bool
	 */
	public static function unschedule() {
		if ( ! self::available() ) {
			return false;
		}

		try {
			self::invoke( 'as_unschedule_all_actions', array( CronScheduler::HOOK, array(), self::GROUP ) );
			return true;
		} catch ( Throwable $throwable ) {
			return false;
		}
	}

	/**
	 * Invoke an optional function only after a runtime callable check.
	 *
	 * @param string $function_name Function name.
	 * @param array  $arguments Function arguments.
	 * @return mixed
	 */
	private static function invoke( $function_name, array $arguments ) {
		if ( ! is_callable( $function_name ) ) {
			return null;
		}

		return call_user_func_array( $function_name, $arguments );
	}
}
