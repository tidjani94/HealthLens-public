<?php
/**
 * @package HealthLens
 */

namespace HealthLens\Tests\Unit;

use HealthLens\Release\PublicRepositoryExporter;
use HealthLens\Release\PublicRepositoryLinkAuditor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PublicRepositoryTest extends TestCase {
	public function test_export_rewrites_private_links_and_excludes_internal_readiness_documents(): void {
		$root        = dirname( __DIR__, 2 );
		$destination = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'healthlens-public-' . bin2hex( random_bytes( 4 ) );
		$private_url  = getenv( 'HEALTHLENS_PRIVATE_REPOSITORY_URL' );
		$private_url  = false !== $private_url && '' !== trim( $private_url ) ? trim( $private_url ) : 'https://' . 'github.com/tidjani94/HealthLens';
		$public_url   = getenv( 'HEALTHLENS_CANONICAL_REPOSITORY_URL' );
		$public_url   = false !== $public_url && '' !== trim( $public_url ) ? trim( $public_url ) : 'https://github.com/tidjani94/HealthLens-public';

		try {
			$exported = ( new PublicRepositoryExporter( $root ) )->export( $destination, $public_url, $private_url );
			$errors   = ( new PublicRepositoryLinkAuditor() )->audit( $destination, $public_url, $private_url );

			$this->assertNotEmpty( $exported );
			$this->assertSame( array(), $errors );
			$this->assertFileExists( $destination . DIRECTORY_SEPARATOR . 'README.md' );
			$this->assertFileExists( $destination . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'quality.yml' );
			$this->assertFileDoesNotExist( $destination . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'RELEASE-READINESS.md' );
			$this->assertStringContainsString( $public_url, file_get_contents( $destination . DIRECTORY_SEPARATOR . 'README.md' ) );
			$private_pattern = '~' . preg_quote( $private_url, '~' ) . '(?=$|[^A-Za-z0-9_-])~';
			$this->assertSame( 0, preg_match( $private_pattern, file_get_contents( $destination . DIRECTORY_SEPARATOR . 'readme.txt' ) ) );
		} finally {
			$this->remove_directory( $destination );
		}
	}

	public function test_export_rejects_a_non_empty_destination(): void {
		$destination = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'healthlens-public-' . bin2hex( random_bytes( 4 ) );
		mkdir( $destination, 0777, true );
		file_put_contents( $destination . DIRECTORY_SEPARATOR . 'existing.txt', 'private' );

		try {
			$this->expectException( \RuntimeException::class );
			( new PublicRepositoryExporter( dirname( __DIR__, 2 ) ) )->export(
				$destination,
				'https://public.example/healthlens',
				'https://private.example/healthlens'
			);
		} finally {
			$this->remove_directory( $destination );
		}
	}

	public function test_repository_urls_require_https_without_query_or_fragment(): void {
		$exporter = new PublicRepositoryExporter( dirname( __DIR__, 2 ) );

		$this->expectException( InvalidArgumentException::class );
		$exporter->normalize_repository_url( 'http://github.com/tidjani94/HealthLens' );
	}

	public function test_audit_reports_private_url_in_exported_text(): void {
		$root       = dirname( __DIR__, 2 );
		$destination = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'healthlens-public-' . bin2hex( random_bytes( 4 ) );
		$private_url = 'https://private.example/healthlens';
		$public_url  = 'https://public.example/healthlens';

		try {
			( new PublicRepositoryExporter( $root ) )->export( $destination, $public_url, $private_url );
			file_put_contents( $destination . DIRECTORY_SEPARATOR . 'readme.txt', file_get_contents( $destination . DIRECTORY_SEPARATOR . 'readme.txt' ) . $private_url );

			$errors = ( new PublicRepositoryLinkAuditor() )->audit( $destination, $public_url, $private_url );

			$this->assertNotEmpty( $errors );
			$this->assertStringContainsString( 'Private repository URL remains', implode( ' ', $errors ) );
		} finally {
			$this->remove_directory( $destination );
		}
	}

	public function test_audit_rejects_sensitive_looking_file_paths(): void {
		$destination = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'healthlens-public-' . bin2hex( random_bytes( 4 ) );

		try {
			( new PublicRepositoryExporter( dirname( __DIR__, 2 ) ) )->export(
				$destination,
				'https://public.example/healthlens',
				'https://private.example/healthlens'
			);
			file_put_contents( $destination . DIRECTORY_SEPARATOR . 'credentials.pem', 'not-a-real-key' );

			$errors = ( new PublicRepositoryLinkAuditor() )->audit( $destination, 'https://public.example/healthlens' );

			$this->assertStringContainsString( 'Forbidden sensitive-looking public repository path', implode( ' ', $errors ) );
		} finally {
			$this->remove_directory( $destination );
		}
	}

	/**
	 * Remove a test directory tree.
	 *
	 * @param string $directory Directory to remove.
	 * @return void
	 */
	private function remove_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $directory );
	}
}
