<?php
/**
 * Verify the opt-in error-capture boundary in a real WordPress runtime.
 *
 * @package HealthLens
 */

use HealthLens\Application\ErrorCapture\ErrorEventCollector;
use HealthLens\Infrastructure\Database\ErrorEventRepository;
use HealthLens\Infrastructure\Database\NullErrorEventRepository;
use HealthLens\Infrastructure\Database\SchemaManager;
use HealthLens\Infrastructure\WordPress\ErrorCaptureBootstrap;

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	WP_CLI::error( 'HealthLens error-capture smoke requires the WordPress runtime.' );
}

$original_settings        = get_option( 'healthlens_settings', array() );
$settings                 = is_array( $original_settings ) ? $original_settings : array();
$settings['capture_errors'] = false;
update_option( 'healthlens_settings', $settings, false );
$disabled = new ErrorEventCollector( new NullErrorEventRepository(), false );
if ( ( new ErrorCaptureBootstrap( $disabled ) )->register() ) {
	WP_CLI::error( 'Disabled error capture installed a PHP handler.' );
}

$settings['capture_errors'] = true;
update_option( 'healthlens_settings', $settings, false );
$schema     = new SchemaManager( $GLOBALS['wpdb'] );
$repository = new ErrorEventRepository( $GLOBALS['wpdb'], $schema );
$collector  = new ErrorEventCollector( $repository, true );
$bootstrap  = new ErrorCaptureBootstrap( $collector );
if ( ! $bootstrap->register() ) {
	WP_CLI::error( 'Enabled error capture did not register its bounded handlers.' );
}

@trigger_error( 'HealthLens controlled smoke message: secret-value', E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Deliberate isolated integration fixture; @ prevents the synthetic message from entering CI output.
$bootstrap->restore();

$rows = $repository->recent( 10 );
if ( empty( $rows ) ) {
	WP_CLI::error( 'Controlled non-fatal error was not persisted.' );
}

$serialized = wp_json_encode( $rows );
if ( false !== strpos( $serialized, 'secret-value' ) || false !== strpos( $serialized, 'error-capture-smoke.php' ) || false !== strpos( $serialized, 'http' ) ) {
	WP_CLI::error( 'Captured error data crossed the redaction boundary.' );
}

update_option( 'healthlens_settings', is_array( $original_settings ) ? $original_settings : array(), false );
WP_CLI::success( 'Opt-in error capture persisted one bounded non-fatal event without storing its message, path, or URL.' );
