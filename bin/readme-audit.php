<?php
/**
 * Validate the package-facing plugin metadata and readme contract.
 *
 * @package HealthLens
 */

declare( strict_types=1 );

$root   = dirname( __DIR__ );
$plugin = $root . DIRECTORY_SEPARATOR . 'healthlens.php';
$readme = $root . DIRECTORY_SEPARATOR . 'readme.txt';
$errors = array();

if ( ! is_readable( $plugin ) ) {
	$errors[] = 'Unable to read healthlens.php.';
}

if ( ! is_readable( $readme ) ) {
	$errors[] = 'Unable to read readme.txt.';
}

if ( empty( $errors ) ) {
	$plugin_source = file_get_contents( $plugin );
	$readme_source = file_get_contents( $readme );

	if ( false === $plugin_source || false === $readme_source ) {
		$errors[] = 'Unable to read the metadata sources.';
	} else {
		$readme_source = str_replace( "\r\n", "\n", $readme_source );
		$version = healthlens_read_plugin_field( $plugin_source, 'Version' );
		$canonical_repository_url = getenv( 'HEALTHLENS_CANONICAL_REPOSITORY_URL' );
		$canonical_repository_url = false !== $canonical_repository_url && '' !== trim( $canonical_repository_url ) ? rtrim( trim( $canonical_repository_url ), '/' ) : 'https://github.com/tidjani94/HealthLens-public';
		$plugin_fields = array(
			'Plugin Name'       => 'HealthLens',
			'Plugin URI'        => $canonical_repository_url,
			'Version'           => $version,
			'Requires at least' => '7.0',
			'Requires PHP'      => '7.4',
			'Author'            => 'COODIV Team',
			'Author URI'        => 'https://coodiv.net',
			'License'           => 'GPL-2.0-or-later',
			'License URI'       => 'https://www.gnu.org/licenses/gpl-2.0.html',
			'Text Domain'       => 'healthlens',
		);

		foreach ( $plugin_fields as $field => $expected ) {
			$actual = healthlens_read_plugin_field( $plugin_source, $field );
			if ( $expected !== $actual ) {
				$errors[] = "healthlens.php {$field} must be '{$expected}', got '{$actual}'.";
			}
		}

		$constant_version = healthlens_read_constant( $plugin_source, 'HEALTHLENS_VERSION' );
		if ( $version !== $constant_version ) {
			$errors[] = "HEALTHLENS_VERSION must match the plugin header Version '{$version}', got '{$constant_version}'.";
		}

		$readme_fields = healthlens_read_readme_fields( $readme_source );
		$required_readme_fields = array(
			'Requires at least' => '7.0',
			'Requires PHP'      => '7.4',
			'Tested up to'      => '7.1',
			'Stable tag'        => $version,
			'License'           => 'GPL-2.0-or-later',
			'License URI'       => 'https://www.gnu.org/licenses/gpl-2.0.html',
		);

		foreach ( $required_readme_fields as $field => $expected ) {
			$actual = isset( $readme_fields[ $field ] ) ? $readme_fields[ $field ] : '';
			if ( $expected !== $actual ) {
				$errors[] = "readme.txt {$field} must be '{$expected}', got '{$actual}'.";
			}
		}

		if ( isset( $readme_fields['Stable tag'] ) && $readme_fields['Stable tag'] !== $plugin_fields['Version'] ) {
			$errors[] = 'readme.txt Stable tag must match the plugin header Version.';
		}

		$tags = isset( $readme_fields['Tags'] ) ? array_filter( array_map( 'trim', explode( ',', $readme_fields['Tags'] ) ) ) : array();
		if ( count( $tags ) < 1 || count( $tags ) > 5 ) {
			$errors[] = 'readme.txt Tags must contain between one and five tags.';
		}

		$short_description = healthlens_read_short_description( $readme_source );
		if ( '' === $short_description ) {
			$errors[] = 'readme.txt must contain a short description after the metadata header.';
		} elseif ( strlen( $short_description ) > 150 ) {
			$errors[] = 'readme.txt short description must be 150 characters or fewer.';
		}

		$required_sections = array( 'Description', 'Installation', 'Privacy', 'FAQ', 'Support', 'Development', 'Changelog' );
		foreach ( $required_sections as $section ) {
			if ( ! preg_match( '/^==\s+' . preg_quote( $section, '/' ) . '\s+==$/mi', $readme_source ) ) {
				$errors[] = "readme.txt is missing the '{$section}' section.";
			}
		}

		if ( preg_match( '/\b(?:M0|inert|foundation release)\b/i', $readme_source ) ) {
			$errors[] = 'readme.txt contains stale foundation-only wording.';
		}
	}
}

if ( ! empty( $errors ) ) {
	fwrite( STDERR, implode( PHP_EOL, $errors ) . PHP_EOL );
	exit( 1 );
}

$reported_version = isset( $version ) ? $version : 'unknown';
fwrite( STDOUT, "Readme metadata audit passed: version {$reported_version}, text domain healthlens, and required sections are aligned." . PHP_EOL );

/**
 * Read one WordPress plugin header field.
 *
 * @param string $source Plugin source.
 * @param string $field Header field.
 * @return string
 */
function healthlens_read_plugin_field( string $source, string $field ): string {
	$pattern = '/^\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*(.+?)\s*$/mi';
	if ( preg_match( $pattern, $source, $matches ) ) {
		return trim( $matches[1] );
	}

	return '';
}

/**
 * Read one single-quoted PHP constant value.
 *
 * @param string $source Plugin source.
 * @param string $constant Constant name.
 * @return string
 */
function healthlens_read_constant( string $source, string $constant ): string {
	$pattern = '/define\(\s*[\'\"]' . preg_quote( $constant, '/' ) . '[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/';
	if ( preg_match( $pattern, $source, $matches ) ) {
		return trim( $matches[1] );
	}

	return '';
}

/**
 * Parse the readme metadata block.
 *
 * @param string $source Readme source.
 * @return array<string, string>
 */
function healthlens_read_readme_fields( string $source ): array {
	$fields = array();
	$lines  = preg_split( '/\R/', $source );

	if ( false === $lines ) {
		return $fields;
	}

	foreach ( $lines as $line ) {
		if ( '' === trim( $line ) ) {
			break;
		}

		if ( preg_match( '/^([^:]+):\s*(.+)$/', $line, $matches ) ) {
			$fields[ trim( $matches[1] ) ] = trim( $matches[2] );
		}
	}

	return $fields;
}

/**
 * Read the short description between metadata and the first section.
 *
 * @param string $source Readme source.
 * @return string
 */
function healthlens_read_short_description( string $source ): string {
	$parts = preg_split( '/^==\s+Description\s+==$/mi', $source, 2 );
	if ( false === $parts || count( $parts ) < 2 ) {
		return '';
	}

	$header_and_short = trim( $parts[0] );
	$lines            = preg_split( '/\R/', $header_and_short );
	if ( false === $lines ) {
		return '';
	}

	$description = array();
	$started     = false;
	foreach ( $lines as $line ) {
		if ( ! $started ) {
			if ( '' === trim( $line ) ) {
				$started = true;
			}
			continue;
		}

		if ( '' !== trim( $line ) ) {
			$description[] = trim( $line );
		}
	}

	return trim( implode( ' ', $description ) );
}
