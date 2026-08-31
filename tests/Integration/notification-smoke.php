<?php
/**
 * Verify bounded opt-in notification behavior in WordPress.
 *
 * @package HealthLens
 */

use HealthLens\Application\NotificationDispatcher;
use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckResult;
use HealthLens\Infrastructure\WordPress\NotificationStateRepository;

if ( ! function_exists( 'wp_mail' ) ) {
	WP_CLI::error( 'HealthLens notification smoke requires the WordPress mail API.' );
}

$original_settings = get_option( 'healthlens_settings', array() );
$original_state    = get_option( NotificationStateRepository::OPTION, false );
$warning           = new CheckResult(
	CheckResult::STATE_ISSUE,
	CheckResult::SEVERITY_WARNING,
	'notification.smoke-warning',
	new CheckContext( array( 'safe_flag' => true, 'message' => 'must-not-be-used' ) ),
	new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
	1
);

update_option( 'healthlens_settings', array( 'notifications_enabled' => false ), false );
$state = new NotificationStateRepository();
if ( 0 !== ( new NotificationDispatcher( $state ) )->dispatch( array( 'smoke-warning' => $warning ) ) ) {
	WP_CLI::error( 'Disabled HealthLens notifications attempted delivery.' );
}

$GLOBALS['healthlens_notification_mail'] = array();
$capture = static function ( $pre, $atts ) {
	$GLOBALS['healthlens_notification_mail'][] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $capture, 10, 2 );
update_option( 'healthlens_settings', array( 'notifications_enabled' => true, 'notification_email' => 'owner@example.org', 'notification_recovery' => true ), false );
$attempts = ( new NotificationDispatcher( $state ) )->dispatch( array( 'smoke-warning' => $warning ) );
remove_filter( 'pre_wp_mail', $capture, 10 );

if ( 1 !== $attempts || 1 !== count( $GLOBALS['healthlens_notification_mail'] ) || 1 !== $state->summary()['sent_count'] ) {
	WP_CLI::error( 'Enabled HealthLens notification did not produce one bounded captured attempt.' );
}

$mail = $GLOBALS['healthlens_notification_mail'][0];
if ( false !== strpos( (string) $mail['message'], 'must-not-be-used' ) || false !== strpos( (string) $mail['message'], 'http' ) ) {
	WP_CLI::error( 'HealthLens notification crossed the raw-context privacy boundary.' );
}

if ( false === $original_state ) {
	delete_option( NotificationStateRepository::OPTION );
} else {
	update_option( NotificationStateRepository::OPTION, $original_state, false );
}
update_option( 'healthlens_settings', is_array( $original_settings ) ? $original_settings : array(), false );
WP_CLI::success( 'HealthLens notifications stayed disabled by default and produced one bounded captured opt-in attempt without raw context.' );
