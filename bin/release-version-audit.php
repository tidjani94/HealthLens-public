<?php
/**
 * Verify release metadata and an optional Git tag boundary.
 *
 * @package HealthLens
 */

declare( strict_types=1 );

$root          = dirname( __DIR__ );
$plugin_source = healthlens_release_read( $root . DIRECTORY_SEPARATOR . 'healthlens.php' );
$readme_source = healthlens_release_read( $root . DIRECTORY_SEPARATOR . 'readme.txt' );
$changelog     = healthlens_release_read( $root . DIRECTORY_SEPARATOR . 'CHANGELOG.md' );
$version       = healthlens_release_match( $plugin_source, '/^\s*\*\s*Version:\s*(.+?)\s*$/mi' );
$constant      = healthlens_release_match( $plugin_source, "/define\(\s*'HEALTHLENS_VERSION',\s*'([^']+)'/" );
$stable_tag    = healthlens_release_match( $readme_source, '/^Stable tag:\s*(.+?)\s*$/mi' );
$errors        = array();

if ( '' === $version || ! preg_match( '/^\d+\.\d+\.\d+(?:[-+].+)?$/', $version ) ) {
	$errors[] = 'healthlens.php does not contain a valid semantic Version header.';
}
if ( $version !== $constant ) {
	$errors[] = 'HEALTHLENS_VERSION does not match the plugin header Version.';
}
if ( $version !== $stable_tag ) {
	$errors[] = 'readme.txt Stable tag does not match the plugin header Version.';
}
if ( '' === $version || ! preg_match( '/^##\s+' . preg_quote( $version, '/' ) . '\b/im', $changelog ) ) {
	$errors[] = 'CHANGELOG.md does not contain a release heading for the plugin header Version.';
}

$tag = getenv( 'GITHUB_REF_NAME' );
if ( false !== $tag && is_string( $tag ) && '' !== $tag && 0 === strpos( $tag, 'v' ) && substr( $tag, 1 ) !== $version ) {
	$errors[] = 'GITHUB_REF_NAME does not match the plugin header Version.';
}

if ( ! empty( $errors ) ) {
	fwrite( STDERR, implode( PHP_EOL, $errors ) . PHP_EOL );
	exit( 1 );
}

fwrite( STDOUT, "Release metadata passed: HealthLens {$version} header, constant, stable tag, and tag boundary align.\n" );

/**
 * Read a release metadata file.
 *
 * @param string $file File path.
 * @return string
 */
function healthlens_release_read( string $file ): string {
	$contents = is_readable( $file ) ? file_get_contents( $file ) : false;
	return false === $contents ? '' : $contents;
}

/**
 * Return the first capture from a regular expression.
 *
 * @param string $contents File contents.
 * @param string $pattern Regular expression.
 * @return string
 */
function healthlens_release_match( string $contents, string $pattern ): string {
	return preg_match( $pattern, $contents, $matches ) ? trim( $matches[1] ) : '';
}
