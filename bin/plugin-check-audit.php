<?php
/**
 * Audit a Plugin Check report against the reviewed warning baseline.
 *
 * @package HealthLens
 */

declare( strict_types=1 );

use HealthLens\Release\PluginCheckReportAuditor;

$root = dirname( __DIR__ );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$report_file   = isset( $argv[1] ) ? $argv[1] : '';
$baseline_file = isset( $argv[2] ) ? $argv[2] : $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'PLUGIN-CHECK-BASELINE.json';

if ( '' === $report_file || ! is_readable( $report_file ) || ! is_readable( $baseline_file ) ) {
	fwrite( STDERR, "Usage: php bin/plugin-check-audit.php REPORT [BASELINE.json]\n" );
	exit( 2 );
}

$baseline = json_decode( file_get_contents( $baseline_file ), true );
if ( ! is_array( $baseline ) || ! isset( $baseline['warnings'] ) || ! is_array( $baseline['warnings'] ) ) {
	fwrite( STDERR, "Plugin Check baseline is not valid.\n" );
	exit( 2 );
}

try {
	$result = ( new PluginCheckReportAuditor() )->audit_json( file_get_contents( $report_file ), $baseline['warnings'] );
} catch ( InvalidArgumentException $exception ) {
	fwrite( STDERR, $exception->getMessage() . "\n" );
	exit( 2 );
}

printf(
	"Plugin Check findings: %d errors, %d warnings (%d reviewed, %d unreviewed).\n",
	count( $result['errors'] ),
	count( $result['warnings'] ),
	count( $result['baseline_warnings'] ),
	count( $result['unreviewed_warnings'] )
);

if ( ! empty( $result['errors'] ) ) {
	fwrite( STDERR, "Plugin Check errors block this release.\n" );
}

if ( ! empty( $result['unreviewed_warnings'] ) ) {
	fwrite( STDERR, "New or changed Plugin Check warnings block this release.\n" );
}

exit( $result['blocking'] ? 1 : 0 );
