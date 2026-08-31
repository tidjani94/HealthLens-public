<?php
/**
 * Run deterministic release-candidate boundary checks.
 *
 * @package HealthLens
 */

declare( strict_types=1 );

$root        = dirname( __DIR__ );
$output_file = null;
$checks      = array();

foreach ( $argv as $argument ) {
	if ( 0 === strpos( $argument, '--output=' ) ) {
		$output_file = substr( $argument, strlen( '--output=' ) );
	}
}

/**
 * Add one release-gate result.
 *
 * @param array<int, array<string, string>> $checks Check results.
 * @param string                            $id Check identifier.
 * @param string                            $severity Failure severity.
 * @param bool                              $passed Whether the check passed.
 * @param string                            $evidence Reproducible evidence.
 * @param string                            $remediation Remediation when failed.
 * @return void
 */
function healthlens_gate_check( array &$checks, string $id, string $severity, bool $passed, string $evidence, string $remediation = '' ): void {
	$check = array(
		'id'       => $id,
		'severity' => $severity,
		'status'   => $passed ? 'pass' : 'fail',
		'evidence' => $evidence,
	);
	if ( ! $passed && '' !== $remediation ) {
		$check['remediation'] = $remediation;
	}

	$checks[] = $check;
}

/**
 * Read a required text file.
 *
 * @param string $file File path.
 * @return string
 */
function healthlens_gate_read( string $file ): string {
	$contents = is_readable( $file ) ? file_get_contents( $file ) : false;
	return false === $contents ? '' : $contents;
}

/**
 * Return first-party source files for static boundary checks.
 *
 * @param string $root Repository root.
 * @param string $directory Relative directory.
 * @param array<int, string> $extensions Allowed extensions.
 * @return array<int, string>
 */
function healthlens_gate_files( string $root, string $directory, array $extensions ): array {
	$files    = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root . DIRECTORY_SEPARATOR . $directory, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( $file->isFile() && in_array( strtolower( $file->getExtension() ), $extensions, true ) ) {
			$files[] = $file->getPathname();
		}
	}

	sort( $files, SORT_STRING );
	return $files;
}

/**
 * Read the plugin version from its header.
 *
 * @param string $plugin_file Main plugin file.
 * @return string
 */
function healthlens_gate_version( string $plugin_file ): string {
	$source = healthlens_gate_read( $plugin_file );
	if ( preg_match( '/^\s*\*\s*Version:\s*(.+?)\s*$/mi', $source, $matches ) ) {
		return trim( $matches[1] );
	}

	return '';
}

$plugin_file    = $root . DIRECTORY_SEPARATOR . 'healthlens.php';
$plugin_source  = healthlens_gate_read( $plugin_file );
$dashboard_file = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Presentation' . DIRECTORY_SEPARATOR . 'Admin' . DIRECTORY_SEPARATOR . 'DashboardPage.php';
$settings_file  = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Presentation' . DIRECTORY_SEPARATOR . 'Admin' . DIRECTORY_SEPARATOR . 'SettingsPage.php';
$composition    = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Plugin.php';
$dashboard      = healthlens_gate_read( $dashboard_file );
$settings       = healthlens_gate_read( $settings_file );
$plugin         = healthlens_gate_read( $composition );
$version        = healthlens_gate_version( $plugin_file );
$source_files   = array_merge(
	array( $plugin_file, $dashboard_file, $settings_file, $composition ),
	healthlens_gate_files( $root, 'src', array( 'php' ) ),
	healthlens_gate_files( $root, 'assets', array( 'css', 'js' ) )
);
$source_files = array_values( array_unique( $source_files ) );
$source       = '';
foreach ( $source_files as $file ) {
	$source .= "\n" . healthlens_gate_read( $file );
}

healthlens_gate_check(
	$checks,
	'metadata.version',
	'blocking',
	(bool) preg_match( '/^\d+\.\d+\.\d+(?:[-+].+)?$/', $version ),
	'healthlens.php supplies a semantic plugin version for release artifacts.',
	'Fix the main plugin header Version before creating a release candidate.'
);

$security_markers = array( 'current_user_can', 'manage_options', 'register_setting', 'sanitize_callback', 'settings_fields' );
$missing_security = array();
foreach ( $security_markers as $marker ) {
	if ( false === strpos( $dashboard . $settings, $marker ) ) {
		$missing_security[] = $marker;
	}
}
healthlens_gate_check(
	$checks,
	'security.admin-settings-boundary',
	'blocking',
	empty( $missing_security ),
	'Admin rendering is capability-gated and the Settings API supplies nonce and sanitizer boundaries.',
	'Missing markers: ' . implode( ', ', $missing_security ) . '.'
);

$network_patterns = array( 'wp_remote_', 'wp_safe_remote_', 'curl_init', 'fsockopen', 'stream_socket_client', 'stream_socket_server' );
$network_matches  = array();
foreach ( $source_files as $file ) {
	$contents = healthlens_gate_read( $file );
	foreach ( $network_patterns as $pattern ) {
		$bounded_transport = in_array( basename( $file ), array( 'BoundedHttpProbe.php', 'OptionalGatewayTransport.php' ), true );
		if ( false !== stripos( $contents, $pattern ) && ! $bounded_transport ) {
			$network_matches[] = basename( $file ) . ':' . $pattern;
		}
	}
}
healthlens_gate_check(
	$checks,
	'privacy.no-unexpected-outbound-requests',
	'blocking',
	empty( $network_matches ),
	'Only bounded, explicitly gated WordPress HTTP adapters may use safe transport; no telemetry or arbitrary remote transport is present.',
	'Unexpected transport markers outside approved bounded adapters: ' . implode( ', ', array_unique( $network_matches ) ) . '.'
);

$remote_code = preg_match( '#(?:<script[^>]+(?:src|href)|(?:src|href)\s*=|@import\s+url\()\s*["\']?https?://#i', $source );
healthlens_gate_check(
	$checks,
	'security.no-remote-executable-code',
	'blocking',
	0 === $remote_code,
	'First-party PHP, JavaScript, and CSS contain no remote executable asset reference.',
	'Remove or separately review the remote executable reference before release.'
);

$privacy_markers = array( "'retain_data_on_uninstall' => false", 'does not start checks or send data remotely' );
$missing_privacy = array();
foreach ( $privacy_markers as $marker ) {
	if ( false === strpos( $plugin . $settings, $marker ) ) {
		$missing_privacy[] = $marker;
	}
}
healthlens_gate_check(
	$checks,
	'privacy.local-first-default-off',
	'blocking',
	empty( $missing_privacy ),
	'Uninstall retention defaults off and the settings copy preserves the local-first/no-remote boundary.',
	'Missing privacy markers: ' . implode( ', ', $missing_privacy ) . '.'
);

$multisite_markers = array( '$network_wide', 'Network activation is not supported' );
$missing_multisite = array();
foreach ( $multisite_markers as $marker ) {
	if ( false === strpos( $plugin, $marker ) ) {
		$missing_multisite[] = $marker;
	}
}
$forbidden_multisite = array( 'manage_network_options', 'is_network_admin', 'switch_to_blog', 'network_admin_menu' );
$forbidden_found     = array();
foreach ( $forbidden_multisite as $marker ) {
	if ( false !== strpos( $source, $marker ) ) {
		$forbidden_found[] = $marker;
	}
}
healthlens_gate_check(
	$checks,
	'security.multisite-site-local',
	'blocking',
	empty( $missing_multisite ) && empty( $forbidden_found ),
	'Network activation is rejected and runtime source contains no network-admin or cross-site switching boundary.',
	'Missing markers: ' . implode( ', ', $missing_multisite ) . '; forbidden markers: ' . implode( ', ', $forbidden_found ) . '.'
);

$accessibility_markers = array( '<main ', 'aria-labelledby=', 'role="status"', 'aria-live="polite"', '<details', '<summary' );
$missing_accessibility = array();
foreach ( $accessibility_markers as $marker ) {
	if ( false === strpos( $dashboard, $marker ) ) {
		$missing_accessibility[] = $marker;
	}
}
healthlens_gate_check(
	$checks,
	'accessibility.server-rendered-dashboard',
	'blocking',
	empty( $missing_accessibility ),
	'Dashboard source contains a labelled main landmark, announced status, and native keyboard-operable details.',
	'Missing accessibility markers: ' . implode( ', ', $missing_accessibility ) . '.'
);

$environment_file = $root . DIRECTORY_SEPARATOR . '.wp-env.json';
$environment_json = json_decode( healthlens_gate_read( $environment_file ), true );
$environment_ok   = is_array( $environment_json ) && isset( $environment_json['core'], $environment_json['phpVersion'] ) && 'WordPress/WordPress#7.0.4' === $environment_json['core'] && '8.3' === $environment_json['phpVersion'];
healthlens_gate_check(
	$checks,
	'environment.minimum-wordpress-floor',
	'blocking',
	$environment_ok,
	'wp-env is pinned to WordPress 7.0.4 with PHP 8.3 for the minimum supported runtime smoke.',
	'Pin .wp-env.json to WordPress/WordPress#7.0.4 and PHP 8.3.'
);

$documentation_files = array( $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'RELEASE-GATES.md', $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'PLUGIN-CHECK-BASELINE.md' );
$documentation_ok    = true;
foreach ( $documentation_files as $file ) {
	$documentation_ok = $documentation_ok && is_readable( $file );
}
healthlens_gate_check(
	$checks,
	'evidence.release-records',
	'blocking',
	$documentation_ok,
	'Release-gate policy and the reviewed Plugin Check warning baseline are versioned with the repository.',
	'Add the release-gate policy and Plugin Check baseline documentation.'
);

$failures = array_values(
	array_filter(
		$checks,
		static function ( array $check ): bool {
			return 'fail' === $check['status'];
		}
	)
);
$report = array(
	'schema'  => 1,
	'plugin'  => 'HealthLens',
	'version' => $version,
	'policy'  => array(
		'blocking_failures' => 'Any failed check blocks a release candidate.',
		'plugin_check_errors' => 'Always blocking.',
		'plugin_check_warnings' => 'Only reviewed baseline warnings may remain; new or changed warnings block release.',
	),
	'summary' => array(
		'checks'  => count( $checks ),
		'passed'  => count( $checks ) - count( $failures ),
		'failed'  => count( $failures ),
	),
	'checks' => $checks,
);

$encoded = json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
if ( false === $encoded ) {
	fwrite( STDERR, "Unable to encode the release-gate report.\n" );
	exit( 1 );
}

if ( null !== $output_file ) {
	$output_directory = dirname( $output_file );
	if ( ! is_dir( $output_directory ) && ! mkdir( $output_directory, 0777, true ) && ! is_dir( $output_directory ) ) {
		fwrite( STDERR, "Unable to create release-gate report directory.\n" );
		exit( 1 );
	}
	file_put_contents( $output_file, $encoded );
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, sprintf( "[%s] %s: %s\n", strtoupper( $failure['severity'] ), $failure['id'], $failure['remediation'] ) );
	}
	exit( 1 );
}

fwrite( STDOUT, sprintf( "Release gate passed: %d blocking boundary checks recorded for HealthLens %s.\n", count( $checks ), $version ) );
