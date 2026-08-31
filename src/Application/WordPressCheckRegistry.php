<?php
/**
 * Default M3 WordPress check catalog.
 *
 * @package HealthLens
 */

namespace HealthLens\Application;

use HealthLens\Infrastructure\WordPress\BoundedHttpProbe;
use HealthLens\Infrastructure\WordPress\Checks\AdministratorEmailCheck;
use HealthLens\Infrastructure\WordPress\Checks\CronScheduleCheck;
use HealthLens\Infrastructure\WordPress\Checks\LoopbackRequestsCheck;
use HealthLens\Infrastructure\WordPress\Checks\RestApiAvailabilityCheck;
use HealthLens\Infrastructure\WordPress\Checks\WordPressVersionCheck;
use HealthLens\Infrastructure\WordPress\Checks\DatabaseConnectivityCheck;
use HealthLens\Infrastructure\WordPress\Checks\DatabaseCharsetSchemaCheck;
use HealthLens\Infrastructure\WordPress\Checks\AutoloadedOptionsCheck;
use HealthLens\Infrastructure\WordPress\Checks\DatabaseStorageGrowthCheck;
use HealthLens\Infrastructure\WordPress\Checks\SslHttpsCheck;
use HealthLens\Infrastructure\WordPress\Checks\FilesystemPathsCheck;
use HealthLens\Infrastructure\WordPress\Checks\DiskSpaceCheck;
use HealthLens\Infrastructure\WordPress\Checks\WordPressStorageConfigCheck;

/**
 * Builds the first concrete, local WordPress health-check catalog.
 */
final class WordPressCheckRegistry {
	/**
	 * Create the default registry without executing any checks.
	 *
	 * @return CheckRegistry
	 */
	public static function create() {
		$registry = new CheckRegistry();
		$host     = null;
		if ( function_exists( 'home_url' ) ) {
			$parts = wp_parse_url( home_url( '/' ) );
			$host  = is_array( $parts ) && isset( $parts['host'] ) ? (string) $parts['host'] : null;
		}
		$probe = new BoundedHttpProbe( null, $host );

		$registry->register( new WordPressVersionCheck() );
		$registry->register( new RestApiAvailabilityCheck( $probe ) );
		$registry->register( new LoopbackRequestsCheck( $probe ) );
		$registry->register( new CronScheduleCheck() );
		$registry->register( new AdministratorEmailCheck() );
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ) {
			$registry->register( new DatabaseConnectivityCheck( $GLOBALS['wpdb'] ) );
			$registry->register( new DatabaseCharsetSchemaCheck( $GLOBALS['wpdb'] ) );
			$registry->register( new AutoloadedOptionsCheck() );
			$registry->register( new DatabaseStorageGrowthCheck( $GLOBALS['wpdb'] ) );
		}
		$registry->register( new SslHttpsCheck( $probe ) );
		$registry->register( new FilesystemPathsCheck() );
		$registry->register( new DiskSpaceCheck() );
		$registry->register( new WordPressStorageConfigCheck() );

		return $registry;
	}
}
