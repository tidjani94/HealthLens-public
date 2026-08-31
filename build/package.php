<?php
/**
 * Build a reproducible production plugin archive.
 *
 * @package HealthLens
 */

declare( strict_types=1 );

$root        = dirname( __DIR__ );
$plugin_file = $root . DIRECTORY_SEPARATOR . 'healthlens.php';
$plugin_data = is_readable( $plugin_file ) ? file_get_contents( $plugin_file ) : false;
$version     = false === $plugin_data ? false : ( preg_match( '/^\s*\*\s*Version:\s*(.+?)\s*$/mi', $plugin_data, $version_match ) ? trim( $version_match[1] ) : false );
$source_repository = getenv( 'HEALTHLENS_CANONICAL_REPOSITORY_URL' );
$source_repository = false !== $source_repository && '' !== trim( $source_repository ) ? rtrim( trim( $source_repository ), '/' ) : 'https://github.com/tidjani94/HealthLens-public';

if ( false === $version || ! preg_match( '/^\d+\.\d+\.\d+(?:[-+].+)?$/', $version ) ) {
	fwrite( STDERR, "Unable to read a valid Version from healthlens.php.\n" );
	exit( 1 );
}

$output      = $root . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'healthlens-' . $version . '.zip';
$staging     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'healthlens-package-' . bin2hex( random_bytes( 8 ) );
$fixed_time  = 946684800;
$lock_file   = $root . DIRECTORY_SEPARATOR . 'composer.lock';
$root_files  = array( 'healthlens.php', 'uninstall.php', 'composer.json', 'composer.lock', 'README.md', 'readme.txt', 'CHANGELOG.md', 'LICENSE', 'SECURITY.md' );
$doc_files   = array( 'docs/PRIVACY.md', 'docs/SECURITY.md', 'docs/RELEASE.md' );

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The PHP zip extension is required to build the release archive.\n" );
	exit( 1 );
}

function healthlens_copy_file( $source, $destination ) {
	$directory = dirname( $destination );
	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) && ! is_dir( $directory ) ) {
		throw new RuntimeException( 'Unable to create package directory.' );
	}

	if ( ! copy( $source, $destination ) ) {
		throw new RuntimeException( 'Unable to copy package file.' );
	}
}

/**
 * Copy a source tree while excluding selected top-level directories.
 *
 * @param string             $source              Source directory.
 * @param string             $destination         Destination directory.
 * @param array<int, string> $excluded_directories Top-level directory names to omit.
 * @return void
 */
function healthlens_copy_tree( $source, $destination, array $excluded_directories = array() ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $item ) {
		$relative = substr( $item->getPathname(), strlen( $source ) + 1 );
		$parts    = explode( DIRECTORY_SEPARATOR, $relative );
		if ( ! empty( $parts[0] ) && in_array( $parts[0], $excluded_directories, true ) ) {
			continue;
		}
		$target   = $destination . DIRECTORY_SEPARATOR . $relative;

		if ( $item->isDir() ) {
			if ( ! is_dir( $target ) && ! mkdir( $target, 0777, true ) && ! is_dir( $target ) ) {
				throw new RuntimeException( 'Unable to create package directory.' );
			}
		} else {
			healthlens_copy_file( $item->getPathname(), $target );
		}
	}
}

function healthlens_run_composer( $working_directory ) {
	$arguments = 'install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts';
	$command   = PHP_OS_FAMILY === 'Windows'
		? 'cmd.exe /d /c composer ' . $arguments
		: array( 'composer', 'install', '--no-dev', '--prefer-dist', '--no-interaction', '--no-progress', '--no-scripts' );
	$process = proc_open(
		$command,
		array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
		$pipes,
		$working_directory
	);

	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Unable to start Composer.' );
	}

	$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$exit_code = proc_close( $process );

	if ( 0 !== $exit_code ) {
		throw new RuntimeException( $output );
	}
}

/**
 * Return production Composer dependency provenance from the repository lock.
 *
 * @param string $lock_file Composer lock path.
 * @return array<int, array<string, mixed>>
 */
function healthlens_read_dependencies( $lock_file ) {
	$contents = file_get_contents( $lock_file );
	$data     = false === $contents ? null : json_decode( $contents, true );
	$packages = is_array( $data ) && isset( $data['packages'] ) && is_array( $data['packages'] ) ? $data['packages'] : array();
	$results  = array();

	foreach ( $packages as $package ) {
		if ( ! is_array( $package ) || empty( $package['name'] ) ) {
			continue;
		}

		$licenses = isset( $package['license'] ) && is_array( $package['license'] ) ? array_values( $package['license'] ) : array();
		$source   = isset( $package['source'] ) && is_array( $package['source'] ) ? $package['source'] : array();
		$results[] = array(
			'name'      => $package['name'],
			'version'   => isset( $package['version'] ) ? $package['version'] : '',
			'license'   => $licenses,
			'source'    => isset( $source['url'] ) ? $source['url'] : '',
			'reference' => isset( $source['reference'] ) ? $source['reference'] : '',
		);
	}

	return $results;
}

/**
 * Return all package files relative to the plugin root.
 *
 * @param string $package_root Staged plugin root.
 * @param string $excluded_file File to exclude from the hash inventory.
 * @return array<int, string>
 */
function healthlens_package_files( $package_root, $excluded_file ) {
	$files    = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $package_root, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $item ) {
		if ( ! $item->isFile() ) {
			continue;
		}

		$relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $item->getPathname(), strlen( $package_root ) + 1 ) );
		if ( $relative !== $excluded_file ) {
			$files[] = $relative;
		}
	}

	sort( $files, SORT_STRING );
	return $files;
}

/**
 * Return a license/source record for one distributed file.
 *
 * @param string $relative_file File path relative to the plugin root.
 * @param array<int, array<string, mixed>> $dependencies Production dependencies.
 * @return array<string, string>
 */
function healthlens_file_provenance( $relative_file, array $dependencies ) {
	if ( 'vendor/autoload.php' === $relative_file || 0 === strpos( $relative_file, 'vendor/composer/' ) ) {
		return array(
			'license' => 'MIT',
			'source'  => 'Composer-generated production autoloader',
		);
	}

	if ( 0 === strpos( $relative_file, 'vendor/' ) ) {
		$parts = explode( '/', $relative_file );
		$name  = isset( $parts[1], $parts[2] ) ? $parts[1] . '/' . $parts[2] : '';
		foreach ( $dependencies as $dependency ) {
			if ( $name === $dependency['name'] ) {
				$license = ! empty( $dependency['license'] ) ? implode( ', ', $dependency['license'] ) : 'See package license';
				$source  = $dependency['source'];
				if ( '' !== $dependency['reference'] ) {
					$source .= '#' . $dependency['reference'];
				}

				return array(
					'license' => $license,
					'source'  => $source,
				);
			}
		}
	}

	return array(
		'license' => 'GPL-2.0-or-later',
		'source'  => 'HealthLens repository',
	);
}

/**
 * Write the deterministic package provenance manifest.
 *
 * @param string $package_root Staged plugin root.
 * @param string $version Package version.
 * @param int $fixed_time Reproducible archive timestamp.
 * @param string $lock_file Composer lock path.
 * @param string $source_repository Canonical source repository URL.
 * @return void
 */
function healthlens_write_manifest( $package_root, $version, $fixed_time, $lock_file, $source_repository ) {
	$manifest_file = 'PROVENANCE.json';
	$dependencies  = healthlens_read_dependencies( $lock_file );
	$source_commit = getenv( 'GITHUB_SHA' );
	$source_commit = is_string( $source_commit ) && '' !== $source_commit ? $source_commit : 'working-tree';
	$lock_hash     = is_file( $lock_file ) ? hash_file( 'sha256', $lock_file ) : '';
	$files         = array();

	foreach ( healthlens_package_files( $package_root, $manifest_file ) as $relative_file ) {
		$absolute_file = $package_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_file );
		$provenance    = healthlens_file_provenance( $relative_file, $dependencies );
		$files[]       = array(
			'path'   => $relative_file,
			'bytes'  => filesize( $absolute_file ),
			'sha256' => hash_file( 'sha256', $absolute_file ),
			'license' => $provenance['license'],
			'source'  => $provenance['source'],
		);
	}

	$manifest = array(
		'schema'       => 1,
		'plugin'       => 'HealthLens',
		'slug'         => 'healthlens',
		'version'      => $version,
		'license'      => 'GPL-2.0-or-later',
		'archive_root' => 'healthlens',
		'archive'      => 'healthlens-' . $version . '.zip',
		'source'       => array(
			'repository' => $source_repository,
			'commit'    => $source_commit,
			'composer_lock_sha256' => $lock_hash,
		),
		'channels'     => array(
			'github_release'  => 'dist/healthlens-' . $version . '.zip',
			'ci_artifact'    => 'healthlens-release/healthlens-' . $version . '.zip',
			'wordpress_org'  => 'SVN trunk and tags/' . $version . ' must contain this same production tree',
		),
		'reproducibility' => array(
			'source_date_epoch' => $fixed_time,
			'file_order'        => 'lexicographic',
			'generated_at'      => 'omitted intentionally',
		),
		'composer' => array(
			'production_dependency_count' => count( $dependencies ),
			'production_dependencies'     => $dependencies,
		),
		'manifest' => array(
			'path'    => $manifest_file,
			'license' => 'GPL-2.0-or-later',
			'source'  => 'build/package.php',
			'hash'    => 'excluded to avoid a self-referential hash',
		),
		'files' => $files,
	);

	$encoded = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
	if ( false === $encoded || false === file_put_contents( $package_root . DIRECTORY_SEPARATOR . $manifest_file, $encoded ) ) {
		throw new RuntimeException( 'Unable to write the package provenance manifest.' );
	}
}

try {
	mkdir( $staging, 0777, true );
	$package_root = $staging . DIRECTORY_SEPARATOR . 'healthlens';
	mkdir( $package_root, 0777, true );

	foreach ( $root_files as $file ) {
		$source = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $file );
		if ( is_file( $source ) ) {
			healthlens_copy_file( $source, $package_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $file ) );
		}
	}

	foreach ( $doc_files as $file ) {
		$source = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $file );
		if ( is_file( $source ) ) {
			healthlens_copy_file( $source, $package_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $file ) );
		}
	}

	healthlens_copy_tree( $root . DIRECTORY_SEPARATOR . 'src', $package_root . DIRECTORY_SEPARATOR . 'src', array( 'Release' ) );
	healthlens_copy_tree( $root . DIRECTORY_SEPARATOR . 'assets', $package_root . DIRECTORY_SEPARATOR . 'assets' );
	healthlens_run_composer( $package_root );

	$autoload_files = array(
		$package_root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
		$package_root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'autoload_real.php',
		$package_root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'autoload_static.php',
	);
	foreach ( $autoload_files as $autoload_file ) {
		if ( ! is_file( $autoload_file ) ) {
			continue;
		}

		$contents = file_get_contents( $autoload_file );
		$contents = preg_replace( '/Composer(Autoloader|Static)Init[a-f0-9]{32}/', 'Composer$1InitHealthLens', $contents );
		file_put_contents( $autoload_file, $contents );
	}

	$package_lock = $package_root . DIRECTORY_SEPARATOR . 'composer.lock';
	if ( is_file( $package_lock ) ) {
		unlink( $package_lock );
	}

	healthlens_write_manifest( $package_root, $version, $fixed_time, $lock_file, $source_repository );

	$files = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $package_root, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $iterator as $item ) {
		if ( $item->isFile() ) {
			$relative = substr( $item->getPathname(), strlen( $staging ) + 1 );
			$files[]  = str_replace( DIRECTORY_SEPARATOR, '/', $relative );
		}
	}
	sort( $files, SORT_STRING );

	$dist = dirname( $output );
	if ( ! is_dir( $dist ) && ! mkdir( $dist, 0777, true ) && ! is_dir( $dist ) ) {
		throw new RuntimeException( 'Unable to create dist directory.' );
	}
	if ( is_file( $output ) ) {
		unlink( $output );
	}

	$zip = new ZipArchive();
	if ( true !== $zip->open( $output, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( 'Unable to open release archive.' );
	}
	$zip->setArchiveComment( 'HealthLens ' . $version . ' production package' );

	foreach ( $files as $relative ) {
		$path = $staging . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		touch( $path, $fixed_time );
		$zip->addFile( $path, $relative );
		if ( method_exists( $zip, 'setMtimeName' ) ) {
			$zip->setMtimeName( $relative, $fixed_time );
		}
	}
	$zip->close();

	echo $output . PHP_EOL;
} catch ( Throwable $exception ) {
	fwrite( STDERR, $exception->getMessage() . PHP_EOL );
	exit( 1 );
} finally {
	if ( is_dir( $staging ) ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $staging, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $staging );
	}
}
