<?php
/**
 * Run the M7 SSL and storage checks through the real registry in WordPress.
 *
 * @package HealthLens
 */

use HealthLens\Application\CheckRunner;
use HealthLens\Application\WordPressCheckRegistry;
use HealthLens\Domain\CheckContext;

if ( ! function_exists( 'wp_get_upload_dir' ) ) {
	WP_CLI::error( 'HealthLens storage smoke requires the WordPress runtime.' );
}

$runner  = new CheckRunner( WordPressCheckRegistry::create() );
$results = $runner->run(
	new CheckContext(),
	array( 'ssl-https', 'filesystem-paths', 'disk-space', 'wordpress-storage-config' )
);

$expected = array( 'disk-space', 'filesystem-paths', 'ssl-https', 'wordpress-storage-config' );
$actual   = array_keys( $results );
sort( $actual, SORT_STRING );
if ( $expected !== $actual ) {
	WP_CLI::error( 'M7 storage checks did not return the expected normalized catalog.' );
}

foreach ( $results as $result ) {
	foreach ( $result->context()->to_array() as $value ) {
		if ( is_string( $value ) && preg_match( '#^(?:[A-Za-z]:[\\\\/]|/|https?://)#i', $value ) ) {
			WP_CLI::error( 'M7 storage context crossed the path or URL privacy boundary.' );
		}
	}
}

WP_CLI::success( 'M7 SSL and storage checks returned four bounded current-site results without paths, URLs, file contents, or recursive scans.' );
