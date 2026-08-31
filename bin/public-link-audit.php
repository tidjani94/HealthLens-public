<?php
/**
 * Audit the public repository link and documentation contract.
 *
 * @package HealthLens
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use HealthLens\Release\PublicRepositoryLinkAuditor;

$audit_root   = $root;
$public_url   = '';
$private_url  = '';

foreach ( $argv as $argument ) {
	if ( 0 === strpos( $argument, '--root=' ) ) {
		$audit_root = substr( $argument, strlen( '--root=' ) );
	}
	if ( 0 === strpos( $argument, '--repository-url=' ) ) {
		$public_url = substr( $argument, strlen( '--repository-url=' ) );
	}
	if ( 0 === strpos( $argument, '--private-repository-url=' ) ) {
		$private_url = substr( $argument, strlen( '--private-repository-url=' ) );
	}
}

if ( '' === $public_url ) {
	fwrite( STDERR, "Usage: php bin/public-link-audit.php --repository-url=PUBLIC_URL [--root=DIR] [--private-repository-url=PRIVATE_URL]\n" );
	exit( 2 );
}

try {
	$errors = ( new PublicRepositoryLinkAuditor() )->audit( $audit_root, $public_url, $private_url );
} catch ( Throwable $exception ) {
	fwrite( STDERR, $exception->getMessage() . "\n" );
	exit( 1 );
}

if ( ! empty( $errors ) ) {
	fwrite( STDERR, implode( PHP_EOL, $errors ) . PHP_EOL );
	exit( 1 );
}

fwrite( STDOUT, "Public repository link audit passed: canonical metadata and required documentation links are public-only.\n" );
