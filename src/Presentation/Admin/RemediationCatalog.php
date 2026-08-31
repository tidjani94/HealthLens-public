<?php
/**
 * Safe next-step destinations for dashboard check results.
 *
 * @package HealthLens
 */

namespace HealthLens\Presentation\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Maps actionable checks to fixed, native WordPress admin screens.
 *
 * This catalog deliberately does not use URLs or destinations from check
 * context. A check can only expose a destination that HealthLens explicitly
 * reviewed as a safe read-only navigation target.
 */
final class RemediationCatalog {
	/**
	 * Return a safe next step for a stable check ID.
	 *
	 * @param mixed $check_id Stable check identifier.
	 * @return array{path: string, label: string}|null
	 */
	public function for_check( $check_id ) {
		if ( ! is_string( $check_id ) ) {
			return null;
		}

		$destinations = array(
			'wordpress-version'     => array(
				'path'  => 'update-core.php',
				'label' => __( 'Review WordPress updates', 'healthlens' ),
			),
			'administrator-email'   => array(
				'path'  => 'options-general.php',
				'label' => __( 'Review General Settings', 'healthlens' ),
			),
			'ssl-https'             => array(
				'path'  => 'options-general.php',
				'label' => __( 'Review site URL settings', 'healthlens' ),
			),
			'rest-api-availability' => array(
				'path'  => 'site-health.php',
				'label' => __( 'Open Site Health', 'healthlens' ),
			),
			'loopback-requests'     => array(
				'path'  => 'site-health.php',
				'label' => __( 'Open Site Health', 'healthlens' ),
			),
			'wp-cron-schedule'      => array(
				'path'  => 'site-health.php',
				'label' => __( 'Open Site Health', 'healthlens' ),
			),
		);

		return isset( $destinations[ $check_id ] ) ? $destinations[ $check_id ] : null;
	}
}
