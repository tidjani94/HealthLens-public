<?php
/**
 * Lint first-party PHP files without requiring a Unix shell.
 *
 * @package HealthLens
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );
$files = array(
	$root . DIRECTORY_SEPARATOR . 'healthlens.php',
	$root . DIRECTORY_SEPARATOR . 'uninstall.php',
	$root . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'package.php',
);

foreach ( array( 'src', 'tests', 'bin' ) as $directory ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root . DIRECTORY_SEPARATOR . $directory )
	);

	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}
}

$failed = false;
foreach ( $files as $file ) {
	$process = proc_open(
		array( PHP_BINARY, '-l', $file ),
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);

	if ( ! is_resource( $process ) ) {
		fwrite( STDERR, "Unable to start PHP lint for {$file}.\n" );
		$failed = true;
		continue;
	}

	$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$exit_code = proc_close( $process );

	if ( 0 !== $exit_code ) {
		fwrite( STDERR, $output );
		$failed = true;
	}
}

exit( $failed ? 1 : 0 );
