<?php
/**
 * WordPress Options API lock.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress;

/**
 * Provides a per-site, non-autoloaded, expiring option lock.
 */
final class OptionLock {
	/**
	 * Option name.
	 *
	 * @var string
	 */
	private $option_name;

	/**
	 * Create an option lock for one site.
	 *
	 * @param string $option_name Plugin-owned option name.
	 */
	public function __construct( $option_name ) {
		$this->option_name = $option_name;
	}

	/**
	 * Acquire the lock if it is absent or expired.
	 *
	 * A valid existing lock is never overwritten. Expiry recovery deletes only
	 * the expired HealthLens option and then uses add_option with autoload off.
	 *
	 * @param int $ttl_seconds Lock lifetime.
	 * @return string|false Opaque owner token, or false when held/unavailable.
	 */
	public function acquire( $ttl_seconds ) {
		if ( ! function_exists( 'add_option' ) || ! function_exists( 'get_option' ) ) {
			return false;
		}

		$existing = get_option( $this->option_name, null );
		$now      = time();

		if ( null !== $existing ) {
			if ( ! is_array( $existing ) || ! isset( $existing['token'], $existing['expires_at'] ) ) {
				return false;
			}

			if ( (int) $existing['expires_at'] > $now ) {
				return false;
			}

			if ( function_exists( 'delete_option' ) ) {
				delete_option( $this->option_name );
			}
		}

		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : hash( 'sha256', uniqid( 'healthlens-', true ) );
		$lock  = array(
			'token'      => $token,
			'expires_at' => $now + max( 1, (int) $ttl_seconds ),
		);

		return add_option( $this->option_name, $lock, '', false ) ? $token : false;
	}

	/**
	 * Release the lock only when this owner still holds it.
	 *
	 * @param string $token Owner token.
	 * @return bool
	 */
	public function release( $token ) {
		if ( '' === $token || ! function_exists( 'get_option' ) || ! function_exists( 'delete_option' ) ) {
			return false;
		}

		$lock = get_option( $this->option_name, null );
		if ( ! is_array( $lock ) || ! isset( $lock['token'] ) || ! hash_equals( (string) $lock['token'], $token ) ) {
			return false;
		}

		return delete_option( $this->option_name );
	}
}
