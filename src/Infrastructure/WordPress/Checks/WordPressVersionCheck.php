<?php
/**
 * WordPress core version and cached-update check.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress\Checks;

// phpcs:disable WordPress.WP.CapitalPDangit.MisspelledInText -- Machine-readable message codes use a lower-case namespace.

use DateTimeImmutable;
use DateTimeZone;
use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckDefinition;
use HealthLens\Domain\CheckResult;
use HealthLens\Domain\HealthCheckInterface;

/**
 * Checks the installed WordPress version against cached core updates.
 */
final class WordPressVersionCheck implements HealthCheckInterface {
	const CADENCE = 86400;

	/** Version reader callback.
	 *
	 * @var callable|null
	 */
	private $version_reader;
	/** Update reader callback.
	 *
	 * @var callable|null
	 */
	private $updates_reader;

	/** Create the check.
	 *
	 * @param callable|null $version_reader Version reader double.
	 * @param callable|null $updates_reader Update reader double.
	 */
	public function __construct( $version_reader = null, $updates_reader = null ) {
		$this->version_reader = is_callable( $version_reader ) ? $version_reader : null;
		$this->updates_reader = is_callable( $updates_reader ) ? $updates_reader : null;
	}

	/** Describe the check.
	 *
	 * @return CheckDefinition
	 */
	public function definition(): CheckDefinition {
		return new CheckDefinition( 'wordpress-version', 'wordpress', 5, self::CADENCE );
	}

	/** Read the installed version.
	 *
	 * @param CheckContext $context Execution context.
	 * @return CheckResult
	 */
	public function run( CheckContext $context ): CheckResult {
		$installed = $this->read_version();
		if ( '' === $installed ) {
			return $this->result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING, 'wordpress.version-unavailable', array( 'update_state' => 'installed-version-unavailable' ) );
		}

		$updates = $this->read_updates();
		if ( ! is_array( $updates ) ) {
			return $this->result(
				CheckResult::STATE_UNKNOWN,
				CheckResult::SEVERITY_WARNING,
				'wordpress.update-data-unavailable',
				array(
					'installed_version' => $installed,
					'update_state'      => 'unavailable',
				)
			);
		}

		$available_version = '';
		foreach ( $updates as $update ) {
			$response = is_array( $update ) ? ( $update['response'] ?? '' ) : ( is_object( $update ) && isset( $update->response ) ? $update->response : '' );
			$version  = is_array( $update ) ? ( $update['version'] ?? '' ) : ( is_object( $update ) && isset( $update->version ) ? $update->version : '' );
			if ( 'upgrade' === $response && is_string( $version ) && '' !== $version && version_compare( $version, $installed, '>' ) ) {
				$available_version = $version;
				break;
			}
		}

		if ( '' !== $available_version ) {
			return $this->result(
				CheckResult::STATE_ISSUE,
				CheckResult::SEVERITY_WARNING,
				'wordpress.update-available',
				array(
					'installed_version' => $installed,
					'update_available'  => true,
					'update_state'      => 'available',
					'update_version'    => $available_version,
				)
			);
		}

		return $this->result(
			CheckResult::STATE_HEALTHY,
			CheckResult::SEVERITY_HEALTHY,
			'wordpress.version-current',
			array(
				'installed_version' => $installed,
				'update_available'  => false,
				'update_state'      => 'current',
			)
		);
	}

	/** Read cached core update data.
	 *
	 * @return string
	 */
	private function read_version() {
		if ( $this->version_reader ) {
			return (string) call_user_func( $this->version_reader );
		}

		return function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '';
	}

	/** Build a normalized result.
	 *
	 * @return mixed
	 */
	private function read_updates() {
		if ( $this->updates_reader ) {
			return call_user_func( $this->updates_reader );
		}

		return function_exists( 'get_core_updates' ) ? get_core_updates() : null;
	}

	/** Build a normalized result.
	 *
	 * @param string                     $state Result state.
	 * @param string                     $severity Result severity.
	 * @param string                     $message_code Stable message code.
	 * @param array<string, bool|string> $values Safe context values.
	 * @return CheckResult
	 */
	private function result( $state, $severity, $message_code, array $values ) {
		return new CheckResult( $state, $severity, $message_code, new CheckContext( $values ), new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ), 0 );
	}
}
