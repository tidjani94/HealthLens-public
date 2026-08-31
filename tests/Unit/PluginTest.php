<?php
/**
 * @package HealthLens
 */

namespace HealthLens\Tests\Unit;

use HealthLens\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase {
	public function test_boot_is_idempotent(): void {
		$plugin = new Plugin();

		$plugin->boot();
		$plugin->boot();

		$this->assertTrue( true );
	}

	public function test_lifecycle_constants_are_stable(): void {
		$this->assertSame( 3, Plugin::SCHEMA_VERSION );
		$this->assertSame( 'healthlens_settings', Plugin::SETTINGS_OPTION );
		$this->assertSame( 'healthlens_schema_version', Plugin::SCHEMA_OPTION );
	}

	public function test_version_is_read_from_the_plugin_metadata(): void {
		$this->assertSame( '0.1.0', Plugin::version() );
	}
}
