<?php
/**
 * Export the reviewed source boundary for the public repository.
 *
 * @package HealthLens
 */

namespace HealthLens\Release;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Creates a sanitized, allowlisted public-repository tree.
 */
final class PublicRepositoryExporter {
	/**
	 * Files at the private repository root that are safe to publish.
	 *
	 * @var array<int, string>
	 */
	private const ROOT_FILES = array(
		'.wp-env.json',
		'healthlens.php',
		'uninstall.php',
		'composer.json',
		'composer.lock',
		'readme.txt',
		'CHANGELOG.md',
		'LICENSE',
		'SECURITY.md',
		'phpunit.xml.dist',
		'phpcs.xml.dist',
		'phpstan.neon.dist',
	);

	/**
	 * Directories that are part of the public source and test contract.
	 *
	 * @var array<int, string>
	 */
	private const DIRECTORIES = array(
		'src',
		'assets',
		'bin',
		'build',
		'tests',
	);

	/**
	 * User-facing and release-validation documentation to publish.
	 *
	 * @var array<int, string>
	 */
	private const DOCUMENTATION_FILES = array(
		'docs/PRIVACY.md',
		'docs/SECURITY.md',
		'docs/SUPPORT.md',
		'docs/TRANSLATION.md',
		'docs/RELEASE-GATES.md',
		'docs/PLUGIN-CHECK-BASELINE.md',
		'docs/PLUGIN-CHECK-BASELINE.json',
		'docs/PROVENANCE.md',
	);

	/**
	 * Files generated for the public repository rather than copied verbatim.
	 *
	 * @var array<string, string>
	 */
	private const GENERATED_FILES = array(
		'build/public-repository-README.md' => 'README.md',
		'build/public-repository-quality.yml' => '.github/workflows/quality.yml',
	);

	/**
	 * Private source repository root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * @param string $root Private repository root.
	 */
	public function __construct( string $root ) {
		$this->root = rtrim( $root, "\\/" );
	}

	/**
	 * Export the allowlisted tree and rewrite the private repository URL.
	 *
	 * @param string $destination Output directory. It must not contain files.
	 * @param string $public_url Public repository URL.
	 * @param string $private_url Private repository URL to replace.
	 * @return array<int, string> Exported relative paths.
	 * @throws InvalidArgumentException When a repository URL is invalid.
	 * @throws RuntimeException When the source or destination is unsafe.
	 */
	public function export( string $destination, string $public_url, string $private_url ): array {
		$public_url  = $this->normalize_repository_url( $public_url );
		$private_url = $this->normalize_repository_url( $private_url );

		if ( $public_url === $private_url ) {
			throw new InvalidArgumentException( 'The public and private repository URLs must differ.' );
		}

		$this->prepare_destination( $destination );
		$exported = array();

		foreach ( self::ROOT_FILES as $relative ) {
			$this->copy_file( $relative, $destination, $private_url, $public_url );
			$exported[] = $relative;
		}

		foreach ( self::DIRECTORIES as $directory ) {
			$this->copy_tree( $directory, $destination, $private_url, $public_url, $exported );
		}

		foreach ( self::DOCUMENTATION_FILES as $relative ) {
			$this->copy_file( $relative, $destination, $private_url, $public_url );
			$exported[] = $relative;
		}

		foreach ( self::GENERATED_FILES as $source_relative => $destination_relative ) {
			$this->copy_file_as( $source_relative, $destination, $destination_relative, $private_url, $public_url );
			$exported[] = $destination_relative;
		}

		sort( $exported, SORT_STRING );
		return $exported;
	}

	/**
	 * Validate and normalize an HTTPS repository URL.
	 *
	 * @param string $url Repository URL.
	 * @return string
	 * @throws InvalidArgumentException When the URL is not an HTTPS origin/path.
	 */
	public function normalize_repository_url( string $url ): string {
		$url    = rtrim( trim( $url ), '/' );
		$parsed = parse_url( $url );

		if ( '' === $url || ! is_array( $parsed ) || 'https' !== ( $parsed['scheme'] ?? '' ) || '' === ( $parsed['host'] ?? '' ) || isset( $parsed['query'] ) || isset( $parsed['fragment'] ) ) {
			throw new InvalidArgumentException( 'Repository URLs must be HTTPS URLs without query strings or fragments.' );
		}

		return $url;
	}

	/**
	 * Ensure the destination is a new or empty directory.
	 *
	 * @param string $destination Output directory.
	 * @return void
	 * @throws RuntimeException When the destination cannot be safely prepared.
	 */
	private function prepare_destination( string $destination ): void {
		if ( is_link( $destination ) ) {
			throw new RuntimeException( 'The public export destination may not be a symbolic link.' );
		}

		if ( ! is_dir( $destination ) && ! mkdir( $destination, 0777, true ) && ! is_dir( $destination ) ) {
			throw new RuntimeException( 'Unable to create the public export destination.' );
		}

		$entries = scandir( $destination );
		if ( false === $entries ) {
			throw new RuntimeException( 'Unable to inspect the public export destination.' );
		}

		$entries = array_values( array_diff( $entries, array( '.', '..' ) ) );
		if ( ! empty( $entries ) ) {
			throw new RuntimeException( 'The public export destination must be empty.' );
		}
	}

	/**
	 * Copy one allowlisted file.
	 *
	 * @param string $relative Source-relative path.
	 * @param string $destination Output root.
	 * @param string $private_url URL to replace.
	 * @param string $public_url Replacement URL.
	 * @return void
	 */
	private function copy_file( string $relative, string $destination, string $private_url, string $public_url ): void {
		$this->copy_file_as( $relative, $destination, $relative, $private_url, $public_url );
	}

	/**
	 * Copy one allowlisted file to a selected destination path.
	 *
	 * @param string $source_relative Source-relative path.
	 * @param string $destination Output root.
	 * @param string $destination_relative Destination-relative path.
	 * @param string $private_url URL to replace.
	 * @param string $public_url Replacement URL.
	 * @return void
	 */
	private function copy_file_as( string $source_relative, string $destination, string $destination_relative, string $private_url, string $public_url ): void {
		$source = $this->root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $source_relative );
		if ( ! is_file( $source ) || is_link( $source ) || ! is_readable( $source ) ) {
			throw new RuntimeException( "Public export source file is missing or unsafe: {$source_relative}." );
		}

		$contents = file_get_contents( $source );
		if ( false === $contents ) {
			throw new RuntimeException( "Unable to read public export source file: {$source_relative}." );
		}

		if ( $this->is_text_file( $source_relative ) ) {
			$private_pattern = '~' . preg_quote( $private_url, '~' ) . '(?=$|[^A-Za-z0-9_-])~';
			$contents        = preg_replace( $private_pattern, $public_url, $contents );
			$contents = str_replace( 'https://github.com/tidjani94/HealthLens-public', $public_url, $contents );
		}

		$target = $destination . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $destination_relative );
		$this->write_file( $target, $contents );
	}

	/**
	 * Copy an allowlisted directory without following symbolic links.
	 *
	 * @param string $relative Source-relative directory.
	 * @param string $destination Output root.
	 * @param string $private_url URL to replace.
	 * @param string $public_url Replacement URL.
	 * @param array<int, string> $exported Exported paths.
	 * @return void
	 */
	private function copy_tree( string $relative, string $destination, string $private_url, string $public_url, array &$exported ): void {
		$source = $this->root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		if ( ! is_dir( $source ) || is_link( $source ) ) {
			throw new RuntimeException( "Public export source directory is missing or unsafe: {$relative}." );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isLink() ) {
				$item_relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $item->getPathname(), strlen( $this->root ) + 1 ) );
				throw new RuntimeException( "Symbolic links are not allowed in the public export: {$item_relative}." );
			}

			if ( ! $item->isFile() ) {
				continue;
			}

			$item_relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $item->getPathname(), strlen( $this->root ) + 1 ) );
			if ( $this->is_forbidden_path( $item_relative ) ) {
				throw new RuntimeException( "Forbidden sensitive-looking public export path: {$item_relative}." );
			}

			$this->copy_file( $item_relative, $destination, $private_url, $public_url );
			$exported[] = $item_relative;
		}
	}

	/**
	 * Determine whether a file should receive URL substitution.
	 *
	 * @param string $relative Relative path.
	 * @return bool
	 */
	private function is_text_file( string $relative ): bool {
		$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
		return in_array( $extension, array( 'css', 'json', 'md', 'php', 'txt', 'xml', 'yml', 'yaml' ), true );
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

	/**
	 * Write an exported file after creating only its destination parents.
	 *
	 * @param string $target Destination file.
	 * @param string $contents File contents.
	 * @return void
	 */
	private function write_file( string $target, string $contents ): void {
		$directory = dirname( $target );
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) && ! is_dir( $directory ) ) {
			throw new RuntimeException( 'Unable to create a public export directory.' );
		}

		if ( false === file_put_contents( $target, $contents ) ) {
			throw new RuntimeException( 'Unable to write a public export file.' );
		}
	}
}
