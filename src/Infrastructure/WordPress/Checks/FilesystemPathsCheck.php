<?php
/**
 * Current-site filesystem paths health check.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress\Checks;

use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckDefinition;
use HealthLens\Domain\CheckResult;
use HealthLens\Domain\HealthCheckInterface;
use HealthLens\Infrastructure\WordPress\StorageScanSupport;

/**
 * Inspects only fixed WordPress-provided path categories, without file reads.
 */
final class FilesystemPathsCheck implements HealthCheckInterface {
	/**
	 * Return the check definition.
	 *
	 * @return CheckDefinition
	 */
	public function definition(): CheckDefinition {
		return new CheckDefinition( 'filesystem-paths', 'storage', 4, 86400 );
	}

	/**
	 * Inspect fixed WordPress path categories.
	 *
	 * @param CheckContext $context Execution context.
	 * @return CheckResult
	 */
	public function run( CheckContext $context ): CheckResult {
		$paths = array();
		if ( function_exists( 'wp_get_upload_dir' ) ) {
			$upload           = wp_get_upload_dir();
			$paths['uploads'] = isset( $upload['basedir'] ) ? $upload['basedir'] : '';
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$paths['content'] = WP_CONTENT_DIR;
		}
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$paths['plugins'] = WP_PLUGIN_DIR;
		}
		if ( function_exists( 'get_temp_dir' ) ) {
			$paths['temporary'] = get_temp_dir();
		}
		if ( empty( $paths ) ) {
			return StorageScanSupport::result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING, 'storage.paths-unavailable', array( 'path_categories' => 0 ) );
		}

		$exists   = 0;
		$writable = 0;
		foreach ( array_slice( $paths, 0, StorageScanSupport::MAX_PATH_CATEGORIES ) as $path ) {
			if ( is_string( $path ) && '' !== $path && is_dir( $path ) ) {
				++$exists;
				$writable += function_exists( 'wp_is_writable' ) ? (int) wp_is_writable( $path ) : 0;
			}
		}
		$category_count = count( $paths );
		if ( $exists < $category_count ) {
			return StorageScanSupport::result(
				CheckResult::STATE_ISSUE,
				CheckResult::SEVERITY_WARNING,
				'storage.paths-missing',
				array(
					'path_categories'     => $category_count,
					'existing_categories' => $exists,
					'writable_categories' => $writable,
				)
			);
		}
		if ( $writable < $category_count ) {
			return StorageScanSupport::result(
				CheckResult::STATE_ISSUE,
				CheckResult::SEVERITY_WARNING,
				'storage.paths-not-writable',
				array(
					'path_categories'     => $category_count,
					'existing_categories' => $exists,
					'writable_categories' => $writable,
				)
			);
		}
		return StorageScanSupport::result(
			CheckResult::STATE_HEALTHY,
			CheckResult::SEVERITY_HEALTHY,
			'storage.paths-healthy',
			array(
				'path_categories'     => $category_count,
				'existing_categories' => $exists,
				'writable_categories' => $writable,
			)
		);
	}
}
