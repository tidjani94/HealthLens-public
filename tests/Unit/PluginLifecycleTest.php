<?php
/**
 * @package HealthLens
 */

namespace HealthLens\Tests\Unit;

use HealthLens\Plugin;
use HealthLens\Infrastructure\WordPress\CronScheduler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PluginLifecycleTest extends TestCase {
	protected function setUp(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['healthlens_test_options']       = array();
		$GLOBALS['healthlens_test_autoload']      = array();
		$GLOBALS['healthlens_test_cleared_hooks'] = array();
		$GLOBALS['healthlens_test_dbdelta']       = array();
		$GLOBALS['healthlens_test_dbdelta_calls'] = 0;
		unset( $GLOBALS['healthlens_test_dbdelta_throw_on_call'] );
		$GLOBALS['healthlens_test_scheduled_events'] = array();
		$GLOBALS['healthlens_test_actions']       = array();
		$GLOBALS['healthlens_test_filters']       = array();
	}

	public function test_activation_is_site_local_and_non_autoloaded(): void {
		Plugin::activate( false );

		$this->assertSame( false, $GLOBALS['healthlens_test_autoload'][ Plugin::SETTINGS_OPTION ] );
		$this->assertSame( false, $GLOBALS['healthlens_test_autoload'][ Plugin::SCHEMA_OPTION ] );
		$this->assertSame( false, $GLOBALS['healthlens_test_autoload'][ Plugin::VERSION_OPTION ] );
		$this->assertSame( array( 'retain_data_on_uninstall' => false, Plugin::CAPTURE_FIELD => false, 'notifications_enabled' => false, 'notification_email' => '', 'notification_recovery' => true, 'gateway_enabled' => false, 'gateway_endpoint' => '', 'gateway_token' => '' ), $GLOBALS['healthlens_test_options'][ Plugin::SETTINGS_OPTION ] );
		$this->assertSame( Plugin::SCHEMA_VERSION, $GLOBALS['healthlens_test_options'][ Plugin::SCHEMA_OPTION ] );
		$this->assertSame( '0.1.0', $GLOBALS['healthlens_test_options'][ Plugin::VERSION_OPTION ] );
		$this->assertSame( 'healthlens_fifteen_minutes', $GLOBALS['healthlens_test_scheduled_events']['healthlens_run_checks']['recurrence'] );
	}

	public function test_boot_registers_cron_hook_without_running_checks(): void {
		$plugin = new Plugin();
		$plugin->boot();

		$this->assertContains( 'healthlens_run_checks', $GLOBALS['healthlens_test_actions'] );
		$this->assertContains( CronScheduler::MANUAL_HOOK, $GLOBALS['healthlens_test_actions'] );
		$this->assertArrayHasKey( 'healthlens_run_checks', $GLOBALS['healthlens_test_scheduled_events'] );
	}

	public function test_manual_run_is_a_separate_idempotent_single_event(): void {
		$scheduler = new CronScheduler();

		$this->assertTrue( $scheduler->request_manual_run() );
		$this->assertSame( false, $GLOBALS['healthlens_test_scheduled_events'][ CronScheduler::MANUAL_HOOK ]['recurrence'] );
		$this->assertTrue( $scheduler->request_manual_run() );
		$this->assertCount( 1, $GLOBALS['healthlens_test_scheduled_events'] );
	}

	public function test_network_activation_is_rejected_before_writes(): void {
		$this->expectException( RuntimeException::class );
		Plugin::activate( true );
	}

	public function test_activation_upgrades_schema_when_wordpress_database_is_available(): void {
		$GLOBALS['wpdb'] = new class {
			/**
			 * @var string
			 */
			public $prefix = 'wp_test_';

			/**
			 * @return string
			 */
			public function get_charset_collate() {
				return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
			}
		};

		Plugin::activate( false );

		$this->assertCount( 3, $GLOBALS['healthlens_test_dbdelta'] );
		$this->assertSame( Plugin::SCHEMA_VERSION, $GLOBALS['healthlens_test_options'][ Plugin::SCHEMA_OPTION ] );
		unset( $GLOBALS['wpdb'] );
	}

	public function test_upgrade_repairs_an_existing_installation_without_activation(): void {
		$GLOBALS['wpdb'] = new class {
			/**
			 * @var string
			 */
			public $prefix = 'wp_test_';

			/**
			 * @return string
			 */
			public function get_charset_collate() {
				return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
			}
		};
		$GLOBALS['healthlens_test_options'][ Plugin::SCHEMA_OPTION ]  = 1;
		$GLOBALS['healthlens_test_options'][ Plugin::VERSION_OPTION ] = '0.0.9';

		Plugin::upgrade();

		$this->assertCount( 3, $GLOBALS['healthlens_test_dbdelta'] );
		$this->assertSame( Plugin::SCHEMA_VERSION, $GLOBALS['healthlens_test_options'][ Plugin::SCHEMA_OPTION ] );
		$this->assertSame( '0.1.0', $GLOBALS['healthlens_test_options'][ Plugin::VERSION_OPTION ] );
		$this->assertSame( false, $GLOBALS['healthlens_test_autoload'][ Plugin::VERSION_OPTION ] );
	}

	public function test_deactivation_only_clears_healthlens_work(): void {
		$GLOBALS['healthlens_test_options'][ Plugin::LOCK_OPTION ] = 'lock';

		Plugin::deactivate();

		$this->assertSame( array( 'healthlens_run_checks', CronScheduler::MANUAL_HOOK ), $GLOBALS['healthlens_test_cleared_hooks'] );
		$this->assertArrayNotHasKey( Plugin::LOCK_OPTION, $GLOBALS['healthlens_test_options'] );
	}

	public function test_partial_upgrade_keeps_previous_state_and_recovers_on_retry(): void {
		$fixture_path = dirname( __DIR__ ) . '/Fixtures/release/upgrade-rollback.json';
		$fixture      = json_decode( file_get_contents( $fixture_path ), true );
		$GLOBALS['wpdb'] = new class {
			/** @var string */
			public $prefix = 'wp_test_';
		};
		$GLOBALS['healthlens_test_options'] = array(
			Plugin::SETTINGS_OPTION => $fixture['existing_options'][ Plugin::SETTINGS_OPTION ],
			Plugin::SCHEMA_OPTION   => $fixture['from']['schema'],
			Plugin::VERSION_OPTION  => $fixture['from']['version'],
		);
		$GLOBALS['healthlens_test_dbdelta_throw_on_call'] = $fixture['failure']['dbdelta_call'];

		( new Plugin() )->boot();

		$this->assertSame( 2, $GLOBALS['healthlens_test_dbdelta_calls'] );
		$this->assertSame( $fixture['failure']['expected_schema'], $GLOBALS['healthlens_test_options'][ Plugin::SCHEMA_OPTION ] );
		$this->assertSame( $fixture['failure']['expected_version'], $GLOBALS['healthlens_test_options'][ Plugin::VERSION_OPTION ] );
		$this->assertSame( $fixture['existing_options'][ Plugin::SETTINGS_OPTION ], $GLOBALS['healthlens_test_options'][ Plugin::SETTINGS_OPTION ] );

		unset( $GLOBALS['healthlens_test_dbdelta_throw_on_call'] );
		( new Plugin() )->boot();

		$this->assertSame( 5, $GLOBALS['healthlens_test_dbdelta_calls'] );
		$this->assertSame( $fixture['to']['schema'], $GLOBALS['healthlens_test_options'][ Plugin::SCHEMA_OPTION ] );
		$this->assertSame( $fixture['to']['version'], $GLOBALS['healthlens_test_options'][ Plugin::VERSION_OPTION ] );
		$this->assertSame( $fixture['recovery']['preserved_settings'], $GLOBALS['healthlens_test_options'][ Plugin::SETTINGS_OPTION ] );
	}

	public function test_uninstall_deletes_by_default_and_honors_retention(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		$GLOBALS['healthlens_test_options'] = array(
			Plugin::SETTINGS_OPTION => array( 'retain_data_on_uninstall' => false ),
			Plugin::SCHEMA_OPTION   => 1,
			Plugin::LOCK_OPTION     => 'lock',
			Plugin::VERSION_OPTION  => '0.0.9',
		);
		$GLOBALS['healthlens_test_scheduled_events']['healthlens_run_checks'] = array( 'recurrence' => 'healthlens_fifteen_minutes' );
		include dirname( __DIR__, 2 ) . '/uninstall.php';
		$this->assertSame( array(), $GLOBALS['healthlens_test_options'] );
		$this->assertSame( array(), $GLOBALS['healthlens_test_scheduled_events'] );

		$GLOBALS['healthlens_test_options'] = array(
			Plugin::SETTINGS_OPTION => array( 'retain_data_on_uninstall' => true ),
			Plugin::SCHEMA_OPTION   => 1,
			Plugin::LOCK_OPTION     => 'lock',
			Plugin::VERSION_OPTION  => '0.0.9',
		);
		$GLOBALS['healthlens_test_scheduled_events']['healthlens_run_checks'] = array( 'recurrence' => 'healthlens_fifteen_minutes' );
		include dirname( __DIR__, 2 ) . '/uninstall.php';
		$this->assertCount( 4, $GLOBALS['healthlens_test_options'] );
		$this->assertSame( array(), $GLOBALS['healthlens_test_scheduled_events'] );
	}
}
