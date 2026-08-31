<?php
/**
 * Run the M5 database checks through the real registry in WordPress.
 *
 * @package HealthLens
 */

use HealthLens\Application\CheckRunner;
use HealthLens\Application\WordPressCheckRegistry;
use HealthLens\Domain\CheckContext;

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	WP_CLI::error( 'HealthLens database smoke requires the WordPress runtime.' );
}

$runner  = new CheckRunner( WordPressCheckRegistry::create() );
$results = $runner->run(
	new CheckContext(),
	array( 'database-connectivity', 'database-charset-schema', 'autoloaded-options', 'database-storage-growth' )
);

$expected = array( 'autoloaded-options', 'database-charset-schema', 'database-connectivity', 'database-storage-growth' );
$actual   = array_keys( $results );
sort( $actual, SORT_STRING );
if ( $expected !== $actual ) {
	WP_CLI::error( 'M5 database checks did not return the expected normalized catalog.' );
}

foreach ( $results as $result ) {
	$context = $result->context()->to_array();
	if ( false !== strpos( wp_json_encode( $context ), 'http' ) || false !== strpos( wp_json_encode( $context ), 'password' ) ) {
		WP_CLI::error( 'M5 database context crossed the privacy boundary.' );
	}
}

WP_CLI::success( 'M5 database checks returned four bounded current-site results without raw SQL, option values, credentials, or URLs.' );
