<?php
/**
 * Verify the dashboard controller in a real WordPress runtime.
 *
 * @package HealthLens
 */

use HealthLens\Presentation\Admin\DashboardPage;

$page = new DashboardPage();

wp_set_current_user( 1 );
$checks_before = did_action( 'healthlens_run_checks' );
ob_start();
$page->render();
$authorized_output = ob_get_clean();
$checks_after = did_action( 'healthlens_run_checks' );

if ( $checks_before !== $checks_after ) {
	WP_CLI::error( 'Dashboard rendering dispatched HealthLens checks instead of queuing background work.' );
}

if ( false === strpos( $authorized_output, 'id="healthlens-dashboard"' ) ) {
	WP_CLI::error( 'Authorized HealthLens dashboard render did not produce its main landmark.' );
}

if ( false === strpos( $authorized_output, 'name="action" value="healthlens_request_run"' ) || false === strpos( $authorized_output, 'Run checks now' ) ) {
	WP_CLI::error( 'Authorized HealthLens dashboard did not render the manual background-run control.' );
}

if ( false === strpos( $authorized_output, 'Version ' . HEALTHLENS_VERSION ) || false === strpos( $authorized_output, 'Crafted with ❤️ by' ) || false === strpos( $authorized_output, 'https://coodiv.net' ) ) {
	WP_CLI::error( 'Authorized HealthLens dashboard did not render version and team attribution metadata.' );
}

$denied = false;
add_filter(
	'wp_die_handler',
	function () use ( &$denied ) {
		return function () use ( &$denied ) {
			$denied = true;
			throw new RuntimeException( 'HealthLens dashboard access denied.' );
		};
	}
);

wp_set_current_user( 0 );
try {
	$page->render();
} catch ( RuntimeException $exception ) {
	if ( 'HealthLens dashboard access denied.' !== $exception->getMessage() ) {
		throw $exception;
	}
}

if ( ! $denied ) {
	WP_CLI::error( 'Unauthorized HealthLens dashboard access was not denied.' );
}

WP_CLI::success( 'HealthLens dashboard authorized and unauthorized access checks passed.' );
