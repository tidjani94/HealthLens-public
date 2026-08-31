<?php
/**
 * @package HealthLens
 */

namespace HealthLens\Tests\Unit;

use HealthLens\Plugin;
use HealthLens\Presentation\Admin\DashboardPage;
use HealthLens\Presentation\Admin\SettingsPage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SettingsPageTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['healthlens_test_actions']          = array();
		$GLOBALS['healthlens_test_action_callbacks'] = array();
		$GLOBALS['healthlens_test_submenu_pages']   = array();
		$GLOBALS['healthlens_test_settings']        = array();
		$GLOBALS['healthlens_test_settings_sections'] = array();
		$GLOBALS['healthlens_test_settings_fields'] = array();
		$GLOBALS['healthlens_test_capabilities']   = array( DashboardPage::CAPABILITY => true );
		$GLOBALS['healthlens_test_options']        = array(
			Plugin::SETTINGS_OPTION => array(
				SettingsPage::RETENTION_FIELD => true,
				'unrelated'                      => 'preserve',
			),
		);
	}

	public function test_registers_site_local_settings_api_surface(): void {
		$page = new SettingsPage();
		$page->register();
		call_user_func( $GLOBALS['healthlens_test_action_callbacks']['admin_menu'] );
		call_user_func( $GLOBALS['healthlens_test_action_callbacks']['admin_init'] );

		$this->assertSame( array( 'admin_menu', 'admin_init' ), $GLOBALS['healthlens_test_actions'] );
		$this->assertSame( DashboardPage::CAPABILITY, $GLOBALS['healthlens_test_submenu_pages'][0]['capability'] );
		$this->assertSame( SettingsPage::PAGE_SLUG, $GLOBALS['healthlens_test_submenu_pages'][0]['menu_slug'] );
		$this->assertCount( 1, $GLOBALS['healthlens_test_settings'] );
		$this->assertSame( Plugin::SETTINGS_OPTION, $GLOBALS['healthlens_test_settings'][0]['option_name'] );
		$this->assertSame( DashboardPage::CAPABILITY, $GLOBALS['healthlens_test_settings'][0]['args']['capability'] );
		$this->assertFalse( $GLOBALS['healthlens_test_settings'][0]['args']['show_in_rest'] );
		$this->assertCount( 1, $GLOBALS['healthlens_test_settings_sections'] );
		$this->assertCount( 4, $GLOBALS['healthlens_test_settings_fields'] );
	}

	public function test_sanitization_changes_only_the_documented_preference(): void {
		$page = new SettingsPage();
		$invalid = $page->sanitize_settings(
			array(
				SettingsPage::RETENTION_FIELD => 'unexpected',
				'unknown_setting'             => 'must-ignore',
			)
		);

		$this->assertTrue( $invalid[ SettingsPage::RETENTION_FIELD ] );
		$this->assertSame( 'preserve', $invalid['unrelated'] );
		$this->assertArrayNotHasKey( 'unknown_setting', $invalid );

		$valid = $page->sanitize_settings( array( SettingsPage::RETENTION_FIELD => '0' ) );
		$this->assertFalse( $valid[ SettingsPage::RETENTION_FIELD ] );
		$this->assertSame( 'preserve', $valid['unrelated'] );
	}

	public function test_settings_page_requires_manage_options(): void {
		$GLOBALS['healthlens_test_capabilities'][ DashboardPage::CAPABILITY ] = false;
		$page = new SettingsPage();
		$this->expectException( RuntimeException::class );
		$page->render();
	}

	public function test_sanitization_rejects_unauthorized_input(): void {
		$GLOBALS['healthlens_test_capabilities'][ DashboardPage::CAPABILITY ] = false;
		$page = new SettingsPage();

		$sanitized = $page->sanitize_settings( array( SettingsPage::RETENTION_FIELD => '0' ) );

		$this->assertTrue( $sanitized[ SettingsPage::RETENTION_FIELD ] );
		$this->assertSame( 'preserve', $sanitized['unrelated'] );
	}

	public function test_authorized_settings_page_is_native_and_explanatory(): void {
		$GLOBALS['healthlens_test_capabilities'][ DashboardPage::CAPABILITY ] = true;
		$page = new SettingsPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'action="options.php"', $output );
		$this->assertStringContainsString( 'Version 0.1.0', $output );
		$this->assertStringContainsString( 'Crafted with ❤️ by', $output );
		$this->assertStringContainsString( 'href="https://coodiv.net"', $output );

		ob_start();
		$page->render_section();
		$page->render_retention_field();
		$field_output = ob_get_clean();

		$this->assertStringContainsString( 'Keep HealthLens data', $field_output );
		$this->assertStringContainsString( 'does not start checks', $field_output );
	}
}
