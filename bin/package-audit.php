<?php
/**
 * Audit a production archive against its provenance manifest.
 *
 * @package HealthLens
 */

declare( strict_types=1 );

$root    = dirname( __DIR__ );
$version = healthlens_read_version( $root . DIRECTORY_SEPARATOR . 'healthlens.php' );
$archive = isset( $argv[1] ) ? $argv[1] : $root . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'healthlens-' . $version . '.zip';
$errors  = array();

if ( ! is_readable( $archive ) ) {
	$errors[] = "Unable to read package archive {$archive}.";
}

$zip = new ZipArchive();
if ( empty( $errors ) && true !== $zip->open( $archive ) ) {
	$errors[] = 'Unable to open the package archive.';
}

if ( empty( $errors ) ) {
	$archive_files = array();
	for ( $index = 0; $index < $zip->numFiles; $index++ ) {
		$name = $zip->getNameIndex( $index );
		if ( false === $name || '/' === substr( $name, -1 ) ) {
			continue;
		}

		if ( 0 !== strpos( $name, 'healthlens/' ) ) {
			$errors[] = "Archive entry {$name} is outside the canonical healthlens root.";
			continue;
		}

		$relative = substr( $name, strlen( 'healthlens/' ) );
		if ( preg_match( '#^(?:tests|\.agents|\.github|node_modules|coverage|src/Release)/#', $relative ) || in_array( $relative, array( 'composer.lock', 'package-lock.json', 'phpunit.xml.dist', 'phpcs.xml.dist', 'phpstan.neon.dist' ), true ) ) {
			$errors[] = "Development-only entry {$name} is present in the archive.";
		}

		$archive_files[] = $relative;
	}

	$required = array( 'healthlens.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'vendor/autoload.php', 'PROVENANCE.json' );
	foreach ( $required as $required_file ) {
		if ( ! in_array( $required_file, $archive_files, true ) ) {
			$errors[] = "Required package entry {$required_file} is missing.";
		}
	}

	$manifest_json = $zip->getFromName( 'healthlens/PROVENANCE.json' );
	$manifest      = false === $manifest_json ? null : json_decode( $manifest_json, true );
	if ( ! is_array( $manifest ) ) {
		$errors[] = 'PROVENANCE.json is missing or invalid JSON.';
	} else {
		$expected = array(
			'plugin'       => 'HealthLens',
			'slug'         => 'healthlens',
			'version'      => $version,
			'license'      => 'GPL-2.0-or-later',
			'archive_root' => 'healthlens',
		);
		foreach ( $expected as $field => $value ) {
			if ( ! isset( $manifest[ $field ] ) || $value !== $manifest[ $field ] ) {
				$errors[] = "Manifest {$field} does not match the expected value.";
			}
		}

		$manifest_paths = array();
		if ( ! isset( $manifest['files'] ) || ! is_array( $manifest['files'] ) ) {
			$errors[] = 'Manifest file inventory is missing.';
		} else {
			foreach ( $manifest['files'] as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['path'] ) ) {
					$errors[] = 'Manifest contains an invalid file entry.';
					continue;
				}

				$relative = $entry['path'];
				$manifest_paths[] = $relative;
				$content = $zip->getFromName( 'healthlens/' . $relative );
				if ( false === $content ) {
					$errors[] = "Manifest entry {$relative} is missing from the archive.";
					continue;
				}

				if ( ! isset( $entry['sha256'] ) || hash( 'sha256', $content ) !== $entry['sha256'] ) {
					$errors[] = "Manifest hash mismatch for {$relative}.";
				}
				if ( ! isset( $entry['bytes'] ) || strlen( $content ) !== (int) $entry['bytes'] ) {
					$errors[] = "Manifest byte count mismatch for {$relative}.";
				}
				if ( empty( $entry['license'] ) || empty( $entry['source'] ) ) {
					$errors[] = "Manifest license/source evidence is incomplete for {$relative}.";
				}
			}
		}

		$archive_without_manifest = array_values( array_diff( $archive_files, array( 'PROVENANCE.json' ) ) );
		sort( $archive_without_manifest, SORT_STRING );
		sort( $manifest_paths, SORT_STRING );
		if ( $archive_without_manifest !== $manifest_paths ) {
			$errors[] = 'Manifest file inventory does not match the archive contents.';
		}
		if ( ! isset( $manifest['manifest']['license'], $manifest['manifest']['source'] ) ) {
			$errors[] = 'Manifest self-license/source evidence is missing.';
		}
	}

	$zip->close();
}

if ( ! empty( $errors ) ) {
	fwrite( STDERR, implode( PHP_EOL, $errors ) . PHP_EOL );
	exit( 1 );
}

fwrite( STDOUT, "Package audit passed: {$archive} has one healthlens root, a verified provenance manifest, and " . count( $archive_files ) . " files." . PHP_EOL );

/**
 * Read the plugin version from the main header.
 *
 * @param string $plugin_file Main plugin file.
 * @return string
 */
function healthlens_read_version( string $plugin_file ): string {
	$source = is_readable( $plugin_file ) ? file_get_contents( $plugin_file ) : false;
	if ( false !== $source && preg_match( '/^\s*\*\s*Version:\s*(.+?)\s*$/mi', $source, $matches ) ) {
		return trim( $matches[1] );
	}

	fwrite( STDERR, "Unable to read a valid plugin version from {$plugin_file}.\n" );
	exit( 1 );
}
