<?php
/**
 * HealthLens uninstall handler.
 *
 * @package HealthLens
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$healthlens_autoloader = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $healthlens_autoloader ) ) {
	require_once $healthlens_autoloader;
}

$healthlens_settings = get_option( 'healthlens_settings', array() );

if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
	wp_clear_scheduled_hook( 'healthlens_run_checks' );
	wp_clear_scheduled_hook( 'healthlens_run_checks_now' );
}

if ( is_array( $healthlens_settings ) && ! empty( $healthlens_settings['retain_data_on_uninstall'] ) ) {
	return;
}

delete_option( 'healthlens_settings' );
delete_option( 'healthlens_schema_version' );
delete_option( 'healthlens_lock' );
delete_option( 'healthlens_plugin_version' );
delete_option( 'healthlens_notification_state' );

if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && class_exists( '\HealthLens\Infrastructure\Database\SchemaManager' ) ) {
	( new \HealthLens\Infrastructure\Database\SchemaManager( $GLOBALS['wpdb'] ) )->drop();
}
