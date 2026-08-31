<?php
/**
 * Exercise the real WordPress lifecycle against a clean site.
 *
 * @package HealthLens
 */

use HealthLens\Plugin;
use HealthLens\Infrastructure\Database\SchemaManager;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

global $wpdb;

$plugin_file = 'healthlens/healthlens.php';
$uninstall_file = WP_PLUGIN_DIR . '/healthlens/uninstall.php';
$assert      = static function ( $condition, $message ) {
	if ( ! $condition ) {
		WP_CLI::error( $message );
	}
};

$table_exists = static function ( $table ) use ( $wpdb ) {
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
};

// Start from a clean site so repeated smoke runs have the same result.
if ( is_plugin_active( $plugin_file ) ) {
	deactivate_plugins( $plugin_file );
}
uninstall_plugin( $plugin_file );

$activation_error = activate_plugin( $plugin_file );
$assert( ! is_wp_error( $activation_error ), 'Fresh activation failed.' );
$assert( is_plugin_active( $plugin_file ), 'HealthLens is not active after fresh activation.' );

$schema = new SchemaManager( $wpdb );
$assert(
	array(
		'retain_data_on_uninstall' => false,
		Plugin::CAPTURE_FIELD => false,
		'notifications_enabled' => false,
		'notification_email' => '',
		'notification_recovery' => true,
		'gateway_enabled' => false,
		'gateway_endpoint' => '',
		'gateway_token' => '',
	) === get_option( Plugin::SETTINGS_OPTION ),
	'Fresh activation did not create the default settings.'
);
$assert( Plugin::SCHEMA_VERSION === (int) get_option( Plugin::SCHEMA_OPTION ), 'Fresh activation did not store the current schema version.' );
$assert( '0.1.0' === get_option( Plugin::VERSION_OPTION ), 'Fresh activation did not store the plugin version.' );
$assert( false !== wp_next_scheduled( 'healthlens_run_checks' ), 'Fresh activation did not schedule the HealthLens event.' );
$assert( $table_exists( $schema->results_table() ), 'Fresh activation did not create the results table.' );
$assert( $table_exists( $schema->incidents_table() ), 'Fresh activation did not create the incidents table.' );
$assert( $table_exists( $schema->errors_table() ), 'Fresh activation did not create the error-event table.' );

// An update of an active plugin does not invoke the activation hook. A new
// bootstrap must still upgrade the schema and record the current version.
update_option( Plugin::SCHEMA_OPTION, 1, false );
update_option( Plugin::VERSION_OPTION, '0.0.9', false );
( new Plugin() )->boot();
$assert( Plugin::SCHEMA_VERSION === (int) get_option( Plugin::SCHEMA_OPTION ), 'Active-plugin upgrade did not migrate the schema.' );
$assert( '0.1.0' === get_option( Plugin::VERSION_OPTION ), 'Active-plugin upgrade did not record the current version.' );

// Verify the explicit retention contract, then remove the retained data for
// the next assertion and for the following smoke tests.
deactivate_plugins( $plugin_file );
update_option( Plugin::SETTINGS_OPTION, array( 'retain_data_on_uninstall' => true ), false );
include $uninstall_file;
$assert( false !== get_option( Plugin::SETTINGS_OPTION, false ), 'Retention-enabled uninstall removed the settings.' );
$assert( $table_exists( $schema->results_table() ), 'Retention-enabled uninstall removed the results table.' );

update_option( Plugin::SETTINGS_OPTION, array( 'retain_data_on_uninstall' => false ), false );
include $uninstall_file;
$assert( false === get_option( Plugin::SETTINGS_OPTION, false ), 'Default uninstall retained the settings.' );
$assert( false === get_option( Plugin::SCHEMA_OPTION, false ), 'Default uninstall retained the schema option.' );
$assert( ! $table_exists( $schema->results_table() ), 'Default uninstall retained the results table.' );
$assert( ! $table_exists( $schema->incidents_table() ), 'Default uninstall retained the incidents table.' );
$assert( false === wp_next_scheduled( 'healthlens_run_checks' ), 'Default uninstall retained the scheduled event.' );

$activation_error = activate_plugin( $plugin_file );
$assert( ! is_wp_error( $activation_error ), 'Reactivation after uninstall failed.' );
$assert( is_plugin_active( $plugin_file ), 'HealthLens is not active after reactivation.' );

WP_CLI::success( 'HealthLens install, upgrade, retention-aware uninstall, cleanup, and reactivation smoke passed.' );
