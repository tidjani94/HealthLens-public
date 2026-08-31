<?php
/**
 * Export the sanitized public repository tree.
 *
 * @package HealthLens
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use HealthLens\Release\PublicRepositoryExporter;

$arguments = array(
	'--output'               => '',
	'--repository-url'       => '',
	'--private-repository-url' => '',
);

foreach ( $argv as $argument ) {
	foreach ( array_keys( $arguments ) as $key ) {
		$prefix = $key . '=';
		if ( 0 === strpos( $argument, $prefix ) ) {
			$arguments[ $key ] = substr( $argument, strlen( $prefix ) );
		}
	}
}

if ( '' === $arguments['--output'] || '' === $arguments['--repository-url'] || '' === $arguments['--private-repository-url'] ) {
	fwrite( STDERR, "Usage: php bin/public-export.php --output=DIR --repository-url=PUBLIC_URL --private-repository-url=PRIVATE_URL\n" );
	exit( 2 );
}

try {
	$exported = ( new PublicRepositoryExporter( $root ) )->export(
		$arguments['--output'],
		$arguments['--repository-url'],
		$arguments['--private-repository-url']
	);

	fwrite( STDOUT, 'Public repository export passed: ' . count( $exported ) . " allowlisted files written.\n" );
} catch ( Throwable $exception ) {
	fwrite( STDERR, $exception->getMessage() . "\n" );
	exit( 1 );
}
