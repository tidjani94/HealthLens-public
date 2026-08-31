<?php
/**
 * WordPress upload/storage configuration health check.
 *
 * @package HealthLens
 */

namespace HealthLens\Infrastructure\WordPress\Checks;

use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckDefinition;
use HealthLens\Domain\CheckResult;
use HealthLens\Infrastructure\WordPress\StorageScanSupport;

/**
 * Checks current-site upload metadata without exposing its path or URL.
 */
final class WordPressStorageConfigCheck implements \HealthLens\Domain\HealthCheckInterface {
	/**
	 * Return the check definition.
	 *
	 * @return CheckDefinition
	 */
	public function definition(): CheckDefinition {
		return new CheckDefinition( 'wordpress-storage-config', 'storage', 3, 86400 );
	}

	/**
	 * Check current-site upload configuration.
	 *
	 * @param CheckContext $context Execution context.
	 * @return CheckResult
	 */
	public function run( CheckContext $context ): CheckResult {
		if ( ! function_exists( 'wp_get_upload_dir' ) ) {
			return StorageScanSupport::result( CheckResult::STATE_UNKNOWN, CheckResult::SEVERITY_WARNING, 'storage.config-unavailable', array( 'status' => 'unavailable' ) );
		}
		$upload      = wp_get_upload_dir();
		$base_dir    = isset( $upload['basedir'] ) && is_string( $upload['basedir'] ) && '' !== $upload['basedir'];
		$base_url    = isset( $upload['baseurl'] ) && is_string( $upload['baseurl'] ) && '' !== $upload['baseurl'];
		$site_scoped = function_exists( 'is_multisite' ) ? ( ! is_multisite() || ( function_exists( 'get_current_blog_id' ) && get_current_blog_id() > 0 ) ) : true;
		if ( ! $base_dir || ! $base_url || ! $site_scoped ) {
			return StorageScanSupport::result(
				CheckResult::STATE_ISSUE,
				CheckResult::SEVERITY_WARNING,
				'storage.config-invalid',
				array(
					'directory_configured' => $base_dir,
					'url_configured'       => $base_url,
					'site_scoped'          => $site_scoped,
				)
			);
		}
		return StorageScanSupport::result(
			CheckResult::STATE_HEALTHY,
			CheckResult::SEVERITY_HEALTHY,
			'storage.config-healthy',
			array(
				'directory_configured' => true,
				'url_configured'       => true,
				'site_scoped'          => true,
			)
		);
	}
}
