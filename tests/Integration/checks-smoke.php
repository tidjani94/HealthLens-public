<?php
/**
 * Run the M3 catalog through the real HealthLens cron dispatcher.
 *
 * @package HealthLens
 */

if ( ! function_exists( 'do_action' ) || ! isset( $GLOBALS['wpdb'] ) ) {
	WP_CLI::error( 'HealthLens check smoke requires the WordPress runtime.' );
}

$registered_checks = count( \HealthLens\Application\WordPressCheckRegistry::create()->definitions() );
$batches           = (int) ceil( $registered_checks / \HealthLens\Application\CheckDispatcher::MAX_CHECKS );

for ( $batch = 0; $batch < $batches; ++$batch ) {
	do_action( 'healthlens_run_checks' );
}

$table = $GLOBALS['wpdb']->prefix . 'healthlens_results';
$rows  = $GLOBALS['wpdb']->get_results( "SELECT check_id, context_json FROM {$table} WHERE check_id IN ('administrator-email', 'loopback-requests', 'rest-api-availability', 'wp-cron-schedule', 'wordpress-version') ORDER BY check_id ASC", ARRAY_A );
$ids   = array_map(
	static function ( $row ) {
		return $row['check_id'];
	},
	$rows
);
sort( $ids, SORT_STRING );

$expected = array(
	'administrator-email',
	'loopback-requests',
	'rest-api-availability',
	'wp-cron-schedule',
	'wordpress-version',
);
sort( $expected, SORT_STRING );

if ( $expected !== $ids ) {
	WP_CLI::error( 'M3 check dispatcher did not persist the expected catalog.' );
}

foreach ( $rows as $row ) {
	if ( false !== strpos( $row['context_json'], '@' ) || false !== strpos( $row['context_json'], 'http' ) ) {
		WP_CLI::error( 'M3 check context contains a prohibited email or URL.' );
	}
}

WP_CLI::success( 'M3 checks executed across bounded cron batches, persisted five normalized rows, and retained no email or URL context.' );
