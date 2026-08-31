<?php
/**
 * Verify the native settings boundary in a real WordPress runtime.
 *
 * @package HealthLens
 */

use HealthLens\Plugin;
use HealthLens\Presentation\Admin\DashboardPage;
use HealthLens\Presentation\Admin\SettingsPage;

wp_set_current_user( 1 );

$page     = new SettingsPage();
$original = get_option( Plugin::SETTINGS_OPTION, array( SettingsPage::RETENTION_FIELD => false ) );
$page->register();
$privacy_context_error = false;
$privacy_context_hook  = function ( $function_name ) use ( &$privacy_context_error ) {
	if ( 'wp_add_privacy_policy_content' === $function_name ) {
		$privacy_context_error = true;
	}
};
add_action( 'doing_it_wrong_run', $privacy_context_hook, 10, 3 );
defined( 'WP_ADMIN' ) || define( 'WP_ADMIN', true );
do_action( 'admin_init' );
remove_action( 'doing_it_wrong_run', $privacy_context_hook, 10 );

if ( $privacy_context_error ) {
	WP_CLI::error( 'The settings smoke fired admin_init outside an admin context.' );
}

$valid = $page->sanitize_settings( array( SettingsPage::RETENTION_FIELD => '1' ) );
update_option( Plugin::SETTINGS_OPTION, $valid, false );
if ( empty( get_option( Plugin::SETTINGS_OPTION )[ SettingsPage::RETENTION_FIELD ] ) ) {
	WP_CLI::error( 'Valid HealthLens retention setting was not persisted.' );
}

if ( isset( wp_load_alloptions()[ Plugin::SETTINGS_OPTION ] ) ) {
	WP_CLI::error( 'HealthLens settings became autoloaded.' );
}

$invalid = $page->sanitize_settings( array( SettingsPage::RETENTION_FIELD => 'unexpected' ) );
if ( empty( $invalid[ SettingsPage::RETENTION_FIELD ] ) ) {
	WP_CLI::error( 'Invalid HealthLens retention input changed the setting.' );
}

wp_set_current_user( 1 );
$before = did_action( 'healthlens_run_checks' );
ob_start();
( new DashboardPage() )->render();
ob_end_clean();
$after = did_action( 'healthlens_run_checks' );
if ( $before !== $after ) {
	WP_CLI::error( 'Dashboard rendering executed the HealthLens check dispatcher.' );
}

update_option( Plugin::SETTINGS_OPTION, is_array( $original ) ? $original : array( SettingsPage::RETENTION_FIELD => false ), false );
WP_CLI::success( 'HealthLens settings valid/invalid, non-autoload, and no-request-execution checks passed.' );
