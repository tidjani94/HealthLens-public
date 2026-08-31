<?php
/**
 * Audits the public repository URL and documentation contract.
 *
 * @package HealthLens
 */

namespace HealthLens\Release;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Verifies that an exported tree points to the public repository only.
 */
final class PublicRepositoryLinkAuditor {
	/**
	 * Required public documentation paths.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED_FILES = array(
		'SECURITY.md',
		'docs/PRIVACY.md',
		'docs/SUPPORT.md',
		'docs/TRANSLATION.md',
	);

	/**
	 * Audit an exported repository tree.
	 *
	 * @param string $root Repository tree.
	 * @param string $public_url Expected public repository URL.
	 * @param string $private_url Private URL that must not remain, optional.
	 * @return array<int, string> Blocking errors.
	 * @throws InvalidArgumentException When the public URL is invalid.
	 */
	public function audit( string $root, string $public_url, string $private_url = '' ): array {
		$exporter  = new PublicRepositoryExporter( $root );
		$public_url = $exporter->normalize_repository_url( $public_url );
		$private_url = '' === trim( $private_url ) ? '' : $exporter->normalize_repository_url( $private_url );
		$errors     = array();

		$plugin_file = $root . DIRECTORY_SEPARATOR . 'healthlens.php';
		$readme_file = $root . DIRECTORY_SEPARATOR . 'readme.txt';
		$plugin      = $this->read_file( $plugin_file, $errors );
		$readme      = $this->read_file( $readme_file, $errors );

		foreach ( self::REQUIRED_FILES as $relative ) {
			$path = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			if ( ! is_file( $path ) || is_link( $path ) ) {
				$errors[] = "Required public repository file is missing or unsafe: {$relative}.";
			}
		}

		$plugin_uri = $this->match_header( $plugin, 'Plugin URI' );
		if ( $public_url !== $plugin_uri ) {
			$errors[] = 'healthlens.php Plugin URI does not match the configured public repository URL.';
		}

		$expected_links = array(
			$public_url,
			$public_url . '/issues',
			$public_url . '/blob/main/docs/PRIVACY.md',
			$public_url . '/blob/main/docs/SUPPORT.md',
			$public_url . '/blob/main/docs/TRANSLATION.md',
			$public_url . '/blob/main/SECURITY.md',
		);
		foreach ( $expected_links as $link ) {
			if ( false === strpos( $readme, $link ) ) {
				$errors[] = "readme.txt is missing the public repository link {$link}.";
			}
		}

		foreach ( $this->text_files( $root, $errors ) as $file ) {
			$contents = file_get_contents( $file );
			if ( false === $contents ) {
				$errors[] = 'Unable to read public repository file ' . $this->relative_path( $root, $file ) . '.';
				continue;
			}

			$private_pattern = '' === $private_url ? '' : '~' . preg_quote( $private_url, '~' ) . '(?=$|[^A-Za-z0-9_-])~';
			if ( '' !== $private_pattern && 1 === preg_match( $private_pattern, $contents ) ) {
				$errors[] = 'Private repository URL remains in ' . $this->relative_path( $root, $file ) . '.';
			}
		}

		return array_values( array_unique( $errors ) );
	}

	/**
	 * Read a required file without exposing its contents in errors.
	 *
	 * @param string $file Absolute file path.
	 * @param array<int, string> $errors Error collection.
	 * @return string
	 */
	private function read_file( string $file, array &$errors ): string {
		if ( ! is_file( $file ) || is_link( $file ) || ! is_readable( $file ) ) {
			$errors[] = 'Required public repository file is missing or unreadable: ' . basename( $file ) . '.';
			return '';
		}

		$contents = file_get_contents( $file );
		if ( false === $contents ) {
			$errors[] = 'Unable to read required public repository file ' . basename( $file ) . '.';
			return '';
		}

		return $contents;
	}

	/**
	 * Read a plugin header field.
	 *
	 * @param string $source Plugin source.
	 * @param string $field Header field.
	 * @return string
	 */
	private function match_header( string $source, string $field ): string {
		$pattern = '/^\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*(.+?)\s*$/mi';
		return preg_match( $pattern, $source, $matches ) ? trim( $matches[1] ) : '';
	}

	/**
	 * Return text files under the exported root.
	 *
	 * @param string $root Exported root.
	 * @param array<int, string> $errors Error collection.
	 * @return array<int, string>
	 */
	private function text_files( string $root, array &$errors ): array {
		if ( ! is_dir( $root ) || is_link( $root ) ) {
			$errors[] = 'Public repository audit root is missing or unsafe.';
			return array();
		}

		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS )
		);
		$extensions = array( 'css', 'json', 'md', 'php', 'txt', 'xml', 'yml', 'yaml' );

		foreach ( $iterator as $item ) {
			if ( $item->isLink() ) {
				$errors[] = 'Symbolic links are not allowed in the public repository export.';
				continue;
			}

			$relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $item->getPathname(), strlen( rtrim( $root, "\\/" ) ) + 1 ) );
			if ( 0 === strpos( $relative, 'vendor/' ) || 0 === strpos( $relative, 'node_modules/' ) || 0 === strpos( $relative, '.git/' ) ) {
				continue;
			}

			if ( $this->is_forbidden_path( $relative ) ) {
				$errors[] = 'Forbidden sensitive-looking public repository path: ' . $relative . '.';
				continue;
			}

			if ( $item->isFile() && in_array( strtolower( $item->getExtension() ), $extensions, true ) ) {
				$files[] = $item->getPathname();
			}
		}

		sort( $files, SORT_STRING );
		return $files;
	}

	/**
	 * Return a root-relative path for a diagnostic.
	 *
	 * @param string $root Root path.
	 * @param string $file Absolute file path.
	 * @return string
	 */
	private function relative_path( string $root, string $file ): string {
		return str_replace( DIRECTORY_SEPARATOR, '/', substr( $file, strlen( rtrim( $root, "\\/" ) ) + 1 ) );
	}

	/**
	 * Determine whether a path looks like a private credential or data file.
	 *
	 * @param string $relative Relative path.
	 * @return bool
	 */
	private function is_forbidden_path( string $relative ): bool {
		return 1 === preg_match( '#(?:^|/)(?:\.env(?:\.[^/]*)?|[^/]+\.(?:crt|key|log|pem|pfx|p12|sql))$#i', $relative );
	}
}
